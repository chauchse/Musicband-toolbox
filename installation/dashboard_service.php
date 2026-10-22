<?php
/* Musicband DASHBOARD SERVICE: agrégation des données pour le tableau de bord d'accueil et préférences utilisateur.
 * Rôle :
 * - Gestion des préférences d'affichage du tableau de bord (visibilité globale, état replié/déplié, sélection des widgets).
 * - Extraction et mise en forme des données des widgets d'accueil : Morceaux à travailler, Prochaine répétition, Prochain concert, Mes tâches, Vidéo d'introduction.
 * - Détection dynamique d'une vidéo d'introduction à la racine web et intégration responsive HTML5.
 * - Connexion directe et stricte aux modules métier autoritaires sans duplication de logique ni données factices.
 * - Rendu HTML accessible et responsive (grille 2 colonnes desktop, 1 colonne mobile).
 * - Isolation stricte des sources de données et distinction explicite entre absence de données et erreurs techniques ("Impossible de charger les données.").
 *
 * Dépendances internes :
 * - auth.php (sessions, sécurité, stockage privé MusicbandRead/MusicbandWrite, MusicbandEscape)
 * - business.php / business_list.php (concerts, paramètres de classement et dates métier)
 * - documents_delivery.php (authentification OAuth Google partagée pour Google Calendar)
 * - song_fields.php (catalogue de morceaux, métadonnées, mapping Sheets/SQL)
 * - workspace_calendar.php (Google Calendar / Agenda)
 * - tasks_service.php (tâches collaboratives, filtres actifs)
 * - rbac.php (contrôle des permissions d'accès)
 *
 * Dépendances externes : PDO MySQL (tables setlist_songs, concerts_evenements, Musicbandai_tasks), API Google Calendar.
 * Effets de bord : Lecture des bases et caches, mise à jour des préférences dans user_preferences.json.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/business.php';
require_once __DIR__ . '/business_list.php';
require_once __DIR__ . '/documents_delivery.php';
require_once __DIR__ . '/song_fields.php';
require_once __DIR__ . '/tasks_service.php';
require_once __DIR__ . '/workspace_calendar.php';

/**
 * Normalise un texte Unicode pour comparaison insensible à la casse et aux accents.
 */
function dashboardNormalizeText(string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (function_exists('transliterator_transliterate')) {
        $trans = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        if ($trans !== false) {
            return $trans;
        }
    }
    if (class_exists('Normalizer') && method_exists('Normalizer', 'normalize')) {
        $norm = Normalizer::normalize($text, Normalizer::FORM_D);
        if ($norm !== false) {
            $norm = preg_replace('/\p{Mn}/u', '', $norm);
            return mb_strtolower($norm, 'UTF-8');
        }
    }
    // Repli natif pour environnements PHP minimaux
    return strtr(
        mb_strtolower($text, 'UTF-8'),
        [
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','œ'=>'oe',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
            'ý'=>'y','ÿ'=>'y',
            'ç'=>'c','ñ'=>'n'
        ]
    );
}

/**
 * Vérifie si le titre d'un événement correspond à une répétition selon les règles métier.
 * Critères : contient 'repetition', 'repet' ou 'rehearsal' (insensible casse et accents).
 */
function dashboardIsRehearsalTitle(string $title): bool {
    $norm = dashboardNormalizeText($title);
    return str_contains($norm, 'repetition') || str_contains($norm, 'repet') || str_contains($norm, 'rehearsal');
}

/**
 * Préférences utilisateur par défaut pour les utilisateurs existants et nouveaux.
 */
function dashboardDefaultPreferences(): array {
    return [
        'dashboard_visible'  => true,
        'dashboard_expanded' => true,
        'widget_songs'       => true,
        'widget_rehearsal'   => true,
        'widget_concert'     => true,
        'widget_tasks'       => true,
        'widget_video'       => true,
        'appearance_theme'   => 'auto',
        'appearance_scale'   => 100,
        'tasks_filter'       => 'me',
        'songs_filter'       => '',
    ];
}

/**
 * Récupère les préférences personnalisées d'un utilisateur.
 */
function dashboardUserPreferences(string $login): array {
    $defaults = dashboardDefaultPreferences();
    if ($login === '') {
        return $defaults;
    }
    try {
        $all = MusicbandRead('user_preferences');
        $userPrefs = $all[$login] ?? [];
        if (!is_array($userPrefs)) {
            return $defaults;
        }
        return array_replace($defaults, $userPrefs);
    } catch (Throwable $e) {
        return $defaults;
    }
}

/**
 * Enregistre les préférences d'un utilisateur de manière atomique et isolée.
 */
function dashboardSaveUserPreferences(string $login, array $newPrefs): void {
    if ($login === '') {
        throw new RuntimeException('Utilisateur non connecté.');
    }
    $allowedKeys = [
        'dashboard_visible', 'dashboard_expanded',
        'widget_songs', 'widget_rehearsal', 'widget_concert', 'widget_tasks', 'widget_video',
        'tasks_filter', 'songs_filter', 'appearance_theme', 'appearance_scale'
    ];
    $sanitized = [];
    foreach ($newPrefs as $k => $v) {
        if (!in_array($k, $allowedKeys, true)) {
            continue;
        }
        if ($k === 'appearance_theme') {
            if (!is_string($v) || !in_array($v, ['auto','light','dark'], true)) throw new RuntimeException('Thème invalide.');
            $sanitized[$k] = $v;
        } elseif ($k === 'appearance_scale') {
            if (!is_int($v) || $v < 90 || $v > 130 || $v % 10 !== 0) throw new RuntimeException('Taille invalide.');
            $sanitized[$k] = $v;
        } elseif ($k === 'tasks_filter') {
            $sanitized[$k] = is_string($v) ? substr(trim($v), 0, 80) : 'me';
        } elseif ($k === 'songs_filter') {
            $sanitized[$k] = is_string($v) ? substr(trim($v), 0, 32) : '';
        } else {
            $sanitized[$k] = (bool)$v;
        }
    }

    MusicbandLocked(function() use ($login, $sanitized) {
        $all = MusicbandRead('user_preferences');
        $current = $all[$login] ?? [];
        if (!is_array($current)) {
            $current = [];
        }
        $all[$login] = array_replace($current, $sanitized);
        MusicbandWrite('user_preferences', $all);
    });
}

/**
 * Liste des filtres dynamiques enregistrés dans le module Morceaux (setlist_documents: songs:filters).
 *
 * @return array Tableau associatif [id => [name => string, rules => array, created_by => string, created_at => string]]
 */
function dashboardSongFiltersList(): array {
    try {
        $db = MusicbandRbacDb();
        $q = $db->prepare('SELECT content FROM setlist_documents WHERE document_key = ?');
        $q->execute(['songs:filters']);
        $raw = $q->fetchColumn();
        if ($raw !== false && is_string($raw)) {
            $items = json_decode($raw, true, 32);
            if (is_array($items)) {
                return $items;
            }
        }
    } catch (Throwable $e) {
        // En cas d'erreur de lecture de la base, retourner tableau vide
    }
    return [];
}

/**
 * Récupère l'identifiant du filtre configuré globalement dans l'administration pour le widget Morceaux.
 */
function dashboardGetConfiguredSongsFilter(): string {
    try {
        $settings = MusicbandRead('home_settings');
        return is_string($settings['dashboard_songs_filter'] ?? null) ? trim($settings['dashboard_songs_filter']) : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Résout le filtre de morceaux actif selon la hiérarchie :
 * 1. Filtre individuel utilisateur (si renseigné et existant)
 * 2. Filtre global choisi par l'administrateur dans Configuration (si renseigné et existant)
 * 3. Filtre dynamique nommé "A travailler" / "À travailler" (priorité exacte, plus récent)
 * 4. Repli : null (utilisation de la propriété next_rehearsal_work de la fiche morceau)
 *
 * @return array{id: ?string, rules: ?array, name: ?string}
 */
function dashboardResolveSongsFilter(?string $userPresetId = null): array {
    $filters = dashboardSongFiltersList();

    // 1. Filtre individuel utilisateur
    if ($userPresetId !== null && $userPresetId !== '' && isset($filters[$userPresetId]['rules'])) {
        return [
            'id'    => $userPresetId,
            'rules' => $filters[$userPresetId]['rules'],
            'name'  => (string)($filters[$userPresetId]['name'] ?? $userPresetId)
        ];
    }

    // 2. Filtre global d'administration (Configuration)
    $configured = dashboardGetConfiguredSongsFilter();
    if ($configured !== '' && isset($filters[$configured]['rules'])) {
        return [
            'id'    => $configured,
            'rules' => $filters[$configured]['rules'],
            'name'  => (string)($filters[$configured]['name'] ?? $configured)
        ];
    }

    // 3. Détection automatique : filtre nommé "A travailler" / "À travailler"
    $bestPriority = 0;
    $bestDate = '';
    $presetId = null;
    $presetRules = null;
    $presetName = null;

    foreach ($filters as $k => $item) {
        if (!is_array($item) || !isset($item['rules'])) continue;
        $nameNorm = dashboardNormalizeText((string)($item['name'] ?? ''));
        $priority = 0;
        if ($nameNorm === 'a travailler' || $nameNorm === 'morceaux a travailler') {
            $priority = 2; // Correspondance exacte prioritaire
        } elseif (str_contains($nameNorm, 'travailler')) {
            $priority = 1;
        }
        if ($priority > 0) {
            $created = (string)($item['created_at'] ?? '');
            if ($priority > $bestPriority || ($priority === $bestPriority && $created >= $bestDate)) {
                $bestPriority = $priority;
                $presetId = $k;
                $presetRules = $item['rules'];
                $presetName = (string)($item['name'] ?? '');
                $bestDate = $created;
            }
        }
    }

    if ($presetRules !== null) {
        return [
            'id'    => $presetId,
            'rules' => $presetRules,
            'name'  => $presetName
        ];
    }

    return ['id' => null, 'rules' => null, 'name' => null];
}

/**
 * Formulaire de sélection du filtre du widget Morceaux pour l'administration (admin.php).
 */
function dashboardSongsFilterAdminForm(string $hidden, string $context = 'songs'): string {
    $filters = dashboardSongFiltersList();
    $current = dashboardGetConfiguredSongsFilter();
    $auto = dashboardResolveSongsFilter(null);
    $autoName = !empty($auto['name']) ? $auto['name'] : 'Repli sur le champ « À travailler » de la fiche';
    $selectId = 'dashboard-songs-filter-' . $context;

    $html = '<section class="dashboard-admin-filter-section">';
    $html .= '<h2>Widget Accueil · Morceaux à travailler</h2>';
    $html .= '<p>Sélectionnez le filtre dynamique enregistré dans le module <a href="./songs.php">Morceaux</a> qui alimentera le widget « Morceaux à travailler » sur la page d’accueil.</p>';
    $html .= '<form method="post">';
    $html .= $hidden;
    $html .= '<input type="hidden" name="action" value="dashboard_songs_filter">';
    $html .= '<label for="' . MusicbandEscape($selectId) . '">Filtre sélectionné pour le widget</label>';
    $html .= '<select id="' . MusicbandEscape($selectId) . '" name="dashboard_songs_filter">';
    $html .= '<option value=""' . ($current === '' ? ' selected' : '') . '>Automatique (actuel : ' . MusicbandEscape($autoName) . ')</option>';
    foreach ($filters as $fid => $f) {
        $fname = (string)($f['name'] ?? $fid);
        $creator = (string)($f['created_by'] ?? '');
        $label = $fname . ($creator !== '' ? ' (créé par ' . $creator . ')' : '');
        $isSelected = ($current === $fid);
        $html .= '<option value="' . MusicbandEscape($fid) . '"' . ($isSelected ? ' selected' : '') . '>' . MusicbandEscape($label) . '</option>';
    }
    $html .= '</select>';
    $html .= '<p>Pour créer ou modifier un filtre, effectuez une recherche dans <a href="./songs.php">Morceaux</a> puis enregistrez le filtre.</p>';
    $html .= '<button>Enregistrer le filtre du widget</button>';
    $html .= '</form>';
    $html .= '</section>';
    return $html;
}

/**
 * Widget 1 — Morceaux à travailler.
 * Source autoritaire exclusive : module Morceaux (setlist_songs / song_fields.php / filtres enregistrés).
 * Règle métier : filtre dynamique configuré dans le menu Configuration ou Préférences (dans setlist_documents: songs:filters).
 * Si un filtre est résolu, il fait autorité absolue sans ajout parallèle de morceaux non retenus.
 * Tri par priorité croissante (1 = priorité la plus haute, non renseigné en fin de liste) avec départage déterministe.
 * Affiche : Titre — Artiste et badge de priorité visible si renseigné.
 */
function dashboardGetSongs(int $limit = 50, ?string $presetIdOverride = null): array {
    try {
        $db = MusicbandRbacDb();
        $q = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'setlist_songs'");
        $q->execute();
        if (!$q->fetchColumn()) {
            return ['status' => 'empty', 'songs' => [], 'preset' => null, 'preset_name' => null, 'message' => 'Aucun morceau à travailler.'];
        }

        $filterInfo = dashboardResolveSongsFilter($presetIdOverride);
        $presetId = $filterInfo['id'];
        $presetRules = $filterInfo['rules'];
        $presetName = $filterInfo['name'];

        // Requête sélectionnant l'ensemble des colonnes physiques de la table
        $stmt = $db->query("SELECT s.* FROM setlist_songs s ORDER BY s.title ASC, s.id ASC");
        $results = [];

        foreach ($stmt as $row) {
            $values = songRowValues($row);
            $source = json_decode((string)($row['source_json'] ?? '{}'), true, 64) ?: [];
            foreach ($source as $name => $value) {
                if (songSourceField($name) === null) {
                    $values[fieldVisibilitySourceKey('songs', $name)] = $value;
                }
            }

            if ($presetRules !== null && function_exists('songsFilterMatches')) {
                // Le filtre dynamique résolu fait autorité absolue
                $isWork = songsFilterMatches($presetRules, $values);
            } else {
                // Repli uniquement si aucun filtre prédéfini n'a été enregistré ou configuré
                $rawWork = $values['next_rehearsal_work'] ?? null;
                if ($rawWork === null) {
                    $dj = json_decode((string)($row['data_json'] ?? '{}'), true, 64) ?: [];
                    $rawWork = $dj['next_rehearsal_work'] ?? null;
                }
                if ($rawWork !== null) {
                    // Si une valeur explicite existe dans la fiche ou ses données, elle fait foi (0 = ne pas travailler)
                    $isWork = !empty($rawWork) && $rawWork !== '0' && $rawWork !== 0;
                } else {
                    $isWork = false;
                    foreach ($source as $sKey => $sVal) {
                        if (str_contains(dashboardNormalizeText($sKey), 'travailler')) {
                            $normVal = dashboardNormalizeText((string)$sVal);
                            if (in_array($normVal, ['1', 'oui', 'yes', 'true', 'x', 'vrai'], true)) {
                                $isWork = true;
                                break;
                            }
                        }
                    }
                }
            }

            if ($isWork) {
                $rawPriority = $values['priority'] ?? $row['priority'] ?? null;
                if ($rawPriority === null || $rawPriority === '') {
                    $dj = json_decode((string)($row['data_json'] ?? '{}'), true, 64) ?: [];
                    $rawPriority = $dj['priority'] ?? $dj['Priorité'] ?? null;
                }
                if ($rawPriority === null || $rawPriority === '') {
                    foreach ($source as $sKey => $sVal) {
                        if (str_contains(dashboardNormalizeText($sKey), 'priorit')) {
                            $rawPriority = $sVal;
                            break;
                        }
                    }
                }
                $priority = ($rawPriority !== null && $rawPriority !== '' && is_numeric($rawPriority)) ? (int)$rawPriority : null;

                $results[] = [
                    'id'       => (int)$row['id'],
                    'title'    => trim((string)($row['title'] ?? '')),
                    'artist'   => trim((string)($row['artist'] ?? '')),
                    'priority' => $priority,
                ];
            }
        }

        // Tri par priorité croissante (1 = priorité la plus haute, null à la fin) puis titre, artiste et identifiant
        usort($results, function (array $a, array $b): int {
            $pA = $a['priority'] !== null ? $a['priority'] : PHP_INT_MAX;
            $pB = $b['priority'] !== null ? $b['priority'] : PHP_INT_MAX;
            if ($pA !== $pB) {
                return $pA <=> $pB;
            }
            $cmpTitle = strcasecmp((string)$a['title'], (string)$b['title']);
            if ($cmpTitle !== 0) {
                return $cmpTitle;
            }
            $cmpArtist = strcasecmp((string)$a['artist'], (string)$b['artist']);
            if ($cmpArtist !== 0) {
                return $cmpArtist;
            }
            return $a['id'] <=> $b['id'];
        });

        if ($limit > 0 && count($results) > $limit) {
            $results = array_slice($results, 0, $limit);
        }

        if (empty($results)) {
            return ['status' => 'empty', 'songs' => [], 'preset' => $presetId, 'preset_name' => $presetName, 'message' => 'Aucun morceau à travailler.'];
        }

        return ['status' => 'ok', 'songs' => $results, 'preset' => $presetId, 'preset_name' => $presetName, 'message' => ''];
    } catch (Throwable $e) {
        error_log('dashboardGetSongs error: ' . $e->getMessage());
        return ['status' => 'error', 'songs' => [], 'preset' => null, 'preset_name' => null, 'message' => 'Impossible de charger les données.'];
    }
}

/**
 * Widget 2 — Prochaine répétition.
 * Source autoritaire exclusive : module Agenda (workspaceCalendarEvents).
 * Filtrage : titre contenant 'répétition', 'repet' ou 'rehearsal' (insensible casse et accents).
 * Exclut : événements annulés et passés. Inclut la répétition du jour si son début n'est pas passé.
 * Rafraîchissement direct à chaque consultation de la page d'accueil.
 */
function dashboardGetNextRehearsal(?array $user = null): array {
    $user = $user ?? MusicbandUser();
    if (!$user) {
        return ['status' => 'unauthorized', 'message' => 'Connexion requise.'];
    }

    // Contrôle d'autorisation RBAC selon le contrat Agenda
    if (!MusicbandCan('calendar.read', $user)) {
        return ['status' => 'unauthorized', 'message' => 'Consultation de l’agenda non autorisée pour ce profil.'];
    }

    // Récupération directe sans cache de session pour garantir la fraîcheur des données
    $now = time();
    unset($_SESSION['dashboard_agenda_cache']);
    $events = null;

    try {
        $settings = workspaceCalendarSettings();
        // Demander les événements depuis le début du jour courant pour ne rien manquer
        $from = $now - 86400;
        $result = workspaceCalendarEvents($settings['id'], $from, '');
        $events = $result['items'] ?? [];
    } catch (Throwable $e) {
        error_log('dashboardGetNextRehearsal calendar error: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Impossible de charger les données.'];
    }

    if (!is_array($events) || empty($events)) {
        return ['status' => 'empty', 'message' => 'Aucune répétition à venir.'];
    }

    $eligible = [];
    $tz = MusicbandDateZone();
    $todayStr = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

    foreach ($events as $event) {
        if (!is_array($event) || ($event['status'] ?? '') === 'cancelled') {
            continue;
        }
        $title = trim((string)($event['summary'] ?? ''));
        if (!dashboardIsRehearsalTitle($title)) {
            continue;
        }

        $start = $event['start'] ?? [];
        $rawStart = $start['dateTime'] ?? $start['date'] ?? null;
        if (!is_string($rawStart)) {
            continue;
        }

        $startTimestamp = 0;

        try {
            if (isset($start['date'])) {
                // Journée entière : date civile YYYY-MM-DD
                $dtStart = new DateTimeImmutable($rawStart . ' 00:00:00', $tz);
                $startTimestamp = $dtStart->getTimestamp();
                if ($dtStart->format('Y-m-d') < $todayStr) {
                    continue;
                }
            } else {
                $dtStart = new DateTimeImmutable($rawStart);
                $startTimestamp = $dtStart->getTimestamp();
                // Répétition horaire : exclure si l'heure de début programmée est déjà passée
                if ($startTimestamp < $now) {
                    continue;
                }
            }
        } catch (Throwable $e) {
            continue;
        }

        $eligible[] = [
            'start_timestamp'=> $startTimestamp,
            'title'          => $title !== '' ? $title : 'Répétition',
            'location'       => trim((string)($event['location'] ?? '')),
            'when_label'     => workspaceCalendarWhen($event),
            'link'           => (string)($event['htmlLink'] ?? ''),
        ];
    }

    if (empty($eligible)) {
        return ['status' => 'empty', 'message' => 'Aucune répétition à venir.'];
    }

    // Tri par date/heure de début croissante pour retenir la plus proche chronologiquement
    usort($eligible, fn($a, $b) => $a['start_timestamp'] <=> $b['start_timestamp']);
    $nearest = $eligible[0];

    return [
        'status'   => 'ok',
        'title'    => $nearest['title'],
        'when'     => $nearest['when_label'],
        'location' => $nearest['location'],
        'link'     => $nearest['link'],
    ];
}

/**
 * Widget 3 — Prochain concert.
 * Source autoritaire exclusive : module Concerts (table concerts_evenements).
 * Exclut : concerts annulés et passés selon les règles métier de business_list.php.
 * Retient : concert le plus proche chronologiquement (date et heure).
 */
function dashboardGetNextConcert(): array {
    try {
        $db = MusicbandRbacDb();
        $q = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'concerts_evenements'");
        $q->execute();
        if (!$q->fetchColumn()) {
            return ['status' => 'empty', 'concert' => null, 'message' => 'Aucun concert à venir.'];
        }

        try {
            $settings = businessListSettings();
        } catch (Throwable $e) {
            $settings = ['timezone' => 'Europe/Paris', 'page_size' => 50, 'date_formats' => ['Y-m-d', 'd/m/Y', 'm/d/Y']];
        }

        $tz = new DateTimeZone($settings['timezone'] ?? 'Europe/Paris');
        $today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
        $nowDt = new DateTimeImmutable('now', $tz);

        $stmt = $db->query("SELECT id, evenement, date_concert, adresse, statut, heure_prestation, heure_arrivee, heure_balances, source_table, JSON_UNQUOTE(JSON_EXTRACT(source_json, '$.Date')) AS imported_date FROM concerts_evenements");
        $candidates = [];

        foreach ($stmt as $row) {
            $statut = dashboardNormalizeText((string)($row['statut'] ?? ''));
            if (str_contains($statut, 'annul') || str_contains($statut, 'cancel')) {
                continue;
            }

            $dateConcert = businessConcertDate($row['date_concert'] ?? null, $settings, businessDateImported($row));
            if ($dateConcert === null && !empty($row['date_concert'])) {
                $rawVal = trim((string)$row['date_concert']);
                foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'm/d/Y', 'Y/m/d'] as $fmt) {
                    $dt = DateTimeImmutable::createFromFormat('!' . $fmt, $rawVal, $tz);
                    if ($dt && $dt->format($fmt) === $rawVal && $dt->format('Y') !== '0000') {
                        $dateConcert = $dt->format('Y-m-d');
                        break;
                    }
                }
            }
            if ($dateConcert === null || $dateConcert < $today) {
                continue;
            }

            // Pour aujourd'hui : vérifier si l'heure de début n'est pas déjà passée
            $timeStr = trim((string)($row['heure_prestation'] ?? ''));
            if ($timeStr === '') {
                $timeStr = trim((string)($row['heure_arrivee'] ?? ''));
            }
            if ($timeStr === '') {
                $timeStr = trim((string)($row['heure_balances'] ?? ''));
            }

            $startTime = null;
            if ($timeStr !== '' && preg_match('/^(\d{1,2})[h:](\d{2})?(?::\d{2})?$/i', $timeStr, $tm)) {
                $h = (int)$tm[1];
                $m = isset($tm[2]) && $tm[2] !== '' ? (int)$tm[2] : 0;
                $startTime = (new DateTimeImmutable($dateConcert . ' ' . sprintf('%02d:%02d:00', $h, $m), $tz));
            }

            if ($dateConcert === $today && $startTime !== null && $startTime < $nowDt) {
                continue;
            }

            $sortTimestamp = $startTime !== null ? $startTime->getTimestamp() : (new DateTimeImmutable($dateConcert . ' 00:00:00', $tz))->getTimestamp();

            $candidates[] = [
                'row'            => $row,
                'date'           => $dateConcert,
                'sort_timestamp' => $sortTimestamp,
                'id'             => (int)$row['id'],
            ];
        }

        if (empty($candidates)) {
            return ['status' => 'empty', 'concert' => null, 'message' => 'Aucun concert à venir.'];
        }

        // Tri chronologique strict par date, heure puis identifiant
        usort($candidates, function($a, $b) {
            return ($a['date'] <=> $b['date']) ?: ($a['sort_timestamp'] <=> $b['sort_timestamp']) ?: ($a['id'] <=> $b['id']);
        });

        $best = $candidates[0];
        $bestRow = $best['row'];
        $dateDisplay = MusicbandDisplayDate($best['date'], false, false, true);

        $heure = trim((string)($bestRow['heure_prestation'] ?? ''));
        if ($heure === '') {
            $heure = trim((string)($bestRow['heure_arrivee'] ?? ''));
        }

        $evenement = trim((string)($bestRow['evenement'] ?? ''));
        $adresse = trim((string)($bestRow['adresse'] ?? ''));

        return [
            'status'  => 'ok',
            'concert' => [
                'id'        => (int)$bestRow['id'],
                'date'      => $dateDisplay,
                'heure'     => $heure,
                'evenement' => $evenement !== '' ? $evenement : 'Concert',
                'adresse'   => $adresse,
            ],
            'message' => '',
        ];
    } catch (Throwable $e) {
        error_log('dashboardGetNextConcert error: ' . $e->getMessage());
        return ['status' => 'error', 'concert' => null, 'message' => 'Impossible de charger les données.'];
    }
}

/**
 * Widget 4 — Mes tâches.
 * Source autoritaire exclusive : module Gestion des tâches (tasksList).
 * Exclut : tâches terminées et annulées.
 * Filtrage : tâches actives assignées à l'utilisateur connecté ($currentUser).
 * Tri autoritaire : tâches en retard en premier, puis échéances les plus proches, puis sans échéance.
 */
function dashboardGetTasks(string $filterMember, string $currentUser, int $limit = 50): array {
    try {
        $db = tasksDb();
        if (!tasksReady($db)) {
            return ['status' => 'error', 'tasks' => [], 'total' => 0, 'message' => 'Impossible de charger les données.'];
        }

        $targetUser = ($filterMember === 'me' || $filterMember === '') ? $currentUser : $filterMember;
        $filters = [
            'active_only'  => true,
            'mes_taches'   => true,
            'current_user' => $targetUser,
        ];

        $res = tasksList($filters, 'default', 'asc', $limit, 0);
        $tasks = $res['tasks'] ?? [];
        $total = (int)($res['total'] ?? count($tasks));

        if (empty($tasks)) {
            return ['status' => 'empty', 'tasks' => [], 'total' => 0, 'message' => 'Aucune tâche en cours.'];
        }

        return ['status' => 'ok', 'tasks' => $tasks, 'total' => $total, 'message' => ''];
    } catch (Throwable $e) {
        error_log('dashboardGetTasks error: ' . $e->getMessage());
        return ['status' => 'error', 'tasks' => [], 'total' => 0, 'message' => 'Impossible de charger les données.'];
    }
}

/**
 * Recherche dynamique d'une vidéo d'introduction à la racine du document web.
 * Détecte les formats vidéo courants sans présumer de l'extension (.mp4, .webm, .mov, etc.).
 *
 * @return ?array{file: string, mime: string, ext: string} Métadonnées du fichier vidéo trouvé ou null.
 */
function dashboardFindIntroductionVideo(): ?array {
    $supportedMimes = [
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'mov'  => 'video/quicktime',
        'ogg'  => 'video/ogg',
        'ogv'  => 'video/ogg',
        'm4v'  => 'video/mp4',
    ];

    $dirsToCheck = [];
    if (!empty($_SERVER['DOCUMENT_ROOT']) && is_dir($_SERVER['DOCUMENT_ROOT'])) {
        $dirsToCheck[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
    }
    $webDir = __DIR__;
    if (!in_array($webDir, $dirsToCheck, true)) {
        $dirsToCheck[] = $webDir;
    }

    foreach ($dirsToCheck as $dir) {
        $candidates = glob($dir . '/introduction.*');
        if (!is_array($candidates) || empty($candidates)) {
            continue;
        }
        foreach ($candidates as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (isset($supportedMimes[$ext])) {
                return [
                    'file' => basename($filePath),
                    'mime' => $supportedMimes[$ext],
                    'ext'  => $ext,
                ];
            }
        }
    }

    return null;
}

/**
 * Rendu HTML complet du tableau de bord d'accueil pour l'utilisateur connecté.
 */
function dashboardRender(array $user): string {
    $login = (string)($user['login'] ?? '');
    if ($login === '') {
        return '';
    }

    $prefs = dashboardUserPreferences($login);
    if (empty($prefs['dashboard_visible'])) {
        return '';
    }

    $isExpanded = !empty($prefs['dashboard_expanded']);
    $widgetSongsActive = !empty($prefs['widget_songs']);
    $widgetRehearsalActive = !empty($prefs['widget_rehearsal']);
    $widgetConcertActive = !empty($prefs['widget_concert']);
    $widgetTasksActive = !empty($prefs['widget_tasks']);
    $videoInfo = !empty($prefs['widget_video']) ? dashboardFindIntroductionVideo() : null;
    $widgetVideoActive = ($videoInfo !== null);

    // La vidéo ne compte comme widget actif que si elle est autorisée et présente.
    $anyWidgetActive = $widgetSongsActive || $widgetRehearsalActive || $widgetConcertActive || $widgetTasksActive || $widgetVideoActive;

    $base = MusicbandBase();
    $csrf = $_SESSION['csrf'] ?? '';

    $html = '<section class="dashboard-section" aria-label="Tableau de bord">';
    $html .= '<details class="dashboard-collapsible"' . ($isExpanded ? ' open' : '') . ' id="dashboard-widgets-panel">';
    $html .= '<summary class="dashboard-summary">';
    $html .= '<div class="dashboard-summary-title">';
    $html .= '<span class="dashboard-chevron"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></span>';
    $html .= '<span>Mes widgets</span>';
    $html .= '</div>';
    $html .= '<a href="' . MusicbandEscape($base . 'activate.php?change=1') . '" class="dashboard-prefs-link" title="Configurer les widgets">Préférences</a>';
    $html .= '</summary>';

    $html .= '<div class="dashboard-body">';

    if (!$anyWidgetActive) {
        $html .= '<p class="dashboard-empty">Tous les widgets sont actuellement masqués. Vous pouvez les réactiver dans vos <a href="' . MusicbandEscape($base . 'activate.php?change=1') . '">Préférences</a>.</p>';
        $html .= '</div></details></section>';
        return $html;
    }

    $html .= '<div class="dashboard-grid">';

    // Widget 1 : Morceaux à travailler
    if ($widgetSongsActive) {
        $userFilter = (string)($prefs['songs_filter'] ?? '');
        $songsData = dashboardGetSongs(50, $userFilter !== '' ? $userFilter : null);
        $songs = $songsData['songs'] ?? [];

        // Lien vers le module Morceaux avec le filtre sélectionné actif
        if (!empty($songsData['preset'])) {
            $songsUrl = $base . 'songs.php?preset=' . urlencode((string)$songsData['preset']);
        } else {
            $rulesPayload = json_encode([
                'condition' => 'AND',
                'not'       => false,
                'rules'     => [['id' => 'next_rehearsal_work', 'operator' => 'equal', 'value' => 1]]
            ], JSON_UNESCAPED_SLASHES);
            $songsUrl = $base . 'songs.php?rules=' . urlencode($rulesPayload ?: '');
        }

        $titleTip = !empty($songsData['preset_name']) ? 'Filtre actif : ' . $songsData['preset_name'] : 'Consulter les morceaux à travailler';

        $html .= '<div class="dashboard-widget">';
        $html .= '<div class="dashboard-widget-header">';
        $html .= '<h3><a href="' . MusicbandEscape($songsUrl) . '" class="dashboard-widget-title-link" title="' . MusicbandEscape($titleTip) . '">Morceaux à travailler</a></h3>';
        $html .= '<a href="' . MusicbandEscape($songsUrl) . '" title="Ouvrir le catalogue de morceaux">Catalogue →</a>';
        $html .= '</div>';

        if ($songsData['status'] === 'error') {
            $html .= '<p class="dashboard-empty dashboard-error" role="alert">Impossible de charger les données.</p>';
        } elseif (empty($songs)) {
            $html .= '<p class="dashboard-empty">Aucun morceau à travailler.</p>';
        } else {
            $html .= '<ul class="dashboard-item-list' . (count($songs) > 6 ? ' dashboard-scrollable-list' : '') . '">';
            foreach ($songs as $s) {
                $songHref = $base . 'songs.php?id=' . $s['id'];
                $artistPart = $s['artist'] !== '' ? ' <span class="dashboard-item-separator" aria-hidden="true">—</span> <span class="dashboard-item-sub">' . MusicbandEscape($s['artist']) . '</span>' : '';
                $priorityBadge = '';
                if (isset($s['priority']) && $s['priority'] !== null) {
                    $pVal = (int)$s['priority'];
                    $badgeClass = 'dashboard-song-badge' . ($pVal === 1 ? ' is-high-priority' : '');
                    $priorityBadge = '<span class="' . $badgeClass . '" title="Priorité ' . $pVal . '">Priorité ' . $pVal . '</span>';
                }
                $html .= '<li class="dashboard-item">';
                $html .= '<a class="dashboard-item-title" href="' . MusicbandEscape($songHref) . '">' . MusicbandEscape($s['title']) . $artistPart . '</a>';
                if ($priorityBadge !== '') {
                    $html .= $priorityBadge;
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div>';
    }

    // Widget 2 : Prochaine répétition
    if ($widgetRehearsalActive) {
        $rehearsal = dashboardGetNextRehearsal($user);
        $html .= '<div class="dashboard-widget">';
        $html .= '<div class="dashboard-widget-header">';
        $html .= '<h3>Prochaine répétition</h3>';
        $html .= '<a href="' . MusicbandEscape($base . 'calendar.php') . '" title="Consulter l’agenda complet">Agenda →</a>';
        $html .= '</div>';

        if ($rehearsal['status'] === 'ok') {
            $agendaHref = !empty($rehearsal['link']) ? $rehearsal['link'] : $base . 'calendar.php';
            $isExternal = !empty($rehearsal['link']);
            $html .= '<div class="dashboard-event-main"><a class="dashboard-item-title" href="' . MusicbandEscape($agendaHref) . '"' . ($isExternal ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . MusicbandEscape($rehearsal['title']) . '</a></div>';
            $html .= '<div class="dashboard-event-when">' . MusicbandEscape($rehearsal['when']) . '</div>';
            if (!empty($rehearsal['location'])) {
                $html .= '<div class="dashboard-event-location">' . MusicbandEscape($rehearsal['location']) . '</div>';
            }
        } elseif ($rehearsal['status'] === 'error') {
            $html .= '<p class="dashboard-empty dashboard-error" role="alert">Impossible de charger les données.</p>';
        } elseif ($rehearsal['status'] === 'unauthorized') {
            $html .= '<p class="dashboard-empty">' . MusicbandEscape($rehearsal['message']) . '</p>';
        } else {
            $html .= '<p class="dashboard-empty">Aucune répétition à venir.</p>';
        }
        $html .= '</div>';
    }

    // Widget 3 : Prochain concert
    if ($widgetConcertActive) {
        $concertData = dashboardGetNextConcert();
        $html .= '<div class="dashboard-widget">';
        $html .= '<div class="dashboard-widget-header">';
        $html .= '<h3>Prochain concert</h3>';
        $html .= '<a href="' . MusicbandEscape($base . 'concerts.php') . '" title="Consulter la liste des concerts">Concerts →</a>';
        $html .= '</div>';

        if ($concertData['status'] === 'ok' && $concertData['concert'] !== null) {
            $c = $concertData['concert'];
            $whenText = $c['date'] . ($c['heure'] !== '' ? ' · ' . $c['heure'] : '');
            $concertHref = $base . 'concerts.php?id=' . $c['id'];
            $html .= '<div class="dashboard-event-when"><a class="dashboard-item-title" href="' . MusicbandEscape($concertHref) . '">' . MusicbandEscape($whenText) . '</a></div>';
            $html .= '<div class="dashboard-event-main">' . MusicbandEscape($c['evenement']) . '</div>';
            if ($c['adresse'] !== '') {
                $html .= '<div class="dashboard-event-location">' . MusicbandEscape($c['adresse']) . '</div>';
            }
        } elseif ($concertData['status'] === 'error') {
            $html .= '<p class="dashboard-empty dashboard-error" role="alert">Impossible de charger les données.</p>';
        } else {
            $html .= '<p class="dashboard-empty">Aucun concert à venir.</p>';
        }
        $html .= '</div>';
    }

    // Widget 4 : Mes tâches
    if ($widgetTasksActive) {
        $tasksData = dashboardGetTasks('me', $login, 50);
        $tasks = $tasksData['tasks'] ?? [];

        $html .= '<div class="dashboard-widget">';
        $html .= '<div class="dashboard-widget-header">';
        $html .= '<h3>Mes tâches</h3>';
        $html .= '<a href="' . MusicbandEscape($base . 'tasks.php?mes_taches=1') . '" title="Consulter mes tâches en cours">Mes tâches →</a>';
        $html .= '</div>';

        if ($tasksData['status'] === 'error') {
            $html .= '<p class="dashboard-empty dashboard-error" role="alert">Impossible de charger les données.</p>';
        } elseif (empty($tasks)) {
            $html .= '<p class="dashboard-empty">Aucune tâche en cours.</p>';
        } else {
            $html .= '<ul class="dashboard-item-list' . (count($tasks) > 5 ? ' dashboard-scrollable-list' : '') . '">';
            foreach ($tasks as $t) {
                $isOverdue = !empty($t['is_overdue']);
                $taskHref = $base . 'tasks.php?id=' . (int)$t['id'];
                $statusLabel = TASKS_STATUSES[$t['status']] ?? $t['status'];

                $html .= '<li class="dashboard-item">';
                $html .= '<div class="dashboard-task-left">';
                $html .= '<a class="dashboard-item-title' . ($isOverdue ? ' dashboard-task-overdue' : '') . '" href="' . MusicbandEscape($taskHref) . '">' . MusicbandEscape($t['title']) . '</a>';
                if (!empty($t['due_date'])) {
                    $html .= '<div class="dashboard-item-sub"><span class="' . ($isOverdue ? 'dashboard-task-overdue' : '') . '">Échéance : ' . MusicbandEscape(MusicbandDisplayDate($t['due_date'])) . '</span></div>';
                }
                $html .= '</div>';
                $html .= '<span class="dashboard-task-badge' . ($isOverdue ? ' is-overdue' : '') . '">' . MusicbandEscape($isOverdue ? 'En retard' : $statusLabel) . '</span>';
                $html .= '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
    }

    // Widget 5 : Vidéo d'introduction (si présente à la racine web)
    if ($widgetVideoActive && $videoInfo !== null) {
        $videoSrc = MusicbandEscape($base . $videoInfo['file']);
        $videoMime = MusicbandEscape($videoInfo['mime']);
        $html .= '<div class="dashboard-widget dashboard-video-widget">';
        $html .= '<div class="dashboard-widget-header">';
        $html .= '<h3>Vidéo d’introduction</h3>';
        $html .= '</div>';
        $html .= '<video controls playsinline preload="metadata" class="Musicband-wide-field">';
        $html .= '<source src="' . $videoSrc . '" type="' . $videoMime . '">';
        $html .= 'Votre navigateur ne prend pas en charge la lecture de vidéos HTML5.';
        $html .= '</video>';
        $html .= '</div>';
    }

    $html .= '</div>'; // .dashboard-grid
    $html .= '</div>'; // .dashboard-body
    $html .= '</details></section>';

    // Script léger de synchronisation de l'état replié/déplié et rafraîchissement au retour arrière (BFCache)
    $html .= '<script>
(function() {
    window.addEventListener("pageshow", function(event) {
        if (event.persisted) {
            window.location.reload();
        }
    });

    var panel = document.getElementById("dashboard-widgets-panel");
    if (!panel) return;
    panel.addEventListener("toggle", function() {
        var data = new URLSearchParams();
        data.append("action", "save_dashboard_state");
        data.append("expanded", panel.open ? "1" : "0");
        data.append("csrf", ' . json_encode($csrf) . ');
        fetch(' . json_encode($base . 'activate.php?change=1') . ', {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: data.toString()
        }).catch(function() {});
    });
})();
</script>';

    return $html;
}
