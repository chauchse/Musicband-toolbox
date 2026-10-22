<?php
/* Musicband ADVANCED NOTIFICATIONS SERVICE: moteur de notifications PWA avancées, règles personnalisées et rappels d'échéances. */
/**
 * Musicband ADVANCED NOTIFICATIONS SERVICE
 *
 * Moteur de notifications PWA avancées, règles personnalisées et rappels d'échéances.
 *
 * Responsabilités :
 * - Catalogue centralisé des événements métier adossé aux permissions RBAC (MusicbandCan).
 * - Moteur déclaratif d'évaluation des conditions au niveau des champs (ancienne / nouvelle valeur).
 * - Gestion des 3 niveaux d'activation indépendants (autorisation admin, activation globale utilisateur, règle individuelle).
 * - Gestion des règles par défaut (modèles admin, copie par utilisateur, révision, réinitialisation).
 * - Gestion des règles personnalisées (création, modification, duplication, activation, suppression).
 * - Planificateur de rappels temporels (Concerts, Répétitions Agenda, Échéances de tâches).
 * - Déduplication, idempotence, journalisation d'audit et distribution Web Push.
 *
 * Dépendances internes :
 * - auth.php (sessions, stockage privé atomique MusicbandRead/MusicbandWrite/MusicbandLocked, audit)
 * - rbac.php (MusicbandCan, catalogue de permissions, rôles)
 * - notifications.php (VAPID, abonnements push, Musicbandai_push_subscriptions)
 * - database.php (connexion MySQL)
 * - business.php (fuseau horaire, dates de concerts)
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/database.php';

/**
 * Limites de ressources pour la protection du serveur.
 */
const ADV_NOTIF_MAX_USER_RULES = 50;
const ADV_NOTIF_MAX_CONDITIONS = 5;
const ADV_NOTIF_MAX_RULE_NAME_LEN = 100;

// -----------------------------------------------------------------------------
// 1. CONTRÔLE D'ACCÈS AUX 3 NIVEAUX D'ACTIVATION
// -----------------------------------------------------------------------------

/**
 * Niveau 1 — Vérifie si l'administrateur autorise les notifications avancées pour cet utilisateur.
 * Par défaut : false pour tous les utilisateurs existants et nouveaux.
 */
function advancedNotificationsUserAllowed(string $login): bool {
    if ($login === '') return false;
    $users = MusicbandRead('users');
    return !empty($users[$login]['advanced_notifications_allowed']);
}

/**
 * Niveau 1 — Modifie l'autorisation d'un utilisateur par un administrateur.
 * Exige les droits d'administration des notifications.
 */
function advancedNotificationsSetUserAllowed(string $login, bool $allowed, array $adminUser): void {
    if (!($adminUser['admin'] ?? false) && !MusicbandCan('notifications.configure', $adminUser)) {
        throw new RuntimeException('Droits insuffisants pour modifier l’autorisation des notifications.');
    }
    MusicbandLocked(function() use ($login, $allowed) {
        $users = MusicbandRead('users');
        if (!isset($users[$login])) {
            throw new RuntimeException('Utilisateur introuvable.');
        }
        $users[$login]['advanced_notifications_allowed'] = $allowed;
        MusicbandWrite('users', $users);
    });
    if (function_exists('MusicbandAuditEvent')) {
        MusicbandAuditEvent('configuration', 'notifications.authorize', 'success', [
            'target' => $login,
            'reason' => $allowed ? 'authorized' : 'revoked'
        ]);
    }
}

/**
 * Niveau 2 — Vérifie si un utilisateur a activé globalement ses notifications.
 * Par défaut : false.
 */
function advancedNotificationsUserGloballyEnabled(string $login): bool {
    if (!advancedNotificationsUserAllowed($login)) return false;
    $store = MusicbandRead('notification_user_rules');
    return !empty($store[$login]['enabled']);
}

/**
 * Niveau 2 — Active ou désactive globalement les notifications pour l'utilisateur connecté.
 */
function advancedNotificationsSetUserGloballyEnabled(string $login, bool $enabled): void {
    if (!advancedNotificationsUserAllowed($login)) {
        throw new RuntimeException('Fonctionnalité non autorisée par un administrateur.');
    }
    MusicbandLocked(function() use ($login, $enabled) {
        $store = MusicbandRead('notification_user_rules');
        $userConfig = $store[$login] ?? [];

        // Premier allumage : copier le jeu de règles par défaut de l'administrateur
        if ($enabled && empty($userConfig['initialized'])) {
            $defaultData = advancedNotificationsGetDefaultRuleset();
            $userConfig['default_rules'] = $defaultData['rules'];
            $userConfig['default_revision'] = $defaultData['revision'];
            $userConfig['custom_rules'] = [];
            $userConfig['initialized'] = true;
        }

        $userConfig['enabled'] = $enabled;
        $userConfig['updated_at'] = gmdate('c');
        $store[$login] = $userConfig;
        MusicbandWrite('notification_user_rules', $store);
    });

    if (function_exists('MusicbandAuditEvent')) {
        MusicbandAuditEvent('notifications', $enabled ? 'user.activated' : 'user.deactivated', 'success', [
            'target' => $login
        ]);
    }
}

/**
 * Vérifie si un compte utilisateur dispose du rôle administrateur.
 */
function advancedNotificationsIsAdmin(string $login, ?array $account = null): bool {
    if ($account === null) {
        $users = MusicbandRead('users');
        $account = $users[$login] ?? [];
    }
    if (!empty($account['admin'])) return true;
    if (function_exists('MusicbandRbacRole')) {
        return MusicbandRbacRole($login, $account) === 'admin';
    }
    return false;
}

/**
 * Indique si un administrateur a activé la notification lors des connexions utilisateurs.
 * Désactivé par défaut.
 */
function advancedNotificationsAdminLoginNotificationsEnabled(string $login): bool {
    if (!advancedNotificationsIsAdmin($login)) return false;
    $store = MusicbandRead('notification_user_rules');
    return !empty($store[$login]['notify_login']);
}

/**
 * Active ou désactive la notification de connexion pour un compte administrateur.
 */
function advancedNotificationsSetAdminLoginNotifications(string $login, bool $enabled): void {
    if (!advancedNotificationsIsAdmin($login)) {
        throw new RuntimeException('Ce réglage est réservé aux administrateurs.');
    }
    MusicbandLocked(function() use ($login, $enabled) {
        $store = MusicbandRead('notification_user_rules');
        $userConfig = $store[$login] ?? [];
        $userConfig['notify_login'] = $enabled;
        $userConfig['updated_at'] = gmdate('c');
        $store[$login] = $userConfig;
        MusicbandWrite('notification_user_rules', $store);
    });

    if (function_exists('MusicbandAuditEvent')) {
        MusicbandAuditEvent('notifications', $enabled ? 'admin.login_notif.enabled' : 'admin.login_notif.disabled', 'success', [
            'target' => $login
        ]);
    }
}

// -----------------------------------------------------------------------------
// 2. CATALOGUE DES ÉVÉNEMENTS MÉTIER ET PERMISSIONS RBAC
// -----------------------------------------------------------------------------

/**
 * Catalogue centralisé des événements de notification compatibles avec le RBAC.
 */
function advancedNotificationEventCatalog(): array {
    $catalog = [
        'songs.edit' => [
            'module' => 'songs',
            'action_label' => 'Modification d’un morceau',
            'permission' => 'songs.edit',
            'description' => 'Déclenché lorsqu’un morceau du catalogue est modifié.',
            'reminders_supported' => false,
            'fields' => [
                'title' => ['label' => 'Titre', 'type' => 'text'],
                'artist' => ['label' => 'Artiste', 'type' => 'text'],
                'next_rehearsal_work' => ['label' => 'À travailler pour la prochaine répète', 'type' => 'bool'],
                'concert_ready' => ['label' => 'Prêt pour le concert', 'type' => 'bool'],
                'tempo' => ['label' => 'Tempo', 'type' => 'int'],
                'musical_key' => ['label' => 'Tonalité', 'type' => 'text'],
                'energy' => ['label' => 'Dynamique', 'type' => 'int'],
                'guitar' => ['label' => 'Guitare (H/S)', 'type' => 'text'],
                'rehearsal_tested' => ['label' => 'Testé en répétition', 'type' => 'bool'],
            ],
            'path_builder' => fn(array $d) => 'songs.php?id=' . (int)($d['id'] ?? 0),
            'payload_builder' => function(array $b, array $a) {
                $title = (string)($a['title'] ?? 'Morceau');
                $artist = (string)($a['artist'] ?? '');
                $sub = $artist !== '' ? " — $artist" : '';
                if (!empty($a['next_rehearsal_work']) && empty($b['next_rehearsal_work'])) {
                    return [
                        'title' => 'Morceau à travailler',
                        'body'  => "« $title$sub » vient d'être marqué À travailler."
                    ];
                }
                return [
                    'title' => 'Morceau mis à jour',
                    'body'  => "Le morceau « $title$sub » a été modifié."
                ];
            }
        ],
        'songs.create' => [
            'module' => 'songs',
            'action_label' => 'Ajout d’un nouveau morceau',
            'permission' => 'songs.edit',
            'description' => 'Déclenché lors de l’ajout d’un morceau au catalogue.',
            'reminders_supported' => false,
            'fields' => [
                'title' => ['label' => 'Titre', 'type' => 'text'],
                'artist' => ['label' => 'Artiste', 'type' => 'text'],
                'next_rehearsal_work' => ['label' => 'À travailler', 'type' => 'bool'],
                'concert_ready' => ['label' => 'Prêt pour le concert', 'type' => 'bool'],
            ],
            'path_builder' => fn(array $d) => 'songs.php?id=' . (int)($d['id'] ?? 0),
            'payload_builder' => function(array $b, array $a) {
                $title = (string)($a['title'] ?? 'Nouveau morceau');
                $artist = (string)($a['artist'] ?? '');
                $sub = $artist !== '' ? " — $artist" : '';
                return [
                    'title' => 'Nouveau morceau au catalogue',
                    'body'  => "« $title$sub » a été ajouté au répertoire."
                ];
            }
        ],
        'songs.delete' => [
            'module' => 'songs',
            'action_label' => 'Suppression d’un morceau',
            'permission' => 'songs.delete',
            'description' => 'Déclenché lors de la suppression d’un morceau non lié.',
            'reminders_supported' => false,
            'fields' => [
                'title' => ['label' => 'Titre', 'type' => 'text'],
                'artist' => ['label' => 'Artiste', 'type' => 'text'],
            ],
            'path_builder' => fn(array $d) => 'songs.php',
            'payload_builder' => function(array $b, array $a) {
                $title = (string)($b['title'] ?? 'Morceau');
                return [
                    'title' => 'Morceau retiré du catalogue',
                    'body'  => "Le morceau « $title » a été supprimé du catalogue."
                ];
            }
        ],
        'concerts.create' => [
            'module' => 'concerts',
            'action_label' => 'Création d’un concert',
            'permission' => 'concerts.edit',
            'description' => 'Déclenché lors de la saisie d’une nouvelle date de concert.',
            'reminders_supported' => true,
            'date_field' => 'date_concert',
            'time_field' => 'heure_prestation',
            'fields' => [
                'evenement' => ['label' => 'Nom de l’événement', 'type' => 'text'],
                'ville' => ['label' => 'Ville / Lieu', 'type' => 'text'],
                'date_concert' => ['label' => 'Date du concert', 'type' => 'date'],
                'statut' => ['label' => 'Statut', 'type' => 'text'],
            ],
            'path_builder' => fn(array $d) => 'concerts.php?id=' . (int)($d['id'] ?? 0),
            'payload_builder' => function(array $b, array $a) {
                $evt = (string)($a['evenement'] ?? 'Nouveau concert');
                $lieu = (string)($a['ville'] ?? '');
                $when = !empty($a['date_concert']) ? MusicbandDisplayDate((string)$a['date_concert']) : '';
                $body = "Concert « $evt » programmé" . ($when !== '' ? " le $when" : '') . ($lieu !== '' ? " à $lieu" : '') . '.';
                return ['title' => 'Nouveau concert programmé', 'body' => $body];
            }
        ],
        'concerts.edit' => [
            'module' => 'concerts',
            'action_label' => 'Modification d’un concert',
            'permission' => 'concerts.edit',
            'description' => 'Déclenché lors de la mise à jour des détails d’un concert.',
            'reminders_supported' => true,
            'date_field' => 'date_concert',
            'time_field' => 'heure_prestation',
            'fields' => [
                'evenement' => ['label' => 'Nom de l’événement', 'type' => 'text'],
                'ville' => ['label' => 'Ville / Lieu', 'type' => 'text'],
                'date_concert' => ['label' => 'Date du concert', 'type' => 'date'],
                'heure_arrivee' => ['label' => 'Heure d’arrivée', 'type' => 'text'],
                'heure_prestation' => ['label' => 'Heure de prestation', 'type' => 'text'],
                'statut' => ['label' => 'Statut', 'type' => 'text'],
                'montant' => ['label' => 'Montant', 'type' => 'text'],
            ],
            'path_builder' => fn(array $d) => 'concerts.php?id=' . (int)($d['id'] ?? 0),
            'payload_builder' => function(array $b, array $a) {
                $evt = (string)($a['evenement'] ?? 'Concert');
                return [
                    'title' => 'Fiche concert modifiée',
                    'body'  => "Les informations du concert « $evt » ont été mises à jour."
                ];
            }
        ],
        'concerts.reminder' => [
            'module' => 'concerts',
            'action_label' => 'Rappel planifié avant concert',
            'permission' => 'concerts.edit',
            'description' => 'Notification envoyée un délai configuré avant le concert.',
            'reminders_supported' => true,
            'date_field' => 'date_concert',
            'time_field' => 'heure_prestation',
            'fields' => [
                'evenement' => ['label' => 'Nom de l’événement', 'type' => 'text'],
                'ville' => ['label' => 'Ville / Lieu', 'type' => 'text'],
                'statut' => ['label' => 'Statut', 'type' => 'text'],
            ],
            'path_builder' => fn(array $d) => 'concerts.php?id=' . (int)($d['id'] ?? 0),
            'payload_builder' => function(array $b, array $a, string $offsetLabel = '') {
                $evt = (string)($a['evenement'] ?? 'Concert');
                $lieu = (string)($a['ville'] ?? '');
                $lieuPart = $lieu !== '' ? " à $lieu" : '';
                $body = "Rappel : concert « $evt »" . ($offsetLabel !== '' ? " dans $offsetLabel" : '') . "$lieuPart.";
                return ['title' => 'Rappel concert', 'body' => $body];
            }
        ],
        'agenda.rehearsal' => [
            'module' => 'agenda',
            'action_label' => 'Rappel de répétition',
            'permission' => 'calendar.read',
            'description' => 'Notification envoyée un délai configuré avant une répétition.',
            'reminders_supported' => true,
            'fields' => [
                'summary' => ['label' => 'Titre de l’événement', 'type' => 'text'],
                'location' => ['label' => 'Lieu', 'type' => 'text'],
            ],
            'path_builder' => fn(array $d) => 'calendar.php',
            'payload_builder' => function(array $b, array $a, string $offsetLabel = '') {
                $title = (string)($a['summary'] ?? 'Répétition Musicband');
                $body = $offsetLabel !== '' ? "Répétition dans $offsetLabel." : "Répétition prévue prochainement.";
                return ['title' => 'Rappel répétition', 'body' => $body];
            }
        ],
        'setlist.validate' => [
            'module' => 'setlist',
            'action_label' => 'Setlist validée et figée',
            'permission' => 'setlist.edit',
            'description' => 'Déclenché lorsqu’une setlist est officiellement validée pour un concert.',
            'reminders_supported' => false,
            'fields' => [
                'publication' => ['label' => 'Nom du fichier publication', 'type' => 'text'],
            ],
            'path_builder' => fn(array $d) => 'setlist/?publication=' . rawurlencode((string)($d['publication'] ?? '')),
            'payload_builder' => function(array $b, array $a) {
                $pub = (string)($a['publication'] ?? 'Setlist');
                return [
                    'title' => 'Setlist validée',
                    'body'  => "La setlist « $pub » a été officiellement validée et figée."
                ];
            }
        ],
        'tasks.create' => [
            'module' => 'tasks',
            'action_label' => 'Création d’une tâche',
            'permission' => 'tasks.read',
            'description' => 'Déclenché lors de la création d’une nouvelle tâche collaborative.',
            'reminders_supported' => false,
            'fields' => [
                'title' => ['label' => 'Titre de la tâche', 'type' => 'text'],
                'category_name' => ['label' => 'Catégorie', 'type' => 'text'],
                'priority' => ['label' => 'Priorité', 'type' => 'text'],
                'assignee_login' => ['label' => 'Assigné à', 'type' => 'text'],
                'due_date' => ['label' => 'Date d’échéance', 'type' => 'date'],
            ],
            'path_builder' => fn(array $d) => 'tasks.php',
            'payload_builder' => function(array $b, array $a) {
                $title = (string)($a['title'] ?? 'Nouvelle tâche');
                return [
                    'title' => 'Nouvelle tâche créée',
                    'body'  => "La tâche « $title » a été ajoutée."
                ];
            }
        ],
        'tasks.status' => [
            'module' => 'tasks',
            'action_label' => 'Changement de statut de tâche',
            'permission' => 'tasks.read',
            'description' => 'Déclenché lorsqu’une tâche passe à faire, en cours ou terminée.',
            'reminders_supported' => false,
            'fields' => [
                'title' => ['label' => 'Titre de la tâche', 'type' => 'text'],
                'status' => ['label' => 'Nouveau statut', 'type' => 'text'],
                'assignee_login' => ['label' => 'Assigné à', 'type' => 'text'],
            ],
            'path_builder' => fn(array $d) => 'tasks.php',
            'payload_builder' => function(array $b, array $a) {
                $title = (string)($a['title'] ?? 'Tâche');
                $st = (string)($a['status'] ?? '');
                $statusLabels = ['a_faire' => 'À faire', 'en_cours' => 'En cours', 'terminee' => 'Terminée', 'annulee' => 'Annulée'];
                $stLabel = $statusLabels[$st] ?? $st;
                return [
                    'title' => 'Statut de tâche mis à jour',
                    'body'  => "La tâche « $title » est désormais : $stLabel."
                ];
            }
        ],
        'tasks.assign' => [
            'module' => 'tasks',
            'action_label' => 'Assignation d’une tâche',
            'permission' => 'tasks.read',
            'description' => 'Déclenché lorsqu’une tâche vous est assignée ou réassignée.',
            'reminders_supported' => false,
            'fields' => [
                'title' => ['label' => 'Titre de la tâche', 'type' => 'text'],
                'assignee_login' => ['label' => 'Assigné à', 'type' => 'text'],
            ],
            'path_builder' => fn(array $d) => 'tasks.php?mes_taches=1',
            'payload_builder' => function(array $b, array $a) {
                $title = (string)($a['title'] ?? 'Tâche');
                $assignee = (string)($a['assignee_login'] ?? '');
                return [
                    'title' => 'Tâche assignée',
                    'body'  => "Vous avez été assigné(e) à la tâche « $title »."
                ];
            }
        ],
        'tasks.reminder' => [
            'module' => 'tasks',
            'action_label' => 'Rappel d’échéance de tâche',
            'permission' => 'tasks.read',
            'description' => 'Notification envoyée un délai configuré avant la date limite d’une tâche.',
            'reminders_supported' => true,
            'date_field' => 'due_date',
            'time_field' => 'due_time',
            'fields' => [
                'title' => ['label' => 'Titre de la tâche', 'type' => 'text'],
                'assignee_login' => ['label' => 'Assigné à', 'type' => 'text'],
                'status' => ['label' => 'Statut', 'type' => 'text'],
            ],
            'path_builder' => fn(array $d) => 'tasks.php?mes_taches=1',
            'payload_builder' => function(array $b, array $a, string $offsetLabel = '') {
                $title = (string)($a['title'] ?? 'Tâche');
                $when = $offsetLabel !== '' ? "dans $offsetLabel" : "prochainement";
                return [
                    'title' => 'Échéance de tâche',
                    'body'  => "Votre tâche « $title » arrive à échéance $when."
                ];
            }
        ],
        'contacts.edit' => [
            'module' => 'contacts',
            'action_label' => 'Création ou modification d’un contact',
            'permission' => 'contacts.edit',
            'description' => 'Déclenché lors de la création ou mise à jour d’une fiche contact.',
            'reminders_supported' => false,
            'fields' => [
                'nom' => ['label' => 'Nom du contact', 'type' => 'text'],
                'ville' => ['label' => 'Ville', 'type' => 'text'],
                'telephone' => ['label' => 'Téléphone', 'type' => 'text'],
                'email' => ['label' => 'E-mail', 'type' => 'text'],
            ],
            'path_builder' => fn(array $d) => 'contacts.php?id=' . (int)($d['id'] ?? 0),
            'payload_builder' => function(array $b, array $a) {
                $nom = (string)($a['nom'] ?? 'Contact');
                return [
                    'title' => 'Contact mis à jour',
                    'body'  => "La fiche du contact « $nom » a été mise à jour."
                ];
            }
        ],
        'documents.validate' => [
            'module' => 'devis_factures',
            'action_label' => 'Validation d’un devis ou facture',
            'permission' => 'documents.edit',
            'description' => 'Déclenché lors de la validation d’un devis ou d’une facture.',
            'reminders_supported' => false,
            'fields' => [
                'numero' => ['label' => 'Numéro de document', 'type' => 'text'],
                'client' => ['label' => 'Client', 'type' => 'text'],
                'montant_total' => ['label' => 'Montant total', 'type' => 'text'],
            ],
            'path_builder' => fn(array $d) => 'devis_factures.php?id=' . (int)($d['id'] ?? 0),
            'payload_builder' => function(array $b, array $a) {
                $num = (string)($a['numero'] ?? 'Document');
                $cli = (string)($a['client'] ?? '');
                $cliPart = $cli !== '' ? " pour $cli" : '';
                return [
                    'title' => 'Document validé',
                    'body'  => "Le document « $num »$cliPart a été validé."
                ];
            }
        ],
        'auth.login' => [
            'module' => 'auth',
            'action_label' => 'Connexion d’un utilisateur',
            'permission' => 'notifications.configure',
            'description' => 'Déclenché lorsqu’un utilisateur s’authentifie avec succès dans l’application.',
            'reminders_supported' => false,
            'fields' => [
                'login' => ['label' => 'Identifiant', 'type' => 'text'],
                'user_name' => ['label' => 'Pseudo / Nom affiché', 'type' => 'text'],
                'login_at' => ['label' => 'Date et heure de connexion', 'type' => 'text'],
            ],
            'path_builder' => fn(array $d) => 'admin.php#config-utilisateurs',
            'payload_builder' => function(array $b, array $a) {
                $user = (string)($a['user_name'] ?? $a['login'] ?? 'Utilisateur');
                $when = (string)($a['login_at'] ?? '');
                $whenText = $when !== '' ? " le $when" : '';
                return [
                    'title' => 'Connexion utilisateur',
                    'body'  => "L’utilisateur « $user » s’est connecté$whenText."
                ];
            }
        ],
    ];
    // La politique Community masque les événements exclus et empêche leur dispatch.
    return array_filter($catalog, static fn(array $event): bool => MusicbandModuleEnabled($event['module']));
}

// -----------------------------------------------------------------------------
// 3. MOTEUR D'ÉVALUATION DÉCLARATIF DES CONDITIONS SUR LES CHAMPS
// -----------------------------------------------------------------------------

/**
 * Normalise une valeur booléenne pour comparaison stricte et typée.
 */
function advancedNormalizeBool($v): ?bool {
    if ($v === null || $v === '') return null;
    if (is_bool($v)) return $v;
    if (is_numeric($v)) return (int)$v !== 0;
    if (is_string($v)) {
        $s = strtolower(trim($v));
        if (in_array($s, ['1', 'oui', 'yes', 'true', 'x', 'vrai', 'y'], true)) return true;
        if (in_array($s, ['0', 'non', 'no', 'false', 'n', 'faux'], true)) return false;
    }
    return (bool)$v;
}

/**
 * Évalue une condition unitaire sur un champ à partir des états précédent et nouveau.
 */
function advancedEvaluateSingleCondition(array $cond, array $previous, array $new): bool {
    $field = (string)($cond['field'] ?? '');
    if ($field === '') return true;

    $op = (string)($cond['op'] ?? 'equals');
    $wanted = $cond['value'] ?? null;
    $wantedPrevious = $cond['previous_value'] ?? null;

    $oldVal = $previous[$field] ?? null;
    $newVal = $new[$field] ?? null;

    // Détection de type
    $isBoolField = in_array($field, ['next_rehearsal_work', 'concert_ready', 'rehearsal_tested'], true);

    if ($isBoolField) {
        $oldBool = advancedNormalizeBool($oldVal);
        $newBool = advancedNormalizeBool($newVal);
        $wantedBool = advancedNormalizeBool($wanted);
        $wantedPrevBool = advancedNormalizeBool($wantedPrevious);

        return match ($op) {
            'equals' => $newBool === $wantedBool,
            'not_equals' => $newBool !== $wantedBool,
            'is_empty' => $newBool === null || $newBool === false,
            'is_not_empty' => $newBool === true,
            'changed' => $oldBool !== $newBool,
            'changed_from' => $oldBool === $wantedPrevBool && $newBool !== $wantedPrevBool,
            'changed_to' => $oldBool !== $wantedBool && $newBool === $wantedBool,
            'changed_from_to' => $oldBool === $wantedPrevBool && $newBool === $wantedBool,
            default => false
        };
    }

    $oldStr = $oldVal !== null ? trim((string)$oldVal) : null;
    $newStr = $newVal !== null ? trim((string)$newVal) : null;
    $wantedStr = $wanted !== null ? trim((string)$wanted) : '';
    $wantedPrevStr = $wantedPrevious !== null ? trim((string)$wantedPrevious) : '';

    return match ($op) {
        'equals' => $newStr === $wantedStr,
        'not_equals' => $newStr !== $wantedStr,
        'is_empty' => $newStr === null || $newStr === '',
        'is_not_empty' => $newStr !== null && $newStr !== '',
        'changed' => $oldStr !== $newStr,
        'changed_from' => $oldStr === $wantedPrevStr && $newStr !== $wantedPrevStr,
        'changed_to' => $oldStr !== $wantedStr && $newStr === $wantedStr,
        'changed_from_to' => $oldStr === $wantedPrevStr && $newStr === $wantedStr,
        'contains' => $newStr !== null && str_contains(mb_strtolower($newStr), mb_strtolower($wantedStr)),
        'greater' => is_numeric($newStr) && is_numeric($wantedStr) && (float)$newStr > (float)$wantedStr,
        'less' => is_numeric($newStr) && is_numeric($wantedStr) && (float)$newStr < (float)$wantedStr,
        'in_list' => is_array($wanted) ? in_array($newStr, $wanted, true) : in_array($newStr, array_map('trim', explode(',', $wantedStr)), true),
        default => false
    };
}

/**
 * Évalue un ensemble de conditions (arbre avec logique AND / OR).
 */
function advancedEvaluateRuleConditions(array $conditionGroup, array $previous, array $new): bool {
    $rules = $conditionGroup['rules'] ?? [];
    if (!is_array($rules) || empty($rules)) {
        return true; // Aucune condition = déclenchement direct
    }

    $logic = strtoupper((string)($conditionGroup['condition'] ?? 'AND'));
    if (!in_array($logic, ['AND', 'OR'], true)) $logic = 'AND';

    foreach ($rules as $child) {
        if (!is_array($child)) continue;
        // Si c'est un sous-groupe
        if (isset($child['rules'])) {
            $hit = advancedEvaluateRuleConditions($child, $previous, $new);
        } else {
            $hit = advancedEvaluateSingleCondition($child, $previous, $new);
        }

        if ($logic === 'AND' && !$hit) {
            return false;
        }
        if ($logic === 'OR' && $hit) {
            return true;
        }
    }

    return $logic === 'AND';
}

// -----------------------------------------------------------------------------
// 4. GESTION DES RÈGLES PAR DÉFAUT (ADMINISTRATEUR)
// -----------------------------------------------------------------------------

/**
 * Modèles initiaux de règles par défaut définis pour le groupe.
 */
function advancedNotificationsInitialDefaultRules(): array {
    return [
        [
            'id' => 'def_song_work',
            'name' => 'Morceau marqué « À travailler »',
            'type' => 'event',
            'event_key' => 'songs.edit',
            'module' => 'songs',
            'enabled' => true,
            'conditions' => [
                'condition' => 'AND',
                'rules' => [
                    [
                        'field' => 'next_rehearsal_work',
                        'op' => 'changed_to',
                        'value' => '1',
                        'previous_value' => '0'
                    ]
                ]
            ],
            'reminder_offset' => null,
            'target_assignee_only' => false,
        ],
        [
            'id' => 'def_rehearsal_2h',
            'name' => 'Rappel de répétition (2h avant)',
            'type' => 'reminder',
            'event_key' => 'agenda.rehearsal',
            'module' => 'agenda',
            'enabled' => true,
            'conditions' => ['condition' => 'AND', 'rules' => []],
            'reminder_offset' => 7200, // 2h en secondes
            'target_assignee_only' => false,
        ],
        [
            'id' => 'def_concert_24h',
            'name' => 'Rappel de concert (24h avant)',
            'type' => 'reminder',
            'event_key' => 'concerts.reminder',
            'module' => 'concerts',
            'enabled' => true,
            'conditions' => ['condition' => 'AND', 'rules' => []],
            'reminder_offset' => 86400, // 24h
            'target_assignee_only' => false,
        ],
        [
            'id' => 'def_concert_7d',
            'name' => 'Rappel de concert (7 jours avant)',
            'type' => 'reminder',
            'event_key' => 'concerts.reminder',
            'module' => 'concerts',
            'enabled' => false, // Désactivé par défaut
            'conditions' => ['condition' => 'AND', 'rules' => []],
            'reminder_offset' => 604800, // 7 jours
            'target_assignee_only' => false,
        ],
        [
            'id' => 'def_task_reminder_24h',
            'name' => 'Échéance de tâche (24h avant)',
            'type' => 'reminder',
            'event_key' => 'tasks.reminder',
            'module' => 'tasks',
            'enabled' => true,
            'conditions' => ['condition' => 'AND', 'rules' => []],
            'reminder_offset' => 86400,
            'target_assignee_only' => true,
        ],
        [
            'id' => 'def_setlist_validated',
            'name' => 'Setlist validée et figée',
            'type' => 'event',
            'event_key' => 'setlist.validate',
            'module' => 'setlist',
            'enabled' => true,
            'conditions' => ['condition' => 'AND', 'rules' => []],
            'reminder_offset' => null,
            'target_assignee_only' => false,
        ],
    ];
}

/**
 * Récupère le jeu de règles par défaut de l'administrateur avec son identifiant de révision.
 */
function advancedNotificationsGetDefaultRuleset(): array {
    $data = MusicbandRead('notification_default_rules');
    if (!isset($data['rules']) || !is_array($data['rules'])) {
        $rules = advancedNotificationsInitialDefaultRules();
        $rev = hash('sha256', json_encode($rules));
        $data = [
            'revision'   => $rev,
            'updated_at' => gmdate('c'),
            'updated_by' => 'system',
            'rules'      => $rules
        ];
        MusicbandLocked(fn() => MusicbandWrite('notification_default_rules', $data));
    }
    return $data;
}

/**
 * Enregistre les règles par défaut administratives et génère une nouvelle révision.
 */
function advancedNotificationsSaveDefaultRuleset(array $rules, array $adminUser): string {
    if (!($adminUser['admin'] ?? false) && !MusicbandCan('notifications.configure', $adminUser)) {
        throw new RuntimeException('Droits insuffisants pour configurer les règles par défaut.');
    }

    $sanitized = [];
    $catalog = advancedNotificationEventCatalog();

    foreach ($rules as $r) {
        if (!is_array($r)) continue;
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($r['id'] ?? 'def_' . bin2hex(random_bytes(4))));
        $name = substr(trim((string)($r['name'] ?? 'Règle par défaut')), 0, ADV_NOTIF_MAX_RULE_NAME_LEN);
        $eventKey = (string)($r['event_key'] ?? '');
        if (!isset($catalog[$eventKey])) continue;

        $type = (string)($r['type'] ?? 'event');
        if (!in_array($type, ['event', 'reminder'], true)) $type = 'event';

        $offset = null;
        if ($type === 'reminder') {
            $offset = max(60, min(2592000, (int)($r['reminder_offset'] ?? 86400)));
        }

        $sanitized[] = [
            'id'                   => $id,
            'name'                 => $name,
            'type'                 => $type,
            'event_key'            => $eventKey,
            'module'               => $catalog[$eventKey]['module'],
            'enabled'              => !empty($r['enabled']),
            'conditions'           => is_array($r['conditions'] ?? null) ? $r['conditions'] : ['condition' => 'AND', 'rules' => []],
            'reminder_offset'      => $offset,
            'target_assignee_only' => !empty($r['target_assignee_only']),
        ];
    }

    $newRevision = hash('sha256', json_encode($sanitized));
    $payload = [
        'revision'   => $newRevision,
        'updated_at' => gmdate('c'),
        'updated_by' => (string)($adminUser['login'] ?? 'admin'),
        'rules'      => $sanitized
    ];

    MusicbandLocked(fn() => MusicbandWrite('notification_default_rules', $payload));

    if (function_exists('MusicbandAuditEvent')) {
        MusicbandAuditEvent('configuration', 'notifications.default_rules.saved', 'success', [
            'count' => count($sanitized),
            'revision' => $newRevision
        ]);
    }

    return $newRevision;
}

// -----------------------------------------------------------------------------
// 5. GESTION DES RÈGLES UTILISATEUR (COPIES PAR DÉFAUT ET RÈGLES PERSONNELLES)
// -----------------------------------------------------------------------------

/**
 * Récupère l'ensemble des règles (par défaut copiées et personnalisées) pour un utilisateur.
 */
function advancedNotificationsGetUserRules(string $login): array {
    $store = MusicbandRead('notification_user_rules');
    $userConfig = $store[$login] ?? [];

    $enabled = !empty($userConfig['enabled']);
    $defaultRules = is_array($userConfig['default_rules'] ?? null) ? $userConfig['default_rules'] : [];
    $customRules = is_array($userConfig['custom_rules'] ?? null) ? $userConfig['custom_rules'] : [];
    $defaultRevision = (string)($userConfig['default_revision'] ?? '');

    return [
        'enabled'          => $enabled,
        'default_revision' => $defaultRevision,
        'default_rules'    => $defaultRules,
        'custom_rules'     => $customRules,
    ];
}

/**
 * Active ou désactive une règle spécifique d'un utilisateur (par défaut copiée ou personnelle).
 */
function advancedNotificationsToggleUserRule(string $login, string $ruleId, bool $enabled): void {
    if (!advancedNotificationsUserAllowed($login)) {
        throw new RuntimeException('Fonctionnalité non autorisée.');
    }

    MusicbandLocked(function() use ($login, $ruleId, $enabled) {
        $store = MusicbandRead('notification_user_rules');
        $userConfig = $store[$login] ?? [];

        $found = false;
        // Recherche dans les règles par défaut copiées
        if (isset($userConfig['default_rules']) && is_array($userConfig['default_rules'])) {
            foreach ($userConfig['default_rules'] as &$r) {
                if (($r['id'] ?? '') === $ruleId) {
                    $r['enabled'] = $enabled;
                    $found = true;
                    break;
                }
            }
        }
        // Recherche dans les règles personnalisées
        if (!$found && isset($userConfig['custom_rules']) && is_array($userConfig['custom_rules'])) {
            foreach ($userConfig['custom_rules'] as &$r) {
                if (($r['id'] ?? '') === $ruleId) {
                    $r['enabled'] = $enabled;
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            throw new RuntimeException('Règle introuvable.');
        }

        $userConfig['updated_at'] = gmdate('c');
        $store[$login] = $userConfig;
        MusicbandWrite('notification_user_rules', $store);
    });

    if (function_exists('MusicbandAuditEvent')) {
        MusicbandAuditEvent('notifications', 'user_rule.toggled', 'success', [
            'target' => $login,
            'rule_id' => $ruleId,
            'enabled' => $enabled ? '1' : '0'
        ]);
    }
}

/**
 * Réinitialise les règles par défaut d'un utilisateur d'après les modèles administrateur actuels.
 * Préserve intégralement les règles personnelles et l'activation globale.
 */
function advancedNotificationsResetUserDefaultRules(string $login): void {
    if (!advancedNotificationsUserAllowed($login)) {
        throw new RuntimeException('Fonctionnalité non autorisée.');
    }

    MusicbandLocked(function() use ($login) {
        $store = MusicbandRead('notification_user_rules');
        $userConfig = $store[$login] ?? [];

        $defaultData = advancedNotificationsGetDefaultRuleset();
        $userConfig['default_rules'] = $defaultData['rules'];
        $userConfig['default_revision'] = $defaultData['revision'];
        $userConfig['updated_at'] = gmdate('c');

        $store[$login] = $userConfig;
        MusicbandWrite('notification_user_rules', $store);
    });

    if (function_exists('MusicbandAuditEvent')) {
        MusicbandAuditEvent('notifications', 'default_rules.reset', 'success', [
            'target' => $login
        ]);
    }
}

/**
 * Enregistre (création ou modification) une règle personnalisée pour un utilisateur.
 * Les nouvelles règles démarrent désactivées par défaut.
 */
function advancedNotificationsSaveCustomRule(string $login, array $ruleData): string {
    if (!advancedNotificationsUserAllowed($login)) {
        throw new RuntimeException('Fonctionnalité non autorisée.');
    }

    $catalog = advancedNotificationEventCatalog();
    $eventKey = (string)($ruleData['event_key'] ?? '');
    if (!isset($catalog[$eventKey])) {
        throw new RuntimeException('Événement métier inconnu.');
    }

    $name = substr(trim((string)($ruleData['name'] ?? 'Ma règle')), 0, ADV_NOTIF_MAX_RULE_NAME_LEN);
    if ($name === '') {
        throw new RuntimeException('Le nom de la règle est requis.');
    }

    $type = (string)($ruleData['type'] ?? 'event');
    if (!in_array($type, ['event', 'reminder'], true)) $type = 'event';

    $offset = null;
    if ($type === 'reminder') {
        if (empty($catalog[$eventKey]['reminders_supported'])) {
            throw new RuntimeException('Les rappels planifiés ne sont pas disponibles pour cet événement.');
        }
        $offset = max(60, min(2592000, (int)($ruleData['reminder_offset'] ?? 3600)));
    }

    $conditions = is_array($ruleData['conditions'] ?? null) ? $ruleData['conditions'] : ['condition' => 'AND', 'rules' => []];

    $ruleId = (string)($ruleData['id'] ?? '');
    $isNew = ($ruleId === '');
    if ($isNew) {
        $ruleId = 'usr_' . bin2hex(random_bytes(6));
    }

    MusicbandLocked(function() use ($login, $ruleId, $isNew, $name, $type, $eventKey, $catalog, $offset, $conditions, $ruleData) {
        $store = MusicbandRead('notification_user_rules');
        $userConfig = $store[$login] ?? [
            'enabled' => false,
            'default_rules' => [],
            'custom_rules' => [],
            'default_revision' => ''
        ];

        $custom = $userConfig['custom_rules'] ?? [];
        if ($isNew && count($custom) >= ADV_NOTIF_MAX_USER_RULES) {
            throw new RuntimeException('Limite de ' . ADV_NOTIF_MAX_USER_RULES . ' règles personnalisées atteinte.');
        }

        $existingIndex = -1;
        foreach ($custom as $i => $r) {
            if (($r['id'] ?? '') === $ruleId) {
                $existingIndex = $i;
                break;
            }
        }

        $enabled = $isNew ? false : !empty($ruleData['enabled']);

        $cleanRule = [
            'id'                   => $ruleId,
            'name'                 => $name,
            'type'                 => $type,
            'event_key'            => $eventKey,
            'module'               => $catalog[$eventKey]['module'],
            'enabled'              => $enabled,
            'conditions'           => $conditions,
            'reminder_offset'      => $offset,
            'target_assignee_only' => !empty($ruleData['target_assignee_only']),
            'updated_at'           => gmdate('c'),
        ];

        if ($existingIndex >= 0) {
            $custom[$existingIndex] = $cleanRule;
        } else {
            $custom[] = $cleanRule;
        }

        $userConfig['custom_rules'] = $custom;
        $userConfig['updated_at'] = gmdate('c');
        $store[$login] = $userConfig;
        MusicbandWrite('notification_user_rules', $store);
    });

    if (function_exists('MusicbandAuditEvent')) {
        MusicbandAuditEvent('notifications', $isNew ? 'custom_rule.created' : 'custom_rule.updated', 'success', [
            'target' => $login,
            'rule_id' => $ruleId
        ]);
    }

    return $ruleId;
}

/**
 * Duplique une règle existante (par défaut ou personnelle) vers les règles personnalisées de l'utilisateur.
 */
function advancedNotificationsDuplicateRule(string $login, string $sourceRuleId): string {
    $rulesInfo = advancedNotificationsGetUserRules($login);
    $allRules = array_merge($rulesInfo['default_rules'], $rulesInfo['custom_rules']);

    $source = null;
    foreach ($allRules as $r) {
        if (($r['id'] ?? '') === $sourceRuleId) {
            $source = $r;
            break;
        }
    }

    if (!$source) {
        throw new RuntimeException('Règle source introuvable.');
    }

    $copy = $source;
    unset($copy['id']);
    $copy['name'] = 'Copie de ' . ($source['name'] ?? 'règle');
    $copy['enabled'] = false; // Nouvelle règle toujours désactivée par défaut

    return advancedNotificationsSaveCustomRule($login, $copy);
}

/**
 * Supprime une règle personnalisée de l'utilisateur.
 */
function advancedNotificationsDeleteCustomRule(string $login, string $ruleId): void {
    if (!advancedNotificationsUserAllowed($login)) {
        throw new RuntimeException('Fonctionnalité non autorisée.');
    }

    MusicbandLocked(function() use ($login, $ruleId) {
        $store = MusicbandRead('notification_user_rules');
        $userConfig = $store[$login] ?? [];
        $custom = $userConfig['custom_rules'] ?? [];

        $filtered = array_values(array_filter($custom, fn($r) => ($r['id'] ?? '') !== $ruleId));
        if (count($filtered) === count($custom)) {
            throw new RuntimeException('Règle personnalisée introuvable.');
        }

        $userConfig['custom_rules'] = $filtered;
        $userConfig['updated_at'] = gmdate('c');
        $store[$login] = $userConfig;
        MusicbandWrite('notification_user_rules', $store);
    });

    if (function_exists('MusicbandAuditEvent')) {
        MusicbandAuditEvent('notifications', 'custom_rule.deleted', 'success', [
            'target' => $login,
            'rule_id' => $ruleId
        ]);
    }
}

// -----------------------------------------------------------------------------
// 6. DISPATCHER DES ÉVÉNEMENTS MÉTIER ET PLANIFICATION DURABLE
// -----------------------------------------------------------------------------

/**
 * Point d'entrée pour la production d'un événement métier après transaction réussie.
 *
 * Évalue les règles des utilisateurs autorisés et planifie la livraison Web Push.
 */
function advancedNotificationDispatchEvent(
    string $eventKey,
    string $module,
    string $entityId,
    array $previousValues,
    array $newValues,
    ?string $actorLogin = null
): int {
    $catalog = advancedNotificationEventCatalog();
    if (!isset($catalog[$eventKey])) return 0;

    $eventDef = $catalog[$eventKey];
    $requiredPermission = $eventDef['permission'];

    $users = MusicbandRead('users');
    $userRulesStore = MusicbandRead('notification_user_rules');
    $scheduledCount = 0;

    try {
        $db = MusicbandRbacDb();
    } catch (Throwable $e) {
        return 0; // Si MySQL est temporairement indisponible, ne jamais faire échouer l'action métier
    }

    $nowIso = gmdate('c');

    foreach ($users as $login => $userData) {
        // Niveau 1 : Autorisation admin
        if (empty($userData['advanced_notifications_allowed'])) continue;

        // Niveau 2 : Activation globale utilisateur
        $userConfig = $userRulesStore[$login] ?? [];
        if (empty($userConfig['enabled'])) continue;

        // Niveau 3 & Conditions : Récupération des règles actives
        $rules = array_merge(
            is_array($userConfig['default_rules'] ?? null) ? $userConfig['default_rules'] : [],
            is_array($userConfig['custom_rules'] ?? null) ? $userConfig['custom_rules'] : []
        );

        foreach ($rules as $rule) {
            if (empty($rule['enabled'])) continue;
            if (($rule['type'] ?? 'event') !== 'event') continue;
            if (($rule['event_key'] ?? '') !== $eventKey) continue;

            // Vérification de la cible (ex: assigné uniquement pour les tâches)
            if (!empty($rule['target_assignee_only'])) {
                $assignee = (string)($newValues['assignee_login'] ?? '');
                if ($assignee !== '' && $assignee !== $login) continue;
            }

            // Évaluation des conditions de champs
            $conditions = $rule['conditions'] ?? ['condition' => 'AND', 'rules' => []];
            if (!advancedEvaluateRuleConditions($conditions, $previousValues, $newValues)) {
                continue;
            }

            // Vérification immédiate du droit RBAC du destinataire
            if (!MusicbandCan($requiredPermission, ['login' => $login])) {
                continue;
            }

            // Construction du contenu
            $payload = ($eventDef['payload_builder'])($previousValues, $newValues);
            $path = ($eventDef['path_builder'])($newValues ?: $previousValues);

            // Clé stable de déduplication : event:login:ruleId:entityId:timestamp_minute
            $sourceKey = substr(hash('sha256', "evt:$eventKey:$entityId:{$rule['id']}:$login:" . substr($nowIso, 0, 16)), 0, 32);

            // Création de l'enregistrement d'événement et réservation de livraison
            try {
                $q = $db->prepare('INSERT IGNORE INTO Musicbandai_notification_events(event_key, source_key, path, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))');
                $q->execute([$eventKey, $sourceKey, $path]);
                $eventId = (int)$db->lastInsertId();

                if ($eventId === 0) {
                    $qFind = $db->prepare('SELECT id FROM Musicbandai_notification_events WHERE event_key=? AND source_key=?');
                    $qFind->execute([$eventKey, $sourceKey]);
                    $eventId = (int)$qFind->fetchColumn();
                }

                if ($eventId > 0) {
                    // Recherche des abonnements actifs pour ce login
                    $qSubs = $db->prepare('SELECT id FROM Musicbandai_push_subscriptions WHERE login=? AND active=1');
                    $qSubs->execute([$login]);
                    foreach ($qSubs->fetchAll(PDO::FETCH_COLUMN) as $subId) {
                        $qJob = $db->prepare("INSERT IGNORE INTO Musicbandai_push_deliveries(event_id, subscription_id, status, requested_at) VALUES (?, ?, 'pending', UTC_TIMESTAMP(6))");
                        $qJob->execute([$eventId, (int)$subId]);
                        if ($qJob->rowCount() > 0) {
                            $scheduledCount++;
                            advancedNotificationStoreDeliveryPayload($eventId, (int)$subId, $payload['title'], $payload['body'], $path);
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('advanced_notif_enqueue_error: ' . $e->getMessage());
            }
        }
    }

    return $scheduledCount;
}

// -----------------------------------------------------------------------------
// 7. MOTEUR DE RAPPELS PLANIFIÉS (CONCERTS, RÉPÉTITIONS, ÉCHÉANCES)
// -----------------------------------------------------------------------------

/**
 * Formate un libellé français convivial pour un délai en secondes.
 */
function advancedFormatOffsetLabel(int $seconds): string {
    if ($seconds < 3600) {
        $m = max(1, (int)round($seconds / 60));
        return "$m minute" . ($m > 1 ? 's' : '');
    }
    if ($seconds < 86400) {
        $h = max(1, (int)round($seconds / 3600));
        return "$h heure" . ($h > 1 ? 's' : '');
    }
    $d = max(1, (int)round($seconds / 86400));
    return "$d jour" . ($d > 1 ? 's' : '');
}

/**
 * Exécute l'évaluation des rappels planifiés (appelé lors de chaque passage cron).
 * Traite les concerts à venir, les répétitions de l'agenda et les échéances de tâches.
 */
function advancedNotificationProcessReminders(PDO $db): int {
    $now = time();
    $scheduled = 0;
    $catalog = advancedNotificationEventCatalog();
    $users = MusicbandRead('users');
    $userRulesStore = MusicbandRead('notification_user_rules');

    // Récupération des utilisateurs autorisés et activés
    $activeUsers = [];
    foreach ($users as $login => $u) {
        if (!empty($u['advanced_notifications_allowed']) && !empty($userRulesStore[$login]['enabled'])) {
            $activeUsers[$login] = array_merge(
                is_array($userRulesStore[$login]['default_rules'] ?? null) ? $userRulesStore[$login]['default_rules'] : [],
                is_array($userRulesStore[$login]['custom_rules'] ?? null) ? $userRulesStore[$login]['custom_rules'] : []
            );
        }
    }
    if (empty($activeUsers)) return 0;

    // 1. Rappels de concerts
    try {
        if (MusicbandFeatureTable($db, 'concerts_evenements')) {
            $policy = businessListSettings();
            $tz = new DateTimeZone($policy['timezone']);
            $todayStr = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

            $qConcerts = $db->prepare("SELECT * FROM concerts_evenements WHERE date_concert >= ? AND (statut IS NULL OR statut NOT IN ('Annulé', 'annule')) ORDER BY date_concert ASC");
            $qConcerts->execute([$todayStr]);
            $concerts = $qConcerts->fetchAll();

            foreach ($concerts as $c) {
                $cDate = (string)($c['date_concert'] ?? '');
                $cTime = (string)($c['heure_prestation'] ?? ($c['heure_arrivee'] ?? '20:00:00'));
                if (strlen($cTime) === 5) $cTime .= ':00';

                try {
                    $concertDt = new DateTimeImmutable("$cDate $cTime", $tz);
                    $concertTs = $concertDt->getTimestamp();
                } catch (Throwable $e) {
                    continue;
                }

                foreach ($activeUsers as $login => $rules) {
                    if (!MusicbandCan('concerts.edit', ['login' => $login])) continue;

                    foreach ($rules as $r) {
                        if (empty($r['enabled']) || ($r['type'] ?? '') !== 'reminder') continue;
                        if (($r['event_key'] ?? '') !== 'concerts.reminder') continue;

                        $offset = (int)($r['reminder_offset'] ?? 86400);
                        $targetReminderTime = $concertTs - $offset;

                        // Vérifier si le rappel doit sonner : dans la fenêtre [Now - 2h, Now]
                        if ($targetReminderTime <= $now && $targetReminderTime >= ($now - 7200)) {
                            $offsetLabel = advancedFormatOffsetLabel($offset);
                            $sourceKey = substr(hash('sha256', "rem:c:{$c['id']}:{$r['id']}:$offset:$cDate:$login"), 0, 32);

                            $payload = ($catalog['concerts.reminder']['payload_builder'])([], $c, $offsetLabel);
                            $path = 'concerts.php?id=' . (int)$c['id'];

                            $scheduled += advancedNotificationEnqueueCustomPush($db, 'concerts.reminder', $sourceKey, $login, $path, $payload['title'], $payload['body']);
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('adv_notif_concerts_reminder_error: ' . $e->getMessage());
    }

    // 2. Rappels d'échéances de tâches
    try {
        if (MusicbandFeatureTable($db, 'Musicbandai_tasks')) {
            $tz = new DateTimeZone(businessListSettings()['timezone']);
            $todayStr = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

            $qTasks = $db->prepare("SELECT * FROM Musicbandai_tasks WHERE due_date >= ? AND status NOT IN ('terminee', 'annulee') AND archived = 0");
            $qTasks->execute([$todayStr]);
            $tasks = $qTasks->fetchAll();

            foreach ($tasks as $t) {
                $dDate = (string)($t['due_date'] ?? '');
                $dTime = '18:00:00';

                try {
                    $dueDt = new DateTimeImmutable("$dDate $dTime", $tz);
                    $dueTs = $dueDt->getTimestamp();
                } catch (Throwable $e) {
                    continue;
                }

                $assignee = (string)($t['assignee'] ?? '');

                foreach ($activeUsers as $login => $rules) {
                    if (!MusicbandCan('tasks.read', ['login' => $login])) continue;

                    foreach ($rules as $r) {
                        if (empty($r['enabled']) || ($r['type'] ?? '') !== 'reminder') continue;
                        if (($r['event_key'] ?? '') !== 'tasks.reminder') continue;

                        if (!empty($r['target_assignee_only']) && $assignee !== '' && $assignee !== $login) {
                            continue;
                        }

                        $offset = (int)($r['reminder_offset'] ?? 86400);
                        $targetReminderTime = $dueTs - $offset;

                        if ($targetReminderTime <= $now && $targetReminderTime >= ($now - 7200)) {
                            $offsetLabel = advancedFormatOffsetLabel($offset);
                            $sourceKey = substr(hash('sha256', "rem:t:{$t['id']}:{$r['id']}:$offset:$dDate:$login"), 0, 32);

                            $payload = ($catalog['tasks.reminder']['payload_builder'])([], $t, $offsetLabel);
                            $path = 'tasks.php?mes_taches=1';

                            $scheduled += advancedNotificationEnqueueCustomPush($db, 'tasks.reminder', $sourceKey, $login, $path, $payload['title'], $payload['body']);
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('adv_notif_tasks_reminder_error: ' . $e->getMessage());
    }

    // 3. Rappels de répétitions (Agenda Workspace)
    try {
        $tz = new DateTimeZone(businessListSettings()['timezone']);
        $settings = MusicbandRead('home_settings')['workspace_calendar'] ?? [];
        $calId = (string)($settings['id'] ?? '');

        if ($calId !== '' && function_exists('workspaceCalendarEvents') && function_exists('dashboardIsRehearsalTitle')) {
            $from = $now - 3600;
            $calData = workspaceCalendarEvents($calId, $from, '');
            $items = is_array($calData['items'] ?? null) ? $calData['items'] : [];

            foreach ($items as $ev) {
                $summary = (string)($ev['summary'] ?? '');
                if (!dashboardIsRehearsalTitle($summary)) continue;

                $startDtStr = (string)($ev['start']['dateTime'] ?? ($ev['start']['date'] ?? ''));
                if ($startDtStr === '') continue;

                try {
                    $startDt = new DateTimeImmutable($startDtStr, $tz);
                    $startTs = $startDt->getTimestamp();
                } catch (Throwable $e) {
                    continue;
                }

                foreach ($activeUsers as $login => $rules) {
                    if (!MusicbandCan('calendar.read', ['login' => $login])) continue;

                    foreach ($rules as $r) {
                        if (empty($r['enabled']) || ($r['type'] ?? '') !== 'reminder') continue;
                        if (($r['event_key'] ?? '') !== 'agenda.rehearsal') continue;

                        $offset = (int)($r['reminder_offset'] ?? 7200);
                        $targetReminderTime = $startTs - $offset;

                        if ($targetReminderTime <= $now && $targetReminderTime >= ($now - 7200)) {
                            $offsetLabel = advancedFormatOffsetLabel($offset);
                            $sourceKey = substr(hash('sha256', "rem:r:$startTs:{$r['id']}:$offset:$login"), 0, 32);

                            $payload = ($catalog['agenda.rehearsal']['payload_builder'])([], $ev, $offsetLabel);
                            $path = 'calendar.php';

                            $scheduled += advancedNotificationEnqueueCustomPush($db, 'agenda.rehearsal', $sourceKey, $login, $path, $payload['title'], $payload['body']);
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // Ignorer l'absence temporaire d'OAuth Google
    }

    return $scheduled;
}

/**
 * Enregistre une livraison push personnalisée avec titre et message dans la base.
 */
function advancedNotificationEnqueueCustomPush(
    PDO $db,
    string $eventKey,
    string $sourceKey,
    string $login,
    string $path,
    string $title,
    string $body
): int {
    $qEvent = $db->prepare('INSERT IGNORE INTO Musicbandai_notification_events(event_key, source_key, path, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))');
    $qEvent->execute([$eventKey, $sourceKey, $path]);
    $eventId = (int)$db->lastInsertId();

    if ($eventId === 0) {
        $qFind = $db->prepare('SELECT id FROM Musicbandai_notification_events WHERE event_key=? AND source_key=?');
        $qFind->execute([$eventKey, $sourceKey]);
        $eventId = (int)$qFind->fetchColumn();
    }
    if ($eventId === 0) return 0;

    $qSubs = $db->prepare('SELECT id FROM Musicbandai_push_subscriptions WHERE login=? AND active=1');
    $qSubs->execute([$login]);
    $subIds = $qSubs->fetchAll(PDO::FETCH_COLUMN);

    $count = 0;
    foreach ($subIds as $subId) {
        // Enregistrement de la livraison avec payload enrichi dans la table deliveries
        $qJob = $db->prepare("INSERT IGNORE INTO Musicbandai_push_deliveries(event_id, subscription_id, status, requested_at) VALUES (?, ?, 'pending', UTC_TIMESTAMP(6))");
        $qJob->execute([$eventId, (int)$subId]);
        if ($qJob->rowCount() > 0) {
            $count++;
            // Stockage durable du contenu de la notification pour le worker
            advancedNotificationStoreDeliveryPayload($eventId, (int)$subId, $title, $body, $path);
        }
    }

    return $count;
}

/**
 * Mémorise durablement le titre et le corps personnalisés pour une livraison Push.
 */
function advancedNotificationStoreDeliveryPayload(int $eventId, int $subscriptionId, string $title, string $body, string $path): void {
    $key = "$eventId:$subscriptionId";
    MusicbandLocked(function() use ($key, $title, $body, $path) {
        $store = MusicbandRead('notification_delivery_payloads');
        $store[$key] = [
            'title' => $title,
            'body'  => $body,
            'path'  => $path,
            'at'    => gmdate('c')
        ];
        // Nettoyage périodique pour éviter toute croissance indéfinie (> 500 entrées)
        if (count($store) > 500) {
            $store = array_slice($store, -300, null, true);
        }
        MusicbandWrite('notification_delivery_payloads', $store);
    });
}

/**
 * Récupère le payload de titre et corps pour une livraison Web Push.
 */
function advancedNotificationGetDeliveryPayload(int $eventId, int $subscriptionId): ?array {
    $key = "$eventId:$subscriptionId";
    $store = MusicbandRead('notification_delivery_payloads');
    return $store[$key] ?? null;
}

/**
 * Déclenche l'envoi d'une notification de test pour un utilisateur autorisé.
 */
function advancedNotificationSendTestNotification(string $login): array {
    if (!advancedNotificationsUserAllowed($login)) {
        throw new RuntimeException('Fonctionnalité non autorisée pour ce compte.');
    }
    if (function_exists('MusicbandNotificationsSendTestDirect')) {
        return MusicbandNotificationsSendTestDirect($login);
    }
    $db = MusicbandRbacDb();
    $qSubs = $db->prepare('SELECT id FROM Musicbandai_push_subscriptions WHERE login=? AND active=1');
    $qSubs->execute([$login]);
    $subIds = $qSubs->fetchAll(PDO::FETCH_COLUMN);
    if (empty($subIds)) {
        return ['success' => false, 'count' => 0, 'message' => 'Aucun appareil actif pour ce compte. Rendez-vous dans la section « Notifications et rappels » de Préférences pour abonner votre appareil.'];
    }

    $sourceKey = 'test:' . substr(hash('sha256', $login . ':' . microtime(true)), 0, 24);
    $title = 'Test Musicband Notification';
    $body = 'Vos notifications PWA fonctionnent correctement !';
    $path = 'index.php';

    $count = advancedNotificationEnqueueCustomPush($db, 'test.notification', $sourceKey, $login, $path, $title, $body);

    if (function_exists('MusicbandAuditEvent')) {
        MusicbandAuditEvent('notifications', 'test_notification.enqueued', 'success', ['target' => $login, 'count' => $count]);
    }

    return ['success' => true, 'count' => $count, 'message' => 'Notification de test en attente d’envoi vers vos appareils.'];
}

/**
 * Notifie les administrateurs éligibles lors d'une connexion réussie d'un utilisateur.
 *
 * Invariants :
 * - Déclenché exclusivement lors d'une authentification réussie (jamais lors d'un échec, rafraîchissement ou check).
 * - Déduplication stricte par source_key basée sur le compte, l'instant de session et le jeton CSRF.
 * - Respect des permissions administrateur, de l'activation PWA et des abonnements actifs.
 * - Tout échec de livraison ou indisponibilité temporaire ne bloque jamais la connexion utilisateur.
 */
function advancedNotificationNotifyAdminsOnLogin(string $authenticatedLogin): int {
    try {
        if (!function_exists('MusicbandRbacDb')) {
            return 0;
        }
        $db = MusicbandRbacDb();
    } catch (Throwable $e) {
        return 0;
    }

    try {
        if (function_exists('MusicbandNotificationsReady') && !MusicbandNotificationsReady($db)) {
            return 0;
        }

        $users = MusicbandRead('users');
        $userRulesStore = MusicbandRead('notification_user_rules');
        $now = time();
        $displayDate = function_exists('MusicbandDisplayDate')
            ? MusicbandDisplayDate(date('Y-m-d H:i:s', $now), true)
            : date('d/m/Y à H:i:s', $now);

        $displayName = function_exists('MusicbandUserDisplayName')
            ? MusicbandUserDisplayName($authenticatedLogin)
            : $authenticatedLogin;

        $userLabel = ($displayName !== '' && $displayName !== $authenticatedLogin)
            ? "$displayName ($authenticatedLogin)"
            : $authenticatedLogin;

        $title = 'Connexion utilisateur';
        $body = "L’utilisateur « $userLabel » s’est connecté le $displayDate.";
        $path = 'admin.php#config-utilisateurs';
        $eventKey = 'auth.login';

        // Clé unique et stable de déduplication pour cette session/connexion
        $started = (int)($_SESSION['started'] ?? $now);
        $csrfPart = substr((string)($_SESSION['csrf'] ?? session_id()), 0, 16);
        $sourceKey = substr(hash('sha256', "login:$authenticatedLogin:$started:$csrfPart"), 0, 32);

        $scheduledCount = 0;

        foreach ($users as $adminLogin => $userData) {
            $adminLoginStr = (string)$adminLogin;

            // 1. Contrôle administrateur actif
            if (!advancedNotificationsIsAdmin($adminLoginStr, (array)$userData)) {
                continue;
            }

            // 2. Autorisation niveau 1 par un administrateur
            if (empty($userData['advanced_notifications_allowed'])) {
                continue;
            }

            // 3. Activation globale niveau 2
            $adminConfig = $userRulesStore[$adminLoginStr] ?? [];
            if (empty($adminConfig['enabled'])) {
                continue;
            }

            // 4. Réglage dédié connexion (désactivé par défaut)
            if (empty($adminConfig['notify_login'])) {
                continue;
            }

            // 5. Contrôle RBAC
            if (function_exists('MusicbandCan') && !MusicbandCan('notifications.configure', ['login' => $adminLoginStr])) {
                continue;
            }

            // Enregistrement de la notification PWA dans la file d'attente
            $count = advancedNotificationEnqueueCustomPush(
                $db,
                $eventKey,
                $sourceKey,
                $adminLoginStr,
                $path,
                $title,
                $body
            );
            $scheduledCount += $count;
        }

        return $scheduledCount;
    } catch (Throwable $e) {
        error_log('advancedNotificationNotifyAdminsOnLogin error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Génère le formulaire d'administration des règles par défaut pour admin.php.
 */
function advancedNotificationsAdminForm(string $hidden): string {
    $defaultData = advancedNotificationsGetDefaultRuleset();
    $rules = $defaultData['rules'];
    $rev = $defaultData['revision'];
    $updatedAt = $defaultData['updated_at'] ?? '';
    $updatedBy = $defaultData['updated_by'] ?? '';
    $catalog = advancedNotificationEventCatalog();

    $html = '<section><h2>Notifications avancées · Règles par défaut</h2>' .
        '<p>Ces règles définissent les modèles standard du groupe. Lors de sa première activation, chaque utilisateur reçoit une copie indépendante de ce jeu de règles. Les modifications apportées ici n’écrasent pas les réglages personnalisés déjà existants des membres, mais peuvent être réimportées par chaque utilisateur via l’action « Réinitialiser les règles par défaut ».</p>' .
        '<p><small>Révision actuelle : <code>' . substr(MusicbandEscape($rev), 0, 16) . '…</code>' .
        ($updatedAt !== '' ? ' · Dernière mise à jour le ' . MusicbandDisplayDate($updatedAt, true, true) : '') .
        ($updatedBy !== '' ? ' par ' . MusicbandEscape($updatedBy) : '') . '</small></p>';

    $html .= '<div class="business-table-scroll"><table style="width:100%;margin-top:12px;">' .
        '<thead><tr><th>Nom</th><th>Événement</th><th>Type</th><th>Délai</th><th>Cible</th><th>Actif par défaut</th></tr></thead><tbody>';

    foreach ($rules as $r) {
        $id = MusicbandEscape((string)($r['id'] ?? ''));
        $name = MusicbandEscape((string)($r['name'] ?? ''));
        $eventKey = (string)($r['event_key'] ?? '');
        $eventLabel = MusicbandEscape($catalog[$eventKey]['action_label'] ?? $eventKey);
        $type = ($r['type'] ?? 'event') === 'reminder' ? 'Rappel planifié' : 'Événement direct';
        $offset = !empty($r['reminder_offset']) ? advancedFormatOffsetLabel((int)$r['reminder_offset']) . ' avant' : '—';
        $target = !empty($r['target_assignee_only']) ? 'Assigné uniquement' : 'Tous autorisés';
        $enabled = !empty($r['enabled']) ? '<span style="color:#86efac;font-weight:600;">Oui</span>' : '<span style="color:#f87171;">Non</span>';

        $html .= '<tr>' .
            '<td><span class="Musicband-table-label">Nom</span><span class="Musicband-cell-value"><strong>' . $name . '</strong></span></td>' .
            '<td><span class="Musicband-table-label">Événement</span><span class="Musicband-cell-value">' . $eventLabel . '</span></td>' .
            '<td><span class="Musicband-table-label">Type</span><span class="Musicband-cell-value">' . $type . '</span></td>' .
            '<td><span class="Musicband-table-label">Délai</span><span class="Musicband-cell-value">' . $offset . '</span></td>' .
            '<td><span class="Musicband-table-label">Cible</span><span class="Musicband-cell-value">' . $target . '</span></td>' .
            '<td><span class="Musicband-table-label">Actif</span><span class="Musicband-cell-value">' . $enabled . '</span></td>' .
            '</tr>';
    }

    $html .= '</tbody></table></div>';

    // Formulaire d'ajout d'une nouvelle règle par défaut
    $eventOptions = '';
    foreach ($catalog as $k => $def) {
        $eventOptions .= '<option value="' . MusicbandEscape($k) . '">' . MusicbandEscape($def['module'] . ' · ' . $def['action_label']) . '</option>';
    }

    $html .= '<details style="margin-top:16px;"><summary>Ajouter une nouvelle règle modèle par défaut</summary>' .
        '<form method="post">' . $hidden .
        '<input type="hidden" name="action" value="admin_default_notifications_add">' .
        '<label>Nom de la règle<input name="name" required maxlength="100" placeholder="Ex. Rappel concert 48h avant"></label>' .
        '<label>Événement déclencheur<select name="event_key">' . $eventOptions . '</select></label>' .
        '<label>Type de déclencheur<select name="type"><option value="event">Événement direct (action immédiate)</option><option value="reminder">Rappel planifié avant échéance</option></select></label>' .
        '<label>Délai avant échéance (pour les rappels)<select name="reminder_offset">' .
        '<option value="1800">30 minutes</option>' .
        '<option value="3600">1 heure</option>' .
        '<option value="7200" selected>2 heures</option>' .
        '<option value="21600">6 heures</option>' .
        '<option value="43200">12 heures</option>' .
        '<option value="86400">24 heures (1 jour)</option>' .
        '<option value="172800">48 heures (2 jours)</option>' .
        '<option value="604800">7 jours</option>' .
        '</select></label>' .
        '<label class="Musicband-check-label"><input type="checkbox" class="Musicband-checkbox" name="target_assignee_only" value="1"> Restreindre à l’assigné uniquement (pour les tâches)</label>' .
        '<label class="Musicband-check-label"><input type="checkbox" class="Musicband-checkbox" name="enabled" value="1" checked> Activer par défaut lors de la copie</label>' .
        '<button class="button-primary">Ajouter la règle modèle</button>' .
        '</form></details></section>';

    $currentAdmin = function_exists('MusicbandUser') ? MusicbandUser() : null;
    $currentAdminLogin = (string)($currentAdmin['login'] ?? '');
    if ($currentAdminLogin !== '') {
        $adminNotifyLogin = advancedNotificationsAdminLoginNotificationsEnabled($currentAdminLogin);
        $html .= '<section style="margin-top:20px;border-top:1px solid rgba(255,255,255,0.1);padding-top:16px;">' .
            '<h3>Notification de connexion des utilisateurs</h3>' .
            '<p>Cette option facultative alerte les administrateurs lorsqu’un utilisateur s’authentifie avec succès dans l’application. Chaque administrateur peut l’activer ou la désactiver individuellement dans ses <a href="activate.php?change=1">Préférences</a> ou ci-dessous.</p>' .
            '<form method="post">' . $hidden .
            '<input type="hidden" name="action" value="toggle_admin_login_notifications">' .
            '<label class="Musicband-check-label" style="font-weight:600;">' .
            '<input type="checkbox" class="Musicband-checkbox" name="notify_login" value="1"' . ($adminNotifyLogin ? ' checked' : '') . '>' .
            ' Activer pour mon compte administrateur (' . MusicbandEscape($currentAdminLogin) . ')' .
            '</label>' .
            ' <button type="submit" class="button-primary" style="margin-left:12px;">Enregistrer</button>' .
            '</form>' .
            '</section>';
    }

    return $html;
}

/**
 * Génère la section complète des préférences de notifications pour activate.php.
 */
function advancedNotificationsUserPreferencesSection(string $login, string $csrf): string {
    $hidden = '<input type="hidden" name="csrf" value="' . MusicbandEscape($csrf) . '">';
    $allowed = advancedNotificationsUserAllowed($login);

    if (!$allowed) {
        return '<section class="preferences-section">' .
            '<h3>Règles personnelles</h3>' .
            '<p class="notice">La personnalisation avancée des notifications n’est pas autorisée pour votre compte. Contactez un administrateur pour l’activer.</p>' .
            '</section>';
    }

    $globallyEnabled = advancedNotificationsUserGloballyEnabled($login);
    $userRulesInfo = advancedNotificationsGetUserRules($login);
    $defaultRules = $userRulesInfo['default_rules'];
    $customRules = $userRulesInfo['custom_rules'];
    $catalog = advancedNotificationEventCatalog();

    // Vérifier l'abonnement de l'appareil
    $deviceSubscribed = false;
    try {
        $db = MusicbandRbacDb();
        if (MusicbandFeatureTable($db, 'Musicbandai_push_subscriptions')) {
            $q = $db->prepare('SELECT COUNT(*) FROM Musicbandai_push_subscriptions WHERE login=? AND active=1');
            $q->execute([$login]);
            $deviceSubscribed = ((int)$q->fetchColumn()) > 0;
        }
    } catch (Throwable $e) {}

    $html = '<section class="preferences-section">' .
        '<h3>Règles personnelles</h3>' .
        '<p>Configurez vos règles de notifications personnelles pour recevoir des alertes ciblées sur votre téléphone ou ordinateur sans être submergé.</p>';

    // Niveau 2 : Activation globale
    $html .= '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'activate.php?change=1" style="margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">' . $hidden .
        '<input type="hidden" name="action" value="toggle_global_notifications">' .
        '<label class="Musicband-check-label" style="font-size:1.05rem;font-weight:600;margin:0;">' .
        '<input type="checkbox" class="Musicband-checkbox" name="global_enabled" value="1"' . ($globallyEnabled ? ' checked' : '') . '>' .
        ' Activer mes notifications personnalisées' .
        '</label>' .
        '<button type="submit" class="button-primary">Enregistrer l’activation</button>' .
        '</form>';

    if (!$globallyEnabled) {
        $html .= '<p class="notice">Vos notifications sont actuellement suspendues. Cochez la case ci-dessus pour activer vos règles.</p></section>';
        return $html;
    }

    // État des appareils du compte ; un seul bouton de test dans le bloc appareil partagé.
    $html .= '<div style="background:rgba(255,255,255,0.05);padding:12px;border-radius:6px;margin:16px 0;">' .
        '<h4>Appareils du compte</h4>';

    if ($deviceSubscribed) {
        $html .= '<p style="color:#86efac;font-weight:600;">✓ Au moins un appareil ou navigateur est abonné aux notifications Push pour votre compte.</p>';
    } else {
        $html .= '<p class="notice">Aucun appareil actif n’est enregistré pour ce compte. Utilisez la section <a href="' . MusicbandEscape(MusicbandBase()) . 'activate.php?change=1#preferences-device">Notifications de cet appareil</a> pour autoriser la réception sur cet écran.</p>';
    }

    $html .= '</div>';

    // Niveau 2B : Options dédiées aux administrateurs
    if (advancedNotificationsIsAdmin($login)) {
        $loginNotifEnabled = advancedNotificationsAdminLoginNotificationsEnabled($login);
        $html .= '<div style="background:rgba(255,255,255,0.04);padding:14px;border-radius:6px;margin:16px 0;border-left:3px solid #ef4444;">' .
            '<h3 style="margin-top:0;">Préférences administrateur</h3>' .
            '<p><small>En tant qu’administrateur, vous pouvez choisir d’être alerté par notification PWA dès qu’un utilisateur s’authentifie avec succès.</small></p>' .
            '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'activate.php?change=1" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">' . $hidden .
            '<input type="hidden" name="action" value="toggle_login_notifications">' .
            '<label class="Musicband-check-label" style="font-weight:600;margin:0;">' .
            '<input type="checkbox" class="Musicband-checkbox" name="notify_login" value="1"' . ($loginNotifEnabled ? ' checked' : '') . '>' .
            ' M’alerter lors de la connexion réussie d’un utilisateur' .
            '</label>' .
            '<button type="submit" class="button-primary">Enregistrer ce réglage</button>' .
            '</form>' .
            '<p style="margin:6px 0 0 0;"><small style="color:var(--text-muted,#9ca3af);">Désactivé par défaut. Inclut l’identifiant et l’horodatage de la connexion. Les tentatives échouées et rafraîchissements de page ne déclenchent aucune notification.</small></p>' .
            '</div>';
    }

    // Niveau 3A : Règles par défaut copiées
    $html .= '<h3>Règles standard du groupe</h3>' .
        '<p><small>Ces règles standard sont initialisées d’après les recommandations du groupe. Vous pouvez activer ou désactiver chaque règle individuellement, ou rétablir les réglages par défaut recommandés.</small></p>' .
        '<div class="business-table-scroll"><table style="width:100%;margin-top:8px;">' .
        '<thead><tr><th>Règle</th><th>Événement</th><th>Délai</th><th>État</th><th>Actions</th></tr></thead><tbody>';

    foreach ($defaultRules as $r) {
        $id = MusicbandEscape((string)($r['id'] ?? ''));
        $name = MusicbandEscape((string)($r['name'] ?? ''));
        $eventKey = (string)($r['event_key'] ?? '');
        $eventLabel = MusicbandEscape($catalog[$eventKey]['action_label'] ?? $eventKey);
        $offset = !empty($r['reminder_offset']) ? advancedFormatOffsetLabel((int)$r['reminder_offset']) . ' avant' : 'Immédiat';
        $active = !empty($r['enabled']);

        $html .= '<tr>' .
            '<td><span class="Musicband-table-label">Règle</span><span class="Musicband-cell-value"><strong>' . $name . '</strong></span></td>' .
            '<td><span class="Musicband-table-label">Événement</span><span class="Musicband-cell-value">' . $eventLabel . '</span></td>' .
            '<td><span class="Musicband-table-label">Délai</span><span class="Musicband-cell-value">' . $offset . '</span></td>' .
            '<td><span class="Musicband-table-label">État</span><span class="Musicband-cell-value">' .
            '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'activate.php?change=1" style="display:inline;">' . $hidden .
            '<input type="hidden" name="action" value="toggle_rule">' .
            '<input type="hidden" name="rule_id" value="' . $id . '">' .
            '<input type="hidden" name="enabled" value="' . ($active ? '0' : '1') . '">' .
            '<button class="button-action ' . ($active ? 'button-success' : 'button-muted') . '">' .
            ($active ? 'Active' : 'Désactivée') . '</button>' .
            '</form>' .
            '</span></td>' .
            '<td><span class="Musicband-table-label">Actions</span><span class="Musicband-cell-value">' .
            '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'activate.php?change=1" style="display:inline;">' . $hidden .
            '<input type="hidden" name="action" value="duplicate_rule">' .
            '<input type="hidden" name="rule_id" value="' . $id . '">' .
            '<button class="button-action" title="Dupliquer pour personnaliser les conditions">Dupliquer</button>' .
            '</form>' .
            '</span></td>' .
            '</tr>';
    }

    $html .= '</tbody></table></div>';

    $html .= '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'activate.php?change=1" style="margin-top:12px;" onsubmit="return confirm(\'Rétablir toutes les règles standard préconfigurées ? Vos règles personnelles ne seront pas modifiées.\');">' . $hidden .
        '<input type="hidden" name="action" value="reset_default_rules">' .
        '<button type="submit" class="button-secondary">Rétablir les règles standard du groupe</button>' .
        '</form>';

    // Niveau 3B : Règles personnalisées
    $html .= '<h3 style="margin-top:24px;">Mes règles personnalisées</h3>' .
        '<p><small>Créez des règles sur mesure adaptées à vos besoins (50 règles maximum, avec jusqu’à 5 conditions de filtrage par règle). Toute nouvelle règle démarre désactivée par défaut.</small></p>';

    if (empty($customRules)) {
        $html .= '<p>Vous n’avez aucune règle personnalisée pour le moment.</p>';
    } else {
        $html .= '<div class="business-table-scroll"><table style="width:100%;margin-top:8px;">' .
            '<thead><tr><th>Nom</th><th>Événement</th><th>Délai</th><th>État</th><th>Actions</th></tr></thead><tbody>';

        foreach ($customRules as $cr) {
            $cid = MusicbandEscape((string)($cr['id'] ?? ''));
            $cname = MusicbandEscape((string)($cr['name'] ?? ''));
            $ceventKey = (string)($cr['event_key'] ?? '');
            $ceventLabel = MusicbandEscape($catalog[$ceventKey]['action_label'] ?? $ceventKey);
            $coffset = !empty($cr['reminder_offset']) ? advancedFormatOffsetLabel((int)$cr['reminder_offset']) . ' avant' : 'Immédiat';
            $cactive = !empty($cr['enabled']);

            $html .= '<tr>' .
                '<td><span class="Musicband-table-label">Nom</span><span class="Musicband-cell-value"><strong>' . $cname . '</strong></span></td>' .
                '<td><span class="Musicband-table-label">Événement</span><span class="Musicband-cell-value">' . $ceventLabel . '</span></td>' .
                '<td><span class="Musicband-table-label">Délai</span><span class="Musicband-cell-value">' . $coffset . '</span></td>' .
                '<td><span class="Musicband-table-label">État</span><span class="Musicband-cell-value">' .
                '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'activate.php?change=1" style="display:inline;">' . $hidden .
                '<input type="hidden" name="action" value="toggle_rule">' .
                '<input type="hidden" name="rule_id" value="' . $cid . '">' .
                '<input type="hidden" name="enabled" value="' . ($cactive ? '0' : '1') . '">' .
                '<button class="button-action ' . ($cactive ? 'button-success' : 'button-muted') . '">' .
                ($cactive ? 'Active' : 'Désactivée') . '</button>' .
                '</form>' .
                '</span></td>' .
                '<td><span class="Musicband-table-label">Actions</span><span class="Musicband-cell-value" style="display:inline-flex;gap:4px;flex-wrap:wrap;">' .
                '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'activate.php?change=1" style="display:inline;">' . $hidden .
                '<input type="hidden" name="action" value="duplicate_rule">' .
                '<input type="hidden" name="rule_id" value="' . $cid . '">' .
                '<button class="button-action" title="Dupliquer pour personnaliser les conditions">Dupliquer</button>' .
                '</form>' .
                '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'activate.php?change=1" style="display:inline;" onsubmit="return confirm(\'Supprimer cette règle personnalisée ?\');">' . $hidden .
                '<input type="hidden" name="action" value="delete_custom_rule">' .
                '<input type="hidden" name="rule_id" value="' . $cid . '">' .
                '<button class="button-action button-danger" title="Supprimer la règle">Supprimer</button>' .
                '</form>' .
                '</span></td>' .
                '</tr>';
        }

        $html .= '</tbody></table></div>';
    }

    // Formulaire d'ajout d'une nouvelle règle personnalisée
    $eventOptions = '';
    foreach ($catalog as $k => $def) {
        if (!empty($def['permission']) && function_exists('MusicbandCan') && !MusicbandCan($def['permission'], ['login' => $login])) {
            continue;
        }
        $eventOptions .= '<option value="' . MusicbandEscape($k) . '">' . MusicbandEscape($def['module'] . ' · ' . $def['action_label']) . '</option>';
    }

    $html .= '<details style="margin-top:16px;"><summary>Créer une nouvelle règle personnalisée</summary>' .
        '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'activate.php?change=1" style="margin-top:12px;">' . $hidden .
        '<input type="hidden" name="action" value="save_custom_rule">' .
        '<label>Nom de votre règle<input name="name" required maxlength="100" placeholder="Ex. Morceau marqué À travailler"></label>' .
        '<label>Événement concerné<select name="event_key">' . $eventOptions . '</select></label>' .
        '<label>Type d’alerte<select name="type"><option value="event">Événement direct (action immédiate)</option><option value="reminder">Rappel planifié avant échéance</option></select></label>' .
        '<label>Délai avant échéance (pour les rappels)<select name="reminder_offset">' .
        '<option value="900">15 minutes</option>' .
        '<option value="1800">30 minutes</option>' .
        '<option value="3600">1 heure</option>' .
        '<option value="7200" selected>2 heures</option>' .
        '<option value="21600">6 heures</option>' .
        '<option value="43200">12 heures</option>' .
        '<option value="86400">24 heures (1 jour)</option>' .
        '<option value="172800">48 heures (2 jours)</option>' .
        '<option value="604800">7 jours</option>' .
        '</select></label>' .
        '<label class="Musicband-check-label"><input type="checkbox" class="Musicband-checkbox" name="target_assignee_only" value="1"> Ne me notifier que si la tâche m’est assignée</label>' .
        '<h4>Condition facultative sur un champ</h4>' .
        '<label>Nom du champ à surveiller<input name="cond_field" placeholder="ex. next_rehearsal_work, status, ville…"></label>' .
        '<label>Opérateur de comparaison<select name="cond_op">' .
        '<option value="equals">Égal à (equals)</option>' .
        '<option value="not_equals">Différent de (not_equals)</option>' .
        '<option value="changed">A changé (changed)</option>' .
        '<option value="changed_to">Passe à la valeur (changed_to)</option>' .
        '<option value="changed_from">Quitte la valeur (changed_from)</option>' .
        '<option value="changed_from_to">Passe d’une valeur à une autre (changed_from_to)</option>' .
        '<option value="contains">Contient le texte (contains)</option>' .
        '<option value="is_not_empty">N’est pas vide (is_not_empty)</option>' .
        '<option value="is_empty">Est vide (is_empty)</option>' .
        '</select></label>' .
        '<label>Valeur cible attendue<input name="cond_value" placeholder="ex. 1, en_cours, Paris…"></label>' .
        '<label>Valeur précédente (pour changed_from / changed_from_to)<input name="cond_prev_value" placeholder="ex. 0, a_faire…"></label>' .
        '<p><small>La nouvelle règle sera créée désactivée. Vous pourrez l’activer d’un clic après vérification.</small></p>' .
        '<button class="button-primary">Créer ma règle</button>' .
        '</form></details>';

    $html .= '</section>';
    return $html;
}
