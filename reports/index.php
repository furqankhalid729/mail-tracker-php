<?php
/**
 * Team reports (Super Admin): per-member task workload and timeline, and for closers
 * the emails they sent and the calls they made. ?user=ID drills into one member.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('reports.view');
$ws = ws_id();
$today = date('Y-m-d');
$week = date('Y-m-d', strtotime('+7 days'));
$open = placeholders(TASK_OPEN_STATUSES);

// Date range: same presets as the calls dashboard
$presets = [
    'today' => ['Today', $today, $today],
    '7d' => ['7 days', date('Y-m-d', strtotime('-6 days')), $today],
    '30d' => ['30 days', date('Y-m-d', strtotime('-29 days')), $today],
    'month' => ['This month', date('Y-m-01'), $today],
    'last_month' => ['Last month', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
];
$range = (string) input('range', '30d');
$isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if ($range === 'custom' && $isDate(input('from')) && $isDate(input('to'))) {
    [$from, $to] = [min(input('from'), input('to')), max(input('from'), input('to'))];
} else {
    $range = isset($presets[$range]) ? $range : '30d';
    [, $from, $to] = $presets[$range];
}
$start = $from . ' 00:00:00';
$end = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
$days = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);
$rangeQuery = ['range' => $range, 'from' => $range === 'custom' ? $from : null, 'to' => $range === 'custom' ? $to : null];

$members = q_all(
    'SELECT u.id, u.name, u.email, m.role FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? ORDER BY u.name',
    [$ws]
);
$byId = array_column($members, null, 'id');

/** Rows keyed by user id from a query whose first column is the user id. */
$keyed = fn(array $rows) => array_column($rows, null, 'uid');

// Tasks: current workload (not range-bound) plus what was completed in the range
$taskStats = $keyed(q_all(
    "SELECT t.assigned_to uid,
        COALESCE(SUM(t.status IN ($open)), 0) pending,
        COALESCE(SUM(t.status = 'backlog'), 0) backlog,
        COALESCE(SUM(t.status = 'in_progress'), 0) in_progress,
        COALESCE(SUM(t.status IN ($open) AND t.due_date < ?), 0) overdue,
        COALESCE(SUM(t.status IN ($open) AND t.due_date BETWEEN ? AND ?), 0) due_week,
        COALESCE(SUM(t.status IN ($open) AND t.due_date IS NULL), 0) no_due,
        MIN(CASE WHEN t.status IN ($open) AND t.due_date >= ? THEN t.due_date END) next_due,
        MAX(CASE WHEN t.status IN ($open) THEN t.due_date END) last_due,
        ROUND(AVG(CASE WHEN t.status IN ($open) THEN t.progress END)) avg_progress,
        COALESCE(SUM(t.status = 'done' AND t.completed_at >= ? AND t.completed_at < ?), 0) done,
        COALESCE(SUM(t.status = 'done' AND t.completed_at >= ? AND t.completed_at < ? AND t.due_date IS NOT NULL), 0) done_with_due,
        COALESCE(SUM(t.status = 'done' AND t.completed_at >= ? AND t.completed_at < ? AND DATE(t.completed_at) <= t.due_date), 0) done_on_time,
        AVG(CASE WHEN t.status = 'done' AND t.completed_at >= ? AND t.completed_at < ? THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.completed_at) END) avg_minutes
     FROM tasks t WHERE t.workspace_id = ? AND t.assigned_to IS NOT NULL GROUP BY t.assigned_to",
    [
        ...TASK_OPEN_STATUSES,                    // pending
        ...TASK_OPEN_STATUSES, $today,            // overdue
        ...TASK_OPEN_STATUSES, $today, $week,     // due_week
        ...TASK_OPEN_STATUSES,                    // no_due
        ...TASK_OPEN_STATUSES, $today,            // next_due
        ...TASK_OPEN_STATUSES,                    // last_due
        ...TASK_OPEN_STATUSES,                    // avg_progress
        $start, $end, $start, $end, $start, $end, $start, $end, $ws,
    ]
));

// Emails sent by the person (compose, reply) and by campaigns they created
$directEmails = $keyed(q_all(
    "SELECT user_id uid, COUNT(*) sent, COUNT(DISTINCT customer_id) recipients, COALESCE(SUM(replied_at IS NOT NULL), 0) replied
     FROM email_messages WHERE workspace_id = ? AND direction = 'outbound' AND user_id IS NOT NULL AND sent_at >= ? AND sent_at < ?
     GROUP BY user_id",
    [$ws, $start, $end]
));
$campaignEmails = $keyed(q_all(
    "SELECT cp.created_by uid, COUNT(*) sent FROM email_messages em JOIN campaigns cp ON cp.id = em.campaign_id
     WHERE em.workspace_id = ? AND em.direction = 'outbound' AND em.user_id IS NULL AND em.sent_at >= ? AND em.sent_at < ? AND cp.created_by IS NOT NULL
     GROUP BY cp.created_by",
    [$ws, $start, $end]
));

// Calls (Zoom Phone, attributed to closers by their numbers) and lead work
$callStats = $keyed(q_all(
    'SELECT c.closer_user_id uid, ' . CALL_KPI_SQL . ', MAX(c.start_time) last_call
     FROM zoom_calls c WHERE c.workspace_id = ? AND c.closer_user_id IS NOT NULL AND c.start_time >= ? AND c.start_time < ? GROUP BY c.closer_user_id',
    [$ws, $start, $end]
));
$leadStats = $keyed(q_all(
    "SELECT user_id uid, COALESCE(SUM(type = 'status'), 0) status_changes, COALESCE(SUM(type = 'note'), 0) notes
     FROM lead_activity WHERE workspace_id = ? AND user_id IS NOT NULL AND created_at >= ? AND created_at < ? GROUP BY user_id",
    [$ws, $start, $end]
));
$assignedLeads = $keyed(q_all('SELECT assigned_to uid, COUNT(*) n FROM leads WHERE workspace_id = ? AND assigned_to IS NOT NULL GROUP BY assigned_to', [$ws]));

$closers = array_values(array_filter($members, fn($m) => $m['role'] === 'closer'));
$team = array_values(array_filter($members, fn($m) => $m['role'] !== 'closer'));
$stat = fn(array $set, int $uid, string $col) => (int) ($set[$uid][$col] ?? 0);
$sum = fn(array $set, array $users, string $col) => array_sum(array_map(fn($u) => (int) ($set[$u['id']][$col] ?? 0), $users));

$formatMinutes = function ($minutes): string {
    if ($minutes === null) {
        return '—';
    }
    $minutes = (int) round((float) $minutes);
    return $minutes >= 1440 ? number_format($minutes / 1440, 1) . ' days' : ($minutes >= 60 ? number_format($minutes / 60, 1) . ' h' : $minutes . ' min');
};
$onTime = fn(int $uid) => $stat($taskStats, $uid, 'done_with_due') ? pct($stat($taskStats, $uid, 'done_on_time'), $stat($taskStats, $uid, 'done_with_due'), 0) : '—';
$dueDate = fn(?string $d) => $d ? format_dt($d, substr($d, 0, 4) === date('Y') ? 'M j' : 'M j, Y') : '—';

// One member's detail
$member = input_int('user') && isset($byId[input_int('user')]) ? $byId[input_int('user')] : null;
if ($member) {
    $uid = (int) $member['id'];
    $isCloser = $member['role'] === 'closer';
    $pendingTasks = q_all(
        "SELECT t.*, c.first_name, c.last_name, c.full_name, c.email customer_email, cp.name campaign_name
         FROM tasks t LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN campaigns cp ON cp.id = t.campaign_id
         WHERE t.workspace_id = ? AND t.assigned_to = ? AND t.status IN ($open, 'backlog')
         ORDER BY t.status = 'backlog', t.due_date IS NULL, t.due_date, FIELD(t.priority, 'urgent', 'high', 'medium', 'low'), t.id",
        [$ws, $uid, ...TASK_OPEN_STATUSES]
    );
    $doneTasks = q_all(
        "SELECT t.* FROM tasks t WHERE t.workspace_id = ? AND t.assigned_to = ? AND t.status = 'done' AND t.completed_at >= ? AND t.completed_at < ?
         ORDER BY t.completed_at DESC LIMIT 100",
        [$ws, $uid, $start, $end]
    );

    // Day by day: emails, calls and task completions
    $daily = [];
    if ($days <= 366) {
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $daily[$d] = ['emails' => 0, 'calls' => 0, 'connected' => 0, 'talk_seconds' => 0, 'done' => 0];
        }
    }
    $addDaily = function (array $rows) use (&$daily) {
        foreach ($rows as $r) {
            $daily[$r['d']] = array_merge($daily[$r['d']] ?? ['emails' => 0, 'calls' => 0, 'connected' => 0, 'talk_seconds' => 0, 'done' => 0], array_diff_key($r, ['d' => 1]));
        }
    };
    $addDaily(q_all(
        "SELECT DATE(em.sent_at) d, COUNT(*) emails FROM email_messages em LEFT JOIN campaigns cp ON cp.id = em.campaign_id
         WHERE em.workspace_id = ? AND em.direction = 'outbound' AND em.sent_at >= ? AND em.sent_at < ?
           AND (em.user_id = ?" . ($isCloser ? '' : ' OR (em.user_id IS NULL AND cp.created_by = ?)') . ')
         GROUP BY DATE(em.sent_at)',
        $isCloser ? [$ws, $start, $end, $uid] : [$ws, $start, $end, $uid, $uid]
    ));
    $addDaily(q_all(
        'SELECT DATE(c.start_time) d, COUNT(*) calls, COALESCE(SUM(c.is_connected), 0) connected, COALESCE(SUM(CASE WHEN c.is_connected = 1 THEN c.duration END), 0) talk_seconds
         FROM zoom_calls c WHERE c.workspace_id = ? AND c.closer_user_id = ? AND c.start_time >= ? AND c.start_time < ? GROUP BY DATE(c.start_time)',
        [$ws, $uid, $start, $end]
    ));
    $addDaily(q_all(
        "SELECT DATE(completed_at) d, COUNT(*) done FROM tasks WHERE workspace_id = ? AND assigned_to = ? AND status = 'done' AND completed_at >= ? AND completed_at < ? GROUP BY DATE(completed_at)",
        [$ws, $uid, $start, $end]
    ));
    krsort($daily);
    $recentEmails = q_all(
        "SELECT em.subject, em.to_email, em.sent_at, em.status, em.customer_id FROM email_messages em
         WHERE em.workspace_id = ? AND em.direction = 'outbound' AND em.user_id = ? AND em.sent_at >= ? AND em.sent_at < ?
         ORDER BY em.sent_at DESC LIMIT 10",
        [$ws, $uid, $start, $end]
    );
}

$page_title = $member ? $member['name'] . ' · Reports' : 'Reports';
$active_nav = 'reports';
require __DIR__ . '/../includes/header.php';
$rangeUrl = fn(array $over) => url('reports/index.php', array_filter(array_merge(['user' => $member['id'] ?? null], $over), fn($v) => $v !== null && $v !== ''));
?>
<div class="page-header">
    <div>
        <?php if ($member): ?>
            <a href="<?= e(url('reports/index.php', array_filter($rangeQuery))) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> All members</a>
            <h1 class="page-title flex items-center gap-3"><?= e($member['name']) ?> <?= role_badge($member['role']) ?></h1>
            <p class="page-subtitle"><?= $isCloser ? 'Emails sent and calls made.' : 'Task workload, timeline and output.' ?></p>
        <?php else: ?>
            <h1 class="page-title">Reports</h1>
            <p class="page-subtitle">What each member is working on, what is due, and what they delivered.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-6 p-3" x-data="{ custom: <?= $range === 'custom' ? 'true' : 'false' ?> }">
    <div class="flex flex-wrap items-center gap-2">
        <div class="flex flex-wrap items-center gap-1 rounded-lg bg-slate-100 p-1">
            <?php foreach ($presets as $k => [$label]): ?>
                <a href="<?= e($rangeUrl(['range' => $k])) ?>" class="rounded-md px-2.5 py-1 text-xs font-medium <?= $range === $k ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
            <button type="button" @click="custom = !custom" class="rounded-md px-2.5 py-1 text-xs font-medium <?= $range === 'custom' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>"><?= icon('calendar', 'inline h-3.5 w-3.5') ?> Custom</button>
        </div>
        <span class="ml-auto text-xs text-slate-500"><?= e(format_dt($from, 'M j, Y')) ?><?= $from !== $to ? ' – ' . e(format_dt($to, 'M j, Y')) : '' ?> · pending and overdue are as of today</span>
    </div>
    <form method="get" action="<?= e(url('reports/index.php')) ?>" x-show="custom" x-cloak class="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3">
        <input type="hidden" name="range" value="custom">
        <?php if ($member): ?><input type="hidden" name="user" value="<?= (int) $member['id'] ?>"><?php endif; ?>
        <div><label class="label !mb-1 text-xs" for="f_from">From</label><input class="input !py-1.5" type="date" id="f_from" name="from" value="<?= e($from) ?>" required></div>
        <div><label class="label !mb-1 text-xs" for="f_to">To</label><input class="input !py-1.5" type="date" id="f_to" name="to" value="<?= e($to) ?>" required></div>
        <button class="btn-primary btn-sm !py-2">Apply</button>
    </form>
</div>

<?php if (!$member): ?>
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-6">
        <div class="stat-card"><div class="stat-label">Pending tasks</div><div class="stat-value"><?= number_format($sum($taskStats, $members, 'pending')) ?></div><div class="mt-1 text-xs text-slate-500"><?= number_format($sum($taskStats, $members, 'backlog')) ?> in backlog</div></div>
        <div class="stat-card"><div class="stat-label">Overdue</div><div class="stat-value <?= $sum($taskStats, $members, 'overdue') ? 'text-red-600' : '' ?>"><?= number_format($sum($taskStats, $members, 'overdue')) ?></div></div>
        <div class="stat-card"><div class="stat-label">Due in 7 days</div><div class="stat-value text-amber-600"><?= number_format($sum($taskStats, $members, 'due_week')) ?></div></div>
        <div class="stat-card"><div class="stat-label">Tasks completed</div><div class="stat-value text-emerald-600"><?= number_format($sum($taskStats, $members, 'done')) ?></div><div class="mt-1 text-xs text-slate-500">in this period</div></div>
        <div class="stat-card"><div class="stat-label">Emails sent</div><div class="stat-value"><?= number_format($sum($directEmails, $members, 'sent') + $sum($campaignEmails, $members, 'sent')) ?></div><div class="mt-1 text-xs text-slate-500"><?= number_format($sum($campaignEmails, $members, 'sent')) ?> by campaigns</div></div>
        <div class="stat-card"><div class="stat-label">Closer calls</div><div class="stat-value"><?= number_format($sum($callStats, $closers, 'calls')) ?></div><div class="mt-1 text-xs text-slate-500"><?= number_format($sum($callStats, $closers, 'connected')) ?> connected</div></div>
    </div>

    <div class="card mt-6 overflow-hidden">
        <div class="card-header"><h2 class="card-title">Team · tasks</h2><span class="text-xs text-slate-400">click a name for their task timeline</span></div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr>
                    <th>Member</th><th class="text-right">Pending</th><th class="text-right">In progress</th><th class="text-right">Overdue</th><th class="text-right">Due ≤ 7d</th>
                    <th>Next due</th><th>All due by</th><th>Avg. progress</th><th class="text-right">Completed</th><th class="text-right">On time</th><th class="text-right">Avg. time to finish</th><th class="text-right">Emails sent</th>
                </tr></thead>
                <tbody>
                <?php foreach ($team as $m): $id = (int) $m['id']; $ts = $taskStats[$id] ?? null; ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('reports/index.php', array_filter(['user' => $id] + $rangeQuery))) ?>" class="flex items-center gap-3 whitespace-nowrap">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold <?= e(avatar_color($m['name'])) ?>" title="<?= e($m['name']) ?>"><?= e(user_initials($m['name'])) ?></span>
                                <span><span class="block font-medium text-slate-900 hover:text-indigo-600"><?= e($m['name']) ?></span><span class="block text-xs text-slate-500"><?= e(role_label($m['role'])) ?></span></span>
                            </a>
                        </td>
                        <td class="text-right tabular-nums font-medium"><?= $stat($taskStats, $id, 'pending') ?><?= $stat($taskStats, $id, 'backlog') ? '<span class="block text-xs font-normal text-slate-400">+' . $stat($taskStats, $id, 'backlog') . ' backlog</span>' : '' ?></td>
                        <td class="text-right tabular-nums"><?= $stat($taskStats, $id, 'in_progress') ?></td>
                        <td class="text-right tabular-nums <?= $stat($taskStats, $id, 'overdue') ? 'font-semibold text-red-600' : '' ?>"><?= $stat($taskStats, $id, 'overdue') ?></td>
                        <td class="text-right tabular-nums <?= $stat($taskStats, $id, 'due_week') ? 'text-amber-600' : '' ?>"><?= $stat($taskStats, $id, 'due_week') ?></td>
                        <td class="whitespace-nowrap text-xs"><?= e($dueDate($ts['next_due'] ?? null)) ?></td>
                        <td class="whitespace-nowrap text-xs"><?= e($dueDate($ts['last_due'] ?? null)) ?><?= $stat($taskStats, $id, 'no_due') ? '<span class="block text-slate-400">' . $stat($taskStats, $id, 'no_due') . ' without date</span>' : '' ?></td>
                        <td><?= isset($ts['avg_progress']) ? task_progress_bar((int) $ts['avg_progress']) : '<span class="text-slate-400">—</span>' ?></td>
                        <td class="text-right tabular-nums text-emerald-600"><?= $stat($taskStats, $id, 'done') ?></td>
                        <td class="text-right tabular-nums"><?= e($onTime($id)) ?></td>
                        <td class="whitespace-nowrap text-right tabular-nums"><?= e($formatMinutes($ts['avg_minutes'] ?? null)) ?></td>
                        <td class="text-right tabular-nums"><?= number_format($stat($directEmails, $id, 'sent') + $stat($campaignEmails, $id, 'sent')) ?><?= $stat($campaignEmails, $id, 'sent') ? '<span class="block text-xs text-slate-400">' . number_format($stat($campaignEmails, $id, 'sent')) . ' campaign</span>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mt-6 overflow-hidden">
        <div class="card-header"><h2 class="card-title">Closers · emails &amp; calls</h2><span class="text-xs text-slate-400">click a name for their day-by-day activity</span></div>
        <?php if ($closers): ?>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr>
                    <th>Closer</th><th class="text-right">Emails sent</th><th class="text-right">Recipients</th><th class="text-right">Replies</th>
                    <th class="text-right">Total calls</th><th class="text-right">Outbound</th><th class="text-right">Connected</th><th class="text-right">Rate</th><th class="text-right">Talk time</th><th class="text-right">Calls/day</th>
                    <th class="text-right">Lead updates</th><th class="text-right">Leads assigned</th><th>Last call</th>
                </tr></thead>
                <tbody>
                <?php foreach ($closers as $m): $id = (int) $m['id']; ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('reports/index.php', array_filter(['user' => $id] + $rangeQuery))) ?>" class="flex items-center gap-2 whitespace-nowrap font-medium text-slate-900 hover:text-indigo-600">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full text-[11px] font-semibold <?= e(avatar_color($m['name'])) ?>" title="<?= e($m['name']) ?>"><?= e(user_initials($m['name'])) ?></span><?= e($m['name']) ?>
                            </a>
                        </td>
                        <td class="text-right font-medium tabular-nums"><?= number_format($stat($directEmails, $id, 'sent')) ?></td>
                        <td class="text-right tabular-nums"><?= number_format($stat($directEmails, $id, 'recipients')) ?></td>
                        <td class="text-right tabular-nums"><?= number_format($stat($directEmails, $id, 'replied')) ?></td>
                        <td class="text-right font-medium tabular-nums"><?= number_format($stat($callStats, $id, 'calls')) ?></td>
                        <td class="text-right tabular-nums"><?= number_format($stat($callStats, $id, 'outbound')) ?></td>
                        <td class="text-right tabular-nums text-emerald-600"><?= number_format($stat($callStats, $id, 'connected')) ?></td>
                        <td class="text-right tabular-nums"><?= pct($stat($callStats, $id, 'connected'), $stat($callStats, $id, 'calls'), 0) ?></td>
                        <td class="whitespace-nowrap text-right tabular-nums"><?= e(format_duration($stat($callStats, $id, 'talk_seconds'))) ?></td>
                        <td class="text-right tabular-nums"><?= number_format($stat($callStats, $id, 'calls') / $days, 1) ?></td>
                        <td class="text-right tabular-nums" title="status changes / notes"><?= $stat($leadStats, $id, 'status_changes') + $stat($leadStats, $id, 'notes') ?></td>
                        <td class="text-right tabular-nums"><?= number_format($stat($assignedLeads, $id, 'n')) ?></td>
                        <td class="whitespace-nowrap text-xs text-slate-500"><?= e(time_ago($callStats[$id]['last_call'] ?? null)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="border-t-2 border-slate-200 bg-slate-50 text-sm font-semibold">
                    <tr>
                        <td class="px-4 py-3">All closers</td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= number_format($sum($directEmails, $closers, 'sent')) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= number_format($sum($directEmails, $closers, 'recipients')) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= number_format($sum($directEmails, $closers, 'replied')) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= number_format($sum($callStats, $closers, 'calls')) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= number_format($sum($callStats, $closers, 'outbound')) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums text-emerald-600"><?= number_format($sum($callStats, $closers, 'connected')) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= pct($sum($callStats, $closers, 'connected'), $sum($callStats, $closers, 'calls'), 0) ?></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(format_duration($sum($callStats, $closers, 'talk_seconds'))) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= number_format($sum($callStats, $closers, 'calls') / $days, 1) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= $sum($leadStats, $closers, 'status_changes') + $sum($leadStats, $closers, 'notes') ?></td>
                        <td class="px-4 py-3 text-right tabular-nums"><?= number_format($sum($assignedLeads, $closers, 'n')) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
            <div class="card-body text-sm text-slate-500">No members have the Closer role yet.</div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <?php if ($isCloser): ?>
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <div class="stat-card"><div class="stat-label">Emails sent</div><div class="stat-value"><?= number_format($stat($directEmails, $uid, 'sent')) ?></div><div class="mt-1 text-xs text-slate-500"><?= number_format($stat($directEmails, $uid, 'recipients')) ?> recipients · <?= number_format($stat($directEmails, $uid, 'replied')) ?> replied</div></div>
            <div class="stat-card"><div class="stat-label">Total calls</div><div class="stat-value"><?= number_format($stat($callStats, $uid, 'calls')) ?></div><div class="mt-1 text-xs text-slate-500"><?= number_format($stat($callStats, $uid, 'calls') / $days, 1) ?> per day</div></div>
            <div class="stat-card"><div class="stat-label">Connected</div><div class="stat-value text-emerald-600"><?= number_format($stat($callStats, $uid, 'connected')) ?></div><div class="mt-1 text-xs text-slate-500"><?= pct($stat($callStats, $uid, 'connected'), $stat($callStats, $uid, 'calls')) ?> connect rate</div></div>
            <div class="stat-card"><div class="stat-label">Talk time</div><div class="stat-value"><?= e(format_duration($stat($callStats, $uid, 'talk_seconds'))) ?></div></div>
            <div class="stat-card"><div class="stat-label">Lead updates</div><div class="stat-value"><?= $stat($leadStats, $uid, 'status_changes') + $stat($leadStats, $uid, 'notes') ?></div><div class="mt-1 text-xs text-slate-500"><?= $stat($leadStats, $uid, 'status_changes') ?> status · <?= $stat($leadStats, $uid, 'notes') ?> notes</div></div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-6">
            <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-value"><?= $stat($taskStats, $uid, 'pending') ?></div><div class="mt-1 text-xs text-slate-500"><?= $stat($taskStats, $uid, 'backlog') ?> in backlog</div></div>
            <div class="stat-card"><div class="stat-label">Overdue</div><div class="stat-value <?= $stat($taskStats, $uid, 'overdue') ? 'text-red-600' : '' ?>"><?= $stat($taskStats, $uid, 'overdue') ?></div></div>
            <div class="stat-card"><div class="stat-label">Due in 7 days</div><div class="stat-value text-amber-600"><?= $stat($taskStats, $uid, 'due_week') ?></div><div class="mt-1 text-xs text-slate-500">all due by <?= e($dueDate($taskStats[$uid]['last_due'] ?? null)) ?></div></div>
            <div class="stat-card"><div class="stat-label">Completed</div><div class="stat-value text-emerald-600"><?= $stat($taskStats, $uid, 'done') ?></div><div class="mt-1 text-xs text-slate-500">in this period</div></div>
            <div class="stat-card"><div class="stat-label">On time</div><div class="stat-value"><?= e($onTime($uid)) ?></div><div class="mt-1 text-xs text-slate-500">avg. <?= e($formatMinutes($taskStats[$uid]['avg_minutes'] ?? null)) ?> to finish</div></div>
            <div class="stat-card"><div class="stat-label">Emails sent</div><div class="stat-value"><?= number_format($stat($directEmails, $uid, 'sent') + $stat($campaignEmails, $uid, 'sent')) ?></div><div class="mt-1 text-xs text-slate-500"><?= number_format($stat($campaignEmails, $uid, 'sent')) ?> by campaigns</div></div>
        </div>

        <div class="card mt-6 overflow-hidden">
            <div class="card-header"><h2 class="card-title">Pending tasks · timeline</h2><span class="text-xs text-slate-400">overdue and soonest due first</span></div>
            <?php if ($pendingTasks): ?>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Task</th><th>Priority</th><th>Status</th><th>Progress</th><th>Due</th><th class="text-right">Time left</th><th>Assigned</th></tr></thead>
                    <tbody>
                    <?php foreach ($pendingTasks as $t):
                        $left = $t['due_date'] ? (int) round((strtotime($t['due_date']) - strtotime($today)) / 86400) : null; ?>
                        <tr>
                            <td class="max-w-md">
                                <a href="<?= e(url('tasks/view.php', ['id' => $t['id']])) ?>" class="block truncate font-medium text-slate-900 hover:text-indigo-600"><?= e($t['title']) ?></a>
                                <?php if ($t['customer_id'] || $t['campaign_id']): ?>
                                    <div class="truncate text-xs text-slate-500"><?= $t['customer_id'] ? e(customer_name(['full_name' => $t['full_name'], 'first_name' => $t['first_name'], 'last_name' => $t['last_name'], 'email' => $t['customer_email']])) : '' ?><?= $t['customer_id'] && $t['campaign_id'] ? ' · ' : '' ?><?= $t['campaign_id'] ? e($t['campaign_name']) : '' ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= task_priority_badge($t['priority']) ?></td>
                            <td><?= task_status_badge($t['status']) ?></td>
                            <td><?= task_progress_bar((int) $t['progress']) ?></td>
                            <td class="whitespace-nowrap text-xs"><?= task_due_label($t) ?></td>
                            <td class="whitespace-nowrap text-right text-xs tabular-nums <?= $left !== null && $left < 0 && $t['status'] !== 'backlog' ? 'font-semibold text-red-600' : ($left !== null && $left <= 2 ? 'text-amber-600' : 'text-slate-500') ?>">
                                <?= $left === null ? '—' : ($left < 0 ? abs($left) . ' ' . (abs($left) === 1 ? 'day' : 'days') . ' late' : ($left === 0 ? 'due today' : $left . ' ' . ($left === 1 ? 'day' : 'days'))) ?>
                            </td>
                            <td class="whitespace-nowrap text-xs text-slate-500"><?= e(time_ago($t['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="card-body text-sm text-slate-500">No pending tasks.</div>
            <?php endif; ?>
        </div>

        <div class="card mt-6 overflow-hidden">
            <div class="card-header"><h2 class="card-title">Completed in this period</h2><span class="text-xs text-slate-400"><?= count($doneTasks) ?><?= count($doneTasks) === 100 ? '+' : '' ?></span></div>
            <?php if ($doneTasks): ?>
            <div class="max-h-[28rem] overflow-auto">
                <table class="table">
                    <thead class="sticky top-0"><tr><th>Task</th><th>Due</th><th>Completed</th><th>Timeline</th><th class="text-right">Took</th></tr></thead>
                    <tbody>
                    <?php foreach ($doneTasks as $t):
                        $late = $t['due_date'] ? (int) round((strtotime(substr($t['completed_at'], 0, 10)) - strtotime($t['due_date'])) / 86400) : null; ?>
                        <tr>
                            <td class="max-w-md"><a href="<?= e(url('tasks/view.php', ['id' => $t['id']])) ?>" class="block truncate font-medium text-slate-900 hover:text-indigo-600"><?= e($t['title']) ?></a></td>
                            <td class="whitespace-nowrap text-xs"><?= e($dueDate($t['due_date'])) ?></td>
                            <td class="whitespace-nowrap text-xs"><?= e(format_dt($t['completed_at'], 'M j, g:i A')) ?></td>
                            <td><?= $late === null ? '<span class="text-xs text-slate-400">no due date</span>' : ($late > 0 ? '<span class="badge badge-red !normal-case">' . $late . ' ' . ($late === 1 ? 'day' : 'days') . ' late</span>' : '<span class="badge badge-green !normal-case">On time</span>') ?></td>
                            <td class="whitespace-nowrap text-right text-xs tabular-nums text-slate-500"><?= e($formatMinutes((strtotime($t['completed_at']) - strtotime($t['created_at'])) / 60)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="card-body text-sm text-slate-500">Nothing completed in this period.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="card overflow-hidden lg:col-span-2">
            <div class="card-header"><h2 class="card-title">Day by day</h2></div>
            <div class="max-h-[28rem] overflow-auto">
                <table class="table">
                    <thead class="sticky top-0"><tr>
                        <th>Date</th><th class="text-right">Emails sent</th>
                        <?php if ($isCloser): ?><th class="text-right">Calls</th><th class="text-right">Connected</th><th class="text-right">Talk time</th><?php else: ?><th class="text-right">Tasks completed</th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($daily as $d => $r): $quiet = !$r['emails'] && !$r['calls'] && !$r['done']; ?>
                        <tr class="<?= $quiet ? 'text-slate-400' : '' ?>">
                            <td class="whitespace-nowrap"><?= e(format_dt($d, 'D, M j')) ?></td>
                            <td class="text-right tabular-nums"><?= number_format((int) $r['emails']) ?></td>
                            <?php if ($isCloser): ?>
                                <td class="text-right tabular-nums"><?= number_format((int) $r['calls']) ?></td>
                                <td class="text-right tabular-nums <?= $quiet ? '' : 'text-emerald-600' ?>"><?= number_format((int) $r['connected']) ?></td>
                                <td class="whitespace-nowrap text-right tabular-nums"><?= e(format_duration((int) $r['talk_seconds'])) ?></td>
                            <?php else: ?>
                                <td class="text-right tabular-nums"><?= number_format((int) $r['done']) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card overflow-hidden">
            <div class="card-header"><h2 class="card-title">Recent emails</h2></div>
            <?php if ($recentEmails): ?>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($recentEmails as $em): ?>
                        <li class="px-4 py-3 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate font-medium text-slate-900"><?= e($em['subject'] ?: '(no subject)') ?></span>
                                <?= status_badge($em['status']) ?>
                            </div>
                            <div class="mt-0.5 flex justify-between gap-2 text-xs text-slate-500">
                                <?php if ($em['customer_id']): ?><a class="truncate hover:text-indigo-600" href="<?= e(url('customers/view.php', ['id' => $em['customer_id']])) ?>"><?= e($em['to_email']) ?></a><?php else: ?><span class="truncate"><?= e($em['to_email']) ?></span><?php endif; ?>
                                <span class="whitespace-nowrap"><?= e(time_ago($em['sent_at'])) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="card-body text-sm text-slate-500">No emails sent in this period.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($isCloser): ?>
        <div class="mt-4 text-right"><a class="text-sm font-medium text-indigo-600 hover:underline" href="<?= e(url('calls/index.php', array_filter(['closer' => $uid] + $rangeQuery))) ?>">Full call dashboard →</a></div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php';
