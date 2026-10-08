<?php
/**
 * Every 15 minutes: php /home/USER/public_html/cron/sync-zoom-calls.php
 * Pulls Zoom Phone call history for every connected workspace (first run backfills 90 days, in chunks).
 */
require __DIR__ . '/_bootstrap.php';

cron_run('sync-zoom-calls', function () {
    $started = time();
    $result = [];
    foreach (q_all("SELECT workspace_id FROM zoom_connections WHERE status <> 'disconnected' AND encrypted_refresh_token IS NOT NULL") as $row) {
        $left = CRON_MAX_RUNTIME - (time() - $started);
        if ($left < 5) {
            $result['deferred'][] = (int) $row['workspace_id'];
            continue;
        }
        try {
            $result[$row['workspace_id']] = zoom_sync((int) $row['workspace_id'], $left);
        } catch (Throwable $e) {
            app_log('warning', 'Zoom sync failed', ['workspace' => $row['workspace_id'], 'error' => $e->getMessage()]);
            $result[$row['workspace_id']] = ['error' => $e->getMessage()];
        }
    }
    return $result ?: ['connections' => 0];
});
