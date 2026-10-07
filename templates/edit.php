<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$template = find_or_404('email_templates', input_int('id'));

if (is_post()) {
    verify_csrf();
    $existing = $template;
    require __DIR__ . '/_save.php';
}

$errors = get_errors();
$previewCustomers = q_all('SELECT * FROM customers WHERE workspace_id = ? ORDER BY last_activity_at DESC LIMIT 20', [$ws]);
$usedBy = q_all('SELECT id, name, status FROM campaigns WHERE template_id = ? AND workspace_id = ?', [$template['id'], $ws]);

$page_title = 'Edit template';
$active_nav = 'templates';
$wide = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('templates/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Templates</a>
        <h1 class="page-title"><?= e($template['name']) ?></h1>
        <?php if ($usedBy): ?><p class="page-subtitle">Used by: <?= implode(', ', array_map(fn($c) => '<a class="text-indigo-600" href="' . e(url('campaigns/view.php', ['id' => $c['id']])) . '">' . e($c['name']) . '</a>', $usedBy)) ?>. Already-queued emails keep their content.</p><?php endif; ?>
    </div>
</div>
<form method="post" action="<?= e(url('templates/edit.php', ['id' => $template['id']])) ?>"><?php require __DIR__ . '/_form.php'; ?></form>
<?php require __DIR__ . '/../includes/footer.php';
