<?php
/* Musicband SETLIST MANAGEMENT: owned publication */
/* Gestion authentifiée des publications : création, duplication, renommage et suppression explicites.
 * Dépend de auth.php, business.php et setlist_validation.php pour droits, stockage et verrou métier.
 * Les POST modifient les publications ; les données de concert liées restent gérées par les services métier.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/business.php';
require_once __DIR__.'/setlist_validation.php';
$user = MusicbandRequireLogin();
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) MusicbandFail('Formulaire trop volumineux.', 413);
function setlistScratchCreate(array $user): string {
    MusicbandRequirePermission('setlist.generate',$user);MusicbandRequirePermission('setlist.edit',$user);
    if(!setlistSqlActive())throw new RuntimeException('Activer Setlist SQL 014 avant de créer une setlist.');
    $title=$_POST['title']??'';$minutes=$_POST['target_minutes']??'';
    if(!is_string($title)||trim($title)===''||strlen($title)>160||preg_match('/[\x00-\x1f]/',$title)||!is_string($minutes)||!ctype_digit($minutes)||strlen($minutes)>4||(int)$minutes<1||(int)$minutes>1440)throw new RuntimeException('Nom requis et durée cible de 1 à 1440 minutes.');
    $settings=json_decode(base64_decode('eyJnZW5lcmF0b3IiOnsibm9ybWFsX3RyYW5zaXRpb25fc2Vjb25kcyI6NSwibGlua2VkX3RyYW5zaXRpb25fc2Vjb25kcyI6MSwiZ3VpdGFyX2NoYW5nZV9leHRyYV9zZWNvbmRzIjoxMCwiZW5jb3JlX2JyZWFrX3NlY29uZHMiOjMwfSwicGF1c2Vfc2Vjb25kcyI6W10sInRlbXBvX2V4cG9ydCI6eyJhdXRvQWR2YW5jZSI6dHJ1ZSwibG9vcCI6ZmFsc2UsInNvbmdfZGVmYXVsdHMiOltdfX0=',true),true,32,JSON_THROW_ON_ERROR);
    $g=$settings['generator'];$rules=[];
    foreach(['normal_transition_seconds','linked_transition_seconds','guitar_change_extra_seconds','encore_break_seconds'] as $key)$rules[$key]=variantSeconds($g[$key]??null);
    $rules+=['base_pause_seconds'=>array_sum($settings['pause_seconds']),'presentation_seconds'=>0,'encore_count'=>0,'required'=>[],'opening'=>null,'forbidden_start'=>[],'presentation'=>null,'encore_required'=>[],'sequences'=>[]];
    variantSeconds($rules['base_pause_seconds']);
    $db=MusicbandRbacDb();$db->beginTransaction();
    try{
        $q=$db->query("SELECT document_key FROM setlist_documents WHERE document_key='setlists.json' FOR UPDATE");if(!$q->fetchColumn())throw new RuntimeException('Index SQL absent : vérifier Upgrade.');
        $manifest=setlistManifest();$available=[];$links=[];
        foreach($db->query("SELECT * FROM setlist_songs WHERE TRIM(title)<>'' ORDER BY title,id LIMIT 1001") as $row){
            if(count($available)>=1000)throw new RuntimeException('Catalogue de plus de 1000 morceaux : création refusée pour respecter la capacité du lecteur.');
            $full=setlistSongData($row);$song=setlistPublicSong($full);
            $song=array_replace($song,array_intersect_key($row,array_flip(['title','artist','guitar','p5','seb_comments'])));
            foreach(['duration_seconds','tempo','energy'] as $key)$song[$key]=$row[$key]===null?null:(int)$row[$key];
            $song['key']=$row['musical_key'];$song['editor_id']='sql-'.$row['id'];$song['hidden']=true;
            $song['unavailable_reason']=($full['ready']??null)===false?'Morceau non prêt':(!$song['duration_seconds']||!$song['tempo']||!trim((string)$song['key'])?'Durée, tempo ou tonalité à compléter dans Morceaux':null);
            $available[]=$song;$links[$song['editor_id']]=(int)$row['id'];
        }
        $file='setlist-'.bin2hex(random_bytes(8)).'.json';$target=(int)$minutes*60;$pause=$rules['base_pause_seconds'];
        $variant=['id'=>'manual-'.bin2hex(random_bytes(8)),'name'=>'Composition manuelle','draft'=>true,'songs'=>[],'music_seconds'=>0,'transition_seconds'=>0,'pause_seconds'=>$pause,'total_seconds'=>$pause,'presentation_seconds'=>0,'encore_break_seconds'=>0,'margin_seconds'=>$target-$pause,'guitar_changes'=>0,'transition_estimated'=>false];
        $title=trim($title);$data=['title'=>$title,'status'=>'manual','generated_at'=>gmdate('Y-m-d\TH:i:s\Z'),'target_seconds'=>$target,'encore_count'=>0,'editor'=>$rules,'available_songs'=>$available,'variants'=>[$variant],'tempo_export'=>$settings['tempo_export']];
        setlistSqlMirror($file,$data,$title);setlistSqlPut($file,json_encode($data,JSON_THROW_ON_ERROR));setlistSqlPut('variants:'.$file,'[]');
        $q=$db->prepare('INSERT INTO setlist_song_links(source_file,editor_id,song_id) VALUES (?,?,?)');foreach($links as $editor=>$id)$q->execute([$file,$editor,$id]);
        $manifest['setlists'][]=['file'=>$file,'title'=>$title];$manifest['latest_file']=$file;setlistSqlPut('setlists.json',json_encode($manifest,JSON_THROW_ON_ERROR));
        $db->commit();return $file;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function setlistDuplicate(array &$manifest, int $index): array {
    $item=$manifest['setlists'][$index]; $path=__DIR__.'/setlist/'.$item['file'];
    if (is_link($path)) throw new RuntimeException('Setlist non autorisée.');
    $raw=@file_get_contents($path,false,null,0,2000001);
    if ($raw===false || strlen($raw)>2000000) throw new RuntimeException('Setlist illisible ou trop volumineuse.');
    $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Setlist invalide.');
    // Les associations sont en SQL et ne sont jamais copiées.
    unset($data['concert_id'],$data['concert_link_by'],$data['concert_link_at']);
    $key=bin2hex(random_bytes(8)); $file='setlist-'.$key.'.json';
    $newPath=__DIR__.'/setlist/'.$file; $storage='setlist-variants-'.$key;
    if (file_exists($newPath) || file_exists(MusicbandPrivate().'/'.$storage.'.json')) throw new RuntimeException('Copie impossible : réessayer.');
    $saved=MusicbandRead('setlist-variants-'.substr($item['file'],8,16));
    $handle=@fopen($newPath,'x'); if (!$handle) throw new RuntimeException('Création de la copie impossible.');
    try {
        $copy=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if (fwrite($handle,$copy)!==strlen($copy)) throw new RuntimeException('Écriture de la copie impossible.');
        fclose($handle); $handle=null;
        if ($saved) MusicbandWrite($storage,$saved);
    } catch (Throwable $e) {
        if (is_resource($handle)) fclose($handle);
        @unlink($newPath); @unlink(MusicbandPrivate().'/'.$storage.'.json'); throw $e;
    }
    $title=(string)($item['title']??'Setlist');
    while (strlen($title)>145) $title=(string)preg_replace('/.$/us','',$title);
    $item['file']=$file; $item['title']=$title.' · Copie';
    unset($item['concert_id'],$item['concert_link_by'],$item['concert_link_at']);
    $manifest['setlists'][]=$item;
    return [$newPath,MusicbandPrivate().'/'.$storage.'.json'];
}
$message = (string)($_SESSION['setlists_notice'] ?? ''); unset($_SESSION['setlists_notice']);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            MusicbandCsrf();
            $message = MusicbandLocked(function() {
                $user = MusicbandRequireLogin();
                $permission=['create'=>'setlist.generate','rename'=>'setlist.edit','duplicate'=>'setlist.duplicate','delete'=>'setlist.delete','link_concert'=>'setlist.link'][$_POST['action']??'']??'';
                MusicbandRequirePermission($permission,$user);
                if(($_POST['action']??'')==='create'){$_SESSION['setlist_created_file']=setlistScratchCreate($user);return 'Setlist vide créée en SQL.';}
                $manifest = setlistManifest(); $file = (string)($_POST['file'] ?? '');
                $index = array_search($file, array_column($manifest['setlists'], 'file'), true);
                if ($index === false) throw new RuntimeException('Cette publication n’existe plus. Recharger la page.');
                if (($_POST['action'] ?? '') === 'link_concert') return setlistConcertSave($manifest['setlists'][$index],$user);
                if (($_POST['action']??'')==='rename') setlistRequireUnvalidated($file);
                if (($_POST['action']??'')==='delete') setlistRequireDeletion($file,$user);
                if(setlistSqlActive())return setlistSqlManage($manifest,(int)$index,(string)$_POST['action'],$user);
                $backup = MusicbandPrivate() . '/setlists-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
                if (!@mkdir($backup, 0700) || !@copy(__DIR__ . '/setlist/setlists.json', $backup . '/setlists.json')) throw new RuntimeException('Sauvegarde privée impossible : aucune modification.');
                $action = $_POST['action'] ?? ''; $remove = []; $created = [];
                if ($action === 'rename') {
                    $title = trim((string)($_POST['title'] ?? ''));
                    if ($title === '' || strlen($title) > 160 || preg_match('/[\x00-\x1f]/', $title)) throw new RuntimeException('Nom requis, limité à 160 octets sans caractères de contrôle.');
                    $manifest['setlists'][$index]['title'] = $title;
                } elseif ($action === 'duplicate') {
                    $created=setlistDuplicate($manifest,(int)$index);
                } elseif ($action === 'delete') {
                    if (setlistLinkedConcert(MusicbandDatabase(businessSettings()),$file)!==null) throw new RuntimeException('Cette setlist est associée à un concert : suppression impossible. Vous pouvez la dupliquer.');
                    if (($_POST['confirm'] ?? '') !== 'yes') throw new RuntimeException('Cocher la confirmation de suppression.');
                    $raw = @file_get_contents(__DIR__ . '/setlist/' . $file);
                    if ($raw === false) throw new RuntimeException('Publication illisible : suppression refusée.');
                    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); $remove = [$file]; $pdfs = [];
                    foreach ($data['variants'] ?? [] as $variant) if (is_string($variant['seb_pdf'] ?? null) && preg_match('/^setlist-seb-[a-f0-9]{16}\.pdf$/D', $variant['seb_pdf'])) $pdfs[$variant['seb_pdf']] = true;
                    array_splice($manifest['setlists'], $index, 1);
                    foreach ($manifest['setlists'] as $item) {
                        $other = @file_get_contents(__DIR__ . '/setlist/' . $item['file']);
                        if ($other === false) throw new RuntimeException('Autre publication illisible : suppression refusée pour préserver les PDF partagés.');
                        $other = json_decode($other, true, 32, JSON_THROW_ON_ERROR);
                        foreach ($other['variants'] ?? [] as $variant) unset($pdfs[$variant['seb_pdf'] ?? '']);
                    }
                    foreach (array_keys($pdfs) as $pdf) if (is_file(__DIR__ . '/setlist/' . $pdf)) $remove[] = $pdf;
                    foreach ($remove as $name) if (is_link(__DIR__ . '/setlist/' . $name) || !@copy(__DIR__ . '/setlist/' . $name, $backup . '/' . $name)) throw new RuntimeException('Sauvegarde des fichiers impossible : suppression refusée.');
                    if (($manifest['latest_file'] ?? '') === $file) $manifest['latest_file'] = $manifest['setlists'] ? $manifest['setlists'][array_key_last($manifest['setlists'])]['file'] : null;
                } else throw new RuntimeException('Action inconnue.');
                $path = __DIR__ . '/setlist/setlists.json'; $temp = $path . '.' . bin2hex(random_bytes(8)) . '.upload';
                if (@file_put_contents($temp, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX) === false || !@rename($temp, $path)) { @unlink($temp); foreach ($created as $createdPath) @unlink($createdPath); throw new RuntimeException('Écriture de l’index impossible. Vérifier les droits PHP du dossier setlist.'); }
                foreach ($remove as $name) if (!@unlink(__DIR__ . '/setlist/' . $name)) throw new RuntimeException('Publication retirée de l’index, mais purge des fichiers incomplète. Sauvegarde privée conservée.');
                return $action === 'rename' ? 'Publication renommée.' : ($action === 'duplicate' ? 'Setlist dupliquée avec ses variantes, sans association à un concert.' : 'Publication et fichiers non partagés supprimés. Sauvegarde privée conservée.');
            });
            MusicbandAuditEvent('setlist',MusicbandAuditText($_POST['action']??'management',64),'success',['publication'=>$_POST['file']??'']);
        } catch (Throwable $e) { MusicbandAuditEvent('setlist',MusicbandAuditText($_POST['action']??'management',64),'rejected');$message = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Action impossible. Consulter le journal PHP.'; error_log('Musicband_setlist_management_failed'); }
        if(isset($_SESSION['setlist_created_file'])){$created=$_SESSION['setlist_created_file'];unset($_SESSION['setlist_created_file']);header('Location: '.MusicbandBase().'setlist/?publication='.rawurlencode($created),true,303);exit;}
        $_SESSION['setlists_notice'] = $message; header('Location: ' . MusicbandBase() . 'setlists.php', true, 303); exit;
    }
    $manifest = setlistManifest();
    $content = '<p><a href="' . MusicbandEscape(MusicbandBase()) . 'setlist/">← Retour aux setlists</a></p><p>Renommer ou dupliquer une setlist. Une setlist liée à un concert ne peut pas être supprimée. Les autres peuvent être supprimées avec leurs PDF non partagés. Une copie privée est conservée pour restauration. Éviter ces actions pendant une publication SFTP.</p>';
    if(setlistSqlActive()&&MusicbandCan('setlist.generate',$user)&&MusicbandCan('setlist.edit',$user))$content.='<details id="create-setlist"><summary>Nouvelle setlist · partir de zéro</summary><form method="post"><input type="hidden" name="csrf" value="'.MusicbandEscape($_SESSION['csrf']).'"><input type="hidden" name="action" value="create"><label>Nom de la setlist<input name="title" maxlength="160" required></label><label>Durée cible (minutes)<input type="number" name="target_minutes" min="1" max="1440" required></label><p>Créer une composition vide et ajouter les morceaux du catalogue SQL depuis Édition et Tous morceaux. Les durées de transition et pauses utilisent la configuration du site. Aucun titre, ouverture ou enchaînement n’est imposé automatiquement ; une composition vide ne peut pas être validée.</p><button>Créer la setlist vide</button></form></details>';
    if ($message !== '') $content .= '<p class="notice" role="status">' . MusicbandEscape($message) . '</p>';
    if (!$manifest['setlists']) $content .= '<p>Aucune setlist publiée.</p>';
    foreach (array_reverse($manifest['setlists']) as $item) {
        $hidden = '<input type="hidden" name="csrf" value="' . MusicbandEscape($_SESSION['csrf']) . '"><input type="hidden" name="file" value="' . MusicbandEscape($item['file']) . '">';
        try { setlistRequireUnvalidated($item['file']);$frozen=false; } catch (Throwable $e) { $frozen=true; }
        try { setlistRequireDeletion($item['file'],$user);$canDelete=true; } catch (Throwable $e) { $canDelete=false; }
        $title = MusicbandEscape((string)($item['title'] ?? 'Setlist'));
        $displayTitle=MusicbandEscape(MusicbandDisplayGeneratedLabel((string)($item['title']??'Setlist')));
        try { $linked=setlistLinkedConcert(MusicbandDatabase(businessSettings()),$item['file'])!==null; $deleteUnavailable=false; }
        catch (Throwable $e) { $linked=false; $deleteUnavailable=true; }
        $deleteForm=($linked || $deleteUnavailable) ? '<p>'.($linked?'Setlist liée à un concert : suppression impossible.':'Suppression indisponible : impossible de vérifier les associations.').'</p>' : '<form method="post">'.$hidden.'<input type="hidden" name="action" value="delete"><label><input type="checkbox" name="confirm" value="yes" required> Confirmer la suppression de cette publication</label><button>Supprimer cette publication</button></form>';
        $content .= '<section><p><a href="'.MusicbandEscape(MusicbandBase()).'setlist/?publication='.rawurlencode($item['file']).'">Ouvrir la setlist</a></p><h2>' . $displayTitle . (($manifest['latest_file'] ?? '') === $item['file'] ? ' · Dernière' : '') . '</h2><p>' . MusicbandEscape($item['file']) . '</p>';
        if (!$frozen && MusicbandCan('setlist.edit',$user)) $content.='<form method="post">'.$hidden.'<input type="hidden" name="action" value="rename"><label>Nom de la publication<input name="title" required maxlength="160" value="'.$title.'"></label><button>Renommer</button></form>';
        if (MusicbandCan('setlist.duplicate',$user)) $content.='<form method="post">'.$hidden.'<input type="hidden" name="action" value="duplicate"><button>Dupliquer sans concert associé</button></form>';
        if ($canDelete) $content.=$deleteForm;
        if ($frozen) $content.='<p>Édition bloquée : setlist validée ou vérification MySQL indisponible. Un administrateur peut supprimer une publication validée non liée à un concert après confirmation ; la vérification MySQL reste obligatoire. L’instantané de validation est conservé. La duplication permet de repartir sur une nouvelle publication.</p>';
        $content.='</section>';
        $content .= setlistConcertForm($item,$hidden);
        try { $db=MusicbandDatabase(businessSettings()); fieldVisibilityUse($db); $content .= businessRelatedPublication($db,$item['file']); }
        catch (Throwable $e) { $content .= '<p class="notice">Relations SQL indisponibles ; publication conservée.</p>'; }
    }
    MusicbandScreen('Gérer les setlists', $content);
} catch (Throwable $e) { MusicbandFail('Gestion des setlists indisponible. Vérifier le manifeste et les droits PHP.'); }
