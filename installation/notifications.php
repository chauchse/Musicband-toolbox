<?php
/**
 * Musicband NOTIFICATIONS: gestion des règles, abonnements Web Push, traitement hybride (CRON + secours) et observabilité.
 *
 * Responsabilité :
 * - Chargement sécurisé de la configuration VAPID et de l'autoload Web Push hors racine publique.
 * - Enregistrement et persistance des événements, abonnements d'appareils et règles d'envoi.
 * - Moteur d'envoi Web Push hybride : exécution planifiée CRON et rattrapage sécurisé sur activité authentifiée.
 * - Exclusion des règles et anciennes livraisons visant les modules financiers retirés de l'édition.
 * - Envoi direct et immédiat de notifications de test avec rapport détaillé par fournisseur (Apple / Google).
 * - Observabilité administrateur complète (états CRON, secours, abonnements, journaux d'erreurs).
 *
 * Dépendances internes : auth.php, rbac.php, database.php, advanced_notifications_service.php.
 * Dépendances externes : minishlink/web-push via autoload privé, base MySQL schéma 012.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

/**
 * Charge l'environnement Web Push depuis les fichiers de configuration privée ou l'environnement système.
 */
function MusicbandNotificationsLoadEnvironment(): void {
    $private = MusicbandPrivate();
    $file = dirname($private) . '/Musicband-webpush/config.php';
    if (!is_file($file)) $file = $private . '/webpush/config.php';
    if (!is_file($file)) return;
    try {
        $directory = realpath(dirname($file));
        $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
        if (!$directory || ($root !== '' && ($directory === $root || str_starts_with($directory . '/', $root . '/'))) || !is_readable($file) || (fileperms($file) & 0077) !== 0 || (fileperms($directory) & 0077) !== 0) {
            throw new RuntimeException();
        }
        $settings = require $file;
        if (!is_array($settings)) throw new RuntimeException();
        foreach (['Musicband_WEBPUSH_AUTOLOAD', 'Musicband_VAPID_PUBLIC_KEY', 'Musicband_VAPID_PRIVATE_KEY', 'Musicband_VAPID_SUBJECT', 'Musicband_NOTIFICATIONS_CRON_TOKEN'] as $key) {
            if (!isset($settings[$key]) || !is_string($settings[$key]) || str_contains($settings[$key], "\0")) throw new RuntimeException();
            if (getenv($key) === false && !putenv($key . '=' . $settings[$key])) throw new RuntimeException();
        }
    } catch (Throwable $e) {
        MusicbandFail('Configuration privée Web Push illisible ou invalide.');
    }
}
MusicbandNotificationsLoadEnvironment();

/**
 * Retourne l'ensemble des réglages Web Push en fusionnant environnement et configuration privée.
 */
function MusicbandWebpushSettings(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $keys = ['Musicband_WEBPUSH_AUTOLOAD', 'Musicband_VAPID_PUBLIC_KEY', 'Musicband_VAPID_PRIVATE_KEY', 'Musicband_VAPID_SUBJECT', 'Musicband_NOTIFICATIONS_CRON_TOKEN'];
    $settings = [];
    foreach ($keys as $k) {
        $val = getenv($k);
        if ($val !== false && $val !== '') $settings[$k] = (string)$val;
    }
    if (count($settings) < count($keys)) {
        $private = MusicbandPrivate();
        $file = dirname($private) . '/Musicband-webpush/config.php';
        if (!is_file($file)) $file = $private . '/webpush/config.php';
        if (is_file($file) && is_readable($file)) {
            $cfg = @require $file;
            if (is_array($cfg)) {
                foreach ($keys as $k) {
                    if (empty($settings[$k]) && !empty($cfg[$k]) && is_string($cfg[$k])) {
                        $settings[$k] = $cfg[$k];
                        putenv($k . '=' . $cfg[$k]);
                    }
                }
            }
        }
    }
    $cached = $settings;
    return $cached;
}

/**
 * Retourne la valeur d'un réglage Web Push donné ou chaîne vide si absent.
 */
function MusicbandWebpushSetting(string $key): string {
    $settings = MusicbandWebpushSettings();
    return (string)($settings[$key] ?? getenv($key) ?: '');
}

/**
 * Initialise le client Minishlink\WebPush de manière centralisée et vérifie les prérequis.
 */
function MusicbandWebpushInit(): array {
    if (PHP_VERSION_ID < 80200) {
        return ['client' => null, 'error' => 'PHP 8.2 ou supérieur requis.', 'stage' => 'php_version'];
    }
    $autoload = realpath(MusicbandWebpushSetting('Musicband_WEBPUSH_AUTOLOAD'));
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    if (!$autoload || !is_file($autoload) || ($root !== '' && str_starts_with($autoload, $root . '/'))) {
        return ['client' => null, 'error' => 'Autoload Web Push introuvable hors racine publique.', 'stage' => 'autoload'];
    }
    require_once $autoload;
    if (!class_exists('Minishlink\\WebPush\\WebPush')) {
        return ['client' => null, 'error' => 'Bibliothèque Web Push absente de l’autoload.', 'stage' => 'autoload'];
    }
    $public = MusicbandWebpushSetting('Musicband_VAPID_PUBLIC_KEY');
    $private = MusicbandWebpushSetting('Musicband_VAPID_PRIVATE_KEY');
    $subject = MusicbandWebpushSetting('Musicband_VAPID_SUBJECT');
    if (!$public || !$private || !preg_match('~^(mailto:[^\s]+@[^\s]+|https://[^\s]+)$~D', $subject)) {
        return ['client' => null, 'error' => 'Configuration VAPID incomplète (clés ou contact).', 'stage' => 'vapid'];
    }
    try {
        $push = new Minishlink\WebPush\WebPush(
            ['VAPID' => ['subject' => $subject, 'publicKey' => $public, 'privateKey' => $private]],
            ['TTL' => 86400, 'urgency' => 'normal'],
            5,
            ['allow_redirects' => false, 'connect_timeout' => 5]
        );
        return ['client' => $push, 'error' => null, 'stage' => 'ready'];
    } catch (Throwable $e) {
        return ['client' => null, 'error' => 'Échec d’initialisation VAPID : ' . $e->getMessage(), 'stage' => 'vapid_init'];
    }
}

/**
 * Lit l'état persistant des notifications (horodatages CRON/secours, résultats, erreurs récentes).
 */
function MusicbandNotificationsState(): array {
    $defaults = [
        'last_execution_at' => null,
        'last_execution_trigger' => null,
        'last_execution_status' => null,
        'last_execution_attempts' => 0,
        'last_execution_outcomes' => [],
        'last_cron_at' => null,
        'last_cron_status' => null,
        'last_cron_outcomes' => [],
        'last_fallback_at' => null,
        'last_fallback_status' => null,
        'last_fallback_outcomes' => [],
        'last_test' => null,
        'recent_errors' => []
    ];
    $stored = MusicbandRead('notifications_state');
    return array_replace($defaults, is_array($stored) ? $stored : []);
}

/**
 * Met à jour l'état persistant des notifications.
 */
function MusicbandNotificationsStateUpdate(array $updates): void {
    MusicbandLocked(function() use ($updates) {
        $current = MusicbandNotificationsState();
        $merged = array_replace($current, $updates);
        MusicbandWrite('notifications_state', $merged);
    });
}

/**
 * Enregistre une exécution (CRON ou secours) dans l'état persistant.
 */
function MusicbandNotificationsRecordExecution(string $trigger, string $status, int $attempts = 0, array $outcomes = []): void {
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $updates = [
        'last_execution_at' => $now,
        'last_execution_trigger' => $trigger,
        'last_execution_status' => $status,
        'last_execution_attempts' => $attempts,
        'last_execution_outcomes' => $outcomes,
    ];
    if ($trigger === 'cron') {
        $updates['last_cron_at'] = $now;
        $updates['last_cron_status'] = $status;
        $updates['last_cron_outcomes'] = $outcomes;
    } elseif ($trigger === 'fallback') {
        $updates['last_fallback_at'] = $now;
        $updates['last_fallback_status'] = $status;
        $updates['last_fallback_outcomes'] = $outcomes;
    }
    MusicbandNotificationsStateUpdate($updates);
}

/**
 * Enregistre un incident technique dans l'historique d'observabilité (conserve les 5 derniers).
 */
function MusicbandNotificationsRecordError(string $source, string $errorMessage): void {
    MusicbandLocked(function() use ($source, $errorMessage) {
        $state = MusicbandNotificationsState();
        $errors = is_array($state['recent_errors'] ?? null) ? $state['recent_errors'] : [];
        $entry = [
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'source' => $source,
            'message' => substr(strip_tags($errorMessage), 0, 300)
        ];
        array_unshift($errors, $entry);
        $state['recent_errors'] = array_slice($errors, 0, 5);
        MusicbandWrite('notifications_state', $state);
    });
}

/**
 * Enregistre le résultat d'un test de notification.
 */
function MusicbandNotificationsRecordTest(array $testData): void {
    MusicbandNotificationsStateUpdate(['last_test' => $testData]);
}

/**
 * Vérifie si le traitement planifié est en retard (au-delà de 65 minutes) et si le délai minimal de secours est respecté.
 */
function MusicbandNotificationsIsOverdue(int $overdueThresholdSeconds = 3900, int $minFallbackIntervalSeconds = 1800): bool {
    $state = MusicbandNotificationsState();
    $now = time();
    $lastExec = !empty($state['last_execution_at']) ? strtotime($state['last_execution_at']) : 0;
    $lastFallback = !empty($state['last_fallback_at']) ? strtotime($state['last_fallback_at']) : 0;

    $isOverdue = ($lastExec === 0 || ($now - $lastExec) >= $overdueThresholdSeconds);
    if (!$isOverdue) return false;
    if ($lastFallback > 0 && ($now - $lastFallback) < $minFallbackIntervalSeconds) return false;
    return true;
}

/**
 * Déclencheur contrôlé et non-bloquant de secours lors d'une activité d'un utilisateur authentifié.
 *
 * Invariants :
 * - Déclenché uniquement sur requête GET authentifiée (jamais sur POST, API ou Ajax).
 * - Throttlé strictement : vérification en microsecondes si le CRON est à jour.
 * - Exécution différée en arrêt de script (shutdown) après libération du client HTTP (fastcgi_finish_request).
 * - Aucune incidence sur le chargement de page ou la navigation utilisateur.
 */
function MusicbandNotificationsTriggerFallbackIfOverdue(): void {
    if (!function_exists('MusicbandUser') || !MusicbandUser()) return;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') return;
    if (isset($_GET['api']) || isset($_GET['ajax'])) return;
    if (!MusicbandNotificationsIsOverdue()) return;

    static $registered = false;
    if ($registered) return;
    $registered = true;

    register_shutdown_function(static function() {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            $db = MusicbandRbacDb();
            if (!MusicbandNotificationsReady($db)) return;
            MusicbandNotificationsProcess($db, 'fallback', 5);
        } catch (Throwable $e) {
            error_log('Musicband_push_fallback_failed: ' . $e->getMessage());
            MusicbandNotificationsRecordError('fallback', $e->getMessage());
        }
    });
}

function MusicbandNotificationEvents(): array {
    $events = [
        'concert.incomplete' => 'Concert à venir · informations incomplètes',
        'document.generated' => 'Devis / Facture généré',
        'document.sent' => 'Devis / Facture transmis au service mail',
        'setlist.validated' => 'Setlist validée'
    ];
    if (!MusicbandModuleEnabled('devis_factures')) unset($events['document.generated'], $events['document.sent']);
    return $events;
}

function MusicbandNotificationFields(): array {
    return [
        'adresse' => 'Adresse',
        'heure_arrivee' => 'Heure d’arrivée',
        'heure_prestation' => 'Heure de prestation',
        'duree' => 'Durée',
        'client' => 'Client',
        'contact' => 'Contact',
        'montant' => 'Montant',
        'reglement' => 'Règlement'
    ];
}

function MusicbandFeatureTable(PDO $db, string $table): bool {
    $q = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$table]);
    return (int)$q->fetchColumn() === 1;
}

function MusicbandNotificationsReady(PDO $db): bool {
    foreach (['Musicbandai_notification_rules', 'Musicbandai_push_subscriptions', 'Musicbandai_notification_events', 'Musicbandai_push_deliveries'] as $table) {
        if (!MusicbandFeatureTable($db, $table)) return false;
    }
    return true;
}

function MusicbandNotificationRules(PDO $db): array {
    $rows = $db->query('SELECT * FROM Musicbandai_notification_rules ORDER BY event_key')->fetchAll();
    $rules = [];
    foreach ($rows as $r) {
        if (!MusicbandModuleEnabled('devis_factures') && in_array($r['event_key'], ['document.generated','document.sent'], true)) continue;
        $rules[$r['event_key']] = $r;
    }
    return $rules;
}

function MusicbandNotificationRecord(PDO $db, string $event, string $source, string $path, ?string $at = null): void {
    $q = $db->prepare('INSERT IGNORE INTO Musicbandai_notification_events(event_key,source_key,path,created_at) VALUES (?,?,?,COALESCE(?,UTC_TIMESTAMP(6)))');
    $q->execute([$event, $source, $path, $at]);
}

/**
 * Moteur unifié de traitement des notifications Web Push (utilisé par CRON et mécanisme de secours).
 */
function MusicbandNotificationsProcess(PDO $db, string $trigger = 'cron', int $maxDeliveries = 30): array {
    $init = MusicbandWebpushInit();
    if (!$init['client']) {
        MusicbandNotificationsRecordError($trigger, $init['error'] ?? 'Échec WebPush');
        return ['status' => 'error', 'stage' => $init['stage'], 'error' => $init['error']];
    }
    $push = $init['client'];

    $lockName = 'Musicband_push_' . substr(hash('sha256', (string)$db->query('SELECT DATABASE()')->fetchColumn()), 0, 24);
    $q = $db->prepare('SELECT GET_LOCK(?, 0)');
    $q->execute([$lockName]);
    $locked = (int)$q->fetchColumn() === 1;
    if (!$locked) {
        MusicbandNotificationsRecordExecution($trigger, 'already_running');
        return ['status' => 'already_running', 'trigger' => $trigger];
    }

    $sent = 0;
    $outcomes = ['accepted' => 0, 'failed' => 0, 'expired' => 0, 'uncertain' => 0];

    try {
        $rules = MusicbandNotificationRules($db);
        // Exclure aussi les anciennes livraisons avant LIMIT, sans effacer leur historique.
        $blockedRoutes = [];
        foreach (['devis_factures'=>['devis_factures','documents_pdf'], 'expenses'=>['expenses'], 'accounting'=>['accounting']] as $module=>$routes) {
            if (!MusicbandModuleEnabled($module)) $blockedRoutes = array_merge($blockedRoutes, $routes);
        }
        $moduleFilter = $blockedRoutes ? " AND e.path NOT REGEXP '(^|/)(".implode('|', $blockedRoutes).")[.]php([?#]|$)'" : '';

        // 1. Détection des événements métier confirmés
        foreach (['document.generated' => ['generation_status', 'complete', 'generated_at'], 'document.sent' => ['mail_status', 'accepted', 'mail_completed_at']] as $event => [$column, $state, $time]) {
            $rule = $rules[$event] ?? null;
            if (!$rule || !$rule['enabled'] || !MusicbandFeatureTable($db, 'devis_factures_deliveries')) continue;
            $q = $db->prepare('SELECT d.document_id,d.' . $time . ' AS event_at FROM devis_factures_deliveries d WHERE d.' . $column . '=? AND d.' . $time . '>=? AND NOT EXISTS(SELECT 1 FROM Musicbandai_notification_events e WHERE e.event_key=? AND e.source_key=CAST(d.document_id AS CHAR)) ORDER BY d.' . $time . ' LIMIT 100');
            $q->execute([$state, $rule['updated_at'], $event]);
            foreach ($q->fetchAll() as $row) {
                MusicbandNotificationRecord($db, $event, (string)$row['document_id'], 'devis_factures.php?id=' . (int)$row['document_id'], $row['event_at']);
            }
        }

        $rule = $rules['setlist.validated'] ?? null;
        if ($rule && $rule['enabled'] && MusicbandFeatureTable($db, 'setlist_validations')) {
            $q = $db->prepare("SELECT source_file,validated_at FROM setlist_validations v WHERE validated_at>=? AND NOT EXISTS(SELECT 1 FROM Musicbandai_notification_events e WHERE e.event_key='setlist.validated' AND e.source_key=v.source_file) ORDER BY validated_at LIMIT 100");
            $q->execute([$rule['updated_at']]);
            foreach ($q->fetchAll() as $v) {
                MusicbandNotificationRecord($db, 'setlist.validated', $v['source_file'], 'setlist/?publication=' . $v['source_file'], $v['validated_at']);
            }
        }

        $rule = $rules['concert.incomplete'] ?? null;
        $concertEligibility = [];
        if ($rule && $rule['enabled'] && MusicbandFeatureTable($db, 'concerts_evenements')) {
            $policy = businessListSettings();
            foreach ($db->query('SELECT * FROM concerts_evenements') as $concert) {
                $key = MusicbandConcertReminderKey($concert, $rule, $policy);
                if ($key !== null) {
                    $concertEligibility[$key] = true;
                    MusicbandNotificationRecord($db, 'concert.incomplete', $key, 'concerts.php?id=' . (int)$concert['id']);
                }
            }
        }

        // 2. Traitement des rappels de tâches et répétitions
        if (function_exists('advancedNotificationProcessReminders')) {
            advancedNotificationProcessReminders($db);
        }

        // 3. Traitement des livraisons de règles standards
        $q = $db->query("SELECT e.id AS event_id,e.created_at AS event_at,e.event_key,e.source_key,e.path,s.id AS subscription_id,s.login,s.account_revision,s.subscription_json FROM Musicbandai_notification_events e JOIN Musicbandai_notification_rules r ON r.event_key=e.event_key JOIN Musicbandai_push_subscriptions s ON s.active=1 AND s.updated_at<=e.created_at LEFT JOIN Musicbandai_push_deliveries d ON d.event_id=e.id AND d.subscription_id=s.id WHERE r.enabled=1 AND JSON_CONTAINS(r.recipients_json,JSON_QUOTE(s.login))=1 AND e.created_at>=r.updated_at AND e.created_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY AND d.event_id IS NULL".$moduleFilter." ORDER BY e.id,s.id LIMIT 50");
        foreach ($q->fetchAll() as $item) {
            if ($sent >= 10) break;
            $liveRules = MusicbandNotificationRules($db);
            $currentRule = $liveRules[$item['event_key']] ?? null;
            $liveUsers = MusicbandRead('users');
            $account = $liveUsers[$item['login']] ?? null;
            $active = $db->prepare('SELECT active,login,account_revision,updated_at FROM Musicbandai_push_subscriptions WHERE id=?');
            $active->execute([$item['subscription_id']]);
            $device = $active->fetch();
            if (!$device || !$device['active'] || $device['login'] !== $item['login'] || $device['account_revision'] !== $item['account_revision'] || $device['updated_at'] > $item['event_at']) continue;
            if (!$currentRule || !$currentRule['enabled'] || $currentRule['updated_at'] > $item['event_at']) continue;
            if (!$account || !hash_equals($account['revision'], $item['account_revision'])) {
                $db->prepare('UPDATE Musicbandai_push_subscriptions SET active=0 WHERE id=?')->execute([$item['subscription_id']]);
                continue;
            }
            if (!in_array($item['login'], json_decode($currentRule['recipients_json'], true) ?: [], true)) continue;
            if ($item['event_key'] === 'concert.incomplete') {
                $check = $db->prepare('SELECT * FROM concerts_evenements WHERE id=?');
                $check->execute([(int)$item['source_key']]);
                $current = $check->fetch();
                if (!$current || MusicbandConcertReminderKey($current, $currentRule, businessListSettings()) !== $item['source_key']) unset($concertEligibility[$item['source_key']]);
                if (!isset($concertEligibility[$item['source_key']])) {
                    $db->prepare("INSERT IGNORE INTO Musicbandai_push_deliveries(event_id,subscription_id,status,requested_at,completed_at) VALUES (?,?,'canceled',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")->execute([$item['event_id'], $item['subscription_id']]);
                    continue;
                }
            }

            $sub = json_decode($item['subscription_json'], true, 8);
            if (!is_array($sub) || empty($sub['endpoint'])) continue;
            $x = $db->prepare("INSERT IGNORE INTO Musicbandai_push_deliveries(event_id,subscription_id,status,requested_at) VALUES (?,?,'reserved',UTC_TIMESTAMP(6))");
            $x->execute([$item['event_id'], $item['subscription_id']]);
            if ($x->rowCount() !== 1) continue;

            $payloadData = ['id' => $item['event_id'], 'event' => $item['event_key'], 'path' => $item['path'], 'tag' => 'Musicband-' . $item['event_id']];
            if (function_exists('advancedNotificationGetDeliveryPayload')) {
                $custom = advancedNotificationGetDeliveryPayload((int)$item['event_id'], (int)$item['subscription_id']);
                if ($custom) {
                    if (!empty($custom['title'])) $payloadData['title'] = $custom['title'];
                    if (!empty($custom['body'])) $payloadData['body'] = $custom['body'];
                }
            }

            $status = 'uncertain';
            try {
                MusicbandPushEndpoint($sub['endpoint']);
                $report = $push->sendOneNotification(Minishlink\WebPush\Subscription::create($sub), json_encode($payloadData, JSON_THROW_ON_ERROR));
                $status = $report->isSuccess() ? 'accepted' : ($report->isSubscriptionExpired() ? 'expired' : 'failed');
            } catch (Throwable $e) {
                error_log('Musicband_push_delivery_uncertain: ' . $e->getMessage());
            }

            $db->prepare('UPDATE Musicbandai_push_deliveries SET status=?,completed_at=UTC_TIMESTAMP(6) WHERE event_id=? AND subscription_id=?')->execute([$status, $item['event_id'], $item['subscription_id']]);
            if ($status === 'expired') {
                $db->prepare('UPDATE Musicbandai_push_subscriptions SET active=0 WHERE id=?')->execute([$item['subscription_id']]);
            }
            $outcomes[$status]++;
            $sent++;
        }

        // 4. Traitement des livraisons de notifications avancées en attente
        $qPending = $db->query("SELECT d.event_id,d.subscription_id,e.event_key,e.source_key,e.path,s.id AS sub_id,s.login,s.account_revision,s.subscription_json FROM Musicbandai_push_deliveries d JOIN Musicbandai_notification_events e ON e.id=d.event_id JOIN Musicbandai_push_subscriptions s ON s.id=d.subscription_id WHERE d.status='pending' AND s.active=1".$moduleFilter." ORDER BY d.requested_at ASC LIMIT 50");
        foreach ($qPending->fetchAll() as $item) {
            if ($sent >= $maxDeliveries) break;
            $liveUsers = MusicbandRead('users');
            $account = $liveUsers[$item['login']] ?? null;
            if (!$account || !hash_equals($account['revision'], $item['account_revision'])) {
                $db->prepare('UPDATE Musicbandai_push_subscriptions SET active=0 WHERE id=?')->execute([$item['sub_id']]);
                $db->prepare("UPDATE Musicbandai_push_deliveries SET status='canceled',completed_at=UTC_TIMESTAMP(6) WHERE event_id=? AND subscription_id=?")->execute([$item['event_id'], $item['subscription_id']]);
                continue;
            }

            if (function_exists('advancedNotificationEventCatalog')) {
                $catalog = advancedNotificationEventCatalog();
                $perm = $catalog[$item['event_key']]['permission'] ?? null;
                if ($perm !== null && !MusicbandCan($perm, ['login' => $item['login']])) {
                    $db->prepare("UPDATE Musicbandai_push_deliveries SET status='canceled',completed_at=UTC_TIMESTAMP(6) WHERE event_id=? AND subscription_id=?")->execute([$item['event_id'], $item['subscription_id']]);
                    continue;
                }
            }

            $x = $db->prepare("UPDATE Musicbandai_push_deliveries SET status='reserved' WHERE event_id=? AND subscription_id=? AND status='pending'");
            $x->execute([$item['event_id'], $item['subscription_id']]);
            if ($x->rowCount() !== 1) continue;

            $sub = json_decode($item['subscription_json'], true, 8);
            if (!is_array($sub) || empty($sub['endpoint'])) continue;

            $payloadData = ['id' => $item['event_id'], 'event' => $item['event_key'], 'path' => $item['path'], 'tag' => 'Musicband-' . $item['event_id']];
            if (function_exists('advancedNotificationGetDeliveryPayload')) {
                $custom = advancedNotificationGetDeliveryPayload((int)$item['event_id'], (int)$item['subscription_id']);
                if ($custom) {
                    if (!empty($custom['title'])) $payloadData['title'] = $custom['title'];
                    if (!empty($custom['body'])) $payloadData['body'] = $custom['body'];
                    if (!empty($custom['path'])) $payloadData['path'] = $custom['path'];
                }
            }

            $status = 'uncertain';
            try {
                MusicbandPushEndpoint($sub['endpoint']);
                $report = $push->sendOneNotification(Minishlink\WebPush\Subscription::create($sub), json_encode($payloadData, JSON_THROW_ON_ERROR));
                $status = $report->isSuccess() ? 'accepted' : ($report->isSubscriptionExpired() ? 'expired' : 'failed');
            } catch (Throwable $e) {
                error_log('Musicband_push_adv_delivery_uncertain: ' . $e->getMessage());
            }

            $db->prepare('UPDATE Musicbandai_push_deliveries SET status=?,completed_at=UTC_TIMESTAMP(6) WHERE event_id=? AND subscription_id=?')->execute([$status, $item['event_id'], $item['subscription_id']]);
            if ($status === 'expired') {
                $db->prepare('UPDATE Musicbandai_push_subscriptions SET active=0 WHERE id=?')->execute([$item['sub_id']]);
            }
            $outcomes[$status]++;
            $sent++;
        }

        MusicbandNotificationsRecordExecution($trigger, 'complete', $sent, $outcomes);
        return ['status' => 'complete', 'trigger' => $trigger, 'attempts' => $sent, 'outcomes' => $outcomes];
    } catch (Throwable $e) {
        error_log('Musicband_push_process_failed_' . $trigger . ': ' . $e->getMessage());
        MusicbandNotificationsRecordError($trigger, $e->getMessage());
        MusicbandNotificationsRecordExecution($trigger, 'error', $sent, $outcomes);
        throw $e;
    } finally {
        try {
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        } catch (Throwable $_) {}
    }
}

/**
 * Envoie une notification de test immédiate avec rapport précis du fournisseur distant (Apple / Google).
 */
function MusicbandNotificationsSendTestDirect(string $login): array {
    if (!advancedNotificationsUserAllowed($login)) {
        throw new RuntimeException('Fonctionnalité non autorisée pour ce compte.');
    }
    $db = MusicbandRbacDb();
    if (!MusicbandNotificationsReady($db)) {
        throw new RuntimeException('Schéma 012 requis pour les notifications.');
    }

    $init = MusicbandWebpushInit();
    if (!$init['client']) {
        $diagMsg = $init['error'] ?? 'Configuration Web Push incomplète';
        MusicbandNotificationsRecordError('test', $diagMsg);
        return [
            'success' => false,
            'count' => 0,
            'outcomes' => [],
            'message' => 'Échec de configuration : ' . $diagMsg . '. Contactez un administrateur.'
        ];
    }
    $push = $init['client'];

    $qSubs = $db->prepare('SELECT id, endpoint_hash, subscription_json, account_revision FROM Musicbandai_push_subscriptions WHERE login=? AND active=1');
    $qSubs->execute([$login]);
    $subRows = $qSubs->fetchAll(PDO::FETCH_ASSOC);
    if (empty($subRows)) {
        return [
            'success' => false,
            'count' => 0,
            'outcomes' => [],
            'message' => 'Aucun appareil actif abonné pour ce compte. Rendez-vous sur la section « Notifications et rappels » de Préférences pour activer cet appareil.'
        ];
    }

    $sourceKey = 'test:' . substr(hash('sha256', $login . ':' . microtime(true)), 0, 24);
    $title = 'Test Musicband Notification';
    $body = 'Vos notifications PWA fonctionnent correctement !';
    $path = 'index.php';

    $count = advancedNotificationEnqueueCustomPush($db, 'test.notification', $sourceKey, $login, $path, $title, $body);
    if ($count === 0) {
        return [
            'success' => false,
            'count' => 0,
            'outcomes' => [],
            'message' => 'Aucune livraison de test n’a pu être initialisée pour vos appareils.'
        ];
    }

    $outcomes = ['accepted' => 0, 'failed' => 0, 'expired' => 0, 'uncertain' => 0];
    $details = [];

    $qPending = $db->prepare("SELECT d.event_id, d.subscription_id, s.subscription_json FROM Musicbandai_push_deliveries d JOIN Musicbandai_push_subscriptions s ON s.id=d.subscription_id WHERE d.status='pending' AND s.login=?");
    $qPending->execute([$login]);
    $items = $qPending->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $subId = (int)$item['subscription_id'];
        $eventId = (int)$item['event_id'];

        $sub = json_decode($item['subscription_json'], true, 8);
        if (!is_array($sub) || empty($sub['endpoint'])) {
            $db->prepare("UPDATE Musicbandai_push_deliveries SET status='failed', completed_at=UTC_TIMESTAMP(6) WHERE event_id=? AND subscription_id=?")->execute([$eventId, $subId]);
            $outcomes['failed']++;
            continue;
        }

        $db->prepare("UPDATE Musicbandai_push_deliveries SET status='reserved' WHERE event_id=? AND subscription_id=? AND status='pending'")->execute([$eventId, $subId]);

        $payloadData = [
            'id' => $eventId,
            'event' => 'test.notification',
            'path' => $path,
            'title' => $title,
            'body' => $body,
            'tag' => 'Musicband-test-' . $eventId
        ];

        $status = 'uncertain';
        $httpCode = 0;
        try {
            MusicbandPushEndpoint($sub['endpoint']);
            $report = $push->sendOneNotification(
                Minishlink\WebPush\Subscription::create($sub),
                json_encode($payloadData, JSON_THROW_ON_ERROR)
            );
            $httpCode = (int)($report->getResponse()?->getStatusCode() ?? 0);
            if ($report->isSuccess()) {
                $status = 'accepted';
            } elseif ($report->isSubscriptionExpired()) {
                $status = 'expired';
            } else {
                $status = 'failed';
            }
        } catch (Throwable $e) {
            $status = 'uncertain';
            $details[] = 'Erreur réseau : ' . $e->getMessage();
            error_log('Musicband_test_push_uncertain: ' . $e->getMessage());
        }

        $db->prepare('UPDATE Musicbandai_push_deliveries SET status=?, completed_at=UTC_TIMESTAMP(6) WHERE event_id=? AND subscription_id=?')->execute([$status, $eventId, $subId]);

        if ($status === 'expired') {
            $db->prepare('UPDATE Musicbandai_push_subscriptions SET active=0 WHERE id=?')->execute([$subId]);
            $details[] = 'Abonnement expiré ou révoqué (HTTP ' . $httpCode . ')';
        } elseif ($status === 'failed') {
            $details[] = 'Rejeté par le service Push distant (HTTP ' . $httpCode . ')';
        }

        $outcomes[$status]++;
    }

    $success = ($outcomes['accepted'] > 0);

    MusicbandNotificationsRecordTest([
        'at' => gmdate('Y-m-d\TH:i:s\Z'),
        'login' => $login,
        'count' => count($items),
        'outcomes' => $outcomes,
        'success' => $success
    ]);

    if (function_exists('MusicbandAuditEvent')) {
        MusicbandAuditEvent('notifications', 'test_notification.sent', $success ? 'success' : 'rejected', ['target' => $login, 'outcomes' => $outcomes]);
    }

    if ($success) {
        $msg = '✓ Notification de test transmise avec succès au service Push distant (' . $outcomes['accepted'] . ' appareil(s) accepté(s) par Apple/Google). En attente d’affichage sur votre écran (l’affichage effectif dépend des autorisations système de l’appareil et de l’état de veille).';
        if ($outcomes['expired'] > 0 || $outcomes['failed'] > 0) {
            $msg .= ' Attention : ' . ($outcomes['expired'] + $outcomes['failed']) . ' appareil(s) ont échoué (' . implode(' ; ', $details) . ').';
        }
        return ['success' => true, 'count' => $outcomes['accepted'], 'outcomes' => $outcomes, 'message' => $msg];
    }

    $msg = 'Échec de transmission de la notification de test. ';
    if ($outcomes['expired'] > 0) {
        $msg .= 'L’abonnement de cet appareil a expiré ou a été révoqué par Apple/Google. Veuillez le réactiver sur la section « Notifications et rappels » de Préférences.';
    } elseif (!empty($details)) {
        $msg .= implode(' ; ', $details);
    } else {
        $msg .= 'Le service Push distant a rejeté la demande. Vérifiez les clés VAPID et la connectivité sortante HTTPS du serveur.';
    }

    return ['success' => false, 'count' => 0, 'outcomes' => $outcomes, 'message' => $msg];
}

function MusicbandExtraSchema(string $kind, array $settings): string {
    if (($_POST['confirm'] ?? '') !== 'yes' || ($_POST['confirm_database'] ?? '') !== ($settings['DB_NAME'] ?? null)) throw new RuntimeException('Confirmer la sauvegarde et la base exacte.');
    if (!MusicbandRbacComplete()) throw new RuntimeException('Initialiser le RBAC 010 auparavant.');
    $sql = base64_decode($kind === 'validation' ? '' : '', true);
    if (!$sql) throw new RuntimeException('Schéma non embarqué : préparer le site.');
    $db = MusicbandDatabase($settings);
    foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') $db->exec($statement);
    $db->beginTransaction();
    try {
        $permissions = $kind === 'validation' ? ['setlist.validate'] : ['notifications.subscribe', 'notifications.configure'];
        $q = $db->prepare('INSERT IGNORE INTO Musicbandai_rbac_permissions(role_key,permission_key,allowed) VALUES (?,?,?)');
        foreach (MusicbandRoles() as $role => $_) foreach ($permissions as $permission) $q->execute([$role, $permission, (int)($role === 'admin' || $permission === 'notifications.subscribe')]);
        if ($kind !== 'validation') {
            if (!MusicbandNotificationsReady($db)) throw new RuntimeException('Schéma 012 incomplet.');
            $q = $db->prepare('INSERT IGNORE INTO Musicbandai_notification_rules(event_key,fields_json,recipients_json,revision,updated_by,updated_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))');
            foreach (MusicbandNotificationEvents() as $event => $_) $q->execute([$event, json_encode(['adresse', 'heure_arrivee', 'heure_prestation', 'duree']), '[]', bin2hex(random_bytes(16)), $_SESSION['login']]);
        } elseif (!MusicbandFeatureTable($db, 'setlist_validations')) throw new RuntimeException('Schéma 011 incomplet.');
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return $kind === 'validation' ? 'Schéma 011 prêt. Aucune setlist validée automatiquement.' : 'Schéma 012 prêt. Règles désactivées, aucun abonnement ni envoi créé.';
}

function MusicbandExtraUpgradeForm(string $hidden): string {
    $html = '';
    foreach (['validation' => '011 · Validation des setlists', 'notifications' => '012 · Notifications PWA'] as $kind => $label) {
        $html .= '<section><h3>' . $label . '</h3><p>Ajouts MySQL uniquement. Sauvegarder base et stockage privé. Une DDL interrompue nécessite inspection puis reprise explicite ; les données existantes sont conservées.</p><form method="post">' . $hidden . '<input type="hidden" name="action" value="' . $kind . '_schema"><label>Nom exact de la base<input name="confirm_database" required></label><label><input type="checkbox" name="confirm" value="yes" required> Sauvegarde et destination vérifiées</label><button>Préparer ' . $label . '</button></form></section>';
    }
    return $html;
}

function MusicbandNotificationsSave(): string {
    MusicbandRequirePermission('notifications.configure');
    if (!MusicbandRequireLogin()['admin']) throw new RuntimeException('Configuration réservée aux administrateurs.');
    $db = MusicbandRbacDb();
    $rules = MusicbandNotificationRules($db);
    $posted = $_POST['notification_rules'] ?? null;
    $users = MusicbandRead('users');
    if (!is_array($posted) || count($posted) !== count(MusicbandNotificationEvents()) || array_diff(array_keys($posted), array_keys(MusicbandNotificationEvents()))) throw new RuntimeException('Règles incomplètes.');
    $values = [];
    foreach (MusicbandNotificationEvents() as $event => $_) {
        $r = $posted[$event] ?? [];
        if (!is_array($r) || !is_string($r['revision'] ?? null) || !hash_equals($rules[$event]['revision'] ?? '', $r['revision'])) throw new RuntimeException('Règle modifiée ailleurs : recharger.');
        if (!in_array($r['enabled'] ?? null, ['0', '1'], true) || !is_string($r['days'] ?? null) || !ctype_digit($r['days']) || (int)$r['days'] < 1 || (int)$r['days'] > 365) throw new RuntimeException('Délai ou activation invalide.');
        $fields = $r['fields'] ?? [];
        $recipients = $r['recipients'] ?? [];
        if (!is_array($fields) || !is_array($recipients) || array_diff($fields, array_keys(MusicbandNotificationFields())) || array_diff($recipients, array_keys($users)) || count($fields) !== count(array_unique($fields)) || count($recipients) !== count(array_unique($recipients))) throw new RuntimeException('Champs ou destinataires invalides.');
        if ($r['enabled'] === '1' && (!$recipients || ($event === 'concert.incomplete' && !$fields))) throw new RuntimeException('Choisir les destinataires et les champs à contrôler avant activation.');
        sort($fields, SORT_STRING);
        sort($recipients, SORT_STRING);
        $oldFields = json_decode($rules[$event]['fields_json'], true, 8, JSON_THROW_ON_ERROR);
        $oldRecipients = json_decode($rules[$event]['recipients_json'], true, 8, JSON_THROW_ON_ERROR);
        sort($oldFields, SORT_STRING);
        sort($oldRecipients, SORT_STRING);
        if ((int)$r['enabled'] === (int)$rules[$event]['enabled'] && (int)$r['days'] === (int)$rules[$event]['lead_days'] && $fields === $oldFields && $recipients === $oldRecipients) continue;
        $values[] = [$r['enabled'], (int)$r['days'], json_encode(array_values($fields), JSON_THROW_ON_ERROR), json_encode(array_values($recipients), JSON_THROW_ON_ERROR), bin2hex(random_bytes(16)), $_SESSION['login'], $event];
    }
    $db->beginTransaction();
    try {
        $q = $db->prepare('UPDATE Musicbandai_notification_rules SET enabled=?,lead_days=?,fields_json=?,recipients_json=?,revision=?,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE event_key=?');
        foreach ($values as $v) $q->execute($v);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return 'Règles enregistrées pour les prochains événements. Aucun envoi immédiat. Chaque appareil doit accepter les notifications.';
}

function MusicbandNotificationsDiagnostics(): string {
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $autoload = realpath(MusicbandWebpushSetting('Musicband_WEBPUSH_AUTOLOAD'));
    $checks = [
        'PHP 8.2 ou supérieur' => PHP_VERSION_ID >= 80200,
        'Extensions cURL, mbstring et OpenSSL' => extension_loaded('curl') && extension_loaded('mbstring') && extension_loaded('openssl'),
        'Autoload Web Push lisible hors racine publique' => $autoload && is_file($autoload) && is_readable($autoload) && ($root === '' || !str_starts_with($autoload, $root . '/')),
        'Clés VAPID renseignées' => (bool)MusicbandWebpushSetting('Musicband_VAPID_PUBLIC_KEY') && (bool)MusicbandWebpushSetting('Musicband_VAPID_PRIVATE_KEY'),
        'Contact VAPID renseigné' => preg_match('~^(mailto:[^\s]+@[^\s]+|https://[^\s]+)$~D', MusicbandWebpushSetting('Musicband_VAPID_SUBJECT')) === 1,
        'Secret de tâche planifiée configuré' => preg_match('/^[a-f0-9]{64}$/D', MusicbandWebpushSetting('Musicband_NOTIFICATIONS_CRON_TOKEN')) === 1
    ];
    $html = '<h3>Diagnostic serveur</h3><ul>';
    foreach ($checks as $label => $ok) {
        $html .= '<li>' . MusicbandEscape($label) . ' : ' . ($ok ? '<span style="color:#86efac;font-weight:600;">présent</span>' : '<span style="color:#ef4444;font-weight:600;">à corriger</span>') . '</li>';
    }
    return $html . '</ul><p><small>Ces vérifications confirment la présence des prérequis techniques sur le serveur.</small></p>';
}

/**
 * Génère le panneau d'observabilité administrateur des notifications (CRON, secours, abonnements, journal).
 */
function MusicbandNotificationsAdminObservability(PDO $db): string {
    $state = MusicbandNotificationsState();
    $devices = (int)$db->query('SELECT COUNT(*) FROM Musicbandai_push_subscriptions WHERE active=1')->fetchColumn();
    $lastDelivery = $db->query('SELECT d.event_id, d.subscription_id, d.status, d.requested_at, d.completed_at, e.event_key, s.login FROM Musicbandai_push_deliveries d JOIN Musicbandai_notification_events e ON e.id=d.event_id JOIN Musicbandai_push_subscriptions s ON s.id=d.subscription_id ORDER BY d.requested_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

    $lastCronAt = !empty($state['last_cron_at']) ? MusicbandDisplayDate($state['last_cron_at'], true, true) : 'Aucun passage enregistré';
    $lastCronStatus = (string)($state['last_cron_status'] ?? '—');
    $lastCronOutcomes = is_array($state['last_cron_outcomes'] ?? null) ? $state['last_cron_outcomes'] : [];
    $cronSummary = !empty($lastCronOutcomes) ? implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($lastCronOutcomes), $lastCronOutcomes)) : 'aucun envoi';

    $lastFallbackAt = !empty($state['last_fallback_at']) ? MusicbandDisplayDate($state['last_fallback_at'], true, true) : 'Aucun déclenchement';
    $lastFallbackStatus = (string)($state['last_fallback_status'] ?? '—');
    $lastFallbackOutcomes = is_array($state['last_fallback_outcomes'] ?? null) ? $state['last_fallback_outcomes'] : [];
    $fallbackSummary = !empty($lastFallbackOutcomes) ? implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($lastFallbackOutcomes), $lastFallbackOutcomes)) : 'aucun envoi';

    $isOverdue = MusicbandNotificationsIsOverdue();
    $syncBadge = $isOverdue
        ? '<span style="display:inline-block;padding:2px 8px;border-radius:4px;background:#ef4444;color:#fff;font-size:0.75rem;font-weight:700;">En retard (> 65 min)</span>'
        : '<span style="display:inline-block;padding:2px 8px;border-radius:4px;background:#22c55e;color:#fff;font-size:0.75rem;font-weight:700;">À jour</span>';

    $lastTest = is_array($state['last_test'] ?? null) ? $state['last_test'] : null;
    $testText = 'Aucun test récent.';
    if ($lastTest) {
        $testDate = !empty($lastTest['at']) ? MusicbandDisplayDate($lastTest['at'], true, true) : 'récent';
        $testLogin = MusicbandEscape((string)($lastTest['login'] ?? ''));
        $testSuccess = !empty($lastTest['success']);
        $testBadge = $testSuccess
            ? '<span style="color:#86efac;font-weight:600;">✓ Accepté par le fournisseur Push</span>'
            : '<span style="color:#ef4444;font-weight:600;">✗ Échec de transmission</span>';
        $testText = "Test du $testDate par $testLogin : $testBadge.";
    }

    $errors = is_array($state['recent_errors'] ?? null) ? $state['recent_errors'] : [];

    $counts = $db->query('SELECT status, COUNT(*) AS n FROM Musicbandai_push_deliveries GROUP BY status')->fetchAll();
    $journalSummary = '';
    foreach ($counts as $c) {
        $journalSummary .= MusicbandEscape((string)$c['status']) . ' : ' . (int)$c['n'] . ' · ';
    }

    $html = '<div style="background:rgba(255,255,255,0.04);border:1px solid #333;border-radius:8px;padding:16px;margin:16px 0;">' .
        '<h3 style="margin-top:0;">Observabilité & Moteur Hybride</h3>' .
        '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-bottom:12px;">' .
        '<div><strong>Tâche planifiée OVH (CRON) :</strong><br>' . MusicbandEscape($lastCronAt) . ' (' . MusicbandEscape($lastCronStatus) . ')<br><small style="color:#999;">' . MusicbandEscape($cronSummary) . '</small></div>' .
        '<div><strong>Traitement de secours (Fallback) :</strong><br>' . MusicbandEscape($lastFallbackAt) . ' (' . MusicbandEscape($lastFallbackStatus) . ')<br><small style="color:#999;">' . MusicbandEscape($fallbackSummary) . '</small></div>' .
        '<div><strong>État du moteur :</strong> ' . $syncBadge . '<br><small style="color:#999;">Seuil de retard : 65 min · Secours min : 30 min</small></div>' .
        '<div><strong>Abonnements actifs :</strong><br><span style="font-size:1.25rem;font-weight:700;color:#86efac;">' . $devices . '</span> appareil(s) abonné(s)</div>' .
        '</div>' .
        '<p style="margin:8px 0;"><strong>Dernier test émis :</strong> ' . $testText . '</p>';

    if ($lastDelivery) {
        $lastDelivDate = MusicbandDisplayDate($lastDelivery['requested_at'], true, true);
        $lastDelivEvent = MusicbandEscape((string)$lastDelivery['event_key']);
        $lastDelivUser = MusicbandEscape((string)$lastDelivery['login']);
        $lastDelivStatus = MusicbandEscape((string)$lastDelivery['status']);
        $html .= '<p style="margin:8px 0;"><strong>Dernière livraison enregistrée :</strong> le ' . MusicbandEscape($lastDelivDate) . ' pour ' . $lastDelivUser . ' (' . $lastDelivEvent . ') · Statut : <code>' . $lastDelivStatus . '</code></p>';
    }

    if ($journalSummary !== '') {
        $html .= '<p style="margin:8px 0;font-size:0.88rem;color:#ccc;"><strong>Cumul des livraisons :</strong> ' . $journalSummary . '</p>';
    }

    $html .= '<p style="margin:8px 0 0 0;font-size:0.82rem;color:#9ca3af;border-top:1px solid #333;padding-top:8px;">' .
        '<em>Note : L’état « accepted » atteste de la prise en charge et validation du message par le service distant (Apple APNs ou Google FCM). L’affichage réel sur l’appareil dépend de l’autorisation système iOS/Android et de l’état de veille de l’écran.</em>' .
        '</p>';

    if (!empty($errors)) {
        $html .= '<details style="margin-top:10px;"><summary style="cursor:pointer;color:#f87171;">Incidents techniques récents (' . count($errors) . ')</summary><ul style="font-size:0.85rem;color:#fca5a5;padding-left:20px;margin-top:6px;">';
        foreach ($errors as $err) {
            $errDate = !empty($err['at']) ? MusicbandDisplayDate($err['at'], true, true) : 'récent';
            $errSrc = MusicbandEscape((string)($err['source'] ?? ''));
            $errMsg = MusicbandEscape((string)($err['message'] ?? ''));
            $html .= '<li>[' . MusicbandEscape($errDate) . '] [' . $errSrc . '] ' . $errMsg . '</li>';
        }
        $html .= '</ul></details>';
    }

    $html .= '</div>';
    return $html;
}

function MusicbandNotificationsForm(string $hidden): string {
    try {
        $db = MusicbandRbacDb();
        if (!MusicbandNotificationsReady($db)) throw new RuntimeException();
        $rules = MusicbandNotificationRules($db);
        $users = MusicbandRead('users');

        $html = '<section><h2>Notifications PWA</h2>' .
            MusicbandNotificationsDiagnostics() .
            MusicbandNotificationsAdminObservability($db) .
            '<p>Choisir les changements à signaler et les destinataires. Les nouvelles règles concernent les événements futurs. Un rappel est créé une seule fois par concert et date, lorsqu’il entre dans le délai choisi et reste incomplet. Chaque destinataire doit activer son appareil. Les messages sur écran verrouillé restent génériques ; consulter la fiche après connexion.</p><form method="post">' . $hidden . '<input type="hidden" name="action" value="notification_rules">';

        foreach (MusicbandNotificationEvents() as $event => $label) {
            $r = $rules[$event];
            $name = 'notification_rules[' . $event . ']';
            $html .= '<fieldset><legend>' . MusicbandEscape($label) . '</legend><input type="hidden" name="' . $name . '[revision]" value="' . $r['revision'] . '"><label>Activation<select name="' . $name . '[enabled]"><option value="0"' . (!$r['enabled'] ? ' selected' : '') . '>Désactivée</option><option value="1"' . ($r['enabled'] ? ' selected' : '') . '>Activée</option></select></label><label' . ($event !== 'concert.incomplete' ? ' hidden' : '') . '>Délai avant concert (jours)<input name="' . $name . '[days]" type="number" min="1" max="365" value="' . (int)$r['lead_days'] . '"></label>';
            if ($event === 'concert.incomplete') foreach (MusicbandNotificationFields() as $key => $text) $html .= '<label class="field-option-visible"><input type="checkbox" name="' . $name . '[fields][]" value="' . $key . '"' . (in_array($key, json_decode($r['fields_json'], true) ?: [], true) ? ' checked' : '') . '> ' . MusicbandEscape($text) . '</label>';
            foreach ($users as $login => $_) $html .= '<label class="field-option-visible"><input type="checkbox" name="' . $name . '[recipients][]" value="' . MusicbandEscape((string)$login) . '"' . (in_array((string)$login, json_decode($r['recipients_json'], true) ?: [], true) ? ' checked' : '') . '> ' . MusicbandEscape((string)$login) . '</label>';
            $html .= '</fieldset>';
        }

        return $html . '<button>Enregistrer les règles de notification</button></form></section>';
    } catch (Throwable $e) {
        return '<section><h2>Notifications PWA</h2>' . MusicbandNotificationsDiagnostics() . '<p class="notice">Lecture du suivi MySQL impossible : vérifier la connexion et le schéma 012 dans Upgrade. Les notifications nécessitent aussi une tâche planifiée côté hébergement.</p></section>';
    }
}

function MusicbandPushEndpoint(string $endpoint): void {
    $url = parse_url($endpoint);
    $host = strtolower($url['host'] ?? '');
    $allowed = $host === 'fcm.googleapis.com' || $host === 'updates.push.services.mozilla.com' || $host === 'web.push.apple.com' || str_ends_with($host, '.push.apple.com') || str_ends_with($host, '.push.services.mozilla.com');
    if (strlen($endpoint) > 2048 || !$allowed || ($url['scheme'] ?? '') !== 'https' || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || (isset($url['port']) && $url['port'] !== 443)) {
        throw new RuntimeException('Service Push non autorisé.');
    }
}

function MusicbandConcertReminderKey(array $concert, array $rule, array $policy): ?string {
    $date = businessConcertDate($concert['date_concert'] ?? null, $policy, businessDateImported($concert));
    $today = new DateTimeImmutable('today', new DateTimeZone($policy['timezone']));
    if (!$date || $date < $today->format('Y-m-d') || $date > $today->modify('+' . (int)$rule['lead_days'] . ' days')->format('Y-m-d')) return null;
    foreach (json_decode($rule['fields_json'], true, 8, JSON_THROW_ON_ERROR) as $field) {
        if (!isset(MusicbandNotificationFields()[$field])) continue;
        $value = trim((string)($concert[$field] ?? ''));
        if ($value === '' || (str_starts_with($field, 'heure_') && businessTemporalValue($field, $value) === null) || ($field === 'duree' && (!is_numeric($value) || (float)$value <= 0))) return $concert['id'] . '-' . $date;
    }
    return null;
}

/** Sections personnelles consolidées ; aucun abonnement modifié lors du rendu. */
function MusicbandPwaPreferencesSection(): string {
    return '<details class="preferences-section field-module" id="preferences-application"><summary>Application / PWA</summary><p>Sur iPhone/iPad : Partager → Ajouter à l’écran d’accueil, puis ouvrir Musicband depuis cette icône. Sur Android/ordinateur : utiliser Installer dans le navigateur. L’application requiert une connexion ; aucune fiche privée n’est conservée hors ligne.</p></details>';
}
function MusicbandPushPreferencesSection(): string {
    $test='';
    if(advancedNotificationsUserAllowed(MusicbandRequireLogin()['login']))$test='<form method="post" action="'.MusicbandEscape(MusicbandBase()).'activate.php?change=1#preferences-notifications"><input type="hidden" name="csrf" value="'.MusicbandEscape($_SESSION['csrf']).'"><input type="hidden" name="action" value="test_notification"><button type="submit">Envoyer une notification de test</button></form>';
    return '<section id="preferences-device"><h3>Notifications de cet appareil</h3><p>Activation facultative, pour le compte actuellement connecté. Un appareil partagé ne doit être activé que pour son utilisateur actuel. Les règles et destinataires sont choisis dans Configuration. Un changement de mot de passe révoque cet abonnement jusqu’à une nouvelle activation.</p><div class="navigation-order-controls" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;"><button id="push-enable" type="button">Activer sur cet appareil</button><button id="push-disable" type="button">Désactiver sur cet appareil</button></div><p id="push-status" role="status" aria-live="polite"></p>'.$test.'</section>';
}
/** Même script local, embarqué à la préparation, exécuté uniquement depuis Préférences. */
function MusicbandDevicePreferencesScript(): string {
    $js=base64_decode('LyogQWJvbm5lbWVudCBXZWIgUHVzaCBkdSBuYXZpZ2F0ZXVyIDogZGlhbG9ndWUgYXZlYyBub3RpZmljYXRpb25zX2RldmljZS5waHAgZXQgcHdhX3N3LnBocC4KICogQ29uc2VudGVtZW50IHN1ciBnZXN0ZSB1dGlsaXNhdGV1ciwgY29udHLDtGxlIFZBUElEIGV0IMOpdGF0IHNlcnZldXIgc8OpcGFyw6kgZGUgbOKAmWFib25uZW1lbnQgbG9jYWwuCiAqIExlcyDDqWNyaXR1cmVzIHV0aWxpc2VudCBsZSBqZXRvbiBDU1JGIGx1IHN1ciBs4oCZQVBJIDsgYXVjdW5lIG1pc2UgZW4gY2FjaGUgZGUgZG9ubsOpZXMgbcOpdGllci4KICovCigoKSA9PiB7CiAgY29uc3Qgc3RhdHVzPWRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdwdXNoLXN0YXR1cycpLGVuYWJsZT1kb2N1bWVudC5nZXRFbGVtZW50QnlJZCgncHVzaC1lbmFibGUnKSxkaXNhYmxlPWRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdwdXNoLWRpc2FibGUnKSx0ZXN0QnRuPWRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdwdXNoLXRlc3QnKTsKICBpZighc3RhdHVzfHwhZW5hYmxlfHwhZGlzYWJsZSlyZXR1cm47CiAgbGV0IGJ1c3k9ZmFsc2Usc3RhdGVWZXJzaW9uPTA7CiAgY29uc3QgYXBpPW5ldyBVUkwoJ25vdGlmaWNhdGlvbnNfZGV2aWNlLnBocD9hcGk9MScsbG9jYXRpb24uaHJlZiksYmFzZT1uZXcgVVJMKCcuLycsYXBpKTsKICBhc3luYyBmdW5jdGlvbiByZXF1ZXN0KGJvZHksc3Vic2NyaXB0aW9uSGFzaD0nJyl7Y29uc3QgdXJsPW5ldyBVUkwoYXBpKTtpZihzdWJzY3JpcHRpb25IYXNoKXVybC5zZWFyY2hQYXJhbXMuc2V0KCdzdWJzY3JpcHRpb25faGFzaCcsc3Vic2NyaXB0aW9uSGFzaCk7Y29uc3Qgcj1hd2FpdCBmZXRjaCh1cmwse2NyZWRlbnRpYWxzOidzYW1lLW9yaWdpbicsY2FjaGU6J25vLXN0b3JlJyxtZXRob2Q6Ym9keT8nUE9TVCc6J0dFVCcsYm9keX0pO2NvbnN0IHY9YXdhaXQgci5qc29uKCk7aWYoIXIub2spdGhyb3cgbmV3IEVycm9yKHYuZXJyb3J8fCdBY3Rpb24gcmVmdXPDqWUuJyk7cmV0dXJuIHY7fQogIGFzeW5jIGZ1bmN0aW9uIHJlZ2lzdHJhdGlvbigpe2lmKCFpc1NlY3VyZUNvbnRleHR8fCEoJ3NlcnZpY2VXb3JrZXInaW4gbmF2aWdhdG9yKXx8ISgnUHVzaE1hbmFnZXInaW4gd2luZG93KXx8ISgnTm90aWZpY2F0aW9uJ2luIHdpbmRvdykpdGhyb3cgbmV3IEVycm9yKCdOb3RpZmljYXRpb25zIG5vbiBwcmlzZXMgZW4gY2hhcmdlIGljaS4gU3VyIGlQaG9uZS9pUGFkLCBvdXZyaXIgbGEgdmVyc2lvbiBham91dMOpZSDDoCBs4oCZw6ljcmFuIGTigJlhY2N1ZWlsLicpO2F3YWl0IG5hdmlnYXRvci5zZXJ2aWNlV29ya2VyLnJlZ2lzdGVyKG5ldyBVUkwoJ3B3YV9zdy5waHAnLGJhc2UpLHtzY29wZTpiYXNlLnBhdGhuYW1lfSk7bGV0IHRpbWVyO3RyeXtyZXR1cm4gYXdhaXQgUHJvbWlzZS5yYWNlKFtuYXZpZ2F0b3Iuc2VydmljZVdvcmtlci5yZWFkeSxuZXcgUHJvbWlzZSgoXyxyZWplY3QpPT57dGltZXI9c2V0VGltZW91dCgoKT0+cmVqZWN0KG5ldyBFcnJvcignTGUgc2VydmljZSB3b3JrZXIgbmUgZGV2aWVudCBwYXMgYWN0aWYuIFbDqXJpZmllciBsYSBwdWJsaWNhdGlvbiBkZSBwd2Ffc3cucGhwIGV0IGxlIGNoZW1pbiBkdSBzaXRlLicpKSwxNTAwMCk7fSldKTt9ZmluYWxseXtjbGVhclRpbWVvdXQodGltZXIpO319CiAgZnVuY3Rpb24ga2V5Qnl0ZXModmFsdWUpe2NvbnN0IHJhdz1hdG9iKHZhbHVlLnJlcGxhY2UoLy0vZywnKycpLnJlcGxhY2UoL18vZywnLycpKTtyZXR1cm4gVWludDhBcnJheS5mcm9tKHJhdyxjPT5jLmNoYXJDb2RlQXQoMCkpO30KICAvLyBJbnZhbGlkZXIgbGVzIGxlY3R1cmVzIGVuIGNvdXJzIGF2YW50IHRvdXRlIG1vZGlmaWNhdGlvbiBkZSBs4oCZYWJvbm5lbWVudC4KICBhc3luYyBmdW5jdGlvbiBhY3Rpb24oc3Vic2NyaWJlKXtpZihidXN5KXJldHVybjtzdGF0ZVZlcnNpb24rKztidXN5PXRydWU7ZW5hYmxlLmRpc2FibGVkPWRpc2FibGUuZGlzYWJsZWQ9dHJ1ZTtpZih0ZXN0QnRuKXRlc3RCdG4uZGlzYWJsZWQ9dHJ1ZTt0cnl7CiAgICAvLyBMYSBkZW1hbmRlIGTigJlhdXRvcmlzYXRpb24gcmVzdGUgZGlyZWN0ZW1lbnQgbGnDqWUgYXUgZ2VzdGUgZGUgbOKAmXV0aWxpc2F0ZXVyLgogICAgaWYoc3Vic2NyaWJlKXtpZighKCdOb3RpZmljYXRpb24naW4gd2luZG93KSl0aHJvdyBuZXcgRXJyb3IoJ05vdGlmaWNhdGlvbnMgaW5kaXNwb25pYmxlcy4nKTtjb25zdCBjb25zZW50PWF3YWl0IE5vdGlmaWNhdGlvbi5yZXF1ZXN0UGVybWlzc2lvbigpO2lmKGNvbnNlbnQhPT0nZ3JhbnRlZCcpdGhyb3cgbmV3IEVycm9yKCdOb3RpZmljYXRpb25zIG5vbiBhdXRvcmlzw6llcy4gVm91cyBwb3V2ZXogY2hhbmdlciBjZSBjaG9peCBkYW5zIGxlcyByw6lnbGFnZXMgZHUgbmF2aWdhdGV1ci4nKTt9CiAgICBjb25zdCByZWc9YXdhaXQgcmVnaXN0cmF0aW9uKCk7bGV0IHN1Yj1hd2FpdCByZWcucHVzaE1hbmFnZXIuZ2V0U3Vic2NyaXB0aW9uKCk7CiAgICBpZighc3Vic2NyaWJlKXtpZihzdWIpe2NvbnN0IGVuZHBvaW50PXN1Yi5lbmRwb2ludDthd2FpdCBzdWIudW5zdWJzY3JpYmUoKTt0cnl7Y29uc3Qgc2Vzc2lvbj1hd2FpdCByZXF1ZXN0KCk7YXdhaXQgcmVxdWVzdChuZXcgVVJMU2VhcmNoUGFyYW1zKHtjc3JmOnNlc3Npb24uY3NyZixhY3Rpb246J2Rpc2FibGUnLGVuZHBvaW50fSkpO31jYXRjaChfZSl7c3RhdHVzLnRleHRDb250ZW50PSdBcHBhcmVpbCBkw6lzYWJvbm7DqS4gU3luY2hyb25pc2F0aW9uIHNlcnZldXIgbm9uIGNvbmZpcm3DqWUgOyB1biBhYm9ubmVtZW50IGV4cGlyw6kgc2VyYSBkw6lzYWN0aXbDqSBhdSBwcm9jaGFpbiB0cmFpdGVtZW50Lic7cmV0dXJuO319c3RhdHVzLnRleHRDb250ZW50PSdOb3RpZmljYXRpb25zIGTDqXNhY3RpdsOpZXMgc3VyIGNldCBhcHBhcmVpbC4nO2lmKHRlc3RCdG4pdGVzdEJ0bi5zdHlsZS5kaXNwbGF5PSdub25lJztyZXR1cm47fQogICAgY29uc3Qgc2Vzc2lvbj1hd2FpdCByZXF1ZXN0KCk7CiAgICBpZihzdWJzY3JpYmUpe2lmKCFzZXNzaW9uLnB1YmxpY0tleSl0aHJvdyBuZXcgRXJyb3IoJ0Nsw6kgcHVibGlxdWUgV2ViIFB1c2ggbm9uIGNvbmZpZ3Vyw6llIHN1ciBsZSBzZXJ2ZXVyLicpO2NvbnN0IHNlcnZlcktleT1rZXlCeXRlcyhzZXNzaW9uLnB1YmxpY0tleSk7CiAgICAgIC8vIFVuIGFib25uZW1lbnQgY3LDqcOpIGF2ZWMgdW5lIGFuY2llbm5lIHBhaXJlIFZBUElEIG5lIHBldXQgcGFzIHJlY2V2b2lyIGxlcyBub3V2ZWF1eCBlbnZvaXMuCiAgICAgIGNvbnN0IHByZXZpb3VzS2V5PXN1Yj8ub3B0aW9ucz8uYXBwbGljYXRpb25TZXJ2ZXJLZXk7CiAgICAgIGlmKHN1YiYmcHJldmlvdXNLZXkpe2NvbnN0IGJ5dGVzPW5ldyBVaW50OEFycmF5KHByZXZpb3VzS2V5KTtpZihieXRlcy5sZW5ndGghPT1zZXJ2ZXJLZXkubGVuZ3RofHxieXRlcy5zb21lKCh2YWx1ZSxpbmRleCk9PnZhbHVlIT09c2VydmVyS2V5W2luZGV4XSkpewogICAgICAgIGNvbnN0IGVuZHBvaW50PXN1Yi5lbmRwb2ludDsKICAgICAgICBpZighYXdhaXQgc3ViLnVuc3Vic2NyaWJlKCkpdGhyb3cgbmV3IEVycm9yKCdBbmNpZW4gYWJvbm5lbWVudCBub24gcsOpdm9xdcOpLiBEw6lzYWN0aXZlciBwdWlzIHLDqWFjdGl2ZXIgbGVzIG5vdGlmaWNhdGlvbnMgc3VyIGNldCBhcHBhcmVpbC4nKTsKICAgICAgICBzdWI9bnVsbDsKICAgICAgICBhd2FpdCByZXF1ZXN0KG5ldyBVUkxTZWFyY2hQYXJhbXMoe2NzcmY6c2Vzc2lvbi5jc3JmLGFjdGlvbjonZGlzYWJsZScsZW5kcG9pbnR9KSk7CiAgICAgIH19CiAgICAgIGlmKCFzdWIpc3ViPWF3YWl0IHJlZy5wdXNoTWFuYWdlci5zdWJzY3JpYmUoe3VzZXJWaXNpYmxlT25seTp0cnVlLGFwcGxpY2F0aW9uU2VydmVyS2V5OnNlcnZlcktleX0pO2F3YWl0IHJlcXVlc3QobmV3IFVSTFNlYXJjaFBhcmFtcyh7Y3NyZjpzZXNzaW9uLmNzcmYsYWN0aW9uOidzdWJzY3JpYmUnLGVuZHBvaW50OnN1Yi5lbmRwb2ludCxzdWJzY3JpcHRpb246SlNPTi5zdHJpbmdpZnkoc3ViLnRvSlNPTigpKX0pKTtzdGF0dXMudGV4dENvbnRlbnQ9J05vdGlmaWNhdGlvbnMgYWN0aXbDqWVzIHN1ciBjZXQgYXBwYXJlaWwgcG91ciBjZSBjb21wdGUuIFZvdXMgcG91dmV6IHRlc3RlciBsYSByw6ljZXB0aW9uIGRhbnMgY2V0dGUgc2VjdGlvbi4nO2lmKHRlc3RCdG4pdGVzdEJ0bi5zdHlsZS5kaXNwbGF5PSdpbmxpbmUtYmxvY2snO30KCiAgfWNhdGNoKGUpe3N0YXR1cy50ZXh0Q29udGVudD1lLm1lc3NhZ2U7fWZpbmFsbHl7YnVzeT1mYWxzZTtlbmFibGUuZGlzYWJsZWQ9ZGlzYWJsZS5kaXNhYmxlZD1mYWxzZTtpZih0ZXN0QnRuKXRlc3RCdG4uZGlzYWJsZWQ9ZmFsc2U7fX0KICBhc3luYyBmdW5jdGlvbiB0ZXN0Tm90aWZpY2F0aW9uKCl7CiAgICBpZihidXN5KXJldHVybjtidXN5PXRydWU7aWYodGVzdEJ0bil0ZXN0QnRuLmRpc2FibGVkPXRydWU7ZW5hYmxlLmRpc2FibGVkPWRpc2FibGUuZGlzYWJsZWQ9dHJ1ZTtzdGF0dXMudGV4dENvbnRlbnQ9J0Vudm9pIGRlIGxhIG5vdGlmaWNhdGlvbiBkZSB0ZXN0IGVuIGNvdXJz4oCmJzsKICAgIHRyeXsKICAgICAgY29uc3Qgc2Vzc2lvbj1hd2FpdCByZXF1ZXN0KCk7CiAgICAgIGNvbnN0IHJlcz1hd2FpdCByZXF1ZXN0KG5ldyBVUkxTZWFyY2hQYXJhbXMoe2NzcmY6c2Vzc2lvbi5jc3JmLGFjdGlvbjondGVzdF9ub3RpZmljYXRpb24nfSkpOwogICAgICBzdGF0dXMudGV4dENvbnRlbnQ9cmVzLm1lc3NhZ2V8fChyZXMub2s/J05vdGlmaWNhdGlvbiBkZSB0ZXN0IHRyYW5zbWlzZSBhdmVjIHN1Y2PDqHMuJzonw4ljaGVjIGR1IHRlc3QuJyk7CiAgICB9Y2F0Y2goZSl7CiAgICAgIHN0YXR1cy50ZXh0Q29udGVudD0nRXJyZXVyIGxvcnMgZHUgdGVzdCA6ICcrZS5tZXNzYWdlOwogICAgfWZpbmFsbHl7CiAgICAgIGJ1c3k9ZmFsc2U7aWYodGVzdEJ0bil0ZXN0QnRuLmRpc2FibGVkPWZhbHNlO2VuYWJsZS5kaXNhYmxlZD1kaXNhYmxlLmRpc2FibGVkPWZhbHNlOwogICAgfQogIH0KICAvLyBVbiBhYm9ubmVtZW50IGxvY2FsIG5lIHN1ZmZpdCBwYXMgOiB2w6lyaWZpZXIgYXVzc2kgc29uIGFzc29jaWF0aW9uIGF1IGNvbXB0ZSBzZXJ2ZXVyLgogIGFzeW5jIGZ1bmN0aW9uIHJlZnJlc2hTdGF0dXMoKXsKICAgIGNvbnN0IHZlcnNpb249KytzdGF0ZVZlcnNpb247c3RhdHVzLnRleHRDb250ZW50PSdWw6lyaWZpY2F0aW9uIGRlIGzigJlhY3RpdmF0aW9uIHN1ciBjZXQgYXBwYXJlaWzigKYnOwogICAgdHJ5ewogICAgICBpZighaXNTZWN1cmVDb250ZXh0fHwhKCdzZXJ2aWNlV29ya2VyJ2luIG5hdmlnYXRvcil8fCEoJ1B1c2hNYW5hZ2VyJ2luIHdpbmRvdyl8fCEoJ05vdGlmaWNhdGlvbidpbiB3aW5kb3cpKXRocm93IG5ldyBFcnJvcignTm90aWZpY2F0aW9ucyBpbmRpc3BvbmlibGVzIGljaS4gU3VyIGlQaG9uZSwgb3V2cmlyIE11c2ljYmFuZCBkZXB1aXMgbOKAmWljw7RuZSBham91dMOpZSDDoCBs4oCZw6ljcmFuIGTigJlhY2N1ZWlsLicpOwogICAgICBjb25zdCByZWc9YXdhaXQgbmF2aWdhdG9yLnNlcnZpY2VXb3JrZXIuZ2V0UmVnaXN0cmF0aW9uKGJhc2UuaHJlZik7CiAgICAgIGNvbnN0IHN1Yj1yZWc/YXdhaXQgcmVnLnB1c2hNYW5hZ2VyLmdldFN1YnNjcmlwdGlvbigpOm51bGw7CiAgICAgIGlmKHZlcnNpb24hPT1zdGF0ZVZlcnNpb258fGJ1c3kpcmV0dXJuOwogICAgICBpZighc3VifHxOb3RpZmljYXRpb24ucGVybWlzc2lvbiE9PSdncmFudGVkJyl7c3RhdHVzLnRleHRDb250ZW50PU5vdGlmaWNhdGlvbi5wZXJtaXNzaW9uPT09J2RlbmllZCc/J05vdGlmaWNhdGlvbnMgcmVmdXPDqWVzIGRhbnMgbGVzIHLDqWdsYWdlcyBkZSBjZXQgYXBwYXJlaWwuJzonTm90aWZpY2F0aW9ucyBub24gYWN0aXbDqWVzIHN1ciBjZXQgYXBwYXJlaWwuJztpZih0ZXN0QnRuKXRlc3RCdG4uc3R5bGUuZGlzcGxheT0nbm9uZSc7cmV0dXJuO30KICAgICAgLy8gTOKAmVVSTCBQdXNoIHJlc3RlIHN1ciBs4oCZYXBwYXJlaWwgOiBzZXVsZSBzb24gZW1wcmVpbnRlIGVzdCBlbnZvecOpZSBwb3VyIGxhIGxlY3R1cmUgZOKAmcOpdGF0LgogICAgICBjb25zdCBkaWdlc3Q9YXdhaXQgY3J5cHRvLnN1YnRsZS5kaWdlc3QoJ1NIQS0yNTYnLG5ldyBUZXh0RW5jb2RlcigpLmVuY29kZShzdWIuZW5kcG9pbnQpKTsKICAgICAgY29uc3QgaGFzaD1bLi4ubmV3IFVpbnQ4QXJyYXkoZGlnZXN0KV0ubWFwKHZhbHVlPT52YWx1ZS50b1N0cmluZygxNikucGFkU3RhcnQoMiwnMCcpKS5qb2luKCcnKTsKICAgICAgY29uc3Qgc2Vzc2lvbj1hd2FpdCByZXF1ZXN0KG51bGwsaGFzaCk7CiAgICAgIGlmKHZlcnNpb24hPT1zdGF0ZVZlcnNpb258fGJ1c3kpcmV0dXJuOwogICAgICBpZighc2Vzc2lvbi5wdWJsaWNLZXkpdGhyb3cgbmV3IEVycm9yKCdDbMOpIHB1YmxpcXVlIFdlYiBQdXNoIG5vbiBjb25maWd1csOpZSBzdXIgbGUgc2VydmV1ci4nKTsKICAgICAgY29uc3QgcHJldmlvdXM9c3ViLm9wdGlvbnM/LmFwcGxpY2F0aW9uU2VydmVyS2V5LGV4cGVjdGVkPWtleUJ5dGVzKHNlc3Npb24ucHVibGljS2V5KTsKICAgICAgY29uc3QgYnl0ZXM9cHJldmlvdXM/bmV3IFVpbnQ4QXJyYXkocHJldmlvdXMpOm51bGw7CiAgICAgIGNvbnN0IGNoYW5nZWQ9Ynl0ZXMmJihieXRlcy5sZW5ndGghPT1leHBlY3RlZC5sZW5ndGh8fGJ5dGVzLnNvbWUoKHZhbHVlLGluZGV4KT0+dmFsdWUhPT1leHBlY3RlZFtpbmRleF0pKTsKICAgICAgaWYodGVzdEJ0bil0ZXN0QnRuLnN0eWxlLmRpc3BsYXk9c2Vzc2lvbi5hY3RpdmU/J2lubGluZS1ibG9jayc6J25vbmUnOwogICAgICBzdGF0dXMudGV4dENvbnRlbnQ9Y2hhbmdlZD8nTGEgY29uZmlndXJhdGlvbiBQdXNoIGEgY2hhbmfDqSA6IHLDqWFjdGl2ZXIgbGVzIG5vdGlmaWNhdGlvbnMgc3VyIGNldCBhcHBhcmVpbC4nOnNlc3Npb24uYWN0aXZlPydOb3RpZmljYXRpb25zIGFjdGl2w6llcyBzdXIgY2V0IGFwcGFyZWlsIHBvdXIgY2UgY29tcHRlLiBVdGlsaXNleiBsZSBib3V0b24gZGUgdGVzdCBwb3VyIHbDqXJpZmllciBsYSByw6ljZXB0aW9uLic6J0Fib25uZW1lbnQgcHLDqXNlbnQgc3VyIGzigJlhcHBhcmVpbCwgbWFpcyBpbmFjdGlmIHBvdXIgY2UgY29tcHRlIGPDtHTDqSBzZXJ2ZXVyIDogY2xpcXVlciBzdXIgQWN0aXZlci4nOwogICAgfWNhdGNoKGVycm9yKXtpZih2ZXJzaW9uPT09c3RhdGVWZXJzaW9uJiYhYnVzeSl7c3RhdHVzLnRleHRDb250ZW50PSdBY3RpdmF0aW9uIG5vbiB2w6lyaWZpw6llIDogJytlcnJvci5tZXNzYWdlO2lmKHRlc3RCdG4pdGVzdEJ0bi5zdHlsZS5kaXNwbGF5PSdub25lJzt9fQogIH0KICBlbmFibGUuYWRkRXZlbnRMaXN0ZW5lcignY2xpY2snLCgpPT5hY3Rpb24odHJ1ZSkpO2Rpc2FibGUuYWRkRXZlbnRMaXN0ZW5lcignY2xpY2snLCgpPT5hY3Rpb24oZmFsc2UpKTsKICBpZih0ZXN0QnRuKXRlc3RCdG4uYWRkRXZlbnRMaXN0ZW5lcignY2xpY2snLHRlc3ROb3RpZmljYXRpb24pOwogIHJlZnJlc2hTdGF0dXMoKTsKfSkoKTsK',true);
    if(!$js&&is_file(__DIR__.'/pwa_device.js'))$js=file_get_contents(__DIR__.'/pwa_device.js');
    return $js?:'';
}
