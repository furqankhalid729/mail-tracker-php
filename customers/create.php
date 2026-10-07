<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

if (is_post()) {
    verify_csrf();
    $existing = null;
    require __DIR__ . '/_save.php';
}

$customer = [];
$customerTags = [];
$allTags = workspace_tags($ws);
$errors = get_errors();

$page_title = 'Add customer';
$active_nav = 'customers';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('customers/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Customers</a>
        <h1 class="page-title">Add customer</h1>
    </div>
</div>
<form method="post">
    <?php require __DIR__ . '/_form.php'; ?>
</form>
<?php require __DIR__ . '/../includes/footer.php';
