<?php
/** Assistant web Community : contrôles, base neuve, compte administrateur et configuration privée.
 * Distribué dans public/ par scripts/prepare-community.py ; dépend du stockage automatique, de PDO/RBAC et des migrations.
 * Les écritures exigent HTTPS, session CSRF et connexion MySQL ; aucun code d'installation à recopier.
 * L'espace privé est créé automatiquement ; aucun site installé n'est réinitialisé.
 * Les erreurs sont conservées en session puis affichées après redirection GET, sans conserver les mots de passe.
 * Présentation autonome avec css/auth.css et css/common.css, sans charger auth.php avant installation.
 * Initialise les exemples fictifs en SQL avec l'administrateur, dans une transaction reprise sans doublons.
 * Configure Web Push hors racine publique avant verrouillage ; aucun envoi ni planification externe.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
require_once __DIR__ . '/community-storage.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/community-migrations.php';
require_once __DIR__ . '/community-pwa.php';

function communityEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/** Remplacement atomique dans le seul dossier privé, sans exposer de secret dans les diagnostics. */
function communitySave(string $name, string $bytes): void {
    $path = communityPrivateDirectory() . '/' . $name;
    $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $handle = @fopen($temporary, 'x');
    if (!$handle) throw new RuntimeException('Création du fichier privé impossible.');
    try {
        if (!chmod($temporary, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) throw new RuntimeException('Écriture privée incomplète.');
        fclose($handle); $handle = null;
        if (!rename($temporary, $path)) throw new RuntimeException('Finalisation du fichier privé impossible.');
    } finally { if (is_resource($handle)) fclose($handle); if (is_file($temporary)) @unlink($temporary); }
}
function MusicbandRead(string $name): array {
    $path = communityPrivateDirectory() . '/' . $name . '.json';
    return is_file($path) ? json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : [];
}
function communityJson(string $name, array $data): void { communitySave($name . '.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)); }

$message = ''; $finished = false; $lock = null; $failed = false; $redirectError = false;
$stage = 'Prérequis PHP';
try {
    communityPwaPrerequisites();
    foreach (['pdo_mysql','mbstring','openssl','fileinfo','session','json','zlib'] as $extension) {
        if (!extension_loaded($extension)) throw new RuntimeException('Activer l’extension PHP : ' . $extension);
    }
    $secure = (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    foreach ($_SERVER as $key => $value) if (preg_match('/^(?:REDIRECT_)*HTTPS$/D', $key) && in_array(strtolower((string)$value), ['on','1'], true)) $secure = true;
    if (!$secure) throw new RuntimeException('HTTPS doit être activé et reconnu par PHP chez l’hébergeur.');
    $stage = 'Création ou lecture de l’espace privé';
    $private = communityPrivateDirectory();
    if (is_file($private . '/installed.lock')) {
        http_response_code(403); $finished = true; $message = 'Installation terminée. L’assistant est verrouillé.';
    } else {
        $stage = 'Session de l’assistant';
        session_name('musicband_install');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
        if (!session_start()) throw new RuntimeException('Sessions PHP indisponibles.');
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SESSION['installation_error'])) {
            $message = (string)$_SESSION['installation_error'];
            unset($_SESSION['installation_error']);
            $failed = true;
        } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['result'] ?? '') === 'error') {
            $message = 'Le résultat de la tentative n’a pas été retrouvé dans la session. Vérifier les cookies du navigateur et le stockage des sessions PHP chez l’hébergeur.';
            $failed = true;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $stage = 'Vérification du formulaire et de la session';
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) throw new RuntimeException('Formulaire trop volumineux.');
            if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) throw new RuntimeException('Session expirée : recharger le formulaire.');
            $lock = fopen($private . '/.installation.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Installation déjà en cours.');
            if (is_file($private . '/installed.lock')) throw new RuntimeException('Installation déjà terminée.');
            // Le quota privé ne peut pas être remis à zéro par suppression des cookies.
            $attempts = MusicbandRead('installation-attempts');
            if (($attempts['until'] ?? 0) > time() && ($attempts['count'] ?? 0) >= 10) throw new RuntimeException('Trop de tentatives : patienter quinze minutes.');
            if (($attempts['until'] ?? 0) <= time()) $attempts = ['count'=>0,'until'=>time()+900];
            $attempts['count']++; communityJson('installation-attempts', $attempts);
            $settings = [];
            foreach (['DB_SERVER','DB_NAME','DB_USER','DB_PASSWORD','DB_PORT'] as $field) {
                $value = $_POST[$field] ?? ($field === 'DB_PORT' ? '3306' : '');
                if (!is_string($value) || strlen($value) > 1024 || preg_match('/[\x00-\x1f]/', $value)) throw new RuntimeException('Paramètre de connexion invalide.');
                $settings[$field] = $field === 'DB_PASSWORD' ? $value : trim($value);
            }
            $stage = 'Connexion MySQL';
            $db = MusicbandDatabase($settings);
            $stage = 'Compatibilité MySQL / MariaDB';
            $version = (string)$db->query('SELECT VERSION()')->fetchColumn();
            $maria = stripos($version, 'MariaDB') !== false;
            $numericVersion = $maria ? preg_replace('/^5\.5\.5-/', '', $version) : $version;
            if (!preg_match('/^(\d+\.\d+\.\d+)/', $numericVersion, $v) || version_compare($v[1], $maria ? '10.6.0' : '8.0.0', '<')) throw new RuntimeException('MySQL 8.0 ou MariaDB 10.6 minimum requis.');
            $engine = $db->query("SELECT SUPPORT FROM information_schema.engines WHERE ENGINE='InnoDB'")->fetchColumn();
            if (!in_array($engine, ['YES','DEFAULT'], true)) throw new RuntimeException('Le moteur InnoDB doit être disponible.');
            communityJson('installation-attempts', ['count'=>0,'until'=>time()+900]);
            if (($_POST['action'] ?? '') === 'check') {
                $message = 'Connexion et version compatibles. Aucun schéma ni compte créé. Les droits CREATE/INSERT/ALTER/DROP doivent aussi être accordés par l’hébergeur.';
            } elseif (($_POST['action'] ?? '') === 'install') {
                $stage = 'Vérification de la base et de la reprise';
                $state = MusicbandRead('installation');
                $fingerprint = hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR));
                if ($state) {
                    if (!hash_equals($state['database_fingerprint'], $fingerprint)) throw new RuntimeException('Destination modifiée après début d’installation : reprendre les mêmes paramètres, sans effacer la base.');
                } else {
                    if ((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() !== 0 || MusicbandRead('users') || is_file($private . '/community.local.php')) throw new RuntimeException('Base ou dossier privé déjà utilisés. Choisir une base vide et un dossier privé neuf ; aucune donnée écrasée.');
                    foreach (new DirectoryIterator($private) as $entry) {
                        if (!$entry->isDot() && !in_array($entry->getFilename(), ['install-key.txt','.htaccess','.installation.lock','installation-attempts.json'], true)) throw new RuntimeException('Dossier privé non vierge : conserver son contenu et choisir un dossier neuf.');
                    }
                    $stage = 'Paramètres de l’application et du premier administrateur';
                    $login = $_POST['login'] ?? ''; $password = $_POST['password'] ?? ''; $email = $_POST['email'] ?? ''; $url = $_POST['base_url'] ?? ''; $name = $_POST['app_name'] ?? '';
                    foreach ([$login,$password,$email,$url,$name] as $value) if (!is_string($value)) throw new RuntimeException('Formulaire invalide.');
                    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]{2,79}$/D', $login) || strlen($password) < 12 || strlen($password) > 72 || !hash_equals($password, (string)($_POST['confirmation'] ?? '')) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) throw new RuntimeException('Identifiant de 3 à 80 caractères, e-mail valide et mots de passe identiques de 12 à 72 octets requis.');
                    $parts = parse_url($url);
                    // Le tilde autorisé dans le chemin doit être échappé car il délimite le motif PHP.
                    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !preg_match('~^/(?:[A-Za-z0-9._\~-]+/)*$~D', $parts['path'] ?? '/') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $parts['path'] ?? '/')) throw new RuntimeException('URL HTTPS publique avec barre finale requise, sans identifiants ni paramètres.');
                    if (trim($name) === '' || strlen($name) > 100 || preg_match('/[\x00-\x1f]/', $name)) throw new RuntimeException('Nom d’application requis, limité à 100 octets.');
                    $state = ['database_fingerprint'=>$fingerprint,'login'=>$login,'account'=>['hash'=>password_hash($password, PASSWORD_BCRYPT),'admin'=>true,'advanced_notifications_allowed'=>true,'status'=>'active','email'=>$email,'revision'=>bin2hex(random_bytes(16))], 'config'=>['base_url'=>$url,'app_name'=>trim($name),'timezone'=>'Europe/Paris']];
                    communityJson('installation', $state);
                }
                $stage = 'Création ou reprise du schéma SQL';
                communityMigrate($db, true);
                communityVerifyRetiredTablesAbsent($db);
                // Données de référence et premier administrateur SQL : une seule transaction.
                $stage = 'Initialisation des droits et de l’administrateur';
                require_once __DIR__ . '/rbac.php';
                $db->beginTransaction();
                try {
                    $q = $db->prepare('INSERT IGNORE INTO Musicbandai_rbac_roles(role_key,label) VALUES (?,?)');
                    foreach (['lecture'=>'Lecture','gestionnaire'=>'Gestionnaire','admin'=>'Administrateur'] as $role=>$label) $q->execute([$role,$label]);
                    $q = $db->prepare('INSERT IGNORE INTO Musicbandai_rbac_permissions(role_key,permission_key,allowed) VALUES (?,?,?)');
                    foreach (['lecture','gestionnaire','admin'] as $role) foreach (MusicbandPermissionCatalog() as $permission=>$_) $q->execute([$role,$permission,(int)($role==='admin' || in_array($permission,['notifications.subscribe','ideas.send'],true))]);
                    $q = $db->prepare("INSERT IGNORE INTO Musicbandai_rbac_principals(kind,principal,role_key,updated_by) VALUES ('account',?,'admin',?)"); $q->execute([$state['login'],$state['login']]);
                    $stage = 'Création des données de démonstration';
                    communitySeedDemo($db, $state['login'], $state['config']['timezone']);
                    $db->exec('UPDATE setlist_runtime SET active=1 WHERE id=1');
                    $db->commit();
                } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
                $stage = 'Enregistrement privé et verrouillage de l’installation';
                $users = MusicbandRead('users');
                if ($users && $users !== [$state['login']=>$state['account']]) throw new RuntimeException('Comptes privés inattendus : aucune substitution.');
                communityJson('users', [$state['login']=>$state['account']]);
                communityJson('database_settings', $settings);
                communityJson('database_upgrade', ['rbac'=>['status'=>'complete'],'community'=>true]);
                $stage = 'Configuration privée PWA';
                $pwaMessage = communityPwaSetup($state['config']['base_url'], $state['account']['email']);
                communitySave('community.local.php', "<?php\n// Configuration privée créée par l’assistant.\nreturn " . var_export($state['config'], true) . ";\n");
                communitySave('installed.lock', gmdate('c') . "\n");
                // Le verrou durable interdit toute réinstallation ; conserver les journaux de reprise.
                $finished = true; $message = 'Installation terminée avec 5 morceaux, une setlist, un concert et un contact fictifs de démonstration. Connectez-vous avec le compte administrateur choisi. ' . $pwaMessage;
            } else throw new RuntimeException('Action inconnue.');
        }
    }
} catch (Throwable $e) {
    $failed = true;
    $detail = $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Vérifier les droits MySQL et fichiers. Conserver la base et l’espace privé pour reprendre, sans effacer le journal.';
    $driverCode = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
    $sqlState = $e instanceof PDOException && preg_match('/^[A-Z0-9]{5}$/D', (string)$e->getCode()) ? (string)$e->getCode() : '';
    $message = 'Étape : ' . $stage . '. ' . $detail . ($sqlState !== '' ? ' [SQLSTATE ' . $sqlState . ']' : '') . ($driverCode > 0 ? ' [MySQL ' . $driverCode . ']' : '');
    // Les codes et l'étape suffisent au diagnostic ; ni SQL, identifiant, mot de passe ni message PDO brut.
    error_log('musicband_install_failed stage=' . $stage . ' type=' . get_class($e) . ' sqlstate=' . $sqlState . ' driver=' . $driverCode);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['installation_error'] = $message;
        $redirectError = true;
    } else http_response_code(400);
} finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
// La redirection évite le remplacement du résultat POST par une page d'erreur 400 de l'hébergeur.
if ($redirectError) {
    if (session_write_close()) { header('Location: install.php?result=error#installation-message', true, 303); exit; }
    http_response_code(400);
}
?><!doctype html>
<!-- Assistant indépendant de auth.php : aucun compte applicatif n'existe avant finalisation. -->
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installer MusicBand Toolbox</title><link rel="stylesheet" href="css/auth.css?v=2026.10.22.22-community"><link rel="stylesheet" href="css/common.css?v=2026.10.22.22-community"></head><body class="community-install">
<header class="installation-header"><div><p class="installation-brand">MusicBand Toolbox</p><h1>Installation</h1></div></header><main>
<?php if ($message !== ''): ?><section id="installation-message" class="installation-notice<?= $failed ? ' installation-notice-error' : '' ?>" role="<?= $failed ? 'alert' : 'status' ?>" tabindex="-1"><h2><?= $failed ? 'Installation interrompue' : 'Résultat' ?></h2><p><?= communityEscape($message) ?></p><?php if ($failed): ?><p>Aucune réussite n’est confirmée. Les mots de passe doivent être saisis à nouveau pour reprendre.</p><?php endif; ?></section><?php endif; ?>
<?php if ($finished): ?><p><a href="login.php">Connexion</a></p>
<?php elseif (isset($_SESSION['csrf'])): ?>
<p>Préparer une base vide et sa sauvegarde éventuelle. En cas d’interruption, reprendre les mêmes paramètres : les informations du premier compte restent celles de la première tentative.</p>
<p>La première installation fournit 5 morceaux, une setlist, un concert et un contact de démonstration, tous fictifs. Aucun service externe n’est contacté pour les créer.</p>
<form class="installation-form" method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= communityEscape($_SESSION['csrf']) ?>">
<fieldset><legend>Base MySQL / MariaDB</legend>
<?php foreach (['DB_SERVER'=>'Serveur','DB_PORT'=>'Port','DB_NAME'=>'Nom de la base','DB_USER'=>'Utilisateur','DB_PASSWORD'=>'Mot de passe de la base'] as $field=>$label): ?>
<label><?= $label ?><input name="<?= $field ?>" type="<?= $field==='DB_PASSWORD'?'password':'text' ?>" value="<?= $field==='DB_PORT'?'3306':'' ?>" required maxlength="1024"></label>
<?php endforeach; ?></fieldset>
<button class="installation-check" name="action" value="check" formnovalidate>Vérifier la connexion uniquement</button>
<fieldset><legend>Application et administrateur</legend><label>Nom de l’application <input name="app_name" value="MusicBand Toolbox" maxlength="100"></label><label>URL publique HTTPS, avec barre finale <input type="url" name="base_url" placeholder="https://exemple.org/toolbox/"></label><label>Identifiant administrateur <input name="login" maxlength="80" autocomplete="username"></label><label>E-mail <input type="email" name="email" maxlength="254"></label><label>Mot de passe, de 12 à 72 octets <input type="password" name="password" autocomplete="new-password"></label><label>Confirmation <input type="password" name="confirmation" autocomplete="new-password"></label></fieldset>
<button name="action" value="install">Installer ou reprendre</button></form>
<?php endif; ?></main><footer class="installation-footer"><p>Version installateur : 2026.10.22.22-community</p></footer></body></html>
