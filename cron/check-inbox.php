<?php
/**
 * Every 5 minutes:  *\/5 * * * * php /home/USER/public_html/cron/check-inbox.php
 * Polls IMAP mailboxes / Gmail for replies and bounce notices. No persistent IMAP connection.
 */
require __DIR__ . '/_bootstrap.php';

cron_run('check-inbox', function () {
    $start = microtime(true);
    $summary = [];
    $accounts = q_all(
        "SELECT * FROM mail_accounts WHERE status <> 'disconnected' AND (provider = 'gmail' OR (imap_host IS NOT NULL AND encrypted_imap_password IS NOT NULL))
         ORDER BY COALESCE(inbox_checked_at, '1970-01-01') LIMIT 20"
    );
    foreach ($accounts as $account) {
        if (microtime(true) - $start > CRON_MAX_RUNTIME) {
            break; // remaining accounts go first next run (ordered by last check)
        }
        try {
            $stats = $account['provider'] === 'gmail' ? check_gmail_account($account) : check_imap_account($account);
            $summary[$account['email']] = $stats;
        } catch (Throwable $e) {
            q('UPDATE mail_accounts SET inbox_checked_at = ?, last_error = ? WHERE id = ?', [now(), mb_substr('Inbox: ' . $e->getMessage(), 0, 500), $account['id']]);
            app_log('warning', 'Inbox check failed', ['account' => $account['id'], 'error' => $e->getMessage()]);
            $summary[$account['email']] = 'error: ' . $e->getMessage();
        }
    }
    return $summary ?: 'no mailboxes configured';
});
