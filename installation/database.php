<?php
/* Musicband DATABASE: connexion explicite uniquement, aucun appel automatique. */
/* Fabrique PDO MySQL à partir des paramètres fournis par l’appelant.
 * Dépend de PDO/pdo_mysql ; valide hôte, port, base et éventuel certificat CA.
 * Ouvre une connexion seulement sur appel ; ne crée ni schéma ni données.
 */
declare(strict_types=1);

function MusicbandDatabase(array $settings): PDO {
    foreach (['DB_SERVER', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
        if (!is_string($settings[$key] ?? null) || $settings[$key] === '') throw new RuntimeException('Configuration MySQL incomplète.');
    }
    if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $settings['DB_SERVER']) || !preg_match('/^[A-Za-z0-9_$-]+$/D', $settings['DB_NAME'])) throw new RuntimeException('Configuration MySQL invalide.');
    $port = $settings['DB_PORT'] ?? '3306';
    if (!is_string($port) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) throw new RuntimeException('Port MySQL invalide.');
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('Extension PHP pdo_mysql requise.');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10];
    if (!empty($settings['DB_SSL_CA'])) {
        if (!is_readable($settings['DB_SSL_CA'])) throw new RuntimeException('Certificat MySQL inaccessible.');
        $options[PDO::MYSQL_ATTR_SSL_CA] = $settings['DB_SSL_CA'];
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    try {
        return new PDO('mysql:host=' . $settings['DB_SERVER'] . ';port=' . $port . ';dbname=' . $settings['DB_NAME'] . ';charset=utf8mb4',
            $settings['DB_USER'], $settings['DB_PASSWORD'], $options);
    } catch (PDOException $e) {
        // Classer l'échec sans reproduire le message fournisseur, qui peut
        // contenir hôte, nom de base, utilisateur ou détails de connexion.
        $code = (int)($e->errorInfo[1] ?? 0);
        $messages = [
            1044 => 'Accès à la base refusé : vérifier les droits de cet utilisateur sur la base choisie.',
            1045 => 'Authentification MySQL refusée : vérifier utilisateur, mot de passe et autorisation depuis cet hébergement.',
            1049 => 'Base MySQL inconnue : reprendre son nom exact dans l’administration OVH.',
            2002 => 'Serveur MySQL inaccessible : vérifier le nom complet, le port et l’accès réseau depuis l’hébergement PHP.',
            2003 => 'Connexion réseau MySQL impossible : vérifier serveur, port et autorisation depuis l’hébergement PHP.',
            2005 => 'Nom du serveur MySQL introuvable : reprendre le nom complet indiqué par OVH.',
            2026 => 'Connexion TLS MySQL refusée : vérifier le certificat CA et les paramètres du service.',
        ];
        $message = $messages[$code] ?? 'Connexion MySQL impossible : vérifier configuration, réseau et droits.';
        // Certains pilotes remontent la résolution DNS comme une erreur 2002.
        if ($code === 2002 && preg_match('/getaddrinfo|php_network_getaddresses|Name or service not known|nodename nor servname/i', $e->getMessage())) {
            $message = 'Nom du serveur MySQL non résolu : reprendre le nom complet indiqué par OVH.';
        }
        throw new RuntimeException($message . ($code > 0 ? ' [MySQL ' . $code . ']' : ''));
    }
}
