<?php
/* Musicband AUDIT TRAIL: collecte locale bornée, sans secret ni appel réseau externe. */
/* Collecte et requêtage du journal MySQL depuis le socle auth.php.
 * Utilise la connexion RBAC et les métadonnées de requête ; les détails passent par une normalisation bornée.
 * Accumule les événements puis les écrit en fin de requête ; les erreurs de journalisation ne doivent pas exposer de secrets.
 */
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
function MusicbandAuditText($value,int $limit): string {
    if(!is_scalar($value))return '';
    $value=(string)$value;if(!preg_match('//u',$value))return '[encodage invalide]';
    $value=preg_replace('/[\x00-\x1f\x7f]/u',' ',$value);
    preg_match('/\A.{0,'.$limit.'}/us',$value,$match);return $match[0]??'';
}
function MusicbandAuditReady(PDO $db): bool {
    static $ready=[];$key=spl_object_id($db);if(isset($ready[$key]))return $ready[$key];
    $q=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_NAME IN ('Musicbandai_audit_events','Musicbandai_audit_settings')");$tables=$q->fetchAll(PDO::FETCH_KEY_PAIR);
    if(count($tables)!==2)return false;
    foreach($tables as $engine)if(strcasecmp($engine,'InnoDB')!==0)throw new RuntimeException('Audit : tables InnoDB requises.');
    foreach(['Musicbandai_audit_events'=>['id','occurred_at','request_id','actor','module','action','outcome','details','source_ip','location','user_agent','language','method','route'],'Musicbandai_audit_settings'=>['id','filters_json','last_purge_at','last_purge_count']] as $table=>$columns){
        $q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);if(array_diff($columns,$q->fetchAll(PDO::FETCH_COLUMN)))throw new RuntimeException('Audit : schéma 015 incomplet.');
    }
    foreach(['Musicbandai_audit_events','Musicbandai_audit_settings'] as $table){$q=$db->prepare("SELECT COLUMN_NAME FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");$q->execute([$table]);if($q->fetchAll(PDO::FETCH_COLUMN)!==['id'])throw new RuntimeException('Audit : clé primaire incompatible.');}
    return $ready[$key]=true;
}
function MusicbandAuditIdentity(string $login): void {if(isset($GLOBALS['Musicband_audit']))$GLOBALS['Musicband_audit']['actor']=MusicbandAuditText($login,254);}
function MusicbandAuditEvent(string $module,string $action,string $outcome,array $details=[],?string $actor=null): void {
    if(!isset($GLOBALS['Musicband_audit'])||count($GLOBALS['Musicband_audit']['events'])>=40)return;
    // Liste blanche : jamais de corps POST/JSON, mot de passe, jeton, cookie ou URL avec paramètres.
    $safe=[];foreach(['id','publication','target','fields','count','reason','document','requested_action','http_status','purged','cutoff'] as $key)if(array_key_exists($key,$details))$safe[$key]=MusicbandAuditText(is_array($details[$key])?implode(', ',array_map(fn($item)=>is_scalar($item)?MusicbandAuditText($item,100):'[valeur structurée]',array_slice($details[$key],0,40))):$details[$key],600);
    $GLOBALS['Musicband_audit']['events'][]=['module'=>MusicbandAuditText($module,64),'action'=>MusicbandAuditText($action,96),'outcome'=>MusicbandAuditText($outcome,24),'details'=>json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'actor'=>$actor===null?($GLOBALS['Musicband_audit']['actor']??null):MusicbandAuditText($actor,254),'at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')];
    if($action==='login.success')$GLOBALS['Musicband_audit']['purge']=true;
}
function MusicbandAuditBegin(): void {
    if(isset($GLOBALS['Musicband_audit']))return;
    $path=(string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);$base=basename($path);
    $map=['login.php'=>'auth','logout.php'=>'auth','activate.php'=>'auth','forgot.php'=>'auth','admin.php'=>'configuration','upgrade.php'=>'upgrade','songs.php'=>'songs','contacts.php'=>'contacts','concerts.php'=>'concerts','devis_factures.php'=>'devis_factures','setlists.php'=>'setlist','setlist_variants.php'=>'setlist','setlist_pdf.php'=>'setlist','setlist_import.php'=>'setlist','calendar.php'=>'agenda','ideas.php'=>'ideas','download.php'=>'exports','audit.php'=>'audit','notifications_device.php'=>'notifications','expenses.php'=>'expenses','accounting.php'=>'accounting','tasks.php'=>'tasks'];
    $module=$map[$base]??(preg_match('~/setlist(?:/|$)~',$path)?'setlist':(preg_match('~/sound(?:/|$)~',$path)?'sound':($base==='index.php'||$base===''||$path===MusicbandBase()?'home':null)));
    if($module===null)return;
    $ip=$_SERVER['REMOTE_ADDR']??'';$ip=is_string($ip)&&filter_var($ip,FILTER_VALIDATE_IP)?$ip:null;
    $local=$ip!==null&&!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);
    $action=$_POST['action']??($_GET['action']??'');$action=is_string($action)&&preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$action)?$action:'';
    $GLOBALS['Musicband_audit']=['actor'=>null,'events'=>[],'purge'=>false,'request_id'=>bin2hex(random_bytes(16)),'module'=>$module,'requested_action'=>$action,'ip'=>$ip,'location'=>$local?'Réseau privé/réservé · position inconnue':'Non disponible · aucune source locale fiable','ua'=>MusicbandAuditText($_SERVER['HTTP_USER_AGENT']??'',512),'language'=>MusicbandAuditText($_SERVER['HTTP_ACCEPT_LANGUAGE']??'',120),'method'=>MusicbandAuditText($_SERVER['REQUEST_METHOD']??'',12),'route'=>MusicbandAuditText($path,160)];
    register_shutdown_function('MusicbandAuditFlush');
}
/** Écrit les événements en fin de requête sur une connexion séparée ; la purge de rétention éventuelle est verrouillée et ne touche pas aux données métier. */
function MusicbandAuditFlush(): void {
    $context=$GLOBALS['Musicband_audit']??null;if(!$context)return;
    try{
        $settings=[];foreach(['DB_SERVER','DB_NAME','DB_USER','DB_PASSWORD','DB_PORT','DB_SSL_CA'] as $key)if(getenv($key)!==false)$settings[$key]=getenv($key);
        // Connexion séparée : aucune validation ni annulation de transaction métier par l'audit.
        $settings=array_replace($settings,MusicbandRead('database_settings'));if(empty($settings['DB_SERVER'])||empty($settings['DB_NAME'])||empty($settings['DB_USER'])||empty($settings['DB_PASSWORD']))return;
        $db=MusicbandDatabase($settings);if(!MusicbandAuditReady($db))return;
        $status=http_response_code()?:200;$last=error_get_last();$fatal=$last&&in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true);
        MusicbandAuditEvent($context['module'],'request.observed',$fatal?'error':($status>=400?'rejected':'observed'),['requested_action'=>$context['requested_action'],'http_status'=>$status,'id'=>is_scalar($_POST['id']??$_GET['id']??null)&&preg_match('/^[0-9]{1,18}$/D',(string)($_POST['id']??$_GET['id']))?(string)($_POST['id']??$_GET['id']):'']);
        $q=$db->prepare('INSERT INTO Musicbandai_audit_events(occurred_at,request_id,actor,module,action,outcome,details,source_ip,location,user_agent,language,method,route) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($GLOBALS['Musicband_audit']['events'] as $event)$q->execute([$event['at'],$context['request_id'],$event['actor'],$event['module'],$event['action'],$event['outcome'],$event['details'],$context['ip'],$context['location'],$context['ua'],$context['language'],$context['method'],$context['route']]);
        if($context['purge']){
            if(function_exists('fastcgi_finish_request')){if(session_status()===PHP_SESSION_ACTIVE)session_write_close();fastcgi_finish_request();}
            // Chaque connexion réussie déclenche la purge ; un seul travailleur par base.
            $lock='Musicband_audit_'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,32);
            $get=$db->prepare('SELECT GET_LOCK(?,0)');$get->execute([$lock]);
            if((int)$get->fetchColumn()===1){
                try{$cutoff=$db->query('SELECT UTC_TIMESTAMP(6)-INTERVAL 6 MONTH')->fetchColumn();$deleted=0;
                    $purge=$db->prepare('DELETE FROM Musicbandai_audit_events WHERE occurred_at < ? ORDER BY occurred_at,id LIMIT 2000');
                    do{$purge->execute([$cutoff]);$n=$purge->rowCount();$deleted+=$n;}while($n===2000);
                    $q=$db->prepare('UPDATE Musicbandai_audit_settings SET last_purge_at=UTC_TIMESTAMP(6),last_purge_count=? WHERE id=1');$q->execute([$deleted]);
                }finally{$release=$db->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lock]);}
            }
        }
    }catch(Throwable $e){error_log('Musicband_audit_write_or_purge_failed');}
}
function MusicbandAuditUpgrade(array $settings): string {
    if(($_POST['confirm']??'')!=='yes'||!is_string($_POST['confirm_database']??null)||!hash_equals($settings['DB_NAME']??'',$_POST['confirm_database']))throw new RuntimeException('Confirmer la sauvegarde et le nom exact de la base.');
    $db=MusicbandDatabase($settings);$sql=base64_decode('',true);if(!$sql)throw new RuntimeException('Schéma Audit indisponible.');
    foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$db->exec($statement);
    if(!MusicbandAuditReady($db))throw new RuntimeException('Schéma Audit incomplet.');
    MusicbandAuditEvent('audit','schema.prepared','success');return 'Audit 015 préparé. Collecte à partir des prochaines requêtes ; aucun historique reconstitué. Purge automatique après chaque connexion réussie.';
}
function MusicbandAuditUpgradeForm(string $hidden): string {
    return '<section><h3>Audit trail · schéma 015</h3><p>Deux tables SQL : événements et filtres partagés. Sauvegarder la base avant préparation. L’audit débute après installation ; aucune reprise fictive. À chaque connexion réussie, les événements de plus de six mois sont supprimés, par lots, uniquement dans le journal d’audit. Les réglages et autres historiques sont conservés.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="audit_schema"><label>Nom exact de la base<input name="confirm_database" required></label><label><input type="checkbox" name="confirm" value="yes" required> Sauvegarde vérifiée et rétention de six mois acceptée</label><button>Préparer Audit 015</button></form></section>';
}
function MusicbandAuditFields(): array {
    return ['occurred_at'=>['Horodatage','datetime'],'actor'=>['Utilisateur','text'],'module'=>['Module','text'],'action'=>['Action','text'],'outcome'=>['Résultat','text'],'details'=>['Détail','text'],'source_ip'=>['IP source','text'],'location'=>['Localisation','text'],'user_agent'=>['User agent','text'],'language'=>['Langues annoncées','text'],'method'=>['Méthode','text'],'route'=>['Chemin sans paramètres','text'],'request_id'=>['Requête','text'],'id'=>['ID journal','int']];
}
function MusicbandAuditOperators(string $field): array {
    return array_merge(['equal','not_equal','is_null','is_not_null'],MusicbandAuditFields()[$field][1]==='text'?['contains','not_contains']:['less','less_or_equal','greater','greater_or_equal']);
}
function MusicbandAuditRules(array $node,int &$size,int $depth=0): array {
    if(++$size>100||$depth>4)throw new RuntimeException('Limiter les critères à 100 nœuds et 4 niveaux.');
    if(array_key_exists('rules',$node)){
        if(!is_array($node['rules'])||!array_is_list($node['rules'])||!in_array($node['condition']??null,['AND','OR'],true)||!is_bool($node['not']??false)||($depth>0&&!$node['rules']))throw new RuntimeException('Groupe de critères invalide.');
        $rules=[];foreach($node['rules'] as $child){if(!is_array($child))throw new RuntimeException('Critère invalide.');$rules[]=MusicbandAuditRules($child,$size,$depth+1);}
        return ['condition'=>$node['condition'],'not'=>$node['not']??false,'rules'=>$rules];
    }
    $field=$node['id']??null;$operator=$node['operator']??null;
    if(!is_string($field)||!isset(MusicbandAuditFields()[$field])||!is_string($operator)||!in_array($operator,MusicbandAuditOperators($field),true))throw new RuntimeException('Attribut ou comparaison invalide.');
    $value=null;
    if(!in_array($operator,['is_null','is_not_null'],true)){
        $value=$node['value']??null;if(!is_scalar($value)||is_bool($value)||trim((string)$value)===''||strlen((string)$value)>6000||!preg_match('//u',(string)$value))throw new RuntimeException('Valeur de critère invalide.');$value=(string)$value;
        if(MusicbandAuditFields()[$field][1]==='int'&&!preg_match('/^[0-9]{1,18}$/D',$value))throw new RuntimeException('ID entier positif attendu.');
        if(MusicbandAuditFields()[$field][1]==='datetime'){
            $value=str_replace('T',' ',$value);if(strlen($value)===16)$value.=':00';
            $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,MusicbandDateZone());$errors=DateTimeImmutable::getLastErrors();
            if(!$date||($errors&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d H:i:s')!==$value)throw new RuntimeException('Horodatage local invalide.');
            $value=$date->format('Y-m-d\TH:i:s');
        }
    }
    return ['id'=>$field,'operator'=>$operator,'value'=>$value];
}
function MusicbandAuditDecodeRules($raw): array {
    if(!is_string($raw)||strlen($raw)>20000)throw new RuntimeException('Critères trop volumineux.');
    if($raw==='')return ['condition'=>'AND','not'=>false,'rules'=>[]];
    try{$rules=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new RuntimeException('Critères invalides.');}
    if(!is_array($rules)||!isset($rules['rules']))throw new RuntimeException('Groupe principal requis.');$size=0;return MusicbandAuditRules($rules,$size);
}
function MusicbandAuditSql(array $node,array &$parameters): string {
    if(isset($node['rules'])){
        if(!$node['rules'])return '1=1';$parts=[];foreach($node['rules'] as $rule)$parts[]=MusicbandAuditSql($rule,$parameters);
        return (!empty($node['not'])?'NOT ':'').'('.implode(' '.$node['condition'].' ',$parts).')';
    }
    $field='`'.$node['id'].'`';$op=$node['operator'];$value=$node['value'];
    if($op==='is_null')return MusicbandAuditFields()[$node['id']][1]==='text'?"($field IS NULL OR $field='')":"$field IS NULL";
    if($op==='is_not_null')return MusicbandAuditFields()[$node['id']][1]==='text'?"($field IS NOT NULL AND $field<>'')":"$field IS NOT NULL";
    if(MusicbandAuditFields()[$node['id']][1]==='datetime')$value=(new DateTimeImmutable($value,MusicbandDateZone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    if($node['id']==='occurred_at'&&in_array($op,['equal','not_equal'],true)){
        $parameters[]=$value;$parameters[]=(new DateTimeImmutable($value,new DateTimeZone('UTC')))->modify('+1 second')->format('Y-m-d H:i:s');return ($op==='not_equal'?'NOT ':'')."($field>=? AND $field<?)";
    }
    if(in_array($op,['contains','not_contains'],true)){
        $parameters[]='%'.str_replace(['=','%','_'],['==','=%','=_'],$value).'%';return ($op==='not_contains'?"($field<>'' AND $field NOT LIKE ? ESCAPE '=')":$field." LIKE ? ESCAPE '='");
    }
    if($op==='not_equal'&&MusicbandAuditFields()[$node['id']][1]==='text'){$parameters[]=$value;return "($field<>'' AND $field<>?)";}
    $comparisons=['equal'=>'=','not_equal'=>'<>','less'=>'<','less_or_equal'=>'<=','greater'=>'>','greater_or_equal'=>'>='];
    $parameters[]=$value;return $field.$comparisons[$op].'?';
}
function MusicbandAuditPresets(PDO $db): array {
    $raw=$db->query('SELECT filters_json FROM Musicbandai_audit_settings WHERE id=1')->fetchColumn();if(!is_string($raw))throw new RuntimeException('Réglages Audit 015 absents.');
    $items=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($items)||count($items)>50)throw new RuntimeException('Filtres audit invalides.');
    foreach($items as $key=>$item)if(!is_string($key)||!preg_match('/^f[a-f0-9]{16}$/D',$key)||!is_array($item)||!is_string($item['name']??null)||!is_array($item['rules']??null))throw new RuntimeException('Filtre audit invalide.');
    return ['items'=>$items,'version'=>hash('sha256',$raw)];
}
function MusicbandAuditPresetSave(PDO $db): string {
    MusicbandRequirePermission('audit.read');MusicbandCsrf();$action=$_POST['action']??'';
    if(!in_array($action,['filter_save','filter_delete'],true))throw new RuntimeException('Action inconnue.');
    $db->beginTransaction();try{
        $db->query('SELECT id FROM Musicbandai_audit_settings WHERE id=1 FOR UPDATE');$saved=MusicbandAuditPresets($db);
        if(!is_string($_POST['filter_version']??null)||!hash_equals($saved['version'],$_POST['filter_version']))throw new RuntimeException('Filtres modifiés ailleurs : recharger.');
        if($action==='filter_save'){
            $name=$_POST['filter_name']??null;
            if(!is_string($name)||!preg_match('/^.{1,80}$/usD',trim($name))||preg_match('/[\x00-\x1f\x7f]/',$name))throw new RuntimeException('Nom requis : 1 à 80 caractères.');$name=trim($name);
            $rules=MusicbandAuditDecodeRules($_POST['rules']??'');if(!$rules['rules'])throw new RuntimeException('Appliquer des critères avant de les enregistrer.');
            if(count($saved['items'])>=50)throw new RuntimeException('50 filtres maximum.');
            foreach($saved['items'] as $item)if(preg_match('/\A'.preg_quote($name,'/').'\z/iu',$item['name']))throw new RuntimeException('Nom de filtre déjà utilisé.');
            $id='f'.bin2hex(random_bytes(8));$saved['items'][$id]=['name'=>$name,'rules'=>$rules];
        }else{
            $id=$_POST['preset']??'';if(!is_string($id)||!isset($saved['items'][$id])||($_POST['confirm_filter']??'')!=='yes')throw new RuntimeException('Confirmer la suppression du filtre actif.');unset($saved['items'][$id]);$affected=$id;$id='';
        }
        $q=$db->prepare('UPDATE Musicbandai_audit_settings SET filters_json=? WHERE id=1');$q->execute([json_encode($saved['items'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);$db->commit();
        MusicbandAuditEvent('audit',$action,'success',['id'=>$affected??$id]);return $id;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
