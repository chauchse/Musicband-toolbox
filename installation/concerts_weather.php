<?php
/**
 * Musicband CONCERTS WEATHER: prévisions horaires Open-Meteo pour les concerts.
 *
 * Rôle :
 * Résolution des coordonnées géographiques (cache persistant + Photon / Nominatim),
 * calcul de la fenêtre temporelle du concert (arrivée - 3 h à fin + 2 h),
 * interrogation de l'API météo Open-Meteo avec politique de cache adaptée,
 * et fourniture du panneau et des données de prévision horaire pour les concerts.
 *
 * Dépendances internes :
 * - database.php : accès MySQL
 * - business_list.php : formatage et validation des dates de concert
 * - concerts_calendar.php : conversion des heures civiles locales
 * - auth.php : stockage persistant privé (MusicbandRead/MusicbandWrite), URL Photon
 *
 * Dépendances externes :
 * - API Open-Meteo (https://api.open-meteo.com) : données météo horaires gratuites
 * - API Photon / OpenStreetMap (geocoding) : résolution d'adresses
 *
 * Effets de bord :
 * - Lecture/écriture de geo_cache.json et weather_cache.json dans le stockage privé
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/business_list.php';

/**
 * Correspondance des codes météo WMO avec libellé en français et icône SVG.
 */
function concertsWeatherWmo(int $code): array {
    $codes = [
        0  => ['condition' => 'Ciel dégagé', 'icon' => 'clear'],
        1  => ['condition' => 'Principalement dégagé', 'icon' => 'partly-cloudy'],
        2  => ['condition' => 'Éclaircies', 'icon' => 'partly-cloudy'],
        3  => ['condition' => 'Couvert', 'icon' => 'overcast'],
        45 => ['condition' => 'Brouillard', 'icon' => 'fog'],
        48 => ['condition' => 'Brouillard givrant', 'icon' => 'fog'],
        51 => ['condition' => 'Bruine légère', 'icon' => 'drizzle'],
        53 => ['condition' => 'Bruine modérée', 'icon' => 'drizzle'],
        55 => ['condition' => 'Bruine dense', 'icon' => 'drizzle'],
        56 => ['condition' => 'Bruine verglaçante légère', 'icon' => 'drizzle'],
        57 => ['condition' => 'Bruine verglaçante dense', 'icon' => 'drizzle'],
        61 => ['condition' => 'Pluie faible', 'icon' => 'rain'],
        63 => ['condition' => 'Pluie modérée', 'icon' => 'rain'],
        65 => ['condition' => 'Pluie forte', 'icon' => 'heavy-rain'],
        66 => ['condition' => 'Pluie verglaçante légère', 'icon' => 'rain'],
        67 => ['condition' => 'Pluie verglaçante forte', 'icon' => 'heavy-rain'],
        71 => ['condition' => 'Neige faible', 'icon' => 'snow'],
        73 => ['condition' => 'Neige modérée', 'icon' => 'snow'],
        75 => ['condition' => 'Neige forte', 'icon' => 'snow'],
        77 => ['condition' => 'Grains de neige', 'icon' => 'snow'],
        80 => ['condition' => 'Averses faibles', 'icon' => 'rain'],
        81 => ['condition' => 'Averses modérées', 'icon' => 'rain'],
        82 => ['condition' => 'Averses violentes', 'icon' => 'heavy-rain'],
        85 => ['condition' => 'Averses de neige faibles', 'icon' => 'snow'],
        86 => ['condition' => 'Averses de neige fortes', 'icon' => 'snow'],
        95 => ['condition' => 'Orage', 'icon' => 'thunderstorm'],
        96 => ['condition' => 'Orage avec grêle légère', 'icon' => 'thunderstorm'],
        99 => ['condition' => 'Orage avec grêle forte', 'icon' => 'thunderstorm'],
    ];
    return $codes[$code] ?? ['condition' => 'Conditions variables', 'icon' => 'cloudy'];
}

/**
 * Requête HTTP GET légère avec délai d'attente court (cURL ou stream).
 */
function concertsWeatherHttp(string $url, int $timeout = 5): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) return null;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'Musicband-Toolbox/1.0 (concert-weather; +https://Musicband-band.fr)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300 && is_string($body)) ? $body : null;
    }
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => "Accept: application/json\r\nUser-Agent: Musicband-Toolbox/1.0 (concert-weather)\r\n",
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : null;
}

/**
 * Résolution des coordonnées avec cache persistant (geo_cache.json).
 * Utilise l'URL Photon configurée, avec secours Nominatim.
 */
function concertsWeatherGeocode(string $address): ?array {
    $clean = preg_replace('/\s+/', ' ', trim($address));
    if ($clean === '') return null;

    $key = hash('sha256', mb_strtolower($clean, 'UTF-8'));
    $cache = [];
    try {
        $cache = MusicbandRead('geo_cache');
    } catch (Throwable $e) {
        $cache = [];
    }

    if (isset($cache[$key])) {
        $entry = $cache[$key];
        if (!empty($entry['failed'])) {
            // Échec mis en cache : pas de réessai avant 24 h
            if (time() - ($entry['failed_at'] ?? 0) < 86400) return null;
        } elseif (isset($entry['lat'], $entry['lon']) && is_numeric($entry['lat']) && is_numeric($entry['lon'])) {
            return [
                'lat'   => (float)$entry['lat'],
                'lon'   => (float)$entry['lon'],
                'label' => (string)($entry['label'] ?? $clean),
            ];
        }
    }

    // 1. Essai Photon
    $photonBase = function_exists('MusicbandPhotonUrl') ? MusicbandPhotonUrl() : '';
    if ($photonBase !== '') {
        $url = rtrim($photonBase, '/') . '/?q=' . rawurlencode($clean) . '&limit=1&lang=fr';
        $json = concertsWeatherHttp($url, 5);
        if ($json !== null) {
            $data = json_decode($json, true);
            $feature = $data['features'][0] ?? null;
            if ($feature && isset($feature['geometry']['coordinates'])) {
                $coords = $feature['geometry']['coordinates'];
                if (is_array($coords) && count($coords) >= 2) {
                    $lon = (float)$coords[0];
                    $lat = (float)$coords[1];
                    $props = $feature['properties'] ?? [];
                    $labelParts = array_filter([
                        $props['name'] ?? '',
                        $props['city'] ?? ($props['town'] ?? ($props['village'] ?? '')),
                        $props['postcode'] ?? '',
                    ]);
                    $label = $labelParts ? implode(', ', $labelParts) : $clean;
                    $cache[$key] = ['lat' => $lat, 'lon' => $lon, 'label' => $label, 'updated_at' => time()];
                    try { MusicbandWrite('geo_cache', $cache); } catch (Throwable $e) {}
                    return ['lat' => $lat, 'lon' => $lon, 'label' => $label];
                }
            }
        }
    }

    // 2. Secours Nominatim OpenStreetMap
    $nomUrl = 'https://nominatim.openstreetmap.org/search?format=json&q=' . rawurlencode($clean) . '&limit=1&accept-language=fr';
    $json = concertsWeatherHttp($nomUrl, 5);
    if ($json !== null) {
        $data = json_decode($json, true);
        if (is_array($data) && isset($data[0]['lat'], $data[0]['lon'])) {
            $lat = (float)$data[0]['lat'];
            $lon = (float)$data[0]['lon'];
            $label = (string)($data[0]['display_name'] ?? $clean);
            $cache[$key] = ['lat' => $lat, 'lon' => $lon, 'label' => $label, 'updated_at' => time()];
            try { MusicbandWrite('geo_cache', $cache); } catch (Throwable $e) {}
            return ['lat' => $lat, 'lon' => $lon, 'label' => $label];
        }
    }

    // Mise en cache de l'échec pour éviter de marteler le service
    $cache[$key] = ['failed' => true, 'failed_at' => time()];
    try { MusicbandWrite('geo_cache', $cache); } catch (Throwable $e) {}
    return null;
}

/**
 * Création sécurisée d'une DateTimeImmutable locale avec gestion fuseau et repli.
 */
function concertsWeatherLocal(string $date, string $time, DateTimeZone $zone): DateTimeImmutable {
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D', $time)) {
        $time = '18:00:00';
    }
    if (strlen($time) === 5) $time .= ':00';
    try {
        if (function_exists('concertsCalendarLocal')) {
            return concertsCalendarLocal($date, $time, $zone);
        }
    } catch (Throwable $e) {
        // En cas d'ambiguïté au changement d'heure, résolution standard
    }
    return new DateTimeImmutable($date . ' ' . $time, $zone);
}

/**
 * Calcul précis de la plage temporelle du concert :
 * début = heure d'arrivée - 3 h
 * fin = heure de fin de prestation (ou début + durée) + 2 h
 */
function concertsWeatherWindow(array $row, array $settings): ?array {
    $date = businessConcertDate($row['date_concert'] ?? null, $settings, businessDateImported($row));
    if ($date === null) return null;

    $tz = new DateTimeZone($settings['timezone'] ?? 'Europe/Paris');
    $arrivee = businessTemporalValue('heure_arrivee', (string)($row['heure_arrivee'] ?? ''));
    $balances = businessTemporalValue('heure_balances', (string)($row['heure_balances'] ?? ''));
    $prestation = businessTemporalValue('heure_prestation', (string)($row['heure_prestation'] ?? ''));

    $duree = (int)($row['duree'] ?? 0);
    if ($duree <= 0 || $duree > 1440) $duree = 120; // 2 h par défaut si non spécifié

    // Heure de début de présence
    $hasArrival = false;
    if ($arrivee !== null && $arrivee !== '') {
        $startRef = $arrivee;
        $hasArrival = true;
    } elseif ($balances !== null && $balances !== '') {
        $startRef = $balances;
    } elseif ($prestation !== null && $prestation !== '') {
        $startRef = $prestation;
    } else {
        $startRef = '18:00:00';
    }

    $startDt = concertsWeatherLocal($date, $startRef, $tz);
    $windowStart = $startDt->modify('-3 hours');

    // Heure de prestation et fin
    if ($prestation !== null && $prestation !== '') {
        $startTimeStr = $startDt->format('H:i:s');
        $nextDay = $prestation < $startTimeStr;
        $showDate = $nextDay ? (new DateTimeImmutable($date . ' 12:00:00', $tz))->modify('+1 day')->format('Y-m-d') : $date;
        $perfStart = concertsWeatherLocal($showDate, $prestation, $tz);
    } else {
        $perfStart = $startDt;
    }

    $perfEnd = $perfStart->modify('+' . $duree . ' minutes');
    $windowEnd = $perfEnd->modify('+2 hours');

    return [
        'date'           => $date,
        'tz'             => $tz,
        'timezone'       => $settings['timezone'] ?? 'Europe/Paris',
        'start_dt'       => $startDt,
        'window_start'   => $windowStart,
        'window_end'     => $windowEnd,
        'perf_start'     => $perfStart,
        'perf_end'       => $perfEnd,
        'has_prestation' => ($prestation !== null && $prestation !== ''),
        'has_arrival'    => $hasArrival,
        'duration'       => $duree,
    ];
}

/**
 * Appel de l'API Open-Meteo pour obtenir les prévisions horaires.
 */
function concertsWeatherFetch(float $lat, float $lon, string $timezone, int $forecastDays = 16, int $pastDays = 2): ?array {
    $url = sprintf(
        'https://api.open-meteo.com/v1/forecast?latitude=%.6f&longitude=%.6f&hourly=temperature_2m,precipitation_probability,precipitation,weather_code,wind_speed_10m,wind_gusts_10m&timezone=%s&forecast_days=%d&past_days=%d',
        $lat,
        $lon,
        rawurlencode($timezone),
        $forecastDays,
        $pastDays
    );
    $json = concertsWeatherHttp($url, 5);
    if ($json === null) return null;
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/**
 * Normalisation et découpage des prévisions selon la fenêtre horaire du concert.
 */
function concertsWeatherNormalize(array $raw, array $window, array $geo, int $concertId): array {
    $hourly = $raw['hourly'] ?? [];
    $times = $hourly['time'] ?? [];
    $temps = $hourly['temperature_2m'] ?? [];
    $precipProbs = $hourly['precipitation_probability'] ?? [];
    $precips = $hourly['precipitation'] ?? [];
    $codes = $hourly['weather_code'] ?? [];
    $winds = $hourly['wind_speed_10m'] ?? [];
    $gusts = $hourly['wind_gusts_10m'] ?? [];

    $daysFr = ['Dim.', 'Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.'];
    $fullDaysFr = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $monthsFr = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $fullMonthsFr = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    // Arrondi de la fenêtre aux heures pleines
    $winStartHour = $window['window_start']->setTime((int)$window['window_start']->format('G'), 0, 0);
    $endH = (int)$window['window_end']->format('G');
    $endM = (int)$window['window_end']->format('i');
    $winEndHour = $endM > 0
        ? $window['window_end']->setTime($endH, 0, 0)->modify('+1 hour')
        : $window['window_end']->setTime($endH, 0, 0);

    $startStamp = $winStartHour->getTimestamp();
    $endStamp = $winEndHour->getTimestamp();

    $perfStartStamp = $window['perf_start']->getTimestamp();
    $perfEndStamp = $window['perf_end']->getTimestamp();
    $arrivalStamp = $window['start_dt']->getTimestamp();

    $items = [];
    $tempsList = [];
    $rainMax = 0;
    $rainSum = 0.0;
    $windMax = 0;
    $gustsMax = 0;
    $perfConditions = [];
    $lastDateStr = null;

    $count = count($times);
    for ($i = 0; $i < $count; $i++) {
        $timeStr = $times[$i];
        $slotDt = new DateTimeImmutable($timeStr, $window['tz']);
        $stamp = $slotDt->getTimestamp();

        if ($stamp < $startStamp || $stamp > $endStamp) continue;

        $temp = round((float)($temps[$i] ?? 0));
        $rainProb = (int)($precipProbs[$i] ?? 0);
        $precip = round((float)($precips[$i] ?? 0), 1);
        $code = (int)($codes[$i] ?? 0);
        $wind = round((float)($winds[$i] ?? 0));
        $gust = round((float)($gusts[$i] ?? 0));

        $wmo = concertsWeatherWmo($code);
        $isPerf = ($stamp >= ($perfStartStamp - 1800) && $stamp < ($perfEndStamp + 1800));
        $isArrival = $window['has_arrival'] && (abs($stamp - $arrivalStamp) < 1800);

        $tempsList[] = $temp;
        if ($rainProb > $rainMax) $rainMax = $rainProb;
        $rainSum += $precip;
        if ($wind > $windMax) $windMax = $wind;
        if ($gust > $gustsMax) $gustsMax = $gust;
        if ($isPerf) $perfConditions[] = $wmo['condition'];

        $currentDateStr = $slotDt->format('Y-m-d');
        $dateLabel = '';
        if ($lastDateStr === null || $lastDateStr !== $currentDateStr) {
            $dateLabel = $daysFr[(int)$slotDt->format('w')] . ' ' . $slotDt->format('j') . ' ' . $monthsFr[(int)$slotDt->format('n') - 1];
            $lastDateStr = $currentDateStr;
        }

        $tooltipDay = $fullDaysFr[(int)$slotDt->format('w')] . ' ' . $slotDt->format('j') . ' ' . $fullMonthsFr[(int)$slotDt->format('n') - 1];
        $tooltipTime = $slotDt->format('H\h00');
        $tooltip = sprintf(
            '%s à %s : %s, %d°C, pluie %d%%%s, vent %d km/h (rafales %d km/h)',
            $tooltipDay,
            $tooltipTime,
            $wmo['condition'],
            $temp,
            $rainProb,
            $precip > 0 ? " ($precip mm)" : '',
            $wind,
            $gust
        );

        $items[] = [
            'time'           => $slotDt->format('H\h00'),
            'hour'           => $slotDt->format('H:i'),
            'date_label'     => $dateLabel,
            'datetime'       => $timeStr,
            'temp'           => $temp,
            'weather_code'   => $code,
            'condition'      => $wmo['condition'],
            'icon'           => $wmo['icon'],
            'rain_prob'      => $rainProb,
            'precipitation'  => $precip,
            'wind_speed'     => $wind,
            'wind_gusts'     => $gust,
            'is_performance' => $isPerf,
            'is_arrival'     => $isArrival,
            'tooltip'        => $tooltip,
        ];
    }

    $summaryCondition = !empty($perfConditions) ? $perfConditions[0] : (isset($items[0]['condition']) ? $items[0]['condition'] : 'Non renseigné');
    $summaryIcon = isset($items[0]['icon']) ? $items[0]['icon'] : 'cloudy';
    foreach ($items as $item) {
        if ($item['is_performance']) {
            $summaryIcon = $item['icon'];
            break;
        }
    }

    return [
        'status'       => 'success',
        'concert_id'   => $concertId,
        'location'     => $geo['label'],
        'coordinates'  => ['lat' => $geo['lat'], 'lon' => $geo['lon']],
        'timezone'     => $window['timezone'],
        'summary'      => [
            'condition'     => $summaryCondition,
            'icon'          => $summaryIcon,
            'temp_min'      => !empty($tempsList) ? min($tempsList) : 0,
            'temp_max'      => !empty($tempsList) ? max($tempsList) : 0,
            'rain_prob_max' => $rainMax,
            'rain_sum'      => round($rainSum, 1),
            'wind_max'      => $windMax,
            'wind_gusts_max'=> $gustsMax,
        ],
        'hourly'       => $items,
        'performance'  => [
            'start' => $window['perf_start']->format('Y-m-d\TH:i'),
            'end'   => $window['perf_end']->format('Y-m-d\TH:i'),
        ],
        'attribution'  => 'Données météo : Open-Meteo.com (CC BY 4.0)',
        'updated_at'   => (new DateTimeImmutable('now', $window['tz']))->format(DateTimeInterface::ATOM),
    ];
}

/**
 * Point d'entrée principal pour l'obtention des prévisions météo d'un concert.
 * Gère le cache, les seuils de rafraîchissement et les retours d'erreur gracieux en français.
 */
function concertsWeatherGet(PDO $db, int $concertId): array {
    $q = $db->prepare('SELECT * FROM concerts_evenements WHERE id=?');
    $q->execute([$concertId]);
    $row = $q->fetch();
    if (!$row) {
        return ['status' => 'error', 'message' => 'Fiche concert introuvable.'];
    }

    $adresse = trim((string)($row['adresse'] ?? ''));
    if ($adresse === '') {
        return ['status' => 'error', 'message' => 'Météo indisponible : localisation du concert à vérifier.'];
    }

    $settings = businessListSettings();
    $window = concertsWeatherWindow($row, $settings);
    if ($window === null) {
        return ['status' => 'error', 'message' => 'Météo indisponible : date du concert à vérifier.'];
    }

    $now = new DateTimeImmutable('now', $window['tz']);
    $today = new DateTimeImmutable('today', $window['tz']);
    $concertDay = new DateTimeImmutable($window['date'], $window['tz']);
    $diffDays = (int)$today->diff($concertDay)->format('%r%a');

    // Horizon supérieur à 16 jours : pas d'appel externe Open-Meteo
    if ($diffDays > 16) {
        return [
            'status'  => 'upcoming',
            'message' => 'Prévisions météo bientôt disponibles (disponibles 16 jours avant le concert).',
        ];
    }

    // Résolution de l'adresse (avec cache geo persistant)
    $geo = concertsWeatherGeocode($adresse);
    if ($geo === null) {
        return ['status' => 'error', 'message' => 'Météo indisponible : localisation du concert à vérifier.'];
    }

    // Gestion du cache météo
    $cache = [];
    try {
        $cache = MusicbandRead('weather_cache');
    } catch (Throwable $e) {
        $cache = [];
    }

    $cached = $cache[$concertId] ?? null;

    // Concerts passés : aucune réactualisation
    if ($diffDays < 0) {
        if ($cached && !empty($cached['data'])) {
            return $cached['data'];
        }
        if ($diffDays < -7) {
            return [
                'status'  => 'past',
                'message' => 'Concert passé : prévisions météo non archivées.',
            ];
        }
    }

    // Calcul de la fraîcheur du cache selon l'échéance :
    // Moins de 48 h : ~30 minutes (1800 s)
    // De 2 à 16 jours : ~2 heures (7200 s)
    // Passé : infini
    $diffSeconds = $window['window_start']->getTimestamp() - time();
    if ($diffDays < 0) {
        $ttl = PHP_INT_MAX;
    } elseif ($diffDays <= 2 || $diffSeconds <= 48 * 3600) {
        $ttl = 1800;
    } else {
        $ttl = 7200;
    }

    if ($cached && isset($cached['fetched_at'], $cached['data']) && (time() - $cached['fetched_at'] < $ttl)) {
        return $cached['data'];
    }

    // Appel à l'API Open-Meteo
    $pastDaysParam = $diffDays < 0 ? min(7, abs($diffDays) + 1) : 2;
    $raw = concertsWeatherFetch($geo['lat'], $geo['lon'], $window['timezone'], 16, $pastDaysParam);
    if ($raw === null || empty($raw['hourly']['time'])) {
        // En cas d'indisponibilité réseau, retourner l'ancienne version en cache si disponible
        if ($cached && !empty($cached['data'])) {
            $stale = $cached['data'];
            $stale['stale'] = true;
            return $stale;
        }
        return ['status' => 'error', 'message' => 'Données météo temporairement indisponibles.'];
    }

    $result = concertsWeatherNormalize($raw, $window, $geo, $concertId);
    if (empty($result['hourly'])) {
        return ['status' => 'error', 'message' => 'Prévisions météo non disponibles pour ce créneau horaire.'];
    }

    // Mise en cache et nettoyage périodique des entrées anciennes (> 30 jours)
    $cache[$concertId] = ['fetched_at' => time(), 'data' => $result];
    $nowStamp = time();
    foreach ($cache as $k => $v) {
        if (isset($v['fetched_at']) && ($nowStamp - $v['fetched_at'] > 30 * 86400)) {
            unset($cache[$k]);
        }
    }
    try {
        MusicbandWrite('weather_cache', $cache);
    } catch (Throwable $e) {}

    return $result;
}

/**
 * Invalidation du cache météo lors de la mise à jour d'un concert.
 */
function concertsWeatherInvalidate(int $concertId): void {
    try {
        $cache = MusicbandRead('weather_cache');
        if (isset($cache[$concertId])) {
            unset($cache[$concertId]);
            MusicbandWrite('weather_cache', $cache);
        }
    } catch (Throwable $e) {
        error_log('Musicband_weather_invalidate_error: ' . $e->getMessage());
    }
}

/**
 * Rendu HTML du conteneur du widget météo pour la fiche détail Concert.
 */
function concertsWeatherPanel(PDO $db, array $row): string {
    $id = (int)($row['id'] ?? 0);
    if ($id <= 0) return '';
    $adresse = trim((string)($row['adresse'] ?? ''));
    if ($adresse === '') {
        return '<section class="concert-weather-section" aria-labelledby="weather-title">'
            . '<h3 id="weather-title">Prévisions météo</h3>'
            . '<p class="weather-notice" role="status">Météo indisponible : localisation du concert à vérifier.</p>'
            . '</section>';
    }

    return '<section class="concert-weather-section" id="concert-weather" data-concert-id="' . $id . '" aria-labelledby="weather-title">'
        . '<div class="weather-header-row">'
        . '<h3 id="weather-title">Prévisions météo</h3>'
        . '<div id="weather-summary-bar" class="weather-summary-bar" aria-live="polite"></div>'
        . '</div>'
        . '<div id="weather-content" class="weather-content">'
        . '<p class="weather-loading" role="status"><span class="weather-spinner" aria-hidden="true"></span> Chargement des prévisions météo…</p>'
        . '</div>'
        . '</section>';
}
