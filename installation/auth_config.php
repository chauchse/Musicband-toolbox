<?php
/** Configuration d'exécution Community : charge uniquement le fichier privé de l'instance.
 * Dépend de community-storage.php ; ne crée ni compte ni schéma et ne contacte aucun service.
 * L'identité et les secrets restent séparés des sources et de la distribution publique.
 */
declare(strict_types=1);
// Politique Community fixe : les anciens réglages privés ne réactivent pas ces modules.
const MUSICBAND_COMMUNITY = true;
const MUSICBAND_COMMUNITY_DISABLED_MODULES = ['sound', 'devis_factures', 'expenses', 'accounting'];
const MUSICBAND_COMMUNITY_EXTERNAL_IMPORTS = false;
$entry = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
if (in_array($entry, array_map(static fn(string $file): string => __DIR__ . '/' . $file, ['google_callback.php','sound_api.php','devis_factures.php','documents_pdf.php','expenses.php','accounting.php','setlist_import.php']), true) || str_starts_with($entry, __DIR__ . '/sound/')) {
    http_response_code(404); exit('Module indisponible dans la version Community.');
}
require_once __DIR__ . '/community-storage.php';
try { $private = communityPrivateDirectory(false); }
catch (RuntimeException $e) { http_response_code(503); exit($e->getMessage()); }
if (!is_file($private . '/installed.lock') || !is_file($private . '/community.local.php')) {
    http_response_code(503); exit('Installation requise : ouvrir install.php à la racine de l’application.');
}
$instance = require $private . '/community.local.php';
if (!is_array($instance) || !is_string($instance['base_url'] ?? null)) { http_response_code(503); exit('Configuration privée invalide.'); }
$url = parse_url($instance['base_url']);
if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])) { http_response_code(503); exit('URL publique invalide.'); }
define('Musicband_BASE_PATH', $url['path'] ?? '/');
define('Musicband_PUBLIC_ORIGIN', 'https://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : ''));
define('Musicband_DISPLAY_TIMEZONE', $instance['timezone'] ?? 'Europe/Paris');
// Les paramètres facultatifs ne peuvent pas désactiver les protections de session.
putenv('SOUND_PRIVATE_DIRECTORY=' . $private);
putenv('Musicband_PHOTON_URL=' . ($instance['geocoding_url'] ?? 'off'));
putenv('COMMUNITY_WEATHER_ENABLED=' . (!empty($instance['weather_enabled']) ? '1' : '0'));
const Musicband_SETUP_HASH = ''; // Premier compte créé exclusivement par l'assistant verrouillé.
const Musicband_SESSION_SECONDS = 28800;
const Musicband_LOGIN_ATTEMPTS = 5;
const Musicband_LOGIN_WINDOW = 900;
const Musicband_INVITE_SECONDS = 1296000;
const Musicband_RESET_SECONDS = 3600;
const Musicband_MAIL_IP_LIMIT = 5;
const Musicband_MAIL_ACCOUNT_LIMIT = 3;
const Musicband_MAIL_ADMIN_LIMIT = 20;
const Musicband_MAX_PENDING_LINKS = 2000;

/** Identité configurable sans accepter d'URL externe ni de balise depuis le fichier privé. */
function communityBrand(string $html): string {
    global $instance;
    $name = htmlspecialchars((string)($instance['app_name'] ?? 'MusicBand Toolbox'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $logo = $instance['logo'] ?? '';
    if (!is_string($logo) || !preg_match('~^assets/[A-Za-z0-9_-]+\.(?:png|jpg|jpeg|webp)$~D', $logo)) $logo = '';
    $uri = $logo === '' ? 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" rx="8" fill="white"/><path d="M20 44V20l25-4v24M20 26l25-4" stroke="#333" stroke-width="4" fill="none"/></svg>') : Musicband_BASE_PATH . $logo;
    return str_replace(['Musicband_LOGO_URI','Musicband Toolbox','MusicBand Toolbox'], [$uri,$name,$name], $html);
}
// Les pages et exports utilisent déjà leurs en-têtes/CSP ; le filtre ne change que l'identité HTML.
ob_start(static fn(string $html): string => preg_match('/^\s*(?:<!doctype html>|<html\b)/i', $html) ? communityBrand($html) : $html);
