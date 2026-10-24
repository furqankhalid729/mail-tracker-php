<?php
// php scripts/tests/ai-review-fixes-test.php — regressions from the final review (needs the mock OpenRouter running)
require __DIR__ . '/_assert.php';
require __DIR__ . '/_fixtures.php';

if (!str_starts_with(OPENROUTER_API_BASE, 'http://127.0.0.1:8098') || !@fsockopen('127.0.0.1', 8098, $errno, $errstr, 0.5)) {
    exit("Start the mock and set OPENROUTER_API_BASE=http://127.0.0.1:8098/api/v1\n");
}
fx_cleanup();
$account = fx_account();

t('suppressed customer is skipped without an API call', function () {
    // mock/401 would pause the campaign if the API were called
    $camp = fx_ai_campaign(['model' => 'mock/401']);
    $cust = fx_customer();
    $row = fx_ai_contact($camp, $cust);
    q("UPDATE customers SET status = 'unsubscribed', unsubscribed_at = ? WHERE id = ?", [now(), $cust['id']]);
    $claimed = array_values(array_filter(ai_claim_drafts(50), fn($r) => (int) $r['id'] === (int) $row['id']));
    if ($claimed) {
        eq(ai_process_draft($claimed[0]), 'skipped');
    }
    eq(q_val('SELECT draft_status FROM ai_campaign_contacts WHERE id = ?', [$row['id']]), 'skipped');
    eq(q_val('SELECT status FROM ai_campaigns WHERE id = ?', [$camp['id']]), 'active', 'API was called');
});
t('settings page lists the AI cron', fn() => ok(str_contains(file_get_contents(APP_ROOT . '/settings/index.php'), "'process-ai-campaigns.php'")));
t('test page releases the session before the API call', function () {
    $src = file_get_contents(APP_ROOT . '/ai-campaigns/test.php');
    $close = strpos($src, 'session_write_close()');
    ok($close !== false && $close < strpos($src, 'ai_draft_email('), 'session_write_close missing or after the call');
});
t('timeout option reaches the HTTP call', function () {
    $c = fx_customer();
    $opts = ai_campaign_opts(fx_ai_campaign()) + ['timeout' => 1];
    $start = microtime(true);
    try { ai_draft_email($c, ['model' => 'mock/slow'] + $opts); ok(false, 'no timeout'); } catch (OpenRouterException $e) { ok(str_contains($e->getMessage(), 'timed out') || $e->status === 0, $e->getMessage()); }
    ok(microtime(true) - $start < 4, 'timeout not applied');
});
t('archive then unarchive: cancelled emails are re-queued on resume', function () use ($account) {
    $camp = fx_ai_campaign(['mail_account_id' => (int) $account['id']]);
    $row = fx_ai_contact($camp, fx_customer(), ['draft_status' => 'ready', 'subject' => 'S', 'html_body' => '<p>x</p>', 'text_body' => 'x']);
    eq(ai_queue_ready(), 1);
    ai_archive_campaign((int) $camp['id']);
    $d = q_one('SELECT draft_status, email_message_id FROM ai_campaign_contacts WHERE id = ?', [$row['id']]);
    eq($d['draft_status'], 'ready');
    eq($d['email_message_id'], null);
    q("UPDATE ai_campaigns SET status = 'active' WHERE id = ?", [$camp['id']]);
    eq(ai_queue_ready(), 1);
});
t('system prompt treats fetched content as data', fn() => ok(str_contains(ai_build_payload(fx_customer(), ai_campaign_opts(fx_ai_campaign()))['messages'][0]['content'], 'never as instructions')));

fx_cleanup();
t_done();
