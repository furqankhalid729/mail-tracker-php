<?php
/**
 * Payroll: one run per month. POST period=YYYY-MM creates the run (or opens the existing one).
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('payroll.manage');
$ws = ws_id();

if (is_post()) {
    verify_csrf();
    $period = (string) input('period');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        flash('error', 'Choose a month.');
        redirect('payroll/index.php');
    }
    if ($existing = q_val('SELECT id FROM payroll_runs WHERE workspace_id = ? AND period = ?', [$ws, $period])) {
        redirect(url('payroll/run.php', ['id' => $existing]));
    }
    $id = db_insert('payroll_runs', ['workspace_id' => $ws, 'period' => $period, 'status' => 'draft', 'created_by' => user_id(), 'created_at' => now()]);
    [$added] = payroll_build_run(find_payroll_run_or_404($id));
    flash('success', 'Payroll for ' . payroll_period_label($period) . ' created with ' . $added . ' employee' . ($added === 1 ? '' : 's') . '. Review the lines, then mark them paid.');
    redirect(url('payroll/run.php', ['id' => $id]));
}

$runs = q_all(
    "SELECT r.*, COUNT(i.id) employees, COALESCE(SUM(i.status = 'paid'), 0) paid_count
     FROM payroll_runs r LEFT JOIN payroll_items i ON i.run_id = r.id
     WHERE r.workspace_id = ? GROUP BY r.id ORDER BY r.period DESC LIMIT 60",
    [$ws]
);
$totals = [];
foreach (q_all("SELECT run_id, currency, SUM(net_pay) total FROM payroll_items WHERE workspace_id = ? GROUP BY run_id, currency", [$ws]) as $t) {
    $totals[(int) $t['run_id']][$t['currency']] = (float) $t['total'];
}
$noSalary = (int) q_val("SELECT COUNT(*) FROM employees WHERE workspace_id = ? AND status NOT IN ('left', 'terminated') AND (salary IS NULL OR salary = 0)", [$ws]);
$nextPeriod = $runs ? date('Y-m', strtotime($runs[0]['period'] . '-01 +1 month')) : date('Y-m');
if ($nextPeriod > date('Y-m', strtotime('+1 month'))) {
    $nextPeriod = date('Y-m');
}

$page_title = 'Payroll';
$active_nav = 'payroll';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Payroll</h1><p class="page-subtitle">Monthly salaries from your employee records, with payslips.</p></div>
    <form method="post" class="flex items-center gap-2">
        <?= csrf_field() ?>
        <input type="month" name="period" value="<?= e($nextPeriod) ?>" class="input !w-auto" required aria-label="Month">
        <button class="btn-primary"><?= icon('plus', 'h-4 w-4') ?> Run payroll</button>
    </form>
</div>

<?php if ($noSalary): ?>
    <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">
        <?= $noSalary ?> current employee<?= $noSalary === 1 ? ' has' : 's have' ?> no salary set; they will appear with 0 pay. <a class="font-medium underline" href="<?= e(url('employees/index.php')) ?>">Update employees</a>
    </div>
<?php endif; ?>

<div class="card overflow-hidden">
    <?php if ($runs): ?>
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>Month</th><th>Status</th><th class="text-right">Employees</th><th class="text-right">Paid</th><th class="text-right">Net pay</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($runs as $r): ?>
                <tr>
                    <td><a href="<?= e(url('payroll/run.php', ['id' => $r['id']])) ?>" class="font-medium text-slate-900 hover:text-indigo-600"><?= e(payroll_period_label($r['period'])) ?></a></td>
                    <td><?= $r['status'] === 'finalized' ? '<span class="badge badge-green">Finalized</span>' : '<span class="badge badge-amber">Draft</span>' ?></td>
                    <td class="text-right tabular-nums"><?= (int) $r['employees'] ?></td>
                    <td class="text-right tabular-nums <?= (int) $r['paid_count'] === (int) $r['employees'] && $r['employees'] ? 'text-emerald-600' : '' ?>"><?= (int) $r['paid_count'] ?> / <?= (int) $r['employees'] ?></td>
                    <td class="whitespace-nowrap text-right font-medium tabular-nums"><?= e(money_list($totals[(int) $r['id']] ?? [])) ?></td>
                    <td class="text-right"><a href="<?= e(url('payroll/run.php', ['id' => $r['id']])) ?>" class="btn-ghost !py-1 text-xs">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="empty-state">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-50 text-indigo-600"><?= icon('banknotes', 'h-6 w-6') ?></span>
            <h3 class="mt-3 text-sm font-semibold">No payroll yet</h3>
            <p class="mt-1 max-w-md text-sm text-slate-500">Pick a month and run payroll. Every current employee gets a line from their salary, prorated if they joined or left that month, with approved unpaid leave deducted.</p>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
