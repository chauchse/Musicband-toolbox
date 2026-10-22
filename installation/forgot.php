<?php
/* Musicband FORGOT: owned publication */
/* Demande de lien de réinitialisation via account_mail.php et le stockage privé.
 * POST protégé par CSRF et quotas IP/compte ; peut déclencher un e-mail.
 * Le message final ne révèle pas si un compte correspond à l’adresse fournie.
 */
require_once __DIR__ . '/account_mail.php';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        MusicbandCsrf(); $email = MusicbandEmail($_POST['email'] ?? '');
        if ($email === '') throw new RuntimeException('Saisir une adresse e-mail valide.');
        MusicbandLocked(function() use ($email) {
            $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $ipAllowed = MusicbandMailQuota('reset-ip-' . $ip, Musicband_MAIL_IP_LIMIT);
            $emailAllowed = MusicbandMailQuota('reset-email-' . hash('sha256', $email), Musicband_MAIL_ACCOUNT_LIMIT);
            if (!$ipAllowed || !$emailAllowed) return;
            $users = MusicbandRead('users'); $login = MusicbandEmailLogin($users, $email);
            if ($login !== null && MusicbandAccountIsActive($users[$login])) {
                try { MusicbandIssueLink('reset', $login, $email, $users[$login]['admin'], $users); }
                catch (Throwable $e) { error_log('Musicband_reset_delivery_failed'); }
            }
        });
        $message = 'Si un compte actif correspond à cette adresse, un lien de réinitialisation a été demandé. Vérifiez votre boîte mail et les indésirables. Le lien est valable une heure.';
    } catch (RuntimeException $e) { $message = $e->getMessage(); }
    catch (Throwable $e) { $message = 'Demande indisponible : réessayer plus tard.'; }
}
$content = '<section>' . ($message ? '<p class="notice" role="status">' . MusicbandEscape($message) . '</p>' : '')
         . '<p>Recevez un lien à usage unique pour choisir un nouveau mot de passe.</p><form method="post"><input type="hidden" name="csrf" value="' . MusicbandEscape($_SESSION['csrf']) . '"><label for="email">Adresse e-mail du compte</label><input id="email" name="email" type="email" required maxlength="254" autocomplete="email" inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false" placeholder="prenom@exemple.fr"><button>Demander le lien</button></form></section><p><a href="' . MusicbandEscape(MusicbandBase()) . 'login.php">← Retour à la connexion</a></p>';
MusicbandScreen('Mot de passe oublié', $content);
