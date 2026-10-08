<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

if (is_post()) {
    verify_csrf();
    $existing = null;
    require __DIR__ . '/_save.php';
}

$task = [];
$users = workspace_users();
$campaigns = q_all("SELECT id, name FROM campaigns WHERE workspace_id = ? AND status <> 'archived' ORDER BY name", [$ws]);
$customerId = (int) old('customer_id', input_int('customer_id'));
$linkedCustomer = $customerId ? q_one('SELECT id, first_name, last_name, full_name, email FROM customers WHERE id = ? AND workspace_id = ?', [$customerId, $ws]) : null;
if (input_int('campaign_id')) {
    $task['campaign_id'] = input_int('campaign_id');
}
$errors = get_errors();

$page_title = 'New task';
$active_nav = 'tasks';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('tasks/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Tasks</a>
        <h1 class="page-title">New task</h1>
    </div>
</div>
<form method="post" action="<?= e(url('tasks/create.php')) ?>"><?php require __DIR__ . '/_form.php'; ?></form>
<?php require __DIR__ . '/../includes/footer.php';
