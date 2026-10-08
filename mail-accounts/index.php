<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

// Never select encrypted columns for display
$accounts = q_all(
    "SELECT a.id, a.name, a.provider, a.email, a.from_name, a.smtp_host, a.smtp_port, a.imap_host, a.daily_limit, a.status, a.last_error,
            a.inbox_checked_at, a.created_at,
            (SELECT COUNT(*) FROM email_messages m WHERE m.mail_account_id = a.id AND m.direction = 'outbound' AND m.sent_at >= ?) sent_today
     FROM mail_accounts a WHERE a.workspace_id = ? ORDER BY a.created_at",
    [date('Y-m-d 00:00:00'), $ws]
);

$page_title = 'Mail accounts';
$active_nav = 'mail-accounts';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Mail accounts</h1><p class="page-subtitle">Mailboxes used to send campaigns and receive replies.</p></div>
    <?php if (allowed('mail_accounts.manage')): ?><a href="<?= e(url('mail-accounts/create.php')) ?>" class="btn-primary"><?= icon('plus', 'h-4 w-4') ?> Add account</a><?php endif; ?>
</div>

<div class="grid gap-4 lg:grid-cols-2">
    <?php foreach ($accounts as $a): ?>
        <div class="card" x-data="{ testTo: '' }">
            <div class="flex items-start gap-4 p-5">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg <?= $a['provider'] === 'gmail' ? 'bg-red-50 text-red-500 text-lg font-bold' : 'bg-indigo-50 text-indigo-600' ?>"><?= $a['provider'] === 'gmail' ? 'G' : icon('at', 'h-6 w-6') ?></span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-semibold text-slate-900"><?= e($a['name']) ?></h3>
                        <?= status_badge($a['status'] === 'active' ? 'active' : $a['status']) ?>
                        <span class="badge badge-slate"><?= $a['provider'] === 'gmail' ? 'Gmail OAuth' : 'SMTP' ?></span>
                    </div>
                    <p class="truncate text-sm text-slate-600"><?= e($a['from_name'] ? $a['from_name'] . ' <' . $a['email'] . '>' : $a['email']) ?></p>
                    <p class="mt-1 text-xs text-slate-400">
                        <?= $a['provider'] === 'smtp' ? e($a['smtp_host'] . ':' . $a['smtp_port']) . ' · ' : '' ?>
                        <?= $a['provider'] === 'gmail' || $a['imap_host'] ? 'Reply tracking on' . ($a['inbox_checked_at'] ? ' (checked ' . e(time_ago($a['inbox_checked_at'])) . ')' : '') : 'No IMAP — replies not tracked' ?>
                    </p>
                    <?php if ($a['last_error'] && $a['status'] !== 'active'): ?><p class="mt-2 rounded bg-red-50 px-2 py-1 text-xs text-red-700"><?= e($a['last_error']) ?></p><?php endif; ?>
                    <div class="mt-3">
                        <div class="flex justify-between text-xs text-slate-500"><span>Sent today</span><span><?= (int) $a['sent_today'] ?> / <?= (int) $a['daily_limit'] ?></span></div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-indigo-500" style="width: <?= min(100, round($a['sent_today'] / max(1, $a['daily_limit']) * 100)) ?>%"></div></div>
                    </div>
                </div>
            </div>
            <?php if (allowed('mail_accounts.manage')): ?>
            <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 px-5 py-3">
                <form method="post" action="<?= e(url('mail-accounts/test.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="type" value="connection"><button class="btn-secondary btn-sm">Test connection</button></form>
                <form method="post" action="<?= e(url('mail-accounts/test.php')) ?>" class="flex items-center gap-1">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="type" value="email">
                    <input type="email" name="to" x-model="testTo" required placeholder="you@example.com" class="input !w-44 !py-1 text-xs" value="<?= e(current_user()['email']) ?>" x-init="testTo = $el.value">
                    <button class="btn-secondary btn-sm">Send test email</button>
                </form>
                <div class="ml-auto flex gap-1">
                    <?php if ($a['status'] === 'disconnected' && $a['provider'] === 'gmail' && gmail_enabled()): ?>
                        <form method="post" action="<?= e(url('mail-accounts/oauth.php')) ?>"><?= csrf_field() ?><button class="btn-ghost btn-sm">Reconnect</button></form>
                    <?php endif; ?>
                    <a href="<?= e(url('mail-accounts/edit.php', ['id' => $a['id']])) ?>" class="btn-ghost btn-sm">Edit</a>
                    <?php if ($a['status'] !== 'disconnected'): ?>
                        <form method="post" action="<?= e(url('mail-accounts/delete.php')) ?>" data-confirm="Disconnect <?= e($a['email']) ?>? Stored credentials are erased and queued emails from this account will wait until it is reconnected." data-confirm-button="Disconnect">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="disconnect"><button class="btn-ghost btn-sm">Disconnect</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('mail-accounts/delete.php')) ?>" data-confirm="Delete <?= e($a['email']) ?> permanently? Sent email history is kept." data-confirm-button="Delete">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn-ghost btn-sm text-red-600">Delete</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (!$accounts): ?>
        <div class="card empty-state lg:col-span-2">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><?= icon('at', 'h-6 w-6') ?></span>
            <h3 class="mt-3 text-sm font-semibold">No mail accounts yet</h3>
            <p class="mt-1 text-sm text-slate-500">Connect SMTP or Gmail to start sending.</p>
            <?php if (allowed('mail_accounts.manage')): ?><a href="<?= e(url('mail-accounts/create.php')) ?>" class="btn-primary mt-4">Add account</a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
