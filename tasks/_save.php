<?php
defined('APP_ROOT') || exit;
/**
 * Shared create/update handler included by create.php and edit.php.
 * Expects $ws and $existing (?array, the task being edited). Returns via redirect.
 */
$errors = validate($_POST, ['title' => 'required|max:200', 'description' => 'max:20000']);

$dueDate = trim((string) input('due_date'));
if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
    $errors['due_date'] = 'Due date is invalid.';
}

// Only managers choose the assignee; everyone else's tasks go to themselves (or keep their current assignee on edit)
if (allowed('tasks.manage_all')) {
    $assignedTo = input_int('assigned_to') ?: null;
    if ($assignedTo && !is_workspace_user($assignedTo)) {
        $errors['assigned_to'] = 'That person is not a member of this workspace.';
    }
} else {
    $assignedTo = $existing ? ($existing['assigned_to'] !== null ? (int) $existing['assigned_to'] : null) : user_id();
}

$customerId = input_int('customer_id') ?: null;
if ($customerId && !owned_ids('customers', [$customerId])) {
    $customerId = null;
}
$campaignId = input_int('campaign_id') ?: null;
if ($campaignId && !owned_ids('campaigns', [$campaignId])) {
    $campaignId = null;
}

if ($errors) {
    keep_old_input();
    flash_errors($errors);
    redirect($existing ? url('tasks/edit.php', ['id' => $existing['id']]) : url('tasks/create.php', ['customer_id' => $customerId]));
}

$status = array_key_exists((string) input('status'), TASK_STATUSES) ? (string) input('status') : 'todo';
$priority = array_key_exists((string) input('priority'), TASK_PRIORITIES) ? (string) input('priority') : 'medium';
$data = [
    'title' => trim((string) input('title')),
    'description' => trim((string) input('description')) ?: null,
    'priority' => $priority,
    'assigned_to' => $assignedTo,
    'customer_id' => $customerId,
    'campaign_id' => $campaignId,
    'due_date' => $dueDate ?: null,
    'updated_at' => now(),
];

$assigneeName = fn(?int $id) => $id ? (string) q_val('SELECT name FROM users WHERE id = ?', [$id]) : 'nobody';

if ($existing) {
    $data += task_state_change($existing, $status, null);
    $taskId = (int) $existing['id'];
    transaction(function () use ($data, $existing, $taskId, $ws, $assigneeName) {
        db_update('tasks', $data, 'id = ? AND workspace_id = ?', [$taskId, $ws]);
        if ((int) $existing['assigned_to'] !== (int) $data['assigned_to']) {
            log_task_update($taskId, 'assigned', 'Reassigned to ' . $assigneeName($data['assigned_to']));
        }
        if ($existing['status'] !== $data['status']) {
            log_task_update($taskId, 'status', $data['status']);
        }
        log_task_update($taskId, 'edited', 'Edited task details');
    });
    clear_old_input();
    flash('success', 'Task updated.');
} else {
    $data += task_state_change(['status' => 'todo', 'progress' => 0, 'completed_at' => null], $status, null);
    $taskId = transaction(function () use ($data, $ws, $assigneeName) {
        $id = db_insert('tasks', $data + ['workspace_id' => $ws, 'created_by' => user_id(), 'created_at' => now()]);
        log_task_update($id, 'created', 'Created the task and assigned it to ' . $assigneeName($data['assigned_to']));
        return $id;
    });
    clear_old_input();
    flash('success', 'Task created.');
}
redirect(url('tasks/view.php', ['id' => $taskId]));
