<?php
/* Musicband BUSINESS: fiches Contacts et Concerts, données et historique MySQL. */
/* Contrôleur partagé des fiches Contacts, Concerts, Devis/Factures et de leur configuration.
 * Assemble database.php, business_list.php, visibilité/disposition et services de documents/concerts.
 * Lit les formulaires HTTP, contrôle droits et versions, écrit les données et leur historique en MySQL.
 * Filtre les modules exclus par la politique d'édition avant toute configuration ou action métier.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/business_list.php';
require_once __DIR__ . '/field_visibility.php';
require_once __DIR__ . '/field_layout.php';
require_once __DIR__ . '/documents_relations.php';
require_once __DIR__ . '/documents_workflow.php';
require_once __DIR__ . '/concerts_calendar.php';
require_once __DIR__ . '/concerts_publication.php';
require_once __DIR__ . '/concerts_fields.php';
require_once __DIR__ . '/concerts_weather.php';
require_once __DIR__ . '/contacts_followup_service.php';
require_once __DIR__ . '/advanced_notifications_service.php';
function businessModules(): array { return array_values(array_filter(['contacts','concerts','devis_factures','songs','expenses','accounting'], 'MusicbandModuleEnabled')); }
function businessRelations(string $module): array {
    $relations = [];
    if (in_array($module,['concerts','devis_factures'],true)) $relations['contact_id']=['module'=>'contacts','label'=>'Contact associé'];
    if ($module==='devis_factures') $relations['concert_id']=['module'=>'concerts','label'=>'Concert associé'];
    return $relations;
}
function businessConfig(string $module): array {
    if (!MusicbandModuleEnabled($module)) throw new RuntimeException('Module indisponible dans cette édition.');
    if ($module==='expenses') return ['title'=>'Dépenses','table'=>'expenses_records','required'=>'justification','fields'=>[
        'justification'=>'Justification','member'=>'Membre','expense_date'=>'Date','amount_cents'=>'Montant (€)','supplier_id'=>'Fournisseur','payment_method'=>'Paiement','reimbursed'=>'Remboursée','reimbursement_date'=>'Date de remboursement']];
    if ($module==='accounting') return ['title'=>'Comptabilité','table'=>'accounting_entries','required'=>'description','fields'=>[
        'description'=>'Description','entry_date'=>'Date bancaire / prestation','amount_cents'=>'Montant (€)','category'=>'Nature','received'=>'Argent reçu']];

    if($module==='songs')return ['title'=>'Morceaux','table'=>'setlist_songs','required'=>'title','fields'=>songFieldLabels()];
    if ($module === 'contacts') {
        $fields = ['nom' => 'Nom', 'contact' => 'Contact', 'mail' => 'Mail', 'tel' => 'Tel', 'adresse' => 'Adresse', 'statut' => 'StatusContact', 'commentaires' => 'ACTIONS / Commentaires'];
        if (contactsFollowupFields()) $fields['dernier_contact'] = 'Date du dernier contact';
        return ['title' => 'Contacts', 'table' => 'contacts_carnet_adresse', 'history' => 'contacts_revisions', 'required' => 'nom', 'fields' => $fields];
    }
    if ($module === 'concerts') { $config = ['title' => 'Concerts', 'table' => 'concerts_evenements', 'history' => 'concerts_revisions', 'required' => 'evenement', 'fields' => [
        'evenement' => 'Evenement', 'date_concert' => 'Date', 'adresse' => "Adresse de l'evenement", 'statut' => 'Status', 'client' => 'Client', 'contact' => 'Contact',
        'heure_arrivee' => "Heure d'arrivée", 'heure_balances' => 'Heure des balances', 'heure_prestation' => 'Heure de la prestation', 'duree' => 'Durée de la prestation (min.)', 'commentaires' => 'Commentaire']];
        if (documentsWorkflowConcertFields()) $config['fields'] += ['montant'=>'Montant cachet','reglement'=>'Mode Reglement'];
        return $config;
    }
    if ($module === 'devis_factures') return ['title'=>'Devis / Factures','table'=>'devis_factures_documents','history'=>'devis_factures_revisions','required'=>'numero','fields'=>[
        'type_document'=>'Type', 'numero'=>'Référence automatique', 'statut'=>'Statut', 'date_document'=>'Date du document', 'client'=>'Nom du client',
        'adresse'=>'Adresse de facturation', 'date_prestation'=>'Date de prestation', 'duree'=>'Durée de prestation', 'montant'=>'Montant saisi', 'reglement'=>'Mode de règlement', 'lien_document'=>'Référence du document existant', 'commentaires'=>'Commentaires']];
    throw new RuntimeException('Module inconnu.');
}
/** Combine environnement DB et réglages privés ; les réglages enregistrés prennent priorité, sans modifier l’environnement. */
function businessSettings(): array {
    $settings = [];
    foreach (['DB_SERVER','DB_NAME','DB_USER','DB_PASSWORD','DB_PORT','DB_SSL_CA'] as $key) if (getenv($key) !== false) $settings[$key] = getenv($key);
    return array_replace($settings, MusicbandRead('database_settings'));
}
function businessJson(array $data): string {
    return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function businessId($value, bool $optional = false): ?int {
    if ($optional && ($value === '' || $value === null)) return null;
    if (!is_string($value) || !ctype_digit($value) || strlen($value) > 15 || (int)$value < 1) throw new RuntimeException('Identifiant invalide.');
    return (int)$value;
}
function businessRecordTitle(string $module, array $row, array $config): string {
    if ($module === 'concerts') {
        $evt = trim((string)($row['evenement'] ?? ''));
        if ($evt !== '') return $evt;
        $ville = trim((string)($row['ville'] ?? ''));
        if ($ville !== '') return 'Concert · ' . $ville;
        return 'Concert #' . ($row['id'] ?? '');
    }
    if ($module === 'contacts') {
        $nom = trim((string)($row['nom'] ?? ''));
        if ($nom !== '') return $nom;
        return 'Contact #' . ($row['id'] ?? '');
    }
    if ($module === 'devis_factures') {
        $type = trim((string)($row['type_document'] ?? ''));
        $num = trim((string)($row['numero'] ?? ''));
        if ($type !== '' && $num !== '') return $type . ' ' . $num;
        if ($num !== '') return $num;
        if ($type !== '') return $type . ' #' . ($row['id'] ?? '');
        return 'Document #' . ($row['id'] ?? '');
    }
    return (string)($config['title'] ?? 'Fiche');
}
function businessValues(array $row, array $config, string $module): array {
    $values = [];
    foreach ($config['fields'] as $key => $_) $values[$key] = $row[$key] ?? null;
    foreach (businessRelations($module) as $key=>$_) $values[$key] = isset($row[$key]) ? (string)$row[$key] : null;
    if ($module==='concerts') foreach (concertsFieldsValues($row) as $name=>$value) {
        $key=fieldVisibilitySourceKey($module,(string)$name);
        if ($name!=='Row ID' && str_starts_with($key,'source:')) $values[$key]=concertsFieldsText($value);
    }
    return $values;
}
/** Empreinte des valeurs éditables et relations : permet de refuser une sauvegarde depuis une fiche périmée. */
function businessVersion(array $row, array $config, string $module): string {
    return hash('sha256', businessJson(businessValues($row, $config, $module)));
}
/** Traite le POST métier, vérifie droits/politique de champs et concurrence ; retourne l’identifiant sauvegardé. Les documents délèguent à leur workflow. */
function businessSave(string $module, array $config, array $user): int {
    MusicbandRequirePermission($module==='devis_factures'?MusicbandDocumentPermission((string)($_POST['action']??'save')):MusicbandBusinessPermission($module),$user);
    $db = MusicbandDatabase(businessSettings());
    fieldVisibilityUse($db);
    if ($module === 'contacts') contactsFollowupReady($db);
    $config=businessConfig($module);
    if ($module==='devis_factures' && !documentsReady($db)) throw new RuntimeException('Appliquer le schéma 005 complet avant de créer ou modifier un document.');
    if (!is_string($_POST['field_policy'] ?? null) || !hash_equals(fieldVisibilityVersion($module), $_POST['field_policy'])) throw new RuntimeException('Les champs affichés ont changé : conserver votre saisie et recharger la fiche avant enregistrement.');
    $id = businessId($_POST['id'] ?? '', true);
    if ($module==='devis_factures') return documentsWorkflowSave($db,$user,$id);
    $extraDefinitions=$module==='concerts' && concertsFieldsReady($db)?concertsFieldsDefinitions($db):null;
    $creationToken=$id===null?businessCreationToken():null;
    $values = [];
    foreach ($config['fields'] as $key => $label) {
        if (!fieldVisibilityVisible($module,$key)) continue;
        $value = $_POST[$key] ?? '';
        $max = $key === 'commentaires' ? 50000 : 12000;
        if (!is_string($value) || strlen($value) > $max || str_contains($value, "\0")) throw new RuntimeException('Champ trop long ou invalide : ' . $label);
        $values[$key] = $value === '' ? null : $value;
    }
    if ($module === 'contacts' && array_key_exists('dernier_contact', $values)) {
        if ($values['dernier_contact'] !== null && $values['dernier_contact'] !== '') {
            $dc = str_replace('T', ' ', (string)$values['dernier_contact']);
            if (strlen($dc) === 16) $dc .= ':00';
            $values['dernier_contact'] = $dc;
        } else {
            $values['dernier_contact'] = null;
        }
    }
    if (trim($values[$config['required']] ?? '') === '') throw new RuntimeException('Le champ ' . $config['fields'][$config['required']] . ' est requis.');
    foreach (businessRelations($module) as $key=>$_) if (fieldVisibilityVisible($module,$key)) $values[$key] = businessId($_POST[$key] ?? '', true);
    // Vérifier le journal AVANT toute écriture, notamment si Upgrade n’a pas été appliqué.
    $db->query('SELECT id FROM ' . $config['history'] . ' LIMIT 0');
    $db->beginTransaction();
    try {
        if ($creationToken!==null) { $existing=businessExistingCreation($db,$module,$creationToken); if ($existing!==null) { $db->rollBack(); return $existing; } }
        if ($module==='concerts' && fieldVisibilityVisible($module,'contact_id')) $values['contact_id']=businessInlineContact($db,$user);
        foreach (businessRelations($module) as $key=>$relation) if (($values[$key] ?? null) !== null) {
            $parent=businessConfig($relation['module']);
            $check=$db->prepare('SELECT id FROM '.$parent['table'].' WHERE id=? FOR UPDATE'); $check->execute([$values[$key]]);
            if (!$check->fetch()) throw new RuntimeException($relation['label'].' introuvable.');
        }
        $before = [];
        if ($id !== null) {
            $select = $db->prepare('SELECT * FROM ' . $config['table'] . ' WHERE id=? FOR UPDATE'); $select->execute([$id]); $old = $select->fetch();
            if (!$old) throw new RuntimeException('Fiche introuvable.');
            if($module==='concerts'&&businessConcertIsPast($old)&&!($user['admin']??false))throw new RuntimeException('Seul un administrateur peut modifier un concert passé.');
            if (!is_string($_POST['version'] ?? null) || !hash_equals(businessVersion($old, $config, $module), $_POST['version'])) throw new RuntimeException('Cette fiche a changé depuis son ouverture. Conserver votre saisie et recharger la fiche avant de rapprocher les modifications.');
            // Une simple réouverture ne doit pas réécrire les formats historiques.
            if ($module==='concerts') foreach (['date_concert','heure_arrivee','heure_balances','heure_prestation'] as $key) {
                if (!isset($values[$key])) continue;
                $imported=businessDateImported($old);
                $previous=businessTemporalValue($key,(string)($old[$key]??''),$imported);
                if ($previous!==null && businessTemporalValue($key,$values[$key],$imported)===$previous) $values[$key]=$old[$key];
            }
            if ($module==='concerts') {
                foreach ($config['fields'] as $key=>$_) if (isset($values[$key]) && in_array($old[$key]??null,['Y','N'],true) && !in_array($values[$key],['Y','N'],true)) throw new RuntimeException('Une case doit valoir Y ou N.');
                if ($extraDefinitions!==null) $values['extra_fields_json']=concertsFieldsSave($extraDefinitions,$old);
            }
            $before = businessValues($old, $config, $module);
            $set = implode(',', array_map(fn($key) => $key . '=?', array_keys($values)));
            $query = $db->prepare('UPDATE ' . $config['table'] . ' SET ' . $set . ' WHERE id=?'); $query->execute([...array_values($values), $id]);
        } else {
            if ($extraDefinitions!==null) $values['extra_fields_json']=concertsFieldsSave($extraDefinitions,['source_json'=>'{}']);
            $key = $creationToken;
            $record = ['source_identity' => hash('sha256', 'Musicband-manual-' . $module), 'source_table' => 'manual', 'source_key' => $key,
                'source_key_hash' => hash('sha256', $key), 'source_sha256' => hash('sha256', '{}'), ...$values, 'source_json' => '{}'];
            $query = $db->prepare('INSERT INTO ' . $config['table'] . ' (' . implode(',', array_keys($record)) . ',imported_at) VALUES (' . implode(',', array_fill(0, count($record), '?')) . ',UTC_TIMESTAMP(6))');
            $query->execute(array_values($record)); $id = (int)$db->lastInsertId();
        }
        $audit = $db->prepare('INSERT INTO ' . $config['history'] . ' (record_id,edited_by,edited_at,before_json,after_json) VALUES (?,?,UTC_TIMESTAMP(6),?,?)');
        $audit->execute([$id, $user['login'], businessJson($before), businessJson(businessValues(isset($old)?array_replace($old,$values):$record,$config,$module))]);
        $db->commit();
        MusicbandAuditEvent($module,'record.saved','success',['id'=>$id,'fields'=>array_keys($values)]);
        if($module==='concerts')concertsWeatherInvalidate($id);
        if (function_exists('advancedNotificationDispatchEvent')) {
            if ($module === 'concerts') {
                $eventKey = ($creationToken !== null) ? 'concerts.create' : 'concerts.edit';
                advancedNotificationDispatchEvent($eventKey, 'concerts', (string)$id, $before, $values, $user['login']);
            } elseif ($module === 'contacts') {
                advancedNotificationDispatchEvent('contacts.edit', 'contacts', (string)$id, $before, $values, $user['login']);
            }
        }
        return $id;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function businessDisplayValue(string $module,string $key,$value,array $row=[]): string {
    $text=(string)($value??'');
    if($module==='accounting'){
        if($key==='entry_date')return MusicbandDisplayDate($text);
        if($key==='received')return $value?'Oui':'Non';
        if($key==='amount_cents')return intdiv((int)$value,100).','.str_pad((string)((int)$value%100),2,'0',STR_PAD_LEFT).' €';
    }
    if($key==='dernier_contact')return $text===''?'—':MusicbandDisplayDate($text,true);
    if(in_array($key,['date_concert','date_document','date_prestation'],true)){
        $date=$key==='date_concert'?businessConcertDate($text,businessListSettings(),businessDateImported($row)):businessTemporalValue('date_concert',$text);
        return $date===null?$text:MusicbandDisplayDate($date);
    }
    if(str_starts_with($key,'heure_')){$time=businessTemporalValue($key,$text);return $time===null?$text:substr($time,0,5);}
    if(str_ends_with($key,'_at'))return MusicbandDisplayDate($text,true,true);
    if(in_array($key,['title','name'],true))return MusicbandDisplayGeneratedLabel($text);
    return $text;
}
function businessTemporalValue(string $key, string $value, bool $imported = false): ?string {
    $value=trim($value);
    if ($value==='') return '';
    if ($key==='date_concert') return businessConcertDate($value,businessListSettings(),$imported);
    if (!in_array($key,['heure_arrivee','heure_balances','heure_prestation'],true)) return null;
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM)?$/iD',$value,$parts)) return null;
    $hour=(int)$parts[1]; $minute=(int)$parts[2]; $second=(int)($parts[3]??0); $period=strtoupper($parts[4]??'');
    if ($minute>59 || $second>59 || ($period!=='' ? ($hour<1 || $hour>12) : $hour>23)) return null;
    if ($period!=='') $hour=$hour%12+($period==='PM'?12:0);
    return sprintf('%02d:%02d:%02d',$hour,$minute,$second);
}
function businessEditorField(string $key, string $label, string $value, bool $required, bool $imported = false, bool $checkbox = false): string {
    if ($checkbox) return concertsFieldsCheckbox($key,$label,$value);
    if ($key === 'dernier_contact') {
        $val = substr(str_replace(' ', 'T', (string)$value), 0, 16);
        return '<label>'.MusicbandEscape($label).'<input name="dernier_contact" type="datetime-local" value="'.MusicbandEscape($val).'"><small>Date du dernier contact effectif (mise à jour automatiquement lors des relances et contacts directs).</small></label>';
    }
    $temporal=$key==='date_concert' || in_array($key,['heure_arrivee','heure_balances','heure_prestation'],true);
    $attributes=$required?' required':'';
    if ($temporal) {
        $normalized=businessTemporalValue($key,$value,$imported);
        $type=$normalized===null?'text':($key==='date_concert'?'date':'time');
        $note=$normalized===null?'<small>Valeur importée non reconnue : conservée telle quelle, à corriger explicitement.</small>':'';
        return '<label>'.MusicbandEscape($label).'<input name="'.$key.'" type="'.$type.'"'.($type==='time'?' step="1"':'').' value="'.MusicbandEscape($normalized??$value).'"'.$attributes.'>'.$note.'</label>';
    }
    return '<label>'.MusicbandEscape($label).'<textarea name="'.$key.'" maxlength="'.($key==='commentaires'?'50000':'12000').'"'.$attributes.'>'.MusicbandEscape($value).'</textarea></label>';
}
function businessSourceFields(PDO $db, string $module, array $row): string {
    $source = $module==='concerts'?concertsFieldsValues($row):json_decode($row['source_json'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
    $html = '<section><h3>Champs supplémentaires</h3><dl>';
    foreach (fieldVisibilityRegistry($db,$module) as $field=>$label) {
        if (!str_starts_with($field,'source:') || !fieldVisibilityVisible($module,$field)) continue;
        $key = substr($label,0,-strlen(' · source'));
        if ($key === 'Row ID') continue;
        $value = $source[$key] ?? null;
        $text = is_scalar($value) || $value === null ? (string)$value : businessJson($value);
        $html .= '<dt>'.MusicbandEscape(fieldDisplayLabel($module,$field,(string)$key)).'</dt><dd>'.($module==='concerts'?concertsFieldsDisplay($value):($text === '' ? '—' : MusicbandEscape($text))).'</dd>';
    }
    return $html.'</dl></section>';
}
/** Orchestre la requête du module : permissions, actions, puis rendu de liste ou fiche. Les helpers partagent le même catalogue de champs. */
function businessRun(string $module): void {
    if (!MusicbandModuleEnabled($module)) MusicbandFail('Module indisponible dans cette édition.', 404);
    $sources = $module==='devis_factures' && $_SERVER['REQUEST_METHOD']==='GET' && ($_GET['sources']??'')==='1';
    $relations = $module==='concerts' && $_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['related_setlist']);
    $weather = $module==='concerts' && $_SERVER['REQUEST_METHOD']==='GET' && (($_GET['weather']??'')==='1' || ($_GET['ajax']??'')==='weather');
    $ajax = $relations || $sources || $weather || ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') === '1');
    $user = MusicbandRequireLogin($ajax); $config = businessConfig($module);
    $listScript = null; $photonUrl = '';
    $recordLabel = $module === 'contacts' ? 'contact' : ($module === 'concerts' ? 'concert' : 'document');
    if ($ajax) {
        session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        try { $db=MusicbandDatabase(businessSettings()); fieldVisibilityUse($db); if ($module === 'contacts') contactsFollowupReady($db); $config=businessConfig($module);
            if ($relations) {
                if (!is_string($_GET['related_setlist'])) throw new RuntimeException('Publication invalide.');
                echo businessJson(['html'=>businessRelatedPublication($db,$_GET['related_setlist'])]);
            } elseif ($sources) {
                if (!documentsWorkflowReady($db)) throw new RuntimeException('Schéma 006 requis.');
                echo businessJson(['html'=>documentsSourcePreview(documentsPreviewValues($db,businessId($_GET['contact_id']??'',true),businessId($_GET['concert_id']??'',true)))]);
            } elseif ($weather) {
                $concertId = businessId($_GET['id'] ?? '', true);
                if ($concertId === null) throw new RuntimeException('Identifiant de concert manquant.');
                echo businessJson(concertsWeatherGet($db, $concertId));
            } else echo businessJson(['html'=>businessListResults($db, $module, $config, businessListSettings())]); }
        catch (Throwable $e) { http_response_code(503); echo businessJson(['error'=>$relations?'Relations indisponibles : vérifier la session, MySQL et les contraintes SQL.':($sources?'Aperçu indisponible : vérifier le schéma 006, les fiches liées et la session.':($weather?'Météo indisponible : '.MusicbandEscape($e->getMessage()):'Recherche indisponible : vérifier les paramètres, la session et MySQL.'))]); }
        return;
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 250000) MusicbandFail('Formulaire trop volumineux.', 413);
    $base = MusicbandBase(); $route = $base . $module . '.php'; $error = null; $posted = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            MusicbandCsrf();
            if ($module === 'contacts' && in_array($_POST['action'] ?? '', ['contact_email_send', 'contact_messenger_confirm', 'contact_note_add'], true)) {
                $db = MusicbandDatabase(businessSettings());
                contactsFollowupReady($db);
                $id = businessId($_POST['id'] ?? '', false);
                $actionUser = MusicbandRequireLogin();
                MusicbandRequirePermission('contacts.edit', $actionUser);
                if ($_POST['action'] === 'contact_email_send') {
                    $recipient = (string)($_POST['email_recipient'] ?? '');
                    $subject = (string)($_POST['email_subject'] ?? '');
                    $message = (string)($_POST['email_message'] ?? '');
                    contactsFollowupSendEmail($db, $id, $actionUser, $recipient, $subject, $message);
                    header('Location: ' . $route . '?id=' . $id . '&followup=email_sent', true, 303);
                    exit;
                }
                if ($_POST['action'] === 'contact_messenger_confirm') {
                    // Refuser aussi les anciens formulaires, sans modifier l’historique existant.
                    throw new RuntimeException('Le contact par Messenger est désactivé.');
                }
                if ($_POST['action'] === 'contact_note_add') {
                    $comment = (string)($_POST['note_comment'] ?? '');
                    $isDirect = !empty($_POST['is_direct_contact']);
                    $contactedAt = !empty($_POST['contacted_at']) ? (string)$_POST['contacted_at'] : null;
                    contactsFollowupAddNote($db, $id, $actionUser, $comment, $isDirect, $contactedAt);
                    header('Location: ' . $route . '?id=' . $id . '&followup=note_added', true, 303);
                    exit;
                }
            }
            if ($module==='devis_factures' && in_array($_POST['action']??'',['document_generate','document_send'],true)) {
                $id=MusicbandLocked(fn()=>documentsDeliveryAction(MusicbandRequireLogin()));
                MusicbandAuditEvent($module,MusicbandAuditText($_POST['action']??'save',64),'accepted',['id'=>$id]);
                header('Location: '.$route.'?id='.$id.'&saved=1',true,303); exit;
            }
            if ($module==='concerts' && ($_POST['action']??'')==='publication_send') {
                $id=MusicbandLocked(fn()=>concertsPublicationSend(MusicbandRequireLogin()));
                MusicbandAuditEvent($module,MusicbandAuditText($_POST['action']??'save',64),'success',['id'=>$id]);
                header('Location: '.$route.'?id='.$id.'&publication=1',true,303);exit;
            }
            if ($module==='concerts' && ($_POST['action']??'')==='calendar_send') {
                $id=MusicbandLocked(fn()=>concertsCalendarSend(MusicbandRequireLogin()));
                MusicbandAuditEvent($module,MusicbandAuditText($_POST['action']??'save',64),'accepted',['id'=>$id]);
                header('Location: '.$route.'?id='.$id.'&calendar=1',true,303); exit;
            }
            $id = MusicbandLocked(function() use ($module, $config) { return $module==='concerts' && in_array($_POST['action']??'',['link_setlist','unlink_setlist'],true) ? concertSetlistSave(MusicbandRequireLogin()) : businessSave($module, $config, MusicbandRequireLogin()); });
            MusicbandAuditEvent($module,MusicbandAuditText($_POST['action']??'save',64),'success',['id'=>$id]);
            header('Location: ' . $route . '?id=' . $id . '&saved=1', true, 303); exit;
        } catch (Throwable $e) {
            MusicbandAuditEvent($module,MusicbandAuditText($_POST['action']??'save',64),'rejected');
            $error = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Enregistrement impossible : vérifier MySQL et le schéma requis (003 pour Contacts/Concerts, 005/006 pour Devis/Factures) dans Upgrade. Aucune modification partielle validée.';
            $posted = !($module==='concerts' && in_array($_POST['action']??'',['calendar_send','publication_send'],true)) && !($module==='concerts' && in_array($_POST['action']??'',['link_setlist','unlink_setlist'],true)) && !($module==='devis_factures' && ($_POST['action']??'save')!=='save') && !($module==='contacts' && in_array($_POST['action']??'',['contact_email_send','contact_messenger_confirm','contact_note_add'],true));
        }
    }
    $content = ''.MusicbandStylesheet('business').''
        . '<details class="business-instructions"><summary>Instructions</summary><ol><li>Taper dans Rechercher pour retrouver une fiche : la liste se met à jour toute seule.</li><li>Choisir les colonnes à afficher, puis cliquer sur un nom pour ouvrir la fiche.</li><li>Utiliser Nouveau ou Modifier si le bouton est proposé. Pour un concert ou un document, choisir le client et les informations liées.</li><li>Cliquer sur Enregistrer pour garder les changements, ou Annuler pour revenir sans les garder.</li></ol></details>';
    if ($error !== null) $content .= '<p class="notice" role="alert">' . MusicbandEscape($error) . '</p>';
    try {
        $db = MusicbandDatabase(businessSettings());
        fieldVisibilityUse($db);
        if ($module === 'contacts') contactsFollowupReady($db);
        $config=businessConfig($module);
        $id = businessId($_SERVER['REQUEST_METHOD']==='POST' ? ($_POST['id'] ?? '') : ($_GET['id'] ?? ''), true);
        $edit = $posted || (isset($_GET['edit']) && MusicbandCan(MusicbandBusinessPermission($module),$user));
        if ($edit) MusicbandRequirePermission(MusicbandBusinessPermission($module),$user);
        $listScript = null;
        $pageTitle = $config['title'];
        if ($id !== null || $edit) {
            $row = [];
            if ($id !== null) { $q = $db->prepare('SELECT * FROM ' . $config['table'] . ' WHERE id=?'); $q->execute([$id]); $row = $q->fetch(); if (!$row) throw new RuntimeException('Fiche introuvable.'); }
            if ($id !== null && $row) {
                $recTitle = businessRecordTitle($module, $row, $config);
                $pageTitle = $edit ? ('Modifier : ' . $recTitle) : $recTitle;
            } elseif ($edit && $id === null) {
                $pageTitle = 'Nouveau ' . $recordLabel;
            }
            if($edit&&$module==='concerts'&&$row&&businessConcertIsPast($row)&&!($user['admin']??false))throw new RuntimeException('Seul un administrateur peut modifier un concert passé.');
            $content .= '<p><a href="' . MusicbandEscape($route) . '">← Liste ' . $config['title'] . '</a></p>';
            if ($edit) {
                if ($module==='devis_factures' && $row && !in_array(documentsWorkflowState($row),['Préparation',''],true)) throw new RuntimeException('Cette fiche est figée : revenir à la consultation pour son cycle de validation.');
                $content .= '<h3>' . ($id !== null ? 'Modifier le ' : 'Créer un ') . $recordLabel . '</h3><p>Enregistrer conserve la fiche et son historique. Annuler abandonne uniquement la saisie en cours.</p>';
                $version = $posted ? ($_POST['version'] ?? '') : ($row ? businessVersion($row, $config, $module) : '');
                $policyVersion = $posted ? ($_POST['field_policy'] ?? '') : fieldVisibilityVersion($module);
                $photonUrl=MusicbandPhotonUrl();
                $creationToken=$posted?($_POST['creation_token']??''):bin2hex(random_bytes(16));
                $content .= '<form data-business-editor="' . $module . '" data-photon-url="' . MusicbandEscape($photonUrl) . '" method="post" action="' . MusicbandEscape($route) . '"><input type="hidden" name="csrf" value="' . MusicbandEscape($_SESSION['csrf']) . '"><input type="hidden" name="id" value="' . ($id ?? '') . '"><input type="hidden" name="version" value="' . MusicbandEscape(is_string($version) ? $version : '') . '"><input type="hidden" name="field_policy" value="' . MusicbandEscape(is_string($policyVersion) ? $policyVersion : '') . '"><input type="hidden" name="creation_token" value="' . MusicbandEscape(is_string($creationToken)?$creationToken:'') . '">';
                if ($module==='devis_factures' && !documentsWorkflowReady($db)) $content .= '<p class="notice">Créer le schéma 006 dans Upgrade avant le premier enregistrement de ce parcours.</p>';
                if ($module==='devis_factures') $content .= documentsWorkflowForm($db,$row,$posted);
                else {
                    $parts=[];
                    if ($module==='concerts' && fieldVisibilityVisible($module,'contact_id')) $parts['contact_id']=businessClientForm($db,$row,$posted,$module);
                    foreach ($config['fields'] as $key=>$label) {
                        if (!fieldVisibilityVisible($module,$key)) continue;
                        $value=$posted?($_POST[$key]??''):($row[$key]??'');
                        $parts[$key]=businessEditorField($key,fieldDisplayLabel($module,$key,$label),is_string($value)?$value:'',$key===$config['required'],!$posted && $module==='concerts' && businessDateImported($row??[]),$module==='concerts' && in_array($value,['Y','N'],true));
                    }
                    if ($module==='concerts') {
                        $extra=concertsFieldsForm($db,$row??[],$posted,true);
                        $content.=$extra['metadata']; $parts+=$extra['fields'];
                    }
                    $content.=fieldLayoutRender($module,'edit',$parts);
                }
                $listScript=base64_decode('LyogRm9ybXVsYWlyZXMgbcOpdGllciA6IGF1Y3VuIHN0b2NrYWdlIGRlIGNvb3Jkb25uw6llcyBkYW5zIGxlIG5hdmlnYXRldXIuICovCigoKSA9PiB7CiAgY29uc3QgZm9ybSA9IGRvY3VtZW50LnF1ZXJ5U2VsZWN0b3IoJ1tkYXRhLWJ1c2luZXNzLWVkaXRvcl0nKTsKICBpZiAoIWZvcm0pIHJldHVybjsKICBmb3IgKGNvbnN0IGNoZWNrYm94IG9mIGZvcm0ucXVlcnlTZWxlY3RvckFsbCgnW2RhdGEtYm9vbGVhbi10b3VjaF0nKSkgewogICAgaWYgKGNoZWNrYm94Lmhhc0F0dHJpYnV0ZSgnZGF0YS1lbXB0eS1ib29sZWFuJykpIGNoZWNrYm94LmluZGV0ZXJtaW5hdGUgPSB0cnVlOwogICAgY2hlY2tib3guYWRkRXZlbnRMaXN0ZW5lcignY2hhbmdlJywgKCkgPT4gewogICAgICBjb25zdCB0b3VjaGVkID0gZm9ybS5lbGVtZW50cy5uYW1lZEl0ZW0oY2hlY2tib3guZGF0YXNldC5ib29sZWFuVG91Y2gpOwogICAgICBpZiAodG91Y2hlZCkgdG91Y2hlZC52YWx1ZSA9ICcxJzsKICAgICAgY2hlY2tib3guY2xvc2VzdCgnbGFiZWwnKT8ucXVlcnlTZWxlY3Rvcignc21hbGwnKT8ucmVtb3ZlKCk7CiAgICB9KTsKICB9CiAgaWYgKGZvcm0uZGF0YXNldC5waG90b25VcmwpIHsKICAgIGZvciAoY29uc3QgZmllbGQgb2YgZm9ybS5xdWVyeVNlbGVjdG9yQWxsKCd0ZXh0YXJlYVtuYW1lPSJhZHJlc3NlIl0saW5wdXRbbmFtZT0iYWRyZXNzZSJdLGlucHV0W25hbWU9Im5ld19jbGllbnRfYWRyZXNzZSJdLFtkYXRhLXBob3Rvbi1hZGRyZXNzXScpKSB7CiAgICAgIGNvbnN0IGJveCA9IGRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ2RpdicpOwogICAgICBjb25zdCBub3RlID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgncCcpOwovKiAgICAgICBub3RlLmFwcGVuZCgnU3VnZ2VzdGlvbnMgUGhvdG9uIGxvcnMgZGUgbGEgc2Fpc2llIMK3ICcpOwogICAgICBjb25zdCBjcmVkaXQgPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdhJyk7CiAgICAgIGNyZWRpdC5ocmVmID0gJ2h0dHBzOi8vd3d3Lm9wZW5zdHJlZXRtYXAub3JnL2NvcHlyaWdodCc7CiAgICAgIGNyZWRpdC50ZXh0Q29udGVudCA9ICfCqSBjb250cmlidXRldXJzIE9wZW5TdHJlZXRNYXAnOyBjcmVkaXQudGFyZ2V0ID0gJ19ibGFuayc7IGNyZWRpdC5yZWwgPSAnbm9vcGVuZXIgbm9yZWZlcnJlcic7CiAgICAgIG5vdGUuYXBwZW5kKGNyZWRpdCk7ICovCiAgICAgIGNvbnN0IHN0YXR1cyA9IGRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ3AnKTsgc3RhdHVzLnNldEF0dHJpYnV0ZSgncm9sZScsICdzdGF0dXMnKTsKICAgICAgY29uc3QgcmVzdWx0cyA9IGRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ2RpdicpOyByZXN1bHRzLnNldEF0dHJpYnV0ZSgnYXJpYS1sYWJlbCcsICdBZHJlc3NlcyBwcm9wb3PDqWVzJyk7CiAgICAgIGJveC5hcHBlbmQobm90ZSwgc3RhdHVzLCByZXN1bHRzKTsKICAgICAgY29uc3QgbGFiZWwgPSBmaWVsZC5jbG9zZXN0KCdsYWJlbCcpOwogICAgICBjb25zdCBncm91cCA9IGRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ2RpdicpOwogICAgICBsYWJlbC5iZWZvcmUoZ3JvdXApOyBncm91cC5hcHBlbmQobGFiZWwsIGJveCk7CiAgICAgIGxldCBzZWxlY3RpbmcgPSBmYWxzZSwgdGltZXIsIGNvbnRyb2xsZXIsIGdlbmVyYXRpb24gPSAwOwogICAgICBjb25zdCBjbGVhciA9ICgpID0+IHsKICAgICAgICBjbGVhclRpbWVvdXQodGltZXIpOyBjb250cm9sbGVyPy5hYm9ydCgpOyArK2dlbmVyYXRpb247CiAgICAgICAgcmVzdWx0cy5yZXBsYWNlQ2hpbGRyZW4oKTsgc3RhdHVzLnRleHRDb250ZW50ID0gJyc7CiAgICAgIH07CiAgICAgIGNvbnN0IHNlYXJjaCA9IGFzeW5jICgpID0+IHsKICAgICAgICBpZiAoZmllbGQuZGlzYWJsZWQgfHwgIWZpZWxkLmlzQ29ubmVjdGVkKSByZXR1cm47CiAgICAgICAgY29uc3QgcXVlcnkgPSBmaWVsZC52YWx1ZS50cmltKCk7CiAgICAgICAgaWYgKHF1ZXJ5Lmxlbmd0aCA8IDMgfHwgcXVlcnkubGVuZ3RoID4gMjAwKSByZXR1cm47CiAgICAgICAgY29udHJvbGxlciA9IG5ldyBBYm9ydENvbnRyb2xsZXIoKTsgY29uc3QgcGVuZGluZyA9IGNvbnRyb2xsZXI7IGNvbnN0IGN1cnJlbnQgPSBnZW5lcmF0aW9uOwogICAgICAgIGNvbnN0IHRpbWVvdXQgPSBzZXRUaW1lb3V0KCgpID0+IHBlbmRpbmcuYWJvcnQoKSwgNzAwMCk7CiAgICAgICAgY29uc3QgdXJsID0gbmV3IFVSTChmb3JtLmRhdGFzZXQucGhvdG9uVXJsKTsKICAgICAgICB1cmwuc2VhcmNoUGFyYW1zLnNldCgncScsIHF1ZXJ5KTsgdXJsLnNlYXJjaFBhcmFtcy5zZXQoJ2xpbWl0JywgJzUnKTsgdXJsLnNlYXJjaFBhcmFtcy5zZXQoJ2xhbmcnLCAnZnInKTsKICAgICAgICBzdGF0dXMudGV4dENvbnRlbnQgPSAnUmVjaGVyY2hlIGTigJlhZHJlc3Nlc+KApic7CiAgICAgICAgdHJ5IHsKICAgICAgICAgIGNvbnN0IHJlc3BvbnNlID0gYXdhaXQgZmV0Y2godXJsLCB7c2lnbmFsOiBwZW5kaW5nLnNpZ25hbCwgY3JlZGVudGlhbHM6ICdvbWl0JywgY2FjaGU6ICduby1zdG9yZScsIHJlZmVycmVyUG9saWN5OiAnbm8tcmVmZXJyZXInLCByZWRpcmVjdDogJ2Vycm9yJ30pOwogICAgICAgICAgaWYgKCFyZXNwb25zZS5vaykgdGhyb3cgbmV3IEVycm9yKCdTZXJ2aWNlIGluZGlzcG9uaWJsZScpOwogICAgICAgICAgY29uc3QgZGF0YSA9IGF3YWl0IHJlc3BvbnNlLmpzb24oKTsKICAgICAgICAgIGlmIChjdXJyZW50ICE9PSBnZW5lcmF0aW9uIHx8IGZpZWxkLmRpc2FibGVkKSByZXR1cm47CiAgICAgICAgICBpZiAoIUFycmF5LmlzQXJyYXkoZGF0YS5mZWF0dXJlcykpIHRocm93IG5ldyBFcnJvcignUsOpcG9uc2UgaW52YWxpZGUnKTsKICAgICAgICAgIGNvbnN0IGxhYmVscyA9IG5ldyBTZXQoKTsKICAgICAgICAgIGZvciAoY29uc3QgZmVhdHVyZSBvZiBkYXRhLmZlYXR1cmVzLnNsaWNlKDAsIDUpKSB7CiAgICAgICAgICAgIGNvbnN0IHAgPSBmZWF0dXJlPy5wcm9wZXJ0aWVzOwogICAgICAgICAgICBpZiAoIXAgfHwgdHlwZW9mIHAgIT09ICdvYmplY3QnKSBjb250aW51ZTsKICAgICAgICAgICAgY29uc3QgdGV4dCA9IGtleSA9PiB0eXBlb2YgcFtrZXldID09PSAnc3RyaW5nJyB8fCB0eXBlb2YgcFtrZXldID09PSAnbnVtYmVyJyA/IFN0cmluZyhwW2tleV0pLnRyaW0oKSA6ICcnOwogICAgICAgICAgICBjb25zdCBzdHJlZXQgPSBbdGV4dCgnaG91c2VudW1iZXInKSwgdGV4dCgnc3RyZWV0JyldLmZpbHRlcihCb29sZWFuKS5qb2luKCcgJyk7CiAgICAgICAgICAgIGNvbnN0IGxhYmVsID0gWy4uLm5ldyBTZXQoW3RleHQoJ25hbWUnKSAhPT0gdGV4dCgnc3RyZWV0JykgPyB0ZXh0KCduYW1lJykgOiAnJywgc3RyZWV0LAogICAgICAgICAgICAgIFt0ZXh0KCdwb3N0Y29kZScpLCB0ZXh0KCdjaXR5JykgfHwgdGV4dCgndG93bicpIHx8IHRleHQoJ3ZpbGxhZ2UnKV0uZmlsdGVyKEJvb2xlYW4pLmpvaW4oJyAnKSwgdGV4dCgnY291bnRyeScpXS5maWx0ZXIoQm9vbGVhbikpXS5qb2luKCcsICcpOwogICAgICAgICAgICBpZiAoIWxhYmVsIHx8IGxhYmVsLmxlbmd0aCA+IDEwMDAgfHwgbGFiZWxzLmhhcyhsYWJlbCkpIGNvbnRpbnVlOwogICAgICAgICAgICBsYWJlbHMuYWRkKGxhYmVsKTsKICAgICAgICAgICAgY29uc3QgY2hvaWNlID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgnYnV0dG9uJyk7IGNob2ljZS50eXBlID0gJ2J1dHRvbic7IGNob2ljZS50ZXh0Q29udGVudCA9IGxhYmVsOwogICAgICAgICAgICBjaG9pY2UuYWRkRXZlbnRMaXN0ZW5lcignY2xpY2snLCAoKSA9PiB7CiAgICAgICAgICAgICAgaWYgKGZpZWxkLmRpc2FibGVkKSByZXR1cm47CiAgICAgICAgICAgICAgY2xlYXIoKTsgc2VsZWN0aW5nID0gdHJ1ZTsgZmllbGQudmFsdWUgPSBsYWJlbDsKICAgICAgICAgICAgICBmaWVsZC5kaXNwYXRjaEV2ZW50KG5ldyBFdmVudCgnaW5wdXQnLCB7YnViYmxlczogdHJ1ZX0pKTsKICAgICAgICAgICAgICBmaWVsZC5kaXNwYXRjaEV2ZW50KG5ldyBFdmVudCgnY2hhbmdlJywge2J1YmJsZXM6IHRydWV9KSk7CiAgICAgICAgICAgICAgc2VsZWN0aW5nID0gZmFsc2U7IGZpZWxkLmZvY3VzKCk7IHN0YXR1cy50ZXh0Q29udGVudCA9ICdBZHJlc3NlIHPDqWxlY3Rpb25uw6llIDsgdsOpcmlmaWVyIHB1aXMgZW5yZWdpc3RyZXIgbGEgZmljaGUuJzsKICAgICAgICAgICAgfSk7CiAgICAgICAgICAgIHJlc3VsdHMuYXBwZW5kKGNob2ljZSk7CiAgICAgICAgICB9CiAgICAgICAgICBzdGF0dXMudGV4dENvbnRlbnQgPSBsYWJlbHMuc2l6ZSA/ICdDaG9pc2lzc2V6IHVuZSBhZHJlc3NlIHByb3Bvc8OpZSBvdSBjb25zZXJ2ZXogdm90cmUgc2Fpc2llLicgOiAnQXVjdW5lIGFkcmVzc2UgcHJvcG9zw6llLiBWb3VzIHBvdXZleiBjb25zZXJ2ZXIgdm90cmUgc2Fpc2llLic7CiAgICAgICAgfSBjYXRjaCAoZXJyb3IpIHsKICAgICAgICAgIGlmIChjdXJyZW50ID09PSBnZW5lcmF0aW9uKSBzdGF0dXMudGV4dENvbnRlbnQgPSAnU3VnZ2VzdGlvbnMgaW5kaXNwb25pYmxlcy4gTGEgc2Fpc2llIG1hbnVlbGxlIHJlc3RlIHBvc3NpYmxlLic7CiAgICAgICAgfSBmaW5hbGx5IHsgY2xlYXJUaW1lb3V0KHRpbWVvdXQpOyB9CiAgICAgIH07CiAgICAgIGZpZWxkLmFkZEV2ZW50TGlzdGVuZXIoJ2lucHV0JywgKCkgPT4gewogICAgICAgIGlmIChzZWxlY3RpbmcpIHJldHVybjsKICAgICAgICBjbGVhcigpOyB0aW1lciA9IHNldFRpbWVvdXQoc2VhcmNoLCA2MDApOwogICAgICB9KTsKICAgICAgZmllbGQuYWRkRXZlbnRMaXN0ZW5lcigna2V5ZG93bicsIGV2ZW50ID0+IHsgaWYgKGV2ZW50LmtleSA9PT0gJ0VzY2FwZScpIGNsZWFyKCk7IH0pOwogICAgICBmb3JtLmVsZW1lbnRzLm5hbWVkSXRlbSgnY2xpZW50X21vZGUnKT8uYWRkRXZlbnRMaXN0ZW5lcignY2hhbmdlJywgY2xlYXIpOwogICAgfQogIH0KICBjb25zdCB0eXBlID0gZm9ybS5xdWVyeVNlbGVjdG9yKCdzZWxlY3RbbmFtZT0idHlwZV9kb2N1bWVudCJdJyk7CiAgY29uc3QgcmVmZXJlbmNlID0gZm9ybS5xdWVyeVNlbGVjdG9yKCdbZGF0YS1yZWZlcmVuY2UtaGludF0nKTsKICBjb25zdCB1cGRhdGVSZWZlcmVuY2UgPSAoKSA9PiB7IGlmIChyZWZlcmVuY2UgJiYgdHlwZSkgcmVmZXJlbmNlLnRleHRDb250ZW50ID0gdHlwZS52YWx1ZSArICcgJyArIHJlZmVyZW5jZS5kYXRhc2V0LnllYXIgKyAnLeKApiAoYXR0cmlidcOpZSBhdSBwcmVtaWVyIGVucmVnaXN0cmVtZW50KSc7IH07CiAgdHlwZT8uYWRkRXZlbnRMaXN0ZW5lcignY2hhbmdlJyx1cGRhdGVSZWZlcmVuY2UpOwogIHVwZGF0ZVJlZmVyZW5jZSgpOwogIGNvbnN0IG1vZGUgPSBmb3JtLmVsZW1lbnRzLm5hbWVkSXRlbSgnY2xpZW50X21vZGUnKTsKICBjb25zdCBjb250YWN0ID0gZm9ybS5lbGVtZW50cy5uYW1lZEl0ZW0oJ2NvbnRhY3RfaWQnKTsKICBjb25zdCBjb25jZXJ0ID0gZm9ybS5lbGVtZW50cy5uYW1lZEl0ZW0oJ2NvbmNlcnRfaWQnKTsKICBpZiAoZm9ybS5kYXRhc2V0LmJ1c2luZXNzRWRpdG9yID09PSAnY29uY2VydHMnKSB7CiAgICBjb25zdCBzeW5jQ2xpZW50ID0gKCkgPT4gewogICAgICBjb25zdCBmcmVzaCA9IG1vZGU/LnZhbHVlID09PSAnbmV3JzsKICAgICAgY29uc3Qgb3B0aW9uID0gY29udGFjdD8uc2VsZWN0ZWRPcHRpb25zWzBdOwogICAgICBjb25zdCBjbGllbnRGaWVsZCA9IGZvcm0uZWxlbWVudHMubmFtZWRJdGVtKCdjbGllbnQnKTsKICAgICAgY29uc3QgY29udGFjdEZpZWxkID0gZm9ybS5lbGVtZW50cy5uYW1lZEl0ZW0oJ2NvbnRhY3QnKTsKICAgICAgaWYgKGNsaWVudEZpZWxkKSBjbGllbnRGaWVsZC52YWx1ZSA9IGZyZXNoID8gKGZvcm0uZWxlbWVudHMubmFtZWRJdGVtKCduZXdfY2xpZW50X25vbScpPy52YWx1ZSB8fCAnJykgOiAob3B0aW9uPy5kYXRhc2V0LmNsaWVudCB8fCAnJyk7CiAgICAgIGlmIChjb250YWN0RmllbGQpIGNvbnRhY3RGaWVsZC52YWx1ZSA9IGZyZXNoID8gJycgOiAob3B0aW9uPy5kYXRhc2V0LmNvbnRhY3QgfHwgJycpOwogICAgfTsKICAgIGNvbnRhY3Q/LmFkZEV2ZW50TGlzdGVuZXIoJ2NoYW5nZScsIHN5bmNDbGllbnQpOwogICAgbW9kZT8uYWRkRXZlbnRMaXN0ZW5lcignY2hhbmdlJywgc3luY0NsaWVudCk7CiAgICBmb3JtLmVsZW1lbnRzLm5hbWVkSXRlbSgnbmV3X2NsaWVudF9ub20nKT8uYWRkRXZlbnRMaXN0ZW5lcignaW5wdXQnLCBzeW5jQ2xpZW50KTsKICB9CiAgY29uc3QgcHJldmlldyA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdkb2N1bWVudC1zb3VyY2UtcHJldmlldycpOwogIGNvbnN0IHN0YXR1cyA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdkb2N1bWVudC1zb3VyY2Utc3RhdHVzJyk7CiAgbGV0IHJlcXVlc3QsIGdlbmVyYXRpb24gPSAwOwogIGNvbnN0IHVwZGF0ZURyYWZ0Q2xpZW50ID0gKCkgPT4gewogICAgaWYgKG1vZGU/LnZhbHVlICE9PSAnbmV3JyB8fCAhcHJldmlldykgcmV0dXJuOwogICAgZm9yIChjb25zdCBba2V5LCBpbnB1dF0gb2YgW1snY2xpZW50JywnbmV3X2NsaWVudF9ub20nXSxbJ2FkcmVzc2UnLCduZXdfY2xpZW50X2FkcmVzc2UnXV0pIHsKICAgICAgY29uc3QgZmllbGQgPSBwcmV2aWV3LnF1ZXJ5U2VsZWN0b3IoJ1tkYXRhLWRlcml2ZWQ9IicgKyBrZXkgKyAnIl0nKTsKICAgICAgaWYgKGZpZWxkKSBmaWVsZC50ZXh0Q29udGVudCA9IGZvcm0uZWxlbWVudHMubmFtZWRJdGVtKGlucHV0KT8udmFsdWUgfHwgJ+KAlCc7CiAgICB9CiAgfTsKICBjb25zdCBkb2N1bWVudENvbnRhY3RzID0gcHJldmlldyAmJiBjb250YWN0ID8gWy4uLmNvbnRhY3Qub3B0aW9uc10gOiBudWxsOwogIGNvbnN0IGRvY3VtZW50Q29uY2VydHMgPSBwcmV2aWV3ICYmIGNvbmNlcnQgPyBbLi4uY29uY2VydC5vcHRpb25zXSA6IG51bGw7CiAgY29uc3QgZmlsdGVyRG9jdW1lbnRTZWxlY3Rpb25zID0gKCkgPT4gewogICAgaWYgKCFkb2N1bWVudENvbnRhY3RzIHx8ICFkb2N1bWVudENvbmNlcnRzKSByZXR1cm4gZmFsc2U7CiAgICBjb25zdCBiZWZvcmVDb250YWN0ID0gY29udGFjdC52YWx1ZSwgYmVmb3JlQ29uY2VydCA9IGNvbmNlcnQudmFsdWU7CiAgICBjb25zdCBpbnZvaWNlID0gdHlwZT8udmFsdWUgPT09ICdGYWN0dXJlJzsKICAgIGNvbnN0IGZyZXNoT3B0aW9uID0gbW9kZT8ucXVlcnlTZWxlY3Rvcignb3B0aW9uW3ZhbHVlPSJuZXciXScpOwogICAgaWYgKGZyZXNoT3B0aW9uKSBmcmVzaE9wdGlvbi5kaXNhYmxlZCA9IGludm9pY2U7CiAgICBpZiAoaW52b2ljZSAmJiBtb2RlPy52YWx1ZSA9PT0gJ25ldycpIHsgbW9kZS52YWx1ZSA9ICdleGlzdGluZyc7IGNvbnRhY3QudmFsdWUgPSAnJzsgfQogICAgY29uc3Qgb3JpZ2luYWwgPSAoKSA9PiBtb2RlPy52YWx1ZSAhPT0gJ25ldycgJiYgdHlwZT8udmFsdWUgPT09IHByZXZpZXcuZGF0YXNldC5vcmlnaW5hbFR5cGUgJiYgY29udGFjdC52YWx1ZSA9PT0gcHJldmlldy5kYXRhc2V0Lm9yaWdpbmFsQ29udGFjdCAmJiBjb25jZXJ0LnZhbHVlID09PSBwcmV2aWV3LmRhdGFzZXQub3JpZ2luYWxDb25jZXJ0OwogICAgY29uc3Qga2VlcCA9IG9yaWdpbmFsKCksIHNlbGVjdGVkQ29udGFjdCA9IGNvbnRhY3QudmFsdWU7CiAgICBjb25zdCBjb250YWN0cyA9IGRvY3VtZW50Q29udGFjdHMuZmlsdGVyKG9wdGlvbiA9PiAhb3B0aW9uLnZhbHVlIHx8ICFpbnZvaWNlIHx8IG9wdGlvbi5kYXRhc2V0LmRvY3VtZW50SW52b2ljZUNsaWVudCA9PT0gJzEnIHx8IChrZWVwICYmIG9wdGlvbi52YWx1ZSA9PT0gcHJldmlldy5kYXRhc2V0Lm9yaWdpbmFsQ29udGFjdCkpOwogICAgZm9yIChjb25zdCBvcHRpb24gb2YgY29udGFjdHMpIHsgb3B0aW9uLmRpc2FibGVkID0gZmFsc2U7IG9wdGlvbi5oaWRkZW4gPSBmYWxzZTsgfQogICAgY29udGFjdC5yZXBsYWNlQ2hpbGRyZW4oLi4uY29udGFjdHMpOwogICAgY29udGFjdC52YWx1ZSA9IGNvbnRhY3RzLnNvbWUob3B0aW9uID0+IG9wdGlvbi52YWx1ZSA9PT0gc2VsZWN0ZWRDb250YWN0KSA/IHNlbGVjdGVkQ29udGFjdCA6ICcnOwogICAgY29uc3QgY2xpZW50ID0gbW9kZT8udmFsdWUgPT09ICduZXcnID8gJycgOiBjb250YWN0LnZhbHVlOwogICAgY29uc3Qgc2VsZWN0ZWRDb25jZXJ0ID0gY29uY2VydC52YWx1ZSwgcHJlc2VydmVDb25jZXJ0ID0gb3JpZ2luYWwoKTsKICAgIGNvbnN0IGNvbmNlcnRzID0gZG9jdW1lbnRDb25jZXJ0cy5maWx0ZXIob3B0aW9uID0+ICFvcHRpb24udmFsdWUgfHwgKG9wdGlvbi5kYXRhc2V0LmRvY3VtZW50RGF0ZU9rID09PSAnMScgJiYgKCFvcHRpb24uZGF0YXNldC5kb2N1bWVudENvbmNlcnRDbGllbnQgfHwgb3B0aW9uLmRhdGFzZXQuZG9jdW1lbnRDb25jZXJ0Q2xpZW50ID09PSBjbGllbnQpKSB8fCAocHJlc2VydmVDb25jZXJ0ICYmIG9wdGlvbi52YWx1ZSA9PT0gcHJldmlldy5kYXRhc2V0Lm9yaWdpbmFsQ29uY2VydCkpOwogICAgZm9yIChjb25zdCBvcHRpb24gb2YgY29uY2VydHMpIHsgb3B0aW9uLmRpc2FibGVkID0gZmFsc2U7IG9wdGlvbi5oaWRkZW4gPSBmYWxzZTsgfQogICAgY29uY2VydC5yZXBsYWNlQ2hpbGRyZW4oLi4uY29uY2VydHMpOwogICAgY29uY2VydC52YWx1ZSA9IGNvbmNlcnRzLnNvbWUob3B0aW9uID0+IG9wdGlvbi52YWx1ZSA9PT0gc2VsZWN0ZWRDb25jZXJ0KSA/IHNlbGVjdGVkQ29uY2VydCA6ICcnOwogICAgcmV0dXJuIChiZWZvcmVDb250YWN0ICE9PSAnJyAmJiBiZWZvcmVDb250YWN0ICE9PSBjb250YWN0LnZhbHVlKSB8fCAoYmVmb3JlQ29uY2VydCAhPT0gJycgJiYgYmVmb3JlQ29uY2VydCAhPT0gY29uY2VydC52YWx1ZSk7CiAgfTsKICBjb25zdCB1cGRhdGUgPSBhc3luYyAoKSA9PiB7CiAgICBjb25zdCBzZWxlY3Rpb25DaGFuZ2VkID0gZmlsdGVyRG9jdW1lbnRTZWxlY3Rpb25zKCk7CiAgICBjb25zdCBjcmVhdGUgPSBtb2RlPy52YWx1ZSA9PT0gJ25ldyc7CiAgICAvLyBMJ2lkZW50aWZpYW50IFNRTCBzw6lsZWN0aW9ubsOpIGTDqXRlcm1pbmUgbGUgbGllbiA7IGF1Y3VuZSByw6lzb2x1dGlvbiBwYXIgbm9tLgogICAgZm9yIChjb25zdCBba2V5LCBmaWVsZCwgcm91dGUsIGxhYmVsXSBvZiBbCiAgICAgIFsnY29udGFjdF9pZCcsIGNvbnRhY3QsICdjb250YWN0cy5waHAnLCAnT3V2cmlyIGxlIGNsaWVudCddLAogICAgICBbJ2NvbmNlcnRfaWQnLCBjb25jZXJ0LCAnY29uY2VydHMucGhwJywgJ091dnJpciBsZSBjb25jZXJ0J10KICAgIF0pIHsKICAgICAgY29uc3QgbGluayA9IGZvcm0ucXVlcnlTZWxlY3RvcignW2RhdGEtZG9jdW1lbnQtcmVsYXRpb249IicgKyBrZXkgKyAnIl0nKTsKICAgICAgaWYgKCFsaW5rKSBjb250aW51ZTsKICAgICAgY29uc3QgaWQgPSBrZXkgPT09ICdjb250YWN0X2lkJyAmJiBjcmVhdGUgPyAnJyA6IChmaWVsZD8udmFsdWUgfHwgJycpOwogICAgICBsaW5rLmhpZGRlbiA9ICFpZDsKICAgICAgaWYgKGlkKSB7IGxpbmsuaHJlZiA9IG5ldyBVUkwocm91dGUgKyAnP2lkPScgKyBlbmNvZGVVUklDb21wb25lbnQoaWQpLCBmb3JtLmFjdGlvbikuaHJlZjsgbGluay50ZXh0Q29udGVudCA9IGxhYmVsICsgJyAjJyArIGlkOyB9CiAgICAgIGVsc2UgbGluay5yZW1vdmVBdHRyaWJ1dGUoJ2hyZWYnKTsKICAgIH0KICAgIGNvbnN0IGV4aXN0aW5nID0gZm9ybS5xdWVyeVNlbGVjdG9yKCdbZGF0YS1leGlzdGluZy1jbGllbnRdJyk7CiAgICBjb25zdCBmcmVzaCA9IGZvcm0ucXVlcnlTZWxlY3RvcignW2RhdGEtbmV3LWNsaWVudF0nKTsKICAgIGlmIChleGlzdGluZykgeyBleGlzdGluZy5oaWRkZW4gPSBjcmVhdGU7IGZvciAoY29uc3QgZmllbGQgb2YgZXhpc3RpbmcucXVlcnlTZWxlY3RvckFsbCgnc2VsZWN0LGlucHV0JykpIGZpZWxkLmRpc2FibGVkID0gY3JlYXRlOyB9CiAgICBpZiAoZnJlc2gpIHsgZnJlc2guaGlkZGVuID0gIWNyZWF0ZTsgZm9yIChjb25zdCBmaWVsZCBvZiBmcmVzaC5xdWVyeVNlbGVjdG9yQWxsKCdpbnB1dCcpKSBmaWVsZC5kaXNhYmxlZCA9ICFjcmVhdGU7IH0KICAgIGlmICghcHJldmlldykgcmV0dXJuOwogICAgcmVxdWVzdD8uYWJvcnQoKTsgcmVxdWVzdCA9IG5ldyBBYm9ydENvbnRyb2xsZXIoKTsgY29uc3QgY3VycmVudCA9ICsrZ2VuZXJhdGlvbjsKICAgIGNvbnN0IHVybCA9IG5ldyBVUkwoZm9ybS5hY3Rpb24pOyB1cmwuc2VhcmNoID0gJyc7CiAgICB1cmwuc2VhcmNoUGFyYW1zLnNldCgnc291cmNlcycsICcxJyk7CiAgICB1cmwuc2VhcmNoUGFyYW1zLnNldCgnY29udGFjdF9pZCcsIGNyZWF0ZSA/ICcnIDogKGNvbnRhY3Q/LnZhbHVlIHx8ICcnKSk7CiAgICB1cmwuc2VhcmNoUGFyYW1zLnNldCgnY29uY2VydF9pZCcsIGNvbmNlcnQ/LnZhbHVlIHx8ICcnKTsKICAgIHN0YXR1cy50ZXh0Q29udGVudCA9ICdMZWN0dXJlIGRlcyBmaWNoZXMgbGnDqWVz4oCmJzsKICAgIHRyeSB7CiAgICAgIGNvbnN0IHJlc3BvbnNlID0gYXdhaXQgZmV0Y2godXJsLCB7Y3JlZGVudGlhbHM6J3NhbWUtb3JpZ2luJywgc2lnbmFsOnJlcXVlc3Quc2lnbmFsLCBoZWFkZXJzOntBY2NlcHQ6J2FwcGxpY2F0aW9uL2pzb24nfX0pOwogICAgICBpZiAocmVzcG9uc2UucmVkaXJlY3RlZCB8fCAhcmVzcG9uc2UuaGVhZGVycy5nZXQoJ2NvbnRlbnQtdHlwZScpPy5pbmNsdWRlcygnYXBwbGljYXRpb24vanNvbicpKSB0aHJvdyBuZXcgRXJyb3IoJ1JlY29ubmVjdGV6LXZvdXMgcG91ciBhY3R1YWxpc2VyIGxlcyBpbmZvcm1hdGlvbnMuJyk7CiAgICAgIGNvbnN0IGRhdGEgPSBhd2FpdCByZXNwb25zZS5qc29uKCk7CiAgICAgIGlmICghcmVzcG9uc2Uub2sgfHwgdHlwZW9mIGRhdGEuaHRtbCAhPT0gJ3N0cmluZycpIHRocm93IG5ldyBFcnJvcihkYXRhLmVycm9yIHx8ICdMZWN0dXJlIGltcG9zc2libGUuJyk7CiAgICAgIGlmIChjdXJyZW50ICE9PSBnZW5lcmF0aW9uKSByZXR1cm47CiAgICAgIGNvbnN0IGluY29taW5nID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgndGVtcGxhdGUnKTsgaW5jb21pbmcuaW5uZXJIVE1MID0gZGF0YS5odG1sOwogICAgICBmb3IgKGNvbnN0IGZpZWxkIG9mIHByZXZpZXcucXVlcnlTZWxlY3RvckFsbCgnW2RhdGEtZGVyaXZlZF0nKSkgewogICAgICAgIGNvbnN0IG5leHQgPSBpbmNvbWluZy5jb250ZW50LnF1ZXJ5U2VsZWN0b3IoJ1tkYXRhLWRlcml2ZWQ9IicgKyBmaWVsZC5kYXRhc2V0LmRlcml2ZWQgKyAnIl0nKTsKICAgICAgICBmaWVsZC50ZXh0Q29udGVudCA9IG5leHQ/LnRleHRDb250ZW50IHx8ICdOb24gcmVuc2VpZ27DqSc7CiAgICAgIH0KICAgICAgdXBkYXRlRHJhZnRDbGllbnQoKTsKICAgICAgc3RhdHVzLnRleHRDb250ZW50ID0gKHNlbGVjdGlvbkNoYW5nZWQgPyAnTGUgY2hvaXggcHLDqWPDqWRlbnQgw6l0YWl0IGhvcnMgY3JpdMOocmVzIDogc8OpbGVjdGlvbm5lciB1biBjbGllbnQgb3UgdW4gY29uY2VydCBjb21wYXRpYmxlLiAnIDogJycpICsgJ0luZm9ybWF0aW9ucyByZXByaXNlcyBkZXMgZmljaGVzIDsgbOKAmWVucmVnaXN0cmVtZW50IGxlcyB2w6lyaWZpZSDDoCBub3V2ZWF1Lic7CiAgICB9IGNhdGNoIChlcnJvcikgewogICAgICBpZiAoY3VycmVudCAhPT0gZ2VuZXJhdGlvbiB8fCBlcnJvci5uYW1lID09PSAnQWJvcnRFcnJvcicpIHJldHVybjsKICAgICAgZm9yIChjb25zdCBmaWVsZCBvZiBwcmV2aWV3LnF1ZXJ5U2VsZWN0b3JBbGwoJ1tkYXRhLWRlcml2ZWRdJykpIGZpZWxkLnRleHRDb250ZW50ID0gJ05vbiByZW5zZWlnbsOpJzsKICAgICAgc3RhdHVzLnRleHRDb250ZW50ID0gJ0FwZXLDp3Ugbm9uIGFjdHVhbGlzw6kuICcgKyBlcnJvci5tZXNzYWdlOwogICAgfQogIH07CiAgdHlwZT8uYWRkRXZlbnRMaXN0ZW5lcignY2hhbmdlJyx1cGRhdGUpOwogIG1vZGU/LmFkZEV2ZW50TGlzdGVuZXIoJ2NoYW5nZScsdXBkYXRlKTsKICBjb250YWN0Py5hZGRFdmVudExpc3RlbmVyKCdjaGFuZ2UnLHVwZGF0ZSk7CiAgY29uY2VydD8uYWRkRXZlbnRMaXN0ZW5lcignY2hhbmdlJyx1cGRhdGUpOwogIGZvciAoY29uc3QgbmFtZSBvZiBbJ25ld19jbGllbnRfbm9tJywnbmV3X2NsaWVudF9hZHJlc3NlJ10pIGZvcm0uZWxlbWVudHMubmFtZWRJdGVtKG5hbWUpPy5hZGRFdmVudExpc3RlbmVyKCdpbnB1dCcsdXBkYXRlRHJhZnRDbGllbnQpOwogIC8vIFJlbGlyZSBsZXMgZmljaGVzIGF1IHJldG91ciBkZSBsZXVyIG9uZ2xldCwgc2FucyB0b3VjaGVyIMOgIGxhIHNhaXNpZSBsaWJyZS4KICBpZiAocHJldmlldykgewogICAgd2luZG93LmFkZEV2ZW50TGlzdGVuZXIoJ2ZvY3VzJywgdXBkYXRlKTsKICAgIHdpbmRvdy5hZGRFdmVudExpc3RlbmVyKCdwYWdlc2hvdycsIGV2ZW50ID0+IHsgaWYgKGV2ZW50LnBlcnNpc3RlZCkgdXBkYXRlKCk7IH0pOwogIH0KICB1cGRhdGUoKTsKfSkoKTsK',true);
                $content .= '<button>Enregistrer la fiche</button><p><a class="business-action" href="' . MusicbandEscape($route) . ($id !== null ? '?id=' . $id : '') . '">Annuler</a></p></form>';
            } else {
                if (isset($_GET['saved'])) $content .= '<p role="status">Fiche enregistrée. La source et l’historique sont conservés.</p>';
                if (isset($_GET['followup'])) {
                    $followupMessages = [
                        'email_sent' => 'Relance e-mail envoyée avec succès et enregistrée dans l’historique.',
                        'messenger_confirmed' => 'Échange Messenger confirmé et enregistré dans l’historique.',
                        'note_added' => 'Commentaire enregistré dans l’historique.',
                    ];
                    $content .= '<p role="status">' . MusicbandEscape($followupMessages[$_GET['followup']] ?? 'Action de suivi enregistrée avec succès.') . '</p>';
                }
                if ($module==='concerts') $content.=concertsWeatherPanel($db,$row);
                if (MusicbandCan(MusicbandBusinessPermission($module),$user) && ($module!=='concerts'||!businessConcertIsPast($row)||($user['admin']??false)) && ($module!=='devis_factures' || in_array(documentsWorkflowState($row),['Préparation',''],true))) $content .= '<p><a class="business-action" href="' . MusicbandEscape($route) . '?id=' . $id . '&amp;edit=1">Modifier le ' . $recordLabel . '</a></p>';
                if ($module === 'contacts' && contactsFollowupReady($db)) $content .= contactsFollowupRenderActions($row, $db, $user);
                if ($module==='devis_factures') $content .= documentsWorkflowActions($row,$db);
                if ($module==='concerts' && MusicbandCan('calendar.send',$user)) $content .= concertsCalendarPanel($db,$row);
                if ($module==='concerts' && MusicbandCan('concerts.publish.wordpress',$user)) $content .= concertsPublicationPanel($db,$row);
                $parts=[];
                foreach ($config['fields'] as $key=>$label) if (fieldVisibilityVisible($module,$key)) $parts[$key]='<dt>'.MusicbandEscape(fieldDisplayLabel($module,$key,$label)).'</dt><dd>'.($module==='concerts'?concertsFieldsDisplay(businessDisplayValue($module,$key,$row[$key]??null,$row)):MusicbandEscape(businessDisplayValue($module,$key,$row[$key]??null,$row))).'</dd>';
                $originalFields=json_decode($row['source_json'],true,32,JSON_THROW_ON_ERROR);
                $effective=$module==='concerts'?concertsFieldsValues($row):$originalFields;
                foreach (fieldLayoutFields($db,$module,'detail') as $key=>$label) if (str_starts_with($key,'source:') && fieldVisibilityVisible($module,$key)) {
                    $name=substr($label,0,-strlen(' · source')); $value=$effective[$name]??null;
                    $parts[$key]='<dt>'.MusicbandEscape(fieldDisplayLabel($module,$key,$name)).'</dt><dd>'.($module==='concerts'?concertsFieldsDisplay($value,$name,isset($originalFields[$name])&&$originalFields[$name]===$value):MusicbandEscape(concertsFieldsText($value))).'</dd>';
                }
                $content.=fieldLayoutRender($module,'detail',$parts);
                if ($module !== 'devis_factures') {
                    $source = json_decode($row['source_json'], true, 32, JSON_THROW_ON_ERROR);
                    $content .= '<details><summary>Valeurs importées d’origine · lecture seule</summary><p>Clé source : ' . (fieldVisibilityVisible($module,fieldVisibilitySourceKey($module,'Row ID')) ? MusicbandEscape($row['source_key']) : 'masquée') . ' · origine : ' . MusicbandEscape($row['source_table']) . '</p><dl>';
                    foreach ($source as $key => $value) if (fieldVisibilityVisible($module,fieldVisibilitySourceKey($module,(string)$key))) $content .= '<dt>' . MusicbandEscape((string)$key) . '</dt><dd>' . MusicbandEscape((string)$value) . '</dd>';
                    $content .= '</dl></details>';
                    $check = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'); $check->execute([$config['history']]);
                    if ((int)$check->fetchColumn() === 1) {
                        $historyLabels=$module==='concerts'?fieldVisibilityRegistry($db,$module):[];
                        $history = $db->prepare('SELECT edited_by,edited_at,before_json,after_json FROM ' . $config['history'] . ' WHERE record_id=? ORDER BY id DESC LIMIT 50'); $history->execute([$id]);
                        $content .= '<details><summary>Historique · 50 dernières modifications</summary>';
                        foreach ($history as $revision) {
                            $before = json_decode($revision['before_json'], true, 32, JSON_THROW_ON_ERROR); $after = json_decode($revision['after_json'], true, 32, JSON_THROW_ON_ERROR);
                            $content .= '<section><p>' . MusicbandEscape(MusicbandDisplayDate($revision['edited_at'],true,true).' · '.$revision['edited_by']) . '</p><dl>';
                            foreach ($after as $key => $value) if (fieldVisibilityVisible($module,$key) && ($before[$key] ?? null) !== $value) $content .= '<dt>' . MusicbandEscape(fieldDisplayLabel($module,$key,$config['fields'][$key] ?? ($historyLabels[$key] ?? (businessRelations($module)[$key]['label'] ?? $key)))) . '</dt><dd>Avant : ' . MusicbandEscape(businessDisplayValue($module,$key,$before[$key]??'', $before)) . "\nAprès : " . MusicbandEscape(businessDisplayValue($module,$key,$value,$after)) . '</dd>';
                            $content .= '</dl></section>';
                        }
                        $content .= '</details>';
                    }
                }
                if ($module === 'contacts' && contactsFollowupReady($db)) {
                    $content .= '<section class="business-relations-footer contact-timeline-section"><h3>Historique des échanges et relances</h3>'
                        . contactsFollowupRenderTimeline(contactsFollowupActivities($db, (int)$id))
                        . '</section>';
                    $listScript = contactsFollowupScript();
                }
                $content .= '<div class="business-relations-footer">'.businessRelatedDocuments($db,$module,$id,$user).'</div>';
                if ($module==='concerts') {
                    $weatherScript = base64_decode('LyoqCiAqIE11c2ljYmFuZCBDT05DRVJUUyBXRUFUSEVSIEpTOiBhZmZpY2hhZ2UgZHluYW1pcXVlIGV0IGFjY2Vzc2libGUgZHUgd2lkZ2V0IG3DqXTDqW8uCiAqCiAqIFLDtGxlIDoKICogUsOpY3Vww6lyYXRpb24gYXN5bmNocm9uZSBkZXMgcHLDqXZpc2lvbnMgbcOpdMOpbyB2aWEgbCdlbmRwb2ludCBBSkFYIGNvbmNlcnRzLnBocCwKICogcmVuZHUgYWNjZXNzaWJsZSBkZSBsYSBmcmlzZSBjaHJvbm9sb2dpcXVlIGhvcmFpcmUsIGRlcyBpbmRpY2F0ZXVycyBtw6l0w6lvCiAqIChpY8O0bmVzIFNWRyBpbnTDqWdyw6llcywgdGVtcMOpcmF0dXJlcywgcmlzcXVlIGRlIHBsdWllLCB2ZW50LCByYWZhbGVzKSBldCBtaXNlCiAqIGVuIHZhbGV1ciBkZSBsYSBww6lyaW9kZSBkZSBwcmVzdGF0aW9uIGR1IGNvbmNlcnQuCiAqCiAqIETDqXBlbmRhbmNlcyBpbnRlcm5lcyA6CiAqIC0gSW52b3F1w6kgc3VyIGxhIHBhZ2UgZGUgZMOpdGFpbCBkJ3VuIGNvbmNlcnQgKHdlYi9idXNpbmVzcy5waHApCiAqIC0gU3R5bGVzIGRhbnMgd2ViL2Nzcy9idXNpbmVzcy5jc3MKICoKICogRWZmZXRzIGRlIGJvcmQgOgogKiAtIEluamVjdGlvbiBkYW5zIGxlcyBuxZN1ZHMgRE9NICN3ZWF0aGVyLXN1bW1hcnktYmFyIGV0ICN3ZWF0aGVyLWNvbnRlbnQKICovCgooZnVuY3Rpb24gKCkgewogICd1c2Ugc3RyaWN0JzsKCiAgLy8gQmlibGlvdGjDqHF1ZSBkJ2ljw7RuZXMgU1ZHIHZlY3RvcmllbGxlcyBpbnTDqWdyw6llcywgYWRhcHTDqWVzIGF1IHRow6htZSBNdXNpY2JhbmQKICBjb25zdCBXRUFUSEVSX0lDT05TID0gewogICAgJ2NsZWFyJzogJzxzdmcgdmlld0JveD0iMCAwIDI0IDI0IiB3aWR0aD0iMjgiIGhlaWdodD0iMjgiIGZpbGw9Im5vbmUiIHN0cm9rZT0iI2Y2YWQ1NSIgc3Ryb2tlLXdpZHRoPSIyIiBzdHJva2UtbGluZWNhcD0icm91bmQiIHN0cm9rZS1saW5lam9pbj0icm91bmQiIGFyaWEtaGlkZGVuPSJ0cnVlIj48Y2lyY2xlIGN4PSIxMiIgY3k9IjEyIiByPSI0Ii8+PHBhdGggZD0iTTEyIDJ2Mk0xMiAyMHYyTTQuOTMgNC45M2wxLjQxIDEuNDFNMTcuNjYgMTcuNjZsMS40MSAxLjQxTTIgMTJoMk0yMCAxMmgyTTYuMzQgMTcuNjZsLTEuNDEgMS40MU0xOS4wNyA0LjkzbC0xLjQxIDEuNDEiLz48L3N2Zz4nLAogICAgJ3BhcnRseS1jbG91ZHknOiAnPHN2ZyB2aWV3Qm94PSIwIDAgMjQgMjQiIHdpZHRoPSIyOCIgaGVpZ2h0PSIyOCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjY2JkNWUwIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIgYXJpYS1oaWRkZW49InRydWUiPjxwYXRoIGQ9Ik0xMiAydjJNNC45MyA0LjkzbDEuNDEgMS40MU0yIDEyaDIiIHN0cm9rZT0iI2Y2YWQ1NSIvPjxjaXJjbGUgY3g9IjEyIiBjeT0iMTAiIHI9IjMiIHN0cm9rZT0iI2Y2YWQ1NSIvPjxwYXRoIGQ9Ik03IDE5aDExYTQgNCAwIDAgMCAuNS03Ljk3QTUuNSA1LjUgMCAwIDAgNy41IDEzIDMuNSAzLjUgMCAwIDAgNyAxOXoiIGZpbGw9IiMyZDM3NDgiLz48L3N2Zz4nLAogICAgJ2Nsb3VkeSc6ICc8c3ZnIHZpZXdCb3g9IjAgMCAyNCAyNCIgd2lkdGg9IjI4IiBoZWlnaHQ9IjI4IiBmaWxsPSJub25lIiBzdHJva2U9IiNhMGFlYzAiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIiBhcmlhLWhpZGRlbj0idHJ1ZSI+PHBhdGggZD0iTTE4IDEwaC0xLjI2QTggOCAwIDEgMCA5IDIwaDlhNSA1IDAgMCAwIDAtMTB6Ii8+PC9zdmc+JywKICAgICdvdmVyY2FzdCc6ICc8c3ZnIHZpZXdCb3g9IjAgMCAyNCAyNCIgd2lkdGg9IjI4IiBoZWlnaHQ9IjI4IiBmaWxsPSJub25lIiBzdHJva2U9IiM3MTgwOTYiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIiBhcmlhLWhpZGRlbj0idHJ1ZSI+PHBhdGggZD0iTTE3LjUgMTlIOWE3IDcgMCAxIDEgNi43MS05aC43OWE0LjUgNC41IDAgMCAxIDEgOC44OXoiIGZpbGw9IiMyZDM3NDgiLz48L3N2Zz4nLAogICAgJ2ZvZyc6ICc8c3ZnIHZpZXdCb3g9IjAgMCAyNCAyNCIgd2lkdGg9IjI4IiBoZWlnaHQ9IjI4IiBmaWxsPSJub25lIiBzdHJva2U9IiNhMGFlYzAiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIiBhcmlhLWhpZGRlbj0idHJ1ZSI+PHBhdGggZD0iTTQgMTRoMTZNNCAxOGgxNk03IDEwaDEwTTkgNmg2Ii8+PC9zdmc+JywKICAgICdkcml6emxlJzogJzxzdmcgdmlld0JveD0iMCAwIDI0IDI0IiB3aWR0aD0iMjgiIGhlaWdodD0iMjgiIGZpbGw9Im5vbmUiIHN0cm9rZT0iIzYzYjNlZCIgc3Ryb2tlLXdpZHRoPSIyIiBzdHJva2UtbGluZWNhcD0icm91bmQiIHN0cm9rZS1saW5lam9pbj0icm91bmQiIGFyaWEtaGlkZGVuPSJ0cnVlIj48cGF0aCBkPSJNMTkgMTNhNCA0IDAgMCAwLTMuNS0zLjkgNiA2IDAgMCAwLTExIDEuOUE0IDQgMCAwIDAgNSAxN2gxNCIgc3Ryb2tlPSIjYTBhZWMwIi8+PHBhdGggZD0iTTggMTl2Mk0xMiAxOXYyTTE2IDE5djIiLz48L3N2Zz4nLAogICAgJ3JhaW4nOiAnPHN2ZyB2aWV3Qm94PSIwIDAgMjQgMjQiIHdpZHRoPSIyOCIgaGVpZ2h0PSIyOCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjNDI5OWUxIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIgYXJpYS1oaWRkZW49InRydWUiPjxwYXRoIGQ9Ik0xOSAxMWE0IDQgMCAwIDAtMy41LTMuOSA2IDYgMCAwIDAtMTEgMS45QTQgNCAwIDAgMCA1IDE1aDE0IiBzdHJva2U9IiNhMGFlYzAiLz48cGF0aCBkPSJNMTAgMTdsLTIgNE0xNCAxN2wtMiA0TTE4IDE3bC0yIDQiLz48L3N2Zz4nLAogICAgJ2hlYXZ5LXJhaW4nOiAnPHN2ZyB2aWV3Qm94PSIwIDAgMjQgMjQiIHdpZHRoPSIyOCIgaGVpZ2h0PSIyOCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjMzE4MmNlIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIgYXJpYS1oaWRkZW49InRydWUiPjxwYXRoIGQ9Ik0xOSAxMGE0IDQgMCAwIDAtMy41LTMuOSA2IDYgMCAwIDAtMTEgMS45QTQgNCAwIDAgMCA1IDE0aDE0IiBzdHJva2U9IiM3MTgwOTYiIGZpbGw9IiMxYTIwMmMiLz48cGF0aCBkPSJNOSAxNmwtMiA1TTEzIDE2bC0yIDVNMTcgMTZsLTIgNSIvPjwvc3ZnPicsCiAgICAnc25vdyc6ICc8c3ZnIHZpZXdCb3g9IjAgMCAyNCAyNCIgd2lkdGg9IjI4IiBoZWlnaHQ9IjI4IiBmaWxsPSJub25lIiBzdHJva2U9IiNlMmU4ZjAiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIiBhcmlhLWhpZGRlbj0idHJ1ZSI+PHBhdGggZD0iTTE5IDExYTQgNCAwIDAgMC0zLjUtMy45IDYgNiAwIDAgMC0xMSAxLjlBNCA0IDAgMCAwIDUgMTVoMTQiIHN0cm9rZT0iI2EwYWVjMCIvPjxwYXRoIGQ9Ik04IDE4aC4wMU0xMiAxOGguMDFNMTYgMThoLjAxTTEwIDIxaC4wMU0xNCAyMWguMDEiLz48L3N2Zz4nLAogICAgJ3RodW5kZXJzdG9ybSc6ICc8c3ZnIHZpZXdCb3g9IjAgMCAyNCAyNCIgd2lkdGg9IjI4IiBoZWlnaHQ9IjI4IiBmaWxsPSJub25lIiBzdHJva2U9IiNlY2M5NGIiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIiBhcmlhLWhpZGRlbj0idHJ1ZSI+PHBhdGggZD0iTTE5IDExYTQgNCAwIDAgMC0zLjUtMy45IDYgNiAwIDAgMC0xMSAxLjlBNCA0IDAgMCAwIDUgMTVoMTQiIHN0cm9rZT0iIzcxODA5NiIgZmlsbD0iIzFhMjAyYyIvPjxwYXRoIGQ9Ik0xMyAxM2wtMyA1aDRsLTIgNSIvPjwvc3ZnPicsCiAgICAnZHJvcGxldCc6ICc8c3ZnIHZpZXdCb3g9IjAgMCAyNCAyNCIgd2lkdGg9IjEzIiBoZWlnaHQ9IjEzIiBmaWxsPSJjdXJyZW50Q29sb3IiIGFyaWEtaGlkZGVuPSJ0cnVlIj48cGF0aCBkPSJNMTIgMi42OWw1LjY2IDUuNjZhOCA4IDAgMSAxLTExLjMxIDB6Ii8+PC9zdmc+JywKICAgICd3aW5kJzogJzxzdmcgdmlld0JveD0iMCAwIDI0IDI0IiB3aWR0aD0iMTMiIGhlaWdodD0iMTMiIGZpbGw9Im5vbmUiIHN0cm9rZT0iY3VycmVudENvbG9yIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIgYXJpYS1oaWRkZW49InRydWUiPjxwYXRoIGQ9Ik05LjU5IDQuNTlBMiAyIDAgMSAxIDExIDhIMm0xMC41OSAxMS40MUEyIDIgMCAxIDAgMTQgMTZIMm0xNS43My04LjI3QTIuNSAyLjUgMCAxIDEgMTkuNSAxMkgyIi8+PC9zdmc+JywKICB9OwoKICAvKioKICAgKiDDiWNoYXBwZW1lbnQgSFRNTCBzw6ljdXJpc8OpIHBvdXIgbGVzIGRvbm7DqWVzIGFmZmljaMOpZXMuCiAgICovCiAgZnVuY3Rpb24gZXNjYXBlSHRtbChzdHIpIHsKICAgIGlmIChzdHIgPT0gbnVsbCkgcmV0dXJuICcnOwogICAgcmV0dXJuIFN0cmluZyhzdHIpCiAgICAgIC5yZXBsYWNlKC8mL2csICcmYW1wOycpCiAgICAgIC5yZXBsYWNlKC88L2csICcmbHQ7JykKICAgICAgLnJlcGxhY2UoLz4vZywgJyZndDsnKQogICAgICAucmVwbGFjZSgvIi9nLCAnJnF1b3Q7JykKICAgICAgLnJlcGxhY2UoLycvZywgJyYjMzk7Jyk7CiAgfQoKICAvKioKICAgKiBDaGFyZ2VtZW50IGRlcyBwcsOpdmlzaW9ucyBtw6l0w6lvIGR1IGNvbmNlcnQgY291cmFudC4KICAgKi8KICBmdW5jdGlvbiBsb2FkQ29uY2VydFdlYXRoZXIoKSB7CiAgICBjb25zdCBtb3VudCA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdjb25jZXJ0LXdlYXRoZXInKTsKICAgIGlmICghbW91bnQpIHJldHVybjsKCiAgICBjb25zdCBjb25jZXJ0SWQgPSBtb3VudC5nZXRBdHRyaWJ1dGUoJ2RhdGEtY29uY2VydC1pZCcpOwogICAgaWYgKCFjb25jZXJ0SWQpIHJldHVybjsKCiAgICBjb25zdCBzdW1tYXJ5QmFyID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ3dlYXRoZXItc3VtbWFyeS1iYXInKTsKICAgIGNvbnN0IGNvbnRlbnQgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgnd2VhdGhlci1jb250ZW50Jyk7CiAgICBpZiAoIWNvbnRlbnQpIHJldHVybjsKCiAgICBjb25zdCB1cmwgPSAnY29uY2VydHMucGhwP2FqYXg9d2VhdGhlciZpZD0nICsgZW5jb2RlVVJJQ29tcG9uZW50KGNvbmNlcnRJZCk7CgogICAgZmV0Y2godXJsLCB7CiAgICAgIG1ldGhvZDogJ0dFVCcsCiAgICAgIGhlYWRlcnM6IHsgJ0FjY2VwdCc6ICdhcHBsaWNhdGlvbi9qc29uJyB9LAogICAgICBjcmVkZW50aWFsczogJ3NhbWUtb3JpZ2luJywKICAgIH0pCiAgICAgIC50aGVuKGZ1bmN0aW9uIChyZXMpIHsKICAgICAgICBpZiAoIXJlcy5vaykgewogICAgICAgICAgdGhyb3cgbmV3IEVycm9yKCdIVFRQICcgKyByZXMuc3RhdHVzKTsKICAgICAgICB9CiAgICAgICAgcmV0dXJuIHJlcy5qc29uKCk7CiAgICAgIH0pCiAgICAgIC50aGVuKGZ1bmN0aW9uIChkYXRhKSB7CiAgICAgICAgaWYgKCFkYXRhIHx8IGRhdGEuc3RhdHVzICE9PSAnc3VjY2VzcycpIHsKICAgICAgICAgIGNvbnN0IG1lc3NhZ2UgPSAoZGF0YSAmJiBkYXRhLm1lc3NhZ2UpCiAgICAgICAgICAgID8gZGF0YS5tZXNzYWdlCiAgICAgICAgICAgIDogJ03DqXTDqW8gaW5kaXNwb25pYmxlIDogZG9ubsOpZXMgaW5hY2Nlc3NpYmxlcy4nOwogICAgICAgICAgaWYgKHN1bW1hcnlCYXIpIHN1bW1hcnlCYXIudGV4dENvbnRlbnQgPSAnJzsKICAgICAgICAgIGNvbnRlbnQuaW5uZXJIVE1MID0gJzxwIGNsYXNzPSJ3ZWF0aGVyLW5vdGljZSIgcm9sZT0ic3RhdHVzIj4nICsgZXNjYXBlSHRtbChtZXNzYWdlKSArICc8L3A+JzsKICAgICAgICAgIHJldHVybjsKICAgICAgICB9CgogICAgICAgIHJlbmRlcldlYXRoZXIoZGF0YSwgc3VtbWFyeUJhciwgY29udGVudCk7CiAgICAgIH0pCiAgICAgIC5jYXRjaChmdW5jdGlvbiAoKSB7CiAgICAgICAgaWYgKHN1bW1hcnlCYXIpIHN1bW1hcnlCYXIudGV4dENvbnRlbnQgPSAnJzsKICAgICAgICBjb250ZW50LmlubmVySFRNTCA9ICc8cCBjbGFzcz0id2VhdGhlci1ub3RpY2UiIHJvbGU9InN0YXR1cyI+RG9ubsOpZXMgbcOpdMOpbyB0ZW1wb3JhaXJlbWVudCBpbmRpc3BvbmlibGVzLjwvcD4nOwogICAgICB9KTsKICB9CgogIC8qKgogICAqIFJlbmR1IHZpc3VlbCBkdSBwYW5uZWF1IGRlIHByw6l2aXNpb25zIGV0IGRlIGxhIGZyaXNlIGhvcmFpcmUuCiAgICovCiAgZnVuY3Rpb24gcmVuZGVyV2VhdGhlcihkYXRhLCBzdW1tYXJ5QmFyLCBjb250ZW50KSB7CiAgICBjb25zdCBzdW0gPSBkYXRhLnN1bW1hcnkgfHwge307CiAgICBjb25zdCBpY29uU3ZnID0gV0VBVEhFUl9JQ09OU1tzdW0uaWNvbl0gfHwgV0VBVEhFUl9JQ09OU1snY2xvdWR5J107CgogICAgLy8gQmFycmUgcsOpY2FwaXR1bGF0aXZlCiAgICBpZiAoc3VtbWFyeUJhcikgewogICAgICBjb25zdCBjaGlwcyA9IFtdOwoKICAgICAgaWYgKGRhdGEubG9jYXRpb24pIHsKICAgICAgICBjaGlwcy5wdXNoKCc8c3BhbiBjbGFzcz0id2VhdGhlci1zdW1tYXJ5LWNoaXAgd2VhdGhlci1jaGlwLWxvY2F0aW9uIiB0aXRsZT0iTG9jYWxpc2F0aW9uIHJldGVudWUiPicgKyBlc2NhcGVIdG1sKGRhdGEubG9jYXRpb24pICsgJzwvc3Bhbj4nKTsKICAgICAgfQoKICAgICAgaWYgKHN1bS50ZW1wX21pbiAhPT0gdW5kZWZpbmVkICYmIHN1bS50ZW1wX21heCAhPT0gdW5kZWZpbmVkKSB7CiAgICAgICAgY29uc3QgdGVtcFRleHQgPSBzdW0udGVtcF9taW4gPT09IHN1bS50ZW1wX21heCA/IHN1bS50ZW1wX21pbiArICfCsEMnIDogc3VtLnRlbXBfbWluICsgJyDDoCAnICsgc3VtLnRlbXBfbWF4ICsgJ8KwQyc7CiAgICAgICAgY2hpcHMucHVzaCgnPHNwYW4gY2xhc3M9IndlYXRoZXItc3VtbWFyeS1jaGlwIj48c3Ryb25nPicgKyB0ZW1wVGV4dCArICc8L3N0cm9uZz48L3NwYW4+Jyk7CiAgICAgIH0KCiAgICAgIGlmIChzdW0ucmFpbl9wcm9iX21heCAhPT0gdW5kZWZpbmVkKSB7CiAgICAgICAgY29uc3QgcmFpblRpdGxlID0gJ1Jpc3F1ZSBtYXggZGUgcGx1aWUgOiAnICsgc3VtLnJhaW5fcHJvYl9tYXggKyAnJSAoJyArIChzdW0ucmFpbl9zdW0gfHwgMCkgKyAnIG1tKSc7CiAgICAgICAgY2hpcHMucHVzaCgnPHNwYW4gY2xhc3M9IndlYXRoZXItc3VtbWFyeS1jaGlwIiB0aXRsZT0iJyArIGVzY2FwZUh0bWwocmFpblRpdGxlKSArICciPicgKyBXRUFUSEVSX0lDT05TLmRyb3BsZXQgKyAnICcgKyBzdW0ucmFpbl9wcm9iX21heCArICclPC9zcGFuPicpOwogICAgICB9CgogICAgICBpZiAoc3VtLndpbmRfbWF4ICE9PSB1bmRlZmluZWQpIHsKICAgICAgICBjb25zdCB3aW5kVGl0bGUgPSAnVmVudCBtYXggOiAnICsgc3VtLndpbmRfbWF4ICsgJyBrbS9oJyArIChzdW0ud2luZF9ndXN0c19tYXggPyAnIChyYWZhbGVzICcgKyBzdW0ud2luZF9ndXN0c19tYXggKyAnIGttL2gpJyA6ICcnKTsKICAgICAgICBjaGlwcy5wdXNoKCc8c3BhbiBjbGFzcz0id2VhdGhlci1zdW1tYXJ5LWNoaXAiIHRpdGxlPSInICsgZXNjYXBlSHRtbCh3aW5kVGl0bGUpICsgJyI+JyArIFdFQVRIRVJfSUNPTlMud2luZCArICcgJyArIHN1bS53aW5kX21heCArICcga20vaDwvc3Bhbj4nKTsKICAgICAgfQoKICAgICAgc3VtbWFyeUJhci5pbm5lckhUTUwgPSBjaGlwcy5qb2luKCcnKTsKICAgIH0KCiAgICAvLyBGcmlzZSBob3JhaXJlIGTDqWZpbGFudGUKICAgIGNvbnN0IGhvdXJseSA9IGRhdGEuaG91cmx5IHx8IFtdOwogICAgaWYgKCFob3VybHkubGVuZ3RoKSB7CiAgICAgIGNvbnRlbnQuaW5uZXJIVE1MID0gJzxwIGNsYXNzPSJ3ZWF0aGVyLW5vdGljZSIgcm9sZT0ic3RhdHVzIj5BdWN1bmUgdHJhbmNoZSBob3JhaXJlIGRpc3BvbmlibGUgcG91ciBjZSBjb25jZXJ0LjwvcD4nOwogICAgICByZXR1cm47CiAgICB9CgogICAgY29uc3QgY2FyZHNIdG1sID0gW107CiAgICBmb3IgKGxldCBpID0gMDsgaSA8IGhvdXJseS5sZW5ndGg7IGkrKykgewogICAgICBjb25zdCBoID0gaG91cmx5W2ldOwogICAgICBjb25zdCBjYXJkSWNvbiA9IFdFQVRIRVJfSUNPTlNbaC5pY29uXSB8fCBXRUFUSEVSX0lDT05TWydjbG91ZHknXTsKICAgICAgY29uc3QgcGVyZkNsYXNzID0gaC5pc19wZXJmb3JtYW5jZSA/ICcgaXMtcGVyZm9ybWFuY2UnIDogJyc7CiAgICAgIGNvbnN0IGFycml2YWxDbGFzcyA9IGguaXNfYXJyaXZhbCA/ICcgaXMtYXJyaXZhbCcgOiAnJzsKCiAgICAgIGxldCBiYWRnZUh0bWwgPSAnJzsKICAgICAgaWYgKGguaXNfcGVyZm9ybWFuY2UpIHsKICAgICAgICBiYWRnZUh0bWwgPSAnPHNwYW4gY2xhc3M9IndlYXRoZXItYmFkZ2UiPkVuIGNvbmNlcnQ8L3NwYW4+JzsKICAgICAgfSBlbHNlIGlmIChoLmlzX2Fycml2YWwpIHsKICAgICAgICBiYWRnZUh0bWwgPSAnPHNwYW4gY2xhc3M9IndlYXRoZXItYmFkZ2UtYXJyaXZhbCI+QXJyaXbDqWU8L3NwYW4+JzsKICAgICAgfQoKICAgICAgY29uc3QgZGF0ZVRhZyA9IGguZGF0ZV9sYWJlbCA/ICc8c3BhbiBjbGFzcz0id2VhdGhlci1kYXRlLXRhZyI+JyArIGVzY2FwZUh0bWwoaC5kYXRlX2xhYmVsKSArICc8L3NwYW4+JyA6ICcnOwogICAgICBjb25zdCByYWluQ2xhc3MgPSBoLnJhaW5fcHJvYiA+PSAyMCA/ICcgaGFzLXJhaW4nIDogJyc7CiAgICAgIGNvbnN0IHByZWNpcFRleHQgPSAoaC5wcmVjaXBpdGF0aW9uICYmIGgucHJlY2lwaXRhdGlvbiA+IDApID8gJyA8c21hbGw+KCcgKyBoLnByZWNpcGl0YXRpb24gKyAnIG1tKTwvc21hbGw+JyA6ICcnOwogICAgICBjb25zdCB3aW5kR3VzdFRleHQgPSAoaC53aW5kX2d1c3RzICYmIGgud2luZF9ndXN0cyA+IGgud2luZF9zcGVlZCArIDgpID8gJyA8c21hbGw+KCcgKyBoLndpbmRfZ3VzdHMgKyAnKTwvc21hbGw+JyA6ICcnOwoKICAgICAgY2FyZHNIdG1sLnB1c2goCiAgICAgICAgJzxkaXYgY2xhc3M9IndlYXRoZXItaG91ci1jYXJkJyArIHBlcmZDbGFzcyArIGFycml2YWxDbGFzcyArICciIHRhYmluZGV4PSIwIiB0aXRsZT0iJyArIGVzY2FwZUh0bWwoaC50b29sdGlwKSArICciIGFyaWEtbGFiZWw9IicgKyBlc2NhcGVIdG1sKGgudG9vbHRpcCkgKyAnIj4nICsKICAgICAgICAgIGJhZGdlSHRtbCArCiAgICAgICAgICAnPHNwYW4gY2xhc3M9IndlYXRoZXItdGltZSI+JyArIGVzY2FwZUh0bWwoaC50aW1lKSArICc8L3NwYW4+JyArCiAgICAgICAgICBkYXRlVGFnICsKICAgICAgICAgICc8ZGl2IGNsYXNzPSJ3ZWF0aGVyLWljb24iPicgKyBjYXJkSWNvbiArICc8L2Rpdj4nICsKICAgICAgICAgICc8c3BhbiBjbGFzcz0id2VhdGhlci10ZW1wIj4nICsgaC50ZW1wICsgJ8KwQzwvc3Bhbj4nICsKICAgICAgICAgICc8c3BhbiBjbGFzcz0id2VhdGhlci1yYWluJyArIHJhaW5DbGFzcyArICciPicgKyBXRUFUSEVSX0lDT05TLmRyb3BsZXQgKyAnICcgKyBoLnJhaW5fcHJvYiArICclJyArIHByZWNpcFRleHQgKyAnPC9zcGFuPicgKwogICAgICAgICAgJzxzcGFuIGNsYXNzPSJ3ZWF0aGVyLXdpbmQiPicgKyBXRUFUSEVSX0lDT05TLndpbmQgKyAnICcgKyBoLndpbmRfc3BlZWQgKyAnIGttL2gnICsgd2luZEd1c3RUZXh0ICsgJzwvc3Bhbj4nICsKICAgICAgICAnPC9kaXY+JwogICAgICApOwogICAgfQoKICAgIGNvbnN0IHRpbWVsaW5lSHRtbCA9CiAgICAgICc8ZGl2IGNsYXNzPSJ3ZWF0aGVyLXRpbWVsaW5lIiB0YWJpbmRleD0iMCIgcm9sZT0icmVnaW9uIiBhcmlhLWxhYmVsPSJGcmlzZSBjaHJvbm9sb2dpcXVlIGRlcyBwcsOpdmlzaW9ucyBtw6l0w6lvIj4nICsKICAgICAgICBjYXJkc0h0bWwuam9pbignJykgKwogICAgICAnPC9kaXY+JyArCiAgICAgICc8ZGl2IGNsYXNzPSJ3ZWF0aGVyLWF0dHJpYnV0aW9uIj4nICsKICAgICAgICAnRG9ubsOpZXMgbcOpdMOpbyA6IDxhIGhyZWY9Imh0dHBzOi8vb3Blbi1tZXRlby5jb20vIiB0YXJnZXQ9Il9ibGFuayIgcmVsPSJub29wZW5lciBub3JlZmVycmVyIj5PcGVuLU1ldGVvLmNvbTwvYT4gKENDIEJZIDQuMCknICsKICAgICAgJzwvZGl2Pic7CgogICAgY29udGVudC5pbm5lckhUTUwgPSB0aW1lbGluZUh0bWw7CiAgfQoKICAvLyBJbml0aWFsaXNhdGlvbiBhdSBjaGFyZ2VtZW50IGR1IERPTQogIGlmIChkb2N1bWVudC5yZWFkeVN0YXRlID09PSAnbG9hZGluZycpIHsKICAgIGRvY3VtZW50LmFkZEV2ZW50TGlzdGVuZXIoJ0RPTUNvbnRlbnRMb2FkZWQnLCBsb2FkQ29uY2VydFdlYXRoZXIpOwogIH0gZWxzZSB7CiAgICBsb2FkQ29uY2VydFdlYXRoZXIoKTsKICB9Cn0pKCk7Cg==', true);
                    if ($weatherScript === false && is_file(__DIR__ . '/concerts_weather.js')) $weatherScript = (string)file_get_contents(__DIR__ . '/concerts_weather.js');
                    if (is_string($weatherScript) && $weatherScript !== '') $listScript = $weatherScript;
                }
                if ($module === 'devis_factures' && documentsDeliveryReady($db)) {
                    $journal = documentsDeliveryJournal($db, (int)$id);
                    if ($journal && $journal['generation_status'] === 'complete') {
                        require_once __DIR__ . '/external_apps.php';
                        $pdfScripts = MusicbandExternalAppScript();
                        $listScript = $listScript !== null ? ($listScript . "\n;\n" . $pdfScripts) : $pdfScripts;
                    }
                }
            }
        } else {
            $settings = businessListSettings();
            if (MusicbandCan(MusicbandBusinessPermission($module),$user)) $content .= '<p class="business-record-actions"><a class="business-action business-new" href="' . MusicbandEscape($route) . '?edit=1">Nouveau ' . $recordLabel . '</a></p>';
            $content .= businessListControls($module) . businessListResults($db, $module, $config, $settings) . '</div></div>';
            $listScript = base64_decode('LyogUmVjaGVyY2hlIHByb2dyZXNzaXZlIGV0IHByw6lmw6lyZW5jZXMgZGUgY29sb25uZXMgOyBhdWN1bmUgZG9ubsOpZSBtw6l0aWVyIHN0b2Nrw6llIGxvY2FsZW1lbnQuICovCi8qIENvbXBsw6ltZW50IGRlcyBsaXN0ZXMgcmVuZHVlcyBwYXIgYnVzaW5lc3NfbGlzdC5waHAgOiByZWNoZXJjaGUgSFRUUCBldCBjaG9peCBkZSBjb2xvbm5lcy4KICogU2V1bHMgbGVzIGlkZW50aWZpYW50cyBkZXMgY29sb25uZXMgc29udCBjb25zZXJ2w6lzIGVuIGxvY2FsU3RvcmFnZSA7IGxlcyByw6lzdWx0YXRzIHJlc3RlbnQgY8O0dMOpIHNlcnZldXIuCiAqIEFib3J0Q29udHJvbGxlciBldCBjb21wdGV1ciBkZSBnw6luw6lyYXRpb24gZW1ww6pjaGVudCB1bmUgcsOpcG9uc2UgYW5jaWVubmUgZGUgcmVtcGxhY2VyIGxhIHJlY2hlcmNoZSBjb3VyYW50ZS4KICovCigoKSA9PiB7CiAgY29uc3Qgcm9vdCA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdidXNpbmVzcy1saXN0Jyk7CiAgaWYgKCFyb290KSByZXR1cm47CiAgY29uc3QgaW5wdXQgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgnYnVzaW5lc3MtcXVlcnknKTsKICBjb25zdCBmb3JtID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ2J1c2luZXNzLXNlYXJjaCcpOwogIGNvbnN0IHJlc3VsdHMgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgnYnVzaW5lc3MtcmVzdWx0cycpOwogIGNvbnN0IHN0YXR1cyA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdidXNpbmVzcy1zZWFyY2gtc3RhdHVzJyk7CiAgY29uc3QgdG9nZ2xlcyA9IFsuLi5yb290LnF1ZXJ5U2VsZWN0b3JBbGwoJ1tkYXRhLXRvZ2dsZS1jb2x1bW5dJyldOwogIGNvbnN0IGtleSA9ICdNdXNpY2JhbmQtY29sdW1ucy0nICsgcm9vdC5kYXRhc2V0Lm1vZHVsZTsKICBsZXQgdGltZXIsIHJlcXVlc3QsIGdlbmVyYXRpb24gPSAwOwogIGNvbnN0IGFwcGx5Q29sdW1ucyA9ICgpID0+IHsKICAgIGZvciAoY29uc3QgdG9nZ2xlIG9mIHRvZ2dsZXMpIHsKICAgICAgZm9yIChjb25zdCBjZWxsIG9mIHJlc3VsdHMucXVlcnlTZWxlY3RvckFsbCgnW2RhdGEtY29sdW1uPSInICsgdG9nZ2xlLmRhdGFzZXQudG9nZ2xlQ29sdW1uICsgJyJdJykpIGNlbGwuaGlkZGVuID0gIXRvZ2dsZS5jaGVja2VkOwogICAgfQogIH07CiAgdHJ5IHsKICAgIGNvbnN0IHNhdmVkID0gSlNPTi5wYXJzZShsb2NhbFN0b3JhZ2UuZ2V0SXRlbShrZXkpKTsKICAgIGlmIChBcnJheS5pc0FycmF5KHNhdmVkKSkgZm9yIChjb25zdCB0b2dnbGUgb2YgdG9nZ2xlcykgaWYgKCF0b2dnbGUuZGlzYWJsZWQpIHRvZ2dsZS5jaGVja2VkID0gc2F2ZWQuaW5jbHVkZXModG9nZ2xlLmRhdGFzZXQudG9nZ2xlQ29sdW1uKTsKICB9IGNhdGNoIChfKSB7IC8qIExlIHRhYmxlYXUgcmVzdGUgdXRpbGlzYWJsZSBzaSBsZSBzdG9ja2FnZSBlc3QgaW5kaXNwb25pYmxlLiAqLyB9CiAgZm9yIChjb25zdCB0b2dnbGUgb2YgdG9nZ2xlcykgdG9nZ2xlLmFkZEV2ZW50TGlzdGVuZXIoJ2NoYW5nZScsICgpID0+IHsKICAgIGFwcGx5Q29sdW1ucygpOwogICAgdHJ5IHsgbG9jYWxTdG9yYWdlLnNldEl0ZW0oa2V5LCBKU09OLnN0cmluZ2lmeSh0b2dnbGVzLmZpbHRlcih0ID0+IHQuY2hlY2tlZCkubWFwKHQgPT4gdC5kYXRhc2V0LnRvZ2dsZUNvbHVtbikpKTsgfSBjYXRjaCAoXykge30KICB9KTsKICBhcHBseUNvbHVtbnMoKTsKCiAgLy8gU2VjdGlvbiBkZSByZWNoZXJjaGUgcmVwbGlhYmxlIDogaW5kaWNhdGV1ciBkJ8OpdGF0LCBhbmltYXRpb24gZXQgcsOpc3Vtw6kgZHluYW1pcXVlLgogIGNvbnN0IGNvbGxhcHNpYmxlID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ2J1c2luZXNzLXNlYXJjaC1wYW5lbCcpOwogIGNvbnN0IHN1bW1hcnkgPSBjb2xsYXBzaWJsZT8ucXVlcnlTZWxlY3RvcignLk11c2ljYmFuZC1zZWFyY2gtaGVhZGVyJyk7CiAgY29uc3QgYm9keSA9IGNvbGxhcHNpYmxlPy5xdWVyeVNlbGVjdG9yKCcuTXVzaWNiYW5kLXNlYXJjaC1ib2R5Jyk7CiAgY29uc3QgYmFkZ2UgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgnYnVzaW5lc3Mtc2VhcmNoLWJhZGdlJyk7CiAgY29uc3QgcmVzZXRCdG4gPSBjb2xsYXBzaWJsZT8ucXVlcnlTZWxlY3RvcignLk11c2ljYmFuZC1zZWFyY2gtcXVpY2stcmVzZXQnKTsKICBsZXQgYW5pbWF0aW5nID0gZmFsc2U7CgogIC8vIEludmFsaWRlciBhdXNzaSBsZSBudW3DqXJvIGRlIGfDqW7DqXJhdGlvbiA6IHVuZSByw6lwb25zZSByZcOndWUgbWFsZ3LDqSBhYm9ydCByZXN0ZSBww6lyaW3DqWUuCiAgY29uc3QgaW52YWxpZGF0ZSA9ICgpID0+IHsgY2xlYXJUaW1lb3V0KHRpbWVyKTsgcmVxdWVzdD8uYWJvcnQoKTsgZ2VuZXJhdGlvbisrOyB9OwogIGNvbnN0IGxvYWQgPSBhc3luYyAodXJsLCB1cGRhdGVIaXN0b3J5ID0gdHJ1ZSkgPT4gewogICAgaW52YWxpZGF0ZSgpOyBjb25zdCBjdXJyZW50ID0gZ2VuZXJhdGlvbjsgcmVxdWVzdCA9IG5ldyBBYm9ydENvbnRyb2xsZXIoKTsKICAgIHJlc3VsdHMuc2V0QXR0cmlidXRlKCdhcmlhLWJ1c3knLCAndHJ1ZScpOyBzdGF0dXMudGV4dENvbnRlbnQgPSAnUmVjaGVyY2hlIGVuIGNvdXJz4oCmJzsKICAgIGNvbnN0IGNsZWFuID0gbmV3IFVSTCh1cmwpOyBjbGVhbi5zZWFyY2hQYXJhbXMuZGVsZXRlKCdhamF4Jyk7CiAgICBjb25zdCBlbmRwb2ludCA9IG5ldyBVUkwoY2xlYW4pOyBlbmRwb2ludC5zZWFyY2hQYXJhbXMuc2V0KCdhamF4JywgJzEnKTsKICAgIHRyeSB7CiAgICAgIGNvbnN0IHJlc3BvbnNlID0gYXdhaXQgZmV0Y2goZW5kcG9pbnQsIHtjcmVkZW50aWFsczogJ3NhbWUtb3JpZ2luJywgc2lnbmFsOiByZXF1ZXN0LnNpZ25hbCwgaGVhZGVyczogeydBY2NlcHQnOiAnYXBwbGljYXRpb24vanNvbid9fSk7CiAgICAgIGlmIChyZXNwb25zZS5yZWRpcmVjdGVkIHx8ICFyZXNwb25zZS5oZWFkZXJzLmdldCgnY29udGVudC10eXBlJyk/LmluY2x1ZGVzKCdhcHBsaWNhdGlvbi9qc29uJykpIHRocm93IG5ldyBFcnJvcignU2Vzc2lvbiBvdSByw6lwb25zZSBpbnZhbGlkZSA6IHJlY2hhcmdleiBsYSBwYWdlIHBvdXIgdm91cyByZWNvbm5lY3Rlci4nKTsKICAgICAgY29uc3QgcGF5bG9hZCA9IGF3YWl0IHJlc3BvbnNlLmpzb24oKTsKICAgICAgaWYgKCFyZXNwb25zZS5vayB8fCB0eXBlb2YgcGF5bG9hZC5odG1sICE9PSAnc3RyaW5nJykgdGhyb3cgbmV3IEVycm9yKHBheWxvYWQuZXJyb3IgfHwgJ1JlY2hlcmNoZSBpbmRpc3BvbmlibGUuJyk7CiAgICAgIGlmIChjdXJyZW50ICE9PSBnZW5lcmF0aW9uKSByZXR1cm47CiAgICAgIHJlc3VsdHMuaW5uZXJIVE1MID0gcGF5bG9hZC5odG1sOyBhcHBseUNvbHVtbnMoKTsKICAgICAgc3RhdHVzLnRleHRDb250ZW50ID0gJ1LDqXN1bHRhdHMgYWN0dWFsaXPDqXMuJzsKICAgICAgaWYgKHVwZGF0ZUhpc3RvcnkpIGhpc3RvcnkucmVwbGFjZVN0YXRlKG51bGwsICcnLCBjbGVhbik7CiAgICB9IGNhdGNoIChlcnJvcikgewogICAgICBpZiAoZXJyb3IubmFtZSAhPT0gJ0Fib3J0RXJyb3InICYmIGN1cnJlbnQgPT09IGdlbmVyYXRpb24pIHsKICAgICAgICBzdGF0dXMudGV4dENvbnRlbnQgPSAnUsOpc3VsdGF0cyBub24gYWN0dWFsaXPDqXMgOyBsZXMgcHLDqWPDqWRlbnRzIHJlc3RlbnQgYWZmaWNow6lzLiAnICsgZXJyb3IubWVzc2FnZTsKICAgICAgICBpZiAoY29sbGFwc2libGUpIGNvbGxhcHNpYmxlLnNldEF0dHJpYnV0ZSgnb3BlbicsICcnKTsKICAgICAgfQogICAgfSBmaW5hbGx5IHsgaWYgKGN1cnJlbnQgPT09IGdlbmVyYXRpb24pIHJlc3VsdHMucmVtb3ZlQXR0cmlidXRlKCdhcmlhLWJ1c3knKTsgfQogIH07CiAgY29uc3Qgc2VhcmNoVVJMID0gKCkgPT4gewogICAgY29uc3QgdXJsID0gbmV3IFVSTChsb2NhdGlvbi5ocmVmKTsgdXJsLnNlYXJjaFBhcmFtcy5zZXQoJ3EnLCBpbnB1dC52YWx1ZSk7CiAgICBmb3IgKGNvbnN0IGtleSBvZiBbJ3BhZ2UnLCd1cGNvbWluZ19wYWdlJywncGFzdF9wYWdlJywndW5rbm93bl9wYWdlJywnYWpheCddKSB1cmwuc2VhcmNoUGFyYW1zLmRlbGV0ZShrZXkpOwogICAgcmV0dXJuIHVybDsKICB9OwoKICBjb25zdCB1cGRhdGVTdW1tYXJ5ID0gKCkgPT4gewogICAgaWYgKCFiYWRnZSkgcmV0dXJuOwogICAgY29uc3QgcSA9IGlucHV0LnZhbHVlLnRyaW0oKTsKICAgIGlmIChxICE9PSAnJykgewogICAgICBiYWRnZS50ZXh0Q29udGVudCA9ICdGaWx0cmUgYWN0aWYgOiBSZWNoZXJjaGUgwqsgJyArIHEgKyAnIMK7JzsKICAgICAgYmFkZ2UuY2xhc3NMaXN0LmFkZCgnaXMtYWN0aXZlJyk7CiAgICAgIGlmIChyZXNldEJ0bikgcmVzZXRCdG4uaGlkZGVuID0gZmFsc2U7CiAgICB9IGVsc2UgewogICAgICBiYWRnZS50ZXh0Q29udGVudCA9ICdBdWN1biBmaWx0cmUgYWN0aWYnOwogICAgICBiYWRnZS5jbGFzc0xpc3QucmVtb3ZlKCdpcy1hY3RpdmUnKTsKICAgICAgaWYgKHJlc2V0QnRuKSByZXNldEJ0bi5oaWRkZW4gPSB0cnVlOwogICAgfQogIH07CgogIGNvbnN0IGNvbGxhcHNlUGFuZWwgPSAoKSA9PiB7CiAgICBpZiAoIWNvbGxhcHNpYmxlIHx8ICFjb2xsYXBzaWJsZS5vcGVuIHx8ICFib2R5KSByZXR1cm47CiAgICBpZiAod2luZG93Lm1hdGNoTWVkaWEoJyhwcmVmZXJzLXJlZHVjZWQtbW90aW9uOiByZWR1Y2UpJykubWF0Y2hlcykgewogICAgICBjb2xsYXBzaWJsZS5yZW1vdmVBdHRyaWJ1dGUoJ29wZW4nKTsKICAgICAgcmV0dXJuOwogICAgfQogICAgaWYgKGFuaW1hdGluZykgcmV0dXJuOwogICAgYW5pbWF0aW5nID0gdHJ1ZTsKICAgIGNvbnN0IHN0YXJ0ID0gYm9keS5vZmZzZXRIZWlnaHQ7CiAgICBib2R5LnN0eWxlLm92ZXJmbG93ID0gJ2hpZGRlbic7CiAgICBib2R5LnN0eWxlLmhlaWdodCA9IHN0YXJ0ICsgJ3B4JzsKICAgIGJvZHkuc3R5bGUub3BhY2l0eSA9ICcxJzsKICAgIGJvZHkuc3R5bGUudHJhbnNpdGlvbiA9ICdoZWlnaHQgMC4ycyBlYXNlLCBvcGFjaXR5IDAuMTVzIGVhc2UnOwogICAgYm9keS5vZmZzZXRIZWlnaHQ7IC8vIHJlZmxvdwogICAgYm9keS5zdHlsZS5oZWlnaHQgPSAnMHB4JzsKICAgIGJvZHkuc3R5bGUub3BhY2l0eSA9ICcwJzsKICAgIGNvbnN0IG9uRW5kID0gKCkgPT4gewogICAgICBib2R5LnJlbW92ZUV2ZW50TGlzdGVuZXIoJ3RyYW5zaXRpb25lbmQnLCBvbkVuZCk7CiAgICAgIGNvbGxhcHNpYmxlLnJlbW92ZUF0dHJpYnV0ZSgnb3BlbicpOwogICAgICBib2R5LnN0eWxlLnJlbW92ZVByb3BlcnR5KCdoZWlnaHQnKTsKICAgICAgYm9keS5zdHlsZS5yZW1vdmVQcm9wZXJ0eSgnb3BhY2l0eScpOwogICAgICBib2R5LnN0eWxlLnJlbW92ZVByb3BlcnR5KCdvdmVyZmxvdycpOwogICAgICBib2R5LnN0eWxlLnJlbW92ZVByb3BlcnR5KCd0cmFuc2l0aW9uJyk7CiAgICAgIGFuaW1hdGluZyA9IGZhbHNlOwogICAgfTsKICAgIGJvZHkuYWRkRXZlbnRMaXN0ZW5lcigndHJhbnNpdGlvbmVuZCcsIG9uRW5kKTsKICB9OwoKICBjb25zdCBleHBhbmRQYW5lbCA9ICgpID0+IHsKICAgIGlmICghY29sbGFwc2libGUgfHwgY29sbGFwc2libGUub3BlbiB8fCAhYm9keSkgcmV0dXJuOwogICAgaWYgKHdpbmRvdy5tYXRjaE1lZGlhKCcocHJlZmVycy1yZWR1Y2VkLW1vdGlvbjogcmVkdWNlKScpLm1hdGNoZXMpIHsKICAgICAgY29sbGFwc2libGUuc2V0QXR0cmlidXRlKCdvcGVuJywgJycpOwogICAgICByZXR1cm47CiAgICB9CiAgICBpZiAoYW5pbWF0aW5nKSByZXR1cm47CiAgICBhbmltYXRpbmcgPSB0cnVlOwogICAgY29sbGFwc2libGUuc2V0QXR0cmlidXRlKCdvcGVuJywgJycpOwogICAgY29uc3QgdGFyZ2V0ID0gYm9keS5vZmZzZXRIZWlnaHQ7CiAgICBib2R5LnN0eWxlLm92ZXJmbG93ID0gJ2hpZGRlbic7CiAgICBib2R5LnN0eWxlLmhlaWdodCA9ICcwcHgnOwogICAgYm9keS5zdHlsZS5vcGFjaXR5ID0gJzAnOwogICAgYm9keS5zdHlsZS50cmFuc2l0aW9uID0gJ2hlaWdodCAwLjJzIGVhc2UsIG9wYWNpdHkgMC4ycyBlYXNlJzsKICAgIGJvZHkub2Zmc2V0SGVpZ2h0OyAvLyByZWZsb3cKICAgIGJvZHkuc3R5bGUuaGVpZ2h0ID0gdGFyZ2V0ICsgJ3B4JzsKICAgIGJvZHkuc3R5bGUub3BhY2l0eSA9ICcxJzsKICAgIGNvbnN0IG9uRW5kID0gKCkgPT4gewogICAgICBib2R5LnJlbW92ZUV2ZW50TGlzdGVuZXIoJ3RyYW5zaXRpb25lbmQnLCBvbkVuZCk7CiAgICAgIGJvZHkuc3R5bGUucmVtb3ZlUHJvcGVydHkoJ2hlaWdodCcpOwogICAgICBib2R5LnN0eWxlLnJlbW92ZVByb3BlcnR5KCdvcGFjaXR5Jyk7CiAgICAgIGJvZHkuc3R5bGUucmVtb3ZlUHJvcGVydHkoJ292ZXJmbG93Jyk7CiAgICAgIGJvZHkuc3R5bGUucmVtb3ZlUHJvcGVydHkoJ3RyYW5zaXRpb24nKTsKICAgICAgYW5pbWF0aW5nID0gZmFsc2U7CiAgICB9OwogICAgYm9keS5hZGRFdmVudExpc3RlbmVyKCd0cmFuc2l0aW9uZW5kJywgb25FbmQpOwogIH07CgogIGlmIChzdW1tYXJ5KSB7CiAgICBzdW1tYXJ5LmFkZEV2ZW50TGlzdGVuZXIoJ2NsaWNrJywgZXZlbnQgPT4gewogICAgICBpZiAoZXZlbnQudGFyZ2V0LmNsb3Nlc3QoJ2EsIGJ1dHRvbicpIHx8IHdpbmRvdy5tYXRjaE1lZGlhKCcocHJlZmVycy1yZWR1Y2VkLW1vdGlvbjogcmVkdWNlKScpLm1hdGNoZXMpIHJldHVybjsKICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTsKICAgICAgaWYgKGNvbGxhcHNpYmxlLm9wZW4pIHsKICAgICAgICBjb2xsYXBzZVBhbmVsKCk7CiAgICAgIH0gZWxzZSB7CiAgICAgICAgZXhwYW5kUGFuZWwoKTsKICAgICAgfQogICAgfSk7CiAgfQoKICBpZiAocmVzZXRCdG4pIHsKICAgIHJlc2V0QnRuLmFkZEV2ZW50TGlzdGVuZXIoJ2NsaWNrJywgZXZlbnQgPT4gewogICAgICBldmVudC5zdG9wUHJvcGFnYXRpb24oKTsKICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTsKICAgICAgaW5wdXQudmFsdWUgPSAnJzsKICAgICAgdXBkYXRlU3VtbWFyeSgpOwogICAgICBsb2FkKHNlYXJjaFVSTCgpKTsKICAgIH0pOwogIH0KCiAgaW5wdXQuYWRkRXZlbnRMaXN0ZW5lcignaW5wdXQnLCBldmVudCA9PiB7CiAgICBpbnZhbGlkYXRlKCk7CiAgICB1cGRhdGVTdW1tYXJ5KCk7CiAgICByZXN1bHRzLnNldEF0dHJpYnV0ZSgnYXJpYS1idXN5JywgJ3RydWUnKTsKICAgIHN0YXR1cy50ZXh0Q29udGVudCA9ICdSZWNoZXJjaGUgZW4gY291cnPigKYnOwogICAgaWYgKCFldmVudC5pc0NvbXBvc2luZykgdGltZXIgPSBzZXRUaW1lb3V0KCgpID0+IGxvYWQoc2VhcmNoVVJMKCkpLCAyNTApOwogIH0pOwogIGlucHV0LmFkZEV2ZW50TGlzdGVuZXIoJ2NvbXBvc2l0aW9uZW5kJywgKCkgPT4gewogICAgaW52YWxpZGF0ZSgpOwogICAgdXBkYXRlU3VtbWFyeSgpOwogICAgdGltZXIgPSBzZXRUaW1lb3V0KCgpID0+IGxvYWQoc2VhcmNoVVJMKCkpLCAyNTApOwogIH0pOwogIGZvcm0uYWRkRXZlbnRMaXN0ZW5lcignc3VibWl0JywgZXZlbnQgPT4gewogICAgZXZlbnQucHJldmVudERlZmF1bHQoKTsKICAgIHVwZGF0ZVN1bW1hcnkoKTsKICAgIGxvYWQoc2VhcmNoVVJMKCkpOwogICAgLy8gUmVwbGkgYXV0b21hdGlxdWUgZMOocyBsYSBzb3VtaXNzaW9uIGRlIGxhIHJlY2hlcmNoZSAoYm91dG9uIG91IHRvdWNoZSBFbnRyw6llKS4KICAgIGNvbGxhcHNlUGFuZWwoKTsKICB9KTsKICByZXN1bHRzLmFkZEV2ZW50TGlzdGVuZXIoJ2NsaWNrJywgZXZlbnQgPT4gewogICAgY29uc3QgbGluayA9IGV2ZW50LnRhcmdldC5jbG9zZXN0KCdhW2RhdGEtbGlzdC1wYWdlXScpOwogICAgaWYgKCFsaW5rIHx8IGV2ZW50LmJ1dHRvbiAhPT0gMCB8fCBldmVudC5jdHJsS2V5IHx8IGV2ZW50Lm1ldGFLZXkgfHwgZXZlbnQuc2hpZnRLZXkgfHwgZXZlbnQuYWx0S2V5KSByZXR1cm47CiAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpOyBsb2FkKG5ldyBVUkwobGluay5ocmVmKSk7CiAgfSk7CiAgd2luZG93LmFkZEV2ZW50TGlzdGVuZXIoJ3BvcHN0YXRlJywgKCkgPT4gewogICAgaW5wdXQudmFsdWUgPSBuZXcgVVJMKGxvY2F0aW9uLmhyZWYpLnNlYXJjaFBhcmFtcy5nZXQoJ3EnKSB8fCAnJzsKICAgIHVwZGF0ZVN1bW1hcnkoKTsKICAgIGxvYWQobmV3IFVSTChsb2NhdGlvbi5ocmVmKSwgZmFsc2UpOwogIH0pOwp9KSgpOwo=', true);
        }
    } catch (Throwable $e) {
        $message = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Module indisponible : vérifier MySQL et créer les schémas requis dans Upgrade (002/003 puis 005/006 pour Devis/Factures).';
        $content .= '<p class="notice" role="alert">' . MusicbandEscape($message) . '</p>';
        if ($user['admin']) $content .= '<p><a href="' . MusicbandEscape($base) . 'upgrade.php">Ouvrir Upgrade</a></p>';
    }
    MusicbandScreen($pageTitle, $content, $listScript, $listScript !== null, $photonUrl, '<link rel="stylesheet" href="./css/common.css?v=2026.10.22.22-community"><link rel="stylesheet" href="./css/navigation.css?v=2026.10.22.22-community"><details class="Musicband-menu"><summary aria-label="Ouvrir ou fermer le menu général"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></summary><nav class="Musicband-links" aria-label="Menu général Musicband"><a href="./" data-Musicband-module="home"> <span class="Musicband-icon" data-Musicband-icon="home"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 10 12 3l9 7v11h-6v-7H9v7H3Z"/></svg></span><span>Musicband Toolbox</span><span class="Musicband-beta" data-Musicband-beta="home" hidden></span></a><a href="./setlist/" data-Musicband-module="setlist"> <span class="Musicband-icon" data-Musicband-icon="setlist"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 18V5l12-2v13M9 8l12-2M9 18a3 3 0 1 1-3-3h3m12 1a3 3 0 1 1-3-3h3"/></svg></span><span>Setlist</span><span class="Musicband-beta" data-Musicband-beta="setlist" hidden></span></a><a href="./songs.php" data-Musicband-module="songs"> <span class="Musicband-icon" data-Musicband-icon="songs"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 18V5l12-2v13M9 8l12-2M9 18a3 3 0 1 1-3-3h3m12 1a3 3 0 1 1-3-3h3"/></svg></span><span>Morceaux</span><span class="Musicband-beta" data-Musicband-beta="songs" hidden></span></a><a href="./contacts.php" data-Musicband-module="contacts"> <span class="Musicband-icon" data-Musicband-icon="contacts"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 3h16v18H4ZM2 7h4m-4 5h4m-4 5h4m4-10a2 2 0 1 0 4 0 2 2 0 0 0-4 0m-1 10v-2a3 3 0 0 1 6 0v2"/></svg></span><span>Contacts</span><span class="Musicband-beta" data-Musicband-beta="contacts" hidden></span></a><a href="./concerts.php" data-Musicband-module="concerts"> <span class="Musicband-icon" data-Musicband-icon="concerts"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5h16v16H4Zm3-3v6m10-6v6M4 10h16m-12 4h2m4 0h2m-8 4h2"/></svg></span><span>Concerts</span><span class="Musicband-beta" data-Musicband-beta="concerts" hidden></span></a><a href="./calendar.php" data-Musicband-module="agenda"> <span class="Musicband-icon" data-Musicband-icon="agenda"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5h16v16H4Zm3-3v6m10-6v6M4 10h16m-12 4h2m4 0h2m-8 4h2"/></svg></span><span>Agenda</span><span class="Musicband-beta" data-Musicband-beta="agenda" hidden></span></a><a href="./tasks.php" data-Musicband-module="tasks"> <span class="Musicband-icon" data-Musicband-icon="tasks"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span><span>Gestion des tâches</span><span class="Musicband-beta" data-Musicband-beta="tasks" hidden></span></a><a href="./admin.php" data-Musicband-admin="1" data-Musicband-module="admin"> <span class="Musicband-icon" data-Musicband-icon="admin"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h16M4 18h16M8 3v6m8 0v6m-6 0v6"/></svg></span><span>Configuration</span><span class="Musicband-beta" data-Musicband-beta="admin" hidden></span></a><a href="./audit.php" data-Musicband-admin="1" data-Musicband-module="audit"> <span class="Musicband-icon" data-Musicband-icon="audit"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 3h9l5 5v13H5Zm9 0v6h5M8 13h8m-8 4h8"/></svg></span><span>Audit trail</span><span class="Musicband-beta" data-Musicband-beta="audit" hidden></span></a><a href="./ideas.php" data-Musicband-module="ideas"> <span class="Musicband-icon" data-Musicband-icon="ideas"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m12 3 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1Z"/></svg></span><span>Boîte à idées</span><span class="Musicband-beta" data-Musicband-beta="ideas" hidden></span></a><a href="./activate.php?change=1" data-Musicband-module="password"> <span class="Musicband-icon" data-Musicband-icon="password"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h16M4 18h16M8 3v6m8 0v6m-6 0v6"/></svg></span><span>Préférences</span><span class="Musicband-beta" data-Musicband-beta="password" hidden></span></a><a href="./logout.php" data-Musicband-module="logout"> <span class="Musicband-icon" data-Musicband-icon="logout"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3H4v18h5m5-14 5 5-5 5m-5-5h14"/></svg></span><span>Déconnexion</span><span class="Musicband-beta" data-Musicband-beta="logout" hidden></span></a><span class="Musicband-version">Version web 2026.10.22.22-community</span></nav></details><details class="Musicband-help-menu"><summary aria-label="Aide" title="Aide">?</summary>
<div class="Musicband-help-panel" role="region" aria-label="Aide des modules Musicband">
<h2>Utiliser Musicband Toolbox</h2>
<p>Choisissez une rubrique. En haut de chaque page, Instructions rappelle les premières étapes.</p>
<details><summary>Contacts</summary>
<p>Retrouvez les personnes et les lieux dans votre carnet. Tapez un nom ou un lieu dans Rechercher : la liste se met à jour toute seule.</p>
<p>Cliquez sur un nom pour ouvrir sa fiche. Colonnes affichées permet de choisir les informations visibles dans le tableau.</p>
<p>Si les boutons sont disponibles, utilisez Nouveau contact pour ajouter une personne ou Modifier pour corriger sa fiche. Cliquez sur Enregistrer pour garder les changements, ou Annuler pour revenir sans les garder.</p>
</details>
<details><summary>Concerts</summary>
<p>Les concerts à venir et les concerts passés sont présentés dans deux tableaux. Ceux qui n’ont pas de date connue restent dans Dates à préciser.</p>
<p>Tapez dans Rechercher pour retrouver un concert. Cliquez sur son nom pour lire les détails. Colonnes affichées permet de choisir les informations du tableau.</p>
<p>Pour créer ou modifier un concert, utilisez les boutons proposés. Renseignez le nom, la date, le lieu et les informations utiles. Choisissez le contact lié au concert, ou Nouveau client pour en ajouter un sur place. Cliquez sur Enregistrer pour garder la fiche.</p>
<p>Dans la fiche du concert, choisissez une setlist dans la liste puis cliquez sur Associer la setlist au concert. Dissocier retire le lien sans supprimer la setlist. Une setlist liée ne peut pas être supprimée ; pour la réutiliser ailleurs, faites-en une copie sans concert associé.</p>
</details>
<details><summary>Devis / Factures</summary>
<p>Choisissez Devis ou Facture, puis le client et le concert. Si le client n’existe pas encore, utilisez Nouveau client sans quitter la page.</p>
<p>Le nom et l’adresse viennent de la fiche du client. La date de prestation, la durée, le montant et le mode de règlement viennent du concert. Pour corriger ces informations, modifiez la fiche concernée puis enregistrez à nouveau le document.</p>
<p>Au premier enregistrement, un numéro est donné automatiquement, par exemple Devis 2026-01 ou Facture 2026-01. Chaque type a sa propre suite de numéros pour l’année en cours. Le numéro reste le même lors des modifications.</p>
<p>Le document commence en Préparation. Vérifiez les informations, puis utilisez Valider. Si une correction est nécessaire, remettez-le en Préparation. La création du PDF et l’envoi au client ne sont pas encore disponibles.</p>
</details>
<details><summary>Setlist · les morceaux du concert</summary>
<p>Choisissez une setlist, puis une variante : c’est une autre version de la même liste. Vérifiez l’ordre des morceaux et la durée totale.</p>
<p>Activez le mode édition pour changer la liste. Déplacez les morceaux avec la poignée ou les flèches ↑ et ↓. Les boutons → Rappel et → Set déplacent un morceau entre la partie principale et le rappel.</p>
<p>Afficher les morceaux non sélectionnés ouvre la réserve. Le bouton + ajoute un morceau ; le bouton − le retire et le remet dans la réserve, y compris s’il était dans le rappel. Certains morceaux restent groupés ou à une place imposée.</p>
<p>Enregistrer crée une nouvelle version avec la date et votre nom. Les versions précédentes restent disponibles. Rétablir la variante annule les changements non enregistrés. Enregistrez avant de changer de liste ou de quitter la page.</p>
<p>Pour emporter la liste, choisissez Export Tempo, Export SACEM, Export Excel ou Imprimer. L’export SACEM contient le lieu du concert, les titres et les artistes, sans séparer le rappel. Il n’est pas envoyé à la SACEM. Les exports reprennent la liste affichée, même si vos derniers changements ne sont pas encore enregistrés.</p>
<p>Sur iPhone, utilisez les options de partage du navigateur pour garder le fichier dans Fichiers.</p>
</details>
<details><summary>Sound · XR18 · les réglages du son</summary>
<p>Ouvrez un fichier de scène .scn ou une scène déjà conservée. Une scène contient les réglages de la console. Sur iPhone, si le fichier est grisé, faites-en une copie et changez la fin de son nom en .txt.</p>
<p>Choisissez la piste et lancez l’analyse sans IA pour lire ses réglages. Choisissez ensuite l’instrument, décrivez le problème entendu et cliquez sur Construire les essais sans IA.</p>
<p>Essayez les réglages proposés sur la console, un changement à la fois, puis écoutez le résultat. La page ne change pas les réglages de la console à votre place.</p>
<p>Avec Noter le résultat après écoute, indiquez si le son est amélioré, inchangé ou dégradé, puis enregistrez votre retour.</p>
<p>Le menu Actions permet d’ouvrir le rapport PDF. Les autres exports permettent de garder les réglages et les conseils dans un fichier. Sur iPhone, utilisez Partager pour l’enregistrer dans Fichiers.</p>
<p>Gardez vos fichiers d’origine : seules les trois scènes les plus récentes sont conservées sur le site. Enregistrez les rapports utiles avant de quitter la page.</p>
</details>
<details><summary>Se déplacer et se connecter</summary>
<p>Le menu général permet de revenir à l’accueil, de changer de module ou de se déconnecter. Si votre connexion a expiré, reconnectez-vous. Pensez à enregistrer vos changements avant de quitter une page.</p>
</details>
<p>Cliquez de nouveau sur ? pour fermer ce guide. Chaque rubrique peut aussi être repliée.</p>
</div></details>');
}
