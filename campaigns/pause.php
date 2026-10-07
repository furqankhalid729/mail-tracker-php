<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();
$campaign = find_or_404('campaigns', input_int('id'));

if ($campaign['status'] === 'active') {
    // Pending jobs stay in the queue; the cron claim query skips campaigns that are not active
    q("UPDATE campaigns SET status = 'paused', updated_at = ? WHERE id = ?", [now(), $campaign['id']]);
    log_activity(ws_id(), 'campaign_paused', 'Paused campaign "' . $campaign['name'] . '"', null, (int) $campaign['id']);
    flash('success', 'Campaign paused. Queued emails will wait until you resume.');
}
redirect(url('campaigns/view.php', ['id' => $campaign['id']]));
