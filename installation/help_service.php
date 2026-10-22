<?php
/**
 * Musicband HELP SERVICE: Gestion, rendu Markdown sécurisé et personnalisation de l'aide Musicband.
 *
 * Responsabilités :
 * - Fourniture du contenu d'aide par défaut (rubriques Contacts, Concerts, Devis/Factures, Setlist, Sound, Se déplacer).
 * - Intégration de tous les modules applicatifs déclarés dans le catalogue de navigation (Morceaux, Agenda, Tâches, etc.).
 * - Persistance et fusion de la configuration personnalisée (help_settings.json) via MusicbandRead/MusicbandWrite.
 * - Conversion Markdown standard en HTML sécurisé (titres, paragraphes, listes, gras, italique, liens sûrs, immunité XSS).
 * - Génération du menu d'aide complet avec rubriques repliées par défaut (<details class="Musicband-help-menu">).
 * - Formulaire d'administration pour la section « Gestion de l'aide » dans admin.php (édition ciblée, sans aperçu intrusif, restauration).
 *
 * Dépendances internes :
 * - web/auth.php (MusicbandRead, MusicbandWrite, MusicbandEscape, MusicbandCsrf, MusicbandAuditEvent, MusicbandHomeModules, MusicbandNavigationCatalog)
 *
 * Dépendances externes : aucune (fonctions natives PHP).
 */
declare(strict_types=1);

if (!function_exists('MusicbandEscape')) {
    require_once __DIR__ . '/auth.php';
}

/**
 * Retourne la liste complète des modules applicatifs disponibles depuis le catalogue de navigation.
 * Associe l'identifiant unique du module à son libellé officiel.
 */
function MusicbandHelpModules(): array {
    $catalog = function_exists('MusicbandNavigationCatalog') ? MusicbandNavigationCatalog() : [];
    $order = function_exists('MusicbandHomeOrder') ? MusicbandHomeOrder() : array_keys($catalog);
    $displayNames = function_exists('MusicbandModuleDisplayNames') ? MusicbandModuleDisplayNames() : [];
    $modules = [];
    foreach ($order as $key) {
        if (isset($catalog[$key])) {
            $modules[$key] = $displayNames[$key] ?? $catalog[$key]['label'];
        }
    }
    if (!empty($modules)) {
        return $modules;
    }
    return [
        'home' => 'Accueil',
        'setlist' => 'Setlist',
        'songs' => 'Morceaux',
        'sound' => 'Sound · XR18',
        'contacts' => 'Contacts',
        'concerts' => 'Concerts',
        'agenda' => 'Agenda',
        'devis_factures' => 'Devis / Factures',
        'tasks' => 'Gestion des tâches',
        'admin' => 'Configuration',
        'audit' => 'Audit trail',
        'ideas' => 'Boîte à idées',
        'password' => 'Préférences',
        'logout' => 'Déconnexion',
    ];
}

/**
 * Retourne le catalogue des rubriques d'aide par défaut avec leurs titres et contenus en Markdown.
 */
function MusicbandHelpDefaults(): array {
    return [
        'title' => 'Utiliser Musicband Toolbox',
        'intro' => 'Choisissez une rubrique. En haut de chaque page, Instructions rappelle les premières étapes.',
        'outro' => 'Cliquez de nouveau sur ? pour fermer ce guide. Chaque rubrique peut aussi être repliée.',
        'sections' => [
            'home' => [
                'title' => 'Accueil',
                'markdown' => "**À quoi sert ce module ?**\nAccéder en un clic aux espaces de travail du groupe et consulter les repères essentiels du groupe.\n\n**Comment l'utiliser ?**\n1. Consulter le tableau de bord pour vérifier les alertes actives et les prochains concerts.\n2. Cliquer sur la tuile d’un module pour ouvrir directement son espace.\n3. Utiliser le bouton menu en haut à droite pour parcourir l’ensemble des rubriques et réglages.\n\n**À savoir**\nLes tuiles affichées sur l’accueil, leur nom et leur ordre peuvent être personnalisés par un administrateur dans Configuration.",
            ],
            'setlist' => [
                'title' => 'Setlist · les morceaux du concert',
                'markdown' => "**À quoi sert ce module ?**\nPréparer, ajuster et exporter la liste des morceaux d’un concert avec le calcul exact des durées.\n\n**Comment l'utiliser ?**\n1. Sélectionner une setlist publiée puis la variante souhaitée dans les menus du haut.\n2. Ouvrir le menu Actions de la setlist et activer le mode Édition.\n3. Déplacer les morceaux avec les flèches ou la poignée pour ajuster l’ordre de jeu.\n4. Répartir les titres entre le set principal et le rappel avec les boutons dédiés.\n5. Utiliser Tous morceaux pour ajouter des titres issus de la réserve.\n6. Cliquer sur Enregistrer pour mémoriser vos modifications, puis choisir un format dans Exporter (Tempo, SACEM, Excel ou Imprimer).\n\n**À savoir**\nLa durée scénique se recalcule en temps réel (musique, transitions et pauses). Rétablir la variante annule les modifications non enregistrées.",
            ],
            'songs' => [
                'title' => 'Morceaux',
                'markdown' => "**À quoi sert ce module ?**\nGérer le répertoire musical du groupe avec les durées, tonalités, tempos et liens de partitions.\n\n**Comment l'utiliser ?**\n1. Parcourir le tableau du catalogue ou saisir un titre/artiste dans le champ Rechercher.\n2. Cliquer sur un morceau pour ouvrir sa fiche complète et ses repères de jeu.\n3. Cliquer sur Ajouter un morceau pour enregistrer un nouveau titre, ou sur Modifier le morceau pour corriger sa fiche.\n4. Renseigner les champs utiles (titre, artiste, durée, tempo, tonalité, chanteur et lien de tablature).\n5. Cliquer sur Enregistrer le morceau pour valider la fiche.\n\n**À savoir**\nSur smartphone, le lien de tablature Ultimate Guitar s’ouvre directement dans l’application native si elle est installée sur votre appareil.",
            ],
            'sound' => [
                'title' => 'Sound · XR18 · les réglages du son',
                'markdown' => "**À quoi sert ce module ?**\nAnalyser les scènes de la console XR18 et obtenir des conseils pratiques pour ajuster le son des pistes.\n\n**Comment l'utiliser ?**\n1. Charger un fichier de scène `.scn` ou sélectionner une scène déjà présente dans la bibliothèque.\n2. Choisir la piste audio à examiner pour afficher ses réglages actuels.\n3. Sélectionner l’instrument et décrire la gêne ou le défaut sonore entendu.\n4. Cliquer sur Construire les essais sans IA pour découvrir les réglages suggérés.\n5. Appliquer les conseils sur la console physique, puis enregistrer votre retour avec Noter le résultat après écoute.\n6. Télécharger le rapport de réglages au format PDF via le menu Actions si souhaité.\n\n**À savoir**\nL’application ne modifie pas la console physique à votre place : les réglages restent toujours sous votre contrôle manuel.",
            ],
            'contacts' => [
                'title' => 'Contacts',
                'markdown' => "**À quoi sert ce module ?**\nCentraliser les coordonnées des organisateurs, lieux de diffusion, techniciens et partenaires du groupe.\n\n**Comment l'utiliser ?**\n1. Trouver un contact en tapant un nom, une structure ou une ville dans Rechercher.\n2. Cliquer sur une ligne pour ouvrir sa fiche détaillée et consulter ses informations.\n3. Utiliser Nouveau contact pour ajouter une fiche, ou Modifier pour corriger ses coordonnées.\n4. Renseigner le nom, le numéro de téléphone, l’adresse e-mail et l’adresse postale.\n5. Cliquer sur Enregistrer pour mémoriser les changements.\n\n**À savoir**\nLe bouton Colonnes affichées permet de choisir les informations visibles dans le tableau pour adapter l’affichage à votre écran.",
            ],
            'concerts' => [
                'title' => 'Concerts',
                'markdown' => "**À quoi sert ce module ?**\nPlanifier les dates de concerts du groupe, leurs horaires, lieux et contacts associés.\n\n**Comment l'utiliser ?**\n1. Consulter les événements dans Concerts à venir, Concerts passés ou Dates à préciser.\n2. Cliquer sur le nom d’un concert pour afficher sa fiche complète.\n3. Cliquer sur Nouveau concert ou Modifier pour renseigner la date, le lieu, les horaires et le client associé.\n4. Associer une setlist au concert depuis la liste déroulante proposée sur sa fiche.\n5. Cliquer sur Enregistrer pour valider la fiche du concert.\n\n**À savoir**\nUne setlist associée à un concert ne peut pas être supprimée afin de préserver l’historique des représentations du groupe.",
            ],
            'agenda' => [
                'title' => 'Agenda',
                'markdown' => "**À quoi sert ce module ?**\nVisualiser les dates, répétitions et événements à venir du groupe synchronisés avec le calendrier d'équipe.\n\n**Comment l'utiliser ?**\n1. Parcourir la liste chronologique des événements programmés.\n2. Vérifier les dates, horaires de début et de fin ainsi que les lieux indiqués.\n3. Cliquer sur Actions · Agenda puis Actualiser les prochains événements pour forcer la mise à jour des données.\n4. Utiliser le lien Ouvrir dans Google Agenda pour afficher l’événement dans votre calendrier habituel.\n\n**À savoir**\nCe module fonctionne en lecture directe depuis le calendrier partagé configuré pour l’équipe.",
            ],
            'devis_factures' => [
                'title' => 'Devis / Factures',
                'markdown' => "**À quoi sert ce module ?**\nCréer, numéroter et gérer les devis et factures du groupe liés aux concerts et aux clients.\n\n**Comment l'utiliser ?**\n1. Cliquer sur Nouveau document pour entamer une pièce comptable.\n2. Sélectionner le type (Devis ou Facture), puis associer le client et le concert correspondant.\n3. Vérifier les montants, les coordonnées et les conditions de règlement préremplis.\n4. Cliquer sur Enregistrer pour attribuer le numéro officiel chronologique du document.\n5. Une fois le document vérifié, passer son état de Préparation à Validé.\n\n**À savoir**\nEn cas de modification nécessaire après validation, remettez temporairement le document en Préparation pour corriger les champs.",
            ],
            'expenses' => ['title'=>'Dépenses','markdown'=>"Créer une dépense en choisissant le pseudo du membre, la date, le montant positif, le fournisseur et la justification. Un nouveau fournisseur est réutilisable et les doublons sont évités.\n\nUne avance reste hors solde jusqu’au remboursement, dont la date est obligatoire. Un virement est débité à la date de dépense. Virements, remboursements et suppressions sont réservés aux administrateurs. Les filtres portent par défaut sur l’année courante ; les avances en attente couvrent toutes les années. Les modifications sont historisées."],
            'accounting' => ['title'=>'Comptabilité','markdown'=>"Configurer d’abord le solde initial dans Configuration → Comptabilité : c’est le solde de clôture à la date choisie. Seuls les mouvements strictement postérieurs comptent.\n\nLes crédits concerts attendus reprennent le cachet et la date de prestation. Cocher Argent reçu puis valider confirme la recette ; une facture seule ne vaut jamais paiement. Un seul crédit par concert, même avec plusieurs documents. Aucun paiement partiel n’est géré. La confirmation est réservée aux administrateurs et impossible avant la prestation.\n\nLa recette confirmée conserve son montant et sa date. Pour corriger : ouvrir l’écriture, décocher Argent reçu, enregistrer, corriger le concert puis confirmer à nouveau. Les écritures manuelles sont des mouvements bancaires effectifs.\n\nLe solde actuel ignore les filtres. Le solde chronologique de chaque ligne inclut les mouvements antérieurs avant filtrage. Les totaux et graphiques suivent les filtres et excluent les mouvements inclus dans le solde initial. Les avances non remboursées sont affichées séparément. Les écritures futures n’entrent pas dans le solde actuel."],
            'tasks' => [
                'title' => 'Gestion des tâches',
                'markdown' => "**À quoi sert ce module ?**\nCoordonner les actions du groupe en suivant les tâches à réaliser, leurs responsables et leurs échéances.\n\n**Comment l'utiliser ?**\n1. Parcourir le tableau des tâches en cours, en attente ou terminées.\n2. Cliquer sur ＋ Nouvelle tâche pour créer une nouvelle action d’équipe.\n3. Indiquer le titre, les détails utiles, la date d’échéance et le membre assigné.\n4. Mettre à jour l’avancement (À faire, En cours, Terminée) directement depuis le tableau ou la fiche.\n5. Filtrer par statut pour isoler les actions prioritaires ou non clôturées.\n\n**À savoir**\nLes tâches dont la date limite est dépassée sont automatiquement signalées par un indicateur rouge « en retard ».",
            ],
            'admin' => [
                'title' => 'Configuration',
                'markdown' => "**À quoi sert ce module ?**\nAdministrer les réglages de la plateforme, les comptes utilisateurs, la navigation et le contenu d’aide.\n\n**Comment l'utiliser ?**\n1. Naviguer entre les sections Général, Utilisateurs, Gestion de l’aide et les modules métiers.\n2. Dans Général, organiser l’ordre des modules, personnaliser leurs noms, leurs icônes et leur présence sur l’accueil.\n3. Dans Utilisateurs, gérer les comptes, créer des invitations et ajuster les rôles et permissions RBAC.\n4. Dans Gestion de l’aide, modifier les textes d’aide de chaque module en Markdown ou rétablir les textes d’origine.\n5. Cliquer sur le bouton Enregistrer de la section modifiée pour appliquer les changements.\n\n**À savoir**\nCe module est strictement réservé aux comptes bénéficiant des prérogatives d’administration.",
            ],
            'audit' => [
                'title' => 'Audit trail',
                'markdown' => "**À quoi sert ce module ?**\nConsulter l’historique d’activité et de sécurité de la plateforme pour vérifier les connexions et opérations effectuées.\n\n**Comment l'utiliser ?**\n1. Définir la période temporelle observée à l’aide des dates de début et de fin.\n2. Filtrer le journal par module, acteur, action ou résultat (succès ou rejet).\n3. Examiner le tableau pour consulter l’horodatage exact, l’adresse IP et le détail de chaque événement.\n4. Utiliser Enregistrer le filtre pour conserver vos combinaisons de critères régulières.\n\n**À savoir**\nLe journal d’audit est conservé pendant six mois calendaires et ne peut pas être modifié manuellement.",
            ],
            'ideas' => [
                'title' => 'Boîte à idées',
                'markdown' => "**À quoi sert ce module ?**\nPartager des suggestions et de nouvelles idées avec l’ensemble des membres du groupe.\n\n**Comment l'utiliser ?**\n1. Consulter les propositions déjà partagées par l’équipe.\n2. Renseigner votre titre d’idée, cocher le ou les modules concernés et rédiger votre description.\n3. Cliquer sur Soumettre l'idée pour la publier auprès des autres membres.\n4. Échanger sur les idées soumises lors des répétitions ou des bilans de concert.\n\n**À savoir**\nToutes les idées déposées sont visibles par les membres connectés du groupe sans restriction.",
            ],
            'password' => [
                'title' => 'Préférences',
                'markdown' => "**À quoi sert ce module ?**\nRegrouper vos préférences générales, l’application PWA, les notifications, l’export Excel Setlist et la sécurité du compte.\n\n**Comment l'utiliser ?**\n1. Ouvrir Préférences générales pour le profil et les widgets d’accueil.\n2. Ouvrir Application / PWA pour les consignes d’installation sur l’écran d’accueil.\n3. Dans Notifications et rappels, activer ou désactiver cet appareil et tester la réception ; gérer vos règles personnelles selon vos autorisations.\n4. Dans Setlist Excel Export, sélectionner et réordonner les colonnes ; Ordre, Titre et Guitare restent obligatoires.\n5. Ouvrir Sécurité du compte pour changer le mot de passe.\n\n**À savoir**\nL’installation et l’autorisation Push dépendent du navigateur ; sur iPhone, ouvrir la version ajoutée à l’écran d’accueil. La modification du mot de passe révoque les anciennes sessions et les abonnements doivent être réactivés. Les anciennes adresses Application et notifications redirigent vers Préférences.",
            ],
            'logout' => [
                'title' => 'Déconnexion',
                'markdown' => "**À quoi sert ce module ?**\nFermer votre session de travail de manière sécurisée sur l’appareil en cours d’utilisation.\n\n**Comment l'utiliser ?**\n1. Cliquer sur Déconnexion dans le menu général lorsque vous avez terminé.\n2. Votre session est instantanément close et les cookies d’accès sont détruits.\n3. La page de connexion s’affiche à nouveau pour une prochaine session.\n\n**À savoir**\nPensez à enregistrer vos fiches ou réglages en cours avant de vous déconnecter pour ne perdre aucune saisie.",
            ],
            'navigation' => [
                'title' => 'Se déplacer et se connecter',
                'markdown' => "**À quoi sert ce module ?**\nNaviguer aisément entre les modules de la plateforme et gérer sa connexion.\n\n**Comment l'utiliser ?**\n1. Ouvrir le menu général en haut de page pour accéder à l'ensemble des modules.\n2. Revenir à l’accueil à tout moment en cliquant sur le logo Musicband ou l'entrée Accueil.\n3. Penser à enregistrer vos changements avant de quitter une page.\n\n**À savoir**\nSi votre session expire après une période d'inactivité, reconnectez-vous pour reprendre votre travail.",
            ],
        ],
    ];
}

/**
 * Récupère la configuration courante de l'aide en fusionnant avec les valeurs par défaut.
 */
function MusicbandHelpSettings(): array {
    $defaults = MusicbandHelpDefaults();
    $saved = MusicbandRead('help_settings');
    if (!is_array($saved)) {
        $saved = [];
    }

    $result = $defaults;
    if (!empty($saved['title']) && is_string($saved['title'])) {
        $result['title'] = trim($saved['title']);
    }
    if (!empty($saved['intro']) && is_string($saved['intro'])) {
        $result['intro'] = trim($saved['intro']);
    }

    if (!empty($saved['sections']) && is_array($saved['sections'])) {
        foreach ($saved['sections'] as $key => $secData) {
            if (!is_string($key) || !is_array($secData)) continue;
            $secTitle = trim((string)($secData['title'] ?? ''));
            $secMarkdown = (string)($secData['markdown'] ?? '');
            if ($secTitle === '' && isset($defaults['sections'][$key])) {
                $secTitle = $defaults['sections'][$key]['title'];
            }
            if ($secTitle !== '') {
                $result['sections'][$key] = [
                    'title' => $secTitle,
                    'markdown' => $secMarkdown,
                ];
            }
        }
    }

    return $result;
}

/**
 * Convertit un texte Markdown standard en HTML sécurisé.
 * Supporte : titres (# à ####), paragraphes, listes à puces (- ou *), listes numérotées,
 * gras (** ou __), italique (* ou _), code (`code`) et liens [texte](url).
 * Échappe systématiquement tout HTML avant traitement pour immunité XSS.
 */
function MusicbandMarkdownToSafeHtml(string $markdown): string {
    $text = str_replace(["\r\n", "\r"], "\n", $markdown);
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    // Échappement préalable strict de tout caractère HTML pour garantir la sécurité XSS
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $inline = function(string $str): string {
        // Code en ligne `code`
        $str = preg_replace('/`([^`]+)`/', '<code>$1</code>', $str) ?? $str;
        // Gras + italique ***texte*** ou ___texte___
        $str = preg_replace('/\*\*\*([^*]+)\*\*\*/', '<strong><em>$1</em></strong>', $str) ?? $str;
        $str = preg_replace('/___([^_]+)___/', '<strong><em>$1</em></strong>', $str) ?? $str;
        // Gras **texte** ou __texte__
        $str = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $str) ?? $str;
        $str = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $str) ?? $str;
        // Italique *texte* ou _texte_
        $str = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $str) ?? $str;
        $str = preg_replace('/_([^_]+)_/', '<em>$1</em>', $str) ?? $str;
        // Liens [texte](url)
        $str = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function($matches) {
            $linkText = $matches[1];
            $url = $matches[2];
            $decoded = strtolower(trim(htmlspecialchars_decode($url, ENT_QUOTES)));
            // Protocoles strictement autorisés
            if (str_starts_with($decoded, 'https://') || str_starts_with($decoded, 'http://') || str_starts_with($decoded, 'mailto:') || str_starts_with($decoded, '#') || str_starts_with($decoded, '/') || str_starts_with($decoded, './')) {
                return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $linkText . '</a>';
            }
            return $linkText . ' (' . $url . ')';
        }, $str) ?? $str;
        return $str;
    };

    $blocks = preg_split('/\n{2,}/', $escaped);
    $htmlBlocks = [];

    foreach ($blocks as $block) {
        $trimmed = trim($block);
        if ($trimmed === '') continue;

        // Titres (# Titre, ## Titre, ### Titre, #### Titre)
        if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $m)) {
            $level = strlen($m[1]);
            $tag = $level <= 2 ? 'h3' : 'h4';
            $htmlBlocks[] = '<' . $tag . '>' . $inline($m[2]) . '</' . $tag . '>';
            continue;
        }

        $lines = explode("\n", $trimmed);
        $isUl = count($lines) > 0;
        $isOl = count($lines) > 0;
        foreach ($lines as $line) {
            if (!preg_match('/^\s*[-*]\s+/', $line)) $isUl = false;
            if (!preg_match('/^\s*\d+\.\s+/', $line)) $isOl = false;
        }

        if ($isUl && count($lines) > 0) {
            $items = '';
            foreach ($lines as $line) {
                $itemText = preg_replace('/^\s*[-*]\s+/', '', $line);
                $items .= '<li>' . $inline($itemText) . '</li>';
            }
            $htmlBlocks[] = '<ul>' . $items . '</ul>';
            continue;
        }

        if ($isOl && count($lines) > 0) {
            $items = '';
            foreach ($lines as $line) {
                $itemText = preg_replace('/^\s*\d+\.\s+/', '', $line);
                $items .= '<li>' . $inline($itemText) . '</li>';
            }
            $htmlBlocks[] = '<ol>' . $items . '</ol>';
            continue;
        }

        // Paragraphe standard
        $pLines = array_map($inline, $lines);
        $htmlBlocks[] = '<p>' . implode('<br>', $pLines) . '</p>';
    }

    return implode("\n", $htmlBlocks);
}

/**
 * Génère le composant HTML complet du menu d'aide (<details class="Musicband-help-menu">).
 * Toutes les rubriques s'ouvrent repliées par défaut (<details> sans attribut open).
 */
function MusicbandHelpMenuHtml(): string {
    $settings = MusicbandHelpSettings();
    $sectionsHtml = '';

    // Ordre de restitution : modules selon MusicbandHelpModules(), puis autres rubriques préservées
    $modules = MusicbandHelpModules();
    $displayNames = function_exists('MusicbandModuleDisplayNames') ? MusicbandModuleDisplayNames() : [];
    $allKeys = array_unique(array_merge(array_keys($modules), array_keys($settings['sections'])));

    foreach ($allKeys as $key) {
        // Conserver les rubriques historiques enregistrées, sans proposer les modules exclus.
        if (function_exists('MusicbandModuleEnabled') && !MusicbandModuleEnabled($key)) continue;
        if (!isset($settings['sections'][$key])) continue;
        $section = $settings['sections'][$key];
        $title = trim((string)($section['title'] ?? ''));
        // Si le nom du module a été personnalisé dans la configuration centralisée, l'utiliser en priorité
        if (isset($displayNames[$key]) && $displayNames[$key] !== '' && !isset($section['updated_at'])) {
            $title = $displayNames[$key];
        }
        $markdown = trim((string)($section['markdown'] ?? ''));
        if ($title === '' || $markdown === '') {
            // Ignorer les rubriques non renseignées ou vides
            continue;
        }

        // Vérification des permissions par module si applicable
        if (function_exists('MusicbandCan')) {
            if ($key === 'agenda' && !MusicbandCan('calendar.read')) continue;
            if (in_array($key,['expenses','accounting'],true) && !MusicbandCan($key.'.read')) continue;
            if ($key === 'tasks' && !MusicbandCan('tasks.read')) continue;
            if ($key === 'audit' && !MusicbandCan('audit.read')) continue;
        }
        if ($key === 'admin' && function_exists('MusicbandUser') && !(MusicbandUser()['admin'] ?? false)) {
            continue;
        }

        $body = MusicbandMarkdownToSafeHtml($markdown);
        $sectionsHtml .= '<details><summary>' . MusicbandEscape($title) . '</summary>' . "\n" . $body . "\n" . '</details>' . "\n";
    }

    return '<details class="Musicband-help-menu"><summary aria-label="Aide" title="Aide">?</summary>' . "\n"
        . '<div class="Musicband-help-panel" role="region" aria-label="Aide des modules Musicband">' . "\n"
        . '<h2>' . MusicbandEscape($settings['title'] ?? 'Utiliser Musicband Toolbox') . '</h2>' . "\n"
        . '<p>' . MusicbandEscape($settings['intro'] ?? 'Choisissez une rubrique. En haut de chaque page, Instructions rappelle les premières étapes.') . '</p>' . "\n"
        . $sectionsHtml
        . '<p>' . MusicbandEscape($settings['outro'] ?? 'Cliquez de nouveau sur ? pour fermer ce guide. Chaque rubrique peut aussi être repliée.') . '</p>' . "\n"
        . '</div></details>';
}

/**
 * Enregistre une modification de rubrique dans help_settings.json.
 */
function MusicbandHelpSaveSection(string $key, string $title, string $markdown): void {
    $key = trim($key);
    if (!preg_match('/^[a-z_]{2,40}$/', $key)) {
        throw new RuntimeException('Identifiant de rubrique invalide.');
    }

    $title = trim($title);
    if ($title === '') {
        throw new RuntimeException('Le titre de la rubrique ne peut pas être vide.');
    }
    if (strlen($title) > 120) {
        throw new RuntimeException('Titre trop long (120 caractères maximum).');
    }
    if (strlen($markdown) > 100000) {
        throw new RuntimeException('Contenu Markdown trop volumineux (100 Ko maximum).');
    }

    $saved = MusicbandRead('help_settings');
    if (!is_array($saved)) {
        $saved = [];
    }
    if (empty($saved['sections']) || !is_array($saved['sections'])) {
        $saved['sections'] = [];
    }

    $saved['sections'][$key] = [
        'title' => $title,
        'markdown' => $markdown,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    MusicbandWrite('help_settings', $saved);
}

/**
 * Restaure les valeurs par défaut pour une rubrique ou pour l'ensemble de l'aide.
 */
function MusicbandHelpReset(string $section = 'all'): void {
    if ($section === 'all' || $section === '') {
        MusicbandWrite('help_settings', []);
        return;
    }

    $saved = MusicbandRead('help_settings');
    if (is_array($saved) && !empty($saved['sections'][$section])) {
        unset($saved['sections'][$section]);
        MusicbandWrite('help_settings', $saved);
    }
}

/**
 * Traite l'action POST d'enregistrement de l'aide dans admin.php.
 */
function MusicbandHelpSaveAction(): string {
    $key = trim((string)($_POST['help_section'] ?? ''));
    $title = trim((string)($_POST['help_title'] ?? ''));
    $content = (string)($_POST['help_content'] ?? '');

    MusicbandHelpSaveSection($key, $title, $content);
    if (function_exists('MusicbandAuditEvent')) {
        MusicbandAuditEvent('configuration', 'help.section.updated', 'success', ['section' => $key]);
    }
    return 'Rubrique d’aide « ' . MusicbandEscape($title) . ' » enregistrée avec succès.';
}

/**
 * Traite l'action POST de réinitialisation de l'aide dans admin.php.
 */
function MusicbandHelpResetAction(): string {
    if (($_POST['confirm_reset'] ?? '') !== 'yes') {
        throw new RuntimeException('Confirmation requise pour rétablir le contenu par défaut.');
    }
    $target = trim((string)($_POST['reset_target'] ?? 'all'));
    MusicbandHelpReset($target);
    if (function_exists('MusicbandAuditEvent')) {
        MusicbandAuditEvent('configuration', 'help.reset', 'success', ['target' => $target]);
    }
    if ($target === 'all') {
        return 'L’ensemble du contenu d’aide a été restauré avec les valeurs par défaut.';
    }
    return 'La rubrique d’aide a été restaurée avec son contenu par défaut.';
}

/**
 * Construit le formulaire d'administration de l'aide dans Configuration (admin.php).
 * Inclut l'ensemble des modules applicatifs disponibles et supprime l'aperçu intrusif.
 */
function MusicbandHelpAdminForm(string $csrfHidden): string {
    $settings = MusicbandHelpSettings();
    $defaults = MusicbandHelpDefaults();
    $modules = MusicbandHelpModules();

    // La liste déroulante contient EXACTEMENT les modules de la configuration d'accueil, dans le même ordre
    $allKeys = $modules;

    $activeSectionKey = $_GET['help_section'] ?? '';
    if (!isset($allKeys[$activeSectionKey])) {
        $activeSectionKey = array_key_first($allKeys) ?? 'home';
    }

    $options = '';
    $sectionsData = [];
    foreach ($allKeys as $key => $configuredName) {
        $hasDefault = isset($defaults['sections'][$key]);
        $defaultTitle = $hasDefault ? $defaults['sections'][$key]['title'] : $configuredName;
        $defaultMarkdown = $hasDefault ? $defaults['sections'][$key]['markdown'] : '';

        // Priorité au nom personnalisé configuré si le titre de section n'a pas été surchargé
        $curTitle = $configuredName;
        $curMarkdown = $defaultMarkdown;

        $isSaved = isset($settings['sections'][$key]) && is_array($settings['sections'][$key]);
        if ($isSaved) {
            $savedTitle = trim((string)($settings['sections'][$key]['title'] ?? ''));
            if ($savedTitle !== '') $curTitle = $savedTitle;
            $curMarkdown = (string)($settings['sections'][$key]['markdown'] ?? '');
        }

        $hasContent = trim($curMarkdown) !== '';
        $isModified = ($curTitle !== $defaultTitle && $curTitle !== $configuredName) || ($curMarkdown !== $defaultMarkdown);

        if (!$hasContent) {
            $optionLabel = $curTitle . ' (à renseigner)';
        } elseif ($isModified) {
            $optionLabel = $curTitle . ' (personnalisée)';
        } else {
            $optionLabel = $curTitle;
        }

        $selected = ($key === $activeSectionKey) ? ' selected' : '';
        $options .= '<option value="' . MusicbandEscape($key) . '"' . $selected . '>' . MusicbandEscape($optionLabel) . '</option>';

        $sectionsData[$key] = [
            'key' => $key,
            'title' => $curTitle,
            'markdown' => $curMarkdown,
            'defaultTitle' => $defaultTitle,
            'defaultMarkdown' => $defaultMarkdown,
            'isModified' => $isModified,
            'hasDefault' => $hasDefault,
        ];
    }

    $activeSec = $sectionsData[$activeSectionKey];

    $html = '<section class="help-admin-section">'
        . '<p>Personnalisez le contenu de l’aide affichée dans le bouton <strong>?</strong> pour chaque module et rubrique. '
        . 'La mise en forme utilise la syntaxe standard <strong>Markdown</strong> (titres avec <code>###</code>, paragraphes séparés par une ligne vide, listes avec <code>-</code> ou <code>1.</code>, texte en <strong>**gras**</strong> ou <em>*italique*</em>, et liens sécurisés <code>[texte](url)</code>).</p>'
        . '<noscript><p class="notice">Pour naviguer sans JavaScript : '
        . implode(' · ', array_map(fn($k) => '<a href="?help_section=' . $k . '#config-aide">' . MusicbandEscape($sectionsData[$k]['title']) . '</a>', array_keys($allKeys)))
        . '</p></noscript>'
        . '<form method="post" id="help-editor-form" class="help-editor-form">'
        . $csrfHidden
        . '<input type="hidden" name="action" value="help_settings_save">'
        . '<input type="hidden" name="config_tab" value="aide">'
        . '<div class="help-form-row">'
        . '<label for="help-section-select"><strong>Module ou rubrique d’aide à modifier :</strong></label>'
        . '<select id="help-section-select" name="help_section" style="max-width: 400px; width: 100%;">' . $options . '</select>'
        . '</div>'
        . '<div class="help-form-row" style="margin-top: 12px;">'
        . '<label for="help-title-input"><strong>Titre de la rubrique :</strong></label>'
        . '<input type="text" id="help-title-input" name="help_title" value="' . MusicbandEscape($activeSec['title']) . '" required maxlength="120" style="max-width: 600px; width: 100%;">'
        . '</div>'
        . '<div class="help-form-row" style="margin-top: 12px;">'
        . '<label for="help-content-textarea"><strong>Contenu en Markdown :</strong></label>'
        . '<textarea id="help-content-textarea" name="help_content" rows="12" style="font-family: monospace, system-ui; width: 100%; box-sizing: border-box; min-height: 220px; line-height: 1.5;">' . MusicbandEscape($activeSec['markdown']) . '</textarea>'
        . '</div>'
        . '<div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">'
        . '<button type="submit">Enregistrer cette rubrique</button>'
        . '</div>'
        . '</form>'
        . '<div style="margin-top: 28px; border-top: 1px solid var(--Musicband-line, #383838); padding-top: 18px;">'
        . '<h3>Restauration du contenu par défaut</h3>'
        . '<p>Rétablir les textes d’aide originaux du projet sans toucher aux autres réglages.</p>'
        . '<div style="display: flex; gap: 12px; flex-wrap: wrap;">'
        . '<form method="post" onsubmit="return confirm(\'Rétablir le contenu par défaut pour cette rubrique ?\');" style="display: inline;">'
        . $csrfHidden
        . '<input type="hidden" name="action" value="help_settings_reset">'
        . '<input type="hidden" name="config_tab" value="aide">'
        . '<input type="hidden" name="confirm_reset" value="yes">'
        . '<input type="hidden" name="reset_target" id="help-reset-current-target" value="' . MusicbandEscape($activeSectionKey) . '">'
        . '<button type="submit" class="button button-secondary">Rétablir la rubrique « <span id="help-reset-btn-label">' . MusicbandEscape($activeSec['title']) . '</span> » par défaut</button>'
        . '</form>'
        . '<form method="post" onsubmit="return confirm(\'ATTENTION : Restaurer l’ensemble de l’aide avec les textes par défaut ? Toutes les personnalisations d’aide seront effacées.\');" style="display: inline;">'
        . $csrfHidden
        . '<input type="hidden" name="action" value="help_settings_reset">'
        . '<input type="hidden" name="config_tab" value="aide">'
        . '<input type="hidden" name="confirm_reset" value="yes">'
        . '<input type="hidden" name="reset_target" value="all">'
        . '<button type="submit" class="button button-danger">Restaurer toute l’aide par défaut</button>'
        . '</form>'
        . '</div>'
        . '</div>'
        . '<script id="help-sections-data" type="application/json">' . json_encode($sectionsData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . '</script>'
        . '</section>';

    return $html;
}

/**
 * Fournit le script client JavaScript pour la gestion en direct de l'éditeur d'aide dans admin.php.
 */
function MusicbandHelpEditorScript(): string {
    return <<<'JS'
(()=>{
  const sectionSelect = document.getElementById('help-section-select');
  const titleInput = document.getElementById('help-title-input');
  const contentTextarea = document.getElementById('help-content-textarea');
  const resetTargetInput = document.getElementById('help-reset-current-target');
  const resetBtnLabel = document.getElementById('help-reset-btn-label');
  const dataScript = document.getElementById('help-sections-data');

  if (!sectionSelect || !titleInput || !contentTextarea || !dataScript) return;

  let sectionsData = {};
  try {
    sectionsData = JSON.parse(dataScript.textContent);
  } catch (e) {
    return;
  }

  function loadSection(key) {
    const sec = sectionsData[key];
    if (!sec) return;
    titleInput.value = sec.title || '';
    contentTextarea.value = sec.markdown || '';
    if (resetTargetInput) resetTargetInput.value = key;
    if (resetBtnLabel) resetBtnLabel.textContent = sec.title || key;
  }

  sectionSelect.addEventListener('change', () => {
    loadSection(sectionSelect.value);
  });

  titleInput.addEventListener('input', () => {
    if (sectionsData[sectionSelect.value]) {
      sectionsData[sectionSelect.value].title = titleInput.value;
    }
    if (resetBtnLabel) resetBtnLabel.textContent = titleInput.value || sectionSelect.value;
  });

  contentTextarea.addEventListener('input', () => {
    if (sectionsData[sectionSelect.value]) {
      sectionsData[sectionSelect.value].markdown = contentTextarea.value;
    }
  });
})();
JS;
}

