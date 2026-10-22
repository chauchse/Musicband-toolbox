<?php
/** Lecture seule d'un agenda Google personnel par adresse iCal privée, sans OAuth.
 * Dépend de auth.php, business.php et des réglages de workspace_calendar.php ; PHP/cURL standard.
 * URL secrète conservée dans home_settings privé, jamais rendue dans les pages ou diagnostics.
 * Téléchargement borné en mémoire, sans cache disque ni écriture Google. Expansion sur 366 jours.
 * Les récurrences non prises en charge provoquent une erreur explicite, jamais une liste tronquée silencieuse.
 */
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

/** N'accepte que le point de lecture Google attendu ; pas d'hôte arbitraire ni de redirection. */
function communityCalendarValidateUrl(string $url): void {
    if (strlen($url) > 2048 || !preg_match('~\Ahttps://calendar\.google\.com/calendar/ical/[A-Za-z0-9@._%+\-]+/private-[a-fA-F0-9]+/basic\.ics\z~D', $url)) {
        throw new RuntimeException('Utiliser l’adresse secrète au format iCal fournie par Google Agenda (calendar.google.com).');
    }
}

function communityCalendarSettingsForm(string $hidden, array $settings): string {
    return '<section><h3>Agenda Google · lecture seule</h3><p>Dans Google Agenda sur ordinateur : Paramètres → votre agenda → Intégrer l’agenda → Adresse secrète au format iCal. Compatible avec un compte Google personnel, sans Google Cloud ni connexion Google sur ce site.</p>'
        . '<p role="status">'.($settings['ical_url'] !== '' ? 'État enregistré : adresse iCal configurée. Son accès sera vérifié à l’ouverture de l’Agenda.' : 'État enregistré : aucun agenda configuré.').'</p>'
        . '<form method="post">'.$hidden.'<input type="hidden" name="action" value="workspace_calendar_settings"><input type="hidden" name="calendar_revision" value="'.MusicbandEscape($settings['revision']).'">'
        . '<label>Adresse secrète iCal<input type="password" name="calendar_ical_url" maxlength="2048" autocomplete="new-password" placeholder="'.($settings['ical_url'] !== '' ? 'Adresse configurée — laisser vide pour conserver' : 'https://calendar.google.com/calendar/ical/…/basic.ics').'"></label>'
        . '<label><input type="checkbox" name="calendar_ical_clear" value="1"> Déconnecter cet agenda</label><p>Cette adresse donne accès aux événements : ne la partagez pas. Les utilisateurs autorisés à consulter l’Agenda verront ses événements. Préférez un agenda dédié au groupe. En cas de fuite, réinitialisez l’adresse dans Google Agenda.</p><button>Enregistrer l’agenda</button></form>'
        . '<p>Événements à venir ou en cours sur 12 mois, actualisés à la demande. Aucune modification dans Google. Les droits Agenda · consulter restent applicables.</p></section>';
}

/** Lecture HTTPS bornée : ni réponse brute ni URL dans une exception. */
function communityCalendarDownload(string $url): string {
    communityCalendarValidateUrl($url);
    if (!function_exists('curl_init')) throw new RuntimeException('Activer l’extension PHP cURL pour lire l’agenda.');
    $body = ''; $overflow = false;
    $handle = curl_init($url);
    if ($handle === false) throw new RuntimeException('Lecture iCal indisponible.');
    try {
        curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>25, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function($handle, string $chunk) use (&$body, &$overflow): int {
                if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) { $overflow = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($handle);
        if ($overflow) throw new RuntimeException('Agenda trop volumineux (limite 4 Mio). Utiliser un agenda dédié au groupe.');
        if ($ok === false || curl_getinfo($handle, CURLINFO_HTTP_CODE) !== 200) throw new RuntimeException('Lecture iCal indisponible. Vérifier l’adresse secrète et l’accès HTTPS sortant de l’hébergement.');
    } finally { curl_close($handle); }
    return $body;
}

/** Date civile ou horaire iCal : les fuseaux IANA et UTC sont conservés pendant l'expansion. */
function communityCalendarDate(array $property): DateTimeImmutable {
    [$params, $value] = $property;
    try { $zone = isset($params['TZID']) ? new DateTimeZone(trim($params['TZID'], '"')) : MusicbandDateZone(); }
    catch (Throwable $e) { throw new RuntimeException('Fuseau iCal non pris en charge.'); }
    if (preg_match('/^\d{8}$/D', $value)) $format = '!Ymd';
    elseif (preg_match('/^\d{8}T\d{6}Z?$/D', $value)) {
        $format = '!Ymd\THis';
        if (str_ends_with($value, 'Z')) { $zone = new DateTimeZone('UTC'); $value = substr($value, 0, -1); }
    } else throw new RuntimeException('Date iCal non prise en charge.');
    $date = DateTimeImmutable::createFromFormat($format, $value, $zone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) throw new RuntimeException('Date iCal invalide.');
    if ((int)$date->format('Y') < 1900 || (int)$date->format('Y') > 2200) throw new RuntimeException('Date iCal hors limites (1900–2200).');
    return $date;
}

/** Déplie les lignes RFC 5545 et isole VEVENT, sans interpréter les alarmes imbriquées. */
function communityCalendarParse(string $body): array {
    if (!str_starts_with($body, 'BEGIN:VCALENDAR') || !str_contains($body, 'END:VCALENDAR')) throw new RuntimeException('Réponse iCal invalide.');
    $body = preg_replace('/\r?\n[ \t]/', '', $body);
    $events = []; $event = null; $depth = 0;
    foreach (preg_split('/\r\n|\n|\r/', $body) as $line) {
        if (strlen($line) > 65536) throw new RuntimeException('Ligne iCal trop volumineuse.');
        if ($line === 'BEGIN:VEVENT') { if ($event !== null) throw new RuntimeException('Structure iCal invalide.'); $event = []; $depth = 0; continue; }
        if ($line === 'END:VEVENT') {
            if ($event === null || $depth !== 0) throw new RuntimeException('Structure iCal invalide.');
            $events[] = $event; $event = null;
            if (count($events) > 10000) throw new RuntimeException('Agenda limité à 10 000 événements sources.');
            continue;
        }
        if ($event === null) continue;
        if (str_starts_with($line, 'BEGIN:')) { $depth++; continue; }
        if (str_starts_with($line, 'END:')) { $depth--; continue; }
        if ($depth !== 0) continue;
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) throw new RuntimeException('Propriété iCal invalide.');
        $head = explode(';', $parts[0]); $name = strtoupper(array_shift($head)); $params = [];
        foreach ($head as $parameter) { $pair = explode('=', $parameter, 2); if (count($pair) === 2) $params[strtoupper($pair[0])] = $pair[1]; }
        $event[$name][] = [$params, $parts[1]];
    }
    if ($event !== null) throw new RuntimeException('Agenda iCal incomplet.');
    return $events;
}

function communityCalendarText(string $value): string {
    return strtr($value, ['\\n'=>"\n", '\\N'=>"\n", '\\,'=>',', '\\;'=>';', '\\\\'=>'\\']);
}

/** Expansion bornée des règles usuelles ; les variantes complexes sont refusées explicitement. */
function communityCalendarOccurrences(array $event, DateTimeImmutable $start, DateTimeImmutable $limit, int &$budget): array {
    $dates = [$start->getTimestamp()=>$start];
    if (isset($event['EXRULE']) || count($event['RRULE'] ?? []) > 1) throw new RuntimeException('Récurrence iCal non prise en charge (EXRULE ou règles multiples).');
    if (isset($event['RRULE'])) {
        $rule = [];
        foreach (explode(';', $event['RRULE'][0][1]) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2 || isset($rule[$pair[0]])) throw new RuntimeException('Règle iCal invalide.');
            $rule[$pair[0]] = $pair[1];
        }
        if (array_diff(array_keys($rule), ['FREQ','INTERVAL','COUNT','UNTIL','BYDAY','BYMONTHDAY','BYMONTH','WKST']) || !in_array($rule['FREQ'] ?? '', ['DAILY','WEEKLY','MONTHLY','YEARLY'], true)) throw new RuntimeException('Récurrence iCal non prise en charge. Règles admises : quotidiennes, hebdomadaires, mensuelles, annuelles ; pas de BYSETPOS/BYHOUR.');
        foreach (['INTERVAL','COUNT'] as $key) if (isset($rule[$key]) && (!ctype_digit($rule[$key]) || (int)$rule[$key] < 1 || (int)$rule[$key] > 200000)) throw new RuntimeException('Limite de récurrence iCal invalide.');
        if (isset($rule['COUNT'], $rule['UNTIL'])) throw new RuntimeException('Règle iCal ambiguë : COUNT et UNTIL simultanés.');
        $weekdays = ['MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6,'SU'=>7];
        $weekStart = $weekdays[$rule['WKST'] ?? 'MO'] ?? 0;
        if (!$weekStart) throw new RuntimeException('Début de semaine iCal invalide.');
        $byDay = isset($rule['BYDAY']) ? explode(',', $rule['BYDAY']) : [];
        foreach ($byDay as $day) {
            if (!preg_match('/^([+-]?[1-5])?(MO|TU|WE|TH|FR|SA|SU)$/D', $day, $m)) throw new RuntimeException('Jour de récurrence iCal invalide.');
            if (($m[1] ?? '') !== '' && (!in_array($rule['FREQ'], ['MONTHLY','YEARLY'], true) || ($rule['FREQ'] === 'YEARLY' && !isset($rule['BYMONTH'])))) throw new RuntimeException('Récurrence ordinale iCal non prise en charge.');
        }
        $lists = [];
        foreach (['BYMONTH'=>12,'BYMONTHDAY'=>31] as $key=>$maximum) if (isset($rule[$key])) {
            $lists[$key] = [];
            foreach (explode(',', $rule[$key]) as $value) {
                if (!preg_match('/^-?\d{1,2}$/D', $value) || (int)$value === 0 || abs((int)$value) > $maximum || ($key === 'BYMONTH' && (int)$value < 0)) throw new RuntimeException('Sélecteur de récurrence iCal invalide.');
                $lists[$key][] = (int)$value;
            }
        }
        if ($rule['FREQ'] === 'WEEKLY' && isset($lists['BYMONTHDAY'])) throw new RuntimeException('Récurrence hebdomadaire BYMONTHDAY invalide.');
        $until = isset($rule['UNTIL']) ? communityCalendarDate([[], $rule['UNTIL']]) : $limit;
        $interval = (int)($rule['INTERVAL'] ?? 1); $count = 1;
        $originWeek = $start->setTime(0, 0)->modify('-'.(((int)$start->format('N') - $weekStart + 7) % 7).' days');
        for ($date = $start->modify('+1 day'); $date <= $limit && $date <= $until; $date = $date->modify('+1 day')) {
            if (--$budget < 0) throw new RuntimeException('Agenda trop complexe : limite de calcul des récurrences atteinte.');
            if ($count >= (int)($rule['COUNT'] ?? 200000)) break;
            $days = (int)$start->diff($date)->format('%a');
            $months = ((int)$date->format('Y') - (int)$start->format('Y')) * 12 + (int)$date->format('n') - (int)$start->format('n');
            $period = match ($rule['FREQ']) {
                'DAILY'=>$days,
                'WEEKLY'=>intdiv((int)$originWeek->diff($date->setTime(0, 0))->format('%a'), 7),
                'MONTHLY'=>$months,
                'YEARLY'=>(int)$date->format('Y') - (int)$start->format('Y'),
            };
            if ($period % $interval) continue;
            if (isset($lists['BYMONTH']) && !in_array((int)$date->format('n'), $lists['BYMONTH'], true)) continue;
            $day = (int)$date->format('j'); $length = (int)$date->format('t');
            if (isset($lists['BYMONTHDAY']) && !in_array($day, $lists['BYMONTHDAY'], true) && !in_array($day - $length - 1, $lists['BYMONTHDAY'], true)) continue;
            if ($byDay) {
                $matches = false;
                foreach ($byDay as $selector) {
                    preg_match('/^([+-]?[1-5])?(MO|TU|WE|TH|FR|SA|SU)$/D', $selector, $m);
                    if ($weekdays[$m[2]] !== (int)$date->format('N')) continue;
                    $ordinal = ($m[1] ?? '') === '' ? 0 : (int)$m[1];
                    if (!$ordinal || $ordinal === intdiv($day - 1, 7) + 1 || $ordinal === -(intdiv($length - $day, 7) + 1)) $matches = true;
                }
                if (!$matches) continue;
            } elseif ($rule['FREQ'] === 'WEEKLY' && $date->format('N') !== $start->format('N')) continue;
            if (in_array($rule['FREQ'], ['MONTHLY','YEARLY'], true) && !$byDay && !isset($lists['BYMONTHDAY']) && $date->format('j') !== $start->format('j')) continue;
            if ($rule['FREQ'] === 'YEARLY' && !isset($lists['BYMONTH']) && !$byDay && !isset($lists['BYMONTHDAY']) && $date->format('n') !== $start->format('n')) continue;
            $dates[$date->getTimestamp()] = $date; $count++;
        }
    }
    foreach (['RDATE','EXDATE'] as $key) foreach ($event[$key] ?? [] as [$params, $values]) {
        foreach (explode(',', $values) as $value) {
            $date = communityCalendarDate([$params, $value]);
            if ($key === 'EXDATE') unset($dates[$date->getTimestamp()]);
            else $dates[$date->getTimestamp()] = $date;
        }
    }
    return $dates;
}

/** Conserve les exceptions déplacées/annulées et les journées entières ; résultat compatible avec calendar.php. */
function communityCalendarEvents(int $from, string $cursor): array {
    if ($cursor !== '' && (!ctype_digit($cursor) || strlen($cursor) > 5)) throw new RuntimeException('Page iCal invalide : actualiser l’agenda.');
    $settings = workspaceCalendarSettings();
    if ($settings['ical_url'] === '') throw new RuntimeException('Configurer l’adresse secrète iCal dans Configuration → Agenda.');
    $events = communityCalendarParse(communityCalendarDownload($settings['ical_url']));
    $limit = (new DateTimeImmutable('@'.$from))->modify('+366 days');
    $overrides = []; $seen = []; $budget = 1000000; $items = [];
    foreach ($events as $event) {
        $uid = $event['UID'][0][1] ?? '';
        if ($uid === '') throw new RuntimeException('Événement iCal sans identifiant.');
        $key = isset($event['RECURRENCE-ID']) ? (string)communityCalendarDate($event['RECURRENCE-ID'][0])->getTimestamp() : 'master';
        if (isset($seen[$uid][$key])) throw new RuntimeException('Identifiant iCal dupliqué.');
        $seen[$uid][$key] = true;
        if ($key !== 'master') {
            if (isset($event['RECURRENCE-ID'][0][0]['RANGE'])) throw new RuntimeException('Exceptions iCal THISANDFUTURE non prises en charge.');
            $overrides[$uid][$key] = true;
        }
    }
    foreach ($events as $event) {
        if (($event['STATUS'][0][1] ?? '') === 'CANCELLED') continue;
        if (!isset($event['DTSTART'])) throw new RuntimeException('Événement iCal sans date de début.');
        $start = communityCalendarDate($event['DTSTART'][0]);
        $allDay = strlen($event['DTSTART'][0][1]) === 8;
        if (isset($event['DTEND']) && (strlen($event['DTEND'][0][1]) === 8) !== $allDay) throw new RuntimeException('Types de dates iCal incompatibles.');
        $end = isset($event['DTEND']) ? communityCalendarDate($event['DTEND'][0]) : ($allDay ? $start->modify('+1 day') : $start);
        if (isset($event['DURATION'])) {
            if (isset($event['DTEND']) || !preg_match('/^P(?=\d|T\d)(?:\d+W|(?:\d+D)?(?:T(?:\d+H)?(?:\d+M)?(?:\d+S)?)?)$/D', $event['DURATION'][0][1])) throw new RuntimeException('Durée iCal non prise en charge.');
            try { $end = $start->add(new DateInterval($event['DURATION'][0][1])); }
            catch (Throwable $e) { throw new RuntimeException('Durée iCal invalide.'); }
        }
        if ($end < $start) throw new RuntimeException('Fin d’événement iCal antérieure au début.');
        $duration = $start->diff($end);
        foreach (communityCalendarOccurrences($event, $start, $limit, $budget) as $timestamp=>$date) {
            if (!isset($event['RECURRENCE-ID']) && isset($overrides[$event['UID'][0][1]][$timestamp])) continue;
            $finish = $date->add($duration);
            if ($date > $limit || ($finish->getTimestamp() <= $from && $date->getTimestamp() < $from)) continue;
            $type = $allDay ? 'date' : 'dateTime'; $format = $allDay ? 'Y-m-d' : DATE_RFC3339;
            $items[] = ['summary'=>communityCalendarText($event['SUMMARY'][0][1] ?? ''), 'location'=>communityCalendarText($event['LOCATION'][0][1] ?? ''), 'start'=>[$type=>$date->format($format)], 'end'=>[$type=>$finish->format($format)], '_time'=>$timestamp, '_uid'=>$event['UID'][0][1]];
            if (count($items) > 10000) throw new RuntimeException('Agenda limité à 10 000 occurrences sur 12 mois.');
        }
    }
    usort($items, static fn($a, $b)=>[$a['_time'], $a['_uid']] <=> [$b['_time'], $b['_uid']]);
    $offset = (int)$cursor;
    return ['items'=>array_slice($items, $offset, 50), 'nextPageToken'=>$offset + 50 < count($items) ? (string)($offset + 50) : ''];
}
