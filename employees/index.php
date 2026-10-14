<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('employees.manage');
$ws = ws_id();
$current = placeholders(EMPLOYEE_CURRENT_STATUSES);

$where = 'e.workspace_id = ?';
$params = [$ws];
$status = (string) input('status', 'current');
if ($status === 'current') {
    $where .= " AND e.status IN ($current)";
    $params = [...$params, ...EMPLOYEE_CURRENT_STATUSES];
} elseif ($status === 'former') {
    $where .= " AND e.status NOT IN ($current)";
    $params = [...$params, ...EMPLOYEE_CURRENT_STATUSES];
} elseif (array_key_exists($status, EMPLOYEE_STATUSES)) {
    $where .= ' AND e.status = ?';
    $params[] = $status;
} else {
    $status = 'any';
}
$type = array_key_exists((string) input('type'), EMPLOYEE_TYPES) ? (string) input('type') : '';
if ($type) {
    $where .= ' AND e.employment_type = ?';
    $params[] = $type;
}
$departments = employee_departments();
$department = in_array((string) input('department'), $departments, true) ? (string) input('department') : '';
if ($department !== '') {
    $where .= ' AND e.department = ?';
    $params[] = $department;
}
$q = trim((string) input('q'));
if ($q !== '') {
    $where .= ' AND (e.full_name LIKE ? OR e.email LIKE ? OR e.phone LIKE ? OR e.employee_code LIKE ? OR e.job_title LIKE ?)';
    $params = [...$params, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"];
}

$stats = q_one(
    "SELECT COALESCE(SUM(status IN ($current)), 0) current_count,
        COALESCE(SUM(status = 'working'), 0) working,
        COALESCE(SUM(status = 'probation'), 0) probation,
        COALESCE(SUM(status IN ('on_leave', 'notice_period')), 0) away,
        COALESCE(SUM(status NOT IN ($current)), 0) former
     FROM employees WHERE workspace_id = ?",
    [...EMPLOYEE_CURRENT_STATUSES, ...EMPLOYEE_CURRENT_STATUSES, $ws]
);
$expiring = (int) q_val(
    'SELECT COUNT(*) FROM employee_documents d JOIN employees e ON e.id = d.employee_id
     WHERE d.workspace_id = ? AND d.expires_at IS NOT NULL AND d.expires_at <= ? AND e.status IN (' . $current . ')',
    [$ws, date('Y-m-d', strtotime('+30 days')), ...EMPLOYEE_CURRENT_STATUSES]
);

$order = sort_sql([
    'name' => 'e.full_name',
    'joined' => 'e.join_date IS NULL, e.join_date',
    'department' => "e.department IS NULL, e.department",
    'status' => "FIELD(e.status, 'working', 'probation', 'on_leave', 'notice_period', 'left', 'terminated')",
    'updated' => 'e.updated_at',
], 'name', 'asc');
$total = (int) q_val("SELECT COUNT(*) FROM employees e WHERE $where", $params);
$p = paginate($total, per_page(25));
$employees = q_all(
    "SELECT e.*, (SELECT COUNT(*) FROM employee_documents d WHERE d.employee_id = e.id) doc_count
     FROM employees e WHERE $where ORDER BY $order, e.id DESC LIMIT ? OFFSET ?",
    [...$params, $p['per_page'], $p['offset']]
);
$filtered = $q !== '' || $status !== 'current' || $type || $department !== '';
$hasAny = $stats['current_count'] + $stats['former'] > 0;

$page_title = 'Employees';
$active_nav = 'employees';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Employees</h1><p class="page-subtitle">Staff records, employment status, contracts and documents.</p></div>
    <a href="<?= e(url('employees/create.php')) ?>" class="btn-primary"><?= icon('plus', 'h-4 w-4') ?> Add employee</a>
</div>

<?php if ($expiring): ?>
    <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">
        <b><?= $expiring ?></b> <?= $expiring === 1 ? 'document of a current employee has' : 'documents of current employees have' ?> expired or <?= $expiring === 1 ? 'expires' : 'expire' ?> within 30 days. Open the employee to renew <?= $expiring === 1 ? 'it' : 'them' ?>.
    </div>
<?php endif; ?>

<div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
    <a href="<?= e(url('employees/index.php', ['status' => 'current'])) ?>" class="stat-card hover:border-indigo-300"><div class="stat-label">Current staff</div><div class="stat-value"><?= (int) $stats['current_count'] ?></div></a>
    <a href="<?= e(url('employees/index.php', ['status' => 'working'])) ?>" class="stat-card hover:border-indigo-300"><div class="stat-label">Working</div><div class="stat-value text-emerald-600"><?= (int) $stats['working'] ?></div></a>
    <a href="<?= e(url('employees/index.php', ['status' => 'probation'])) ?>" class="stat-card hover:border-indigo-300"><div class="stat-label">On probation</div><div class="stat-value text-sky-600"><?= (int) $stats['probation'] ?></div></a>
    <a href="<?= e(url('employees/index.php', ['status' => 'on_leave'])) ?>" class="stat-card hover:border-indigo-300"><div class="stat-label">On leave / notice</div><div class="stat-value text-amber-600"><?= (int) $stats['away'] ?></div></a>
    <a href="<?= e(url('employees/index.php', ['status' => 'former'])) ?>" class="stat-card hover:border-indigo-300"><div class="stat-label">Left</div><div class="stat-value text-slate-500"><?= (int) $stats['former'] ?></div></a>
</div>

<div class="card overflow-hidden">
    <form method="get" class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3">
        <div class="relative min-w-[12rem] flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><?= icon('search', 'h-4 w-4') ?></span>
            <input class="input !pl-9" name="q" value="<?= e($q) ?>" placeholder="Search name, email, phone, ID, job title…">
        </div>
        <select name="status" class="input !w-auto" onchange="this.form.submit()">
            <option value="current" <?= $status === 'current' ? 'selected' : '' ?>>Current staff</option>
            <option value="former" <?= $status === 'former' ? 'selected' : '' ?>>Former staff</option>
            <?php foreach (EMPLOYEE_STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            <option value="any" <?= $status === 'any' ? 'selected' : '' ?>>Any status</option>
        </select>
        <select name="type" class="input !w-auto" onchange="this.form.submit()">
            <option value="">Any type</option>
            <?php foreach (EMPLOYEE_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <?php if ($departments): ?>
            <select name="department" class="input !w-auto" onchange="this.form.submit()">
                <option value="">Any department</option>
                <?php foreach ($departments as $d): ?><option value="<?= e($d) ?>" <?= $department === $d ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button class="btn-secondary"><?= icon('filter', 'h-4 w-4') ?> Filter</button>
        <?php if ($filtered): ?><a href="<?= e(url('employees/index.php')) ?>" class="btn-ghost">Clear</a><?php endif; ?>
    </form>
    <?php if ($employees): ?>
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr>
                <th><?= sort_link('name', 'Employee') ?></th>
                <th><?= sort_link('department', 'Role') ?></th>
                <th>Type</th>
                <th><?= sort_link('status', 'Status') ?></th>
                <th><?= sort_link('joined', 'Joined') ?></th>
                <th class="text-right">Docs</th>
            </tr></thead>
            <tbody>
            <?php foreach ($employees as $emp): ?>
                <tr>
                    <td>
                        <a href="<?= e(url('employees/view.php', ['id' => $emp['id']])) ?>" class="flex items-center gap-3 whitespace-nowrap">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold <?= e(avatar_color($emp['full_name'])) ?>"><?= e(user_initials($emp['full_name'])) ?></span>
                            <span class="min-w-0">
                                <span class="block font-medium text-slate-900 hover:text-indigo-600"><?= e($emp['full_name']) ?><?php if ($emp['employee_code']): ?> <span class="text-xs font-normal text-slate-400">#<?= e($emp['employee_code']) ?></span><?php endif; ?></span>
                                <span class="block truncate text-xs text-slate-500"><?= e($emp['email'] ?: ($emp['phone'] ?: '')) ?></span>
                            </span>
                        </a>
                    </td>
                    <td class="whitespace-nowrap">
                        <span class="block text-slate-800"><?= e($emp['job_title'] ?: '—') ?></span>
                        <?php if ($emp['department']): ?><span class="block text-xs text-slate-500"><?= e($emp['department']) ?></span><?php endif; ?>
                    </td>
                    <td class="whitespace-nowrap text-slate-600"><?= e(EMPLOYEE_TYPES[$emp['employment_type']] ?? '') ?></td>
                    <td><?= employee_status_badge($emp['status']) ?></td>
                    <td class="whitespace-nowrap text-xs text-slate-500">
                        <?= $emp['join_date'] ? e(format_dt($emp['join_date'], 'M j, Y')) : '—' ?>
                        <?php if ($emp['join_date']): ?><span class="block text-slate-400"><?= e(employee_tenure($emp['join_date'], in_array($emp['status'], EMPLOYEE_CURRENT_STATUSES, true) ? null : $emp['leave_date'])) ?></span><?php endif; ?>
                    </td>
                    <td class="text-right tabular-nums text-slate-500"><?= (int) $emp['doc_count'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($p) ?>
    <?php else: ?>
        <div class="empty-state">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-50 text-indigo-600"><?= icon('briefcase', 'h-6 w-6') ?></span>
            <h3 class="mt-3 text-sm font-semibold"><?= $filtered || $hasAny ? 'No employees match these filters' : 'No employees yet' ?></h3>
            <p class="mt-1 text-sm text-slate-500"><?= $filtered || $hasAny ? 'Try another status or search, e.g. “Former staff”.' : 'Add your staff to keep their details, status and contracts in one place.' ?></p>
            <a href="<?= e(url('employees/create.php')) ?>" class="btn-primary mt-4"><?= icon('plus', 'h-4 w-4') ?> Add employee</a>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
