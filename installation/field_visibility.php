<?php
/* Musicband FIELD VISIBILITY: configuration administrateur des champs métier. */
/* Catalogue, libellés et visibilité des champs configurables par module.
 * Utilise PDO et les helpers métier ; fieldVisibilityUse initialise la politique de la requête.
 * Les réglages sont stockés séparément des données ; les champs obligatoires restent protégés.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
/** Politique en mémoire pour la requête courante ; renseigner via fieldVisibilityUse avant de rendre les champs. */
function fieldVisibilityState(?array $set = null): array {
    static $policy = [];
    if ($set !== null) $policy = $set;
    return $policy;
}
function fieldVisibilityExists(PDO $db): bool {
    return (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='Musicbandai_field_visibility'")->fetchColumn() === 1;
}
/** Charge depuis SQL la visibilité puis les options configurées ; ne modifie aucune valeur de fiche. */
function fieldVisibilityUse(PDO $db): void {
    documentsWorkflowLoad($db);
    $policy = [];
    if (fieldVisibilityExists($db)) foreach ($db->query('SELECT module,hidden_json FROM Musicbandai_field_visibility') as $row) {
        $hidden = json_decode($row['hidden_json'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($hidden) || array_filter($hidden, fn($key)=>!is_string($key))) throw new RuntimeException('Configuration des champs invalide.');
        $policy[$row['module']] = $hidden;
    }
    fieldVisibilityState($policy);
}
function fieldVisibilityMandatory(string $module,string $key): bool {
    if($module==='expenses'&&in_array($key,['member','expense_date','amount_cents','supplier_id','payment_method'],true))return true;
    if($module==='accounting'&&in_array($key,['entry_date','amount_cents','category','credit','debit','running'],true))return true;
    return $key===businessConfig($module)['required'] || ($module==='devis_factures' && in_array($key,['type_document','numero','statut','contact_id','concert_id'],true));
}
function fieldVisibilityVisible(string $module, string $key): bool {
    if (fieldVisibilityMandatory($module,$key)) return true;
    $legacy=$module==='songs'?songLegacyFieldKey($key):null;
    $options=fieldOptions()[$module][$key]??($legacy!==null?(fieldOptions()[$module][$legacy]??null):null);
    if (is_array($options) && is_bool($options['visible']??null)) return $options['visible'];
    $hidden=fieldVisibilityState()[$module]??[];
    $label=businessConfig($module)['fields'][$key]??null;
    return ($legacy===null||!in_array($legacy,$hidden,true)) && !in_array($key,$hidden,true) && ($label===null || !in_array('source:'.hash('sha256',$label),$hidden,true));
}
function fieldVisibilityVersion(string $module): string {
    return hash('sha256', businessJson(['hidden'=>fieldVisibilityState()[$module]??[],'layout'=>fieldLayoutSaved()[$module]??[],'options'=>fieldOptions()[$module]??[]]));
}
function fieldOptions(): array {
    static $options=null;
    if ($options===null) { $options=MusicbandRead('home_settings')['field_options']??[]; if (!is_array($options)) throw new RuntimeException('Configuration des champs invalide.'); }
    return $options;
}
function fieldDisplayLabel(string $module,string $key,string $fallback): string {
    $legacy=$module==='songs'?songLegacyFieldKey($key):null;
    $label=fieldOptions()[$module][$key]['label']??($legacy!==null?(fieldOptions()[$module][$legacy]['label']??''):'');
    // Renommer uniquement les anciens libellés standard ; conserver les personnalisations.
    if ($module==='songs' && (($key==='p5' && $label==='P5') || ($key==='seb_comments' && $label==='Commentaires Seb'))) $label=$key==='p5'?'Patch':'Commentaires';
    return is_string($label) && trim($label)!==''?$label:$fallback;
}
function fieldVisibilitySourceKey(string $module, string $name): string {
    if($module==='songs'&&($key=songSourceField($name))!==null)return $key;
    $main = array_search($name, businessConfig($module)['fields'], true);
    return $main === false ? 'source:' . hash('sha256', $name) : $main;
}
function fieldVisibilityRegistry(PDO $db, string $module): array {
    $config = businessConfig($module); $fields = $config['fields'];
    foreach (businessRelations($module) as $key=>$relation) $fields[$key]=$relation['label'];
    if($module==='accounting')return $fields+['credit'=>'Crédit','debit'=>'Débit','running'=>'Solde chronologique'];
    if($module==='expenses')return $fields;
    // Détecter les noms effectivement importés, sans exposer leurs valeurs.
    foreach ($db->query('SELECT source_json FROM ' . $config['table'] . ' ORDER BY id') as $row) {
        $source = json_decode($row['source_json'], true, 32, JSON_THROW_ON_ERROR);
        foreach ($source as $name=>$_) {
            $key = fieldVisibilitySourceKey($module, (string)$name);
            if (!isset($fields[$key])) $fields[$key] = (string)$name . ' · source';
        }
    }
    return $fields;
}
function fieldVisibilitySave(array $user): string {
    if (!$user['admin']) throw new RuntimeException('Accès administrateur requis.');
    $module = $_POST['module'] ?? '';
    if (!is_string($module) || !in_array($module, businessModules(), true)) throw new RuntimeException('Module inconnu.');
    if (isset(fieldOptions()[$module])) throw new RuntimeException('Utiliser le nouvel éditeur Champs et vues : recharger Configuration.');
    $db = MusicbandDatabase(businessSettings());
    if (!fieldVisibilityExists($db)) throw new RuntimeException('Créer le schéma 004 dans Upgrade avant d’enregistrer les champs.');
    fieldVisibilityUse($db);
    $fields = fieldVisibilityRegistry($db, $module);
    if (!is_string($_POST['catalog'] ?? null) || !hash_equals(hash('sha256', businessJson($fields)), $_POST['catalog'])) throw new RuntimeException('La liste des champs a changé après un import : recharger la page.');
    $visible = $_POST['visible'] ?? [];
    if (!is_array($visible) || count($visible)>count($fields)) throw new RuntimeException('Sélection de champs invalide.');
    foreach ($visible as $key) if (!is_string($key) || !isset($fields[$key])) throw new RuntimeException('Un champ sélectionné n’est plus disponible : recharger la page.');
    foreach (array_keys($fields) as $key) if (fieldVisibilityMandatory($module,$key)) $visible[]=$key;
    $db->beginTransaction();
    try {
        $select = $db->prepare('SELECT revision,hidden_json FROM Musicbandai_field_visibility WHERE module=? FOR UPDATE'); $select->execute([$module]); $old = $select->fetch();
        if (!is_string($_POST['revision'] ?? null) || ($_POST['revision'] !== ($old['revision'] ?? ''))) throw new RuntimeException('Ces paramètres ont été modifiés ailleurs : recharger avant de les modifier.');
        // Préserver les choix d’un champ importé devenu momentanément absent.
        $hidden = $old ? json_decode($old['hidden_json'], true, 16, JSON_THROW_ON_ERROR) : [];
        foreach (businessConfig($module)['fields'] as $key=>$label) {
            $alias='source:'.hash('sha256',$label);
            if (in_array($alias,$hidden,true)) { $hidden=array_values(array_diff($hidden,[$alias])); $hidden[]=$key; }
        }
        $hidden = array_values(array_unique([...array_diff($hidden, array_keys($fields)), ...array_diff(array_keys($fields), $visible)]));
        $revision = bin2hex(random_bytes(16));
        if ($old) { $q=$db->prepare('UPDATE Musicbandai_field_visibility SET hidden_json=?,revision=?,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE module=?'); }
        else { $q=$db->prepare('INSERT INTO Musicbandai_field_visibility (hidden_json,revision,updated_by,updated_at,module) VALUES (?,?,?,UTC_TIMESTAMP(6),?)'); }
        $q->execute([businessJson($hidden),$revision,$user['login'],$module]); $db->commit();
        return 'Champs affichés enregistrés pour ' . businessConfig($module)['title'] . '. Recharger les pages déjà ouvertes pour appliquer le choix.';
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function fieldVisibilityForms(string $hidden): string {
    $html = '<section><h2>Champs affichés par module</h2><p>Cocher les champs à afficher pour tous les utilisateurs. Les champs décochés disparaissent des tableaux, fiches et formulaires ; leurs valeurs sont conservées. Les champs indispensables au parcours restent cochés (identification, et type/statut/associations des documents). Ces choix organisent l’affichage, ils ne constituent pas une gestion de permissions.</p>';
    try {
        $db = MusicbandDatabase(businessSettings()); $ready = fieldVisibilityExists($db); fieldVisibilityUse($db);
        if (!$ready) $html .= '<p>Créer d’abord le schéma 004 dans <a href="' . MusicbandEscape(MusicbandBase()) . 'upgrade.php">Upgrade</a>. Les choix ci-dessous deviennent enregistrables après sa création.</p>';
        foreach (businessModules() as $module) {
            try {
                $fields = fieldVisibilityRegistry($db,$module); $revision = '';
                if ($ready) { $q=$db->prepare('SELECT revision FROM Musicbandai_field_visibility WHERE module=?'); $q->execute([$module]); $revision=$q->fetchColumn() ?: ''; }
                $html .= '<details class="field-module"><summary>' . businessConfig($module)['title'] . ' · choisir les champs</summary><form method="post">' . $hidden . '<input type="hidden" name="action" value="field_visibility"><input type="hidden" name="module" value="' . $module . '"><input type="hidden" name="revision" value="' . MusicbandEscape($revision) . '"><input type="hidden" name="catalog" value="' . hash('sha256',businessJson($fields)) . '"><fieldset><legend>Champs affichés</legend>';
                foreach ($fields as $key=>$label) $html .= '<label class="field-choice"><input type="checkbox" name="visible[]" value="' . MusicbandEscape($key) . '"' . (fieldVisibilityVisible($module,$key)?' checked':'') . (fieldVisibilityMandatory($module,$key)?' disabled':'') . '> ' . MusicbandEscape($label) . '</label>';
                $html .= '</fieldset><button' . (!$ready?' disabled':'') . '>Enregistrer les champs · ' . businessConfig($module)['title'] . '</button></form></details>';
            } catch (Throwable $e) { $html .= '<p>Champs ' . businessConfig($module)['title'] . ' indisponibles : vérifier ses tables et son import dans Upgrade.</p>'; }
        }
    } catch (Throwable $e) { $html .= '<p>Paramètres des champs indisponibles : vérifier MySQL dans Upgrade. La gestion des utilisateurs reste disponible.</p>'; }
    return $html . '</section>';
}
function fieldVisibilitySchema(array $settings): string {
    if (($_POST['confirm']??'')!=='yes' || ($_POST['confirm_database']??null)!==($settings['DB_NAME']??null)) throw new RuntimeException('Confirmer la sauvegarde et le nom exact de la base.');
    $db=MusicbandDatabase($settings);
    if (fieldVisibilityExists($db)) throw new RuntimeException('La table des champs existe déjà : aucune recréation.');
    $sql=base64_decode('',true);
    if (!$sql) throw new RuntimeException('Schéma 004 non embarqué : préparer les fichiers avant transfert.');
    $db->exec($sql);
    return 'Schéma 004 créé. Choisir maintenant les champs dans Configuration. Aucune fiche métier modifiée.';
}
function fieldVisibilityUpgradeForm(string $hidden): string {
    return '<section><h3>Champs affichés · schéma 004</h3><p>Ajouter la table générique Musicbandai_field_visibility pour les préférences communes. Aucune table métier modifiée.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="fields_schema"><label>Nom exact de la base<input name="confirm_database" required autocomplete="off"></label><label class="upgrade-confirm"><input type="checkbox" name="confirm" value="yes" required> Destination et sauvegarde vérifiées.</label><button>Créer le schéma 004 · Champs affichés</button></form></section>';
}
