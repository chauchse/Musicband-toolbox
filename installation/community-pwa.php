<?php
/** Installation PWA Community, sans réseau ni envoi de notification.
 * Appelé par install.php ou Upgrade après CSRF ; utilise community-storage.php et le paquet
 * Web Push préparé avec les dépendances Composer figées. Écrit exclusivement hors racine web.
 * Préserve les configurations existantes ; ne crée aucune tâche chez l'hébergeur.
 */
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

/** Prérequis locaux seulement : aucun appel HTTP, SQL ou chargement de secret. */
function communityPwaPrerequisites(): void {
    if (PHP_VERSION_ID < 80200) throw new RuntimeException('PHP 8.2 minimum requis pour la PWA.');
    foreach (['curl', 'mbstring', 'openssl', 'json', 'zlib'] as $extension) {
        if (!extension_loaded($extension)) throw new RuntimeException('Activer l’extension PWA : ' . $extension);
    }
    if (!is_file(__DIR__ . '/community-webpush-payload.php')) throw new RuntimeException('Paquet PWA absent : transférer la distribution complète.');
}

/** Refuse liens et droits exposés ; ne change pas les permissions d'un dossier préexistant. */
function communityPwaDirectory(string $path): void {
    if (is_link($path)) throw new RuntimeException('Lien symbolique interdit dans le stockage PWA.');
    if (!is_dir($path) && !@mkdir($path, 0700)) throw new RuntimeException('Création du dossier PWA impossible.');
    clearstatcache(true, $path);
    if (!is_dir($path) || (fileperms($path) & 0777) !== 0700) throw new RuntimeException('Permissions PWA privées 0700 requises.');
}

/** Reprise sans substitution : un fichier existant doit être privé et strictement identique. */
function communityPwaFile(string $path, string $bytes): void {
    if (is_link($path)) throw new RuntimeException('Lien symbolique interdit dans le stockage PWA.');
    if (file_exists($path)) {
        if (!is_file($path) || (fileperms($path) & 0077) !== 0 || !hash_equals(hash('sha256', $bytes), (string)hash_file('sha256', $path))) {
            throw new RuntimeException('Fichier PWA existant différent : conservé pour inspection.');
        }
        return;
    }
    $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $handle = @fopen($temporary, 'x');
    if (!$handle) throw new RuntimeException('Création privée PWA impossible.');
    try {
        if (!chmod($temporary, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) throw new RuntimeException('Écriture PWA incomplète.');
        fclose($handle); $handle = null;
        if (file_exists($path) || is_link($path) || !rename($temporary, $path)) throw new RuntimeException('Finalisation PWA impossible.');
    } finally {
        if (is_resource($handle)) fclose($handle);
        if (is_file($temporary)) @unlink($temporary);
    }
}

/** Initialise les clés propres à l'instance une seule fois, sous verrou ; aucun abonnement ni règle activé. */
function communityPwaSetup(string $baseUrl, string $email): string {
    communityPwaPrerequisites();
    $parts = parse_url($baseUrl);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !str_ends_with($baseUrl, '/') || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('URL HTTPS et e-mail administrateur valides requis pour la PWA.');
    }
    $private = communityPrivateDirectory();
    $lockPath = $private . '/.pwa.lock';
    if (is_link($lockPath)) throw new RuntimeException('Verrou PWA invalide.');
    $lock = @fopen($lockPath, 'c');
    if (!$lock || !@chmod($lockPath, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('Configuration PWA déjà en cours ou verrou indisponible.');
    }
    try {
        // Le chargeur historique privilégie ce dossier : ne jamais remplacer ses clés.
        foreach ([dirname($private) . '/Musicband-webpush/config.php', $private . '/webpush/config.php'] as $config) {
            if (file_exists($config) || is_link($config)) return 'Configuration PWA existante conservée ; vérifier le diagnostic dans Configuration → Général.';
        }
        foreach (['Musicband_WEBPUSH_AUTOLOAD', 'Musicband_VAPID_PUBLIC_KEY', 'Musicband_VAPID_PRIVATE_KEY', 'Musicband_VAPID_SUBJECT', 'Musicband_NOTIFICATIONS_CRON_TOKEN'] as $key) {
            if (getenv($key) !== false && getenv($key) !== '') return 'Configuration PWA d’environnement conservée ; vérifier le diagnostic dans Configuration → Général.';
        }
        $payload = require __DIR__ . '/community-webpush-payload.php';
        if (!is_array($payload) || !is_array($payload['files'] ?? null) || !is_string($payload['launcher'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $payload['id'] ?? '')) throw new RuntimeException('Paquet PWA invalide.');
        $directory = $private . '/webpush';
        communityPwaDirectory($directory);
        $vendor = $directory . '/vendor-' . $payload['id'];
        communityPwaDirectory($vendor);
        // Tous les fichiers sont vérifiés et écrits avant de charger l'autoloader privé.
        foreach ($payload['files'] as $name => $file) {
            if (!is_string($name) || !preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D', $name) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $name) || !is_array($file)) throw new RuntimeException('Chemin du paquet PWA invalide.');
            $bytes = base64_decode($file['data'] ?? '', true);
            if ($bytes === false || !is_string($file['sha256'] ?? null) || !hash_equals($file['sha256'], hash('sha256', $bytes))) throw new RuntimeException('Empreinte du paquet PWA invalide.');
            $parent = $vendor;
            foreach (array_slice(explode('/', $name), 0, -1) as $component) { $parent .= '/' . $component; communityPwaDirectory($parent); }
            communityPwaFile($vendor . '/' . $name, $bytes);
        }
        if (!isset($payload['files']['autoload.php'])) throw new RuntimeException('Autoload absent du paquet PWA.');
        require_once $vendor . '/autoload.php';
        if (!class_exists('Minishlink\\WebPush\\VAPID') || !class_exists('Minishlink\\WebPush\\WebPush')) throw new RuntimeException('Bibliothèque Web Push incomplète.');
        $keys = Minishlink\WebPush\VAPID::createVapidKeys();
        Minishlink\WebPush\VAPID::validate(['subject' => 'mailto:' . $email] + $keys);
        $settings = [
            'Musicband_WEBPUSH_AUTOLOAD' => $vendor . '/autoload.php',
            'Musicband_VAPID_PUBLIC_KEY' => $keys['publicKey'],
            'Musicband_VAPID_PRIVATE_KEY' => $keys['privateKey'],
            'Musicband_VAPID_SUBJECT' => 'mailto:' . $email,
            'Musicband_NOTIFICATIONS_CRON_TOKEN' => bin2hex(random_bytes(32)),
            'Musicband_NOTIFICATIONS_CRON_URL' => $baseUrl . 'notifications_cron.php',
        ];
        communityPwaFile($directory . '/cron.php', $payload['launcher']);
        // Dernière écriture : une reprise ne régénère jamais les clés déjà publiées.
        communityPwaFile($directory . '/config.php', "<?php\n// Secrets PWA propres à cette instance, hors racine publique.\nreturn " . var_export($settings, true) . ";\n");
        return 'Configuration PWA créée : dépendances privées, clés VAPID et lanceur CRON. Autoriser les notifications sur chaque appareil ; planifier webpush/cron.php dans le panneau de l’hébergeur. Aucun envoi effectué.';
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
