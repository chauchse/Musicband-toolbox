<?php
/* Musicband CONCERTS FIELDS: corrections locales, sources importées préservées. */
/* Helpers des champs source supplémentaires : lecture, affichage et corrections locales.
 * Appelé depuis business.php ; utilise PDO, les réglages de visibilité et l’historique métier.
 * Les valeurs importées restent séparées des corrections ; préparation du schéma par Upgrade explicite.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
function concertsFieldsReady(PDO $db): bool {
    $q=$db->query("SELECT DATA_TYPE,IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='concerts_evenements' AND column_name='extra_fields_json'"); $column=$q->fetch();
    if (!$column) return false;
    if ($column['DATA_TYPE']!=='longtext' || $column['IS_NULLABLE']!=='YES') throw new RuntimeException('Colonne de corrections personnalisée : vérifier le schéma 008.');
    return true;
}
function concertsFieldsSchema(array $settings): string {
    $db=MusicbandDatabase($settings);
    if (concertsFieldsReady($db)) return 'Schéma 008 déjà présent. Aucune donnée modifiée.';
    if (!documentsTableExists($db,'concerts_evenements')) throw new RuntimeException('Schéma Concerts 003 requis.');
    if (($_POST['confirm']??'')!=='yes' || ($_POST['confirm_database']??null)!==($settings['DB_NAME']??null)) throw new RuntimeException('Confirmer la sauvegarde et la base exacte.');
    $sql=base64_decode('',true);
    if (!$sql) throw new RuntimeException('Schéma 008 non embarqué : préparer les fichiers du site.');
    $db->exec($sql);
    if (!concertsFieldsReady($db)) throw new RuntimeException('Vérifier le résultat du schéma 008 avant reprise.');
    return 'Schéma 008 ajouté : corrections locales des champs Concerts, sans réécriture des sources.';
}
function concertsFieldsUpgradeForm(string $hidden): string {
    return '<section><h3>Édition complète des concerts · schéma 008</h3><p>Ajouter une colonne nullable de corrections locales. Les sources et les données existantes sont conservées. Sauvegarder la base avant modification.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="concert_fields_schema"><label>Nom exact de la base<input name="confirm_database" required autocomplete="off"></label><label class="upgrade-confirm"><input type="checkbox" name="confirm" value="yes" required> Sauvegarde et destination vérifiées.</label><button>Créer le schéma 008 · Champs Concerts</button></form></section>';
}
function concertsFieldsObject(?string $json): array {
    $data=json_decode($json??'{}',true,32,JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Champs supplémentaires invalides.');
    return $data;
}
/** Superpose les corrections locales aux valeurs importées pour la lecture ; ne réécrit pas la provenance source. */
function concertsFieldsValues(array $row): array {
    return array_replace(concertsFieldsObject($row['source_json']??null),concertsFieldsObject($row['extra_fields_json']??null));
}
function concertsFieldsText($value): string {
    return is_scalar($value) || $value===null?(string)$value:businessJson($value);
}
function concertsFieldsDisplay($value,string $name='',bool $imported=false): string {
    if ($value==='Y' || $value==='N') return '<input class="Musicband-checkbox" type="checkbox" disabled'.($value==='Y'?' checked':'').' aria-label="'.($value==='Y'?'Oui':'Non').'" >';
    $text=concertsFieldsText($value);
    if(preg_match('/^date(?:\b|_)/iu',$name)){$date=businessConcertDate($text,businessListSettings(),$imported);if($date!==null)$text=MusicbandDisplayDate($date);}
    elseif(preg_match('/^heure(?:\b|_)/iu',$name)){$time=businessTemporalValue('heure_arrivee',$text);if($time!==null)$text=substr($time,0,5);}
    elseif(preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:/D',$text))$text=MusicbandDisplayDate($text,true);
    return $text===''?'—':MusicbandEscape($text);
}
function concertsFieldsDefinitions(PDO $db): array {
    $definitions=[];
    foreach (fieldVisibilityRegistry($db,'concerts') as $key=>$label) {
        if (!str_starts_with($key,'source:')) continue;
        $name=substr($label,0,-strlen(' · source'));
        if ($name==='Row ID') continue;
        $definitions[$key]=['name'=>$name,'boolean'=>false];
    }
    $ready=concertsFieldsReady($db); $seen=[]; $other=[];
    foreach ($db->query('SELECT source_json'.($ready?',extra_fields_json':'').' FROM concerts_evenements ORDER BY id') as $row) {
        foreach (concertsFieldsValues($row) as $name=>$value) {
            $key=fieldVisibilitySourceKey('concerts',(string)$name);
            if (!isset($definitions[$key]) || $value===null || $value==='') continue;
            if ($value==='Y' || $value==='N') $seen[$key]=true; else $other[$key]=true;
        }
    }
    foreach ($definitions as $key=>&$definition) $definition['boolean']=isset($seen[$key]) && !isset($other[$key]);
    unset($definition); return $definitions;
}
function concertsFieldsCatalog(array $definitions): string {
    return hash('sha256',businessJson($definitions));
}
function concertsFieldsCheckbox(string $name,string $label,string $value,string $touch='', string $touchValue='0'): string {
    $empty=$value!== 'Y' && $value!=='N';
    return ($touch!==''?'<input type="hidden" name="'.$touch.'" value="'.$touchValue.'">':'')
        . '<input type="hidden" name="'.$name.'" value="N"><label class="Musicband-check-label" >'
        . '<input class="Musicband-checkbox" type="checkbox" name="'.$name.'" value="Y"'.($value==='Y'?' checked':'').($empty?' data-empty-boolean':'').($touch!==''?' data-boolean-touch="'.$touch.'"':'').' >'
        . MusicbandEscape($label).($empty?' <small>Non renseigné</small>':'').'</label>';
}
function concertsFieldsForm(PDO $db,array $row,bool $posted,bool $asParts=false): array|string {
    if (!concertsFieldsReady($db)) { $notice='<p class="notice">Créer le schéma 008 dans Upgrade pour modifier les champs supplémentaires. Les sources restent conservées.</p>'.businessSourceFields($db,'concerts',$row); return $asParts?['metadata'=>$notice,'fields'=>[]]:$notice; }
    $definitions=concertsFieldsDefinitions($db); $effective=concertsFieldsValues($row);
    $catalog=$posted?($_POST['extra_catalog']??''):concertsFieldsCatalog($definitions);
    $metadata='<input type="hidden" name="extra_catalog" value="'.MusicbandEscape(is_string($catalog)?$catalog:'').'">'; $parts=[];
    foreach ($definitions as $key=>$definition) {
        if (!fieldVisibilityVisible('concerts',$key)) continue;
        $hash=substr($key,7); $name=$definition['name'];
        $value=$posted?($_POST['extra'][$hash]??($definition['boolean']?'N':'')):($effective[$name]??'');
        $text=is_string($value)?$value:concertsFieldsText($value);
        if ($posted && ($definition['boolean'] || in_array($effective[$name]??null,['Y','N'],true)) && (($effective[$name]??'')==='') && $value==='N' && ($_POST['extra_touched'][$hash]??'0')!=='1') $text='';
        $html=''; $display=fieldDisplayLabel('concerts',$key,$name);
        if ($definition['boolean'] || in_array($effective[$name]??null,['Y','N'],true)) $html.=concertsFieldsCheckbox('extra['.$hash.']',$display,$text,'extra_touched['.$hash.']',$posted && ($_POST['extra_touched'][$hash]??'0')==='1'?'1':'0');
        else $html.='<label>'.MusicbandEscape($display).'<textarea name="extra['.$hash.']"'.(preg_match('/adresse/i',$name)?' data-photon-address':'').' maxlength="12000">'.MusicbandEscape($text).'</textarea></label>';
        $parts[$key]=$html;
    }
    return $asParts?['metadata'=>$metadata,'fields'=>$parts]:$metadata.fieldLayoutRender('concerts','edit',$parts);
}
function concertsFieldsSave(array $definitions,array $row): string {
    if (!is_string($_POST['extra_catalog']??null) || !hash_equals(concertsFieldsCatalog($definitions),$_POST['extra_catalog'])) throw new RuntimeException('La liste ou le type des champs a changé : conserver la saisie et recharger la fiche.');
    $posted=$_POST['extra']??[]; $touched=$_POST['extra_touched']??[];
    if (!is_array($posted) || !is_array($touched)) throw new RuntimeException('Champs supplémentaires invalides.');
    foreach (array_keys($posted) as $hash) if (!is_string($hash) || !isset($definitions['source:'.$hash]) || !fieldVisibilityVisible('concerts','source:'.$hash)) throw new RuntimeException('Champ supplémentaire inconnu ou masqué.');
    $source=concertsFieldsObject($row['source_json']??null); $edits=concertsFieldsObject($row['extra_fields_json']??null); $effective=array_replace($source,$edits);
    foreach ($definitions as $key=>$definition) {
        if (!fieldVisibilityVisible('concerts',$key)) continue;
        $hash=substr($key,7); $name=$definition['name']; $old=$effective[$name]??null;
        if (!array_key_exists($hash,$posted)) throw new RuntimeException('Formulaire incomplet : conserver la saisie et recharger.');
        $value=$posted[$hash];
        if (!is_string($value) || strlen($value)>12000 || str_contains($value,"\0")) throw new RuntimeException('Valeur supplémentaire trop longue ou invalide.');
        if ($definition['boolean'] || in_array($old,['Y','N'],true)) {
            if (!in_array($value,['Y','N'],true)) throw new RuntimeException('Une case doit valoir Y ou N.');
            if (($old===null || $old==='') && $value==='N' && ($touched[$hash]??'0')!=='1') continue;
        }
        if ($value===concertsFieldsText($old)) continue;
        if (is_array($old) && $value!=='') {
            try { $value=json_decode($value,true,32,JSON_THROW_ON_ERROR); }
            catch (Throwable $e) { throw new RuntimeException('Ce champ structuré doit contenir un JSON valide.'); }
            if (!is_array($value)) throw new RuntimeException('Conserver la structure JSON de ce champ.');
        }
        if ($value===($source[$name]??null)) unset($edits[$name]); else $edits[$name]=$value;
    }
    return json_encode((object)$edits,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
