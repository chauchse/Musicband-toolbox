<?php
/* Musicband CONCERT PUBLICATION: WordPress confirmé et journal par plateforme. */
/* Publication WordPress de concerts et suivi de chaque tentative par plateforme.
 * Utilise les helpers métier, la configuration privée, PDO et les requêtes HTTP du service.
 * Un envoi confirmé peut créer un contenu distant ; le journal local ne garantit pas à lui seul sa réception.
 * Community désactive configuration et envoi ; les tentatives historiques restent consultables sans écriture.
 */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) { http_response_code(404);exit; }
function concertsPublicationReady(PDO $db): bool {
    $q=$db->query("SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='concerts_publications'");
    $engine=$q->fetchColumn();if($engine===false)return false;
    if(strcasecmp((string)$engine,'InnoDB')!==0)throw new RuntimeException('Journal 013 : moteur InnoDB requis.');
    $columns=$db->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='concerts_publications'")->fetchAll(PDO::FETCH_COLUMN);
    if(array_diff(['concert_id','platform','status','snapshot_json','requested_by','requested_at','completed_at','venue_id','event_id','http_status'],$columns))throw new RuntimeException('Schéma 013 incomplet : inspection manuelle requise.');
    $primary=$db->query("SELECT COLUMN_NAME FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='concerts_publications' AND index_name='PRIMARY' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
    if($primary!==['concert_id','platform'])throw new RuntimeException('Clé du journal 013 invalide : refuser toute publication.');
    foreach(documentsForeignKeys($db) as $fk)if($fk['TABLE_NAME']==='concerts_publications'&&$fk['COLUMN_NAME']==='concert_id'&&$fk['REFERENCED_TABLE_NAME']==='concerts_evenements'&&$fk['REFERENCED_COLUMN_NAME']==='id'&&$fk['DELETE_RULE']==='RESTRICT'&&$fk['UPDATE_RULE']==='RESTRICT')return true;
    throw new RuntimeException('Contrainte Concerts du journal 013 absente ou incompatible.');
}
function concertsPublicationSchema(array $settings): string {
    if (!MusicbandPermissionEnabled('concerts.publish.wordpress')) throw new RuntimeException('Publication WordPress indisponible dans cette édition.');
    if(!MusicbandRequireLogin()['admin'])throw new RuntimeException('Accès administrateur requis.');
    if(($_POST['confirm']??'')!=='yes'||($_POST['confirm_database']??'')!==($settings['DB_NAME']??null))throw new RuntimeException('Confirmer sauvegarde et base exacte.');
    $db=MusicbandDatabase($settings);
    if(!MusicbandRbacComplete()||!documentsTableExists($db,'concerts_evenements')||!documentsTableExists($db,'Musicbandai_rbac_permissions'))throw new RuntimeException('Préparer les schémas Concerts 003 et RBAC 010 avant 013.');
    $sql=base64_decode('',true);
    if(!$sql||str_contains($sql,'Musicband_'))throw new RuntimeException('Schéma 013 non embarqué : préparer le site.');
    if(!concertsPublicationReady($db))foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$db->exec($statement);
    if(!concertsPublicationReady($db))throw new RuntimeException('Vérifier le schéma 013 avant reprise.');
    $db->beginTransaction();
    try{
        $q=$db->prepare('INSERT IGNORE INTO Musicbandai_rbac_permissions(role_key,permission_key,allowed) VALUES (?,?,?)');
        foreach(MusicbandRoles() as $role=>$_)$q->execute([$role,'concerts.publish.wordpress',(int)($role==='admin')]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return 'Journal 013 et droit WordPress préparés. Aucun concert publié ; droits existants conservés.';
}
function concertsPublicationUpgradeForm(string $hidden): string {
    if (!MusicbandPermissionEnabled('concerts.publish.wordpress')) return '';
    return '<section><h3>Publication des concerts · schéma 013</h3><p>Journal par concert et plateforme ; WordPress uniquement pour cette étape. Prérequis : schémas Concerts 003 et RBAC 010. Sauvegarder base et configuration privée. Conserver ce journal lors des mises à jour et retours arrière.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="publication_schema"><label>Nom exact de la base<input name="confirm_database" required></label><label><input type="checkbox" name="confirm" value="yes" required> Sauvegarde et destination vérifiées.</label><button>Préparer 013 · Publications des concerts</button></form></section>';
}
function concertsWordPressSettings(): array {
    return array_replace(['url'=>'','username'=>'','password'=>'','category'=>'4','revision'=>''],MusicbandRead('home_settings')['concert_wordpress']??[]);
}
function concertsWordPressUrl(string $url): string {
    $url=rtrim(trim($url),'/');$parts=parse_url($url);
    if(!$parts||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||(isset($parts['port'])&&$parts['port']!==443))throw new RuntimeException('URL WordPress HTTPS requise, sans identifiants, paramètres ni port spécifique.');
    $host=$parts['host']??'';$path=$parts['path']??'';
    if(!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/Di',$host)||strlen($host)>253||!preg_match('~^(?:/[a-z0-9_-]+)*$~Di',$path))throw new RuntimeException('Domaine public et chemin WordPress simple requis.');
    return 'https://'.strtolower($host).$path;
}
function concertsWordPressSave(): string {
    if (!MusicbandPermissionEnabled('concerts.publish.wordpress')) throw new RuntimeException('Publication WordPress indisponible dans cette édition.');
    if(!MusicbandRequireLogin()['admin'])throw new RuntimeException('Accès administrateur requis.');
    $old=concertsWordPressSettings();
    if(!is_string($_POST['wp_revision']??null)||!hash_equals($old['revision'],$_POST['wp_revision']))throw new RuntimeException('Réglages WordPress modifiés : recharger Configuration.');
    $data=[];
    foreach(['url','username','password','category'] as $key){$value=$_POST['wp_'.$key]??null;if(!is_string($value)||strlen($value)>2048)throw new RuntimeException('Réglage WordPress invalide.');$data[$key]=trim($value);}
    $data['url']=$data['url']===''?'':concertsWordPressUrl($data['url']);
    if($data['username']===''||preg_match('/[:\x00-\x20\x7f]/',$data['username']))throw new RuntimeException('Identifiant WordPress requis, sans espace ni deux-points.');
    $data['password']=str_replace(' ','',$data['password']);
    if($data['password']==='')$data['password']=$old['password'];
    if($data['password']===''||preg_match('/[^\x21-\x7e]/',$data['password']))throw new RuntimeException('Nouveau mot de passe d’application WordPress requis (ASCII).');
    if(!preg_match('/^[1-9][0-9]{0,8}$/D',$data['category']))throw new RuntimeException('Identifiant numérique de catégorie requis.');
    $data['revision']=bin2hex(random_bytes(16));$settings=MusicbandRead('home_settings');$settings['concert_wordpress']=$data;MusicbandWrite('home_settings',$settings);
    return 'Réglages WordPress privés enregistrés. Aucun appel externe. Une URL vide désactive les nouvelles publications.';
}
function concertsWordPressForm(string $hidden): string {
    if (!MusicbandPermissionEnabled('concerts.publish.wordpress')) return '';
    $s=concertsWordPressSettings();$html='<section><h2>Publication WordPress · calendrier</h2><p>Configurer The Events Calendar avec un compte dédié autorisé à publier événements et lieux. Le mot de passe d’application reste dans le stockage privé existant, sans réaffichage. Laisser le secret vide pour le conserver ; vider l’URL désactive les nouvelles publications. Enregistrer ne contacte pas WordPress.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="wordpress_settings"><input type="hidden" name="wp_revision" value="'.MusicbandEscape($s['revision']).'">';
    foreach(['url'=>'URL HTTPS du site WordPress','username'=>'Identifiant WordPress','category'=>'ID de catégorie des événements'] as $key=>$label)$html.='<label>'.$label.'<input name="wp_'.$key.'" maxlength="2048" value="'.MusicbandEscape($s[$key]).'"'.($key==='url'?' type="url"':'').' autocomplete="off"></label>';
    return $html.'<label>Mot de passe d’application · '.($s['password']===''?'à renseigner':'présent').'<input type="password" name="wp_password" maxlength="2048" autocomplete="new-password" placeholder="Laisser vide pour conserver"></label><button>Enregistrer WordPress</button></form></section>';
}
function concertsPublicationSnapshot(array $row): array {
    foreach(['evenement','date_concert','adresse','heure_prestation','duree'] as $key)if(!fieldVisibilityVisible('concerts',$key))throw new RuntimeException('Afficher les champs publics requis avant publication.');
    $policy=businessListSettings();$zone=new DateTimeZone($policy['timezone']);
    $date=businessConcertDate($row['date_concert']??null,$policy,businessDateImported($row));
    $time=businessTemporalValue('heure_prestation',(string)($row['heure_prestation']??''));$duration=trim((string)($row['duree']??''));
    if(!$date||$date<(new DateTimeImmutable('today',$zone))->format('Y-m-d')||!$time||!preg_match('/^[0-9]{1,4}$/D',$duration)||(int)$duration<1||(int)$duration>1440)throw new RuntimeException('Concert à venir, heure de prestation valide et durée de 1 à 1440 minutes requis.');
    $title=trim((string)($row['evenement']??''));$address=trim((string)($row['adresse']??''));
    if($title===''||strlen($title)>500||$address===''||strlen($address)>2000)throw new RuntimeException('Événement (500 octets maximum) et adresse (2000 octets maximum) requis.');
    $start=concertsCalendarLocal($date,$time,$zone);$end=$start->setTimestamp($start->getTimestamp()+(int)$duration*60);
    concertsCalendarLocal($end->format('Y-m-d'),$end->format('H:i:s'),$zone);
    $s=concertsWordPressSettings();
    if($s['url']===''||$s['username']===''||$s['password']==='')throw new RuntimeException('Configurer WordPress dans Configuration → Concerts.');
    return ['concert_id'=>(int)$row['id'],'platform'=>'wordpress','title'=>$title,'address'=>$address,'start'=>$start->format(DateTimeInterface::ATOM),'end'=>$end->format(DateTimeInterface::ATOM),'timezone'=>$policy['timezone'],'minutes'=>(int)$duration,'url'=>concertsWordPressUrl($s['url']),'username'=>$s['username'],'category'=>(int)$s['category'],'settings_revision'=>$s['revision'],'field_policy'=>fieldVisibilityVersion('concerts')];
}
function concertsPublicationVersion(array $data): string { return hash('sha256',businessJson($data)); }
function concertsPublicationRow(PDO $db,int $id): ?array {
    $q=$db->prepare("SELECT * FROM concerts_publications WHERE concert_id=? AND platform='wordpress'");$q->execute([$id]);return $q->fetch()?:null;
}
// Le transport est borné et épinglé sur une IPv4 publique ; aucune redirection ni proxy.
function concertsWordPressIp(string $url): string {
    $host=parse_url($url,PHP_URL_HOST);$ips=gethostbynamel($host);
    if(!$ips)throw new RuntimeException('Résolution IPv4 publique WordPress indisponible.');
    foreach($ips as $ip){
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('Destination WordPress non publique refusée.');
        $n=(int)sprintf('%u',ip2long($ip));
        if($n>=3758096384||($n>=1681915904&&$n<=1686110207))throw new RuntimeException('Destination WordPress réservée refusée.');
    }
    return $ips[0];
}
function concertsWordPressRequest(array $settings,string $ip,string $endpoint,array $payload,?int &$http): array {
    if (!MusicbandPermissionEnabled('concerts.publish.wordpress')) throw new RuntimeException('Publication WordPress indisponible dans cette édition.');
    $http=null;
    $url=$settings['url'].'/wp-json/tribe/events/v1/'.$endpoint;$host=parse_url($settings['url'],PHP_URL_HOST);$response='';
    $curl=curl_init($url);if($curl===false)throw new RuntimeException('Transport WordPress indisponible.');
    try{
        $configured=curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>businessJson($payload),CURLOPT_HTTPHEADER=>['Authorization: Basic '.base64_encode($settings['username'].':'.$settings['password']),'Content-Type: application/json','Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_WRITEFUNCTION=>function($ch,string $chunk)use(&$response){if(strlen($response)+strlen($chunk)>1048576)return 0;$response.=$chunk;return strlen($chunk);}]);
        if(!$configured)throw new RuntimeException('Transport WordPress non configuré.');
        $ok=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
        if($ok===false||!in_array($http,[200,201],true))throw new RuntimeException('Publication WordPress non confirmée.');
        $result=json_decode($response,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($result)||!is_int($result['id']??null)||$result['id']<1)throw new RuntimeException('Identifiant WordPress absent de la réponse.');
        return $result;
    }finally{curl_close($curl);}
}
function concertsPublicationSend(array $user): int {
    MusicbandRequirePermission('concerts.publish.wordpress',$user);
    if(($_POST['publication_confirm']??'')!=='yes'||($_POST['publication_new']??'')!=='yes')throw new RuntimeException('Confirmer la publication publique WordPress.');
    if(!function_exists('curl_init'))throw new RuntimeException('Extension PHP cURL requise sur l’hébergement.');
    $id=businessId($_POST['id']??'',false);$db=MusicbandDatabase(businessSettings());
    if(!concertsPublicationReady($db))throw new RuntimeException('Préparer le schéma 013 dans Upgrade.');
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT * FROM concerts_evenements WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch();
        if(!$row)throw new RuntimeException('Concert introuvable.');
        if(businessConcertIsPast($row))throw new RuntimeException('La publication d’un concert passé est désactivée.');
        fieldVisibilityUse($db);
        if(concertsPublicationRow($db,$id))throw new RuntimeException('Une tentative WordPress existe déjà. Aucun nouvel envoi autorisé.');
        $data=concertsPublicationSnapshot($row);
        if(!is_string($_POST['publication_version']??null)||!hash_equals(concertsPublicationVersion($data),$_POST['publication_version']))throw new RuntimeException('Concert ou configuration modifiés : recharger l’aperçu.');
        $venueId=$_POST['wp_venue_id']??'';
        if(!is_string($venueId)||($venueId!==''&&!preg_match('/^[1-9][0-9]{0,8}$/D',$venueId)))throw new RuntimeException('ID de lieu WordPress invalide.');
        $venue=['venue'=>$data['address'],'status'=>'publish','show_map'=>'true','show_map_link'=>'true'];
        foreach(['address','city','zip','country'] as $key){$value=$_POST['venue_'.$key]??'';if(!is_string($value)||strlen($value)>2000||str_contains($value,"\0")||!preg_match('//u',$value))throw new RuntimeException('Adresse structurée invalide.');$venue[$key]=trim($value);}
        if($venueId===''&&$venue['address']==='')throw new RuntimeException('Adresse du lieu requise.');
        $data['venue']=$venue;$data['venue_id']=$venueId===''?null:(int)$venueId;
        $settings=concertsWordPressSettings();$ip=concertsWordPressIp($data['url']);
        $q=$db->prepare("INSERT INTO concerts_publications(concert_id,platform,status,snapshot_json,requested_by,requested_at,venue_id) VALUES (?,'wordpress','reserved',?,?,UTC_TIMESTAMP(6),?)");$q->execute([$id,businessJson($data),$user['login'],$data['venue_id']]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    $http=null;
    try{
        $venueId=$data['venue_id'];
        if($venueId===null){
            $result=concertsWordPressRequest($settings,$ip,'venues',$data['venue'],$http);$venueId=$result['id'];
            $q=$db->prepare("UPDATE concerts_publications SET venue_id=?,http_status=? WHERE concert_id=? AND platform='wordpress'");$q->execute([$venueId,$http,$id]);
            if(($result['status']??'')!=='publish')throw new RuntimeException('Lieu WordPress non public.');
        }
        $payload=['title'=>$data['title'],'status'=>'publish','start_date'=>(new DateTimeImmutable($data['start']))->format('Y-m-d H:i:s'),'end_date'=>(new DateTimeImmutable($data['end']))->format('Y-m-d H:i:s'),'timezone'=>$data['timezone'],'venue'=>$venueId,'categories'=>[$data['category']]];
        $result=concertsWordPressRequest($settings,$ip,'events',$payload,$http);
        $q=$db->prepare("UPDATE concerts_publications SET event_id=?,http_status=? WHERE concert_id=? AND platform='wordpress'");$q->execute([$result['id'],$http,$id]);
        if(($result['status']??'')!=='publish'||($result['start_date']??'')!==$payload['start_date']||($result['end_date']??'')!==$payload['end_date']||($result['timezone']??'')!==$data['timezone'])throw new RuntimeException('État public ou horaires WordPress non confirmés.');
        $q=$db->prepare("UPDATE concerts_publications SET status='published',completed_at=UTC_TIMESTAMP(6) WHERE concert_id=? AND platform='wordpress'");$q->execute([$id]);
    }catch(Throwable $e){
        try{$q=$db->prepare("UPDATE concerts_publications SET status='uncertain',http_status=?,completed_at=UTC_TIMESTAMP(6) WHERE concert_id=? AND platform='wordpress'");$q->execute([$http,$id]);}catch(Throwable $ignored){}
        throw new RuntimeException('Publication non confirmée : vérifier le journal et WordPress. Un lieu ou un événement peut avoir été créé. Aucune relance autorisée.');
    }
    return $id;
}
function concertsPublicationPanel(PDO $db,array $row): string {
    $publicationEnabled = MusicbandPermissionEnabled('concerts.publish.wordpress');
    $html='<details class="business-instructions"'.(isset($_GET['publication'])||($_POST['action']??'')==='publication_send'?' open':'').'><summary>'.($publicationEnabled?'Publication du concert · WordPress':'Historique des publications').'</summary>';
    try{
        if(!concertsPublicationReady($db))return !$publicationEnabled?'':$html.'<p>Préparer le schéma 013 dans Upgrade.</p></details>';
        $journal=concertsPublicationRow($db,(int)$row['id']);
        if($journal){
            $labels=['published'=>'Publication confirmée par WordPress.','reserved'=>'Tentative réservée ; résultat à vérifier.','uncertain'=>'Publication incertaine ; vérifier WordPress.'];
            $data=json_decode($journal['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
            $html.='<p role="status">'.($labels[$journal['status']]??'Tentative enregistrée.').' Aucun nouvel envoi autorisé.</p><p>'.MusicbandEscape(MusicbandDisplayDate($journal['requested_at'],true,true).' · '.$journal['requested_by']).'</p><p>ID événement : '.MusicbandEscape((string)($journal['event_id']??'—')).' · ID lieu : '.MusicbandEscape((string)($journal['venue_id']??'—')).' · HTTP : '.MusicbandEscape((string)($journal['http_status']??'—')).'</p>';
            foreach(['title'=>'Événement confirmé','address'=>'Adresse confirmée','url'=>'Site cible','start'=>'Début','end'=>'Fin'] as $key=>$label){
                $visible=$key==='url'||($key==='title'&&fieldVisibilityVisible('concerts','evenement'))||($key==='address'&&fieldVisibilityVisible('concerts','adresse'))||(in_array($key,['start','end'],true)&&fieldVisibilityVisible('concerts','date_concert')&&fieldVisibilityVisible('concerts','heure_prestation')&&fieldVisibilityVisible('concerts','duree'));
                if($visible)$html.='<p>'.MusicbandEscape($label).' : '.MusicbandEscape(in_array($key,['start','end'],true)?MusicbandDisplayDate($data[$key],true,false,true):$data[$key]).'</p>';
            }
            if(fieldVisibilityVisible('concerts','adresse')){
                $html.='<details><summary>Lieu confirmé · instantané</summary><dl>';
                foreach(['address'=>'Adresse du lieu','zip'=>'Code postal','city'=>'Ville','country'=>'Pays'] as $key=>$label)$html.='<dt>'.$label.'</dt><dd>'.MusicbandEscape((string)($data['venue'][$key]??'')).'</dd>';
                $html.='</dl></details>';
            }
            return $html.'</details>';
        }
        // Conserver le journal existant, sans proposer une préparation ou un nouvel envoi.
        if (!$publicationEnabled) return '';
        if(businessConcertIsPast($row))return $html.'<p>La publication d’un concert passé est désactivée.</p></details>';
        $data=concertsPublicationSnapshot($row);
        if(!isset($_GET['publication'])&&($_POST['action']??'')!=='publication_send')return $html.'<p><a class="business-action" href="'.MusicbandEscape(MusicbandBase()).'concerts.php?id='.(int)$row['id'].'&amp;publication=1">Préparer la publication WordPress…</a></p></details>';
        $html.='<p>Publication publique immédiate après confirmation. Une seule tentative par concert et plateforme ; aucune mise à jour, suppression ni relance automatique. Les données internes et commentaires ne sont pas transmis.</p><dl>';
        foreach(['title'=>'Titre','address'=>'Adresse complète','start'=>'Début · prestation','end'=>'Fin · prestation + durée','url'=>'Site cible','category'=>'Catégorie WordPress (ID)'] as $key=>$label)$html.='<dt>'.$label.'</dt><dd>'.MusicbandEscape(in_array($key,['start','end'],true)?MusicbandDisplayDate($data[$key],true,false,true):(string)$data[$key]).'</dd>';
        $parts=array_map('trim',explode(',',$data['address']));$street=$data['address'];$zip='';$city='';$country='';
        if(count($parts)===3&&preg_match('/^([0-9]{4,6})\s+(.+)$/u',$parts[1],$match)){$street=$parts[0];$zip=$match[1];$city=$match[2];$country=$parts[2];}
        $venueIdValue=is_string($_POST['wp_venue_id']??null)?$_POST['wp_venue_id']:'';
        $venueValues=['address'=>$street,'zip'=>$zip,'city'=>$city,'country'=>$country];
        if(($_POST['action']??'')==='publication_send')foreach($venueValues as $key=>$value)if(is_string($_POST['venue_'.$key]??null))$venueValues[$key]=$_POST['venue_'.$key];
        $html.='</dl><form method="post" action="'.MusicbandEscape(MusicbandBase()).'concerts.php"><input type="hidden" name="csrf" value="'.MusicbandEscape($_SESSION['csrf']).'"><input type="hidden" name="action" value="publication_send"><input type="hidden" name="id" value="'.(int)$row['id'].'"><input type="hidden" name="publication_version" value="'.concertsPublicationVersion($data).'"><label>ID d’un lieu WordPress existant (facultatif)<input name="wp_venue_id" value="'.MusicbandEscape($venueIdValue).'" inputmode="numeric" pattern="[1-9][0-9]{0,8}"></label><p>Avec un ID existant, les champs ci-dessous sont ignorés et le lieu reste inchangé. Sinon, vérifier les champs avant création du lieu ; l’adresse complète restera son nom.</p>';
        foreach(['address'=>'Adresse du lieu','zip'=>'Code postal','city'=>'Ville','country'=>'Pays'] as $key=>$label)$html.='<label>'.$label.'<input name="venue_'.$key.'" maxlength="2000" value="'.MusicbandEscape($venueValues[$key]).'"></label>';
        return $html.'<label><input type="checkbox" name="publication_new" value="yes" required> J’ai vérifié que ce concert n’est pas déjà publié sur le site cible.</label><label><input type="checkbox" name="publication_confirm" value="yes" required> Je confirme les informations et leur publication publique sur WordPress.</label><button>Publier le concert sur WordPress</button></form><p><a href="'.MusicbandEscape(MusicbandBase()).'concerts.php?id='.(int)$row['id'].'">Annuler</a></p></details>';
    }catch(Throwable $e){return $html.'<p>'.MusicbandEscape($e instanceof RuntimeException?$e->getMessage():'Publication indisponible : vérifier MySQL et la configuration.').'</p></details>';}
}
