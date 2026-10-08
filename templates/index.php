<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

$q = trim((string) input('q'));
$where = 'workspace_id = ?';
$params = [$ws];
if ($q !== '') {
    $where .= ' AND (name LIKE ? OR subject LIKE ?)';
    array_push($params, "%$q%", "%$q%");
}
$total = (int) q_val("SELECT COUNT(*) FROM email_templates WHERE $where", $params);
$p = paginate($total, per_page(25));
$templates = q_all(
    "SELECT t.*, (SELECT COUNT(*) FROM campaigns c WHERE c.template_id = t.id) campaigns FROM email_templates t WHERE $where ORDER BY updated_at DESC LIMIT ? OFFSET ?",
    [...$params, $p['per_page'], $p['offset']]
);

$page_title = 'Templates';
$active_nav = 'templates';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Email templates</h1><p class="page-subtitle">Reusable messages with personalisation variables.</p></div>
    <?php if (allowed('templates.manage')): ?><a href="<?= e(url('templates/create.php')) ?>" class="btn-primary"><?= icon('plus', 'h-4 w-4') ?> New template</a><?php endif; ?>
</div>

<form class="mb-4 max-w-sm"><input type="search" name="q" value="<?= e($q) ?>" class="input" placeholder="Search templates…"></form>

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" x-data="{ preview: null }">
    <?php foreach ($templates as $t): ?>
        <div class="card flex flex-col">
            <div class="flex-1 p-5">
                <div class="flex items-start justify-between gap-2">
                    <?php if (allowed('templates.manage')): ?><a href="<?= e(url('templates/edit.php', ['id' => $t['id']])) ?>" class="font-semibold text-slate-900 hover:text-indigo-600"><?= e($t['name']) ?></a><?php else: ?><span class="font-semibold text-slate-900"><?= e($t['name']) ?></span><?php endif; ?>
                    <?php if ($t['campaigns']): ?><span class="badge badge-indigo"><?= (int) $t['campaigns'] ?> campaign<?= $t['campaigns'] > 1 ? 's' : '' ?></span><?php endif; ?>
                </div>
                <p class="mt-1 truncate text-sm text-slate-600"><?= e($t['subject']) ?></p>
                <p class="mt-3 line-clamp-3 text-xs leading-relaxed text-slate-500"><?= e(mb_strimwidth(html_to_text((string) $t['html_body']), 0, 220, '…')) ?></p>
            </div>
            <div class="flex items-center justify-between border-t border-slate-100 px-3 py-2">
                <span class="pl-2 text-xs text-slate-400">Updated <?= e(time_ago($t['updated_at'])) ?></span>
                <div class="flex">
                    <button type="button" class="btn-icon tooltip" data-tip="Preview" @click="preview = <?= e(json_encode(['name' => $t['name'], 'subject' => $t['subject'], 'html' => (string) $t['html_body']])) ?>"><?= icon('eye', 'h-4 w-4') ?></button>
                    <?php if (allowed('templates.manage')): ?>
                    <a href="<?= e(url('templates/edit.php', ['id' => $t['id']])) ?>" class="btn-icon tooltip" data-tip="Edit"><?= icon('pencil', 'h-4 w-4') ?></a>
                    <form method="post" action="<?= e(url('templates/duplicate.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn-icon tooltip" data-tip="Duplicate"><?= icon('copy', 'h-4 w-4') ?></button></form>
                    <form method="post" action="<?= e(url('templates/delete.php')) ?>" data-confirm="Delete template “<?= e($t['name']) ?>”?<?= $t['campaigns'] ? ' Campaigns using it will need a new template before sending more emails.' : '' ?>" data-confirm-button="Delete">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn-icon tooltip hover:!text-red-600" data-tip="Delete"><?= icon('trash', 'h-4 w-4') ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!$templates): ?>
        <div class="card empty-state sm:col-span-2 xl:col-span-3">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><?= icon('template', 'h-6 w-6') ?></span>
            <h3 class="mt-3 text-sm font-semibold"><?= $q ? 'No templates match' : 'No templates yet' ?></h3>
            <p class="mt-1 text-sm text-slate-500">Templates power campaigns and speed up manual emails.</p>
            <?php if (allowed('templates.manage')): ?><a href="<?= e(url('templates/create.php')) ?>" class="btn-primary mt-4">Create template</a><?php endif; ?>
        </div>
    <?php endif; ?>

    <template x-if="preview">
        <div>
            <div class="modal-backdrop" @click="preview = null"></div>
            <div class="modal-panel !max-w-2xl overflow-hidden" @keydown.escape.window="preview = null">
                <div class="card-header"><h3 class="card-title" x-text="preview.name"></h3><button class="btn-icon" @click="preview = null"><?= icon('x', 'h-4 w-4') ?></button></div>
                <div class="border-b border-slate-100 px-5 py-3 text-sm"><span class="text-slate-500">Subject:</span> <span class="font-medium" x-text="preview.subject"></span></div>
                <iframe sandbox="" class="h-[60vh] w-full" :srcdoc="'<style>body{font-family:Arial,sans-serif;font-size:14px;line-height:1.6;padding:16px;color:#1f2937}</style>' + preview.html"></iframe>
            </div>
        </div>
    </template>
</div>
<?php if ($total > $p['per_page']): ?><div class="card mt-4"><?= pagination_links($p) ?></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php';
