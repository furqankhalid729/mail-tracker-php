<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('campaigns.manage');
require_post();
$ws = ws_id();
$campaign = find_or_404('ai_campaigns', input_int('id'));

transaction(function () use ($campaign, $ws) {
    // Unsent mail is discarded; sent mail stays in customer timelines (ai_campaign_id → NULL)
    q('DELETE FROM email_jobs WHERE ai_campaign_id = ?', [$campaign['id']]);
    q("DELETE FROM email_messages WHERE ai_campaign_id = ? AND workspace_id = ? AND status IN ('queued','cancelled','draft')", [$campaign['id'], $ws]);
    q('UPDATE email_messages SET ai_campaign_id = NULL WHERE ai_campaign_id = ? AND workspace_id = ?', [$campaign['id'], $ws]);
    q('UPDATE email_threads SET ai_campaign_id = NULL WHERE ai_campaign_id = ? AND workspace_id = ?', [$campaign['id'], $ws]);
    q('DELETE FROM ai_campaigns WHERE id = ? AND workspace_id = ?', [$campaign['id'], $ws]);
});
flash('success', 'AI campaign “' . $campaign['name'] . '” deleted.');
redirect('ai-campaigns/index.php');
