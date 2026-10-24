<?php
/**
 * Every minute:  * * * * * php /home/USER/public_html/cron/process-ai-campaigns.php
 * AI Campaigns: researches + writes a few customers' emails with OpenRouter (AI_DRAFT_BATCH per run), then queues
 * the ready ones. The email-queue cron sends them within the normal daily limits.
 */
require __DIR__ . '/_bootstrap.php';

cron_run('ai-campaigns', fn() => ai_campaign_tick());
