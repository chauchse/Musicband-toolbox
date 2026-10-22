<?php
/* Musicband CONCERTS: module de consultation et édition authentifié. */
/* Point d’entrée léger : auth.php puis business.php portent session, droits, formulaires et stockage.
 * Délègue le traitement du module concerts à businessRun ; éviter de dupliquer ici sa logique.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/business.php';
businessRun('concerts');
