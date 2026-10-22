<?php
/* Musicband TASKS: interface principale de gestion des tâches collaboratives.
 * Rôle : Affichage de la liste des tâches, cartes de synthèse, filtres et recherche repliable, tri interactif, détail d'une tâche avec historique d'audit, formulaires de création et de modification, actions rapides de statut.
 * Dépendances internes : auth.php, tasks_service.php, rbac.php, audit_trail.php.
 * Dépendances externes : HTML5, CSS responsive, JavaScript pour repli/tri/filtres progressifs.
 * Effets de bord : Modifications d'état des tâches et journalisation des événements.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tasks_service.php';

$user = MusicbandRequireLogin();
$canEdit = MusicbandCan('tasks.edit', $user);
$canDelete = MusicbandCan('tasks.delete', $user);
$db = tasksDb();
$schemaReady = tasksReady($db);

$notice = $_SESSION['tasks_notice'] ?? null;
unset($_SESSION['tasks_notice']);
$message = is_array($notice) ? (string)($notice['text'] ?? '') : '';
$isError = is_array($notice) ? (bool)($notice['error'] ?? false) : false;

// Traitement des requêtes POST (création, mise à jour, changement de statut, archivage, suppression)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        MusicbandCsrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            $newId = tasksCreate($_POST, $user);
            $_SESSION['tasks_notice'] = ['text' => 'Tâche créée avec succès.', 'error' => false];
            header('Location: ' . MusicbandBase() . 'tasks.php?id=' . $newId, true, 303);
            exit;
        }

        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Identifiant de tâche invalide.');
            tasksUpdate($id, $_POST, $user);
            $_SESSION['tasks_notice'] = ['text' => 'Tâche mise à jour.', 'error' => false];
            header('Location: ' . MusicbandBase() . 'tasks.php?id=' . $id, true, 303);
            exit;
        }

        if ($action === 'status') {
            $id = (int)($_POST['id'] ?? 0);
            $newStatus = (string)($_POST['status'] ?? '');
            if ($id <= 0) throw new RuntimeException('Identifiant de tâche invalide.');
            tasksChangeStatus($id, $newStatus, $user);
            $_SESSION['tasks_notice'] = ['text' => 'Statut de la tâche actualisé.', 'error' => false];
            $redirect = !empty($_POST['redirect_to_list']) ? MusicbandBase() . 'tasks.php' : MusicbandBase() . 'tasks.php?id=' . $id;
            header('Location: ' . $redirect, true, 303);
            exit;
        }

        if ($action === 'archive') {
            $id = (int)($_POST['id'] ?? 0);
            $archive = ($_POST['archive'] ?? '') === '1';
            if ($id <= 0) throw new RuntimeException('Identifiant de tâche invalide.');
            tasksArchive($id, $archive, $user);
            $_SESSION['tasks_notice'] = ['text' => $archive ? 'Tâche archivée.' : 'Tâche restaurée.', 'error' => false];
            header('Location: ' . MusicbandBase() . 'tasks.php', true, 303);
            exit;
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Identifiant de tâche invalide.');
            tasksDelete($id, $user);
            $_SESSION['tasks_notice'] = ['text' => 'Tâche définitivement supprimée.', 'error' => false];
            header('Location: ' . MusicbandBase() . 'tasks.php', true, 303);
            exit;
        }

        throw new RuntimeException('Action demandée inconnue.');
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $isError = true;
    }
}

// Données partagées pour les formulaires
$users = MusicbandRead('users');
$categories = tasksCategories(false);
$activeCategories = tasksCategories(true);
$hiddenCsrf = '<input type="hidden" name="csrf" value="' . MusicbandEscape($_SESSION['csrf']) . '">';

// Mode formulaire de création : ?action=new
$viewAction = (string)($_GET['action'] ?? '');
$taskId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;

// =========================================================================
// VUE 1 : FORMULAIRE DE CRÉATION DE TÂCHE
// =========================================================================
if ($viewAction === 'new') {
    if (!$canEdit) MusicbandFail('Droits insuffisants pour créer des tâches.', 403);
    if (!$schemaReady) MusicbandFail('Schéma non initialisé. Rendez-vous dans Upgrade.', 503);

    $content = '<div class="tasks-container"><p><a href="' . MusicbandEscape(MusicbandBase()) . 'tasks.php">← Retour à la liste des tâches</a></p>' .
        '<h2>Nouvelle tâche</h2>';

    if ($message) {
        $content .= '<p class="notice" role="' . ($isError ? 'alert' : 'status') . '">' . MusicbandEscape($message) . '</p>';
    }

    $content .= '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'tasks.php" class="task-form">' .
        $hiddenCsrf .
        '<input type="hidden" name="action" value="create">' .
        '<div class="task-form-group">' .
        '<label for="task-title">Titre <span class="required" aria-hidden="true">*</span></label>' .
        '<input id="task-title" name="title" type="text" required maxlength="255" autocomplete="off" placeholder="Description courte et claire de la tâche" value="' . MusicbandEscape($_POST['title'] ?? '') . '">' .
        '</div>' .
        '<div class="task-form-row">' .
        '<div class="task-form-group">' .
        '<label for="task-category">Catégorie <span class="required" aria-hidden="true">*</span></label>' .
        '<select id="task-category" name="category_id" required>' .
        '<option value="">Choisir une catégorie…</option>';

    foreach ($activeCategories as $cat) {
        $selected = ($_POST['category_id'] ?? '') === $cat['id'] ? ' selected' : '';
        $content .= '<option value="' . MusicbandEscape($cat['id']) . '"' . $selected . '>' . MusicbandEscape($cat['name']) . '</option>';
    }

    $content .= '</select></div>' .
        '<div class="task-form-group">' .
        '<label for="task-assignee">Affecté à</label>' .
        '<select id="task-assignee" name="assignee">' .
        '<option value="">Non assignée</option>';

    foreach ($users as $login => $info) {
        $selected = ($_POST['assignee'] ?? '') === $login ? ' selected' : '';
        $content .= '<option value="' . MusicbandEscape((string)$login) . '"' . $selected . '>' . MusicbandEscape((string)$login) . '</option>';
    }

    $content .= '</select></div>' .
        '<div class="task-form-group">' .
        '<label for="task-due-date">Échéance (facultatif)</label>' .
        '<input id="task-due-date" name="due_date" type="date" value="' . MusicbandEscape($_POST['due_date'] ?? '') . '">' .
        '</div></div>' .
        '<div class="task-form-group">' .
        '<label for="task-description">Description détaillée (facultatif)</label>' .
        '<textarea id="task-description" name="description" rows="5" placeholder="Précisions, instructions, liens utiles…">' . MusicbandEscape($_POST['description'] ?? '') . '</textarea>' .
        '</div>' .
        '<div class="task-form-actions">' .
        '<button type="submit" class="button-primary">Créer la tâche</button> ' .
        '<a href="' . MusicbandEscape(MusicbandBase()) . 'tasks.php" class="button button-secondary">Annuler</a>' .
        '</div></form></div>';

    MusicbandScreen('Nouvelle tâche', MusicbandStylesheet('business') . $content);
    exit;
}

// =========================================================================
// VUE 2 : DÉTAIL D'UNE TÂCHE (?id=123) OU ÉDITION (?id=123&action=edit)
// =========================================================================
if ($taskId !== null && $taskId > 0) {
    $task = tasksGet($taskId);
    if (!$task) {
        MusicbandFail('Tâche introuvable ou supprimée.', 404);
    }

    // Mode édition de la tâche
    if ($viewAction === 'edit') {
        if (!$canEdit) MusicbandFail('Droits insuffisants pour modifier les tâches.', 403);

        $content = '<div class="tasks-container"><p><a href="' . MusicbandEscape(MusicbandBase()) . 'tasks.php?id=' . $taskId . '">← Retour à la fiche tâche</a></p>' .
            '<h2>Modifier la tâche #' . $taskId . '</h2>';

        if ($message) {
            $content .= '<p class="notice" role="' . ($isError ? 'alert' : 'status') . '">' . MusicbandEscape($message) . '</p>';
        }

        $titleVal = $_POST['title'] ?? $task['title'];
        $catVal = $_POST['category_id'] ?? $task['category_id'];
        $assigneeVal = $_POST['assignee'] ?? $task['assignee'];
        $dueVal = $_POST['due_date'] ?? $task['due_date'];
        $descVal = $_POST['description'] ?? $task['description'];
        $statusVal = $_POST['status'] ?? $task['status'];

        $content .= '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'tasks.php" class="task-form">' .
            $hiddenCsrf .
            '<input type="hidden" name="action" value="update">' .
            '<input type="hidden" name="id" value="' . $taskId . '">' .
            '<div class="task-form-group">' .
            '<label for="task-title">Titre <span class="required" aria-hidden="true">*</span></label>' .
            '<input id="task-title" name="title" type="text" required maxlength="255" autocomplete="off" value="' . MusicbandEscape((string)$titleVal) . '">' .
            '</div>' .
            '<div class="task-form-row">' .
            '<div class="task-form-group">' .
            '<label for="task-category">Catégorie <span class="required" aria-hidden="true">*</span></label>' .
            '<select id="task-category" name="category_id" required>';

        // Inclure toutes les catégories actives, et la catégorie actuelle si désactivée
        foreach ($categories as $cat) {
            if (!$cat['enabled'] && $cat['id'] !== $catVal) continue;
            $selected = $catVal === $cat['id'] ? ' selected' : '';
            $disabledNote = !$cat['enabled'] ? ' (désactivée)' : '';
            $content .= '<option value="' . MusicbandEscape($cat['id']) . '"' . $selected . '>' . MusicbandEscape($cat['name'] . $disabledNote) . '</option>';
        }

        $content .= '</select></div>' .
            '<div class="task-form-group">' .
            '<label for="task-assignee">Affecté à</label>' .
            '<select id="task-assignee" name="assignee">' .
            '<option value="">Non assignée</option>';

        foreach ($users as $login => $info) {
            $selected = (string)$assigneeVal === (string)$login ? ' selected' : '';
            $content .= '<option value="' . MusicbandEscape((string)$login) . '"' . $selected . '>' . MusicbandEscape((string)$login) . '</option>';
        }

        $content .= '</select></div>' .
            '<div class="task-form-group">' .
            '<label for="task-due-date">Échéance</label>' .
            '<input id="task-due-date" name="due_date" type="date" value="' . MusicbandEscape((string)$dueVal) . '">' .
            '</div>' .
            '<div class="task-form-group">' .
            '<label for="task-status">Statut</label>' .
            '<select id="task-status" name="status">';

        foreach (TASKS_STATUSES as $k => $label) {
            $selected = $statusVal === $k ? ' selected' : '';
            $content .= '<option value="' . $k . '"' . $selected . '>' . MusicbandEscape($label) . '</option>';
        }

        $content .= '</select></div></div>' .
            '<div class="task-form-group">' .
            '<label for="task-description">Description détaillée</label>' .
            '<textarea id="task-description" name="description" rows="5">' . MusicbandEscape((string)$descVal) . '</textarea>' .
            '</div>' .
            '<div class="task-form-actions">' .
            '<button type="submit" class="button-primary">Enregistrer les modifications</button> ' .
            '<a href="' . MusicbandEscape(MusicbandBase()) . 'tasks.php?id=' . $taskId . '" class="button button-secondary">Annuler</a>' .
            '</div></form></div>';

        MusicbandScreen('Modifier : ' . $task['title'], MusicbandStylesheet('business') . $content);
        exit;
    }

    // Affichage fiche détaillée
    $statusKey = $task['status'];
    $statusLabel = TASKS_STATUSES[$statusKey] ?? $statusKey;
    $isOverdue = (bool)$task['is_overdue'];
    $isArchived = (bool)$task['archived'];

    $content = '<div class="tasks-container"><p><a href="' . MusicbandEscape(MusicbandBase()) . 'tasks.php">← Retour à la liste des tâches</a></p>';

    if ($message) {
        $content .= '<p class="notice" role="' . ($isError ? 'alert' : 'status') . '">' . MusicbandEscape($message) . '</p>';
    }

    $content .= '<div class="task-detail-header">' .
        '<div class="task-detail-title-row">' .
        '<h2>' . MusicbandEscape($task['title']) . '</h2>' .
        '<div class="task-detail-badges">' .
        '<span class="task-badge task-status-' . MusicbandEscape($statusKey) . '">' . MusicbandEscape($statusLabel) . '</span>';

    if ($isOverdue) {
        $content .= ' <span class="task-badge task-badge-overdue">En retard</span>';
    }
    if ($isArchived) {
        $content .= ' <span class="task-badge task-badge-archived">Archivée</span>';
    }

    $content .= '</div></div>';

    // Boutons d'action rapide sur le statut
    if ($canEdit) {
        $content .= '<div class="task-quick-actions">' .
            '<form method="post" style="display:inline-flex;gap:8px;flex-wrap:wrap;">' .
            $hiddenCsrf .
            '<input type="hidden" name="action" value="status">' .
            '<input type="hidden" name="id" value="' . $taskId . '">';

        if ($statusKey === 'a_faire') {
            $content .= '<button name="status" value="en_cours" class="button-action">Démarrer (En cours)</button>';
            $content .= '<button name="status" value="terminee" class="button-action button-success">Terminer</button>';
            $content .= '<button name="status" value="annulee" class="button-action button-muted">Annuler la tâche</button>';
        } elseif ($statusKey === 'en_cours') {
            $content .= '<button name="status" value="terminee" class="button-action button-success">Terminer la tâche</button>';
            $content .= '<button name="status" value="a_faire" class="button-action button-muted">Mettre en attente (À faire)</button>';
            $content .= '<button name="status" value="annulee" class="button-action button-muted">Annuler la tâche</button>';
        } elseif ($statusKey === 'terminee' || $statusKey === 'annulee') {
            $content .= '<button name="status" value="a_faire" class="button-action">Rouvrir la tâche</button>';
        }

        $content .= '</form>';

        // Liens d'édition et archivage
        $content .= '<div class="task-management-actions" style="margin-left:auto;display:inline-flex;gap:8px;">' .
            '<a href="' . MusicbandEscape(MusicbandBase()) . 'tasks.php?id=' . $taskId . '&amp;action=edit" class="button button-secondary">Modifier</a>';

        $content .= '<form method="post" style="display:inline;" onsubmit="return confirm(\'' . ($isArchived ? 'Désarchiver cette tâche ?' : 'Archiver cette tâche ?') . '\');">' .
            $hiddenCsrf .
            '<input type="hidden" name="action" value="archive">' .
            '<input type="hidden" name="id" value="' . $taskId . '">' .
            '<input type="hidden" name="archive" value="' . ($isArchived ? '0' : '1') . '">' .
            '<button class="button button-secondary">' . ($isArchived ? 'Restaurer' : 'Archiver') . '</button>' .
            '</form>';

        if ($canDelete) {
            $content .= '<form method="post" style="display:inline;" onsubmit="return confirm(\'ATTENTION : Supprimer définitivement cette tâche ? Cette action est irréversible.\');">' .
                $hiddenCsrf .
                '<input type="hidden" name="action" value="delete">' .
                '<input type="hidden" name="id" value="' . $taskId . '">' .
                '<button class="button button-danger">Supprimer</button>' .
                '</form>';
        }

        $content .= '</div></div>';
    }

    $content .= '</div>'; // Fin task-detail-header

    // Section détails et métadonnées
    $content .= '<div class="task-detail-grid">' .
        '<div class="task-detail-card task-detail-main">' .
        '<h3>Description</h3>';

    if (!empty($task['description'])) {
        $content .= '<div class="task-description-content">' . nl2br(MusicbandEscape($task['description'])) . '</div>';
    } else {
        $content .= '<p class="task-muted-text">Aucune description détaillée renseignée.</p>';
    }

    $content .= '</div>' .
        '<div class="task-detail-card task-detail-sidebar">' .
        '<h3>Informations</h3>' .
        '<dl class="task-meta-list">' .
        '<dt>Catégorie</dt><dd>' . MusicbandEscape($task['category_name'] ?: 'Non définie') . '</dd>' .
        '<dt>Affecté à</dt><dd>' . (!empty($task['assignee']) ? '<strong>' . MusicbandEscape($task['assignee']) . '</strong>' : '<span class="task-muted-text">Non assignée</span>') . '</dd>' .
        '<dt>Date d’ajout</dt><dd>' . MusicbandEscape(MusicbandDisplayDate($task['created_at'], true)) . ' par ' . MusicbandEscape($task['created_by']) . '</dd>' .
        '<dt>Échéance</dt><dd>' . (!empty($task['due_date']) ? MusicbandEscape(MusicbandDisplayDate($task['due_date'])) . ($isOverdue ? ' <span class="overdue-inline-tag">(en retard)</span>' : '') : '<span class="task-muted-text">Aucune</span>') . '</dd>' .
        '<dt>Statut</dt><dd><span class="task-badge task-status-' . MusicbandEscape($statusKey) . '">' . MusicbandEscape($statusLabel) . '</span></dd>';

    if ($task['completed_at'] !== null) {
        $content .= '<dt>Date de réalisation</dt><dd>' . MusicbandEscape(MusicbandDisplayDate($task['completed_at'], true)) . ' par ' . MusicbandEscape((string)$task['completed_by']) . '</dd>';
    }

    $content .= '<dt>Dernière modification</dt><dd>' . MusicbandEscape(MusicbandDisplayDate($task['updated_at'], true)) . ' par ' . MusicbandEscape($task['updated_by']) . '</dd>' .
        '</dl></div></div>';

    // Section Historique et Journal d'audit
    $history = tasksHistory($taskId);
    $content .= '<div class="task-detail-card" style="margin-top:20px;">' .
        '<h3>Historique des modifications</h3>';

    if (empty($history)) {
        $content .= '<p class="task-muted-text">Aucun événement d’audit spécifique enregistré pour cette tâche.</p>';
    } else {
        $content .= '<div class="business-table-scroll"><table style="width:100%;"><thead><tr>' .
            '<th>Date et heure</th><th>Auteur</th><th>Action</th><th>Détails</th></tr></thead><tbody>';

        $actionLabels = [
            'task.created' => 'Création',
            'task.edited' => 'Modification',
            'task.status_changed' => 'Changement de statut',
            'task.completed' => 'Tâche terminée',
            'task.reopened' => 'Tâche rouverte',
            'task.cancelled' => 'Tâche annulée',
            'task.assigned' => 'Affectation modifiée',
            'task.archived' => 'Tâche archivée',
            'task.restored' => 'Tâche restaurée',
            'task.deleted' => 'Suppression'
        ];

        foreach ($history as $h) {
            $act = $h['action'];
            $label = $actionLabels[$act] ?? $act;
            $details = json_decode((string)$h['details'], true) ?: [];
            $detailStr = '';
            if (!empty($details['fields'])) {
                $detailStr = 'Champs : ' . (is_array($details['fields']) ? implode(', ', $details['fields']) : (string)$details['fields']);
            } elseif (!empty($details['reason'])) {
                $detailStr = (string)$details['reason'];
            } elseif (!empty($details['target'])) {
                $detailStr = 'Cible : ' . (string)$details['target'];
            }

            $content .= '<tr>' .
                '<td><span class="Musicband-table-label">Date et heure</span><span class="Musicband-cell-value">' . MusicbandEscape(MusicbandDisplayDate($h['occurred_at'], true, true)) . '</span></td>' .
                '<td><span class="Musicband-table-label">Auteur</span><span class="Musicband-cell-value">' . MusicbandEscape((string)$h['actor']) . '</span></td>' .
                '<td><span class="Musicband-table-label">Action</span><span class="Musicband-cell-value">' . MusicbandEscape($label) . '</span></td>' .
                '<td><span class="Musicband-table-label">Détails</span><span class="Musicband-cell-value">' . MusicbandEscape($detailStr) . '</span></td>' .
                '</tr>';
        }

        $content .= '</tbody></table></div>';
    }

    $content .= '</div></div>';

    MusicbandScreen($task['title'], MusicbandStylesheet('business') . $content);
    exit;
}

// =========================================================================
// VUE 3 : LISTE PRINCIPALE DES TÂCHES
// =========================================================================
if (!$schemaReady) {
    $content = '<div class="tasks-container"><h2>Gestion des tâches</h2>' .
        '<p class="notice" role="alert">Le module de gestion des tâches n’est pas encore initialisé dans la base de données. ' .
        'Veuillez appliquer le <strong>schéma 016</strong> depuis la page <a href="' . MusicbandEscape(MusicbandBase()) . 'upgrade.php">Upgrade</a>.</p></div>';
    MusicbandScreen('Gestion des tâches', MusicbandStylesheet('business') . $content);
    exit;
}

// Récupération des filtres GET
$filterStatus = (string)($_GET['statut'] ?? '');
$filterCategory = (string)($_GET['categorie'] ?? '');
$filterAssignee = (string)($_GET['assigne'] ?? '');
$filterOverdue = !empty($_GET['en_retard']) ? '1' : '';
$filterMyTasks = !empty($_GET['mes_taches']) ? '1' : '';
$filterArchived = !empty($_GET['archives']) ? '1' : '';
$searchQuery = trim((string)($_GET['q'] ?? ''));

// Récupération du tri
$sortCol = (string)($_GET['sort'] ?? 'default');
$sortOrder = strtolower((string)($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Construction des critères pour tasksList
$listFilters = [
    'status' => $filterStatus,
    'category_id' => $filterCategory,
    'assignee' => $filterAssignee,
    'overdue' => $filterOverdue === '1',
    'mes_taches' => $filterMyTasks === '1',
    'current_user' => $user['login'],
    'q' => $searchQuery,
    'archived' => $filterArchived === '1' ? 1 : 0
];

$result = tasksList($listFilters, $sortCol, $sortOrder, $limit, $offset);
$tasks = $result['tasks'];
$totalTasks = $result['total'];
$totalPages = ceil($totalTasks / $limit);

// Compteurs de synthèse
$summary = tasksSummaryCounts();

// Détection d'un filtre actif pour le badge et le repli automatique
$hasActiveFilters = $filterStatus !== '' || $filterCategory !== '' || $filterAssignee !== '' || $filterOverdue !== '' || $filterMyTasks !== '' || $filterArchived !== '' || $searchQuery !== '';

// Construction du texte de résumé des filtres actifs
$filterSummaryLabels = [];
if ($searchQuery !== '') $filterSummaryLabels[] = '« ' . $searchQuery . ' »';
if ($filterMyTasks === '1') $filterSummaryLabels[] = 'Mes tâches';
if ($filterOverdue === '1') $filterSummaryLabels[] = 'En retard';
if ($filterStatus !== '' && isset(TASKS_STATUSES[$filterStatus])) $filterSummaryLabels[] = TASKS_STATUSES[$filterStatus];
if ($filterCategory !== '') {
    $catMap = tasksCategoriesMap();
    if (isset($catMap[$filterCategory])) $filterSummaryLabels[] = $catMap[$filterCategory];
}
if ($filterAssignee !== '') {
    $filterSummaryLabels[] = $filterAssignee === 'non_assigne' ? 'Non assignée' : $filterAssignee;
}
if ($filterArchived === '1') $filterSummaryLabels[] = 'Archives';

$summaryBadgeText = !empty($filterSummaryLabels) ? 'Filtres actifs (' . count($filterSummaryLabels) . ') : ' . implode(' · ', $filterSummaryLabels) : 'Aucun filtre actif';

// Construction de la page HTML
$content = '<div class="tasks-container">';

// En-tête principal
$content .= '<div class="tasks-header-bar">' .
    '<div><h1>Gestion des tâches</h1><p class="tasks-header-subtitle">Suivi collaboratif des tâches, échéances et responsabilités du groupe.</p></div>';

if ($canEdit) {
    $content .= '<div class="tasks-header-actions">' .
        '<a href="' . MusicbandEscape(MusicbandBase()) . 'tasks.php?action=new" class="button button-primary">＋ Nouvelle tâche</a>' .
        '</div>';
}
$content .= '</div>';

if ($message) {
    $content .= '<p class="notice" role="' . ($isError ? 'alert' : 'status') . '">' . MusicbandEscape($message) . '</p>';
}

// Indicateurs de synthèse
$isFilterAfaire = $filterStatus === 'a_faire' && !$filterOverdue;
$isFilterEnCours = $filterStatus === 'en_cours' && !$filterOverdue;
$isFilterOverdue = $filterOverdue === '1';
$isFilterTerminee = $filterStatus === 'terminee';

$content .= '<div class="tasks-summary" role="region" aria-label="Indicateurs de synthèse">' .
    '<a href="' . MusicbandEscape(MusicbandBase()) . 'tasks.php?statut=a_faire" class="tasks-summary-card' . ($isFilterAfaire ? ' is-active' : '') . '">' .
    '<span class="tasks-summary-count">' . $summary['a_faire'] . '</span>' .
    '<span class="tasks-summary-label">À faire</span>' .
    '</a>' .
    '<a href="' . MusicbandEscape(MusicbandBase()) . 'tasks.php?statut=en_cours" class="tasks-summary-card' . ($isFilterEnCours ? ' is-active' : '') . '">' .
    '<span class="tasks-summary-count">' . $summary['en_cours'] . '</span>' .
    '<span class="tasks-summary-label">En cours</span>' .
    '</a>' .
    '<a href="' . MusicbandEscape(MusicbandBase()) . 'tasks.php?en_retard=1" class="tasks-summary-card' . ($summary['overdue'] > 0 ? ' is-overdue' : '') . ($isFilterOverdue ? ' is-active' : '') . '">' .
    '<span class="tasks-summary-count">' . $summary['overdue'] . '</span>' .
    '<span class="tasks-summary-label">En retard</span>' .
    '</a>' .
    '<a href="' . MusicbandEscape(MusicbandBase()) . 'tasks.php?statut=terminee" class="tasks-summary-card' . ($isFilterTerminee ? ' is-active' : '') . '">' .
    '<span class="tasks-summary-count">' . $summary['terminee'] . '</span>' .
    '<span class="tasks-summary-label">Terminées</span>' .
    '</a>' .
    '</div>';

// Section de recherche et filtrage repliable par défaut
$chevronSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';
$quickReset = '<a href="' . MusicbandEscape(MusicbandBase()) . 'tasks.php" class="Musicband-search-quick-reset" title="Réinitialiser tous les filtres"' . ($hasActiveFilters ? '' : ' hidden') . '>Effacer</a>';

$content .= '<details class="Musicband-search-collapsible" id="tasks-search-panel">' .
    '<summary class="Musicband-search-header"><div class="Musicband-search-header-main">' .
    '<span class="Musicband-search-chevron">' . $chevronSvg . '</span>' .
    '<span class="Musicband-search-title">Recherche et filtres</span>' .
    '<span class="Musicband-search-summary-badge' . ($hasActiveFilters ? ' is-active' : '') . '" id="tasks-search-badge" role="status">' . MusicbandEscape($summaryBadgeText) . '</span>' .
    '</div>' . $quickReset . '</summary>' .
    '<div class="Musicband-search-body">' .
    '<form id="tasks-filter-form" method="get" action="' . MusicbandEscape(MusicbandBase()) . 'tasks.php" role="search">' .
    '<div class="tasks-filter-grid">' .
    '<div class="task-form-group">' .
    '<label for="filter-q">Recherche libre</label>' .
    '<input id="filter-q" type="search" name="q" placeholder="Mots-clés, titre, détails…" value="' . MusicbandEscape($searchQuery) . '" autocomplete="off">' .
    '</div>' .
    '<div class="task-form-group">' .
    '<label for="filter-status">Statut</label>' .
    '<select id="filter-status" name="statut">' .
    '<option value="">Tous les statuts</option>';

foreach (TASKS_STATUSES as $k => $label) {
    $sel = $filterStatus === $k ? ' selected' : '';
    $content .= '<option value="' . $k . '"' . $sel . '>' . MusicbandEscape($label) . '</option>';
}

$content .= '</select></div>' .
    '<div class="task-form-group">' .
    '<label for="filter-category">Catégorie</label>' .
    '<select id="filter-category" name="categorie">' .
    '<option value="">Toutes les catégories</option>';

foreach ($categories as $cat) {
    $sel = $filterCategory === $cat['id'] ? ' selected' : '';
    $content .= '<option value="' . MusicbandEscape($cat['id']) . '"' . $sel . '>' . MusicbandEscape($cat['name']) . '</option>';
}

$content .= '</select></div>' .
    '<div class="task-form-group">' .
    '<label for="filter-assignee">Affecté à</label>' .
    '<select id="filter-assignee" name="assigne">' .
    '<option value="">Tous les membres</option>' .
    '<option value="non_assigne"' . ($filterAssignee === 'non_assigne' ? ' selected' : '') . '>Non assignée</option>';

foreach ($users as $login => $info) {
    $sel = $filterAssignee === $login ? ' selected' : '';
    $content .= '<option value="' . MusicbandEscape((string)$login) . '"' . $sel . '>' . MusicbandEscape((string)$login) . '</option>';
}

$content .= '</select></div></div>' .
    '<div class="tasks-filter-checkboxes">' .
    '<label class="tasks-checkbox-label"><input type="checkbox" name="mes_taches" value="1"' . ($filterMyTasks === '1' ? ' checked' : '') . '> 👤 Mes tâches uniquement</label>' .
    '<label class="tasks-checkbox-label"><input type="checkbox" name="en_retard" value="1"' . ($filterOverdue === '1' ? ' checked' : '') . '> ⚠️ Tâches en retard</label>' .
    '<label class="tasks-checkbox-label"><input type="checkbox" name="archives" value="1"' . ($filterArchived === '1' ? ' checked' : '') . '> 📦 Afficher les tâches archivées</label>' .
    '</div>' .
    '<div class="tasks-filter-actions">' .
    '<button type="submit" class="button-primary">Appliquer les filtres</button>' .
    ' <a href="' . MusicbandEscape(MusicbandBase()) . 'tasks.php" class="button button-secondary">Réinitialiser</a>' .
    '</div></form></div></details>';

// Fonction d'URL pour le tri cliquable
$buildSortUrl = function(string $col) use ($sortCol, $sortOrder, $listFilters) {
    $params = $_GET;
    $params['sort'] = $col;
    if ($sortCol === $col) {
        $params['order'] = $sortOrder === 'asc' ? 'desc' : 'asc';
    } else {
        $params['order'] = 'asc';
    }
    unset($params['page']);
    return MusicbandEscape(MusicbandBase() . 'tasks.php?' . http_build_query($params));
};

$sortIndicator = function(string $col) use ($sortCol, $sortOrder) {
    if ($sortCol !== $col) return ' <span class="sort-icon-neutral" aria-hidden="true">↕</span>';
    return $sortOrder === 'desc' ? ' <span class="sort-icon-active" aria-hidden="true">▼</span>' : ' <span class="sort-icon-active" aria-hidden="true">▲</span>';
};

// Tableau des résultats
$content .= '<div class="tasks-table-wrapper">' .
    '<div class="tasks-table-header-info">' .
    '<span class="tasks-count-badge">' . $totalTasks . ' tâche' . ($totalTasks > 1 ? 's' : '') . '</span>' .
    '</div>';

if (empty($tasks)) {
    $content .= '<div class="tasks-empty-state"><p>Aucune tâche ne correspond aux critères sélectionnés.</p></div>';
} else {
    $content .= '<div class="business-table-scroll"><table class="tasks-table"><thead><tr>' .
        '<th scope="col"><a href="' . $buildSortUrl('title') . '" class="sortable-header">Tâche' . $sortIndicator('title') . '</a></th>' .
        '<th scope="col"><a href="' . $buildSortUrl('category') . '" class="sortable-header">Catégorie' . $sortIndicator('category') . '</a></th>' .
        '<th scope="col"><a href="' . $buildSortUrl('assignee') . '" class="sortable-header">Affecté à' . $sortIndicator('assignee') . '</a></th>' .
        '<th scope="col"><a href="' . $buildSortUrl('created_at') . '" class="sortable-header">Ajout' . $sortIndicator('created_at') . '</a></th>' .
        '<th scope="col"><a href="' . $buildSortUrl('due_date') . '" class="sortable-header">Échéance' . $sortIndicator('due_date') . '</a></th>' .
        '<th scope="col"><a href="' . $buildSortUrl('status') . '" class="sortable-header">Statut' . $sortIndicator('status') . '</a></th>' .
        '<th scope="col">Actions</th>' .
        '</tr></thead><tbody>';

    foreach ($tasks as $t) {
        $id = (int)$t['id'];
        $sKey = $t['status'];
        $sLabel = TASKS_STATUSES[$sKey] ?? $sKey;
        $overdue = (bool)$t['is_overdue'];
        $detailUrl = MusicbandEscape(MusicbandBase() . 'tasks.php?id=' . $id);

        $content .= '<tr>' .
            '<td><span class="Musicband-table-label">Tâche</span><span class="Musicband-cell-value">' .
            '<a href="' . $detailUrl . '" class="task-title-link"><strong>' . MusicbandEscape($t['title']) . '</strong></a>' .
            '</span></td>' .
            '<td><span class="Musicband-table-label">Catégorie</span><span class="Musicband-cell-value">' .
            '<span class="task-category-pill">' . MusicbandEscape($t['category_name'] ?: 'Non définie') . '</span>' .
            '</span></td>' .
            '<td><span class="Musicband-table-label">Affecté à</span><span class="Musicband-cell-value">' .
            (!empty($t['assignee']) ? MusicbandEscape($t['assignee']) : '<span class="task-muted-text">Non assignée</span>') .
            '</span></td>' .
            '<td><span class="Musicband-table-label">Ajout</span><span class="Musicband-cell-value">' .
            MusicbandEscape(MusicbandDisplayDate($t['created_at'])) .
            '</span></td>' .
            '<td><span class="Musicband-table-label">Échéance</span><span class="Musicband-cell-value">' .
            (!empty($t['due_date']) ? MusicbandEscape(MusicbandDisplayDate($t['due_date'])) : '<span class="task-muted-text">—</span>') .
            ($overdue ? ' <span class="task-overdue-tag">En retard</span>' : '') .
            '</span></td>' .
            '<td><span class="Musicband-table-label">Statut</span><span class="Musicband-cell-value">' .
            '<span class="task-badge task-status-' . MusicbandEscape($sKey) . '">' . MusicbandEscape($sLabel) . '</span>' .
            '</span></td>' .
            '<td><span class="Musicband-table-label">Actions</span><span class="Musicband-cell-value task-row-actions">';

        // Actions rapides selon statut
        if ($canEdit) {
            $content .= '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'tasks.php" style="display:inline-flex;gap:4px;">' .
                $hiddenCsrf .
                '<input type="hidden" name="action" value="status">' .
                '<input type="hidden" name="id" value="' . $id . '">' .
                '<input type="hidden" name="redirect_to_list" value="1">';

            if ($sKey === 'a_faire') {
                $content .= '<button name="status" value="en_cours" class="task-action-btn" title="Démarrer la tâche">Démarrer</button>';
                $content .= '<button name="status" value="terminee" class="task-action-btn task-btn-done" title="Marquer terminée">Terminer</button>';
            } elseif ($sKey === 'en_cours') {
                $content .= '<button name="status" value="terminee" class="task-action-btn task-btn-done" title="Marquer terminée">Terminer</button>';
            } elseif ($sKey === 'terminee' || $sKey === 'annulee') {
                $content .= '<button name="status" value="a_faire" class="task-action-btn" title="Rouvrir la tâche">Rouvrir</button>';
            }

            $content .= '</form>';
        }

        $content .= '<a href="' . $detailUrl . '" class="task-action-btn" title="Consulter la fiche détaillée">Détails</a>' .
            '</span></td></tr>';
    }

    $content .= '</tbody></table></div>';

    // Pagination
    if ($totalPages > 1) {
        $content .= '<div class="business-pagination" role="navigation" aria-label="Pagination">';
        $queryBase = $_GET;
        if ($page > 1) {
            $queryBase['page'] = $page - 1;
            $content .= '<a href="' . MusicbandEscape(MusicbandBase() . 'tasks.php?' . http_build_query($queryBase)) . '">← Précédent</a> ';
        }
        $content .= '<span>Page ' . $page . ' sur ' . $totalPages . '</span> ';
        if ($page < $totalPages) {
            $queryBase['page'] = $page + 1;
            $content .= '<a href="' . MusicbandEscape(MusicbandBase() . 'tasks.php?' . http_build_query($queryBase)) . '">Suivant →</a>';
        }
        $content .= '</div>';
    }
}

$content .= '</div></div>'; // Fin tasks-table-wrapper et tasks-container

// Script de repli automatique lors de la soumission de la recherche
$script = <<<'JS'
(() => {
  const panel = document.getElementById('tasks-search-panel');
  const form = document.getElementById('tasks-filter-form');
  if (panel && form) {
    form.addEventListener('submit', () => {
      // Si une recherche est envoyée, replier le panneau s'il n'y a pas de préférence de réduction de mouvement
      if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        panel.removeAttribute('open');
      }
    });
  }
})();
JS;

MusicbandScreen('Gestion des tâches', MusicbandStylesheet('business') . $content, $script);

