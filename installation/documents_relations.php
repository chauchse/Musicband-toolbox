<?php
/* Musicband DOCUMENTS RELATIONS: schéma 005 et associations explicites. */
/* Relations SQL entre contacts, concerts, documents et publications de setlist.
 * Chargé par business.php ; utilise PDO, les configurations métier et le stockage Setlist.
 * Vérifie/prépare le schéma sur action explicite, rend les liens et sauvegarde les associations demandées.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
function documentsForeignKeys(PDO $db): array {
    return $db->query("SELECT k.TABLE_NAME,k.COLUMN_NAME,k.CONSTRAINT_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll();
}
function documentsExpectedKeys(): array {
    $keys = [
        ['concerts_evenements','contact_id','concerts_contact_fk','contacts_carnet_adresse','id'],
        ['Musicband_publications','concert_id','Musicband_publication_concert_fk','concerts_evenements','id'],
        ['devis_factures_documents','concert_id','devis_factures_concert_fk','concerts_evenements','id'],
        ['devis_factures_documents','contact_id','devis_factures_contact_fk','contacts_carnet_adresse','id'],
        ['devis_factures_revisions','record_id','devis_factures_history_fk','devis_factures_documents','id']];
    // Community conserve les relations contact/concert/setlist sans exiger les tables financières.
    return MusicbandModuleEnabled('devis_factures') ? $keys : array_slice($keys, 0, 2);
}
function documentsTableExists(PDO $db,string $table): bool {
    $q=$db->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'); $q->execute([$table]);
    return strcasecmp((string)$q->fetchColumn(),'InnoDB')===0;
}
function documentsReady(PDO $db): bool {
    if (MusicbandModuleEnabled('devis_factures') && (!documentsTableExists($db,'devis_factures_documents') || !documentsTableExists($db,'devis_factures_revisions'))) return false;
    $metadata=(int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='Musicband_publications' AND column_name IN ('concert_id','concert_link_by','concert_link_at')")->fetchColumn();
    if ($metadata!==3) return false;
    $actual=documentsForeignKeys($db);
    foreach (documentsExpectedKeys() as $expected) {
        $found=false;
        foreach ($actual as $row) if ([$row['TABLE_NAME'],$row['COLUMN_NAME'],$row['CONSTRAINT_NAME'],$row['REFERENCED_TABLE_NAME'],$row['REFERENCED_COLUMN_NAME']]===$expected && $row['DELETE_RULE']==='RESTRICT' && $row['UPDATE_RULE']==='RESTRICT') $found=true;
        if (!$found) return false;
    }
    return true;
}
function documentsPreflight(PDO $db): string {
    foreach (['contacts_carnet_adresse','concerts_evenements','Musicband_publications'] as $table) if (!documentsTableExists($db,$table)) throw new RuntimeException('Schémas 001/002/003 InnoDB requis avant le schéma 005.');
    foreach ([['contacts_carnet_adresse','id',true],['concerts_evenements','id',true],['concerts_evenements','contact_id',false]] as [$table,$column,$primary]) {
        $q=$db->prepare("SELECT COLUMN_TYPE,COLUMN_KEY FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?"); $q->execute([$table,$column]); $id=$q->fetch();
        if (!$id || !preg_match('/^bigint(?:\(\d+\))? unsigned$/iD',$id['COLUMN_TYPE']) || ($primary && $id['COLUMN_KEY']!=='PRI')) throw new RuntimeException('Types des identifiants incompatibles : inspecter le schéma avant migration.');
    }
    $orphans=(int)$db->query('SELECT COUNT(*) FROM concerts_evenements c LEFT JOIN contacts_carnet_adresse a ON a.id=c.contact_id WHERE c.contact_id IS NOT NULL AND a.id IS NULL')->fetchColumn();
    if ($orphans) throw new RuntimeException($orphans.' liens concert/contact orphelins : corriger explicitement avant migration. Aucune valeur supprimée.');
    if (documentsReady($db)) return 'Schéma 005 déjà présent et clés étrangères RESTRICT vérifiées.';
    $q=$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND ((table_name='Musicband_publications' AND column_name IN ('concert_id','concert_link_by','concert_link_at')) OR table_name IN ('devis_factures_documents','devis_factures_revisions'))");
    if ((int)$q->fetchColumn()>0) throw new RuntimeException('Schéma 005 partiel ou personnalisé : inspecter les ajouts existants avant toute reprise. Aucun nettoyage automatique.');
    foreach (documentsForeignKeys($db) as $key) if ($key['TABLE_NAME']==='concerts_evenements' && $key['COLUMN_NAME']==='contact_id') throw new RuntimeException('Une clé concert/contact existe déjà : vérifier sa définition avant adaptation du schéma 005.');
    return 'Prêt pour le schéma 005 : tables parentes InnoDB, identifiants compatibles, aucun lien concert/contact orphelin. Aucune écriture.';
}
function documentsUpgradeAction(string $action,array $settings): string {
    if (!in_array($action,['documents_diagnose','documents_schema'],true)) throw new RuntimeException('Action Documents inconnue.');
    $db=MusicbandDatabase($settings); $diagnostic=documentsPreflight($db);
    if ($action==='documents_diagnose' || documentsReady($db)) return $diagnostic;
    if (($_POST['confirm']??'')!=='yes' || ($_POST['confirm_database']??null)!==($settings['DB_NAME']??null)) throw new RuntimeException('Confirmer la sauvegarde et le nom exact de la base.');
    $sql=base64_decode('',true);
    if (!$sql) throw new RuntimeException('Schéma 005 non embarqué : préparer les fichiers avant publication.');
    foreach (explode(';',$sql) as $statement) if (trim($statement)!=='') $db->exec($statement);
    if (!documentsReady($db)) throw new RuntimeException('Schéma 005 incomplet : inspecter la base avant reprise.');
    return 'Schéma 005 créé : Devis/Factures et clés étrangères contact/concert, document/contact/concert, setlist/concert. Aucune fiche ni publication importée automatiquement.';
}
function documentsUpgradeForm(string $hidden): string {
    return '<section><h3>Devis / Factures et relations · schéma 005</h3><p>Ajouter les fiches documentaires et protéger les relations par clés étrangères RESTRICT, sans suppression en cascade. Une interruption DDL peut laisser un schéma partiel ; conserver une sauvegarde.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="documents_diagnose"><button>Vérifier les relations · lecture seule</button></form><form method="post">'.$hidden.'<input type="hidden" name="action" value="documents_schema"><label>Nom exact de la base<input name="confirm_database" required autocomplete="off"></label><label class="upgrade-confirm"><input type="checkbox" name="confirm" value="yes" required> Sauvegarde et destination vérifiées.</label><button>Créer le schéma 005 · Documents et relations</button></form></section>';
}
function businessRelationSelect(PDO $db,string $key,array $relation,string $selected): string {
    $parent=businessConfig($relation['module']);
    $html='<label>'.MusicbandEscape($relation['label']).'<select name="'.$key.'"><option value="">Non associé</option>';
    foreach ($db->query('SELECT id,'.$parent['required'].' AS label'.($relation['module']==='contacts' && fieldVisibilityVisible('contacts','contact')?',contact':'').' FROM '.$parent['table'].' ORDER BY '.$parent['required'].',id') as $row) $html.='<option value="'.(int)$row['id'].'"'.($relation['module']==='contacts'?' data-client="'.MusicbandEscape((string)($row['label']??'')).'" data-contact="'.MusicbandEscape((string)($row['contact']??'')).'"':'').($selected===(string)$row['id']?' selected':'').'>'.MusicbandEscape((string)($row['label']??'').' (#'.$row['id'].')').'</option>';
    return $html.'</select></label>';
}
function businessRelationNodes(): array {
    $nodes = [
        ... (MusicbandCan('accounting.read')?['accounting'=>['table'=>'accounting_entries','key'=>'id','title'=>'Écritures comptables liées','columns'=>['description'=>'Description','entry_date'=>'Date comptable','amount_cents'=>'Montant (€)','received'=>'Argent reçu']]]:[]),
        'contacts'=>['table'=>'contacts_carnet_adresse','key'=>'id','title'=>'Contacts liés','columns'=>['nom'=>'Nom','contact'=>'Interlocuteur','mail'=>'E-mail','tel'=>'Téléphone']],
        'concerts'=>['table'=>'concerts_evenements','key'=>'id','title'=>'Concerts liés','columns'=>['evenement'=>'Événement','date_concert'=>'Date','adresse'=>'Lieu','statut'=>'Statut']],
        'devis_factures'=>['table'=>'devis_factures_documents','key'=>'id','title'=>'Devis / Factures liés','columns'=>['numero'=>'Référence','type_document'=>'Type','date_document'=>'Date','statut'=>'Statut','montant'=>'Montant']],
        'setlists'=>['table'=>'Musicband_publications','key'=>'publication_key','title'=>'Setlists liées','columns'=>['title'=>'Nom','created_at'=>'Publication']],
    ];
    // Les liens entre modules conservés restent disponibles ; aucun lien vers une fonction exclue.
    return array_filter($nodes, static fn(string $module): bool => MusicbandModuleEnabled($module), ARRAY_FILTER_USE_KEY);
}
function businessRelatedTables(PDO $db,string $module,$id,string $file=''): string {
    $nodes=businessRelationNodes(); if (!isset($nodes[$module])) return '';
    $source=$nodes[$module];
    if (!documentsTableExists($db,$source['table'])) return '';
    $q=$db->prepare('SELECT * FROM '.$source['table'].' WHERE '.$source['key'].'=?'); $q->execute([$id]); $record=$q->fetch();
    if (!$record) return '';
    $byTable=[]; foreach ($nodes as $name=>$node) $byTable[$node['table']]=$name;
    $html=''; $size=businessListSettings()['page_size'];
    foreach (documentsForeignKeys($db) as $edge) {
        if (!isset($byTable[$edge['TABLE_NAME']],$byTable[$edge['REFERENCED_TABLE_NAME']])) continue;
        if (!preg_match('/^[a-z_]+$/D',$edge['COLUMN_NAME']) || !preg_match('/^[a-z_]+$/D',$edge['REFERENCED_COLUMN_NAME'])) continue;
        $child=$byTable[$edge['TABLE_NAME']]; $parent=$byTable[$edge['REFERENCED_TABLE_NAME']];
        if ($child!==$module && $parent!==$module) continue;
        if ($child!=='setlists' && !fieldVisibilityVisible($child,$edge['COLUMN_NAME'])) continue;
        $outgoing=$child===$module; $targetModule=$outgoing?$parent:$child; $target=$nodes[$targetModule];
        $value=$record[$outgoing?$edge['COLUMN_NAME']:$edge['REFERENCED_COLUMN_NAME']]??null;
        if ($value===null || $value==='') continue;
        $column=$outgoing?$edge['REFERENCED_COLUMN_NAME']:$edge['COLUMN_NAME'];
        $where=' FROM '.$target['table'].' WHERE `'.$column.'`=?';
        $q=$db->prepare('SELECT COUNT(*)'.$where); $q->execute([$value]); $total=(int)$q->fetchColumn();
        if (!$total) continue;
        $pageName='rel_'.substr(hash('sha256',$edge['CONSTRAINT_NAME'].($outgoing?'out':'in')),0,12);
        $raw=$_GET[$pageName]??'1';
        if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,5}$/D',$raw)) throw new RuntimeException('Page de relations invalide.');
        $pages=(int)ceil($total/$size); $page=min((int)$raw,$pages);
        $columns=$target['columns'];
        if ($targetModule!=='setlists') $columns=array_filter($columns,fn($key)=>fieldVisibilityVisible($targetModule,$key),ARRAY_FILTER_USE_KEY);
        if ($targetModule!=='setlists') foreach ($columns as $key=>$label) $columns[$key]=fieldDisplayLabel($targetModule,$key,$label);
        $select=array_unique([$target['key'],...array_keys($columns),...($targetModule==='setlists'?['source_file']:($targetModule==='concerts'?['source_json','source_table','date_concert']:[]))]);
        $q=$db->prepare('SELECT '.implode(',',$select).$where.' ORDER BY '.$target['key'].' DESC LIMIT '.$size.' OFFSET '.(($page-1)*$size)); $q->execute([$value]);
        $title=$target['title'];
        $html.='<section class="Musicband-related"><h3>'.MusicbandEscape($title).' <small>('.$total.')</small></h3><div class="Musicband-related-scroll" tabindex="0" role="region" aria-label="'.MusicbandEscape($title).'"><table role="table"><caption>'.MusicbandEscape($title).'</caption><thead role="rowgroup"><tr role="row">';
        foreach ($columns as $label) $html.='<th scope="col">'.MusicbandEscape($label).'</th>';
        $html.='<th scope="col">Fiche</th></tr></thead><tbody role="rowgroup">';
        foreach ($q as $row) {
            $html.='<tr role="row">'; foreach ($columns as $key=>$label) {
                $value=businessDisplayValue($targetModule,$key,$row[$key]??'',$row); preg_match('/\A.{0,180}/us',$value,$match); $short=$match[0]??'';
                $html.='<td role="cell"><span class="Musicband-table-label" aria-hidden="true">'.MusicbandEscape($label).'</span><span class="Musicband-cell-value">'.MusicbandEscape($short===''?'—':$short.(strlen($short)<strlen($value)?'…':'')).'</span></td>';
            }
            $link=$targetModule==='setlists'?(preg_match('/^setlist-[a-f0-9]{16}\.json$/D',$row['source_file']??'')?MusicbandBase().'setlist/?publication='.rawurlencode($row['source_file']):null):MusicbandBase().$targetModule.'.php?id='.(int)$row[$target['key']];
            $html.='<td role="cell"><span class="Musicband-table-label" aria-hidden="true">Fiche</span><span class="Musicband-cell-value">'.($link===null?'Publication indisponible':'<a href="'.MusicbandEscape($link).'">Ouvrir</a>').'</span></td></tr>';
        }
        $html.='</tbody></table></div>';
        if ($pages>1) {
            $html.='<nav aria-label="Pagination '.MusicbandEscape($title).'"><span>Page '.$page.' / '.$pages.'</span> ';
            foreach ([1=>'Première', $page-1=>'Précédente', $page+1=>'Suivante'] as $number=>$label) {
                if ($number<1 || $number>$pages || $number===$page) continue;
                $params=$module==='setlists'?['publication'=>$file]:['id'=>$id];
                foreach ($_GET as $key=>$parameter) if (preg_match('/^rel_[a-f0-9]{12}$/D',$key) && is_string($parameter) && preg_match('/^[1-9][0-9]{0,5}$/D',$parameter)) $params[$key]=$parameter;
                $params[$pageName]=(string)$number;
                $href=MusicbandBase().($module==='setlists'?'setlist/':$module.'.php').'?'.http_build_query($params);
                $html.='<a data-related-page href="'.MusicbandEscape($href).'">'.$label.'</a> ';
            }
            $html.='</nav>';
        }
        $html.='</section>';
    }
    return $html;
}
function businessRelatedPublication(PDO $db,string $file): string {
    if (!preg_match('/^setlist-[a-f0-9]{16}\.json$/D',$file)) throw new RuntimeException('Publication invalide.');
    if (!documentsTableExists($db,'Musicband_publications')) return '';
    $q=$db->prepare('SELECT publication_key FROM Musicband_publications WHERE source_file=?'); $q->execute([$file]); $key=$q->fetchColumn();
    // Studio conserve les autres relations SQL ; le catalogue reste dans la gestion des morceaux.
    return $key===false?'':businessRelatedTables($db,'setlists',$key,$file);
}
function businessRelatedDocuments(PDO $db,string $module,int $id,array $user): string {
    try { $html=businessRelatedTables($db,$module,$id); }
    catch (Throwable $e) { $html='<p class="notice">Relations indisponibles : vérifier MySQL et les contraintes SQL. Aucune association modifiée.</p>'; }
    if ($module==='concerts' && documentsReady($db)) {
        $q=$db->prepare('SELECT title,source_file FROM Musicband_publications WHERE concert_id=? ORDER BY created_at DESC'); $q->execute([$id]);
        $linked=$q->fetchAll();
        if (MusicbandCan('setlist.link',$user)) {
            try { $html.=concertSetlistForm($db,$id,$linked); }
            catch (Throwable $e) { $html.='<p>Choix des setlists indisponible. Réessayez après rechargement.</p>'; }
        }
    }
    return $html;
}
function setlistConcertUpcoming(array $row): bool {
    $settings=businessListSettings();
    $date=businessConcertDate($row['date_concert']??null,$settings,businessDateImported($row));
    return $date!==null&&$date>=(new DateTimeImmutable('today',new DateTimeZone($settings['timezone'])))->format('Y-m-d');
}
function setlistConcertSelect(PDO $db,string $selected): string {
    $html='<label>Concert de cette setlist<select name="concert_id"><option value="">Non associé / dissocier</option>';
    foreach($db->query('SELECT * FROM concerts_evenements ORDER BY evenement,id') as $row){
        $current=$selected===(string)$row['id'];$upcoming=setlistConcertUpcoming($row);
        if(!$upcoming&&!$current)continue;
        $html.='<option value="'.(int)$row['id'].'"'.($current?' selected':'').'>'.MusicbandEscape($row['evenement'].' (#'.$row['id'].')'.(!$upcoming?' · association existante (passée ou sans date exploitable)':'')).'</option>';
    }
    return $html.'</select></label><p>Seuls les concerts d’aujourd’hui et à venir avec une date exploitable peuvent recevoir une nouvelle association. Une association ancienne est conservée tant qu’elle n’est pas modifiée.</p>';
}
function setlistConcertForm(array $item,string $hidden): string {
    if (!MusicbandCan('setlist.link')) return '';
    try {
        $db=MusicbandDatabase(businessSettings());
        if (!documentsReady($db)) return '<p>Association concert disponible après le schéma 005 dans Upgrade.</p>';
        $q=$db->prepare('SELECT concert_id FROM Musicband_publications WHERE source_file=?'); $q->execute([$item['file']]); $old=$q->fetch();
        $selected=isset($old['concert_id'])?(string)$old['concert_id']:'';
        return '<form method="post">'.$hidden.'<input type="hidden" name="action" value="link_concert"><input type="hidden" name="link_version" value="'.hash('sha256',$selected).'">'.setlistConcertSelect($db,$selected).'<button>Enregistrer le concert associé</button></form>';
    } catch (Throwable $e) { return '<p>Association concert indisponible : vérifier MySQL et le schéma 005 dans Upgrade. Les publications restent conservées.</p>'; }
}
function setlistConcertSave(array $item,array $user): string {
    MusicbandRequirePermission('setlist.link',$user);
    $db=MusicbandDatabase(businessSettings());
    if (!documentsReady($db)) throw new RuntimeException('Appliquer le schéma 005 avant association.');
    $concert=businessId($_POST['concert_id']??'',true); $file=$item['file'];
    if (!preg_match('/^setlist-([a-f0-9]{16})\.json$/D',$file,$match)) throw new RuntimeException('Publication invalide.');
    $db->beginTransaction();
    try {
        if ($concert!==null) { $q=$db->prepare('SELECT * FROM concerts_evenements WHERE id=? FOR UPDATE'); $q->execute([$concert]); if (!($concertRow=$q->fetch())) throw new RuntimeException('Concert introuvable.'); }
        $q=$db->prepare('SELECT publication_key,concert_id FROM Musicband_publications WHERE source_file=? FOR UPDATE'); $q->execute([$file]); $old=$q->fetch();
        $previous=isset($old['concert_id'])?(string)$old['concert_id']:'';
        if (!is_string($_POST['link_version']??null) || !hash_equals(hash('sha256',$previous),$_POST['link_version'])) throw new RuntimeException('Association modifiée ailleurs : recharger avant enregistrement.');
        if($concert!==null&&$previous!==(string)$concert&&!setlistConcertUpcoming($concertRow))throw new RuntimeException('Choisir un concert d’aujourd’hui ou à venir avec une date exploitable.');
        if (!$old && $concert!==null) {
            $path=__DIR__.'/setlist/'.$file;
            if (is_link($path)) throw new RuntimeException('Publication non autorisée.');
            $raw=@file_get_contents($path,false,null,0,2000001);
            if ($raw===false || strlen($raw)>2000000) throw new RuntimeException('Publication illisible ou trop volumineuse.');
            $snapshot=json_decode($raw,true,32,JSON_THROW_ON_ERROR); $title=$item['title']??'Setlist';
            if (!is_array($snapshot) || !is_string($title) || strlen($title)>255) throw new RuntimeException('Métadonnées de publication invalides.');
            $target=$snapshot['target_seconds']??null;
            if ($target!==null && (!is_int($target) || $target<0 || $target>4294967295)) throw new RuntimeException('Durée cible invalide.');
            $q=$db->prepare('INSERT INTO Musicband_publications (publication_key,title,source_file,snapshot_json,target_seconds,created_at,concert_id,concert_link_by,concert_link_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),?,?,UTC_TIMESTAMP(6))');
            $q->execute([$match[1],$title,$file,$raw,$target,$concert,$user['login']]);
        } elseif ($old) {
            $q=$db->prepare('UPDATE Musicband_publications SET concert_id=?,concert_link_by=?,concert_link_at=UTC_TIMESTAMP(6) WHERE source_file=?'); $q->execute([$concert,$user['login'],$file]);
        }
        $db->commit(); return 'Association setlist/concert enregistrée en MySQL. Publication et variantes inchangées.';
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function setlistManifest(): array {
    $raw = setlistRaw('setlists.json');
    if ($raw === false) throw new RuntimeException('Index des setlists illisible.');
    $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($manifest['setlists'] ?? null)) throw new RuntimeException('Index des setlists invalide.');
    foreach ($manifest['setlists'] as $item) if (!is_array($item) || !is_string($item['file'] ?? null) || !preg_match('/^setlist-[a-f0-9]{16}\.json$/D', $item['file'])) throw new RuntimeException('Référence de publication invalide.');
    if (count(array_unique(array_column($manifest['setlists'], 'file'))) !== count($manifest['setlists'])) throw new RuntimeException('Index ambigu : publication dupliquée.');
    return $manifest;
}
function setlistLinkedConcert(PDO $db, string $file): ?int {
    $q=$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='Musicband_publications' AND column_name='concert_id'");
    if ((int)$q->fetchColumn()===0) return null;
    $q=$db->prepare('SELECT concert_id FROM Musicband_publications WHERE source_file=?'); $q->execute([$file]);
    $value=$q->fetchColumn(); return $value===false || $value===null ? null : (int)$value;
}
function concertSetlistForm(PDO $db, int $id, array $linked): string {
    $manifest=setlistManifest(); $q=$db->query('SELECT source_file,concert_id FROM Musicband_publications WHERE concert_id IS NOT NULL'); $assigned=[];
    foreach ($q as $row) $assigned[$row['source_file']]=(int)$row['concert_id'];
    $hidden='<input type="hidden" name="csrf" value="'.MusicbandEscape($_SESSION['csrf']).'"><input type="hidden" name="id" value="'.$id.'">';
    $html='';
    foreach ($linked as $row) $html.='<form method="post">'.$hidden.'<input type="hidden" name="action" value="unlink_setlist"><input type="hidden" name="file" value="'.MusicbandEscape($row['source_file']).'"><input type="hidden" name="link_version" value="'.hash('sha256',(string)$id).'"><button>Dissocier '.MusicbandEscape($row['title']).'</button></form>';
    $q=$db->prepare('SELECT * FROM concerts_evenements WHERE id=?');$q->execute([$id]);$concertRow=$q->fetch();
    if(!$concertRow||!setlistConcertUpcoming($concertRow))return $html.'<p>Nouvelle association indisponible : concert passé ou sans date exploitable. Les associations existantes sont conservées.</p>';
    $html.='<form method="post">'.$hidden.'<input type="hidden" name="action" value="link_setlist"><input type="hidden" name="link_version" value="'.hash('sha256','').'"><label>Setlist à associer<select name="file" required><option value="">Choisir une setlist</option>';
    foreach ($manifest['setlists'] as $item) if (!isset($assigned[$item['file']])) $html.='<option value="'.MusicbandEscape($item['file']).'">'.MusicbandEscape($item['title']??$item['file']).'</option>';
    return $html.'</select></label><p>Les setlists déjà liées à un concert ne sont pas proposées. Vous pouvez en faire une copie depuis Gérer les publications.</p><button>Associer la setlist au concert</button></form>';
}
function concertSetlistSave(array $user): int {
    MusicbandRequirePermission('setlist.link',$user);
    $id=businessId($_POST['id']??'',false); $file=$_POST['file']??null;
    if (!is_string($file)) throw new RuntimeException('Choisir une setlist.');
    $manifest=setlistManifest(); $index=array_search($file,array_column($manifest['setlists'],'file'),true);
    if ($index===false) throw new RuntimeException('Setlist absente : recharger la fiche.');
    $db=MusicbandDatabase(businessSettings());
    if (!documentsReady($db)) throw new RuntimeException('Association indisponible.');
    $q=$db->prepare('SELECT id FROM concerts_evenements WHERE id=?'); $q->execute([$id]); if (!$q->fetch()) throw new RuntimeException('Concert introuvable.');
    $unlink=($_POST['action']??'')==='unlink_setlist';
    $previous=setlistLinkedConcert($db,$file);
    if (($unlink && $previous!==$id) || (!$unlink && $previous!==null)) throw new RuntimeException('Association modifiée : recharger la fiche.');
    // Le contrôle de version est refait sous transaction par la fonction commune.
    $_POST['concert_id']=$unlink?'':(string)$id;
    setlistConcertSave($manifest['setlists'][$index],$user);
    return $id;
}
