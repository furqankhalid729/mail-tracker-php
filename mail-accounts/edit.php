<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_role('admin');
$ws = ws_id();
$account = find_or_404('mail_accounts', input_int('id'));

if ($account['provider'] === 'gmail' && is_post()) {
    // Gmail accounts only expose label, from name, reply-to and limit
    verify_csrf();
    $errors = validate($_POST, ['name' => 'required|max:120', 'reply_to' => 'email', 'daily_limit' => 'required|int|between:1,10000']);
    if ($errors) {
        flash_errors($errors);
        redirect_back('mail-accounts/index.php');
    }
    db_update('mail_accounts', [
        'name' => trim((string) input('name')),
        'from_name' => trim((string) input('from_name')) ?: null,
        'reply_to' => strtolower(trim((string) input('reply_to'))) ?: null,
        'daily_limit' => input_int('daily_limit'),
        'updated_at' => now(),
    ], 'id = ? AND workspace_id = ?', [$account['id'], $ws]);
    release_limit_deferred_jobs($ws);
    flash('success', 'Account updated.');
    redirect('mail-accounts/index.php');
}
if (is_post()) {
    verify_csrf();
    $existing = $account;
    require __DIR__ . '/_save.php';
}

$errors = get_errors();
$page_title = 'Edit mail account';
$active_nav = 'mail-accounts';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('mail-accounts/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Mail accounts</a>
        <h1 class="page-title"><?= e($account['name']) ?></h1>
    </div>
</div>
<?php if ($account['provider'] === 'gmail'): ?>
    <form method="post" action="<?= e(url('mail-accounts/edit.php', ['id' => $account['id']])) ?>" class="card max-w-xl">
        <?= csrf_field() ?>
        <div class="card-body space-y-4">
            <p class="text-sm text-slate-500">Connected via Google OAuth as <b><?= e($account['email']) ?></b>.</p>
            <div><label class="label">Label</label><input class="input" name="name" value="<?= e($account['name']) ?>" required maxlength="120"></div>
            <div><label class="label">From name</label><input class="input" name="from_name" value="<?= e($account['from_name']) ?>" maxlength="120"></div>
            <div><label class="label">Reply-To</label><input class="input" type="email" name="reply_to" value="<?= e($account['reply_to']) ?>"></div>
            <div><label class="label">Daily limit</label><input class="input" type="number" name="daily_limit" min="1" max="10000" value="<?= (int) $account['daily_limit'] ?>"></div>
            <button class="btn-primary">Save</button>
        </div>
    </form>
<?php else: ?>
    <form method="post" action="<?= e(url('mail-accounts/edit.php', ['id' => $account['id']])) ?>"><?php require __DIR__ . '/_form.php'; ?></form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php';
