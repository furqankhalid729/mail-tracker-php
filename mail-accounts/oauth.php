<?php
/**
 * Google OAuth for Gmail / Workspace.
 *   POST (CSRF) → redirect to Google consent
 *   GET ?code&state → exchange code server-side, store encrypted tokens
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('mail_accounts.manage');
$ws = ws_id();

if (!gmail_enabled()) {
    flash('error', 'Google OAuth is not configured on this server.');
    redirect('mail-accounts/index.php');
}

if (is_post()) {
    verify_csrf();
    $_SESSION['oauth_state'] = random_token(24);
    redirect(gmail_auth_url($_SESSION['oauth_state']));
}

$state = (string) input('state');
if ($state === '' || empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
    unset($_SESSION['oauth_state']);
    flash('error', 'Google sign-in could not be verified. Please try again.');
    redirect('mail-accounts/index.php');
}
unset($_SESSION['oauth_state']);

if (input('error')) {
    flash('error', 'Google connection was cancelled.');
    redirect('mail-accounts/index.php');
}

try {
    $tokens = gmail_exchange_code((string) input('code'));
    $email = gmail_profile_email($tokens['access_token']);
    $existing = q_one("SELECT * FROM mail_accounts WHERE workspace_id = ? AND email = ? AND provider = 'gmail'", [$ws, $email]);

    $data = [
        'provider' => 'gmail',
        'oauth_provider' => 'google',
        'email' => $email,
        'encrypted_access_token' => encrypt_value($tokens['access_token']),
        'oauth_expires_at' => date('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 3600)),
        'status' => 'active',
        'last_error' => null,
        'updated_at' => now(),
    ];
    if (!empty($tokens['refresh_token'])) {
        $data['encrypted_refresh_token'] = encrypt_value($tokens['refresh_token']);
    } elseif (!$existing || !$existing['encrypted_refresh_token']) {
        throw new RuntimeException('Google did not return a refresh token. Remove the app from your Google account permissions and connect again.');
    }

    if ($existing) {
        db_update('mail_accounts', $data, 'id = ?', [$existing['id']]);
    } else {
        db_insert('mail_accounts', $data + [
            'workspace_id' => $ws,
            'name' => 'Gmail ' . $email,
            'from_name' => current_user()['name'],
            'daily_limit' => str_ends_with($email, '@gmail.com') ? 400 : 1500,
            'inbox_checked_at' => now(),
            'created_at' => now(),
        ]);
    }
    flash('success', 'Connected ' . $email . '. Replies will be checked by cron.');
} catch (Throwable $e) {
    app_log('warning', 'Gmail OAuth failed', ['error' => $e->getMessage()]);
    flash('error', 'Could not connect Google: ' . $e->getMessage());
}
redirect('mail-accounts/index.php');
