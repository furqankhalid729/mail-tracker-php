<?php
/**
 * Expenses for a month: totals (in APP_CURRENCY), split by category, subscriptions due soon,
 * the last 6 months of spending next to payroll, and the list of expenses.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('expenses.manage');
$ws = ws_id();

$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) input('month')) ? (string) input('month') : date('Y-m');
$from = "$month-01";
$to = date('Y-m-d', strtotime("$from +1 month"));
$category = isset(EXPENSE_CATEGORIES[input('category')]) ? (string) input('category') : '';
$q = mb_substr(trim((string) input('q')), 0, 100);

$where = 'workspace_id = ? AND expense_date >= ? AND expense_date < ?';
$params = [$ws, $from, $to];
$monthTotals = [];
foreach (q_all("SELECT currency, SUM(amount) total FROM expenses WHERE $where GROUP BY currency", $params) as $r) {
    $monthTotals[$r['currency']] = (float) $r['total'];
}
$byCategory = q_all("SELECT category, currency, SUM(amount) total, COUNT(*) n FROM expenses WHERE $where GROUP BY category, currency ORDER BY total DESC", $params);

if ($category) {
    $where .= ' AND category = ?';
    $params[] = $category;
}
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
    $where .= ' AND (title LIKE ? OR vendor LIKE ? OR reference LIKE ? OR notes LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
$total = (int) q_val("SELECT COUNT(*) FROM expenses WHERE $where", $params);
$p = paginate($total, per_page(50));
$expenses = q_all("SELECT * FROM expenses WHERE $where ORDER BY expense_date DESC, id DESC LIMIT ? OFFSET ?", [...$params, $p['per_page'], $p['offset']]);

// Last 6 months: expenses and payroll (net pay of each month's run) per currency
$trend = [];
$trendStart = date('Y-m-01', strtotime("$from -5 months"));
for ($i = 0; $i < 6; $i++) {
    $trend[date('Y-m', strtotime("$trendStart +$i months"))] = ['expenses' => [], 'payroll' => []];
}
foreach (q_all("SELECT DATE_FORMAT(expense_date, '%Y-%m') ym, currency, SUM(amount) total FROM expenses WHERE workspace_id = ? AND expense_date >= ? AND expense_date < ? GROUP BY ym, currency", [$ws, $trendStart, $to]) as $r) {
    $trend[$r['ym']]['expenses'][$r['currency']] = (float) $r['total'];
}
$canPayroll = allowed('payroll.manage');
if ($canPayroll) {
    foreach (q_all('SELECT r.period, i.currency, SUM(i.net_pay) total FROM payroll_runs r JOIN payroll_items i ON i.run_id = r.id WHERE r.workspace_id = ? AND r.period >= ? AND r.period <= ? GROUP BY r.period, i.currency', [$ws, substr($trendStart, 0, 7), $month]) as $r) {
        $trend[$r['period']]['payroll'][$r['currency']] = (float) $r['total'];
    }
}
$dueSoon = subscriptions_due_soon(7);
$months = [];
for ($i = 0; $i < 18; $i++) {
    $months[] = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
}
$filtered = $category || $q !== '';
$catMax = max([1, ...array_map(fn($r) => (float) $r['total'], $byCategory)]);

$page_title = 'Expenses';
$active_nav = 'expenses';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Expenses</h1><p class="page-subtitle">Company spending, receipts and recurring subscriptions. Salaries live in Payroll.</p></div>
    <div class="flex flex-wrap gap-2">
        <a href="<?= e(url('expenses/subscriptions.php')) ?>" class="btn-secondary"><?= icon('clock', 'h-4 w-4') ?> Subscriptions</a>
        <a href="<?= e(url('expenses/edit.php')) ?>" class="btn-primary"><?= icon('plus', 'h-4 w-4') ?> Add expense</a>
    </div>
</div>

<?php if ($dueSoon): ?>
    <div class="card mb-6 overflow-hidden ring-1 ring-amber-200">
        <div class="card-header bg-amber-50"><h2 class="card-title text-amber-900">Subscriptions due within 7 days</h2><a href="<?= e(url('expenses/subscriptions.php')) ?>" class="text-xs font-medium text-amber-800 underline">All subscriptions</a></div>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($dueSoon as $s): ?>
                <li class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                    <span class="min-w-0 flex-1"><span class="font-medium text-slate-900"><?= e($s['name']) ?></span> <span class="text-slate-500">· <?= e(money($s['amount'], $s['currency'])) ?> <?= e(strtolower(BILLING_CYCLES[$s['billing_cycle']])) ?></span></span>
                    <?= subscription_due_badge($s['next_due']) ?>
                    <form method="post" action="<?= e(url('expenses/actions.php')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="action" value="pay_subscription"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="paid_on" value="<?= e($s['next_due'] <= date('Y-m-d') ? $s['next_due'] : date('Y-m-d')) ?>">
                        <button class="btn-secondary btn-sm"><?= icon('check', 'h-4 w-4') ?> Mark paid</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="mb-4 flex flex-wrap items-center gap-2">
    <form method="get" class="flex items-center gap-2">
        <select name="month" class="input !w-auto" onchange="this.form.submit()" aria-label="Month">
            <?php foreach ($months as $m): ?><option value="<?= e($m) ?>" <?= $m === $month ? 'selected' : '' ?>><?= e(date('F Y', strtotime("$m-01"))) ?></option><?php endforeach; ?>
        </select>
    </form>
</div>

<div class="mb-6 grid gap-6 xl:grid-cols-3">
    <div class="space-y-4">
        <div class="stat-card"><div class="stat-label">Spent in <?= e(date('F', strtotime($from))) ?></div><div class="stat-value text-xl"><?= e(money_list($monthTotals)) ?></div></div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">By category</h2></div>
            <?php if ($byCategory): ?>
            <ul class="space-y-3 p-5 text-sm">
                <?php foreach ($byCategory as $c): [$label, $color] = EXPENSE_CATEGORIES[$c['category']] ?? EXPENSE_CATEGORIES['other']; ?>
                    <li>
                        <a href="<?= e(url('expenses/index.php', ['month' => $month, 'category' => $category === $c['category'] ? null : $c['category']])) ?>" class="block hover:opacity-80">
                            <div class="flex justify-between gap-2"><span class="<?= $category === $c['category'] ? 'font-semibold' : '' ?> text-slate-700"><?= e($label) ?></span><span class="tabular-nums text-slate-900"><?= e(money($c['total'], $c['currency'])) ?></span></div>
                            <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full" style="width: <?= max(2, (int) round((float) $c['total'] / $catMax * 100)) ?>%; background: <?= e($color) ?>"></div></div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?><p class="px-5 py-8 text-center text-sm text-slate-400">Nothing spent this month.</p><?php endif; ?>
        </div>
    </div>

    <div class="card overflow-hidden xl:col-span-2">
        <div class="card-header"><h2 class="card-title">Last 6 months</h2><span class="text-xs text-slate-400">in <?= e(APP_CURRENCY) ?></span></div>
        <div class="overflow-x-auto">
            <table class="table text-sm">
                <thead><tr><th>Month</th><th class="text-right">Expenses</th><?php if ($canPayroll): ?><th class="text-right">Payroll (net)</th><th class="text-right">Total</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach (array_reverse($trend, true) as $ym => $t):
                    $sum = $t['expenses'];
                    foreach ($t['payroll'] as $c => $a) { $sum[$c] = ($sum[$c] ?? 0) + $a; } ?>
                    <tr class="<?= $ym === $month ? 'bg-indigo-50/50' : '' ?>">
                        <td class="whitespace-nowrap"><a href="<?= e(url('expenses/index.php', ['month' => $ym])) ?>" class="<?= $ym === $month ? 'font-semibold text-indigo-700' : 'text-slate-700 hover:text-indigo-600' ?>"><?= e(date('M Y', strtotime("$ym-01"))) ?></a></td>
                        <td class="whitespace-nowrap text-right tabular-nums"><?= e(money_list($t['expenses'])) ?></td>
                        <?php if ($canPayroll): ?>
                            <td class="whitespace-nowrap text-right tabular-nums text-slate-600"><?= e(money_list($t['payroll'])) ?></td>
                            <td class="whitespace-nowrap text-right font-semibold tabular-nums"><?= e(money_list($sum)) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card overflow-hidden">
    <form method="get" class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3">
        <input type="hidden" name="month" value="<?= e($month) ?>">
        <div class="relative min-w-[12rem] flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><?= icon('search', 'h-4 w-4') ?></span>
            <input class="input !pl-9" name="q" value="<?= e($q) ?>" placeholder="Search description, vendor, reference…">
        </div>
        <select name="category" class="input !w-auto" onchange="this.form.submit()" aria-label="Category">
            <option value="">All categories</option>
            <?php foreach (EXPENSE_CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $category === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <button class="btn-secondary"><?= icon('filter', 'h-4 w-4') ?> Filter</button>
        <?php if ($filtered): ?><a href="<?= e(url('expenses/index.php', ['month' => $month])) ?>" class="btn-ghost">Clear</a><?php endif; ?>
    </form>
    <?php if ($expenses): ?>
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>Date</th><th>Description</th><th>Category</th><th class="text-right">Amount</th><th>Paid with</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($expenses as $x): $xid = (int) $x['id']; ?>
                <tr>
                    <td class="whitespace-nowrap text-xs text-slate-500"><?= e(format_dt($x['expense_date'], 'M j, Y')) ?></td>
                    <td class="max-w-md">
                        <a href="<?= e(url('expenses/edit.php', ['id' => $xid])) ?>" class="block truncate font-medium text-slate-900 hover:text-indigo-600"><?= e($x['title']) ?></a>
                        <div class="truncate text-xs text-slate-500"><?= e(implode(' · ', array_filter([$x['vendor'], $x['reference'] ? '#' . $x['reference'] : null, $x['subscription_id'] ? 'Subscription' : null]))) ?></div>
                    </td>
                    <td><?= expense_category_chip($x['category']) ?></td>
                    <td class="whitespace-nowrap text-right font-medium tabular-nums text-slate-900"><?= e(money($x['amount'], $x['currency'])) ?></td>
                    <td class="whitespace-nowrap text-xs text-slate-500"><?= e($x['payment_method'] ?: '—') ?></td>
                    <td class="whitespace-nowrap text-right">
                        <?php if ($x['receipt_path']): ?><a href="<?= e(url('expenses/receipt.php', ['id' => $xid])) ?>" target="_blank" rel="noopener" class="btn-icon" title="Receipt: <?= e($x['receipt_name']) ?>" aria-label="Open receipt"><?= icon('paperclip', 'h-4 w-4') ?></a><?php endif; ?>
                        <a href="<?= e(url('expenses/edit.php', ['id' => $xid])) ?>" class="btn-icon" title="Edit" aria-label="Edit expense"><?= icon('pencil', 'h-4 w-4') ?></a>
                        <form method="post" action="<?= e(url('expenses/actions.php')) ?>" class="inline" data-confirm="Delete “<?= e($x['title']) ?>”?" data-confirm-button="Delete"><?= csrf_field() ?><input type="hidden" name="action" value="delete_expense"><input type="hidden" name="id" value="<?= $xid ?>"><button class="btn-icon text-red-600" title="Delete" aria-label="Delete expense"><?= icon('trash', 'h-4 w-4') ?></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($p) ?>
    <?php else: ?>
        <div class="empty-state">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-50 text-indigo-600"><?= icon('receipt', 'h-6 w-6') ?></span>
            <h3 class="mt-3 text-sm font-semibold"><?= $filtered ? 'No expenses match these filters' : 'No expenses in ' . e(date('F Y', strtotime($from))) ?></h3>
            <a href="<?= e(url('expenses/edit.php')) ?>" class="btn-primary mt-4"><?= icon('plus', 'h-4 w-4') ?> Add expense</a>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
