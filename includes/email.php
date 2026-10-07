<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/* ---------------------------------------------------------------------------
 * Personalisation
 * ------------------------------------------------------------------------- */

function template_vars(array $customer): array
{
    $custom = [];
    if (!empty($customer['custom_fields'])) {
        $decoded = is_array($customer['custom_fields']) ? $customer['custom_fields'] : json_decode((string) $customer['custom_fields'], true);
        $custom = is_array($decoded) ? $decoded : [];
    }
    $vars = [
        'firstName' => (string) ($customer['first_name'] ?? ''),
        'lastName' => (string) ($customer['last_name'] ?? ''),
        'fullName' => customer_name($customer),
        'company' => (string) ($customer['company'] ?? ''),
        'email' => (string) ($customer['email'] ?? ''),
        'website' => (string) ($customer['website'] ?? ''),
        'jobTitle' => (string) ($customer['job_title'] ?? ''),
        'country' => (string) ($customer['country'] ?? ''),
    ];
    foreach ($custom as $k => $v) {
        if (is_scalar($v)) {
            $vars[(string) $k] ??= (string) $v;
        }
    }
    return $vars;
}

/**
 * Replace {{variable}} and {{variable|fallback}} placeholders.
 * When $html is true, values are HTML-escaped so customer data can't inject markup.
 */
function render_vars(string $text, array $customer, bool $html = false): string
{
    $vars = template_vars($customer);
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*(?:\|\s*([^}]*?))?\s*\}\}/', function ($m) use ($vars, $html) {
        $value = $vars[$m[1]] ?? '';
        if ($value === '' && isset($m[2])) {
            $value = $m[2];
        }
        return $html ? e($value) : $value;
    }, $text);
}

/* ---------------------------------------------------------------------------
 * Messages & threads
 * ------------------------------------------------------------------------- */

function generate_message_id(string $fromEmail): string
{
    $domain = substr(strrchr($fromEmail, '@') ?: '', 1) ?: app_host();
    return random_token(12) . '.' . time() . '@' . preg_replace('/[^a-z0-9.-]/i', '', $domain);
}

function create_thread(int $wsId, int $customerId, ?int $campaignId, ?int $accountId, string $subject): int
{
    $now = now();
    return db_insert('email_threads', [
        'workspace_id' => $wsId,
        'customer_id' => $customerId,
        'campaign_id' => $campaignId,
        'mail_account_id' => $accountId,
        'subject' => mb_substr($subject, 0, 255),
        'last_message_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * Create an outbound message row. Does not send.
 * $o keys: workspace_id, customer (array), campaign_id, account (array), subject, html, text, cc, bcc,
 *          user_id, thread_id, in_reply_to, references, status ('draft'|'queued'), message_id (existing draft id)
 */
function save_outbound_message(array $o): int
{
    $now = now();
    $account = $o['account'] ?? null;
    $customer = $o['customer'];
    $html = sanitize_html($o['html'] ?? '');
    $text = trim((string) ($o['text'] ?? '')) !== '' ? (string) $o['text'] : html_to_text($html);

    $data = [
        'workspace_id' => $o['workspace_id'],
        'customer_id' => $customer['id'],
        'campaign_id' => $o['campaign_id'] ?? null,
        'thread_id' => $o['thread_id'] ?? null,
        'mail_account_id' => $account['id'] ?? null,
        'user_id' => $o['user_id'] ?? null,
        'direction' => 'outbound',
        'in_reply_to' => $o['in_reply_to'] ?? null,
        'references_header' => $o['references'] ?? null,
        'from_email' => $account['email'] ?? null,
        'from_name' => $account['from_name'] ?? null,
        'to_email' => $o['to'] ?? $customer['email'],
        'cc' => $o['cc'] ?? null,
        'bcc' => $o['bcc'] ?? null,
        'subject' => mb_substr((string) $o['subject'], 0, 255),
        'html_body' => $html,
        'text_body' => $text,
        'status' => $o['status'] ?? 'draft',
        'queued_at' => ($o['status'] ?? '') === 'queued' ? $now : null,
        'updated_at' => $now,
    ];

    if (!empty($o['message_id'])) {
        db_update('email_messages', $data, 'id = ? AND workspace_id = ?', [$o['message_id'], $o['workspace_id']]);
        return (int) $o['message_id'];
    }
    $data['tracking_token'] = random_token(20);
    $data['created_at'] = $now;
    return db_insert('email_messages', $data);
}

/** Ensure the customer is a contact of the campaign (idempotent via UNIQUE(campaign_id, customer_id)). */
function ensure_campaign_contact(int $wsId, int $campaignId, int $customerId): bool
{
    $now = now();
    $inserted = q(
        'INSERT IGNORE INTO campaign_contacts (workspace_id, campaign_id, customer_id, manual_status, assigned_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$wsId, $campaignId, $customerId, 'New', $now, $now, $now]
    )->rowCount() > 0;
    if ($inserted) {
        $name = (string) q_val('SELECT name FROM campaigns WHERE id = ?', [$campaignId]);
        log_activity($wsId, 'campaign_added', 'Added to campaign "' . $name . '"', $customerId, $campaignId);
    }
    return $inserted;
}

/* ---------------------------------------------------------------------------
 * Transport
 * ------------------------------------------------------------------------- */

function new_phpmailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
    $mail->XMailer = ' ';
    $mail->Timeout = SMTP_TIMEOUT;
    return $mail;
}

function configure_smtp(PHPMailer $mail, string $host, int $port, string $encryption, ?string $user, ?string $pass): void
{
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port;
    $mail->SMTPAuth = $user !== null && $user !== '';
    $mail->Username = (string) $user;
    $mail->Password = (string) $pass;
    if ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }
}

/** PHPMailer configured for a mail account. SMTP connections are reused within one cron run. */
function mailer_for_account(array $account, bool $keepAlive = false): PHPMailer
{
    static $pool = [];
    if ($keepAlive && isset($pool[$account['id']])) {
        $mail = $pool[$account['id']];
        $mail->clearAllRecipients();
        $mail->clearAttachments();
        $mail->clearCustomHeaders();
        $mail->clearReplyTos();
        $mail->Subject = $mail->Body = $mail->AltBody = '';
        $mail->MessageID = '';
        return $mail;
    }
    $mail = new_phpmailer();
    if ($account['provider'] === 'smtp') {
        configure_smtp(
            $mail,
            (string) $account['smtp_host'],
            (int) $account['smtp_port'],
            (string) ($account['smtp_encryption'] ?? 'tls'),
            $account['smtp_username'] ?: $account['email'],
            decrypt_value($account['encrypted_smtp_password'])
        );
        $mail->SMTPKeepAlive = $keepAlive;
    }
    if ($keepAlive) {
        $pool[$account['id']] = $mail;
    }
    return $mail;
}

/** Close pooled SMTP connections at the end of a cron run. */
function close_mailers(): void
{
    // PHPMailer closes kept-alive connections in its destructor; force GC of the static pool
    gc_collect_cycles();
}

function smtp_failure_is_permanent(PHPMailer $mail, string $error): bool
{
    $code = 0;
    if ($mail->Mailer === 'smtp' && $mail->getSMTPInstance()) {
        $code = (int) ($mail->getSMTPInstance()->getError()['smtp_code'] ?? 0);
    }
    if (!$code && preg_match('/\b(5\d\d)\b/', $error, $m)) {
        $code = (int) $m[1];
    }
    if (preg_match('/invalid address|recipient address rejected|user unknown|no such user|mailbox unavailable|does not exist/i', $error)) {
        return true;
    }
    // 5xx = permanent, except 535 (auth) / 530 (needs auth) / 554 (often transient policy) which depend on account config
    return $code >= 500 && $code < 600 && !in_array($code, [530, 535, 554], true);
}

/**
 * Send one stored message right now.
 * Returns ['ok' => bool, 'error' => ?string, 'permanent' => bool].
 */
function send_message(int $messageId, bool $keepAlive = false): array
{
    $message = q_one('SELECT * FROM email_messages WHERE id = ?', [$messageId]);
    if (!$message) {
        return ['ok' => false, 'error' => 'Message not found', 'permanent' => true];
    }
    if (in_array($message['status'], ['sent', 'delivered', 'opened', 'clicked', 'replied'], true)) {
        return ['ok' => true, 'error' => null, 'permanent' => false]; // already sent: idempotent
    }
    $account = $message['mail_account_id'] ? q_one('SELECT * FROM mail_accounts WHERE id = ? AND workspace_id = ?', [$message['mail_account_id'], $message['workspace_id']]) : null;
    if (!$account) {
        return ['ok' => false, 'error' => 'No sending account configured', 'permanent' => true];
    }
    if ($account['status'] === 'disconnected') {
        return ['ok' => false, 'error' => 'Sending account is disconnected', 'permanent' => false];
    }
    $workspace = q_one('SELECT * FROM workspaces WHERE id = ?', [$message['workspace_id']]);
    // Campaign mail carries the unsubscribe footer/header; replies inside a conversation do not
    $isCampaign = !empty($message['campaign_id']) && empty($message['in_reply_to']);

    q("UPDATE email_messages SET status = 'sending', updated_at = ? WHERE id = ?", [now(), $messageId]);

    $mail = mailer_for_account($account, $keepAlive && $account['provider'] === 'smtp');
    $messageIdHeader = $message['message_id'] ?: generate_message_id($account['email']);

    try {
        $mail->setFrom($account['email'], (string) ($account['from_name'] ?: ''));
        if (!empty($account['reply_to'])) {
            $mail->addReplyTo($account['reply_to']);
        }
        $mail->addAddress((string) $message['to_email']);
        [$cc] = parse_email_list($message['cc']);
        [$bcc] = parse_email_list($message['bcc']);
        foreach ($cc as $a) {
            $mail->addCC($a);
        }
        foreach ($bcc as $a) {
            $mail->addBCC($a);
        }
        $mail->MessageID = '<' . $messageIdHeader . '>';
        if (!empty($message['in_reply_to'])) {
            $mail->addCustomHeader('In-Reply-To', '<' . $message['in_reply_to'] . '>');
            $refs = trim((string) $message['references_header']);
            $mail->addCustomHeader('References', $refs !== '' ? $refs : '<' . $message['in_reply_to'] . '>');
        }
        if ($isCampaign) {
            $mail->addCustomHeader('List-Unsubscribe', '<' . unsubscribe_url((string) $message['tracking_token']) . '>');
            $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }

        $mail->Subject = (string) $message['subject'];
        $html = (string) $message['html_body'];
        if (trim($html) !== '') {
            $mail->isHTML(true);
            $mail->Body = instrument_html($html, $message, $workspace, $isCampaign);
            $mail->AltBody = instrument_text((string) $message['text_body'] ?: html_to_text($html), $message, $workspace, $isCampaign);
        } else {
            $mail->isHTML(false);
            $mail->Body = instrument_text((string) $message['text_body'], $message, $workspace, $isCampaign);
        }

        foreach (q_all('SELECT * FROM attachments WHERE email_message_id = ?', [$messageId]) as $att) {
            $path = UPLOAD_PATH . '/' . $att['storage_path'];
            if (is_file($path)) {
                $mail->addAttachment($path, $att['filename'], PHPMailer::ENCODING_BASE64, $att['mime_type']);
            }
        }

        $providerId = null;
        $providerThread = null;
        if ($account['provider'] === 'gmail') {
            $mail->preSend();
            $threadRow = $message['thread_id'] ? q_one('SELECT provider_thread_id FROM email_threads WHERE id = ?', [$message['thread_id']]) : null;
            [$providerId, $providerThread, $realId] = gmail_send_raw($account, $mail->getSentMIMEMessage(), $threadRow['provider_thread_id'] ?? null);
            if ($realId) {
                $messageIdHeader = $realId;
            }
        } else {
            $mail->send();
            $providerId = $mail->getLastMessageID() ? trim($mail->getLastMessageID(), '<>') : null;
        }
    } catch (Throwable $e) {
        $error = $e instanceof MailerException ? ($mail->ErrorInfo ?: $e->getMessage()) : $e->getMessage();
        $error = mb_substr(preg_replace('/\s+/', ' ', $error), 0, 480);
        $permanent = $account['provider'] === 'smtp' ? smtp_failure_is_permanent($mail, $error) : ($e->getCode() === 400);
        q("UPDATE email_messages SET status = 'queued', error_message = ?, updated_at = ? WHERE id = ?", [$error, now(), $messageId]);
        if (preg_match('/authenticat|535|username and password|invalid_grant/i', $error)) {
            q("UPDATE mail_accounts SET status = 'error', last_error = ? WHERE id = ?", [$error, $account['id']]);
        }
        app_log('warning', 'Send failed', ['message_id' => $messageId, 'account' => $account['id'], 'error' => $error]);
        if (!$keepAlive && $mail->Mailer === 'smtp') {
            $mail->smtpClose();
        }
        return ['ok' => false, 'error' => $error, 'permanent' => $permanent];
    }

    $now = now();
    q(
        "UPDATE email_messages SET status = 'sent', sent_at = ?, message_id = ?, provider_message_id = ?, provider_thread_id = ?, error_message = NULL, updated_at = ? WHERE id = ?",
        [$now, $messageIdHeader, $providerId, $providerThread, $now, $messageId]
    );
    if ($account['status'] === 'error') {
        q("UPDATE mail_accounts SET status = 'active', last_error = NULL WHERE id = ?", [$account['id']]);
    }
    if ($message['thread_id']) {
        q(
            'UPDATE email_threads SET last_message_at = ?, provider_thread_id = COALESCE(provider_thread_id, ?), updated_at = ? WHERE id = ?',
            [$now, $providerThread, $now, $message['thread_id']]
        );
    }
    $message['status'] = 'sent';
    record_event($message, 'sent', ['account' => $account['email']], 'sent:' . $messageId);
    advance_contact_status($isCampaign ? (int) $message['campaign_id'] : null, (int) $message['customer_id'], 'sent', $messageId);
    if (!$keepAlive && $mail->Mailer === 'smtp') {
        $mail->smtpClose();
    }
    return ['ok' => true, 'error' => null, 'permanent' => false];
}

/** Mark a message as permanently failed (after retries or a permanent SMTP error). */
function fail_message(int $messageId, string $error): void
{
    $message = q_one('SELECT * FROM email_messages WHERE id = ?', [$messageId]);
    if (!$message) {
        return;
    }
    q("UPDATE email_messages SET status = 'failed', error_message = ?, updated_at = ? WHERE id = ?", [mb_substr($error, 0, 500), now(), $messageId]);
    record_event($message, 'failed', ['error' => mb_substr($error, 0, 300)], 'failed:' . $messageId);
    advance_contact_status($message['campaign_id'] ? (int) $message['campaign_id'] : null, (int) $message['customer_id'], 'failed', $messageId);
}

/* ---------------------------------------------------------------------------
 * Account tests & system mail
 * ------------------------------------------------------------------------- */

/** Returns [ok, message]. Tests SMTP login and, if configured, IMAP login. */
function test_mail_account(array $account): array
{
    if ($account['provider'] === 'gmail') {
        try {
            $profile = gmail_api($account, 'GET', '/profile');
            return [true, 'Connection successful (' . ($profile['emailAddress'] ?? $account['email']) . ').'];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
    $mail = mailer_for_account($account);
    $mail->Timeout = 15;
    try {
        if (!$mail->smtpConnect()) {
            return [false, 'Could not connect to SMTP server.'];
        }
        $mail->smtpClose();
    } catch (Throwable $e) {
        return [false, 'SMTP: ' . ($mail->ErrorInfo ?: $e->getMessage())];
    }
    $msg = 'SMTP connection successful.';
    if (!empty($account['imap_host'])) {
        try {
            $imap = imap_client_for_account($account);
            $imap->logout();
            $msg .= ' IMAP connection successful.';
        } catch (Throwable $e) {
            return [false, $msg . ' IMAP failed: ' . $e->getMessage()];
        }
    }
    return [true, $msg];
}

function send_test_email(array $account, string $to): array
{
    $mail = mailer_for_account($account);
    try {
        $mail->setFrom($account['email'], (string) ($account['from_name'] ?: APP_NAME));
        $mail->addAddress($to);
        $mail->Subject = 'Test email from ' . APP_NAME;
        $mail->isHTML(true);
        $mail->Body = '<p>This is a test email sent from <b>' . e(APP_NAME) . '</b> using the mail account <b>' . e($account['email']) . '</b>.</p><p>If you received it, sending works.</p>';
        $mail->AltBody = 'This is a test email sent from ' . APP_NAME . ' using ' . $account['email'] . '.';
        if ($account['provider'] === 'gmail') {
            $mail->preSend();
            gmail_send_raw($account, $mail->getSentMIMEMessage());
        } else {
            $mail->send();
        }
        return [true, "Test email sent to $to."];
    } catch (Throwable $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}

/** Password resets and other app notifications. Uses SYSTEM_SMTP_* or PHP mail(). */
function send_system_email(string $to, string $subject, string $html): bool
{
    $mail = new_phpmailer();
    try {
        if (SYSTEM_SMTP_HOST !== '') {
            configure_smtp($mail, SYSTEM_SMTP_HOST, SYSTEM_SMTP_PORT, SYSTEM_SMTP_ENCRYPTION, SYSTEM_SMTP_USERNAME, SYSTEM_SMTP_PASSWORD);
        } else {
            $mail->isMail();
        }
        $mail->setFrom(SYSTEM_FROM_EMAIL, SYSTEM_FROM_NAME);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = html_to_text($html);
        return $mail->send();
    } catch (Throwable $e) {
        app_log('error', 'System email failed', ['to' => $to, 'error' => $mail->ErrorInfo ?: $e->getMessage()]);
        return false;
    }
}

/* ---------------------------------------------------------------------------
 * Attachments
 * ------------------------------------------------------------------------- */

/**
 * Validate and store uploaded attachment files from $_FILES[$field].
 * Returns [storedRows[], errors[]]. Rows are not yet linked to a message.
 */
function store_uploaded_attachments(string $field, int $wsId): array
{
    $stored = $errors = [];
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) {
        return [$stored, $errors];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $maxBytes = UPLOAD_LIMIT_MB * 1024 * 1024;
    $files = $_FILES[$field];
    foreach ($files['name'] as $i => $name) {
        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $name = basename((string) $name);
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "$name: upload failed (code {$files['error'][$i]}).";
            continue;
        }
        if ($files['size'][$i] > $maxBytes) {
            $errors[] = "$name: larger than " . UPLOAD_LIMIT_MB . ' MB.';
            continue;
        }
        // Check every extension segment, so "invoice.php.pdf" is rejected too
        $parts = explode('.', strtolower($name));
        $ext = count($parts) > 1 ? end($parts) : '';
        $blocked = array_intersect(array_slice($parts, 1), BLOCKED_EXTENSIONS);
        if ($blocked || !isset(ALLOWED_ATTACHMENT_TYPES[$ext])) {
            $errors[] = "$name: file type not allowed.";
            continue;
        }
        $mime = (string) $finfo->file($files['tmp_name'][$i]);
        if (!in_array($mime, ALLOWED_ATTACHMENT_TYPES[$ext], true)) {
            $errors[] = "$name: content does not match its extension ($mime).";
            continue;
        }
        $rel = 'attachments/' . $wsId . '/' . date('Y/m') . '/' . random_token(16) . '.' . $ext;
        $dir = dirname(UPLOAD_PATH . '/' . $rel);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        if (!move_uploaded_file($files['tmp_name'][$i], UPLOAD_PATH . '/' . $rel)) {
            $errors[] = "$name: could not be saved.";
            continue;
        }
        $stored[] = [
            'workspace_id' => $wsId,
            'filename' => mb_substr(preg_replace('/[^\w.\- ()]+/u', '_', $name), 0, 255),
            'mime_type' => $mime,
            'size' => (int) $files['size'][$i],
            'storage_path' => $rel,
            'created_at' => now(),
        ];
    }
    return [$stored, $errors];
}

function attach_files_to_message(array $rows, int $messageId): void
{
    foreach ($rows as $row) {
        $row['email_message_id'] = $messageId;
        db_insert('attachments', $row);
    }
}
