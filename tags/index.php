<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

$tags = q_all(
    'SELECT t.*, (SELECT COUNT(*) FROM customer_tags ct WHERE ct.tag_id = t.id) customers FROM tags t WHERE t.workspace_id = ? ORDER BY t.name',
    [$ws]
);
$errors = get_errors();
$editing = input_int('edit') ? find_or_404('tags', input_int('edit')) : null;
$palette = ['#6366f1', '#8b5cf6', '#ec4899', '#ef4444', '#f97316', '#f59e0b', '#10b981', '#14b8a6', '#0ea5e9', '#64748b'];

$page_title = 'Tags';
$active_nav = 'tags';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Tags</h1><p class="page-subtitle">Segment customers for filtering and campaign audiences.</p></div>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="card overflow-hidden lg:col-span-2">
        <table class="table">
            <thead><tr><th>Tag</th><th>Description</th><th class="text-right">Customers</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($tags as $t): ?>
                <tr>
                    <td><?= tag_chip($t) ?></td>
                    <td class="max-w-xs truncate text-slate-500"><?= e($t['description'] ?: '—') ?></td>
                    <td class="text-right"><a class="font-medium text-indigo-600 hover:underline" href="<?= e(url('customers/index.php', ['tags' => [$t['id']]])) ?>"><?= number_format((int) $t['customers']) ?></a></td>
                    <td class="whitespace-nowrap text-right">
                        <a href="<?= e(url('tags/index.php', ['edit' => $t['id']])) ?>" class="btn-icon"><?= icon('pencil', 'h-4 w-4') ?></a>
                        <form method="post" action="<?= e(url('tags/delete.php')) ?>" class="inline" data-confirm="Delete tag “<?= e($t['name']) ?>”? It will be removed from <?= (int) $t['customers'] ?> customers." data-confirm-button="Delete">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button class="btn-icon hover:!text-red-600"><?= icon('trash', 'h-4 w-4') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tags): ?><tr><td colspan="4" class="py-12 text-center text-slate-400">No tags yet. Create your first one →</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <form method="post" action="<?= e(url($editing ? 'tags/edit.php' : 'tags/create.php')) ?>" class="card h-fit" x-data="{ color: <?= e(json_encode((string) old('color', $editing['color'] ?? '#6366f1'))) ?>, name: <?= e(json_encode((string) old('name', $editing['name'] ?? ''))) ?> }">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
        <div class="card-header"><h2 class="card-title"><?= $editing ? 'Edit tag' : 'New tag' ?></h2><?php if ($editing): ?><a href="<?= e(url('tags/index.php')) ?>" class="text-xs text-slate-500">Cancel</a><?php endif; ?></div>
        <div class="card-body space-y-4">
            <div>
                <label class="label" for="name">Name</label>
                <input class="input" id="name" name="name" x-model="name" required maxlength="80" placeholder="Hot Lead">
                <?= field_error($errors, 'name') ?>
            </div>
            <div>
                <label class="label">Color</label>
                <div class="flex flex-wrap items-center gap-2">
                    <?php foreach ($palette as $c): ?>
                        <button type="button" class="h-7 w-7 rounded-full ring-offset-2" style="background: <?= $c ?>" :class="color === '<?= $c ?>' && 'ring-2 ring-slate-400'" @click="color = '<?= $c ?>'"></button>
                    <?php endforeach; ?>
                    <input type="color" name="color" x-model="color" class="h-7 w-10 cursor-pointer rounded border border-slate-200">
                </div>
                <?= field_error($errors, 'color') ?>
            </div>
            <div>
                <label class="label" for="description">Description</label>
                <input class="input" id="description" name="description" value="<?= e(old('description', $editing['description'] ?? '')) ?>" maxlength="255">
            </div>
            <div class="flex items-center justify-between">
                <span class="tag-chip" :style="`--tag: ${color}`" x-text="name || 'Preview'"></span>
                <button class="btn-primary"><?= $editing ? 'Save' : 'Create tag' ?></button>
            </div>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php';
