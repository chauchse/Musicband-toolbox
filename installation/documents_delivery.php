<?php
/**
 * Musicband DOCUMENTS DELIVERY : PDF Drive et transmission de documents.
 *
 * Responsabilité :
 * - Stockage et récupération des PDF de devis et factures sur Google Drive via OAuth.
 * - Transmission par e-mail avec PJ PDF via le service e-mail centralisé (Gmail API / mail()).
 * - Suivi et traçabilité des livraisons (journal devis_factures_deliveries).
 *
 * Dépendances internes : config.json, business.php, rbac.php, email_service.php.
 * Dépendances externes : Google Drive / Docs / Gmail API (HTTPS cURL).
 */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/email_service.php';
require_once __DIR__ . '/external_apps.php';
const DOCUMENTS_PDF_MAX_BYTES=8000000;
function documentsGoogleCredentials(): array {
    $saved=MusicbandRead('home_settings')['documents_oauth']??[]; $values=[];
    foreach (['client_id'=>'GOOGLE_DRIVE_OAUTH_CLIENT_ID','client_secret'=>'GOOGLE_DRIVE_OAUTH_CLIENT_SECRET','refresh_token'=>'GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN'] as $key=>$name) $values[$key]=$saved[$key]??(getenv($name)?:'');
    return $values;
}
function documentsGoogleSettingsSave(): string {
    if (!MusicbandRequireLogin()['admin']) throw new RuntimeException('Accès administrateur requis.');
    $settings=MusicbandRead('home_settings'); $old=$settings['documents_oauth']??[];
    if (!is_string($_POST['google_revision']??null) || !hash_equals($old['revision']??'',$_POST['google_revision'])) throw new RuntimeException('Accès Google modifiés : recharger Configuration.');
    $effective=documentsGoogleCredentials(); $values=array_intersect_key($old,array_flip(['client_id','client_secret','refresh_token']));
    foreach (['client_id'=>2048,'client_secret'=>2048,'refresh_token'=>8192] as $key=>$maximum) {
        $value=$_POST['google_'.$key]??null;
        if (!is_string($value) || strlen($value)>$maximum || preg_match('/[\x00-\x20\x7f]/',$value)) throw new RuntimeException('Identifiant ou secret Google invalide (sans espace ni retour à la ligne).');
        if ($value!=='') { $values[$key]=$value; $effective[$key]=$value; }
        if (!is_string($effective[$key]) || $effective[$key]==='') throw new RuntimeException('Renseigner les trois paramètres OAuth lors de la première configuration.');
    }
    $values['revision']=bin2hex(random_bytes(16)); $settings['documents_oauth']=$values; MusicbandWrite('home_settings',$settings);
    return 'Accès Google enregistrés dans le stockage privé. Aucun appel Google effectué. Les secrets ne sont pas réaffichés.';
}
function documentsGoogleSettingsForm(string $hidden): string {
    $values=documentsGoogleCredentials(); $saved=MusicbandRead('home_settings')['documents_oauth']??[];
    $html='<section><h2>Accès Google Drive · OAuth</h2><p>Paramètres en écriture seule, conservés hors racine publique dans le stockage privé existant. Laisser vide pour conserver une valeur. Les variables serveur GOOGLE_DRIVE_OAUTH_* servent de repli si aucune valeur privée n’est enregistrée. Obtenir le refresh token avec votre propre client OAuth selon la procédure du README. Aucun appel externe à la sauvegarde.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="documents_google_settings"><input type="hidden" name="google_revision" value="'.MusicbandEscape($saved['revision']??'').'">';
    foreach (['client_id'=>'Client ID OAuth','client_secret'=>'Client secret OAuth','refresh_token'=>'Refresh token OAuth'] as $key=>$label) $html.='<label>'.$label.' · '.($values[$key]!==''?'présent':'à renseigner').'<input type="password" name="google_'.$key.'" maxlength="'.($key==='refresh_token'?8192:2048).'" autocomplete="new-password" placeholder="Laisser vide pour conserver"></label>';
    return $html.'<button>Enregistrer les accès privés Google</button></form></section>';
}
function documentsDriveId($value): string {
    if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{10,200}$/D',$value)) throw new RuntimeException('Identifiant Google Drive requis (sans URL).');
    return $value;
}
function documentsPdfSettings(): array {
    $defaults=json_decode(base64_decode('eyJmb2xkZXIiOiIifQ==',true),true,8,JSON_THROW_ON_ERROR);
    return array_replace(['folder'=>getenv('GOOGLE_DRIVE_DOCUMENTS_FOLDER_ID')?:($defaults['folder']??''),'Devis'=>'','Facture'=>''],MusicbandRead('home_settings')['documents_pdf']??[]);
}
function documentsPdfSettingsSave(): string {
    if (!MusicbandRequireLogin()['admin']) throw new RuntimeException('Accès administrateur requis.');
    $old=documentsPdfSettings();
    if (!is_string($_POST['pdf_version']??null) || !hash_equals(hash('sha256',businessJson($old)),$_POST['pdf_version'])) throw new RuntimeException('Configuration PDF modifiée : recharger.');
    $values=[];
    foreach (['folder','Devis','Facture'] as $key) { $value=$_POST['pdf_'.$key]??null; $values[$key]=documentsDriveId(is_string($value)?trim($value):$value); }
    if ($values['folder']===$values['Devis'] || $values['folder']===$values['Facture']) throw new RuntimeException('Le dossier et les modèles doivent être distincts.');
    $settings=MusicbandRead('home_settings'); $settings['documents_pdf']=$values; MusicbandWrite('home_settings',$settings);
    return 'Dossier et modèles PDF enregistrés. Aucun accès Google ni génération effectués.';
}
function documentsPdfSettingsForm(string $hidden): string {
    $settings=documentsPdfSettings();
    $html='<section><h2>PDF Devis / Factures · Google Drive</h2><p>Indiquer des identifiants, pas des liens. Le dossier est initialisé depuis GOOGLE_DRIVE_DOCUMENTS_FOLDER_ID lors de la préparation du site. Les modèles doivent être des Google Docs contenant les balises documentées dans le README. Chaque génération crée une copie du modèle et un PDF dans ce dossier, sans modifier les modèles. Les accès OAuth se configurent en écriture seule dans Configuration ou dans l’environnement privé du serveur.</p><details><summary>Balises à placer dans les modèles</summary><p>Obligatoires : {{numero}}, {{date_document}}, {{client}}, {{adresse}}, {{date_prestation}}, {{duree}}, {{montant}}, {{reglement}}. Facultatives : {{type_document}}, {{commentaires}}. Les valeurs sont reprises telles qu’enregistrées, sans calcul fiscal.</p></details><form method="post">'.$hidden.'<input type="hidden" name="action" value="documents_pdf_settings"><input type="hidden" name="pdf_version" value="'.hash('sha256',businessJson($settings)).'">';
    foreach (['folder'=>'Dossier Drive des PDF','Devis'=>'Modèle Google Docs · Devis','Facture'=>'Modèle Google Docs · Facture'] as $key=>$label) $html.='<label>'.$label.'<input name="pdf_'.$key.'" required maxlength="200" value="'.MusicbandEscape($settings[$key]).'"></label>';
    return $html.'<button>Enregistrer le dossier et les modèles</button></form></section>';
}
function documentsDeliveryReady(PDO $db): bool {
    if (!documentsTableExists($db,'devis_factures_deliveries')) return false;
    $db->query('SELECT document_id,generation_status,snapshot_json,template_copy_id,pdf_file_id,pdf_sha256,generated_by,generation_requested_at,generated_at,mail_status,mail_json,sent_by,mail_requested_at,mail_completed_at FROM devis_factures_deliveries LIMIT 0');
    if ($db->query("SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='devis_factures_deliveries'")->fetchColumn()!=='InnoDB') return false;
    $keys=$db->query("SELECT COLUMN_NAME FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='devis_factures_deliveries' AND index_name='PRIMARY' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
    if ($keys!==['document_id']) return false;
    foreach (documentsForeignKeys($db) as $key) if ($key['TABLE_NAME']==='devis_factures_deliveries' && $key['COLUMN_NAME']==='document_id' && $key['REFERENCED_TABLE_NAME']==='devis_factures_documents' && $key['REFERENCED_COLUMN_NAME']==='id' && $key['DELETE_RULE']==='RESTRICT' && $key['UPDATE_RULE']==='RESTRICT') return true;
    return false;
}
function documentsDeliverySchema(array $settings): string {
    if (($_POST['confirm']??'')!=='yes' || ($_POST['confirm_database']??null)!==($settings['DB_NAME']??null)) throw new RuntimeException('Confirmer sauvegarde et base cible.');
    $db=MusicbandDatabase($settings); documentsWorkflowLoad($db);
    if (!documentsWorkflowReady($db)) throw new RuntimeException('Schéma 006 complet requis.');
    if (documentsTableExists($db,'devis_factures_deliveries')) throw new RuntimeException('Journal déjà présent : vérifier sa structure, aucune recréation.');
    $sql=base64_decode('',true);
    if (!$sql) throw new RuntimeException('Schéma 009 non embarqué.');
    $db->exec($sql);
    if (!documentsDeliveryReady($db)) throw new RuntimeException('Journal incomplet : inspecter la base, ne pas supprimer les données.');
    return 'Schéma 009 créé. Aucun PDF ni message produit.';
}
function documentsDeliveryUpgradeForm(string $hidden): string {
    return '<section><h3>Documents PDF et envois · schéma 009</h3><p>Une table de suivi additive, aucune fiche réécrite. Sauvegarder la base. Conserver ce journal même lors d’un retour arrière pour empêcher les doublons.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="delivery_schema"><label>Nom exact de la base<input name="confirm_database" required></label><label><input type="checkbox" name="confirm" value="yes" required> Sauvegarde et destination vérifiées.</label><button>Créer le schéma 009 · PDF et envois</button></form></section>';
}
function documentsDeliveryJournal(PDO $db,int $id,bool $lock=false): ?array {
    $q=$db->prepare('SELECT * FROM devis_factures_deliveries WHERE document_id=?'.($lock?' FOR UPDATE':'')); $q->execute([$id]); return $q->fetch()?:null;
}
function documentsDeliveryStarted(PDO $db,int $id): bool {
    return documentsTableExists($db,'devis_factures_deliveries') && documentsDeliveryJournal($db,$id)!==null;
}

/**
 * Exécute une requête HTTPS vers les API Google autorisées.
 *
 * Dépendances : extension PHP cURL.
 *
 * Sécurité :
 * - Destinations Google strictement contrôlées.
 * - Validation SSL obligatoire.
 * - Pas de redirection HTTP.
 * - Taille de réponse limitée.
 * - Aucun token ni secret journalisé.
 * - Aucune relance automatique.
 *
 * @throws RuntimeException En cas d'erreur réseau ou Google API.
 */
function documentsGoogleRequest(
    string $url,
    string $method = 'GET',
    ?string $body = null,
    array $headers = [],
    int $limit = 1048576
): string {

    // Vérification des prérequis.
    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'Extension PHP cURL requise sur le serveur.'
        );
    }

    // Autoriser uniquement les API Google nécessaires.
    if (!preg_match(
        '~^https://(?:oauth2\.googleapis\.com/token|www\.googleapis\.com/(?:drive/|upload/drive/|calendar/v3/calendars/)|docs\.googleapis\.com/v1/documents/|gmail\.googleapis\.com/gmail/v1/users/me/messages/)~D',
        $url
    )) {
        throw new RuntimeException(
            'Destination Google non autorisée.'
        );
    }

    // Identifier le service pour faciliter le diagnostic.
    $service = 'Google API';

    $host = parse_url($url, PHP_URL_HOST);

    if ($host === 'oauth2.googleapis.com') {
        $service = 'Google OAuth';
    } elseif ($host === 'docs.googleapis.com') {
        $service = 'Google Docs';
    } elseif ($host === 'www.googleapis.com') {
        $service = 'Google Drive';
    } elseif ($host === 'gmail.googleapis.com') {
        $service = 'Google Gmail';
    }

    // Initialisation de la requête.
    $response = '';
    $responseLimitExceeded = false;
    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException(
            'Initialisation cURL impossible.'
        );
    }

    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,

        // Limiter la mémoire utilisée pour la réponse.
        CURLOPT_WRITEFUNCTION => function ($handle, $chunk)
            use (&$response, $limit, &$responseLimitExceeded) {

            if (strlen($response) + strlen($chunk) > $limit) {
                $responseLimitExceeded = true;
                return 0;
            }

            $response .= $chunk;
            return strlen($chunk);
        }
    ]);

    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    // Exécution sans aucune relance automatique.
    $ok = curl_exec($curl);

    $status = (int) curl_getinfo(
        $curl,
        CURLINFO_RESPONSE_CODE
    );

    $curlError = $ok === false
        ? curl_error($curl)
        : '';

    curl_close($curl);

    // Gestion des réponses dépassant la limite.
    if ($responseLimitExceeded) {
        error_log(
            '[Musicband Google] Réponse trop volumineuse : '
            . $service
        );

        throw new RuntimeException(
            $service . ' : réponse supérieure à la limite autorisée.'
        );
    }

    // Analyse des erreurs réseau et HTTP.
    if ($ok === false || $status < 200 || $status >= 300) {

        $googleCode = '';
        $googleStatus = '';
        $googleMessage = '';

        // Extraire les informations structurées de Google.
        $errorData = json_decode($response, true);

        if (is_array($errorData)) {
            $error = $errorData['error'] ?? [];

            if (is_array($error)) {
                $googleCode = (string)($error['code'] ?? '');
                $googleStatus = (string)($error['status'] ?? '');
                $googleMessage = (string)($error['message'] ?? '');
            } elseif (is_string($error)) {
                // Certaines erreurs OAuth utilisent une chaîne.
                $googleStatus = $error;
                $googleMessage = (string)(
                    $errorData['error_description'] ?? ''
                );
            }
        }

        // Limiter la taille des messages de diagnostic.
        $googleMessage = substr($googleMessage, 0, 500);

        // Construire un diagnostic exploitable.
        $diagnostic = $service . ' HTTP ' . $status;

        if ($googleCode !== '') {
            $diagnostic .= ' | Code : ' . $googleCode;
        }

        if ($googleStatus !== '') {
            $diagnostic .= ' | Statut : ' . $googleStatus;
        }

        if ($googleMessage !== '') {
            $diagnostic .= ' | Message : ' . $googleMessage;
        }

        if ($curlError !== '') {
            // Ne pas journaliser l'URL ni les en-têtes.
            $diagnostic .= ' | Erreur réseau cURL';
        }

        // Journal serveur : pas de secret ni de token.
        // Le détail Google peut contenir des métadonnées :
        // conserver les logs dans un emplacement privé.
        error_log('[Musicband Google] ' . $diagnostic);

        // Message exploitable côté administration.
        throw new RuntimeException(
            $diagnostic
            . '. Aucune relance automatique. '
            . 'Vérifier les autorisations et le journal.'
        );
    }

    return $response;
}

function documentsGoogleToken(): string {
    $values=documentsGoogleCredentials();
    foreach ($values as $value) if (!is_string($value) || trim($value)==='') throw new RuntimeException('Configurer les trois accès OAuth dans Configuration → Accès Google Drive ou dans l’environnement privé du serveur.');
    $values['grant_type']='refresh_token';
    $response=json_decode(documentsGoogleRequest('https://oauth2.googleapis.com/token','POST',http_build_query($values),['Content-Type: application/x-www-form-urlencoded']),true,16,JSON_THROW_ON_ERROR);
    $token=$response['access_token']??null;
    if (!is_string($token) || !preg_match('/^[A-Za-z0-9._~+\/-]+$/D',$token)) throw new RuntimeException('Jeton Google indisponible.');
    return $token;
}
function documentsGoogleJson(string $token,string $url,?array $body=null): array {
    return json_decode(documentsGoogleRequest($url,$body===null?'GET':'POST',$body===null?null:businessJson($body),['Authorization: Bearer '.$token,'Content-Type: application/json']),true,32,JSON_THROW_ON_ERROR);
}
function documentsPdfCheck(string $pdf): string {
    if (!str_starts_with($pdf,'%PDF-') || strlen($pdf)>DOCUMENTS_PDF_MAX_BYTES) throw new RuntimeException('PDF invalide ou supérieur à 8 Mo.'); return $pdf;
}
function documentsMailSubject(string $subject): string {
    $chunks=[]; $chunk='';
    foreach (preg_split('//u',$subject,-1,PREG_SPLIT_NO_EMPTY) as $char) {
        if (strlen($chunk.$char)>42) { $chunks[]='=?UTF-8?B?'.base64_encode($chunk).'?='; $chunk=''; }
        $chunk.=$char;
    }
    if ($chunk!=='') $chunks[]='=?UTF-8?B?'.base64_encode($chunk).'?=';
    return implode(' ',$chunks);
}
function documentsPdfRead(array $journal,?string $token=null): string {
    if ($journal['generation_status']!=='complete' || !is_string($journal['pdf_sha256'])) throw new RuntimeException('Aucun PDF confirmé.');
    $id=documentsDriveId($journal['pdf_file_id']); $token??=documentsGoogleToken();
    $pdf=documentsPdfCheck(documentsGoogleRequest('https://www.googleapis.com/drive/v3/files/'.$id.'?alt=media&supportsAllDrives=true','GET',null,['Authorization: Bearer '.$token],DOCUMENTS_PDF_MAX_BYTES));
    if (!hash_equals($journal['pdf_sha256'],hash('sha256',$pdf))) throw new RuntimeException('Le PDF Drive a changé : téléchargement ou envoi refusé.');
    return $pdf;
}
function documentsDeliveryTransition(PDO $db,array $row,string $state,array $user): void {
    $before=businessValues($row,businessConfig('devis_factures'),'devis_factures'); $after=$before; $after['statut']=$state;
    $q=$db->prepare('UPDATE devis_factures_documents SET statut=? WHERE id=?'); $q->execute([$state,$row['id']]);
    $q=$db->prepare('INSERT INTO devis_factures_revisions (record_id,edited_by,edited_at,before_json,after_json) VALUES (?,?,UTC_TIMESTAMP(6),?,?)'); $q->execute([$row['id'],$user['login'],businessJson($before),businessJson($after)]);
}
function documentsDeliveryVariables(array $row): array {
    $variables=[];
    foreach (['numero','type_document','date_document','client','adresse','date_prestation','duree','montant','reglement','commentaires'] as $key) $variables['{{'.$key.'}}']=businessDisplayValue('devis_factures',$key,$row[$key]??'',$row);
    return $variables;
}
function documentsTemplateText(array $node): string {
    if (isset($node['textRun']['content']) && is_string($node['textRun']['content'])) return $node['textRun']['content'];
    $text=''; foreach ($node as $child) if (is_array($child)) $text.=documentsTemplateText($child); return $text;
}
function documentsGenerate(PDO $db,array $row,array $user): void {
    if ($row['statut']!=='Validé' || !in_array($row['type_document'],['Devis','Facture'],true) || documentsDeliveryJournal($db,(int)$row['id'],true)) throw new RuntimeException('Génération réservée à un devis ou une facture validé sans tentative antérieure.');
    $settings=documentsPdfSettings(); $folder=documentsDriveId($settings['folder']); $template=documentsDriveId($settings[$row['type_document']]??null);
    // Les lectures de contrôle précèdent la réservation ; aucun effet externe ici.
    $token=documentsGoogleToken();
    $variables=documentsDeliveryVariables($row);
    foreach ($variables as $value) if (str_contains($value,'{{') || str_contains($value,'}}')) throw new RuntimeException('Une valeur du document contient une balise réservée {{…}} : corriger explicitement avant génération.');
    $meta=documentsGoogleJson($token,'https://www.googleapis.com/drive/v3/files/'.$template.'?fields=id,mimeType,trashed,capabilities(canCopy)&supportsAllDrives=true');
    $target=documentsGoogleJson($token,'https://www.googleapis.com/drive/v3/files/'.$folder.'?fields=id,mimeType,trashed,capabilities(canAddChildren)&supportsAllDrives=true');
    if (($meta['mimeType']??'')!=='application/vnd.google-apps.document' || !empty($meta['trashed']) || empty($meta['capabilities']['canCopy']) || ($target['mimeType']??'')!=='application/vnd.google-apps.folder' || !empty($target['trashed']) || empty($target['capabilities']['canAddChildren'])) throw new RuntimeException('Modèle Google Docs copiable et dossier accessible en écriture requis.');
    $required=['{{numero}}','{{date_document}}','{{client}}','{{adresse}}','{{date_prestation}}','{{duree}}','{{montant}}','{{reglement}}'];
    $templateText=documentsTemplateText(documentsGoogleJson($token,'https://docs.googleapis.com/v1/documents/'.$template.'?includeTabsContent=true'));
    foreach ($required as $tag) if (!str_contains($templateText,$tag)) throw new RuntimeException('Modèle incomplet : balise '. $tag .' absente. Aucune génération commencée.');
    preg_match_all('/\{\{[^{}]+\}\}/u',$templateText,$tags);
    foreach ($tags[0] as $tag) if (!array_key_exists($tag,$variables)) throw new RuntimeException('Balise inconnue dans le modèle : '.$tag.'. Aucune génération commencée.');
    $snapshot=['document'=>businessValues($row,businessConfig('devis_factures'),'devis_factures'),'settings'=>$settings];
    $q=$db->prepare("INSERT INTO devis_factures_deliveries(document_id,generation_status,snapshot_json,generated_by,generation_requested_at) VALUES (?,'reserved',?,?,UTC_TIMESTAMP(6))"); $q->execute([$row['id'],businessJson($snapshot),$user['login']]); $db->commit();
    try {
        $copy=documentsGoogleJson($token,'https://www.googleapis.com/drive/v3/files/'.$template.'/copy?supportsAllDrives=true&fields=id',['name'=>$row['numero'].' · source génération','parents'=>[$folder],'appProperties'=>['Musicband_document'=>(string)$row['id']]]);
        $copyId=documentsDriveId($copy['id']??null); $q=$db->prepare('UPDATE devis_factures_deliveries SET template_copy_id=? WHERE document_id=?'); $q->execute([$copyId,$row['id']]);
        $requests=[]; $variables=documentsDeliveryVariables($row);
        foreach ($variables as $tag=>$value) $requests[]=['replaceAllText'=>['containsText'=>['text'=>$tag,'matchCase'=>true],'replaceText'=>$value]];
        $replaced=documentsGoogleJson($token,'https://docs.googleapis.com/v1/documents/'.$copyId.':batchUpdate',['requests'=>$requests]);
        foreach ($required as $tag) { $index=array_search($tag,array_keys($variables),true); if (($replaced['replies'][$index]['replaceAllText']['occurrencesChanged']??0)<1) throw new RuntimeException('Balise obligatoire non remplacée. Copie conservée ; génération bloquée pour inspection.'); }
        $pdf=documentsPdfCheck(documentsGoogleRequest('https://www.googleapis.com/drive/v3/files/'.$copyId.'/export?mimeType=application%2Fpdf','GET',null,['Authorization: Bearer '.$token],DOCUMENTS_PDF_MAX_BYTES));
        $boundary='Musicband_'.bin2hex(random_bytes(18));
        $body='--'.$boundary."\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".businessJson(['name'=>$row['numero'].'.pdf','parents'=>[$folder],'appProperties'=>['Musicband_document'=>(string)$row['id']]])."\r\n--".$boundary."\r\nContent-Type: application/pdf\r\n\r\n".$pdf."\r\n--".$boundary."--\r\n";
        $file=json_decode(documentsGoogleRequest('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id','POST',$body,['Authorization: Bearer '.$token,'Content-Type: multipart/related; boundary='.$boundary]),true,16,JSON_THROW_ON_ERROR);
        $pdfId=documentsDriveId($file['id']??null); $sha=hash('sha256',$pdf);
        $q=$db->prepare('UPDATE devis_factures_deliveries SET pdf_file_id=?,pdf_sha256=? WHERE document_id=?'); $q->execute([$pdfId,$sha,$row['id']]);
        $confirmed=documentsPdfCheck(documentsGoogleRequest('https://www.googleapis.com/drive/v3/files/'.$pdfId.'?alt=media&supportsAllDrives=true','GET',null,['Authorization: Bearer '.$token],DOCUMENTS_PDF_MAX_BYTES));
        if (!hash_equals($sha,hash('sha256',$confirmed))) throw new RuntimeException('Stockage du PDF non confirmé.');
        $db->beginTransaction(); $q=$db->prepare("UPDATE devis_factures_deliveries SET generation_status='complete',generated_at=UTC_TIMESTAMP(6) WHERE document_id=?"); $q->execute([$row['id']]);
        documentsDeliveryTransition($db,$row,'Généré',$user); $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        try { $q=$db->prepare("UPDATE devis_factures_deliveries SET generation_status='uncertain' WHERE document_id=? AND generation_status='reserved'"); $q->execute([$row['id']]); } catch (Throwable $ignored) { error_log('Musicband_pdf_journal_uncertain'); }
        throw new RuntimeException('Génération non confirmée. Les éventuels fichiers et le journal sont conservés ; aucune nouvelle tentative autorisée. Vérifier Drive et la configuration.');
    }
}
function documentsMailData(PDO $db,array $row,array $journal): array {
    $q=$db->prepare('SELECT mail FROM contacts_carnet_adresse WHERE id=?'); $q->execute([$row['contact_id']]); $to=MusicbandEmail($q->fetchColumn());
    if ($to==='' || preg_match('/["\\\\;,:<>]/',$to)) throw new RuntimeException('Renseigner une adresse e-mail simple et valide sur le contact facturé.');
    $settings=documentsMailSettings(); $cc=concertsCalendarEmails(implode("\n",$settings['cc'])); $cc=array_values(array_diff($cc,[$to])); $from=MusicbandMailFrom();
    if (preg_match('/["\\\\;,:<>]/',$from)) throw new RuntimeException('Adresse expéditrice simple requise.');
    $variables=[]; foreach (['numero','type_document','client','date_prestation','montant'] as $key) $variables['{'.$key.'}']=businessDisplayValue('devis_factures',$key,$row[$key]??'',$row);
    $subject=strtr($settings['subject'],$variables); $body=strtr($settings['body'],$variables);
    return ['to'=>$to,'cc'=>$cc,'from'=>$from,'subject'=>$subject,'body'=>$body,'version'=>hash('sha256',businessJson([businessVersion($row,businessConfig('devis_factures'),'devis_factures'),$journal['pdf_sha256'],$to,$cc,$from,$settings,fieldVisibilityVersion('devis_factures')]))];
}
function documentsSend(PDO $db,array $row,array $user): void {
    $journal=documentsDeliveryJournal($db,(int)$row['id'],true);
    if ($row['statut']!=='Généré' || !$journal || $journal['generation_status']!=='complete' || $journal['mail_status']!==null) throw new RuntimeException('PDF confirmé requis, sans tentative de transmission antérieure.');
    $data=documentsMailData($db,$row,$journal);
    if (!is_string($_POST['mail_version']??null) || !hash_equals($data['version'],$_POST['mail_version'])) throw new RuntimeException('Destinataires, PDF ou modèle modifiés : recharger la confirmation.');
    $subject=$_POST['mail_subject']??null; $body=$_POST['mail_body']??null;
    if (!is_string($subject) || trim($subject)==='' || strlen($subject)>500 || preg_match('/[\x00-\x1f\x7f]/',$subject) || !is_string($body) || trim($body)==='' || strlen($body)>6000 || str_contains($body,"\0")) throw new RuntimeException('Objet (500 octets, une ligne) et message (6000 octets) requis.');
    $pdf=documentsPdfRead($journal); $data['subject']=$subject; $data['body']=$body; unset($data['version']);
    // Identifiant durable pour rapprocher la tentative des journaux, sans journaliser le message.
    $mailReference='Musicband-doc-'.bin2hex(random_bytes(16));
    $data['message_id']='<'.$mailReference.'@'.substr(strrchr($data['from'],'@'),1).'>';
    $q=$db->prepare("UPDATE devis_factures_deliveries SET mail_status='reserved',mail_json=?,sent_by=?,mail_requested_at=UTC_TIMESTAMP(6) WHERE document_id=?"); $q->execute([businessJson($data),$user['login'],$row['id']]); $db->commit();
    $result='uncertain'; $started=microtime(true);
    error_log('Musicband_document_mail_start ref='.$mailReference);
    try {
        $filename='document-'.(int)$row['id'].'.pdf';
        $sendResult=MusicbandSendEmail([
            'to'=>$data['to'],
            'cc'=>$data['cc'],
            'from'=>$data['from'],
            'subject'=>$subject,
            'text'=>$body,
            'attachments'=>[
                [
                    'filename'=>$filename,
                    'content'=>$pdf,
                    'mime'=>'application/pdf',
                    'disposition'=>'attachment',
                ]
            ],
            'type'=>'document_delivery',
        ]);
        $result=($sendResult['status']==='accepted')?'accepted':'failed';
        if (!empty($sendResult['message_id'])) {
            $data['gmail_message_id']=$sendResult['message_id'];
        }
    } catch (Throwable $e) {
        $result='uncertain';
        error_log('Musicband_document_mail_error ref='.$mailReference.' msg='.$e->getMessage());
    }
    error_log('Musicband_document_mail_end ref='.$mailReference.' status='.$result.' duration_ms='.(int)round((microtime(true)-$started)*1000));
    try {
        $db->beginTransaction();
        $q=$db->prepare('UPDATE devis_factures_deliveries SET mail_status=?,mail_completed_at=UTC_TIMESTAMP(6),mail_json=? WHERE document_id=?');
        $q->execute([$result,businessJson($data),$row['id']]);
        if ($result==='accepted') documentsDeliveryTransition($db,$row,'Transmis au client',$user);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw new RuntimeException('Résultat de transmission non confirmé en base. Aucun renvoi autorisé ; vérifier le journal et le service mail.'); }
    if ($result!=='accepted') throw new RuntimeException('Transmission refusée ou incertaine. Tentative conservée et tout renvoi bloqué.');
}
function documentsDeliveryAction(array $user): int {
    MusicbandRequirePermission(MusicbandDocumentPermission((string)($_POST['action']??'')),$user);
    if (($_POST['delivery_confirm']??'')!=='yes') throw new RuntimeException('Confirmation explicite requise.');
    $db=MusicbandDatabase(businessSettings()); fieldVisibilityUse($db);
    if (!documentsDeliveryReady($db)) throw new RuntimeException('Créer ou vérifier le schéma 009 dans Upgrade.');
    $id=businessId($_POST['id']??''); $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT * FROM devis_factures_documents WHERE id=? FOR UPDATE'); $q->execute([$id]); $row=$q->fetch();
        if (!$row || !is_string($_POST['version']??null) || !hash_equals(businessVersion($row,businessConfig('devis_factures'),'devis_factures'),$_POST['version']) || !is_string($_POST['field_policy']??null) || !hash_equals(fieldVisibilityVersion('devis_factures'),$_POST['field_policy'])) throw new RuntimeException('Document ou réglages modifiés : recharger.');
        if (($_POST['action']??'')==='document_generate') {
            if (!is_string($_POST['pdf_version']??null) || !hash_equals(hash('sha256',businessJson(documentsPdfSettings())),$_POST['pdf_version'])) throw new RuntimeException('Dossier ou modèle modifié : recharger.');
            documentsGenerate($db,$row,$user);
        } elseif (($_POST['action']??'')==='document_send') documentsSend($db,$row,$user);
        else throw new RuntimeException('Action inconnue.');
        return $id;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function documentsDeliveryHidden(array $row): string {
    return '<input type="hidden" name="csrf" value="'.MusicbandEscape($_SESSION['csrf']).'"><input type="hidden" name="id" value="'.(int)$row['id'].'"><input type="hidden" name="version" value="'.businessVersion($row,businessConfig('devis_factures'),'devis_factures').'"><input type="hidden" name="field_policy" value="'.fieldVisibilityVersion('devis_factures').'">';
}

/**
 * Résout l'URL du dossier Google Drive parent d'origine du document.
 * Ne produit jamais d'URL inventée ; retourne null si le dossier ne peut être résolu.
 */
function documentsDriveFolderUrl(?array $journal): ?string {
    if (!$journal) {
        return null;
    }
    $folderId = null;
    if (!empty($journal['snapshot_json'])) {
        $snapshot = json_decode((string)$journal['snapshot_json'], true);
        if (is_array($snapshot) && !empty($snapshot['settings']['folder']) && is_string($snapshot['settings']['folder'])) {
            $folderId = trim($snapshot['settings']['folder']);
        }
    }
    if (!$folderId) {
        $settings = documentsPdfSettings();
        if (!empty($settings['folder']) && is_string($settings['folder'])) {
            $folderId = trim($settings['folder']);
        }
    }
    if ($folderId && preg_match('/^[A-Za-z0-9_-]{10,200}$/D', $folderId)) {
        return 'https://drive.google.com/drive/folders/' . rawurlencode($folderId);
    }
    return null;
}

/**
 * Rendu simplifié des actions du document généré : [Télécharger] [Ouvrir Drive].
 * Remplace l'ancien visualiseur PDF par deux actions réactives et directes.
 *
 * Dépendances : MusicbandBase, MusicbandEscape, documentsDriveFolderUrl, MusicbandExternalAppLink.
 */
function documentsDeliveryActionsRender(array $row, ?array $journal): string {
    $id = (int)$row['id'];
    $cleanNumero = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($row['numero'] ?? ''));
    $filename = ($cleanNumero !== '' ? $cleanNumero : ('document-' . $id)) . '.pdf';
    $downloadUrl = MusicbandBase() . 'documents_pdf.php?id=' . $id . '&download=1';
    $driveFolderUrl = documentsDriveFolderUrl($journal);

    $html = '<div class="document-pdf-actions" role="group" aria-label="Actions sur le document">';
    $html .= '<a class="business-action document-action-download" href="' . MusicbandEscape($downloadUrl) . '" download="' . MusicbandEscape($filename) . '" title="Télécharger le fichier PDF original (' . MusicbandEscape($filename) . ')">';
    $html .= '<span class="action-icon" aria-hidden="true">📥</span> <span>Télécharger</span>';
    $html .= '</a>';

    if ($driveFolderUrl !== null) {
        $html .= MusicbandExternalAppLink($driveFolderUrl, 'google-drive', [
            'label' => '📁 Ouvrir Drive',
            'class' => 'business-action document-action-drive',
            'title' => 'Ouvrir le dossier Google Drive original dans son application',
        ]);
    }
    $html .= '</div>';
    return $html;
}

/**
 * Rétrocompatibilité : redirige vers le rendu simplifié des actions documentaires.
 */
function documentsPdfViewerRender(array $row, ?array $journal, bool $standalone = false): string {
    return documentsDeliveryActionsRender($row, $journal);
}

/**
 * Rétrocompatibilité script : plus aucun script de barre d'outils complexe nécessaire.
 */
function documentsPdfOuterToolbarScript(): string {
    return '';
}

function documentsDeliveryPanel(PDO $db,array $row): string {
    if (!in_array($row['statut'],['Validé','Généré','Transmis au client','Envoyé'],true)) return '';
    try {
        if (!documentsDeliveryReady($db)) return '<p>Pour produire les PDF et transmettre les documents, créer le schéma 009 dans Upgrade et configurer PDF Google Drive dans Configuration.</p>';
        $journal=documentsDeliveryJournal($db,(int)$row['id']);
        if (!$journal && $row['statut']==='Validé') {
            if (!MusicbandCan('documents.generate')) return '<p>Document validé. Génération PDF réservée à un rôle autorisé.</p>';
            $settings=documentsPdfSettings(); documentsDriveId($settings['folder']); documentsDriveId($settings[$row['type_document']]??null);
            return '<form method="post">'.documentsDeliveryHidden($row).'<input type="hidden" name="action" value="document_generate"><input type="hidden" name="pdf_version" value="'.hash('sha256',businessJson($settings)).'"><p>La génération copie le modèle Google Docs, le complète et stocke le PDF dans Drive. Les modèles et liens existants sont conservés. Une tentative commencée ne peut pas être relancée, même en cas d’interruption.</p><p class="Musicband-wrap" >Dossier : '.MusicbandEscape($settings['folder']).'<br>Modèle '.MusicbandEscape($row['type_document']).' : '.MusicbandEscape($settings[$row['type_document']]).'</p><label><input type="checkbox" name="delivery_confirm" value="yes" required> Je confirme la génération et le dépôt Drive pour ce document validé.</label><button>Générer et stocker le PDF dans Drive</button></form>';
        }
        if (!$journal) return '<p>Document historique figé : aucun PDF ni envoi n’est revendiqué par le nouveau journal.</p>';
        if ($journal['generation_status']!=='complete') return '<p class="notice">Génération en cours ou non confirmée. Tentative du '.MusicbandEscape(MusicbandDisplayDate($journal['generation_requested_at'],true,true)).' par '.MusicbandEscape($journal['generated_by']).'. Aucune relance autorisée ; vérifier le journal et les éventuels fichiers Drive.</p>';
        $html='<p>PDF stocké et empreinte enregistrée.</p>' . documentsPdfViewerRender($row, $journal, false);
        if ($journal['mail_status']!==null) {
            $labels=['accepted'=>'Message accepté par le serveur d’envoi, mais livraison non confirmée. Si absent, vérifier le suivi des e-mails automatisés dans le Manager OVH et les éventuels retours à l’expéditeur.','failed'=>'Mail refusé par le service mail.','reserved'=>'Tentative de transmission en cours ou résultat non confirmé.','uncertain'=>'Résultat de transmission incertain.'];
            $html.='<p>'.($labels[$journal['mail_status']]??'Tentative enregistrée.').' Aucun nouvel envoi autorisé.</p><p>Demandé le '.MusicbandEscape(MusicbandDisplayDate($journal['mail_requested_at'],true,true)).' par '.MusicbandEscape($journal['sent_by']).'.</p>';
            $mail=json_decode($journal['mail_json'],true,16,JSON_THROW_ON_ERROR);
            $html.='<details><summary>Message demandé · trace conservée</summary><dl>';
            foreach (['message_id'=>'Identifiant du message (si disponible)','from'=>'De','to'=>'À','cc'=>'Cc','subject'=>'Objet','body'=>'Message'] as $key=>$label) $html.='<dt>'.$label.'</dt><dd>'.MusicbandEscape(is_array($mail[$key]??null)?implode(', ',$mail[$key]):(string)($mail[$key]??'')).'</dd>';
            return $html.'</dl></details>';
        }
        if ($row['statut']!=='Généré' || !MusicbandCan('documents.send')) return $html;
        $data=documentsMailData($db,$row,$journal);
        if (($_POST['action']??'')==='document_send' && (string)($_POST['id']??'')===(string)$row['id']) {
            foreach (['subject'=>500,'body'=>6000] as $key=>$maximum) if (is_string($_POST['mail_'.$key]??null) && strlen($_POST['mail_'.$key])<=$maximum) $data[$key]=$_POST['mail_'.$key];
        }
        $html.='<form method="post">'.documentsDeliveryHidden($row).'<input type="hidden" name="action" value="document_send"><input type="hidden" name="mail_version" value="'.$data['version'].'"><p class="Musicband-wrap" >De : '.MusicbandEscape($data['from']).'<br>À : '.MusicbandEscape($data['to']).'<br>Cc (visibles) : '.MusicbandEscape(implode(', ',$data['cc'])).'</p><label>Objet<input name="mail_subject" maxlength="500" required value="'.MusicbandEscape($data['subject']).'"></label><label>Message modifiable<textarea name="mail_body" maxlength="6000" required>'.MusicbandEscape($data['body']).'</textarea></label><p>Pièce jointe : le PDF stocké, vérifié par son empreinte avant envoi. Relire le PDF et ce message avant confirmation.</p><label><input type="checkbox" name="delivery_confirm" value="yes" required> Je confirme le PDF, le message, le destinataire et les copies ; une seule tentative d’envoi.</label><button>Confirmer et transmettre le PDF par e-mail</button></form>';
        return $html;
    } catch (Throwable $e) { return ($html??'').'<p class="notice">'.MusicbandEscape($e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'PDF/envoi indisponible : vérifier la configuration et le schéma 009.').'</p>'; }
}
function documentsDeliveryReadLink(PDO $db,array $row): string {
    try {
        if (!documentsDeliveryReady($db)) return '';
        $journal=documentsDeliveryJournal($db,(int)$row['id']);
        return $journal && $journal['generation_status']==='complete' ? documentsPdfViewerRender($row, $journal, false) : '';
    } catch (Throwable $e) { return '<p>PDF momentanément indisponible.</p>'; }
}
