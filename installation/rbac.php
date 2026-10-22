<?php
/**
 * Musicband RBAC: gestion centralisée des rôles, permissions et matrice d'autorisations MySQL.
 *
 * Dépendances : database.php (connexion MySQL), auth.php (sessions, audit).
 * Stockage : tables MySQL Musicbandai_rbac_roles, Musicbandai_rbac_permissions, Musicbandai_rbac_principals.
 * Sécurité : principe du moindre privilège, refus par défaut, protection de l'administrateur ultime.
 * Les exclusions de l'édition priment sur les rôles ; les droits historiques exclus restent en base.
 */
declare(strict_types=1);
require_once __DIR__.'/database.php';

/** La politique d'édition prime sur les droits enregistrés, y compris pour un administrateur. */
function MusicbandModuleEnabled(string $module): bool {
    return !defined('MUSICBAND_COMMUNITY_DISABLED_MODULES') || !in_array($module, MUSICBAND_COMMUNITY_DISABLED_MODULES, true);
}
function MusicbandPermissionEnabled(string $permission): bool {
    // Les anciens droits enregistrés ne réactivent pas la publication WordPress en Community.
    if ($permission === 'concerts.publish.wordpress' && defined('MUSICBAND_COMMUNITY') && MUSICBAND_COMMUNITY) return false;
    if ($permission === 'setlist.import' && defined('MUSICBAND_COMMUNITY_EXTERNAL_IMPORTS') && !MUSICBAND_COMMUNITY_EXTERNAL_IMPORTS) return false;
    $module = explode('.', $permission, 2)[0];
    return MusicbandModuleEnabled($module === 'documents' ? 'devis_factures' : $module);
}

function MusicbandRoles(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $baseRoles = ['lecture' => 'Lecture', 'gestionnaire' => 'Gestionnaire', 'admin' => 'Administrateur'];
    if (!MusicbandRbacComplete()) return $cached = $baseRoles;
    try {
        $rows = MusicbandRbacDb()->query("SELECT role_key, label FROM Musicbandai_rbac_roles ORDER BY FIELD(role_key, 'admin', 'gestionnaire', 'lecture'), label")->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($rows) {
            return $cached = array_replace($baseRoles, $rows);
        }
    } catch (Throwable $e) {}
    return $cached = $baseRoles;
}

function MusicbandPermissionCatalog(): array {
    return [
        'songs.edit' => 'Catalogue · ajouter et modifier les morceaux',
        'songs.delete' => 'Catalogue · supprimer un morceau non lié',
        'setlist.edit' => 'Setlists · modifier et enregistrer les variantes / renommer',
        'setlist.duplicate' => 'Setlists · dupliquer une setlist',
        'setlist.delete' => 'Setlists · supprimer une setlist',
        'setlist.import' => 'Setlists · importer JSON et TSV',
        'setlist.generate' => 'Setlists · créer une setlist manuelle / génération',
        'setlist.validate' => 'Setlists · valider et figer une publication',
        'setlist.link' => 'Setlists · associer ou dissocier un concert',
        'calendar.read' => 'Agenda · consulter les événements Workspace',
        'contacts.edit' => 'Contacts · créer et modifier',
        'concerts.edit' => 'Concerts · créer et modifier',
        'calendar.send' => 'Concerts · envoyer l’invitation calendrier',
        'concerts.publish.wordpress' => 'Concerts · publier publiquement sur WordPress',
        'documents.edit' => 'Devis / Factures · créer et modifier la préparation',
        'documents.validate' => 'Devis / Factures · valider',
        'documents.reopen' => 'Devis / Factures · reprendre / initialiser la préparation',
        'documents.generate' => 'Devis / Factures · générer le PDF et le stocker dans Drive',
        'documents.send' => 'Devis / Factures · transmettre par e-mail',
        'documents.delete' => 'Devis / Factures · supprimer / restaurer (action sensible)',
        'expenses.read' => 'Dépenses · consulter',
        'expenses.create' => 'Dépenses · créer',
        'expenses.update' => 'Dépenses · modifier',
        'expenses.delete' => 'Dépenses · supprimer (administrateur)',
        'accounting.read' => 'Comptabilité · consulter',
        'accounting.create' => 'Comptabilité · créer une écriture manuelle (administrateur)',
        'accounting.update' => 'Comptabilité · modifier une écriture manuelle (administrateur)',
        'accounting.delete' => 'Comptabilité · supprimer une écriture manuelle (administrateur)',
        'accounting.confirm' => 'Comptabilité · confirmer une recette concert (administrateur)',
        'accounting.configure' => 'Comptabilité · configurer le solde initial (administrateur)',
        'tasks.read' => 'Gestion des tâches · consulter et filtrer les tâches',
        'tasks.edit' => 'Gestion des tâches · créer, modifier, assigner et changer le statut',
        'tasks.categories' => 'Gestion des tâches · configurer les catégories',
        'tasks.delete' => 'Gestion des tâches · supprimer définitivement (action sensible)',
        'sound.upload' => 'Sound · importer une scène XR18 (avec rétention existante)',
        'sound.feedback' => 'Sound · enregistrer un retour d’écoute',
        'ideas.send' => 'Boîte à idées · envoyer une suggestion',
        'notifications.subscribe' => 'Notifications · abonnement personnel de l’appareil',
        'notifications.configure' => 'Notifications · règles d’envoi (action sensible)',
        'audit.read' => 'Audit trail · consultation et filtres du journal',
    ];
}

/**
 * Retourne l'organisation modulaire des autorisations pour la matrice d'administration.
 */
function MusicbandPermissionModules(): array {
    $modules = [
        'songs' => [
            'label' => 'Morceaux · Catalogue',
            'permissions' => [
                'songs.edit' => ['type' => 'edit', 'type_label' => 'Modification', 'label' => 'Ajouter et modifier les morceaux', 'desc' => 'Créer ou modifier les morceaux dans le catalogue SQL.'],
                'songs.delete' => ['type' => 'delete', 'type_label' => 'Suppression', 'label' => 'Supprimer un morceau non lié', 'desc' => 'Supprimer un morceau qui n’est rattaché à aucune setlist.']
            ]
        ],
        'setlist' => [
            'label' => 'Setlists',
            'permissions' => [
                'setlist.edit' => ['type' => 'edit', 'type_label' => 'Modification', 'label' => 'Modifier les variantes et renommer', 'desc' => 'Modifier les blocs et morceaux des setlists, enregistrer les variantes.'],
                'setlist.duplicate' => ['type' => 'create', 'type_label' => 'Création', 'label' => 'Dupliquer une setlist', 'desc' => 'Créer une copie d’une setlist sans concert associé.'],
                'setlist.delete' => ['type' => 'delete', 'type_label' => 'Suppression', 'label' => 'Supprimer une setlist', 'desc' => 'Supprimer définitivement une publication de setlist.'],
                'setlist.generate' => ['type' => 'create', 'type_label' => 'Création', 'label' => 'Créer une setlist manuelle / vide', 'desc' => 'Initialiser une nouvelle composition en SQL.'],
                'setlist.validate' => ['type' => 'specific', 'type_label' => 'Action spécifique', 'label' => 'Valider et figer une publication', 'desc' => 'Marquer la setlist comme validée pour le concert.'],
                'setlist.link' => ['type' => 'specific', 'type_label' => 'Action spécifique', 'label' => 'Associer ou dissocier un concert', 'desc' => 'Lier la setlist à un événement de concert.'],
                'setlist.import' => ['type' => 'specific', 'type_label' => 'Action spécifique', 'label' => 'Importer des fichiers (JSON / TSV)', 'desc' => 'Importer des sets et catalogue depuis Google Sheets ou JSON.']
            ]
        ],
        'calendar' => [
            'label' => 'Agenda',
            'permissions' => [
                'calendar.read' => ['type' => 'read', 'type_label' => 'Consultation', 'label' => 'Consulter les événements Workspace', 'desc' => 'Afficher le calendrier partagé des répétitions et dates.']
            ]
        ],
        'concerts' => [
            'label' => 'Concerts',
            'permissions' => [
                'concerts.edit' => ['type' => 'edit', 'type_label' => 'Modification', 'label' => 'Créer et modifier des concerts', 'desc' => 'Ajouter ou éditer les fiches concerts et détails de prestation.'],
                'calendar.send' => ['type' => 'specific', 'type_label' => 'Action spécifique', 'label' => 'Envoyer les invitations calendrier', 'desc' => 'Transmettre les invitations d’événement aux musiciens.'],
                'concerts.publish.wordpress' => ['type' => 'specific', 'type_label' => 'Action spécifique', 'label' => 'Publier publiquement sur WordPress', 'desc' => 'Publier les dates sur le site public WordPress.']
            ]
        ],
        'contacts' => [
            'label' => 'Contacts',
            'permissions' => [
                'contacts.edit' => ['type' => 'edit', 'type_label' => 'Modification', 'label' => 'Créer et modifier des contacts', 'desc' => 'Gérer le carnet d’adresses des clients, organisateurs et partenaires.']
            ]
        ],
        'documents' => [
            'label' => 'Devis / Factures',
            'permissions' => [
                'documents.edit' => ['type' => 'edit', 'type_label' => 'Modification', 'label' => 'Créer et modifier la préparation', 'desc' => 'Saisir les devis, acomptes et factures avant validation.'],
                'documents.validate' => ['type' => 'specific', 'type_label' => 'Action spécifique', 'label' => 'Valider un document', 'desc' => 'Figer les montants et attribuer un numéro définitif.'],
                'documents.reopen' => ['type' => 'specific', 'type_label' => 'Action spécifique', 'label' => 'Reprendre / réinitialiser la préparation', 'desc' => 'Débloquer un document pour modification avant envoi.'],
                'documents.generate' => ['type' => 'specific', 'type_label' => 'Action spécifique', 'label' => 'Générer le PDF Google Drive', 'desc' => 'Générer et archiver le PDF sur l’espace Google Drive du groupe.'],
                'documents.send' => ['type' => 'specific', 'type_label' => 'Action spécifique', 'label' => 'Transmettre par e-mail', 'desc' => 'Envoyer le document PDF validé au client par e-mail.'],
                'documents.delete' => ['type' => 'delete', 'type_label' => 'Suppression', 'label' => 'Supprimer ou restaurer un document', 'desc' => 'Suppression logique d’un devis/facture (action protégée).']
            ]
        ],
        'expenses' => ['label'=>'Dépenses', 'permissions'=>[
            'expenses.read'=>['type'=>'read','type_label'=>'Consultation','label'=>'consulter','desc'=>'Dépenses : consulter.'],
            'expenses.create'=>['type'=>'create','type_label'=>'Création','label'=>'créer','desc'=>'Dépenses : créer.'],
            'expenses.update'=>['type'=>'edit','type_label'=>'Modification','label'=>'modifier','desc'=>'Dépenses : modifier.'],
            'expenses.delete'=>['type'=>'delete','type_label'=>'Suppression','label'=>'supprimer (administrateur)','desc'=>'Dépenses : supprimer (administrateur).'],
        ]],
        'accounting' => ['label'=>'Comptabilité', 'permissions'=>[
            'accounting.read'=>['type'=>'read','type_label'=>'Consultation','label'=>'consulter','desc'=>'Comptabilité : consulter.'],
            'accounting.create'=>['type'=>'create','type_label'=>'Création','label'=>'créer une écriture manuelle (administrateur)','desc'=>'Comptabilité : créer une écriture manuelle (administrateur).'],
            'accounting.update'=>['type'=>'edit','type_label'=>'Modification','label'=>'modifier une écriture manuelle (administrateur)','desc'=>'Comptabilité : modifier une écriture manuelle (administrateur).'],
            'accounting.delete'=>['type'=>'delete','type_label'=>'Suppression','label'=>'supprimer une écriture manuelle (administrateur)','desc'=>'Comptabilité : supprimer une écriture manuelle (administrateur).'],
            'accounting.confirm'=>['type'=>'specific','type_label'=>'Confirmation','label'=>'confirmer une recette concert (administrateur)','desc'=>'Comptabilité : confirmer une recette concert (administrateur).'],
            'accounting.configure'=>['type'=>'specific','type_label'=>'Configuration','label'=>'configurer le solde initial (administrateur)','desc'=>'Comptabilité : configurer le solde initial (administrateur).'],
        ]],
        'tasks' => [
            'label' => 'Gestion des tâches',
            'permissions' => [
                'tasks.read' => ['type' => 'read', 'type_label' => 'Consultation', 'label' => 'Consulter et filtrer les tâches', 'desc' => 'Accéder à la liste des tâches et au tableau de bord.'],
                'tasks.edit' => ['type' => 'edit', 'type_label' => 'Modification', 'label' => 'Créer, modifier, assigner et changer le statut', 'desc' => 'Gérer le cycle de vie des tâches collaboratives.'],
                'tasks.categories' => ['type' => 'specific', 'type_label' => 'Action spécifique', 'label' => 'Configurer les catégories', 'desc' => 'Créer et gérer les catégories de tâches.'],
                'tasks.delete' => ['type' => 'delete', 'type_label' => 'Suppression', 'label' => 'Supprimer définitivement des tâches', 'desc' => 'Purger définitivement une tâche de la base (action protégée).']
            ]
        ],
        'sound' => [
            'label' => 'Sound · XR18',
            'permissions' => [
                'sound.upload' => ['type' => 'create', 'type_label' => 'Création', 'label' => 'Importer une scène XR18', 'desc' => 'Transférer un fichier scène .scn dans le stockage privé partagé.'],
                'sound.feedback' => ['type' => 'edit', 'type_label' => 'Modification', 'label' => 'Enregistrer un retour d’écoute', 'desc' => 'Noter et commenter les réglages sonores après essai.']
            ]
        ],
        'general' => [
            'label' => 'Général & Système',
            'permissions' => [
                'ideas.send' => ['type' => 'specific', 'type_label' => 'Action spécifique', 'label' => 'Boîte à idées · envoyer une suggestion', 'desc' => 'Transmettre une idée ou proposition d’amélioration.'],
                'notifications.subscribe' => ['type' => 'specific', 'type_label' => 'Action spécifique', 'label' => 'Notifications · abonnement personnel', 'desc' => 'Recevoir les alertes Web Push sur son appareil.'],
                'notifications.configure' => ['type' => 'specific', 'type_label' => 'Action spécifique', 'label' => 'Notifications · règles d’envoi', 'desc' => 'Paramétrer les délais et événements déclenchant les notifications.'],
                'audit.read' => ['type' => 'read', 'type_label' => 'Consultation', 'label' => 'Audit trail · consultation du journal', 'desc' => 'Consulter le journal de sécurité et l’historique des connexions.']
            ]
        ]
    ];
    foreach ($modules as &$module) $module['permissions'] = array_filter($module['permissions'], static fn(string $permission): bool => MusicbandPermissionEnabled($permission), ARRAY_FILTER_USE_KEY);
    unset($module);
    return array_filter($modules, static fn(string $module): bool => MusicbandPermissionEnabled($module), ARRAY_FILTER_USE_KEY);
}

function MusicbandRbacComplete(): bool { return (MusicbandRead('database_upgrade')['rbac']['status']??'')==='complete'; }
function MusicbandRbacDb(): PDO {
    static $db=null,$failed=false;
    if ($failed) throw new RuntimeException('RBAC MySQL indisponible. Écritures refusées.');
    if ($db instanceof PDO) return $db;
    $settings=[];
    foreach (['DB_SERVER','DB_NAME','DB_USER','DB_PASSWORD','DB_PORT','DB_SSL_CA'] as $key) if (getenv($key)!==false) $settings[$key]=getenv($key);
    try { return $db=MusicbandDatabase(array_replace($settings,MusicbandRead('database_settings'))); }
    catch (Throwable $e) { $failed=true; throw new RuntimeException('RBAC MySQL indisponible. Vérifier la connexion dans Upgrade.'); }
}
function MusicbandRbacRole(string $login,array $account): string {
    // Avant le schéma uniquement : conserver l’accès des administrateurs à Upgrade.
    if (!MusicbandRbacComplete()) return !empty($account['admin'])?'admin':'lecture';
    try {
        $q=MusicbandRbacDb()->prepare("SELECT role_key FROM Musicbandai_rbac_principals WHERE kind='account' AND principal=?"); $q->execute([$login]);
        $role=$q->fetchColumn(); return isset(MusicbandRoles()[$role?:''])?$role:'lecture';
    } catch (Throwable $e) { return 'lecture'; }
}
function MusicbandCan(string $permission, ?array $user = null): bool {
    if (!MusicbandPermissionEnabled($permission)) return false;
    if (!isset(MusicbandPermissionCatalog()[$permission])) return false;
    try {
        if (!MusicbandRbacComplete()) return false;
        $user = $user ?? MusicbandUser();
        if (!$user || empty($user['login'])) return false;
        $login = (string)$user['login'];
        $accounts = MusicbandRead('users');
        $account = $accounts[$login] ?? null;
        if (!$account) return false;

        // Contrôle de révocation si exécuté dans la session web du compte connecté
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['login']) && $_SESSION['login'] === $login && isset($_SESSION['revision'])) {
            if (!hash_equals((string)$account['revision'], (string)$_SESSION['revision'])) return false;
        }

        // Rôle dynamique déterminé en temps réel depuis MySQL (table principals)
        $role = MusicbandRbacRole($login, $account);

        // L'administrateur conserve l'intégralité des droits
        if ($role === 'admin') return true;

        // Résolution dynamique et directe des autorisations depuis la table MySQL
        $q = MusicbandRbacDb()->prepare('SELECT allowed FROM Musicbandai_rbac_permissions WHERE role_key=? AND permission_key=?');
        $q->execute([$role, $permission]);
        $allowed = $q->fetchColumn();
        if ($allowed !== false) {
            return (int)$allowed === 1;
        }

        // Valeurs par défaut déterministes si la permission n'est pas encore enregistrée en base
        if (in_array($permission, ['ideas.send', 'notifications.subscribe', 'tasks.read'], true)) return true;
        if ($role === 'gestionnaire') {
            $defaultGestionnaire = [
                'setlist.edit', 'setlist.duplicate', 'setlist.link', 'contacts.edit',
                'concerts.edit', 'documents.edit', 'sound.upload', 'sound.feedback', 'tasks.edit'
            ];
            return in_array($permission, $defaultGestionnaire, true);
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}
function MusicbandRequirePermission(string $permission, ?array $user = null): void {
    if (!MusicbandCan($permission, $user)) throw new RuntimeException('Action non autorisée pour votre rôle, ou RBAC MySQL indisponible.');
}
function MusicbandBusinessPermission(string $module): string {
    return ['contacts' => 'contacts.edit', 'concerts' => 'concerts.edit', 'devis_factures' => 'documents.edit', 'songs' => 'songs.edit'][$module] ?? '';
}
function MusicbandDocumentPermission(string $action): string {
    return ['save'=>'documents.edit','document_validate'=>'documents.validate','document_reopen'=>'documents.reopen','document_initialize'=>'documents.reopen','document_delete'=>'documents.delete','document_restore'=>'documents.delete','document_generate'=>'documents.generate','document_send'=>'documents.send'][$action]??'';
}
function MusicbandRoleInput($value): string {
    if (!is_string($value) || !isset(MusicbandRoles()[$value])) throw new RuntimeException('Rôle inconnu.');
    return $value;
}
function MusicbandRoleOptions(string $selected='lecture'): string {
    $html=''; foreach (MusicbandRoles() as $key=>$label) $html.='<option value="'.$key.'"'.($selected===$key?' selected':'').'>'.$label.'</option>'; return $html;
}
function MusicbandRbacAssign(PDO $db,string $kind,string $principal,string $role,string $actor): void {
    MusicbandRoleInput($role);
    if (!in_array($kind,['account','invite'],true) || $principal==='' || strlen($principal)>254) throw new RuntimeException('Affectation invalide.');
    $q=$db->prepare('INSERT INTO Musicbandai_rbac_principals (kind,principal,role_key,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role_key=VALUES(role_key),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP');
    $q->execute([$kind,$principal,$role,$actor]);
}
function MusicbandRbacInviteRole(string $hash): string {
    if (!MusicbandRbacComplete()) throw new RuntimeException('Appliquer le schéma RBAC 010 dans Upgrade avant les invitations.');
    $q=MusicbandRbacDb()->prepare("SELECT role_key FROM Musicbandai_rbac_principals WHERE kind='invite' AND principal=?");$q->execute([$hash]);
    // Les invitations antérieures au RBAC deviennent lecture seule.
    return MusicbandRoleInput($q->fetchColumn()?:'lecture');
}
function MusicbandRbacSchema(array $settings): string {
    if (($_POST['confirm']??'')!=='yes' || ($_POST['confirm_database']??'')!==($settings['DB_NAME']??null)) throw new RuntimeException('Confirmer la sauvegarde et le nom exact de la base.');
    if (MusicbandRbacComplete()) {
        $db=MusicbandRbacDb();$db->beginTransaction();
        try{$q=$db->prepare('INSERT IGNORE INTO Musicbandai_rbac_permissions (role_key,permission_key,allowed) VALUES (?, ?, 1)');foreach(MusicbandRoles() as $role=>$_)$q->execute([$role,'ideas.send']);$q=$db->prepare('INSERT IGNORE INTO Musicbandai_rbac_permissions(role_key,permission_key,allowed) VALUES (?,?,?)');foreach(MusicbandRoles() as $role=>$_)$q->execute([$role,'calendar.read',(int)($role==='admin')]);$db->commit();}
        catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        return 'Droits Boîte à idées et Agenda ajoutés si absents. Rôles et droits existants conservés.';
    }
    $sql=base64_decode('',true);
    if (!$sql || str_contains($sql,'Musicband_')) throw new RuntimeException('Schéma RBAC non embarqué : préparer le site.');
    $db=MusicbandDatabase($settings); $state=MusicbandRead('database_upgrade');
    ksort($settings);$fingerprint=hash('sha256',json_encode($settings,JSON_THROW_ON_ERROR));
    if (isset($state['rbac']['fingerprint']) && !hash_equals($state['rbac']['fingerprint'],$fingerprint)) throw new RuntimeException('Destination RBAC modifiée après début de migration : conserver la destination et inspecter la base.');
    $state['rbac']=['status'=>'started','fingerprint'=>$fingerprint,'at'=>gmdate('c'),'by'=>$_SESSION['login']];MusicbandWrite('database_upgrade',$state);
    foreach (explode(';',$sql) as $statement) if (trim($statement)!=='') $db->exec($statement);
    $db->beginTransaction();
    try {
        $q=$db->prepare('INSERT IGNORE INTO Musicbandai_rbac_roles (role_key,label) VALUES (?,?)');
        foreach (MusicbandRoles() as $key=>$label) $q->execute([$key,$label]);
        $q=$db->prepare('INSERT IGNORE INTO Musicbandai_rbac_permissions (role_key,permission_key,allowed) VALUES (?,?,?)');
        $defaults=['setlist.edit','setlist.duplicate','setlist.link','contacts.edit','concerts.edit','documents.edit','sound.upload','sound.feedback'];
        foreach (MusicbandRoles() as $role=>$_) foreach (MusicbandPermissionCatalog() as $key=>$_label) $q->execute([$role,$key,(int)($role==='admin' || in_array($key,['notifications.subscribe','ideas.send'],true) || ($role==='gestionnaire' && in_array($key,$defaults,true)))]);
        $q=$db->prepare("INSERT IGNORE INTO Musicbandai_rbac_principals (kind,principal,role_key,updated_by) VALUES ('account',?,?,?)");
        foreach (MusicbandRead('users') as $login=>$entry) $q->execute([$login,!empty($entry['admin'])?'admin':'gestionnaire',$_SESSION['login']]);
        if ((int)$db->query('SELECT COUNT(*) FROM Musicbandai_rbac_permissions')->fetchColumn()!==count(MusicbandRoles())*count(MusicbandPermissionCatalog())) throw new RuntimeException('Schéma RBAC incomplet ou inattendu : inspecter les tables avant reprise.');
        MusicbandRbacRequireAdministrator($db,MusicbandRead('users'));
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    $state['rbac']['status']='complete';MusicbandWrite('database_upgrade',$state);
    return 'Schéma RBAC 010 initialisé. Administrateurs conservés, anciens utilisateurs devenus gestionnaires. Configurer les droits sensibles dans Configuration.';
}
function MusicbandRbacUpgradeForm(string $hidden): string {
    return '<section><h3>Rôles et droits · schéma 010</h3><p>Trois tables MySQL RBAC. Aucun mot de passe déplacé. Sauvegarder la base et les comptes privés. Après initialisation, MySQL devient obligatoire pour les autorisations ; une indisponibilité refuse les écritures et l’administration. Une création interrompue peut être reprise explicitement, sans écraser les affectations existantes. Sur une base déjà initialisée, cette action ajoute uniquement les droits Boîte à idées et Agenda absents pour chaque rôle ; elle conserve les affectations et droits existants.</p><form method="post">'.$hidden.'<input type="hidden" name="action" value="rbac_schema"><label>Nom exact de la base<input name="confirm_database" required></label><label><input type="checkbox" name="confirm" value="yes" required> Sauvegarde et destination vérifiées</label><button>Initialiser / compléter les rôles et droits · 010</button></form></section>';
}
function MusicbandRbacMatrix(?string $role = null): array {
    if (!MusicbandRbacComplete()) throw new RuntimeException('Initialiser le schéma RBAC 010 depuis Upgrade.');
    $db = MusicbandRbacDb();
    $catalog = MusicbandPermissionCatalog();

    if ($role !== null) {
        if ($role === 'admin') {
            $result = [];
            foreach ($catalog as $key => $_) $result[$key] = 1;
            return $result;
        }
        $q = $db->prepare('SELECT permission_key, allowed FROM Musicbandai_rbac_permissions WHERE role_key=? ORDER BY permission_key');
        $q->execute([$role]);
        $rows = $q->fetchAll();
        $dbPerms = [];
        foreach ($rows as $row) $dbPerms[$row['permission_key']] = (int)$row['allowed'];
        $result = [];
        $defaultGestionnaire = [
            'setlist.edit', 'setlist.duplicate', 'setlist.link', 'contacts.edit',
            'concerts.edit', 'documents.edit', 'sound.upload', 'sound.feedback', 'tasks.edit'
        ];
        foreach ($catalog as $key => $_) {
            if (isset($dbPerms[$key])) {
                $result[$key] = $dbPerms[$key];
            } else {
                if (in_array($key, ['ideas.send', 'notifications.subscribe', 'tasks.read'], true)) $result[$key] = 1;
                elseif ($role === 'gestionnaire') $result[$key] = in_array($key, $defaultGestionnaire, true) ? 1 : 0;
                else $result[$key] = 0;
            }
        }
        return $result;
    }

    $all = [];
    foreach (MusicbandRoles() as $k => $_) {
        $all[$k] = MusicbandRbacMatrix($k);
    }
    return $all;
}

function MusicbandRbacMatrixVersion(array $matrix): string {
    ksort($matrix);
    return hash('sha256', json_encode($matrix, JSON_THROW_ON_ERROR));
}

function MusicbandRbacSave(): string {
    $actor = MusicbandRequireLogin();
    if (!$actor['admin']) throw new RuntimeException('Accès administrateur requis.');
    $role = trim((string)($_POST['role'] ?? 'gestionnaire'));
    if ($role === 'admin') throw new RuntimeException('Le rôle Administrateur conserve toujours tous les droits.');
    $roles = MusicbandRoles();
    if (!isset($roles[$role])) throw new RuntimeException('Rôle sélectionné inconnu.');

    $matrix = MusicbandRbacMatrix($role);
    $posted = $_POST['permissions'] ?? [];
    if (!is_array($posted)) $posted = [];
    $catalog = MusicbandPermissionCatalog();

    if (!is_string($_POST['rbac_version'] ?? null) || !hash_equals(MusicbandRbacMatrixVersion($matrix), $_POST['rbac_version'])) {
        throw new RuntimeException('Les droits ont été modifiés entre-temps : rechargez la page avant d’enregistrer.');
    }

    $db = MusicbandRbacDb();
    $db->beginTransaction();
    try {
        $q = $db->prepare('INSERT INTO Musicbandai_rbac_permissions (role_key, permission_key, allowed, updated_by, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE allowed=VALUES(allowed), updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP');
        $changedCount = 0;
        foreach ($catalog as $key => $_) {
            // Ne pas révoquer les droits historiques des modules absents de l'édition.
            if (!MusicbandPermissionEnabled($key)) continue;
            $allowed = !empty($posted[$key]) ? 1 : 0;
            $oldAllowed = (int)($matrix[$key] ?? 0);
            if ($allowed !== $oldAllowed) $changedCount++;
            $q->execute([$role, $key, $allowed, $actor['login']]);
        }
        $db->commit();
        if (function_exists('MusicbandAuditEvent')) {
            MusicbandAuditEvent('rbac', 'permissions.updated', 'success', ['target' => $role, 'count' => $changedCount]);
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return 'Droits du rôle « ' . MusicbandEscape($roles[$role]) . ' » enregistrés. Ils sont appliqués immédiatement dès la prochaine action, sans reconnexion.';
}

function MusicbandRbacRoleCreateAction(): string {
    $actor = MusicbandRequireLogin();
    if (!$actor['admin'] || !MusicbandRbacComplete()) throw new RuntimeException('Accès administrateur requis.');
    $key = strtolower(trim((string)($_POST['role_key'] ?? '')));
    $label = trim((string)($_POST['role_label'] ?? ''));
    $cloneFrom = trim((string)($_POST['clone_from'] ?? 'lecture'));

    if (!preg_match('/^[a-z][a-z0-9_]{1,23}$/D', $key)) {
        throw new RuntimeException('Identifiant de rôle invalide (lettres minuscules, chiffres, tiret bas, 2 à 24 caractères, débutant par une lettre).');
    }
    if (in_array($key, ['admin', 'gestionnaire', 'lecture'], true)) {
        throw new RuntimeException('Ce rôle système existe déjà.');
    }
    if ($label === '' || strlen($label) > 64) {
        throw new RuntimeException('Libellé de rôle requis (1 à 64 caractères).');
    }
    $roles = MusicbandRoles();
    if (isset($roles[$key])) {
        throw new RuntimeException('Cet identifiant de rôle est déjà utilisé.');
    }
    if (!isset($roles[$cloneFrom])) {
        $cloneFrom = 'lecture';
    }

    $db = MusicbandRbacDb();
    $db->beginTransaction();
    try {
        $q = $db->prepare('INSERT INTO Musicbandai_rbac_roles (role_key, label) VALUES (?, ?)');
        $q->execute([$key, $label]);

        $catalog = MusicbandPermissionCatalog();
        $sourceMatrix = MusicbandRbacMatrix($cloneFrom);

        $ins = $db->prepare('INSERT INTO Musicbandai_rbac_permissions (role_key, permission_key, allowed, updated_by, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)');
        foreach ($catalog as $permKey => $_) {
            $allowed = ($cloneFrom === 'admin') ? 1 : (int)($sourceMatrix[$permKey] ?? 0);
            $ins->execute([$key, $permKey, $allowed, $actor['login']]);
        }
        $db->commit();
        if (function_exists('MusicbandAuditEvent')) {
            MusicbandAuditEvent('rbac', 'role.created', 'success', ['target' => $key, 'document' => $label, 'reason' => $cloneFrom]);
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return 'Rôle « ' . MusicbandEscape($label) . ' » créé avec succès. Ses droits peuvent être configurés ci-dessous.';
}

function MusicbandRbacRoleRenameAction(): string {
    $actor = MusicbandRequireLogin();
    if (!$actor['admin'] || !MusicbandRbacComplete()) throw new RuntimeException('Accès administrateur requis.');
    $key = trim((string)($_POST['role_key'] ?? ''));
    $label = trim((string)($_POST['role_label'] ?? ''));
    if ($key === 'admin') throw new RuntimeException('Le rôle Administrateur ne peut pas être renommé.');
    if ($label === '' || strlen($label) > 64) throw new RuntimeException('Libellé requis (1 à 64 caractères).');
    $roles = MusicbandRoles();
    if (!isset($roles[$key])) throw new RuntimeException('Rôle introuvable.');

    $db = MusicbandRbacDb();
    $q = $db->prepare('UPDATE Musicbandai_rbac_roles SET label=? WHERE role_key=?');
    $q->execute([$label, $key]);
    if (function_exists('MusicbandAuditEvent')) {
        MusicbandAuditEvent('rbac', 'role.renamed', 'success', ['target' => $key, 'document' => $label]);
    }
    return 'Rôle « ' . MusicbandEscape($label) . ' » renommé.';
}

function MusicbandRbacRoleDeleteAction(): string {
    $actor = MusicbandRequireLogin();
    if (!$actor['admin'] || !MusicbandRbacComplete()) throw new RuntimeException('Accès administrateur requis.');
    $key = trim((string)($_POST['role_key'] ?? ''));
    if (in_array($key, ['admin', 'gestionnaire', 'lecture'], true)) {
        throw new RuntimeException('Les rôles système ne peuvent pas être supprimés.');
    }
    $roles = MusicbandRoles();
    if (!isset($roles[$key])) throw new RuntimeException('Rôle introuvable.');

    $db = MusicbandRbacDb();
    $check = $db->prepare('SELECT COUNT(*) FROM Musicbandai_rbac_principals WHERE role_key=?');
    $check->execute([$key]);
    if ((int)$check->fetchColumn() > 0) {
        throw new RuntimeException('Ce rôle est actuellement attribué à un ou plusieurs utilisateurs ou invitations. Réaffectez ces comptes avant de le supprimer.');
    }

    $db->beginTransaction();
    try {
        $delPerms = $db->prepare('DELETE FROM Musicbandai_rbac_permissions WHERE role_key=?');
        $delPerms->execute([$key]);
        $delRole = $db->prepare('DELETE FROM Musicbandai_rbac_roles WHERE role_key=?');
        $delRole->execute([$key]);
        $db->commit();
        if (function_exists('MusicbandAuditEvent')) {
            MusicbandAuditEvent('rbac', 'role.deleted', 'success', ['target' => $key]);
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return 'Rôle supprimé avec succès.';
}

function MusicbandRbacUserEffectivePermissions(string $login): array {
    $accounts = MusicbandRead('users');
    $account = $accounts[$login] ?? null;
    if (!$account) return [];
    $role = MusicbandRbacRole($login, $account);
    $catalog = MusicbandPermissionCatalog();
    $matrix = MusicbandRbacMatrix($role);
    $result = [];
    foreach ($catalog as $key => $label) {
        if (!MusicbandPermissionEnabled($key)) continue;
        $allowed = ($role === 'admin') ? true : (!empty($matrix[$key]));
        $result[$key] = [
            'permission_key' => $key,
            'label' => $label,
            'role' => $role,
            'allowed' => $allowed
        ];
    }
    return $result;
}

function MusicbandRbacForm(string $hidden): string {
    try {
        if (!MusicbandRbacComplete()) {
            return '<section><h2>Rôles et droits</h2><p>RBAC indisponible ou non initialisé : vérifier MySQL et le schéma 010 dans <a href="upgrade.php">Upgrade</a>. Les écritures métier sont refusées.</p></section>';
        }
        $db = MusicbandRbacDb();
        $roles = MusicbandRoles();
        $modules = MusicbandPermissionModules();

        // Nombre de comptes par rôle
        $userCounts = [];
        try {
            $q = $db->query("SELECT role_key, COUNT(*) AS count FROM Musicbandai_rbac_principals WHERE kind='account' GROUP BY role_key");
            foreach ($q as $row) $userCounts[$row['role_key']] = (int)$row['count'];
        } catch (Throwable $e) {}

        // Rôle sélectionné pour l'affichage et l'édition de la matrice
        $selectedRole = (string)($_GET['rbac_role'] ?? 'gestionnaire');
        if (!isset($roles[$selectedRole]) || $selectedRole === 'admin') {
            $selectedRole = 'gestionnaire';
        }

        $matrix = MusicbandRbacMatrix($selectedRole);
        $version = MusicbandRbacMatrixVersion($matrix);

        $html = '<section class="rbac-section"><h2>Rôles et autorisations (RBAC)</h2>';
        $html .= '<p>Gestion centralisée et granulaire des autorisations d’accès. Les modifications prennent effet immédiatement dès l’action suivante des utilisateurs, sans reconnexion requise. Le rôle Administrateur conserve toujours tous les droits.</p>';

        // Tableau des rôles existants
        $html .= '<h3>Rôles disponibles</h3>';
        $html .= '<div class="rbac-roles-table-wrapper"><table class="rbac-roles-table"><thead><tr><th>Rôle</th><th>Identifiant</th><th>Type</th><th>Utilisateurs</th><th>Actions</th></tr></thead><tbody>';
        foreach ($roles as $key => $label) {
            $isSystem = in_array($key, ['admin', 'gestionnaire', 'lecture'], true);
            $count = $userCounts[$key] ?? 0;
            $html .= '<tr>';
            $html .= '<td><strong>' . MusicbandEscape($label) . '</strong></td>';
            $html .= '<td><code>' . MusicbandEscape($key) . '</code></td>';
            $html .= '<td>' . ($isSystem ? '<span class="rbac-badge rbac-badge-system">Système</span>' : '<span class="rbac-badge rbac-badge-custom">Personnalisé</span>') . '</td>';
            $html .= '<td>' . (int)$count . ' compte(s)</td>';
            $html .= '<td>';
            if ($key !== 'admin') {
                $html .= '<a class="business-action" href="admin.php?rbac_role=' . rawurlencode($key) . '#rbac-matrix-title">Configurer les autorisations</a> ';
            } else {
                $html .= '<em>Tous droits permanents</em> ';
            }
            if (!$isSystem) {
                $html .= '<details class="rbac-inline-details"><summary>Gérer</summary>';
                $html .= '<form method="post" class="rbac-inline-form">' . $hidden . '<input type="hidden" name="action" value="rbac_role_rename"><input type="hidden" name="config_tab" value="utilisateurs"><input type="hidden" name="role_key" value="' . MusicbandEscape($key) . '"><label>Nouveau nom<input name="role_label" value="' . MusicbandEscape($label) . '" required maxlength="64"></label><button type="submit">Renommer</button></form>';
                if ($count === 0) {
                    $html .= '<form method="post" class="rbac-inline-form" onsubmit="return confirm(\'Supprimer définitivement ce rôle personnalisé ?\');">' . $hidden . '<input type="hidden" name="action" value="rbac_role_delete"><input type="hidden" name="config_tab" value="utilisateurs"><input type="hidden" name="role_key" value="' . MusicbandEscape($key) . '"><button type="submit" class="business-action-delete">Supprimer ce rôle</button></form>';
                } else {
                    $html .= '<p class="notice">Réaffectez d’abord les comptes liés avant de supprimer ce rôle.</p>';
                }
                $html .= '</details>';
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        // Créer ou dupliquer un rôle
        $html .= '<details class="rbac-create-role-details"><summary>+ Créer ou dupliquer un rôle personnalisé</summary>';
        $html .= '<form method="post" class="rbac-create-role-form">' . $hidden . '<input type="hidden" name="action" value="rbac_role_create"><input type="hidden" name="config_tab" value="utilisateurs">';
        $html .= '<label>Nom du rôle<input name="role_label" placeholder="ex. Trésorier, Technicien son" required maxlength="64"></label>';
        $html .= '<label>Identifiant technique (slug)<input name="role_key" placeholder="ex. tresorier, tech_son" required maxlength="24" pattern="[a-z][a-z0-9_]{1,23}" title="Lettres minuscules, chiffres, tiret bas"></label>';
        $html .= '<label>Dupliquer les droits depuis<select name="clone_from">';
        foreach ($roles as $k => $l) {
            if ($k !== 'admin') $html .= '<option value="' . MusicbandEscape($k) . '"' . ($k === 'lecture' ? ' selected' : '') . '>' . MusicbandEscape($l) . '</option>';
        }
        $html .= '</select></label>';
        $html .= '<button type="submit">Créer le rôle</button></form></details>';

        // Matrice des autorisations
        $html .= '<h3 id="rbac-matrix-title">Matrice des autorisations · Rôle : <span class="rbac-current-role">' . MusicbandEscape($roles[$selectedRole]) . '</span></h3>';
        $html .= '<p class="rbac-role-selector-p">Choisir le rôle à configurer : ';
        foreach ($roles as $k => $l) {
            if ($k === 'admin') continue;
            $isCur = ($k === $selectedRole);
            $html .= '<a class="rbac-role-tab' . ($isCur ? ' active' : '') . '" href="admin.php?rbac_role=' . rawurlencode($k) . '#rbac-matrix-title">' . MusicbandEscape($l) . ($isCur ? ' [Actif]' : '') . '</a> ';
        }
        $html .= '</p>';

        $html .= '<form method="post" class="rbac-matrix-form" id="rbac-matrix-form">' . $hidden;
        $html .= '<input type="hidden" name="action" value="rbac_permissions">';
        $html .= '<input type="hidden" name="config_tab" value="utilisateurs">';
        $html .= '<input type="hidden" name="role" value="' . MusicbandEscape($selectedRole) . '">';
        $html .= '<input type="hidden" name="rbac_version" value="' . $version . '">';

        foreach ($modules as $modKey => $modData) {
            $html .= '<fieldset class="rbac-module-fieldset" data-rbac-module="' . MusicbandEscape($modKey) . '">';
            $html .= '<legend class="rbac-module-legend"><strong>' . MusicbandEscape($modData['label']) . '</strong> ';
            $html .= '<span class="rbac-module-controls">';
            $html .= '<button type="button" class="rbac-btn-toggle" onclick="MusicbandRbacToggleModule(\'' . MusicbandEscape($modKey) . '\', true)">Tout cocher</button> ';
            $html .= '<button type="button" class="rbac-btn-toggle" onclick="MusicbandRbacToggleModule(\'' . MusicbandEscape($modKey) . '\', false)">Tout décocher</button>';
            $html .= '</span></legend>';

            $html .= '<table class="rbac-module-table"><thead><tr><th>Action / Fonctionnalité</th><th>Type</th><th>Description</th><th class="rbac-th-check">Autorisé</th></tr></thead><tbody>';
            foreach ($modData['permissions'] as $pKey => $pData) {
                $checked = !empty($matrix[$pKey]) ? ' checked' : '';
                $isDeleteOrSensitive = in_array($pData['type'], ['delete'], true) || in_array($pKey, ['notifications.configure', 'documents.delete', 'tasks.delete', 'audit.read'], true);
                $typeBadgeClass = 'rbac-type-' . $pData['type'];
                $html .= '<tr class="' . ($isDeleteOrSensitive ? 'rbac-row-sensitive' : '') . '">';
                $html .= '<td><strong>' . MusicbandEscape($pData['label']) . '</strong>' . ($isDeleteOrSensitive ? ' <span class="rbac-sensitive-pill">Sensible</span>' : '') . '</td>';
                $html .= '<td><span class="rbac-badge ' . $typeBadgeClass . '">' . MusicbandEscape($pData['type_label']) . '</span></td>';
                $html .= '<td class="rbac-desc-cell">' . MusicbandEscape($pData['desc']) . '</td>';
                $html .= '<td class="rbac-check-cell"><label class="Musicband-check-label"><input type="checkbox" class="Musicband-checkbox rbac-checkbox" name="permissions[' . MusicbandEscape($pKey) . ']" value="1"' . $checked . ' aria-label="Autoriser ' . MusicbandEscape($pData['label']) . '"></label></td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></fieldset>';
        }

        $html .= '<div class="rbac-submit-wrapper"><button type="submit" class="business-action">Enregistrer les autorisations pour ' . MusicbandEscape($roles[$selectedRole]) . '</button></div>';
        $html .= '</form>';

        $html .= '<script>
function MusicbandRbacToggleModule(moduleId, state) {
  var fieldset = document.querySelector("fieldset[data-rbac-module=\'" + moduleId + "\']");
  if (!fieldset) return;
  var boxes = fieldset.querySelectorAll("input[type=\'checkbox\'].rbac-checkbox");
  for (var i = 0; i < boxes.length; i++) {
    boxes[i].checked = state;
  }
}
</script>';

        $html .= '</section>';
        return $html;
    } catch (Throwable $e) {
        return '<section><h2>Rôles et autorisations</h2><p>RBAC indisponible ou non initialisé : ' . MusicbandEscape($e->getMessage()) . '</p></section>';
    }
}
function MusicbandRbacAccountRoleSave(string $login,string $role): string {
    MusicbandRoleInput($role);
    $actor=MusicbandRequireLogin();
    if (!$actor['admin'] || !MusicbandRbacComplete()) throw new RuntimeException('RBAC administrateur requis.');
    $users=MusicbandRead('users');
    if (!isset($users[$login])) throw new RuntimeException('Compte absent.');
    $current=MusicbandRbacRole($login,$users[$login]);
    if (!is_string($_POST['role_version']??null) || !hash_equals(hash('sha256',$login."\n".$current),$_POST['role_version'])) throw new RuntimeException('Rôle modifié ailleurs : recharger Configuration.');
    if ($login===$actor['login'] && $role!=='admin') throw new RuntimeException('Conservez votre rôle administrateur.');
    $db=MusicbandRbacDb();$db->beginTransaction();
    try {
        MusicbandRbacAssign($db,'account',$login,$role,$actor['login']);
        MusicbandRbacRequireAdministrator($db,$users);$db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack();throw $e; }
    return 'Rôle enregistré dans MySQL. Il est appliqué dès la prochaine action, y compris dans les sessions déjà ouvertes.';
}
/** Ne pas compter les invités ni les comptes sans hash local comme accès administrateur de secours. */
function MusicbandRbacRequireAdministrator(PDO $db,array $users): void {
    $admins=$db->query("SELECT principal FROM Musicbandai_rbac_principals WHERE kind='account' AND role_key='admin'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($admins as $login) {
        $account = $users[$login] ?? [];
        $hash = (string)($account['hash'] ?? '');
        if (MusicbandAccountIsActive($account) && $hash !== '' && (password_get_info($hash)['algoName'] ?? 'unknown') !== 'unknown') return;
    }
    throw new RuntimeException('Conserver au moins un administrateur actif avec un mot de passe local.');
}
function MusicbandRbacAccountSave(array $users): void {
    if (!MusicbandRbacComplete() || !MusicbandRequireLogin()['admin']) throw new RuntimeException('Initialiser le schéma RBAC 010 avant de modifier les comptes.');
    MusicbandRbacRequireAdministrator(MusicbandRbacDb(),$users);
    // Les identifiants et mots de passe restent privés ; aucune transaction distribuée.
    // Une affectation SQL orpheline ne donne aucun accès et sera remplacée à la prochaine activation.
    MusicbandWrite('users',$users);
}
