<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$task = find_task_or_404(input_int('id'));
if (!can_edit_task($task)) {
    abort(403, 'Only the person who created this task or a manager can edit it.');
}

if (is_post()) {
    verify_csrf();
    $existing = $task;
    require __DIR__ . '/_save.php';
}

$users = workspace_users();
$campaigns = q_all("SELECT id, name FROM campaigns WHERE workspace_id = ? AND (status <> 'archived' OR id = ?) ORDER BY name", [$ws, (int) $task['campaign_id']]);
$customerId = (int) old('customer_id', $task['customer_id']);
$linkedCustomer = $customerId ? q_one('SELECT id, first_name, last_name, full_name, email FROM customers WHERE id = ? AND workspace_id = ?', [$customerId, $ws]) : null;
$errors = get_errors();

$page_title = 'Edit task';
$active_nav = 'tasks';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('tasks/view.php', ['id' => $task['id']])) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> <?= e($task['title']) ?></a>
        <h1 class="page-title">Edit task</h1>
    </div>
</div>
<form method="post" action="<?= e(url('tasks/edit.php', ['id' => $task['id']])) ?>"><?php require __DIR__ . '/_form.php'; ?></form>
<?php require __DIR__ . '/../includes/footer.php';
