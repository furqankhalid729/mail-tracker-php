<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('campaigns.manage');
$ws = ws_id();

if (is_post()) {
    verify_csrf();
    $existing = null;
    require __DIR__ . '/_save.php';
}

$campaign = [];
// "Follow up" from a campaign's More menu: copy its sender and limits; the template is left for the user to pick.
$followUp = input_int('follow_up') ? q_one('SELECT * FROM campaigns WHERE id = ? AND workspace_id = ?', [input_int('follow_up'), $ws]) : null;
if ($followUp) {
    $campaign = [
        'name' => mb_substr('Follow-up: ' . $followUp['name'], 0, 150),
        'mail_account_id' => $followUp['mail_account_id'],
        'daily_limit' => $followUp['daily_limit'],
        'max_per_domain' => $followUp['max_per_domain'],
    ];
}
$errors = get_errors();
$accounts = q_all("SELECT id, name, email FROM mail_accounts WHERE workspace_id = ? AND status <> 'disconnected' ORDER BY name", [$ws]);
$templates = q_all('SELECT id, name FROM email_templates WHERE workspace_id = ? ORDER BY name', [$ws]);

$page_title = 'New campaign';
$active_nav = 'campaigns';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('campaigns/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Campaigns</a>
        <h1 class="page-title">New campaign</h1>
    </div>
</div>
<?php if ($followUp): ?>
    <div class="mb-4 max-w-2xl rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
        Follow-up to <b><?= e($followUp['name']) ?></b>. Pick a new template. Next you will choose who gets it: opened, not opened, clicked, and so on.
    </div>
<?php endif; ?>
<form method="post"><?php if ($followUp): ?><input type="hidden" name="follow_up" value="<?= (int) $followUp['id'] ?>"><?php endif; ?><?php require __DIR__ . '/_form.php'; ?></form>
<?php require __DIR__ . '/../includes/footer.php';
