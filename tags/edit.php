<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

if (!is_post()) {
    redirect(url('tags/index.php', ['edit' => input_int('id')]));
}
verify_csrf();
$tag = find_or_404('tags', input_int('id'));
$errors = validate($_POST, ['name' => 'required|max:80', 'color' => 'required|color', 'description' => 'max:255']);
$name = trim((string) input('name'));
if (!$errors && q_val('SELECT id FROM tags WHERE workspace_id = ? AND name = ? AND id <> ?', [$ws, $name, $tag['id']])) {
    $errors['name'] = 'A tag with this name already exists.';
}
if ($errors) {
    keep_old_input();
    flash_errors($errors);
    redirect(url('tags/index.php', ['edit' => $tag['id']]));
}
db_update('tags', [
    'name' => $name,
    'color' => strtolower((string) input('color')),
    'description' => trim((string) input('description')) ?: null,
    'updated_at' => now(),
], 'id = ? AND workspace_id = ?', [$tag['id'], $ws]);
flash('success', 'Tag updated.');
redirect('tags/index.php');
