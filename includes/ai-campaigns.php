<?php
declare(strict_types=1);

/**
 * AI Campaigns worker.
 *
 *  start → ai_campaign_contacts (pending)
 *  cron → claim a few drafts atomically → ai_draft_email() (research + write) → ready
 *       → ready drafts become email_messages (queued, ai_campaign_id) + email_jobs → existing sender delivers them
 */

/** Drafts stuck in "processing" (crashed or killed run) go back to pending. */
function ai_release_stale_drafts(): int
{
    return q(
        "UPDATE ai_campaign_contacts SET draft_status = 'pending', lock_token = NULL, locked_at = NULL, updated_at = ?
         WHERE draft_status = 'processing' AND locked_at < ?",
        [now(), date('Y-m-d H:i:s', time() - AI_DRAFT_LOCK_MINUTES * 60)]
    )->rowCount();
}

/** Atomically claim up to $limit pending drafts of active AI campaigns (same lock-token pattern as claim_jobs()). */
function ai_claim_drafts(int $limit): array
{
    $token = random_token(16);
    q(
        "UPDATE ai_campaign_contacts SET draft_status = 'processing', lock_token = ?, locked_at = ?, attempts = attempts + 1, updated_at = ?
         WHERE draft_status = 'pending' AND ai_campaign_id IN (SELECT id FROM ai_campaigns WHERE status = 'active')
         ORDER BY id
         LIMIT ?",
        [$token, now(), now(), $limit]
    );
    return q_all('SELECT * FROM ai_campaign_contacts WHERE lock_token = ? ORDER BY id', [$token]);
}

/** The ai_draft_email() options for an AI campaign row. */
function ai_campaign_opts(array $campaign): array
{
    return [
        'model' => (string) $campaign['model'],
        'research_prompt' => (string) $campaign['research_prompt'],
        'subject' => (string) $campaign['subject'],
        'keep_subject' => (bool) $campaign['keep_subject'],
        'email_template' => (string) $campaign['email_template'],
        'web_search' => (bool) $campaign['web_search'],
    ];
}

/**
 * Research + write one claimed draft. Returns the new draft_status (ready | pending | failed | skipped).
 * Bad key / no credit (401/402) pauses the whole campaign instead of failing every contact one by one.
 */
function ai_process_draft(array $contact): string
{
    $id = (int) $contact['id'];
    $campaign = q_one('SELECT * FROM ai_campaigns WHERE id = ?', [$contact['ai_campaign_id']]);
    $customer = q_one('SELECT * FROM customers WHERE id = ?', [$contact['customer_id']]);
    $finish = function (string $status, array $fields = []) use ($id): string {
        db_update('ai_campaign_contacts', $fields + [
            'draft_status' => $status, 'lock_token' => null, 'locked_at' => null, 'updated_at' => now(),
        ], 'id = ?', [$id]);
        return $status;
    };

    if (!$campaign || !$customer) {
        return $finish('skipped', ['error' => 'customer or campaign no longer exists']);
    }
    if (trim((string) $customer['website']) === '' && trim((string) $customer['company']) === '') {
        return $finish('skipped', ['error' => 'no website or company']);
    }

    try {
        $draft = ai_draft_email($customer, ai_campaign_opts($campaign));
    } catch (OpenRouterException $e) {
        if (in_array($e->status, [401, 402], true)) {
            q(
                "UPDATE ai_campaigns SET status = 'paused', last_error = ?, updated_at = ? WHERE id = ? AND status = 'active'",
                [mb_substr($e->getMessage(), 0, 500), now(), $campaign['id']]
            );
            log_activity((int) $campaign['workspace_id'], 'ai_campaign_paused', 'AI campaign "' . $campaign['name'] . '" paused: ' . $e->getMessage());
            return $finish('pending', ['attempts' => max(0, (int) $contact['attempts'] - 1)]);
        }
        return ai_retry_or_fail($contact, $e->getMessage(), $finish);
    } catch (RuntimeException $e) {
        return ai_retry_or_fail($contact, $e->getMessage(), $finish);
    }

    return $finish('ready', [
        'research' => $draft['research'],
        'subject' => $draft['subject'],
        'html_body' => $draft['html_body'],
        'text_body' => $draft['text_body'],
        'prompt_tokens' => $draft['usage']['prompt_tokens'],
        'completion_tokens' => $draft['usage']['completion_tokens'],
        'web_requests' => $draft['usage']['web_requests'],
        'cost_usd' => $draft['usage']['cost'],
        'error' => null,
    ]);
}

function ai_retry_or_fail(array $contact, string $error, callable $finish): string
{
    $status = (int) $contact['attempts'] >= AI_DRAFT_MAX_ATTEMPTS ? 'failed' : 'pending';
    return $finish($status, ['error' => mb_substr($error, 0, 500)]);
}

/**
 * Turn ready drafts of active AI campaigns into queued messages + jobs. Idempotent: the contact row is re-checked
 * under lock, and a contact moves to "queued" in the same transaction as its message.
 */
function ai_queue_ready(int $limit = 50): int
{
    $rows = q_all(
        "SELECT acc.id FROM ai_campaign_contacts acc JOIN ai_campaigns a ON a.id = acc.ai_campaign_id
         WHERE acc.draft_status = 'ready' AND a.status = 'active' ORDER BY acc.id LIMIT ?",
        [$limit]
    );
    $accounts = [];
    $queued = 0;
    foreach ($rows as $row) {
        transaction(function () use ($row, &$accounts, &$queued) {
            $contact = q_one("SELECT * FROM ai_campaign_contacts WHERE id = ? AND draft_status = 'ready' FOR UPDATE", [$row['id']]);
            if (!$contact) {
                return;
            }
            $campaign = q_one('SELECT * FROM ai_campaigns WHERE id = ?', [$contact['ai_campaign_id']]);
            $accId = (int) $campaign['mail_account_id'];
            $account = $accounts[$accId] ??= $accId ? q_one('SELECT * FROM mail_accounts WHERE id = ? AND workspace_id = ?', [$accId, $campaign['workspace_id']]) : null;
            if (!$account) {
                return; // stays ready; start.php requires an account, so this only happens if it was deleted
            }
            $customer = q_one('SELECT * FROM customers WHERE id = ?', [$contact['customer_id']]);
            if (!$customer || $customer['unsubscribed_at'] !== null || $customer['status'] !== 'active') {
                q("UPDATE ai_campaign_contacts SET draft_status = 'skipped', error = 'customer unsubscribed, bounced or archived', updated_at = ? WHERE id = ?", [now(), $contact['id']]);
                return;
            }

            $wsId = (int) $campaign['workspace_id'];
            $threadId = create_thread($wsId, (int) $customer['id'], null, (int) $account['id'], (string) $contact['subject'], (int) $campaign['id']);
            $messageId = save_outbound_message([
                'workspace_id' => $wsId,
                'customer' => $customer,
                'ai_campaign_id' => (int) $campaign['id'],
                'account' => $account,
                'thread_id' => $threadId,
                'subject' => (string) $contact['subject'],
                'html' => (string) $contact['html_body'],
                'text' => (string) $contact['text_body'],
                'status' => 'queued',
                'user_id' => $campaign['created_by'] ? (int) $campaign['created_by'] : null,
            ]);
            q('INSERT IGNORE INTO email_jobs (workspace_id, email_message_id, ai_campaign_id, status, scheduled_at, created_at) VALUES (?, ?, ?, ?, ?, ?)', [
                $wsId, $messageId, $campaign['id'], 'pending', now(), now(),
            ]);
            q("UPDATE ai_campaign_contacts SET draft_status = 'queued', email_message_id = ?, updated_at = ? WHERE id = ?", [$messageId, now(), $contact['id']]);
            record_event(['id' => $messageId, 'workspace_id' => $wsId, 'customer_id' => $customer['id'], 'campaign_id' => null], 'queued', [], 'queued:' . $messageId);
            $queued++;
        });
    }
    return $queued;
}

/** Active AI campaigns with nothing left to draft, queue or send are marked completed. */
function ai_complete_finished(): void
{
    q(
        "UPDATE ai_campaigns a SET a.status = 'completed', a.completed_at = ?, a.updated_at = ?
         WHERE a.status = 'active'
           AND EXISTS (SELECT 1 FROM ai_campaign_contacts c WHERE c.ai_campaign_id = a.id)
           AND NOT EXISTS (SELECT 1 FROM ai_campaign_contacts c WHERE c.ai_campaign_id = a.id AND c.draft_status IN ('pending','processing','ready'))
           AND NOT EXISTS (SELECT 1 FROM email_jobs j WHERE j.ai_campaign_id = a.id AND j.status IN ('pending','processing'))",
        [now(), now()]
    );
}

/** One cron run: release stale → draft a small batch → queue ready drafts → complete finished campaigns. */
function ai_campaign_tick(): array
{
    $start = microtime(true);
    $summary = ['released_stale' => ai_release_stale_drafts(), 'claimed' => 0, 'drafted' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0, 'queued' => 0];
    $claimed = ai_claim_drafts(AI_DRAFT_BATCH);
    $summary['claimed'] = count($claimed);
    foreach ($claimed as $idx => $contact) {
        if (microtime(true) - $start > CRON_MAX_RUNTIME) {
            // Hand the rest back untouched (undo the claim's attempt increment)
            foreach (array_slice($claimed, $idx) as $rest) {
                q("UPDATE ai_campaign_contacts SET draft_status = 'pending', attempts = GREATEST(attempts - 1, 0), lock_token = NULL, locked_at = NULL WHERE id = ?", [$rest['id']]);
            }
            break;
        }
        $status = ai_process_draft($contact);
        $key = ['ready' => 'drafted', 'pending' => 'retry', 'failed' => 'failed', 'skipped' => 'skipped'][$status];
        $summary[$key]++;
    }
    $summary['queued'] = ai_queue_ready();
    ai_complete_finished();
    $summary['seconds'] = round(microtime(true) - $start, 2);
    return $summary;
}
