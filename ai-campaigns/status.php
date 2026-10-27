<?php
/** Retry failed drafts, archive / unarchive. */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('campaigns.manage');
require_post();
$ws = ws_id();
$campaign = find_or_404('ai_campaigns', input_int('id'));
$cid = (int) $campaign['id'];

switch ((string) input('action')) {
    case 'retry_failed':
        $n = q("UPDATE ai_campaign_contacts SET draft_status = 'pending', attempts = 0, error = NULL, updated_at = ? WHERE ai_campaign_id = ? AND draft_status = 'failed'", [now(), $cid])->rowCount();
        if ($n && $campaign['status'] === 'completed') {
            q("UPDATE ai_campaigns SET status = 'active', completed_at = NULL, updated_at = ? WHERE id = ?", [now(), $cid]);
        }
        flash('success', $n . ' failed customer' . ($n === 1 ? '' : 's') . ' will be retried' . ($campaign['status'] === 'active' || $campaign['status'] === 'completed' ? ' on the next cron run.' : ' when the campaign runs.'));
        break;
    case 'archive':
        ai_archive_campaign($cid);
        flash('success', 'AI campaign archived. Queued emails were cancelled and will be queued again if you unarchive and resume.');
        break;
    case 'unarchive':
        q("UPDATE ai_campaigns SET status = 'paused', updated_at = ? WHERE id = ? AND status = 'archived'", [now(), $cid]);
        flash('success', 'AI campaign restored (paused).');
        break;
}
redirect(url('ai-campaigns/view.php', ['id' => $cid]));
