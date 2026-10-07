<?php
defined('APP_ROOT') || exit;
/** Expects $ws, $existing (array|null). */
$errors = validate($_POST, ['name' => 'required|max:150', 'description' => 'max:2000']);
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
    'updated_at' => now(),
];
if ($existing) {
    db_update('campaigns', $data, 'id = ? AND workspace_id = ?', [$existing['id'], $ws]);
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
redirect(url('campaigns/contacts.php', ['id' => $id]));
