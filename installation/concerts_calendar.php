<?php
/* Musicband CONCERTS CALENDAR: invitation confirmée, au plus une tentative par concert.
 * Réutilise account_mail.php, les services métier et le stockage privé/MySQL existants.
 * Prévisualise le modèle et son lien de fiche ; l’envoi confirmé conserve un instantané et un journal.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/account_mail.php';

const CONCERTS_CALENDAR_MAX_RECIPIENTS = 30;
const CONCERTS_CALENDAR_MAX_MINUTES = 1440;

function concertsCalendarTemplate(): string {
    $body=MusicbandRead('home_settings')['calendar_mail_template']??"{evenement}\nAdresse : {adresse}\nArrivée : {arrivee}\nPrestation : {prestation}\nDurée : {duree} min.\nFin (heure supplémentaire comprise) : {fin}";
    if(!is_string($body))throw new RuntimeException('Modèle calendrier invalide.');return $body;
}
function concertsCalendarTemplateSave(array $user): string {
    if(!$user['admin'])throw new RuntimeException('Accès administrateur requis.');
    $old=concertsCalendarTemplate();$body=$_POST['calendar_body']??null;
    if(!is_string($_POST['calendar_template_version']??null)||!hash_equals(hash('sha256',$old),$_POST['calendar_template_version']))throw new RuntimeException('Modèle modifié ailleurs : recharger Configuration.');
    if(!is_string($body)||trim($body)===''||strlen($body)>6000||str_contains($body,"\0")||!preg_match('//u',$body))throw new RuntimeException('Texte requis, UTF-8, limité à 6000 octets.');
    preg_match_all('/\{[^{}]*\}/u',$body,$matches);if(array_diff($matches[0],['{evenement}','{adresse}','{date}','{arrivee}','{prestation}','{fin}','{duree}','{lien_concert}']))throw new RuntimeException('Variable inconnue dans le modèle calendrier.');
    $settings=MusicbandRead('home_settings');$settings['calendar_mail_template']=str_replace(["\r\n","\r"],"\n",$body);MusicbandWrite('home_settings',$settings);
    return 'Modèle calendrier enregistré pour les futurs envois. Aucun envoi déclenché ; recharger les confirmations ouvertes.';
}
function concertsCalendarTemplateForm(string $hidden): string {
    $body=concertsCalendarTemplate();return '<section><h2>Texte des invitations calendrier</h2><p>Texte simple commun au mail et à la description du rendez-vous. Variables : {evenement}, {adresse}, {date}, {arrivee}, {prestation}, {fin}, {duree}, {lien_concert}. Exemple : Fiche concert : {lien_concert}. Ce lien privé nécessite une connexion et les droits habituels. Les dates/heures remplacées sont en français, sans secondes ni fuseau affiché. Le texte sera prévisualisé avant confirmation.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="calendar_mail_template"><input type="hidden" name="calendar_template_version" value="'.hash('sha256',$body).'"><label>Corps de l’invitation<textarea class="Musicband-wide-field" name="calendar_body" rows="9" maxlength="6000" required >'.MusicbandEscape($body).'</textarea></label><button>Enregistrer le texte des invitations</button></form></section>';
}
function concertsCalendarReady(PDO $db): bool {
    $tables=$db->query("SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('concerts_calendar_settings','concerts_calendar_invitations')")->fetchAll();
    if (count($tables)!==2 || array_filter($tables,fn($row)=>strcasecmp($row['ENGINE']??'','InnoDB')!==0)) return false;
    $db->query('SELECT id,recipients_json,revision,updated_by,updated_at FROM concerts_calendar_settings LIMIT 0');
    $db->query('SELECT concert_id,event_uid,status,snapshot_json,requested_by,requested_at,completed_at FROM concerts_calendar_invitations LIMIT 0');
    $primary=$db->query("SELECT COLUMN_NAME FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='concerts_calendar_invitations' AND index_name='PRIMARY' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
    if ($primary!==['concert_id']) return false;
    $settingsPrimary=$db->query("SELECT COLUMN_NAME FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='concerts_calendar_settings' AND index_name='PRIMARY' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
    if ($settingsPrimary!==['id']) return false;
    if ((int)$db->query('SELECT COUNT(*) FROM concerts_calendar_settings')->fetchColumn()!==1 || (int)$db->query('SELECT COUNT(*) FROM concerts_calendar_settings WHERE id=1')->fetchColumn()!==1) return false;
    foreach (documentsForeignKeys($db) as $key) if ($key['TABLE_NAME']==='concerts_calendar_invitations' && $key['COLUMN_NAME']==='concert_id' && $key['REFERENCED_TABLE_NAME']==='concerts_evenements' && $key['REFERENCED_COLUMN_NAME']==='id' && $key['DELETE_RULE']==='RESTRICT' && $key['UPDATE_RULE']==='RESTRICT') return true;
    return false;
}
function concertsCalendarRequire(PDO $db): void {
    if (!concertsCalendarReady($db)) throw new RuntimeException('Créer ou vérifier le schéma 007 · Invitations calendrier dans Upgrade.');
}
function concertsCalendarSchema(array $settings): string {
    $db=MusicbandDatabase($settings);
    if (concertsCalendarReady($db)) return 'Schéma 007 déjà présent. Aucun envoi ni donnée modifiée.';
    if (!documentsTableExists($db,'concerts_evenements')) throw new RuntimeException('Schéma Concerts 003 InnoDB requis.');
    $count=(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('concerts_calendar_settings','concerts_calendar_invitations')")->fetchColumn();
    if ($count) throw new RuntimeException('Schéma 007 partiel ou personnalisé : inspecter la base avant reprise. Aucun nettoyage automatique.');
    if (($_POST['confirm']??'')!=='yes' || ($_POST['confirm_database']??null)!==($settings['DB_NAME']??null)) throw new RuntimeException('Confirmer la sauvegarde et le nom exact de la base.');
    $sql=base64_decode('',true);
    if (!$sql) throw new RuntimeException('Schéma calendrier non embarqué : préparer les fichiers du site.');
    foreach (explode(';',$sql) as $statement) if (trim($statement)!=='') $db->exec($statement);
    concertsCalendarRequire($db);
    return 'Schéma 007 créé : destinataires et journal des invitations. Aucun e-mail envoyé.';
}
function concertsCalendarUpgradeForm(string $hidden): string {
    return '<section><h3>Invitations calendrier · schéma 007</h3><p>Deux tables additives, aucune fiche modifiée. Sauvegarder la base ; une interruption peut laisser un schéma partiel. Conserver le journal même lors d’un retour arrière.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="calendar_schema"><label>Nom exact de la base<input name="confirm_database" required autocomplete="off"></label><label class="upgrade-confirm"><input type="checkbox" name="confirm" value="yes" required> Sauvegarde et destination vérifiées.</label><button>Créer le schéma 007 · Invitations calendrier</button></form></section>';
}
function concertsCalendarEmails(string $raw): array {
    if (strlen($raw)>10000) throw new RuntimeException('Liste de destinataires trop longue.');
    $emails=[];
    foreach (preg_split('/[\s,;]+/',trim($raw),-1,PREG_SPLIT_NO_EMPTY) as $value) {
        $email=MusicbandEmail($value);
        // Les adresses servent aussi de CAL-ADDRESS : refuser les séparateurs iCalendar.
        if ($email==='' || preg_match('/["\\\\;,:<>]/',$email)) throw new RuntimeException('Adresse destinataire invalide. Utiliser des adresses simples, sans nom affiché.');
        $emails[$email]=$email;
    }
    if (count($emails)>CONCERTS_CALENDAR_MAX_RECIPIENTS) throw new RuntimeException('Maximum '.CONCERTS_CALENDAR_MAX_RECIPIENTS.' destinataires.');
    sort($emails,SORT_STRING); return array_values($emails);
}
function concertsCalendarSettings(PDO $db,bool $lock=false): array {
    $row=$db->query('SELECT recipients_json,revision FROM concerts_calendar_settings WHERE id=1'.($lock?' FOR UPDATE':''))->fetch();
    if (!$row) throw new RuntimeException('Réglage calendrier absent : vérifier le schéma 007.');
    $emails=json_decode($row['recipients_json'],true,8,JSON_THROW_ON_ERROR);
    if (!is_array($emails) || array_filter($emails,fn($value)=>!is_string($value))) throw new RuntimeException('Destinataires calendrier invalides.');
    return ['emails'=>concertsCalendarEmails(implode("\n",$emails)),'revision'=>$row['revision']];
}
function concertsCalendarSettingsSave(array $user): string {
    if (!$user['admin']) throw new RuntimeException('Accès administrateur requis.');
    $raw=$_POST['calendar_recipients']??null;
    if (!is_string($raw)) throw new RuntimeException('Liste de destinataires invalide.');
    $emails=concertsCalendarEmails($raw); $db=MusicbandDatabase(businessSettings()); concertsCalendarRequire($db);
    $db->beginTransaction();
    try {
        $old=concertsCalendarSettings($db,true);
        if (!is_string($_POST['calendar_revision']??null) || !hash_equals($old['revision'],$_POST['calendar_revision'])) throw new RuntimeException('Les destinataires ont changé ailleurs : recharger la page.');
        $q=$db->prepare('UPDATE concerts_calendar_settings SET recipients_json=?,revision=?,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=1');
        $q->execute([businessJson($emails),bin2hex(random_bytes(16)),$user['login']]); $db->commit();
        return 'Destinataires calendrier enregistrés. Aucun envoi déclenché ; les concerts déjà traités restent bloqués.';
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function concertsCalendarSettingsForm(string $hidden): string {
    $html='<section><h2>Invitations calendrier des concerts</h2>';
    try {
        $db=MusicbandDatabase(businessSettings()); concertsCalendarRequire($db); $settings=concertsCalendarSettings($db);
        $html.='<p>Destinataires communs aux concerts, indépendants des comptes du site. Une adresse par ligne (maximum '.CONCERTS_CALENDAR_MAX_RECIPIENTS.'). Une liste vide désactive les nouveaux envois. Les destinataires seront visibles entre eux dans l’invitation. L’expéditeur configuré dans E-mails sera l’organisateur.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="calendar_settings"><input type="hidden" name="calendar_revision" value="'.MusicbandEscape($settings['revision']).'"><label>Destinataires<textarea class="Musicband-textarea" name="calendar_recipients" rows="6" maxlength="10000" >'.MusicbandEscape(implode("\n",$settings['emails'])).'</textarea></label><button>Enregistrer les destinataires calendrier</button></form>';
    } catch (Throwable $e) { $html.='<p>Configuration indisponible : vérifier MySQL et le schéma 007 dans Upgrade.</p>'; }
    return $html.'</section>';
}
function concertsCalendarLocal(string $date,string $time,DateTimeZone $zone): DateTimeImmutable {
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D',$time)) throw new RuntimeException('Horaires requis au format HH:MM ou HH:MM:SS.');
    if (strlen($time)===5) $time.=':00';
    $wall=$date.' '.$time; $naive=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$wall,new DateTimeZone('UTC'));
    if (!$naive || $naive->format('Y-m-d H:i:s')!==$wall) throw new RuntimeException('Date ou horaire invalide.');
    $stamp=$naive->getTimestamp(); $candidates=[];
    foreach ($zone->getTransitions($stamp-172800,$stamp+172800)?:[] as $transition) {
        $candidate=(new DateTimeImmutable('@'.($stamp-$transition['offset'])))->setTimezone($zone);
        if ($candidate->format('Y-m-d H:i:s')===$wall) $candidates[$candidate->getTimestamp()]=$candidate;
    }
    if (count($candidates)!==1) throw new RuntimeException('Horaire inexistant ou ambigu au changement d’heure : corriger la fiche avant envoi.');
    return array_values($candidates)[0];
}
function concertsCalendarEligible(array $row): bool {
    $policy=businessListSettings();
    $date=businessConcertDate($row['date_concert']??null,$policy,businessDateImported($row));
    $today=(new DateTimeImmutable('today',new DateTimeZone($policy['timezone'])))->format('Y-m-d');
    if ($date===null || $date<$today || trim((string)($row['adresse']??''))==='') return false;
    foreach (['heure_arrivee','heure_prestation'] as $key) if (in_array(businessTemporalValue($key,(string)($row[$key]??'')),[null,''],true)) return false;
    $minutes=trim((string)($row['duree']??''));
    return preg_match('/^[0-9]{1,4}$/D',$minutes)===1 && (int)$minutes>=1 && (int)$minutes<=CONCERTS_CALENDAR_MAX_MINUTES;
}
function concertsCalendarSnapshot(PDO $db,array $row,bool $lock=false): array {
    if (!concertsCalendarEligible($row)) throw new RuntimeException('Invitation réservée aux concerts à venir (aujourd’hui inclus), avec adresse, heure d’arrivée, heure de prestation et durée valides renseignées.');
    foreach (['evenement','date_concert','adresse','heure_arrivee','heure_prestation','duree'] as $key) if (!fieldVisibilityVisible('concerts',$key)) throw new RuntimeException('Afficher les champs du calendrier dans Configuration avant de confirmer l’envoi.');
    $policy=businessListSettings(); $zone=new DateTimeZone($policy['timezone']);
    $date=businessConcertDate($row['date_concert']??null,$policy,businessDateImported($row));
    if ($date===null || trim($row['evenement']??'')==='' || trim($row['adresse']??'')==='') throw new RuntimeException('Renseigner un événement, une date valide et l’adresse de prestation avant envoi.');
    $minutes=trim($row['duree']??'');
    if (!preg_match('/^[0-9]{1,4}$/D',$minutes) || (int)$minutes<1 || (int)$minutes>CONCERTS_CALENDAR_MAX_MINUTES) throw new RuntimeException('Durée requise en minutes entières, entre 1 et '.CONCERTS_CALENDAR_MAX_MINUTES.'.');
    $start=concertsCalendarLocal($date,businessTemporalValue('heure_arrivee',(string)($row['heure_arrivee']??''))??'',$zone);
    $showTime=businessTemporalValue('heure_prestation',(string)($row['heure_prestation']??''))??'';
    // Comparaison des heures civiles avant toute conversion de fuseau.
    $arrivalTime=$start->format('H:i:s');
    if (strlen($showTime)===5) $showTime.=':00';
    $nextDay=$showTime<$arrivalTime;
    $showDate=$nextDay?(new DateTimeImmutable($date.' 12:00:00',$zone))->modify('+1 day')->format('Y-m-d'):$date;
    $show=concertsCalendarLocal($showDate,$showTime,$zone);
    $end=$show->setTimestamp($show->getTimestamp()+((int)$minutes+60)*60);
    if ($end<=$start) throw new RuntimeException('La fin de l’invitation doit suivre l’arrivée.');
    $settings=concertsCalendarSettings($db,$lock);
    if (!$settings['emails']) throw new RuntimeException('Configurer au moins un destinataire calendrier dans Configuration.');
    $from=MusicbandMailFrom();
    if (preg_match('/["\\\\;,:<>]/',$from)) throw new RuntimeException('Utiliser une adresse expéditrice simple pour le calendrier.');
    $data=['concert_id'=>(int)$row['id'],'title'=>$row['evenement'],'location'=>$row['adresse'],
        'timezone'=>$policy['timezone'],'start'=>$start->format(DateTimeInterface::ATOM),'performance'=>$show->format(DateTimeInterface::ATOM),
        'end'=>$end->format(DateTimeInterface::ATOM),'minutes'=>(int)$minutes,'next_day'=>$nextDay,
        'from'=>$from,'recipients'=>$settings['emails'],'recipients_revision'=>$settings['revision'],'field_policy'=>fieldVisibilityVersion('concerts')];
    $template=concertsCalendarTemplate();$data['template_version']=hash('sha256',$template);
    // Origine configurée, jamais l’en-tête Host ; le lien ne confère aucun droit.
    $concertUrl=Musicband_PUBLIC_ORIGIN.MusicbandBase().'concerts.php?id='.(int)$row['id'];
    $data['body']=strtr($template,['{evenement}'=>$data['title'],'{adresse}'=>$data['location'],'{date}'=>MusicbandDisplayDate($data['start']),'{arrivee}'=>MusicbandDisplayDate($data['start'],true,false,true),'{prestation}'=>MusicbandDisplayDate($data['performance'],true,false,true),'{fin}'=>MusicbandDisplayDate($data['end'],true,false,true),'{duree}'=>(string)$data['minutes'],'{lien_concert}'=>$concertUrl]);
    return $data;
}
function concertsCalendarText(string $value): string {
    return str_replace(["\\","\r\n","\r","\n",';',','],["\\\\","\\n","\\n","\\n","\\;","\\,"],$value);
}
function concertsCalendarIcs(array $data,string $uid): string {
    $utc=new DateTimeZone('UTC');
    $lines=['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//Musicband AI//Concerts//FR','CALSCALE:GREGORIAN','METHOD:REQUEST','BEGIN:VEVENT',
        'UID:'.$uid,'DTSTAMP:'.gmdate('Ymd\THis\Z'),'SEQUENCE:0','STATUS:CONFIRMED',
        'DTSTART:'.(new DateTimeImmutable($data['start']))->setTimezone($utc)->format('Ymd\THis\Z'),
        'DTEND:'.(new DateTimeImmutable($data['end']))->setTimezone($utc)->format('Ymd\THis\Z'),
        'SUMMARY:'.concertsCalendarText($data['title']),'LOCATION:'.concertsCalendarText($data['location']),
        'DESCRIPTION:'.concertsCalendarText($data['body']),
        'ORGANIZER:mailto:'.str_replace('%40','@',rawurlencode($data['from']))];
    foreach ($data['recipients'] as $email) $lines[]='ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:'.str_replace('%40','@',rawurlencode($email));
    array_push($lines,'END:VEVENT','END:VCALENDAR');
    $output='';
    foreach ($lines as $line) {
        // Repli UTF-8 par octets, sans dépendance mbstring ni caractère coupé.
        while (strlen($line)>75) {
            $cut=75; while ((ord($line[$cut])&0xC0)===0x80) $cut--;
            $output.=substr($line,0,$cut)."\r\n"; $line=' '.substr($line,$cut);
        }
        $output.=$line."\r\n";
    }
    return $output;
}
function concertsCalendarSend(array $user): int {
    MusicbandRequirePermission('calendar.send',$user);
    if (($_POST['calendar_confirm']??'')!=='yes') throw new RuntimeException('Confirmer explicitement l’envoi de l’invitation.');
    $id=businessId($_POST['id']??''); $db=MusicbandDatabase(businessSettings()); concertsCalendarRequire($db); fieldVisibilityUse($db);
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT * FROM concerts_evenements WHERE id=? FOR UPDATE'); $q->execute([$id]); $row=$q->fetch();
        if (!$row) throw new RuntimeException('Concert introuvable.');
        $q=$db->prepare('SELECT status FROM concerts_calendar_invitations WHERE concert_id=?'); $q->execute([$id]);
        if ($q->fetch()) throw new RuntimeException('Une tentative existe déjà pour ce concert. Aucun nouvel envoi autorisé.');
        $data=concertsCalendarSnapshot($db,$row,true);
        if (!is_string($_POST['calendar_version']??null) || !hash_equals(hash('sha256',businessJson($data)),$_POST['calendar_version'])) throw new RuntimeException('Le concert, l’expéditeur ou les destinataires ont changé : recharger la confirmation.');
        $uid=bin2hex(random_bytes(24)).'@Musicband-calendar'; $ics=concertsCalendarIcs($data,$uid);
        $q=$db->prepare("INSERT INTO concerts_calendar_invitations (concert_id,event_uid,status,snapshot_json,requested_by,requested_at) VALUES (?,?,'reserved',?,?,UTC_TIMESTAMP(6))");
        $q->execute([$id,$uid,businessJson($data),$user['login']]);
        // Réservation DURABLE avant mail : une interruption ne permet jamais de rejouer.
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    $state='uncertain';
    try {
        require_once __DIR__ . '/email_service.php';
        $sendResult=MusicbandSendEmail([
            'to'=>$data['recipients'],
            'from'=>$data['from'],
            'subject'=>'Musicband - Invitation concert',
            'text'=>$data['body'],
            'attachments'=>[
                [
                    'filename'=>'concert.ics',
                    'content'=>$ics,
                    'mime'=>'text/calendar; charset=UTF-8; method=REQUEST',
                    'disposition'=>'inline',
                ]
            ],
            'type'=>'calendar_invitation',
        ]);
        $state=($sendResult['status']==='accepted')?'accepted':'failed';
    } catch (Throwable $e) { error_log('Musicband_calendar_mail_uncertain: '.$e->getMessage()); }
    try {
        $q=$db->prepare('UPDATE concerts_calendar_invitations SET status=?,completed_at=UTC_TIMESTAMP(6) WHERE concert_id=?'); $q->execute([$state,$id]);
    } catch (Throwable $e) { error_log('Musicband_calendar_status_unconfirmed'); }
    return $id;
}
function concertsCalendarPanel(PDO $db,array $row): string {
    if (!MusicbandCan('calendar.send')) return '';
    $html='<section><h3>Invitation calendrier</h3>';
    try {
        concertsCalendarRequire($db);
        $q=$db->prepare('SELECT status,requested_at,requested_by FROM concerts_calendar_invitations WHERE concert_id=?'); $q->execute([$row['id']]); $sent=$q->fetch();
        if ($sent) {
            $labels=['accepted'=>'Invitation acceptée par le service mail ; réception à vérifier.', 'failed'=>'Envoi refusé par le service mail.', 'reserved'=>'Tentative réservée : en cours ou résultat non confirmé.', 'uncertain'=>'Résultat de l’envoi incertain.'];
            return $html.'<p role="status">'.($labels[$sent['status']]??'Tentative enregistrée.').' Aucun nouvel envoi autorisé.</p><p>Déclenchée le '.MusicbandEscape(MusicbandDisplayDate($sent['requested_at'],true,true)).' par '.MusicbandEscape($sent['requested_by']).'.</p></section>';
        }
        if (!concertsCalendarEligible($row)) return '';
        if (!isset($_GET['calendar']) && ($_POST['action']??'')!=='calendar_send') return $html.'<p><a class="business-action" href="'.MusicbandEscape(MusicbandBase()).'concerts.php?id='.(int)$row['id'].'&amp;calendar=1">Inviter au calendrier…</a></p></section>';
        $data=concertsCalendarSnapshot($db,$row);
        $html.='<p>Vérifier avant envoi. Une seule tentative est autorisée, même en cas d’échec ou d’interruption. Les adresses seront visibles entre destinataires. Aucun renvoi, mise à jour ni annulation automatique après modification du concert.</p><dl>';
        foreach (['title'=>'Événement','location'=>'Localisation','from'=>'Organisateur','start'=>'Début · arrivée','performance'=>'Heure de prestation','minutes'=>'Durée de prestation (min.)','end'=>'Fin · prestation + durée + 1 h'] as $key=>$label) $html.='<dt>'.$label.'</dt><dd>'.MusicbandEscape(in_array($key,['start','performance','end'],true)?MusicbandDisplayDate($data[$key],true,false,true):(string)$data[$key]).'</dd>';
        $html.='<dt>Texte de l’invitation</dt><dd>'.nl2br(MusicbandEscape($data['body'])).'</dd>';
        $html.='<dt>Destinataires</dt><dd>'.MusicbandEscape(implode(', ',$data['recipients'])).'</dd></dl>';
        if ($data['next_day']) $html.='<p class="notice">La prestation est interprétée au lendemain de l’arrivée. Vérifier les dates ci-dessus.</p>';
        $html.='<form method="post" action="'.MusicbandEscape(MusicbandBase()).'concerts.php"><input type="hidden" name="csrf" value="'.MusicbandEscape($_SESSION['csrf']).'"><input type="hidden" name="action" value="calendar_send"><input type="hidden" name="id" value="'.(int)$row['id'].'"><input type="hidden" name="calendar_version" value="'.hash('sha256',businessJson($data)).'"><label><input type="checkbox" name="calendar_confirm" value="yes" required> Je confirme les informations, les destinataires et l’envoi unique.</label><button>Confirmer et envoyer l’invitation</button></form><p><a href="'.MusicbandEscape(MusicbandBase()).'concerts.php?id='.(int)$row['id'].'">Annuler</a></p>';
    } catch (Throwable $e) { $html.='<p class="notice">'.MusicbandEscape($e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'Invitation indisponible : vérifier MySQL et le schéma 007.').'</p>'; }
    return $html.'</section>';
}
