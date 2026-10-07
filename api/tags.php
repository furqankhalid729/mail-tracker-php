<?php
/**
 * Tags API.
 *   GET                         → all tags with customer counts
 *   POST {name, color}          → create (JSON)
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

if (is_post()) {
    verify_csrf();
    $body = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    $errors = validate($body, ['name' => 'required|max:80', 'color' => 'required|color']);
    $name = trim((string) ($body['name'] ?? ''));
    if (!$errors && q_val('SELECT id FROM tags WHERE workspace_id = ? AND name = ?', [$ws, $name])) {
        $errors['name'] = 'Tag already exists.';
    }
    if ($errors) {
        json_response(['ok' => false, 'error' => reset($errors)], 422);
    }
    $id = db_insert('tags', ['workspace_id' => $ws, 'name' => $name, 'color' => strtolower($body['color']), 'created_at' => now(), 'updated_at' => now()]);
    json_response(['ok' => true, 'tag' => ['id' => $id, 'name' => $name, 'color' => strtolower($body['color'])]]);
}

json_response(['ok' => true, 'tags' => q_all(
    'SELECT t.id, t.name, t.color, (SELECT COUNT(*) FROM customer_tags ct WHERE ct.tag_id = t.id) customers FROM tags t WHERE t.workspace_id = ? ORDER BY t.name',
    [$ws]
)]);
