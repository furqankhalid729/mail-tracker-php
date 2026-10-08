<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('campaigns.manage');
require_post();
$ws = ws_id();
$campaign = find_or_404('campaigns', input_int('id'));

transaction(function () use ($campaign, $ws) {
    // Unsent campaign mail is discarded; sent mail stays in customer timelines (campaign_id → NULL)
    q("DELETE FROM email_messages WHERE campaign_id = ? AND workspace_id = ? AND status IN ('queued','cancelled','draft')", [$campaign['id'], $ws]);
    q('DELETE FROM email_jobs WHERE campaign_id = ?', [$campaign['id']]);
    q('DELETE FROM campaigns WHERE id = ? AND workspace_id = ?', [$campaign['id'], $ws]);
});
flash('success', 'Campaign “' . $campaign['name'] . '” deleted.');
redirect('campaigns/index.php');
