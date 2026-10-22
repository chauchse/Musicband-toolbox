<?php
/**
 * Musicband EMAIL SERVICE : envoi centralisé par SMTP authentifié, PHP mail() ou Gmail API configurée.
 *
 * Responsabilité :
 * - Construction conforme de messages MIME (RFC 2822 / RFC 2045-2047) : texte brut,
 *   HTML, destinataires multiples (To, Cc, Bcc, Reply-To), pièces jointes (PDF, ICS).
 * - Encodage Base64URL et transmission via Google Gmail API v1 (users/me/messages/send).
 * - Réutilisation de l'authentification OAuth existante (Google Drive / Calendar)
 *   avec le scope gmail.send déjà accordé.
 * - Transport local PHP mail(), par défaut sur une instance Community neuve.
 * - SMTP authentifié PHPMailer 7.1.1, TLS obligatoire et secrets dans les réglages privés.
 * - Formulaire Community sans renvoi vers les accès du module Devis/Factures retiré.
 * - Journalisation structurée sans secret, sans token et sans contenu sensible.
 *
 * Dépendances internes :
 * - auth.php (MusicbandRead, MusicbandWrite, MusicbandEmail, MusicbandPrivate, MusicbandRequireLogin, MusicbandCsrf)
 * - documents_delivery.php (documentsGoogleToken, documentsGoogleRequest, documentsGoogleCredentials)
 * - audit_trail.php (MusicbandAuditEvent si actif)
 *
 * Dépendances externes :
 * - Google Gmail API v1 (HTTPS cURL via documentsGoogleRequest)
 * - Google OAuth 2.0 (token refresh endpoint)
 * - vendor/phpmailer/src/SMTP.php et Exception.php, licence LGPL 2.1.
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/documents_delivery.php';

// Limites défensives de sécurité pour l'envoi d'e-mails.
const Musicband_MAIL_MAX_BODY_BYTES = 200000;
const Musicband_MAIL_MAX_ATTACHMENT_BYTES = 10000000; // 10 Mo par pièce jointe
const Musicband_MAIL_MAX_RECIPIENTS = 50;

/**
 * Lit la configuration e-mail dans le stockage privé.
 *
 * @return array Réglages e-mail et SMTP privés ; ne jamais sérialiser ce tableau dans le frontend.
 */
function MusicbandEmailSettings(): array {
    $saved = MusicbandRead('mail_settings');
    $from = MusicbandEmail($saved['from'] ?? (getenv('Musicband_MAIL_FROM') ?: ''));
    $fromName = trim((string)($saved['from_name'] ?? 'Musicband'));
    $replyTo = MusicbandEmail($saved['reply_to'] ?? '');
    // Une instance Community neuve ne doit pas dépendre des accès OAuth des modules retirés.
    $defaultTransport = defined('MUSICBAND_COMMUNITY') && MUSICBAND_COMMUNITY ? 'mail' : 'gmail';
    $transport = $saved['transport'] ?? $defaultTransport;
    if (!in_array($transport, ['mail','gmail','smtp'], true)) throw new RuntimeException('Transport e-mail enregistré invalide.');

    return [
        'transport' => $transport,
        'from' => $from,
        'from_name' => $fromName !== '' ? $fromName : 'Musicband',
        'reply_to' => $replyTo,
        'smtp_host' => (string)($saved['smtp_host'] ?? ''),
        'smtp_port' => (string)($saved['smtp_port'] ?? '587'),
        'smtp_security' => (string)($saved['smtp_security'] ?? 'starttls'),
        'smtp_username' => (string)($saved['smtp_username'] ?? ''),
        'smtp_password_set' => !empty($saved['smtp_password']),
    ];
}

/** Libellé commun aux écrans et au message de contrôle. */
function MusicbandMailTransportLabel(string $transport): string {
    return ['mail'=>'PHP mail() local','gmail'=>'Google Gmail API','smtp'=>'SMTP authentifié'][$transport] ?? 'Transport inconnu';
}

/** Refuse les URL, listes de serveurs et connexions sans TLS avant toute ouverture réseau. */
function MusicbandSmtpValidate(array $settings): void {
    $host = $settings['smtp_host'];
    if ($host==='' || strlen($host)>253 || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        || !ctype_digit((string)$settings['smtp_port']) || (int)$settings['smtp_port']<1 || (int)$settings['smtp_port']>65535
        || !in_array($settings['smtp_security'], ['starttls','smtps'], true)
        || $settings['smtp_username']==='' || $settings['smtp_password']==='') throw new RuntimeException('SMTP : serveur, port valide, chiffrement TLS, identifiant et mot de passe requis.');
    foreach (['smtp_host','smtp_username','smtp_password'] as $key) {
        if (strlen($settings[$key])>1024 || preg_match('/[\x00-\x1f\x7f]/', $settings[$key])) throw new RuntimeException('Paramètre SMTP invalide.');
    }
}

/** Transmet le MIME existant sans réencoder les pièces jointes ; aucun repli ou nouvel essai automatique.
 * Les destinataires Bcc restent dans l’enveloppe uniquement. Aucun dialogue serveur ni secret n’est journalisé.
 */
function MusicbandSendSmtp(array $settings, array $mime, string $raw, string $type, float $started): array {
    $settings['smtp_password'] = (string)(MusicbandRead('mail_settings')['smtp_password'] ?? '');
    MusicbandSmtpValidate($settings);
    if (!extension_loaded('openssl')) throw new RuntimeException('SMTP : extension OpenSSL requise.');
    require_once __DIR__.'/vendor/phpmailer/src/Exception.php';
    require_once __DIR__.'/vendor/phpmailer/src/SMTP.php';
    $smtp = new \PHPMailer\PHPMailer\SMTP();
    $smtp->Timeout = 10;
    $smtp->Timelimit = 15;
    $smtp->setDebugLevel(0);
    $stage = 'connexion TLS';
    try {
        $host = ($settings['smtp_security']==='smtps'?'ssl://':'').$settings['smtp_host'];
        $options = ['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$settings['smtp_host']]];
        if (!$smtp->connect($host, (int)$settings['smtp_port'], 10, $options) || !$smtp->hello('localhost.localdomain')) throw new RuntimeException();
        if ($settings['smtp_security']==='starttls') {
            if (!$smtp->startTLS() || !$smtp->hello('localhost.localdomain')) throw new RuntimeException();
        }
        $stage = 'authentification';
        if (!$smtp->authenticate($settings['smtp_username'], $settings['smtp_password'])) throw new RuntimeException();
        $stage = 'expéditeur';
        if (!$smtp->mail($mime['from'])) throw new RuntimeException();
        $recipients = [];
        foreach (['to','cc','bcc'] as $key) $recipients = array_merge($recipients, MusicbandFormatRecipients($mime[$key] ?? [])['addresses']);
        $recipients = array_values(array_unique($recipients));
        if (count($recipients)>Musicband_MAIL_MAX_RECIPIENTS) throw new RuntimeException('Trop de destinataires.');
        $stage = 'destinataires';
        foreach ($recipients as $recipient) if (!$smtp->recipient($recipient)) throw new RuntimeException();
        // Ne jamais transmettre l’en-tête Bcc aux autres destinataires.
        [$headers, $body] = explode("\r\n\r\n", $raw, 2);
        $headers = preg_replace('/^Bcc:[^\r\n]*(?:\r\n[ \t][^\r\n]*)*\r\n/mi', '', $headers."\r\n");
        $stage = 'transmission';
        if (!$smtp->data(rtrim($headers,"\r\n")."\r\n\r\n".$body)) throw new RuntimeException();
        $duration = (int)round((microtime(true)-$started)*1000);
        MusicbandMailLog($type, 'accepted', 200, '', $duration);
        return ['status'=>'accepted','transport'=>'smtp','message_id'=>'','duration_ms'=>$duration];
    } catch (Throwable $e) {
        $code = (string)($smtp->getError()['smtp_code'] ?? '');
        $code = preg_match('/^\d{3}$/D',$code)?$code:'';
        MusicbandMailLog($type, $stage==='transmission'?'uncertain':'failed', 500, '', (int)round((microtime(true)-$started)*1000), 'SMTP '.$stage.($code!==''?' code='.$code:''));
        throw new RuntimeException('SMTP : échec à l’étape '.$stage.($code!==''?' (code '.$code.')':'').'. Vérifier les paramètres et le suivi du service mail.'.($stage==='transmission'?' Livraison incertaine : vérifier avant de renvoyer.':''));
    } finally {
        // Une erreur de fermeture ne doit pas transformer une acceptation en échec et provoquer un doublon.
        try { $smtp->quit(); } catch (Throwable $ignored) {} finally { $smtp->close(); }
    }
}

/**
 * Enregistre les réglages d'e-mails depuis l'écran d'administration.
 *
 * @throws RuntimeException En cas de validation échouée ou droits insuffisants.
 */
function MusicbandEmailSettingsSave(): string {
    if (!(MusicbandRequireLogin()['admin'] ?? false)) {
        throw new RuntimeException('Accès administrateur requis.');
    }
    MusicbandCsrf();

    $from = MusicbandEmail($_POST['from'] ?? '');
    if ($from === '') {
        throw new RuntimeException('Adresse expéditrice valide requise.');
    }

    $fromName = trim((string)($_POST['from_name'] ?? 'Musicband'));
    if (strlen($fromName) > 120 || preg_match('/[\r\n\x00-\x1f\x7f]/', $fromName)) {
        throw new RuntimeException('Nom expéditeur invalide (120 caractères max, sans retour à la ligne).');
    }

    $replyTo = MusicbandEmail($_POST['reply_to'] ?? '');
    $saved = MusicbandRead('mail_settings');
    $transport = $_POST['transport'] ?? '';
    if (!is_string($transport) || !in_array($transport, ['mail','gmail','smtp'], true)) throw new RuntimeException('Choisir un transport e-mail valide.');
    $smtp = [];
    foreach (['smtp_host','smtp_port','smtp_security','smtp_username','smtp_password'] as $key) {
        $value = $_POST[$key] ?? ($saved[$key] ?? ($key==='smtp_port'?'587':($key==='smtp_security'?'starttls':'')));
        if (!is_string($value) || strlen($value)>1024 || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new RuntimeException('Paramètre SMTP invalide.');
        // Un champ mot de passe vide conserve le secret sans jamais le réafficher.
        $smtp[$key] = $key==='smtp_password' ? ($value!==''?$value:(string)($saved[$key]??'')) : trim($value);
    }
    if ($transport==='smtp') MusicbandSmtpValidate($smtp);


    $settings = [
        'transport' => $transport,
        'from' => $from,
        'from_name' => $fromName !== '' ? $fromName : 'Musicband',
        'reply_to' => $replyTo,
    ];
    MusicbandWrite('mail_settings', array_replace($saved, $settings, $smtp));

    return 'Configuration des e-mails enregistrée. Transport actif : '
        . MusicbandMailTransportLabel($transport) . '.';
}

/**
 * Génère le formulaire d'administration de la rubrique E-mails.
 */
function MusicbandEmailSettingsForm(string $hidden): string {
    $settings = MusicbandEmailSettings();
    $community = defined('MUSICBAND_COMMUNITY') && MUSICBAND_COMMUNITY;
    // Le transport local ne nécessite pas les accès Google des modules retirés.
    if ($community) {
        $oauthNotice = $settings['transport'] === 'smtp'
            ? '<p class="notice">SMTP authentifié : saisir les paramètres fournis par le service mail. TLS est obligatoire. Enregistrer avant de lancer un test.</p>'
            : ($settings['transport'] === 'mail'
            ? '<p class="notice">Envoi par le serveur mail de l’hébergement. Configurer une adresse expéditrice autorisée par l’hébergeur.</p>'
            : '<p class="notice">Le transport Google existant est conservé. Sa configuration privée doit être disponible sur le serveur. Pour utiliser le serveur mail de l’hébergement, choisir PHP mail() local.</p>');
    } else {
        $creds = documentsGoogleCredentials();
        $hasOauth = (!empty($creds['client_id']) && !empty($creds['client_secret']) && !empty($creds['refresh_token']));

        $oauthNotice = $hasOauth
            ? '<p class="notice">Accès OAuth Google configuré (scope Gmail API inclus). Les e-mails sont acheminés directement via Google Workspace sans dépendre du serveur d’envoi OVH.</p>'
            : '<p class="notice">Accès OAuth non configuré. Renseigner les identifiants OAuth dans <a href="#config-devis_factures">Devis / Factures → Accès Google Drive</a> pour activer l’envoi Gmail API.</p>';
    }

    $html = '<section><h2>E-mails · Envoi & Transport</h2>' . $oauthNotice;
    $html .= '<form method="post">' . $hidden;
    $html .= '<input type="hidden" name="action" value="mail_settings">';

    // Choix du transport
    $html .= '<label>Mode de transport<select name="transport">';
    $html .= '<option value="smtp"' . ($settings['transport'] === 'smtp' ? ' selected' : '') . '>SMTP authentifié (TLS)</option>';
    $html .= '<option value="gmail"' . ($settings['transport'] === 'gmail' ? ' selected' : '') . '>Google Gmail API (configuration privée requise)</option>';
    $html .= '<option value="mail"' . ($settings['transport'] === 'mail' ? ' selected' : '') . '>PHP mail() local de l’hébergement</option>';
    $html .= '</select></label>';

    // Champs SMTP accessibles sans JavaScript ; aucune valeur de secret dans le HTML.
    $html .= '<fieldset><legend>SMTP authentifié</legend><p>À renseigner pour le transport SMTP. Laisser le mot de passe vide pour conserver celui enregistré. Aucune connexion à la sauvegarde.</p>';
    $html .= '<label>Serveur SMTP<input name="smtp_host" maxlength="253" autocomplete="off" placeholder="smtp.exemple.org" value="'.MusicbandEscape($settings['smtp_host']).'"></label>';
    $html .= '<label>Port SMTP<input type="number" name="smtp_port" min="1" max="65535" value="'.MusicbandEscape($settings['smtp_port']).'"></label>';
    $html .= '<label>Chiffrement<select name="smtp_security"><option value="starttls"'.($settings['smtp_security']==='starttls'?' selected':'').'>STARTTLS (port habituel 587)</option><option value="smtps"'.($settings['smtp_security']==='smtps'?' selected':'').'>TLS implicite (port habituel 465)</option></select></label>';
    $html .= '<label>Identifiant SMTP<input name="smtp_username" maxlength="1024" autocomplete="off" value="'.MusicbandEscape($settings['smtp_username']).'"></label>';
    $html .= '<label>Mot de passe SMTP<input type="password" name="smtp_password" maxlength="1024" autocomplete="new-password" value=""></label><p>'.($settings['smtp_password_set']?'Mot de passe enregistré.':'Aucun mot de passe enregistré.').'</p></fieldset>';

    // Expéditeur
    $html .= '<label for="mail_from">Adresse e-mail expéditrice<input id="mail_from" name="from" type="email" required maxlength="254" autocomplete="section-sender email" placeholder="toolbox@exemple.org" value="' . MusicbandEscape($settings['from']) . '"></label>';
    $html .= '<label for="mail_from_name">Nom d’expéditeur affiché<input id="mail_from_name" name="from_name" type="text" maxlength="120" placeholder="Musicband" value="' . MusicbandEscape($settings['from_name']) . '"></label>';
    $html .= '<label for="mail_reply_to">Adresse de réponse (Reply-To, facultatif)<input id="mail_reply_to" name="reply_to" type="email" maxlength="254" placeholder="contact@exemple.org" value="' . MusicbandEscape($settings['reply_to']) . '"></label>';

    $html .= '<button>Enregistrer la configuration des e-mails</button></form>';

    // Formulaire de test d'envoi administrateur
    $currentUser = MusicbandUser();
    $defaultTestEmail = MusicbandEmail($currentUser['email'] ?? '');
    $html .= '<h3>Test d’envoi d’e-mail</h3>';
    $html .= '<p>Envoie un message de contrôle avec le transport enregistré. Vérifier ensuite sa réception.</p>';
    $html .= '<form method="post">' . $hidden;
    $html .= '<input type="hidden" name="action" value="mail_test">';
    $html .= '<label>Destinataire du test<input name="test_email" type="email" required maxlength="254" placeholder="destinataire@exemple.fr" value="' . MusicbandEscape($defaultTestEmail) . '"></label>';
    $html .= '<button>Envoyer un e-mail de test</button></form>';

    $html .= '</section>';
    return $html;
}

/**
 * Encode un sujet ou une chaîne en RFC 2047 Base64 (UTF-8).
 */
function MusicbandMimeSubject(string $subject): string {
    $clean = preg_replace('/[\r\n\x00-\x1f\x7f]/u', ' ', $subject);
    $clean = trim((string)$clean);
    if ($clean === '') {
        return '';
    }
    // Si la chaîne est purement ASCII sans caractères spéciaux RFC, pas besoin d'encodage B.
    if (preg_match('/^[A-Za-z0-9 ,.\-_:;!?()\'\/]+$/D', $clean)) {
        return $clean;
    }
    // Découpage en tranches UTF-8 de 42 octets max pour garantir des lignes <= 76 caractères
    $chunks = [];
    $chunk = '';
    foreach (preg_split('//u', $clean, -1, PREG_SPLIT_NO_EMPTY) as $char) {
        if (strlen($chunk . $char) > 42) {
            $chunks[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
            $chunk = '';
        }
        $chunk .= $char;
    }
    if ($chunk !== '') {
        $chunks[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
    }
    return implode(' ', $chunks);
}

/**
 * Normalise et formate une liste d'adresses pour les en-têtes RFC 2822.
 *
 * @param string|array $recipients
 * @return array{header: string, addresses: string[]}
 */
function MusicbandFormatRecipients($recipients): array {
    $list = is_array($recipients) ? $recipients : explode(',', (string)$recipients);
    $validAddresses = [];
    $headerParts = [];

    foreach ($list as $entry) {
        $entry = trim((string)$entry);
        if ($entry === '') {
            continue;
        }
        if (preg_match('/^(.*?)\s*<([^>]+)>$/u', $entry, $matches)) {
            $name = trim($matches[1], " \t\n\r\0\x0B\"'");
            $email = MusicbandEmail($matches[2]);
            if ($email !== '') {
                $validAddresses[] = $email;
                $headerParts[] = $name !== ''
                    ? '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>'
                    : $email;
            }
        } else {
            $email = MusicbandEmail($entry);
            if ($email !== '') {
                $validAddresses[] = $email;
                $headerParts[] = $email;
            }
        }
    }

    return [
        'header' => implode(', ', $headerParts),
        'addresses' => array_values(array_unique($validAddresses)),
    ];
}

/**
 * Construit un message MIME complet conforme aux spécifications RFC 2822 / RFC 2045-2047.
 *
 * @param array{
 *   from: string,
 *   from_name?: string,
 *   to: string|array,
 *   cc?: string|array,
 *   bcc?: string|array,
 *   reply_to?: string,
 *   subject: string,
 *   text: string,
 *   html?: ?string,
 *   attachments?: array,
 *   message_id?: string,
 *   date?: string
 * } $data
 * @return string Message MIME complet avec délimiteurs CRLF (\r\n).
 */
function MusicbandBuildMime(array $data): string {
    // Validation expéditeur
    $fromEmail = MusicbandEmail($data['from'] ?? '');
    if ($fromEmail === '') {
        throw new RuntimeException('Adresse expéditrice requise pour la construction du message.');
    }
    $fromName = trim((string)($data['from_name'] ?? ''));
    $fromFormatted = $fromName !== ''
        ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>'
        : $fromEmail;

    // Validation destinataires
    $toFormatted = MusicbandFormatRecipients($data['to'] ?? '');
    if (empty($toFormatted['addresses'])) {
        throw new RuntimeException('Au moins un destinataire (To) valide est requis.');
    }
    if (count($toFormatted['addresses']) > Musicband_MAIL_MAX_RECIPIENTS) {
        throw new RuntimeException('Nombre de destinataires supérieur à la limite autorisée.');
    }

    // En-têtes obligatoires
    $headers = [
        'From' => $fromFormatted,
        'To' => $toFormatted['header'],
        'Subject' => MusicbandMimeSubject((string)($data['subject'] ?? '')),
        'Date' => (string)($data['date'] ?? date(DATE_RFC2822)),
        'MIME-Version' => '1.0',
    ];

    // Message-ID
    $domain = substr(strrchr($fromEmail, '@') ?: '@example.invalid', 1);
    $headers['Message-ID'] = (string)($data['message_id'] ?? ('<Musicband-' . bin2hex(random_bytes(16)) . '@' . $domain . '>'));

    // Copies Cc
    if (!empty($data['cc'])) {
        $ccFormatted = MusicbandFormatRecipients($data['cc']);
        if (!empty($ccFormatted['addresses'])) {
            $headers['Cc'] = $ccFormatted['header'];
        }
    }

    // Copies Bcc
    if (!empty($data['bcc'])) {
        $bccFormatted = MusicbandFormatRecipients($data['bcc']);
        if (!empty($bccFormatted['addresses'])) {
            $headers['Bcc'] = $bccFormatted['header'];
        }
    }

    // Reply-To
    $replyTo = MusicbandEmail($data['reply_to'] ?? '');
    if ($replyTo !== '') {
        $headers['Reply-To'] = $replyTo;
    }

    $text = (string)($data['text'] ?? '');
    $html = isset($data['html']) && is_string($data['html']) && trim($data['html']) !== ''
        ? $data['html']
        : null;
    $attachments = is_array($data['attachments'] ?? null) ? $data['attachments'] : [];

    // Détermination de la structure MIME
    $hasAttachments = !empty($attachments);
    $hasHtml = $html !== null;

    if ($hasAttachments) {
        // multipart/mixed englobant le corps et les pièces jointes
        $mixedBoundary = 'Musicband_mixed_' . bin2hex(random_bytes(12));
        $headers['Content-Type'] = 'multipart/mixed; boundary="' . $mixedBoundary . '"';

        $bodyParts = [];

        // Partie corps : alternative (texte + html) ou texte brut
        if ($hasHtml) {
            $altBoundary = 'Musicband_alt_' . bin2hex(random_bytes(12));
            $altPart = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"' . "\r\n\r\n";
            $altPart .= '--' . $altBoundary . "\r\n";
            $altPart .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
            $altPart .= chunk_split(base64_encode($text)) . "\r\n";
            $altPart .= '--' . $altBoundary . "\r\n";
            $altPart .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
            $altPart .= chunk_split(base64_encode($html)) . "\r\n";
            $altPart .= '--' . $altBoundary . '--';

            $bodyParts[] = $altPart;
        } else {
            $textPart = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                      . chunk_split(base64_encode($text));
            $bodyParts[] = $textPart;
        }

        // Pièces jointes
        foreach ($attachments as $att) {
            if (!is_array($att)) continue;
            $filename = basename(trim((string)($att['filename'] ?? 'attachment')));
            if ($filename === '') $filename = 'document';
            $content = (string)($att['content'] ?? '');
            if (strlen($content) > Musicband_MAIL_MAX_ATTACHMENT_BYTES) {
                throw new RuntimeException('Pièce jointe ' . $filename . ' supérieure à la taille maximale (10 Mo).');
            }
            $mime = (string)($att['mime'] ?? 'application/octet-stream');
            $disposition = ($att['disposition'] ?? 'attachment') === 'inline' ? 'inline' : 'attachment';

            $safeAscii = (bool)preg_match('/^[a-zA-Z0-9._-]+$/', $filename);
            $encodedFilename = $safeAscii ? $filename : ('=?UTF-8?B?' . base64_encode($filename) . '?=');
            $attPart = 'Content-Type: ' . $mime . '; name="' . $encodedFilename . "\"\r\n";
            $attPart .= 'Content-Disposition: ' . $disposition . '; filename="' . $encodedFilename . "\"\r\n";
            $attPart .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $attPart .= chunk_split(base64_encode($content));

            $bodyParts[] = $attPart;
        }

        $bodyContent = '--' . $mixedBoundary . "\r\n"
                     . implode("\r\n--" . $mixedBoundary . "\r\n", $bodyParts)
                     . "\r\n--" . $mixedBoundary . '--';

    } elseif ($hasHtml) {
        // multipart/alternative simple (texte + html)
        $altBoundary = 'Musicband_alt_' . bin2hex(random_bytes(12));
        $headers['Content-Type'] = 'multipart/alternative; boundary="' . $altBoundary . '"';

        $bodyContent = '--' . $altBoundary . "\r\n"
                     . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                     . chunk_split(base64_encode($text)) . "\r\n"
                     . '--' . $altBoundary . "\r\n"
                     . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                     . chunk_split(base64_encode($html)) . "\r\n"
                     . '--' . $altBoundary . '--';

    } else {
        // texte brut simple
        $headers['Content-Type'] = 'text/plain; charset=UTF-8';
        $headers['Content-Transfer-Encoding'] = 'base64';
        $bodyContent = chunk_split(base64_encode($text));
    }

    // Assemblage final des en-têtes et du corps
    $headerLines = [];
    foreach ($headers as $name => $val) {
        $headerLines[] = $name . ': ' . $val;
    }

    return implode("\r\n", $headerLines) . "\r\n\r\n" . $bodyContent;
}

/**
 * Encodage Base64URL sécurisé requis par l'API Gmail (RFC 4648 §5).
 */
function MusicbandBase64Url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Enregistre un événement structuré dans le journal sans secrets ni données sensibles.
 */
function MusicbandMailLog(string $type, string $status, int $httpCode, string $messageId, int $durationMs, string $error = ''): void {
    $cleanError = $error !== '' ? preg_replace('/[\r\n\t]+/', ' ', substr($error, 0, 300)) : '';
    error_log(sprintf(
        '[Musicband Mail] type=%s status=%s http_code=%d message_id=%s duration_ms=%d error=%s',
        $type,
        $status,
        $httpCode,
        $messageId !== '' ? $messageId : '-',
        $durationMs,
        $cleanError !== '' ? $cleanError : '-'
    ));

    if (function_exists('MusicbandAuditEvent')) {
        try {
            MusicbandAuditEvent('mail', $type, $status, [
                'http_status' => (string)$httpCode,
                'id' => $messageId,
                'reason' => $cleanError,
            ]);
        } catch (Throwable $ignored) {}
    }
}

/**
 * Service centralisé d'envoi d'e-mail.
 *
 * Utilise prioritairement l'API Gmail avec le token OAuth Workspace,
 * ou le transport SMTP authentifié / PHP mail() local selon le réglage enregistré.
 *
 * @param array{
 *   to: string|array,
 *   subject: string,
 *   text: string,
 *   html?: ?string,
 *   from?: string,
 *   from_name?: string,
 *   cc?: string|array,
 *   bcc?: string|array,
 *   reply_to?: string,
 *   attachments?: array,
 *   type?: string
 * } $params
 * @return array{status: string, transport: string, message_id: string, thread_id?: string, duration_ms: int}
 * @throws RuntimeException En cas de rejet par Gmail API ou d'erreur de transport.
 */
function MusicbandSendEmail(array $params): array {
    $started = microtime(true);
    $type = (string)($params['type'] ?? 'general');

    $settings = MusicbandEmailSettings();
    $transport = $settings['transport'];

    // Expéditeur effectif
    $from = MusicbandEmail((string)($params['from'] ?? $settings['from']));
    if ($from === '') {
        throw new RuntimeException('Aucune adresse e-mail expéditrice configurée dans Musicband.');
    }
    $fromName = trim((string)($params['from_name'] ?? $settings['from_name']));

    // Préparation des paramètres MIME
    $mimeParams = [
        'from' => $from,
        'from_name' => $fromName !== '' ? $fromName : 'Musicband',
        'to' => $params['to'] ?? '',
        'cc' => $params['cc'] ?? [],
        'bcc' => $params['bcc'] ?? [],
        'reply_to' => $params['reply_to'] ?? ($settings['reply_to'] ?: null),
        'subject' => (string)($params['subject'] ?? ''),
        'text' => (string)($params['text'] ?? ''),
        'html' => isset($params['html']) ? (string)$params['html'] : null,
        'attachments' => is_array($params['attachments'] ?? null) ? $params['attachments'] : [],
    ];

    $rawMime = MusicbandBuildMime($mimeParams);

    if ($transport === 'smtp') return MusicbandSendSmtp($settings, $mimeParams, $rawMime, $type, $started);

    if ($transport === 'gmail') {
        // Envoi via Google Gmail API
        try {
            $token = documentsGoogleToken();
            $base64Url = MusicbandBase64Url($rawMime);
            $payload = json_encode(['raw' => $base64Url], JSON_THROW_ON_ERROR);

            $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';
            $response = documentsGoogleRequest($url, 'POST', $payload, [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ]);

            $decoded = json_decode($response, true);
            $messageId = (string)($decoded['id'] ?? '');
            $threadId = (string)($decoded['threadId'] ?? '');

            if ($messageId === '') {
                throw new RuntimeException('Gmail API : identifiant de message absent de la réponse Google.');
            }

            $durationMs = (int)round((microtime(true) - $started) * 1000);
            MusicbandMailLog($type, 'accepted', 200, $messageId, $durationMs);

            return [
                'status' => 'accepted',
                'transport' => 'gmail',
                'message_id' => $messageId,
                'thread_id' => $threadId,
                'duration_ms' => $durationMs,
            ];

        } catch (Throwable $e) {
            $durationMs = (int)round((microtime(true) - $started) * 1000);
            preg_match('/HTTP\s+(\d{3})/', $e->getMessage(), $matches);
            $httpCode = isset($matches[1]) ? (int)$matches[1] : 500;
            MusicbandMailLog($type, 'failed', $httpCode, '', $durationMs, $e->getMessage());

            throw new RuntimeException('Gmail API : ' . $e->getMessage(), 0, $e);
        }
    } else {
        // Transport PHP mail() local de secours
        $parts = explode("\r\n\r\n", $rawMime, 2);
        $headerLines = explode("\r\n", $parts[0]);
        $headers = [];
        $toHeader = '';
        $subjectHeader = '';

        foreach ($headerLines as $line) {
            if (preg_match('/^To:\s*(.*)$/i', $line, $m)) {
                $toHeader = $m[1];
            } elseif (preg_match('/^Subject:\s*(.*)$/i', $line, $m)) {
                $subjectHeader = $m[1];
            } elseif (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $m)) {
                $headers[$m[1]] = $m[2];
            }
        }
        $body = $parts[1] ?? '';

        // L’acceptation locale ne constitue pas un accusé de livraison au destinataire.
        if (!function_exists('mail')) {
            MusicbandMailLog($type, 'failed', 500, '', (int)round((microtime(true) - $started) * 1000), 'mail() indisponible');
            throw new RuntimeException('La fonction PHP mail() est indisponible sur cet hébergement.');
        }
        $sent = @mail($toHeader, $subjectHeader, $body, $headers);
        $durationMs = (int)round((microtime(true) - $started) * 1000);
        $status = $sent ? 'accepted' : 'failed';

        MusicbandMailLog($type, $status, $sent ? 200 : 500, '', $durationMs, $sent ? '' : 'mail() retourné false');

        if (!$sent) {
            throw new RuntimeException('Le service mail local a refusé la transmission. Consulter le journal PHP et le suivi des envois chez l’hébergeur.');
        }

        return [
            'status' => 'accepted',
            'transport' => 'mail',
            'message_id' => '',
            'duration_ms' => $durationMs,
        ];
    }
}

/**
 * Envoie un e-mail de test administrateur pour valider le transport.
 *
 * @throws RuntimeException
 */
function MusicbandTestEmail(string $recipient): array {
    $user = MusicbandRequireLogin();
    if (!($user['admin'] ?? false)) {
        throw new RuntimeException('Accès administrateur requis.');
    }

    $email = MusicbandEmail($recipient);
    if ($email === '') {
        throw new RuntimeException('Adresse de test invalide.');
    }

    $settings = MusicbandEmailSettings();
    $subject = 'Musicband · Test d’envoi d’e-mail';
    $body = "Bonjour,\n\n"
          . "Ceci est un e-mail de test envoyé depuis Musicband Toolbox.\n"
          . "Transport utilisé : " . MusicbandMailTransportLabel($settings['transport']) . "\n"
          . "Expéditeur configuré : " . $settings['from'] . "\n"
          . "Date d’envoi : " . date('d/m/Y H:i:s') . "\n"
          . "Déclenché par : " . $user['login'] . "\n\n"
          . "La réception de ce message confirme uniquement la livraison de ce test à cette adresse.\n";

    return MusicbandSendEmail([
        'to' => $email,
        'subject' => $subject,
        'text' => $body,
        'type' => 'test',
    ]);
}

/**
 * Classe de service orientée objet pour l'envoi d'e-mails.
 */
class MusicbandEmailService {
    public function send(array $params): array {
        return MusicbandSendEmail($params);
    }

    public function buildMime(array $data): string {
        return MusicbandBuildMime($data);
    }

    public function base64Url(string $data): string {
        return MusicbandBase64Url($data);
    }

    public function getSettings(): array {
        return MusicbandEmailSettings();
    }
}
