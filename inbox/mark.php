<?php
/** Bulk read/unread for inbox conversations: selected ids, or every unread conversation in the workspace. */
require_once __DIR__ . '/../includes/init.php';
require_auth();
if (!is_post()) {
    redirect(url('inbox/index.php'));
}
verify_csrf();
$ws = ws_id();
$read = input('action') !== 'unread';

if (input('all') === '1') {
    // Mark every unread conversation in the workspace as read
    q("UPDATE email_messages m JOIN email_threads t ON t.id = m.thread_id SET m.is_read = 1
       WHERE t.workspace_id = ? AND t.is_unread = 1 AND m.direction = 'inbound' AND m.is_read = 0", [$ws]);
    $n = q('UPDATE email_threads SET is_unread = 0 WHERE workspace_id = ? AND is_unread = 1', [$ws])->rowCount();
    flash('success', number_format($n) . ' conversation' . ($n === 1 ? '' : 's') . ' marked as read.');
    redirect_back('inbox/index.php');
}

$ids = input_ids('ids');
if (!$ids) {
    flash('error', 'Select at least one conversation.');
    redirect_back('inbox/index.php');
}
$in = implode(',', array_fill(0, count($ids), '?'));
q("UPDATE email_threads SET is_unread = ? WHERE workspace_id = ? AND id IN ($in)", [$read ? 0 : 1, $ws, ...$ids]);
if ($read) {
    q("UPDATE email_messages m JOIN email_threads t ON t.id = m.thread_id SET m.is_read = 1
       WHERE t.workspace_id = ? AND t.id IN ($in) AND m.direction = 'inbound'", [$ws, ...$ids]);
}
flash('success', count($ids) . ' conversation' . (count($ids) === 1 ? '' : 's') . ' marked as ' . ($read ? 'read' : 'unread') . '.');
redirect_back('inbox/index.php');
