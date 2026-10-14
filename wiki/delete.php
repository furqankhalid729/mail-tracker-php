<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();
require_permission('wiki.manage');

$page = find_or_404('wiki_pages', input_int('id'), 'id, parent_id, title, content');
transaction(function () use ($page) {
    // Sub-pages move up one level instead of disappearing
    q('UPDATE wiki_pages SET parent_id = ? WHERE parent_id = ? AND workspace_id = ?', [$page['parent_id'], $page['id'], ws_id()]);
    q('DELETE FROM wiki_pages WHERE id = ? AND workspace_id = ?', [$page['id'], ws_id()]);
});
wiki_delete_unused_images(wiki_image_names($page['content']));
flash('success', 'Page “' . $page['title'] . '” deleted.');
redirect($page['parent_id'] ? url('wiki/view.php', ['id' => $page['parent_id']]) : 'wiki/index.php');
