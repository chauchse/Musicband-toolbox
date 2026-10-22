<?php
/** Lecteur Setlist authentifié ; le HTML reste hors du parseur PHP. */
require_once dirname(__DIR__).'/auth.php'; MusicbandRequireLogin(); session_write_close(); echo MusicbandUiHtml((string)file_get_contents(__DIR__.'/template.html'));
