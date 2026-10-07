<?php
/**
 * Every 15 minutes:
 *  - releases jobs stuck in "processing" (crashed or killed runs)
 *  - gives transient failures one extra attempt once their mail account is healthy again
 * Permanent failures (invalid address, 5xx rejections) are never retried automatically.
 */
require __DIR__ . '/_bootstrap.php';

cron_run('retry-failed', function () {
    $released = release_stale_jobs();

    // attempts stays at max_attempts: the claim bumps it to max+1, so a job is auto-retried at most once
    $ids = q_col(
        "SELECT j.email_message_id FROM email_jobs j
         JOIN email_messages m ON m.id = j.email_message_id
         JOIN mail_accounts a ON a.id = m.mail_account_id
         JOIN workspaces w ON w.id = j.workspace_id
         LEFT JOIN customers cu ON cu.id = m.customer_id
         WHERE j.status = 'failed' AND j.is_permanent_failure = 0 AND a.status = 'active'
           AND j.attempts <= w.max_attempts
           AND j.processed_at BETWEEN ? AND ?
           AND (m.campaign_id IS NULL OR (cu.unsubscribed_at IS NULL AND EXISTS (SELECT 1 FROM campaigns c WHERE c.id = m.campaign_id AND c.status = 'active')))
         LIMIT 200",
        [date('Y-m-d H:i:s', time() - 86400), date('Y-m-d H:i:s', time() - 1800)]
    );
    if ($ids) {
        $in = placeholders($ids);
        q("UPDATE email_jobs SET status = 'pending', scheduled_at = ?, lock_token = NULL, locked_at = NULL WHERE email_message_id IN ($in) AND status = 'failed'", [now(), ...$ids]);
        q("UPDATE email_messages SET status = 'queued', updated_at = ? WHERE id IN ($in) AND status = 'failed'", [now(), ...$ids]);
    }
    return ['released_stale' => $released, 'requeued' => count($ids)];
});
