<?php
/** Migrations Community : journal de chaque étape, verrou MySQL et reprise DDL.
 * Dépend de PDO MySQL et de baseline.sql ; aucune connexion à l'inclusion.
 * Installation neuve exclusivement pour la version 1, sans adoption automatique d'une base existante.
 * Les futures versions doivent ajouter des étapes avec leur vérification, sans modifier la baseline.
 * Une installation neuve omet explicitement les créations de tables retirées ; les anciens journaux restent reconnus.
 * Fournit aussi le jeu fictif de première installation, dans la transaction de l'installateur uniquement.
 */
declare(strict_types=1);

/** Insère le jeu fictif une seule fois ; l'appelant possède la transaction et le verrou d'installation.
 * Aucun ajout sur mise à niveau ni sur base métier déjà remplie. Le marqueur SQL partage la transaction
 * avec les entités et empêche les doublons si l'installation reprend après son commit SQL.
 */
function communitySeedDemo(PDO $db, string $login, string $timezone): void {
    if (!$db->inTransaction()) throw new RuntimeException('Transaction requise pour les données de démonstration.');
    $marker = 'community:demo:v1';
    $q = $db->prepare('SELECT content,sha256 FROM setlist_documents WHERE document_key=? FOR UPDATE');
    $q->execute([$marker]); $existing = $q->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if (!hash_equals($existing['sha256'], hash('sha256', $existing['content']))) throw new RuntimeException('Marqueur de démonstration altéré : conserver la base pour inspection.');
        return;
    }
    foreach (['contacts_carnet_adresse','concerts_evenements','setlist_songs','Musicband_publications'] as $table) {
        if ((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn() !== 0) throw new RuntimeException('Données métier déjà présentes : initialisation de démonstration refusée, aucune donnée remplacée.');
    }
    // Une ancienne initialisation interrompue peut avoir écrit uniquement l'index vide.
    $empty = json_encode(['setlists'=>[], 'latest_file'=>null], JSON_THROW_ON_ERROR);
    foreach ($db->query('SELECT document_key,content,sha256 FROM setlist_documents')->fetchAll(PDO::FETCH_ASSOC) as $document) {
        if ($document['document_key'] !== 'setlists.json' || $document['content'] !== $empty || !hash_equals($document['sha256'], hash('sha256', $empty))) throw new RuntimeException('Documents Setlist existants : initialisation de démonstration refusée.');
    }
    $json = static fn(array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $source = $json(['demo'=>true, 'description'=>'Exemple fictif Community, sans données réelles.']);
    $identity = hash('sha256', 'community-demo-v1');
    $q = $db->prepare('INSERT INTO contacts_carnet_adresse(source_identity,source_table,source_key,source_key_hash,source_sha256,nom,contact,mail,commentaires,source_json,imported_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
    $q->execute([$identity,'community_demo','contact-demo',hash('sha256','contact-demo'),hash('sha256',$source),'Contact démo — fictif','Équipe de démonstration','contact@example.invalid','Exemple fictif à adapter ou supprimer. Aucun e-mail réel.',$source]);
    $contact = (int)$db->lastInsertId();
    $date = (new DateTimeImmutable('today', new DateTimeZone($timezone)))->modify('+30 days')->format('Y-m-d');
    $q = $db->prepare('INSERT INTO concerts_evenements(source_identity,source_table,source_key,source_key_hash,source_sha256,evenement,date_concert,adresse,statut,client,contact,contact_id,commentaires,source_json,imported_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
    $q->execute([$identity,'community_demo','concert-demo',hash('sha256','concert-demo'),hash('sha256',$source),'Concert démo — fictif',$date,'Lieu fictif de démonstration','À confirmer','Contact démo — fictif','Équipe de démonstration',$contact,'Exemple fictif, aucune prestation réelle prévue.',$source]);
    $concert = (int)$db->lastInsertId();

    // Catalogue fictif autonome ; les durées sont des valeurs d'exemple, en secondes.
    $songInsert = $db->prepare('INSERT INTO setlist_songs(title,artist,duration_seconds,tempo,guitar,musical_key,energy,data_json,source_json,revision) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $songs = []; $ids = []; $music = 0;
    foreach (['Ouverture'=>180, 'Premier refrain'=>210, 'Éclaircie'=>200, 'Dernière danse'=>240, 'Finale'=>190] as $name=>$duration) {
        $song = ['title'=>'Démo — '.$name, 'artist'=>'Groupe fictif', 'duration_seconds'=>$duration, 'tempo'=>120, 'guitar'=>'Démo', 'key'=>'C', 'energy'=>3, 'ready'=>true, 'p5'=>'', 'seb_comments'=>''];
        $songInsert->execute([$song['title'],$song['artist'],$duration,120,'Démo','C',3,$json($song),$source,bin2hex(random_bytes(16))]);
        $id = (int)$db->lastInsertId(); $song['editor_id'] = 'sql-'.$id;
        $song['hidden'] = false; $song['unavailable_reason'] = null;
        $ids[$song['editor_id']] = $id; $songs[] = $song; $music += $duration;
    }
    // Cette setlist d'exemple contient quatre transitions de 5 s, sans pause ni rappel.
    $rules = ['normal_transition_seconds'=>5, 'linked_transition_seconds'=>1, 'guitar_change_extra_seconds'=>10, 'encore_break_seconds'=>30, 'base_pause_seconds'=>0, 'presentation_seconds'=>0, 'encore_count'=>0, 'required'=>[], 'opening'=>null, 'forbidden_start'=>[], 'presentation'=>null, 'encore_required'=>[], 'sequences'=>[]];
    $ordered = [];
    foreach ($songs as $index=>$song) $ordered[] = $song + ['position'=>$index+1, 'section'=>'main'];
    $transitions = (count($songs) - 1) * $rules['normal_transition_seconds'];
    $total = $music + $transitions;
    $variant = ['id'=>'demo-v1', 'name'=>'Exemple fictif', 'songs'=>$ordered, 'music_seconds'=>$music, 'transition_seconds'=>$transitions, 'pause_seconds'=>0, 'total_seconds'=>$total, 'presentation_seconds'=>0, 'encore_break_seconds'=>0, 'margin_seconds'=>0, 'guitar_changes'=>0, 'transition_estimated'=>false];
    $key = substr(hash('sha256','community-demo-setlist-v1'),0,16); $file = 'setlist-'.$key.'.json';
    $title = 'Setlist démo — 5 morceaux fictifs';
    $data = ['title'=>$title, 'status'=>'manual', 'generated_at'=>gmdate('Y-m-d\TH:i:s\Z'), 'concert_location'=>'Lieu fictif de démonstration', 'target_seconds'=>$total, 'encore_count'=>0, 'editor'=>$rules, 'available_songs'=>$songs, 'variants'=>[$variant], 'tempo_export'=>['autoAdvance'=>true,'loop'=>false,'song_defaults'=>new stdClass()]];
    $q = $db->prepare('INSERT INTO Musicband_publications(publication_key,title,concert_location,target_seconds,source_file,snapshot_json,created_at,concert_id,concert_link_by,concert_link_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6),?,?,UTC_TIMESTAMP(6))');
    $q->execute([$key,$title,$data['concert_location'],$total,$file,$json($data),$concert,$login]);
    $q = $db->prepare('INSERT INTO Musicband_variants(publication_key,variant_key,name,snapshot_json,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP(6))');
    $q->execute([$key,$variant['id'],$variant['name'],$json($variant)]); $variantId = (int)$db->lastInsertId();
    $q = $db->prepare('INSERT INTO Musicband_variant_songs(variant_id,position,editor_id,title,artist,section,duration_seconds,snapshot_json) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($ordered as $song) $q->execute([$variantId,$song['position'],$song['editor_id'],$song['title'],$song['artist'],$song['section'],$song['duration_seconds'],$json($song)]);
    $q = $db->prepare('INSERT INTO setlist_song_links(source_file,editor_id,song_id) VALUES (?,?,?)');
    foreach ($ids as $editor=>$id) $q->execute([$file,$editor,$id]);

    // Publication, index, variantes et marqueur : même transaction, empreintes des octets stockés.
    $manifest = ['setlists'=>[['file'=>$file,'title'=>$title]], 'latest_file'=>$file];
    $documents = [$file=>$data, 'variants:'.$file=>[], 'setlists.json'=>$manifest, $marker=>['version'=>1,'contact_id'=>$contact,'concert_id'=>$concert,'song_ids'=>array_values($ids),'file'=>$file]];
    $q = $db->prepare('INSERT INTO setlist_documents(document_key,content,sha256) VALUES (?,?,?) ON DUPLICATE KEY UPDATE content=VALUES(content),sha256=VALUES(sha256)');
    foreach ($documents as $name=>$content) { $raw = $json($content); $q->execute([$name,$raw,hash('sha256',$raw)]); }
}

/** Manifeste immuable : DDL vérifiée séparément, données et journal validés ensemble. */
function communityMigrations(): array {
    $sql = file_get_contents(__DIR__ . '/baseline.sql');
    if ($sql === false) throw new RuntimeException('Baseline inaccessible.');
    $steps = [];
    foreach (explode(';', preg_replace('/--[^\n]*/', '', $sql)) as $statement) {
        $statement = trim($statement);
        if ($statement === '') continue;
        if (preg_match('/^CREATE TABLE (\w+)\s*\((.*)\)\s*ENGINE=/s', $statement, $match)) {
            preg_match_all('/(?:^|,)\s*([A-Za-z_]\w*)\s+(?:BIGINT|INT|TINYINT|SMALLINT|CHAR|VARCHAR|TEXT|LONGTEXT|DATETIME|TIMESTAMP|ENUM|DECIMAL|DATE|MEDIUMTEXT|DOUBLE|FLOAT|JSON)\b/i', $match[2], $columns);
            $steps[] = ['sql' => $statement, 'table' => $match[1], 'columns' => $columns[1]];
        } elseif (preg_match('/^INSERT(?: IGNORE)? INTO /', $statement)) {
            $steps[] = ['sql' => $statement];
        } else throw new RuntimeException('Instruction non prise en charge dans la baseline.');
    }
    // La version 1 reste immuable pour vérifier les installations déjà journalisées.
    return [1 => $steps, 2 => [
        ['sql'=>'DROP TABLE contacts_imports', 'drop_table'=>'contacts_imports'],
        ['sql'=>'DROP TABLE concerts_imports', 'drop_table'=>'concerts_imports'],
    ], 3 => [
        // Enfants avant parents : conserver les contraintes actives pendant tout le retrait.
        ['sql'=>'DROP TABLE devis_factures_deliveries', 'drop_table'=>'devis_factures_deliveries'],
        ['sql'=>'DROP TABLE devis_factures_revisions', 'drop_table'=>'devis_factures_revisions'],
        ['sql'=>'DROP TABLE devis_factures_sequences', 'drop_table'=>'devis_factures_sequences'],
        ['sql'=>'DROP TABLE devis_factures_documents', 'drop_table'=>'devis_factures_documents'],
        ['sql'=>'DROP TABLE expenses_records', 'drop_table'=>'expenses_records'],
        ['sql'=>'DROP TABLE expenses_suppliers', 'drop_table'=>'expenses_suppliers'],
        ['sql'=>'DROP TABLE accounting_revisions', 'drop_table'=>'accounting_revisions'],
        ['sql'=>'DROP TABLE accounting_entries', 'drop_table'=>'accounting_entries'],
        ['sql'=>'DROP TABLE accounting_settings', 'drop_table'=>'accounting_settings'],
    ]];
}

function communityTableExists(PDO $db, string $name): bool {
    $q = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$name]);
    return (int)$q->fetchColumn() === 1;
}

/** Refuse un schéma interrompu dont les colonnes divergent au lieu de le réparer implicitement. */
function communityVerifyTable(PDO $db, array $step): void {
    $q = $db->prepare('SELECT column_name AS c_name,column_type AS c_type,is_nullable AS c_nullable,character_set_name AS c_charset,collation_name AS c_collation FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position');
    $q->execute([$step['table']]);
    $columns = $q->fetchAll(PDO::FETCH_ASSOC);
    if (array_column($columns, 'c_name') !== $step['columns']) {
        throw new RuntimeException('Structure inattendue : ' . $step['table'] . '. Conserver la base et faire inspecter cette étape.');
    }
    // Vérifier aussi types/nullabilité et noms d'index/contraintes avant de reprendre un DDL interrompu.
    $normalizeType = static fn(string $type): string => preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', strtolower(trim($type)));
    foreach ($columns as $column) {
        $pattern = '/(?:\(|,)\s*' . preg_quote($column['c_name'], '/') . '\s+([A-Za-z]+(?:\([^)]*\))?(?:\s+UNSIGNED)?)([^,]*)(?=,|\n\))/i';
        if (!preg_match($pattern, $step['sql'], $definition)) throw new RuntimeException('Définition de colonne non reconnue : ' . $step['table']);
        $nullable = stripos($definition[2], 'NOT NULL') !== false || stripos($definition[2], 'PRIMARY KEY') !== false ? 'NO' : 'YES';
        if ($normalizeType($column['c_type']) !== $normalizeType($definition[1]) || $column['c_nullable'] !== $nullable) throw new RuntimeException('Type ou nullabilité inattendus : ' . $step['table'] . '.' . $column['c_name']);
        if (preg_match('/CHARACTER SET (\w+)/i', $definition[2], $charset) && $column['c_charset'] !== strtolower($charset[1])) throw new RuntimeException('Encodage de colonne inattendu.');
        if (preg_match('/COLLATE (\w+)/i', $definition[2], $collation) && $column['c_collation'] !== strtolower($collation[1])) throw new RuntimeException('Collation de colonne inattendue.');
    }
    preg_match_all('/\b(?:UNIQUE\s+)?KEY\s+(\w+)\s*\(/i', $step['sql'], $matches);
    $expectedIndexes = $matches[1];
    if (preg_match('/\bPRIMARY KEY\b/i', $step['sql'])) $expectedIndexes[] = 'PRIMARY';
    $q = $db->prepare('SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$step['table']]); $actualIndexes = $q->fetchAll(PDO::FETCH_COLUMN);
    // MySQL peut créer implicitement l'index portant le nom d'une clé étrangère.
    preg_match_all('/\bCONSTRAINT\s+(\w+)\s+FOREIGN KEY/i', $step['sql'], $foreignKeys);
    foreach ($expectedIndexes as $index) if (!in_array($index, $actualIndexes, true)) throw new RuntimeException('Index attendu absent : ' . $step['table'] . '.' . $index);
    $q = $db->prepare("SELECT constraint_name FROM information_schema.table_constraints WHERE table_schema=DATABASE() AND table_name=? AND constraint_type='FOREIGN KEY'");
    $q->execute([$step['table']]); $actualForeignKeys = $q->fetchAll(PDO::FETCH_COLUMN);
    $expectedForeignKeys = $foreignKeys[1]; sort($expectedForeignKeys); sort($actualForeignKeys);
    if ($actualForeignKeys !== $expectedForeignKeys) throw new RuntimeException('Contraintes relationnelles inattendues : ' . $step['table']);
}

/** Installe/reprend une base neuve ou migre une base Community dont le journal est reconnu.
 * $fresh autorise la création du journal, jamais l'adoption de tables préexistantes.
 * DDL MySQL hors transaction ; chaque INSERT et son journal sont atomiques sous InnoDB.
 */
function communityMigrate(PDO $db, bool $fresh = false): void {
    $lock = 'mbt-migrate-' . substr(hash('sha256', (string)$db->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
    $q = $db->prepare('SELECT GET_LOCK(?, 0)'); $q->execute([$lock]);
    if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('Une installation ou mise à niveau est déjà en cours.');
    try {
        if (!communityTableExists($db, 'community_schema_migrations')) {
            if (!$fresh || (int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() !== 0) {
                throw new RuntimeException('Base non vide ou sans journal Community : aucune adoption ni réinstallation automatique.');
            }
            $db->exec("CREATE TABLE community_schema_migrations (version INT UNSIGNED NOT NULL, step INT UNSIGNED NOT NULL, checksum CHAR(64) NOT NULL, state VARCHAR(16) NOT NULL, completed_at DATETIME NULL, PRIMARY KEY(version,step)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $manifest = communityMigrations();
        $retiredTables = array_merge(array_column($manifest[2], 'drop_table'), array_column($manifest[3], 'drop_table'));
        $known = [];
        foreach ($manifest as $version => $steps) foreach ($steps as $index => $step) $known[$version . ':' . $index] = hash('sha256', $step['sql']);
        foreach ($db->query('SELECT * FROM community_schema_migrations') as $row) {
            $key = $row['version'] . ':' . $row['step'];
            if (!isset($known[$key]) || !hash_equals($known[$key], $row['checksum'])) throw new RuntimeException('Migration inconnue ou modifiée : restaurer les sources correspondant à la base.');
            if ($row['state'] === 'omitted') {
                $table = $manifest[$row['version']][$row['step']]['table'] ?? null;
                if ((int)$row['version'] !== 1 || !in_array($table, $retiredTables, true) || communityTableExists($db, $table)) throw new RuntimeException('Étape omise incompatible avec le schéma : conserver la base pour inspection.');
            } elseif (!in_array($row['state'], ['running','complete'], true)) throw new RuntimeException('État de migration invalide : conserver le journal pour inspection.');
        }
        // Contrôler tout le retrait financier avant le premier DROP, y compris les références d’autres bases.
        $retired = array_column($manifest[3], 'drop_table');
        $q = $db->prepare('SELECT TABLE_SCHEMA AS child_schema,TABLE_NAME AS child_table FROM information_schema.key_column_usage WHERE referenced_table_schema=DATABASE() AND referenced_table_name=?');
        $schema = (string)$db->query('SELECT DATABASE()')->fetchColumn();
        foreach ($retired as $table) {
            $q->execute([$table]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $reference) {
                $child = array_search($reference['child_table'], $retired, true);
                $parent = array_search($table, $retired, true);
                if ($reference['child_schema'] !== $schema || $child === false || $child >= $parent) throw new RuntimeException('Relation incompatible avec le retrait financier : aucune nouvelle suppression effectuée. Conserver la base pour inspection.');
            }
        }
        foreach ($manifest as $version => $steps) foreach ($steps as $index => $step) {
            $checksum = hash('sha256', $step['sql']);
            $q = $db->prepare('SELECT state FROM community_schema_migrations WHERE version=? AND step=?');
            $q->execute([$version, $index]); $state = $q->fetchColumn();
            if ($state === 'complete' || $state === 'omitted') continue;
            // Ne jamais créer les onze tables retirées sur une base neuve, même temporairement.
            // L’état distinct conserve l’empreinte historique sans prétendre avoir exécuté le CREATE.
            if ($fresh && $version === 1 && $state === false && isset($step['table']) && in_array($step['table'], $retiredTables, true) && !communityTableExists($db, $step['table'])) {
                $q = $db->prepare("INSERT INTO community_schema_migrations VALUES (?,?,?,'omitted',UTC_TIMESTAMP())");
                $q->execute([$version, $index, $checksum]);
                continue;
            }
            if (isset($step['drop_table'])) {
                $table = $step['drop_table'];
                if (communityTableExists($db, $table)) {
                    // Une relation personnalisée doit être inspectée avant toute suppression.
                    $q = $db->prepare('SELECT COUNT(*) FROM information_schema.key_column_usage WHERE referenced_table_schema=DATABASE() AND referenced_table_name=?');
                    $q->execute([$table]);
                    if ((int)$q->fetchColumn() !== 0) throw new RuntimeException('Table encore référencée : migration arrêtée avant suppression.');
                }
                if ($state === false) {
                    $q = $db->prepare("INSERT INTO community_schema_migrations VALUES (?,?,?,'running',NULL)");
                    $q->execute([$version, $index, $checksum]);
                }
                // DROP est non transactionnel : journal avant DDL, reprise possible si la table a déjà disparu.
                if (communityTableExists($db, $table)) $db->exec($step['sql']);
                $q = $db->prepare("UPDATE community_schema_migrations SET state='complete',completed_at=UTC_TIMESTAMP() WHERE version=? AND step=?");
                $q->execute([$version, $index]);
            } elseif (isset($step['table'])) {
                // Journaliser la propriété AVANT le DDL, qui provoque un commit implicite.
                if ($state === false) {
                    if (communityTableExists($db, $step['table'])) throw new RuntimeException('Table non suivie déjà présente : aucune modification.');
                    $q = $db->prepare("INSERT INTO community_schema_migrations VALUES (?,?,?,'running',NULL)");
                    $q->execute([$version, $index, $checksum]);
                }
                if (!communityTableExists($db, $step['table'])) $db->exec($step['sql']);
                communityVerifyTable($db, $step);
                $q = $db->prepare("UPDATE community_schema_migrations SET state='complete',completed_at=UTC_TIMESTAMP() WHERE version=? AND step=?");
                $q->execute([$version, $index]);
            } else {
                $db->beginTransaction();
                try {
                    $db->exec($step['sql']);
                    $q = $db->prepare("INSERT INTO community_schema_migrations VALUES (?,?,?,'complete',UTC_TIMESTAMP())");
                    $q->execute([$version, $index, $checksum]); $db->commit();
                } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            }
        }
    } finally {
        $q = $db->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lock]);
    }
}

/** Refuse de finaliser un site Community tant qu’une table retirée subsiste. Lecture seule. */
function communityVerifyRetiredTablesAbsent(PDO $db): void {
    $manifest = communityMigrations();
    foreach (array_merge(array_column($manifest[2], 'drop_table'), array_column($manifest[3], 'drop_table')) as $table) {
        if (communityTableExists($db, $table)) throw new RuntimeException('Schéma Community non conforme : table retirée encore présente. Conserver la base et reprendre la migration.');
    }
}
