<?php
/**
 * Start / resume an AI campaign. Nothing is researched or sent here: cron drafts a few customers per run
 * (process-ai-campaigns.php) and the email-queue cron sends what is ready.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('campaigns.manage');
require_post();
$ws = ws_id();
$campaign = find_or_404('ai_campaigns', input_int('id'));
$cid = (int) $campaign['id'];
$back = url('ai-campaigns/view.php', ['id' => $cid]);

if (in_array($campaign['status'], ['archived', 'active'], true)) {
    flash('info', 'AI campaign is already ' . $campaign['status'] . '.');
    redirect($back);
}
$account = $campaign['mail_account_id'] ? q_one('SELECT * FROM mail_accounts WHERE id = ? AND workspace_id = ?', [$campaign['mail_account_id'], $ws]) : null;
if (!$account || $account['status'] === 'disconnected') {
    flash('error', 'Choose a connected sending account before starting.');
    redirect(url('ai-campaigns/edit.php', ['id' => $cid]));
}
if (OPENROUTER_API_KEY === '' && !str_starts_with(OPENROUTER_API_BASE, 'http://127.0.0.1')) {
    flash('error', 'Add OPENROUTER_API_KEY to .env before starting an AI campaign.');
    redirect($back);
}
$waiting = (int) q_val("SELECT COUNT(*) FROM ai_campaign_contacts WHERE ai_campaign_id = ? AND draft_status IN ('pending','ready')", [$cid]);
$pendingJobs = (int) q_val("SELECT COUNT(*) FROM email_jobs WHERE ai_campaign_id = ? AND status = 'pending'", [$cid]);
if (!$waiting && !$pendingJobs) {
    flash('error', 'There are no contacts left to write to. Add contacts first.');
    redirect($back);
}

$wasPaused = $campaign['status'] === 'paused';
q(
    "UPDATE ai_campaigns SET status = 'active', last_error = NULL, started_at = COALESCE(started_at, ?), completed_at = NULL, updated_at = ? WHERE id = ?",
    [now(), now(), $cid]
);
log_activity($ws, 'ai_campaign_started', ($wasPaused ? 'Resumed' : 'Started') . ' AI campaign "' . $campaign['name'] . '"');
flash('success', ($wasPaused ? 'AI campaign resumed. ' : 'AI campaign started. ') . number_format($waiting) . ' customers to research and write to, ' . AI_DRAFT_BATCH . ' per minute. Emails are sent as they are ready.');
redirect($back);
