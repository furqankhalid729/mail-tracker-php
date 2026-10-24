<?php
defined('APP_ROOT') || exit;
/** Expects $ws, $existing (array|null). */
$errors = validate($_POST, [
    'name' => 'required|max:150',
    'description' => 'max:2000',
    'model' => 'required|max:120',
    'research_prompt' => 'required|max:5000',
    'subject' => 'required|max:255',
    'email_template' => 'required|max:20000',
]);
$accountId = input_int('mail_account_id') ?: null;
if ($accountId && !owned_ids('mail_accounts', [$accountId])) {
    $errors['mail_account_id'] = 'Invalid mail account.';
}
if (!preg_match('~^[A-Za-z0-9._:/-]+$~', trim((string) input('model')))) {
    $errors['model'] = 'Use an OpenRouter model id such as ' . OPENROUTER_DEFAULT_MODEL . '.';
}
if ($existing && $existing['status'] === 'active' && !$accountId) {
    $errors['mail_account_id'] = 'An active AI campaign needs a sender.';
}
if ($errors) {
    keep_old_input();
    flash_errors($errors);
    redirect_back('ai-campaigns/index.php');
}
$data = [
    'name' => trim((string) input('name')),
    'description' => trim((string) input('description')) ?: null,
    'mail_account_id' => $accountId,
    'model' => trim((string) input('model')),
    'research_prompt' => trim((string) input('research_prompt')),
    'subject' => trim((string) input('subject')),
    'keep_subject' => input('keep_subject') === '1' ? 1 : 0,
    'email_template' => trim((string) input('email_template')),
    'web_search' => input('web_search') === '1' ? 1 : 0,
    'updated_at' => now(),
];
if ($existing) {
    db_update('ai_campaigns', $data, 'id = ? AND workspace_id = ?', [$existing['id'], $ws]);
    // Not-yet-sent messages follow a sender change
    if ($accountId && (int) $existing['mail_account_id'] !== $accountId) {
        q("UPDATE email_messages SET mail_account_id = ?, from_email = (SELECT email FROM mail_accounts WHERE id = ?) WHERE ai_campaign_id = ? AND status = 'queued'", [$accountId, $accountId, $existing['id']]);
    }
    clear_old_input();
    flash('success', 'AI campaign saved. Changes apply to emails not yet written.');
    redirect(url('ai-campaigns/view.php', ['id' => $existing['id']]));
}
$id = db_insert('ai_campaigns', $data + ['workspace_id' => $ws, 'status' => 'draft', 'created_by' => user_id(), 'created_at' => now()]);
clear_old_input();
flash('success', 'AI campaign created. Now add contacts.');
redirect(url('ai-campaigns/contacts.php', ['id' => $id]));
