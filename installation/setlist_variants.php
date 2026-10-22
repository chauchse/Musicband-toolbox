<?php
/* Musicband SETLIST VARIANTS: lecture, sauvegarde et validation des variantes authentifiées.
 * Dépend de auth.php, setlist_validation.php et setlist_calculations.php.
 * Remplace la variante courante en SQL ou ajoute une sauvegarde en mode historique ; trace le résultat des POST dans l’audit.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__.'/setlist_validation.php';
require_once __DIR__.'/setlist_calculations.php';
$user = MusicbandRequireLogin(true);
const SETLIST_VARIANTS_MAX_BYTES = 16000000;
const SETLIST_VARIANTS_MAX_COUNT = 100;
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD']==='POST' && !MusicbandCan(($_POST['action']??'save')==='validate'?'setlist.validate':'setlist.edit',$user)) { http_response_code(403);echo json_encode(['error'=>'Modification de setlist non autorisée.']);exit; }

/** Termine la réponse JSON et trace le résultat applicatif, distinct du simple accès HTTP observé. */
function variantReply(array $data, int $status = 200): void {
    if(($_SERVER['REQUEST_METHOD']??'')==='POST')MusicbandAuditEvent('setlist',MusicbandAuditText($_POST['action']??'save',64),isset($data['error'])||$status>=400?'rejected':'success',['publication'=>$_POST['file']??'']);
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}
function variantPublication(string $file): array {
    if (!preg_match('/^setlist-[a-f0-9]{16}\.json$/D', $file)) throw new RuntimeException('Publication invalide.');
    $manifest = json_decode(setlistRaw('setlists.json'), true, 64, JSON_THROW_ON_ERROR);
    if (count(array_filter($manifest['setlists'] ?? [], fn($item) => ($item['file'] ?? '') === $file)) !== 1) throw new RuntimeException('Publication absente : recharger la page.');
    $path = __DIR__ . '/setlist/' . $file;
    if (!setlistSqlActive() && is_link($path)) throw new RuntimeException('Publication invalide.');
    $raw = setlistRaw($file);
    if ($raw === false || strlen($raw) > SETLIST_VARIANTS_MAX_BYTES) throw new RuntimeException('Publication illisible ou trop volumineuse.');
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data['editor'] ?? null) || !is_array($data['available_songs'] ?? null) || !is_array($data['variants'] ?? null)) throw new RuntimeException('Régénérer cette publication pour activer la sauvegarde.');
    return $data;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET', 'POST'], true)) variantReply(['error' => 'Méthode non autorisée.'], 405);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > SETLIST_VARIANTS_MAX_BYTES) variantReply(['error' => 'Requête trop volumineuse.'], 413);
    if ($method === 'POST') MusicbandCsrf();
    if ($method==='GET' && !isset($_GET['file'])) variantReply(['permissions'=>['edit'=>MusicbandCan('setlist.edit'),'validate'=>MusicbandCan('setlist.validate')]]);
    $file = ($method === 'POST' ? $_POST : $_GET)['file'] ?? null;
    if (!is_string($file)) throw new RuntimeException('Publication requise.');
    // Même verrou que la gestion des publications : deux sauvegardes concurrentes
    // relisent le dernier état avant ajout, sans écraser une variante existante.
    $result = MusicbandLocked(function() use ($method, $file) {
        $user = MusicbandRequireLogin(true);
        $data = variantPublication($file);
        $storage = 'setlist-variants-' . substr($file, 8, 16);
        $saved = setlistSaved($file);
        $state=setlistValidationState($file,$data,$saved);
        if ($method === 'GET') return ['csrf'=>$_SESSION['csrf'],'variants'=>$saved,'state'=>$state,'permissions'=>['edit'=>MusicbandCan('setlist.edit',$user)&&!$state['locked'],'validate'=>MusicbandCan('setlist.validate',$user)],'sql'=>setlistSqlActive()];
        if (($_POST['action']??'save')==='validate') return setlistValidate($file,$data,$saved,$state,$user);
        if (($_POST['action']??'save')!=='save') throw new RuntimeException('Action inconnue.');
        setlistRequireUnvalidated($file);
        MusicbandRequirePermission('setlist.edit',$user);
        if (!setlistSqlActive() && count($saved) >= SETLIST_VARIANTS_MAX_COUNT) throw new RuntimeException('Limite de 100 variantes enregistrées atteinte pour cette publication. Aucune variante supprimée.');
        $raw = $_POST['variant'] ?? null;
        if (!is_string($raw) || strlen($raw) > SETLIST_VARIANTS_MAX_BYTES) throw new RuntimeException('Variante invalide ou trop volumineuse.');
        $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_string($input['source_variant_id'] ?? null)) throw new RuntimeException('Variante source requise.');
        $matches = array_values(array_filter(array_merge($data['variants'], $saved), fn($v) => ($v['id'] ?? null) === $input['source_variant_id']));
        if (count($matches) !== 1) throw new RuntimeException('Variante source absente ou ambiguë. Recharger la page.');
        $variant = variantBuild($data, $matches[0], $input, $user['login']);
        if(setlistSqlActive()){
            if(!is_string($_POST['sql_version']??null)||!hash_equals($state['version'],$_POST['sql_version']))throw new RuntimeException('Publication modifiée : recharger avant enregistrement.');
            $source=$matches[0];$variant['id']=$source['id'];$variant['name']=$source['name']??$source['id'];unset($variant['source_variant_id']);$replaced=false;
            foreach($data['variants'] as &$entry)if($entry['id']===$source['id']){$entry=$variant;$replaced=true;}unset($entry);
            foreach($saved as &$entry)if($entry['id']===$source['id']){$entry=$variant;$replaced=true;}unset($entry);
            if(!$replaced)throw new RuntimeException('Variante absente.');
            $db=MusicbandRbacDb();$db->beginTransaction();
            try{setlistSqlPut($file,json_encode($data,JSON_THROW_ON_ERROR));setlistSqlPut('variants:'.$file,json_encode($saved,JSON_THROW_ON_ERROR));$q=$db->prepare('SELECT title FROM Musicband_publications WHERE source_file=?');$q->execute([$file]);setlistSqlMirror($file,$data,(string)$q->fetchColumn(),$saved);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            return ['variant'=>$variant,'replace'=>true,'state'=>setlistValidationState($file,$data,$saved)];
        }
        $saved[] = $variant;
        if (strlen(json_encode(['csrf' => $_SESSION['csrf'], 'variants' => $saved], JSON_THROW_ON_ERROR)) > SETLIST_VARIANTS_MAX_BYTES) throw new RuntimeException('Stockage des variantes plein pour cette publication. Aucune variante supprimée.');
        MusicbandWrite($storage, $saved);
        $state['version']=hash('sha256',$state['publicationHash']."\n".json_encode($saved,JSON_THROW_ON_ERROR));
        return ['variant'=>$variant,'state'=>$state];
    });
    variantReply($result, $method === 'POST' ? 201 : 200);
} catch (Throwable $e) {
    error_log('Musicband_setlist_variant_failed');
    variantReply(['error' => $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Sauvegarde indisponible : vérifier les données et le stockage privé.'], 400);
}
