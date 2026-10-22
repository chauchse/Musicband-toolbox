<?php
/* Musicband AGENDA: prochains événements Google, lecture seule et session requise.
 * Community utilise le lien iCal privé ; les autres éditions conservent leur accès OAuth.
 */
/* Route authentifiée de consultation paginée de Google Calendar.
 * Dépend de auth.php, business.php et workspace_calendar.php pour le lecteur iCal/OAuth, les droits et le formatage.
 * Effectue une lecture distante ; aucun événement Google n’est créé ou modifié.
 */
require_once __DIR__.'/auth.php';
require_once __DIR__.'/business.php';
require_once __DIR__.'/workspace_calendar.php';
$user=MusicbandRequireLogin();
try{MusicbandRequirePermission('calendar.read',$user);}catch(Throwable $e){MusicbandFail('Consultation de l’agenda non autorisée pour ce profil.',403);}
$content='<details><summary>Actions · Agenda</summary><p><a href="'.MusicbandEscape(MusicbandBase()).'calendar.php">Actualiser les prochains événements</a></p></details>';
try{
    $settings=workspaceCalendarSettings();$now=time();$from=$_GET['from']??(string)$now;$cursor=$_GET['cursor']??'';
    if(!is_string($from)||!ctype_digit($from)||strlen($from)>10||(int)$from<$now-3600||(int)$from>$now||!is_string($cursor)||strlen($cursor)>4096||preg_match('/[\x00-\x20\x7f]/',$cursor))throw new RuntimeException('Page expirée ou paramètres invalides : actualiser les prochains événements.');
    session_write_close();$result=workspaceCalendarEvents($settings['id'],(int)$from,$cursor);
    $content.='<p>'.(defined('MUSICBAND_COMMUNITY') && MUSICBAND_COMMUNITY ? 'Agenda Google · lecture iCal à la demande · horizon de 12 mois.' : 'Calendrier '.MusicbandEscape($settings['id']).' · lecture à la demande · événements à venir ou en cours.').'</p>';
    $count=0;$groups=[];
    $months=[1=>'Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    $shortMonths=[1=>'Jan','Fév','Mar','Avr','Mai','Jui','Jui','Aoû','Sep','Oct','Nov','Déc'];
    foreach($result['items'] as $event){
        if(!is_array($event)||($event['status']??'')==='cancelled')continue;$count++;
        $title=is_string($event['summary']??null)&&trim($event['summary'])!==''?$event['summary']:'Événement sans titre';
        // Une journée entière garde sa date civile ; les horaires sont regroupés en heure locale.
        $start=$event['start']??[];$date=null;
        $raw=$start['date']??$start['dateTime']??null;
        if(is_string($raw))try{$date=(new DateTimeImmutable($raw,MusicbandDateZone()))->setTimezone(MusicbandDateZone());}catch(Throwable $e){}
        $year=$date?$date->format('Y'):'Sans date';$month=$date?(int)$date->format('n'):0;
        $card='<article class="agenda-event"><div class="agenda-date">'.($date?'<time datetime="'.MusicbandEscape($date->format('Y-m-d')).'"><strong>'.$date->format('d').'</strong><span title="'.MusicbandEscape($months[$month]).'">'.MusicbandEscape($shortMonths[$month]).'</span></time>':'Date non précisée').'</div><div class="agenda-event-content"><h3>'.MusicbandEscape($title).'</h3><p class="agenda-when">'.MusicbandEscape(workspaceCalendarWhen($event)).'</p>';
        if(is_string($event['location']??null)&&trim($event['location'])!=='')$card.='<p>'.MusicbandEscape($event['location']).'</p>';
        $link=$event['htmlLink']??'';$parts=is_string($link)?parse_url($link):false;
        if($parts&&($parts['scheme']??'')==='https'&&in_array($parts['host']??'',['www.google.com','calendar.google.com'],true)&&!isset($parts['user'])&&!isset($parts['pass']))$card.='<p><a href="'.MusicbandEscape($link).'" target="_blank" rel="noopener noreferrer">Ouvrir dans Google Agenda</a></p>';
        $groups[$year][$month][]=$card.'</div></article>';
    }
    // Les compteurs décrivent la page reçue, sans charger les pages API suivantes.
    $content.='<div class="agenda-groups">';$openedMonths=0;
    foreach($groups as $year=>$yearMonths){
        $yearCount=array_sum(array_map('count',$yearMonths));
        $content.='<details class="agenda-year"'.($openedMonths<3?' open':'').'><summary>'.MusicbandEscape((string)$year).' <small>('.$yearCount.' sur cette page)</small></summary>';
        foreach($yearMonths as $month=>$cards){
            $content.='<details class="agenda-month"'.($openedMonths<3?' open':'').'><summary>'.MusicbandEscape($months[$month]??'Date non précisée').' <small>('.count($cards).')</small></summary>'.implode('',$cards).'</details>';
            $openedMonths++;
        }
        $content.='</details>';
    }
    $content.='</div>';
    if(!$count)$content.='<p>Aucun événement sur cette page.</p>';
    if(($result['nextPageToken']??'')!=='')$content.='<nav><a href="'.MusicbandEscape(MusicbandBase().'calendar.php?'.http_build_query(['from'=>$from,'cursor'=>$result['nextPageToken']])).'">Événements suivants →</a></nav>';
}catch(Throwable $e){$content.='<p class="notice" role="alert">'.MusicbandEscape($e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Agenda indisponible. Vérifier la configuration et les autorisations.').'</p>';}
MusicbandScreen('Agenda · prochains événements',$content);
