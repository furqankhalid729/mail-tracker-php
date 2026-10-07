<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

$view = in_array(input('view'), ['replies', 'unread', 'all'], true) ? input('view') : 'replies';
$q = trim((string) input('q'));
$where = ['t.workspace_id = ?', "EXISTS (SELECT 1 FROM email_messages mm WHERE mm.thread_id = t.id AND (mm.sent_at IS NOT NULL OR mm.direction = 'inbound'))"];
$params = [$ws];
if ($view === 'replies') {
    $where[] = 't.has_reply = 1';
} elseif ($view === 'unread') {
    $where[] = 't.is_unread = 1';
}
if (input_int('campaign_id')) {
    $where[] = 't.campaign_id = ?';
    $params[] = input_int('campaign_id');
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(t.subject LIKE ? OR c.email LIKE ? OR c.full_name LIKE ? OR c.company LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$total = (int) q_val("SELECT COUNT(*) FROM email_threads t JOIN customers c ON c.id = t.customer_id WHERE $whereSql", $params);
$p = paginate($total, per_page(25));
$threads = q_all(
    "SELECT t.*, c.first_name, c.last_name, c.full_name, c.email, c.company, cp.name campaign_name,
        (SELECT COUNT(*) FROM email_messages m WHERE m.thread_id = t.id AND m.status <> 'cancelled') message_count,
        (SELECT m.text_body FROM email_messages m WHERE m.thread_id = t.id AND (m.sent_at IS NOT NULL OR m.direction = 'inbound') ORDER BY m.id DESC LIMIT 1) last_text,
        (SELECT m.direction FROM email_messages m WHERE m.thread_id = t.id AND (m.sent_at IS NOT NULL OR m.direction = 'inbound') ORDER BY m.id DESC LIMIT 1) last_direction
     FROM email_threads t JOIN customers c ON c.id = t.customer_id LEFT JOIN campaigns cp ON cp.id = t.campaign_id
     WHERE $whereSql ORDER BY t.last_message_at DESC LIMIT ? OFFSET ?",
    [...$params, $p['per_page'], $p['offset']]
);
$unreadCount = (int) q_val('SELECT COUNT(*) FROM email_threads WHERE workspace_id = ? AND is_unread = 1', [$ws]);
$accountsWithInbox = (int) q_val("SELECT COUNT(*) FROM mail_accounts WHERE workspace_id = ? AND (imap_host IS NOT NULL OR provider = 'gmail') AND status <> 'disconnected'", [$ws]);

$page_title = 'Inbox';
$active_nav = 'inbox';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Inbox</h1><p class="page-subtitle">Conversations with your customers. Replies are fetched by cron every few minutes.</p></div>
</div>

<?php if (!$accountsWithInbox): ?>
    <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">No mailbox is set up for reply tracking. Add IMAP settings to an SMTP account or connect Gmail in <a class="font-medium underline" href="<?= e(url('mail-accounts/index.php')) ?>">Mail accounts</a>.</div>
<?php endif; ?>

<div class="card overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-slate-200 p-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex gap-1">
            <?php foreach (['replies' => 'Replies', 'unread' => 'Unread (' . $unreadCount . ')', 'all' => 'All conversations'] as $k => $l): ?>
                <a href="<?= e(url('inbox/index.php', ['view' => $k, 'q' => $q])) ?>" class="rounded-md px-3 py-1.5 text-sm font-medium <?= $view === $k ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-100' ?>"><?= e($l) ?></a>
            <?php endforeach; ?>
        </div>
        <form class="w-full sm:w-72"><input type="hidden" name="view" value="<?= e($view) ?>"><input type="search" name="q" value="<?= e($q) ?>" class="input" placeholder="Search conversations…"></form>
    </div>
    <ul class="divide-y divide-slate-100">
        <?php foreach ($threads as $t): $name = customer_name($t); ?>
            <li>
                <a href="<?= e(url('inbox/thread.php', ['id' => $t['id']])) ?>" class="flex items-start gap-3 px-4 py-3.5 hover:bg-slate-50 <?= $t['is_unread'] ? 'bg-indigo-50/40' : '' ?>">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold <?= avatar_color($t['email']) ?>"><?= e(initials($name)) ?></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="truncate text-sm <?= $t['is_unread'] ? 'font-semibold text-slate-900' : 'font-medium text-slate-800' ?>"><?= e($name) ?><?php if ($t['company']): ?> <span class="font-normal text-slate-400">· <?= e($t['company']) ?></span><?php endif; ?></span>
                            <span class="shrink-0 text-xs <?= $t['is_unread'] ? 'font-semibold text-indigo-600' : 'text-slate-400' ?>"><?= e(time_ago($t['last_message_at'])) ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="truncate text-sm <?= $t['is_unread'] ? 'font-medium text-slate-800' : 'text-slate-600' ?>"><?= e($t['subject'] ?: '(no subject)') ?></span>
                            <?php if ($t['message_count'] > 1): ?><span class="text-xs text-slate-400"><?= (int) $t['message_count'] ?></span><?php endif; ?>
                        </div>
                        <p class="truncate text-xs text-slate-500"><?= $t['last_direction'] === 'outbound' ? '<span class="text-slate-400">You:</span> ' : '' ?><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', strip_quoted_reply((string) $t['last_text'])), 0, 140, '…')) ?></p>
                    </div>
                    <div class="hidden shrink-0 flex-col items-end gap-1 sm:flex">
                        <?php if ($t['is_unread']): ?><span class="h-2 w-2 rounded-full bg-indigo-600"></span><?php endif; ?>
                        <?php if ($t['campaign_name']): ?><span class="badge badge-slate max-w-[10rem] truncate normal-case"><?= e($t['campaign_name']) ?></span><?php endif; ?>
                    </div>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if (!$threads): ?>
        <div class="empty-state">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><?= icon('inbox', 'h-6 w-6') ?></span>
            <h3 class="mt-3 text-sm font-semibold"><?= $view === 'replies' ? 'No replies yet' : 'Nothing here' ?></h3>
            <p class="mt-1 text-sm text-slate-500"><?= $view === 'replies' ? 'Customer replies to your emails will show up here.' : 'Try another view.' ?></p>
        </div>
    <?php endif; ?>
    <?= pagination_links($p) ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
