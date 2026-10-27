<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('campaigns.manage');
require_post();
$campaign = find_or_404('ai_campaigns', input_int('id'));

if ($campaign['status'] === 'active') {
    // Drafting stops (no API spend) and queued jobs wait: both cron claims skip AI campaigns that are not active
    q("UPDATE ai_campaigns SET status = 'paused', updated_at = ? WHERE id = ?", [now(), $campaign['id']]);
    log_activity(ws_id(), 'ai_campaign_paused', 'Paused AI campaign "' . $campaign['name'] . '"');
    flash('success', 'AI campaign paused. Nothing is researched or sent until you resume.');
}
redirect(url('ai-campaigns/view.php', ['id' => $campaign['id']]));
