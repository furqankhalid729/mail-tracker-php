<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$customer = find_or_404('customers', input_int('id'));

if (is_post()) {
    verify_csrf();
    $existing = $customer;
    require __DIR__ . '/_save.php';
}

$customerTags = array_map('intval', q_col('SELECT tag_id FROM customer_tags WHERE customer_id = ?', [$customer['id']]));
$allTags = workspace_tags($ws);
$errors = get_errors();

$page_title = 'Edit ' . customer_name($customer);
$active_nav = 'customers';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('customers/view.php', ['id' => $customer['id']])) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> <?= e(customer_name($customer)) ?></a>
        <h1 class="page-title">Edit customer</h1>
    </div>
</div>
<form method="post" action="<?= e(url('customers/edit.php', ['id' => $customer['id']])) ?>">
    <?php require __DIR__ . '/_form.php'; ?>
</form>
<?php require __DIR__ . '/../includes/footer.php';
