<?php
/* Musicband WORKSPACE CALENDAR: lecture Google Calendar, iCal privé en Community, OAuth ailleurs. */
/* Accès Google Calendar en lecture via documents_delivery.php et son jeton OAuth partagé hors Community.
 * Community délègue au lecteur iCal privé, sans jeton OAuth ni dépendance à Drive.
 * Les réglages d’agenda sont privés ; les dates sont affichées dans le fuseau applicatif.
 * Les appels réseau retournent une page d’événements et son curseur, sans copie persistante des événements.
 */
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
require_once __DIR__ . '/documents_delivery.php';
if (defined('MUSICBAND_COMMUNITY') && MUSICBAND_COMMUNITY) require_once __DIR__ . '/community_calendar.php';
function workspaceCalendarSettings(): array {
    $saved=MusicbandRead('home_settings')['workspace_calendar']??[];
    if(!is_array($saved))throw new RuntimeException('Configuration Agenda invalide.');
    return array_replace(['id'=>'','revision'=>'','ical_url'=>''],$saved);
}
function workspaceCalendarSettingsSave(): string {
    if(!MusicbandRequireLogin()['admin'])throw new RuntimeException('Accès administrateur requis.');
    $settings=MusicbandRead('home_settings');$old=workspaceCalendarSettings();
    if(!is_string($_POST['calendar_revision']??null)||!hash_equals($old['revision'],$_POST['calendar_revision']))throw new RuntimeException('Agenda modifié ailleurs : recharger Configuration.');
    if (defined('MUSICBAND_COMMUNITY') && MUSICBAND_COMMUNITY) {
        $url = $_POST['calendar_ical_url'] ?? null;
        if (!is_string($url)) throw new RuntimeException('Adresse iCal invalide.');
        $url = trim($url);
        if ($url !== '') communityCalendarValidateUrl($url);
        $clear = ($_POST['calendar_ical_clear'] ?? '') === '1';
        // Un formulaire vide ne prouve pas qu’une adresse a été configurée.
        if (!$clear && $url === '' && $old['ical_url'] === '') throw new RuntimeException('Aucune adresse iCal enregistrée : renseigner l’adresse secrète avant d’enregistrer.');
        $saved = $old;
        $saved['ical_url'] = $clear ? '' : ($url === '' ? $old['ical_url'] : $url);
        $saved['revision'] = bin2hex(random_bytes(16));
        $settings['workspace_calendar'] = $saved;
        MusicbandWrite('home_settings', $settings);
        // Relire le stockage partagé avec la page Agenda, sans afficher ni journaliser le secret.
        $persisted = workspaceCalendarSettings();
        if ($persisted['revision'] !== $saved['revision'] || $persisted['ical_url'] !== $saved['ical_url']) throw new RuntimeException('Enregistrement Agenda non confirmé : recharger Configuration avant de réessayer.');
        if ($clear) return 'Agenda déconnecté. Aucun appel Google effectué.';
        return ($url === '' ? 'Adresse iCal existante conservée.' : 'Adresse iCal enregistrée et relue.') . ' L’accès Google n’a pas été testé : ouvrir Agenda pour consulter les événements.';
    }
    $id=$_POST['calendar_id']??null;
    if(!is_string($id)||trim($id)===''||strlen($id)>1024||preg_match('/[\x00-\x20\x7f]/',$id))throw new RuntimeException('Identifiant du calendrier invalide.');
    $settings['workspace_calendar']=['id'=>$id,'revision'=>bin2hex(random_bytes(16))];MusicbandWrite('home_settings',$settings);
    return 'Calendrier enregistré. Aucun appel Google effectué. Les accès OAuth existants sont conservés.';
}
function workspaceCalendarSettingsForm(string $hidden): string {
    $settings=workspaceCalendarSettings();
    if (defined('MUSICBAND_COMMUNITY') && MUSICBAND_COMMUNITY) return communityCalendarSettingsForm($hidden, $settings);
    return '<section><h3>Calendrier Google Workspace</h3><form method="post">'.$hidden.'<input type="hidden" name="action" value="workspace_calendar_settings"><input type="hidden" name="calendar_revision" value="'.MusicbandEscape($settings['revision']).'"><label>Identifiant du calendrier<input name="calendar_id" maxlength="1024" value="'.MusicbandEscape($settings['id']).'" required></label><p>Pour un calendrier principal, utiliser son adresse Workspace. L’accès OAuth de Configuration → Devis / Factures est réutilisé ; il doit autoriser Calendar en lecture et avoir accès au calendrier. Le partage du calendrier et les scopes OAuth sont deux prérequis distincts.</p><button>Enregistrer le calendrier</button></form><p>Consultation réservée aux administrateurs par défaut. Dans Upgrade, compléter RBAC 010, puis accorder Agenda · consulter aux gestionnaires concernés. Aucun événement créé ou modifié ; aucune copie persistante dans MySQL ou sur disque.</p></section>';
}
/** Lit une page depuis un instant Unix UTC et un curseur ; diagnostics sans URL privée ni contenu d'événement. */
function workspaceCalendarEvents(string $calendar,int $from,string $cursor): array {
    if (defined('MUSICBAND_COMMUNITY') && MUSICBAND_COMMUNITY) return communityCalendarEvents($from, $cursor);
    $parameters=['timeMin'=>gmdate('Y-m-d\TH:i:s\Z',$from),'singleEvents'=>'true','orderBy'=>'startTime','showDeleted'=>'false','maxResults'=>50,'timeZone'=>MusicbandDateZone()->getName(),'fields'=>'nextPageToken,items(summary,location,start,end,status,htmlLink)'];
    if($cursor!=='')$parameters['pageToken']=$cursor;
    try{
        $token=documentsGoogleToken();
        $url='https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendar).'/events?'.http_build_query($parameters,'','&',PHP_QUERY_RFC3986);
        $result=documentsGoogleJson($token,$url);
    }catch(Throwable $e){
        // Ne jamais afficher la réponse Google, les secrets ou les titres privés dans un diagnostic.
        preg_match('/HTTP\s+(\d{3})/',$e->getMessage(),$status);
        throw new RuntimeException('Lecture du calendrier indisponible'.(isset($status[1])?' (HTTP '.$status[1].')':'').'. Vérifier les accès OAuth Drive existants, le scope Calendar en lecture, l’API Calendar activée et le partage du calendrier. Aucun événement modifié.');
    }
    if(!is_array($result['items']??null)||!is_string($result['nextPageToken']??''))throw new RuntimeException('Réponse calendrier invalide.');
    return $result;
}
/** Présente événements horaires ou journées entières ; la date de fin exclusive Google est ramenée au dernier jour inclus. */
function workspaceCalendarWhen(array $event): string {
    $start=$event['start']??[];$end=$event['end']??[];
    if(isset($start['date'])){
        $label=MusicbandDisplayDate($start['date'],false,false,true).' · journée entière';
        if(isset($end['date'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$end['date'])){
            $last=(new DateTimeImmutable($end['date'],MusicbandDateZone()))->modify('-1 day')->format('Y-m-d');
            if($last>$start['date'])$label.=' → '.MusicbandDisplayDate($last,false,false,true);
        }
        return $label;
    }
    if(!is_string($start['dateTime']??null))return 'Date non précisée';
    return MusicbandDisplayDate($start['dateTime'],true,false,true).(is_string($end['dateTime']??null)?' → '.MusicbandDisplayDate($end['dateTime'],true):'');
}
