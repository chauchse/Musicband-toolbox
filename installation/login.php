<?php
/* Musicband LOGIN: owned publication */
/* Connexion locale, installation initiale et accès au parcours Google.
 * Dépend de auth.php et google_auth_service.php ; utilise les comptes privés et quotas.
 * POST protégé par CSRF ; peut créer le premier administrateur ou ouvrir une session.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/google_auth_service.php';
try {
    $installed = (bool)MusicbandRead('users');
    if (MusicbandUser()) { header('Location: ' . MusicbandBase() . 'index.php', true, 303); exit; }
    $error = ($_GET['error'] ?? '') !== '' ? (string)$_GET['error'] : (($_GET['password'] ?? '') === 'updated' ? 'Mot de passe enregistré. Connectez-vous avec votre nouveau mot de passe.' : ($_SESSION['notice'] ?? ''));
    if (($_GET['activated'] ?? '') === 'google') $error = 'Invitation validée. Connectez-vous avec votre compte Google autorisé.';
    unset($_SESSION['notice']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            MusicbandCsrf();
            $login = trim((string)($_POST['login'] ?? '')); $email = MusicbandEmail($_POST['email'] ?? ''); $password = (string)($_POST['password'] ?? '');
            [$authenticatedLogin, $authenticatedAccount] = MusicbandLocked(function() use ($login, $email, $password, $installed) {
                $users = MusicbandRead('users');
                $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown'); $now = time();
                $attempts = array_filter(MusicbandRead('attempts'), fn($a) => $a['until'] > $now);
                if (($attempts[$key]['count'] ?? 0) >= Musicband_LOGIN_ATTEMPTS) throw new RuntimeException('Trop de tentatives. Réessayer dans quinze minutes.');
                if (!$installed && !$users) {
                    $code = (string)($_POST['setup_code'] ?? '');
                    if (!hash_equals(Musicband_SETUP_HASH, hash('sha256', $code))) {
                        $attempts[$key] = ['count' => ($attempts[$key]['count'] ?? 0) + 1, 'until' => $now + Musicband_LOGIN_WINDOW]; MusicbandWrite('attempts', array_slice($attempts, -2000, null, true));
                        throw new RuntimeException('Code d’installation invalide.');
                    }
                    MusicbandValidateCredentials($login, $password);
                    if ($email === '') throw new RuntimeException('Adresse e-mail requise pour la récupération du compte administrateur.');
                    if (!hash_equals($password, (string)($_POST['confirmation'] ?? ''))) throw new RuntimeException('Les mots de passe ne correspondent pas.');
                    $user = ['hash' => password_hash($password, PASSWORD_BCRYPT), 'admin' => true, 'email' => $email, 'revision' => bin2hex(random_bytes(16))];
                    MusicbandWrite('users', [$login => $user]); unset($attempts[$key]); MusicbandWrite('attempts', $attempts); return [$login, $user];
                }
                if (!isset($users[$login])) { $alias = MusicbandEmailLogin($users, MusicbandEmail($login)); if ($alias !== null) $login = $alias; }
                $user = $users[$login] ?? null;
                $valid = password_verify($password, $user['hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
                if (!$user || !MusicbandAccountIsActive($user) || !$valid) {
                    $attempts[$key] = ['count' => ($attempts[$key]['count'] ?? 0) + 1, 'until' => $now + Musicband_LOGIN_WINDOW]; MusicbandWrite('attempts', array_slice($attempts, -2000, null, true));
                    throw new RuntimeException('Identifiant ou mot de passe incorrect.');
                }
                unset($attempts[$key]); MusicbandWrite('attempts', $attempts); return [$login, $user];
            });
            // Les notifications de connexion peuvent acquérir leur propre verrou.
            MusicbandLogin($authenticatedLogin, $authenticatedAccount);
            header('Location: ' . MusicbandBase() . 'index.php', true, 303); exit;
        } catch (RuntimeException $e) { MusicbandAuditEvent('auth','login.failed','rejected',['reason'=>'credentials_or_validation'],is_string($_POST['login']??null)?$_POST['login']:null);$error = $e->getMessage(); }
        $installed = (bool)MusicbandRead('users');
    }
    $form = '<section>' . ($error ? '<p class="notice" role="alert">' . MusicbandEscape($error) . '</p>' : '');
    $form .= $installed ? '<p>Connectez-vous à votre espace Musicband.</p>' : '<p>Première installation : créez le compte administrateur avec le code conservé sur votre poste de publication.</p>';
    $form .= '<form method="post"><input type="hidden" name="csrf" value="' . MusicbandEscape($_SESSION['csrf']) . '">';
    if (!$installed) $form .= '<label for="setup_code">Code d’installation</label><input id="setup_code" name="setup_code" type="password" required autocomplete="off">';
    $form .= '<label for="login">Identifiant ou e-mail</label><input id="login" name="login" required maxlength="254" autocomplete="username" autocapitalize="none"><label for="password">Mot de passe</label><input id="password" name="password" type="password" required autocomplete="' . ($installed ? 'current-password' : 'new-password') . '">';
    if (!$installed) $form .= '<label for="email">E-mail de récupération</label><input id="email" name="email" type="email" required maxlength="254" autocomplete="email" inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false" placeholder="prenom@exemple.fr"><label for="confirmation">Confirmer le mot de passe</label><input id="confirmation" name="confirmation" type="password" required autocomplete="new-password"><p>Entre 12 et 72 octets. Ce compte pourra gérer les autres utilisateurs.</p>';
    $googleConfig = googleAuthSettings();
    $googleButton = '';
    if ($installed && $googleConfig['enabled']) {
        $googleSvg = '<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true"><path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.616z"/><path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.258c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z"/><path fill="#FBBC05" d="M3.964 10.707c-.18-.54-.282-1.117-.282-1.707s.102-1.167.282-1.707V4.961H.957C.347 6.175 0 7.55 0 9s.347 2.825.957 4.039l3.007-2.332z"/><path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.961L3.964 7.293C4.672 5.166 6.656 3.58 9 3.58z"/></svg>';
        $googleButton = '<div class="google-login-separator"><span>ou</span></div>'
            . '<p><a class="google-login-btn" href="' . MusicbandEscape(MusicbandBase() . 'google_callback.php?start=login') . '">'
            . $googleSvg . '<span>Se connecter avec Google</span></a></p>';
    }
    MusicbandScreen($installed ? 'Connexion' : 'Créer l’administrateur', $form . '<button>' . ($installed ? 'Se connecter' : 'Créer mon espace') . '</button></form>' . $googleButton . '</section>' . ($installed ? '<p><a href="' . MusicbandEscape(MusicbandBase()) . 'forgot.php">Mot de passe oublié ?</a></p>' : ''));
} catch (Throwable $e) { MusicbandFail('Stockage privé indisponible. Vérifier les permissions de l’hébergement.'); }
