<?php
/** Test fixtures for AI Campaigns tests. Everything created here is tagged and removed by fx_cleanup(). */

const FX_TAG = 'zz-aitest';

function fx_ws(): int
{
    return (int) q_val('SELECT MIN(id) FROM workspaces');
}

/** A mail account that is "disconnected", so send_message() fails softly and nothing leaves the machine. */
function fx_account(): array
{
    $id = db_insert('mail_accounts', [
        'workspace_id' => fx_ws(), 'name' => FX_TAG, 'email' => FX_TAG . '@example.test',
        'status' => 'disconnected', 'created_at' => now(), 'updated_at' => now(),
    ]);
    return q_one('SELECT * FROM mail_accounts WHERE id = ?', [$id]);
}

function fx_customer(array $over = []): array
{
    static $n = 0;
    $n++;
    $id = db_insert('customers', $over + [
        'workspace_id' => fx_ws(), 'first_name' => 'Test', 'last_name' => "Person$n",
        'email' => FX_TAG . "-$n-" . random_token(4) . '@example.test', 'company' => "Acme $n",
        'website' => "https://acme$n.example", 'status' => 'active', 'source' => FX_TAG,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return q_one('SELECT * FROM customers WHERE id = ?', [$id]);
}

function fx_ai_campaign(array $over = []): array
{
    $id = db_insert('ai_campaigns', $over + [
        'workspace_id' => fx_ws(), 'name' => FX_TAG, 'status' => 'active', 'model' => 'mock/ok',
        'research_prompt' => 'Find competitors of {{company}}', 'subject' => 'Idea for {{company}}',
        'email_template' => 'Hi {{firstName}}, ...', 'web_search' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    return q_one('SELECT * FROM ai_campaigns WHERE id = ?', [$id]);
}

function fx_ai_contact(array $campaign, array $customer, array $over = []): array
{
    $id = db_insert('ai_campaign_contacts', $over + [
        'workspace_id' => $campaign['workspace_id'], 'ai_campaign_id' => $campaign['id'], 'customer_id' => $customer['id'],
        'draft_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    return q_one('SELECT * FROM ai_campaign_contacts WHERE id = ?', [$id]);
}

/** A queued AI-campaign message + pending job, built the way ai_queue_ready() builds it. */
function fx_ai_message(array $campaign, array $customer, array $account): array
{
    $thread = create_thread((int) $campaign['workspace_id'], (int) $customer['id'], null, (int) $account['id'], 'S', (int) $campaign['id']);
    $mid = save_outbound_message([
        'workspace_id' => (int) $campaign['workspace_id'], 'customer' => $customer, 'ai_campaign_id' => (int) $campaign['id'],
        'account' => $account, 'thread_id' => $thread, 'subject' => 'S', 'html' => '<p>x</p>', 'status' => 'queued',
    ]);
    $message = q_one('SELECT * FROM email_messages WHERE id = ?', [$mid]);
    enqueue_message($message);
    return $message;
}

function fx_cleanup(): void
{
    $ws = fx_ws();
    $customers = q_col('SELECT id FROM customers WHERE workspace_id = ? AND source = ?', [$ws, FX_TAG]);
    if ($customers) {
        $in = placeholders($customers);
        q("DELETE j FROM email_jobs j JOIN email_messages m ON m.id = j.email_message_id WHERE m.customer_id IN ($in)", $customers);
        q("DELETE FROM email_events WHERE customer_id IN ($in)", $customers);
        q("DELETE FROM unsubscribe_records WHERE customer_id IN ($in)", $customers);
        q("DELETE FROM email_messages WHERE customer_id IN ($in)", $customers);
        q("DELETE FROM email_threads WHERE customer_id IN ($in)", $customers);
        q("DELETE FROM customers WHERE id IN ($in)", $customers);
    }
    q('DELETE FROM ai_campaigns WHERE workspace_id = ? AND name = ?', [$ws, FX_TAG]);
    q('DELETE FROM mail_accounts WHERE workspace_id = ? AND name = ?', [$ws, FX_TAG]);
}
