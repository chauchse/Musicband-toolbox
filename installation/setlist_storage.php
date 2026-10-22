<?php
/* Musicband SETLIST STORAGE: état courant SQL, import explicite et nettoyage vérifié. */
/* Stockage et liens du catalogue/setlists, avec compatibilité des anciennes publications JSON.
 * Dépend de database.php, song_fields.php, setlist_calculations.php et des notifications avancées.
 * Les écritures et migrations sont explicites ; une fois SQL actif, les erreurs ne doivent pas réactiver les fichiers historiques.
 */
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
require_once __DIR__.'/database.php';
require_once __DIR__.'/song_fields.php';
require_once __DIR__.'/setlist_calculations.php';
require_once __DIR__.'/advanced_notifications_service.php';
const SETLIST_SQL_MAX_BYTES=16000000;
function setlistSqlReady(PDO $db): bool {
    $keys=['setlist_documents'=>['document_key'],'setlist_runtime'=>['id'],'setlist_songs'=>['id'],'setlist_song_links'=>['source_file','editor_id']];
    $columns=['setlist_documents'=>['document_key','content','sha256'],'setlist_runtime'=>['id','active','cleanup_json'],'setlist_songs'=>['id','title','artist','duration_seconds','tempo','guitar','musical_key','energy','p5','seb_comments','data_json','source_json','revision'],'setlist_song_links'=>['source_file','editor_id','song_id']];
    foreach($keys as $table=>$expected){$q=$db->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);$engine=$q->fetchColumn();if($engine===false)return false;if(strcasecmp((string)$engine,'InnoDB')!==0)throw new RuntimeException('Schéma 014 : InnoDB requis.');
        $q=$db->prepare("SELECT COLUMN_NAME FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");$q->execute([$table]);if($q->fetchAll(PDO::FETCH_COLUMN)!==$expected)throw new RuntimeException('Clé primaire 014 incompatible : inspection manuelle requise.');
        $q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);if(array_diff($columns[$table],$q->fetchAll(PDO::FETCH_COLUMN)))throw new RuntimeException('Colonnes 014 incomplètes : inspection requise.');
    }
    $foreign=[];foreach(documentsForeignKeys($db) as $fk)if($fk['TABLE_NAME']==='setlist_song_links'&&$fk['DELETE_RULE']==='RESTRICT')$foreign[$fk['COLUMN_NAME']]=$fk['REFERENCED_TABLE_NAME'].'.'.$fk['REFERENCED_COLUMN_NAME'];
    if(($foreign['source_file']??'')!=='Musicband_publications.source_file'||($foreign['song_id']??'')!=='setlist_songs.id')throw new RuntimeException('Relations 014 incompatibles.');return true;
}
function setlistSqlActive(): bool {
    $db=MusicbandRbacDb();$q=$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='setlist_runtime'");
    if((int)$q->fetchColumn()!==1)return false;$active=$db->query('SELECT active FROM setlist_runtime WHERE id=1')->fetchColumn();if($active===false)throw new RuntimeException('État SQL incomplet : reprendre 014 dans Upgrade.');return (int)$active===1;
}
/** Retourne le document SQL seulement si son empreinte correspond ; absence ou corruption = exception, sans repli fichier. */
function setlistSqlRaw(string $key): string {
    $q=MusicbandRbacDb()->prepare('SELECT content,sha256 FROM setlist_documents WHERE document_key=?');$q->execute([$key]);$r=$q->fetch();
    if(!$r||!hash_equals($r['sha256'],hash('sha256',$r['content'])))throw new RuntimeException('Document SQL absent ou empreinte invalide.');return $r['content'];
}
function setlistSqlPut(string $key,string $raw): void {
    if(strlen($raw)>SETLIST_SQL_MAX_BYTES)throw new RuntimeException('Document Setlist trop volumineux.');json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    $q=MusicbandRbacDb()->prepare('INSERT INTO setlist_documents(document_key,content,sha256) VALUES (?,?,?) ON DUPLICATE KEY UPDATE content=VALUES(content),sha256=VALUES(sha256)');$q->execute([$key,$raw,hash('sha256',$raw)]);
}
/** Accepte uniquement un identifiant de publication connu ; choisit SQL actif ou la compatibilité fichier, jamais un chemin arbitraire. */
function setlistRaw(string $file): string {
    if(!preg_match('/^(?:setlists|setlist-[a-f0-9]{16})\.json$/D',$file))throw new RuntimeException('Identifiant de publication invalide.');
    if(setlistSqlActive())return setlistSqlRaw($file);
    $path=__DIR__.'/setlist/'.$file;if(is_link($path))throw new RuntimeException('Lien symbolique refusé.');$raw=@file_get_contents($path,false,null,0,SETLIST_SQL_MAX_BYTES+1);
    if($raw===false||strlen($raw)>SETLIST_SQL_MAX_BYTES)throw new RuntimeException('Publication illisible.');return $raw;
}
function setlistSaved(string $file): array {
    return setlistSqlActive()?json_decode(setlistSqlRaw('variants:'.$file),true,64,JSON_THROW_ON_ERROR):MusicbandRead('setlist-variants-'.substr($file,8,16));
}
/** Reconstruit les tables relationnelles de la publication et de ses variantes ; l’appelant doit gérer la transaction du travail global. */
function setlistSqlMirror(string $file,array $data,string $title,array $saved=[]): void {
    $db=MusicbandRbacDb();$q=$db->prepare('INSERT INTO Musicband_publications(publication_key,title,concert_location,target_seconds,source_file,snapshot_json,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE title=VALUES(title),snapshot_json=VALUES(snapshot_json),concert_location=VALUES(concert_location),target_seconds=VALUES(target_seconds)');
    $q->execute([substr($file,8,16),$title,$data['concert_location']??null,$data['target_seconds']??null,$file,json_encode($data,JSON_THROW_ON_ERROR)]);
    $key=substr($file,8,16);$q=$db->prepare('DELETE s FROM Musicband_variant_songs s JOIN Musicband_variants v ON v.id=s.variant_id WHERE v.publication_key=?');$q->execute([$key]);$keys=[];
    $variantInsert=$db->prepare('INSERT INTO Musicband_variants(publication_key,variant_key,source_variant_key,name,saved_by,created_at,snapshot_json) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),source_variant_key=VALUES(source_variant_key),name=VALUES(name),saved_by=VALUES(saved_by),snapshot_json=VALUES(snapshot_json)');
    $songInsert=$db->prepare('INSERT INTO Musicband_variant_songs(variant_id,position,editor_id,title,artist,section,duration_seconds,snapshot_json) VALUES (?,?,?,?,?,?,?,?)');
    foreach(array_merge($data['variants'],$saved) as $vi=>$variant){$keys[]=$variant['id'];$variantInsert->execute([$key,$variant['id'],$variant['source_variant_id']??null,$variant['name']??$variant['id'],$variant['saved_by']??null,json_encode($variant,JSON_THROW_ON_ERROR)]);$id=(int)$db->lastInsertId();foreach($variant['songs'] as $i=>$song)$songInsert->execute([$id,$i+1,$song['editor_id']??'legacy-'.$vi.'-'.$i,$song['title'],$song['artist'],$song['section'],$song['duration_seconds'],json_encode($song,JSON_THROW_ON_ERROR)]);}
    $q=$db->prepare('DELETE FROM Musicband_variants WHERE publication_key=? AND variant_key NOT IN ('.implode(',',array_fill(0,count($keys),'?')).')');$q->execute([$key,...$keys]);

}
function setlistSqlSong(array $data,array $source=[]): int {
    foreach(['title','artist'] as $key)if(!is_string($data[$key]??null))throw new RuntimeException('Titre/artiste de morceau invalide.');
    $q=MusicbandRbacDb()->prepare('INSERT INTO setlist_songs(title,artist,duration_seconds,tempo,guitar,musical_key,energy,p5,seb_comments,data_json,source_json,revision) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$data['title'],$data['artist'],$data['duration_seconds']??null,$data['tempo']??null,$data['guitar']??null,$data['key']??null,$data['energy']??null,$data['p5']??null,$data['seb_comments']??null,json_encode($data,JSON_THROW_ON_ERROR),json_encode($source,JSON_THROW_ON_ERROR),bin2hex(random_bytes(16))]);$id=(int)MusicbandRbacDb()->lastInsertId();songUpdateColumns(MusicbandRbacDb(),$id,$data);return $id;
}
function setlistSqlSource(array $row,array $headers): array {
    $source=[];foreach($row['cells']??[] as $i=>$value)$source['Colonne '.($i+1).' · '.($headers[$i]??'Sans titre')]=(string)$value;return $source;
}
/** Valide l’instantané et ses durées avant insertion ; un identifiant déjà importé avec un contenu différent est refusé. */
function setlistSqlImport(string $file,string $raw,string $title,array $saved=[]): void {
    if (defined('MUSICBAND_COMMUNITY_EXTERNAL_IMPORTS') && !MUSICBAND_COMMUNITY_EXTERNAL_IMPORTS) throw new RuntimeException('Imports externes indisponibles dans Community.');
    if(!preg_match('/^setlist-[a-f0-9]{16}\.json$/D',$file)||strlen($raw)>SETLIST_SQL_MAX_BYTES)throw new RuntimeException('Publication invalide ou trop volumineuse.');
    $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($data)||!is_array($data['variants']??null)||!$data['variants']||count($data['variants'])>20)throw new RuntimeException('Publication sans variantes valides.');
    foreach(array_merge($data['variants'],$saved) as $variant){
        foreach(['music_seconds','transition_seconds','pause_seconds','total_seconds'] as $k)if(!is_int($variant[$k]??null)||$variant[$k]<0)throw new RuntimeException('Durées de variante invalides.');
        if($variant['music_seconds']+$variant['transition_seconds']+$variant['pause_seconds']!==$variant['total_seconds'])throw new RuntimeException('Total de variante incohérent.');
        $music=0;$encore=false;foreach($variant['songs']??[] as $i=>$song){if(!is_int($song['duration_seconds']??null)||$song['duration_seconds']<0||($song['position']??$i+1)!==$i+1||!in_array($song['section']??'',['main','encore'],true)||$encore&&$song['section']!=='encore')throw new RuntimeException('Morceau ou ordre de variante invalide.');$music+=$song['duration_seconds'];$encore=$song['section']==='encore';}
        if($music!==$variant['music_seconds'])throw new RuntimeException('Durée musicale incohérente.');
    }
    $db=MusicbandRbacDb();$q=$db->prepare('SELECT sha256 FROM setlist_documents WHERE document_key=?');$q->execute([$file]);$old=$q->fetchColumn();if($old!==false){if(!hash_equals($old,hash('sha256',$raw)))throw new RuntimeException('Publication SQL déjà présente avec un autre contenu : import refusé.');return;}
    $songs=[];$full=[];$headers=$data['catalogue']['headers']??[];
    if(!is_array($headers)||count($headers)>500)throw new RuntimeException('En-têtes du catalogue invalides.');
    foreach($headers as $header)if(!is_string($header)||strlen($header)>6000)throw new RuntimeException('En-tête invalide.');
    foreach($data['catalogue']['rows']??[] as $r){if(!is_array($r)||!is_int($r['sheet_row']??null)||$r['sheet_row']<1||isset($full[(string)$r['sheet_row']])||!is_array($r['cells']??null)||count($r['cells'])>500)throw new RuntimeException('Ligne source absente ou dupliquée.');foreach($r['cells'] as $cell)if(!is_string($cell)||strlen($cell)>6000)throw new RuntimeException('Cellule source invalide.');$full[(string)$r['sheet_row']]=$r;}

    foreach($data['available_songs']??[] as $i=>$song){if(!is_string($song['editor_id']??null)){if(isset($data['editor']))throw new RuntimeException('Identifiant de morceau absent.');$song['editor_id']='legacy-available-'.$i;}if($song['editor_id']===''||strlen($song['editor_id'])>64)throw new RuntimeException('Identifiant de réserve invalide.');if(isset($songs[$song['editor_id']]))throw new RuntimeException('Identifiant de réserve dupliqué.');$songs[$song['editor_id']]=$song;}
    $ids=[];foreach(array_merge($data['variants'],$saved) as $variantIndex=>$v){if(!is_string($v['id']??null)||$v['id']===''||strlen($v['id'])>64||isset($ids[$v['id']]))throw new RuntimeException('Variante absente ou dupliquée.');$ids[$v['id']]=true;
        if(!is_array($v['songs']??null)||!$v['songs']||count($v['songs'])>1000)throw new RuntimeException('Liste de morceaux invalide.');
        $seen=[];foreach($v['songs'] as $songIndex=>$song){$id=$song['editor_id']??(isset($data['editor'])?null:'legacy-'.$variantIndex.'-'.$songIndex);$song['editor_id']=$id;if(!is_string($id)||strlen($id)>64||isset($seen[$id]))throw new RuntimeException('Identifiant de morceau absent ou dupliqué.');$seen[$id]=true;$songs[$id]=$song;}
    }
    foreach($full as $id=>$r)if(!isset($songs[$id]))$songs[$id]=array_replace($r,['editor_id'=>(string)$id]);
    if(count($songs)>2000)throw new RuntimeException('Catalogue limité à 2000 morceaux par import.');
    setlistSqlMirror($file,$data,$title,$saved);setlistSqlPut($file,$raw);setlistSqlPut('variants:'.$file,json_encode($saved,JSON_THROW_ON_ERROR));
    $link=$db->prepare('INSERT INTO setlist_song_links(source_file,editor_id,song_id) VALUES (?,?,?)');
    foreach($songs as $id=>$song){$song=array_replace($full[$id]??[],$song);$song['_headers']=$data['catalogue']['headers']??[];$song['_column_indexes']=$data['catalogue']['column_indexes']??[];$songId=setlistSqlSong($song,setlistSqlSource($full[$id]??[],$data['catalogue']['headers']??[]));$link->execute([$file,(string)$id,$songId]);}
}
/** Exécute uniquement l’étape de migration confirmée : schéma, conversion ou retrait des anciens JSON vérifiés par empreinte. */
function setlistSqlUpgrade(string $action,array $settings): string {
    if (defined('MUSICBAND_COMMUNITY_EXTERNAL_IMPORTS') && !MUSICBAND_COMMUNITY_EXTERNAL_IMPORTS) throw new RuntimeException('Anciennes conversions et imports indisponibles dans Community.');
    if(!MusicbandRequireLogin()['admin']||($_POST['confirm']??'')!=='yes'||($_POST['confirm_database']??'')!==($settings['DB_NAME']??null))throw new RuntimeException('Administrateur, sauvegarde et base exacte requis.');
    $db=MusicbandRbacDb();
    if($action==='setlist_sql_schema'){
        if(!documentsTableExists($db,'Musicband_publications')||!documentsTableExists($db,'Musicbandai_rbac_permissions'))throw new RuntimeException('Schémas 001 et RBAC 010 requis avant 014.');
        $sql=base64_decode('',true);if(!$sql)throw new RuntimeException('Préparer les fichiers du site.');foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$db->exec($statement);
        if(!setlistSqlReady($db))throw new RuntimeException('Schéma 014 incomplet.');
        $q=$db->prepare('INSERT IGNORE INTO Musicbandai_rbac_permissions(role_key,permission_key,allowed) VALUES (?,?,?)');foreach(MusicbandRoles() as $role=>$_)foreach(['songs.edit','songs.delete','setlist.import'] as $permission)$q->execute([$role,$permission,(int)($role==='admin')]);return 'Schéma SQL 014 préparé, aucun fichier déplacé.';
    }
    if(!setlistSqlReady($db))throw new RuntimeException('Préparer le schéma 014.');
    if($action==='setlist_sql_convert'){
        if(setlistSqlActive())return 'Mode SQL déjà actif. Aucun import ni retrait répété.';
        $manifestPath=__DIR__.'/setlist/setlists.json';
        if(!is_file($manifestPath)){if(glob(__DIR__.'/setlist/setlist-*.json')||(int)$db->query('SELECT COUNT(*) FROM Musicband_publications')->fetchColumn())throw new RuntimeException('Index absent avec publications existantes : restaurer l’index avant conversion.');$manifest=['setlists'=>[],'latest_file'=>null];}else $manifest=setlistManifest();
        $cleanup=[];$db->beginTransaction();
        try{
            $raw=is_file($manifestPath)?setlistRaw('setlists.json'):json_encode($manifest,JSON_THROW_ON_ERROR);if(is_file($manifestPath)){setlistSqlPut('migration:setlists.json',$raw);$cleanup[]=['key'=>'setlists.json','path'=>'public','sha256'=>hash('sha256',$raw)];}setlistSqlPut('setlists.json',$raw);
            foreach($manifest['setlists']??[] as $item){$file=$item['file'];$raw=setlistRaw($file);setlistSqlPut('migration:'.$file,$raw);$saved=setlistSaved($file);setlistSqlImport($file,$raw,$item['title'],$saved);$cleanup[]=['key'=>$file,'path'=>'public','sha256'=>hash('sha256',$raw)];
                $private='setlist-variants-'.substr($file,8,16).'.json';if(is_file(MusicbandPrivate().'/'.$private)){$bytes=file_get_contents(MusicbandPrivate().'/'.$private);setlistSqlPut('legacy:'.$file,$bytes);$cleanup[]=['key'=>'legacy:'.$file,'path'=>$private,'sha256'=>hash('sha256',$bytes)];}
            }
            $q=$db->prepare('UPDATE setlist_runtime SET active=1,cleanup_json=? WHERE id=1');$q->execute([json_encode($cleanup,JSON_THROW_ON_ERROR)]);$db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        return 'Conversion SQL validée. Vérifier lecteur, variantes, liens et exports avant le retrait explicite des anciens JSON.';
    }
    if($action==='setlist_sql_cleanup'){
        if(!setlistSqlActive())throw new RuntimeException('Activer et contrôler le mode SQL avant retrait.');
        $plan=json_decode($db->query('SELECT cleanup_json FROM setlist_runtime WHERE id=1')->fetchColumn()?:'[]',true,64,JSON_THROW_ON_ERROR);
        // Vérifier tout avant le premier retrait. Un document modifié reste protégé.
        foreach($plan as $entry){$raw=setlistSqlRaw($entry['path']==='public'?'migration:'.$entry['key']:$entry['key']);if(!hash_equals($entry['sha256'],hash('sha256',$raw)))throw new RuntimeException('Document SQL modifié depuis conversion : retrait refusé, exporter et vérifier manuellement.');
            $path=setlistCleanupPath($entry);if(is_link($path))throw new RuntimeException('Lien symbolique refusé.');if(is_file($path)&&!hash_equals($entry['sha256'],hash_file('sha256',$path)))throw new RuntimeException('Ancien JSON modifié : retrait refusé.');}
        foreach($plan as $entry){$path=setlistCleanupPath($entry);if(is_file($path)&&!unlink($path))throw new RuntimeException('Retrait partiel : SQL actif, reprendre après inspection.');}
        $db->exec("DELETE FROM setlist_documents WHERE document_key LIKE 'migration:%' OR document_key LIKE 'legacy:%'");$db->exec('UPDATE setlist_runtime SET cleanup_json=NULL WHERE id=1');return 'JSON importés retirés. SQL reste la source de vérité ; PDF et sauvegardes préexistantes conservés.';
    }
    throw new RuntimeException('Action SQL inconnue.');
}
function setlistSqlUpgradeForm(string $hidden): string {
    if (defined('MUSICBAND_COMMUNITY_EXTERNAL_IMPORTS') && !MUSICBAND_COMMUNITY_EXTERNAL_IMPORTS) return '';
    $html='<section><h3>014 · Setlists et catalogue SQL</h3><p>Sauvegarder base et fichiers sur votre poste. Préparer le schéma, convertir, contrôler manuellement lecteur/exports/liens, puis retirer les JSON importés. Aucun nettoyage global ; les PDF restent conservés. Après activation, une panne SQL ne bascule jamais sur les anciens fichiers.</p>';
    foreach(['schema'=>'Préparer le schéma 014','convert'=>'Convertir et activer SQL','cleanup'=>'Retirer les JSON importés après contrôle'] as $key=>$label)$html.='<form method="post">'.$hidden.'<input type="hidden" name="action" value="setlist_sql_'.$key.'"><label>Base exacte<input name="confirm_database" required></label><label><input type="checkbox" name="confirm" value="yes" required> Sauvegardes et étape vérifiées.</label><button>'.$label.'</button></form>';
    return $html.'</section>';
}
function setlistSqlRecalculate(array $data,array $variant): array {
    if(($variant['draft']??false)===true&&($variant['songs']??null)===[])return $variant;
    if(!isset($data['editor']))throw new RuntimeException('Publication historique sans contraintes : importer un JSON récent avant de corriger ses morceaux.');$rules=$variant['editorRules']??$data['editor'];
    $v=variantBuild($data,$variant,['rules'=>$rules,'songs'=>array_map(fn($s)=>['editor_id'=>$s['editor_id'],'section'=>$s['section']],$variant['songs'])],$_SESSION['login']);
    return array_replace($variant,array_intersect_key($v,array_flip(['songs','music_seconds','transition_seconds','pause_seconds','presentation_seconds','encore_break_seconds','total_seconds','margin_seconds','guitar_changes','transition_estimated'])));
}
function setlistPublicSong(array $song): array {
    return array_intersect_key($song,array_flip(['title','artist','duration_seconds','tempo','guitar','key','energy','p5','seb_comments']));
}
function setlistCleanupPath(array $entry): string {
    if(($entry['path']??'')==='public'&&preg_match('/^(?:setlists|setlist-[a-f0-9]{16})\.json$/D',$entry['key']??''))return __DIR__.'/setlist/'.$entry['key'];
    if(preg_match('/^setlist-variants-([a-f0-9]{16})\.json$/D',$entry['path']??'',$m)&&($entry['key']??'')==='legacy:setlist-'.$m[1].'.json')return MusicbandPrivate().'/'.$entry['path'];
    throw new RuntimeException('Plan de retrait invalide : inspection requise.');
}
function setlistSqlRefreshSong(int $id,array $song): void {
    $fullSong=$song;$song=setlistPublicSong($song);$db=MusicbandRbacDb();$q=$db->prepare('SELECT source_file,editor_id FROM setlist_song_links WHERE song_id=?');$q->execute([$id]);
    foreach($q->fetchAll() as $link){$file=$link['source_file'];if(documentsTableExists($db,'setlist_validations')){$check=$db->prepare('SELECT 1 FROM setlist_validations WHERE source_file=?');$check->execute([$file]);if($check->fetchColumn())continue;}
        $data=json_decode(setlistSqlRaw($file),true,64,JSON_THROW_ON_ERROR);$editor=$link['editor_id'];
        foreach($data['catalogue']['rows']??[] as $i=>$r)if((string)($r['sheet_row']??'')===$editor)$data['catalogue']['rows'][$i]=array_replace($r,$fullSong);
        if(($fullSong['ready']??null)===false)foreach(array_merge($data['variants'],$savedForReady=json_decode(setlistSqlRaw('variants:'.$file),true,64,JSON_THROW_ON_ERROR)) as $v)foreach($v['songs'] as $s)if($s['editor_id']===$editor)throw new RuntimeException('Retirer ce morceau des setlists non validées avant de le marquer non prêt.');
        foreach($data['available_songs']??[] as $i=>$s)if($s['editor_id']===$editor){$data['available_songs'][$i]=array_replace($s,$song,['editor_id'=>$editor]);// Seuls les motifs issus du catalogue sont recalculés ; les exclusions explicites restent intactes.
            $reason=$s['unavailable_reason']??null;
            if(!$reason||in_array($reason,['Morceau non prêt','Durée, tempo ou tonalité à compléter dans Morceaux'],true)){
                $data['available_songs'][$i]['unavailable_reason']=($fullSong['ready']??null)===false?'Morceau non prêt':(!is_int($song['duration_seconds']??null)||$song['duration_seconds']<=0||!is_int($song['tempo']??null)||$song['tempo']<=0||!trim((string)($song['key']??''))?'Durée, tempo ou tonalité à compléter dans Morceaux':null);
            }}
        foreach($data['variants'] as &$v){foreach($v['songs'] as &$s)if($s['editor_id']===$editor)$s=array_replace($s,$song,['editor_id'=>$editor,'section'=>$s['section'],'position'=>$s['position']]);unset($s);$v=setlistSqlRecalculate($data,$v);}unset($v);
        $saved=json_decode(setlistSqlRaw('variants:'.$file),true,64,JSON_THROW_ON_ERROR);foreach($saved as &$v){foreach($v['songs'] as &$s)if($s['editor_id']===$editor)$s=array_replace($s,$song,['editor_id'=>$editor,'section'=>$s['section'],'position'=>$s['position']]);unset($s);$v=setlistSqlRecalculate($data,$v);}unset($v);
        setlistSqlPut($file,json_encode($data,JSON_THROW_ON_ERROR));setlistSqlPut('variants:'.$file,json_encode($saved,JSON_THROW_ON_ERROR));$title=$db->prepare('SELECT title FROM Musicband_publications WHERE source_file=?');$title->execute([$file]);setlistSqlMirror($file,$data,(string)$title->fetchColumn(),$saved);
    }
}
function setlistSqlManage(array &$manifest,int $index,string $action,array $user): string {
    $db=MusicbandRbacDb();$file=$manifest['setlists'][$index]['file'];$db->beginTransaction();
    try{
        $lock=$db->prepare('SELECT source_file FROM Musicband_publications WHERE source_file=? FOR UPDATE');$lock->execute([$file]);if(!$lock->fetchColumn())throw new RuntimeException('Publication SQL absente : recharger.');
        if($action==='rename'){$title=trim((string)($_POST['title']??''));if($title===''||strlen($title)>160||preg_match('/[\x00-\x1f]/',$title))throw new RuntimeException('Nom invalide.');$manifest['setlists'][$index]['title']=$title;$q=$db->prepare('UPDATE Musicband_publications SET title=? WHERE source_file=?');$q->execute([$title,$file]);}
        elseif($action==='duplicate'){$new='setlist-'.bin2hex(random_bytes(8)).'.json';$data=json_decode(setlistSqlRaw($file),true,64,JSON_THROW_ON_ERROR);$title=$manifest['setlists'][$index]['title'];while(strlen($title)>145)$title=(string)preg_replace('/.$/us','',$title);$title.=' · Copie';setlistSqlMirror($new,$data,$title,json_decode(setlistSqlRaw('variants:'.$file),true,64,JSON_THROW_ON_ERROR));setlistSqlPut($new,json_encode($data,JSON_THROW_ON_ERROR));setlistSqlPut('variants:'.$new,setlistSqlRaw('variants:'.$file));$q=$db->prepare('INSERT INTO setlist_song_links(source_file,editor_id,song_id) SELECT ?,editor_id,song_id FROM setlist_song_links WHERE source_file=?');$q->execute([$new,$file]);$manifest['setlists'][]=['file'=>$new,'title'=>$title];}
        elseif($action==='delete'){
            if(($_POST['confirm']??'')!=='yes'||setlistLinkedConcert($db,$file)!==null)throw new RuntimeException('Confirmation et dissociation du concert requises.');
            foreach(['setlist_song_links','setlist_validations'] as $table){if(!documentsTableExists($db,$table))continue;$q=$db->prepare('DELETE FROM '.$table.' WHERE source_file=?');$q->execute([$file]);}
            $q=$db->prepare('DELETE s FROM Musicband_variant_songs s JOIN Musicband_variants v ON v.id=s.variant_id JOIN Musicband_publications p ON p.publication_key=v.publication_key WHERE p.source_file=?');$q->execute([$file]);$q=$db->prepare('DELETE v FROM Musicband_variants v JOIN Musicband_publications p ON p.publication_key=v.publication_key WHERE p.source_file=?');$q->execute([$file]);$q=$db->prepare('DELETE FROM Musicband_publications WHERE source_file=?');$q->execute([$file]);$q=$db->prepare('DELETE FROM setlist_documents WHERE document_key IN (?,?)');$q->execute([$file,'variants:'.$file]);array_splice($manifest['setlists'],$index,1);
            if(($manifest['latest_file']??'')===$file)$manifest['latest_file']=$manifest['setlists']?end($manifest['setlists'])['file']:null;
        }else throw new RuntimeException('Action SQL inconnue.');
        setlistSqlPut('setlists.json',json_encode($manifest,JSON_THROW_ON_ERROR));$db->commit();return 'Publication SQL mise à jour. Aucun historique supplémentaire créé.';
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function setlistSongRow(int $id): array {
    $q=MusicbandRbacDb()->prepare('SELECT * FROM setlist_songs WHERE id=?');$q->execute([$id]);$row=$q->fetch();if(!$row)throw new RuntimeException('Morceau SQL introuvable.');return $row;
}
function setlistSongData(array $row): array {
    $song=json_decode($row['data_json'],true,64,JSON_THROW_ON_ERROR);
    if(songFieldsReady())foreach(songFieldSpecs() as $sql=>$spec)if(array_key_exists($sql,$row)&&$row[$sql]!==null){
        $value=$row[$sql];if($spec['type']==='bool')$value=(bool)$value;elseif(in_array($spec['type'],['int','duration'],true))$value=(int)$value;
        $song[$spec['key']]=$value;
    }
    return $song;
}
function setlistAttachSong(string $file,int $id,?string $editor=null): void {
    setlistRequireUnvalidated($file);$fullSong=setlistSongData(setlistSongRow($id));if(($fullSong['ready']??null)===false)throw new RuntimeException('Morceau non prêt : ajout refusé.');$song=setlistPublicSong($fullSong);
    if($editor===null){$q=MusicbandRbacDb()->prepare('SELECT editor_id FROM setlist_song_links WHERE source_file=? AND song_id=?');$q->execute([$file,$id]);if($q->fetchColumn()!==false)return;}
    elseif(!preg_match('/^[A-Za-z0-9:_-]{1,64}$/D',$editor))throw new RuntimeException('Repère source invalide.');
    $data=json_decode(setlistSqlRaw($file),true,64,JSON_THROW_ON_ERROR);
    if($editor!==null){$q=MusicbandRbacDb()->prepare('SELECT song_id FROM setlist_song_links WHERE source_file=? AND editor_id=?');$q->execute([$file,$editor]);if($q->fetchColumn()===false)throw new RuntimeException('Repère source absent.');$q=MusicbandRbacDb()->prepare('SELECT 1 FROM setlist_song_links WHERE source_file=? AND song_id=? AND editor_id<>?');$q->execute([$file,$id,$editor]);if($q->fetchColumn())throw new RuntimeException('Ce morceau SQL est déjà lié à un autre repère de cette publication.');}
    $editor=$editor??'sql-'.$id;
    $q=MusicbandRbacDb()->prepare('INSERT INTO setlist_song_links(source_file,editor_id,song_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE song_id=VALUES(song_id)');$q->execute([$file,$editor,$id]);
    $found=false;foreach($data['available_songs']??[] as $i=>$s)if($s['editor_id']===$editor){$data['available_songs'][$i]=array_replace($s,$song,['editor_id'=>$editor]);$found=true;}
    if(!$found)$data['available_songs'][]=array_replace($song,['editor_id'=>$editor,'hidden'=>true]);setlistSqlPut($file,json_encode($data,JSON_THROW_ON_ERROR));setlistSqlRefreshSong($id,$fullSong);
}
function setlistSongTemplate(string $file=''): ?array {
    $db=MusicbandRbacDb();$args=[];$filter="s.source_json NOT IN ('[]','{}')";
    if($file!==''){$filter.=' AND EXISTS(SELECT 1 FROM setlist_song_links l WHERE l.song_id=s.id AND l.source_file=?)';$args[]=$file;}
    $q=$db->prepare('SELECT s.* FROM setlist_songs s WHERE '.$filter.' ORDER BY s.id DESC LIMIT 1');$q->execute($args);$row=$q->fetch();
    if(!$row&&$file!=='')return setlistSongTemplate();return $row?:null;
}
function setlistSongSave(): int {
    MusicbandRequirePermission('songs.edit');$db=MusicbandRbacDb();$id=businessId($_POST['id']??'',true);$row=$id===null?null:setlistSongRow($id);
    if($row&&(!is_string($_POST['revision']??null)||!hash_equals($row['revision'],$_POST['revision'])))throw new RuntimeException('Morceau modifié ailleurs : recharger.');
    $template=$row??setlistSongTemplate(is_string($_POST['publication']??null)?$_POST['publication']:'');$song=$row?setlistSongData($row):array_intersect_key($template?setlistSongData($template):[],array_flip(['_headers','_column_indexes']));$previousSong=$song;$source=$template?json_decode($template['source_json'],true,64,JSON_THROW_ON_ERROR):[];if(!$row)$source=array_fill_keys(array_keys($source),'');
    foreach(businessConfig('songs')['fields'] as $key=>$_){
        if(!fieldVisibilityVisible('songs',$key))continue;
        $value=$_POST[$key]??null;
        if(!is_string($value))throw new RuntimeException('Valeur de morceau invalide.');
        $spec=songFieldSpecs()[$key];$target=$spec['key'];
        $song[$target]=($spec['type']==='bool'&&($_POST[$key.'_unknown']??'')==='1')?null:songParseValue($spec,$value);
    }
    if(trim($song['title']??'')==='')throw new RuntimeException('Titre requis.');
    foreach($source as $name=>$old){if(songSourceField($name)!==null)continue;$key=fieldVisibilitySourceKey('songs',$name);if(!fieldVisibilityVisible('songs',$key))continue;$value=$_POST[$key]??null;if(!is_string($value)||strlen($value)>6000||!preg_match('//u',$value)||str_contains($value,"\0"))throw new RuntimeException('Cellule importée invalide.');$source[$name]=$value;}
    // Compatibilité avant migration : les anciens champs Prêt/Ordre/À travailler vivaient en JSON.
    if(!songFieldsReady())foreach(['ready'=>'concert_ready','order'=>'catalogue_order','next_rehearsal_work'=>'next_rehearsal_work'] as $target=>$sql){
        $index=$song['_column_indexes'][$target]??null;if($index===null)continue;
        $name='Colonne '.($index+1).' · '.($song['_headers'][$index]??'Sans titre');
        $postKey=fieldVisibilitySourceKey('songs',$name);
        if(isset($_POST[$postKey])&&isset($source[$name]))$song[$target]=songParseValue(songFieldSpecs()[$sql],$source[$name],true);
    }
    // Les 34 champs éditent les valeurs normalisées ; la source importée reste intacte.
    // Les autres colonnes gardent leur éditeur historique et leur provenance.
    if(trim((string)($song['title']??''))==='')throw new RuntimeException('Titre requis.');
    foreach(['duration_seconds','tempo','energy'] as $numeric)if(isset($song[$numeric])&&(!is_int($song[$numeric])||$song[$numeric]<($numeric==='energy'?0:1)||$song[$numeric]>86400))throw new RuntimeException('Durée, tempo ou dynamique hors limites.');
    $cells=$song['cells']??[];foreach($source as $name=>$value)if(preg_match('/^Colonne ([0-9]+) · /u',$name,$m))$cells[(int)$m[1]-1]=$value;if($cells){ksort($cells);$song['cells']=array_values($cells);}
    $ownTransaction=!$db->inTransaction();if($ownTransaction)$db->beginTransaction();try{
        if($id===null)$id=setlistSqlSong($song,$source);
        else{$q=$db->prepare('UPDATE setlist_songs SET title=?,artist=?,duration_seconds=?,tempo=?,guitar=?,musical_key=?,energy=?,p5=?,seb_comments=?,data_json=?,source_json=?,revision=? WHERE id=? AND revision=?');$q->execute([$song['title'],$song['artist']??'',$song['duration_seconds']??null,$song['tempo']??null,$song['guitar']??null,$song['key']??null,$song['energy']??null,$song['p5']??null,$song['seb_comments']??null,json_encode($song,JSON_THROW_ON_ERROR),json_encode($source,JSON_THROW_ON_ERROR),bin2hex(random_bytes(16)),$id,$row['revision']]);if($q->rowCount()!==1)throw new RuntimeException('Conflit de modification.');songUpdateColumns($db,$id,$song);setlistSqlRefreshSong($id,$song);}
        $file=$_POST['publication']??'';if(is_string($file)&&$file!==''){$q=$db->prepare('SELECT 1 FROM setlist_song_links WHERE source_file=? AND song_id=?');$q->execute([$file,$id]);if(!$q->fetchColumn()){MusicbandRequirePermission('setlist.edit');setlistAttachSong($file,$id);}}
        if($ownTransaction)$db->commit();
        if (function_exists('advancedNotificationDispatchEvent')) {
            advancedNotificationDispatchEvent($row === null ? 'songs.create' : 'songs.edit', 'songs', (string)$id, $row ? setlistSongData($row) : [], $song, $_SESSION['login'] ?? null);
        }
        return $id;
    }catch(Throwable $e){if($ownTransaction&&$db->inTransaction())$db->rollBack();throw $e;}
}

function setlistSqlRelatedSongs(PDO $db, string $file): string {
    fieldVisibilityUse($db);

    $q = $db->prepare('SELECT COUNT(*) FROM setlist_song_links WHERE source_file=?');
    $q->execute([$file]);
    $count = (int)$q->fetchColumn();

    if (!$count) return '';

    // Pagination
    $pageKey = 'rel_'.substr(hash('sha256', 'setlist-songs'), 0, 12);
    $page = businessListPage($pageKey);
    $pages = max(1, (int)ceil($count / 50));
    $page = min($page, $pages);

    $q = $db->prepare(
        'SELECT s.*,l.editor_id
         FROM setlist_song_links l
         JOIN setlist_songs s ON s.id=l.song_id
         WHERE l.source_file=?
         ORDER BY s.title,s.id
         LIMIT 50 OFFSET '.(($page-1)*50)
    );
    $q->execute([$file]);

    $artist = fieldVisibilityVisible('songs', 'artist');

    // Section repliable, fermée par défaut
    $html = '<details class="Musicband-related">
        <summary>Morceaux du catalogue liés ('.$count.')</summary>
        <div class="Musicband-related-scroll">
        <table>
            <thead>
                <tr>
                    <th>'.MusicbandEscape(fieldDisplayLabel('songs','title','Titre')).'</th>'.
                    ($artist ? '<th>'.MusicbandEscape(fieldDisplayLabel('songs','artist','Artiste')).'</th>' : '').
                    songRelatedHead().'<th>Repère source / ID SQL</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($q as $song) {
        $html .= '<tr>
            <td><a href="'.MusicbandEscape(MusicbandBase()).'songs.php?id='.$song['id'].'&amp;publication='.rawurlencode($file).'">'.
                MusicbandEscape($song['title']).'</a></td>'.
            ($artist ? '<td>'.MusicbandEscape($song['artist']).'</td>' : '').
            songRelatedCells($song).'<td>'.MusicbandEscape($song['editor_id']).' / '.$song['id'].'</td>
        </tr>';
    }

    $html .= '</tbody></table></div><nav>Page '.$page.' / '.$pages;

    foreach (['Précédente'=>$page-1, 'Suivante'=>$page+1] as $label=>$n) {
        if ($n>=1 && $n<=$pages) {
            $html .= ' <a data-related-page href="'.
                MusicbandEscape(MusicbandBase().'setlist/?'.http_build_query([
                    'publication'=>$file,
                    $pageKey=>$n
                ])).'">'.$label.'</a>';
        }
    }

    return $html.'</nav></details>';
}
