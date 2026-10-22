<?php
/* Musicband IDEAS: suggestions authentifiées, envoi explicite par le service mail existant. */
require_once __DIR__.'/account_mail.php';
$user=MusicbandRequireLogin();
if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)MusicbandFail('Formulaire trop volumineux.',413);
$modules=MusicbandHomeModules();$selected=[];$text='';$message='';$error=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        MusicbandCsrf();MusicbandRequirePermission('ideas.send');
        $selected=$_POST['modules']??[];$text=$_POST['idea']??'';
        if(!is_array($selected)||!$selected||count($selected)>count($modules))throw new RuntimeException('Choisir au moins un module.');
        foreach($selected as $key)if(!is_string($key)||!isset($modules[$key]))throw new RuntimeException('Module inconnu : recharger la page.');
        $selected=array_values(array_unique($selected));
        if(!is_string($text)||trim($text)===''||strlen($text)>4000||str_contains($text,"\0")||!preg_match('//u',$text))throw new RuntimeException('Suggestion requise, limitée à 4000 octets de texte UTF-8.');
        if(($_POST['confirm']??'')!=='yes')throw new RuntimeException('Confirmer l’envoi de la suggestion par e-mail.');
        $message=MusicbandLocked(function()use($selected,$text,$modules){
            $fresh=MusicbandRequireLogin();MusicbandRequirePermission('ideas.send',$fresh);
            if(!is_string($_POST['send_token']??null)||!isset($_SESSION['idea_token'])||!hash_equals($_SESSION['idea_token'],$_POST['send_token']))throw new RuntimeException('Formulaire déjà utilisé ou expiré : recharger avant tout nouvel envoi.');
            $to=MusicbandEmail(MusicbandRead('home_settings')['ideas_recipient']??'');
            if(!is_string($_POST['recipient_version']??null)||!hash_equals(hash('sha256',$to),$_POST['recipient_version']))throw new RuntimeException('Destinataire modifié : recharger avant de confirmer.');
            if($to==='')throw new RuntimeException('Configurer le destinataire de la Boîte à idées dans Configuration → Général.');
            $from=MusicbandMailFrom();
            if(!MusicbandMailQuota('ideas-'.hash('sha256',$fresh['login']),3))throw new RuntimeException('Limite de suggestions atteinte : réessayer dans quinze minutes.');
            unset($_SESSION['idea_token']); // Consommer avant l’appel externe : aucun renvoi par rechargement.
            $body="Suggestion Musicband\nAuteur : ".$fresh['login']."\nModules : ".implode(', ',array_map(fn($key)=>$modules[$key],$selected))."\n\n".str_replace(["\r\n","\r"],"\n",trim($text))."\n";
            try{
                $sendResult=MusicbandSendEmail([
                    'to'=>$to,
                    'from'=>$from,
                    'subject'=>'Musicband · Boîte à idées',
                    'text'=>$body,
                    'type'=>'ideas',
                ]);
                $accepted=($sendResult['status']==='accepted');
            }
            catch(Throwable $e){return 'Résultat de l’envoi incertain : '.$e->getMessage().'. Vérifier la réception avant de créer un nouvel envoi ; aucun renvoi automatique.';}
            return $accepted?'Suggestion acceptée par le service d’envoi d’e-mails. Réception à vérifier.':'Le service d’envoi d’e-mails a refusé l’envoi. Aucun renvoi automatique ; vérifier la configuration avant de recommencer.';
        });
        $_SESSION['idea_notice']=$message;
        header('Location: '.MusicbandBase().'ideas.php',true,303);exit;
    }catch(Throwable $e){$message=$e instanceof RuntimeException?$e->getMessage():'Envoi indisponible. Vérifier la configuration.';$error=true;}
}else{$message=(string)($_SESSION['idea_notice']??'');unset($_SESSION['idea_notice']);}
if(!is_array($selected))$selected=[];
if(!is_string($text))$text='';
$_SESSION['idea_token']=$_SESSION['idea_token']??bin2hex(random_bytes(24));
$content='<p><a href="'.MusicbandEscape(MusicbandBase()).'index.php">← Accueil</a></p><p>Une idée pour améliorer Musicband ? Choisissez les modules concernés et décrivez votre proposition. Évitez les mots de passe et les informations confidentielles.</p>';
if($message!=='')$content.='<p class="notice" role="'.($error?'alert':'status').'">'.MusicbandEscape($message).'</p>';
if(!MusicbandCan('ideas.send',$user))$content.='<p>Envoi non autorisé ou droits non initialisés. Contacter un administrateur ; le droit « Envoyer une idée » se prépare dans Upgrade.</p>';
else{
    $recipient=MusicbandEmail(MusicbandRead('home_settings')['ideas_recipient']??'');
    $content.='<p>Destinataire : '.MusicbandEscape($recipient===''?'non configuré — demander à un administrateur':$recipient).'</p>';
    $content.='<form method="post"><input type="hidden" name="csrf" value="'.MusicbandEscape($_SESSION['csrf']).'"><input type="hidden" name="recipient_version" value="'.hash('sha256',$recipient).'"><input type="hidden" name="send_token" value="'.MusicbandEscape($_SESSION['idea_token']).'"><fieldset><legend>Modules concernés · au moins un</legend><div class="Musicband-icon-options">';
    foreach($modules as $key=>$label)$content.='<label class="field-option-visible"><input type="checkbox" name="modules[]" value="'.MusicbandEscape($key).'"'.(in_array($key,$selected,true)?' checked':'').'> '.MusicbandEscape($label).'</label>';
    $content.='</div></fieldset><label for="idea">Votre suggestion</label><textarea class="Musicband-wide-field" id="idea" name="idea" rows="7" maxlength="4000" required >'.MusicbandEscape($text).'</textarea><p>4000 octets maximum ; les accents peuvent occuper plusieurs octets. Votre identifiant et les modules choisis seront inclus dans le mail.</p><label class="field-option-visible"><input type="checkbox" name="confirm" value="yes" required> Je confirme l’envoi de cette suggestion par e-mail.</label><button'.($recipient===''?' disabled':'').'>Envoyer mon idée</button></form>';
}
MusicbandScreen('Boîte à idées',$content);
