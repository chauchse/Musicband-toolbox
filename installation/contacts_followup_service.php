<?php
/* Musicband CONTACTS FOLLOWUP SERVICE: suivi des contacts, relances e-mail et historique des échanges.
 * Rôle :
 * - Gestion du suivi des contacts du carnet d'adresses Musicband.
 * - Envoi d'e-mails de relance avec gabarit professionnel en français via le transport e-mail configuré.
 * - Conservation des échanges Messenger historiques ; action retirée des fiches.
 * - Enregistrement de notes manuelles internes et de contacts directs avec horodatage.
 * - Maintien rigoureux et précautionneux de la date du dernier contact effectif (dernier_contact).
 * - Affichage d'un historique chronologique des échanges sous forme de fil de discussion compact.
 * - Attribution de chaque action au pseudo de l'utilisateur avec référence à l'identifiant interne.
 *
 * Dépendances internes :
 * - auth.php (sessions, sécurité, MusicbandUserDisplayName, MusicbandCsrf, MusicbandDisplayDate, MusicbandEscape, MusicbandRead)
 * - email_service.php (MusicbandSendEmail)
 * - audit_trail.php (MusicbandAuditEvent)
 *
 * Dépendances externes : MySQL / MariaDB (PDO).
 * Effets de bord : Écriture dans Musicbandai_contact_activities, mise à jour de dernier_contact dans contacts_carnet_adresse, envoi d'e-mails.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/email_service.php';
require_once __DIR__ . '/audit_trail.php';

/**
 * Maintient en cache l'état d'activation des champs de suivi des contacts.
 */
function contactsFollowupFields(?bool $set = null): bool {
    static $ready = null;
    if ($set !== null) {
        $ready = $set;
    }
    return $ready ?? false;
}

/**
 * Vérifie si la table d'activités et la colonne dernier_contact sont présentes en base.
 */
function contactsFollowupReady(PDO $db): bool {
    try {
        $qTable = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='Musicbandai_contact_activities'");
        if ((int)$qTable->fetchColumn() !== 1) {
            contactsFollowupFields(false);
            return false;
        }

        $qCol = $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='contacts_carnet_adresse' AND column_name='dernier_contact'");
        $hasColumn = (int)$qCol->fetchColumn() === 1;
        contactsFollowupFields($hasColumn);
        return $hasColumn;
    } catch (Throwable $e) {
        contactsFollowupFields(false);
        return false;
    }
}

/**
 * Applique la migration 017 pour le suivi des contacts (action réservée aux administrateurs dans Upgrade).
 */
function contactsActivitiesSchemaUpgrade(array $settings): string {
    $user = MusicbandRequireLogin();
    if (empty($user['admin'])) {
        throw new RuntimeException('Accès administrateur requis.');
    }

    $db = MusicbandDatabase($settings);
    if (contactsFollowupReady($db)) {
        return 'Schéma 017 déjà appliqué. Aucune modification de structure requise.';
    }

    if (($_POST['confirm'] ?? '') !== 'yes' || ($_POST['confirm_database'] ?? null) !== ($settings['DB_NAME'] ?? null)) {
        throw new RuntimeException('Confirmer la sauvegarde et le nom exact de la base de données.');
    }

    $sql = base64_decode('', true);
    if ($sql === false || trim($sql) === '') {
        $fallback = __DIR__ . '/../deploy/mysql/017_contact_activities.sql';
        if (is_file($fallback)) {
            $sql = (string)file_get_contents($fallback);
        } else {
            throw new RuntimeException('Schéma SQL 017 introuvable.');
        }
    }

    // Exécution pas à pas des instructions
    $statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== '');
    foreach ($statements as $statement) {
        try {
            $db->exec($statement);
        } catch (PDOException $e) {
            // Ignorer si la colonne existe déjà (Duplicate column name)
            if (!str_contains($e->getMessage(), 'Duplicate column name') && !str_contains($e->getMessage(), 'already exists')) {
                throw $e;
            }
        }
    }

    contactsFollowupReady($db);
    return 'Schéma 017 appliqué avec succès : table des activités de contact et colonne dernier_contact opérationnelles.';
}

/**
 * Formulaire Upgrade pour le schéma 017.
 */
function contactsActivitiesUpgradeForm(string $hidden): string {
    return '<section><h3>Suivi des contacts · schéma 017</h3>'
        . '<p>Ajoute la table d’historique chronologique des échanges et la colonne de dernier contact dans le carnet d’adresses. Sauvegarder la base avant modification.</p>'
        . '<form method="post">' . $hidden
        . '<input type="hidden" name="action" value="contacts_activities_schema">'
        . '<label>Nom exact de la base<input name="confirm_database" required autocomplete="off"></label>'
        . '<label class="upgrade-confirm"><input type="checkbox" name="confirm" value="yes" required> Destination et sauvegarde vérifiées.</label>'
        . '<button>Créer le schéma 017 · Suivi des contacts</button></form></section>';
}

/**
 * Détecte et valide une URL ou un identifiant Facebook / Messenger dans les données d'un contact.
 *
 * @param array $row Ligne complète du contact.
 * @return string|null URL validée et sécurisée, ou null si absente/invalide.
 */
function contactsMessengerUrl(array $row): ?string {
    $sources = [];
    if (!empty($row['source_json'])) {
        $decoded = json_decode((string)$row['source_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $key => $val) {
                if (is_string($val) && trim($val) !== '') {
                    $kLower = strtolower((string)$key);
                    if (str_contains($kLower, 'facebook') || str_contains($kLower, 'messenger') || str_contains($kLower, 'fb')) {
                        $sources[] = trim($val);
                    }
                }
            }
        }
    }

    foreach (['commentaires', 'contact', 'nom'] as $field) {
        if (!empty($row[$field]) && is_string($row[$field])) {
            $text = $row[$field];
            if (preg_match_all('~(?:https?://)?(?:www\.|m\.)?(?:facebook\.com|messenger\.com|m\.me|fb\.com)/[A-Za-z0-9._/?=&-]+~i', $text, $matches)) {
                foreach ($matches[0] as $m) $sources[] = $m;
            }
            if (preg_match_all('~(?:messenger|facebook|fb)\s*[:=]\s*@?([A-Za-z0-9.]{3,50})~i', $text, $matches)) {
                foreach ($matches[1] as $m) $sources[] = $m;
            }
        }
    }

    $allowedHosts = ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'messenger.com', 'www.messenger.com', 'm.me', 'fb.com'];

    foreach ($sources as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') continue;

        if (!preg_match('~^https?://~i', $candidate) && preg_match('~^(?:www\.|m\.)?(?:facebook\.com|messenger\.com|m\.me|fb\.com)/~i', $candidate)) {
            $candidate = 'https://' . ltrim($candidate, '/');
        }

        // Cas 1 : URL complète
        if (preg_match('~^https?://~i', $candidate)) {
            $parsed = parse_url($candidate);
            $scheme = strtolower($parsed['scheme'] ?? '');
            $host = strtolower($parsed['host'] ?? '');
            if (($scheme === 'http' || $scheme === 'https') && in_array($host, $allowedHosts, true)) {
                $path = $parsed['path'] ?? '/';
                $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
                if ($host === 'fb.com') $host = 'www.facebook.com';
                return 'https://' . $host . $path . $query;
            }
        }

        // Cas 2 : Identifiant simple Facebook ou Messenger (ex. @johndoe ou johndoe)
        $clean = ltrim($candidate, '@');
        if (preg_match('/^[A-Za-z0-9.]{3,50}$/', $clean)) {
            return 'https://m.me/' . rawurlencode($clean);
        }
    }

    return null;
}

/**
 * Met à jour la date de dernier contact effectif d'une fiche.
 * Dérive automatiquement la date de la dernière interaction réussie enregistrée.
 */
function contactsFollowupUpdateLastContact(PDO $db, int $contactId, ?string $timestamp = null): void {
    if (!contactsFollowupFields()) {
        return;
    }

    $latest = null;
    try {
        $q = $db->prepare("SELECT MAX(contacted_at) FROM Musicbandai_contact_activities WHERE contact_id=? AND status='success'");
        $q->execute([$contactId]);
        $latest = $q->fetchColumn();
    } catch (Throwable $e) {}

    $targetDate = $latest ? (string)$latest : $timestamp;

    $stmt = $db->prepare('UPDATE contacts_carnet_adresse SET dernier_contact=? WHERE id=?');
    $stmt->execute([$targetDate, $contactId]);
}

/**
 * Enregistre une entrée dans l'historique d'activité du contact.
 */
function contactsFollowupAddActivity(
    PDO $db,
    int $contactId,
    array $user,
    string $channel,
    string $action,
    string $status,
    ?string $subject,
    ?string $comment,
    ?string $contactedAt = null
): int {
    $nowUtc = gmdate('Y-m-d H:i:s');
    $actionDate = $contactedAt ? trim($contactedAt) : $nowUtc;
    if (strlen($actionDate) === 16) {
        $actionDate .= ':00';
    }

    $authorDisplayName = MusicbandUserDisplayName($user);
    $userLogin = (string)($user['login'] ?? 'unknown');

    $stmt = $db->prepare('INSERT INTO Musicbandai_contact_activities (contact_id, user_login, author_display_name, channel, action, status, subject, comment, contacted_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))');
    $stmt->execute([
        $contactId,
        $userLogin,
        $authorDisplayName,
        $channel,
        $action,
        $status,
        $subject ? trim($subject) : null,
        $comment ? trim($comment) : null,
        $actionDate,
    ]);

    $activityId = (int)$db->lastInsertId();

    if ($status === 'success') {
        contactsFollowupUpdateLastContact($db, $contactId, $actionDate);
    }

    MusicbandAuditEvent('contacts', 'followup.' . $channel, $status, [
        'contact_id' => $contactId,
        'action' => $action,
        'author' => $userLogin,
    ]);

    return $activityId;
}

/**
 * Envoie un e-mail de relance à un contact et enregistre l'issue dans son historique.
 */
function contactsFollowupSendEmail(PDO $db, int $contactId, array $user, string $recipientEmail, string $subject, string $message): array {
    $recipient = MusicbandEmail($recipientEmail);
    if ($recipient === '') {
        throw new RuntimeException('Adresse e-mail du contact invalide.');
    }

    $subject = trim($subject);
    if ($subject === '') {
        throw new RuntimeException('Objet de l’e-mail requis.');
    }

    $message = trim($message);
    if ($message === '') {
        throw new RuntimeException('Contenu du message requis.');
    }

    try {
        $res = MusicbandSendEmail([
            'to' => $recipient,
            'subject' => $subject,
            'text' => $message,
            'type' => 'contact_relance',
        ]);

        $status = ($res['status'] ?? '') === 'accepted' ? 'success' : 'uncertain';
        $activityId = contactsFollowupAddActivity($db, $contactId, $user, 'email', 'relance_email', $status, $subject, $message);

        return [
            'success' => true,
            'activity_id' => $activityId,
            'message' => 'Relance e-mail envoyée avec succès (' . ($res['transport'] === 'gmail' ? 'Google Gmail API' : 'PHP mail') . ').',
        ];
    } catch (Throwable $e) {
        contactsFollowupAddActivity($db, $contactId, $user, 'email', 'relance_email', 'failed', $subject, "Échec d'envoi : " . $e->getMessage() . "\n\nMessage initial :\n" . $message);
        throw new RuntimeException('Échec de l’envoi de l’e-mail : ' . $e->getMessage(), 0, $e);
    }
}

/**
 * Confirme l'envoi d'un message via Messenger après retour de l'utilisateur.
 */
function contactsFollowupConfirmMessenger(PDO $db, int $contactId, array $user, ?string $comment, ?string $contactedAt = null): int {
    $text = trim((string)$comment);
    if ($text === '') {
        $text = 'Échange confirmé via Facebook Messenger.';
    }

    return contactsFollowupAddActivity(
        $db,
        $contactId,
        $user,
        'messenger',
        'messenger_message',
        'success',
        null,
        $text,
        $contactedAt
    );
}

/**
 * Enregistre une note manuelle (commentaire interne ou contact direct).
 */
function contactsFollowupAddNote(PDO $db, int $contactId, array $user, string $comment, bool $isDirectContact, ?string $contactedAt = null): int {
    $text = trim($comment);
    if ($text === '') {
        throw new RuntimeException('Le texte du commentaire est requis.');
    }

    $channel = 'note';
    $action = $isDirectContact ? 'contact_direct' : 'note_interne';
    $status = $isDirectContact ? 'success' : 'note';

    return contactsFollowupAddActivity(
        $db,
        $contactId,
        $user,
        $channel,
        $action,
        $status,
        null,
        $text,
        $contactedAt
    );
}

/**
 * Récupère l'ensemble des activités enregistrées pour un contact.
 */
function contactsFollowupActivities(PDO $db, int $contactId): array {
    if (!contactsFollowupReady($db)) {
        return [];
    }

    $stmt = $db->prepare('SELECT * FROM Musicbandai_contact_activities WHERE contact_id=? ORDER BY contacted_at DESC, id DESC LIMIT 100');
    $stmt->execute([$contactId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Rendu visuel HTML du fil chronologique de suivi (style fil de discussion / messagerie compact).
 */
function contactsFollowupRenderTimeline(array $activities): string {
    if (!$activities) {
        return '<p class="contact-timeline-empty">Aucun échange ni commentaire enregistré pour ce contact.</p>';
    }

    $channelLabels = [
        'email' => 'E-mail',
        'messenger' => 'Messenger',
        'note' => 'Note interne',
        'call' => 'Téléphone',
        'other' => 'Autre',
    ];

    $html = '<div class="contact-timeline" role="feed" aria-label="Historique des échanges">';
    foreach ($activities as $act) {
        $channel = (string)($act['channel'] ?? 'note');
        $status = (string)($act['status'] ?? 'note');
        $author = (string)($act['author_display_name'] ?? ($act['user_login'] ?? 'Utilisateur'));
        $dateStr = MusicbandDisplayDate((string)$act['contacted_at'], true);
        $subject = trim((string)($act['subject'] ?? ''));
        $comment = trim((string)($act['comment'] ?? ''));

        $badgeClass = 'contact-badge-' . htmlspecialchars($channel, ENT_QUOTES, 'UTF-8');
        $statusClass = 'contact-status-' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8');

        $channelName = $channelLabels[$channel] ?? ucfirst($channel);
        if ($channel === 'note' && $status === 'success') {
            $channelName = 'Contact direct';
        }

        $statusText = '';
        if ($status === 'failed') {
            $statusText = ' · Échec';
        } elseif ($status === 'uncertain') {
            $statusText = ' · Envoi incertain';
        }

        $html .= '<article class="contact-timeline-item ' . $badgeClass . ' ' . $statusClass . '">';
        $html .= '<header class="contact-timeline-header">';
        $html .= '<span class="contact-author"><strong>' . MusicbandEscape($author) . '</strong></span>';
        $html .= '<span class="contact-channel-badge">' . MusicbandEscape($channelName . $statusText) . '</span>';
        $html .= '<time class="contact-time" datetime="' . MusicbandEscape((string)$act['contacted_at']) . '">' . MusicbandEscape($dateStr) . '</time>';
        $html .= '</header>';

        if ($subject !== '') {
            $html .= '<div class="contact-subject"><strong>Objet : ' . MusicbandEscape($subject) . '</strong></div>';
        }

        if ($comment !== '') {
            $html .= '<div class="contact-body">' . nl2br(MusicbandEscape($comment)) . '</div>';
        }

        $html .= '</article>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Rendu du bloc d'actions rapides (Relancer par e-mail, Commentaire) et des formulaires modaux.
 */
function contactsFollowupRenderActions(array $row, PDO $db, array $user): string {
    if (!MusicbandCan('contacts.edit', $user)) {
        return '';
    }

    $contactId = (int)$row['id'];
    $contactName = trim((string)($row['nom'] ?? ''));
    $contactPerson = trim((string)($row['contact'] ?? ''));
    $contactMail = trim((string)($row['mail'] ?? ''));
    $csrf = MusicbandEscape($_SESSION['csrf'] ?? '');
    $route = MusicbandEscape(MusicbandBase() . 'contacts.php?id=' . $contactId);

    $displayName = $contactPerson !== '' ? $contactPerson : $contactName;
    $salutation = 'Bonjour' . ($displayName !== '' ? ' ' . $displayName : '') . ",\n\n";
    $defaultSubject = 'Musicband — Prise de contact' . ($contactName !== '' ? ' (' . $contactName . ')' : '');
    $defaultBody = $salutation . "Je me permets de revenir vers vous concernant notre échange au sujet d’une éventuelle prestation musicale du groupe Musicband.\n\nN’hésitez pas à nous contacter si vous souhaitez davantage d’informations ou échanger sur votre projet.\n\nAu plaisir d’avoir de vos nouvelles !\n\nBien cordialement,\nL’équipe Musicband";

    $nowLocal = (new DateTimeImmutable('now', MusicbandDateZone()))->format('Y-m-d\TH:i');

    $html = '<section class="contact-followup-panel">';
    $html .= '<h3>Actions de suivi</h3>';
    $html .= '<div class="contact-followup-actions">';

    // 1. Bouton Relancer par e-mail (toujours actif, champ modifiable si coordonnées absentes)
    $emailBtnTitle = $contactMail !== '' ? 'Envoyer une relance par e-mail à ' . $contactMail : 'Saisir une adresse e-mail et envoyer une relance';
    $html .= '<button type="button" class="button-action contact-action-email" id="btn-contact-email" title="' . MusicbandEscape($emailBtnTitle) . '">';
    $html .= '✉ Relancer par e-mail</button>';

    // Bouton Ajouter un commentaire / note
    $html .= '<button type="button" class="button-action contact-action-note" id="btn-contact-note" title="Ajouter une note ou consigner un contact direct">';
    $html .= '📝 Ajouter un commentaire</button>';
    $html .= '</div>';

    // Boîte de dialogue : Relance e-mail
    $html .= '<dialog id="dialog-contact-email" class="contact-modal" aria-labelledby="dialog-email-title">';
    $html .= '<form method="post" action="' . $route . '">';
    $html .= '<input type="hidden" name="csrf" value="' . $csrf . '">';
    $html .= '<input type="hidden" name="action" value="contact_email_send">';
    $html .= '<input type="hidden" name="id" value="' . $contactId . '">';
    $html .= '<h4 id="dialog-email-title">Relancer par e-mail</h4>';
    $html .= '<p class="contact-modal-desc">Un e-mail sera expédié avec le compte configuré dans Musicband. En cas de succès, la date du dernier contact sera mise à jour.</p>';
    $html .= '<label>Destinataire<input type="email" name="email_recipient" value="' . MusicbandEscape($contactMail) . '" placeholder="adresse@exemple.fr" required></label>';
    $html .= '<label>Objet<input type="text" name="email_subject" value="' . MusicbandEscape($defaultSubject) . '" required maxlength="255"></label>';
    $html .= '<label>Message<textarea name="email_message" rows="8" required>' . MusicbandEscape($defaultBody) . '</textarea></label>';
    $html .= '<div class="contact-modal-buttons">';
    $html .= '<button type="submit" class="button-primary">Confirmer et envoyer l’e-mail</button>';
    $html .= '<button type="button" class="button-secondary" id="btn-cancel-email">Annuler</button>';
    $html .= '</div></form></dialog>';

    // Boîte de dialogue : Note manuelle
    $html .= '<dialog id="dialog-contact-note" class="contact-modal" aria-labelledby="dialog-note-title">';
    $html .= '<form method="post" action="' . $route . '">';
    $html .= '<input type="hidden" name="csrf" value="' . $csrf . '">';
    $html .= '<input type="hidden" name="action" value="contact_note_add">';
    $html .= '<input type="hidden" name="id" value="' . $contactId . '">';
    $html .= '<h4 id="dialog-note-title">Ajouter un commentaire</h4>';
    $html .= '<label>Commentaire ou compte-rendu<textarea name="note_comment" rows="5" required placeholder="Saisissez vos observations ou le détail d’un échange..."></textarea></label>';
    $html .= '<label>Date et heure<input type="datetime-local" name="contacted_at" value="' . MusicbandEscape($nowLocal) . '"></label>';
    $html .= '<label class="Musicband-check-label"><input type="checkbox" class="Musicband-checkbox" name="is_direct_contact" value="1"> Compter comme un contact direct avec le client (actualise la date du dernier contact)</label>';
    $html .= '<div class="contact-modal-buttons">';
    $html .= '<button type="submit" class="button-primary">Enregistrer la note</button>';
    $html .= '<button type="button" class="button-secondary" id="btn-cancel-note">Annuler</button>';
    $html .= '</div></form></dialog>';

    $html .= '</section>';

    return $html;
}

/**
 * Script noncé gérant les boîtes de dialogue e-mail et notes sans violation CSP.
 */
function contactsFollowupScript(): string {
    return <<<'JS'
(function() {
    function initFollowup() {
        var emailBtn = document.getElementById('btn-contact-email');
        var emailDialog = document.getElementById('dialog-contact-email');
        var emailCancel = document.getElementById('btn-cancel-email');
        if (emailBtn && emailDialog) {
            emailBtn.addEventListener('click', function() { emailDialog.showModal(); });
        }
        if (emailCancel && emailDialog) {
            emailCancel.addEventListener('click', function() { emailDialog.close(); });
        }

        var noteBtn = document.getElementById('btn-contact-note');
        var noteDialog = document.getElementById('dialog-contact-note');
        var noteCancel = document.getElementById('btn-cancel-note');
        if (noteBtn && noteDialog) {
            noteBtn.addEventListener('click', function() { noteDialog.showModal(); });
        }
        if (noteCancel && noteDialog) {
            noteCancel.addEventListener('click', function() { noteDialog.close(); });
        }

        [emailDialog, noteDialog].forEach(function(d) {
            if (d) {
                d.addEventListener('click', function(e) {
                    if (e.target === d) d.close();
                });
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFollowup);
    } else {
        initFollowup();
    }
})();
JS;
}

