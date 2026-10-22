<?php
/* Musicband SETLIST EXPORT STYLE: palette des cellules guitare de Seb et Excel.
 * Valeurs RGB historiques du PDF Seb ; conversion hexadécimale pour les cellules XLSX.
 * Aucune lecture de données ni effet de bord.
 */
function setlistGuitarPalette(): array {
    return ['fill'=>[0.85,0.85,0.85],'change'=>[0.7,0.14,0.18]];
}
function setlistExportHex(array $rgb): string {
    return implode('',array_map(fn($value)=>sprintf('%02X',(int)round($value*255)),$rgb));
}
