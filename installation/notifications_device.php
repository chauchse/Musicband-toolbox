<?php
/* Musicband NOTIFICATION DEVICE: abonnement personnel et consentement navigateur. */
/* Route authentifiée pour l’abonnement Push du navigateur et les actions personnelles.
 * Dépend de auth.php et des services de notifications ; pwa_device.js pilote le formulaire.
 * Les POST vérifient CSRF et peuvent enregistrer/révoquer un abonnement ou envoyer un test demandé.
 */
require_once __DIR__.'/auth.php';require_once __DIR__.'/notifications.php';require_once __DIR__.'/advanced_notifications_service.php';
$user=MusicbandRequireLogin(($_GET['api']??'')==='1');
if(($_GET['api']??'')==='1'){
    header('Content-Type: application/json; charset=utf-8');
    try{
        MusicbandRequirePermission('notifications.subscribe',$user);$db=MusicbandRbacDb();if(!MusicbandNotificationsReady($db))throw new RuntimeException('Préparer le schéma Notifications 012.');
        if($_SERVER['REQUEST_METHOD']==='GET'){
            $hash=$_GET['subscription_hash']??'';
            if(!is_string($hash)||($hash!==''&&!preg_match('/^[a-f0-9]{64}$/D',$hash)))throw new RuntimeException('Identifiant appareil invalide.');
            $active=false;
            if($hash!==''){
                $q=$db->prepare('SELECT active,account_revision FROM Musicbandai_push_subscriptions WHERE endpoint_hash=? AND login=?');$q->execute([$hash,$user['login']]);$device=$q->fetch();$accounts=MusicbandRead('users');
                $active=$device&&(int)$device['active']===1&&hash_equals((string)($accounts[$user['login']]['revision']??''),(string)$device['account_revision']);
            }
            echo json_encode(['csrf'=>$_SESSION['csrf'],'publicKey'=>(string)(MusicbandWebpushSetting('Musicband_VAPID_PUBLIC_KEY')?:getenv('Musicband_VAPID_PUBLIC_KEY')),'active'=>(bool)$active]);exit;
        }
        if((int)($_SERVER['CONTENT_LENGTH']??0)>8192)MusicbandFail('Abonnement trop volumineux.',413);
        MusicbandCsrf();
        $action=$_POST['action']??'';
        if($action==='test_notification'){
            $fresh=MusicbandRequireLogin();MusicbandRequirePermission('notifications.subscribe',$fresh);
            $testRes=advancedNotificationSendTestNotification($fresh['login']);
            echo json_encode([
                'ok'=>!empty($testRes['success']),
                'message'=>$testRes['message']??'',
                'count'=>$testRes['count']??0,
                'outcomes'=>$testRes['outcomes']??[]
            ]);
            exit;
        }
        MusicbandLocked(function()use($user,$db,$action){
            $fresh=MusicbandRequireLogin();MusicbandRequirePermission('notifications.subscribe',$fresh);
            $endpoint=$_POST['endpoint']??'';if(!is_string($endpoint))throw new RuntimeException('Abonnement invalide.');MusicbandPushEndpoint($endpoint);$hash=hash('sha256',$endpoint);
            if($action==='disable'){$q=$db->prepare('UPDATE Musicbandai_push_subscriptions SET active=0,updated_at=UTC_TIMESTAMP(6) WHERE endpoint_hash=? AND login=?');$q->execute([$hash,$fresh['login']]);return;}
            if($action!=='subscribe'||!is_string($_POST['subscription']??null))throw new RuntimeException('Action inconnue.');
            $sub=json_decode($_POST['subscription'],true,8,JSON_THROW_ON_ERROR);
            if(($sub['endpoint']??'')!==$endpoint)throw new RuntimeException('Abonnement incohérent.');
            foreach(['p256dh'=>65,'auth'=>16] as $key=>$length){$value=$sub['keys'][$key]??'';if(!is_string($value)||!preg_match('/^[A-Za-z0-9_-]+={0,2}$/D',$value))throw new RuntimeException('Clé Push invalide.');$decoded=base64_decode(strtr($value,'-_','+/'),true);if($decoded===false||strlen($decoded)!==$length||($key==='p256dh'&&$decoded[0]!=="\x04"))throw new RuntimeException('Clé Push invalide.');}
            $q=$db->prepare('SELECT COUNT(*) FROM Musicbandai_push_subscriptions WHERE login=? AND active=1 AND endpoint_hash<>?');$q->execute([$fresh['login'],$hash]);if((int)$q->fetchColumn()>=10)throw new RuntimeException('Limite de dix appareils actifs atteinte.');
            $accounts=MusicbandRead('users');
            // Un clic répété ne doit pas déplacer la borne des événements éligibles.
            $q=$db->prepare('SELECT login,active,account_revision,subscription_json FROM Musicbandai_push_subscriptions WHERE endpoint_hash=?');$q->execute([$hash]);$existing=$q->fetch();
            $encoded=json_encode(['endpoint'=>$endpoint,'keys'=>['p256dh'=>$sub['keys']['p256dh'],'auth'=>$sub['keys']['auth']]],JSON_THROW_ON_ERROR);
            $stored=$existing?json_decode($existing['subscription_json'],true,8,JSON_THROW_ON_ERROR):null;
            if($existing&&$existing['login']===$fresh['login']&&(int)$existing['active']===1&&hash_equals($existing['account_revision'],$accounts[$fresh['login']]['revision'])&&is_array($stored)&&($stored['endpoint']??null)===$endpoint&&($stored['keys']['p256dh']??null)===$sub['keys']['p256dh']&&($stored['keys']['auth']??null)===$sub['keys']['auth'])return;
            $q=$db->prepare('INSERT INTO Musicbandai_push_subscriptions(endpoint_hash,login,account_revision,subscription_json,created_at,updated_at) VALUES (?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE login=VALUES(login),account_revision=VALUES(account_revision),subscription_json=VALUES(subscription_json),active=1,updated_at=UTC_TIMESTAMP(6)');
            $q->execute([$hash,$fresh['login'],$accounts[$fresh['login']]['revision'],$encoded]);
        });MusicbandAuditEvent('notifications',MusicbandAuditText($_POST['action']??'subscription',64),'success');echo json_encode(['ok'=>true]);
    }catch(Throwable $e){http_response_code(400);echo json_encode(['error'=>$e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Notifications indisponibles. Vérifier la configuration.']);}exit;
}
// Les anciennes adresses restent valides ; l’API ci-dessus conserve son contrat.
header('Location: '.MusicbandBase().'activate.php?change=1#preferences-notifications',true,303);exit;
