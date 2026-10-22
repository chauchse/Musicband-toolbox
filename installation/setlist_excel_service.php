<?php
/* Musicband SETLIST EXCEL SERVICE: colonnes et préférences XLSX personnelles.
 * Réutilise les champs/visibilité Morceaux, les liens SQL et user_preferences existant.
 * Valide les champs du formulaire ; aucune nouvelle table ni fichier de données.
 */
require_once __DIR__.'/auth.php';
require_once __DIR__.'/business.php';
require_once __DIR__.'/setlist_storage.php';

/** Même référentiel que les fiches Morceaux, cellules source comprises ; clés historiques conservées. */
function setlistExcelFields(): array {
    static $fields=null;if($fields!==null)return $fields;
    $db=MusicbandRbacDb();fieldVisibilityUse($db);
    $catalogue=businessConfig('songs')['fields'];
    foreach(fieldVisibilityRegistry($db,'songs') as $key=>$label)if(str_starts_with($key,'source:'))$catalogue[$key]=str_ends_with($label,' · source')?substr($label,0,-strlen(' · source')):$label;
    $catalogue=fieldLayoutOrder('songs','detail',$catalogue);
    $fields=['position'=>'Ordre','title'=>'Titre','guitar'=>'Guitare'];
    foreach($catalogue as $key=>$label){
        if(!fieldVisibilityVisible('songs',$key)&&!in_array($key,['title','guitar'],true))continue;
        $exportKey=$key==='musical_key'?'key':$key;
        $label=fieldDisplayLabel('songs',$key,$label);
        $fields[$exportKey]=$key==='catalogue_order'?$label.' (catalogue)':$label;
    }
    // Les intitulés obligatoires de l’export et son ordre de passage restent distincts du catalogue.
    $fields['title']='Titre';$fields['guitar']='Guitare';$fields['section']='Set / Rappel';
    return $fields;
}
/** Lecture groupée par liens explicites ; aucune correspondance par nom ou numéro de ligne. */
function setlistExcelCatalogueRows($file,array $songs): array {
    if($file===null||$file==='')return [];
    if(!is_string($file)||!preg_match('/^setlist-[a-f0-9]{16}\.json$/D',$file))throw new RuntimeException('Publication Excel invalide.');
    if(!setlistSqlActive())return [];
    $ids=[];foreach($songs as $song){
        $id=$song['editor_id']??null;if($id===null)continue;
        if(!is_string($id)||$id===''||strlen($id)>64)throw new RuntimeException('Repère de morceau invalide.');
        $ids[$id]=true;
    }
    if(!$ids)return [];
    $q=MusicbandRbacDb()->prepare('SELECT c.*,l.editor_id AS export_editor_id FROM setlist_song_links l JOIN setlist_songs c ON c.id=l.song_id WHERE l.source_file=? AND l.editor_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
    $q->execute([$file,...array_keys($ids)]);$rows=[];
    foreach($q as $row){
        $values=songRowValues($row);$source=json_decode($row['source_json'],true,64,JSON_THROW_ON_ERROR);
        foreach($source as $name=>$value)if(songSourceField($name)===null)$values[fieldVisibilitySourceKey('songs',$name)]=$value;
        $rows[$row['export_editor_id']]=$values;
    }
    return $rows;
}
function setlistExcelDefaults(): array {
    $fields=setlistExcelFields();
    return array_values(array_filter(['position','title','guitar','artist','duration_seconds'],fn($key)=>isset($fields[$key])));
}
/** Refuse les colonnes inconnues/dupliquées et la suppression des trois obligatoires. */
function setlistExcelValidate($columns): array {
    if(!is_array($columns)||!array_is_list($columns)||count($columns)>count(setlistExcelFields()))throw new RuntimeException('Liste de colonnes Excel invalide.');
    $seen=[];
    foreach($columns as $key){
        if(!is_string($key)||!isset(setlistExcelFields()[$key])||isset($seen[$key]))throw new RuntimeException('Colonne Excel inconnue ou dupliquée.');
        $seen[$key]=true;
    }
    foreach(['position','title','guitar'] as $key)if(!isset($seen[$key]))throw new RuntimeException('Ordre, Titre et Guitare sont obligatoires.');
    return $columns;
}
function setlistExcelPreferences(string $login): array {
    $all=MusicbandRead('user_preferences');$saved=$all[$login]['setlist_excel_columns']??null;
    if($saved===null)return setlistExcelDefaults();
    // Compatibilité des profils anciens ; aucune valeur persistée n’est utilisée sans validation.
    try{
        if(!is_array($saved)||!array_is_list($saved))return setlistExcelDefaults();
        // Une colonne désormais masquée disparaît seule ; les autres choix et leur ordre survivent.
        $fields=setlistExcelFields();$saved=array_values(array_filter($saved,fn($key)=>is_string($key)&&isset($fields[$key])));
        return setlistExcelValidate($saved);
    }catch(RuntimeException $e){return setlistExcelDefaults();}
}
/** Valide toutes les positions, même celles des colonnes non sélectionnées. */
function setlistExcelPostedColumns(array $input): array {
    $positions=$input['positions']??null;$enabled=$input['excel_columns']??null;$fields=setlistExcelFields();
    if(!is_array($positions)||count($positions)!==count($fields)||array_diff(array_keys($positions),array_keys($fields))||!is_array($enabled))throw new RuntimeException('Configuration Excel incomplète.');
    $ranks=[];
    foreach($positions as $key=>$rank){
        if(!is_string($rank)||!ctype_digit($rank)||(int)$rank<1||(int)$rank>count($fields)||isset($ranks[(int)$rank]))throw new RuntimeException('Positions Excel invalides ou dupliquées.');
        $ranks[(int)$rank]=$key;
    }
    setlistExcelValidate($enabled);ksort($ranks);
    return array_values(array_filter($ranks,fn($key)=>in_array($key,$enabled,true)));
}
function setlistExcelSavePreferences(string $login,array $columns): void {
    $columns=setlistExcelValidate($columns);
    MusicbandLocked(function() use($login,$columns){
        $all=MusicbandRead('user_preferences');$current=$all[$login]??[];
        if(!is_array($current))$current=[];
        $current['setlist_excel_columns']=$columns;$all[$login]=$current;
        MusicbandWrite('user_preferences',$all);
    });
}
/** Même contrat data-navigation-order que Configuration, avec alternative clavier/sans JS. */
function setlistExcelPreferencesForm(string $login,string $notice): string {
    $fields=setlistExcelFields();$selected=setlistExcelPreferences($login);
    $order=array_merge($selected,array_values(array_diff(array_keys($fields),$selected)));
    $html='<details class="preferences-section field-module" id="preferences-excel"'.($notice!==''?' open':'').'><summary>Setlist Excel Export</summary><p>Choisissez vos colonnes puis déplacez-les avec la poignée ou les flèches. Ordre, Titre et Guitare restent obligatoires. Les modifications sont enregistrées automatiquement.</p><form method="post" action="'.MusicbandEscape(MusicbandBase()).'activate.php?change=1" data-navigation-order data-excel-preferences><input type="hidden" name="csrf" value="'.MusicbandEscape($_SESSION['csrf']).'"><input type="hidden" name="action" value="save_excel_columns"><div data-navigation-items>';
    foreach($order as $index=>$key){
        $mandatory=in_array($key,['position','title','guitar'],true);
        $html.='<div class="navigation-order-item" data-navigation-item><strong data-drag-label>'.MusicbandEscape($fields[$key]).'</strong><label class="Musicband-check-label"><input type="checkbox" name="excel_columns[]" value="'.$key.'"'.(in_array($key,$selected,true)?' checked':'').($mandatory?' disabled':'').'> Inclure'.($mandatory?' (obligatoire)':'').'</label>';
        if($mandatory)$html.='<input type="hidden" name="excel_columns[]" value="'.$key.'">';
        $html.='<label>Position<select name="positions['.$key.']">';
        for($rank=1;$rank<=count($fields);$rank++)$html.='<option value="'.$rank.'"'.($rank===$index+1?' selected':'').'>'.$rank.'</option>';
        $html.='</select></label></div>';
    }
    return $html.'</div><p data-navigation-status role="status" aria-live="polite">'.MusicbandEscape($notice).'</p><button>Enregistrer</button> <button type="submit" name="excel_reset" value="1">Rétablir les valeurs par défaut</button></form></details>';
}
