<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();

$t = find_or_404('email_templates', input_int('id'));
$id = db_insert('email_templates', [
    'workspace_id' => ws_id(),
    'name' => mb_substr($t['name'] . ' (copy)', 0, 150),
    'subject' => $t['subject'],
    'html_body' => $t['html_body'],
    'text_body' => $t['text_body'],
    'created_at' => now(),
    'updated_at' => now(),
]);
flash('success', 'Template duplicated.');
redirect(url('templates/edit.php', ['id' => $id]));
