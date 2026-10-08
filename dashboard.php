<?php
require_once __DIR__ . '/includes/init.php';
require_auth();
$ws = ws_id();

$days = in_array(input_int('days', 30), [7, 30, 90], true) ? input_int('days', 30) : 30;
$since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

$totalContacts = (int) q_val('SELECT COUNT(*) FROM customers WHERE workspace_id = ?', [$ws]);
$activeCampaigns = (int) q_val("SELECT COUNT(*) FROM campaigns WHERE workspace_id = ? AND status = 'active'", [$ws]);

// Unique-message engagement counts in the period, from the event ledger (indexed on workspace_id, type, created_at)
$counts = ['sent' => 0, 'delivered' => 0, 'opened' => 0, 'clicked' => 0, 'replied' => 0, 'bounced' => 0];
foreach (q_all(
    "SELECT type, COUNT(DISTINCT email_message_id) n FROM email_events
     WHERE workspace_id = ? AND created_at >= ? AND type IN ('sent','delivered','opened','clicked','replied','bounced')
     GROUP BY type",
    [$ws, $since]
) as $r) {
    $counts[$r['type']] = (int) $r['n'];
}
$sent = $counts['sent'];

// Daily series for the chart
$series = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $series[date('Y-m-d', strtotime("-$i days"))] = ['sent' => 0, 'opened' => 0, 'clicked' => 0, 'replied' => 0];
}
foreach (q_all(
    "SELECT DATE(created_at) d, type, COUNT(*) n FROM email_events
     WHERE workspace_id = ? AND created_at >= ? AND type IN ('sent','opened','clicked','replied')
     GROUP BY DATE(created_at), type",
    [$ws, $since]
) as $r) {
    if (isset($series[$r['d']])) {
        $series[$r['d']][$r['type']] = (int) $r['n'];
    }
}

$recent = q_all(
    "SELECT ev.type, ev.created_at, ev.metadata, c.id customer_id, c.first_name, c.last_name, c.full_name, c.email, cp.name campaign_name
     FROM email_events ev LEFT JOIN customers c ON c.id = ev.customer_id LEFT JOIN campaigns cp ON cp.id = ev.campaign_id
     WHERE ev.workspace_id = ? AND ev.type IN ('opened','clicked','replied','bounced','unsubscribed','sent')
     ORDER BY ev.id DESC LIMIT 12",
    [$ws]
);

$topCampaigns = q_all(
    "SELECT cp.id, cp.name, cp.status,
        (SELECT COUNT(*) FROM campaign_contacts cc WHERE cc.campaign_id = cp.id) contacts,
        SUM(m.sent_at IS NOT NULL) sent,
        SUM(m.first_opened_at IS NOT NULL) opened,
        SUM(m.first_clicked_at IS NOT NULL) clicked,
        SUM(m.replied_at IS NOT NULL) replied
     FROM campaigns cp LEFT JOIN email_messages m ON m.campaign_id = cp.id AND m.direction = 'outbound'
     WHERE cp.workspace_id = ? AND cp.status <> 'archived'
     GROUP BY cp.id ORDER BY sent DESC, cp.updated_at DESC LIMIT 6",
    [$ws]
);

$queuePending = (int) q_val("SELECT COUNT(*) FROM email_jobs WHERE workspace_id = ? AND status IN ('pending','processing')", [$ws]);
$hasAccount = (bool) q_val('SELECT id FROM mail_accounts WHERE workspace_id = ? LIMIT 1', [$ws]);
$hasTemplate = (bool) q_val('SELECT id FROM email_templates WHERE workspace_id = ? LIMIT 1', [$ws]);
$hasCampaign = (bool) q_val('SELECT id FROM campaigns WHERE workspace_id = ? LIMIT 1', [$ws]);
$sentToday = sent_today_for_workspace($ws);
$workspace = current_workspace();
$myTasks = q_all(
    "SELECT t.* FROM tasks t WHERE t.workspace_id = ? AND t.assigned_to = ? AND t.status IN (" . placeholders(TASK_OPEN_STATUSES) . ")
     ORDER BY t.due_date IS NULL, t.due_date, FIELD(t.priority, 'urgent', 'high', 'medium', 'low') LIMIT 6",
    [$ws, user_id(), ...TASK_OPEN_STATUSES]
);

$page_title = 'Dashboard';
$active_nav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Good <?= (int) date('G') < 12 ? 'morning' : ((int) date('G') < 18 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', current_user()['name'])[0]) ?></h1>
        <p class="page-subtitle">Here's how your outreach is performing.</p>
    </div>
    <div class="flex items-center gap-1 rounded-lg bg-white p-1 ring-1 ring-slate-200">
        <?php foreach ([7, 30, 90] as $d): ?>
            <a href="<?= e(url('dashboard.php', ['days' => $d])) ?>" class="rounded-md px-3 py-1 text-xs font-medium <?= $d === $days ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' ?>"><?= $d ?> days</a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!$hasAccount || !$hasTemplate || !$hasCampaign || !$totalContacts): ?>
<div class="card mb-6 p-5">
    <h2 class="text-sm font-semibold">Get started</h2>
    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <?php foreach ([
            [$hasAccount, 'Connect a mail account', 'SMTP, Gmail or Google Workspace', 'mail-accounts/create.php'],
            [$totalContacts > 0, 'Import customers', 'Upload a CSV with column mapping', 'customers/import.php'],
            [$hasTemplate, 'Write a template', 'Use {{firstName}} and other variables', 'templates/create.php'],
            [$hasCampaign, 'Launch a campaign', 'Queue personalised emails', 'campaigns/create.php'],
        ] as $i => [$done, $title, $sub, $href]): ?>
            <a href="<?= e(url($href)) ?>" class="flex gap-3 rounded-lg border border-slate-200 p-3 hover:border-indigo-300 hover:bg-indigo-50/30">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold <?= $done ? 'bg-emerald-500 text-white' : 'bg-slate-100 text-slate-500' ?>"><?= $done ? '✓' : $i + 1 ?></span>
                <span><span class="block text-sm font-medium <?= $done ? 'text-slate-400 line-through' : 'text-slate-800' ?>"><?= e($title) ?></span><span class="text-xs text-slate-500"><?= e($sub) ?></span></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
    <div class="stat-card"><div class="stat-label">Total contacts</div><div class="stat-value"><?= number_format($totalContacts) ?></div></div>
    <div class="stat-card"><div class="stat-label">Active campaigns</div><div class="stat-value"><?= number_format($activeCampaigns) ?></div></div>
    <div class="stat-card"><div class="stat-label">Emails sent <span class="normal-case text-slate-400">(<?= $days ?>d)</span></div><div class="stat-value"><?= number_format($sent) ?></div><div class="mt-1 text-xs text-slate-500"><?= number_format($sentToday) ?> today · limit <?= number_format((int) $workspace['daily_limit']) ?> · <?= number_format($queuePending) ?> queued</div></div>
    <div class="stat-card"><div class="stat-label">Delivery rate</div><div class="stat-value"><?= pct($counts['delivered'], $sent) ?></div><div class="mt-1 text-xs text-slate-500">Requires provider webhooks</div></div>
    <div class="stat-card"><div class="stat-label tooltip" data-tip="Approximate: privacy features can pre-load images">Open rate ~</div><div class="stat-value"><?= pct($counts['opened'], $sent) ?></div></div>
    <div class="stat-card"><div class="stat-label">Click rate</div><div class="stat-value"><?= pct($counts['clicked'], $sent) ?></div></div>
    <div class="stat-card"><div class="stat-label">Reply rate</div><div class="stat-value text-emerald-600"><?= pct($counts['replied'], $sent) ?></div></div>
    <div class="stat-card"><div class="stat-label">Bounce rate</div><div class="stat-value <?= $sent && $counts['bounced'] / $sent > 0.05 ? 'text-red-600' : '' ?>"><?= pct($counts['bounced'], $sent) ?></div></div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="card lg:col-span-2">
        <div class="card-header"><h2 class="card-title">Email activity</h2><span class="text-xs text-slate-400">Last <?= $days ?> days</span></div>
        <div class="card-body"><div class="h-72"><canvas id="activityChart"></canvas></div></div>
    </div>
    <div class="card">
        <div class="card-header"><h2 class="card-title">Recent activity</h2><a href="<?= e(url('activity/index.php')) ?>" class="text-xs font-medium text-indigo-600">View all</a></div>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($recent as $r): ?>
                <li class="flex items-center gap-3 px-5 py-2.5">
                    <?= event_icon($r['type']) ?>
                    <div class="min-w-0 flex-1 text-sm">
                        <a href="<?= e(url('customers/view.php', ['id' => $r['customer_id']])) ?>" class="font-medium text-slate-800 hover:text-indigo-600"><?= e(customer_name($r)) ?></a>
                        <span class="text-slate-500"><?= e(strtolower(event_label($r['type']))) ?></span>
                        <?php if ($r['campaign_name']): ?><div class="truncate text-xs text-slate-400"><?= e($r['campaign_name']) ?></div><?php endif; ?>
                    </div>
                    <span class="shrink-0 text-xs text-slate-400"><?= e(time_ago($r['created_at'])) ?></span>
                </li>
            <?php endforeach; ?>
            <?php if (!$recent): ?><li class="px-5 py-10 text-center text-sm text-slate-400">No activity yet.</li><?php endif; ?>
        </ul>
    </div>
</div>

<div class="card mt-6 overflow-hidden">
    <div class="card-header"><h2 class="card-title">My tasks</h2><a href="<?= e(url('tasks/index.php')) ?>" class="text-xs font-medium text-indigo-600">All tasks</a></div>
    <ul class="divide-y divide-slate-100">
        <?php foreach ($myTasks as $t): ?>
            <li class="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center sm:gap-4">
                <a href="<?= e(url('tasks/view.php', ['id' => $t['id']])) ?>" class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 hover:text-indigo-600"><?= e($t['title']) ?></a>
                <div class="flex items-center gap-3"><?= task_priority_badge($t['priority']) ?><?= task_status_badge($t['status']) ?><?= task_progress_bar((int) $t['progress'], 'w-20') ?><span class="w-36 text-right text-xs"><?= task_due_label($t) ?></span></div>
            </li>
        <?php endforeach; ?>
        <?php if (!$myTasks): ?><li class="px-5 py-8 text-center text-sm text-slate-400">Nothing assigned to you. <a class="text-indigo-600" href="<?= e(url('tasks/create.php')) ?>">Create a task</a></li><?php endif; ?>
    </ul>
</div>

<div class="card mt-6 overflow-hidden">
    <div class="card-header"><h2 class="card-title">Top campaigns</h2><a href="<?= e(url('campaigns/index.php')) ?>" class="text-xs font-medium text-indigo-600">All campaigns</a></div>
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>Campaign</th><th>Status</th><th class="text-right">Contacts</th><th class="text-right">Sent</th><th class="text-right">Open % ~</th><th class="text-right">Click %</th><th class="text-right">Reply %</th></tr></thead>
            <tbody>
            <?php foreach ($topCampaigns as $c): ?>
                <tr>
                    <td><a class="font-medium text-slate-900 hover:text-indigo-600" href="<?= e(url('campaigns/view.php', ['id' => $c['id']])) ?>"><?= e($c['name']) ?></a></td>
                    <td><?= status_badge($c['status']) ?></td>
                    <td class="text-right tabular-nums"><?= number_format((int) $c['contacts']) ?></td>
                    <td class="text-right tabular-nums"><?= number_format((int) $c['sent']) ?></td>
                    <td class="text-right tabular-nums"><?= pct((int) $c['opened'], (int) $c['sent']) ?></td>
                    <td class="text-right tabular-nums"><?= pct((int) $c['clicked'], (int) $c['sent']) ?></td>
                    <td class="text-right tabular-nums font-medium text-emerald-600"><?= pct((int) $c['replied'], (int) $c['sent']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$topCampaigns): ?><tr><td colspan="7" class="py-10 text-center text-slate-400">No campaigns yet. <a class="text-indigo-600" href="<?= e(url('campaigns/create.php')) ?>">Create one</a></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$extra_scripts = '<script src="' . e(asset('vendor/chart.umd.min.js')) . '"></script>
<script>
(function () {
    const s = ' . json_encode($series) . ';
    const labels = Object.keys(s).map(d => new Date(d + "T00:00:00").toLocaleDateString(undefined, { month: "short", day: "numeric" }));
    const ds = (label, key, color) => ({ label, data: Object.values(s).map(v => v[key]), borderColor: color, backgroundColor: color + "22", tension: .35, fill: key === "sent", pointRadius: 0, borderWidth: 2 });
    new Chart(document.getElementById("activityChart"), {
        type: "line",
        data: { labels, datasets: [ds("Sent", "sent", "#6366f1"), ds("Opened (approx.)", "opened", "#8b5cf6"), ds("Clicked", "clicked", "#0ea5e9"), ds("Replied", "replied", "#10b981")] },
        options: { maintainAspectRatio: false, interaction: { mode: "index", intersect: false }, plugins: { legend: { position: "bottom", labels: { usePointStyle: true, boxWidth: 6 } } },
            scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: "#f1f5f9" } } } }
    });
})();
</script>';
require __DIR__ . '/includes/footer.php';
