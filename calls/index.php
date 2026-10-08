<?php
/**
 * Calls dashboard: KPIs from Zoom Phone call history.
 * Closers see only calls on their assigned numbers; admins see everyone, per closer and combined.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
if (!can_see_calls()) {
    abort(403, 'Calls are available to closers and admins.');
}
$ws = ws_id();
$isAdmin = allowed('calls.view_all');
$f = calls_filters();
[$where, $params] = calls_where($f);
$target = 'calls/index.php';

$closers = $isAdmin ? q_all(
    "SELECT DISTINCT u.id, u.name FROM users u JOIN workspace_members m ON m.user_id = u.id AND m.workspace_id = ?
     WHERE m.role = 'closer' OR EXISTS (SELECT 1 FROM closer_numbers n WHERE n.workspace_id = m.workspace_id AND n.user_id = u.id)
     ORDER BY u.name",
    [$ws]
) : [];

$kpi = q_one('SELECT ' . CALL_KPI_SQL . " FROM zoom_calls c WHERE $where", $params);
$calls = (int) $kpi['calls'];
$connected = (int) $kpi['connected'];
$days = max(1, (int) ((strtotime($f['to']) - strtotime($f['from'])) / 86400) + 1);

// Date-wise (filled so quiet days show as zero)
$daily = [];
if ($days <= 366) {
    for ($d = $f['from']; $d <= $f['to']; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $daily[$d] = null;
    }
}
foreach (q_all('SELECT DATE(c.start_time) d, ' . CALL_KPI_SQL . " FROM zoom_calls c WHERE $where GROUP BY DATE(c.start_time) ORDER BY d", $params) as $r) {
    $daily[$r['d']] = $r;
}
$hourly = array_fill(0, 24, 0);
$hourlyConnected = array_fill(0, 24, 0);
foreach (q_all("SELECT HOUR(c.start_time) h, COUNT(*) n, SUM(c.is_connected) cn FROM zoom_calls c WHERE $where GROUP BY HOUR(c.start_time)", $params) as $r) {
    $hourly[(int) $r['h']] = (int) $r['n'];
    $hourlyConnected[(int) $r['h']] = (int) $r['cn'];
}
$results = q_all("SELECT COALESCE(c.call_result, 'unknown') result, MAX(c.is_connected) is_connected, COUNT(*) n FROM zoom_calls c WHERE $where GROUP BY COALESCE(c.call_result, 'unknown') ORDER BY n DESC", $params);

$perCloser = [];
if ($isAdmin && $f['closer'] === '') {
    $perCloser = q_all(
        'SELECT c.closer_user_id, u.name, ' . CALL_KPI_SQL . ', MAX(c.start_time) last_call,
            (SELECT COUNT(*) FROM closer_numbers n WHERE n.workspace_id = c.workspace_id AND n.user_id = c.closer_user_id) numbers
         FROM zoom_calls c LEFT JOIN users u ON u.id = c.closer_user_id
         WHERE ' . $where . ' GROUP BY c.closer_user_id, u.name, c.workspace_id ORDER BY c.closer_user_id IS NULL, calls DESC',
        $params
    );
    // Closers with no calls in the period still belong in the comparison
    $seen = array_column($perCloser, 'closer_user_id');
    foreach ($closers as $c) {
        if (!in_array($c['id'], $seen)) {
            $perCloser[] = ['closer_user_id' => $c['id'], 'name' => $c['name'], 'calls' => 0, 'outbound' => 0, 'inbound' => 0, 'connected' => 0, 'missed' => 0, 'talk_seconds' => 0, 'longest' => 0, 'contacts' => 0, 'last_call' => null, 'numbers' => q_val('SELECT COUNT(*) FROM closer_numbers WHERE workspace_id = ? AND user_id = ?', [$ws, $c['id']])];
        }
    }
}

// One closer's numbers, shown as a paginated table at the bottom (with calls in the selected range per number)
$numbersUser = !$isAdmin ? user_id() : ((int) $f['closer'] > 0 ? (int) $f['closer'] : 0);
$numbersTotal = $numbersUser ? (int) q_val('SELECT COUNT(*) FROM closer_numbers WHERE workspace_id = ? AND user_id = ?', [$ws, $numbersUser]) : 0;
$numbersPage = paginate($numbersTotal, per_page(10));
$myNumbers = $numbersTotal ? q_all(
    'SELECT n.phone_number, n.label, n.created_at,
        (SELECT COUNT(*) FROM zoom_calls c WHERE c.workspace_id = n.workspace_id AND c.closer_number_key = n.number_key AND c.start_time >= ? AND c.start_time < ?) calls_in_range
     FROM closer_numbers n WHERE n.workspace_id = ? AND n.user_id = ? ORDER BY n.phone_number LIMIT ? OFFSET ?',
    [$f['from'] . ' 00:00:00', date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00', $ws, $numbersUser, $numbersPage['per_page'], $numbersPage['offset']]
) : [];
$recent = q_all(
    "SELECT c.*, u.name closer_name, cu.first_name, cu.last_name, cu.full_name, cu.email customer_email
     FROM zoom_calls c LEFT JOIN users u ON u.id = c.closer_user_id LEFT JOIN customers cu ON cu.id = c.customer_id
     WHERE $where ORDER BY c.start_time DESC LIMIT 8",
    $params
);
$conn = zoom_connection($ws);
$selectedName = $isAdmin && (int) $f['closer'] > 0 ? (string) q_val('SELECT name FROM users WHERE id = ?', [(int) $f['closer']]) : '';

$rate = fn($r) => pct((int) $r['connected'], (int) $r['calls'], 0);
$avgTalk = fn($r) => (int) $r['connected'] ? format_duration(intdiv((int) $r['talk_seconds'], (int) $r['connected'])) : '—';

$page_title = 'Calls';
$active_nav = 'calls';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $selectedName ? e($selectedName) . ' · calls' : ($isAdmin ? 'Calls' : 'My calls') ?></h1>
        <p class="page-subtitle"><?= $isAdmin ? 'Zoom Phone activity across your closers.' : 'Zoom Phone activity on your assigned numbers.' ?><?= $conn && $conn['last_sync_at'] ? ' Updated ' . e(time_ago($conn['last_sync_at'])) . '.' : '' ?></p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="<?= e(url('calls/log.php', array_filter(['range' => $f['range'], 'from' => $f['range'] === 'custom' ? $f['from'] : null, 'to' => $f['range'] === 'custom' ? $f['to'] : null, 'closer' => $isAdmin ? $f['closer'] : null]))) ?>" class="btn-secondary"><?= icon('table', 'h-4 w-4') ?> Call log</a>
        <?php if (allowed('calls.manage')): ?>
            <a href="<?= e(url('calls/numbers.php')) ?>" class="btn-secondary"><?= icon('users', 'h-4 w-4') ?> Closer numbers</a>
            <a href="<?= e(url('calls/zoom.php')) ?>" class="btn-secondary"><?= icon('cog', 'h-4 w-4') ?> Zoom</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($isAdmin && (!$conn || $conn['status'] !== 'active')): ?>
    <div class="mb-4 flex items-center justify-between gap-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">
        <span><?= !$conn || $conn['status'] === 'disconnected' ? 'Zoom Phone is not connected yet.' : 'The Zoom connection has a problem: ' . e($conn['last_error']) ?></span>
        <a class="font-medium underline" href="<?= e(url('calls/zoom.php')) ?>"><?= !$conn || $conn['status'] === 'disconnected' ? 'Connect Zoom' : 'Fix it' ?></a>
    </div>
<?php endif; ?>

<?php if ($numbersUser && !$numbersTotal): ?>
    <div class="mb-4 text-sm text-amber-700"><?= $isAdmin ? 'This closer has no numbers assigned yet.' : 'No numbers assigned yet: ask an admin to add your Zoom number.' ?></div>
<?php endif; ?>

<?php require __DIR__ . '/_filters.php'; ?>

<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
    <div class="stat-card"><div class="stat-label">Total calls</div><div class="stat-value"><?= number_format($calls) ?></div><div class="mt-1 text-xs text-slate-500"><?= number_format($calls / $days, 1) ?> per day</div></div>
    <div class="stat-card"><div class="stat-label">Outbound / inbound</div><div class="stat-value"><?= number_format((int) $kpi['outbound']) ?> <span class="text-base font-normal text-slate-400">/ <?= number_format((int) $kpi['inbound']) ?></span></div><div class="mt-1 text-xs text-slate-500"><?= number_format((int) $kpi['contacts']) ?> unique numbers</div></div>
    <div class="stat-card"><div class="stat-label">Connected</div><div class="stat-value text-emerald-600"><?= number_format($connected) ?></div><div class="mt-1 text-xs text-slate-500"><?= pct($connected, $calls) ?> connect rate</div></div>
    <div class="stat-card"><div class="stat-label">Missed inbound</div><div class="stat-value <?= (int) $kpi['missed'] ? 'text-red-600' : '' ?>"><?= number_format((int) $kpi['missed']) ?></div><div class="mt-1 text-xs text-slate-500"><?= pct((int) $kpi['missed'], (int) $kpi['inbound']) ?> of inbound</div></div>
    <div class="stat-card"><div class="stat-label">Talk time</div><div class="stat-value"><?= e(format_duration((int) $kpi['talk_seconds'])) ?></div><div class="mt-1 text-xs text-slate-500"><?= e(format_duration(intdiv((int) $kpi['talk_seconds'], $days))) ?> per day</div></div>
    <div class="stat-card"><div class="stat-label">Avg. talk time</div><div class="stat-value"><?= e($avgTalk($kpi)) ?></div><div class="mt-1 text-xs text-slate-500">per connected call</div></div>
    <div class="stat-card"><div class="stat-label">Longest call</div><div class="stat-value"><?= e(format_duration((int) $kpi['longest'])) ?></div></div>
    <div class="stat-card"><div class="stat-label">Not connected</div><div class="stat-value text-slate-500"><?= number_format($calls - $connected) ?></div><div class="mt-1 text-xs text-slate-500">no answer, voicemail, busy…</div></div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="card lg:col-span-2">
        <div class="card-header"><h2 class="card-title">Calls per day</h2><span class="text-xs text-slate-400">bars: outbound / inbound · line: connected</span></div>
        <div class="card-body"><div class="h-72"><canvas id="dailyChart"></canvas></div></div>
    </div>
    <div class="card">
        <div class="card-header"><h2 class="card-title">Best time to call</h2><span class="text-xs text-slate-400">connect rate by hour</span></div>
        <div class="card-body"><div class="h-72"><canvas id="hourChart"></canvas></div></div>
    </div>
</div>

<?php if ($perCloser): ?>
<div class="card mt-6 overflow-hidden">
    <div class="card-header"><h2 class="card-title">Closers</h2><span class="text-xs text-slate-400">click a name for their dashboard</span></div>
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>Closer</th><th class="text-right">Calls</th><th class="text-right">Out</th><th class="text-right">In</th><th class="text-right">Connected</th><th class="text-right">Rate</th><th class="text-right">Missed</th><th class="text-right">Talk time</th><th class="text-right">Avg. talk</th><th class="text-right">Calls/day</th><th>Last call</th></tr></thead>
            <tbody>
            <?php foreach ($perCloser as $r): ?>
                <tr>
                    <td class="whitespace-nowrap">
                        <?php if ($r['closer_user_id']): ?>
                            <a class="flex items-center gap-2 font-medium text-slate-900 hover:text-indigo-600" href="<?= e(url('calls/index.php', ['closer' => $r['closer_user_id'], 'range' => $f['range'], 'from' => $f['from'], 'to' => $f['to']])) ?>">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full text-[11px] font-semibold <?= e(avatar_color((string) $r['name'])) ?>" title="<?= e($r['name']) ?>"><?= e(user_initials($r['name'])) ?></span><?= e($r['name']) ?>
                                <?php if (!(int) $r['numbers']): ?><span class="badge badge-amber !normal-case">no numbers</span><?php endif; ?>
                            </a>
                        <?php else: ?>
                            <a class="text-slate-500 hover:text-indigo-600" href="<?= e(url('calls/log.php', ['closer' => 'unassigned', 'range' => $f['range'], 'from' => $f['from'], 'to' => $f['to']])) ?>">Unassigned numbers</a>
                        <?php endif; ?>
                    </td>
                    <td class="text-right font-medium tabular-nums"><?= number_format((int) $r['calls']) ?></td>
                    <td class="text-right tabular-nums"><?= number_format((int) $r['outbound']) ?></td>
                    <td class="text-right tabular-nums"><?= number_format((int) $r['inbound']) ?></td>
                    <td class="text-right tabular-nums text-emerald-600"><?= number_format((int) $r['connected']) ?></td>
                    <td class="text-right tabular-nums"><?= $rate($r) ?></td>
                    <td class="text-right tabular-nums <?= (int) $r['missed'] ? 'text-red-600' : '' ?>"><?= number_format((int) $r['missed']) ?></td>
                    <td class="whitespace-nowrap text-right tabular-nums"><?= e(format_duration((int) $r['talk_seconds'])) ?></td>
                    <td class="whitespace-nowrap text-right tabular-nums"><?= e($avgTalk($r)) ?></td>
                    <td class="text-right tabular-nums"><?= number_format((int) $r['calls'] / $days, 1) ?></td>
                    <td class="whitespace-nowrap text-xs text-slate-500"><?= e(time_ago($r['last_call'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="border-t-2 border-slate-200 bg-slate-50 text-sm font-semibold">
                <tr>
                    <td class="px-4 py-3">All closers</td>
                    <td class="px-4 py-3 text-right tabular-nums"><?= number_format($calls) ?></td>
                    <td class="px-4 py-3 text-right tabular-nums"><?= number_format((int) $kpi['outbound']) ?></td>
                    <td class="px-4 py-3 text-right tabular-nums"><?= number_format((int) $kpi['inbound']) ?></td>
                    <td class="px-4 py-3 text-right tabular-nums text-emerald-600"><?= number_format($connected) ?></td>
                    <td class="px-4 py-3 text-right tabular-nums"><?= $rate($kpi) ?></td>
                    <td class="px-4 py-3 text-right tabular-nums"><?= number_format((int) $kpi['missed']) ?></td>
                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(format_duration((int) $kpi['talk_seconds'])) ?></td>
                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e($avgTalk($kpi)) ?></td>
                    <td class="px-4 py-3 text-right tabular-nums"><?= number_format($calls / $days, 1) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="card overflow-hidden lg:col-span-2">
        <div class="card-header"><h2 class="card-title">Day by day</h2></div>
        <div class="max-h-[28rem] overflow-auto">
            <table class="table">
                <thead class="sticky top-0"><tr><th>Date</th><th class="text-right">Calls</th><th class="text-right">Out</th><th class="text-right">In</th><th class="text-right">Connected</th><th class="text-right">Rate</th><th class="text-right">Missed</th><th class="text-right">Talk time</th><th class="text-right">Avg. talk</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($daily, true) as $d => $r): ?>
                    <tr class="<?= $r ? '' : 'text-slate-400' ?>">
                        <td class="whitespace-nowrap"><?php if ($r): ?><a class="font-medium text-slate-900 hover:text-indigo-600" href="<?= e(url('calls/log.php', array_filter(['range' => 'custom', 'from' => $d, 'to' => $d, 'closer' => $isAdmin ? $f['closer'] : null]))) ?>"><?= e(format_dt($d, 'D, M j')) ?></a><?php else: ?><?= e(format_dt($d, 'D, M j')) ?><?php endif; ?></td>
                        <?php if ($r): ?>
                            <td class="text-right font-medium tabular-nums"><?= number_format((int) $r['calls']) ?></td>
                            <td class="text-right tabular-nums"><?= number_format((int) $r['outbound']) ?></td>
                            <td class="text-right tabular-nums"><?= number_format((int) $r['inbound']) ?></td>
                            <td class="text-right tabular-nums text-emerald-600"><?= number_format((int) $r['connected']) ?></td>
                            <td class="text-right tabular-nums"><?= $rate($r) ?></td>
                            <td class="text-right tabular-nums"><?= number_format((int) $r['missed']) ?></td>
                            <td class="whitespace-nowrap text-right tabular-nums"><?= e(format_duration((int) $r['talk_seconds'])) ?></td>
                            <td class="whitespace-nowrap text-right tabular-nums"><?= e($avgTalk($r)) ?></td>
                        <?php else: ?>
                            <td class="text-right" colspan="8">no calls</td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Call results</h2></div>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($results as $r): ?>
                    <li class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm"><?= call_result_badge($r['result'] === 'unknown' ? null : $r['result'], (bool) $r['is_connected']) ?><span class="tabular-nums text-slate-600"><?= number_format((int) $r['n']) ?> <span class="text-xs text-slate-400">· <?= pct((int) $r['n'], $calls, 0) ?></span></span></li>
                <?php endforeach; ?>
                <?php if (!$results): ?><li class="px-5 py-8 text-center text-sm text-slate-400">No calls in this period.</li><?php endif; ?>
            </ul>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Latest calls</h2><a class="text-xs font-medium text-indigo-600" href="<?= e(url('calls/log.php', array_filter(['range' => $f['range'], 'from' => $f['range'] === 'custom' ? $f['from'] : null, 'to' => $f['range'] === 'custom' ? $f['to'] : null, 'closer' => $isAdmin ? $f['closer'] : null]))) ?>">All</a></div>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($recent as $c): ?>
                    <li class="flex items-center gap-3 px-5 py-2.5">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full <?= $c['direction'] === 'outbound' ? 'bg-indigo-50 text-indigo-600' : 'bg-sky-50 text-sky-600' ?>" title="<?= e(ucfirst((string) $c['direction'])) ?>"><?= icon($c['direction'] === 'outbound' ? 'forward' : 'reply', 'h-4 w-4') ?></span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium text-slate-800"><?= $c['customer_id'] ? e(customer_name(['full_name' => $c['full_name'], 'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'email' => $c['customer_email']])) : e($c['external_number'] ?: ($c['direction'] === 'outbound' ? $c['callee_name'] : $c['caller_name']) ?: 'Unknown') ?></div>
                            <div class="truncate text-xs text-slate-500"><?= e(time_ago($c['start_time'])) ?><?= $isAdmin && $c['closer_name'] ? ' · ' . e($c['closer_name']) : '' ?></div>
                        </div>
                        <span class="text-right text-xs"><?= call_result_badge($c['call_result'], (bool) $c['is_connected']) ?><span class="mt-0.5 block tabular-nums text-slate-500"><?= e(format_duration((int) $c['duration'])) ?></span></span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$recent): ?><li class="px-5 py-8 text-center text-sm text-slate-400">No calls yet.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php if ($numbersTotal): ?>
    <div class="card mt-6 overflow-hidden">
        <div class="card-header">
            <h2 class="card-title"><?= $isAdmin ? 'Numbers' : 'Your numbers' ?> <span class="font-normal text-slate-400">(<?= number_format($numbersTotal) ?>)</span></h2>
            <?php if (allowed('calls.manage')): ?><a class="text-xs font-medium text-indigo-600" href="<?= e(url('calls/numbers.php')) ?>">Manage</a><?php endif; ?>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Number</th><th>Label</th><th class="text-right">Calls in range</th><th>Added</th></tr></thead>
                <tbody>
                <?php foreach ($myNumbers as $n): ?>
                    <tr>
                        <td class="font-medium tabular-nums text-slate-900"><?= e($n['phone_number']) ?></td>
                        <td class="max-w-xs truncate text-slate-500"><?= e($n['label'] ?: '—') ?></td>
                        <td class="text-right tabular-nums"><?= number_format((int) $n['calls_in_range']) ?></td>
                        <td class="whitespace-nowrap text-xs text-slate-500"><?= e(format_dt($n['created_at'], 'M j, Y')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $numbersTotal > 10 ? pagination_links($numbersPage) : '' ?>
    </div>
<?php endif; ?>

<?php
$chartDaily = ['labels' => [], 'out' => [], 'in' => [], 'connected' => []];
foreach ($daily as $d => $r) {
    $chartDaily['labels'][] = date('M j', strtotime($d));
    $chartDaily['out'][] = (int) ($r['outbound'] ?? 0);
    $chartDaily['in'][] = (int) ($r['inbound'] ?? 0);
    $chartDaily['connected'][] = (int) ($r['connected'] ?? 0);
}
$hourRate = array_map(fn($n, $c) => $n ? round($c / $n * 100) : null, $hourly, $hourlyConnected);
$extra_scripts = '<script src="' . e(asset('vendor/chart.umd.min.js')) . '"></script>
<script>
(function () {
    const d = ' . json_encode($chartDaily) . ', hours = ' . json_encode($hourly) . ', rate = ' . json_encode($hourRate) . ';
    const grid = { color: "#f1f5f9" };
    new Chart(document.getElementById("dailyChart"), {
        data: { labels: d.labels, datasets: [
            { type: "bar", label: "Outbound", data: d.out, backgroundColor: "#6366f1", stack: "calls", borderRadius: 3 },
            { type: "bar", label: "Inbound", data: d.in, backgroundColor: "#38bdf8", stack: "calls", borderRadius: 3 },
            { type: "line", label: "Connected", data: d.connected, borderColor: "#10b981", backgroundColor: "#10b981", tension: .3, pointRadius: d.labels.length > 31 ? 0 : 2, borderWidth: 2 },
        ] },
        options: { maintainAspectRatio: false, interaction: { mode: "index", intersect: false }, plugins: { legend: { position: "bottom", labels: { usePointStyle: true, boxWidth: 6 } } },
            scales: { x: { stacked: true, grid: { display: false }, ticks: { maxTicksLimit: 12 } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid } } }
    });
    const labels = hours.map((_, h) => (h % 12 || 12) + (h < 12 ? "a" : "p"));
    new Chart(document.getElementById("hourChart"), {
        data: { labels, datasets: [
            { type: "bar", label: "Calls", data: hours, backgroundColor: "#c7d2fe", yAxisID: "y", borderRadius: 3 },
            { type: "line", label: "Connect rate %", data: rate, borderColor: "#10b981", backgroundColor: "#10b981", yAxisID: "y1", spanGaps: true, tension: .3, pointRadius: 2, borderWidth: 2 },
        ] },
        options: { maintainAspectRatio: false, interaction: { mode: "index", intersect: false }, plugins: { legend: { position: "bottom", labels: { usePointStyle: true, boxWidth: 6 } } },
            scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid }, y1: { position: "right", min: 0, max: 100, grid: { display: false }, ticks: { callback: v => v + "%" } } } }
    });
})();
</script>';
require __DIR__ . '/../includes/footer.php';
