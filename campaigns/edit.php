<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$campaign = find_or_404('campaigns', input_int('id'));

if (is_post()) {
    verify_csrf();
    $existing = $campaign;
    require __DIR__ . '/_save.php';
}

$errors = get_errors();
$accounts = q_all("SELECT id, name, email FROM mail_accounts WHERE workspace_id = ? AND status <> 'disconnected' ORDER BY name", [$ws]);
$templates = q_all('SELECT id, name FROM email_templates WHERE workspace_id = ? ORDER BY name', [$ws]);

$page_title = 'Edit campaign';
$active_nav = 'campaigns';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('campaigns/view.php', ['id' => $campaign['id']])) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> <?= e($campaign['name']) ?></a>
        <h1 class="page-title">Edit campaign</h1>
    </div>
</div>
<form method="post" action="<?= e(url('campaigns/edit.php', ['id' => $campaign['id']])) ?>"><?php require __DIR__ . '/_form.php'; ?></form>
<?php require __DIR__ . '/../includes/footer.php';
