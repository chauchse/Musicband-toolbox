<?php
/* Musicband SESSION AUTH: comptes privés, sessions et formulaires CSRF. */
/* Socle partagé : chargé par les routes PHP avant toute sortie HTTP.
 * Dépend de auth_config.php, rbac.php, setlist_storage.php et audit_trail.php.
 * Initialise HTTPS, en-têtes et session ; fournit stockage JSON privé, contrôles CSRF et rendu commun.
 * Le catalogue et les liens des modules exclus sont filtrés selon la politique Community d'auth_config.php.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/auth_config.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/setlist_storage.php';
function MusicbandFail(string $message, int $status = 503): void {
    http_response_code($status); header('Content-Type: text/plain; charset=utf-8'); exit($message);
}
$secure = (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
foreach ($_SERVER as $key => $value) {
    if (preg_match('/^(?:REDIRECT_)*HTTPS$/D', $key) && in_array(strtolower((string)$value), ['on', '1'], true)) $secure = true;
}
// Les en-têtes HTTP_X_FORWARDED_PROTO ne constituent pas une preuve TLS.
if (!$secure) MusicbandFail('HTTPS non reconnu par PHP. Vérifier la configuration SSL OVH.', 403);
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');
header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0'); header('Pragma: no-cache'); header('Expires: 0');
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
function MusicbandBase(): string {
    // Source unique : chemin de l'URL publique configurée, jamais SCRIPT_NAME
    // ni le chemin physique OVH exposé par certaines réécritures FastCGI.
    return defined('Musicband_BASE_PATH') ? Musicband_BASE_PATH : './';
}
/** Résout/crée le dossier privé hors racine publique, en 0700 ; refuse une configuration exposée ou non inscriptible. */
function MusicbandPrivate(): string {
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if (!$root) MusicbandFail('Racine web non reconnue.');
    $configured = getenv('SOUND_PRIVATE_DIRECTORY');
    $path = $configured ?: dirname($root) . '/.Musicband-' . substr(hash('sha256', __DIR__), 0, 16);
    if (!is_dir($path) && !@mkdir($path, 0700, true)) MusicbandFail('Création du dossier privé impossible. Configurer SOUND_PRIVATE_DIRECTORY chez l’hébergeur.');
    $private = realpath($path);
    if (!$private || $private === $root || str_starts_with($private . '/', $root . '/') || !is_writable($private) || (fileperms($private) & 0077) !== 0) MusicbandFail('Dossier privé hors racine web et permissions 0700 requis.');
    return $private;
}
/** Lit un document JSON privé ; absence = tableau vide, contenu invalide = exception. Le nom provient des services internes. */
function MusicbandRead(string $name): array {
    $file = MusicbandPrivate() . '/' . $name . '.json';
    if (!is_file($file)) return [];
    $data = @file_get_contents($file);
    if ($data === false) throw new RuntimeException('Lecture du stockage privé impossible.');
    $value = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException('Stockage privé invalide.');
    return $value;
}
/** Remplace un document par renommage du temporaire privé. Pour un cycle lecture/modification/écriture partagé, utiliser MusicbandLocked. */
function MusicbandWrite(string $name, array $value): void {
    $file = MusicbandPrivate() . '/' . $name . '.json'; $temp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
    if (@file_put_contents($temp, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX) === false || !@chmod($temp, 0600) || !@rename($temp, $file)) {
        @unlink($temp); throw new RuntimeException('Écriture du stockage privé impossible.');
    }
    if(function_exists('MusicbandAuditEvent')&&in_array($name,['users','home_settings','mail_settings','database_settings','account_links'],true))MusicbandAuditEvent('configuration','storage.changed','success',['document'=>$name,'count'=>count($value)]);
}
/** Sérialise une opération sur le stockage des comptes ; libère le verrou même si le callback lève une exception. Ne pas imbriquer les appels. */
function MusicbandLocked(callable $action) {
    $lock = @fopen(MusicbandPrivate() . '/.accounts.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Gestion des comptes occupée.');
    try { return $action(); } finally { flock($lock, LOCK_UN); fclose($lock); }
}
// Durée absolue depuis la connexion ; aucun délai glissant lié à l'activité.
function MusicbandSessionSeconds(): int {
    static $seconds=null;
    if ($seconds===null) {
        $days=MusicbandRead('home_settings')['session_days']??null;
        if ($days!==null && (!is_int($days) || $days<1 || $days>365)) throw new RuntimeException('Durée de connexion invalide.');
        $seconds=$days===null?Musicband_SESSION_SECONDS:$days*86400;
    }
    return $seconds;
}
function MusicbandSessionSettingsSave(): string {
    if (!MusicbandRequireLogin()['admin']) throw new RuntimeException('Accès administrateur requis.');
    $settings=MusicbandRead('home_settings');$old=$settings['session_days']??null;
    if (!is_string($_POST['session_version']??null) || !hash_equals(hash('sha256',json_encode($old,JSON_THROW_ON_ERROR)),$_POST['session_version'])) throw new RuntimeException('Durée modifiée ailleurs : recharger Configuration.');
    $value=$_POST['session_days']??null;
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,2}$/D',$value) || (int)$value>365) throw new RuntimeException('Durée requise : de 1 à 365 jours entiers.');
    $settings['session_days']=(int)$value;MusicbandWrite('home_settings',$settings);
    return 'Durée de connexion enregistrée. Appliquée dès la prochaine requête, depuis la date de connexion de chaque session.';
}
function MusicbandSessionSettingsForm(string $hidden): string {
    $days=MusicbandRead('home_settings')['session_days']??null;
    return '<section><h2>Authentification</h2><p>Durée maximale depuis la connexion, sans prolongation automatique. Une réduction peut déconnecter immédiatement les sessions anciennes. Sans réglage enregistré, la limite historique de 8 heures reste active. L’hébergement ou le navigateur peut interrompre une session plus tôt.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="session_settings"><input type="hidden" name="session_version" value="'.hash('sha256',json_encode($days,JSON_THROW_ON_ERROR)).'"><label>Durée de connexion (jours)<input type="number" name="session_days" min="1" max="365" step="1" required value="'.($days===null?'':(string)$days).'" placeholder="1 à 365"></label><button>Enregistrer la durée de connexion</button></form></section>';
}
$sessionSeconds=MusicbandSessionSeconds();
ini_set('session.gc_maxlifetime',(string)$sessionSeconds);
$cookie = 'Musicband_' . substr(hash('sha256', __DIR__), 0, 12);
session_name($cookie); ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
session_set_cookie_params(['lifetime' => $sessionSeconds, 'path' => MusicbandBase(), 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
if (!session_start()) MusicbandFail('Session indisponible.');
if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['login.php', 'admin.php', 'logout.php', 'forgot.php', 'activate.php'], true) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > (basename($_SERVER['SCRIPT_NAME']??'')==='admin.php' && in_array($_POST['action']??'',['field_layout','field_editor','documents_mail_settings','documents_google_settings','calendar_mail_template','wordpress_settings'],true)?(($_POST['action']??'')==='field_editor'?1048576:131072):16384)) MusicbandFail('Formulaire trop volumineux.', 413);
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
require_once __DIR__.'/audit_trail.php';
MusicbandAuditBegin();
/** Vérifie expiration absolue et révision du compte avant de résoudre le rôle ; une révision changée révoque les anciennes sessions. */
/** Les comptes historiques sans statut restent actifs ; un compte invité ou désactivé ne peut ouvrir de session. */
function MusicbandAccountIsActive(array $account): bool {
    return ($account['status'] ?? 'active') === 'active' && empty($account['disabled']);
}
function MusicbandUser(): ?array {
    if (!isset($_SESSION['login'], $_SESSION['revision'], $_SESSION['started']) || !is_int($_SESSION['started']) || time() - $_SESSION['started'] >= MusicbandSessionSeconds()) return null;
    $users = MusicbandRead('users'); $user = $users[$_SESSION['login']] ?? null;
    if (!$user || !MusicbandAccountIsActive($user) || !hash_equals($user['revision'], $_SESSION['revision'])) return null;
    MusicbandSessionCookie();
    $role=MusicbandRbacRole($_SESSION['login'],$user);
    if(function_exists('MusicbandAuditIdentity'))MusicbandAuditIdentity($_SESSION['login']);
    return ['login' => $_SESSION['login'], 'pseudo' => (string)($user['pseudo'] ?? ''), 'role'=>$role, 'admin' => $role==='admin'];
}
/**
 * Retourne le nom d'affichage d'un utilisateur : pseudo si défini, sinon identifiant de connexion.
 *
 * @param array|string|null $userOrLogin Compte utilisateur ou identifiant login.
 */
function MusicbandUserDisplayName($userOrLogin): string {
    if (is_array($userOrLogin)) {
        $pseudo = trim((string)($userOrLogin['pseudo'] ?? ''));
        if ($pseudo !== '') return $pseudo;
        return (string)($userOrLogin['login'] ?? '');
    }
    if (is_string($userOrLogin)) {
        $login = trim($userOrLogin);
        if ($login === '') return '';
        try {
            $users = MusicbandRead('users');
            $pseudo = trim((string)($users[$login]['pseudo'] ?? ''));
            if ($pseudo !== '') return $pseudo;
        } catch (Throwable $e) {}
        return $login;
    }
    return '';
}
/** Retourne le compte courant ; sinon termine par JSON 401 pour une API ou redirection 303 pour une page. */
function MusicbandRequireLogin(bool $api = false): array {
    try { $user = MusicbandUser(); } catch (Throwable $e) { MusicbandFail('Comptes privés inaccessibles.'); }
    if ($user) return $user;
    if(isset($_SESSION['login'])&&empty($_SESSION['audit_expired'])){MusicbandAuditEvent('auth','session.expired_or_revoked','rejected',[],(string)$_SESSION['login']);$_SESSION['audit_expired']=true;}
    if ($api) { http_response_code(401); header('Content-Type: application/json'); exit('{"error":"Session expirée : rechargez la page pour vous reconnecter."}'); }
    header('Location: ' . MusicbandBase() . 'login.php', true, 303); exit;
}
/** Exige POST et égalité du jeton de session ; les contrôleurs traitent l’exception comme un formulaire expiré. */
function MusicbandCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) throw new RuntimeException('Formulaire expiré : recharger la page.');
}
function MusicbandDateZone(): DateTimeZone {
    $zone=defined('Musicband_DISPLAY_TIMEZONE')?Musicband_DISPLAY_TIMEZONE:'Europe/Paris';if(function_exists('businessListSettings'))$zone=businessListSettings()['timezone'];return new DateTimeZone($zone);
}
/** Formate seulement les dates ISO reconnues ; conserve une valeur source ambiguë ou invalide au lieu de la réinterpréter. */
function MusicbandDisplayDate(string $value,bool $withTime=false,bool $utc=false,bool $long=false): string {
    if($value==='')return $value;
    if(!preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D',$value))return $value;
    try{$date=new DateTimeImmutable($value,$utc?new DateTimeZone('UTC'):MusicbandDateZone());$errors=DateTimeImmutable::getLastErrors();if($errors&&($errors['warning_count']||$errors['error_count']))return $value;$date=$date->setTimezone(MusicbandDateZone());
        if(!$long)return $date->format($withTime?'d/m/Y H:i':'d/m/Y');
        $months=[1=>'janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];return $date->format('j').' '.$months[(int)$date->format('n')].' '.$date->format('Y').($withTime?' à '.$date->format('H:i'):'');
    }catch(Throwable $e){return $value;}
}
function MusicbandDisplayGeneratedLabel(string $value): string {
    return preg_replace_callback('/\d{4}-\d{2}-\d{2}[ T]\d{2}(?::\d{2}(?::\d{2}(?:\.\d{1,6})?)?|h\d{2}) UTC/',fn($m)=>MusicbandDisplayDate(str_replace('h',':',substr($m[0],0,-4)),true,true),$value)??$value;
}
function MusicbandEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
// Catalogue et ordre communs à la navigation, à l’accueil et à Configuration.
function MusicbandNavigationCatalog(): array {
    static $catalog=null;
    if($catalog===null){
        try{
            $catalog=json_decode(base64_decode('eyJob21lIjp7ImxhYmVsIjoiQWNjdWVpbCIsInBhdGgiOiIiLCJtb2R1bGUiOmZhbHNlLCJob21lX2RlZmF1bHQiOmZhbHNlLCJpY29uIjoiaG9tZSJ9LCJzZXRsaXN0Ijp7ImxhYmVsIjoiU2V0bGlzdCIsInBhdGgiOiJzZXRsaXN0LyIsIm1vZHVsZSI6dHJ1ZSwiaG9tZV9kZWZhdWx0Ijp0cnVlLCJpY29uIjoibXVzaWMifSwic29uZ3MiOnsibGFiZWwiOiJNb3JjZWF1eCIsInBhdGgiOiJzb25ncy5waHAiLCJtb2R1bGUiOnRydWUsImhvbWVfZGVmYXVsdCI6dHJ1ZSwiaWNvbiI6Im11c2ljIn0sImNvbnRhY3RzIjp7ImxhYmVsIjoiQ29udGFjdHMiLCJwYXRoIjoiY29udGFjdHMucGhwIiwibW9kdWxlIjp0cnVlLCJob21lX2RlZmF1bHQiOnRydWUsImljb24iOiJjb250YWN0cyJ9LCJjb25jZXJ0cyI6eyJsYWJlbCI6IkNvbmNlcnRzIiwicGF0aCI6ImNvbmNlcnRzLnBocCIsIm1vZHVsZSI6dHJ1ZSwiaG9tZV9kZWZhdWx0Ijp0cnVlLCJpY29uIjoiY2FsZW5kYXIifSwiYWdlbmRhIjp7ImxhYmVsIjoiQWdlbmRhIiwicGF0aCI6ImNhbGVuZGFyLnBocCIsIm1vZHVsZSI6dHJ1ZSwiaG9tZV9kZWZhdWx0Ijp0cnVlLCJpY29uIjoiY2FsZW5kYXIifSwidGFza3MiOnsibGFiZWwiOiJHZXN0aW9uIGRlcyB0XHUwMGUyY2hlcyIsInBhdGgiOiJ0YXNrcy5waHAiLCJtb2R1bGUiOnRydWUsImhvbWVfZGVmYXVsdCI6dHJ1ZSwiaWNvbiI6InRhc2tzIn0sImFkbWluIjp7ImxhYmVsIjoiQ29uZmlndXJhdGlvbiIsInBhdGgiOiJhZG1pbi5waHAiLCJtb2R1bGUiOmZhbHNlLCJob21lX2RlZmF1bHQiOmZhbHNlLCJpY29uIjoic2V0dGluZ3MifSwiYXVkaXQiOnsibGFiZWwiOiJBdWRpdCB0cmFpbCIsInBhdGgiOiJhdWRpdC5waHAiLCJtb2R1bGUiOmZhbHNlLCJob21lX2RlZmF1bHQiOmZhbHNlLCJpY29uIjoiZG9jdW1lbnQifSwiaWRlYXMiOnsibGFiZWwiOiJCb1x1MDBlZXRlIFx1MDBlMCBpZFx1MDBlOWVzIiwicGF0aCI6ImlkZWFzLnBocCIsIm1vZHVsZSI6ZmFsc2UsImhvbWVfZGVmYXVsdCI6ZmFsc2UsImljb24iOiJzdGFyIn0sInBhc3N3b3JkIjp7ImxhYmVsIjoiUHJcdTAwZTlmXHUwMGU5cmVuY2VzIiwicGF0aCI6ImFjdGl2YXRlLnBocD9jaGFuZ2U9MSIsIm1vZHVsZSI6ZmFsc2UsImhvbWVfZGVmYXVsdCI6ZmFsc2UsImljb24iOiJzZXR0aW5ncyJ9LCJsb2dvdXQiOnsibGFiZWwiOiJEXHUwMGU5Y29ubmV4aW9uIiwicGF0aCI6ImxvZ291dC5waHAiLCJtb2R1bGUiOmZhbHNlLCJob21lX2RlZmF1bHQiOmZhbHNlLCJpY29uIjoibG9nb3V0In19',true),true,16,JSON_THROW_ON_ERROR);
        }catch(Throwable $e){
            $catalog=[
                'home'=>['label'=>'Accueil','path'=>'','module'=>false,'home_default'=>false,'icon'=>'home'],
                'setlist'=>['label'=>'Setlist','path'=>'setlist/','module'=>true,'home_default'=>true,'icon'=>'music'],
                'songs'=>['label'=>'Morceaux','path'=>'songs.php','module'=>true,'home_default'=>true,'icon'=>'music'],
                'sound'=>['label'=>'Sound · XR18','path'=>'sound/','module'=>true,'home_default'=>true,'icon'=>'sliders'],
                'contacts'=>['label'=>'Contacts','path'=>'contacts.php','module'=>true,'home_default'=>true,'icon'=>'contacts'],
                'concerts'=>['label'=>'Concerts','path'=>'concerts.php','module'=>true,'home_default'=>true,'icon'=>'calendar'],
                'agenda'=>['label'=>'Agenda','path'=>'calendar.php','module'=>true,'home_default'=>true,'icon'=>'calendar'],
                'devis_factures'=>['label'=>'Devis / Factures','path'=>'devis_factures.php','module'=>true,'home_default'=>true,'icon'=>'document'],
                'expenses'=>['label'=>'Dépenses','path'=>'expenses.php','module'=>true,'home_default'=>true,'icon'=>'document'],
                'accounting'=>['label'=>'Comptabilité','path'=>'accounting.php','module'=>true,'home_default'=>true,'icon'=>'document'],
                'tasks'=>['label'=>'Gestion des tâches','path'=>'tasks.php','module'=>true,'home_default'=>true,'icon'=>'tasks'],
                'admin'=>['label'=>'Configuration','path'=>'admin.php','module'=>false,'home_default'=>false,'icon'=>'settings'],
                'audit'=>['label'=>'Audit trail','path'=>'audit.php','module'=>false,'home_default'=>false,'icon'=>'document'],
                'ideas'=>['label'=>'Boîte à idées','path'=>'ideas.php','module'=>false,'home_default'=>false,'icon'=>'star'],
                'password'=>['label'=>'Préférences','path'=>'activate.php?change=1','module'=>false,'home_default'=>false,'icon'=>'settings'],
                'logout'=>['label'=>'Déconnexion','path'=>'logout.php','module'=>false,'home_default'=>false,'icon'=>'logout'],
            ];
        }
    }
    return array_filter($catalog, static fn(string $module): bool => MusicbandModuleEnabled($module), ARRAY_FILTER_USE_KEY);
}
function MusicbandModuleDisplayNames(): array {
    $catalog = MusicbandNavigationCatalog();
    $saved = MusicbandRead('home_settings')['module_labels'] ?? (MusicbandRead('home_settings')['display_names'] ?? []);
    $names = [];
    foreach ($catalog as $key => $entry) {
        $custom = $saved[$key] ?? null;
        if (is_string($custom) && trim($custom) !== '') {
            $names[$key] = trim($custom);
        } else {
            $names[$key] = $entry['label'];
        }
    }
    return $names;
}
/** Conserve les préférences des modules exclus lorsqu'un formulaire enregistre les autres. */
function MusicbandPreserveCommunitySettings(array $settings, array $previous): array {
    if (!defined('MUSICBAND_COMMUNITY_DISABLED_MODULES')) return $settings;
    foreach (MUSICBAND_COMMUNITY_DISABLED_MODULES as $module) {
        foreach (['module_home','module_labels','module_icons','module_descriptions'] as $key) {
            if (is_array($previous[$key] ?? null) && array_key_exists($module, $previous[$key])) $settings[$key][$module] = $previous[$key][$module];
        }
    }
    // Réinsérer les modules dans leur ancien ordre pour conserver les positions relatives.
    foreach ($previous['module_order'] ?? [] as $position => $module) {
        if (is_string($module) && !MusicbandModuleEnabled($module) && is_array($settings['module_order'] ?? null) && !in_array($module, $settings['module_order'], true)) array_splice($settings['module_order'], (int)$position, 0, [$module]);
    }
    return $settings;
}
function MusicbandNavigationModules(): array { return MusicbandModuleDisplayNames(); }
function MusicbandHomeModules(): array {
    $catalog=MusicbandNavigationCatalog();$names=MusicbandModuleDisplayNames();$modules=[];
    foreach(MusicbandHomeOrder() as $key)if(!empty($catalog[$key]['module']))$modules[$key]=$names[$key];
    return $modules;
}
function MusicbandHomeOrder(): array {
    $available=MusicbandNavigationCatalog();$order=[];
    foreach(MusicbandRead('home_settings')['module_order']??[] as $key)if(is_string($key)&&isset($available[$key])&&!in_array($key,$order,true))$order[]=$key;
    foreach(array_keys($available) as $key)if(!in_array($key,$order,true))$order[]=$key;
    return $order;
}
function MusicbandHomeVisibility(): array {
    $saved=MusicbandRead('home_settings')['module_home']??[];$visible=[];
    foreach(MusicbandNavigationCatalog() as $key=>$entry)$visible[$key]=($saved[$key]??$entry['home_default'])===true;
    return $visible;
}
function MusicbandHomeOrderVersion(array $order): string {
    return hash('sha256',json_encode([$order,MusicbandHomeVisibility(),MusicbandNavigationCatalog()],JSON_THROW_ON_ERROR));
}
function MusicbandModuleSettingsVersion(array $order): string {
    return hash('sha256',json_encode([$order,MusicbandHomeVisibility(),MusicbandMenuIcons(),MusicbandModuleDisplayNames(),MusicbandHomeDescriptions(),MusicbandNavigationCatalog()],JSON_THROW_ON_ERROR));
}
function MusicbandHomeOrderSave(): string {
    $order=MusicbandHomeOrder();
    if(!is_string($_POST['order_version']??null)||!hash_equals(MusicbandHomeOrderVersion($order),$_POST['order_version']))throw new RuntimeException('Ordre ou affichage modifié ailleurs : recharger Configuration.');
    $modules=MusicbandNavigationCatalog();$positions=$_POST['positions']??null;$home=$_POST['module_home']??null;
    foreach([$positions,$home] as $values)if(!is_array($values)||count($values)!==count($modules)||array_diff(array_keys($values),array_keys($modules)))throw new RuntimeException('Renseigner toutes les entrées du menu.');
    $ranked=[];$visible=[];
    foreach($modules as $key=>$_){
        $position=$positions[$key]??null;
        if(!is_string($position)||!in_array($position,array_map('strval',range(1,count($modules))),true)||isset($ranked[(int)$position]))throw new RuntimeException('Choisir une position différente pour chaque entrée.');
        if(!is_string($home[$key])||!in_array($home[$key],['0','1'],true))throw new RuntimeException('Affichage accueil invalide.');
        $ranked[(int)$position]=$key;$visible[$key]=$home[$key]==='1';
    }
    ksort($ranked);$settings=MusicbandRead('home_settings');$previous=$settings;$settings['module_order']=array_values($ranked);$settings['module_home']=$visible;MusicbandWrite('home_settings',MusicbandPreserveCommunitySettings($settings,$previous));
    return 'Ordre commun et affichage accueil enregistrés. Recharger les autres pages pour actualiser leur menu.';
}
function MusicbandModuleSettingsSave(): string {
    $order = MusicbandHomeOrder();
    $version = $_POST['order_version'] ?? '';
    if (!is_string($version) || (!hash_equals(MusicbandModuleSettingsVersion($order), $version) && !hash_equals(MusicbandHomeOrderVersion($order), $version))) {
        throw new RuntimeException('La configuration des modules a été modifiée ailleurs : recharger avant d’enregistrer.');
    }

    $catalog = MusicbandNavigationCatalog();
    $iconCatalog = MusicbandIconCatalog();
    $positions = $_POST['positions'] ?? null;
    $home = $_POST['module_home'] ?? null;
    $displayNames = $_POST['display_names'] ?? null;
    $icons = $_POST['icons'] ?? null;
    $descriptions = $_POST['module_descriptions'] ?? null;

    foreach ([$positions, $home, $displayNames, $icons] as $field) {
        if (!is_array($field) || count($field) !== count($catalog) || array_diff(array_keys($field), array_keys($catalog))) {
            throw new RuntimeException('Renseigner tous les paramètres pour chaque entrée.');
        }
    }

    $ranked = [];
    $visible = [];
    $validatedNames = [];
    $validatedIcons = [];
    $validatedDesc = [];

    foreach ($catalog as $key => $_) {
        $pos = $positions[$key] ?? null;
        if (!is_string($pos) || !in_array($pos, array_map('strval', range(1, count($catalog))), true) || isset($ranked[(int)$pos])) {
            throw new RuntimeException('Choisir une position unique pour chaque entrée.');
        }
        $ranked[(int)$pos] = $key;

        if (!is_string($home[$key]) || !in_array($home[$key], ['0', '1'], true)) {
            throw new RuntimeException('Visibilité accueil invalide.');
        }
        $visible[$key] = $home[$key] === '1';

        $name = trim((string)($displayNames[$key] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Le nom d’affichage ne peut pas être vide.');
        }
        if (strlen($name) > 60 || preg_match('/[\x00-\x1f\x7f<>{}]/', $name)) {
            throw new RuntimeException('Nom d’affichage invalide (60 caractères max, sans balises HTML).');
        }
        $validatedNames[$key] = $name;

        $icon = (string)($icons[$key] ?? '');
        if (!isset($iconCatalog['icons'][$icon])) {
            throw new RuntimeException('Icône inconnue sélectionnée.');
        }
        $validatedIcons[$key] = $icon;

        if (is_array($descriptions) && isset($descriptions[$key])) {
            $descText = trim((string)$descriptions[$key]);
            if (strlen($descText) > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $descText)) {
                throw new RuntimeException('Texte de tuile invalide : maximum 1000 caractères.');
            }
            $validatedDesc[$key] = $descText;
        }
    }

    ksort($ranked);
    $settings = MusicbandRead('home_settings');
    $previous = $settings;
    $settings['module_order'] = array_values($ranked);
    $settings['module_home'] = $visible;
    $settings['module_labels'] = $validatedNames;
    $settings['module_icons'] = $validatedIcons;
    if (!empty($validatedDesc)) {
        $settings['module_descriptions'] = array_merge($settings['module_descriptions'] ?? [], $validatedDesc);
    }
    MusicbandWrite('home_settings', MusicbandPreserveCommunitySettings($settings, $previous));

    return 'Configuration centralisée des modules enregistrée avec succès. Recharger les pages pour actualiser le menu et l’accueil.';
}
function MusicbandModuleSettingsReset(): string {
    if (($_POST['confirm_reset'] ?? '') !== 'yes') {
        throw new RuntimeException('Confirmation requise pour rétablir les modules par défaut.');
    }
    $settings = MusicbandRead('home_settings');
    unset($settings['module_order'], $settings['module_home'], $settings['module_labels'], $settings['module_icons'], $settings['module_descriptions']);
    MusicbandWrite('home_settings', $settings);

    if (function_exists('MusicbandAuditEvent')) {
        MusicbandAuditEvent('configuration', 'modules.settings.reset', 'success');
    }
    return 'Paramètres des modules réinitialisés avec succès (noms, icônes, ordre et affichage d’origine rétablis).';
}
function MusicbandModuleSettingsForm(string $hidden): string {
    $order = MusicbandHomeOrder();
    $catalog = MusicbandNavigationCatalog();
    $iconCatalog = MusicbandIconCatalog();
    $icons = MusicbandMenuIcons();
    $displayNames = MusicbandModuleDisplayNames();
    $visible = MusicbandHomeVisibility();
    $texts = MusicbandHomeDescriptions();
    $version = MusicbandModuleSettingsVersion($order);

    $rows = '';
    foreach ($order as $index => $key) {
        $entry = $catalog[$key];
        $currentIcon = $icons[$key] ?? $entry['icon'];
        $iconSvgPath = $iconCatalog['icons'][$currentIcon]['path'] ?? $iconCatalog['icons']['document']['path'];

        $posOptions = '';
        for ($pos = 1; $pos <= count($order); $pos++) {
            $posOptions .= '<option value="' . $pos . '"' . ($pos === $index + 1 ? ' selected' : '') . '>' . $pos . '</option>';
        }

        $iconOptions = '';
        foreach ($iconCatalog['icons'] as $iKey => $iData) {
            $iconOptions .= '<option value="' . MusicbandEscape($iKey) . '"' . ($iKey === $currentIcon ? ' selected' : '') . '>' . MusicbandEscape($iData['label']) . '</option>';
        }

        $accessLabel = 'Public';
        if ($key === 'admin') {
            $accessLabel = 'Admin';
        } elseif ($key === 'audit') {
            $accessLabel = 'RBAC · audit.read';
        } elseif ($key === 'agenda') {
            $accessLabel = 'RBAC · calendar.read';
        } elseif (in_array($key,['expenses','accounting'],true)) {
            $accessLabel = 'RBAC · '.$key.'.read';
        } elseif ($key === 'tasks') {
            $accessLabel = 'RBAC · tasks.read';
        } elseif (!empty($entry['module'])) {
            $accessLabel = 'Actif · Standard';
        }

        $rows .= '<tr data-navigation-item data-module="' . MusicbandEscape($key) . '" class="module-config-row">'
            . '<td data-order-cell class="module-order-cell">'
            . '<label class="sr-only">Position ' . MusicbandEscape($entry['label']) . '<select name="positions[' . MusicbandEscape($key) . ']">' . $posOptions . '</select></label>'
            . '</td>'
            . '<td class="module-icon-cell">'
            . '<div class="module-icon-selector">'
            . '<span class="Musicband-icon module-icon-preview" data-preview-icon="' . MusicbandEscape($key) . '"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="' . MusicbandEscape($iconSvgPath) . '"/></svg></span>'
            . '<select name="icons[' . MusicbandEscape($key) . ']" data-icon-select="' . MusicbandEscape($key) . '" class="module-icon-select" aria-label="Icône : ' . MusicbandEscape($entry['label']) . '">' . $iconOptions . '</select>'
            . '</div>'
            . '</td>'
            . '<td class="module-key-cell"><code>' . MusicbandEscape($key) . '</code></td>'
            . '<td class="module-name-cell">'
            . '<span data-drag-label hidden>' . MusicbandEscape($displayNames[$key]) . '</span>'
            . '<input type="text" name="display_names[' . MusicbandEscape($key) . ']" value="' . MusicbandEscape($displayNames[$key]) . '" required maxlength="60" class="Musicband-input module-display-name-input" aria-label="Nom d’affichage : ' . MusicbandEscape($key) . '">'
            . '<input type="text" name="module_descriptions[' . MusicbandEscape($key) . ']" value="' . MusicbandEscape($texts[$key] ?? '') . '" maxlength="1000" placeholder="Sous-titre d’accueil (facultatif)" class="Musicband-input module-desc-input" aria-label="Sous-titre accueil : ' . MusicbandEscape($key) . '" style="margin-top: 4px; font-size: 0.85rem; color: #a0a0a0;">'
            . '</td>'
            . '<td class="module-home-cell" style="text-align: center;">'
            . '<input type="hidden" name="module_home[' . MusicbandEscape($key) . ']" value="0">'
            . '<label class="Musicband-touch-target" style="justify-content: center; min-height: 38px; margin: 0;">'
            . '<input type="checkbox" class="Musicband-checkbox" name="module_home[' . MusicbandEscape($key) . ']" value="1"' . ($visible[$key] ? ' checked' : '') . ' aria-label="Afficher sur l’accueil : ' . MusicbandEscape($key) . '">'
            . '</label>'
            . '</td>'
            . '<td class="module-access-cell"><span class="module-access-badge">' . MusicbandEscape($accessLabel) . '</span></td>'
            . '</tr>';
    }

    $html = '<section class="module-config-section"><h2>Modules et navigation</h2>'
        . '<p>Gestion centralisée des entrées du menu et de l’accueil. Déplacez les lignes avec leur poignée ↕ ou les boutons ↑ / ↓ pour ajuster l’ordre d’affichage. Personnalisez le nom d’affichage, l’icône et la présence sur la page d’accueil. Décocher « Accueil » masque la tuile sans restreindre l’accès du module.</p>'
        . '<form method="post" data-navigation-order class="module-config-form">'
        . $hidden
        . '<input type="hidden" name="action" value="module_settings">'
        . '<input type="hidden" name="config_tab" value="general">'
        . '<input type="hidden" name="order_version" value="' . MusicbandEscape($version) . '">'
        . '<div class="module-config-table-wrapper rbac-roles-table-wrapper">'
        . '<table class="module-config-table rbac-roles-table">'
        . '<thead><tr>'
        . '<th scope="col" style="width: 110px;">Ordre</th>'
        . '<th scope="col" style="width: 170px;">Icône</th>'
        . '<th scope="col" style="width: 120px;">Identifiant</th>'
        . '<th scope="col">Nom d’affichage &amp; sous-titre accueil</th>'
        . '<th scope="col" style="width: 90px; text-align: center;">Accueil</th>'
        . '<th scope="col" style="width: 130px;">Disponibilité</th>'
        . '</tr></thead>'
        . '<tbody data-navigation-items>' . $rows . '</tbody>'
        . '</table></div>'
        . '<p data-navigation-status role="status" aria-live="polite" style="margin: 8px 0; font-size: 0.9rem; color: #a0a0a0;"></p>'
        . '<div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-top: 14px;">'
        . '<button type="submit">Enregistrer la configuration des modules</button>'
        . '</div>'
        . '<script id="Musicband-icons-catalog-data" type="application/json">' . json_encode($iconCatalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . '</script>'
        . '</form>'
        . '<div style="margin-top: 24px; border-top: 1px solid var(--Musicband-line, #383838); padding-top: 16px;">'
        . '<h3>Restauration des valeurs par défaut</h3>'
        . '<p>Rétablir les noms d’affichage originaux, les icônes standards, l’ordre et l’affichage d’accueil par défaut.</p>'
        . '<form method="post" onsubmit="return confirm(\'ATTENTION : Rétablir tous les modules avec leurs paramètres par défaut ? Les personnalisations de noms et d’icônes seront réinitialisées.\');">'
        . $hidden
        . '<input type="hidden" name="action" value="module_settings_reset">'
        . '<input type="hidden" name="config_tab" value="general">'
        . '<input type="hidden" name="confirm_reset" value="yes">'
        . '<button type="submit" class="button button-danger">Rétablir les modules par défaut</button>'
        . '</form>'
        . '</div>'
        . '</section>';

    return $html;
}
function MusicbandHomeOrderForm(string $hidden): string {
    return MusicbandModuleSettingsForm($hidden);
}
function MusicbandIconCatalog(): array {
    static $catalog=null;
    if ($catalog===null) {
        try {
            $catalog=json_decode(base64_decode('eyJpY29ucyI6eyJob21lIjp7ImxhYmVsIjoiTWFpc29uIiwicGF0aCI6Ik0zIDEwIDEyIDNsOSA3djExaC02di03SDl2N0gzWiJ9LCJtdXNpYyI6eyJsYWJlbCI6Ik11c2lxdWUiLCJwYXRoIjoiTTkgMThWNWwxMi0ydjEzTTkgOGwxMi0yTTkgMThhMyAzIDAgMSAxLTMtM2gzbTEyIDFhMyAzIDAgMSAxLTMtM2gzIn0sInNsaWRlcnMiOnsibGFiZWwiOiJSXHUwMGU5Z2xhZ2VzIGF1ZGlvIiwicGF0aCI6Ik01IDN2N20wIDR2N203LTE4djEybTAgNHYybTctMTh2M20wIDR2MTFNMiAxMGg2bTEgNWg2bTEtOWg2In0sImNvbnRhY3RzIjp7ImxhYmVsIjoiQ29udGFjdHMiLCJwYXRoIjoiTTQgM2gxNnYxOEg0Wk0yIDdoNG0tNCA1aDRtLTQgNWg0bTQtMTBhMiAyIDAgMSAwIDQgMCAyIDIgMCAwIDAtNCAwbS0xIDEwdi0yYTMgMyAwIDAgMSA2IDB2MiJ9LCJjYWxlbmRhciI6eyJsYWJlbCI6IkNhbGVuZHJpZXIiLCJwYXRoIjoiTTQgNWgxNnYxNkg0Wm0zLTN2Nm0xMC02djZNNCAxMGgxNm0tMTIgNGgybTQgMGgybS04IDRoMiJ9LCJkb2N1bWVudCI6eyJsYWJlbCI6IkRvY3VtZW50IiwicGF0aCI6Ik01IDNoOWw1IDV2MTNINVptOSAwdjZoNU04IDEzaDhtLTggNGg4In0sInNldHRpbmdzIjp7ImxhYmVsIjoiQ29uZmlndXJhdGlvbiIsInBhdGgiOiJNNCA2aDE2TTQgMTJoMTZNNCAxOGgxNk04IDN2Nm04IDB2Nm0tNiAwdjYifSwibG9nb3V0Ijp7ImxhYmVsIjoiU29ydGllIiwicGF0aCI6Ik05IDNINHYxOGg1bTUtMTQgNSA1LTUgNW0tNS01aDE0In0sIm1pY3JvcGhvbmUiOnsibGFiZWwiOiJNaWNyb3Bob25lIiwicGF0aCI6Ik05IDZhMyAzIDAgMCAxIDYgMHY2YTMgMyAwIDAgMS02IDBabS0zIDV2MWE2IDYgMCAwIDAgMTIgMHYtMW0tNiA3djRtLTQgMGg4In0sInN0YXIiOnsibGFiZWwiOiJcdTAwYzl0b2lsZSIsInBhdGgiOiJtMTIgMyAzIDYgNyAxLTUgNSAxIDctNi0zLTYgMyAxLTctNS01IDctMVoifSwidXNlcnMiOnsibGFiZWwiOiJHcm91cGUiLCJwYXRoIjoiTTkgMTFhNCA0IDAgMSAwIDAtOCA0IDQgMCAwIDAgMCA4Wm0tNyAxMHYtM2E3IDUgMCAwIDEgMTQgMHYzbTAtMTdhNCA0IDAgMCAxIDAgOG0zIDNhNSA0IDAgMCAxIDMgNHYyIn0sInBpbiI6eyJsYWJlbCI6IkxpZXUiLCJwYXRoIjoiTTEyIDIyUzQgMTMgNCA5YTggOCAwIDAgMSAxNiAwYzAgNC04IDEzLTggMTNabS0zLTEzYTMgMyAwIDEgMCA2IDAgMyAzIDAgMCAwLTYgMCJ9LCJ0YXNrcyI6eyJsYWJlbCI6IlRcdTAwZTJjaGVzIiwicGF0aCI6Ik05IDExbDMgM0wyMiA0TTIxIDEydjdhMiAyIDAgMCAxLTIgMkg1YTIgMiAwIDAgMS0yLTJWNWEyIDIgMCAwIDEgMi0yaDExIn19LCJkZWZhdWx0cyI6eyJob21lIjoiaG9tZSIsInNldGxpc3QiOiJtdXNpYyIsInNvbmdzIjoibXVzaWMiLCJjb250YWN0cyI6ImNvbnRhY3RzIiwiY29uY2VydHMiOiJjYWxlbmRhciIsImFnZW5kYSI6ImNhbGVuZGFyIiwidGFza3MiOiJ0YXNrcyIsImFkbWluIjoic2V0dGluZ3MiLCJhdWRpdCI6ImRvY3VtZW50IiwiaWRlYXMiOiJzdGFyIiwicGFzc3dvcmQiOiJzZXR0aW5ncyIsImxvZ291dCI6ImxvZ291dCJ9fQ==',true),true,16,JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $catalog = [
                'icons' => [
                    'home' => ['label' => 'Maison', 'path' => 'M3 10 12 3l9 7v11h-6v-7H9v7H3Z'],
                    'music' => ['label' => 'Musique', 'path' => 'M9 18V5l12-2v13M9 8l12-2M9 18a3 3 0 1 1-3-3h3m12 1a3 3 0 1 1-3-3h3'],
                    'sliders' => ['label' => 'Réglages audio', 'path' => 'M5 3v7m0 4v7m7-18v12m0 4v2m7-18v3m0 4v11M2 10h6m1 5h6m1-9h6'],
                    'contacts' => ['label' => 'Contacts', 'path' => 'M4 3h16v18H4ZM2 7h4m-4 5h4m-4 5h4m4-10a2 2 0 1 0 4 0 2 2 0 0 0-4 0m-1 10v-2a3 3 0 0 1 6 0v2'],
                    'calendar' => ['label' => 'Calendrier', 'path' => 'M4 5h16v16H4Zm3-3v6m10-6v6M4 10h16m-12 4h2m4 0h2m-8 4h2'],
                    'document' => ['label' => 'Document', 'path' => 'M5 3h9l5 5v13H5Zm9 0v6h5M8 13h8m-8 4h8'],
                    'settings' => ['label' => 'Configuration', 'path' => 'M4 6h16M4 12h16M4 18h16M8 3v6m8 0v6m-6 0v6'],
                    'logout' => ['label' => 'Sortie', 'path' => 'M9 3H4v18h5m5-14 5 5-5 5m-5-5h14'],
                    'microphone' => ['label' => 'Microphone', 'path' => 'M9 6a3 3 0 0 1 6 0v6a3 3 0 0 1-6 0Zm-3 5v1a6 6 0 0 0 12 0v-1m-6 7v4m-4 0h8'],
                    'star' => ['label' => 'Étoile', 'path' => 'm12 3 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1Z'],
                    'users' => ['label' => 'Groupe', 'path' => 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 10v-3a7 5 0 0 1 14 0v3m0-17a4 4 0 0 1 0 8m3 3a5 4 0 0 1 3 4v2'],
                    'pin' => ['label' => 'Lieu', 'path' => 'M12 22S4 13 4 9a8 8 0 0 1 16 0c0 4-8 13-8 13Zm-3-13a3 3 0 1 0 6 0 3 3 0 0 0-6 0'],
                    'tasks' => ['label' => 'Tâches', 'path' => 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'],
                ],
                'defaults' => [
                    'home' => 'home', 'setlist' => 'music', 'songs' => 'music', 'sound' => 'sliders', 'contacts' => 'contacts',
                    'agenda' => 'calendar', 'concerts' => 'calendar', 'devis_factures' => 'document', 'tasks' => 'tasks',
                    'admin' => 'settings', 'password' => 'settings', 'notifications' => 'calendar', 'ideas' => 'star', 'audit' => 'document', 'logout' => 'logout',
                ]
            ];
        }
    }
    return $catalog;
}
function MusicbandMenuIcons(): array {
    $catalog=MusicbandIconCatalog(); $icons=$catalog['defaults'];foreach(MusicbandNavigationCatalog() as $key=>$entry)if(!isset($icons[$key]))$icons[$key]=$entry['icon'];
    foreach (MusicbandRead('home_settings')['module_icons']??[] as $module=>$icon) {
        if (isset($icons[$module]) && is_string($icon) && isset($catalog['icons'][$icon])) $icons[$module]=$icon;
    }
    return $icons;
}
function MusicbandHomeDescriptions(): array {
    static $defaults = null;
    if ($defaults === null) {
        try {
            $defaults = json_decode(base64_decode('eyJzZXRsaXN0IjoiQ29tcG9zZXIgbGVzIHNldGxpc3RzIGV0IGNvbnN1bHRlciBsZXVycyBleHBvcnRzLiIsInNvbmdzIjoiR1x1MDBlOXJlciBsZSBjYXRhbG9ndWUgZGUgbW9yY2VhdXguIiwiY29udGFjdHMiOiJHXHUwMGU5cmVyIGxlcyBjb250YWN0cyBkdSBncm91cGUuIiwiY29uY2VydHMiOiJQclx1MDBlOXBhcmVyIGxlcyBjb25jZXJ0cy4iLCJhZ2VuZGEiOiJDb25zdWx0ZXIgbFx1MjAxOWFnZW5kYSBHb29nbGUgZW4gbGVjdHVyZSBzZXVsZS4iLCJ0YXNrcyI6IlN1aXZyZSBsZXMgdFx1MDBlMmNoZXMgZHUgZ3JvdXBlLiJ9', true), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $defaults = [
                'setlist' => 'Consulter les concerts publiés et leurs exports.',
                'sound' => 'Lire une scène, interpréter les réglages, construire les essais sans IA et exporter le rapport PDF.',
                'contacts' => 'Rechercher les contacts, consulter les coordonnées et modifier les fiches.',
                'concerts' => 'Retrouver les événements, leurs horaires, lieux et contacts associés.',
                'devis_factures' => 'Gérer les fiches documentaires et leurs associations aux concerts et contacts.',
                'tasks' => 'Suivre les tâches collaboratives du groupe, leurs échéances et avancements.',
            ];
        }
        if (!is_array($defaults)) {
            $defaults = [];
        }
    }
    $saved = MusicbandRead('home_settings')['module_descriptions'] ?? [];
    $texts = [];
    foreach (MusicbandNavigationCatalog() as $key => $_) {
        $texts[$key] = is_string($saved[$key] ?? null) ? $saved[$key] : ($defaults[$key] ?? '');
    }
    return $texts;
}
function MusicbandIconsVersion(array $icons): string {
    return hash('sha256',json_encode([$icons,MusicbandHomeDescriptions()],JSON_THROW_ON_ERROR));
}
function MusicbandIconsSave(): string {
    $current=MusicbandMenuIcons(); $posted=$_POST['icons']??null; $catalog=MusicbandIconCatalog();
    // Le formulaire ne présente que les entrées actives ; conserver les autres préférences privées.
    $editable = array_intersect_key($current, MusicbandNavigationCatalog());
    if (!is_string($_POST['icons_version']??null) || !hash_equals(MusicbandIconsVersion($current),$_POST['icons_version'])) throw new RuntimeException('Les icônes ou textes ont changé ailleurs : recharger avant de modifier.');
    if (!is_array($posted) || count($posted)!==count($editable) || array_diff(array_keys($posted),array_keys($editable))) throw new RuntimeException('Choisir une icône pour chaque menu.');
    foreach ($posted as $icon) if (!is_string($icon) || !isset($catalog['icons'][$icon])) throw new RuntimeException('Icône inconnue.');
    $texts=$_POST['module_descriptions']??null;$currentTexts=MusicbandHomeDescriptions();
    if (!is_array($texts) || count($texts)!==count($currentTexts) || array_diff(array_keys($texts),array_keys($currentTexts))) throw new RuntimeException('Renseigner le texte de chaque tuile.');
    foreach($texts as $key=>$text) {
        if (!is_string($text) || strlen($text)>1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text) || !preg_match('//u',$text)) throw new RuntimeException('Texte de tuile invalide : maximum 1000 octets UTF-8.');
        $texts[$key]=trim($text);
    }
    $settings=MusicbandRead('home_settings');
    $settings['module_icons']=array_replace($settings['module_icons']??[], $posted);
    $settings['module_descriptions']=array_replace($settings['module_descriptions']??[], $texts);
    MusicbandWrite('home_settings',$settings);
    return 'Icônes et textes des tuiles enregistrés pour tous. Recharger les autres pages pour les afficher.';
}
function MusicbandIconsForm(string $hidden): string {
    $current=MusicbandMenuIcons(); $catalog=MusicbandIconCatalog();$texts=MusicbandHomeDescriptions();
    $labels=MusicbandNavigationModules();
    $html='<section><h2>Icônes et textes des tuiles</h2><p>Les icônes sont communes au menu et à l’accueil. Le petit texte apparaît uniquement sous le titre de la tuile. Texte simple, maximum 1000 octets ; laisser vide pour le masquer. Les noms et destinations restent conservés.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="menu_icons"><input type="hidden" name="config_tab" value="icones"><input type="hidden" name="icons_version" value="'.MusicbandIconsVersion($current).'"><div class="Musicband-icon-options">';
    foreach (MusicbandHomeOrder() as $module) {
        $selected=$current[$module];
        $html.='<fieldset><legend><span class="Musicband-icon" data-Musicband-icon="'.$module.'"></span>'.MusicbandEscape($labels[$module]).'</legend><label>Icône<select name="icons['.$module.']">';
        foreach ($catalog['icons'] as $key=>$icon) $html.='<option value="'.$key.'"'.($key===$selected?' selected':'').'>'.MusicbandEscape($icon['label']).'</option>';
        $html.='</select></label><label>Texte de la tuile d’accueil<textarea name="module_descriptions['.$module.']" rows="3" maxlength="1000">'.MusicbandEscape($texts[$module]).'</textarea></label></fieldset>';
    }
    return $html.'</div><button>Enregistrer les icônes et textes</button></form></section>';
}
function MusicbandModuleBeta(): array {
    $saved=MusicbandRead('home_settings')['module_beta']??[]; $state=[];
    foreach (MusicbandHomeModules() as $key=>$_) $state[$key]=($saved[$key]??false)===true;
    return $state;
}
function MusicbandBetaVersion(array $state): string {
    return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));
}
function MusicbandBetaSave(): string {
    $current=MusicbandModuleBeta(); $posted=$_POST['beta']??null;
    if (!is_string($_POST['beta_version']??null) || !hash_equals(MusicbandBetaVersion($current),$_POST['beta_version'])) throw new RuntimeException('Les statuts bêta ont changé ailleurs : recharger avant de modifier.');
    if (!is_array($posted) || count($posted)!==count($current) || array_diff(array_keys($posted),array_keys($current))) throw new RuntimeException('Choisir un statut pour chaque module.');
    $state=[];
    foreach ($current as $key=>$_) {
        if (!is_string($posted[$key]) || !in_array($posted[$key],['0','1'],true)) throw new RuntimeException('Statut bêta invalide.');
        $state[$key]=$posted[$key]==='1';
    }
    $settings=MusicbandRead('home_settings'); $settings['module_beta']=$state; MusicbandWrite('home_settings',$settings);
    return 'Statuts bêta enregistrés pour tous. Recharger les autres pages pour les afficher.';
}
function MusicbandBetaForm(string $hidden): string {
    $state=MusicbandModuleBeta();
    $html='<section><h2>Modules en bêta</h2><p>Cocher les modules encore en bêta. Le badge est visible pour tous dans les menus et sur l’accueil. Décocher retire le badge et ne constitue pas une validation fonctionnelle.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="module_beta"><input type="hidden" name="beta_version" value="'.MusicbandBetaVersion($state).'"><div class="Musicband-icon-options">';
    foreach (MusicbandHomeModules() as $key=>$label) $html.='<input type="hidden" name="beta['.$key.']" value="0"><label class="Musicband-touch-target" ><input class="Musicband-checkbox" type="checkbox" name="beta['.$key.']" value="1"'.($state[$key]?' checked':'').' >'.MusicbandEscape($label).' · Bêta</label>';
    return $html.'</div><button>Enregistrer les statuts bêta</button></form></section>';
}
function MusicbandPwaHead(): string { $base=MusicbandEscape(MusicbandBase());return '<link rel="manifest" href="'.$base.'pwa_manifest.php" crossorigin="use-credentials"><link rel="apple-touch-icon" href="'.$base.'pwa_icon.php?size=192"><meta name="theme-color" content="#101010">'; }
function MusicbandUiHtml(string $html): string {
    // Retirer aussi les liens des anciens gabarits préparés, sans changer les réglages enregistrés.
    if (defined('MUSICBAND_COMMUNITY_DISABLED_MODULES')) foreach (MUSICBAND_COMMUNITY_DISABLED_MODULES as $module) $html = preg_replace('~<a\b[^>]*data-(?:Musicband-module|module)="'.preg_quote($module, '~').'"[^>]*>.*?</a>~s', '', $html) ?? $html;
    if (str_contains($html,'</head>')) { $html=preg_replace('~<link\b[^>]*rel="manifest"[^>]*>~i','',$html)??$html;$html=str_replace('</head>',MusicbandPwaHead().MusicbandAppearanceHead().'</head>',$html); }
    // Les anciennes déclarations inline suivent les mêmes tokens que les feuilles préparées.
    $html = preg_replace_callback('~\bstyle="([^"]*)"~i', function($attribute) {
        $style = preg_replace_callback('~(?<![\w-])(font-size|font)\s*:([^;]+)~i', function($declaration) {
            if (str_contains($declaration[2], 'var(')) return $declaration[0];
            return $declaration[1].':'.preg_replace('~(?<![\w.-])(\d+(?:\.\d+)?)px~', 'calc($1px * var(--appearance-scale, 1))', $declaration[2]);
        }, $attribute[1]);
        $style = str_contains($style, 'var(') ? $style : preg_replace_callback('~#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b~', function($color) {
            $hex = strtolower(substr($color[0], 1));
            if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            if (substr($hex,0,2) !== substr($hex,2,2) || substr($hex,0,2) !== substr($hex,4,2)) return $color[0];
            $shade = hexdec(substr($hex,0,2));
            $role = $shade <= 24 ? 'bg' : ($shade <= 34 ? 'card' : ($shade <= 48 ? 'elevated' : ($shade <= 68 ? 'line' : ($shade <= 102 ? 'border' : ($shade <= 163 ? 'muted' : 'text')))));
            return 'var(--appearance-neutral-'.$role.', '.$color[0].')';
        }, $style);
        return 'style="'.$style.'"';
    }, $html) ?? $html;
    $displayNames = MusicbandModuleDisplayNames();
    $html=preg_replace_callback('~(<nav class="Musicband-links"[^>]*>)(.*?)(</nav>)~s',function($nav) use ($displayNames) {
        preg_match_all('~<a\b[^>]*data-Musicband-module="([a-z_]+)"[^>]*>.*?</a>~s',$nav[2],$matches,PREG_SET_ORDER);$links=[];
        foreach($matches as $match) {
            $key = $match[1];
            $linkHtml = $match[0];
            if (isset($displayNames[$key])) {
                $linkHtml = preg_replace('~<span(?![^>]*class=)>(.*?)</span>~', '<span>' . MusicbandEscape($displayNames[$key]) . '</span>', $linkHtml, 1) ?? $linkHtml;
            }
            $links[$key] = $linkHtml;
        }
        if(!$links)return $nav[0];$ordered='';
        foreach(MusicbandHomeOrder() as $key)if(isset($links[$key])){$ordered.=$links[$key];unset($links[$key]);}
        $tail=preg_replace('~<a\b[^>]*data-Musicband-module="[a-z_]+"[^>]*>.*?</a>~s','',$nav[2])??'';
        return $nav[1].$ordered.implode('',$links).$tail.$nav[3];
    },$html)??$html;
    if (str_contains($html,'data-Musicband-admin=') && !(MusicbandUser()['admin']??false)) $html=preg_replace('~<a\b[^>]*data-Musicband-admin="1"[^>]*>.*?</a>~s','',$html)??$html;
    if(!MusicbandCan('calendar.read'))$html=preg_replace('~<a\b[^>]*data-(?:Musicband-module|module)="agenda"[^>]*>.*?</a>~s','',$html)??$html;
    if(!MusicbandCan('audit.read'))$html=preg_replace('~<a\b[^>]*data-(?:Musicband-module|module)="audit"[^>]*>.*?</a>~s','',$html)??$html;
    foreach(['expenses','accounting'] as $financialModule)if(!MusicbandCan($financialModule.'.read'))$html=preg_replace('~<a\b[^>]*data-(?:Musicband-module|module)="'.$financialModule.'"[^>]*>.*?</a>~s','',$html)??$html;
    if(!MusicbandCan('tasks.read'))$html=preg_replace('~<a\b[^>]*data-(?:Musicband-module|module)="tasks"[^>]*>.*?</a>~s','',$html)??$html;
    if (str_contains($html, 'Musicband-help-menu')) {
        require_once __DIR__ . '/help_service.php';
        $html = preg_replace('~<details class="Musicband-help-menu">.*?<div class="Musicband-help-panel".*?</div>\s*</details>~s', MusicbandHelpMenuHtml(), $html, 1) ?? $html;
    }
    if (!str_contains($html,'data-Musicband-icon=') && !str_contains($html,'data-Musicband-beta=')) return $html;
    $icons=MusicbandMenuIcons(); $catalog=MusicbandIconCatalog();
    $html=preg_replace_callback('~<span class="Musicband-icon" data-Musicband-icon="([a-z_]+)">.*?</span>~s',function($match) use ($icons,$catalog) {
        $key=$match[1]; if (!isset($icons[$key])) return $match[0];
        return '<span class="Musicband-icon" data-Musicband-icon="'.MusicbandEscape($key).'"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="'.MusicbandEscape($catalog['icons'][$icons[$key]]['path']).'"/></svg></span>';
    },$html)??$html;
    $beta=MusicbandModuleBeta();
    return preg_replace_callback('~<span class="Musicband-beta" data-Musicband-beta="([a-z_]+)" hidden></span>~',fn($match)=>!empty($beta[$match[1]])?'<span class="Musicband-beta">Bêta</span>':'',$html)??$html;
}
function MusicbandHomeHtml(string $html): string {
    if (!headers_sent()) {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    $dashboard = '';
    $user = MusicbandUser();
    if ($user && file_exists(__DIR__ . '/dashboard_service.php')) {
        require_once __DIR__ . '/dashboard_service.php';
        $dashboard = dashboardRender($user);
    }
    // Synchroniser les cartes de l'accueil avec la configuration centralisée
    $rendered = MusicbandUiHtml(preg_replace_callback('~<main class="modules">(.*?)</main>~s', function($main) {
        preg_match_all('~<a class="module" data-module="([a-z_]+)"[^>]*>.*?</a>~s', $main[1], $matches, PREG_SET_ORDER);
        $cards = [];
        foreach ($matches as $match) $cards[$match[1]] = $match[0];
        $visible=MusicbandHomeVisibility();$catalog=MusicbandNavigationCatalog();$displayNames=MusicbandModuleDisplayNames();$texts=MusicbandHomeDescriptions();$output='';
        foreach(MusicbandHomeOrder() as $key){
            if(!$visible[$key]||($key==='admin'&&!(MusicbandUser()['admin']??false))||($key==='audit'&&!MusicbandCan('audit.read'))||($key==='agenda'&&!MusicbandCan('calendar.read'))||($key==='tasks'&&!MusicbandCan('tasks.read'))||(in_array($key,['expenses','accounting'],true)&&!MusicbandCan($key.'.read')))continue;
            $label=$displayNames[$key]??$catalog[$key]['label'];
            $description=$texts[$key]!==''?'<p>'.MusicbandEscape($texts[$key]).'</p>':'';
            $h2='<h2><span class="Musicband-icon" data-Musicband-icon="'.$key.'"></span>'.MusicbandEscape($label).'<span class="Musicband-beta" data-Musicband-beta="'.$key.'" hidden></span></h2>';
            if(isset($cards[$key])){
                $card=preg_replace('~<p>.*?</p>~s','',$cards[$key]);
                if(str_contains($card,'<h2')) {
                    $card=preg_replace('~<h2>.*?</h2>~s',$h2,$card)??$card;
                } else {
                    $card=preg_replace('~(<a\b[^>]*>)~','$1'.$h2,$card,1)??$card;
                }
                $output.=str_replace('</a>',$description.'</a>',$card);
                continue;
            }
            if(!isset($catalog[$key]))continue;
            $entry=$catalog[$key];
            $output.='<a class="module" data-module="'.$key.'"'.($key==='admin'||$key==='audit'?' data-Musicband-admin="1"':'').' href="'.MusicbandEscape(MusicbandBase().$entry['path']).'">'.$h2.$description.'</a>';
        }
        foreach($cards as $key=>$card)if(!isset($catalog[$key]))$output.=$card;
        return '<main class="modules">'.($output!==''?$output:'<p>Aucune entrée affichée sur l’accueil. Utilisez le menu général pour naviguer.</p>').'</main>';
    }, $html) ?? $html);
    if ($dashboard !== '') {
        $rendered = str_replace('<main class="modules">', $dashboard . '<main class="modules">', $rendered);
    }
    return $rendered;
}
function MusicbandSessionCookie(): void {
    if (!headers_sent() && isset($_SESSION['started'])) setcookie(session_name(),session_id(),['expires'=>$_SESSION['started']+MusicbandSessionSeconds(),'path'=>MusicbandBase(),'secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
}
function MusicbandLogin(string $login, array $user): void {
    if (!MusicbandAccountIsActive($user)) throw new RuntimeException('Compte en attente d’activation ou désactivé.');
    session_regenerate_id(true); $_SESSION = ['login' => $login, 'revision' => $user['revision'], 'started' => time(), 'csrf' => bin2hex(random_bytes(32))];
    MusicbandSessionCookie();
    MusicbandAuditIdentity($login);MusicbandAuditEvent('auth','login.success','success');
    // Notification optionnelle des administrateurs lors d'une authentification réussie
    try {
        if (!function_exists('advancedNotificationNotifyAdminsOnLogin')) {
            $advService = __DIR__ . '/advanced_notifications_service.php';
            if (file_exists($advService)) {
                require_once $advService;
            }
        }
        if (function_exists('advancedNotificationNotifyAdminsOnLogin')) {
            advancedNotificationNotifyAdminsOnLogin($login);
        }
    } catch (Throwable $e) {
        error_log('advanced_notif_login_error: ' . $e->getMessage());
    }
}
function MusicbandLogout(): void {
    MusicbandAuditEvent('auth','logout','success',['reason'=>basename($_SERVER['SCRIPT_NAME']??'')==='logout.php'?'voluntary':'session_revoked']);
    $_SESSION = []; setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => MusicbandBase(), 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']); session_destroy();
}
function MusicbandEmail($value): string {
    if (!is_string($value) || strlen($value) > 254 || preg_match('/[\r\n]/', $value)) return '';
    $email = strtolower(trim($value));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}
function MusicbandEmailLogin(array $users, string $email): ?string {
    if ($email === '') return null;
    foreach ($users as $login => $user) if (MusicbandEmail($user['email'] ?? '') === $email) return (string)$login;
    return null;
}
function MusicbandValidateCredentials(string $login, string $password): void {
    if (!preg_match('/^[A-Za-z0-9_.@-]{1,80}$/D', $login) && MusicbandEmail($login) === '') throw new RuntimeException('Identifiant ou adresse e-mail invalide.');
    if (strlen($password) < 12 || strlen($password) > 72) throw new RuntimeException('Mot de passe : entre 12 et 72 octets.');
}
function MusicbandPhotonUrl(): string {
    $url=getenv('Musicband_PHOTON_URL');
    if ($url==='off') return '';
    $url=$url===false || $url===''?'https://photon.komoot.io/api/':$url;
    if (!preg_match('~^https://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?/[a-zA-Z0-9/_-]*$~D',$url) || !filter_var($url,FILTER_VALIDATE_URL)) { error_log('Musicband_photon_url_invalid'); return ''; }
    return $url;
}
/** Préférences privées injectées avant le rendu ; aucune dépendance métier chargée ici. */
function MusicbandAppearanceHead(?string $nonce = null): string {
    $prefs = [];
    if (isset($_SESSION['login'])) {
        try { $all = MusicbandRead('user_preferences'); $prefs = $all[$_SESSION['login']] ?? []; } catch (Throwable $e) { /* Repli accessible sans bloquer la page. */ }
    }
    $theme = in_array($prefs['appearance_theme'] ?? '', ['light','dark','auto'], true) ? $prefs['appearance_theme'] : 'auto';
    $scale = $prefs['appearance_scale'] ?? 100;
    if (!is_int($scale) || $scale < 90 || $scale > 130 || $scale % 10 !== 0) $scale = 100;
    $state = json_encode(['theme'=>$theme,'scale'=>$scale], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    $script = <<<'JS'
(()=>{
    const root=document.documentElement, system=window.matchMedia('(prefers-color-scheme: dark)');
    let state=Musicband_APPEARANCE_STATE;
    const apply=()=>{
        root.dataset.appearanceTheme=state.theme==='auto'?(system.matches?'dark':'light'):state.theme;
        root.style.setProperty('--appearance-scale',String(state.scale/100));
    };
    apply();
    if(system.addEventListener)system.addEventListener('change',apply);else system.addListener(apply);
    document.addEventListener('DOMContentLoaded',()=>{
        const form=document.getElementById('appearance-preferences');if(!form)return;
        const theme=form.elements.appearance_theme, scale=form.elements.appearance_scale, output=document.getElementById('appearance-size'), status=document.getElementById('appearance-status');
        let pending=false;
        const refresh=()=>{
            theme.value=state.theme;scale.value=state.scale;output.value=state.scale+' %';
            form.querySelector('[data-size="-10"]').disabled=pending||state.scale<=90;
            form.querySelector('[data-size="10"]').disabled=pending||state.scale>=130;
        };
        refresh();
        const save=async(next)=>{
            if(pending)return;
            const previous={...state};state=next;apply();refresh();pending=true;
            const data=new FormData(form);
            const controls=[...form.querySelectorAll('button,select')];controls.forEach(el=>el.disabled=true);
            status.textContent='Enregistrement…';
            try{
                // Le champ caché name=action masque la propriété form.action.
                const target=form.getAttribute('action')||window.location.href;
                const response=await fetch(target,{method:'POST',body:data,credentials:'same-origin'});
                const result=await response.json();if(!response.ok||!result.ok)throw new Error();
                status.textContent='Apparence enregistrée.';
            }catch(e){state=previous;apply();status.textContent='Apparence non enregistrée. Réessayez ou reconnectez-vous.';}
            finally{pending=false;controls.forEach(el=>el.disabled=false);refresh();}
        };
        form.addEventListener('submit',event=>event.preventDefault());
        // Construire les données avant désactivation : le thème reste transmis explicitement.
        theme.addEventListener('change',()=>save({...state,theme:theme.value}));
        form.querySelectorAll('[data-size]').forEach(button=>button.addEventListener('click',()=>save({...state,scale:Number(button.dataset.size)===0?100:Math.max(90,Math.min(130,state.scale+Number(button.dataset.size)))})));
        document.getElementById('appearance-reset').addEventListener('click',()=>save({theme:'auto',scale:100}));
    });
})();
JS;
    $script = str_replace('Musicband_APPEARANCE_STATE', $state, $script);
    return '<script'.($nonce === null ? '' : ' nonce="'.MusicbandEscape($nonce).'"').'>'.$script.'</script>';
}

// Chemin versionné unique ; les noms de feuilles viennent du code.
function MusicbandStylesheet(string $name): string {
    return '<link rel="stylesheet" href="'.MusicbandEscape(MusicbandBase().'css/'.$name.'.css?v=2026.10.22.23-community').'">';
}
/** Cadre HTML commun : les feuilles portent la mise en page, PHP garde les droits et la CSP. */
function MusicbandScreen(string $title, string $content, ?string $script = null, bool $connectSelf = false, string $photonUrl = '', string $headerNavigation = ''): void {
    $navUi = base64_decode('LyoqCiAqIE11c2ljYmFuZCBOQVZJR0FUSU9OIFVJOiBDb21tYW5kZXMgZGUgbmF2aWdhdGlvbiBwYXJ0YWfDqWVzLgogKiAtIFJlbW9udMOpZSBlbiBoYXV0IChib3V0b24gZmxvdHRhbnQgZMOpcGxhw6dhYmxlLCBhY2Nlc3NpYmxlIGF1IGNsYXZpZXIpLgogKiAtIE5hdmlnYXRpb24gY29udGV4dHVlbGxlIGVudHJlIGZpY2hlcyBkw6l0YWlsbMOpZXMgKHByw6ljw6lkZW50L3N1aXZhbnQgPCBldCA+LCBzd2lwZSBtb2JpbGUsIGNvbnNlcnZhdGlvbiBmaWx0cmVzL3RyaSkuCiAqIC0gRmVybWV0dXJlIGRlcyBtZW51cyBhdSBjbGF2aWVyIChFc2NhcGUpLgogKiBTYW5zIGTDqXBlbmRhbmNlIGV4dGVybmUgbmkgYXBwZWwgcsOpc2VhdSA7IHBlcnNpc3RhbmNlIGRlIGNvbnRleHRlIHZpYSBzZXNzaW9uU3RvcmFnZS4KICovCigoKT0+ewogIC8qID09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT0KICAgKiAxLiBCb3V0b24gZmxvdHRhbnQgZGUgcmVtb250w6llIGVuIGhhdXQKICAgKiA9PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09ICovCiAgZnVuY3Rpb24gaW5pdFNjcm9sbFRvcCgpewogICAgaWYoZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ011c2ljYmFuZC1zY3JvbGwtdG9wJykpcmV0dXJuOwogICAgY29uc3QgYnV0dG9uPWRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ2J1dHRvbicpO2J1dHRvbi5pZD0nTXVzaWNiYW5kLXNjcm9sbC10b3AnO2J1dHRvbi5jbGFzc05hbWU9J011c2ljYmFuZC1zY3JvbGwtdG9wJztidXR0b24udHlwZT0nYnV0dG9uJzsKICAgIGJ1dHRvbi5zZXRBdHRyaWJ1dGUoJ2FyaWEtbGFiZWwnLCdSZW1vbnRlciBlbiBoYXV0LiBBcHB1aSBsb25nIHBvdXIgY2hhbmdlciBkZSBjw7R0w6kuJyk7CiAgICBidXR0b24udGl0bGU9J1JlbW9udGVyIGVuIGhhdXQgwrcgQXBwdWkgbG9uZyA6IGNoYW5nZXIgZGUgY8O0dMOpJzsKICAgIGJ1dHRvbi5pbm5lckhUTUw9Jzxzdmcgdmlld0JveD0iMCAwIDI0IDI0IiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSI+PHBhdGggZD0ibTUgMTIgNy03IDcgN00xMiA1djE1Ii8+PC9zdmc+JzsKICAgIGxldCBsZWZ0PWZhbHNlLHRpbWVyPW51bGwsbG9uZ1ByZXNzPWZhbHNlLHN0YXJ0PW51bGw7CiAgICB0cnl7bGVmdD1sb2NhbFN0b3JhZ2UuZ2V0SXRlbSgnTXVzaWNiYW5kLXNjcm9sbC10b3Atc2lkZScpPT09J2xlZnQnO31jYXRjaChlKXt9CiAgICBjb25zdCBwbGFjZT0oKT0+YnV0dG9uLmNsYXNzTGlzdC50b2dnbGUoJ2lzLWxlZnQnLGxlZnQpO3BsYWNlKCk7CiAgICBjb25zdCB1cGRhdGU9KCk9PntidXR0b24uaGlkZGVuPXdpbmRvdy5zY3JvbGxZPDMyMDt9O3VwZGF0ZSgpOwogICAgZG9jdW1lbnQuYm9keS5hcHBlbmQoYnV0dG9uKTt3aW5kb3cuYWRkRXZlbnRMaXN0ZW5lcignc2Nyb2xsJyx1cGRhdGUse3Bhc3NpdmU6dHJ1ZX0pOwogICAgY29uc3QgY2FuY2VsPSgpPT57Y2xlYXJUaW1lb3V0KHRpbWVyKTt0aW1lcj1udWxsO307CiAgICBidXR0b24uYWRkRXZlbnRMaXN0ZW5lcigncG9pbnRlcmRvd24nLGV2ZW50PT57CiAgICAgIGlmKGV2ZW50LmJ1dHRvbiE9PTApcmV0dXJuOwogICAgICBjYW5jZWwoKTtsb25nUHJlc3M9ZmFsc2U7c3RhcnQ9e3g6ZXZlbnQuY2xpZW50WCx5OmV2ZW50LmNsaWVudFl9OwogICAgICBidXR0b24uc2V0UG9pbnRlckNhcHR1cmUoZXZlbnQucG9pbnRlcklkKTsKICAgICAgdGltZXI9c2V0VGltZW91dCgoKT0+e2xvbmdQcmVzcz10cnVlO2xlZnQ9IWxlZnQ7cGxhY2UoKTt0cnl7bG9jYWxTdG9yYWdlLnNldEl0ZW0oJ011c2ljYmFuZC1zY3JvbGwtdG9wLXNpZGUnLGxlZnQ/J2xlZnQnOidyaWdodCcpO31jYXRjaChlKXt9fSw3MDApOwogICAgfSk7CiAgICBidXR0b24uYWRkRXZlbnRMaXN0ZW5lcigncG9pbnRlcm1vdmUnLGV2ZW50PT57aWYoc3RhcnQmJk1hdGguaHlwb3QoZXZlbnQuY2xpZW50WC1zdGFydC54LGV2ZW50LmNsaWVudFktc3RhcnQueSk+MTIpY2FuY2VsKCk7fSk7CiAgICBidXR0b24uYWRkRXZlbnRMaXN0ZW5lcigncG9pbnRlcnVwJywoKT0+e2NhbmNlbCgpO3N0YXJ0PW51bGw7fSk7CiAgICBidXR0b24uYWRkRXZlbnRMaXN0ZW5lcigncG9pbnRlcmNhbmNlbCcsKCk9PntjYW5jZWwoKTtzdGFydD1udWxsO2xvbmdQcmVzcz1mYWxzZTt9KTsKICAgIGJ1dHRvbi5hZGRFdmVudExpc3RlbmVyKCdsb3N0cG9pbnRlcmNhcHR1cmUnLGNhbmNlbCk7CiAgICBidXR0b24uYWRkRXZlbnRMaXN0ZW5lcignY29udGV4dG1lbnUnLGV2ZW50PT5ldmVudC5wcmV2ZW50RGVmYXVsdCgpKTsKICAgIGJ1dHRvbi5hZGRFdmVudExpc3RlbmVyKCdjbGljaycsZXZlbnQ9PnsKICAgICAgaWYobG9uZ1ByZXNzKXtldmVudC5wcmV2ZW50RGVmYXVsdCgpO2xvbmdQcmVzcz1mYWxzZTtyZXR1cm47fQogICAgICBsb25nUHJlc3M9ZmFsc2U7d2luZG93LnNjcm9sbFRvKHt0b3A6MCxiZWhhdmlvcjp3aW5kb3cubWF0Y2hNZWRpYSgnKHByZWZlcnMtcmVkdWNlZC1tb3Rpb246IHJlZHVjZSknKS5tYXRjaGVzPydpbnN0YW50Jzonc21vb3RoJ30pOwogICAgfSk7CiAgICBidXR0b24uYWRkRXZlbnRMaXN0ZW5lcigna2V5ZG93bicsZXZlbnQ9PntpZihldmVudC5rZXk9PT0nQXJyb3dMZWZ0J3x8ZXZlbnQua2V5PT09J0Fycm93UmlnaHQnKXtldmVudC5wcmV2ZW50RGVmYXVsdCgpO2xlZnQ9ZXZlbnQua2V5PT09J0Fycm93TGVmdCc7cGxhY2UoKTt0cnl7bG9jYWxTdG9yYWdlLnNldEl0ZW0oJ011c2ljYmFuZC1zY3JvbGwtdG9wLXNpZGUnLGxlZnQ/J2xlZnQnOidyaWdodCcpO31jYXRjaChlKXt9fX0pOwogIH0KCiAgLyogPT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PQogICAqIDIuIE5hdmlnYXRpb24gY29udGV4dHVlbGxlIGVudHJlIGZpY2hlcyBkw6l0YWlsbMOpZXMgKDwgZXQgPiwgc3dpcGUsIGNvbnRleHRlKQogICAqID09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT0gKi8KICBjb25zdCBTVVBQT1JURURfTU9EVUxFUz1bJ3NvbmdzJywnY29uY2VydHMnLCdjb250YWN0cycsJ2RldmlzX2ZhY3R1cmVzJywndGFza3MnXTsKICBjb25zdCBTVE9SQUdFX1BSRUZJWD0nTXVzaWNiYW5kX2RldGFpbF9uYXZfJzsKCiAgZnVuY3Rpb24gZGV0ZWN0TW9kdWxlKCl7CiAgICBjb25zdCBwYXRoPXdpbmRvdy5sb2NhdGlvbi5wYXRobmFtZTsKICAgIGZvcihjb25zdCBtIG9mIFNVUFBPUlRFRF9NT0RVTEVTKXsKICAgICAgaWYocGF0aC5lbmRzV2l0aCgnLycrbSsnLnBocCcpfHxwYXRoLmVuZHNXaXRoKG0rJy5waHAnKSlyZXR1cm4gbTsKICAgIH0KICAgIHJldHVybiBudWxsOwogIH0KCiAgZnVuY3Rpb24gZ2V0UmVjb3JkSWQoKXsKICAgIGNvbnN0IHBhcmFtcz1uZXcgVVJMU2VhcmNoUGFyYW1zKHdpbmRvdy5sb2NhdGlvbi5zZWFyY2gpOwogICAgY29uc3QgaWQ9cGFyYW1zLmdldCgnaWQnKTsKICAgIGlmKGlkJiYvXlxkKyQvLnRlc3QoaWQpKXJldHVybiBwYXJzZUludChpZCwxMCk7CiAgICByZXR1cm4gbnVsbDsKICB9CgogIGZ1bmN0aW9uIGlzRWRpdE1vZGUoKXsKICAgIGNvbnN0IHBhcmFtcz1uZXcgVVJMU2VhcmNoUGFyYW1zKHdpbmRvdy5sb2NhdGlvbi5zZWFyY2gpOwogICAgcmV0dXJuIHBhcmFtcy5oYXMoJ2VkaXQnKXx8cGFyYW1zLmdldCgnYWN0aW9uJyk9PT0nZWRpdCd8fHBhcmFtcy5nZXQoJ2FjdGlvbicpPT09J25ldyc7CiAgfQoKICBmdW5jdGlvbiBnZXRTdG9yZWRDb250ZXh0KG1vZHVsZSl7CiAgICB0cnl7CiAgICAgIGNvbnN0IHJhdz1zZXNzaW9uU3RvcmFnZS5nZXRJdGVtKFNUT1JBR0VfUFJFRklYK21vZHVsZSk7CiAgICAgIGlmKCFyYXcpcmV0dXJuIG51bGw7CiAgICAgIHJldHVybiBKU09OLnBhcnNlKHJhdyk7CiAgICB9Y2F0Y2goZSl7cmV0dXJuIG51bGw7fQogIH0KCiAgZnVuY3Rpb24gc2V0U3RvcmVkQ29udGV4dChtb2R1bGUsZGF0YSl7CiAgICB0cnl7CiAgICAgIHNlc3Npb25TdG9yYWdlLnNldEl0ZW0oU1RPUkFHRV9QUkVGSVgrbW9kdWxlLEpTT04uc3RyaW5naWZ5KGRhdGEpKTsKICAgIH1jYXRjaChlKXt9CiAgfQoKICBmdW5jdGlvbiBlc2NhcGVIdG1sKHN0cil7CiAgICBpZighc3RyKXJldHVybiAnJzsKICAgIHJldHVybiBTdHJpbmcoc3RyKQogICAgICAucmVwbGFjZSgvJi9nLCcmYW1wOycpCiAgICAgIC5yZXBsYWNlKC88L2csJyZsdDsnKQogICAgICAucmVwbGFjZSgvPi9nLCcmZ3Q7JykKICAgICAgLnJlcGxhY2UoLyIvZywnJnF1b3Q7JykKICAgICAgLnJlcGxhY2UoLycvZywnJiMzOTsnKTsKICB9CgogIGZ1bmN0aW9uIGV4dHJhY3RMaXN0SXRlbXMobW9kdWxlKXsKICAgIGNvbnN0IHJvd3M9ZG9jdW1lbnQucXVlcnlTZWxlY3RvckFsbCgndGFibGUgdGJvZHkgdHInKTsKICAgIGNvbnN0IGl0ZW1zPVtdOwogICAgY29uc3Qgc2Vlbklkcz1uZXcgU2V0KCk7CiAgICBjb25zdCBpZFJlZ2V4PS9bPyZdaWQ9KFxkKykvOwoKICAgIHJvd3MuZm9yRWFjaCh0cj0+ewogICAgICBsZXQgaHJlZj10ci5nZXRBdHRyaWJ1dGUoJ2RhdGEtc29uZy1ocmVmJyl8fCcnOwogICAgICBsZXQgdGl0bGU9Jyc7CiAgICAgIGlmKGhyZWYpewogICAgICAgIGNvbnN0IHRpdGxlTGluaz10ci5xdWVyeVNlbGVjdG9yKCcuc29uZy10aXRsZS1jaG9pY2UgYSwgdGQ6Zmlyc3QtY2hpbGQgYScpOwogICAgICAgIHRpdGxlPXRpdGxlTGluaz90aXRsZUxpbmsudGV4dENvbnRlbnQudHJpbSgpOicnOwogICAgICB9ZWxzZXsKICAgICAgICBjb25zdCBhbmNob3I9dHIucXVlcnlTZWxlY3RvcihgYVtocmVmKj0iJHttb2R1bGV9LnBocD9pZD0iXSwgYVtocmVmKj0iP2lkPSJdLCBhW2hyZWYqPSImaWQ9Il1gKTsKICAgICAgICBpZihhbmNob3IpewogICAgICAgICAgaHJlZj1hbmNob3IuZ2V0QXR0cmlidXRlKCdocmVmJyk7CiAgICAgICAgICB0aXRsZT1hbmNob3IudGV4dENvbnRlbnQudHJpbSgpOwogICAgICAgIH0KICAgICAgfQoKICAgICAgaWYoaHJlZil7CiAgICAgICAgY29uc3QgbWF0Y2g9aHJlZi5tYXRjaChpZFJlZ2V4KTsKICAgICAgICBpZihtYXRjaCl7CiAgICAgICAgICBjb25zdCBpZD1wYXJzZUludChtYXRjaFsxXSwxMCk7CiAgICAgICAgICBpZighc2Vlbklkcy5oYXMoaWQpKXsKICAgICAgICAgICAgc2Vlbklkcy5hZGQoaWQpOwogICAgICAgICAgICBpZighdGl0bGUpewogICAgICAgICAgICAgIGNvbnN0IGFyaWFMYWJlbD10ci5nZXRBdHRyaWJ1dGUoJ2FyaWEtbGFiZWwnKTsKICAgICAgICAgICAgICBpZihhcmlhTGFiZWwmJmFyaWFMYWJlbC5zdGFydHNXaXRoKCdPdXZyaXIgJykpewogICAgICAgICAgICAgICAgdGl0bGU9YXJpYUxhYmVsLnNsaWNlKDcpOwogICAgICAgICAgICAgIH1lbHNlewogICAgICAgICAgICAgICAgdGl0bGU9dHIucXVlcnlTZWxlY3RvcignLk11c2ljYmFuZC1jZWxsLXZhbHVlLCB0ZCcpPy50ZXh0Q29udGVudD8udHJpbSgpfHwnJzsKICAgICAgICAgICAgICB9CiAgICAgICAgICAgIH0KICAgICAgICAgICAgaXRlbXMucHVzaCh7aWQsdXJsOmhyZWYsdGl0bGV9KTsKICAgICAgICAgIH0KICAgICAgICB9CiAgICAgIH0KICAgIH0pOwoKICAgIHJldHVybiBpdGVtczsKICB9CgogIGZ1bmN0aW9uIGhhbmRsZUxpc3RWaWV3KG1vZHVsZSl7CiAgICBmdW5jdGlvbiBzYXZlQ29udGV4dCgpewogICAgICBjb25zdCBpdGVtcz1leHRyYWN0TGlzdEl0ZW1zKG1vZHVsZSk7CiAgICAgIGlmKCFpdGVtc3x8aXRlbXMubGVuZ3RoPT09MClyZXR1cm47CiAgICAgIHNldFN0b3JlZENvbnRleHQobW9kdWxlLHsKICAgICAgICBtb2R1bGUsCiAgICAgICAgbGlzdFVybDp3aW5kb3cubG9jYXRpb24uaHJlZiwKICAgICAgICBzY3JvbGxZOndpbmRvdy5zY3JvbGxZfHx3aW5kb3cucGFnZVlPZmZzZXR8fDAsCiAgICAgICAgaXRlbXMsCiAgICAgICAgdXBkYXRlZEF0OkRhdGUubm93KCkKICAgICAgfSk7CiAgICB9CgogICAgY29uc3QgZXhpc3Rpbmc9Z2V0U3RvcmVkQ29udGV4dChtb2R1bGUpOwogICAgaWYoZXhpc3RpbmcmJmV4aXN0aW5nLmxpc3RVcmw9PT13aW5kb3cubG9jYXRpb24uaHJlZiYmdHlwZW9mIGV4aXN0aW5nLnNjcm9sbFk9PT0nbnVtYmVyJyYmZXhpc3Rpbmcuc2Nyb2xsWT4wKXsKICAgICAgcmVxdWVzdEFuaW1hdGlvbkZyYW1lKCgpPT57CiAgICAgICAgd2luZG93LnNjcm9sbFRvKHt0b3A6ZXhpc3Rpbmcuc2Nyb2xsWSxiZWhhdmlvcjonaW5zdGFudCd9KTsKICAgICAgfSk7CiAgICB9CgogICAgc2F2ZUNvbnRleHQoKTsKCiAgICBkb2N1bWVudC5hZGRFdmVudExpc3RlbmVyKCdjbGljaycsZXZlbnQ9PnsKICAgICAgY29uc3QgdGFyZ2V0TGluaz1ldmVudC50YXJnZXQuY2xvc2VzdChgYVtocmVmKj0iJHttb2R1bGV9LnBocD9pZD0iXSwgYVtocmVmKj0iP2lkPSJdLCB0cltkYXRhLXNvbmctaHJlZl1gKTsKICAgICAgaWYodGFyZ2V0TGluayl7CiAgICAgICAgc2F2ZUNvbnRleHQoKTsKICAgICAgfQogICAgfSx7Y2FwdHVyZTp0cnVlfSk7CgogICAgd2luZG93LmFkZEV2ZW50TGlzdGVuZXIoJ2JlZm9yZXVubG9hZCcsKCk9PnsKICAgICAgY29uc3QgY3VyPWdldFN0b3JlZENvbnRleHQobW9kdWxlKTsKICAgICAgaWYoY3VyJiZjdXIubGlzdFVybD09PXdpbmRvdy5sb2NhdGlvbi5ocmVmKXsKICAgICAgICBjdXIuc2Nyb2xsWT13aW5kb3cuc2Nyb2xsWXx8d2luZG93LnBhZ2VZT2Zmc2V0fHwwOwogICAgICAgIHNldFN0b3JlZENvbnRleHQobW9kdWxlLGN1cik7CiAgICAgIH0KICAgIH0pOwogIH0KCiAgZnVuY3Rpb24gc2V0dXBIZWFkZXJOYXYocHJldkl0ZW0sbmV4dEl0ZW0sY3VycmVudEluZGV4LHRvdGFsQ291bnQpewogICAgY29uc3QgaGVhZGVyPWRvY3VtZW50LnF1ZXJ5U2VsZWN0b3IoJy5NdXNpY2JhbmQtaGVhZGVyJyk7CiAgICBpZighaGVhZGVyKXJldHVybjsKCiAgICBjb25zdCBwYWdlSGVhZGVyPWRvY3VtZW50LnF1ZXJ5U2VsZWN0b3IoJy5NdXNpY2JhbmQtcGFnZS1oZWFkZXInKXx8aGVhZGVyOwogICAgcGFnZUhlYWRlci5jbGFzc0xpc3QuYWRkKCdoYXMtZGV0YWlsLW5hdicpOwogICAgaGVhZGVyLmNsYXNzTGlzdC5hZGQoJ2hhcy1kZXRhaWwtbmF2Jyk7CgogICAgbGV0IG5hdj1oZWFkZXIucXVlcnlTZWxlY3RvcignLk11c2ljYmFuZC1kZXRhaWwtbmF2Jyk7CiAgICBpZighbmF2KXsKICAgICAgbmF2PWRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ25hdicpOwogICAgICBuYXYuY2xhc3NOYW1lPSdNdXNpY2JhbmQtZGV0YWlsLW5hdic7CiAgICAgIG5hdi5zZXRBdHRyaWJ1dGUoJ2FyaWEtbGFiZWwnLCdOYXZpZ2F0aW9uIGVudHJlIGZpY2hlcycpOwogICAgICBjb25zdCBoZWxwTWVudT1oZWFkZXIucXVlcnlTZWxlY3RvcignLk11c2ljYmFuZC1oZWxwLW1lbnUnKTsKICAgICAgaWYoaGVscE1lbnUpewogICAgICAgIGhlYWRlci5pbnNlcnRCZWZvcmUobmF2LGhlbHBNZW51KTsKICAgICAgfWVsc2V7CiAgICAgICAgaGVhZGVyLmFwcGVuZENoaWxkKG5hdik7CiAgICAgIH0KICAgIH0KCiAgICBjb25zdCBwcmV2VGl0bGU9cHJldkl0ZW0KICAgICAgP2BGaWNoZSBwcsOpY8OpZGVudGUgKCR7Y3VycmVudEluZGV4fSAvICR7dG90YWxDb3VudH0pYCsocHJldkl0ZW0udGl0bGU/YCA6ICR7cHJldkl0ZW0udGl0bGV9YDonJykKICAgICAgOidEw6lidXQgZGUgc8OpbGVjdGlvbiDCtyBBdWN1bmUgZmljaGUgcHLDqWPDqWRlbnRlJzsKICAgIGNvbnN0IG5leHRUaXRsZT1uZXh0SXRlbQogICAgICA/YEZpY2hlIHN1aXZhbnRlICgke2N1cnJlbnRJbmRleCsyfSAvICR7dG90YWxDb3VudH0pYCsobmV4dEl0ZW0udGl0bGU/YCA6ICR7bmV4dEl0ZW0udGl0bGV9YDonJykKICAgICAgOidGaW4gZGUgc8OpbGVjdGlvbiDCtyBBdWN1bmUgZmljaGUgc3VpdmFudGUnOwoKICAgIG5hdi5pbm5lckhUTUw9YAogICAgICA8YSBjbGFzcz0iTXVzaWNiYW5kLWRldGFpbC1uYXYtYnRuIE11c2ljYmFuZC1kZXRhaWwtbmF2LXByZXYkeyFwcmV2SXRlbT8nIGlzLWRpc2FibGVkJzonJ30iCiAgICAgICAgIGhyZWY9IiR7cHJldkl0ZW0/ZXNjYXBlSHRtbChwcmV2SXRlbS51cmwpOicjJ30iCiAgICAgICAgIHJvbGU9ImJ1dHRvbiIKICAgICAgICAgYXJpYS1sYWJlbD0iJHtlc2NhcGVIdG1sKHByZXZUaXRsZSl9IgogICAgICAgICB0aXRsZT0iJHtlc2NhcGVIdG1sKHByZXZUaXRsZSl9IgogICAgICAgICAkeyFwcmV2SXRlbT8nYXJpYS1kaXNhYmxlZD0idHJ1ZSIgdGFiaW5kZXg9Ii0xIic6Jyd9PgogICAgICAgIDxzdmcgdmlld0JveD0iMCAwIDI0IDI0IiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSI+PHBhdGggZD0ibTE1IDE4LTYtNiA2LTYiLz48L3N2Zz4KICAgICAgPC9hPgogICAgICA8YSBjbGFzcz0iTXVzaWNiYW5kLWRldGFpbC1uYXYtYnRuIE11c2ljYmFuZC1kZXRhaWwtbmF2LW5leHQkeyFuZXh0SXRlbT8nIGlzLWRpc2FibGVkJzonJ30iCiAgICAgICAgIGhyZWY9IiR7bmV4dEl0ZW0/ZXNjYXBlSHRtbChuZXh0SXRlbS51cmwpOicjJ30iCiAgICAgICAgIHJvbGU9ImJ1dHRvbiIKICAgICAgICAgYXJpYS1sYWJlbD0iJHtlc2NhcGVIdG1sKG5leHRUaXRsZSl9IgogICAgICAgICB0aXRsZT0iJHtlc2NhcGVIdG1sKG5leHRUaXRsZSl9IgogICAgICAgICAkeyFuZXh0SXRlbT8nYXJpYS1kaXNhYmxlZD0idHJ1ZSIgdGFiaW5kZXg9Ii0xIic6Jyd9PgogICAgICAgIDxzdmcgdmlld0JveD0iMCAwIDI0IDI0IiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSI+PHBhdGggZD0ibTkgNiA2IDYtNiA2Ii8+PC9zdmc+CiAgICAgIDwvYT4KICAgIGA7CgogICAgbmF2LnF1ZXJ5U2VsZWN0b3JBbGwoJy5NdXNpY2JhbmQtZGV0YWlsLW5hdi1idG4nKS5mb3JFYWNoKGJ0bj0+ewogICAgICBidG4uYWRkRXZlbnRMaXN0ZW5lcignY2xpY2snLGV2ZW50PT57CiAgICAgICAgaWYoYnRuLmNsYXNzTGlzdC5jb250YWlucygnaXMtZGlzYWJsZWQnKXx8YnRuLmdldEF0dHJpYnV0ZSgnYXJpYS1kaXNhYmxlZCcpPT09J3RydWUnKXsKICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7CiAgICAgICAgfQogICAgICB9KTsKICAgICAgYnRuLmFkZEV2ZW50TGlzdGVuZXIoJ2tleWRvd24nLGV2ZW50PT57CiAgICAgICAgaWYoZXZlbnQua2V5PT09JyAnfHxldmVudC5rZXk9PT0nU3BhY2ViYXInKXsKICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7CiAgICAgICAgICBpZighYnRuLmNsYXNzTGlzdC5jb250YWlucygnaXMtZGlzYWJsZWQnKSl7CiAgICAgICAgICAgIGJ0bi5jbGljaygpOwogICAgICAgICAgfQogICAgICAgIH0KICAgICAgfSk7CiAgICB9KTsKCiAgICBuYXYuYWRkRXZlbnRMaXN0ZW5lcigna2V5ZG93bicsZXZlbnQ9PnsKICAgICAgaWYoZXZlbnQua2V5PT09J0Fycm93TGVmdCcpewogICAgICAgIGNvbnN0IHByZXZCdG49bmF2LnF1ZXJ5U2VsZWN0b3IoJy5NdXNpY2JhbmQtZGV0YWlsLW5hdi1wcmV2Jyk7CiAgICAgICAgaWYocHJldkJ0biYmIXByZXZCdG4uY2xhc3NMaXN0LmNvbnRhaW5zKCdpcy1kaXNhYmxlZCcpKXsKICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7CiAgICAgICAgICBwcmV2QnRuLmZvY3VzKCk7CiAgICAgICAgfQogICAgICB9ZWxzZSBpZihldmVudC5rZXk9PT0nQXJyb3dSaWdodCcpewogICAgICAgIGNvbnN0IG5leHRCdG49bmF2LnF1ZXJ5U2VsZWN0b3IoJy5NdXNpY2JhbmQtZGV0YWlsLW5hdi1uZXh0Jyk7CiAgICAgICAgaWYobmV4dEJ0biYmIW5leHRCdG4uY2xhc3NMaXN0LmNvbnRhaW5zKCdpcy1kaXNhYmxlZCcpKXsKICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7CiAgICAgICAgICBuZXh0QnRuLmZvY3VzKCk7CiAgICAgICAgfQogICAgICB9CiAgICB9KTsKICB9CgogIGZ1bmN0aW9uIHNldHVwU3dpcGVOYXYocHJldkl0ZW0sbmV4dEl0ZW0pewogICAgbGV0IHRvdWNoU3RhcnRYPTA7CiAgICBsZXQgdG91Y2hTdGFydFk9MDsKICAgIGxldCB0b3VjaFN0YXJ0VGltZT0wOwogICAgbGV0IHRvdWNoVmFsaWQ9ZmFsc2U7CgogICAgZnVuY3Rpb24gaXNJbnRlcmFjdGl2ZU9yU2Nyb2xsYWJsZSh0YXJnZXQpewogICAgICBpZighdGFyZ2V0KXJldHVybiBmYWxzZTsKICAgICAgaWYodGFyZ2V0LmNsb3Nlc3QoJ2lucHV0LCB0ZXh0YXJlYSwgc2VsZWN0LCBidXR0b24sIGEsIGxhYmVsLCBhdWRpbywgdmlkZW8sIGlmcmFtZSwgcHJlLCBjb2RlLCBbY29udGVudGVkaXRhYmxlPSJ0cnVlIl0sIFtyb2xlPSJidXR0b24iXSwgW3JvbGU9ImxpbmsiXSwgLk11c2ljYmFuZC1tZW51LCAuTXVzaWNiYW5kLWhlbHAtbWVudSwgLk11c2ljYmFuZC1zY3JvbGwtdG9wJykpewogICAgICAgIHJldHVybiB0cnVlOwogICAgICB9CiAgICAgIGxldCBjdXI9dGFyZ2V0OwogICAgICB3aGlsZShjdXImJmN1ciE9PWRvY3VtZW50LmJvZHkmJmN1ciE9PWRvY3VtZW50LmRvY3VtZW50RWxlbWVudCl7CiAgICAgICAgaWYoY3VyLmNsYXNzTGlzdC5jb250YWlucygnYnVzaW5lc3MtdGFibGUtc2Nyb2xsJyl8fGN1ci5jbGFzc0xpc3QuY29udGFpbnMoJ011c2ljYmFuZC1yZWxhdGVkLXNjcm9sbCcpKXsKICAgICAgICAgIHJldHVybiB0cnVlOwogICAgICAgIH0KICAgICAgICBjb25zdCBzdHlsZT13aW5kb3cuZ2V0Q29tcHV0ZWRTdHlsZShjdXIpOwogICAgICAgIGNvbnN0IG92ZXJmbG93WD1zdHlsZS5vdmVyZmxvd1g7CiAgICAgICAgaWYoKG92ZXJmbG93WD09PSdhdXRvJ3x8b3ZlcmZsb3dYPT09J3Njcm9sbCcpJiZjdXIuc2Nyb2xsV2lkdGg+Y3VyLmNsaWVudFdpZHRoKzIpewogICAgICAgICAgcmV0dXJuIHRydWU7CiAgICAgICAgfQogICAgICAgIGN1cj1jdXIucGFyZW50RWxlbWVudDsKICAgICAgfQogICAgICByZXR1cm4gZmFsc2U7CiAgICB9CgogICAgZG9jdW1lbnQuYWRkRXZlbnRMaXN0ZW5lcigndG91Y2hzdGFydCcsZXZlbnQ9PnsKICAgICAgaWYoZXZlbnQudG91Y2hlcy5sZW5ndGghPT0xKXsKICAgICAgICB0b3VjaFZhbGlkPWZhbHNlOwogICAgICAgIHJldHVybjsKICAgICAgfQoKICAgICAgY29uc3QgdG91Y2g9ZXZlbnQudG91Y2hlc1swXTsKICAgICAgY29uc3QgeD10b3VjaC5jbGllbnRYOwogICAgICBjb25zdCB5PXRvdWNoLmNsaWVudFk7CgogICAgICBjb25zdCBlZGdlTWFyZ2luPTI1OwogICAgICBpZih4PGVkZ2VNYXJnaW58fHg+d2luZG93LmlubmVyV2lkdGgtZWRnZU1hcmdpbil7CiAgICAgICAgdG91Y2hWYWxpZD1mYWxzZTsKICAgICAgICByZXR1cm47CiAgICAgIH0KCiAgICAgIGlmKGlzSW50ZXJhY3RpdmVPclNjcm9sbGFibGUoZXZlbnQudGFyZ2V0KSl7CiAgICAgICAgdG91Y2hWYWxpZD1mYWxzZTsKICAgICAgICByZXR1cm47CiAgICAgIH0KCiAgICAgIHRvdWNoU3RhcnRYPXg7CiAgICAgIHRvdWNoU3RhcnRZPXk7CiAgICAgIHRvdWNoU3RhcnRUaW1lPURhdGUubm93KCk7CiAgICAgIHRvdWNoVmFsaWQ9dHJ1ZTsKICAgIH0se3Bhc3NpdmU6dHJ1ZX0pOwoKICAgIGRvY3VtZW50LmFkZEV2ZW50TGlzdGVuZXIoJ3RvdWNoZW5kJyxldmVudD0+ewogICAgICBpZighdG91Y2hWYWxpZClyZXR1cm47CiAgICAgIHRvdWNoVmFsaWQ9ZmFsc2U7CgogICAgICBpZighZXZlbnQuY2hhbmdlZFRvdWNoZXN8fGV2ZW50LmNoYW5nZWRUb3VjaGVzLmxlbmd0aD09PTApcmV0dXJuOwogICAgICBjb25zdCB0b3VjaD1ldmVudC5jaGFuZ2VkVG91Y2hlc1swXTsKICAgICAgY29uc3QgZGVsdGFYPXRvdWNoLmNsaWVudFgtdG91Y2hTdGFydFg7CiAgICAgIGNvbnN0IGRlbHRhWT10b3VjaC5jbGllbnRZLXRvdWNoU3RhcnRZOwogICAgICBjb25zdCBkdXJhdGlvbj1EYXRlLm5vdygpLXRvdWNoU3RhcnRUaW1lOwoKICAgICAgaWYoZHVyYXRpb24+NjAwfHxkdXJhdGlvbjw0MClyZXR1cm47CiAgICAgIGNvbnN0IGFic1g9TWF0aC5hYnMoZGVsdGFYKTsKICAgICAgY29uc3QgYWJzWT1NYXRoLmFicyhkZWx0YVkpOwoKICAgICAgaWYoYWJzWDw2MClyZXR1cm47CiAgICAgIGlmKGFic1g8YWJzWSoxLjgpcmV0dXJuOwoKICAgICAgaWYoZGVsdGFYPjApewogICAgICAgIGlmKHByZXZJdGVtJiZwcmV2SXRlbS51cmwpewogICAgICAgICAgd2luZG93LmxvY2F0aW9uLmhyZWY9cHJldkl0ZW0udXJsOwogICAgICAgIH0KICAgICAgfWVsc2V7CiAgICAgICAgaWYobmV4dEl0ZW0mJm5leHRJdGVtLnVybCl7CiAgICAgICAgICB3aW5kb3cubG9jYXRpb24uaHJlZj1uZXh0SXRlbS51cmw7CiAgICAgICAgfQogICAgICB9CiAgICB9LHtwYXNzaXZlOnRydWV9KTsKCiAgICBkb2N1bWVudC5hZGRFdmVudExpc3RlbmVyKCd0b3VjaGNhbmNlbCcsKCk9PnsKICAgICAgdG91Y2hWYWxpZD1mYWxzZTsKICAgIH0se3Bhc3NpdmU6dHJ1ZX0pOwogIH0KCiAgZnVuY3Rpb24gaGFuZGxlRGV0YWlsVmlldyhtb2R1bGUscmVjb3JkSWQpewogICAgY29uc3QgY29udGV4dD1nZXRTdG9yZWRDb250ZXh0KG1vZHVsZSk7CiAgICBpZighY29udGV4dHx8IUFycmF5LmlzQXJyYXkoY29udGV4dC5pdGVtcyl8fGNvbnRleHQuaXRlbXMubGVuZ3RoPT09MCl7CiAgICAgIHJldHVybjsKICAgIH0KCiAgICBjb25zdCBjdXJyZW50SW5kZXg9Y29udGV4dC5pdGVtcy5maW5kSW5kZXgoaXRlbT0+aXRlbS5pZD09PXJlY29yZElkKTsKICAgIGlmKGN1cnJlbnRJbmRleD09PS0xKXsKICAgICAgcmV0dXJuOwogICAgfQoKICAgIGNvbnN0IHRvdGFsQ291bnQ9Y29udGV4dC5pdGVtcy5sZW5ndGg7CiAgICBjb25zdCBwcmV2SXRlbT1jdXJyZW50SW5kZXg+MD9jb250ZXh0Lml0ZW1zW2N1cnJlbnRJbmRleC0xXTpudWxsOwogICAgY29uc3QgbmV4dEl0ZW09Y3VycmVudEluZGV4PHRvdGFsQ291bnQtMT9jb250ZXh0Lml0ZW1zW2N1cnJlbnRJbmRleCsxXTpudWxsOwoKICAgIGlmKGNvbnRleHQubGlzdFVybCl7CiAgICAgIGNvbnN0IGxpbmtzPWRvY3VtZW50LnF1ZXJ5U2VsZWN0b3JBbGwoJ21haW4gYSwgLnRhc2tzLWNvbnRhaW5lciBhLCBkZXRhaWxzIGEsIHAgYScpOwogICAgICBsaW5rcy5mb3JFYWNoKGE9PnsKICAgICAgICBjb25zdCBocmVmPWEuZ2V0QXR0cmlidXRlKCdocmVmJyl8fCcnOwogICAgICAgIGlmKGhyZWYuaW5jbHVkZXMobW9kdWxlKycucGhwJykmJiFocmVmLmluY2x1ZGVzKCdpZD0nKSYmIWhyZWYuaW5jbHVkZXMoJ2VkaXQ9JykmJiFocmVmLmluY2x1ZGVzKCdhY3Rpb249JykpewogICAgICAgICAgY29uc3QgdGV4dD1hLnRleHRDb250ZW50LnRvTG93ZXJDYXNlKCk7CiAgICAgICAgICBpZih0ZXh0LmluY2x1ZGVzKCdsaXN0ZScpfHx0ZXh0LmluY2x1ZGVzKCdtb3JjZWF1eCcpfHx0ZXh0LmluY2x1ZGVzKCdyZXRvdXInKXx8dGV4dC5pbmNsdWRlcygn4oaQJyl8fHRleHQuaW5jbHVkZXMoJ3RvdXMnKSl7CiAgICAgICAgICAgIGEuc2V0QXR0cmlidXRlKCdocmVmJyxjb250ZXh0Lmxpc3RVcmwpOwogICAgICAgICAgfQogICAgICAgIH0KICAgICAgfSk7CiAgICB9CgogICAgc2V0dXBIZWFkZXJOYXYocHJldkl0ZW0sbmV4dEl0ZW0sY3VycmVudEluZGV4LHRvdGFsQ291bnQpOwogICAgc2V0dXBTd2lwZU5hdihwcmV2SXRlbSxuZXh0SXRlbSk7CiAgfQoKICBmdW5jdGlvbiBpbml0RGV0YWlsTmF2aWdhdGlvbigpewogICAgY29uc3QgbW9kdWxlPWRldGVjdE1vZHVsZSgpOwogICAgaWYoIW1vZHVsZSlyZXR1cm47CgogICAgY29uc3QgcmVjb3JkSWQ9Z2V0UmVjb3JkSWQoKTsKICAgIGlmKHJlY29yZElkIT09bnVsbCYmIWlzRWRpdE1vZGUoKSl7CiAgICAgIGhhbmRsZURldGFpbFZpZXcobW9kdWxlLHJlY29yZElkKTsKICAgIH1lbHNlIGlmKHJlY29yZElkPT09bnVsbCYmIWlzRWRpdE1vZGUoKSl7CiAgICAgIGhhbmRsZUxpc3RWaWV3KG1vZHVsZSk7CiAgICB9CiAgfQoKICAvKiA9PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09CiAgICogMy4gQWlkZSBNdXNpY2JhbmQgOiBvdXZlcnR1cmUgc3lzdMOpbWF0aXF1ZSBhdmVjIHJ1YnJpcXVlcyByZXBsacOpZXMKICAgKiA9PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09ICovCiAgZnVuY3Rpb24gaW5pdEhlbHBDb2xsYXBzZSgpewogICAgZG9jdW1lbnQuYWRkRXZlbnRMaXN0ZW5lcignY2xpY2snLGV2ZW50PT57CiAgICAgIGNvbnN0IHN1bW1hcnk9ZXZlbnQudGFyZ2V0LmNsb3Nlc3QoJy5NdXNpY2JhbmQtaGVscC1tZW51PnN1bW1hcnknKTsKICAgICAgaWYoc3VtbWFyeSl7CiAgICAgICAgY29uc3QgbWVudT1zdW1tYXJ5LnBhcmVudEVsZW1lbnQ7CiAgICAgICAgaWYobWVudSYmIW1lbnUub3Blbil7CiAgICAgICAgICBtZW51LnF1ZXJ5U2VsZWN0b3JBbGwoJy5NdXNpY2JhbmQtaGVscC1wYW5lbCBkZXRhaWxzW29wZW5dJykuZm9yRWFjaChkPT57ZC5vcGVuPWZhbHNlO30pOwogICAgICAgIH0KICAgICAgfQogICAgfSk7CiAgICBkb2N1bWVudC5xdWVyeVNlbGVjdG9yQWxsKCcuTXVzaWNiYW5kLWhlbHAtbWVudScpLmZvckVhY2gobWVudT0+ewogICAgICBtZW51LmFkZEV2ZW50TGlzdGVuZXIoJ3RvZ2dsZScsKCk9PnsKICAgICAgICBpZihtZW51Lm9wZW4pewogICAgICAgICAgbWVudS5xdWVyeVNlbGVjdG9yQWxsKCcuTXVzaWNiYW5kLWhlbHAtcGFuZWwgZGV0YWlsc1tvcGVuXScpLmZvckVhY2goZD0+e2Qub3Blbj1mYWxzZTt9KTsKICAgICAgICB9CiAgICAgIH0pOwogICAgfSk7CiAgfQoKICAvKiA9PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09CiAgICogNC4gSW5pdGlhbGlzYXRpb24gZXQgw6ljb3V0ZXVycyBnbG9iYXV4CiAgICogPT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PSAqLwogIGZ1bmN0aW9uIGluaXQoKXsKICAgIGluaXRTY3JvbGxUb3AoKTsKICAgIGluaXREZXRhaWxOYXZpZ2F0aW9uKCk7CiAgICBpbml0SGVscENvbGxhcHNlKCk7CiAgfQoKICBpZihkb2N1bWVudC5yZWFkeVN0YXRlPT09J2xvYWRpbmcnKXsKICAgIGRvY3VtZW50LmFkZEV2ZW50TGlzdGVuZXIoJ0RPTUNvbnRlbnRMb2FkZWQnLGluaXQse29uY2U6dHJ1ZX0pOwogIH1lbHNlewogICAgaW5pdCgpOwogIH0KCiAgd2luZG93LmFkZEV2ZW50TGlzdGVuZXIoJ3BhZ2VzaG93JywoKT0+ewogICAgaW5pdERldGFpbE5hdmlnYXRpb24oKTsKICB9KTsKCiAgZG9jdW1lbnQuYWRkRXZlbnRMaXN0ZW5lcigna2V5ZG93bicsZXZlbnQ9PnsKICAgIGlmKGV2ZW50LmtleT09PSdFc2NhcGUnKXsKICAgICAgZG9jdW1lbnQucXVlcnlTZWxlY3RvckFsbCgnLk11c2ljYmFuZC1tZW51W29wZW5dLC5NdXNpY2JhbmQtaGVscC1tZW51W29wZW5dJykuZm9yRWFjaChtZW51PT57CiAgICAgICAgbWVudS5vcGVuPWZhbHNlOwogICAgICAgIG1lbnUucXVlcnlTZWxlY3Rvcignc3VtbWFyeScpPy5mb2N1cygpOwogICAgICB9KTsKICAgIH0KICB9KTsKfSkoKTsK', true);
    if (($navUi === false || strlen($navUi) < 50) && is_file(__DIR__ . '/navigation_ui.js')) {
        $navUi = (string)file_get_contents(__DIR__ . '/navigation_ui.js');
    }
    $script = ($navUi !== false && $navUi !== '' ? $navUi : '') . "\n;\n" . ($script ?? '');
    if($headerNavigation===''&&MusicbandUser())$headerNavigation=base64_decode('PGxpbmsgcmVsPSJzdHlsZXNoZWV0IiBocmVmPSIuL2Nzcy9jb21tb24uY3NzP3Y9MjAyNi4xMC4yMi4yMi1jb21tdW5pdHkiPjxsaW5rIHJlbD0ic3R5bGVzaGVldCIgaHJlZj0iLi9jc3MvbmF2aWdhdGlvbi5jc3M/dj0yMDI2LjEwLjIyLjIyLWNvbW11bml0eSI+PGRldGFpbHMgY2xhc3M9Ik11c2ljYmFuZC1tZW51Ij48c3VtbWFyeSBhcmlhLWxhYmVsPSJPdXZyaXIgb3UgZmVybWVyIGxlIG1lbnUgZ8OpbsOpcmFsIj48c3ZnIHZpZXdCb3g9IjAgMCAyNCAyNCIgYXJpYS1oaWRkZW49InRydWUiIGZpbGw9Im5vbmUiIHN0cm9rZT0iY3VycmVudENvbG9yIiBzdHJva2Utd2lkdGg9IjEuNyIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48cGF0aCBkPSJNNCA2aDE2TTQgMTJoMTZNNCAxOGgxNiIvPjwvc3ZnPjwvc3VtbWFyeT48bmF2IGNsYXNzPSJNdXNpY2JhbmQtbGlua3MiIGFyaWEtbGFiZWw9Ik1lbnUgZ8OpbsOpcmFsIE11c2ljYmFuZCI+PGEgaHJlZj0iLi8iIGRhdGEtTXVzaWNiYW5kLW1vZHVsZT0iaG9tZSI+IDxzcGFuIGNsYXNzPSJNdXNpY2JhbmQtaWNvbiIgZGF0YS1NdXNpY2JhbmQtaWNvbj0iaG9tZSI+PHN2ZyB2aWV3Qm94PSIwIDAgMjQgMjQiIGFyaWEtaGlkZGVuPSJ0cnVlIiBmb2N1c2FibGU9ImZhbHNlIj48cGF0aCBkPSJNMyAxMCAxMiAzbDkgN3YxMWgtNnYtN0g5djdIM1oiLz48L3N2Zz48L3NwYW4+PHNwYW4+TXVzaWNiYW5kIFRvb2xib3g8L3NwYW4+PHNwYW4gY2xhc3M9Ik11c2ljYmFuZC1iZXRhIiBkYXRhLU11c2ljYmFuZC1iZXRhPSJob21lIiBoaWRkZW4+PC9zcGFuPjwvYT48YSBocmVmPSIuL3NldGxpc3QvIiBkYXRhLU11c2ljYmFuZC1tb2R1bGU9InNldGxpc3QiPiA8c3BhbiBjbGFzcz0iTXVzaWNiYW5kLWljb24iIGRhdGEtTXVzaWNiYW5kLWljb249InNldGxpc3QiPjxzdmcgdmlld0JveD0iMCAwIDI0IDI0IiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSI+PHBhdGggZD0iTTkgMThWNWwxMi0ydjEzTTkgOGwxMi0yTTkgMThhMyAzIDAgMSAxLTMtM2gzbTEyIDFhMyAzIDAgMSAxLTMtM2gzIi8+PC9zdmc+PC9zcGFuPjxzcGFuPlNldGxpc3Q8L3NwYW4+PHNwYW4gY2xhc3M9Ik11c2ljYmFuZC1iZXRhIiBkYXRhLU11c2ljYmFuZC1iZXRhPSJzZXRsaXN0IiBoaWRkZW4+PC9zcGFuPjwvYT48YSBocmVmPSIuL3NvbmdzLnBocCIgZGF0YS1NdXNpY2JhbmQtbW9kdWxlPSJzb25ncyI+IDxzcGFuIGNsYXNzPSJNdXNpY2JhbmQtaWNvbiIgZGF0YS1NdXNpY2JhbmQtaWNvbj0ic29uZ3MiPjxzdmcgdmlld0JveD0iMCAwIDI0IDI0IiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSI+PHBhdGggZD0iTTkgMThWNWwxMi0ydjEzTTkgOGwxMi0yTTkgMThhMyAzIDAgMSAxLTMtM2gzbTEyIDFhMyAzIDAgMSAxLTMtM2gzIi8+PC9zdmc+PC9zcGFuPjxzcGFuPk1vcmNlYXV4PC9zcGFuPjxzcGFuIGNsYXNzPSJNdXNpY2JhbmQtYmV0YSIgZGF0YS1NdXNpY2JhbmQtYmV0YT0ic29uZ3MiIGhpZGRlbj48L3NwYW4+PC9hPjxhIGhyZWY9Ii4vY29udGFjdHMucGhwIiBkYXRhLU11c2ljYmFuZC1tb2R1bGU9ImNvbnRhY3RzIj4gPHNwYW4gY2xhc3M9Ik11c2ljYmFuZC1pY29uIiBkYXRhLU11c2ljYmFuZC1pY29uPSJjb250YWN0cyI+PHN2ZyB2aWV3Qm94PSIwIDAgMjQgMjQiIGFyaWEtaGlkZGVuPSJ0cnVlIiBmb2N1c2FibGU9ImZhbHNlIj48cGF0aCBkPSJNNCAzaDE2djE4SDRaTTIgN2g0bS00IDVoNG0tNCA1aDRtNC0xMGEyIDIgMCAxIDAgNCAwIDIgMiAwIDAgMC00IDBtLTEgMTB2LTJhMyAzIDAgMCAxIDYgMHYyIi8+PC9zdmc+PC9zcGFuPjxzcGFuPkNvbnRhY3RzPC9zcGFuPjxzcGFuIGNsYXNzPSJNdXNpY2JhbmQtYmV0YSIgZGF0YS1NdXNpY2JhbmQtYmV0YT0iY29udGFjdHMiIGhpZGRlbj48L3NwYW4+PC9hPjxhIGhyZWY9Ii4vY29uY2VydHMucGhwIiBkYXRhLU11c2ljYmFuZC1tb2R1bGU9ImNvbmNlcnRzIj4gPHNwYW4gY2xhc3M9Ik11c2ljYmFuZC1pY29uIiBkYXRhLU11c2ljYmFuZC1pY29uPSJjb25jZXJ0cyI+PHN2ZyB2aWV3Qm94PSIwIDAgMjQgMjQiIGFyaWEtaGlkZGVuPSJ0cnVlIiBmb2N1c2FibGU9ImZhbHNlIj48cGF0aCBkPSJNNCA1aDE2djE2SDRabTMtM3Y2bTEwLTZ2Nk00IDEwaDE2bS0xMiA0aDJtNCAwaDJtLTggNGgyIi8+PC9zdmc+PC9zcGFuPjxzcGFuPkNvbmNlcnRzPC9zcGFuPjxzcGFuIGNsYXNzPSJNdXNpY2JhbmQtYmV0YSIgZGF0YS1NdXNpY2JhbmQtYmV0YT0iY29uY2VydHMiIGhpZGRlbj48L3NwYW4+PC9hPjxhIGhyZWY9Ii4vY2FsZW5kYXIucGhwIiBkYXRhLU11c2ljYmFuZC1tb2R1bGU9ImFnZW5kYSI+IDxzcGFuIGNsYXNzPSJNdXNpY2JhbmQtaWNvbiIgZGF0YS1NdXNpY2JhbmQtaWNvbj0iYWdlbmRhIj48c3ZnIHZpZXdCb3g9IjAgMCAyNCAyNCIgYXJpYS1oaWRkZW49InRydWUiIGZvY3VzYWJsZT0iZmFsc2UiPjxwYXRoIGQ9Ik00IDVoMTZ2MTZINFptMy0zdjZtMTAtNnY2TTQgMTBoMTZtLTEyIDRoMm00IDBoMm0tOCA0aDIiLz48L3N2Zz48L3NwYW4+PHNwYW4+QWdlbmRhPC9zcGFuPjxzcGFuIGNsYXNzPSJNdXNpY2JhbmQtYmV0YSIgZGF0YS1NdXNpY2JhbmQtYmV0YT0iYWdlbmRhIiBoaWRkZW4+PC9zcGFuPjwvYT48YSBocmVmPSIuL3Rhc2tzLnBocCIgZGF0YS1NdXNpY2JhbmQtbW9kdWxlPSJ0YXNrcyI+IDxzcGFuIGNsYXNzPSJNdXNpY2JhbmQtaWNvbiIgZGF0YS1NdXNpY2JhbmQtaWNvbj0idGFza3MiPjxzdmcgdmlld0JveD0iMCAwIDI0IDI0IiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSI+PHBhdGggZD0iTTkgMTFsMyAzTDIyIDRNMjEgMTJ2N2EyIDIgMCAwIDEtMiAySDVhMiAyIDAgMCAxLTItMlY1YTIgMiAwIDAgMSAyLTJoMTEiLz48L3N2Zz48L3NwYW4+PHNwYW4+R2VzdGlvbiBkZXMgdMOiY2hlczwvc3Bhbj48c3BhbiBjbGFzcz0iTXVzaWNiYW5kLWJldGEiIGRhdGEtTXVzaWNiYW5kLWJldGE9InRhc2tzIiBoaWRkZW4+PC9zcGFuPjwvYT48YSBocmVmPSIuL2FkbWluLnBocCIgZGF0YS1NdXNpY2JhbmQtYWRtaW49IjEiIGRhdGEtTXVzaWNiYW5kLW1vZHVsZT0iYWRtaW4iPiA8c3BhbiBjbGFzcz0iTXVzaWNiYW5kLWljb24iIGRhdGEtTXVzaWNiYW5kLWljb249ImFkbWluIj48c3ZnIHZpZXdCb3g9IjAgMCAyNCAyNCIgYXJpYS1oaWRkZW49InRydWUiIGZvY3VzYWJsZT0iZmFsc2UiPjxwYXRoIGQ9Ik00IDZoMTZNNCAxMmgxNk00IDE4aDE2TTggM3Y2bTggMHY2bS02IDB2NiIvPjwvc3ZnPjwvc3Bhbj48c3Bhbj5Db25maWd1cmF0aW9uPC9zcGFuPjxzcGFuIGNsYXNzPSJNdXNpY2JhbmQtYmV0YSIgZGF0YS1NdXNpY2JhbmQtYmV0YT0iYWRtaW4iIGhpZGRlbj48L3NwYW4+PC9hPjxhIGhyZWY9Ii4vYXVkaXQucGhwIiBkYXRhLU11c2ljYmFuZC1hZG1pbj0iMSIgZGF0YS1NdXNpY2JhbmQtbW9kdWxlPSJhdWRpdCI+IDxzcGFuIGNsYXNzPSJNdXNpY2JhbmQtaWNvbiIgZGF0YS1NdXNpY2JhbmQtaWNvbj0iYXVkaXQiPjxzdmcgdmlld0JveD0iMCAwIDI0IDI0IiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSI+PHBhdGggZD0iTTUgM2g5bDUgNXYxM0g1Wm05IDB2Nmg1TTggMTNoOG0tOCA0aDgiLz48L3N2Zz48L3NwYW4+PHNwYW4+QXVkaXQgdHJhaWw8L3NwYW4+PHNwYW4gY2xhc3M9Ik11c2ljYmFuZC1iZXRhIiBkYXRhLU11c2ljYmFuZC1iZXRhPSJhdWRpdCIgaGlkZGVuPjwvc3Bhbj48L2E+PGEgaHJlZj0iLi9pZGVhcy5waHAiIGRhdGEtTXVzaWNiYW5kLW1vZHVsZT0iaWRlYXMiPiA8c3BhbiBjbGFzcz0iTXVzaWNiYW5kLWljb24iIGRhdGEtTXVzaWNiYW5kLWljb249ImlkZWFzIj48c3ZnIHZpZXdCb3g9IjAgMCAyNCAyNCIgYXJpYS1oaWRkZW49InRydWUiIGZvY3VzYWJsZT0iZmFsc2UiPjxwYXRoIGQ9Im0xMiAzIDMgNiA3IDEtNSA1IDEgNy02LTMtNiAzIDEtNy01LTUgNy0xWiIvPjwvc3ZnPjwvc3Bhbj48c3Bhbj5Cb8OudGUgw6AgaWTDqWVzPC9zcGFuPjxzcGFuIGNsYXNzPSJNdXNpY2JhbmQtYmV0YSIgZGF0YS1NdXNpY2JhbmQtYmV0YT0iaWRlYXMiIGhpZGRlbj48L3NwYW4+PC9hPjxhIGhyZWY9Ii4vYWN0aXZhdGUucGhwP2NoYW5nZT0xIiBkYXRhLU11c2ljYmFuZC1tb2R1bGU9InBhc3N3b3JkIj4gPHNwYW4gY2xhc3M9Ik11c2ljYmFuZC1pY29uIiBkYXRhLU11c2ljYmFuZC1pY29uPSJwYXNzd29yZCI+PHN2ZyB2aWV3Qm94PSIwIDAgMjQgMjQiIGFyaWEtaGlkZGVuPSJ0cnVlIiBmb2N1c2FibGU9ImZhbHNlIj48cGF0aCBkPSJNNCA2aDE2TTQgMTJoMTZNNCAxOGgxNk04IDN2Nm04IDB2Nm0tNiAwdjYiLz48L3N2Zz48L3NwYW4+PHNwYW4+UHLDqWbDqXJlbmNlczwvc3Bhbj48c3BhbiBjbGFzcz0iTXVzaWNiYW5kLWJldGEiIGRhdGEtTXVzaWNiYW5kLWJldGE9InBhc3N3b3JkIiBoaWRkZW4+PC9zcGFuPjwvYT48YSBocmVmPSIuL2xvZ291dC5waHAiIGRhdGEtTXVzaWNiYW5kLW1vZHVsZT0ibG9nb3V0Ij4gPHNwYW4gY2xhc3M9Ik11c2ljYmFuZC1pY29uIiBkYXRhLU11c2ljYmFuZC1pY29uPSJsb2dvdXQiPjxzdmcgdmlld0JveD0iMCAwIDI0IDI0IiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSI+PHBhdGggZD0iTTkgM0g0djE4aDVtNS0xNCA1IDUtNSA1bS01LTVoMTQiLz48L3N2Zz48L3NwYW4+PHNwYW4+RMOpY29ubmV4aW9uPC9zcGFuPjxzcGFuIGNsYXNzPSJNdXNpY2JhbmQtYmV0YSIgZGF0YS1NdXNpY2JhbmQtYmV0YT0ibG9nb3V0IiBoaWRkZW4+PC9zcGFuPjwvYT48c3BhbiBjbGFzcz0iTXVzaWNiYW5kLXZlcnNpb24iPlZlcnNpb24gd2ViIDIwMjYuMTAuMjIuMjItY29tbXVuaXR5PC9zcGFuPjwvbmF2PjwvZGV0YWlscz48ZGV0YWlscyBjbGFzcz0iTXVzaWNiYW5kLWhlbHAtbWVudSI+PHN1bW1hcnkgYXJpYS1sYWJlbD0iQWlkZSIgdGl0bGU9IkFpZGUiPj88L3N1bW1hcnk+CjxkaXYgY2xhc3M9Ik11c2ljYmFuZC1oZWxwLXBhbmVsIiByb2xlPSJyZWdpb24iIGFyaWEtbGFiZWw9IkFpZGUgZGVzIG1vZHVsZXMgTXVzaWNiYW5kIj4KPGgyPlV0aWxpc2VyIE11c2ljYmFuZCBUb29sYm94PC9oMj4KPHA+Q2hvaXNpc3NleiB1bmUgcnVicmlxdWUuIEVuIGhhdXQgZGUgY2hhcXVlIHBhZ2UsIEluc3RydWN0aW9ucyByYXBwZWxsZSBsZXMgcHJlbWnDqHJlcyDDqXRhcGVzLjwvcD4KPGRldGFpbHM+PHN1bW1hcnk+Q29udGFjdHM8L3N1bW1hcnk+CjxwPlJldHJvdXZleiBsZXMgcGVyc29ubmVzIGV0IGxlcyBsaWV1eCBkYW5zIHZvdHJlIGNhcm5ldC4gVGFwZXogdW4gbm9tIG91IHVuIGxpZXUgZGFucyBSZWNoZXJjaGVyIDogbGEgbGlzdGUgc2UgbWV0IMOgIGpvdXIgdG91dGUgc2V1bGUuPC9wPgo8cD5DbGlxdWV6IHN1ciB1biBub20gcG91ciBvdXZyaXIgc2EgZmljaGUuIENvbG9ubmVzIGFmZmljaMOpZXMgcGVybWV0IGRlIGNob2lzaXIgbGVzIGluZm9ybWF0aW9ucyB2aXNpYmxlcyBkYW5zIGxlIHRhYmxlYXUuPC9wPgo8cD5TaSBsZXMgYm91dG9ucyBzb250IGRpc3BvbmlibGVzLCB1dGlsaXNleiBOb3V2ZWF1IGNvbnRhY3QgcG91ciBham91dGVyIHVuZSBwZXJzb25uZSBvdSBNb2RpZmllciBwb3VyIGNvcnJpZ2VyIHNhIGZpY2hlLiBDbGlxdWV6IHN1ciBFbnJlZ2lzdHJlciBwb3VyIGdhcmRlciBsZXMgY2hhbmdlbWVudHMsIG91IEFubnVsZXIgcG91ciByZXZlbmlyIHNhbnMgbGVzIGdhcmRlci48L3A+CjwvZGV0YWlscz4KPGRldGFpbHM+PHN1bW1hcnk+Q29uY2VydHM8L3N1bW1hcnk+CjxwPkxlcyBjb25jZXJ0cyDDoCB2ZW5pciBldCBsZXMgY29uY2VydHMgcGFzc8OpcyBzb250IHByw6lzZW50w6lzIGRhbnMgZGV1eCB0YWJsZWF1eC4gQ2V1eCBxdWkgbuKAmW9udCBwYXMgZGUgZGF0ZSBjb25udWUgcmVzdGVudCBkYW5zIERhdGVzIMOgIHByw6ljaXNlci48L3A+CjxwPlRhcGV6IGRhbnMgUmVjaGVyY2hlciBwb3VyIHJldHJvdXZlciB1biBjb25jZXJ0LiBDbGlxdWV6IHN1ciBzb24gbm9tIHBvdXIgbGlyZSBsZXMgZMOpdGFpbHMuIENvbG9ubmVzIGFmZmljaMOpZXMgcGVybWV0IGRlIGNob2lzaXIgbGVzIGluZm9ybWF0aW9ucyBkdSB0YWJsZWF1LjwvcD4KPHA+UG91ciBjcsOpZXIgb3UgbW9kaWZpZXIgdW4gY29uY2VydCwgdXRpbGlzZXogbGVzIGJvdXRvbnMgcHJvcG9zw6lzLiBSZW5zZWlnbmV6IGxlIG5vbSwgbGEgZGF0ZSwgbGUgbGlldSBldCBsZXMgaW5mb3JtYXRpb25zIHV0aWxlcy4gQ2hvaXNpc3NleiBsZSBjb250YWN0IGxpw6kgYXUgY29uY2VydCwgb3UgTm91dmVhdSBjbGllbnQgcG91ciBlbiBham91dGVyIHVuIHN1ciBwbGFjZS4gQ2xpcXVleiBzdXIgRW5yZWdpc3RyZXIgcG91ciBnYXJkZXIgbGEgZmljaGUuPC9wPgo8cD5EYW5zIGxhIGZpY2hlIGR1IGNvbmNlcnQsIGNob2lzaXNzZXogdW5lIHNldGxpc3QgZGFucyBsYSBsaXN0ZSBwdWlzIGNsaXF1ZXogc3VyIEFzc29jaWVyIGxhIHNldGxpc3QgYXUgY29uY2VydC4gRGlzc29jaWVyIHJldGlyZSBsZSBsaWVuIHNhbnMgc3VwcHJpbWVyIGxhIHNldGxpc3QuIFVuZSBzZXRsaXN0IGxpw6llIG5lIHBldXQgcGFzIMOqdHJlIHN1cHByaW3DqWUgOyBwb3VyIGxhIHLDqXV0aWxpc2VyIGFpbGxldXJzLCBmYWl0ZXMtZW4gdW5lIGNvcGllIHNhbnMgY29uY2VydCBhc3NvY2nDqS48L3A+CjwvZGV0YWlscz4KPGRldGFpbHM+PHN1bW1hcnk+RGV2aXMgLyBGYWN0dXJlczwvc3VtbWFyeT4KPHA+Q2hvaXNpc3NleiBEZXZpcyBvdSBGYWN0dXJlLCBwdWlzIGxlIGNsaWVudCBldCBsZSBjb25jZXJ0LiBTaSBsZSBjbGllbnQgbuKAmWV4aXN0ZSBwYXMgZW5jb3JlLCB1dGlsaXNleiBOb3V2ZWF1IGNsaWVudCBzYW5zIHF1aXR0ZXIgbGEgcGFnZS48L3A+CjxwPkxlIG5vbSBldCBs4oCZYWRyZXNzZSB2aWVubmVudCBkZSBsYSBmaWNoZSBkdSBjbGllbnQuIExhIGRhdGUgZGUgcHJlc3RhdGlvbiwgbGEgZHVyw6llLCBsZSBtb250YW50IGV0IGxlIG1vZGUgZGUgcsOoZ2xlbWVudCB2aWVubmVudCBkdSBjb25jZXJ0LiBQb3VyIGNvcnJpZ2VyIGNlcyBpbmZvcm1hdGlvbnMsIG1vZGlmaWV6IGxhIGZpY2hlIGNvbmNlcm7DqWUgcHVpcyBlbnJlZ2lzdHJleiDDoCBub3V2ZWF1IGxlIGRvY3VtZW50LjwvcD4KPHA+QXUgcHJlbWllciBlbnJlZ2lzdHJlbWVudCwgdW4gbnVtw6lybyBlc3QgZG9ubsOpIGF1dG9tYXRpcXVlbWVudCwgcGFyIGV4ZW1wbGUgRGV2aXMgMjAyNi0wMSBvdSBGYWN0dXJlIDIwMjYtMDEuIENoYXF1ZSB0eXBlIGEgc2EgcHJvcHJlIHN1aXRlIGRlIG51bcOpcm9zIHBvdXIgbOKAmWFubsOpZSBlbiBjb3Vycy4gTGUgbnVtw6lybyByZXN0ZSBsZSBtw6ptZSBsb3JzIGRlcyBtb2RpZmljYXRpb25zLjwvcD4KPHA+TGUgZG9jdW1lbnQgY29tbWVuY2UgZW4gUHLDqXBhcmF0aW9uLiBWw6lyaWZpZXogbGVzIGluZm9ybWF0aW9ucywgcHVpcyB1dGlsaXNleiBWYWxpZGVyLiBTaSB1bmUgY29ycmVjdGlvbiBlc3QgbsOpY2Vzc2FpcmUsIHJlbWV0dGV6LWxlIGVuIFByw6lwYXJhdGlvbi4gTGEgY3LDqWF0aW9uIGR1IFBERiBldCBs4oCZZW52b2kgYXUgY2xpZW50IG5lIHNvbnQgcGFzIGVuY29yZSBkaXNwb25pYmxlcy48L3A+CjwvZGV0YWlscz4KPGRldGFpbHM+PHN1bW1hcnk+U2V0bGlzdCDCtyBsZXMgbW9yY2VhdXggZHUgY29uY2VydDwvc3VtbWFyeT4KPHA+Q2hvaXNpc3NleiB1bmUgc2V0bGlzdCwgcHVpcyB1bmUgdmFyaWFudGUgOiBj4oCZZXN0IHVuZSBhdXRyZSB2ZXJzaW9uIGRlIGxhIG3Dqm1lIGxpc3RlLiBWw6lyaWZpZXogbOKAmW9yZHJlIGRlcyBtb3JjZWF1eCBldCBsYSBkdXLDqWUgdG90YWxlLjwvcD4KPHA+QWN0aXZleiBsZSBtb2RlIMOpZGl0aW9uIHBvdXIgY2hhbmdlciBsYSBsaXN0ZS4gRMOpcGxhY2V6IGxlcyBtb3JjZWF1eCBhdmVjIGxhIHBvaWduw6llIG91IGxlcyBmbMOoY2hlcyDihpEgZXQg4oaTLiBMZXMgYm91dG9ucyDihpIgUmFwcGVsIGV0IOKGkiBTZXQgZMOpcGxhY2VudCB1biBtb3JjZWF1IGVudHJlIGxhIHBhcnRpZSBwcmluY2lwYWxlIGV0IGxlIHJhcHBlbC48L3A+CjxwPkFmZmljaGVyIGxlcyBtb3JjZWF1eCBub24gc8OpbGVjdGlvbm7DqXMgb3V2cmUgbGEgcsOpc2VydmUuIExlIGJvdXRvbiArIGFqb3V0ZSB1biBtb3JjZWF1IDsgbGUgYm91dG9uIOKIkiBsZSByZXRpcmUgZXQgbGUgcmVtZXQgZGFucyBsYSByw6lzZXJ2ZSwgeSBjb21wcmlzIHPigJlpbCDDqXRhaXQgZGFucyBsZSByYXBwZWwuIENlcnRhaW5zIG1vcmNlYXV4IHJlc3RlbnQgZ3JvdXDDqXMgb3Ugw6AgdW5lIHBsYWNlIGltcG9zw6llLjwvcD4KPHA+RW5yZWdpc3RyZXIgY3LDqWUgdW5lIG5vdXZlbGxlIHZlcnNpb24gYXZlYyBsYSBkYXRlIGV0IHZvdHJlIG5vbS4gTGVzIHZlcnNpb25zIHByw6ljw6lkZW50ZXMgcmVzdGVudCBkaXNwb25pYmxlcy4gUsOpdGFibGlyIGxhIHZhcmlhbnRlIGFubnVsZSBsZXMgY2hhbmdlbWVudHMgbm9uIGVucmVnaXN0csOpcy4gRW5yZWdpc3RyZXogYXZhbnQgZGUgY2hhbmdlciBkZSBsaXN0ZSBvdSBkZSBxdWl0dGVyIGxhIHBhZ2UuPC9wPgo8cD5Qb3VyIGVtcG9ydGVyIGxhIGxpc3RlLCBjaG9pc2lzc2V6IEV4cG9ydCBUZW1wbywgRXhwb3J0IFNBQ0VNLCBFeHBvcnQgRXhjZWwgb3UgSW1wcmltZXIuIEzigJlleHBvcnQgU0FDRU0gY29udGllbnQgbGUgbGlldSBkdSBjb25jZXJ0LCBsZXMgdGl0cmVzIGV0IGxlcyBhcnRpc3Rlcywgc2FucyBzw6lwYXJlciBsZSByYXBwZWwuIElsIG7igJllc3QgcGFzIGVudm95w6kgw6AgbGEgU0FDRU0uIExlcyBleHBvcnRzIHJlcHJlbm5lbnQgbGEgbGlzdGUgYWZmaWNow6llLCBtw6ptZSBzaSB2b3MgZGVybmllcnMgY2hhbmdlbWVudHMgbmUgc29udCBwYXMgZW5jb3JlIGVucmVnaXN0csOpcy48L3A+CjxwPlN1ciBpUGhvbmUsIHV0aWxpc2V6IGxlcyBvcHRpb25zIGRlIHBhcnRhZ2UgZHUgbmF2aWdhdGV1ciBwb3VyIGdhcmRlciBsZSBmaWNoaWVyIGRhbnMgRmljaGllcnMuPC9wPgo8L2RldGFpbHM+CjxkZXRhaWxzPjxzdW1tYXJ5PlNvdW5kIMK3IFhSMTggwrcgbGVzIHLDqWdsYWdlcyBkdSBzb248L3N1bW1hcnk+CjxwPk91dnJleiB1biBmaWNoaWVyIGRlIHNjw6huZSAuc2NuIG91IHVuZSBzY8OobmUgZMOpasOgIGNvbnNlcnbDqWUuIFVuZSBzY8OobmUgY29udGllbnQgbGVzIHLDqWdsYWdlcyBkZSBsYSBjb25zb2xlLiBTdXIgaVBob25lLCBzaSBsZSBmaWNoaWVyIGVzdCBncmlzw6ksIGZhaXRlcy1lbiB1bmUgY29waWUgZXQgY2hhbmdleiBsYSBmaW4gZGUgc29uIG5vbSBlbiAudHh0LjwvcD4KPHA+Q2hvaXNpc3NleiBsYSBwaXN0ZSBldCBsYW5jZXogbOKAmWFuYWx5c2Ugc2FucyBJQSBwb3VyIGxpcmUgc2VzIHLDqWdsYWdlcy4gQ2hvaXNpc3NleiBlbnN1aXRlIGzigJlpbnN0cnVtZW50LCBkw6ljcml2ZXogbGUgcHJvYmzDqG1lIGVudGVuZHUgZXQgY2xpcXVleiBzdXIgQ29uc3RydWlyZSBsZXMgZXNzYWlzIHNhbnMgSUEuPC9wPgo8cD5Fc3NheWV6IGxlcyByw6lnbGFnZXMgcHJvcG9zw6lzIHN1ciBsYSBjb25zb2xlLCB1biBjaGFuZ2VtZW50IMOgIGxhIGZvaXMsIHB1aXMgw6ljb3V0ZXogbGUgcsOpc3VsdGF0LiBMYSBwYWdlIG5lIGNoYW5nZSBwYXMgbGVzIHLDqWdsYWdlcyBkZSBsYSBjb25zb2xlIMOgIHZvdHJlIHBsYWNlLjwvcD4KPHA+QXZlYyBOb3RlciBsZSByw6lzdWx0YXQgYXByw6hzIMOpY291dGUsIGluZGlxdWV6IHNpIGxlIHNvbiBlc3QgYW3DqWxpb3LDqSwgaW5jaGFuZ8OpIG91IGTDqWdyYWTDqSwgcHVpcyBlbnJlZ2lzdHJleiB2b3RyZSByZXRvdXIuPC9wPgo8cD5MZSBtZW51IEFjdGlvbnMgcGVybWV0IGTigJlvdXZyaXIgbGUgcmFwcG9ydCBQREYuIExlcyBhdXRyZXMgZXhwb3J0cyBwZXJtZXR0ZW50IGRlIGdhcmRlciBsZXMgcsOpZ2xhZ2VzIGV0IGxlcyBjb25zZWlscyBkYW5zIHVuIGZpY2hpZXIuIFN1ciBpUGhvbmUsIHV0aWxpc2V6IFBhcnRhZ2VyIHBvdXIgbOKAmWVucmVnaXN0cmVyIGRhbnMgRmljaGllcnMuPC9wPgo8cD5HYXJkZXogdm9zIGZpY2hpZXJzIGTigJlvcmlnaW5lIDogc2V1bGVzIGxlcyB0cm9pcyBzY8OobmVzIGxlcyBwbHVzIHLDqWNlbnRlcyBzb250IGNvbnNlcnbDqWVzIHN1ciBsZSBzaXRlLiBFbnJlZ2lzdHJleiBsZXMgcmFwcG9ydHMgdXRpbGVzIGF2YW50IGRlIHF1aXR0ZXIgbGEgcGFnZS48L3A+CjwvZGV0YWlscz4KPGRldGFpbHM+PHN1bW1hcnk+U2UgZMOpcGxhY2VyIGV0IHNlIGNvbm5lY3Rlcjwvc3VtbWFyeT4KPHA+TGUgbWVudSBnw6luw6lyYWwgcGVybWV0IGRlIHJldmVuaXIgw6AgbOKAmWFjY3VlaWwsIGRlIGNoYW5nZXIgZGUgbW9kdWxlIG91IGRlIHNlIGTDqWNvbm5lY3Rlci4gU2kgdm90cmUgY29ubmV4aW9uIGEgZXhwaXLDqSwgcmVjb25uZWN0ZXotdm91cy4gUGVuc2V6IMOgIGVucmVnaXN0cmVyIHZvcyBjaGFuZ2VtZW50cyBhdmFudCBkZSBxdWl0dGVyIHVuZSBwYWdlLjwvcD4KPC9kZXRhaWxzPgo8cD5DbGlxdWV6IGRlIG5vdXZlYXUgc3VyID8gcG91ciBmZXJtZXIgY2UgZ3VpZGUuIENoYXF1ZSBydWJyaXF1ZSBwZXV0IGF1c3NpIMOqdHJlIHJlcGxpw6llLjwvcD4KPC9kaXY+PC9kZXRhaWxzPg==');
    if($headerNavigation!=='')$headerNavigation=MusicbandUiHtml($headerNavigation);
    $nonce = base64_encode(random_bytes(18));
    $scriptPolicy = $script === null ? "" : " script-src 'nonce-" . $nonce . "';";
    $photonOrigin=$photonUrl===''?'':' '.parse_url($photonUrl,PHP_URL_SCHEME).'://'.parse_url($photonUrl,PHP_URL_HOST).(parse_url($photonUrl,PHP_URL_PORT) ? ':'.parse_url($photonUrl,PHP_URL_PORT) : '');
    header("Content-Security-Policy: default-src 'none'; frame-src 'self'; object-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; media-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; manifest-src 'self'; worker-src 'self';" . $scriptPolicy . ($connectSelf ? " connect-src 'self'".$photonOrigin.";" : ""));
    $content=MusicbandUiHtml($content);
    $title = MusicbandEscape($title);
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . ' · Musicband Toolbox</title>'.MusicbandPwaHead().MusicbandAppearanceHead($nonce).MusicbandStylesheet('auth').MusicbandStylesheet('common').MusicbandStylesheet('navigation');

    echo '<body class="Musicband-managed"><header class="Musicband-page-header Musicband-header"><a class="Musicband-home-logo" href="' . MusicbandEscape(MusicbandBase()) . 'index.php" aria-label="Retour à l’accueil"><img src="Musicband_LOGO_URI" alt="Musicband"></a><h1 title="' . $title . '">' . $title . '</h1>'.$headerNavigation.'</header><main>' . $content . '</main><footer class="Musicband-footer" >Version web 2026.10.22.23-community</footer>' . ($script === null ? '' : '<script nonce="' . $nonce . '">' . $script . '</script>') . '</body></html>';
    if (MusicbandUser()) {
        if (!function_exists('MusicbandNotificationsTriggerFallbackIfOverdue')) {
            @include_once __DIR__ . '/notifications.php';
        }
        if (function_exists('MusicbandNotificationsTriggerFallbackIfOverdue')) {
            MusicbandNotificationsTriggerFallbackIfOverdue();
        }
    }
}
