<?php
/* Musicband ACCOUNT MAIL: owned publication
 * Comptes invités et liens à usage unique : préparation/envoi sous le verrou du contrôleur, activation sous son propre verrou.
 * Dépend de auth.php, du RBAC et du service e-mail existant ; aucun mot de passe n’est généré/envoyé.
 * Précrée le compte avant envoi et conserve son état invité si le transport échoue.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/email_service.php';
// Politique portée par le code pour les nouvelles invitations, y compris sur un site déjà installé.
function MusicbandInviteSeconds(): int { return 15*86400; }
function MusicbandMailFrom(): string {
    $settings = MusicbandEmailSettings();
    $from = $settings['from'];
    if ($from === '') throw new RuntimeException('Configurer une adresse expéditrice autorisée dans la rubrique E-mails.');
    return $from;
}
function MusicbandMailQuota(string $key, int $maximum): bool {
    $now = time();
    $limits = array_filter(MusicbandRead('mail_limits'), fn($entry) => $entry['until'] > $now);
    $entry = $limits[$key] ?? ['count' => 0, 'until' => $now + Musicband_LOGIN_WINDOW];
    $allowed = $entry['count'] < $maximum; $entry['count']++;
    $limits[$key] = $entry; MusicbandWrite('mail_limits', array_slice($limits, -Musicband_MAX_PENDING_LINKS, null, true)); return $allowed;
}
/** Précrée ou retrouve un compte invité compatible ; l’appelant doit déjà détenir MusicbandLocked. */
function MusicbandPrepareInvitation(string $email, string $role, bool $allowGoogle, array $issuer): array {
    if (defined('MUSICBAND_COMMUNITY') && MUSICBAND_COMMUNITY && $allowGoogle) throw new RuntimeException('Les invitations Community utilisent un mot de passe local.');
    $actor = MusicbandRequireLogin();
    if (!$actor['admin'] || $actor['login'] !== $issuer['login'] || !MusicbandRbacComplete()) throw new RuntimeException('Administrateur RBAC requis.');
    $email = MusicbandEmail($email);
    if ($email === '') throw new RuntimeException('Adresse e-mail invalide.');
    $role = MusicbandRoleInput($role);
    $users = MusicbandRead('users'); $matches = [];
    foreach ($users as $login => $account) {
        if ((string)$login === $email || MusicbandEmail($account['email'] ?? '') === $email) $matches[] = (string)$login;
    }
    if (count($matches) > 1) throw new RuntimeException('Plusieurs comptes portent cette adresse : résoudre le conflit avant invitation.');
    if ($matches) {
        $login = $matches[0]; $account = $users[$login];
        if (($account['status'] ?? 'active') !== 'invited' || MusicbandEmail($account['email'] ?? '') !== $email || !empty($account['disabled'])) {
            throw new RuntimeException('Compte déjà actif ou incompatible. Utiliser sa fiche et la récupération du mot de passe.');
        }
        // Un renvoi ne modifie ni le rôle courant, ni une autorisation Google déjà administrée.
        return [$login, $users];
    }
    $legacy = [];
    foreach (MusicbandRead('account_links') as $hash => $entry) {
        if (($entry['purpose'] ?? '') === 'invite' && ($entry['login'] ?? '') === $email && ($entry['email'] ?? '') === $email
            && ($entry['expires_at'] ?? 0) > time() && ($entry['delivery_status'] ?? '') !== 'failed' && empty($entry['revision'])) $legacy[] = $hash;
    }
    if ($allowGoogle) {
        if (!function_exists('googleAuthCheckUnique')) require_once __DIR__ . '/google_auth_service.php';
        googleAuthCheckUnique($users, $email, $email);
    }
    // Une affectation SQL orpheline ne donne pas d’accès ; aucune transaction distribuée JSON/MySQL.
    MusicbandRbacAssign(MusicbandRbacDb(), 'account', $email, $role, $actor['login']);
    $users[$email] = ['email' => $email, 'hash' => '', 'admin' => $role === 'admin', 'status' => 'invited',
        'revision' => bin2hex(random_bytes(16)), 'invitation_revision' => bin2hex(random_bytes(16)),
        'invited_at' => time(), 'invited_by' => $actor['login'], 'legacy_invitation_hashes' => $legacy,
        'google_allowed_email' => $allowGoogle ? $email : '', 'google_login_enabled' => $allowGoogle];
    MusicbandWrite('users', $users);
    MusicbandAuditEvent('auth', 'account.invited', 'success', ['target' => $email]);
    return [$email, $users];
}

/** Valide le compte visé et conserve ses attributs ; compatibilité explicite avec les anciens liens sans compte précréé. */
function MusicbandInvitationAccount(array $entry, string $hash, array $users): array {
    $login = $entry['login']; $issuerLogin = $entry['issuer'] ?? '';
    $issuer = $users[$issuerLogin] ?? null;
    if (!$issuer || !MusicbandAccountIsActive($issuer) || MusicbandRbacRole($issuerLogin, $issuer) !== 'admin'
        || !is_string($entry['issuer_revision'] ?? null) || !hash_equals($issuer['revision'], $entry['issuer_revision'])) {
        throw new RuntimeException('Invitation révoquée : contacter un administrateur.');
    }
    foreach ($users as $key => $account) {
        if ((string)$key !== $login && MusicbandEmail($account['email'] ?? '') === $entry['email']) throw new RuntimeException('Adresse déjà utilisée par un autre compte.');
    }
    if (isset($users[$login])) {
        $account = $users[$login];
        $revisionMatches = is_string($entry['revision'] ?? null) && hash_equals((string)($account['invitation_revision'] ?? ''), $entry['revision']);
        $legacyMatches = empty($entry['revision']) && in_array($hash, $account['legacy_invitation_hashes'] ?? [], true);
        if (($account['status'] ?? 'active') !== 'invited' || !empty($account['disabled'])
            || MusicbandEmail($account['email'] ?? '') !== $entry['email'] || (!$revisionMatches && !$legacyMatches)) {
            throw new RuntimeException('Invitation remplacée ou compte déjà activé.');
        }
        $account['admin'] = MusicbandRbacRole($login, $account) === 'admin';
    } else {
        // Ancienne invitation valide : créer une seule fois à l’acceptation, sans autorisation Google implicite.
        if (!empty($entry['revision'])) throw new RuntimeException('Compte invité supprimé : demander une nouvelle invitation.');
        $role = MusicbandRbacInviteRole($hash);
        MusicbandRbacAssign(MusicbandRbacDb(), 'account', $login, $role, $issuerLogin);
        $account = ['admin' => $role === 'admin', 'email' => $entry['email']];
    }
    $account['status'] = 'active'; $account['activated_at'] = time();
    unset($account['invitation_revision'], $account['legacy_invitation_hashes'], $account['invitation_cancelled_at']);
    return $account;
}

/** Prépare un lien haché puis délègue l’envoi ; un échec laisse le compte invité et les anciens liens valides. */
function MusicbandIssueLink(string $purpose, string $login, string $email, bool $admin, array $users, ?array $issuer = null, ?string $role = null): void {
    if (!in_array($purpose, ['invite', 'reset'], true) || MusicbandEmail($email) === '') throw new RuntimeException('Demande de lien invalide.');
    if ($purpose === 'invite') {
        $actor = MusicbandRequireLogin();
        if (!$actor['admin'] || !$issuer || $issuer['login'] !== $actor['login']
            || ($users[$login]['status'] ?? '') !== 'invited' || MusicbandEmail($users[$login]['email'] ?? '') !== $email) {
            throw new RuntimeException('Créer le compte invité avant de préparer son invitation.');
        }
        $role = MusicbandRbacRole($login, $users[$login]);
    }
    $now = time(); $ttl = $purpose === 'invite' ? MusicbandInviteSeconds() : Musicband_RESET_SECONDS;
    $raw = bin2hex(random_bytes(32)); $hash = hash('sha256', $raw);
    $links = array_filter(MusicbandRead('account_links'), fn($entry) => $entry['expires_at'] > $now);
    $links[$hash] = ['purpose' => $purpose, 'login' => $login, 'email' => $email, 'admin' => $admin,
                    'revision' => $purpose === 'invite' ? ($users[$login]['invitation_revision'] ?? null) : ($users[$login]['revision'] ?? null), 'expires_at' => $now + $ttl,
                    'created_at' => $now, 'delivery_status' => 'prepared', 'delivery_error' => '',
                    'issuer' => $issuer['login'] ?? null, 'issuer_revision' => $issuer ? $users[$issuer['login']]['revision'] : null];
    if (count($links) > Musicband_MAX_PENDING_LINKS) throw new RuntimeException('Trop de liens en attente : réessayer plus tard.');
    if ($purpose==='invite') {
        if (!MusicbandRbacComplete()) throw new RuntimeException('Initialiser le schéma RBAC 010 avant les invitations.');
        MusicbandRbacAssign(MusicbandRbacDb(),'invite',$hash,MusicbandRoleInput($role??'lecture'),$issuer['login']??'');
    }
    MusicbandWrite('account_links', $links);
    if ($purpose === 'invite' && isset($users[$login]['invitation_cancelled_at'])) {
        unset($users[$login]['invitation_cancelled_at']); MusicbandWrite('users', $users);
    }
    // Le fragment n'est pas transmis dans les requêtes GET ni les journaux HTTP.
    $googleInvite = !(defined('MUSICBAND_COMMUNITY') && MUSICBAND_COMMUNITY) && $purpose === 'invite' && !empty($users[$login]['google_login_enabled'])
        && MusicbandEmail($users[$login]['google_allowed_email'] ?? '') === $email;
    $url = Musicband_PUBLIC_ORIGIN . MusicbandBase() . 'activate.php' . ($googleInvite ? '?google=1' : '') . '#token=' . $raw;
    $subject = $purpose === 'invite' ? 'Votre invitation Musicband Toolbox' : 'Réinitialiser votre mot de passe Musicband Toolbox';
    $body = "Bonjour,\n\n" . ($purpose === 'invite' ? 'Vous êtes invité à créer votre accès Musicband Toolbox.' : 'Une réinitialisation de votre mot de passe a été demandée.')
          . "\n\nIdentifiant : " . $login . ($googleInvite ? "\n\nValidez votre invitation, puis connectez-vous avec Google (aucun mot de passe local requis) :\n" : "\n\nChoisissez votre mot de passe :\n") . $url
          . "\n\nCe lien est à usage unique et valable " . ($purpose === 'invite' ? (int)($ttl / 86400) . ' jours' : (int)($ttl / 3600) . ' heure(s)') . ".\nSi vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail.\n";
    $deliveryError = '';
    try {
        $sendResult = MusicbandSendEmail([
            'to' => $email,
            'subject' => $subject,
            'text' => $body,
            'type' => $purpose,
        ]);
        $sent = ($sendResult['status'] === 'accepted');
        if (!empty($sendResult['message_id'])) {
            $links[$hash]['gmail_message_id'] = $sendResult['message_id'];
        }
    } catch (RuntimeException $e) { $sent = false; $deliveryError = $e->getMessage(); }
    catch (Throwable $e) { $sent = false; $deliveryError = 'Service d’envoi d’e-mails indisponible. Vérifier la configuration.'; }
    if (!$sent) {
        $links[$hash]['delivery_status'] = 'failed'; $links[$hash]['delivery_error'] = $deliveryError;
        MusicbandWrite('account_links', $links);
        MusicbandAuditEvent('auth', $purpose . '.delivery_failed', 'error', ['target' => $login]);
        error_log('Musicband_account_mail_failed');
        throw new RuntimeException(($purpose === 'invite' ? 'Invitation enregistrée, mais e-mail non envoyé. ' : 'Lien préparé, mais e-mail non envoyé. ')
                                   . $deliveryError . ' Les anciens liens restent inchangés.');
    }
    $links[$hash]['delivery_status'] = 'accepted';
    $links[$hash]['sent_at'] = time();
    MusicbandAuditEvent('auth', $purpose . '.delivery_accepted', 'accepted', ['target' => $login]);
    // Un renvoi réussi remplace les autres liens du même parcours et destinataire.
    $links = array_filter($links, fn($entry, $key) => $key === $hash || $entry['purpose'] !== $purpose || ($purpose === 'invite' ? $entry['email'] !== $email : $entry['login'] !== $login), ARRAY_FILTER_USE_BOTH);
    MusicbandWrite('account_links', $links);
}

/** Consomme un lien valide sous verrou, active le compte existant et révoque les autres liens/sessions. */
function MusicbandActivateAccount(string $token, string $password, bool $googleOnly = false): string {
    if (defined('MUSICBAND_COMMUNITY') && MUSICBAND_COMMUNITY) $googleOnly = false;
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new RuntimeException('Lien absent ou invalide.');
    return MusicbandLocked(function() use ($token, $password, $googleOnly) {
        $links = MusicbandRead('account_links'); $hash = hash('sha256', $token); $entry = $links[$hash] ?? null;
        if (!$entry || !in_array($entry['purpose'] ?? '', ['invite', 'reset'], true) || $entry['expires_at'] <= time() || ($entry['delivery_status'] ?? '') === 'failed') throw new RuntimeException('Lien expiré, remplacé ou déjà utilisé. Demander un nouveau lien.');
        $users = MusicbandRead('users'); $login = $entry['login'];
        if (!$googleOnly) MusicbandValidateCredentials($login, $password);
        if ($entry['purpose'] === 'invite') {
            $user = MusicbandInvitationAccount($entry, $hash, $users);
        } else {
            $user = $users[$login] ?? null;
            if (!$user || !MusicbandAccountIsActive($user) || MusicbandEmail($user['email'] ?? '') !== $entry['email'] || !hash_equals($user['revision'], $entry['revision'])) throw new RuntimeException('Lien révoqué ou compte modifié : demander un nouveau lien.');
        }
        // Le mode sans mot de passe exige une invitation et une autorisation Google courante.
        if ($googleOnly && ($entry['purpose'] !== 'invite' || empty($user['google_login_enabled'])
            || MusicbandEmail($user['google_allowed_email'] ?? '') !== $entry['email'])) {
            throw new RuntimeException('Cette invitation nécessite un mot de passe local.');
        }
        if (!$googleOnly) $user['hash'] = password_hash($password, PASSWORD_BCRYPT);
        $user['revision'] = bin2hex(random_bytes(16));
        $users[$login] = $user; MusicbandWrite('users', $users);
        // Une réutilisation est aussi empêchée par la révision/présence du compte,
        // même si la suppression du lien échoue après l’écriture du mot de passe.
        $links = array_filter($links, fn($item) => $item['login'] !== $login);
        MusicbandWrite('account_links', $links);
        MusicbandAuditEvent('auth',$entry['purpose']==='invite'?'account.activated':'password.reset','success',['target'=>$login],$login);
        return $login;
    });
}
