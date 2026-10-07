<?php
/**
 * Start / resume a campaign. Emails are never sent here: contacts are turned into queued
 * messages + jobs, and cron sends them in small batches.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();
$ws = ws_id();
$campaign = find_or_404('campaigns', input_int('id'));
$cid = (int) $campaign['id'];
$back = url('campaigns/view.php', ['id' => $cid]);

if ($campaign['status'] === 'archived' || $campaign['status'] === 'active') {
    flash('info', 'Campaign is already ' . $campaign['status'] . '.');
    redirect($back);
}
$account = $campaign['mail_account_id'] ? q_one('SELECT * FROM mail_accounts WHERE id = ? AND workspace_id = ?', [$campaign['mail_account_id'], $ws]) : null;
$template = $campaign['template_id'] ? q_one('SELECT id FROM email_templates WHERE id = ? AND workspace_id = ?', [$campaign['template_id'], $ws]) : null;
if (!$account || $account['status'] === 'disconnected') {
    flash('error', 'Choose a connected sending account before starting.');
    redirect(url('campaigns/edit.php', ['id' => $cid]));
}
if (!$template) {
    flash('error', 'Choose an email template before starting.');
    redirect(url('campaigns/edit.php', ['id' => $cid]));
}
$wasPaused = $campaign['status'] === 'paused';
$pendingJobs = (int) q_val("SELECT COUNT(*) FROM email_jobs WHERE campaign_id = ? AND status = 'pending'", [$cid]);
$unqueued = campaign_unqueued_count($cid);
if (!$unqueued && !$pendingJobs) {
    flash('error', 'There are no contacts left to email. Add contacts first (unsubscribed and bounced customers are skipped).');
    redirect($back);
}

q(
    "UPDATE campaigns SET status = 'active', started_at = COALESCE(started_at, ?), completed_at = NULL, updated_at = ? WHERE id = ?",
    [now(), now(), $cid]
);
log_activity($ws, 'campaign_started', ($wasPaused ? 'Resumed' : 'Started') . ' campaign "' . $campaign['name'] . '"', null, $cid);

// Build the first chunks now so the user sees progress; cron builds the rest
set_time_limit(120);
$queued = 0;
$start = microtime(true);
while (microtime(true) - $start < 20 && ($n = queue_campaign_contacts($cid, QUEUE_BUILD_CHUNK)) > 0) {
    $queued += $n;
}
$remaining = campaign_unqueued_count($cid);

$msg = ($wasPaused ? 'Campaign resumed. ' : 'Campaign started. ') . number_format($queued + $pendingJobs) . ' emails queued.';
if ($remaining) {
    $msg .= ' ' . number_format($remaining) . ' more will be queued automatically by cron.';
}
flash('success', $msg . ' Cron sends them in batches within your daily limits.');
redirect($back);
