<?php
/**
 * Daily:  30 3 * * * php /home/USER/public_html/cron/cleanup.php
 * Prunes housekeeping tables, applies IP retention, removes abandoned import files and old logs.
 */
require __DIR__ . '/_bootstrap.php';

cron_run('cleanup', function () {
    $r = [];
    $r['rate_limits'] = q('DELETE FROM rate_limits WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)])->rowCount();
    $r['password_resets'] = q('DELETE FROM password_resets WHERE expires_at < ? OR used_at IS NOT NULL', [date('Y-m-d H:i:s', time() - 86400)])->rowCount();
    $r['webhook_events'] = q("DELETE FROM webhook_events WHERE status IN ('processed','ignored') AND created_at < ?", [date('Y-m-d H:i:s', strtotime('-30 days'))])->rowCount();
    $r['jobs'] = q("DELETE FROM email_jobs WHERE status IN ('completed','cancelled') AND processed_at < ?", [date('Y-m-d H:i:s', strtotime('-30 days'))])->rowCount();
    $r['bulk_jobs'] = q("DELETE FROM bulk_jobs WHERE status IN ('completed','failed') AND updated_at < ?", [date('Y-m-d H:i:s', strtotime('-7 days'))])->rowCount();

    // Privacy: drop IP / user agent after each workspace's retention period
    $r['ip_anonymised'] = 0;
    foreach (q_all('SELECT id, ip_retention_days FROM workspaces') as $w) {
        $r['ip_anonymised'] += q(
            'UPDATE email_events SET ip_address = NULL, user_agent = NULL WHERE workspace_id = ? AND ip_address IS NOT NULL AND created_at < ?',
            [$w['id'], date('Y-m-d H:i:s', strtotime('-' . max(1, (int) $w['ip_retention_days']) . ' days'))]
        )->rowCount();
    }

    // Abandoned CSV uploads (> 1 day) and orphaned attachments from unsent forms
    $r['import_files'] = 0;
    foreach (glob(UPLOAD_PATH . '/imports/*/*.csv') ?: [] as $f) {
        if (filemtime($f) < time() - 86400 && @unlink($f)) {
            $r['import_files']++;
        }
    }
    foreach (q_all('SELECT id, storage_path FROM attachments WHERE email_message_id IS NULL AND created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]) as $a) {
        @unlink(UPLOAD_PATH . '/' . $a['storage_path']);
        q('DELETE FROM attachments WHERE id = ?', [$a['id']]);
    }

    // Log rotation: keep 14 days
    foreach (glob(STORAGE_PATH . '/logs/app-*.log') ?: [] as $f) {
        if (filemtime($f) < strtotime('-14 days')) {
            @unlink($f);
        }
    }
    return $r;
});
