<?php
/* Musicband DOCUMENTS WORKFLOW: sources, numérotation et validation métier. */
/* Cycle métier des devis/factures : sources, références, instantanés et contrôles de concurrence.
 * Chargé par business.php ; dépend de documents_delivery.php et advanced_notifications_service.php.
 * Écrit les documents et leur numérotation en MySQL ; les envois externes relèvent du service de livraison.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/documents_delivery.php';
require_once __DIR__.'/advanced_notifications_service.php';
function documentsWorkflowConcertFields(?bool $set=null): bool {
    static $ready=false; if ($set!==null) $ready=$set; return $ready;
}
function documentsMailSettings(): array {
    $saved=MusicbandRead('home_settings')['documents_mail']??[];
    if (!is_array($saved)) throw new RuntimeException('Configuration e-mail documentaire invalide.');
    $settings=array_replace(['subject'=>'{numero} — Musicband','body'=>"Bonjour {client},\n\nVeuillez trouver en pièce jointe votre document {numero}.\n\nCordialement,\nMusicband",'cc'=>[]],$saved);
    if (!is_string($settings['subject']) || !is_string($settings['body']) || !is_array($settings['cc'])) throw new RuntimeException('Configuration e-mail documentaire invalide.');
    return $settings;
}
function documentsMailVersion(array $settings): string { return hash('sha256',businessJson($settings)); }
function documentsMailSettingsSave(): string {
    if (!(MusicbandRequireLogin()['admin']??false)) throw new RuntimeException('Accès administrateur requis.');
    $current=documentsMailSettings();
    if (!is_string($_POST['documents_mail_version']??null) || !hash_equals(documentsMailVersion($current),$_POST['documents_mail_version'])) throw new RuntimeException('Le modèle e-mail a changé ailleurs : recharger Configuration.');
    $subject=$_POST['documents_subject']??null; $body=$_POST['documents_body']??null; $raw=$_POST['documents_cc']??null;
    if (!is_string($subject) || trim($subject)==='' || strlen($subject)>500 || preg_match('/[\r\n\x00-\x1f\x7f]/',$subject)) throw new RuntimeException('Objet requis, sur une ligne, maximum 500 octets.');
    if (!is_string($body) || trim($body)==='' || strlen($body)>6000 || str_contains($body,"\0")) throw new RuntimeException('Texte du message requis, maximum 6000 octets.');
    foreach ([$subject,$body] as $text) {
        preg_match_all('/\{[^{}]*\}/u',$text,$matches);
        foreach ($matches[0] as $variable) if (!in_array($variable,['{numero}','{type_document}','{client}','{date_prestation}','{montant}'],true)) throw new RuntimeException('Variable inconnue dans le modèle e-mail.');
    }
    if (!is_string($raw) || strlen($raw)>4000) throw new RuntimeException('Liste Cc trop longue.');
    $cc=[];
    foreach (preg_split('/[\r\n,;]+/',trim($raw),-1,PREG_SPLIT_NO_EMPTY) as $value) {
        $email=MusicbandEmail(trim($value));
        if ($email==='' || preg_match('/[\r\n<>]/',$email)) throw new RuntimeException('Adresse Cc invalide : une adresse simple par ligne.');
        $cc[$email]=$email;
    }
    if (count($cc)>30) throw new RuntimeException('Maximum 30 adresses en copie.');
    $settings=MusicbandRead('home_settings');
    $settings['documents_mail']=['subject'=>trim($subject),'body'=>str_replace(["\r\n","\r"],"\n",$body),'cc'=>array_values($cc)];
    MusicbandWrite('home_settings',$settings);
    return 'Modèle e-mail et copies enregistrés. Aucun message envoyé. La fiche générée proposera une confirmation avant transmission.';
}
function documentsMailSettingsForm(string $hidden): string {
    $settings=documentsMailSettings();
    $html='<section><h2>E-mails Devis / Factures</h2><p>Modèle en texte simple, commun aux documents. Variables : {numero}, {type_document}, {client}, {date_prestation}, {montant}. Les adresses Cc seront visibles aux destinataires. Le texte est repris et reste modifiable dans la confirmation de transmission, après génération et stockage du PDF Drive. Aucun message ne part lors de cette sauvegarde.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="documents_mail_settings"><input type="hidden" name="documents_mail_version" value="'.documentsMailVersion($settings).'">';
    $html.='<label>Objet du mail<input name="documents_subject" required maxlength="500" value="'.MusicbandEscape($settings['subject']).'"></label><label>Texte du mail<textarea class="Musicband-wide-field"  name="documents_body" rows="9" required maxlength="6000">'.MusicbandEscape($settings['body']).'</textarea></label><label>Adresses en copie (Cc) · une par ligne<textarea class="Musicband-wide-field"  name="documents_cc" rows="4" maxlength="4000">'.MusicbandEscape(implode("\n",$settings['cc'])).'</textarea></label>';
    return $html.'<button>Enregistrer le modèle et les copies</button></form></section>';
}
function documentsWorkflowLoad(PDO $db): void {
    $count=(int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='concerts_evenements' AND column_name IN ('montant','reglement')")->fetchColumn();
    documentsWorkflowConcertFields($count===2);
}
function documentsWorkflowReady(PDO $db): bool {
    if (!documentsWorkflowConcertFields() || !documentsTableExists($db,'devis_factures_sequences')) return false;
    $count=(int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='devis_factures_documents' AND column_name IN ('reference_annee','reference_sequence','reference_at','source_snapshot_sha256')")->fetchColumn();
    return $count===4 && documentsReady($db);
}
function documentsWorkflowSchema(array $settings): string {
    if (($_POST['confirm']??'')!=='yes' || ($_POST['confirm_database']??null)!==($settings['DB_NAME']??null)) throw new RuntimeException('Confirmer la sauvegarde et la base exacte.');
    $db=MusicbandDatabase($settings); documentsWorkflowLoad($db);
    if (documentsWorkflowReady($db)) return 'Schéma 006 déjà présent. Aucune référence réattribuée.';
    if (!documentsReady($db)) throw new RuntimeException('Créer d’abord le schéma 005 complet.');
    $count=(int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND ((table_name='concerts_evenements' AND column_name IN ('montant','reglement')) OR (table_name='devis_factures_documents' AND column_name IN ('reference_annee','reference_sequence','reference_at','source_snapshot_sha256')))")->fetchColumn();
    if ($count || documentsTableExists($db,'devis_factures_sequences')) throw new RuntimeException('Schéma 006 partiel : inspecter les ajouts avant une reprise ciblée. Aucun nettoyage automatique.');
    $sql=base64_decode('',true);
    if (!$sql) throw new RuntimeException('Schéma 006 non embarqué. Préparer les fichiers avant transfert.');
    foreach (explode(';',$sql) as $statement) if (trim($statement)!=='') $db->exec($statement);
    documentsWorkflowLoad($db);
    if (!documentsWorkflowReady($db)) throw new RuntimeException('Schéma 006 incomplet : inspecter la base.');
    return 'Schéma 006 créé : compteurs par type/année et informations de prestation. Références et statuts antérieurs conservés.';
}
function documentsWorkflowUpgradeForm(string $hidden): string {
    return '<section><h3>Numérotation et formulaire · schéma 006</h3><p>Ajouter les compteurs par type/année et reprendre Montant cachet / Mode Reglement dans de nouvelles colonnes Concerts. Les références existantes ne sont pas renumérotées. DDL non transactionnelle : sauvegarde requise.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="workflow_schema"><label>Nom exact de la base<input name="confirm_database" required autocomplete="off"></label><label class="upgrade-confirm"><input type="checkbox" name="confirm" value="yes" required> Sauvegarde et destination vérifiées.</label><button>Créer le schéma 006 · Numérotation</button></form></section>';
}
function documentsWorkflowState(array $row): string { return (string)($row['statut']??''); }
/** Réserve un numéro annuel sous verrou SQL dans la transaction de création ; un rollback doit annuler aussi le compteur. */
function documentsNextReference(PDO $db,string $type): array {
    if (!$db->inTransaction() || !in_array($type,['Devis','Facture'],true)) throw new RuntimeException('Attribution de référence hors transaction refusée.');
    // Appel exclusivement dans la transaction qui crée la fiche.
    $now=new DateTimeImmutable('now',new DateTimeZone(businessListSettings()['timezone'])); $year=(int)$now->format('Y');
    $prefix=$type.' '.$year.'-'; $q=$db->prepare('SELECT numero FROM devis_factures_documents WHERE numero LIKE ?'); $q->execute([$prefix.'%']); $max=0;
    foreach ($q as $row) if (preg_match('/^'.preg_quote($prefix,'/').'(\d{1,10})$/D',(string)$row['numero'],$match)) $max=max($max,(int)$match[1]);
    if ($max>=4294967295) throw new RuntimeException('Compteur annuel épuisé.');
    $q=$db->prepare('INSERT INTO devis_factures_sequences (type_document,annee,dernier_numero) VALUES (?,?,?) ON DUPLICATE KEY UPDATE dernier_numero=GREATEST(dernier_numero,VALUES(dernier_numero))'); $q->execute([$type,$year,$max]);
    $q=$db->prepare('SELECT dernier_numero FROM devis_factures_sequences WHERE type_document=? AND annee=? FOR UPDATE'); $q->execute([$type,$year]); $next=(int)$q->fetchColumn()+1;
    if ($next>4294967295) throw new RuntimeException('Compteur annuel épuisé.');
    $q=$db->prepare('UPDATE devis_factures_sequences SET dernier_numero=? WHERE type_document=? AND annee=?'); $q->execute([$next,$type,$year]);
    return ['numero'=>$prefix.sprintf('%02d',$next),'reference_annee'=>$year,'reference_sequence'=>$next,'reference_at'=>$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')];
}
function businessCreationToken(): string {
    $token=$_POST['creation_token']??'';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D',$token)) throw new RuntimeException('Formulaire de création expiré : recharger la page.');
    return $token;
}
function businessExistingCreation(PDO $db,string $module,string $token): ?int {
    $config=businessConfig($module); $q=$db->prepare('SELECT id FROM '.$config['table'].' WHERE source_identity=? AND source_table=? AND source_key_hash=?');
    $q->execute([hash('sha256','Musicband-manual-'.$module),'manual',hash('sha256',$token)]); $id=$q->fetchColumn(); return $id===false?null:(int)$id;
}
function businessInlineContact(PDO $db,array $user): ?int {
    if (($_POST['client_mode']??'existing')!=='new') return businessId($_POST['contact_id']??'',true);
    MusicbandRequirePermission('contacts.edit',$user);
    $values=[];
    foreach (['nom','adresse','mail','tel'] as $key) {
        if (!fieldVisibilityVisible('contacts',$key)) { $values[$key]=null; continue; }
        $value=$_POST['new_client_'.$key]??'';
        if (!is_string($value) || strlen($value)>12000 || str_contains($value,"\0")) throw new RuntimeException('Champ du nouveau client invalide.');
        $values[$key]=$value===''?null:$value;
    }
    if (trim($values['nom']??'')==='') throw new RuntimeException('Le nom du nouveau client est requis.');
    $db->query('SELECT id FROM contacts_revisions LIMIT 0'); $token=bin2hex(random_bytes(16));
    $record=['source_identity'=>hash('sha256','Musicband-manual-contacts'),'source_table'=>'manual','source_key'=>$token,'source_key_hash'=>hash('sha256',$token),'source_sha256'=>hash('sha256','{}'),...$values,'source_json'=>'{}'];
    $q=$db->prepare('INSERT INTO contacts_carnet_adresse ('.implode(',',array_keys($record)).',imported_at) VALUES ('.implode(',',array_fill(0,count($record),'?')).',UTC_TIMESTAMP(6))'); $q->execute(array_values($record)); $id=(int)$db->lastInsertId();
    $q=$db->prepare('INSERT INTO contacts_revisions (record_id,edited_by,edited_at,before_json,after_json) VALUES (?,?,UTC_TIMESTAMP(6),?,?)'); $q->execute([$id,$user['login'],'[]',businessJson(businessValues($record,businessConfig('contacts'),'contacts'))]);
    return $id;
}
function documentsConcertDateAllowed(array $row): bool {
    $settings=businessListSettings();$zone=new DateTimeZone($settings['timezone']);
    $today=new DateTimeImmutable('today',$zone);$month=$today->modify('first day of this month')->modify('-6 months');
    $cutoff=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$today->format('j'),(int)$month->format('t')))->format('Y-m-d');
    $date=businessConcertDate($row['date_concert']??null,$settings,businessDateImported($row));
    return $date!==null&&$date>=$cutoff;
}
function documentsInvoiceClients(PDO $db): array {
    $clients=[];
    foreach($db->query('SELECT * FROM concerts_evenements WHERE contact_id IS NOT NULL') as $concert)if(documentsConcertDateAllowed($concert))$clients[(int)$concert['contact_id']]=true;
    return $clients;
}
function documentsSelectionUnchanged(array $old,string $type,?int $contact,?int $concert): bool {
    return !empty($old['id'])&&($old['type_document']??'')===$type&&(int)($old['contact_id']??0)===$contact&&(int)($old['concert_id']??0)===$concert;
}
function documentsCheckSelection(PDO $db,string $type,int $contact,int $concert,array $old): void {
    if(documentsSelectionUnchanged($old,$type,$contact,$concert))return;
    $q=$db->prepare('SELECT * FROM concerts_evenements WHERE id=? FOR UPDATE');$q->execute([$concert]);$row=$q->fetch();
    if(!$row||!documentsConcertDateAllowed($row))throw new RuntimeException('Choisir un concert futur ou datant au maximum de six mois.');
    if(!empty($row['contact_id'])&&(int)$row['contact_id']!==$contact)throw new RuntimeException('Ce concert est affecté à un autre client.');
    if($type==='Facture'){
        $q=$db->prepare('SELECT * FROM concerts_evenements WHERE contact_id=? FOR UPDATE');$q->execute([$contact]);$eligible=false;
        foreach($q->fetchAll() as $linked)if(documentsConcertDateAllowed($linked)){$eligible=true;break;}
        if(!$eligible)throw new RuntimeException('Une facture nécessite un client ayant un concert affecté dans la période autorisée. Créer un devis ou affecter un concert à ce client.');
    }
}
// Liens SQL uniquement ; un nouvel onglet conserve la préparation non enregistrée.
function documentsRelationLink(string $key,?int $id): string {
    $route=$key==='contact_id'?'contacts.php':'concerts.php';
    $label=$key==='contact_id'?'Ouvrir le client':'Ouvrir le concert';
    return '<a data-document-relation="'.$key.'" target="_blank" rel="noopener"'.($id===null?' hidden':' href="'.MusicbandEscape(MusicbandBase().$route).'?id='.$id.'"').'>'.$label.($id===null?'':' #'.$id).'</a>';
}
function documentsClientSelect(PDO $db,array $row,bool $posted,string $selected): string {
    $type=$posted?($_POST['type_document']??''):($row['type_document']??'Devis');
    $concert=$posted?businessId($_POST['concert_id']??'',true):businessId((string)($row['concert_id']??''),true);
    $same=is_string($type)&&documentsSelectionUnchanged($row,$type,businessId($selected,true),$concert)&&($_POST['client_mode']??'existing')!=='new';
    $clients=documentsInvoiceClients($db);$html='<label>'.MusicbandEscape(fieldDisplayLabel('devis_factures','contact_id','Client / contact')).'<select name="contact_id"><option value="">Choisir un client</option>';
    foreach($db->query('SELECT id,nom FROM contacts_carnet_adresse ORDER BY nom,id') as $contact){
        $id=(string)$contact['id'];$eligible=isset($clients[(int)$id]);$allowed=$type!=='Facture'||$eligible||($same&&$selected===$id);
        $html.='<option value="'.$id.'" data-document-invoice-client="'.($eligible?'1':'0').'"'.($selected===$id?' selected':'').(!$allowed?' hidden disabled':'').'>'.MusicbandEscape($contact['nom'].' (#'.$id.')').'</option>';
    }
    return $html.'</select></label>'.documentsRelationLink('contact_id',businessId($selected,true));
}
function documentsConcertSelect(PDO $db,array $row,bool $posted): string {
    $selected=(string)($posted?($_POST['concert_id']??''):($row['concert_id']??''));
    $contact=($posted&&($_POST['client_mode']??'existing')==='new')?'':(string)($posted?($_POST['contact_id']??''):($row['contact_id']??''));
    $type=(string)($posted?($_POST['type_document']??''):($row['type_document']??'Devis'));
    $same=documentsSelectionUnchanged($row,$type,businessId($contact,true),businessId($selected,true));
    $html='<label>'.MusicbandEscape(fieldDisplayLabel('devis_factures','concert_id','Concert')).'<select name="concert_id"><option value="">Choisir un concert</option>';
    foreach($db->query('SELECT * FROM concerts_evenements ORDER BY evenement,id') as $concert){
        $id=(string)$concert['id'];$client=(string)($concert['contact_id']??'');$dateOk=documentsConcertDateAllowed($concert);
        $allowed=($dateOk&&($client===''||$client===$contact))||($same&&$selected===$id);
        $html.='<option value="'.$id.'" data-document-concert-client="'.MusicbandEscape($client).'" data-document-date-ok="'.($dateOk?'1':'0').'"'.($selected===$id?' selected':'').(!$allowed?' hidden disabled':'').'>'.MusicbandEscape($concert['evenement'].' (#'.$id.')').'</option>';
    }
    return $html.'</select></label>'.documentsRelationLink('concert_id',businessId($selected,true)).'<p>Concerts du client choisi ou sans client, depuis six mois inclus. Les anciennes associations restent conservées.</p>';
}

function businessClientForm(PDO $db,array $row,bool $posted,string $module='devis_factures'): string {
    $mode=$posted && ($_POST['client_mode']??'')==='new'?'new':'existing';
    $selected=$posted?($_POST['contact_id']??''):($row['contact_id']??'');
    $clientSelect=$module==='devis_factures'?documentsClientSelect($db,$row,$posted,is_scalar($selected)?(string)$selected:''):businessRelationSelect($db,'contact_id',['module'=>'contacts','label'=>fieldDisplayLabel($module,'contact_id','Client / contact')],is_scalar($selected)?(string)$selected:'');
    $invoice=$module==='devis_factures'&&($posted?($_POST['type_document']??''):($row['type_document']??''))==='Facture';
    $html='<fieldset class="business-source"><legend>'.MusicbandEscape(fieldDisplayLabel($module,'contact_id','Client')).'</legend><label>Choix du client<select name="client_mode"><option value="existing"'.($mode==='existing'?' selected':'').'>Client existant</option>'.(MusicbandCan('contacts.edit')?'<option value="new"'.($invoice?' disabled':'').($mode==='new'?' selected':'').'>Nouveau client</option>':'').'</select></label><div data-existing-client>'.$clientSelect.'</div><div data-new-client><p>Le nouveau client sera créé avec sa fiche Contacts seulement si l’enregistrement complet réussit.</p>';
    foreach (['nom'=>'Nom du client','adresse'=>'Adresse de facturation','mail'=>'E-mail','tel'=>'Téléphone'] as $key=>$label) {
        if (!fieldVisibilityVisible('contacts',$key)) continue;
        $value=$posted?($_POST['new_client_'.$key]??''):'';
        $html.='<label>'.MusicbandEscape(fieldDisplayLabel('contacts',$key,$label)).'<input name="new_client_'.$key.'" value="'.MusicbandEscape(is_string($value)?$value:'').'" maxlength="12000"></label>';
    }
    return $html.'</div></fieldset>';
}
function documentsSourceValues(PDO $db,int $contactId,int $concertId,bool $lock=false): array {
    $q=$db->prepare('SELECT id,nom,adresse FROM contacts_carnet_adresse WHERE id=?'.($lock?' FOR UPDATE':'')); $q->execute([$contactId]); $contact=$q->fetch();
    $q=$db->prepare('SELECT id,date_concert,duree,montant,reglement FROM concerts_evenements WHERE id=?'.($lock?' FOR UPDATE':'')); $q->execute([$concertId]); $concert=$q->fetch();
    if (!$contact || !$concert) throw new RuntimeException('Choisir un client et un concert existants.');
    return ['client'=>$contact['nom'],'adresse'=>$contact['adresse'],'date_prestation'=>$concert['date_concert'],'duree'=>$concert['duree'],'montant'=>$concert['montant'],'reglement'=>$concert['reglement']];
}
function documentsSourceFingerprint(array $values,int $contact,int $concert): string { return hash('sha256',businessJson([$contact,$concert,$values])); }
/** Sauvegarde le POST dans sa propre transaction ; refuse versions périmées et édition après début de génération, conserve l’instantané des sources. */
function documentsWorkflowSave(PDO $db,array $user,?int $id): int {
    MusicbandRequirePermission(MusicbandDocumentPermission((string)($_POST['action']??'save')),$user);
    if (!documentsWorkflowReady($db)) throw new RuntimeException('Appliquer le schéma 006 avant enregistrement.');
    $config=businessConfig('devis_factures'); $db->beginTransaction();
    try {
        $old=[]; $before=[];
        if ($id!==null) {
            $q=$db->prepare('SELECT * FROM devis_factures_documents WHERE id=? FOR UPDATE'); $q->execute([$id]); $old=$q->fetch();
            if (!$old) throw new RuntimeException('Document introuvable.');
            if (!is_string($_POST['version']??null) || !hash_equals(businessVersion($old,$config,'devis_factures'),$_POST['version'])) throw new RuntimeException('Fiche modifiée ailleurs : recharger avant enregistrement.');
            if (documentsDeliveryStarted($db,$id)) throw new RuntimeException('Une génération a été engagée : document figé, journal et fichiers à conserver.');
            $before=businessValues($old,$config,'devis_factures');
        } else {
            $token=businessCreationToken(); $existing=businessExistingCreation($db,'devis_factures',$token);
            if ($existing!==null) { $db->rollBack(); return $existing; }
        }
        $action=$_POST['action']??'save'; $state=documentsWorkflowState($old);
        if(in_array($action,['document_delete','document_restore'],true)){
            if(!$user['admin']||!$old||($_POST['confirm']??'')!=='yes')throw new RuntimeException('Action réservée à un administrateur, avec confirmation.');
            if(($action==='document_delete'&&$state!=='Validé')||($action==='document_restore'&&$state!=='Supprimé'))throw new RuntimeException('Seul un document validé sans génération peut être supprimé ou restauré.');
            $values=['statut'=>$action==='document_delete'?'Supprimé':'Validé'];
        } elseif ($action==='document_validate') {
            if (!$old || $state!=='Préparation') throw new RuntimeException('Seul un document en préparation peut être validé.');
            if (empty($old['contact_id']) || empty($old['concert_id'])) throw new RuntimeException('Renseigner le client et le concert avant validation.');
            $source=documentsSourceValues($db,(int)$old['contact_id'],(int)$old['concert_id'],true);
            if (!hash_equals((string)($old['source_snapshot_sha256']??''),documentsSourceFingerprint($source,(int)$old['contact_id'],(int)$old['concert_id']))) throw new RuntimeException('Le client ou le concert a changé : réenregistrer la fiche avant validation.');
            foreach ($source as $value) if (trim((string)$value)==='') throw new RuntimeException('Compléter le client et le concert : nom, adresse, date, durée, montant et règlement sont requis avant validation.');
            $values=['statut'=>'Validé'];
        } elseif ($action==='document_reopen') {
            if (!$old || $state!=='Validé'||($_POST['confirm']??'')!=='yes') throw new RuntimeException('Confirmer la reprise d’un document validé et non généré.');
            $values=['statut'=>'Préparation'];
        } elseif ($action==='document_initialize') {
            if (!$old || in_array($state,['Préparation','Validé','Généré','Transmis au client','Envoyé','Supprimé'],true) || ($_POST['confirm']??'')!=='yes') throw new RuntimeException('Confirmer la reprise de cette ancienne fiche en préparation.');
            $values=['statut'=>'Préparation'];
        } else {
            if ($action!=='save' || ($old && $state!=='Préparation' && $state!=='')) throw new RuntimeException('Reprendre la préparation avant modification. Les documents générés ou transmis sont figés.');
            $type=$_POST['type_document']??'';
            if (is_string($type)) $type=match (strtolower(trim($type))) { 'devis'=>'Devis','facture'=>'Facture',default=>$type };
            if (!is_string($type) || !in_array($type,['Devis','Facture'],true)) throw new RuntimeException('Choisir Devis ou Facture.');
            if ($old && !empty($old['reference_sequence']) && $type!==$old['type_document']) throw new RuntimeException('Le type est fixé après attribution de la référence. Créer un nouveau document pour un autre type.');
            if($type==='Facture'&&($_POST['client_mode']??'existing')==='new')throw new RuntimeException('Pour une facture, choisir un client existant ayant un concert affecté. Les nouveaux clients sont disponibles pour les devis.');
            $contact=businessInlineContact($db,$user); $concert=businessId($_POST['concert_id']??'',true);
            if ($contact===null || $concert===null) throw new RuntimeException('Le client et le concert sont requis.');
            documentsCheckSelection($db,$type,$contact,$concert,$old);
            $source=documentsSourceValues($db,$contact,$concert,true);
            $values=['type_document'=>$type,'statut'=>'Préparation','contact_id'=>$contact,'concert_id'=>$concert,...$source,'source_snapshot_sha256'=>documentsSourceFingerprint($source,$contact,$concert)];
            foreach (['commentaires','lien_document'] as $key) if (fieldVisibilityVisible('devis_factures',$key)) {
                $value=$_POST[$key]??'';
                if (!is_string($value) || strlen($value)>($key==='commentaires'?50000:12000) || str_contains($value,"\0")) throw new RuntimeException('Champ documentaire invalide.');
                $values[$key]=$value===''?null:$value;
            }
            if (trim((string)($old['numero']??''))==='') $values=[...$values,...documentsNextReference($db,$type)];
            if (!$old) $values['date_document']=(new DateTimeImmutable('today',new DateTimeZone(businessListSettings()['timezone'])))->format('Y-m-d');
        }
        if ($old) {
            $q=$db->prepare('UPDATE devis_factures_documents SET '.implode(',',array_map(fn($key)=>$key.'=?',array_keys($values))).' WHERE id=?'); $q->execute([...array_values($values),$id]);
        } else {
            $record=['source_identity'=>hash('sha256','Musicband-manual-devis_factures'),'source_table'=>'manual','source_key'=>$token,'source_key_hash'=>hash('sha256',$token),'source_sha256'=>hash('sha256','{}'),...$values,'source_json'=>'{}'];
            $q=$db->prepare('INSERT INTO devis_factures_documents ('.implode(',',array_keys($record)).',imported_at) VALUES ('.implode(',',array_fill(0,count($record),'?')).',UTC_TIMESTAMP(6))'); $q->execute(array_values($record)); $id=(int)$db->lastInsertId();
        }
        $after=array_replace($old?:[],$values);
        $q=$db->prepare('INSERT INTO devis_factures_revisions (record_id,edited_by,edited_at,before_json,after_json) VALUES (?,?,UTC_TIMESTAMP(6),?,?)'); $q->execute([$id,$user['login'],businessJson($before),businessJson(businessValues($after,$config,'devis_factures'))]);
        $db->commit();
        if ($action === 'document_validate' && function_exists('advancedNotificationDispatchEvent')) {
            advancedNotificationDispatchEvent('documents.validate', 'devis_factures', (string)$id, $before, $after, $user['login']);
        }
        return $id;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function documentsSourcePreview(array $values): string {
    $html='<div class="business-derived-grid">';
    foreach (['client'=>'Nom du client · Contact','adresse'=>'Adresse de facturation · Contact','date_prestation'=>'Date · Concert','duree'=>'Durée (min.) · Concert','montant'=>'Montant · Concert','reglement'=>'Mode de règlement · Concert'] as $key=>$label) if (fieldVisibilityVisible('devis_factures',$key)) $html.='<div><strong>'.MusicbandEscape(fieldDisplayLabel('devis_factures',$key,$label)).'</strong><p data-derived="'.$key.'">'.MusicbandEscape(($values[$key]??'')===''?'Non renseigné':businessDisplayValue('devis_factures',$key,$values[$key],$values)).'</p></div>';
    return $html.'</div>';
}
function documentsWorkflowForm(PDO $db,array $row,bool $posted): string {
    // Actualiser l'aperçu sans écrire ni modifier l'empreinte enregistrée.
    $row=array_replace($row,documentsPreviewValues($db,
        ($posted && ($_POST['client_mode']??'existing')==='new')?null:businessId((string)($posted?($_POST['contact_id']??''):($row['contact_id']??'')),true),
        businessId((string)($posted?($_POST['concert_id']??''):($row['concert_id']??'')),true)));

    $type=$posted?($_POST['type_document']??''):($row['type_document']??'Devis'); $fixed=!empty($row['reference_sequence']); $parts=[];
    $html='<label>'.MusicbandEscape(fieldDisplayLabel('devis_factures','type_document','Type')).'<select name="type_document" required'.($fixed?' disabled':'').'>';
    foreach (['Devis','Facture'] as $choice) $html.='<option value="'.$choice.'"'.(is_string($type) && strcasecmp($type,$choice)===0?' selected':'').'>'.$choice.'</option>';
    $html.='</select></label>';
    if ($fixed) $html.='<input type="hidden" name="type_document" value="'.MusicbandEscape((string)$row['type_document']).'">';
    $parts['type_document']=$html;
    $year=(new DateTimeImmutable('now',new DateTimeZone(businessListSettings()['timezone'])))->format('Y'); $reference=(string)($row['numero']??'');
    $parts['numero']='<div><strong>'.MusicbandEscape(fieldDisplayLabel('devis_factures','numero','Référence automatique')).'</strong><p'.($reference===''?' data-reference-hint data-year="'.$year.'"':'').'>'.MusicbandEscape($reference!==''?$reference:((is_string($type)&&$type==='Facture'?'Facture':'Devis').' '.$year.'-… (attribuée au premier enregistrement)')).'</p></div>';
    $parts['statut']='<div><strong>'.MusicbandEscape(fieldDisplayLabel('devis_factures','statut','Statut automatique')).'</strong><p>'.MusicbandEscape((string)($row['statut']??'Préparation')).'</p></div>';
    if (fieldVisibilityVisible('devis_factures','date_document')) $parts['date_document']='<div><strong>'.MusicbandEscape(fieldDisplayLabel('devis_factures','date_document','Date du document · automatique')).'</strong><p>'.MusicbandEscape(businessDisplayValue('devis_factures','date_document',$row['date_document']??'Attribuée au premier enregistrement',$row??[])).'</p></div>';
    $parts['contact_id']=businessClientForm($db,$row,$posted);
    foreach (['client'=>'Nom du client · Contact','adresse'=>'Adresse de facturation · Contact'] as $key=>$label) if (fieldVisibilityVisible('devis_factures',$key)) $parts[$key]='<div><strong>'.MusicbandEscape(fieldDisplayLabel('devis_factures',$key,$label)).'</strong><p data-derived="'.$key.'">'.MusicbandEscape(businessDisplayValue('devis_factures',$key,$row[$key]??'Non renseigné',$row??[])).'</p></div>';
    $selected=$posted?($_POST['concert_id']??''):($row['concert_id']??'');
    $parts['concert_id']=documentsConcertSelect($db,$row,$posted);
    foreach (['date_prestation'=>'Date · Concert','duree'=>'Durée (min.) · Concert','montant'=>'Montant · Concert','reglement'=>'Mode de règlement · Concert'] as $key=>$label) if (fieldVisibilityVisible('devis_factures',$key)) $parts[$key]='<div><strong>'.MusicbandEscape(fieldDisplayLabel('devis_factures',$key,$label)).'</strong><p data-derived="'.$key.'">'.MusicbandEscape(businessDisplayValue('devis_factures',$key,$row[$key]??'Non renseigné',$row)).'</p></div>';
    foreach (['commentaires','lien_document'] as $key) if (fieldVisibilityVisible('devis_factures',$key)) {
        $value=$posted?($_POST[$key]??''):($row[$key]??'');
        $parts[$key]='<label>'.MusicbandEscape(fieldDisplayLabel('devis_factures',$key,businessConfig('devis_factures')['fields'][$key])).'<textarea name="'.$key.'" maxlength="'.($key==='commentaires'?50000:12000).'">'.MusicbandEscape(is_string($value)?$value:'').'</textarea></label>';
    }
    return '<p>Les références et statuts restent automatiques. Les informations liées se corrigent dans le contact ou le concert ; leur disposition ne change pas leur calcul.</p><div id="document-source-preview" data-original-type="'.MusicbandEscape((string)($row['type_document']??'')).'" data-original-contact="'.MusicbandEscape((string)($row['contact_id']??'')).'" data-original-concert="'.MusicbandEscape((string)($row['concert_id']??'')).'" class="field-layout-root">'.fieldLayoutRender('devis_factures','edit',$parts).'</div><p id="document-source-status" role="status" aria-live="polite"></p>';
}
function documentsAdministrativeForm(array $row,string $action,string $label): string {
    return '<form method="post"><input type="hidden" name="csrf" value="'.MusicbandEscape($_SESSION['csrf']).'"><input type="hidden" name="id" value="'.(int)$row['id'].'"><input type="hidden" name="version" value="'.businessVersion($row,businessConfig('devis_factures'),'devis_factures').'"><input type="hidden" name="field_policy" value="'.fieldVisibilityVersion('devis_factures').'"><input type="hidden" name="action" value="'.$action.'"><label><input type="checkbox" name="confirm" value="yes" required> Je confirme : '.MusicbandEscape($label).'</label><p>Suppression logique restaurable : la référence, les données, les liens et l’historique restent conservés. Aucun fichier Drive ni envoi supprimé.</p><button>'.MusicbandEscape($label).'</button></form>';
}
function documentsWorkflowActions(array $row,?PDO $db=null): string {
    $db??=MusicbandDatabase(businessSettings());
    $state=documentsWorkflowState($row); $html='<p>Cycle : Préparation → Validé → Généré → Transmis au client.</p>';
    if($state==='Supprimé')return $html.'<p>Document supprimé des listes courantes ; données et historique conservés.</p>'.(MusicbandCan('documents.delete')&&!documentsDeliveryStarted($db,(int)$row['id'])?documentsAdministrativeForm($row,'document_restore','Restaurer le document validé'):'');
    if($state==='Validé'&&MusicbandCan('documents.delete')&&!documentsDeliveryStarted($db,(int)$row['id']))$html.=documentsAdministrativeForm($row,'document_delete','Supprimer ce document validé');
    if (!MusicbandCan('documents.validate') && !MusicbandCan('documents.reopen') && !MusicbandCan('documents.generate') && !MusicbandCan('documents.send')) return $html.documentsDeliveryReadLink($db,$row);
    $html.=documentsDeliveryPanel($db,$row);
    $action=$state==='Préparation'?'document_validate':($state==='Validé'?'document_reopen':'document_initialize');
    if (in_array($state,['Généré','Transmis au client','Envoyé'],true) || documentsDeliveryStarted($db,(int)$row['id'])) return $html.'<p>Document figé après début de génération ou transmission.</p>';
    if (!MusicbandCan(MusicbandDocumentPermission($action))) return $html;
    $html.='<form method="post"><input type="hidden" name="csrf" value="'.MusicbandEscape($_SESSION['csrf']).'"><input type="hidden" name="id" value="'.(int)$row['id'].'"><input type="hidden" name="version" value="'.businessVersion($row,businessConfig('devis_factures'),'devis_factures').'"><input type="hidden" name="field_policy" value="'.fieldVisibilityVersion('devis_factures').'"><input type="hidden" name="action" value="'.$action.'">';
    if($action==='document_reopen')$html.='<label><input type="checkbox" name="confirm" value="yes" required> Confirmer le retour en préparation, sans changer la référence.</label>';
    if ($action==='document_initialize') $html.='<label><input type="checkbox" name="confirm" value="yes" required> Reprendre explicitement cette ancienne fiche en préparation, sans changer sa référence.</label>';
    return $html.'<button>'.($action==='document_validate'?'Valider le document':($action==='document_reopen'?'Reprendre la préparation':'Initialiser le cycle de préparation')).'</button></form>';
}
function documentsPreviewValues(PDO $db,?int $contact,?int $concert): array {
    $values=[];
    if ($contact!==null) { $q=$db->prepare('SELECT nom AS client,adresse FROM contacts_carnet_adresse WHERE id=?'); $q->execute([$contact]); $row=$q->fetch(); if (!$row) throw new RuntimeException('Client introuvable.'); $values=$row; }
    if ($concert!==null) { $q=$db->prepare('SELECT date_concert AS date_prestation,duree,montant,reglement FROM concerts_evenements WHERE id=?'); $q->execute([$concert]); $row=$q->fetch(); if (!$row) throw new RuntimeException('Concert introuvable.'); $values=array_replace($values,$row); }
    return $values;
}
