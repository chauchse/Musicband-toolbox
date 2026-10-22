<?php
/* Musicband DOWNLOAD: owned publication */
/* Route de lecture JSON après contrôle de session par auth.php ; anciens PDF Seb refusés.
 * Utilise le stockage Setlist SQL s’il est actif, sinon les fichiers autorisés sous setlist/.
 * La liste stricte de noms et le contrôle du chemin réel empêchent un téléchargement arbitraire.
 */
require_once __DIR__ . '/auth.php';
MusicbandRequireLogin(!str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')); session_write_close();
$file = $_GET['file'] ?? '';
if (!is_string($file) || !preg_match('/^(?:setlists\.json|setlist-[a-f0-9]{16}\.json|setlist-seb-[a-f0-9]{16}\.pdf)$/D', $file)) MusicbandFail('Fichier non autorisé.', 404);
// Les anciens fichiers restent conservés sur disque, mais l'export Seb n'est plus distribué.
if (str_ends_with($file, '.pdf')) MusicbandFail('Export Seb indisponible.', 404);
if(str_ends_with($file,'.json')&&setlistSqlActive()){
    try{$raw=setlistRaw($file);header('Content-Type: application/json; charset=utf-8');header('Content-Length: '.strlen($raw));echo $raw;exit;}
    catch(Throwable $e){MusicbandFail('Publication SQL indisponible.',503);}
}
$path = realpath(__DIR__ . '/setlist/' . $file); $directory = realpath(__DIR__ . '/setlist');
if (!$path || !$directory || dirname($path) !== $directory || !is_file($path)) MusicbandFail('Fichier absent.', 404);
header('Content-Type: ' . (str_ends_with($file, '.pdf') ? 'application/pdf' : 'application/json; charset=utf-8'));
header('Content-Length: ' . filesize($path)); header('Content-Disposition: inline; filename="' . $file . '"'); readfile($path);
