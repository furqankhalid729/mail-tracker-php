<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();

$task = find_task_or_404(input_int('id'));
if (!can_edit_task($task)) {
    abort(403, 'Only the person who created this task or a manager can delete it.');
}
q('DELETE FROM tasks WHERE id = ? AND workspace_id = ?', [$task['id'], ws_id()]);
flash('success', 'Task “' . $task['title'] . '” deleted.');
redirect('tasks/index.php');
