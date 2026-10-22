<?php
/* Musicband SETLIST VALIDATION: instantané MySQL immuable et verrou de publication. */
/* Fige une variante choisie dans un instantané SQL et interdit les éditions ultérieures.
 * Dépend du stockage Setlist, de RBAC et des services de notifications.
 * Contrôle version et confirmation ; la validation métier est distincte d’un test logiciel.
 */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) { http_response_code(404);exit; }
require_once __DIR__.'/notifications.php';
require_once __DIR__.'/setlist_storage.php';
require_once __DIR__.'/advanced_notifications_service.php';
/** Compare les empreintes de publication/variantes à l’instantané validé ; drift signale un écart, pas une dévalidation automatique. */
function setlistValidationState(string $file,array $data,array $saved): array {
    $db=MusicbandRbacDb();$available=MusicbandFeatureTable($db,'setlist_validations');$row=null;
    if($available){$q=$db->prepare('SELECT variant_key,validated_by,validated_at,publication_sha256,variants_sha256 FROM setlist_validations WHERE source_file=?');$q->execute([$file]);$row=$q->fetch()?:null;}
    $raw=setlistRaw($file);if($raw===false)throw new RuntimeException('Publication illisible.');
    $drift=$row!==null && (!hash_equals($row['publication_sha256'],hash('sha256',$raw))||!hash_equals($row['variants_sha256'],hash('sha256',json_encode($saved,JSON_THROW_ON_ERROR))));
    if($row)unset($row['publication_sha256'],$row['variants_sha256']);
    return ['available'=>$available,'locked'=>$row!==null,'drift'=>$drift,'validation'=>$row,'publicationHash'=>hash('sha256',$raw),'version'=>hash('sha256',hash('sha256',$raw)."\n".json_encode($saved,JSON_THROW_ON_ERROR))];
}
function setlistRequireUnvalidated(string $file): void {
    $db=MusicbandRbacDb();if(!MusicbandFeatureTable($db,'setlist_validations'))return;
    $q=$db->prepare('SELECT 1 FROM setlist_validations WHERE source_file=?');$q->execute([$file]);if($q->fetchColumn())throw new RuntimeException('Setlist validée et figée. Dupliquer pour préparer une nouvelle version.');
}
function setlistRequireDeletion(string $file,array $user): void {
    MusicbandRequirePermission('setlist.delete',$user);
    $db=MusicbandRbacDb();if(!MusicbandFeatureTable($db,'setlist_validations'))return;
    $q=$db->prepare('SELECT 1 FROM setlist_validations WHERE source_file=?');$q->execute([$file]);
    if($q->fetchColumn()&&!MusicbandRequireLogin()['admin'])throw new RuntimeException('Seul un administrateur peut supprimer une setlist validée.');
}
/** Fige la variante choisie après contrôle de droit, confirmation et version ; notifie après validation de la transaction SQL. */
function setlistValidate(string $file,array $data,array $saved,array $state,array $user): array {
    MusicbandRequirePermission('setlist.validate',$user);
    if(!$state['available'])throw new RuntimeException('Préparer le schéma 011 dans Upgrade.');
    if($state['locked'])return ['state'=>$state];
    if(($_POST['confirm']??'')!=='yes'||!is_string($_POST['validation_version']??null)||!hash_equals($state['version'],$_POST['validation_version']))throw new RuntimeException('Confirmer une publication à jour, sans modification non enregistrée.');
    $id=$_POST['variant_id']??null;$matches=array_values(array_filter(array_merge($data['variants'],$saved),fn($v)=>($v['id']??null)===$id));
    if(count($matches)!==1)throw new RuntimeException('Variante absente ou ambiguë.');
    $variant=$matches[0];if(empty($variant['songs']))throw new RuntimeException('Ajouter et enregistrer au moins un morceau avant validation.');$raw=setlistRaw($file);if($raw===false)throw new RuntimeException('Publication illisible.');
    $db=MusicbandRbacDb();$db->beginTransaction();
    try{$q=$db->prepare('INSERT INTO setlist_validations(source_file,variant_key,snapshot_json,publication_sha256,variants_sha256,validated_by,validated_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))');$q->execute([$file,$id,json_encode(['publication'=>$data,'variant'=>$variant],JSON_THROW_ON_ERROR),hash('sha256',$raw),hash('sha256',json_encode($saved,JSON_THROW_ON_ERROR)),$user['login']]);
        $db->commit();
        if (function_exists('advancedNotificationDispatchEvent')) {
            advancedNotificationDispatchEvent('setlist.validate', 'setlist', $file, [], ['publication' => $file, 'variant_id' => $id], $user['login']);
        }
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return ['state'=>setlistValidationState($file,$data,$saved)];
}
