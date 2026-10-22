<?php
/* Musicband GOOGLE AUTH SERVICE: authentification hybride Google OIDC (OpenID Connect / PKCE).
 * Rôle :
 * - Gestion du flux OAuth 2.0 Authorization Code avec PKCE (S256) pour Google Sign-In.
 * - Validation cryptographique complète des ID tokens OIDC (signature RSA-SHA256, émetteur, audience, nonce, expiration, email_verified).
 * - Contrôle strict de la liste blanche d'adresses e-mail Google autorisées (allowlist par utilisateur local).
 * - Association sécurisée de comptes (account linking) via identifiant stable Google sub.
 * - Préservation intégrale de l'authentification locale par mot de passe et du modèle de session/RBAC existant.
 *
 * Dépendances internes :
 * - auth.php (sessions, sécurité, stockage privé MusicbandRead/MusicbandWrite, MusicbandEscape, MusicbandLogin)
 * - audit_trail.php (journalisation sécurisée des événements d'authentification)
 * - rbac.php (modèle de rôles et permissions)
 *
 * Dépendances externes : OpenSSL pour la vérification de signature JWKS, cURL pour les échanges Google token / JWKS.
 * Effets de bord : Lecture/écriture dans home_settings et users.json, création de sessions applicatives, écriture dans l'audit trail.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit_trail.php';

const GOOGLE_OIDC_ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];
const GOOGLE_OIDC_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_OIDC_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GOOGLE_OIDC_JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
const GOOGLE_OIDC_SCOPES = 'openid email profile';

/**
 * Récupère les paramètres de configuration du client OAuth Google.
 * Priorité : configuration privée sauvegardée dans home_settings, puis variables d'environnement.
 */
function googleAuthSettings(): array {
    $saved = MusicbandRead('home_settings')['google_auth'] ?? [];
    $defaultRedirect = (function_exists('site_public_origin') ? '' : '') . MusicbandBase() . 'google_callback.php';
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ? 'https' : 'http';
        $defaultRedirect = $scheme . '://' . $_SERVER['HTTP_HOST'] . MusicbandBase() . 'google_callback.php';
    }

    $clientId = (string)($saved['client_id'] ?? (getenv('GOOGLE_LOGIN_CLIENT_ID') ?: ''));
    $clientSecret = (string)($saved['client_secret'] ?? (getenv('GOOGLE_LOGIN_CLIENT_SECRET') ?: ''));
    $redirectUri = (string)($saved['redirect_uri'] ?? (getenv('GOOGLE_LOGIN_REDIRECT_URI') ?: $defaultRedirect));
    $enabled = !empty($saved['enabled']) || (getenv('GOOGLE_LOGIN_ENABLED') === 'true' || getenv('GOOGLE_LOGIN_ENABLED') === '1');

    return [
        'client_id'     => trim($clientId),
        'client_secret' => trim($clientSecret),
        'redirect_uri'  => trim($redirectUri),
        'enabled'       => !(defined('MUSICBAND_COMMUNITY') && MUSICBAND_COMMUNITY) && $enabled && $clientId !== '' && $clientSecret !== '',
        'revision'      => (string)($saved['revision'] ?? ''),
    ];
}

/**
 * Enregistre les paramètres globaux d'authentification Google (réservé aux administrateurs).
 */
function googleAuthSettingsSave(): string {
    $user = MusicbandRequireLogin();
    if (empty($user['admin'])) {
        throw new RuntimeException('Accès administrateur requis.');
    }

    $settings = MusicbandRead('home_settings');
    $old = $settings['google_auth'] ?? [];
    $oldRevision = (string)($old['revision'] ?? '');
    $postedRevision = (string)($_POST['google_auth_revision'] ?? '');

    if ($oldRevision !== '' && !hash_equals($oldRevision, $postedRevision)) {
        throw new RuntimeException('Configuration modifiée par un autre administrateur : recharger la page.');
    }

    $clientId = trim((string)($_POST['google_client_id'] ?? ''));
    $postedSecret = (string)($_POST['google_client_secret'] ?? '');
    $clientSecret = $postedSecret !== '' ? trim($postedSecret) : (string)($old['client_secret'] ?? (getenv('GOOGLE_LOGIN_CLIENT_SECRET') ?: ''));
    $redirectUri = trim((string)($_POST['google_redirect_uri'] ?? ''));
    $enabled = !empty($_POST['google_auth_enabled']);

    if ($enabled && ($clientId === '' || $clientSecret === '')) {
        throw new RuntimeException('L’activation de la connexion Google requiert un Client ID et un Client Secret valides.');
    }

    $newSettings = [
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'enabled'       => $enabled,
        'revision'      => bin2hex(random_bytes(16)),
    ];

    $settings['google_auth'] = $newSettings;
    MusicbandWrite('home_settings', $settings);

    return 'Configuration Authentification Google enregistrée avec succès.';
}

/**
 * Génère le formulaire d'administration de la configuration Authentification Google.
 */
function googleAuthSettingsForm(string $hidden): string {
    if (defined('MUSICBAND_COMMUNITY') && MUSICBAND_COMMUNITY) return '';
    $config = googleAuthSettings();
    $hasSecret = $config['client_secret'] !== '';
    return '<section><h2>Authentification Google (OIDC)</h2>'
        . '<p>Connexion hybride via Google OpenID Connect avec échange PKCE (S256). L’authentification locale par mot de passe reste toujours active et indépendante.</p>'
        . '<form method="post">' . $hidden
        . '<input type="hidden" name="action" value="google_auth_settings">'
        . '<input type="hidden" name="google_auth_revision" value="' . MusicbandEscape($config['revision']) . '">'
        . '<label>Client ID Google<input name="google_client_id" value="' . MusicbandEscape($config['client_id']) . '" placeholder="ex. 123456789-abc.apps.googleusercontent.com" autocomplete="off"></label>'
        . '<label>Client Secret Google<input name="google_client_secret" type="password" placeholder="' . ($hasSecret ? 'Secret configuré (laisser vide pour conserver)' : 'Client secret Google Cloud') . '" autocomplete="off"></label>'
        . '<label>URI de redirection autorisée<input name="google_redirect_uri" value="' . MusicbandEscape($config['redirect_uri']) . '" placeholder="https://votredomaine/google_callback.php" autocomplete="off"></label>'
        . '<p class="notice">Cette URI de redirection doit être enregistrée à l’identique dans la console Google Cloud.</p>'
        . '<label class="Musicband-check-label"><input type="checkbox" class="Musicband-checkbox" name="google_auth_enabled" value="1"' . ($config['enabled'] ? ' checked' : '') . '> Activer le bouton « Se connecter avec Google »</label>'
        . '<button>Enregistrer la configuration Google</button></form></section>';
}

/**
 * Génère une paire PKCE (code_verifier et code_challenge S256).
 */
function googleAuthPkce(): array {
    $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return ['verifier' => $verifier, 'challenge' => $challenge];
}

/**
 * Construit l'URL d'autorisation Google OAuth pour initier le flux OIDC.
 * Stocke l'état, le nonce et le code_verifier en session.
 */
function googleAuthBuildAuthorizationUrl(): string {
    $config = googleAuthSettings();
    if (!$config['enabled']) {
        throw new RuntimeException('La connexion Google n’est pas configurée ou activée.');
    }

    $state = bin2hex(random_bytes(32));
    $nonce = bin2hex(random_bytes(32));
    $pkce = googleAuthPkce();

    $_SESSION['google_oidc_flow'] = [
        'state'         => $state,
        'nonce'         => $nonce,
        'code_verifier' => $pkce['verifier'],
        'mode'          => 'login',
        'created_at'    => time(),
    ];

    $params = [
        'client_id'             => $config['client_id'],
        'redirect_uri'          => $config['redirect_uri'],
        'response_type'         => 'code',
        'scope'                 => GOOGLE_OIDC_SCOPES,
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => $pkce['challenge'],
        'code_challenge_method' => 'S256',
        'prompt'                => 'select_account',
    ];

    return GOOGLE_OIDC_AUTH_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Échange le code d'autorisation contre des jetons auprès du point d'accès Google Token.
 */
function googleAuthExchangeCode(string $code, string $verifier): array {
    $config = googleAuthSettings();
    $params = [
        'code'          => $code,
        'client_id'     => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri'  => $config['redirect_uri'],
        'grant_type'    => 'authorization_code',
        'code_verifier' => $verifier,
    ];

    $postData = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init(GOOGLE_OIDC_TOKEN_URL);
    if ($ch === false) {
        throw new RuntimeException('Extension PHP cURL requise pour l’authentification Google.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'User-Agent: Musicband-Toolbox-OIDC/1.0',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Échec de communication avec Google OAuth : ' . $curlError);
    }

    $data = json_decode((string)$response, true, 32);
    if (!is_array($data) || $httpCode !== 200 || empty($data['id_token'])) {
        $errorMsg = $data['error_description'] ?? ($data['error'] ?? 'Réponse Google invalide');
        throw new RuntimeException('Échange de jeton refusé par Google : ' . $errorMsg);
    }

    return $data;
}

/**
 * Récupère le jeu de clés publiques Google (JWKS) avec mise en cache locale (1 heure).
 */
function googleAuthGetJwks(): array {
    $cacheFile = MusicbandPrivate() . '/google_jwks_cache.json';
    $now = time();

    if (file_exists($cacheFile)) {
        $cached = @file_get_contents($cacheFile);
        if ($cached !== false) {
            $data = json_decode($cached, true, 32);
            if (is_array($data) && ($data['expires_at'] ?? 0) > $now && !empty($data['keys'])) {
                return $data['keys'];
            }
        }
    }

    $ch = curl_init(GOOGLE_OIDC_JWKS_URL);
    if ($ch === false) {
        return [];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body !== false && $code === 200) {
        $parsed = json_decode((string)$body, true, 32);
        if (is_array($parsed) && !empty($parsed['keys'])) {
            $save = ['expires_at' => $now + 3600, 'keys' => $parsed['keys']];
            @file_put_contents($cacheFile, json_encode($save, JSON_THROW_ON_ERROR), LOCK_EX);
            return $parsed['keys'];
        }
    }

    return [];
}

/**
 * Reconstruit une clé publique RSA PEM à partir des composantes modulus et exponent d'une JWK.
 */
function googleAuthJwkToPem(string $n64, string $e64): ?string {
    $n = rtrim(strtr($n64, '-_', '+/'), '=');
    $e = rtrim(strtr($e64, '-_', '+/'), '=');
    $modulus = base64_decode($n);
    $exponent = base64_decode($e);

    if ($modulus === false || $exponent === false) {
        return null;
    }

    // Encodage ASN.1 DER d'une clé RSA publique PKCS#1
    $encodeLength = function(int $len): string {
        if ($len < 0x80) return chr($len);
        if ($len <= 0xFF) return "\x81" . chr($len);
        return "\x82" . chr($len >> 8) . chr($len & 0xFF);
    };

    $encodeInteger = function(string $bytes) use ($encodeLength): string {
        if (ord($bytes[0]) > 0x7F) $bytes = "\x00" . $bytes;
        return "\x02" . $encodeLength(strlen($bytes)) . $bytes;
    };

    $rsaPubKey = "\x30" . $encodeLength(strlen($encodeInteger($modulus) . $encodeInteger($exponent))) . $encodeInteger($modulus) . $encodeInteger($exponent);

    // Encodage SubjectPublicKeyInfo (X.509)
    $algorithmIdentifier = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $publicKeyBitString = "\x03" . $encodeLength(strlen($rsaPubKey) + 1) . "\x00" . $rsaPubKey;
    $der = "\x30" . $encodeLength(strlen($algorithmIdentifier . $publicKeyBitString)) . $algorithmIdentifier . $publicKeyBitString;

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * Valide cryptographiquement et sémantiquement l'ID Token Google OIDC.
 * Vérifie : signature (JWKS RSA-SHA256), audience, émetteur, nonce, expiration, email_verified.
 */
function googleAuthValidateIdToken(string $idToken, string $expectedNonce, string $expectedAudience): array {
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        throw new RuntimeException('Format d’ID token invalide.');
    }

    [$headerB64, $payloadB64, $sigB64] = $parts;

    $headerJson = base64_decode(strtr($headerB64, '-_', '+/'), true);
    $payloadJson = base64_decode(strtr($payloadB64, '-_', '+/'), true);
    $sig = base64_decode(strtr($sigB64, '-_', '+/'), true);

    if (!$headerJson || !$payloadJson || !$sig) {
        throw new RuntimeException('Impossible de décoder les segments du jeton OIDC.');
    }

    $header = json_decode($headerJson, true, 32);
    $payload = json_decode($payloadJson, true, 32);

    if (!is_array($header) || !is_array($payload)) {
        throw new RuntimeException('Structure JSON du jeton OIDC corrompue.');
    }

    // 1. Validation de l'algorithme
    if (($header['alg'] ?? '') !== 'RS256') {
        throw new RuntimeException('Algorithme de signature non pris en charge : ' . ($header['alg'] ?? 'inconnu'));
    }

    // 2. Validation de l'émetteur
    $iss = (string)($payload['iss'] ?? '');
    if (!in_array($iss, GOOGLE_OIDC_ISSUERS, true)) {
        throw new RuntimeException('Émetteur Google OIDC non reconnu : ' . $iss);
    }

    // 3. Validation de l'audience
    $aud = (string)($payload['aud'] ?? '');
    if ($aud !== $expectedAudience) {
        throw new RuntimeException('Audience du jeton non conforme (client_id attendu).');
    }

    // 4. Validation temporelle
    $now = time();
    $exp = $payload['exp'] ?? null;
    if (!is_int($exp) || $exp <= $now) {
        throw new RuntimeException('Le jeton d’authentification Google a expiré.');
    }

    // 5. Validation du nonce
    $nonce = (string)($payload['nonce'] ?? '');
    if ($expectedNonce === '' || !hash_equals($expectedNonce, $nonce)) {
        throw new RuntimeException('Jeton de validation corrompu (nonce non concordant).');
    }

    if ($expectedAudience === '' || (isset($payload['azp']) && $payload['azp'] !== $expectedAudience)
        || (isset($payload['iat']) && (!is_int($payload['iat']) || $payload['iat'] > $now + 60))
        || !is_string($payload['sub'] ?? null) || trim($payload['sub']) === '' || strlen($payload['sub']) > 255
        || !is_string($payload['email'] ?? null) || MusicbandEmail($payload['email']) === '') {
        throw new RuntimeException('Identité ou destinataire du jeton Google invalide.');
    }

    // 6. Validation email_verified
    $emailVerified = $payload['email_verified'] ?? false;
    if ($emailVerified !== true && $emailVerified !== 'true' && $emailVerified !== 1) {
        throw new RuntimeException('L’adresse e-mail associée au compte Google n’est pas vérifiée.');
    }

    // 7. Validation de la signature cryptographique
    $kid = (string)($header['kid'] ?? '');
    $jwks = googleAuthGetJwks();
    $signatureVerified = false;

    if (!empty($jwks) && $kid !== '') {
        foreach ($jwks as $key) {
            if (($key['kid'] ?? '') === $kid && ($key['kty'] ?? '') === 'RSA') {
                $pem = googleAuthJwkToPem($key['n'] ?? '', $key['e'] ?? '');
                if ($pem !== null) {
                    $signedData = $headerB64 . '.' . $payloadB64;
                    $verifyResult = openssl_verify($signedData, $sig, $pem, OPENSSL_ALGO_SHA256);
                    if ($verifyResult === 1) {
                        $signatureVerified = true;
                        break;
                    }
                }
            }
        }
    }

    if (!$signatureVerified) {
        $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken));
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $tiBody = curl_exec($ch);
            $tiCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($tiBody !== false && $tiCode === 200) {
                $tiData = json_decode((string)$tiBody, true, 32);
                if (is_array($tiData) && ($tiData['aud'] ?? '') === $expectedAudience && ($tiData['sub'] ?? '') === ($payload['sub'] ?? '')) {
                    $signatureVerified = true;
                }
            }
        }
    }

    if (!$signatureVerified) {
        throw new RuntimeException('Signature cryptographique du jeton Google invalide.');
    }

    return $payload;
}

/** Contrôle les collisions, y compris les associations désactivées héritées, sans les migrer silencieusement. */
function googleAuthCheckUnique(array $users, string $targetLogin, string $email, string $sub = ''): void {
    foreach ($users as $login => $account) {
        if ((string)$login === $targetLogin) continue;
        $otherEmail = strtolower(trim((string)($account['google_allowed_email'] ?? '')));
        if (($email !== '' && $otherEmail === $email) || ($sub !== '' && (string)($account['google_sub'] ?? '') === $sub)) {
            throw new RuntimeException('Association Google en conflit avec un autre compte. Un administrateur doit la résoudre.');
        }
    }
}

/** Empreinte du seul accès Google : refuse une modification depuis un ancien formulaire administrateur. */
function googleAuthUserVersion(array $account): string {
    return hash('sha256', json_encode([
        $account['google_allowed_email'] ?? '', $account['google_login_enabled'] ?? false,
        $account['google_sub'] ?? '', $account['revision'] ?? '',
    ], JSON_THROW_ON_ERROR));
}

/** Statut de l’association administrée, sans proposer d’action de liaison personnelle. */
function googleAuthUserStatus($accountOrLogin): string {
    $users = is_string($accountOrLogin) ? MusicbandRead('users') : [];
    $account = is_string($accountOrLogin) ? ($users[$accountOrLogin] ?? []) : $accountOrLogin;
    if (is_string($accountOrLogin)) {
        try { googleAuthCheckUnique($users, $accountOrLogin, strtolower(trim((string)($account['google_allowed_email'] ?? ''))), (string)($account['google_sub'] ?? '')); }
        catch (RuntimeException $e) { return 'Conflit d’association à résoudre'; }
    }
    if (!is_array($account)) return 'Non autorisé';
    $email = (string)($account['google_allowed_email'] ?? '');
    if (empty($account['google_login_enabled'])) return $email !== '' || !empty($account['google_sub']) ? 'Révoqué / désactivé' : 'Non autorisé';
    if (MusicbandEmail($email) === '') return 'Adresse Google à renseigner';
    if (!MusicbandAccountIsActive($account)) return 'Invitation à accepter avant connexion Google';
    return !empty($account['google_sub']) ? 'Associé' : 'En attente de première connexion Google';
}

/** Autorisation administrateur sous son propre verrou ; ne jamais appeler depuis MusicbandLocked. */
function googleAuthAuthorizeUser(string $targetLogin, string $googleEmail, bool $enabled): void {
    MusicbandCsrf();
    $googleEmail = strtolower(trim($googleEmail));
    if (($enabled && $googleEmail === '') || ($googleEmail !== '' && MusicbandEmail($googleEmail) === '')) {
        throw new RuntimeException('Renseigner une adresse e-mail Google valide.');
    }
    MusicbandLocked(function() use ($targetLogin, $googleEmail, $enabled) {
        $admin = MusicbandRequireLogin();
        if (!$admin['admin']) throw new RuntimeException('Accès administrateur requis.');
        $users = MusicbandRead('users');
        $account = $users[$targetLogin] ?? null;
        if (!$account) throw new RuntimeException('Compte utilisateur introuvable.');
        if (!is_string($_POST['google_user_version'] ?? null) || !hash_equals(googleAuthUserVersion($account), $_POST['google_user_version'])) {
            throw new RuntimeException('Accès Google modifié : recharger Configuration.');
        }
        $replaceIdentity = ($_POST['google_replace_identity'] ?? '') === 'yes';
        googleAuthCheckUnique($users, $targetLogin, $googleEmail, $replaceIdentity ? '' : (string)($account['google_sub'] ?? ''));
        // Un changement d’adresse conserve le sub ; seul un remplacement explicitement confirmé le retire.
        if ($replaceIdentity) {
            unset($account['google_sub'], $account['google_linked_at'], $account['google_last_login_at']);
        }
        $account['google_allowed_email'] = $googleEmail;
        $account['google_login_enabled'] = $enabled;
        $account['revision'] = bin2hex(random_bytes(16));
        $users[$targetLogin] = $account;
        MusicbandRbacRequireAdministrator(MusicbandRbacDb(), $users);
        MusicbandWrite('users', $users);
        if ($replaceIdentity) MusicbandAuditEvent('auth', 'google.identity_replaced', 'success', ['target' => $targetLogin]);
        MusicbandAuditEvent('auth', 'google.authorized', 'success', ['target' => $targetLogin, 'enabled' => $enabled ? 'yes' : 'no']);
    });
}

/** Révocation exclusivement administrateur : conserver le sub pour empêcher une réassociation implicite. */
function googleAuthRevokeUser(string $targetLogin): void {
    MusicbandCsrf();
    MusicbandLocked(function() use ($targetLogin) {
        $admin = MusicbandRequireLogin();
        if (!$admin['admin']) throw new RuntimeException('Accès administrateur requis.');
        $users = MusicbandRead('users');
        $account = $users[$targetLogin] ?? null;
        if (!$account) throw new RuntimeException('Compte utilisateur introuvable.');
        if (!is_string($_POST['google_user_version'] ?? null) || !hash_equals(googleAuthUserVersion($account), $_POST['google_user_version'])) {
            throw new RuntimeException('Accès Google modifié : recharger Configuration.');
        }
        $users[$targetLogin]['google_login_enabled'] = false;
        $users[$targetLogin]['revision'] = bin2hex(random_bytes(16));
        MusicbandRbacRequireAdministrator(MusicbandRbacDb(), $users);
        MusicbandWrite('users', $users);
        MusicbandAuditEvent('auth', 'google.revoked', 'success', ['target' => $targetLogin]);
    });
}

/** Résout une identité OIDC déjà vérifiée ; aucun rôle, utilisateur ou autorisation n’est créé ici. */
function googleAuthResolveAccount(array $users, string $sub, string $email): string {
    $email = strtolower(trim($email));
    if ($sub === '' || MusicbandEmail($email) === '') throw new RuntimeException('Identité Google incomplète.');
    $matches = [];
    foreach ($users as $login => $account) {
        if ((string)($account['google_sub'] ?? '') === $sub || strtolower(trim((string)($account['google_allowed_email'] ?? ''))) === $email) {
            $matches[] = (string)$login;
        }
    }
    if (count($matches) !== 1) throw new RuntimeException('Compte Google non autorisé ou association ambiguë. Contacter un administrateur.');
    $login = $matches[0]; $account = $users[$login];
    if (empty($account['google_login_enabled']) || !MusicbandAccountIsActive($account)) {
        throw new RuntimeException('Connexion Google refusée : compte désactivé ou invitation non acceptée.');
    }
    $allowed = strtolower(trim((string)($account['google_allowed_email'] ?? '')));
    $bound = (string)($account['google_sub'] ?? '');
    if (!hash_equals($allowed, $email) || ($bound !== '' && !hash_equals($bound, $sub))) {
        throw new RuntimeException('Identité Google différente de l’association autorisée. Contacter un administrateur.');
    }
    googleAuthCheckUnique($users, $login, $email, $sub);
    return $login;
}

/** Lie le premier sub sous verrou puis ouvre la session ; une révocation concurrente invalide sa révision au prochain accès. */
function googleAuthLoginIdentity(string $sub, string $email): void {
    [$login, $account] = MusicbandLocked(function() use ($sub, $email) {
        $users = MusicbandRead('users');
        $login = googleAuthResolveAccount($users, $sub, $email);
        $account = $users[$login];
        $firstLogin = empty($account['google_sub']);
        if ($firstLogin) {
            $account['google_sub'] = $sub;
            $account['google_linked_at'] = gmdate('Y-m-d H:i:s UTC');
        }
        $account['google_last_login_at'] = gmdate('Y-m-d H:i:s UTC');
        $users[$login] = $account;
        MusicbandWrite('users', $users);
        if ($firstLogin) MusicbandAuditEvent('auth', 'google.linked', 'success', ['target' => $login]);
        return [$login, $account];
    });
    // MusicbandLogin peut planifier des notifications sous son propre verrou.
    MusicbandLogin($login, $account);
    MusicbandAuditEvent('auth', 'login.google.success', 'success', ['target' => $login], $login);
}

/**
 * Traite le retour OAuth OIDC Google (point d'entrée pour google_callback.php).
 */
function googleAuthProcessCallback(): void {
    $flow = $_SESSION['google_oidc_flow'] ?? null;
    unset($_SESSION['google_oidc_flow']);

    if (!is_array($flow) || empty($flow['state']) || empty($flow['nonce']) || empty($flow['code_verifier'])
        || ($flow['mode'] ?? 'login') !== 'login' || !is_int($flow['created_at'] ?? null)
        || $flow['created_at'] > time() || time() - $flow['created_at'] > 600) {
        MusicbandAuditEvent('auth', 'google.callback.failed', 'rejected', ['reason' => 'missing_flow_state']);
        header('Location: ' . MusicbandBase() . 'login.php?error=' . rawurlencode('Session d’authentification Google expirée ou invalide. Réessayez.'), true, 303);
        exit;
    }

    $state = (string)($_GET['state'] ?? '');
    if (!hash_equals($flow['state'], $state)) {
        MusicbandAuditEvent('auth', 'google.callback.failed', 'rejected', ['reason' => 'state_mismatch']);
        header('Location: ' . MusicbandBase() . 'login.php?error=' . rawurlencode('Contrôle de sécurité échoué (state invalide).'), true, 303);
        exit;
    }

    // Gestion des erreurs retournées directement par Google (ex. annulation utilisateur)
    if (!empty($_GET['error'])) {
        $err = $_GET['error'] === 'access_denied' ? 'access_denied' : 'provider_error';
        MusicbandAuditEvent('auth', 'google.callback.error', 'rejected', ['reason' => $err]);
        $msg = $err === 'access_denied' ? 'Connexion Google annulée.' : 'Connexion Google refusée par le fournisseur.';
        header('Location: ' . MusicbandBase() . 'login.php?error=' . rawurlencode($msg), true, 303);
        exit;
    }

    $code = (string)($_GET['code'] ?? '');
    if ($code === '') {
        MusicbandAuditEvent('auth', 'google.callback.failed', 'rejected', ['reason' => 'missing_code']);
        header('Location: ' . MusicbandBase() . 'login.php?error=' . rawurlencode('Code d’autorisation Google manquant.'), true, 303);
        exit;
    }

    $config = googleAuthSettings();

    $stage = 'token_exchange';
    try {
        // 1. Échange du code d'autorisation
        $tokens = googleAuthExchangeCode($code, $flow['code_verifier']);

        // 2. Validation cryptographique et des claims OIDC
        $stage = 'id_token_validation';
        $claims = googleAuthValidateIdToken($tokens['id_token'], $flow['nonce'], $config['client_id']);

        $googleSub = (string)($claims['sub'] ?? '');
        $googleEmail = strtolower(trim((string)($claims['email'] ?? '')));

        if ($googleSub === '' || $googleEmail === '') {
            throw new RuntimeException('Identité Google incomplète reçue.');
        }

        $stage = 'account_authorization';
        googleAuthLoginIdentity($googleSub, $googleEmail);
        header('Location: ' . MusicbandBase() . 'index.php', true, 303);
        exit;

    } catch (Throwable $e) {
        error_log('Musicband_google_callback_failed stage=' . $stage . ' type=' . get_class($e));
        MusicbandAuditEvent('auth', 'login.google.failed', 'error', ['reason' => $stage]);
        header('Location: ' . MusicbandBase() . 'login.php?error=' . rawurlencode('Connexion Google impossible (étape : ' . $stage . '). Contactez un administrateur ; si vous avez un mot de passe local, vous pouvez l’utiliser.'), true, 303);
        exit;
    }
}
