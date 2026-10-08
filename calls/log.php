<?php
/** Call log: every call with its timing. Same scoping as the dashboard. ?export=csv downloads the filtered list. */
require_once __DIR__ . '/../includes/init.php';
require_auth();
if (!can_see_calls()) {
    abort(403, 'Calls are available to closers and admins.');
}
$ws = ws_id();
$isAdmin = allowed('calls.view_all');
$f = calls_filters();
[$where, $params] = calls_where($f);
$target = 'calls/log.php';
$select = "SELECT c.*, u.name closer_name, cu.first_name, cu.last_name, cu.full_name, cu.email customer_email
     FROM zoom_calls c LEFT JOIN users u ON u.id = c.closer_user_id LEFT JOIN customers cu ON cu.id = c.customer_id
     WHERE $where";
$order = sort_sql(['time' => 'c.start_time', 'duration' => 'c.duration', 'closer' => 'u.name'], 'time', 'desc');
$customerLabel = fn(array $c) => $c['customer_id'] ? customer_name(['full_name' => $c['full_name'], 'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'email' => $c['customer_email']]) : '';

if (input('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="calls-' . $f['from'] . '-to-' . $f['to'] . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Start', 'Answered', 'End', 'Direction', 'Closer', 'Closer number', 'From name', 'From number', 'To name', 'To number', 'Customer', 'Result', 'Connected', 'Ended by', 'Duration (s)', 'Duration'], ',', '"', '');
    $stmt = q("$select ORDER BY $order", $params);
    while ($c = $stmt->fetch()) {
        fputcsv($out, [
            $c['start_time'], $c['answer_time'], $c['end_time'], $c['direction'], $c['closer_name'], $c['closer_number_key'],
            $c['caller_name'], $c['caller_number'] ?: $c['caller_ext'], $c['callee_name'], $c['callee_number'] ?: $c['callee_ext'],
            $customerLabel($c), $c['call_result'], $c['is_connected'] ? 'yes' : 'no', $c['direction'] === 'outbound' ? $c['ended_by'] : '', $c['duration'], format_duration((int) $c['duration']),
        ], ',', '"', '');
    }
    exit;
}

$closers = $isAdmin ? q_all(
    "SELECT DISTINCT u.id, u.name FROM users u JOIN workspace_members m ON m.user_id = u.id AND m.workspace_id = ?
     WHERE m.role = 'closer' OR EXISTS (SELECT 1 FROM closer_numbers n WHERE n.workspace_id = m.workspace_id AND n.user_id = u.id)
     ORDER BY u.name",
    [$ws]
) : [];
$total = (int) q_val("SELECT COUNT(*) FROM zoom_calls c LEFT JOIN users u ON u.id = c.closer_user_id WHERE $where", $params);
$p = paginate($total, per_page(50));
$rows = q_all("$select ORDER BY $order LIMIT ? OFFSET ?", [...$params, $p['per_page'], $p['offset']]);

$page_title = 'Call log';
$active_nav = 'calls';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('calls/index.php', array_filter(['range' => $f['range'], 'from' => $f['range'] === 'custom' ? $f['from'] : null, 'to' => $f['range'] === 'custom' ? $f['to'] : null, 'closer' => $isAdmin ? $f['closer'] : null]))) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Calls dashboard</a>
        <h1 class="page-title">Call log</h1>
        <p class="page-subtitle"><?= number_format($total) ?> calls<?= $isAdmin ? '' : ' on your numbers' ?>.</p>
    </div>
    <a href="<?= e(url_with(['export' => 'csv', 'page' => null])) ?>" class="btn-secondary"><?= icon('download', 'h-4 w-4') ?> Export CSV</a>
</div>

<?php require __DIR__ . '/_filters.php'; ?>

<div class="card overflow-hidden">
    <form method="get" class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3">
        <?php foreach (['range' => $f['range'], 'from' => $f['from'], 'to' => $f['to'], 'closer' => $isAdmin ? $f['closer'] : '', 'direction' => $f['direction'], 'result' => $f['result']] as $k => $v): if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
        <div class="relative min-w-[14rem] flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><?= icon('search', 'h-4 w-4') ?></span>
            <input class="input !pl-9" name="q" value="<?= e($f['q']) ?>" placeholder="Search by name or phone number…">
        </div>
        <button class="btn-secondary">Search</button>
        <?php if ($f['q'] !== ''): ?><a class="btn-ghost" href="<?= e(url_with(['q' => null, 'page' => null])) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($rows): ?>
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr>
                <th><?= sort_link('time', 'Time') ?></th>
                <th>Direction</th>
                <?php if ($isAdmin): ?><th><?= sort_link('closer', 'Closer') ?></th><?php endif; ?>
                <th>Other party</th>
                <th>Closer line</th>
                <th>Result</th>
                <th>Ended by</th>
                <th class="text-right"><?= sort_link('duration', 'Duration') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $c):
                $out = $c['direction'] === 'outbound';
                $otherName = $out ? $c['callee_name'] : $c['caller_name'];
                $closerLine = $out ? ($c['caller_number'] ?: $c['caller_ext']) : ($c['callee_number'] ?: $c['callee_ext']);
            ?>
                <tr>
                    <td class="whitespace-nowrap">
                        <div class="font-medium text-slate-900"><?= e(format_dt($c['start_time'], 'M j, g:i A')) ?></div>
                        <div class="text-xs text-slate-500"><?= $c['end_time'] ? 'ended ' . e(format_dt($c['end_time'], 'g:i:s A')) : '' ?></div>
                    </td>
                    <td><span class="inline-flex items-center gap-1.5 text-xs font-medium <?= $out ? 'text-indigo-600' : 'text-sky-600' ?>"><?= icon($out ? 'forward' : 'reply', 'h-3.5 w-3.5') ?><?= e(ucfirst((string) $c['direction'] ?: '—')) ?></span></td>
                    <?php if ($isAdmin): ?><td class="whitespace-nowrap"><?= $c['closer_name'] ? e($c['closer_name']) : '<span class="text-slate-400">Unassigned</span>' ?></td><?php endif; ?>
                    <td class="max-w-xs">
                        <?php if ($c['customer_id']): ?>
                            <a class="block truncate font-medium text-indigo-600 hover:underline" href="<?= e(url('customers/view.php', ['id' => $c['customer_id']])) ?>"><?= e($customerLabel($c)) ?></a>
                        <?php elseif ($otherName): ?><div class="truncate font-medium text-slate-800"><?= e($otherName) ?></div><?php endif; ?>
                        <div class="text-xs tabular-nums text-slate-500"><?= e($c['external_number'] ?: '—') ?></div>
                    </td>
                    <td class="whitespace-nowrap text-xs tabular-nums text-slate-500"><?= e($closerLine ?: '—') ?></td>
                    <td><?= call_result_badge($c['call_result'], (bool) $c['is_connected']) ?></td>
                    <td><?= call_ended_by_badge($c) ?></td>
                    <td class="whitespace-nowrap text-right tabular-nums <?= $c['is_connected'] ? 'font-medium text-slate-900' : 'text-slate-400' ?>"><?= e(format_duration((int) $c['duration'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($p) ?>
    <?php else: ?>
        <div class="empty-state">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><?= icon('table', 'h-6 w-6') ?></span>
            <h3 class="mt-3 text-sm font-semibold">No calls found</h3>
            <p class="mt-1 text-sm text-slate-500">Try a longer date range<?= $f['q'] !== '' ? ' or clear the search' : '' ?>.</p>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
