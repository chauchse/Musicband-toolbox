<?php
/* Musicband TASKS SERVICE: gestion des tâches collaboratives et catégories, persistance MySQL et audit.
 * Rôle : Logique métier du module Gestion des tâches (tâches du groupe, catégories configurables, transitions d'état, historique, autorisations et audit).
 * Dépendances internes : auth.php, database.php, rbac.php, audit_trail.php.
 * Dépendances externes : PDO MySQL (schéma 016).
 * Effets de bord : Lecture/écriture dans les tables Musicbandai_tasks et Musicbandai_task_categories, journalisation dans Musicbandai_audit_events.
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/audit_trail.php';
require_once __DIR__ . '/advanced_notifications_service.php';

const TASKS_STATUSES = [
    'a_faire' => 'À faire',
    'en_cours' => 'En cours',
    'terminee' => 'Terminée',
    'annulee' => 'Annulée'
];

/**
 * Retourne la connexion PDO pour la gestion des tâches.
 */
function tasksDb(): PDO {
    return MusicbandRbacDb();
}

/**
 * Vérifie si le schéma 016 est prêt dans la base active.
 */
function tasksReady(PDO $db): bool {
    try {
        $q = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('Musicbandai_tasks', 'Musicbandai_task_categories')");
        return count($q->fetchAll()) === 2;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Exécute la préparation du schéma 016 depuis le module Upgrade.
 */
function tasksSchemaUpgrade(array $settings): string {
    if (($_POST['confirm'] ?? '') !== 'yes' || !is_string($_POST['confirm_database'] ?? null) || !hash_equals($settings['DB_NAME'] ?? '', $_POST['confirm_database'])) {
        throw new RuntimeException('Confirmer la sauvegarde et le nom exact de la base.');
    }
    $db = MusicbandDatabase($settings);
    $sql = base64_decode('', true);
    if (!$sql) {
        // En développement local si non substitué, lire directement le fichier
        $path = dirname(__DIR__) . '/deploy/mysql/016_tasks.sql';
        if (file_exists($path)) {
            $sql = file_get_contents($path);
        } else {
            throw new RuntimeException('Schéma Tâches indisponible.');
        }
    }
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') {
            $db->exec($statement);
        }
    }
    if (!tasksReady($db)) {
        throw new RuntimeException('Schéma Tâches incomplet après exécution.');
    }
    MusicbandAuditEvent('tasks', 'schema.prepared', 'success');
    return 'Schéma Tâches 016 préparé avec succès. Les catégories par défaut ont été initialisées.';
}

/**
 * Formulaire de migration pour upgrade.php.
 */
function tasksUpgradeForm(string $hidden): string {
    return '<section><h3>Gestion des tâches · schéma 016</h3>' .
        '<p>Deux tables MySQL : Musicbandai_task_categories et Musicbandai_tasks. Sauvegarder la base avant préparation. ' .
        'Initialise les catégories par défaut (Graphisme, Réseaux sociaux, Technique, Achat, Autre) sans écraser les données existantes.</p>' .
        '<form method="post">' . $hidden .
        '<input type="hidden" name="action" value="tasks_schema">' .
        '<label>Nom exact de la base<input name="confirm_database" required autocomplete="off" spellcheck="false"></label>' .
        '<label><input type="checkbox" name="confirm" value="yes" required> Sauvegarde vérifiée et destination confirmée</label>' .
        '<button>Préparer Tâches · schéma 016</button>' .
        '</form></section>';
}

/* =========================================================================
 * GESTION DES CATÉGORIES (CONFIGURATION)
 * ========================================================================= */

/**
 * Retourne la liste ordonnée des catégories.
 */
function tasksCategories(bool $activeOnly = false): array {
    $db = tasksDb();
    if (!tasksReady($db)) return [];
    $sql = 'SELECT id, name, display_order, enabled, created_at, updated_at FROM Musicbandai_task_categories';
    if ($activeOnly) $sql .= ' WHERE enabled = 1';
    $sql .= ' ORDER BY display_order ASC, name ASC';
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retourne le dictionnaire id => nom des catégories.
 */
function tasksCategoriesMap(): array {
    $categories = tasksCategories(false);
    $map = [];
    foreach ($categories as $cat) {
        $map[$cat['id']] = $cat['name'];
    }
    return $map;
}

/**
 * Compte le nombre de tâches par catégorie.
 */
function tasksCountByCategory(): array {
    $db = tasksDb();
    if (!tasksReady($db)) return [];
    $sql = 'SELECT category_id, COUNT(*) as count FROM Musicbandai_tasks WHERE archived = 0 GROUP BY category_id';
    $stmt = $db->query($sql);
    $counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $counts[$row['category_id']] = (int)$row['count'];
    }
    return $counts;
}

/**
 * Crée une nouvelle catégorie avec identifiant stable.
 */
function tasksCategoryCreate(string $name): array {
    $actor = MusicbandRequireLogin();
    if (!$actor['admin']) throw new RuntimeException('Droits administrateur requis pour configurer les catégories.');
    $trimmed = trim($name);
    if ($trimmed === '' || mb_strlen($trimmed) > 100) {
        throw new RuntimeException('Le nom de la catégorie doit comporter entre 1 et 100 caractères.');
    }
    $db = tasksDb();
    // Vérification d'unicité insensible à la casse
    $check = $db->prepare('SELECT COUNT(*) FROM Musicbandai_task_categories WHERE LOWER(name) = LOWER(?)');
    $check->execute([$trimmed]);
    if ((int)$check->fetchColumn() > 0) {
        throw new RuntimeException('Une catégorie portant ce nom existe déjà.');
    }
    // Génération d'un identifiant stable
    $slug = 'cat_' . substr(preg_replace('/[^a-z0-9_]+/i', '_', strtolower($trimmed)) ?? '', 0, 20);
    $slug = trim($slug, '_');
    if ($slug === 'cat' || strlen($slug) < 5) {
        $slug = 'cat_' . bin2hex(random_bytes(6));
    }
    // Vérifier collision d'ID
    $idCheck = $db->prepare('SELECT COUNT(*) FROM Musicbandai_task_categories WHERE id = ?');
    $idCheck->execute([$slug]);
    if ((int)$idCheck->fetchColumn() > 0) {
        $slug .= '_' . bin2hex(random_bytes(3));
    }
    // Ordre suivant
    $maxOrder = (int)$db->query('SELECT COALESCE(MAX(display_order), 0) FROM Musicbandai_task_categories')->fetchColumn();
    $stmt = $db->prepare('INSERT INTO Musicbandai_task_categories (id, name, display_order, enabled, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(6), NOW(6))');
    $stmt->execute([$slug, $trimmed, $maxOrder + 1]);
    MusicbandAuditEvent('tasks', 'category.created', 'success', ['id' => $slug, 'target' => $trimmed]);
    return ['id' => $slug, 'name' => $trimmed];
}

/**
 * Renomme une catégorie existante.
 */
function tasksCategoryUpdate(string $id, string $name): void {
    $actor = MusicbandRequireLogin();
    if (!$actor['admin']) throw new RuntimeException('Droits administrateur requis.');
    $trimmed = trim($name);
    if ($trimmed === '' || mb_strlen($trimmed) > 100) {
        throw new RuntimeException('Le nom de la catégorie doit comporter entre 1 et 100 caractères.');
    }
    $db = tasksDb();
    $check = $db->prepare('SELECT COUNT(*) FROM Musicbandai_task_categories WHERE LOWER(name) = LOWER(?) AND id <> ?');
    $check->execute([$trimmed, $id]);
    if ((int)$check->fetchColumn() > 0) {
        throw new RuntimeException('Une autre catégorie porte déjà ce nom.');
    }
    $stmt = $db->prepare('UPDATE Musicbandai_task_categories SET name = ?, updated_at = NOW(6) WHERE id = ?');
    $stmt->execute([$trimmed, $id]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Catégorie introuvable.');
    }
    MusicbandAuditEvent('tasks', 'category.renamed', 'success', ['id' => $id, 'target' => $trimmed]);
}

/**
 * Active ou désactive une catégorie.
 */
function tasksCategoryToggle(string $id, bool $enabled): void {
    $actor = MusicbandRequireLogin();
    if (!$actor['admin']) throw new RuntimeException('Droits administrateur requis.');
    $db = tasksDb();
    $stmt = $db->prepare('UPDATE Musicbandai_task_categories SET enabled = ?, updated_at = NOW(6) WHERE id = ?');
    $stmt->execute([(int)$enabled, $id]);
    MusicbandAuditEvent('tasks', $enabled ? 'category.enabled' : 'category.disabled', 'success', ['id' => $id]);
}

/**
 * Réordonne les catégories.
 */
function tasksCategoryReorder(array $orderIds): void {
    $actor = MusicbandRequireLogin();
    if (!$actor['admin']) throw new RuntimeException('Droits administrateur requis.');
    $db = tasksDb();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('UPDATE Musicbandai_task_categories SET display_order = ?, updated_at = NOW(6) WHERE id = ?');
        $pos = 1;
        foreach ($orderIds as $id) {
            if (is_string($id) && $id !== '') {
                $stmt->execute([$pos++, $id]);
            }
        }
        $db->commit();
        MusicbandAuditEvent('tasks', 'categories.reordered', 'success');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Supprime une catégorie si aucune tâche n'y fait référence.
 */
function tasksCategoryDelete(string $id): void {
    $actor = MusicbandRequireLogin();
    if (!$actor['admin']) throw new RuntimeException('Droits administrateur requis.');
    $db = tasksDb();
    $check = $db->prepare('SELECT COUNT(*) FROM Musicbandai_tasks WHERE category_id = ?');
    $check->execute([$id]);
    $taskCount = (int)$check->fetchColumn();
    if ($taskCount > 0) {
        throw new RuntimeException("Cette catégorie est utilisée par $taskCount tâche(s) et ne peut pas être supprimée. Vous pouvez la désactiver.");
    }
    $stmt = $db->prepare('DELETE FROM Musicbandai_task_categories WHERE id = ?');
    $stmt->execute([$id]);
    MusicbandAuditEvent('tasks', 'category.deleted', 'success', ['id' => $id]);
}

/**
 * Traite les actions POST sur les catégories depuis admin.php.
 */
function tasksCategoryAction(string $action): string {
    $actor = MusicbandRequireLogin();
    if (!$actor['admin']) throw new RuntimeException('Droits administrateur requis.');
    if ($action === 'tasks_category_create') {
        $name = (string)($_POST['category_name'] ?? '');
        tasksCategoryCreate($name);
        return 'Catégorie créée avec succès.';
    }
    if ($action === 'tasks_category_edit') {
        $id = (string)($_POST['category_id'] ?? '');
        $name = (string)($_POST['category_name'] ?? '');
        tasksCategoryUpdate($id, $name);
        return 'Catégorie renommée avec succès.';
    }
    if ($action === 'tasks_category_toggle') {
        $id = (string)($_POST['category_id'] ?? '');
        $enabled = ($_POST['enabled'] ?? '') === '1';
        tasksCategoryToggle($id, $enabled);
        return $enabled ? 'Catégorie activée.' : 'Catégorie désactivée.';
    }
    if ($action === 'tasks_category_reorder') {
        $order = $_POST['category_order'] ?? [];
        if (!is_array($order)) throw new RuntimeException('Ordre invalide.');
        tasksCategoryReorder($order);
        return 'Ordre des catégories enregistré.';
    }
    if ($action === 'tasks_category_delete') {
        $id = (string)($_POST['category_id'] ?? '');
        tasksCategoryDelete($id);
        return 'Catégorie supprimée.';
    }
    throw new RuntimeException('Action de catégorie inconnue.');
}

/**
 * Formulaire de configuration des catégories pour admin.php.
 */
function tasksCategoriesConfigForm(string $hidden): string {
    $db = tasksDb();
    if (!tasksReady($db)) {
        return '<p>Module de gestion des tâches non initialisé : appliquer le schéma 016 depuis <a href="' . MusicbandEscape(MusicbandBase()) . 'upgrade.php">Upgrade</a>.</p>';
    }
    $categories = tasksCategories(false);
    $counts = tasksCountByCategory();

    $html = '<section><h3>Catégories de tâches</h3>' .
        '<p>Configurez les catégories utilisées pour classer les tâches du groupe. Les identifiants sont stables : renommer une catégorie conserve le lien avec toutes les tâches existantes. Une catégorie désactivée reste visible sur l’historique des tâches déjà enregistrées mais ne peut plus être affectée à de nouvelles tâches. Une catégorie utilisée ne peut pas être supprimée.</p>';

    // Formulaire d'ajout
    $html .= '<form method="post">' . $hidden .
        '<input type="hidden" name="action" value="tasks_category_create">' .
        '<label>Nouvelle catégorie' .
        '<input type="text" name="category_name" required maxlength="100" placeholder="Ex. Logistique, Répétition…" autocomplete="off">' .
        '</label>' .
        '<button>Ajouter la catégorie</button>' .
        '</form>';

    if (empty($categories)) {
        $html .= '<p>Aucune catégorie enregistrée.</p></section>';
        return $html;
    }

    $html .= '<div class="business-table-scroll"><table style="width:100%;margin-top:16px;"><thead><tr>' .
        '<th>Ordre</th><th>Nom</th><th>Statut</th><th>Tâches</th><th>Actions</th></tr></thead><tbody>';

    foreach ($categories as $cat) {
        $id = MusicbandEscape($cat['id']);
        $name = MusicbandEscape($cat['name']);
        $enabled = (bool)$cat['enabled'];
        $count = $counts[$cat['id']] ?? 0;
        $statusLabel = $enabled ? '<span style="color:#86efac;font-weight:600;">Active</span>' : '<span style="color:#f87171;">Désactivée</span>';

        $html .= '<tr>' .
            '<td><span class="Musicband-table-label">Ordre</span><span class="Musicband-cell-value">' . (int)$cat['display_order'] . '</span></td>' .
            '<td><span class="Musicband-table-label">Nom</span><span class="Musicband-cell-value">' .
            '<form method="post" style="display:inline-flex;gap:4px;align-items:center;">' . $hidden .
            '<input type="hidden" name="action" value="tasks_category_edit">' .
            '<input type="hidden" name="category_id" value="' . $id . '">' .
            '<input type="text" name="category_name" value="' . $name . '" required maxlength="100" style="padding:4px 8px;font-size:13px;width:180px;">' .
            '<button title="Enregistrer le nom" style="padding:4px 8px;font-size:12px;">Renommer</button>' .
            '</form></span></td>' .
            '<td><span class="Musicband-table-label">Statut</span><span class="Musicband-cell-value">' . $statusLabel . '</span></td>' .
            '<td><span class="Musicband-table-label">Tâches</span><span class="Musicband-cell-value">' . $count . '</span></td>' .
            '<td><span class="Musicband-table-label">Actions</span><span class="Musicband-cell-value" style="display:inline-flex;gap:6px;flex-wrap:wrap;">' .
            // Bouton activer / désactiver
            '<form method="post" style="display:inline;">' . $hidden .
            '<input type="hidden" name="action" value="tasks_category_toggle">' .
            '<input type="hidden" name="category_id" value="' . $id . '">' .
            '<input type="hidden" name="enabled" value="' . ($enabled ? '0' : '1') . '">' .
            '<button style="padding:4px 8px;font-size:12px;">' . ($enabled ? 'Désactiver' : 'Activer') . '</button>' .
            '</form>';

        if ($count === 0) {
            $html .= '<form method="post" style="display:inline;" onsubmit="return confirm(\'Supprimer définitivement cette catégorie ?\');">' . $hidden .
                '<input type="hidden" name="action" value="tasks_category_delete">' .
                '<input type="hidden" name="category_id" value="' . $id . '">' .
                '<button style="padding:4px 8px;font-size:12px;color:#f87171;" title="Supprimer la catégorie inutilisée">Supprimer</button>' .
                '</form>';
        } else {
            $html .= '<span style="font-size:11px;color:#888;align-self:center;">(utilisée)</span>';
        }

        $html .= '</span></td></tr>';
    }

    $html .= '</tbody></table></div></section>';
    return $html;
}

/* =========================================================================
 * GESTION DES TÂCHES (LOGIQUE MÉTIER & PERSISTENCE)
 * ========================================================================= */

/**
 * Calcule les compteurs d'indicateurs de synthèse.
 */
function tasksSummaryCounts(?string $userLogin = null): array {
    $db = tasksDb();
    if (!tasksReady($db)) {
        return ['a_faire' => 0, 'en_cours' => 0, 'overdue' => 0, 'terminee' => 0, 'total' => 0];
    }
    $where = 'WHERE archived = 0';
    $params = [];
    if ($userLogin !== null) {
        $where .= ' AND assignee = ?';
        $params[] = $userLogin;
    }
    $sql = "SELECT 
        COUNT(CASE WHEN status = 'a_faire' THEN 1 END) as count_a_faire,
        COUNT(CASE WHEN status = 'en_cours' THEN 1 END) as count_en_cours,
        COUNT(CASE WHEN status = 'terminee' THEN 1 END) as count_terminee,
        COUNT(CASE WHEN status IN ('a_faire', 'en_cours') AND due_date IS NOT NULL AND due_date < CURDATE() THEN 1 END) as count_overdue,
        COUNT(*) as total
        FROM Musicbandai_tasks $where";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'a_faire' => (int)($row['count_a_faire'] ?? 0),
        'en_cours' => (int)($row['count_en_cours'] ?? 0),
        'overdue' => (int)($row['count_overdue'] ?? 0),
        'terminee' => (int)($row['count_terminee'] ?? 0),
        'total' => (int)($row['total'] ?? 0)
    ];
}

/**
 * Recherche et liste paginée des tâches avec filtres et tri.
 */
function tasksList(array $filters = [], string $sort = 'default', string $order = 'asc', int $limit = 50, int $offset = 0): array {
    $db = tasksDb();
    if (!tasksReady($db)) return ['tasks' => [], 'total' => 0];

    $conditions = ['t.archived = :archived'];
    $params = [':archived' => !empty($filters['archived']) ? 1 : 0];

    // Filtre statut
    if (!empty($filters['active_only'])) {
        $conditions[] = "t.status IN ('a_faire', 'en_cours')";
    } elseif (!empty($filters['status']) && array_key_exists($filters['status'], TASKS_STATUSES)) {
        $conditions[] = 't.status = :status';
        $params[':status'] = $filters['status'];
    }

    // Filtre catégorie
    if (!empty($filters['category_id'])) {
        $conditions[] = 't.category_id = :category_id';
        $params[':category_id'] = $filters['category_id'];
    }

    // Filtre affectation
    if (!empty($filters['assignee'])) {
        if ($filters['assignee'] === 'non_assigne') {
            $conditions[] = '(t.assignee IS NULL OR t.assignee = "")';
        } else {
            $conditions[] = 't.assignee = :assignee';
            $params[':assignee'] = $filters['assignee'];
        }
    }

    // Filtre tâches en retard
    if (!empty($filters['overdue'])) {
        $conditions[] = "t.status IN ('a_faire', 'en_cours') AND t.due_date IS NOT NULL AND t.due_date < CURDATE()";
    }

    // Filtre mes tâches
    if (!empty($filters['mes_taches']) && !empty($filters['current_user'])) {
        $conditions[] = 't.assignee = :mes_taches_user';
        $params[':mes_taches_user'] = $filters['current_user'];
    }

    // Recherche libre sur titre et description
    if (!empty($filters['q'])) {
        $conditions[] = '(t.title LIKE :query OR t.description LIKE :query)';
        $params[':query'] = '%' . $filters['q'] . '%';
    }

    $whereSql = implode(' AND ', $conditions);

    // Comptage total
    $countSql = "SELECT COUNT(*) FROM Musicbandai_tasks t WHERE $whereSql";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Clause de tri
    $dir = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
    switch ($sort) {
        case 'title':
            $orderSql = "t.title $dir, t.id DESC";
            break;
        case 'category':
            $orderSql = "c.name $dir, t.id DESC";
            break;
        case 'assignee':
            $orderSql = "CASE WHEN t.assignee IS NULL OR t.assignee = '' THEN 1 ELSE 0 END, t.assignee $dir, t.id DESC";
            break;
        case 'created_at':
            $orderSql = "t.created_at $dir, t.id DESC";
            break;
        case 'due_date':
            $orderSql = "CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date $dir, t.id DESC";
            break;
        case 'status':
            $orderSql = "t.status $dir, t.id DESC";
            break;
        case 'default':
        default:
            // 1. Tâches en retard d'abord
            // 2. Échéances à venir
            // 3. Tâches sans échéance
            // 4. Tâches terminées ou annulées en dernier
            $orderSql = "CASE 
                WHEN t.status IN ('terminee', 'annulee') THEN 4
                WHEN t.due_date IS NOT NULL AND t.due_date < CURDATE() THEN 1
                WHEN t.due_date IS NOT NULL THEN 2
                ELSE 3
            END ASC, t.due_date ASC, t.created_at DESC";
            break;
    }

    $sql = "SELECT t.*, c.name as category_name, c.enabled as category_enabled,
        (t.status IN ('a_faire', 'en_cours') AND t.due_date IS NOT NULL AND t.due_date < CURDATE()) AS is_overdue
        FROM Musicbandai_tasks t
        LEFT JOIN Musicbandai_task_categories c ON t.category_id = c.id
        WHERE $whereSql
        ORDER BY $orderSql
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['tasks' => $tasks, 'total' => $total];
}

/**
 * Récupère une tâche complète par son ID.
 */
function tasksGet(int $id): ?array {
    $db = tasksDb();
    if (!tasksReady($db)) return null;
    $sql = "SELECT t.*, c.name as category_name, c.enabled as category_enabled,
        (t.status IN ('a_faire', 'en_cours') AND t.due_date IS NOT NULL AND t.due_date < CURDATE()) AS is_overdue
        FROM Musicbandai_tasks t
        LEFT JOIN Musicbandai_task_categories c ON t.category_id = c.id
        WHERE t.id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    return $task ?: null;
}

/**
 * Valide et prépare les données d'une tâche.
 */
function tasksValidateInput(array $input, bool $isUpdate = false, ?array $currentTask = null): array {
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 255) {
        throw new RuntimeException('Le titre de la tâche est obligatoire (255 caractères maximum).');
    }

    $description = trim((string)($input['description'] ?? ''));
    if ($description === '') $description = null;

    $categoryId = trim((string)($input['category_id'] ?? ''));
    if ($categoryId === '') {
        throw new RuntimeException('La catégorie est obligatoire.');
    }
    $db = tasksDb();
    $catStmt = $db->prepare('SELECT id, name, enabled FROM Musicbandai_task_categories WHERE id = ?');
    $catStmt->execute([$categoryId]);
    $category = $catStmt->fetch(PDO::FETCH_ASSOC);
    if (!$category) {
        throw new RuntimeException('Catégorie sélectionnée inexistante.');
    }
    // Une catégorie désactivée ne peut pas être affectée à une nouvelle tâche, mais peut être conservée lors d'une mise à jour
    if (!$category['enabled'] && (!$isUpdate || ($currentTask && $currentTask['category_id'] !== $categoryId))) {
        throw new RuntimeException('Cette catégorie est désactivée et ne peut plus être affectée.');
    }

    $assignee = trim((string)($input['assignee'] ?? ''));
    if ($assignee === '') {
        $assignee = null;
    } else {
        $users = MusicbandRead('users');
        if (!isset($users[$assignee])) {
            throw new RuntimeException('Le membre affecté sélectionné n’existe pas dans Musicband.');
        }
    }

    $dueDate = trim((string)($input['due_date'] ?? ''));
    if ($dueDate === '') {
        $dueDate = null;
    } else {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dueDate)) {
            throw new RuntimeException('Format de date d’échéance invalide (AAAA-MM-JJ attendu).');
        }
        $parts = explode('-', $dueDate);
        if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
            throw new RuntimeException('Date d’échéance calendaire invalide.');
        }
    }

    $status = trim((string)($input['status'] ?? 'a_faire'));
    if (!array_key_exists($status, TASKS_STATUSES)) {
        $status = 'a_faire';
    }

    return [
        'title' => $title,
        'description' => $description,
        'category_id' => $categoryId,
        'assignee' => $assignee,
        'due_date' => $dueDate,
        'status' => $status
    ];
}

/**
 * Crée une nouvelle tâche.
 */
function tasksCreate(array $data, array $user): int {
    MusicbandRequirePermission('tasks.edit', $user);
    $valid = tasksValidateInput($data, false);
    $db = tasksDb();

    $stmt = $db->prepare('INSERT INTO Musicbandai_tasks 
        (title, description, category_id, assignee, created_by, created_at, due_date, status, updated_by, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(6), ?, "a_faire", ?, NOW(6))');

    $stmt->execute([
        $valid['title'],
        $valid['description'],
        $valid['category_id'],
        $valid['assignee'],
        $user['login'],
        $valid['due_date'],
        $user['login']
    ]);

    $id = (int)$db->lastInsertId();
    MusicbandAuditEvent('tasks', 'task.created', 'success', [
        'id' => (string)$id,
        'target' => $valid['assignee'] ?? 'non_assigné',
        'fields' => ['title' => $valid['title'], 'category' => $valid['category_id'], 'due_date' => $valid['due_date']]
    ]);
    if (function_exists('advancedNotificationDispatchEvent')) {
        $taskPayload = [
            'id' => $id,
            'title' => $valid['title'],
            'category_name' => $valid['category_id'],
            'priority' => 'normale',
            'assignee_login' => $valid['assignee'],
            'due_date' => $valid['due_date'],
            'status' => 'a_faire'
        ];
        advancedNotificationDispatchEvent('tasks.create', 'tasks', (string)$id, [], $taskPayload, $user['login']);
        if (!empty($valid['assignee'])) {
            advancedNotificationDispatchEvent('tasks.assign', 'tasks', (string)$id, [], $taskPayload, $user['login']);
        }
    }
    return $id;
}

/**
 * Modifie les informations d'une tâche existante.
 */
function tasksUpdate(int $id, array $data, array $user): void {
    MusicbandRequirePermission('tasks.edit', $user);
    $current = tasksGet($id);
    if (!$current) throw new RuntimeException('Tâche introuvable.');

    $valid = tasksValidateInput($data, true, $current);
    $db = tasksDb();

    $fieldsChanged = [];
    if ($current['title'] !== $valid['title']) $fieldsChanged[] = 'titre';
    if ($current['description'] !== $valid['description']) $fieldsChanged[] = 'description';
    if ($current['category_id'] !== $valid['category_id']) $fieldsChanged[] = 'catégorie';
    if ($current['assignee'] !== $valid['assignee']) $fieldsChanged[] = 'affectation';
    if ($current['due_date'] !== $valid['due_date']) $fieldsChanged[] = 'échéance';

    // Gestion du statut si envoyé
    $newStatus = $valid['status'];
    $completedBy = $current['completed_by'];
    $completedAt = $current['completed_at'];

    if ($newStatus !== $current['status']) {
        $fieldsChanged[] = 'statut';
        if ($newStatus === 'terminee') {
            $completedBy = $user['login'];
            $completedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        } elseif ($current['status'] === 'terminee') {
            $completedBy = null;
            $completedAt = null;
        }
    }

    $stmt = $db->prepare('UPDATE Musicbandai_tasks SET 
        title = ?, description = ?, category_id = ?, assignee = ?, due_date = ?, 
        status = ?, completed_by = ?, completed_at = ?, updated_by = ?, updated_at = NOW(6)
        WHERE id = ?');

    $stmt->execute([
        $valid['title'],
        $valid['description'],
        $valid['category_id'],
        $valid['assignee'],
        $valid['due_date'],
        $newStatus,
        $completedBy,
        $completedAt,
        $user['login'],
        $id
    ]);

    MusicbandAuditEvent('tasks', 'task.edited', 'success', [
        'id' => (string)$id,
        'target' => $valid['assignee'] ?? '',
        'fields' => $fieldsChanged
    ]);
    if (function_exists('advancedNotificationDispatchEvent')) {
        $taskPayload = [
            'id' => $id,
            'title' => $valid['title'],
            'category_name' => $valid['category_id'],
            'assignee_login' => $valid['assignee'],
            'due_date' => $valid['due_date'],
            'status' => $newStatus
        ];
        $prevPayload = [
            'id' => $id,
            'title' => $current['title'],
            'category_name' => $current['category_id'],
            'assignee_login' => $current['assignee'],
            'due_date' => $current['due_date'],
            'status' => $current['status']
        ];
        if ($valid['assignee'] !== $current['assignee'] && !empty($valid['assignee'])) {
            advancedNotificationDispatchEvent('tasks.assign', 'tasks', (string)$id, $prevPayload, $taskPayload, $user['login']);
        }
        if ($newStatus !== $current['status']) {
            advancedNotificationDispatchEvent('tasks.status', 'tasks', (string)$id, $prevPayload, $taskPayload, $user['login']);
        }
    }
}

/**
 * Change le statut d'une tâche (cycle de vie).
 */
function tasksChangeStatus(int $id, string $newStatus, array $user): void {
    MusicbandRequirePermission('tasks.edit', $user);
    if (!array_key_exists($newStatus, TASKS_STATUSES)) {
        throw new RuntimeException('Statut demandé invalide.');
    }
    $current = tasksGet($id);
    if (!$current) throw new RuntimeException('Tâche introuvable.');

    $prevStatus = $current['status'];
    if ($prevStatus === $newStatus) return;

    $db = tasksDb();
    $completedBy = $current['completed_by'];
    $completedAt = $current['completed_at'];
    $actionEvent = 'task.status_changed';

    if ($newStatus === 'terminee') {
        $completedBy = $user['login'];
        $completedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $actionEvent = 'task.completed';
    } elseif ($prevStatus === 'terminee') {
        // Réouverture
        $completedBy = null;
        $completedAt = null;
        $actionEvent = 'task.reopened';
    } elseif ($newStatus === 'annulee') {
        $actionEvent = 'task.cancelled';
    }

    $stmt = $db->prepare('UPDATE Musicbandai_tasks SET status = ?, completed_by = ?, completed_at = ?, updated_by = ?, updated_at = NOW(6) WHERE id = ?');
    $stmt->execute([$newStatus, $completedBy, $completedAt, $user['login'], $id]);

    MusicbandAuditEvent('tasks', $actionEvent, 'success', [
        'id' => (string)$id,
        'target' => $newStatus,
        'reason' => "Transition de {$prevStatus} vers {$newStatus}"
    ]);
    if (function_exists('advancedNotificationDispatchEvent')) {
        advancedNotificationDispatchEvent('tasks.status', 'tasks', (string)$id, [
            'id' => $id,
            'title' => $current['title'],
            'status' => $prevStatus,
            'assignee_login' => $current['assignee']
        ], [
            'id' => $id,
            'title' => $current['title'],
            'status' => $newStatus,
            'assignee_login' => $current['assignee']
        ], $user['login']);
    }
}

/**
 * Réaffecte une tâche à un autre membre.
 */
function tasksAssign(int $id, ?string $assignee, array $user): void {
    MusicbandRequirePermission('tasks.edit', $user);
    $current = tasksGet($id);
    if (!$current) throw new RuntimeException('Tâche introuvable.');

    if ($assignee !== null && $assignee !== '') {
        $users = MusicbandRead('users');
        if (!isset($users[$assignee])) {
            throw new RuntimeException('Utilisateur inconnu.');
        }
    } else {
        $assignee = null;
    }

    $db = tasksDb();
    $stmt = $db->prepare('UPDATE Musicbandai_tasks SET assignee = ?, updated_by = ?, updated_at = NOW(6) WHERE id = ?');
    $stmt->execute([$assignee, $user['login'], $id]);

    MusicbandAuditEvent('tasks', 'task.assigned', 'success', [
        'id' => (string)$id,
        'target' => $assignee ?? 'non_assigné'
    ]);
    if (function_exists('advancedNotificationDispatchEvent') && $assignee !== null) {
        advancedNotificationDispatchEvent('tasks.assign', 'tasks', (string)$id, [
            'id' => $id,
            'title' => $current['title'],
            'assignee_login' => $current['assignee']
        ], [
            'id' => $id,
            'title' => $current['title'],
            'assignee_login' => $assignee
        ], $user['login']);
    }
}

/**
 * Archive ou désarchive une tâche (suppression douce).
 */
function tasksArchive(int $id, bool $archive, array $user): void {
    MusicbandRequirePermission('tasks.edit', $user);
    $current = tasksGet($id);
    if (!$current) throw new RuntimeException('Tâche introuvable.');

    $db = tasksDb();
    $archivedAt = $archive ? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u') : null;
    $archivedBy = $archive ? $user['login'] : null;

    $stmt = $db->prepare('UPDATE Musicbandai_tasks SET archived = ?, archived_at = ?, archived_by = ?, updated_by = ?, updated_at = NOW(6) WHERE id = ?');
    $stmt->execute([(int)$archive, $archivedAt, $archivedBy, $user['login'], $id]);

    MusicbandAuditEvent('tasks', $archive ? 'task.archived' : 'task.restored', 'success', ['id' => (string)$id]);
}

/**
 * Supprime définitivement une tâche (administrateur uniquement avec confirmation).
 */
function tasksDelete(int $id, array $user): void {
    MusicbandRequirePermission('tasks.delete', $user);
    $current = tasksGet($id);
    if (!$current) throw new RuntimeException('Tâche introuvable.');

    $db = tasksDb();
    $stmt = $db->prepare('DELETE FROM Musicbandai_tasks WHERE id = ?');
    $stmt->execute([$id]);

    MusicbandAuditEvent('tasks', 'task.deleted', 'success', [
        'id' => (string)$id,
        'target' => $current['title']
    ]);
}

/**
 * Récupère l'historique d'audit d'une tâche depuis le journal global.
 */
function tasksHistory(int $taskId): array {
    $db = tasksDb();
    if (!MusicbandAuditReady($db)) return [];
    try {
        $stmt = $db->prepare("SELECT id, occurred_at, actor, action, outcome, details 
            FROM Musicbandai_audit_events 
            WHERE module = 'tasks' AND details LIKE ? 
            ORDER BY id DESC LIMIT 50");
        $stmt->execute(['%"id":"' . $taskId . '"%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

