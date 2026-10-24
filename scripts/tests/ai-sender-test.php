<?php
// php scripts/tests/ai-sender-test.php — the existing queue/sender treats AI-campaign mail like campaign mail
require __DIR__ . '/_assert.php';
require __DIR__ . '/_fixtures.php';

fx_cleanup();
$account = fx_account();

t('create_thread stores ai id', function () {
    $camp = fx_ai_campaign();
    $cust = fx_customer();
    $tid = create_thread(fx_ws(), (int) $cust['id'], null, null, 'S', (int) $camp['id']);
    eq((int) q_val('SELECT ai_campaign_id FROM email_threads WHERE id = ?', [$tid]), (int) $camp['id']);
});
t('save_outbound_message + enqueue carry ai id', function () use ($account) {
    $camp = fx_ai_campaign();
    $m = fx_ai_message($camp, fx_customer(), $account);
    eq((int) $m['ai_campaign_id'], (int) $camp['id']);
    eq($m['campaign_id'], null);
    eq((int) q_val('SELECT ai_campaign_id FROM email_jobs WHERE email_message_id = ?', [$m['id']]), (int) $camp['id']);
});
t('paused AI campaign: job not claimed', function () use ($account) {
    $camp = fx_ai_campaign(['status' => 'paused']);
    $m = fx_ai_message($camp, fx_customer(), $account);
    $claimed = array_column(claim_jobs(50), 'email_message_id');
    q("UPDATE email_jobs SET status = 'pending', lock_token = NULL, locked_at = NULL WHERE status = 'processing' AND ai_campaign_id IS NOT NULL");
    ok(!in_array($m['id'], $claimed), 'paused job was claimed');
});
t('AI campaign paused before send: job held, not sent', function () use ($account) {
    $camp = fx_ai_campaign();
    $m = fx_ai_message($camp, fx_customer(), $account);
    q("UPDATE ai_campaigns SET status = 'paused' WHERE id = ?", [$camp['id']]);
    process_email_queue(50);
    eq(q_val('SELECT status FROM email_messages WHERE id = ?', [$m['id']]), 'queued');
    $job = q_one('SELECT status, error_message FROM email_jobs WHERE email_message_id = ?', [$m['id']]);
    eq($job['status'], 'pending');
    // Nothing claims it while paused; after resume, the send-time check is what holds a job paused mid-batch
    q("UPDATE email_jobs SET status = 'processing', lock_token = 'zz', locked_at = ? WHERE email_message_id = ?", [now(), $m['id']]);
    eq(job_hold_reason(q_one('SELECT * FROM email_messages WHERE id = ?', [$m['id']])), 'AI campaign not active');
});
t('unsubscribed before send: cancelled', function () use ($account) {
    $camp = fx_ai_campaign();
    $cust = fx_customer();
    $m = fx_ai_message($camp, $cust, $account);
    q("UPDATE customers SET unsubscribed_at = ?, status = 'unsubscribed' WHERE id = ?", [now(), $cust['id']]);
    process_email_queue(50);
    eq(q_val('SELECT status FROM email_messages WHERE id = ?', [$m['id']]), 'cancelled');
});
t('unsubscribe cancels pending AI jobs', function () use ($account) {
    $camp = fx_ai_campaign();
    $cust = fx_customer();
    $m = fx_ai_message($camp, $cust, $account);
    unsubscribe_customer($cust, 'manual');
    eq(q_val('SELECT status FROM email_jobs WHERE email_message_id = ?', [$m['id']]), 'cancelled');
});
t('AI mail counts as campaign mail for the unsubscribe footer', function () {
    eq(is_bulk_mail(['campaign_id' => null, 'ai_campaign_id' => 5, 'in_reply_to' => null]), true);
    eq(is_bulk_mail(['campaign_id' => 3, 'ai_campaign_id' => null, 'in_reply_to' => null]), true);
    eq(is_bulk_mail(['campaign_id' => null, 'ai_campaign_id' => null, 'in_reply_to' => null]), false);
    eq(is_bulk_mail(['campaign_id' => null, 'ai_campaign_id' => 5, 'in_reply_to' => 'abc@x']), false);
});
t('standard one-off message unaffected by suppression', function () use ($account) {
    $cust = fx_customer(['status' => 'unsubscribed', 'unsubscribed_at' => now()]);
    $mid = save_outbound_message(['workspace_id' => fx_ws(), 'customer' => $cust, 'account' => $account, 'subject' => 'S', 'html' => '<p>x</p>', 'status' => 'queued']);
    enqueue_message(q_one('SELECT * FROM email_messages WHERE id = ?', [$mid]));
    process_email_queue(50);
    ok(q_val('SELECT status FROM email_messages WHERE id = ?', [$mid]) !== 'cancelled', 'one-off mail was suppressed');
});

fx_cleanup();
t_done();
