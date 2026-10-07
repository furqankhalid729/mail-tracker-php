<?php
defined('APP_ROOT') || exit;
/** Shared create/update handler. Expects $ws and $existing (array|null). */
$errors = validate($_POST, ['name' => 'required|max:150', 'subject' => 'required|max:255']);
$html = sanitize_html((string) ($_POST['html_body'] ?? ''));
$text = trim((string) ($_POST['text_body'] ?? ''));
if (trim(strip_tags($html)) === '' && $text === '') {
    $errors['html_body'] = 'Write the email body (HTML or plain text).';
}
if ($errors) {
    keep_old_input();
    flash_errors($errors);
    redirect_back('templates/index.php');
}
$data = [
    'name' => trim((string) input('name')),
    'subject' => trim((string) input('subject')),
    'html_body' => $html !== '' ? $html : text_to_html($text),
    'text_body' => $text !== '' ? $text : null,
    'updated_at' => now(),
];
if ($existing) {
    db_update('email_templates', $data, 'id = ? AND workspace_id = ?', [$existing['id'], $ws]);
    flash('success', 'Template saved.');
} else {
    db_insert('email_templates', $data + ['workspace_id' => $ws, 'created_at' => now()]);
    flash('success', 'Template created.');
}
clear_old_input();
redirect('templates/index.php');
