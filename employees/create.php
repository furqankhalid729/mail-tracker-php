<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('employees.manage');
$ws = ws_id();

if (is_post()) {
    verify_csrf();
    $existing = null;
    require __DIR__ . '/_save.php';
}

$employee = [];
$users = workspace_users();
$departments = employee_departments();
$errors = get_errors();

$page_title = 'Add employee';
$active_nav = 'employees';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('employees/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Employees</a>
        <h1 class="page-title">Add employee</h1>
    </div>
</div>
<form method="post" action="<?= e(url('employees/create.php')) ?>" enctype="multipart/form-data"><?php require __DIR__ . '/_form.php'; ?></form>
<?php require __DIR__ . '/../includes/footer.php';
