<?php
// php scripts/tests/ai-campaigns-test.php — AI campaign draft worker + queueing (needs the mock OpenRouter running).
// OPENROUTER_API_BASE=http://127.0.0.1:8098/api/v1 php scripts/tests/ai-campaigns-test.php
require __DIR__ . '/_assert.php';
require __DIR__ . '/_fixtures.php';

if (!str_starts_with(OPENROUTER_API_BASE, 'http://127.0.0.1:8098') || !@fsockopen('127.0.0.1', 8098, $errno, $errstr, 0.5)) {
    exit("Start the mock and set OPENROUTER_API_BASE=http://127.0.0.1:8098/api/v1\n");
}
fx_cleanup();
$account = fx_account();

/** Claim drafts belonging to this campaign only (other tests' rows are left alone by cleanup in between). */
function claim_for(int $cid): array
{
    return array_values(array_filter(ai_claim_drafts(50), fn($r) => (int) $r['ai_campaign_id'] === $cid));
}
function draft(int $id): array
{
    return q_one('SELECT * FROM ai_campaign_contacts WHERE id = ?', [$id]);
}

t('claim only active campaigns', function () {
    $paused = fx_ai_campaign(['status' => 'paused']);
    fx_ai_contact($paused, fx_customer());
    eq(claim_for((int) $paused['id']), []);
});
t('double claim is exclusive', function () {
    $camp = fx_ai_campaign();
    fx_ai_contact($camp, fx_customer());
    fx_ai_contact($camp, fx_customer());
    eq(count(claim_for((int) $camp['id'])), 2);
    eq(count(claim_for((int) $camp['id'])), 0);
});
t('process → ready with usage', function () {
    $camp = fx_ai_campaign();
    $row = fx_ai_contact($camp, fx_customer(['company' => 'Lee Plumbing']));
    [$claimed] = claim_for((int) $camp['id']);
    eq(ai_process_draft($claimed), 'ready');
    $d = draft((int) $row['id']);
    eq($d['subject'], 'Quick idea for Lee Plumbing');
    ok($d['research'] !== '' && $d['html_body'] !== '' && $d['text_body'] !== '');
    eq((float) $d['cost_usd'], 0.0021);
    eq((int) $d['prompt_tokens'], 1200);
    eq((int) $d['web_requests'], 2);
    eq($d['lock_token'], null);
});
t('keep_subject uses the campaign subject', function () {
    $camp = fx_ai_campaign(['keep_subject' => 1]);
    $row = fx_ai_contact($camp, fx_customer(['company' => 'Zed Co']));
    [$claimed] = claim_for((int) $camp['id']);
    ai_process_draft($claimed);
    eq(draft((int) $row['id'])['subject'], 'Idea for Zed Co');
});
t('no website or company → skipped', function () {
    $camp = fx_ai_campaign();
    $row = fx_ai_contact($camp, fx_customer(['company' => null, 'website' => null]));
    [$claimed] = claim_for((int) $camp['id']);
    eq(ai_process_draft($claimed), 'skipped');
    eq(draft((int) $row['id'])['error'], 'no website or company');
});
t('website only still drafts', function () {
    $camp = fx_ai_campaign();
    fx_ai_contact($camp, fx_customer(['company' => null]));
    [$claimed] = claim_for((int) $camp['id']);
    eq(ai_process_draft($claimed), 'ready');
});
t('prose reply retried then failed', function () {
    $camp = fx_ai_campaign(['model' => 'mock/prose']);
    $row = fx_ai_contact($camp, fx_customer());
    $statuses = [];
    for ($i = 0; $i < AI_DRAFT_MAX_ATTEMPTS; $i++) {
        [$claimed] = claim_for((int) $camp['id']);
        $statuses[] = ai_process_draft($claimed);
    }
    eq($statuses, ['pending', 'pending', 'failed']);
    eq(draft((int) $row['id'])['error'], 'AI reply was not valid JSON');
});
t('402 pauses campaign, keeps attempts', function () {
    $camp = fx_ai_campaign(['model' => 'mock/402']);
    $row = fx_ai_contact($camp, fx_customer());
    [$claimed] = claim_for((int) $camp['id']);
    eq(ai_process_draft($claimed), 'pending');
    $c = q_one('SELECT status, last_error FROM ai_campaigns WHERE id = ?', [$camp['id']]);
    eq($c['status'], 'paused');
    ok(str_contains((string) $c['last_error'], '402'), (string) $c['last_error']);
    eq((int) draft((int) $row['id'])['attempts'], 0);
});
t('queue ready creates message+job once', function () {
    $camp = fx_ai_campaign(['mail_account_id' => (int) q_val('SELECT id FROM mail_accounts WHERE name = ?', [FX_TAG])]);
    $row = fx_ai_contact($camp, fx_customer());
    [$claimed] = claim_for((int) $camp['id']);
    ai_process_draft($claimed);
    ok(ai_queue_ready() >= 1);
    eq(ai_queue_ready(), 0);
    $cid = (int) $camp['id'];
    eq((int) q_val('SELECT COUNT(*) FROM email_jobs WHERE ai_campaign_id = ?', [$cid]), 1);
    $m = q_one('SELECT * FROM email_messages WHERE ai_campaign_id = ?', [$cid]);
    eq($m['campaign_id'], null);
    eq($m['status'], 'queued');
    eq($m['subject'], draft((int) $row['id'])['subject']);
    $d = draft((int) $row['id']);
    eq($d['draft_status'], 'queued');
    eq((int) $d['email_message_id'], (int) $m['id']);
    eq((int) q_val('SELECT ai_campaign_id FROM email_threads WHERE id = ?', [$m['thread_id']]), $cid);
});
t('ready draft of unsubscribed customer is not queued', function () {
    $camp = fx_ai_campaign(['mail_account_id' => (int) q_val('SELECT id FROM mail_accounts WHERE name = ?', [FX_TAG])]);
    $cust = fx_customer();
    $row = fx_ai_contact($camp, $cust, ['draft_status' => 'ready', 'subject' => 'S', 'html_body' => '<p>x</p>', 'text_body' => 'x']);
    q("UPDATE customers SET status = 'unsubscribed', unsubscribed_at = ? WHERE id = ?", [now(), $cust['id']]);
    ai_queue_ready();
    eq(draft((int) $row['id'])['draft_status'], 'skipped');
});
t('stale processing released', function () {
    $camp = fx_ai_campaign();
    $row = fx_ai_contact($camp, fx_customer(), ['draft_status' => 'processing', 'lock_token' => 'old', 'locked_at' => date('Y-m-d H:i:s', time() - 11 * 60)]);
    ok(ai_release_stale_drafts() >= 1);
    eq(draft((int) $row['id'])['draft_status'], 'pending');
});
t('campaign completes when everything is sent or done', function () {
    $camp = fx_ai_campaign();
    fx_ai_contact($camp, fx_customer(), ['draft_status' => 'failed']);
    $m = fx_ai_message($camp, fx_customer(), q_one('SELECT * FROM mail_accounts WHERE name = ?', [FX_TAG]));
    ai_complete_finished();
    eq(q_val('SELECT status FROM ai_campaigns WHERE id = ?', [$camp['id']]), 'active', 'job still pending');
    q("UPDATE email_jobs SET status = 'completed' WHERE email_message_id = ?", [$m['id']]);
    ai_complete_finished();
    eq(q_val('SELECT status FROM ai_campaigns WHERE id = ?', [$camp['id']]), 'completed');
});
t('tick runs end to end', function () {
    $camp = fx_ai_campaign(['mail_account_id' => (int) q_val('SELECT id FROM mail_accounts WHERE name = ?', [FX_TAG])]);
    fx_ai_contact($camp, fx_customer());
    $s = ai_campaign_tick();
    ok($s['drafted'] >= 1 && $s['queued'] >= 1, json_encode($s));
});

fx_cleanup();
t_done();
