<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('mail_accounts.manage');
require_post();

$account = find_or_404('mail_accounts', input_int('id'));

if (input('action') === 'disconnect') {
    // Erase secrets but keep the row so history and campaigns stay linked
    q(
        "UPDATE mail_accounts SET status = 'disconnected', encrypted_smtp_password = NULL, encrypted_imap_password = NULL,
         encrypted_access_token = NULL, encrypted_refresh_token = NULL, oauth_expires_at = NULL, updated_at = ? WHERE id = ?",
        [now(), $account['id']]
    );
    flash('success', $account['email'] . ' disconnected. Edit it and re-enter the password (or reconnect Google) to use it again.');
} else {
    q('DELETE FROM mail_accounts WHERE id = ? AND workspace_id = ?', [$account['id'], ws_id()]);
    flash('success', $account['email'] . ' deleted.');
}
redirect('mail-accounts/index.php');
