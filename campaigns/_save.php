<?php
defined('APP_ROOT') || exit;
/** Expects $ws, $existing (array|null). */
$errors = validate($_POST, ['name' => 'required|max:150', 'description' => 'max:2000', 'daily_limit' => 'int|between:1,10000', 'max_per_domain' => 'int|between:1,100'], ['daily_limit' => 'Daily limit', 'max_per_domain' => 'Max contacts per domain']);
$dailyLimit = input_int('daily_limit') ?: null; // empty = no campaign cap
$maxPerDomain = input_int('max_per_domain') ?: null; // empty = every contact at a domain is emailed
$accountId = input_int('mail_account_id') ?: null;
$templateId = input_int('template_id') ?: null;
if ($accountId && !owned_ids('mail_accounts', [$accountId])) {
    $errors['mail_account_id'] = 'Invalid mail account.';
}
if ($templateId && !owned_ids('email_templates', [$templateId])) {
    $errors['template_id'] = 'Invalid template.';
}
if ($existing && $existing['status'] === 'active' && (!$accountId || !$templateId)) {
    $errors['template_id'] = 'An active campaign needs both a sender and a template.';
}
if ($errors) {
    keep_old_input();
    flash_errors($errors);
    redirect_back('campaigns/index.php');
}
$data = [
    'name' => trim((string) input('name')),
    'description' => trim((string) input('description')) ?: null,
    'mail_account_id' => $accountId,
    'template_id' => $templateId,
    'daily_limit' => $dailyLimit,
    'max_per_domain' => $maxPerDomain,
    'updated_at' => now(),
];
if ($existing) {
    db_update('campaigns', $data, 'id = ? AND workspace_id = ?', [$existing['id'], $ws]);
    // Limit raised or removed → emails parked until tomorrow by the campaign cap can go out today
    $oldLimit = $existing['daily_limit'] === null ? null : (int) $existing['daily_limit'];
    if ($oldLimit !== null && ($dailyLimit === null || $dailyLimit > $oldLimit)) {
        release_campaign_deferred_jobs((int) $existing['id']);
    }
    // Per-domain cap changed → skipped contacts are re-evaluated by the queue builder (already queued mail is kept)
    $oldPerDomain = ($existing['max_per_domain'] ?? null) === null ? null : (int) $existing['max_per_domain'];
    if ($oldPerDomain !== $maxPerDomain) {
        campaign_reset_domain_skips((int) $existing['id']);
    }
    // Not-yet-sent messages follow a sender change
    if ($accountId && (int) $existing['mail_account_id'] !== $accountId) {
        q("UPDATE email_messages SET mail_account_id = ?, from_email = (SELECT email FROM mail_accounts WHERE id = ?) WHERE campaign_id = ? AND status = 'queued'", [$accountId, $accountId, $existing['id']]);
    }
    flash('success', 'Campaign saved.');
    redirect(url('campaigns/view.php', ['id' => $existing['id']]));
}
$id = db_insert('campaigns', $data + ['workspace_id' => $ws, 'status' => 'draft', 'created_by' => user_id(), 'created_at' => now()]);
clear_old_input();
flash('success', 'Campaign created. Now add contacts.');
$followUpId = input_int('follow_up');
$followUpId = $followUpId && owned_ids('campaigns', [$followUpId]) ? $followUpId : 0;
redirect(url('campaigns/contacts.php', $followUpId ? ['id' => $id, 'engaged_campaign' => $followUpId, 'engagement' => 'not_opened'] : ['id' => $id]));
