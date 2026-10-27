<?php
// php scripts/tests/schema-test.php — AI Campaigns schema (migration 013) and config
require __DIR__ . '/_assert.php';

t('ai tables exist', function () {
    ok((bool) q_val("SHOW TABLES LIKE 'ai_campaigns'"), 'ai_campaigns');
    ok((bool) q_val("SHOW TABLES LIKE 'ai_campaign_contacts'"), 'ai_campaign_contacts');
});
t('lock_token and last_error columns', function () {
    ok((bool) q_one("SHOW COLUMNS FROM ai_campaign_contacts LIKE 'lock_token'"));
    ok((bool) q_one("SHOW COLUMNS FROM ai_campaigns LIKE 'last_error'"));
});
t('email_messages.ai_campaign_id', fn() => ok((bool) q_one("SHOW COLUMNS FROM email_messages LIKE 'ai_campaign_id'")));
t('email_jobs.ai_campaign_id', fn() => ok((bool) q_one("SHOW COLUMNS FROM email_jobs LIKE 'ai_campaign_id'")));
t('email_threads.ai_campaign_id', fn() => ok((bool) q_one("SHOW COLUMNS FROM email_threads LIKE 'ai_campaign_id'")));
t('config defaults', function () {
    ok(OPENROUTER_DEFAULT_MODEL !== '');
    ok(OPENROUTER_API_BASE !== '' && !str_ends_with(OPENROUTER_API_BASE, '/'));
    eq(AI_DRAFT_BATCH, 3);
    eq(AI_DRAFT_MAX_ATTEMPTS, 3);
    eq(AI_DRAFT_LOCK_MINUTES, 10);
});
t_done();
