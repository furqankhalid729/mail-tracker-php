<?php
defined('APP_ROOT') || exit;
/** Create/update an SMTP account. Expects $ws, $existing (array|null). */
$errors = validate($_POST, [
    'name' => 'required|max:120',
    'email' => 'required|email',
    'reply_to' => 'email',
    'daily_limit' => 'required|int|between:1,10000',
    'smtp_host' => 'required|max:190',
    'smtp_port' => 'required|int|between:1,65535',
    'smtp_encryption' => 'required|in:tls,ssl,none',
    'imap_port' => 'int|between:1,65535',
    'imap_encryption' => 'in:tls,ssl,none',
]);
if (!$existing && (string) ($_POST['smtp_password'] ?? '') === '') {
    $errors['smtp_password'] = 'Password is required.';
}
foreach (['smtp_host', 'imap_host'] as $hostField) {
    $h = trim((string) input($hostField));
    if ($h !== '' && !preg_match('/^[a-z0-9.-]+$/i', $h)) {
        $errors[$hostField] = 'Enter a hostname like smtp.example.com.';
    }
}
if ($errors) {
    keep_old_input();
    flash_errors($errors);
    redirect_back('mail-accounts/index.php');
}

$smtpPass = (string) ($_POST['smtp_password'] ?? '');
$imapPass = (string) ($_POST['imap_password'] ?? '');
$imapHost = trim((string) input('imap_host'));
$data = [
    'name' => trim((string) input('name')),
    'provider' => 'smtp',
    'email' => strtolower(trim((string) input('email'))),
    'from_name' => trim((string) input('from_name')) ?: null,
    'reply_to' => strtolower(trim((string) input('reply_to'))) ?: null,
    'daily_limit' => input_int('daily_limit', 100),
    'smtp_host' => strtolower(trim((string) input('smtp_host'))),
    'smtp_port' => input_int('smtp_port'),
    'smtp_encryption' => (string) input('smtp_encryption'),
    'smtp_username' => trim((string) input('smtp_username')) ?: null,
    'imap_host' => $imapHost !== '' ? strtolower($imapHost) : null,
    'imap_port' => $imapHost !== '' ? input_int('imap_port', 993) : null,
    'imap_encryption' => $imapHost !== '' ? ((string) input('imap_encryption') ?: 'ssl') : null,
    'imap_username' => trim((string) input('imap_username')) ?: null,
    'status' => 'active',
    'last_error' => null,
    'updated_at' => now(),
];
if ($smtpPass !== '') {
    $data['encrypted_smtp_password'] = encrypt_value($smtpPass);
}
if ($imapPass !== '') {
    $data['encrypted_imap_password'] = encrypt_value($imapPass);
} elseif ($smtpPass !== '' && (!$existing || !$existing['encrypted_imap_password'])) {
    $data['encrypted_imap_password'] = encrypt_value($smtpPass); // default: same as SMTP
}
if ($existing && $existing['imap_host'] !== $data['imap_host']) {
    $data['imap_last_uid'] = null;
    $data['imap_uidvalidity'] = null;
}

if ($existing) {
    db_update('mail_accounts', $data, 'id = ? AND workspace_id = ?', [$existing['id'], $ws]);
    $id = (int) $existing['id'];
} else {
    $id = db_insert('mail_accounts', $data + ['workspace_id' => $ws, 'created_at' => now()]);
}

release_limit_deferred_jobs($ws);

// Verify immediately so the user knows the credentials work
$account = q_one('SELECT * FROM mail_accounts WHERE id = ?', [$id]);
[$ok, $msg] = test_mail_account($account);
if ($ok) {
    flash('success', 'Account saved. ' . $msg);
} else {
    q("UPDATE mail_accounts SET status = 'error', last_error = ? WHERE id = ?", [mb_substr($msg, 0, 500), $id]);
    flash('warning', 'Account saved, but the connection test failed: ' . $msg);
}
clear_old_input();
redirect('mail-accounts/index.php');
