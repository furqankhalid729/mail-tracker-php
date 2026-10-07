<?php
declare(strict_types=1);

/**
 * MySQL-backed email queue.
 *
 *  campaign start → email_messages (queued) + email_jobs (pending)
 *  cron → claim a small batch atomically → checks → send → completed / retry / failed
 */

/**
 * Turn not-yet-queued contacts of an active campaign into personalised messages + jobs.
 * Idempotent: contacts whose last_email_id is set are skipped; UNIQUE(email_message_id) on jobs.
 * Returns the number of messages queued in this call.
 */
function queue_campaign_contacts(int $campaignId, int $limit = QUEUE_BUILD_CHUNK): int
{
    $campaign = q_one('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
    if (!$campaign || $campaign['status'] !== 'active') {
        return 0;
    }
    $template = $campaign['template_id'] ? q_one('SELECT * FROM email_templates WHERE id = ? AND workspace_id = ?', [$campaign['template_id'], $campaign['workspace_id']]) : null;
    $account = $campaign['mail_account_id'] ? q_one('SELECT * FROM mail_accounts WHERE id = ? AND workspace_id = ?', [$campaign['mail_account_id'], $campaign['workspace_id']]) : null;
    if (!$template || !$account) {
        return 0;
    }

    $contacts = q_all(
        "SELECT cc.id AS cc_id, c.* FROM campaign_contacts cc
         JOIN customers c ON c.id = cc.customer_id
         WHERE cc.campaign_id = ? AND cc.last_email_id IS NULL AND cc.system_status IS NULL
           AND c.unsubscribed_at IS NULL AND c.status = 'active'
         ORDER BY cc.id LIMIT ?",
        [$campaignId, $limit]
    );
    $queued = 0;
    foreach ($contacts as $customer) {
        transaction(function () use ($campaign, $template, $account, $customer, &$queued) {
            // Re-check under lock so two concurrent builders can't double-queue a contact
            $locked = q_one('SELECT last_email_id FROM campaign_contacts WHERE id = ? FOR UPDATE', [$customer['cc_id']]);
            if (!$locked || $locked['last_email_id']) {
                return;
            }
            $subject = render_vars((string) $template['subject'], $customer);
            $threadId = create_thread((int) $campaign['workspace_id'], (int) $customer['id'], (int) $campaign['id'], (int) $account['id'], $subject);
            $messageId = save_outbound_message([
                'workspace_id' => (int) $campaign['workspace_id'],
                'customer' => $customer,
                'campaign_id' => (int) $campaign['id'],
                'account' => $account,
                'thread_id' => $threadId,
                'subject' => $subject,
                'html' => render_vars((string) $template['html_body'], $customer, true),
                'text' => render_vars((string) $template['text_body'], $customer),
                'status' => 'queued',
            ]);
            q('INSERT IGNORE INTO email_jobs (workspace_id, email_message_id, campaign_id, status, scheduled_at, created_at) VALUES (?, ?, ?, ?, ?, ?)', [
                $campaign['workspace_id'], $messageId, $campaign['id'], 'pending', now(), now(),
            ]);
            q("UPDATE campaign_contacts SET last_email_id = ?, system_status = 'queued', updated_at = ? WHERE id = ?", [$messageId, now(), $customer['cc_id']]);
            $message = ['id' => $messageId, 'workspace_id' => $campaign['workspace_id'], 'customer_id' => $customer['id'], 'campaign_id' => $campaign['id']];
            record_event($message, 'queued', [], 'queued:' . $messageId);
            $queued++;
        });
    }
    return $queued;
}

/** How many contacts of a campaign still need a message built. */
function campaign_unqueued_count(int $campaignId): int
{
    return (int) q_val(
        "SELECT COUNT(*) FROM campaign_contacts cc JOIN customers c ON c.id = cc.customer_id
         WHERE cc.campaign_id = ? AND cc.last_email_id IS NULL AND cc.system_status IS NULL AND c.unsubscribed_at IS NULL AND c.status = 'active'",
        [$campaignId]
    );
}

/** Queue a single already-saved message (manual "send later"/retry). */
function enqueue_message(array $message, ?string $at = null): void
{
    q(
        "INSERT INTO email_jobs (workspace_id, email_message_id, campaign_id, status, scheduled_at, created_at) VALUES (?, ?, ?, 'pending', ?, ?)
         ON DUPLICATE KEY UPDATE status = 'pending', attempts = 0, scheduled_at = VALUES(scheduled_at), error_message = NULL, is_permanent_failure = 0, locked_at = NULL",
        [$message['workspace_id'], $message['id'], $message['campaign_id'], $at ?? now(), now()]
    );
    q("UPDATE email_messages SET status = 'queued', queued_at = ?, updated_at = ? WHERE id = ?", [now(), now(), $message['id']]);
}

/** After limits are raised, jobs parked until tomorrow by a daily limit become due again. */
function release_limit_deferred_jobs(int $wsId): void
{
    q(
        "UPDATE email_jobs SET scheduled_at = ?, error_message = NULL
         WHERE workspace_id = ? AND status = 'pending' AND error_message LIKE '%daily limit reached'",
        [now(), $wsId]
    );
}

/** Jobs stuck in "processing" (crashed/killed cron) become pending again. */
function release_stale_jobs(): int
{
    return q(
        "UPDATE email_jobs SET status = 'pending', locked_at = NULL, lock_token = NULL, scheduled_at = ?
         WHERE status = 'processing' AND locked_at < ?",
        [now(), date('Y-m-d H:i:s', time() - JOB_LOCK_TIMEOUT_MINUTES * 60)]
    )->rowCount();
}

/**
 * Atomically claim up to $limit due jobs. A single UPDATE ... LIMIT with a unique lock token means two
 * overlapping cron runs can never claim the same row (InnoDB row locks + re-check of status='pending').
 */
function claim_jobs(int $limit): array
{
    $token = random_token(16);
    q(
        "UPDATE email_jobs SET status = 'processing', lock_token = ?, locked_at = ?, attempts = attempts + 1
         WHERE status = 'pending' AND scheduled_at <= ?
           AND (campaign_id IS NULL OR campaign_id IN (SELECT id FROM campaigns WHERE status = 'active'))
         ORDER BY scheduled_at, id
         LIMIT ?",
        [$token, now(), now(), $limit]
    );
    return q_all('SELECT * FROM email_jobs WHERE lock_token = ? ORDER BY scheduled_at, id', [$token]);
}

function complete_job(int $jobId, string $status = 'completed', ?string $error = null, bool $permanent = false): void
{
    q(
        'UPDATE email_jobs SET status = ?, processed_at = ?, error_message = ?, is_permanent_failure = ?, lock_token = NULL WHERE id = ?',
        [$status, now(), $error ? mb_substr($error, 0, 500) : null, $permanent ? 1 : 0, $jobId]
    );
}

/** Put a claimed job back without counting the attempt (limits reached, runtime exceeded). */
function release_job(int $jobId, string $at, ?string $reason = null): void
{
    q(
        "UPDATE email_jobs SET status = 'pending', lock_token = NULL, locked_at = NULL, attempts = GREATEST(attempts - 1, 0), scheduled_at = ?, error_message = ? WHERE id = ?",
        [$at, $reason, $jobId]
    );
}

function sent_today_for_account(int $accountId): int
{
    return (int) q_val("SELECT COUNT(*) FROM email_messages WHERE mail_account_id = ? AND direction = 'outbound' AND sent_at >= ?", [$accountId, date('Y-m-d 00:00:00')]);
}

function sent_today_for_workspace(int $wsId): int
{
    return (int) q_val("SELECT COUNT(*) FROM email_messages WHERE workspace_id = ? AND direction = 'outbound' AND sent_at >= ?", [$wsId, date('Y-m-d 00:00:00')]);
}

/**
 * One cron pass of the email queue. Short-lived by design: bounded batch, bounded runtime.
 * Returns a summary array for logging.
 */
function process_email_queue(?int $limitOverride = null): array
{
    $start = microtime(true);
    $summary = ['released_stale' => release_stale_jobs(), 'built' => 0, 'claimed' => 0, 'sent' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0, 'deferred' => 0];

    // Build messages for active campaigns that still have unqueued contacts (bounded per run)
    foreach (q_col("SELECT id FROM campaigns WHERE status = 'active'") as $cid) {
        if (microtime(true) - $start > CRON_MAX_RUNTIME / 3) {
            break;
        }
        $summary['built'] += queue_campaign_contacts((int) $cid, QUEUE_BUILD_CHUNK);
    }

    // Batch size: the smallest per-run setting of workspaces with work, capped hard at 100
    $limit = $limitOverride ?? (int) (q_val(
        "SELECT MAX(w.emails_per_run) FROM workspaces w WHERE EXISTS (SELECT 1 FROM email_jobs j WHERE j.workspace_id = w.id AND j.status = 'pending')"
    ) ?: 20);
    $limit = max(1, min(100, $limit));

    $jobs = claim_jobs($limit);
    $summary['claimed'] = count($jobs);

    $workspaces = [];
    $accountCounts = [];
    $workspaceCounts = [];
    $perWorkspaceRun = [];
    $tomorrow = date('Y-m-d 00:05:00', strtotime('+1 day'));

    foreach ($jobs as $idx => $job) {
        // Stop early on long runs; hand the remaining claimed jobs back
        if (microtime(true) - $start > CRON_MAX_RUNTIME) {
            foreach (array_slice($jobs, $idx) as $rest) {
                release_job((int) $rest['id'], now(), null);
                $summary['deferred']++;
            }
            break;
        }

        $wsId = (int) $job['workspace_id'];
        $ws = $workspaces[$wsId] ??= q_one('SELECT * FROM workspaces WHERE id = ?', [$wsId]);
        $message = q_one('SELECT * FROM email_messages WHERE id = ?', [$job['email_message_id']]);

        if (!$message || in_array($message['status'], ['sent', 'delivered', 'opened', 'clicked', 'replied'], true)) {
            complete_job((int) $job['id']); // already sent → idempotent completion
            $summary['skipped']++;
            continue;
        }
        if ($message['status'] === 'cancelled') {
            complete_job((int) $job['id'], 'cancelled', 'Message cancelled');
            $summary['skipped']++;
            continue;
        }

        // Per-workspace batch size
        $perWorkspaceRun[$wsId] = ($perWorkspaceRun[$wsId] ?? 0) + 1;
        if ($perWorkspaceRun[$wsId] > (int) $ws['emails_per_run']) {
            release_job((int) $job['id'], now());
            $summary['deferred']++;
            continue;
        }

        // Suppression: unsubscribed / bounced recipients never receive campaign mail
        $customer = $message['customer_id'] ? q_one('SELECT id, status, unsubscribed_at FROM customers WHERE id = ?', [$message['customer_id']]) : null;
        if ($message['campaign_id'] && (!$customer || $customer['unsubscribed_at'] !== null || in_array($customer['status'], ['unsubscribed', 'bounced'], true))) {
            q("UPDATE email_messages SET status = 'cancelled', error_message = 'Recipient suppressed (unsubscribed or bounced)', updated_at = ? WHERE id = ?", [now(), $message['id']]);
            complete_job((int) $job['id'], 'cancelled', 'Recipient suppressed');
            $summary['skipped']++;
            continue;
        }

        // Campaign must still be active at send time
        if ($message['campaign_id']) {
            $cstatus = q_val('SELECT status FROM campaigns WHERE id = ?', [$message['campaign_id']]);
            if ($cstatus !== 'active') {
                release_job((int) $job['id'], date('Y-m-d H:i:s', time() + 300), 'Campaign not active');
                $summary['deferred']++;
                continue;
            }
        }

        // Daily limits: workspace and mail account
        $accId = (int) $message['mail_account_id'];
        $workspaceCounts[$wsId] ??= sent_today_for_workspace($wsId);
        if ($workspaceCounts[$wsId] >= (int) $ws['daily_limit']) {
            release_job((int) $job['id'], $tomorrow, 'Workspace daily limit reached');
            // Park the rest of today's backlog too, so later runs don't keep claiming and releasing it
            q(
                "UPDATE email_jobs SET scheduled_at = ?, error_message = 'Workspace daily limit reached'
                 WHERE workspace_id = ? AND status = 'pending' AND scheduled_at < ?",
                [$tomorrow, $wsId, $tomorrow]
            );
            $summary['deferred']++;
            continue;
        }
        if ($accId) {
            $accLimit = (int) q_val('SELECT daily_limit FROM mail_accounts WHERE id = ?', [$accId]);
            $accountCounts[$accId] ??= sent_today_for_account($accId);
            if ($accountCounts[$accId] >= $accLimit) {
                release_job((int) $job['id'], $tomorrow, 'Mail account daily limit reached');
                q(
                    "UPDATE email_jobs j JOIN email_messages m ON m.id = j.email_message_id
                     SET j.scheduled_at = ?, j.error_message = 'Mail account daily limit reached'
                     WHERE m.mail_account_id = ? AND j.status = 'pending' AND j.scheduled_at < ?",
                    [$tomorrow, $accId, $tomorrow]
                );
                $summary['deferred']++;
                continue;
            }
        }

        $result = send_message((int) $message['id'], true);
        if ($result['ok']) {
            complete_job((int) $job['id']);
            $summary['sent']++;
            $workspaceCounts[$wsId]++;
            if ($accId) {
                $accountCounts[$accId]++;
            }
        } else {
            $attempts = (int) $job['attempts']; // already incremented when the job was claimed
            $maxAttempts = max(1, (int) $ws['max_attempts']);
            if ($result['permanent'] || $attempts >= $maxAttempts) {
                complete_job((int) $job['id'], 'failed', $result['error'], $result['permanent']);
                fail_message((int) $message['id'], (string) $result['error']);
                $summary['failed']++;
            } else {
                $delay = RETRY_DELAYS[min($attempts - 1, count(RETRY_DELAYS) - 1)];
                q(
                    "UPDATE email_jobs SET status = 'pending', lock_token = NULL, locked_at = NULL, scheduled_at = ?, error_message = ? WHERE id = ?",
                    [date('Y-m-d H:i:s', time() + $delay * 60), $result['error'], $job['id']]
                );
                $summary['retry']++;
            }
        }

        $delayMs = (int) $ws['delay_ms'];
        if ($delayMs > 0 && $idx < count($jobs) - 1) {
            usleep(min($delayMs, 10000) * 1000);
        }
    }

    close_mailers();
    complete_finished_campaigns();
    $summary['seconds'] = round(microtime(true) - $start, 2);
    return $summary;
}

/** Active campaigns with nothing left to build or send are marked completed. */
function complete_finished_campaigns(): void
{
    $rows = q_all(
        "SELECT c.id, c.workspace_id, c.name FROM campaigns c
         WHERE c.status = 'active'
           AND NOT EXISTS (SELECT 1 FROM email_jobs j WHERE j.campaign_id = c.id AND j.status IN ('pending','processing'))
           AND NOT EXISTS (
               SELECT 1 FROM campaign_contacts cc JOIN customers cu ON cu.id = cc.customer_id
               WHERE cc.campaign_id = c.id AND cc.last_email_id IS NULL AND cc.system_status IS NULL
                 AND cu.unsubscribed_at IS NULL AND cu.status = 'active')
           AND EXISTS (SELECT 1 FROM email_jobs j2 WHERE j2.campaign_id = c.id)"
    );
    foreach ($rows as $c) {
        q("UPDATE campaigns SET status = 'completed', completed_at = ?, updated_at = ? WHERE id = ? AND status = 'active'", [now(), now(), $c['id']]);
        log_activity((int) $c['workspace_id'], 'campaign_completed', 'Campaign "' . $c['name'] . '" finished sending', null, (int) $c['id']);
    }
}

/** Re-queue failed jobs that failed for transient reasons (e.g. after fixing SMTP credentials). */
function retry_failed_jobs(?int $wsId = null, ?int $campaignId = null, int $withinHours = 72): int
{
    $where = "j.status = 'failed' AND j.is_permanent_failure = 0 AND j.processed_at >= ?";
    $params = [date('Y-m-d H:i:s', time() - $withinHours * 3600)];
    if ($wsId) {
        $where .= ' AND j.workspace_id = ?';
        $params[] = $wsId;
    }
    if ($campaignId) {
        $where .= ' AND j.campaign_id = ?';
        $params[] = $campaignId;
    }
    $ids = q_col("SELECT j.email_message_id FROM email_jobs j WHERE $where LIMIT 1000", $params);
    if (!$ids) {
        return 0;
    }
    q("UPDATE email_jobs SET status = 'pending', attempts = 0, scheduled_at = ?, error_message = NULL, lock_token = NULL WHERE email_message_id IN (" . placeholders($ids) . ')', [now(), ...$ids]);
    q("UPDATE email_messages SET status = 'queued', error_message = NULL, updated_at = ? WHERE id IN (" . placeholders($ids) . ") AND status = 'failed'", [now(), ...$ids]);
    q("UPDATE campaign_contacts SET system_status = 'queued' WHERE last_email_id IN (" . placeholders($ids) . ") AND system_status = 'failed'", $ids);
    return count($ids);
}
