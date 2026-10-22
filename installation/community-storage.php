<?php
/** Résout le stockage privé Community sans configuration de chemin à saisir.
 * Partagé par install.php et auth_config.php ; dépend des chemins PHP du site.
 * Réutilise une installation historique hors racine publique, sinon crée un dossier dédié en 0700.
 * Aucun déplacement, suppression ou adoption de données exposées dans la racine web.
 */
declare(strict_types=1);

function communityPrivateDirectory(bool $create = true): string {
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($root === false || $root === DIRECTORY_SEPARATOR) throw new RuntimeException('Racine web non reconnue. Vérifier sa configuration auprès de l’hébergeur.');
    // Compatibilité des premières archives : un chemin déjà configuré reste valable,
    // sans imposer ce fichier aux nouvelles installations.
    if (is_file(__DIR__ . '/community-path.php')) require_once __DIR__ . '/community-path.php';
    $legacy = defined('COMMUNITY_PRIVATE_DIRECTORY') ? COMMUNITY_PRIVATE_DIRECTORY : dirname(__DIR__) . '/musicband-private';
    $automatic = dirname($root) . '/.musicband-' . substr(hash('sha256', __DIR__), 0, 16);
    $candidates = array_unique([$legacy, $automatic]);
    $chosen = null;
    foreach ($candidates as $candidate) {
        $path = realpath($candidate);
        if ($path === false) continue;
        $outside = $path !== $root && !str_starts_with($path . '/', $root . '/');
        $hasState = is_file($path . '/installation.json') || is_file($path . '/users.json') || is_file($path . '/installed.lock') || is_file($path . '/community.local.php');
        if ($hasState) {
            if (!$outside) throw new RuntimeException('Une ancienne installation existe dans le dossier public. Conserver ses fichiers : déplacement sécurisé nécessaire avant de poursuivre.');
            if ($chosen !== null && $chosen !== $path) throw new RuntimeException('Plusieurs installations privées détectées. Aucune sélection automatique pour éviter une perte de données.');
            $chosen = $path;
        }
    }
    if ($chosen === null) {
        $chosen = $automatic;
        if (!is_dir($chosen) && $create && !@mkdir($chosen, 0700)) throw new RuntimeException('Création automatique de l’espace privé impossible. L’hébergeur doit autoriser PHP à créer un dossier hors de la racine web.');
        if (!is_dir($chosen) && !$create) return $chosen;
    }
    clearstatcache(true, $chosen);
    $private = realpath($chosen);
    $permissions = $private === false ? false : @fileperms($private);
    if (!$private || $private === $root || str_starts_with($private . '/', $root . '/') || !is_dir($private) || !is_writable($private) || $permissions === false || ($permissions & 0777) !== 0700) {
        throw new RuntimeException('Espace privé indisponible ou permissions incompatibles. Aucun fichier existant n’a été déplacé ou supprimé.');
    }
    return $private;
}
