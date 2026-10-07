<?php
/**
 * Every 5 minutes: applies stored webhook events that were not processed at receive time
 * (bursts, timeouts). Processing is idempotent.
 */
require __DIR__ . '/_bootstrap.php';

cron_run('webhooks', function () {
    $start = microtime(true);
    $n = 0;
    foreach (q_col("SELECT id FROM webhook_events WHERE status = 'pending' ORDER BY id LIMIT 1000") as $id) {
        if (microtime(true) - $start > CRON_MAX_RUNTIME) {
            break;
        }
        process_webhook_event((int) $id);
        $n++;
    }
    return ['processed' => $n];
});
