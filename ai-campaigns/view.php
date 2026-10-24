<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$campaign = find_or_404('ai_campaigns', input_int('id'));
$cid = (int) $campaign['id'];

$stats = ai_campaign_stats($cid);
$account = $campaign['mail_account_id'] ? q_one('SELECT id, name, email, status FROM mail_accounts WHERE id = ?', [$campaign['mail_account_id']]) : null;
$draftFilter = in_array(input('draft'), ['pending', 'processing', 'ready', 'queued', 'failed', 'skipped'], true) ? (string) input('draft') : '';

$where = 'a.ai_campaign_id = ?';
$params = [$cid];
if ($draftFilter) {
    $where .= ' AND a.draft_status = ?';
    $params[] = $draftFilter;
}
$total = (int) q_val("SELECT COUNT(*) FROM ai_campaign_contacts a WHERE $where", $params);
$p = paginate($total, per_page(25));
$rows = q_all(
    "SELECT a.*, c.first_name, c.last_name, c.full_name, c.email, c.company, c.website, m.status email_status, m.sent_at
     FROM ai_campaign_contacts a JOIN customers c ON c.id = a.customer_id LEFT JOIN email_messages m ON m.id = a.email_message_id
     WHERE $where ORDER BY a.id LIMIT ? OFFSET ?",
    [...$params, $p['per_page'], $p['offset']]
);
$canManage = allowed('campaigns.manage');

$page_title = $campaign['name'];
$active_nav = 'ai-campaigns';
require __DIR__ . '/../includes/header.php';
?>
<a href="<?= e(url('ai-campaigns/index.php')) ?>" class="mb-3 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> AI Campaigns</a>

<div class="page-header !items-start">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="page-title"><?= e($campaign['name']) ?></h1>
            <?= status_badge($campaign['status']) ?>
        </div>
        <p class="page-subtitle">
            <?= $account ? 'From ' . e($account['email']) : '<span class="text-amber-600">No sender</span>' ?> ·
            <span class="font-mono text-xs"><?= e($campaign['model']) ?></span> · web search <?= $campaign['web_search'] ? 'on' : 'off' ?>
            <?php if ($campaign['started_at']): ?> · started <?= e(format_dt($campaign['started_at'], 'M j, Y')) ?><?php endif; ?>
        </p>
        <?php if ($campaign['description']): ?><p class="mt-1 max-w-2xl text-sm text-slate-500"><?= e($campaign['description']) ?></p><?php endif; ?>
    </div>
    <?php if ($canManage): ?>
    <div class="flex flex-wrap gap-2">
        <a href="<?= e(url('ai-campaigns/test.php', ['id' => $cid])) ?>" class="btn-secondary"><?= icon('sparkles', 'h-4 w-4') ?> Test on one customer</a>
        <?php if ($campaign['status'] !== 'archived'): ?>
            <a href="<?= e(url('ai-campaigns/contacts.php', ['id' => $cid])) ?>" class="btn-secondary"><?= icon('plus', 'h-4 w-4') ?> Add contacts</a>
        <?php endif; ?>
        <?php if (in_array($campaign['status'], ['draft', 'paused', 'completed'], true)): ?>
            <form method="post" action="<?= e(url('ai-campaigns/start.php')) ?>" data-confirm="<?= $campaign['status'] === 'paused' ? 'Resume? Research, writing and sending continue automatically.' : 'Research and email ' . number_format($stats['waiting']) . ' customers? Each email is written by AI and sent automatically, with no review step.' ?>" data-confirm-button="<?= $campaign['status'] === 'paused' ? 'Resume' : 'Start' ?>" data-confirm-danger="0">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>">
                <button class="btn-primary"><?= icon('play', 'h-4 w-4') ?> <?= $campaign['status'] === 'paused' ? 'Resume' : ($campaign['status'] === 'completed' ? 'Send to new contacts' : 'Start') ?></button>
            </form>
        <?php elseif ($campaign['status'] === 'active'): ?>
            <form method="post" action="<?= e(url('ai-campaigns/pause.php')) ?>">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>">
                <button class="btn-secondary"><?= icon('pause', 'h-4 w-4') ?> Pause</button>
            </form>
        <?php endif; ?>
        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
            <button type="button" class="btn-secondary" @click="open = !open">More <?= icon('chevron-down', 'h-4 w-4') ?></button>
            <div x-show="open" x-cloak class="dropdown right-0">
                <a href="<?= e(url('ai-campaigns/edit.php', ['id' => $cid])) ?>" class="dropdown-item"><?= icon('pencil', 'h-4 w-4') ?> Edit settings</a>
                <?php if ($stats['failed']): ?>
                    <form method="post" action="<?= e(url('ai-campaigns/status.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>"><button name="action" value="retry_failed" class="dropdown-item"><?= icon('bolt', 'h-4 w-4') ?> Retry <?= $stats['failed'] ?> failed</button></form>
                <?php endif; ?>
                <?php if ($campaign['status'] !== 'archived'): ?>
                    <form method="post" action="<?= e(url('ai-campaigns/status.php')) ?>" data-confirm="Archive this AI campaign? Queued emails are cancelled; history is kept." data-confirm-button="Archive"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>"><button name="action" value="archive" class="dropdown-item">Archive</button></form>
                <?php else: ?>
                    <form method="post" action="<?= e(url('ai-campaigns/status.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>"><button name="action" value="unarchive" class="dropdown-item">Unarchive</button></form>
                <?php endif; ?>
                <form method="post" action="<?= e(url('ai-campaigns/delete.php')) ?>" data-confirm="Delete “<?= e($campaign['name']) ?>”? Contacts stay in your CRM; sent emails remain in customer timelines." data-confirm-button="Delete"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $cid ?>"><button class="dropdown-item text-red-600"><?= icon('trash', 'h-4 w-4') ?> Delete AI campaign</button></form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($campaign['last_error'] && $campaign['status'] === 'paused'): ?>
    <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">Paused automatically: <?= e($campaign['last_error']) ?>. Fix it (API key or OpenRouter credit), then resume.</div>
<?php endif; ?>
<?php if ($campaign['status'] === 'active' && $account && $account['status'] !== 'active'): ?>
    <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">The sending account <?= e($account['email']) ?> has a problem (<?= e($account['status']) ?>). <a class="font-medium underline" href="<?= e(url('mail-accounts/index.php')) ?>">Check it</a>: emails are waiting.</div>
<?php endif; ?>
<?php if ($campaign['status'] === 'active' && ($stats['waiting'] || $stats['queued'])): ?>
    <div class="mb-4 flex items-center gap-2 rounded-lg bg-indigo-50 px-4 py-3 text-sm text-indigo-800 ring-1 ring-indigo-200">
        <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-indigo-400 opacity-75"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-indigo-500"></span></span>
        Working: <?= number_format($stats['waiting']) ?> customers to research (<?= AI_DRAFT_BATCH ?> per minute), <?= number_format($stats['queued']) ?> emails in the send queue.
    </div>
<?php endif; ?>

<div class="grid grid-cols-3 gap-3 sm:grid-cols-5 xl:grid-cols-9">
    <?php foreach ([
        ['Contacts', number_format($stats['contacts'])],
        ['To research', number_format($stats['waiting'])],
        ['Written', number_format($stats['drafted'])],
        ['Failed', number_format($stats['failed'])],
        ['Skipped', number_format($stats['skipped'])],
        ['Sent', number_format($stats['sent'])],
        ['Opened ~', number_format($stats['opened'])],
        ['Replied', number_format($stats['replied'])],
        ['AI cost', '$' . number_format($stats['cost'], 4)],
    ] as [$label, $value]): ?>
        <div class="stat-card !p-3">
            <div class="text-[11px] font-medium uppercase tracking-wide text-slate-500"><?= e($label) ?></div>
            <div class="mt-0.5 text-xl font-semibold tabular-nums"><?= e($value) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="mb-3 mt-6 flex flex-wrap gap-1 rounded-lg bg-white p-1 ring-1 ring-slate-200 w-fit">
    <a href="<?= e(url('ai-campaigns/view.php', ['id' => $cid])) ?>" class="rounded-md px-3 py-1 text-xs font-medium <?= !$draftFilter ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' ?>">All</a>
    <?php foreach (['pending', 'processing', 'ready', 'queued', 'failed', 'skipped'] as $s): ?>
        <a href="<?= e(url('ai-campaigns/view.php', ['id' => $cid, 'draft' => $s])) ?>" class="rounded-md px-3 py-1 text-xs font-medium capitalize <?= $draftFilter === $s ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' ?>"><?= e($s) ?></a>
    <?php endforeach; ?>
</div>

<form method="post" action="<?= e(url('ai-campaigns/contacts.php', ['id' => $cid])) ?>" class="card overflow-hidden" x-data="{ picked: [] }">
    <?= csrf_field() ?><input type="hidden" name="action" value="remove">
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><?php if ($canManage): ?><th class="w-8"></th><?php endif; ?><th>Customer</th><th>AI draft</th><th>Subject</th><th>Email</th><th class="text-right">Cost</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr x-data="{ open: false }">
                    <?php if ($canManage): ?><td><?php if (in_array($r['draft_status'], ['pending', 'ready', 'failed', 'skipped'], true)): ?><input type="checkbox" class="checkbox" name="contact_ids[]" value="<?= (int) $r['id'] ?>" x-model="picked"><?php endif; ?></td><?php endif; ?>
                    <td>
                        <a href="<?= e(url('customers/view.php', ['id' => $r['customer_id']])) ?>" class="font-medium text-slate-900 hover:text-indigo-600"><?= e(customer_name($r)) ?></a>
                        <div class="max-w-[16rem] truncate text-xs text-slate-500"><?= e(implode(' · ', array_filter([$r['company'], $r['website']]))) ?></div>
                    </td>
                    <td>
                        <?= status_badge($r['draft_status']) ?>
                        <?php if ($r['error']): ?><div class="mt-0.5 max-w-[16rem] truncate text-[11px] text-red-500" title="<?= e($r['error']) ?>"><?= e($r['error']) ?></div><?php endif; ?>
                    </td>
                    <td class="max-w-xs">
                        <?php if ($r['html_body']): ?>
                            <button type="button" class="truncate text-left text-sm text-indigo-600 hover:underline" @click="open = !open"><?= e($r['subject'] ?: '(no subject)') ?></button>
                            <div x-show="open" x-cloak class="mt-2 space-y-2 rounded-lg bg-slate-50 p-3 text-xs">
                                <div><b class="text-slate-600">Research</b><p class="mt-1 whitespace-pre-line text-slate-600"><?= e($r['research'] ?: '—') ?></p></div>
                                <div><b class="text-slate-600">Email</b><div class="prose prose-sm mt-1 max-w-none rounded bg-white p-2"><?= sanitize_html($r['html_body']) ?></div></div>
                            </div>
                        <?php else: ?><span class="text-slate-400">—</span><?php endif; ?>
                    </td>
                    <td><?= $r['email_status'] ? status_badge($r['email_status']) : '<span class="text-slate-400">—</span>' ?></td>
                    <td class="text-right text-xs tabular-nums text-slate-500"><?= $r['cost_usd'] !== null ? '$' . number_format((float) $r['cost_usd'], 4) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$rows): ?>
            <div class="empty-state">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><?= icon('users', 'h-6 w-6') ?></span>
                <h3 class="mt-3 text-sm font-semibold">No contacts<?= $draftFilter ? ' with this status' : ' yet' ?></h3>
                <?php if ($canManage && !$draftFilter && $campaign['status'] !== 'archived'): ?><a href="<?= e(url('ai-campaigns/contacts.php', ['id' => $cid])) ?>" class="btn-primary mt-4">Add contacts</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($canManage && $rows): ?>
        <div x-show="picked.length" x-cloak class="flex items-center justify-between border-t border-slate-100 bg-slate-50/60 px-5 py-3 text-sm">
            <span><b x-text="picked.length"></b> selected</span>
            <button class="btn-secondary btn-sm text-red-600" data-confirm="Remove the selected contacts from this AI campaign?" data-confirm-button="Remove">Remove</button>
        </div>
    <?php endif; ?>
    <?= pagination_links($p) ?>
</form>
<?php require __DIR__ . '/../includes/footer.php';
