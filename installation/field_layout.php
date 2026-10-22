<?php
/* Musicband FIELD LAYOUT: ordre et groupes par module et vue, sans donnée métier. */
/* Disposition des listes, fiches et formulaires à partir du catalogue de champs métier.
 * Utilise les helpers business, song_fields et field_visibility fournis par l’appelant.
 * Retourne du HTML ou sauvegarde home_settings après contrôle de version ; ne modifie pas les fiches métier.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
function fieldLayoutViews(): array { return ['list'=>'Liste','detail'=>'Fiche détaillée','edit'=>'Formulaire']; }
function fieldLayoutSaved(): array {
    $layouts=MusicbandRead('home_settings')['field_layouts']??[];
    if (!is_array($layouts)) throw new RuntimeException('Organisation des champs invalide.');
    // Reprendre les dispositions des anciennes cellules sans réécrire la configuration.
    foreach(songFieldSpecs() as $key=>$_)foreach(['list','detail','edit'] as $view){
        $legacy=songLegacyFieldKey($key);
        if(!isset($layouts['songs'][$view][$key])&&isset($layouts['songs'][$view][$legacy]))$layouts['songs'][$view][$key]=$layouts['songs'][$view][$legacy];
    }
    return $layouts;
}
function fieldLayoutFields(PDO $db,string $module,string $view): array {
    $config=businessConfig($module);
    if ($view==='list') return businessColumns($module,false,true);
    if ($view==='edit') {
        $fields=$config['fields'];
        if ($module==='concerts') $fields=['contact_id'=>'Choix du client / nouveau client']+$fields;
        if ($module==='devis_factures') $fields+=['contact_id'=>'Choix du client / nouveau client','concert_id'=>'Choix du concert'];
        if ($module==='devis_factures') {
            $ordered=[]; foreach (['type_document','numero','statut','date_document','contact_id','client','adresse','concert_id','date_prestation','duree','montant','reglement','commentaires','lien_document'] as $key) if (isset($fields[$key])) $ordered[$key]=$fields[$key];
            return $ordered;
        }
        if (!in_array($module,['concerts','songs'],true)) return $fields;
    } else $fields=$config['fields'];
    foreach (fieldVisibilityRegistry($db,$module) as $key=>$label) if (str_starts_with($key,'source:') && $key!==fieldVisibilitySourceKey($module,'Row ID')) $fields[$key]=$label;
    return $fields;
}
/** Fusionne dispositions enregistrées et champs actuels ; les nouveaux champs ont un groupe dédié sans réordonner les choix existants. */
function fieldLayoutEntries(string $module,string $view,array $fields): array {
    $saved=fieldLayoutSaved()[$module][$view]??[]; $entries=[]; $positions=[];
    if (!is_array($saved)) throw new RuntimeException('Organisation des champs invalide.');
    $used=[]; foreach ($saved as $entry) if (is_array($entry) && is_int($entry['group']??null)) $used[$entry['group']]=true;
    $newGroup=$used?min(999,max(array_keys($used))+1):1;
    if (isset($used[$newGroup])) { $newGroup=1; while ($newGroup<=999 && isset($used[$newGroup])) $newGroup++; }
    $newRank=0;
    foreach ($fields as $key=>$label) {
        $group=str_starts_with($key,'source:')?2:1; $title=$group===2?'Champs supplémentaires':'';
        if ($module==='devis_factures' && $view==='edit') {
            $group=in_array($key,['type_document','numero','statut','date_document'],true)?1:(in_array($key,['contact_id','client','adresse'],true)?2:(in_array($key,['concert_id','date_prestation','duree','montant','reglement'],true)?3:4));
            $title=[1=>'Document',2=>'Client',3=>'Prestation',4=>''][$group];
        }
        if($module==='songs'&&str_starts_with($key,'source:')){$group=9;$title='Données supplémentaires';}
        if($module==='songs'&&$view!=='list'&&isset(songFieldSpecs()[$key])){
            $group=songFieldSpecs()[$key]['group'];
            $title=[1=>'Informations générales',2=>'Préparation et répétitions',3=>'Guitare',4=>'Claviers',5=>'Chant et chœurs',6=>'Basse',7=>'Balance',8=>'Commentaires et ressources'][$group];
        }
        $positions[$group]=($positions[$group]??0)+1;
        $entry=$saved[$key]??($saved?['group'=>$newGroup,'rank'=>++$newRank,'title'=>'Nouveaux champs']:['group'=>$group,'rank'=>$positions[$group],'title'=>$title]);
        if (!is_array($entry) || !is_int($entry['group']??null) || !is_int($entry['rank']??null) || !is_string($entry['title']??null) || $entry['group']<1 || $entry['group']>999 || $entry['rank']<1 || $entry['rank']>999 || strlen($entry['title'])>160) throw new RuntimeException('Organisation des champs invalide.');
        // Actualiser l’ancien titre standard sans modifier les groupes personnalisés ni leur ordre.
        if ($module==='songs' && $entry['title']==='Données supplémentaires importées') $entry['title']='Données supplémentaires';
        $entries[$key]=$entry;
    }
    uasort($entries,fn($a,$b)=>($a['group']<=>$b['group'])?:($a['rank']<=>$b['rank']));
    return $entries;
}
function fieldLayoutOrder(string $module,string $view,array $fields): array {
    $result=[]; foreach (fieldLayoutEntries($module,$view,$fields) as $key=>$_) $result[$key]=$fields[$key];
    return $result;
}
/** Assemble les groupes entiers dans l’ordre configuré ; les CSS donnent la pleine largeur au premier groupe effectivement rendu. */
function fieldLayoutRender(string $module,string $view,array $parts): string {
    $entries=fieldLayoutEntries($module,$view,$parts); $groups=[];
    foreach ($entries as $key=>$entry) { $group=$entry['group']; $groups[$group]['title']=$entry['title']; $groups[$group]['parts'][]=$parts[$key]; }
    $columns=min(3,max(1,count($groups)-1));
    $html='<div class="field-layout-root field-layout-container"><div class="field-layout-sections field-layout-columns-'.$columns.'">';
    foreach ($groups as $group) {
        $html.='<section class="field-layout-group">'.($group['title']!==''?'<h3>'.MusicbandEscape($group['title']).'</h3>':'');
        $html.=$view==='detail'?'<dl>':'<div class="field-layout-grid">';
        $html.=implode('',$group['parts']).($view==='detail'?'</dl>':'</div>').'</section>';
    }
    return $html.'</div></div>';
}
function fieldLayoutVersion(string $module,string $view): string {
    return hash('sha256',businessJson(fieldLayoutSaved()[$module][$view]??[]));
}
function fieldLayoutSave(array $user): string {
    if (!$user['admin']) throw new RuntimeException('Accès administrateur requis.');
    $module=$_POST['layout_module']??null; $view=$_POST['layout_view']??null;
    if (!is_string($module) || !in_array($module,businessModules(),true) || !is_string($view) || !isset(fieldLayoutViews()[$view])) throw new RuntimeException('Module ou vue invalide.');
    if (isset(fieldOptions()[$module])) throw new RuntimeException('Utiliser le nouvel éditeur Champs et vues : recharger Configuration.');
    $db=MusicbandDatabase(businessSettings()); fieldVisibilityUse($db); $fields=fieldLayoutFields($db,$module,$view);
    if (!is_string($_POST['layout_catalog']??null) || !hash_equals(hash('sha256',businessJson($fields)),$_POST['layout_catalog'])) throw new RuntimeException('Les champs disponibles ont changé : recharger Configuration.');
    if (!is_string($_POST['layout_version']??null) || !hash_equals(fieldLayoutVersion($module,$view),$_POST['layout_version'])) throw new RuntimeException('Cette organisation a changé ailleurs : recharger avant de modifier.');
    if (($_POST['layout_reset']??'')==='1') {
        $settings=MusicbandRead('home_settings'); unset($settings['field_layouts'][$module][$view]); MusicbandWrite('home_settings',$settings);
        return 'Disposition initiale rétablie pour '.businessConfig($module)['title'].' · '.fieldLayoutViews()[$view].'.';
    }
    $posted=$_POST['layout']??null;
    if (!is_array($posted) || count($posted)!==count($fields) || array_diff(array_keys($posted),array_keys($fields))) throw new RuntimeException('Formulaire incomplet : aucun réglage enregistré.');
    $entries=[]; $titles=[]; $positions=[];
    foreach ($fields as $key=>$_) {
        $entry=$posted[$key];
        if (!is_array($entry) || !is_string($entry['group']??null) || !preg_match('/^[1-9][0-9]{0,2}$/D',$entry['group']) || !is_string($entry['rank']??null) || !preg_match('/^[1-9][0-9]{0,2}$/D',$entry['rank']) || !is_string($entry['title']??null) || strlen($entry['title'])>160 || str_contains($entry['title'],"\0")) throw new RuntimeException('Groupe/position : nombre de 1 à 999 ; titre : 160 octets maximum.');
        $group=(int)$entry['group']; $rank=(int)$entry['rank']; $title=trim($entry['title']);
        if (isset($positions[$group][$rank])) throw new RuntimeException('Deux champs ont la même position dans le même groupe.');
        if (array_key_exists($group,$titles) && $titles[$group]!==$title) throw new RuntimeException('Les champs du même groupe doivent avoir le même titre (ou tous un titre vide).');
        $positions[$group][$rank]=true; $titles[$group]=$title; $entries[$key]=['group'=>$group,'rank'=>$rank,'title'=>$title];
    }
    $settings=MusicbandRead('home_settings'); $settings['field_layouts'][$module][$view]=array_replace($settings['field_layouts'][$module][$view]??[],$entries); MusicbandWrite('home_settings',$settings);
    return 'Organisation enregistrée pour '.businessConfig($module)['title'].' · '.fieldLayoutViews()[$view].'. Recharger les autres pages.';
}
function fieldEditorCatalog(PDO $db,string $module): array {
    $fields=fieldVisibilityRegistry($db,$module); $views=[];
    foreach (fieldLayoutViews() as $view=>$_) $views[$view]=fieldLayoutFields($db,$module,$view);
    return ['fields'=>$fields,'views'=>$views];
}
function fieldEditorVersion(string $module): string {
    return fieldVisibilityVersion($module);
}
function fieldEditorEntries(array $posted,array $fields): array {
    if (count($posted)!==count($fields) || array_diff(array_keys($posted),array_keys($fields))) throw new RuntimeException('Disposition incomplète ou champ inconnu : aucun réglage enregistré.');
    $entries=[]; $titles=[]; $positions=[];
    foreach ($fields as $key=>$_) {
        $entry=$posted[$key];
        if (!is_array($entry) || !is_string($entry['group']??null) || !preg_match('/^[1-9][0-9]{0,2}$/D',$entry['group']) || !is_string($entry['rank']??null) || !preg_match('/^[1-9][0-9]{0,2}$/D',$entry['rank']) || !is_string($entry['title']??null) || strlen($entry['title'])>160 || str_contains($entry['title'],"\0")) throw new RuntimeException('Groupe/position : de 1 à 999 ; titre : 160 octets maximum.');
        $group=(int)$entry['group']; $rank=(int)$entry['rank']; $title=trim($entry['title']);
        if (isset($positions[$group][$rank])) throw new RuntimeException('Deux champs ont la même position dans le même groupe.');
        if (isset($titles[$group]) && $titles[$group]!==$title) throw new RuntimeException('Les champs du même groupe doivent avoir le même titre.');
        $positions[$group][$rank]=true; $titles[$group]=$title; $entries[$key]=['group'=>$group,'rank'=>$rank,'title'=>$title];
    }
    return $entries;
}
/** Valide catalogue, version et formulaire complet avant de sauvegarder les options de champs et dispositions de vues. */
function fieldEditorSave(array $user): string {
    if (!$user['admin']) throw new RuntimeException('Accès administrateur requis.');
    $module=$_POST['module']??null;
    if (!is_string($module) || !in_array($module,businessModules(),true)) throw new RuntimeException('Module inconnu.');
    $db=MusicbandDatabase(businessSettings()); fieldVisibilityUse($db); $catalog=fieldEditorCatalog($db,$module);
    if (!is_string($_POST['catalog']??null) || !hash_equals(hash('sha256',businessJson($catalog)),$_POST['catalog'])) throw new RuntimeException('Les champs disponibles ont changé : recharger Configuration.');
    if (!is_string($_POST['editor_version']??null) || !hash_equals(fieldEditorVersion($module),$_POST['editor_version'])) throw new RuntimeException('Les réglages du module ont changé ailleurs : recharger Configuration.');
    $settings=MusicbandRead('home_settings');
    if (isset($_POST['layout_reset'])) {
        $view=$_POST['layout_reset'];
        if (!is_string($view) || !isset(fieldLayoutViews()[$view])) throw new RuntimeException('Vue inconnue.');
        unset($settings['field_layouts'][$module][$view]); MusicbandWrite('home_settings',$settings);
        return 'Disposition initiale rétablie pour cette vue. Visibilité, libellés et autres vues conservés ; les autres modifications du formulaire ne sont pas enregistrées.';
    }
    $posted=$_POST['options']??null; $layouts=$_POST['layout']??null; $fields=$catalog['fields'];
    if (!is_array($posted) || count($posted)!==count($fields) || array_diff(array_keys($posted),array_keys($fields)) || !is_array($layouts) || count($layouts)!==count($catalog['views']) || array_diff(array_keys($layouts),array_keys($catalog['views']))) throw new RuntimeException('Formulaire incomplet : aucun réglage enregistré.');
    $options=[];
    foreach ($fields as $key=>$_) {
        $option=$posted[$key];
        if (!is_array($option) || !is_string($option['label']??null) || strlen($option['label'])>160 || preg_match('/[\x00-\x1f\x7f]/',$option['label']) || !in_array($option['visible']??null,['0','1'],true)) throw new RuntimeException('Libellé : une ligne, 160 octets maximum ; visibilité invalide.');
        if (fieldVisibilityMandatory($module,$key) && $option['visible']!=='1') throw new RuntimeException('Un champ indispensable ne peut pas être masqué.');
        $options[$key]=['label'=>trim($option['label']),'visible'=>$option['visible']==='1'];
    }
    $entries=[];
    foreach ($catalog['views'] as $view=>$viewFields) {
        if (!is_array($layouts[$view]??null)) throw new RuntimeException('Disposition incomplète.');
        $entries[$view]=fieldEditorEntries($layouts[$view],$viewFields);
    }
    $settings['field_options'][$module]=array_replace($settings['field_options'][$module]??[],$options);
    foreach ($entries as $view=>$values) $settings['field_layouts'][$module][$view]=array_replace($settings['field_layouts'][$module][$view]??[],$values);
    MusicbandWrite('home_settings',$settings);
    return 'Champs et vues enregistrés pour '.businessConfig($module)['title'].'. Recharger les pages déjà ouvertes.';
}
function fieldLayoutForms(string $hidden, ?string $onlyModule = null): string {
    $html='<section><h2>Champs et vues</h2><p>Un éditeur et une sauvegarde par module. Affichage et nom sont communs à toutes les vues et tous les utilisateurs ; groupes et ordre sont propres à chaque vue. Le nom d’origine reste le repère technique. Un nom personnalisé vide rétablit le libellé d’origine. Les champs indispensables restent visibles.</p>';
    try {
        $db=MusicbandDatabase(businessSettings()); fieldVisibilityUse($db);
        foreach (businessModules() as $module) {
            if ($onlyModule !== null && $module !== $onlyModule) continue;
            try {
                $catalog=fieldEditorCatalog($db,$module);
                $html.='<details class="field-module"><summary>'.MusicbandEscape(businessConfig($module)['title']).'</summary><form method="post" data-field-editor>'.$hidden.'<input type="hidden" name="action" value="field_editor"><input type="hidden" name="module" value="'.$module.'"><input type="hidden" name="catalog" value="'.hash('sha256',businessJson($catalog)).'"><input type="hidden" name="editor_version" value="'.fieldEditorVersion($module).'">';
                $html.='<div class="field-editor-save"><button>Enregistrer les champs et les vues · '.MusicbandEscape(businessConfig($module)['title']).'</button></div>';
                $html.='<details data-field-options open><summary>Tous les champs · affichage et libellés</summary><p>Sans JavaScript, régler ici l’affichage et les noms. Ces choix sont globaux au module ; les données et les droits restent conservés.</p>';
                foreach ($catalog['fields'] as $key=>$label) {
                    $prefix='options['.MusicbandEscape($key).']'; $mandatory=fieldVisibilityMandatory($module,$key);
                    $html.='<fieldset data-field-option="'.MusicbandEscape($key).'" class="field-layout-setting"><legend>'.MusicbandEscape($label).'</legend><label class="field-option-visible"><input type="hidden" name="'.$prefix.'[visible]" value="'.($mandatory?'1':'0').'"><input type="checkbox" data-option-visible name="'.$prefix.'[visible]" value="1"'.(fieldVisibilityVisible($module,$key)?' checked':'').($mandatory?' disabled':'').'> Affiché'.($mandatory?' · indispensable':'').'</label><label>Nom affiché<input data-option-label name="'.$prefix.'[label]" maxlength="160" placeholder="'.MusicbandEscape($label).'" value="'.MusicbandEscape(fieldOptions()[$module][$key]['label']??'').'"></label></fieldset>';
                }
                $html.='</details>';
                foreach ($catalog['views'] as $view=>$fields) {
                    $html.='<details data-field-layout><summary>'.MusicbandEscape(fieldLayoutViews()[$view]).'</summary><p>Déplacer les champs et les groupes ; la numérotation est automatique. Les cases et noms sont synchronisés entre vues. Sans JavaScript, utiliser les numéros et le même titre pour les membres d’un groupe.</p>';
                    foreach (fieldLayoutEntries($module,$view,$fields) as $key=>$entry) {
                        $prefix='layout['.$view.']['.MusicbandEscape($key).']';
                        $html.='<fieldset class="field-layout-setting" data-field-key="'.MusicbandEscape($key).'"><legend>'.MusicbandEscape($fields[$key]).'</legend><label>Groupe n°<input type="number" min="1" max="999" required name="'.$prefix.'[group]" value="'.$entry['group'].'"></label><label>Position dans le groupe<input type="number" min="1" max="999" required name="'.$prefix.'[rank]" value="'.$entry['rank'].'"></label><label>Titre du groupe (facultatif)<input maxlength="160" name="'.$prefix.'[title]" value="'.MusicbandEscape($entry['title']).'"></label></fieldset>';
                    }
                    $html.='<button name="layout_reset" value="'.$view.'">Rétablir uniquement l’ordre et les groupes de cette vue</button></details>';
                }
                $html.='<p>Les listes conservent leurs colonnes disponibles ; les relations SQL, historiques et sources d’origine gardent leurs vues dédiées. Les champs masqués restent dans l’éditeur.</p></form></details>';
            } catch (Throwable $e) { $html.='<p>Éditeur '.MusicbandEscape($module).' indisponible : vérifier ses tables et sa configuration.</p>'; }
        }
    } catch (Throwable $e) { $html.='<p>Éditeur indisponible : vérifier MySQL et les réglages privés.</p>'; }
    return $html.'</section>';
}
