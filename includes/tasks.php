<?php
declare(strict_types=1);

/**
 * Tasks: who sees what.
 *   - Managers and above see and manage every task in the workspace.
 *   - Everyone else sees tasks assigned to them or created by them, and can only assign to themselves.
 */

/** WHERE fragment (alias t) limiting tasks to those the current user may see. */
function task_visibility_sql(): array
{
    if (allowed('tasks.manage_all')) {
        return ['t.workspace_id = ?', [ws_id()]];
    }
    return ['t.workspace_id = ? AND (t.assigned_to = ? OR t.created_by = ?)', [ws_id(), user_id(), user_id()]];
}

function find_task_or_404(int $id): array
{
    [$where, $params] = task_visibility_sql();
    $task = q_one(
        "SELECT t.*, a.name assignee_name, a.email assignee_email, cr.name creator_name,
            c.first_name, c.last_name, c.full_name, c.email customer_email, cp.name campaign_name
         FROM tasks t
         LEFT JOIN users a ON a.id = t.assigned_to
         LEFT JOIN users cr ON cr.id = t.created_by
         LEFT JOIN customers c ON c.id = t.customer_id
         LEFT JOIN campaigns cp ON cp.id = t.campaign_id
         WHERE t.id = ? AND $where",
        [$id, ...$params]
    );
    if (!$task) {
        abort(404, 'Task not found.');
    }
    return $task;
}

/** Edit details (title, assignee, due date…) or delete: managers, or the person who created it. */
function can_edit_task(array $task): bool
{
    return allowed('tasks.manage_all') || (int) $task['created_by'] === user_id();
}

/** Post progress, change status, comment: the assignee, the creator, or a manager. */
function can_update_task(array $task): bool
{
    return can_edit_task($task) || (int) $task['assigned_to'] === user_id();
}

/** Members of the current workspace, for assignee pickers and the team report. */
function workspace_users(): array
{
    return q_all(
        "SELECT u.id, u.name, u.email, m.role FROM workspace_members m JOIN users u ON u.id = m.user_id
         WHERE m.workspace_id = ? ORDER BY u.name",
        [ws_id()]
    );
}

function is_workspace_user(int $userId): bool
{
    return (bool) q_val('SELECT 1 FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [ws_id(), $userId]);
}

function log_task_update(int $taskId, string $type, ?string $body = null): void
{
    db_insert('task_updates', [
        'workspace_id' => ws_id(),
        'task_id' => $taskId,
        'user_id' => user_id() ?: null,
        'type' => $type,
        'body' => $body,
        'created_at' => now(),
    ]);
}

/**
 * Apply a status and/or progress change, keeping them consistent:
 *   - done ⇒ 100%
 *   - re-opening a done task ⇒ below 100% (90% unless a lower value was given; "in review" may stay at 100%)
 *   - when only progress moves: progress on a backlog/to-do task ⇒ in progress, 100% ⇒ in review
 * Saving a task without touching status or progress never changes either.
 * Returns the columns to update.
 */
function task_state_change(array $task, ?string $status, ?int $progress): array
{
    $status ??= $task['status'];
    $oldProgress = (int) $task['progress'];
    $progress = $progress === null ? $oldProgress : max(0, min(100, $progress));
    $progressMoved = $progress !== $oldProgress;

    if ($status === 'done') {
        $progress = 100;
    } elseif ($task['status'] === 'done') {
        // Re-opened: the form still carries the old 100%, so drop below it
        if ($progress >= 100 && $status !== 'review') {
            $progress = 90;
        }
    } elseif ($status === $task['status'] && $progressMoved) {
        if ($progress > 0 && in_array($status, ['backlog', 'todo'], true)) {
            $status = 'in_progress';
        }
        if ($progress === 100 && in_array($status, ['backlog', 'todo', 'in_progress'], true)) {
            $status = 'review';
        }
    }

    return [
        'status' => $status,
        'progress' => $progress,
        'completed_at' => $status === 'done' ? ($task['completed_at'] ?: now()) : null,
    ];
}

function task_is_overdue(array $task): bool
{
    return $task['due_date'] && in_array($task['status'], TASK_OPEN_STATUSES, true) && $task['due_date'] < date('Y-m-d');
}

function task_status_badge(string $status): string
{
    $color = ['backlog' => 'slate', 'todo' => 'indigo', 'in_progress' => 'blue', 'review' => 'violet', 'done' => 'green', 'cancelled' => 'slate'][$status] ?? 'slate';
    return '<span class="badge badge-' . $color . ' !normal-case">' . e(TASK_STATUSES[$status] ?? $status) . '</span>';
}

function task_priority_badge(string $priority): string
{
    $color = ['low' => 'slate', 'medium' => 'sky', 'high' => 'amber', 'urgent' => 'red'][$priority] ?? 'slate';
    return '<span class="badge badge-' . $color . '">' . e(TASK_PRIORITIES[$priority] ?? $priority) . '</span>';
}

function task_progress_bar(int $progress, string $class = 'w-24'): string
{
    $color = $progress >= 100 ? 'bg-emerald-500' : ($progress >= 50 ? 'bg-indigo-500' : 'bg-sky-400');
    return '<div class="flex items-center gap-2"><div class="h-1.5 ' . e($class) . ' overflow-hidden rounded-full bg-slate-100">'
        . '<div class="h-full rounded-full ' . $color . '" style="width:' . $progress . '%"></div></div>'
        . '<span class="text-xs tabular-nums text-slate-500">' . $progress . '%</span></div>';
}

function task_due_label(array $task): string
{
    if (!$task['due_date']) {
        return '<span class="text-slate-400">—</span>';
    }
    $label = e(format_dt($task['due_date'], substr($task['due_date'], 0, 4) === date('Y') ? 'M j' : 'M j, Y'));
    if (task_is_overdue($task)) {
        return '<span class="inline-flex items-center gap-1.5 font-medium text-red-600">' . $label . '<span class="rounded bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ring-inset ring-red-600/20">Overdue</span></span>';
    }
    if ($task['due_date'] === date('Y-m-d') && in_array($task['status'], TASK_OPEN_STATUSES, true)) {
        return '<span class="font-medium text-amber-600">Today</span>';
    }
    return '<span class="text-slate-600">' . $label . '</span>';
}

/** Open tasks assigned to the current user (sidebar badge). */
function my_open_task_count(): int
{
    return (int) q_val(
        'SELECT COUNT(*) FROM tasks WHERE workspace_id = ? AND assigned_to = ? AND status IN (' . placeholders(TASK_OPEN_STATUSES) . ')',
        [ws_id(), user_id(), ...TASK_OPEN_STATUSES]
    );
}
