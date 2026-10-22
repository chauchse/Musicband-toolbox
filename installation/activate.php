<?php
/* Musicband ACTIVATE: activation et préférences du compte connecté.
 * Dépend des services de compte/e-mail, tableau de bord, notifications et préférences Excel Setlist.
 * Traite les formulaires protégés par CSRF et enregistre les choix en stockage privé.
 */
require_once __DIR__ . '/account_mail.php';
require_once __DIR__ . '/dashboard_service.php';
require_once __DIR__.'/setlist_excel_service.php';
require_once __DIR__ . '/advanced_notifications_service.php';
// Parcours connecté distinct des liens reçus par e-mail ; aucun compte choisi en POST.
if (($_GET['change'] ?? '') === '1') {
    $user = MusicbandRequireLogin(); $message = ''; $prefsNotice = ''; $profileNotice = ''; $profileError = '';
    $notifNotice = ''; $notifError = '';
    // La configuration Excel complète porte davantage de positions ; les autres formulaires
    // conservent leur limite. Les colonnes sont ensuite validées par le référentiel serveur.
    $preferencesLimit=($_POST['action']??'')==='save_excel_columns'?65536:16384;
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $preferencesLimit) MusicbandFail('Formulaire trop volumineux.', 413);

    // Endpoint asynchrone pour la persistance de l'état ouvert/fermé du tableau de bord
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_dashboard_state')) {
        try {
            MusicbandCsrf();
            $expanded = ($_POST['expanded'] ?? '') === '1';
            dashboardSaveUserPreferences($user['login'], ['dashboard_expanded' => $expanded]);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            exit;
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Échec de synchronisation']);
            exit;
        }
    }

    // Sauvegarde indépendante du profil, des widgets et du mot de passe.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_appearance') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            MusicbandCsrf();
            $scale = filter_var($_POST['appearance_scale'] ?? null, FILTER_VALIDATE_INT);
            dashboardSaveUserPreferences($user['login'], [
                'appearance_theme' => $_POST['appearance_theme'] ?? null,
                'appearance_scale' => $scale,
            ]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Apparence non enregistrée. Réessayez ou reconnectez-vous.']);
        }
        exit;
    }

    $excelNotice='';
    if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='save_excel_columns'){
        try{
            MusicbandCsrf();
            $columns=($_POST['excel_reset']??'')==='1'?setlistExcelDefaults():setlistExcelPostedColumns($_POST);
            setlistExcelSavePreferences($user['login'],$columns);
            $excelNotice='Préférences Excel enregistrées.';
            if(($_POST['excel_async']??'')==='1'){
                header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true]);exit;
            }
        }catch(Throwable $e){
            $excelNotice='Préférences Excel non enregistrées : vérifier les colonnes et la session.';
            if(($_POST['excel_async']??'')==='1'){
                http_response_code(400);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$excelNotice]);exit;
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_profile') {
            try {
                MusicbandCsrf();
                $pseudo = trim((string)($_POST['pseudo'] ?? ''));
                if (strlen($pseudo) > 40 || preg_match('/[\x00-\x1f\x7f<>{}]/', $pseudo)) {
                    throw new RuntimeException('Pseudo invalide (40 caractères max, sans balises ni caractères de contrôle).');
                }
                MusicbandLocked(function() use ($user, $pseudo) {
                    $users = MusicbandRead('users');
                    if (!isset($users[$user['login']])) throw new RuntimeException('Compte introuvable.');
                    $users[$user['login']]['pseudo'] = $pseudo;
                    $users[$user['login']]['revision'] = bin2hex(random_bytes(16));
                    MusicbandWrite('users', $users);
                    $_SESSION['revision'] = $users[$user['login']]['revision'];
                });
                $profileNotice = 'Votre profil a été mis à jour.';
            } catch (Throwable $e) {
                $profileError = $e->getMessage();
            }
        } elseif ($action === 'save_preferences') {
            try {
                MusicbandCsrf();
                $newPrefs = [
                    'dashboard_visible'  => isset($_POST['dashboard_visible']),
                    'dashboard_expanded' => isset($_POST['dashboard_expanded']),
                    'widget_songs'       => isset($_POST['widget_songs']),
                    'widget_rehearsal'   => isset($_POST['widget_rehearsal']),
                    'widget_concert'     => isset($_POST['widget_concert']),
                    'widget_tasks'       => isset($_POST['widget_tasks']),
                    'widget_video'       => isset($_POST['widget_video']),
                    'songs_filter'       => (string)($_POST['songs_filter'] ?? ''),
                ];
                dashboardSaveUserPreferences($user['login'], $newPrefs);
                $prefsNotice = 'Vos préférences d’affichage ont été enregistrées.';
            } catch (Throwable $e) {
                $prefsNotice = 'Impossible d’enregistrer vos préférences : ' . $e->getMessage();
            }
        } elseif ($action === 'toggle_global_notifications') {
            try {
                MusicbandCsrf();
                $enabled = !empty($_POST['global_enabled']);
                advancedNotificationsSetUserGloballyEnabled($user['login'], $enabled);
                $notifNotice = 'Vos notifications personnalisées ont été ' . ($enabled ? 'activées.' : 'suspendues.');
            } catch (Throwable $e) {
                $notifError = $e->getMessage();
            }
        } elseif ($action === 'toggle_login_notifications') {
            try {
                MusicbandCsrf();
                $enabled = !empty($_POST['notify_login']);
                advancedNotificationsSetAdminLoginNotifications($user['login'], $enabled);
                $notifNotice = 'Les notifications de connexion ont été ' . ($enabled ? 'activées' : 'désactivées') . ' pour votre compte administrateur.';
            } catch (Throwable $e) {
                $notifError = $e->getMessage();
            }
        } elseif ($action === 'toggle_rule') {
            try {
                MusicbandCsrf();
                $ruleId = (string)($_POST['rule_id'] ?? '');
                $enabled = ($_POST['enabled'] ?? '') === '1';
                advancedNotificationsToggleUserRule($user['login'], $ruleId, $enabled);
                $notifNotice = 'Règle ' . ($enabled ? 'activée.' : 'désactivée.');
            } catch (Throwable $e) {
                $notifError = $e->getMessage();
            }
        } elseif ($action === 'reset_default_rules') {
            try {
                MusicbandCsrf();
                advancedNotificationsResetUserDefaultRules($user['login']);
                $notifNotice = 'Les règles standard du groupe ont été réinitialisées d’après les recommandations actuelles.';
            } catch (Throwable $e) {
                $notifError = $e->getMessage();
            }
        } elseif ($action === 'save_custom_rule') {
            try {
                MusicbandCsrf();
                $ruleName = trim((string)($_POST['name'] ?? ''));
                $eventKey = (string)($_POST['event_key'] ?? '');
                $type = (string)($_POST['type'] ?? 'event');
                $offset = (int)($_POST['reminder_offset'] ?? 7200);
                $targetAssignee = !empty($_POST['target_assignee_only']);
                $condField = trim((string)($_POST['cond_field'] ?? ''));
                $condOp = (string)($_POST['cond_op'] ?? 'equals');
                $condVal = $_POST['cond_value'] ?? null;
                $condPrevVal = $_POST['cond_prev_value'] ?? null;

                $conditions = ['condition' => 'AND', 'rules' => []];
                if ($condField !== '') {
                    $conditions['rules'][] = [
                        'field' => $condField,
                        'op' => $condOp,
                        'value' => $condVal !== '' ? $condVal : null,
                        'previous_value' => $condPrevVal !== '' ? $condPrevVal : null
                    ];
                }

                $ruleData = [
                    'id' => (string)($_POST['rule_id'] ?? ''),
                    'name' => $ruleName,
                    'event_key' => $eventKey,
                    'type' => $type,
                    'reminder_offset' => $type === 'reminder' ? $offset : null,
                    'target_assignee_only' => $targetAssignee,
                    'enabled' => false,
                    'conditions' => $conditions
                ];
                advancedNotificationsSaveCustomRule($user['login'], $ruleData);
                $notifNotice = 'Votre règle personnalisée a été enregistrée (désactivée par défaut). Activez-la d’un clic après vérification.';
            } catch (Throwable $e) {
                $notifError = $e->getMessage();
            }
        } elseif ($action === 'duplicate_rule') {
            try {
                MusicbandCsrf();
                $sourceRuleId = (string)($_POST['rule_id'] ?? '');
                advancedNotificationsDuplicateRule($user['login'], $sourceRuleId);
                $notifNotice = 'Règle dupliquée dans vos règles personnalisées (désactivée par défaut).';
            } catch (Throwable $e) {
                $notifError = $e->getMessage();
            }
        } elseif ($action === 'delete_custom_rule') {
            try {
                MusicbandCsrf();
                $ruleId = (string)($_POST['rule_id'] ?? '');
                advancedNotificationsDeleteCustomRule($user['login'], $ruleId);
                $notifNotice = 'Règle personnalisée supprimée.';
            } catch (Throwable $e) {
                $notifError = $e->getMessage();
            }
        } elseif ($action === 'test_notification') {
            try {
                MusicbandCsrf();
                $res = advancedNotificationSendTestNotification($user['login']);
                if (!empty($res['success'])) {
                    $notifNotice = $res['message'];
                } else {
                    $notifError = $res['message'] ?? 'Échec de transmission de la notification de test.';
                }
            } catch (Throwable $e) {
                $notifError = $e->getMessage();
            }
        } elseif ($action === 'change_password') {
            try {
                MusicbandCsrf();
                MusicbandLocked(function() use ($user) {
                    $current = MusicbandRequireLogin();
                    if ($current['login'] !== $user['login']) throw new RuntimeException('Session modifiée : reconnectez-vous.');
                    // Réutilise les quotas privés existants, avec une clé indépendante des e-mails.
                    if (!MusicbandMailQuota('password-change-' . hash('sha256', $current['login']), Musicband_LOGIN_ATTEMPTS)) throw new RuntimeException('Trop de tentatives : réessayer dans quinze minutes.');
                    $old = $_POST['current_password'] ?? null;
                    $password = $_POST['password'] ?? null;
                    $confirmation = $_POST['confirmation'] ?? null;
                    if (!is_string($old) || !is_string($password) || !is_string($confirmation)) throw new RuntimeException('Champs de mot de passe invalides.');
                    $users = MusicbandRead('users'); $login = $current['login'];
                    if (!isset($users[$login]) || !password_verify($old, $users[$login]['hash'])) throw new RuntimeException('Le mot de passe actuel est incorrect.');
                    MusicbandValidateCredentials($login, $password);
                    if (str_contains($password, "\0")) throw new RuntimeException('Le mot de passe contient un caractère invalide.');
                    if (!hash_equals($password, $confirmation)) throw new RuntimeException('Les nouveaux mots de passe ne correspondent pas.');
                    $users[$login]['hash'] = password_hash($password, PASSWORD_BCRYPT);
                    $users[$login]['revision'] = bin2hex(random_bytes(16));
                    // Préserve rôle, adresse et autres comptes ; révoque sessions et liens de récupération.
                    MusicbandWrite('users', $users);
                });
                MusicbandAuditEvent('auth','password.changed','success');MusicbandLogout();
                header('Location: ' . MusicbandBase() . 'login.php?password=updated', true, 303); exit;
            } catch (RuntimeException $e) { $message = $e->getMessage(); }
            catch (Throwable $e) { $message = 'Changement indisponible : vérifier votre connexion ou réessayer plus tard.'; }
        }
    }

    $allUsers = MusicbandRead('users');
    $userData = $allUsers[$user['login']] ?? [];
    $currentPseudo = (string)($userData['pseudo'] ?? '');

    $prefs = dashboardUserPreferences($user['login']);
    $songFilters = dashboardSongFiltersList();
    $activeGlobal = dashboardResolveSongsFilter(null);
    $defaultFilterLabel = !empty($activeGlobal['name']) ? $activeGlobal['name'] : 'Automatique / Repli fiche';
    $currentSongFilter = (string)($prefs['songs_filter'] ?? '');
    $songFilterSelectHtml = '<option value=""' . ($currentSongFilter === '' ? ' selected' : '') . '>Par défaut de la configuration (' . MusicbandEscape($defaultFilterLabel) . ')</option>';
    foreach ($songFilters as $fid => $f) {
        $fname = (string)($f['name'] ?? $fid);
        $songFilterSelectHtml .= '<option value="' . MusicbandEscape($fid) . '"' . ($currentSongFilter === $fid ? ' selected' : '') . '>' . MusicbandEscape($fname) . '</option>';
    }
    $content = '<p><a href="' . MusicbandEscape(MusicbandBase()) . 'index.php">← Accueil</a></p>'
        . '<details class="preferences-section field-module" id="preferences-general" open><summary>Préférences générales</summary><div>'
        . '<h3>Apparence</h3><form id="appearance-preferences" method="post" action="'.MusicbandEscape(MusicbandBase()).'activate.php?change=1">'
        . '<input type="hidden" name="csrf" value="'.MusicbandEscape($_SESSION['csrf']).'"><input type="hidden" name="action" value="save_appearance">'
        . '<label for="appearance-theme">Thème d’affichage</label><select id="appearance-theme" name="appearance_theme"><option value="auto">Automatique</option><option value="light">Clair</option><option value="dark">Sombre</option></select>'
        . '<p id="appearance-size-label">Taille du texte</p><div class="layout-tools" role="group" aria-labelledby="appearance-size-label"><button type="button" data-size="-10" aria-label="Diminuer la taille du texte">A−</button><button type="button" data-size="0" aria-label="Taille du texte par défaut">A</button><button type="button" data-size="10" aria-label="Augmenter la taille du texte">A+</button><output id="appearance-size"></output></div>'
        . '<input type="hidden" name="appearance_scale" value="100"><button type="button" id="appearance-reset">Réinitialiser l’apparence</button><p id="appearance-status" role="status" aria-live="polite"></p><noscript>JavaScript est nécessaire pour modifier l’apparence immédiatement.</noscript></form>'
        . '<h3>Profil et identité</h3>'
        . ($profileNotice !== '' ? '<p class="notice" role="status">' . MusicbandEscape($profileNotice) . '</p>' : '')
        . ($profileError !== '' ? '<p class="notice" role="alert">' . MusicbandEscape($profileError) . '</p>' : '')
        . '<p>Identifiant : <strong>' . MusicbandEscape($user['login']) . '</strong></p>'
        . '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'activate.php?change=1">'
        . '<input type="hidden" name="csrf" value="' . MusicbandEscape($_SESSION['csrf']) . '">'
        . '<input type="hidden" name="action" value="save_profile">'
        . '<label for="pseudo">Pseudo (nom affiché dans l’application)</label>'
        . '<input id="pseudo" name="pseudo" type="text" maxlength="40" value="' . MusicbandEscape($currentPseudo) . '" placeholder="ex. ' . MusicbandEscape($user['login']) . '">'
        . '<p>Le pseudo est utilisé pour signer vos actions et commentaires sans modifier votre identifiant de connexion.</p>'
        . '<button class="button-primary">Enregistrer mon profil</button>'
        . '</form>'
        . '</div>'
        . '<div>'
        . '<h3>Affichage de l’accueil</h3>'
        . ($prefsNotice !== '' ? '<p class="notice" role="status">' . MusicbandEscape($prefsNotice) . '</p>' : '')
        . '<p>Personnalisez la section des widgets sur l’accueil et leur visibilité individuelle.</p>'
        . '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'activate.php?change=1">'
        . '<input type="hidden" name="csrf" value="' . MusicbandEscape($_SESSION['csrf']) . '">'
        . '<input type="hidden" name="action" value="save_preferences">'
        . '<label class="Musicband-check-label"><input type="checkbox" class="Musicband-checkbox" name="dashboard_visible" value="1"' . (!empty($prefs['dashboard_visible']) ? ' checked' : '') . '> Afficher la section widgets</label>'
        . '<label class="Musicband-check-label"><input type="checkbox" class="Musicband-checkbox" name="dashboard_expanded" value="1"' . (!empty($prefs['dashboard_expanded']) ? ' checked' : '') . '> Déplier les widgets par défaut</label>'
        . '<h3>Visibilité des widgets</h3>'
        . '<label class="Musicband-check-label"><input type="checkbox" class="Musicband-checkbox" name="widget_songs" value="1"' . (!empty($prefs['widget_songs']) ? ' checked' : '') . '> Morceaux à travailler</label>'
        . '<div style="margin:4px 0 12px 24px;">'
        . '<label for="pref-songs-filter" style="display:block;font-size:0.9rem;margin-bottom:4px;">Filtre du catalogue pour ce widget</label>'
        . '<select id="pref-songs-filter" name="songs_filter">' . $songFilterSelectHtml . '</select>'
        . '</div>'
        . '<label class="Musicband-check-label"><input type="checkbox" class="Musicband-checkbox" name="widget_rehearsal" value="1"' . (!empty($prefs['widget_rehearsal']) ? ' checked' : '') . '> Prochaine répétition</label>'
        . '<label class="Musicband-check-label"><input type="checkbox" class="Musicband-checkbox" name="widget_concert" value="1"' . (!empty($prefs['widget_concert']) ? ' checked' : '') . '> Prochain concert</label>'
        . '<label class="Musicband-check-label"><input type="checkbox" class="Musicband-checkbox" name="widget_tasks" value="1"' . (!empty($prefs['widget_tasks']) ? ' checked' : '') . '> Tâches à faire</label>'
        . '<label class="Musicband-check-label"><input type="checkbox" class="Musicband-checkbox" name="widget_video" value="1"' . (!empty($prefs['widget_video']) ? ' checked' : '') . '> Vidéo d’introduction</label>'
        . '<button class="button-primary">Enregistrer mes préférences</button>'
        . '</form>'
        . '</div></details>';

    $content.=MusicbandPwaPreferencesSection();
    $content.='<details class="preferences-section field-module" id="preferences-notifications"'.($notifNotice!==''||$notifError!==''?' open':'').'><summary>Notifications et rappels</summary>'.MusicbandPushPreferencesSection();
    if ($notifNotice !== '') {
        $content .= '<p class="notice" role="status">' . MusicbandEscape($notifNotice) . '</p>';
    }
    if ($notifError !== '') {
        $content .= '<p class="notice" role="alert">' . MusicbandEscape($notifError) . '</p>';
    }
    $content .= advancedNotificationsUserPreferencesSection($user['login'], $_SESSION['csrf']).'</details>';

    $content.=setlistExcelPreferencesForm($user['login'],$excelNotice);
    $content .= '<details class="preferences-section field-module" id="preferences-security"'.($message!==''?' open':'').'><summary>Sécurité du compte</summary>'
        . ($message !== '' ? '<p class="notice" role="alert">' . MusicbandEscape($message) . '</p>' : '')
        . '<p>Compte : ' . MusicbandEscape($user['login']) . '. Choisissez un nouveau mot de passe entre 12 et 72 octets. Après enregistrement, toutes vos sessions seront déconnectées et vos anciens liens de récupération seront invalides.</p>'
        . '<form method="post" action="' . MusicbandEscape(MusicbandBase()) . 'activate.php?change=1">'
        . '<input type="hidden" name="csrf" value="' . MusicbandEscape($_SESSION['csrf']) . '">'
        . '<input type="hidden" name="action" value="change_password">'
        . '<label for="current-password">Mot de passe actuel</label><input id="current-password" name="current_password" type="password" required autocomplete="current-password">'
        . '<label for="password">Nouveau mot de passe</label><input id="password" name="password" type="password" required autocomplete="new-password">'
        . '<label for="confirmation">Confirmer le nouveau mot de passe</label><input id="confirmation" name="confirmation" type="password" required autocomplete="new-password">'
        . '<button class="button-primary">Enregistrer mon nouveau mot de passe</button></form></details>';

    // Attachement non-intrusif d'un gestionnaire de soumission automatique pour la case d'activation globale des notifications
    $script = "document.addEventListener('DOMContentLoaded',function(){"
        . "var cb=document.querySelector('form input[name=\"global_enabled\"]');"
        . "if(cb&&cb.form){cb.addEventListener('change',function(){cb.form.submit();});}"
        . "});";
    $excelScript=base64_decode('LyogUHLDqWbDqXJlbmNlcyBFeGNlbCA6IHBlcnNpc3RhbmNlIGF1dG9tYXRpcXVlIGFwcsOocyBTb3J0YWJsZUpTIC8gY29tbWFuZGVzIGNsYXZpZXIuCiAqIFLDqXV0aWxpc2UgbGUgZm9ybXVsYWlyZSBldCBsZXMgcG9zaXRpb25zIGRlIGZpZWxkX2xheW91dF9lZGl0b3IuanMgOyBQT1NUIENTUkYgc8OpcmlhbGlzw6lzLgogKiBBdWN1bmUgcHLDqWbDqXJlbmNlIGRhbnMgbGUgc3RvY2thZ2UgZHUgbmF2aWdhdGV1ciA7IEVucmVnaXN0cmVyL3LDqWluaXRpYWxpc2VyIHV0aWxpc2VudCBsZSBQT1NUIG5hdGlmLgogKi8KKCgpID0+IHsKICBjb25zdCBmb3JtID0gZG9jdW1lbnQucXVlcnlTZWxlY3RvcignW2RhdGEtZXhjZWwtcHJlZmVyZW5jZXNdJyk7CiAgaWYgKCFmb3JtKSByZXR1cm47CiAgY29uc3Qgc3RhdHVzID0gZm9ybS5xdWVyeVNlbGVjdG9yKCdbZGF0YS1uYXZpZ2F0aW9uLXN0YXR1c10nKTsKICBsZXQgdGltZXIsIG5hdGl2ZVN1Ym1pc3Npb24gPSBmYWxzZSwgcXVldWUgPSBQcm9taXNlLnJlc29sdmUoKTsKICBjb25zdCBzYXZlID0gKCkgPT4gewogICAgY2xlYXJUaW1lb3V0KHRpbWVyKTsKICAgIGNvbnN0IGRhdGEgPSBuZXcgRm9ybURhdGEoZm9ybSk7CiAgICBkYXRhLnNldCgnZXhjZWxfYXN5bmMnLCAnMScpOwogICAgLy8gQ2hhcXVlIMOpdGF0IGVzdCBlbnZvecOpIGRhbnMgbOKAmW9yZHJlLCBhZmluIHF14oCZdW5lIHLDqXBvbnNlIGxlbnRlIG5lIHJlc3RhdXJlIHBhcyBs4oCZYW5jaWVuLgogICAgcXVldWUgPSBxdWV1ZS5jYXRjaCgoKSA9PiB7fSkudGhlbihhc3luYyAoKSA9PiB7CiAgICAgIGlmIChuYXRpdmVTdWJtaXNzaW9uKSByZXR1cm47CiAgICAgIHN0YXR1cy50ZXh0Q29udGVudCA9ICdFbnJlZ2lzdHJlbWVudOKApic7CiAgICAgIC8vIExlIGNoYW1wIGNhY2jDqSBuYW1lPWFjdGlvbiBtYXNxdWUgZm9ybS5hY3Rpb24gOiBsaXJlIGzigJlhdHRyaWJ1dCwgamFtYWlzIGxhIHByb3ByacOpdMOpLgogICAgICBjb25zdCB0YXJnZXQgPSBmb3JtLmdldEF0dHJpYnV0ZSgnYWN0aW9uJykgfHwgd2luZG93LmxvY2F0aW9uLmhyZWY7CiAgICAgIGNvbnN0IHJlc3BvbnNlID0gYXdhaXQgZmV0Y2godGFyZ2V0LCB7bWV0aG9kOiAnUE9TVCcsIGNyZWRlbnRpYWxzOiAnc2FtZS1vcmlnaW4nLCBib2R5OiBuZXcgVVJMU2VhcmNoUGFyYW1zKGRhdGEpfSk7CiAgICAgIGlmICghcmVzcG9uc2UuaGVhZGVycy5nZXQoJ0NvbnRlbnQtVHlwZScpPy5pbmNsdWRlcygnYXBwbGljYXRpb24vanNvbicpKSB0aHJvdyBuZXcgRXJyb3IoYEVucmVnaXN0cmVtZW50IHJlZnVzw6kgKEhUVFAgJHtyZXNwb25zZS5zdGF0dXN9KSA6IHJlY2hhcmdleiBsYSBwYWdlIGV0IHbDqXJpZmlleiBsYSBzZXNzaW9uLmApOwogICAgICBjb25zdCByZXN1bHQgPSBhd2FpdCByZXNwb25zZS5qc29uKCk7CiAgICAgIGlmICghcmVzcG9uc2Uub2sgfHwgIXJlc3VsdC5vaykgdGhyb3cgbmV3IEVycm9yKHJlc3VsdC5lcnJvciB8fCAnRW5yZWdpc3RyZW1lbnQgaW1wb3NzaWJsZS4nKTsKICAgICAgc3RhdHVzLnRleHRDb250ZW50ID0gJ1Byw6lmw6lyZW5jZXMgRXhjZWwgZW5yZWdpc3Ryw6llcy4nOwogICAgfSkuY2F0Y2goZXJyb3IgPT4gewogICAgICBjb25zdCByZWFzb24gPSBlcnJvciBpbnN0YW5jZW9mIFR5cGVFcnJvciA/ICdyZXF1w6p0ZSBibG9xdcOpZSBvdSBjb25uZXhpb24gaW50ZXJyb21wdWUuJyA6IChlcnJvci5tZXNzYWdlIHx8ICdyw6lwb25zZSBkdSBzZXJ2ZXVyIGluZGlzcG9uaWJsZS4nKTsKICAgICAgc3RhdHVzLnRleHRDb250ZW50ID0gJ0VucmVnaXN0cmVtZW50IGF1dG9tYXRpcXVlIG5vbiBjb25maXJtw6kgOiAnICsgcmVhc29uICsgJyBVdGlsaXNleiBFbnJlZ2lzdHJlciBwb3VyIGVudm95ZXIgbGUgZm9ybXVsYWlyZSBkaXJlY3RlbWVudC4nOwogICAgfSk7CiAgfTsKICBjb25zdCBzY2hlZHVsZSA9ICgpID0+IHsgaWYgKG5hdGl2ZVN1Ym1pc3Npb24pIHJldHVybjsgY2xlYXJUaW1lb3V0KHRpbWVyKTsgdGltZXIgPSBzZXRUaW1lb3V0KCgpID0+IHNhdmUoKSwgNDAwKTsgfTsKICBmb3JtLmFkZEV2ZW50TGlzdGVuZXIoJ2NoYW5nZScsIHNjaGVkdWxlKTsKICBuZXcgTXV0YXRpb25PYnNlcnZlcihzY2hlZHVsZSkub2JzZXJ2ZShmb3JtLnF1ZXJ5U2VsZWN0b3IoJ1tkYXRhLW5hdmlnYXRpb24taXRlbXNdJyksIHtjaGlsZExpc3Q6IHRydWV9KTsKICAvLyBVbmUgcGFubmUgZGUgZmV0Y2ggbmUgZG9pdCBwYXMgYmxvcXVlciBhdXNzaSBsZSBib3V0b24gZGUgc2Vjb3Vycy4KICAvLyBBdHRlbmRyZSBs4oCZw6ljcml0dXJlIGVuIGNvdXJzIGV0IGlnbm9yZXIgbGVzIHN1aXZhbnRlcyBwb3VyIHF1ZSBsZSBkZXJuaWVyIMOpdGF0IGdhZ25lLgogIGZvcm0uYWRkRXZlbnRMaXN0ZW5lcignc3VibWl0JywgZXZlbnQgPT4gewogICAgZXZlbnQucHJldmVudERlZmF1bHQoKTsKICAgIGlmIChuYXRpdmVTdWJtaXNzaW9uKSByZXR1cm47CiAgICBuYXRpdmVTdWJtaXNzaW9uID0gdHJ1ZTsKICAgIGNsZWFyVGltZW91dCh0aW1lcik7CiAgICBpZiAoZXZlbnQuc3VibWl0dGVyPy5uYW1lID09PSAnZXhjZWxfcmVzZXQnKSB7CiAgICAgIGNvbnN0IHJlc2V0ID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgnaW5wdXQnKTsKICAgICAgcmVzZXQudHlwZSA9ICdoaWRkZW4nOyByZXNldC5uYW1lID0gJ2V4Y2VsX3Jlc2V0JzsgcmVzZXQudmFsdWUgPSAnMSc7IGZvcm0uYXBwZW5kKHJlc2V0KTsKICAgIH0KICAgIHN0YXR1cy50ZXh0Q29udGVudCA9ICdFbnZvaSBkdSBmb3JtdWxhaXJl4oCmJzsKICAgIHF1ZXVlLmZpbmFsbHkoKCkgPT4gSFRNTEZvcm1FbGVtZW50LnByb3RvdHlwZS5zdWJtaXQuY2FsbChmb3JtKSk7CiAgfSk7Cn0pKCk7Cg==',true);
    if(!$excelScript)$excelScript=file_get_contents(__DIR__.'/vendor/sortablejs/Sortable-1.15.6.min.js')."\n;\n".file_get_contents(__DIR__.'/field_layout_editor.js')."\n;\n".file_get_contents(__DIR__.'/setlist_excel_preferences.js');
    // Ouvrir le groupe visé par un lien interne sans toucher aux réglages enregistrés.
    $script.="(()=>{const reveal=()=>{const target=document.getElementById(location.hash.slice(1));for(let node=target;node;node=node.parentElement)if(node.tagName==='DETAILS')node.open=true;};document.addEventListener('DOMContentLoaded',reveal);window.addEventListener('hashchange',reveal);})();";
    MusicbandScreen('Préférences', MusicbandStylesheet('configuration').$content, $script."\n;\n".$excelScript."\n;\n".MusicbandDevicePreferencesScript(),true); exit;
}
$message = ''; $token = '';
// Les anciennes invitations Google passent par le choix d'un mot de passe en Community ; le service revalide le lien.
$googleOnly = !(defined('MUSICBAND_COMMUNITY') && MUSICBAND_COMMUNITY) && ($_GET['google'] ?? '') === '1';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        MusicbandCsrf(); $token = (string)($_POST['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new RuntimeException('Lien absent ou invalide : rouvrir le lien reçu par e-mail.');
        $password = (string)($_POST['password'] ?? '');
        if (!hash_equals($password, (string)($_POST['confirmation'] ?? ''))) throw new RuntimeException('Les mots de passe ne correspondent pas.');
        MusicbandActivateAccount($token, $password, $googleOnly);
        if ($googleOnly) {
            MusicbandLogout(); header('Location: ' . MusicbandBase() . 'login.php?activated=google', true, 303); exit;
        }
        MusicbandLogout(); header('Location: ' . MusicbandBase() . 'login.php?password=updated', true, 303); exit;
    } catch (RuntimeException $e) { MusicbandAuditEvent('auth', 'account_link.rejected', 'rejected', ['reason' => 'invalid_or_expired_link']); $message = $e->getMessage(); }
    catch (Throwable $e) { MusicbandAuditEvent('auth', 'account_link.failed', 'error'); $message = 'Initialisation indisponible : réessayer plus tard.'; }
}
$content = '<section>' . ($message ? '<p class="notice" role="alert">' . MusicbandEscape($message) . '</p>' : '')
         . '<p>Choisissez un mot de passe entre 12 et 72 octets. Le lien ne sera utilisé qu’après validation de ce formulaire.</p><form method="post"><input type="hidden" name="csrf" value="' . MusicbandEscape($_SESSION['csrf']) . '"><input id="token" type="hidden" name="token" value="' . MusicbandEscape($token) . '"><label for="password">Nouveau mot de passe</label><input id="password" name="password" type="password" required autocomplete="new-password"><label for="confirmation">Confirmer le mot de passe</label><input id="confirmation" name="confirmation" type="password" required autocomplete="new-password"><button class="button-primary">Enregistrer mon mot de passe</button></form><noscript><p>Activez JavaScript pour ouvrir le lien reçu par e-mail.</p></noscript></section><p><a href="' . MusicbandEscape(MusicbandBase()) . 'forgot.php">Demander un nouveau lien de réinitialisation</a></p>';
if ($googleOnly) {
    $content = '<section>' . ($message ? '<p class="notice" role="alert">' . MusicbandEscape($message) . '</p>' : '')
        . '<p>Validez votre invitation, puis connectez-vous avec le compte Google autorisé. Aucun mot de passe local à créer.</p>'
        . '<form method="post"><input type="hidden" name="csrf" value="' . MusicbandEscape($_SESSION['csrf']) . '">'
        . '<input id="token" type="hidden" name="token" value="' . MusicbandEscape($token) . '">'
        . '<button class="button-primary">Valider mon invitation</button></form>'
        . '<noscript><p>Activez JavaScript pour ouvrir le lien reçu par e-mail.</p></noscript></section>';
}
$script = "const token=new URLSearchParams(location.hash.slice(1)).get('token');if(token){document.getElementById('token').value=token;history.replaceState(null,'',location.pathname+location.search);}document.querySelector('form').addEventListener('submit',()=>{document.querySelector('button').disabled=true;});";
MusicbandScreen($googleOnly ? 'Valider mon invitation' : 'Initialiser le mot de passe', $content, $script);
