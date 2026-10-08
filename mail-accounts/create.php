<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('mail_accounts.manage');
$ws = ws_id();

if (is_post()) {
    verify_csrf();
    $existing = null;
    require __DIR__ . '/_save.php';
}

$account = [];
$errors = get_errors();
$page_title = 'Add mail account';
$active_nav = 'mail-accounts';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('mail-accounts/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Mail accounts</a>
        <h1 class="page-title">Add mail account</h1>
    </div>
</div>

<div class="mb-6 grid gap-4 md:grid-cols-2">
    <div class="card flex items-center gap-4 p-5">
        <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-red-50 text-lg font-bold text-red-500">G</span>
        <div class="flex-1">
            <h3 class="text-sm font-semibold">Gmail / Google Workspace (OAuth)</h3>
            <p class="text-xs text-slate-500"><?= gmail_enabled() ? 'Connect securely without storing a password.' : 'Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env to enable. SMTP with an App Password works without it.' ?></p>
        </div>
        <?php if (gmail_enabled()): ?>
            <form method="post" action="<?= e(url('mail-accounts/oauth.php')) ?>"><?= csrf_field() ?><button class="btn-secondary">Connect Google</button></form>
        <?php else: ?>
            <button class="btn-secondary" disabled>Not configured</button>
        <?php endif; ?>
    </div>
    <div class="card flex items-center gap-4 p-5 ring-2 ring-indigo-500">
        <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600"><?= icon('at', 'h-6 w-6') ?></span>
        <div class="flex-1">
            <h3 class="text-sm font-semibold">SMTP + IMAP</h3>
            <p class="text-xs text-slate-500">Any provider: Hostinger, Gmail App Password, Outlook, Zoho…</p>
        </div>
        <span class="badge badge-indigo">Selected</span>
    </div>
</div>

<form method="post"><?php require __DIR__ . '/_form.php'; ?></form>
<?php require __DIR__ . '/../includes/footer.php';
