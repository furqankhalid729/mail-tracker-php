<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

$status = in_array(input('status'), CAMPAIGN_STATUSES, true) ? (string) input('status') : '';
$q = trim((string) input('q'));
$where = 'a.workspace_id = ?';
$params = [$ws];
if ($status) {
    $where .= ' AND a.status = ?';
    $params[] = $status;
} else {
    $where .= " AND a.status <> 'archived'";
}
if ($q !== '') {
    $where .= ' AND a.name LIKE ?';
    $params[] = "%$q%";
}
$total = (int) q_val("SELECT COUNT(*) FROM ai_campaigns a WHERE $where", $params);
$p = paginate($total, per_page(25));
$campaigns = q_all(
    "SELECT a.*, ma.email account_email,
        (SELECT COUNT(*) FROM ai_campaign_contacts c WHERE c.ai_campaign_id = a.id) contacts,
        (SELECT COUNT(*) FROM ai_campaign_contacts c WHERE c.ai_campaign_id = a.id AND c.draft_status IN ('ready','queued')) drafted,
        (SELECT COUNT(*) FROM ai_campaign_contacts c WHERE c.ai_campaign_id = a.id AND c.draft_status = 'failed') failed,
        (SELECT COALESCE(SUM(c.cost_usd), 0) FROM ai_campaign_contacts c WHERE c.ai_campaign_id = a.id) cost,
        (SELECT COUNT(*) FROM email_messages m WHERE m.ai_campaign_id = a.id AND m.sent_at IS NOT NULL) sent,
        (SELECT COUNT(*) FROM email_messages m WHERE m.ai_campaign_id = a.id AND m.replied_at IS NOT NULL) replied
     FROM ai_campaigns a LEFT JOIN mail_accounts ma ON ma.id = a.mail_account_id
     WHERE $where ORDER BY FIELD(a.status, 'active', 'paused', 'draft', 'completed', 'archived'), a.updated_at DESC LIMIT ? OFFSET ?",
    [...$params, $p['per_page'], $p['offset']]
);
$counts = array_column(q_all('SELECT status, COUNT(*) n FROM ai_campaigns WHERE workspace_id = ? GROUP BY status', [$ws]), 'n', 'status');

$page_title = 'AI Campaigns';
$active_nav = 'ai-campaigns';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">AI Campaigns</h1><p class="page-subtitle">Each customer's website and competitors are researched, then an email is written for them and sent automatically.</p></div>
    <?php if (allowed('campaigns.manage')): ?><a href="<?= e(url('ai-campaigns/create.php')) ?>" class="btn-primary"><?= icon('plus', 'h-4 w-4') ?> New AI campaign</a><?php endif; ?>
</div>

<?php if (OPENROUTER_API_KEY === '' && !str_starts_with(OPENROUTER_API_BASE, 'http://127.0.0.1')): ?>
    <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">Add <code>OPENROUTER_API_KEY</code> to <code>.env</code> before starting an AI campaign (get one at openrouter.ai/keys).</div>
<?php endif; ?>

<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex flex-wrap gap-1 rounded-lg bg-white p-1 ring-1 ring-slate-200">
        <a href="<?= e(url('ai-campaigns/index.php')) ?>" class="rounded-md px-3 py-1 text-xs font-medium <?= !$status ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' ?>">All</a>
        <?php foreach (CAMPAIGN_STATUSES as $s): ?>
            <a href="<?= e(url('ai-campaigns/index.php', ['status' => $s])) ?>" class="rounded-md px-3 py-1 text-xs font-medium capitalize <?= $status === $s ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' ?>"><?= e($s) ?> <span class="opacity-60"><?= (int) ($counts[$s] ?? 0) ?></span></a>
        <?php endforeach; ?>
    </div>
    <form class="w-full sm:w-64"><input type="hidden" name="status" value="<?= e($status) ?>"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search AI campaigns…" class="input"></form>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>Campaign</th><th>Status</th><th>Sender</th><th class="text-right">Contacts</th><th>Progress</th><th class="text-right">Reply %</th><th class="text-right">AI cost</th></tr></thead>
            <tbody>
            <?php foreach ($campaigns as $c):
                $progress = $c['contacts'] ? min(100, round($c['sent'] / $c['contacts'] * 100)) : 0; ?>
                <tr>
                    <td>
                        <a href="<?= e(url('ai-campaigns/view.php', ['id' => $c['id']])) ?>" class="font-medium text-slate-900 hover:text-indigo-600"><?= e($c['name']) ?></a>
                        <div class="text-xs text-slate-500"><?= e($c['model']) ?> · created <?= e(time_ago($c['created_at'])) ?></div>
                    </td>
                    <td><?= status_badge($c['status']) ?></td>
                    <td class="max-w-[12rem] truncate text-xs text-slate-600"><?= e($c['account_email'] ?: '—') ?></td>
                    <td class="text-right tabular-nums"><?= number_format((int) $c['contacts']) ?></td>
                    <td class="w-48">
                        <div class="flex items-center gap-2">
                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-indigo-500" style="width: <?= $progress ?>%"></div></div>
                            <span class="w-20 text-right text-xs tabular-nums text-slate-500"><?= number_format((int) $c['sent']) ?> sent</span>
                        </div>
                        <div class="mt-0.5 text-[11px] text-slate-400"><?= number_format((int) $c['drafted']) ?> written<?= $c['failed'] ? ' · <span class="text-red-500">' . number_format((int) $c['failed']) . ' failed</span>' : '' ?></div>
                    </td>
                    <td class="text-right font-medium tabular-nums text-emerald-600"><?= pct((int) $c['replied'], (int) $c['sent']) ?></td>
                    <td class="text-right tabular-nums text-slate-600">$<?= number_format((float) $c['cost'], 4) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$campaigns): ?>
            <div class="empty-state">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><?= icon('sparkles', 'h-6 w-6') ?></span>
                <h3 class="mt-3 text-sm font-semibold">No AI campaigns<?= $status ? ' with this status' : ' yet' ?></h3>
                <?php if (allowed('campaigns.manage')): ?><a href="<?= e(url('ai-campaigns/create.php')) ?>" class="btn-primary mt-4">Create AI campaign</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?= pagination_links($p) ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
