<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

if (!is_post()) {
    redirect('tags/index.php');
}
verify_csrf();
$errors = validate($_POST, ['name' => 'required|max:80', 'color' => 'required|color', 'description' => 'max:255']);
$name = trim((string) input('name'));
if (!$errors && q_val('SELECT id FROM tags WHERE workspace_id = ? AND name = ?', [$ws, $name])) {
    $errors['name'] = 'A tag with this name already exists.';
}
if ($errors) {
    if (is_ajax()) {
        json_response(['ok' => false, 'error' => reset($errors)], 422);
    }
    keep_old_input();
    flash_errors($errors);
    redirect('tags/index.php');
}
$id = db_insert('tags', [
    'workspace_id' => $ws,
    'name' => $name,
    'color' => strtolower((string) input('color')),
    'description' => trim((string) input('description')) ?: null,
    'created_at' => now(),
    'updated_at' => now(),
]);
if (is_ajax()) {
    json_response(['ok' => true, 'tag' => q_one('SELECT id, name, color FROM tags WHERE id = ?', [$id])]);
}
flash('success', 'Tag “' . $name . '” created.');
redirect('tags/index.php');
