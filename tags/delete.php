<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();

$tag = find_or_404('tags', input_int('id'));
q('DELETE FROM tags WHERE id = ? AND workspace_id = ?', [$tag['id'], ws_id()]);
flash('success', 'Tag “' . $tag['name'] . '” deleted.');
redirect('tags/index.php');
