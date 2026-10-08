<?php
/**
 * Progress update on a task: any combination of status, progress (0–100) and a comment.
 * Used by the task page and by the quick status picker in the task list.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();
$ws = ws_id();
$task = find_task_or_404(input_int('id'));
if (!can_update_task($task)) {
    abort(403, 'Only the assignee, the creator or a manager can update this task.');
}

$status = array_key_exists((string) input('status'), TASK_STATUSES) ? (string) input('status') : null;
$progress = isset($_POST['progress']) && $_POST['progress'] !== '' ? input_int('progress') : null;
$comment = mb_substr(trim((string) input('comment')), 0, 5000);

$change = task_state_change($task, $status, $progress);
$statusChanged = $change['status'] !== $task['status'];
$progressChanged = $change['progress'] !== (int) $task['progress'];

if (!$statusChanged && !$progressChanged && $comment === '') {
    flash('info', 'Nothing to update.');
    redirect_back(url('tasks/view.php', ['id' => $task['id']]));
}

transaction(function () use ($task, $ws, $change, $statusChanged, $progressChanged, $comment) {
    db_update('tasks', $change + ['updated_at' => now()], 'id = ? AND workspace_id = ?', [$task['id'], $ws]);
    if ($statusChanged) {
        log_task_update((int) $task['id'], 'status', $change['status']);
    }
    if ($progressChanged) {
        log_task_update((int) $task['id'], 'progress', (string) $change['progress']);
    }
    if ($comment !== '') {
        log_task_update((int) $task['id'], 'comment', $comment);
    }
});

flash('success', $statusChanged ? 'Task moved to “' . TASK_STATUSES[$change['status']] . '”.' : 'Task updated.');
redirect_back(url('tasks/view.php', ['id' => $task['id']]));
