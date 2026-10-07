<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

$status = in_array(input('status'), CAMPAIGN_STATUSES, true) ? (string) input('status') : '';
$q = trim((string) input('q'));
$where = 'cp.workspace_id = ?';
$params = [$ws];
if ($status) {
    $where .= ' AND cp.status = ?';
    $params[] = $status;
} else {
    $where .= " AND cp.status <> 'archived'";
}
if ($q !== '') {
    $where .= ' AND cp.name LIKE ?';
    $params[] = "%$q%";
}
$total = (int) q_val("SELECT COUNT(*) FROM campaigns cp WHERE $where", $params);
$p = paginate($total, per_page(25));
$campaigns = q_all(
    "SELECT cp.*, a.email account_email, t.name template_name,
        (SELECT COUNT(*) FROM campaign_contacts cc WHERE cc.campaign_id = cp.id) contacts,
        (SELECT COUNT(*) FROM email_jobs j WHERE j.campaign_id = cp.id AND j.status IN ('pending','processing')) queued,
        (SELECT COUNT(*) FROM email_messages m WHERE m.campaign_id = cp.id AND m.sent_at IS NOT NULL) sent,
        (SELECT COUNT(*) FROM email_messages m WHERE m.campaign_id = cp.id AND m.first_opened_at IS NOT NULL) opened,
        (SELECT COUNT(*) FROM email_messages m WHERE m.campaign_id = cp.id AND m.replied_at IS NOT NULL) replied
     FROM campaigns cp LEFT JOIN mail_accounts a ON a.id = cp.mail_account_id LEFT JOIN email_templates t ON t.id = cp.template_id
     WHERE $where ORDER BY FIELD(cp.status, 'active', 'paused', 'draft', 'completed', 'archived'), cp.updated_at DESC LIMIT ? OFFSET ?",
    [...$params, $p['per_page'], $p['offset']]
);
$counts = array_column(q_all('SELECT status, COUNT(*) n FROM campaigns WHERE workspace_id = ? GROUP BY status', [$ws]), 'n', 'status');

$page_title = 'Campaigns';
$active_nav = 'campaigns';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Campaigns</h1><p class="page-subtitle">Personalised outreach, sent safely in small batches by cron.</p></div>
    <a href="<?= e(url('campaigns/create.php')) ?>" class="btn-primary"><?= icon('plus', 'h-4 w-4') ?> New campaign</a>
</div>

<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex flex-wrap gap-1 rounded-lg bg-white p-1 ring-1 ring-slate-200">
        <a href="<?= e(url('campaigns/index.php')) ?>" class="rounded-md px-3 py-1 text-xs font-medium <?= !$status ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' ?>">All</a>
        <?php foreach (CAMPAIGN_STATUSES as $s): ?>
            <a href="<?= e(url('campaigns/index.php', ['status' => $s])) ?>" class="rounded-md px-3 py-1 text-xs font-medium capitalize <?= $status === $s ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' ?>"><?= e($s) ?> <span class="opacity-60"><?= (int) ($counts[$s] ?? 0) ?></span></a>
        <?php endforeach; ?>
    </div>
    <form class="w-full sm:w-64"><input type="hidden" name="status" value="<?= e($status) ?>"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search campaigns…" class="input"></form>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>Campaign</th><th>Status</th><th>Sender</th><th class="text-right">Contacts</th><th>Progress</th><th class="text-right">Open % ~</th><th class="text-right">Reply %</th></tr></thead>
            <tbody>
            <?php foreach ($campaigns as $c):
                $progress = $c['contacts'] ? min(100, round($c['sent'] / $c['contacts'] * 100)) : 0; ?>
                <tr>
                    <td>
                        <a href="<?= e(url('campaigns/view.php', ['id' => $c['id']])) ?>" class="font-medium text-slate-900 hover:text-indigo-600"><?= e($c['name']) ?></a>
                        <div class="text-xs text-slate-500"><?= e($c['template_name'] ?: 'No template') ?> · created <?= e(time_ago($c['created_at'])) ?></div>
                    </td>
                    <td><?= status_badge($c['status']) ?></td>
                    <td class="max-w-[12rem] truncate text-xs text-slate-600"><?= e($c['account_email'] ?: '—') ?></td>
                    <td class="text-right tabular-nums"><?= number_format((int) $c['contacts']) ?></td>
                    <td class="w-48">
                        <div class="flex items-center gap-2">
                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-indigo-500" style="width: <?= $progress ?>%"></div></div>
                            <span class="w-20 text-right text-xs tabular-nums text-slate-500"><?= number_format((int) $c['sent']) ?> sent</span>
                        </div>
                        <?php if ($c['queued']): ?><div class="mt-0.5 text-[11px] text-slate-400"><?= number_format((int) $c['queued']) ?> in queue</div><?php endif; ?>
                    </td>
                    <td class="text-right tabular-nums"><?= pct((int) $c['opened'], (int) $c['sent']) ?></td>
                    <td class="text-right font-medium tabular-nums text-emerald-600"><?= pct((int) $c['replied'], (int) $c['sent']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$campaigns): ?>
            <div class="empty-state">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><?= icon('megaphone', 'h-6 w-6') ?></span>
                <h3 class="mt-3 text-sm font-semibold">No campaigns<?= $status ? ' with this status' : ' yet' ?></h3>
                <a href="<?= e(url('campaigns/create.php')) ?>" class="btn-primary mt-4">Create campaign</a>
            </div>
        <?php endif; ?>
    </div>
    <?= pagination_links($p) ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
