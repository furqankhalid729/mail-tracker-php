<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$task = find_task_or_404(input_int('id'));
$tid = (int) $task['id'];
$canUpdate = can_update_task($task);
$canEdit = can_edit_task($task);

$updates = q_all(
    'SELECT tu.*, u.name user_name FROM task_updates tu LEFT JOIN users u ON u.id = tu.user_id
     WHERE tu.task_id = ? AND tu.workspace_id = ? ORDER BY tu.id DESC LIMIT 200',
    [$tid, $ws]
);

$page_title = $task['title'];
$active_nav = 'tasks';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div class="min-w-0">
        <a href="<?= e(url('tasks/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Tasks</a>
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="page-title <?= $task['status'] === 'done' ? 'text-slate-500 line-through decoration-slate-300' : '' ?>"><?= e($task['title']) ?></h1>
            <?= task_status_badge($task['status']) ?>
            <?= task_priority_badge($task['priority']) ?>
        </div>
        <p class="page-subtitle">Created by <?= e($task['creator_name'] ?: 'a former member') ?> · <?= e(time_ago($task['created_at'])) ?></p>
    </div>
    <?php if ($canEdit): ?>
    <div class="flex gap-2">
        <a href="<?= e(url('tasks/edit.php', ['id' => $tid])) ?>" class="btn-secondary"><?= icon('pencil', 'h-4 w-4') ?> Edit</a>
        <form method="post" action="<?= e(url('tasks/delete.php')) ?>" data-confirm="Delete “<?= e($task['title']) ?>” and its history?" data-confirm-button="Delete">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $tid ?>">
            <button class="btn-secondary text-red-600" aria-label="Delete task" title="Delete task"><?= icon('trash', 'h-4 w-4') ?></button>
        </form>
    </div>
    <?php endif; ?>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Description</h2></div>
            <div class="card-body text-sm leading-relaxed text-slate-700">
                <?= $task['description'] ? nl2br(e($task['description'])) : '<span class="text-slate-400">No description.</span>' ?>
            </div>
        </div>

        <?php if ($canUpdate): ?>
        <form method="post" action="<?= e(url('tasks/update.php')) ?>" class="card" x-data="{ progress: <?= (int) $task['progress'] ?>, status: <?= e(json_encode($task['status'])) ?> }">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $tid ?>">
            <div class="card-header"><div><h2 class="card-title">Update progress</h2><p class="text-xs text-slate-500">Post where things stand. The team sees it in the timeline below.</p></div></div>
            <div class="card-body space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="status">Status</label>
                        <select class="input" id="status" name="status" x-model="status" @change="if (status === 'done') progress = 100; else if (progress >= 100 && status !== 'review') progress = 90">
                            <?php foreach (TASK_STATUSES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="label" for="progress">Progress <span class="font-semibold text-indigo-600" x-text="progress + '%'"></span></label>
                        <input type="range" id="progress" name="progress" min="0" max="100" step="5" x-model.number="progress" class="mt-2 w-full accent-indigo-600">
                    </div>
                </div>
                <div>
                    <label class="label" for="comment">Comment</label>
                    <textarea class="input" id="comment" name="comment" rows="3" maxlength="5000" placeholder="What did you do? Anything blocking you?"></textarea>
                </div>
                <div class="flex justify-end"><button class="btn-primary"><?= icon('send', 'h-4 w-4') ?> Post update</button></div>
            </div>
        </form>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Timeline</h2><span class="text-xs text-slate-400"><?= count($updates) ?> update<?= count($updates) === 1 ? '' : 's' ?></span></div>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($updates as $u):
                    $who = '<span class="font-medium text-slate-800">' . e($u['user_name'] ?: 'Someone') . '</span>';
                    $text = match ($u['type']) {
                        'status' => $who . ' moved the task to ' . task_status_badge((string) $u['body']),
                        'progress' => $who . ' set progress to <b>' . (int) $u['body'] . '%</b>',
                        'comment' => $who . ' commented',
                        default => $who . ' ' . e(lcfirst((string) $u['body'])),
                    };
                ?>
                    <li class="flex gap-3 px-5 py-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold <?= e(avatar_color((string) ($u['user_name'] ?? '?'))) ?>" title="<?= e($u['user_name']) ?>"><?= e(user_initials($u['user_name'])) ?></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-600"><?= $text ?><span class="text-xs text-slate-400"><?= e(time_ago($u['created_at'])) ?></span></div>
                            <?php if ($u['type'] === 'comment'): ?>
                                <div class="mt-1.5 whitespace-pre-line rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700"><?= e($u['body']) ?></div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$updates): ?><li class="px-5 py-10 text-center text-sm text-slate-400">No updates yet.</li><?php endif; ?>
            </ul>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Progress</h2><span class="text-lg font-semibold tabular-nums text-slate-900"><?= (int) $task['progress'] ?>%</span></div>
            <div class="card-body"><?= task_progress_bar((int) $task['progress'], 'w-full') ?></div>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Details</h2></div>
            <dl class="divide-y divide-slate-100 text-sm">
                <div class="flex items-center justify-between gap-3 px-5 py-3">
                    <dt class="text-slate-500">Assignee</dt>
                    <dd class="text-right">
                        <?php if ($task['assigned_to']): ?>
                            <span class="font-medium text-slate-800"><?= e($task['assignee_name']) ?></span><?= (int) $task['assigned_to'] === user_id() ? ' <span class="text-xs text-slate-400">(you)</span>' : '' ?>
                        <?php else: ?><span class="text-slate-400">Unassigned</span><?php endif; ?>
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 px-5 py-3"><dt class="text-slate-500">Due</dt><dd><?= task_due_label($task) ?></dd></div>
                <div class="flex items-center justify-between gap-3 px-5 py-3"><dt class="text-slate-500">Priority</dt><dd><?= task_priority_badge($task['priority']) ?></dd></div>
                <?php if ($task['customer_id']): ?>
                    <div class="flex items-center justify-between gap-3 px-5 py-3"><dt class="text-slate-500">Customer</dt><dd class="min-w-0 truncate"><a class="font-medium text-indigo-600 hover:underline" href="<?= e(url('customers/view.php', ['id' => $task['customer_id']])) ?>"><?= e(customer_name(['full_name' => $task['full_name'], 'first_name' => $task['first_name'], 'last_name' => $task['last_name'], 'email' => $task['customer_email']])) ?></a></dd></div>
                <?php endif; ?>
                <?php if ($task['campaign_id']): ?>
                    <div class="flex items-center justify-between gap-3 px-5 py-3"><dt class="text-slate-500">Campaign</dt><dd class="min-w-0 truncate"><a class="font-medium text-indigo-600 hover:underline" href="<?= e(url('campaigns/view.php', ['id' => $task['campaign_id']])) ?>"><?= e($task['campaign_name']) ?></a></dd></div>
                <?php endif; ?>
                <div class="flex items-center justify-between gap-3 px-5 py-3"><dt class="text-slate-500">Last updated</dt><dd class="text-slate-600"><?= e(time_ago($task['updated_at'])) ?></dd></div>
                <?php if ($task['completed_at']): ?>
                    <div class="flex items-center justify-between gap-3 px-5 py-3"><dt class="text-slate-500">Completed</dt><dd class="text-emerald-600"><?= e(format_dt($task['completed_at'], 'M j, Y')) ?></dd></div>
                <?php endif; ?>
            </dl>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php';
