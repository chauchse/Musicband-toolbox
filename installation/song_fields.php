<?php
/* Musicband SONG FIELDS: mapping typé commun à Sheets, SQL et formulaires. */
/* Catalogue typé des champs Morceaux, conversions de saisie et correspondances historiques.
 * Utilisé par les formulaires métier et setlist_storage.php ; PDO pour les colonnes SQL.
 * Centralise unités, booléens et noms afin que listes, éditions et imports partagent les mêmes conversions.
 */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) { http_response_code(404);exit; }
function songFieldSpecs(): array {
    static $spec=null;
    if($spec===null)$spec=json_decode(base64_decode('eyJhcnRpc3QiOnsia2V5IjoiYXJ0aXN0IiwiaGVhZGVyIjoiQXJ0aXN0ZSIsImNvbHVtbiI6MSwidHlwZSI6InRleHQiLCJncm91cCI6MSwibGFiZWwiOiJBcnRpc3RlIn0sInRpdGxlIjp7ImtleSI6InRpdGxlIiwiaGVhZGVyIjoiTW9yY2VhdSIsImNvbHVtbiI6MiwidHlwZSI6InRleHQiLCJncm91cCI6MSwibGFiZWwiOiJNb3JjZWF1In0sImVuZXJneSI6eyJrZXkiOiJlbmVyZ3kiLCJoZWFkZXIiOiJEeW5hbWlxdWUiLCJjb2x1bW4iOjMsInR5cGUiOiJpbnQiLCJncm91cCI6MSwibGFiZWwiOiJEeW5hbWlxdWUifSwiYWRkZWRfZGF0ZSI6eyJrZXkiOiJhZGRlZF9kYXRlIiwiaGVhZGVyIjoiRGF0ZSBkJ2Fqb3V0IiwiY29sdW1uIjo0LCJ0eXBlIjoiZGF0ZSIsImdyb3VwIjoxLCJsYWJlbCI6IkRhdGUgZCdham91dCJ9LCJ0ZW1wbyI6eyJrZXkiOiJ0ZW1wbyIsImhlYWRlciI6IlRlbXBvIiwiY29sdW1uIjo1LCJ0eXBlIjoiaW50IiwiZ3JvdXAiOjEsImxhYmVsIjoiVGVtcG8ifSwibXVzaWNhbF9rZXkiOnsia2V5Ijoia2V5IiwiaGVhZGVyIjoiVG9uYWxpdFx1MDBlOSIsImNvbHVtbiI6NiwidHlwZSI6InRleHQiLCJncm91cCI6MSwibGFiZWwiOiJUb25hbGl0XHUwMGU5In0sImR1cmF0aW9uX3NlY29uZHMiOnsia2V5IjoiZHVyYXRpb25fc2Vjb25kcyIsImhlYWRlciI6IkR1clx1MDBlOWUiLCJjb2x1bW4iOjcsInR5cGUiOiJkdXJhdGlvbiIsImdyb3VwIjoxLCJsYWJlbCI6IkR1clx1MDBlOWUifSwicmVoZWFyc2FsX3Rlc3RlZCI6eyJrZXkiOiJyZWhlYXJzYWxfdGVzdGVkIiwiaGVhZGVyIjoiVGVzdFx1MDBlOSBlbiByXHUwMGU5cGV0ZSIsImNvbHVtbiI6OSwidHlwZSI6ImJvb2wiLCJncm91cCI6MiwibGFiZWwiOiJUZXN0XHUwMGU5IGVuIHJcdTAwZTlwXHUwMGU4dGUifSwiY29uY2VydF9yZWFkeSI6eyJrZXkiOiJyZWFkeSIsImhlYWRlciI6IlByXHUwMGVhdCBwb3VyIGNvbmNlcnQiLCJjb2x1bW4iOjEwLCJ0eXBlIjoiYm9vbCIsImdyb3VwIjoyLCJsYWJlbCI6IlByXHUwMGVhdCBwb3VyIGNvbmNlcnQifSwibmV4dF9yZWhlYXJzYWxfd29yayI6eyJrZXkiOiJuZXh0X3JlaGVhcnNhbF93b3JrIiwiaGVhZGVyIjoiQSB0cmF2YWlsbGVyIHBvdXIgbGEgcHJvY2hhaW5lIHJcdTAwZTlwXHUwMGU4dGUiLCJjb2x1bW4iOjExLCJ0eXBlIjoiYm9vbCIsImdyb3VwIjoyLCJsYWJlbCI6Ilx1MDBjMCB0cmF2YWlsbGVyIHBvdXIgbGEgcHJvY2hhaW5lIHJcdTAwZTlwXHUwMGU4dGUifSwiY2F0YWxvZ3VlX29yZGVyIjp7ImtleSI6Im9yZGVyIiwiaGVhZGVyIjoiT3JkcmUiLCJjb2x1bW4iOjEyLCJ0eXBlIjoiaW50IiwiZ3JvdXAiOjIsImxhYmVsIjoiT3JkcmUifSwiY29tbW9uX2NvbW1lbnRzIjp7ImtleSI6ImNvbW1vbl9jb21tZW50cyIsImhlYWRlciI6IkNvbW1lbnRhaXJlcyBjb21tdW5zIiwiY29sdW1uIjoyMSwidHlwZSI6Imxvbmd0ZXh0IiwiZ3JvdXAiOjgsImxhYmVsIjoiQ29tbWVudGFpcmVzIGNvbW11bnMifSwiZ3VpdGFyIjp7ImtleSI6Imd1aXRhciIsImhlYWRlciI6Ikd1aXRhcmUiLCJjb2x1bW4iOjI1LCJ0eXBlIjoiZ3VpdGFyIiwiZ3JvdXAiOjMsImxhYmVsIjoiR3VpdGFyZSJ9LCJjYXBvIjp7ImtleSI6ImNhcG8iLCJoZWFkZXIiOiJDYXBvIiwiY29sdW1uIjoyNiwidHlwZSI6ImludCIsImdyb3VwIjozLCJsYWJlbCI6IkNhcG8ifSwiam8iOnsia2V5Ijoiam8iLCJoZWFkZXIiOiJKbyIsImNvbHVtbiI6MjcsInR5cGUiOiJ0ZXh0IiwiZ3JvdXAiOjMsImxhYmVsIjoiSm8ifSwicGVudGF0b25pY19maXJzdCI6eyJrZXkiOiJwZW50YXRvbmljX2ZpcnN0IiwiaGVhZGVyIjoiUGVudGEgMWVyIHNjaFx1MDBlOW1hIiwiY29sdW1uIjoyOCwidHlwZSI6InRleHQiLCJncm91cCI6MywibGFiZWwiOiJQZW50YSAxZXIgc2NoXHUwMGU5bWEifSwicGVudGF0b25pY19mb3VydGgiOnsia2V5IjoicGVudGF0b25pY19mb3VydGgiLCJoZWFkZXIiOiJQZW50YSA0XHUwMGU4bWUgc2NoXHUwMGU5bWEiLCJjb2x1bW4iOjI5LCJ0eXBlIjoidGV4dCIsImdyb3VwIjozLCJsYWJlbCI6IlBlbnRhIDRcdTAwZThtZSBzY2hcdTAwZTltYSJ9LCJzZWJfYmFja2luZ192b2NhbHMiOnsia2V5Ijoic2ViX2JhY2tpbmdfdm9jYWxzIiwiaGVhZGVyIjoiQ2hvZXVycyBzZWIiLCJjb2x1bW4iOjMxLCJ0eXBlIjoiYm9vbCIsImdyb3VwIjo1LCJsYWJlbCI6IkNoXHUwMTUzdXJzIFNlYiJ9LCJwNSI6eyJrZXkiOiJwNSIsImhlYWRlciI6IlA1IiwiY29sdW1uIjozMiwidHlwZSI6InRleHQiLCJncm91cCI6NCwibGFiZWwiOiJQYXRjaCJ9LCJvcmdhbiI6eyJrZXkiOiJvcmdhbiIsImhlYWRlciI6Ik9yZ3VlIiwiY29sdW1uIjozMywidHlwZSI6ImJvb2wiLCJncm91cCI6NCwibGFiZWwiOiJPcmd1ZSJ9LCJhMSI6eyJrZXkiOiJhMSIsImhlYWRlciI6IkExIiwiY29sdW1uIjozNCwidHlwZSI6InRleHQiLCJncm91cCI6NCwibGFiZWwiOiJBMSJ9LCJyZXZpZXdfbmVlZGVkIjp7ImtleSI6InJldmlld19uZWVkZWQiLCJoZWFkZXIiOiJSZXZvaXIiLCJjb2x1bW4iOjM1LCJ0eXBlIjoiYm9vbCIsImdyb3VwIjoyLCJsYWJlbCI6IlJldm9pciJ9LCJzZWJfY29tbWVudHMiOnsia2V5Ijoic2ViX2NvbW1lbnRzIiwiaGVhZGVyIjoiQ29tbWVudGFpcmVzIFNlYiIsImNvbHVtbiI6MzYsInR5cGUiOiJsb25ndGV4dCIsImdyb3VwIjo4LCJsYWJlbCI6IkNvbW1lbnRhaXJlcyJ9LCJqZXJvbWVfY29tbWVudHMiOnsia2V5IjoiamVyb21lX2NvbW1lbnRzIiwiaGVhZGVyIjoiQ29tbWVudGFpcmVzIEpcdTAwZTlyb21lIiwiY29sdW1uIjozOCwidHlwZSI6Imxvbmd0ZXh0IiwiZ3JvdXAiOjgsImxhYmVsIjoiQ29tbWVudGFpcmVzIEpcdTAwZTlyXHUwMGY0bWUifSwiamVyb21lX2JhY2tpbmdfdm9jYWxzIjp7ImtleSI6Implcm9tZV9iYWNraW5nX3ZvY2FscyIsImhlYWRlciI6IkNob2V1cnMgSlx1MDBlOXJvbWUiLCJjb2x1bW4iOjQyLCJ0eXBlIjoidGV4dCIsImdyb3VwIjo1LCJsYWJlbCI6IkNoXHUwMTUzdXJzIEpcdTAwZTlyXHUwMGY0bWUifSwibWF1ZF9jb21tZW50cyI6eyJrZXkiOiJtYXVkX2NvbW1lbnRzIiwiaGVhZGVyIjoiQ29tbWVudGFpcmUgTWF1ZCIsImNvbHVtbiI6NDQsInR5cGUiOiJsb25ndGV4dCIsImdyb3VwIjo4LCJsYWJlbCI6IkNvbW1lbnRhaXJlIE1hdWQifSwiYmFzcyI6eyJrZXkiOiJiYXNzIiwiaGVhZGVyIjoiQmFzc2UiLCJjb2x1bW4iOjQ1LCJ0eXBlIjoidGV4dCIsImdyb3VwIjo2LCJsYWJlbCI6IkJhc3NlIn0sImVsb2RfY29tbWVudHMiOnsia2V5IjoiZWxvZF9jb21tZW50cyIsImhlYWRlciI6IkNvbW1lbnRhaXJlIEVsb2QiLCJjb2x1bW4iOjQ3LCJ0eXBlIjoibG9uZ3RleHQiLCJncm91cCI6OCwibGFiZWwiOiJDb21tZW50YWlyZSBFbG9kIn0sImxpbmtzX3VybCI6eyJrZXkiOiJsaW5rc191cmwiLCJoZWFkZXIiOiJMaWVucyIsImNvbHVtbiI6NDgsInR5cGUiOiJ1cmwiLCJncm91cCI6OCwibGFiZWwiOiJMaWVucyJ9LCJwcmlvcml0eSI6eyJrZXkiOiJwcmlvcml0eSIsImhlYWRlciI6IlByaW9yaXRcdTAwZTkiLCJjb2x1bW4iOjQ5LCJ0eXBlIjoiaW50IiwiZ3JvdXAiOjIsImxhYmVsIjoiUHJpb3JpdFx1MDBlOSJ9LCJzb3VuZGNoZWNrIjp7ImtleSI6InNvdW5kY2hlY2siLCJoZWFkZXIiOiJCYWxhbmNlIiwiY29sdW1uIjo1MSwidHlwZSI6ImJvb2wiLCJncm91cCI6NywibGFiZWwiOiJCYWxhbmNlIn0sInNvdW5kY2hlY2tfb3JkZXIiOnsia2V5Ijoic291bmRjaGVja19vcmRlciIsImhlYWRlciI6Ik9yZHJlIEJhbGFuY2UiLCJjb2x1bW4iOjUyLCJ0eXBlIjoiaW50IiwiZ3JvdXAiOjcsImxhYmVsIjoiT3JkcmUgQmFsYW5jZSJ9LCJzb3VuZGNoZWNrX2NvbW1lbnRzIjp7ImtleSI6InNvdW5kY2hlY2tfY29tbWVudHMiLCJoZWFkZXIiOiJDb21tZW50YWlyZSBiYWxhbmNlIiwiY29sdW1uIjo1MywidHlwZSI6Imxvbmd0ZXh0IiwiZ3JvdXAiOjcsImxhYmVsIjoiQ29tbWVudGFpcmUgYmFsYW5jZSJ9LCJ0YWJzX3VybCI6eyJrZXkiOiJ0YWJzX3VybCIsImhlYWRlciI6IlRhYnMiLCJjb2x1bW4iOjU1LCJ0eXBlIjoidXJsIiwiZ3JvdXAiOjgsImxhYmVsIjoiVGFicyJ9fQ==',true),true,32,JSON_THROW_ON_ERROR);
    return $spec;
}
function songFieldsReady(): bool {
    static $ready=null;
    if($ready===null){$q=MusicbandRbacDb()->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='setlist_songs'");$ready=!array_diff(array_keys(songFieldSpecs()),$q->fetchAll(PDO::FETCH_COLUMN));}
    return $ready;
}
function songFieldLabels(): array {
    $basic=['title','artist','duration_seconds','tempo','guitar','musical_key','energy','p5','seb_comments'];$out=[];
    foreach(songFieldSpecs() as $key=>$spec)if(songFieldsReady()||in_array($key,$basic,true))$out[$key]=$spec['label'];
    return $out;
}
function songSourceField(string $name): ?string {
    foreach(songFieldSpecs() as $key=>$spec)if(str_starts_with($name,'Colonne '.$spec['column'].' · ')&&trim(substr($name,strlen('Colonne '.$spec['column'].' · ')))===$spec['header']&&array_key_exists($key,songFieldLabels()))return $key;
    return null;
}
function songLegacyFieldKey(string $key): ?string {
    $spec=songFieldSpecs()[$key]??null;
    return $spec?'source:'.hash('sha256','Colonne '.$spec['column'].' · '.$spec['header']):null;
}
function songDurationLabel($value): string { return $value===null||$value===''?'':intdiv((int)$value,60).':'.str_pad((string)((int)$value%60),2,'0',STR_PAD_LEFT); }
function songParseValue(array $spec,string $value,bool $sheet=false) {
    $value=trim($value);$kind=$spec['type'];
    if(strlen($value)>($kind==='longtext'?60000:6000)||!preg_match('//u',$value)||str_contains($value,"\0"))throw new RuntimeException('Texte invalide ou trop long.');
    if($value==='')return in_array($kind,['text','longtext','url','guitar'],true)?'':null;
    if($kind==='bool'){
        $v=strtolower($value);
        if(in_array($v,['oui','yes','y','true','1','x'],true))return true;
        if(in_array($v,['non','no','n','false','0'],true))return false;
        throw new RuntimeException('Booléen attendu : Oui/Non, Y/N, X, TRUE/FALSE ou vide.');
    }
    if($kind==='duration'){
        if(!$sheet&&ctype_digit($value))$value=(int)$value;
        elseif(preg_match('/^(\d+):([0-5]\d)(?::([0-5]\d))?$/D',$value,$m))$value=isset($m[3])?(int)$m[1]*3600+(int)$m[2]*60+(int)$m[3]:(int)$m[1]*60+(int)$m[2];
        else throw new RuntimeException('Durée attendue M:SS ou H:MM:SS.');
        if($value<1||$value>86400)throw new RuntimeException('Durée hors limites (1 à 86400 secondes).');return $value;
    }
    if($kind==='int'){
        if(!preg_match('/^-?[0-9]{1,9}$/D',$value))throw new RuntimeException('Entier attendu.');$number=(int)$value;
        if($spec['key']==='tempo'&&($number<1||$number>86400)||$spec['key']==='energy'&&($number<0||$number>86400))throw new RuntimeException('Tempo ou dynamique hors limites.');return $number;
    }
    if($kind==='date'){
        $format=$sheet&&str_contains($value,'/')?'!d/m/Y':'!Y-m-d';
        $date=DateTimeImmutable::createFromFormat($format,$value);$errors=DateTimeImmutable::getLastErrors();
        if(!$date||($errors&&($errors['warning_count']||$errors['error_count']))||$date->format(substr($format,1))!==$value)throw new RuntimeException('Date invalide : jour/mois/année dans Sheets ou AAAA-MM-JJ.');return $date->format('Y-m-d');
    }
    if($kind==='guitar'&&!in_array($value,['H','S'],true))throw new RuntimeException('Guitare : H, S ou vide.');
    if($kind==='url'&&(!filter_var($value,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($value,PHP_URL_SCHEME)),['http','https'],true)))throw new RuntimeException('Une URL HTTP(S) complète est requise.');
    return $value;
}
function songSqlValues(array $song): array {
    $values=[];foreach(songFieldSpecs() as $sql=>$spec){$key=$spec['key'];$value=$song[$key]??null;if($value!==null&&!is_scalar($value))throw new RuntimeException('Propriété de morceau invalide.');if($value!==null)$value=songParseValue($spec,is_bool($value)?($value?'1':'0'):(string)$value);$values[$sql]=is_bool($value)?(int)$value:$value;}
    // Colonnes historiques NOT NULL : une cellule Artiste vide reste une chaîne vide.
    $values['artist']=$values['artist']??'';$values['title']=$values['title']??'';
    return $values;
}
function songRowValues(array $row): array {
    $json=json_decode($row['data_json']??'{}',true,64,JSON_THROW_ON_ERROR);$out=$row;
    foreach(songFieldSpecs() as $sql=>$spec)if(($out[$sql]??null)===null&&array_key_exists($spec['key'],$json))$out[$sql]=$json[$spec['key']];
    return $out;
}
function songUpdateColumns(PDO $db,int $id,array $song): void {
    if(!songFieldsReady())return;$values=songSqlValues($song);
    $q=$db->prepare('UPDATE setlist_songs SET '.implode(',',array_map(fn($key)=>'`'.$key.'`=?',array_keys($values))).' WHERE id=?');$q->execute([...array_values($values),$id]);
}
function songFieldText(string $key,$value): string {
    if($value===null||$value==='')return '—';$spec=songFieldSpecs()[$key]??null;
    if(($spec['type']??'')==='bool')return (bool)$value?'Oui':'Non';
    if(($spec['type']??'')==='duration')return songDurationLabel($value);
    if(($spec['type']??'')==='date')return MusicbandDisplayDate((string)$value);
    return (string)$value;
}
function songFieldInput(string $key,string $value): string {
    $spec=songFieldSpecs()[$key]??null;$name=MusicbandEscape($key);$escaped=MusicbandEscape($value);
    if(!$spec)return '<textarea name="'.$name.'" maxlength="6000" rows="2">'.$escaped.'</textarea>';
    $kind=$spec['type'];
    // Le booléen conserve trois valeurs : inconnu, faux et vrai.
    if($kind==='bool')return '<div class="song-boolean"><input type="hidden" name="'.$name.'" value="0"><label><input type="checkbox" name="'.$name.'" value="1"'.($value==='1'?' checked':'').'> Oui</label><label><input type="checkbox" name="'.$name.'_unknown" value="1"'.($value===''?' checked':'').'> Non renseigné (prioritaire)</label></div>';
    if($kind==='guitar')return '<select name="'.$name.'"><option value="">Non renseigné</option><option value="H"'.($value==='H'?' selected':'').'>H</option><option value="S"'.($value==='S'?' selected':'').'>S</option></select>';
    if($kind==='longtext')return '<textarea name="'.$name.'" rows="3" maxlength="60000">'.$escaped.'</textarea>';
    if($kind==='duration')return '<input name="'.$name.'" inputmode="text" placeholder="M:SS" pattern="[0-9]+:[0-5][0-9](:[0-5][0-9])?" value="'.MusicbandEscape(str_contains($value,':')?$value:songDurationLabel($value)).'">';
    $type=['int'=>'number','date'=>'date','url'=>'url'][$kind]??'text';
    return '<input type="'.$type.'" name="'.$name.'"'.($kind==='int'?' step="1"':' maxlength="6000"').($key==='title'?' required':'').' value="'.$escaped.'">';
}

function songInitialImport(string $path,string $kind,bool $dryRun): string {
    if(!songFieldsReady()&&!$dryRun)throw new RuntimeException('Appliquer data/migrate_morceaux.sql avant cet import.');
    $db=MusicbandRbacDb();
    // Verrou commun aux initialisations, puis contrôle sous transaction.
    $q=$db->query('SELECT id FROM setlist_runtime WHERE id=1 FOR UPDATE');if(!$q->fetchColumn())throw new RuntimeException('État Setlist SQL absent.');
    $needsPurge=false;
    foreach(['setlist_songs','Musicband_publications','Musicband_variants','Musicband_variant_songs','setlist_song_links','setlist_validations','devis_factures_documents','devis_factures_deliveries','devis_factures_revisions','devis_factures_sequences'] as $table){
        if((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()>0)$needsPurge=true;
    }
    if((int)$db->query("SELECT COUNT(*) FROM setlist_documents WHERE document_key<>'setlists.json'")->fetchColumn()>0)$needsPurge=true;
    if((int)$db->query("SELECT COUNT(*) FROM Musicbandai_notification_events WHERE event_key IN ('document.generated','document.sent','setlist.validated')")->fetchColumn()>0)$needsPurge=true;
    if($needsPurge&&!$dryRun)throw new RuntimeException('Purge manuelle préalable requise : les modules concernés ne sont pas vides. Aucun import effectué.');
    if($kind==='catalogue_json'){
        $data=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data)||!is_array($data['headers']??null)||!is_array($data['rows']??null))throw new RuntimeException('Catalogue JSON attendu : headers et rows avec cells.');
        $headers=$data['headers'];$rows=$data['rows'];
    }else{
        $handle=fopen($path,'rb');if(!$handle)throw new RuntimeException('Fichier TSV illisible.');
        try{
            $line=$_POST['header_row']??'1';if(!is_string($line)||!ctype_digit($line)||(int)$line<1||(int)$line>100)throw new RuntimeException('Ligne d’en-têtes invalide.');
            for($i=0;$i<(int)$line;$i++){$headers=fgetcsv($handle,0,"\t",'"','');if($headers===false)throw new RuntimeException('En-têtes absents.');}
            $headers[0]=preg_replace('/^\xEF\xBB\xBF/','',(string)$headers[0]);$rows=[];
            while(($cells=fgetcsv($handle,0,"\t",'"',''))!==false){if(count($rows)>=2000)throw new RuntimeException('2000 lignes maximum.');$rows[]=['cells'=>$cells,'sheet_row'=>++$i];}
        }finally{fclose($handle);}
    }
    if(count($headers)>500||count($rows)>2000)throw new RuntimeException('Catalogue limité à 2000 lignes et 500 colonnes.');
    foreach($headers as $header)if(!is_string($header)||strlen($header)>6000)throw new RuntimeException('En-tête invalide.');
    $indexes=[];
    foreach(songFieldSpecs() as $key=>$spec){$index=$spec['column']-1;if(trim($headers[$index]??'')!==$spec['header'])throw new RuntimeException('Colonne '.$spec['column'].' : en-tête attendu « '.$spec['header'].' ». Aucun mapping deviné.');$indexes[$spec['key']]=$index;}
    $valid=[];$ignored=0;$rejected=0;$errors=[];
    foreach($rows as $n=>$row){
        $cells=$row['cells']??null;if(!is_array($cells)||count($cells)>500)throw new RuntimeException('Cellules invalides à la ligne '.($n+1).'.');
        foreach($cells as $cell)if(!is_string($cell)||strlen($cell)>60000||!preg_match('//u',$cell)||str_contains($cell,"\0"))throw new RuntimeException('Cellule invalide à la ligne '.($n+1).'.');
        if(trim($cells[0]??'')===''&&trim($cells[1]??'')===''){$ignored++;continue;}
        $song=['_headers'=>$headers,'_column_indexes'=>$indexes,'cells'=>$cells];$issues=[];
        foreach(songFieldSpecs() as $key=>$spec){try{$song[$spec['key']]=songParseValue($spec,$cells[$spec['column']-1]??'',true);}catch(Throwable $e){$issues[]=$spec['label'];}}
        if(trim($song['title']??'')==='')$issues[]='Morceau requis';
        if($issues){$rejected++;if(count($errors)<20)$errors[]='ligne '.($n+1).' : '.implode(', ',$issues);continue;}
        $valid[]=[$song,setlistSqlSource(['cells'=>$cells],$headers)];
    }
    $report=count($valid).' lignes valides, '.$ignored.' ignorées (artiste et titre vides), '.$rejected.' rejetées.';
    if($errors)$report.=' Champs à corriger : '.implode(' ; ',$errors).'.';
    if($dryRun)return 'Simulation sans écriture : '.$report.($needsPurge?' Purge préalable encore nécessaire avant import réel.':' Modules concernés vides.').(!songFieldsReady()?' Migration encore nécessaire.':'');
    if($rejected||!$valid)throw new RuntimeException('Import annulé intégralement : '.$report);
    foreach($valid as [$song,$source])setlistSqlSong($song,$source);
    return 'Import initial complet : '.count($valid).' morceaux insérés, '.$ignored.' lignes ignorées, 0 rejetée. Aucun rapprochement par titre ; un nouvel import initial est maintenant bloqué.';
}

// Le tableau lié Setlist reste compact ; la fiche Morceaux porte les 34 propriétés.
function songRelatedColumns(): array {
    static $columns=null;if($columns!==null)return $columns;
    $fields=array_intersect_key(songFieldLabels(),array_flip(['duration_seconds','tempo','guitar','musical_key','concert_ready']));
    $fields=array_filter($fields,fn($key)=>fieldVisibilityVisible('songs',$key),ARRAY_FILTER_USE_KEY);
    return $columns=fieldLayoutOrder('songs','list',$fields);
}
function songRelatedHead(): string {
    $html='';foreach(songRelatedColumns() as $key=>$label)$html.='<th>'.MusicbandEscape(fieldDisplayLabel('songs',$key,$label)).'</th>';return $html;
}
function songRelatedCells(array $row): string {
    $values=songRowValues($row);$html='';
    foreach(songRelatedColumns() as $key=>$label)$html.='<td><span class="Musicband-table-label">'.MusicbandEscape(fieldDisplayLabel('songs',$key,$label)).'</span><span class="Musicband-cell-value">'.MusicbandEscape(songFieldText($key,$values[$key]??null)).'</span></td>';
    return $html;
}

if (!function_exists('songsFilterOperators')) {
    function songsFilterOperators(string $key): array {
        $type = songFieldSpecs()[$key]['type'] ?? 'text';
        $operators = ['equal', 'not_equal', 'is_null', 'is_not_null'];
        return array_merge($operators, in_array($type, ['int', 'duration', 'date'], true) ? ['less', 'less_or_equal', 'greater', 'greater_or_equal'] : ($type === 'bool' ? [] : ['contains', 'not_contains']));
    }
}

if (!function_exists('songsFilterMatches')) {
    function songsFilterMatches(array $node, array $values): bool {
        if (isset($node['rules'])) {
            if (!$node['rules']) return true;
            $match = $node['condition'] === 'AND';
            foreach ($node['rules'] as $child) {
                $hit = songsFilterMatches($child, $values);
                if ($node['condition'] === 'AND' && !$hit) { $match = false; break; }
                if ($node['condition'] === 'OR' && $hit) { $match = true; break; }
            }
            return !empty($node['not']) ? !$match : $match;
        }
        $key = $node['id'] ?? null;
        if (!is_string($key)) return false;
        $value = $values[$key] ?? null;
        $wanted = $node['value'] ?? null;
        $op = $node['operator'] ?? null;
        $empty = $value === null || $value === '';
        if ($op === 'is_null') return $empty;
        if ($op === 'is_not_null') return !$empty;
        if ($empty) return false;
        $type = songFieldSpecs()[$key]['type'] ?? 'text';
        if ($type === 'bool') {
            $bVal = is_bool($value) ? $value : (is_numeric($value) ? (int)$value !== 0 : in_array(strtolower(trim((string)$value)), ['1', 'oui', 'yes', 'true', 'x', 'vrai', 'y'], true));
            $bWanted = is_bool($wanted) ? $wanted : (is_numeric($wanted) ? (int)$wanted !== 0 : in_array(strtolower(trim((string)$wanted)), ['1', 'oui', 'yes', 'true', 'x', 'vrai', 'y'], true));
            $cmp = (int)$bVal <=> (int)$bWanted;
        } elseif (in_array($type, ['int', 'duration'], true)) $cmp = (int)$value <=> (int)$wanted;
        elseif ($type === 'date') $cmp = strcmp((string)$value, (string)$wanted);
        else {
            $pattern = preg_quote((string)$wanted, '/');
            $hit = preg_match('/' . (in_array($op, ['equal', 'not_equal'], true) ? '\\A' . $pattern . '\\z' : $pattern) . '/iu', (string)$value) === 1;
            return in_array($op, ['not_equal', 'not_contains'], true) ? !$hit : $hit;
        }
        return match ($op) {
            'equal' => $cmp === 0,
            'not_equal' => $cmp !== 0,
            'less' => $cmp < 0,
            'less_or_equal' => $cmp <= 0,
            'greater' => $cmp > 0,
            'greater_or_equal' => $cmp >= 0,
            default => false
        };
    }
}

