<?php
/** Accueil Community authentifié ; rendu commun et tableau de bord. */
require_once __DIR__.'/auth.php'; MusicbandRequireLogin(); session_write_close(); echo MusicbandHomeHtml((string)file_get_contents(__DIR__.'/home-template.html'));
