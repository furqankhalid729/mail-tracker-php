<?php
/**
 * Leads → Approvals: appointments (statuses that need a Super Admin's approval) per closer and month.
 * Counts come from lead_approvals, one row per booking, so they stay right after leads change status or are deleted.
 * A booking counts in the month it was booked; cancelled requests (the lead left the status before a decision) are left out.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
if (!can_view_lead_approvals()) {
    abort(403, 'Only Admins and Super Admins can see approvals.');
}
$ws = ws_id();

$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) input('month')) ? (string) input('month') : date('Y-m');
$from = $month . '-01 00:00:00';
$to = date('Y-m-d 00:00:00', strtotime($from . ' +1 month'));
$months = [];
for ($i = 0; $i < 12; $i++) {
    $months[] = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
}
if (!in_array($month, $months, true)) {
    $months[] = $month;
}

// Per closer for the month
$perCloser = q_all(
    "SELECT r.booked_by, u.name closer_name,
        COUNT(*) booked,
        COALESCE(SUM(r.decision = 'approved'), 0) approved,
        COALESCE(SUM(r.decision = 'rejected'), 0) rejected,
        COALESCE(SUM(r.decision = 'pending'), 0) pending
     FROM lead_approvals r LEFT JOIN users u ON u.id = r.booked_by
     WHERE r.workspace_id = ? AND r.booked_at >= ? AND r.booked_at < ? AND r.decision <> 'cancelled'
     GROUP BY r.booked_by, u.name ORDER BY approved DESC, booked DESC, u.name",
    [$ws, $from, $to]
);
$totals = ['booked' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0];
foreach ($perCloser as $r) {
    foreach ($totals as $k => $_) {
        $totals[$k] += (int) $r[$k];
    }
}
$rate = fn(int $approved, int $rejected) => $approved + $rejected ? round($approved / ($approved + $rejected) * 100) . '%' : '—';

// Approved per closer over the last 6 months (ending with the chosen month)
$trendMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $trendMonths[] = date('Y-m', strtotime($from . " -$i months"));
}
$trendRows = q_all(
    "SELECT r.booked_by, u.name closer_name, DATE_FORMAT(r.booked_at, '%Y-%m') ym, COUNT(*) approved
     FROM lead_approvals r LEFT JOIN users u ON u.id = r.booked_by
     WHERE r.workspace_id = ? AND r.decision = 'approved' AND r.booked_at >= ? AND r.booked_at < ?
     GROUP BY r.booked_by, u.name, ym",
    [$ws, $trendMonths[0] . '-01 00:00:00', $to]
);
$trend = [];
foreach ($trendRows as $r) {
    $key = (string) ($r['booked_by'] ?? '0');
    $trend[$key]['name'] ??= $r['closer_name'] ?: 'Former member / unknown';
    $trend[$key][$r['ym']] = (int) $r['approved'];
}
uasort($trend, fn($a, $b) => array_sum(array_filter($b, 'is_int')) <=> array_sum(array_filter($a, 'is_int')));

// Requests of the month
$closer = (string) input('closer');
$decision = in_array(input('decision'), ['pending', 'approved', 'rejected', 'cancelled'], true) ? (string) input('decision') : '';
$where = 'r.workspace_id = ? AND r.booked_at >= ? AND r.booked_at < ?';
$params = [$ws, $from, $to];
if ($closer === 'none') {
    $where .= ' AND r.booked_by IS NULL';
} elseif ((int) $closer > 0) {
    $where .= ' AND r.booked_by = ?';
    $params[] = (int) $closer;
}
if ($decision) {
    $where .= ' AND r.decision = ?';
    $params[] = $decision;
} else {
    $where .= " AND r.decision <> 'cancelled'";
}
$total = (int) q_val("SELECT COUNT(*) FROM lead_approvals r WHERE $where", $params);
$p = paginate($total, per_page(25));
$rows = q_all(
    "SELECT r.*, bk.name closer_name, d.name decider_name, l.id live_lead_id
     FROM lead_approvals r LEFT JOIN users bk ON bk.id = r.booked_by LEFT JOIN users d ON d.id = r.decided_by
     LEFT JOIN leads l ON l.id = r.lead_id AND l.workspace_id = r.workspace_id
     WHERE $where ORDER BY r.booked_at DESC, r.id DESC LIMIT ? OFFSET ?",
    [...$params, $p['per_page'], $p['offset']]
);
$closers = q_all('SELECT DISTINCT u.id, u.name FROM lead_approvals r JOIN users u ON u.id = r.booked_by WHERE r.workspace_id = ? ORDER BY u.name', [$ws]);
$decisionBadge = fn(string $d) => match ($d) {
    'approved' => '<span class="badge badge-green">Approved</span>',
    'rejected' => '<span class="badge badge-red">Disapproved</span>',
    'pending' => '<span class="badge badge-amber">Awaiting approval</span>',
    default => '<span class="badge badge-slate">Cancelled</span>',
};
$monthLabel = fn(string $ym) => date('F Y', strtotime($ym . '-01'));

$page_title = 'Approvals';
$active_nav = 'leads';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('leads/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Leads</a>
        <h1 class="page-title">Approvals</h1>
        <p class="page-subtitle">Appointments booked by each closer and the Super Admin’s decisions, by the month they were booked.</p>
    </div>
    <form method="get" class="flex items-center gap-2">
        <select name="month" class="input !w-auto" onchange="this.form.submit()" aria-label="Month">
            <?php foreach ($months as $m): ?><option value="<?= e($m) ?>" <?= $m === $month ? 'selected' : '' ?>><?= e($monthLabel($m)) ?></option><?php endforeach; ?>
        </select>
        <?php if (allowed('leads.approve') && ($pendingNow = lead_pending_approval_count())): ?>
            <a href="<?= e(url('leads/index.php', ['approval' => 'pending'])) ?>" class="btn-primary whitespace-nowrap"><?= icon('clock', 'h-4 w-4') ?> <?= $pendingNow ?> awaiting you</a>
        <?php endif; ?>
    </form>
</div>

<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
    <div class="stat-card"><div class="stat-label">Booked</div><div class="stat-value"><?= $totals['booked'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Approved</div><div class="stat-value text-emerald-600"><?= $totals['approved'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Disapproved</div><div class="stat-value text-red-600"><?= $totals['rejected'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Awaiting approval</div><div class="stat-value text-amber-600"><?= $totals['pending'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Approval rate</div><div class="stat-value"><?= $rate($totals['approved'], $totals['rejected']) ?></div></div>
</div>

<div class="grid gap-6 xl:grid-cols-2">
    <div class="card overflow-hidden">
        <div class="card-header"><h2 class="card-title">By closer · <?= e($monthLabel($month)) ?></h2></div>
        <?php if ($perCloser): ?>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Closer</th><th class="text-right">Booked</th><th class="text-right">Approved</th><th class="text-right">Disapproved</th><th class="text-right">Waiting</th><th class="text-right">Rate</th></tr></thead>
                <tbody>
                <?php foreach ($perCloser as $r): ?>
                    <tr>
                        <td class="whitespace-nowrap">
                            <a href="<?= e(url('leads/approvals.php', ['month' => $month, 'closer' => $r['booked_by'] ?? 'none'])) ?>#requests" class="font-medium text-slate-900 hover:text-indigo-600"><?= e($r['closer_name'] ?: 'Former member / unknown') ?></a>
                        </td>
                        <td class="text-right tabular-nums"><?= (int) $r['booked'] ?></td>
                        <td class="text-right font-semibold tabular-nums text-emerald-600"><?= (int) $r['approved'] ?></td>
                        <td class="text-right tabular-nums text-red-600"><?= (int) $r['rejected'] ?></td>
                        <td class="text-right tabular-nums text-amber-600"><?= (int) $r['pending'] ?></td>
                        <td class="text-right tabular-nums"><?= $rate((int) $r['approved'], (int) $r['rejected']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="font-semibold"><td>Total</td><td class="text-right tabular-nums"><?= $totals['booked'] ?></td><td class="text-right tabular-nums text-emerald-600"><?= $totals['approved'] ?></td><td class="text-right tabular-nums text-red-600"><?= $totals['rejected'] ?></td><td class="text-right tabular-nums text-amber-600"><?= $totals['pending'] ?></td><td class="text-right tabular-nums"><?= $rate($totals['approved'], $totals['rejected']) ?></td></tr></tfoot>
            </table>
        </div>
        <?php else: ?>
            <p class="px-5 py-10 text-center text-sm text-slate-400">No appointments were booked in <?= e($monthLabel($month)) ?>.</p>
        <?php endif; ?>
    </div>

    <div class="card overflow-hidden">
        <div class="card-header"><h2 class="card-title">Approved per month</h2><span class="text-xs text-slate-400">last 6 months</span></div>
        <?php if ($trend): ?>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Closer</th><?php foreach ($trendMonths as $m): ?><th class="text-right <?= $m === $month ? 'text-indigo-600' : '' ?>"><?= e(date('M', strtotime($m . '-01'))) ?></th><?php endforeach; ?><th class="text-right">Total</th></tr></thead>
                <tbody>
                <?php foreach ($trend as $t): ?>
                    <tr>
                        <td class="whitespace-nowrap font-medium text-slate-900"><?= e($t['name']) ?></td>
                        <?php foreach ($trendMonths as $m): $n = $t[$m] ?? 0; ?><td class="text-right tabular-nums <?= $n ? 'text-slate-900' : 'text-slate-300' ?> <?= $m === $month ? 'bg-indigo-50/50 font-semibold' : '' ?>"><?= $n ?></td><?php endforeach; ?>
                        <td class="text-right font-semibold tabular-nums text-emerald-600"><?= array_sum(array_map(fn($m) => $t[$m] ?? 0, $trendMonths)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <p class="px-5 py-10 text-center text-sm text-slate-400">No approved appointments in these months yet.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-6 overflow-hidden" id="requests">
    <form method="get" class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3">
        <input type="hidden" name="month" value="<?= e($month) ?>">
        <h2 class="card-title mr-auto">Appointments · <?= e($monthLabel($month)) ?></h2>
        <select name="closer" class="input !w-auto" onchange="this.form.submit()" aria-label="Closer">
            <option value="">All closers</option>
            <?php foreach ($closers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $closer === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            <option value="none" <?= $closer === 'none' ? 'selected' : '' ?>>Former member / unknown</option>
        </select>
        <select name="decision" class="input !w-auto" onchange="this.form.submit()" aria-label="Decision">
            <option value="">Any decision</option>
            <?php foreach (['pending' => 'Awaiting approval', 'approved' => 'Approved', 'rejected' => 'Disapproved', 'cancelled' => 'Cancelled (status changed first)'] as $k => $l): ?><option value="<?= $k ?>" <?= $decision === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
    </form>
    <?php if ($rows): ?>
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>Lead</th><th>Closer</th><th>Booked</th><th>Decision</th><th>Decided by</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="max-w-xs">
                        <?php if ($r['live_lead_id']): ?><a href="<?= e(url('leads/view.php', ['id' => $r['live_lead_id']])) ?>" class="block truncate font-medium text-slate-900 hover:text-indigo-600"><?= e($r['lead_name'] ?: 'Lead #' . $r['lead_id']) ?></a>
                        <?php else: ?><span class="block truncate text-slate-500" title="This lead was deleted"><?= e($r['lead_name'] ?: 'Deleted lead') ?></span><?php endif; ?>
                        <span class="text-xs text-slate-400"><?= e($r['status_name']) ?></span>
                    </td>
                    <td class="whitespace-nowrap"><?= $r['closer_name'] ? e($r['closer_name']) : '<span class="text-slate-400">Unknown</span>' ?></td>
                    <td class="whitespace-nowrap text-xs text-slate-500"><?= e(format_dt($r['booked_at'])) ?></td>
                    <td><?= $decisionBadge($r['decision']) ?></td>
                    <td class="whitespace-nowrap text-xs text-slate-500"><?= $r['decided_at'] ? e(($r['decider_name'] ?: 'Former member') . ' · ' . format_dt($r['decided_at'], 'M j, g:i A')) : '—' ?></td>
                    <td class="max-w-xs text-xs text-slate-600"><?= $r['note'] ? e($r['note']) : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($p) ?>
    <?php else: ?>
        <p class="px-5 py-10 text-center text-sm text-slate-400">No appointments match these filters.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
