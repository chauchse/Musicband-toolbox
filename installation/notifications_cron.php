<?php
/* Musicband NOTIFICATION CRON: POST HTTPS avec secret serveur, aucun déclenchement par navigation. */
/* Entrée serveur du traitement des notifications : authentification par secret, sans session utilisateur requise pour le travail.
 * Charge auth.php, business.php et les deux moteurs de notifications.
 * Peut envoyer des notifications Web Push et enregistrer le suivi ; ne pas appeler pour une simple inspection.
 */
require_once __DIR__.'/auth.php';require_once __DIR__.'/business.php';require_once __DIR__.'/notifications.php';require_once __DIR__.'/advanced_notifications_service.php';
if((int)($_SERVER['CONTENT_LENGTH']??0)>1024)MusicbandFail('Requête trop volumineuse.',413);
$secret=MusicbandWebpushSetting('Musicband_NOTIFICATIONS_CRON_TOKEN');
$provided=$_SERVER['HTTP_AUTHORIZATION']??'';
if($provided==='')foreach($_SERVER as $name=>$value)if(preg_match('/^(?:REDIRECT_)+HTTP_AUTHORIZATION$/D',$name)&&is_string($value)&&$value!==''){$provided=$value;break;}
$tokenProvided='';
if($provided!==''&&str_starts_with($provided,'Bearer ')){
    $tokenProvided=substr($provided,7);
}elseif(!empty($_SERVER['HTTP_X_Musicband_CRON_TOKEN'])&&is_string($_SERVER['HTTP_X_Musicband_CRON_TOKEN'])){
    $tokenProvided=$_SERVER['HTTP_X_Musicband_CRON_TOKEN'];
}elseif(!empty($_POST['token'])&&is_string($_POST['token'])){
    $tokenProvided=$_POST['token'];
}
if($_SERVER['REQUEST_METHOD']!=='POST'||!is_string($secret)||!preg_match('/^[a-f0-9]{64}$/D',$secret)||!hash_equals($secret,$tokenProvided))MusicbandFail('Accès refusé.',403);
session_write_close();header('Content-Type: application/json; charset=utf-8');
$db=null;
try{
    $db=MusicbandRbacDb();
    if(!MusicbandNotificationsReady($db)){
        http_response_code(503);
        echo json_encode(['error_code'=>'database','error'=>'Schéma 012 requis.']);
        exit;
    }
    $result=MusicbandNotificationsProcess($db,'cron',30);
    echo json_encode($result);
}catch(Throwable $e){
    http_response_code(503);
    error_log('Musicband_notification_cron_failed: '.$e->getMessage());
    echo json_encode(['error_code'=>'process_failed','error'=>'Traitement non terminé. Vérifier schémas, dépendances, connexion et configuration serveur.']);
}

