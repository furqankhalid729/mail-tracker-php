<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('employees.manage');
$ws = ws_id();
$employee = find_employee_or_404(input_int('id'));

if (is_post()) {
    verify_csrf();
    $existing = $employee;
    require __DIR__ . '/_save.php';
}

$users = workspace_users();
$departments = employee_departments();
$errors = get_errors();

$page_title = 'Edit employee';
$active_nav = 'employees';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('employees/view.php', ['id' => $employee['id']])) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> <?= e($employee['full_name']) ?></a>
        <h1 class="page-title">Edit employee</h1>
    </div>
</div>
<form method="post" action="<?= e(url('employees/edit.php', ['id' => $employee['id']])) ?>"><?php require __DIR__ . '/_form.php'; ?></form>
<?php require __DIR__ . '/../includes/footer.php';
