<?php
/**
 * Admins define the lead statuses closers can set. Each one automatically becomes a filter on the leads list.
 *   POST action=save    [id], name, color
 *   POST action=move    id, dir = up | down
 *   POST action=delete  id, move_to (status that its leads get)
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('leads.manage');
$ws = ws_id();
$self = 'leads/statuses.php';
$statuses = lead_statuses($ws);

if (is_post()) {
    verify_csrf();
    $action = (string) input('action');
    $id = input_int('id');
    if ($id && !isset($statuses[$id])) {
        abort(404, 'Status not found.');
    }

    if ($action === 'save') {
        $name = mb_substr(trim((string) input('name')), 0, 80);
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) input('color')) ? (string) input('color') : '#64748b';
        $taken = q_val('SELECT id FROM lead_statuses WHERE workspace_id = ? AND name = ? AND id <> ?', [$ws, $name, $id]);
        if ($name === '' || $taken) {
            keep_old_input();
            flash_errors(['name' => $name === '' ? 'Enter a name.' : 'A status with that name already exists.']);
            redirect(url($self, ['edit' => $id ?: null]));
        }
        if ($id) {
            db_update('lead_statuses', ['name' => $name, 'color' => $color], 'id = ? AND workspace_id = ?', [$id, $ws]);
            flash('success', 'Status saved.');
        } else {
            $order = (int) q_val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM lead_statuses WHERE workspace_id = ?', [$ws]);
            db_insert('lead_statuses', ['workspace_id' => $ws, 'name' => $name, 'color' => $color, 'sort_order' => $order, 'created_at' => now()]);
            flash('success', 'Status added. It now appears as a filter on the leads list.');
        }
        clear_old_input();
    } elseif ($action === 'move' && $id) {
        $ids = array_keys($statuses);
        $pos = array_search($id, $ids, true);
        $swap = input('dir') === 'up' ? $pos - 1 : $pos + 1;
        if (isset($ids[$swap])) {
            [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
            foreach ($ids as $i => $sid) {
                q('UPDATE lead_statuses SET sort_order = ? WHERE id = ? AND workspace_id = ?', [$i + 1, $sid, $ws]);
            }
        }
    } elseif ($action === 'delete' && $id) {
        $moveTo = input_int('move_to');
        if (count($statuses) <= 1) {
            flash('error', 'Keep at least one status.');
        } elseif (!isset($statuses[$moveTo]) || $moveTo === $id) {
            flash('error', 'Choose the status its leads should move to.');
        } else {
            transaction(function () use ($ws, $id, $moveTo, $statuses) {
                $leadIds = array_map('intval', q_col('SELECT id FROM leads WHERE workspace_id = ? AND status_id = ?', [$ws, $id]));
                foreach (array_chunk($leadIds, 1000) as $chunk) {
                    $in = placeholders($chunk);
                    q("UPDATE leads SET status_id = ?, updated_at = ? WHERE workspace_id = ? AND id IN ($in)", [$moveTo, now(), $ws, ...$chunk]);
                    q(
                        "INSERT INTO lead_activity (workspace_id, lead_id, user_id, type, from_label, to_label, body, created_at)
                         SELECT workspace_id, id, ?, 'status', ?, ?, 'Previous status was deleted', ? FROM leads WHERE workspace_id = ? AND id IN ($in)",
                        [user_id(), $statuses[$id]['name'], $statuses[$moveTo]['name'], now(), $ws, ...$chunk]
                    );
                }
                q('DELETE FROM lead_statuses WHERE id = ? AND workspace_id = ?', [$id, $ws]);
            });
            flash('success', 'Status “' . $statuses[$id]['name'] . '” deleted.');
        }
    }
    redirect($self);
}

$counts = [];
foreach (q_all('SELECT status_id, COUNT(*) n FROM leads WHERE workspace_id = ? GROUP BY status_id', [$ws]) as $r) {
    $counts[(int) $r['status_id']] = (int) $r['n'];
}
$editing = input_int('edit') ? ($statuses[input_int('edit')] ?? null) : null;
$errors = get_errors();
$palette = ['#64748b', '#f59e0b', '#0ea5e9', '#6366f1', '#8b5cf6', '#ec4899', '#ef4444', '#f97316', '#10b981', '#14b8a6'];

$page_title = 'Lead statuses';
$active_nav = 'leads';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('leads/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Leads</a>
        <h1 class="page-title">Lead statuses</h1>
        <p class="page-subtitle">Closers set one of these on each lead. Every status is a filter on the leads list. New leads get the first one.</p>
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="card overflow-hidden lg:col-span-2">
        <table class="table">
            <thead><tr><th class="w-20">Order</th><th>Status</th><th class="text-right">Leads</th><th class="text-right">Actions</th></tr></thead>
            <?php $i = 0; $n = count($statuses); foreach ($statuses as $sid => $s): $i++; $leadCount = $counts[$sid] ?? 0; ?>
            <tbody x-data="{ del: false }">
                <tr>
                    <td class="whitespace-nowrap">
                        <?php foreach (['up' => '↑', 'down' => '↓'] as $dir => $arrow): $disabled = ($dir === 'up' && $i === 1) || ($dir === 'down' && $i === $n); ?>
                            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $sid ?>"><input type="hidden" name="dir" value="<?= $dir ?>">
                                <button class="btn-icon !px-1.5 text-sm <?= $disabled ? 'invisible' : '' ?>" aria-label="Move <?= $dir ?>"><?= $arrow ?></button>
                            </form>
                        <?php endforeach; ?>
                    </td>
                    <td><?= tag_chip($s) ?><?= $i === 1 ? ' <span class="ml-1 text-xs text-slate-400">default for new leads</span>' : '' ?></td>
                    <td class="text-right"><a class="font-medium text-indigo-600 hover:underline" href="<?= e(url('leads/index.php', ['status' => $sid])) ?>"><?= number_format($leadCount) ?></a></td>
                    <td class="whitespace-nowrap text-right">
                        <a href="<?= e(url($self, ['edit' => $sid])) ?>" class="btn-icon" aria-label="Edit"><?= icon('pencil', 'h-4 w-4') ?></a>
                        <?php if ($n > 1): ?><button type="button" class="btn-icon hover:!text-red-600" :class="del && '!text-red-600'" @click="del = !del" aria-label="Delete"><?= icon('trash', 'h-4 w-4') ?></button><?php endif; ?>
                    </td>
                </tr>
                <?php if ($n > 1): ?>
                    <tr x-show="del" x-cloak class="bg-red-50/50">
                        <td colspan="4">
                            <form method="post" class="flex flex-wrap items-center justify-end gap-2"
                                  data-confirm="Delete the status “<?= e($s['name']) ?>”?<?= $leadCount ? ' Its ' . number_format($leadCount) . ' lead' . ($leadCount === 1 ? '' : 's') . ' will move to the status you chose.' : '' ?>" data-confirm-button="Delete">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $sid ?>">
                                <?php $others = array_diff_key($statuses, [$sid => true]); ?>
                                <?php if ($leadCount): ?>
                                    <span class="text-xs text-slate-600">Delete “<?= e($s['name']) ?>” and move its <?= number_format($leadCount) ?> lead<?= $leadCount === 1 ? '' : 's' ?> to</span>
                                    <select name="move_to" class="input !w-auto !py-1 text-xs" aria-label="Move leads to">
                                        <?php foreach ($others as $oid => $o): ?><option value="<?= (int) $oid ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <span class="text-xs text-slate-600">Delete “<?= e($s['name']) ?>”? No leads use it.</span>
                                    <input type="hidden" name="move_to" value="<?= (int) array_key_first($others) ?>">
                                <?php endif; ?>
                                <button class="rounded-lg bg-red-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-red-700">Delete</button>
                                <button type="button" class="btn-ghost btn-sm" @click="del = false">Cancel</button>
                            </form>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
            <?php endforeach; ?>
        </table>
    </div>

    <form method="post" class="card h-fit" x-data="{ color: <?= e(json_encode((string) old('color', $editing['color'] ?? '#6366f1'))) ?>, name: <?= e(json_encode((string) old('name', $editing['name'] ?? ''))) ?> }">
        <?= csrf_field() ?><input type="hidden" name="action" value="save">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
        <div class="card-header"><h2 class="card-title"><?= $editing ? 'Edit status' : 'New status' ?></h2><?php if ($editing): ?><a href="<?= e(url($self)) ?>" class="text-xs text-slate-500">Cancel</a><?php endif; ?></div>
        <div class="card-body space-y-4">
            <div>
                <label class="label" for="name">Name</label>
                <input class="input" id="name" name="name" x-model="name" required maxlength="80" placeholder="Voicemail left">
                <?= field_error($errors, 'name') ?>
            </div>
            <div>
                <label class="label">Color</label>
                <div class="flex flex-wrap items-center gap-2">
                    <?php foreach ($palette as $c): ?>
                        <button type="button" class="h-7 w-7 rounded-full ring-offset-2" style="background: <?= $c ?>" :class="color === '<?= $c ?>' && 'ring-2 ring-slate-400'" @click="color = '<?= $c ?>'" aria-label="Color <?= $c ?>"></button>
                    <?php endforeach; ?>
                    <input type="color" name="color" x-model="color" class="h-7 w-10 cursor-pointer rounded border border-slate-200">
                </div>
            </div>
            <div class="flex items-center justify-between">
                <span class="tag-chip" :style="`--tag: ${color}`" x-text="name || 'Preview'"></span>
                <button class="btn-primary"><?= $editing ? 'Save' : 'Add status' ?></button>
            </div>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php';
