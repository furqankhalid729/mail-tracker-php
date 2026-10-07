<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

$campaignId = input_int('campaign_id');
$type = (string) input('type');
$customerQ = trim((string) input('customer'));
$sender = input_int('sender');
$from = (string) input('from');
$to = (string) input('to');
$validDate = fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

$crmTypes = ['campaign_added', 'campaign_removed', 'status_changed', 'customer_created', 'imported', 'resubscribed', 'campaign_started', 'campaign_paused', 'campaign_completed'];
$includeEvents = $type === '' || in_array($type, EVENT_TYPES, true);
$includeActivity = ($type === '' || in_array($type, $crmTypes, true)) && !$sender;

// Shared conditions, applied to each half of the UNION
$evWhere = ['ev.workspace_id = ?'];
$evParams = [$ws];
$acWhere = ['a.workspace_id = ?'];
$acParams = [$ws];
if ($campaignId) {
    $evWhere[] = 'ev.campaign_id = ?';
    $evParams[] = $campaignId;
    $acWhere[] = 'a.campaign_id = ?';
    $acParams[] = $campaignId;
}
if ($type !== '') {
    $evWhere[] = 'ev.type = ?';
    $evParams[] = $type;
    $acWhere[] = 'a.type = ?';
    $acParams[] = $type;
}
if ($customerQ !== '') {
    $like = '%' . $customerQ . '%';
    $evWhere[] = '(c.email LIKE ? OR c.full_name LIKE ? OR c.company LIKE ?)';
    array_push($evParams, $like, $like, $like);
    $acWhere[] = '(c.email LIKE ? OR c.full_name LIKE ? OR c.company LIKE ?)';
    array_push($acParams, $like, $like, $like);
}
if ($sender) {
    $evWhere[] = 'm.mail_account_id = ?';
    $evParams[] = $sender;
}
if ($validDate($from)) {
    $evWhere[] = 'ev.created_at >= ?';
    $evParams[] = "$from 00:00:00";
    $acWhere[] = 'a.created_at >= ?';
    $acParams[] = "$from 00:00:00";
}
if ($validDate($to)) {
    $evWhere[] = 'ev.created_at <= ?';
    $evParams[] = "$to 23:59:59";
    $acWhere[] = 'a.created_at <= ?';
    $acParams[] = "$to 23:59:59";
}

$parts = [];
$params = [];
if ($includeEvents) {
    $parts[] = "SELECT 'event' kind, ev.id, ev.type, ev.created_at, ev.metadata, NULL description, ev.customer_id, c.first_name, c.last_name, c.full_name, c.email, cp.id campaign_id, cp.name campaign, m.subject, m.thread_id
        FROM email_events ev LEFT JOIN customers c ON c.id = ev.customer_id LEFT JOIN campaigns cp ON cp.id = ev.campaign_id LEFT JOIN email_messages m ON m.id = ev.email_message_id
        WHERE " . implode(' AND ', $evWhere);
    array_push($params, ...$evParams);
}
if ($includeActivity) {
    $parts[] = "SELECT 'activity', a.id, a.type, a.created_at, NULL, a.description, a.customer_id, c.first_name, c.last_name, c.full_name, c.email, cp.id, cp.name, NULL, NULL
        FROM activity_log a LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN campaigns cp ON cp.id = a.campaign_id
        WHERE " . implode(' AND ', $acWhere);
    array_push($params, ...$acParams);
}

$rows = [];
$total = 0;
if ($parts) {
    $union = '(' . implode(') UNION ALL (', $parts) . ')';
    $total = (int) q_val("SELECT COUNT(*) FROM ($union) x", $params);
    $p = paginate($total, per_page(50));
    $rows = q_all("SELECT * FROM ($union) x ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?", [...$params, $p['per_page'], $p['offset']]);
} else {
    $p = paginate(0, per_page(50));
}

$campaigns = q_all('SELECT id, name FROM campaigns WHERE workspace_id = ? ORDER BY created_at DESC', [$ws]);
$senders = q_all('SELECT id, email FROM mail_accounts WHERE workspace_id = ? ORDER BY email', [$ws]);

$page_title = 'Activity';
$active_nav = 'activity';
require __DIR__ . '/../includes/header.php';
$lastDay = null;
?>
<div class="page-header">
    <div><h1 class="page-title">Activity</h1><p class="page-subtitle">Every email event and CRM change across your workspace. Opens are approximate.</p></div>
</div>

<form method="get" class="card mb-4 grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-6">
    <input type="search" name="customer" value="<?= e($customerQ) ?>" class="input" placeholder="Customer or company…">
    <select name="campaign_id" class="input"><option value="">All campaigns</option><?php foreach ($campaigns as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $campaignId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <select name="type" class="input">
        <option value="">All events</option>
        <optgroup label="Email"><?php foreach (EVENT_TYPES as $t): ?><option value="<?= e($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= e(event_label($t)) ?></option><?php endforeach; ?></optgroup>
        <optgroup label="CRM"><?php foreach ($crmTypes as $t): ?><option value="<?= e($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= e(event_label($t)) ?></option><?php endforeach; ?></optgroup>
    </select>
    <select name="sender" class="input"><option value="">Any sender</option><?php foreach ($senders as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sender === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['email']) ?></option><?php endforeach; ?></select>
    <div class="flex gap-2"><input type="date" name="from" value="<?= e($from) ?>" class="input"><input type="date" name="to" value="<?= e($to) ?>" class="input"></div>
    <div class="flex gap-2"><button class="btn-primary flex-1">Filter</button><a href="<?= e(url('activity/index.php')) ?>" class="btn-ghost">Reset</a></div>
</form>

<div class="card">
    <ul class="divide-y divide-slate-100">
        <?php foreach ($rows as $r):
            $day = date('Y-m-d', strtotime($r['created_at']));
            $meta = $r['metadata'] ? (json_decode($r['metadata'], true) ?: []) : [];
            if ($day !== $lastDay): $lastDay = $day; ?>
                <li class="bg-slate-50/80 px-5 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500"><?= e($day === date('Y-m-d') ? 'Today' : ($day === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : date('l, M j, Y', strtotime($day)))) ?></li>
            <?php endif; ?>
            <li class="flex items-start gap-3 px-5 py-3">
                <span class="w-14 shrink-0 pt-1.5 text-xs tabular-nums text-slate-400"><?= e(date('H:i', strtotime($r['created_at']))) ?></span>
                <?= event_icon($r['type']) ?>
                <div class="min-w-0 flex-1 pt-1 text-sm">
                    <?php if ($r['customer_id']): ?><a class="font-medium text-slate-900 hover:text-indigo-600" href="<?= e(url('customers/view.php', ['id' => $r['customer_id']])) ?>"><?= e(customer_name($r)) ?></a><?php endif; ?>
                    <span class="text-slate-600"><?= $r['kind'] === 'activity' ? e(lcfirst($r['description'])) : e(strtolower(event_label($r['type']))) ?></span>
                    <?php if (!empty($meta['url'])): ?><span class="text-slate-400">→</span> <span class="text-indigo-600"><?= e(mb_strimwidth($meta['url'], 0, 60, '…')) ?></span><?php endif; ?>
                    <div class="truncate text-xs text-slate-500">
                        <?php if ($r['subject']): ?><?php if ($r['thread_id']): ?><a class="hover:text-indigo-600" href="<?= e(url('inbox/thread.php', ['id' => $r['thread_id']])) ?>">“<?= e($r['subject']) ?>”</a><?php else: ?>“<?= e($r['subject']) ?>”<?php endif; ?><?php endif; ?>
                        <?php if ($r['campaign']): ?> · <a class="hover:text-indigo-600" href="<?= e(url('campaigns/view.php', ['id' => $r['campaign_id']])) ?>"><?= e($r['campaign']) ?></a><?php endif; ?>
                        <?php if (!empty($meta['reason'])): ?> · <?= e($meta['reason']) ?><?php endif; ?>
                        <?php if (!empty($meta['error'])): ?> · <span class="text-red-600"><?= e($meta['error']) ?></span><?php endif; ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if (!$rows): ?><div class="empty-state"><h3 class="text-sm font-semibold">No activity found</h3><p class="mt-1 text-sm text-slate-500">Events appear here as emails are sent, opened, clicked and replied to.</p></div><?php endif; ?>
    <?= pagination_links($p) ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
