<?php
/** Complete / archive / unarchive / retry-failed for a campaign. */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('campaigns.manage');
require_post();
$ws = ws_id();
$campaign = find_or_404('campaigns', input_int('id'));
$cid = (int) $campaign['id'];

$cancelQueue = function () use ($cid): int {
    $n = q(
        "UPDATE email_jobs j JOIN email_messages m ON m.id = j.email_message_id
         SET j.status = 'cancelled', j.error_message = 'Campaign stopped', m.status = 'cancelled'
         WHERE j.campaign_id = ? AND j.status = 'pending'",
        [$cid]
    )->rowCount();
    q("UPDATE campaign_contacts cc JOIN email_messages m ON m.id = cc.last_email_id SET cc.system_status = NULL, cc.last_email_id = NULL WHERE cc.campaign_id = ? AND m.status = 'cancelled' AND cc.system_status = 'queued'", [$cid]);
    return $n;
};

switch ((string) input('action')) {
    case 'complete':
        $n = $cancelQueue();
        q("UPDATE campaigns SET status = 'completed', completed_at = ?, updated_at = ? WHERE id = ?", [now(), now(), $cid]);
        log_activity($ws, 'campaign_completed', 'Completed campaign "' . $campaign['name'] . '"', null, $cid);
        flash('success', 'Campaign completed' . ($n ? "; $n queued emails cancelled." : '.'));
        break;
    case 'archive':
        $n = $cancelQueue();
        q("UPDATE campaigns SET status = 'archived', updated_at = ? WHERE id = ?", [now(), $cid]);
        flash('success', 'Campaign archived' . ($n ? "; $n queued emails cancelled." : '.'));
        break;
    case 'unarchive':
        q("UPDATE campaigns SET status = IF(started_at IS NULL, 'draft', 'completed'), updated_at = ? WHERE id = ? AND status = 'archived'", [now(), $cid]);
        flash('success', 'Campaign restored.');
        break;
    case 'retry_failed':
        $n = retry_failed_jobs($ws, $cid, 24 * 30);
        if ($n && $campaign['status'] === 'completed') {
            q("UPDATE campaigns SET status = 'active', completed_at = NULL WHERE id = ?", [$cid]);
        }
        flash('success', $n ? "$n failed emails re-queued." : 'No retryable failures (permanent failures like invalid addresses are not retried).');
        break;
    default:
        flash('error', 'Unknown action.');
}
redirect(url('campaigns/view.php', ['id' => $cid]));
