<?php
/**
 * Pending tasks: leads whose status is marked "Show on Pending tasks" (Callback, Interested, Follow up, Presentation…),
 * soonest date first, as a reminder of who to call. Closers see their own leads; managers and up see everyone's.
 *   GET due = overdue | today | tomorrow | week | later | none, from / to (Y-m-d), status, assigned, q
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$self = 'leads/pending.php';

$statuses = lead_statuses($ws);
$pending = lead_pending_statuses($ws);
$viewAll = can_view_all_leads();
$members = $viewAll ? workspace_users() : [];

$filters = array_intersect_key(lead_filters_from_request(), ['q' => 1, 'assigned' => 1, 'status' => 1]);
if (isset($filters['status']) && !isset($pending[(int) $filters['status']])) {
    unset($filters['status']);
}
$due = in_array(input('due'), ['overdue', 'today', 'tomorrow', 'week', 'later', 'none'], true) ? (string) input('due') : '';
$validDate = fn($v) => ($d = DateTime::createFromFormat('!Y-m-d', (string) $v)) && $d->format('Y-m-d') === $v ? $v : '';
$from = $validDate(input('from'));
$to = $validDate(input('to'));
if ($from && $to && $from > $to) {
    [$from, $to] = [$to, $from];
}

// Base: visible leads in a pending status (+ search / assignee / one status)
[$where, $params] = lead_filter_sql($filters);
$pendingIds = array_keys($pending) ?: [0];
$where .= ' AND l.status_id IN (' . placeholders($pendingIds) . ')';
$params = [...$params, ...$pendingIds];

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$weekEnd = date('Y-m-d', strtotime('+6 days'));
$buckets = [
    'overdue' => ['Overdue', 'l.follow_up_on < ?', [$today]],
    'today' => ['Today', 'l.follow_up_on = ?', [$today]],
    'tomorrow' => ['Tomorrow', 'l.follow_up_on = ?', [$tomorrow]],
    'week' => ['Next 7 days', 'l.follow_up_on BETWEEN ? AND ?', [$today, $weekEnd]],
    'later' => ['Later', 'l.follow_up_on > ?', [$weekEnd]],
    'none' => ['No date', 'l.follow_up_on IS NULL', []],
];

// Per-bucket counts, before the date filters
$countSql = [];
$countParams = [];
foreach ($buckets as $key => [, $sql, $p]) {
    $countSql[] = "COALESCE(SUM($sql), 0) AS `$key`";
    $countParams = [...$countParams, ...$p];
}
$counts = q_one('SELECT COUNT(*) AS `all`, ' . implode(', ', $countSql) . " FROM leads l WHERE $where", [...$countParams, ...$params]);

if ($due) {
    $where .= ' AND ' . $buckets[$due][1];
    $params = [...$params, ...$buckets[$due][2]];
}
if ($from) {
    $where .= ' AND l.follow_up_on >= ?';
    $params[] = $from;
}
if ($to) {
    $where .= ' AND l.follow_up_on <= ?';
    $params[] = $to;
}

$total = (int) q_val("SELECT COUNT(*) FROM leads l WHERE $where", $params);
$p = paginate($total, per_page(25));
$leads = q_all(
    "SELECT l.*, a.name assignee_name, ap.name approver_name FROM leads l LEFT JOIN users a ON a.id = l.assigned_to LEFT JOIN users ap ON ap.id = l.approval_by
     WHERE $where ORDER BY l.follow_up_on IS NULL, l.follow_up_on, l.updated_at DESC, l.id DESC LIMIT ? OFFSET ?",
    [...$params, $p['per_page'], $p['offset']]
);
$leadIds = array_map('intval', array_column($leads, 'id'));
$phones = phones_for_leads($leadIds);

// Latest note / status comment on each lead, as context for the call
$notes = [];
if ($leadIds) {
    foreach (q_all(
        'SELECT a.lead_id, a.body FROM lead_activity a
         JOIN (SELECT lead_id, MAX(id) id FROM lead_activity WHERE workspace_id = ? AND body IS NOT NULL AND lead_id IN (' . placeholders($leadIds) . ') GROUP BY lead_id) m ON m.id = a.id',
        [$ws, ...$leadIds]
    ) as $r) {
        $body = trim(preg_replace('/^Date: [^\n]*\n?/', '', (string) $r['body']));
        if ($body !== '') {
            $notes[(int) $r['lead_id']] = $body;
        }
    }
}

$dueLabel = function (?string $date) use ($today): string {
    if (!$date) {
        return '<span class="text-xs text-slate-400">No date</span>';
    }
    $days = (int) round((strtotime($date) - strtotime($today)) / 86400);
    $rel = match (true) {
        $days < -1 => abs($days) . ' days overdue',
        $days === -1 => '1 day overdue',
        $days === 0 => 'Due today',
        $days === 1 => 'Tomorrow',
        default => 'In ' . $days . ' days',
    };
    $color = $days < 0 ? 'text-red-600' : ($days === 0 ? 'text-amber-600' : 'text-slate-400');
    return lead_follow_up_badge($date, 'text-sm') . '<span class="block text-xs ' . $color . '">' . e($rel) . '</span>';
};
$keep = array_filter(['q' => $filters['q'] ?? null, 'assigned' => $filters['assigned'] ?? null, 'status' => $filters['status'] ?? null]);
$dueUrl = fn(?string $key) => url($self, $keep + array_filter(['due' => $key]));
$hasFilters = $filters || $due || $from || $to;

$hideDateBadge = true; // the Date column shows it
$page_title = 'Pending tasks';
$active_nav = 'leads-pending';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('leads/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Leads</a>
        <h1 class="page-title">Pending tasks</h1>
        <p class="page-subtitle">
            Leads in <?= $pending ? implode(', ', array_map(fn($s) => e($s['name']), $pending)) : 'no status yet' ?>, soonest date first.
            <?php if (allowed('leads.manage')): ?><a href="<?= e(url('leads/statuses.php')) ?>" class="text-indigo-600 hover:underline">Choose statuses</a><?php endif; ?>
        </p>
    </div>
</div>

<!-- Date chips -->
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="<?= e($dueUrl(null)) ?>" class="rounded-full px-3 py-1 text-xs font-medium ring-1 <?= !$due ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' ?>">All <span class="opacity-70"><?= number_format((int) $counts['all']) ?></span></a>
    <?php foreach ($buckets as $key => [$label]): $active = $due === $key; $n = (int) $counts[$key]; ?>
        <a href="<?= e($dueUrl($key)) ?>" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium ring-1 <?= $active ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' ?>">
            <?php if ($key === 'overdue' && $n): ?><span class="h-2 w-2 rounded-full bg-red-500"></span><?php elseif ($key === 'today' && $n): ?><span class="h-2 w-2 rounded-full bg-amber-500"></span><?php endif; ?>
            <?= e($label) ?> <span class="opacity-70"><?= number_format($n) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <form method="get" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
        <?php if ($due): ?><input type="hidden" name="due" value="<?= e($due) ?>"><?php endif; ?>
        <div class="min-w-[14rem] flex-1">
            <label class="label" for="q">Search</label>
            <input class="input" id="q" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Name, phone, email, website…">
        </div>
        <div>
            <label class="label" for="from">From</label>
            <input type="date" class="input" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div>
            <label class="label" for="to">To</label>
            <input type="date" class="input" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All pending</option>
                <?php foreach ($pending as $sid => $s): ?><option value="<?= (int) $sid ?>" <?= ($filters['status'] ?? '') === (string) $sid ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php if ($viewAll): ?>
            <div>
                <label class="label" for="assigned">Assigned to</label>
                <select class="input" id="assigned" name="assigned">
                    <option value="">Anyone</option>
                    <option value="<?= user_id() ?>" <?= ($filters['assigned'] ?? '') === (string) user_id() ? 'selected' : '' ?>>Me</option>
                    <?php foreach ($members as $m): if ((int) $m['id'] === user_id()) continue; ?><option value="<?= (int) $m['id'] ?>" <?= ($filters['assigned'] ?? '') === (string) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?>
                    <option value="unassigned" <?= ($filters['assigned'] ?? '') === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                </select>
            </div>
        <?php endif; ?>
        <div class="flex gap-2">
            <button class="btn-primary">Apply</button>
            <?php if ($hasFilters): ?><a href="<?= e(url($self)) ?>" class="btn-ghost">Clear</a><?php endif; ?>
        </div>
    </form>

    <div class="overflow-x-auto">
        <table class="table">
            <thead>
            <tr>
                <th>Date</th>
                <th>Lead</th>
                <th>Phone</th>
                <th>Status</th>
                <th>Latest note</th>
                <th>Last call</th>
                <?php if ($viewAll): ?><th>Assigned</th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($leads as $l): $lp = $phones[$l['id']] ?? []; $overdue = $l['follow_up_on'] && $l['follow_up_on'] < $today; ?>
                <tr class="<?= $overdue ? 'bg-red-50/40' : '' ?>">
                    <td class="whitespace-nowrap"><?= $dueLabel($l['follow_up_on']) ?></td>
                    <td class="max-w-[16rem]">
                        <a href="<?= e(url('leads/view.php', ['id' => $l['id']])) ?>" class="block truncate font-medium text-slate-900 hover:text-indigo-600"><?= e($l['name']) ?></a>
                        <?php if ($l['website']): ?><a href="<?= e($l['website']) ?>" target="_blank" rel="noopener noreferrer" class="block truncate text-xs text-indigo-600 hover:underline"><?= e(preg_replace('#^https?://(www\.)?#', '', rtrim($l['website'], '/'))) ?></a><?php endif; ?>
                    </td>
                    <td class="whitespace-nowrap text-sm">
                        <?php if ($ph = $lp[0] ?? null): ?>
                            <span class="flex items-center gap-1"><button type="button" data-copy="<?= e($ph['phone_number']) ?>" title="Copy number" class="tabular-nums text-slate-700 hover:text-indigo-600"><?= e($ph['phone_number']) ?></button><?= zoom_call_button($ph['phone_number'], $l['name']) ?></span>
                        <?php else: ?><span class="text-slate-400">—</span><?php endif; ?>
                        <?php if ($lz = lead_time_zone($lp)): ?><span class="block text-xs text-slate-400" title="<?= e(US_TIME_ZONES[$lz][0]) ?> time"><?= e(US_TIME_ZONES[$lz][1]) ?> · <?= e(us_zone_local_time($lz)) ?></span><?php endif; ?>
                    </td>
                    <td><?php require __DIR__ . '/_status_form.php'; ?><?php require __DIR__ . '/_approval.php'; ?></td>
                    <td class="max-w-[18rem] text-xs text-slate-600">
                        <?php if (isset($notes[(int) $l['id']])): ?><span class="line-clamp-2" title="<?= e($notes[(int) $l['id']]) ?>"><?= e($notes[(int) $l['id']]) ?></span><?php else: ?><span class="text-slate-400">—</span><?php endif; ?>
                    </td>
                    <td><?= lead_last_call_badge($l) ?></td>
                    <?php if ($viewAll): ?><td class="whitespace-nowrap text-xs"><?= $l['assignee_name'] ? e($l['assignee_name']) : '<span class="text-slate-400">Unassigned</span>' ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$leads): ?>
            <div class="empty-state">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><?= icon('calendar', 'h-6 w-6') ?></span>
                <h3 class="mt-3 text-sm font-semibold"><?= $hasFilters ? 'Nothing matches these filters' : 'No pending tasks' ?></h3>
                <p class="mt-1 text-sm text-slate-500"><?= $hasFilters ? 'Try another date or clear the filters.' : 'Leads you set to ' . e(implode(', ', array_column($pending, 'name')) ?: 'a pending status') . ' show up here.' ?></p>
            </div>
        <?php endif; ?>
    </div>
    <?= pagination_links($p) ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
