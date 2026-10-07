<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_role('admin');
require_post();

$account = find_or_404('mail_accounts', input_int('id'));
if (!rate_limit('mailtest:' . $account['id'], 10, 600)) {
    flash('error', 'Too many tests. Wait a few minutes.');
    redirect('mail-accounts/index.php');
}

if (input('type') === 'email') {
    // Test emails are only sent on this explicit request
    $to = strtolower(trim((string) input('to')));
    if (!is_valid_email($to)) {
        flash('error', 'Enter a valid recipient for the test email.');
        redirect('mail-accounts/index.php');
    }
    [$ok, $msg] = send_test_email($account, $to);
} else {
    [$ok, $msg] = test_mail_account($account);
}

if ($ok) {
    q("UPDATE mail_accounts SET status = 'active', last_error = NULL WHERE id = ? AND status <> 'disconnected'", [$account['id']]);
    flash('success', $msg);
} else {
    q("UPDATE mail_accounts SET status = 'error', last_error = ? WHERE id = ? AND status <> 'disconnected'", [mb_substr($msg, 0, 500), $account['id']]);
    flash('error', 'Connection failed. Reason: ' . $msg);
}
redirect('mail-accounts/index.php');
