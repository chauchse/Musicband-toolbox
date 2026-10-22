<?php
/* Musicband SETLIST EXCEL: téléchargement XLSX authentifié de la variante courante.
 * Dépend des préférences privées, de la palette Seb partagée et de SimpleXLSXGen 1.5.15/MIT.
 * POST + CSRF : variante courante et compléments des fiches Morceaux liées, sans écriture.
 */
require_once __DIR__.'/setlist_excel_service.php';
require_once __DIR__.'/setlist_export_style.php';
$user=MusicbandRequireLogin(true);
if($_SERVER['REQUEST_METHOD']==='GET'){
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    echo json_encode(['csrf'=>$_SESSION['csrf']]);exit;
}
if((int)($_SERVER['CONTENT_LENGTH']??0)>2000000)MusicbandFail('Export trop volumineux.',413);
try{
    MusicbandCsrf();$columns=setlistExcelValidate(setlistExcelPreferences($user['login']));session_write_close();
    $data=json_decode((string)($_POST['report']??''),true,32,JSON_THROW_ON_ERROR);$songs=$data['songs']??null;
    if(!is_array($songs)||!array_is_list($songs)||!$songs||count($songs)>1000)throw new RuntimeException('Liste de morceaux invalide.');
    $catalogue=setlistExcelCatalogueRows($data['publication']??null,$songs);
    $library=is_file(__DIR__.'/setlist_xlsx_library.php')?__DIR__.'/setlist_xlsx_library.php':__DIR__.'/vendor/simplexlsxgen/SimpleXLSXGen-1.5.15.php';
    require_once $library;
    // Les textes sont forcés en chaînes : aucune formule, balise ou URL exécutée par Excel.
    $literal=function(string $value): string {
        if(strlen($value)>60000||!preg_match('//u',$value)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value))throw new RuntimeException('Texte Excel invalide.');
        if(strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))>65534)throw new RuntimeException('Texte trop long pour une cellule Excel.');
        $value=htmlspecialchars($value,ENT_QUOTES|ENT_XML1,'UTF-8');
        return '<wraptext><raw>'.str_replace(["\r\n","\r","\n"],' ',$value).'</raw></wraptext>';
    };
    $fields=setlistExcelFields();$rows=[array_map(fn($key)=>'<b>'.$literal($fields[$key]).'</b>',$columns)];
    $palette=setlistGuitarPalette();$previous=null;$textBytes=0;
    foreach($songs as $index=>$song){
        if(!is_array($song)||!is_string($song['title']??null)||trim($song['title'])===''||!is_int($song['duration_seconds']??null)||$song['duration_seconds']<0||!in_array($song['section']??null,['main','encore'],true))throw new RuntimeException('Morceau invalide.');
        // Vérifier les données même lorsque la colonne est masquée dans les préférences.
        foreach(['title','artist','guitar','key','p5','seb_comments'] as $key)if(isset($song[$key])){if(!is_string($song[$key]))throw new RuntimeException('Champ texte invalide.');$literal($song[$key]);}
        if(isset($song['tempo'])&&(!is_int($song['tempo'])||$song['tempo']<0))throw new RuntimeException('Tempo invalide.');
        $details=$catalogue[$song['editor_id']??'']??[];
        $guitar=array_key_exists('guitar',$song)?($song['guitar']??''):($details['guitar']??'');
        $changed=$previous&&$guitar!==''&&$previous!==$guitar;$row=[];
        foreach($columns as $key){
            $sqlKey=$key==='key'?'musical_key':$key;
            if($key==='position')$value=$index+1;
            elseif($key==='section')$value=$literal($song[$key]==='encore'?'Rappel':'Set principal');
            else{
                // Les neuf propriétés courantes de la variante priment sur le catalogue vivant.
                $current=in_array($key,['title','artist','guitar','duration_seconds','tempo','key','energy','p5','seb_comments'],true);
                $raw=$current&&array_key_exists($key,$song)?$song[$key]:($details[$sqlKey]??null);
                if($raw!==null&&!is_scalar($raw))throw new RuntimeException('Valeur de fiche invalide.');
                $type=songFieldSpecs()[$sqlKey]['type']??'text';
                if($raw===null||$raw==='')$value=$literal('');
                elseif($type==='int'){
                    if(!is_int($raw)&&(!is_string($raw)||!preg_match('/^-?[0-9]{1,9}$/D',$raw)))throw new RuntimeException('Entier de fiche invalide.');
                    $value=(int)$raw;
                }
                else $value=$literal(songFieldText($sqlKey,$raw));
            }
            $textBytes+=is_string($value)?strlen($value):0;
            if($textBytes>16000000)throw new RuntimeException('Export Excel trop volumineux.');
            if($key==='guitar')$value='<style bgcolor="#'.setlistExportHex($palette['fill']).'"'.($changed?' border="none none none medium#'.setlistExportHex($palette['change']).'"':'').'>'.$value.'</style>';
            $row[]=$value;
        }
        $rows[]=$row;$previous=$guitar;
    }
    $xlsx=\Shuchkin\SimpleXLSXGen::fromArray($rows,'Setlist')->freezePanes('A2');
    foreach($columns as $index=>$key){
        $type=songFieldSpecs()[$key==='key'?'musical_key':$key]['type']??'text';
        $xlsx->setColWidth($index+1,['position'=>8,'title'=>40,'guitar'=>12,'artist'=>28,'duration_seconds'=>12][$key]??($type==='longtext'?50:22));
    }
    header('Cache-Control: no-store');
    if(!$xlsx->downloadAs('Setlist.xlsx'))throw new RuntimeException('Génération Excel impossible.');
}catch(Throwable $e){MusicbandFail('Export Excel impossible : vérifier les données et la session.',400);}
