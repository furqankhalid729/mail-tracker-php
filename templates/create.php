<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

if (is_post()) {
    verify_csrf();
    $existing = null;
    require __DIR__ . '/_save.php';
}

$template = [
    'subject' => 'Quick question about {{company|your business}}',
    'html_body' => "<p>Hi {{firstName|there}},</p>\n<p>I noticed that {{company}} is growing fast and wanted to reach out.</p>\n<p>Would you be open to a quick 15-minute call next week?</p>\n<p>Best regards,<br>" . e(current_user()['name']) . '</p>',
];
$errors = get_errors();
$previewCustomers = q_all('SELECT * FROM customers WHERE workspace_id = ? ORDER BY last_activity_at DESC LIMIT 20', [$ws]);

$page_title = 'New template';
$active_nav = 'templates';
$wide = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('templates/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Templates</a>
        <h1 class="page-title">New template</h1>
    </div>
</div>
<form method="post"><?php require __DIR__ . '/_form.php'; ?></form>
<?php require __DIR__ . '/../includes/footer.php';
