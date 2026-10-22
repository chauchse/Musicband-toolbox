<?php
/* Musicband LOGOUT: owned publication */
/* Confirmation de déconnexion du seul navigateur courant.
 * Dépend de auth.php ; la destruction de session exige un POST protégé par CSRF.
 */
require_once __DIR__ . '/auth.php'; MusicbandRequireLogin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { MusicbandCsrf(); MusicbandLogout(); header('Location: ' . MusicbandBase() . 'login.php', true, 303); exit; }
    catch (RuntimeException $e) { MusicbandFail('Formulaire expiré : rechargez la page.', 403); }
}
MusicbandScreen('Déconnexion', '<section><form method="post"><input type="hidden" name="csrf" value="' . MusicbandEscape($_SESSION['csrf']) . '"><p>Fermer votre session sur cet appareil ?</p><button>Se déconnecter</button></form></section>');
