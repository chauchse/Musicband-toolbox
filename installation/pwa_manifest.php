<?php
/* Musicband PWA MANIFEST: chemins du site configuré, aucune donnée privée. */
/* Produit le manifeste PWA avec les chemins issus de auth_config.php.
 * Réponse JSON publique de configuration d’installation, sans contenu métier ni secret.
 */
require_once __DIR__.'/auth_config.php';header('Content-Type: application/manifest+json; charset=utf-8');header('Cache-Control: no-cache');
$base=Musicband_BASE_PATH;
echo json_encode(['id'=>$base,'name'=>'Musicband Toolbox','short_name'=>'Musicband','lang'=>'fr','start_url'=>$base.'index.php','scope'=>$base,'display'=>'standalone','background_color'=>'#101010','theme_color'=>'#101010','icons'=>[['src'=>$base.'pwa_icon.php?size=192','sizes'=>'192x192','type'=>'image/png','purpose'=>'any'],['src'=>$base.'pwa_icon.php?size=512','sizes'=>'512x512','type'=>'image/png','purpose'=>'any'],['src'=>$base.'pwa_icon.php?size=maskable','sizes'=>'512x512','type'=>'image/png','purpose'=>'maskable']]],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
