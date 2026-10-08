<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$me = user_id();
$isManager = allowed('tasks.manage_all');

$views = ['mine' => 'My tasks', 'created' => 'Created by me'];
if ($isManager) {
    $views['all'] = 'All tasks';
}
if (allowed('team.view')) {
    $views['team'] = 'Team progress';
}
$view = array_key_exists((string) input('view'), $views) ? (string) input('view') : 'mine';
$today = date('Y-m-d');
$open = placeholders(TASK_OPEN_STATUSES);

if ($view === 'team') {
    $team = q_all(
        "SELECT u.id, u.name, u.email, m.role,
            COUNT(t.id) total,
            COALESCE(SUM(t.status IN ($open)), 0) open_count,
            COALESCE(SUM(t.status = 'in_progress'), 0) in_progress,
            COALESCE(SUM(t.status = 'backlog'), 0) backlog,
            COALESCE(SUM(t.status IN ($open) AND t.due_date < ?), 0) overdue,
            COALESCE(SUM(t.status = 'done' AND t.completed_at >= ?), 0) done_30d,
            COALESCE(SUM(t.status = 'done'), 0) done_all,
            ROUND(AVG(CASE WHEN t.status IN ($open) THEN t.progress END)) avg_progress,
            MAX(t.updated_at) last_update
         FROM workspace_members m
         JOIN users u ON u.id = m.user_id
         LEFT JOIN tasks t ON t.assigned_to = u.id AND t.workspace_id = m.workspace_id
         WHERE m.workspace_id = ?
         GROUP BY u.id, u.name, u.email, m.role
         ORDER BY overdue DESC, open_count DESC, u.name",
        [...TASK_OPEN_STATUSES, ...TASK_OPEN_STATUSES, $today, date('Y-m-d H:i:s', strtotime('-30 days')), ...TASK_OPEN_STATUSES, $ws]
    );
    $unassigned = (int) q_val("SELECT COUNT(*) FROM tasks WHERE workspace_id = ? AND assigned_to IS NULL AND status IN ($open)", [$ws, ...TASK_OPEN_STATUSES]);
} else {
    // Base scope for the selected view, then the user's filters on top
    [$where, $params] = task_visibility_sql();
    if ($view === 'mine') {
        $where .= ' AND t.assigned_to = ?';
        $params[] = $me;
    } elseif ($view === 'created') {
        $where .= ' AND t.created_by = ?';
        $params[] = $me;
    }
    [$scopeWhere, $scopeParams] = [$where, $params];

    $status = (string) input('status', 'open');
    if ($status === 'open') {
        $where .= " AND t.status IN ($open)";
        $params = [...$params, ...TASK_OPEN_STATUSES];
    } elseif (array_key_exists($status, TASK_STATUSES)) {
        $where .= ' AND t.status = ?';
        $params[] = $status;
    } else {
        $status = 'any';
    }
    $priority = array_key_exists((string) input('priority'), TASK_PRIORITIES) ? (string) input('priority') : '';
    if ($priority) {
        $where .= ' AND t.priority = ?';
        $params[] = $priority;
    }
    $assignee = $view === 'all' ? (string) input('assignee') : '';
    if ($assignee === 'none') {
        $where .= ' AND t.assigned_to IS NULL';
    } elseif ((int) $assignee > 0) {
        $where .= ' AND t.assigned_to = ?';
        $params[] = (int) $assignee;
    }
    $due = in_array(input('due'), ['overdue', 'today', 'week', 'none'], true) ? (string) input('due') : '';
    if ($due === 'overdue') {
        $where .= " AND t.due_date < ? AND t.status IN ($open)";
        $params = [...$params, $today, ...TASK_OPEN_STATUSES];
    } elseif ($due === 'today') {
        $where .= ' AND t.due_date = ?';
        $params[] = $today;
    } elseif ($due === 'week') {
        $where .= ' AND t.due_date BETWEEN ? AND ?';
        $params = [...$params, $today, date('Y-m-d', strtotime('+7 days'))];
    } elseif ($due === 'none') {
        $where .= ' AND t.due_date IS NULL';
    }
    $q = trim((string) input('q'));
    if ($q !== '') {
        $where .= ' AND (t.title LIKE ? OR t.description LIKE ?)';
        $params = [...$params, "%$q%", "%$q%"];
    }

    $stats = q_one(
        "SELECT COALESCE(SUM(t.status IN ($open)), 0) open_count,
            COALESCE(SUM(t.status = 'in_progress'), 0) in_progress,
            COALESCE(SUM(t.status = 'backlog'), 0) backlog,
            COALESCE(SUM(t.status IN ($open) AND t.due_date < ?), 0) overdue,
            COALESCE(SUM(t.status = 'done' AND t.completed_at >= ?), 0) done_week
         FROM tasks t WHERE $scopeWhere",
        [...TASK_OPEN_STATUSES, ...TASK_OPEN_STATUSES, $today, date('Y-m-d 00:00:00', strtotime('-6 days')), ...$scopeParams]
    );

    $order = sort_sql([
        'due' => 't.due_date IS NULL, t.due_date',
        'priority' => "FIELD(t.priority, 'urgent', 'high', 'medium', 'low')",
        'progress' => 't.progress',
        'updated' => 't.updated_at',
        'title' => 't.title',
    ], 'due', 'asc');
    $total = (int) q_val("SELECT COUNT(*) FROM tasks t WHERE $where", $params);
    $p = paginate($total, per_page(25));
    $tasks = q_all(
        "SELECT t.*, a.name assignee_name, c.first_name, c.last_name, c.full_name, c.email customer_email, cp.name campaign_name
         FROM tasks t
         LEFT JOIN users a ON a.id = t.assigned_to
         LEFT JOIN customers c ON c.id = t.customer_id
         LEFT JOIN campaigns cp ON cp.id = t.campaign_id
         WHERE $where ORDER BY $order, FIELD(t.priority, 'urgent', 'high', 'medium', 'low'), t.id DESC LIMIT ? OFFSET ?",
        [...$params, $p['per_page'], $p['offset']]
    );
    $users = $view === 'all' ? workspace_users() : [];
}

$page_title = 'Tasks';
$active_nav = 'tasks';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Tasks</h1><p class="page-subtitle"><?= $isManager ? 'Assign work to your team and follow its progress.' : 'Your work, its progress and what is due.' ?></p></div>
    <a href="<?= e(url('tasks/create.php')) ?>" class="btn-primary"><?= icon('plus', 'h-4 w-4') ?> New task</a>
</div>

<div class="mb-6 flex gap-6 overflow-x-auto border-b border-slate-200">
    <?php foreach ($views as $k => $l): ?>
        <a href="<?= e(url('tasks/index.php', ['view' => $k])) ?>" class="tab whitespace-nowrap <?= $view === $k ? 'active' : '' ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($view === 'team'): ?>
    <?php if ($unassigned): ?>
        <div class="mb-4 flex items-center justify-between gap-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">
            <span><b><?= $unassigned ?></b> open <?= $unassigned === 1 ? 'task has' : 'tasks have' ?> no assignee.</span>
            <a class="font-medium underline" href="<?= e(url('tasks/index.php', ['view' => 'all', 'assignee' => 'none'])) ?>">Assign them</a>
        </div>
    <?php endif; ?>
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Member</th><th class="text-right">Backlog</th><th class="text-right">Open</th><th class="text-right">In progress</th><th class="text-right">Overdue</th><th class="text-right">Done 30d</th><th>Avg. progress</th><th>Completed</th><th>Updated</th></tr></thead>
                <tbody>
                <?php foreach ($team as $m): $all = (int) $m['total']; ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('tasks/index.php', ['view' => 'all', 'assignee' => $m['id']])) ?>" class="flex items-center gap-3 whitespace-nowrap">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold <?= e(avatar_color($m['name'])) ?>" title="<?= e($m['name']) ?>"><?= e(user_initials($m['name'])) ?></span>
                                <span><span class="block font-medium text-slate-900 hover:text-indigo-600"><?= e($m['name']) ?></span><span class="block text-xs text-slate-500"><?= e(role_label($m['role'])) ?></span></span>
                            </a>
                        </td>
                        <td class="text-right tabular-nums text-slate-500"><?= (int) $m['backlog'] ?></td>
                        <td class="text-right tabular-nums"><?= (int) $m['open_count'] ?></td>
                        <td class="text-right tabular-nums"><?= (int) $m['in_progress'] ?></td>
                        <td class="text-right tabular-nums <?= $m['overdue'] ? 'font-semibold text-red-600' : '' ?>"><?= (int) $m['overdue'] ?></td>
                        <td class="text-right tabular-nums text-emerald-600"><?= (int) $m['done_30d'] ?></td>
                        <td><?= $m['avg_progress'] !== null ? task_progress_bar((int) $m['avg_progress']) : '<span class="text-slate-400">—</span>' ?></td>
                        <td class="whitespace-nowrap text-xs text-slate-500"><?= $all ? (int) $m['done_all'] . ' / ' . $all . ' · ' . pct((int) $m['done_all'], $all, 0) : '—' ?></td>
                        <td class="whitespace-nowrap text-xs text-slate-500"><?= e(time_ago($m['last_update'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <a href="<?= e(url('tasks/index.php', ['view' => $view, 'status' => 'backlog'])) ?>" class="stat-card hover:border-indigo-300"><div class="stat-label">Backlog</div><div class="stat-value text-slate-500"><?= (int) $stats['backlog'] ?></div></a>
        <a href="<?= e(url('tasks/index.php', ['view' => $view, 'status' => 'open'])) ?>" class="stat-card hover:border-indigo-300"><div class="stat-label">Open</div><div class="stat-value"><?= (int) $stats['open_count'] ?></div></a>
        <a href="<?= e(url('tasks/index.php', ['view' => $view, 'status' => 'in_progress'])) ?>" class="stat-card hover:border-indigo-300"><div class="stat-label">In progress</div><div class="stat-value text-blue-600"><?= (int) $stats['in_progress'] ?></div></a>
        <a href="<?= e(url('tasks/index.php', ['view' => $view, 'due' => 'overdue'])) ?>" class="stat-card hover:border-indigo-300"><div class="stat-label">Overdue</div><div class="stat-value <?= $stats['overdue'] ? 'text-red-600' : '' ?>"><?= (int) $stats['overdue'] ?></div></a>
        <a href="<?= e(url('tasks/index.php', ['view' => $view, 'status' => 'done'])) ?>" class="stat-card hover:border-indigo-300"><div class="stat-label">Done this week</div><div class="stat-value text-emerald-600"><?= (int) $stats['done_week'] ?></div></a>
    </div>

    <div class="card overflow-hidden">
        <form method="get" class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3">
            <input type="hidden" name="view" value="<?= e($view) ?>">
            <div class="relative min-w-[12rem] flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><?= icon('search', 'h-4 w-4') ?></span>
                <input class="input !pl-9" name="q" value="<?= e($q) ?>" placeholder="Search tasks…">
            </div>
            <select name="status" class="input !w-auto" onchange="this.form.submit()">
                <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open</option>
                <?php foreach (TASK_STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                <option value="any" <?= $status === 'any' ? 'selected' : '' ?>>Any status</option>
            </select>
            <select name="priority" class="input !w-auto" onchange="this.form.submit()">
                <option value="">Any priority</option>
                <?php foreach (TASK_PRIORITIES as $k => $l): ?><option value="<?= $k ?>" <?= $priority === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
            <select name="due" class="input !w-auto" onchange="this.form.submit()">
                <option value="">Any due date</option>
                <?php foreach (['overdue' => 'Overdue', 'today' => 'Due today', 'week' => 'Due in 7 days', 'none' => 'No due date'] as $k => $l): ?><option value="<?= $k ?>" <?= $due === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
            <?php if ($view === 'all'): ?>
                <select name="assignee" class="input !w-auto" onchange="this.form.submit()">
                    <option value="">Anyone</option>
                    <option value="none" <?= $assignee === 'none' ? 'selected' : '' ?>>Unassigned</option>
                    <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $assignee === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button class="btn-secondary"><?= icon('filter', 'h-4 w-4') ?> Filter</button>
            <?php if ($q !== '' || $status !== 'open' || $priority || $due || $assignee !== ''): ?><a href="<?= e(url('tasks/index.php', ['view' => $view])) ?>" class="btn-ghost">Clear</a><?php endif; ?>
        </form>
        <?php if ($tasks): ?>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr>
                    <th><?= sort_link('title', 'Task') ?></th>
                    <?php if ($view !== 'mine'): ?><th>Assignee</th><?php endif; ?>
                    <th><?= sort_link('priority', 'Priority') ?></th>
                    <th>Status</th>
                    <th><?= sort_link('progress', 'Progress') ?></th>
                    <th><?= sort_link('due', 'Due') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($tasks as $t): ?>
                    <tr>
                        <td class="max-w-md">
                            <a href="<?= e(url('tasks/view.php', ['id' => $t['id']])) ?>" class="block truncate font-medium <?= $t['status'] === 'done' ? 'text-slate-400 line-through' : 'text-slate-900' ?> hover:text-indigo-600"><?= e($t['title']) ?></a>
                            <?php if ($t['customer_id'] || $t['campaign_id']): ?>
                                <div class="truncate text-xs text-slate-500">
                                    <?= $t['customer_id'] ? e(customer_name(['full_name' => $t['full_name'], 'first_name' => $t['first_name'], 'last_name' => $t['last_name'], 'email' => $t['customer_email']])) : '' ?>
                                    <?= $t['customer_id'] && $t['campaign_id'] ? '·' : '' ?>
                                    <?= $t['campaign_id'] ? e($t['campaign_name']) : '' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <?php if ($view !== 'mine'): ?>
                            <td class="whitespace-nowrap">
                                <?php if ($t['assigned_to']): ?>
                                    <span class="flex items-center gap-2"><span class="flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-semibold <?= e(avatar_color($t['assignee_name'])) ?>" title="<?= e($t['assignee_name']) ?>"><?= e(user_initials($t['assignee_name'])) ?></span><?= e($t['assignee_name']) ?></span>
                                <?php else: ?><span class="text-slate-400">Unassigned</span><?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td><?= task_priority_badge($t['priority']) ?></td>
                        <td>
                            <?php if (can_update_task($t)): ?>
                                <form method="post" action="<?= e(url('tasks/update.php')) ?>">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                    <select name="status" class="input !w-auto !py-1 text-xs" onchange="this.form.submit()" aria-label="Status">
                                        <?php foreach (TASK_STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $t['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                                    </select>
                                </form>
                            <?php else: ?><?= task_status_badge($t['status']) ?><?php endif; ?>
                        </td>
                        <td><?= task_progress_bar((int) $t['progress']) ?></td>
                        <td class="whitespace-nowrap text-xs"><?= task_due_label($t) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php if (!$tasks): ?>
            <div class="empty-state">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-50 text-indigo-600"><?= icon('tasks', 'h-6 w-6') ?></span>
                <h3 class="mt-3 text-sm font-semibold">No tasks here</h3>
                <p class="mt-1 text-sm text-slate-500"><?= $view === 'mine' ? 'Nothing is assigned to you with these filters.' : 'No tasks match these filters.' ?></p>
                <a href="<?= e(url('tasks/create.php')) ?>" class="btn-primary mt-4"><?= icon('plus', 'h-4 w-4') ?> New task</a>
            </div>
        <?php else: ?>
            <?= pagination_links($p) ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php';
