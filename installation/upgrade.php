<?php
/** Mise à niveau Community réservée à l'administrateur, distincte de l'installation.
 * Dépend des sessions/RBAC existants et du journal central ; aucun DDL sur une requête GET.
 * Les migrations 2 et 3 retirent les journaux d’import et neuf tables financières après confirmation.
 * L'action PWA distincte initialise le stockage privé sans migration SQL ni envoi.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/community-migrations.php';
require_once __DIR__ . '/community-pwa.php';
$user = MusicbandRequireLogin();
if (!$user['admin']) MusicbandFail('Accès administrateur requis.', 403);
$notice = '';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        MusicbandCsrf();
        if (($_POST['action'] ?? '') === 'pwa_setup') {
            if (($_POST['pwa_backup'] ?? '') !== 'yes') throw new RuntimeException('Confirmer la sauvegarde du dossier privé.');
            $notice = MusicbandLocked(function() use ($user, $instance): string {
                $accounts = MusicbandRead('users');
                return communityPwaSetup($instance['base_url'], (string)($accounts[$user['login']]['email'] ?? ''));
            });
        } else {
            if (($_POST['action'] ?? '') !== 'migrate' || ($_POST['backup'] ?? '') !== 'yes') throw new RuntimeException('Confirmer la sauvegarde avant de lancer les migrations.');
            $settings = MusicbandRead('database_settings');
            if (!is_string($_POST['database'] ?? null) || !hash_equals($settings['DB_NAME'] ?? '', $_POST['database'])) throw new RuntimeException('Nom exact de la base requis.');
            $db = MusicbandDatabase($settings);
            communityMigrate($db);
            communityVerifyRetiredTablesAbsent($db);
            $notice = 'Migrations terminées. Les étapes déjà journalisées ont été conservées.';
        }
    }
} catch (Throwable $e) { $notice = $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Opération interrompue. Conserver la base, le dossier privé et les journaux pour diagnostic avant reprise.'; }
$content = '<p role="status">' . MusicbandEscape($notice) . '</p><p>Sauvegarder la base et le dossier privé. Cette page met à niveau une installation Community existante ; elle ne reprend pas automatiquement une ancienne base.</p><p>La migration 2 supprime définitivement les journaux contacts_imports et concerts_imports, y compris leurs lignes. Les fiches contacts/concerts et leurs historiques restent conservés. Les imports externes sont retirés. Pour annuler cette suppression, une restauration des deux tables depuis la sauvegarde sera nécessaire. Aucun jeu de démonstration n’est ajouté sur une installation existante.</p><p>La migration 3 supprime définitivement les neuf tables financières et toutes leurs lignes : devis_factures_deliveries, devis_factures_revisions, devis_factures_sequences, devis_factures_documents, expenses_records, expenses_suppliers, accounting_revisions, accounting_entries et accounting_settings. Les contacts, concerts, setlists et tables techniques sont conservés. Vérifier la restauration de la sauvegarde avant confirmation ; une interruption peut laisser un retrait partiel à reprendre.</p><form method="post"><input type="hidden" name="csrf" value="' . MusicbandEscape($_SESSION['csrf']) . '"><input type="hidden" name="action" value="migrate"><label>Nom exact de la base <input name="database" required></label><label><input type="checkbox" name="backup" value="yes" required> Sauvegarde de la base et du dossier privé effectuée ; suppression des deux journaux et des neuf tables financières comprise</label><button>Appliquer les migrations</button></form>';
$content .= '<section><h2>Configuration PWA</h2><p>Initialise les dépendances Web Push, les clés VAPID propres au site et le lanceur privé, sans migration de base ni envoi. Toute configuration existante est conservée. Après cette étape, vérifier Configuration → Général et autoriser les notifications sur chaque appareil.</p><form method="post"><input type="hidden" name="csrf" value="' . MusicbandEscape($_SESSION['csrf']) . '"><input type="hidden" name="action" value="pwa_setup"><label><input type="checkbox" name="pwa_backup" value="yes" required> Dossier privé sauvegardé</label><button>Configurer la PWA</button></form><p>Pour un traitement sans visite du site, créer dans le panneau de l’hébergeur une tâche PHP 8.2+ horaire vers <code>' . MusicbandEscape(communityPrivateDirectory(false) . '/webpush/cron.php') . '</code>, pour une configuration créée ici. Aucun secret ne doit figurer dans la commande. Le paquet ne dispose pas des accès au planificateur de l’hébergeur ; vérifier ensuite sa dernière exécution dans Configuration → Général. Les configurations historiques conservent leur propre lanceur.</p></section>';
MusicbandScreen('Mise à niveau Community', $content);
