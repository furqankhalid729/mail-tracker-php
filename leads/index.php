<?php
/**
 * Call leads list. Closers see their assigned leads; managers and up see everyone's.
 * Every admin-defined status is a filter chip with a live count; other filters live under "Filters".
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

$filters = lead_filters_from_request();
[$where, $params] = lead_filter_sql($filters);
[$whereNoStatus, $paramsNoStatus] = lead_filter_sql($filters, false);

$statuses = lead_statuses($ws);
$statusCounts = [];
foreach (q_all("SELECT l.status_id, COUNT(*) n FROM leads l WHERE $whereNoStatus GROUP BY l.status_id", $paramsNoStatus) as $r) {
    $statusCounts[(string) $r['status_id']] = (int) $r['n'];
}
$allCount = array_sum($statusCounts);

$total = (int) q_val("SELECT COUNT(*) FROM leads l WHERE $where", $params);
$p = paginate($total, per_page(25));
$order = sort_sql([
    'name' => 'l.name', 'rating' => 'l.rating', 'reviews' => 'l.reviews_count', 'attempts' => 'l.total_attempts',
    'last_call' => 'l.last_call_at', 'created' => 'l.created_at',
], 'created', 'desc');
$leads = q_all(
    "SELECT l.*, a.name assignee_name FROM leads l LEFT JOIN users a ON a.id = l.assigned_to
     WHERE $where ORDER BY $order, l.id DESC LIMIT ? OFFSET ?",
    [...$params, $p['per_page'], $p['offset']]
);
$phones = phones_for_leads(array_column($leads, 'id'));

$viewAll = can_view_all_leads();
$manage = allowed('leads.manage');
$members = $viewAll ? workspace_users() : [];
usort($members, fn($a, $b) => [$a['role'] !== 'closer', $a['name']] <=> [$b['role'] !== 'closer', $b['name']]);
$sources = q_col("SELECT DISTINCT source FROM leads WHERE workspace_id = ? AND source IS NOT NULL AND source <> '' ORDER BY source LIMIT 200", [$ws]);
if (!empty($filters['source']) && !in_array($filters['source'], $sources, true)) {
    $sources[] = $filters['source']; // keep a filter on a now-empty list visible so it can be cleared
}
$extraKeys = lead_extra_keys($ws);
$activeFilterCount = count(array_diff_key($filters, ['q' => 1, 'status' => 1, 'field_value' => 1]));
$chipUrl = fn(?string $status) => url('leads/index.php', array_merge(array_diff_key($filters, ['status' => 1]), ['status' => $status, 'sort' => input('sort'), 'dir' => input('dir')]));

$page_title = 'Leads';
$active_nav = 'leads';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Leads</h1>
        <p class="page-subtitle"><?= number_format($total) ?> <?= $filters ? 'matching' : ($viewAll ? 'total' : 'assigned to you') ?></p>
    </div>
    <?php if ($manage): ?>
        <div class="flex flex-wrap gap-2">
            <a href="<?= e(url('leads/statuses.php')) ?>" class="btn-secondary"><?= icon('tag', 'h-4 w-4') ?> Statuses</a>
            <a href="<?= e(url('leads/import.php')) ?>" class="btn-secondary"><?= icon('upload', 'h-4 w-4') ?> Import CSV</a>
            <a href="<?= e(url('leads/edit.php')) ?>" class="btn-primary"><?= icon('plus', 'h-4 w-4') ?> Add lead</a>
        </div>
    <?php endif; ?>
</div>

<!-- Status chips: one per admin-defined status -->
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="<?= e($chipUrl(null)) ?>" class="rounded-full px-3 py-1 text-xs font-medium ring-1 <?= empty($filters['status']) ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' ?>">All <span class="opacity-70"><?= number_format($allCount) ?></span></a>
    <?php foreach ($statuses as $id => $s): $active = ($filters['status'] ?? '') === (string) $id; ?>
        <a href="<?= e($chipUrl((string) $id)) ?>" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium ring-1 <?= $active ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' ?>">
            <span class="h-2 w-2 rounded-full" style="background: <?= e($s['color']) ?>"></span><?= e($s['name']) ?> <span class="opacity-70"><?= number_format($statusCounts[(string) $id] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
    <?php if (!empty($statusCounts[''])): ?>
        <a href="<?= e($chipUrl('none')) ?>" class="rounded-full px-3 py-1 text-xs font-medium ring-1 <?= ($filters['status'] ?? '') === 'none' ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' ?>">No status <span class="opacity-70"><?= number_format($statusCounts['']) ?></span></a>
    <?php endif; ?>
</div>

<div class="card" x-data="bulkSelect(<?= $total ?>)">
    <!-- Filters -->
    <form method="get" class="border-b border-slate-200 p-4" x-data="{ more: <?= $activeFilterCount ? 'true' : 'false' ?> }">
        <?php if (!empty($filters['status'])): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
        <div class="flex flex-col gap-3 sm:flex-row">
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><?= icon('search', 'h-4 w-4') ?></span>
                <input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Search name, phone, email, website or address…" class="input pl-9">
            </div>
            <button type="button" class="btn-secondary" @click="more = !more"><?= icon('filter', 'h-4 w-4') ?> Filters<?php if ($activeFilterCount): ?> <span class="rounded-full bg-indigo-600 px-1.5 text-[11px] text-white"><?= $activeFilterCount ?></span><?php endif; ?></button>
            <button class="btn-primary">Apply</button>
            <?php if ($filters): ?><a href="<?= e(url('leads/index.php')) ?>" class="btn-ghost">Reset</a><?php endif; ?>
        </div>
        <div x-show="more" x-cloak class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <?php if ($viewAll): ?>
                <div>
                    <label class="label">Assigned to</label>
                    <select name="assigned" class="input">
                        <option value="">Anyone</option>
                        <option value="unassigned" <?= ($filters['assigned'] ?? '') === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                        <?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>" <?= ($filters['assigned'] ?? '') === (string) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div>
                <label class="label">Total attempts</label>
                <select name="attempts" class="input">
                    <?php foreach (['' => 'Any', '0' => 'Not called yet', '1-2' => '1–2', '3-5' => '3–5', '6+' => '6 or more'] as $k => $l): ?><option value="<?= e($k) ?>" <?= ($filters['attempts'] ?? '') === (string) $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="label">Last call</label>
                <select name="last_call" class="input">
                    <?php foreach (['' => 'Any time', 'today' => 'Today', '7d' => 'In the last 7 days', '30d' => 'In the last 30 days', 'older7' => 'More than 7 days ago', 'older30' => 'More than 30 days ago', 'never' => 'Never'] as $k => $l): ?><option value="<?= e($k) ?>" <?= ($filters['last_call'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="label">Reached</label>
                <select name="connected" class="input">
                    <?php foreach (['' => 'Any', 'yes' => 'Connected at least once', 'no' => 'Called, never connected'] as $k => $l): ?><option value="<?= e($k) ?>" <?= ($filters['connected'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php foreach (['has_phone' => 'Phone', 'has_email' => 'Email', 'has_website' => 'Website'] as $k => $l): ?>
                <div>
                    <label class="label"><?= e($l) ?></label>
                    <select name="<?= $k ?>" class="input">
                        <option value="">Any</option>
                        <option value="1" <?= ($filters[$k] ?? '') === '1' ? 'selected' : '' ?>>Has <?= e(strtolower($l)) ?></option>
                        <option value="0" <?= ($filters[$k] ?? '') === '0' ? 'selected' : '' ?>>No <?= e(strtolower($l)) ?></option>
                    </select>
                </div>
            <?php endforeach; ?>
            <div>
                <label class="label">Minimum rating</label>
                <select name="min_rating" class="input">
                    <?php foreach (['' => 'Any', '3' => '3+ ★', '4' => '4+ ★', '4.5' => '4.5+ ★'] as $k => $l): ?><option value="<?= e($k) ?>" <?= ($filters['min_rating'] ?? '') === (string) $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php if ($sources): ?>
                <div>
                    <label class="label">Source / list</label>
                    <select name="source" class="input">
                        <option value="">Any</option>
                        <?php foreach ($sources as $s): ?><option <?= ($filters['source'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <?php if ($extraKeys): ?>
                <div class="sm:col-span-2">
                    <label class="label">Other column contains</label>
                    <div class="flex gap-2">
                        <select name="field" class="input !w-1/2">
                            <option value="">Column…</option>
                            <?php foreach ($extraKeys as $k): ?><option <?= ($filters['field'] ?? '') === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?>
                        </select>
                        <input name="field_value" value="<?= e($filters['field_value'] ?? '') ?>" class="input" placeholder="Text">
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <!-- Bulk action bar -->
    <form method="post" action="<?= e(url('leads/actions.php')) ?>" x-show="count > 0" x-cloak
          class="flex flex-wrap items-center gap-2 border-b border-indigo-100 bg-indigo-50/60 px-4 py-2.5"
          x-data="{ bulk: '' }"
          :data-confirm="bulk === 'delete' ? `Delete ${count.toLocaleString()} lead${count === 1 ? '' : 's'}? Their timelines are deleted too; Zoom calls stay in the call log.` : 'Apply this action to the selected leads?'"
          :data-confirm-title="bulk === 'delete' ? 'Delete leads?' : 'Are you sure?'"
          :data-confirm-button="bulk === 'delete' ? 'Delete' : 'Apply'"
          :data-confirm-danger="bulk === 'delete' ? '1' : '0'">
        <?= csrf_field() ?><input type="hidden" name="action" value="bulk">
        <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
        <input type="hidden" name="all_matching" :value="allMatching ? 1 : 0">
        <input type="hidden" name="filters" value="<?= e(json_encode($filters)) ?>">
        <span class="text-sm font-medium text-indigo-900"><span x-text="count.toLocaleString()"></span> selected</span>
        <template x-if="pageAllSelected && !allMatching && totalMatching > selected.length">
            <button type="button" class="text-sm font-medium text-indigo-600 underline" @click="allMatching = true">Select all <?= number_format($total) ?> matching</button>
        </template>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            <select name="bulk" x-model="bulk" class="input !w-auto !py-1.5" required>
                <option value="">Choose action…</option>
                <option value="set_status">Set status</option>
                <?php if ($manage): ?><option value="assign">Assign to</option><option value="delete">Delete</option><?php endif; ?>
            </select>
            <select name="status_id" x-show="bulk === 'set_status'" class="input !w-auto !py-1.5">
                <?php foreach ($statuses as $id => $s): ?><option value="<?= (int) $id ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
            <?php if ($manage): ?>
                <select name="user_id" x-show="bulk === 'assign'" class="input !w-auto !py-1.5">
                    <?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?><?= $m['role'] === 'closer' ? '' : ' (' . e(role_label($m['role'])) . ')' ?></option><?php endforeach; ?>
                    <option value="0">— Unassign —</option>
                </select>
            <?php endif; ?>
            <button class="btn-primary btn-sm" :class="bulk === 'delete' && '!bg-red-600'">Apply</button>
            <button type="button" class="btn-ghost btn-sm" @click="clear()">Cancel</button>
        </div>
    </form>

    <div class="overflow-x-auto">
        <table class="table">
            <thead>
            <tr>
                <th class="w-10"><input type="checkbox" class="checkbox" :checked="pageAllSelected" @change="togglePage($event)" aria-label="Select page"></th>
                <th><?= sort_link('name', 'Lead') ?></th>
                <th>Phones</th>
                <th>Contact</th>
                <th>Status</th>
                <th class="text-right"><?= sort_link('attempts', 'Attempts') ?></th>
                <th><?= sort_link('last_call', 'Last call') ?></th>
                <?php if ($viewAll): ?><th>Assigned</th><?php endif; ?>
                <th><?= sort_link('rating', 'Rating') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($leads as $l): $lp = $phones[$l['id']] ?? []; ?>
                <tr>
                    <td><input type="checkbox" class="checkbox" data-row-id value="<?= (int) $l['id'] ?>" x-model="selected" @change="allMatching = false"></td>
                    <td class="max-w-[16rem]">
                        <a href="<?= e(url('leads/view.php', ['id' => $l['id']])) ?>" class="block truncate font-medium text-slate-900 hover:text-indigo-600"><?= e($l['name']) ?></a>
                        <?php if ($l['address']): ?><span class="block truncate text-xs text-slate-500"><?= e($l['address']) ?></span><?php endif; ?>
                    </td>
                    <td class="whitespace-nowrap text-sm">
                        <?php foreach (array_slice($lp, 0, 2) as $ph): ?>
                            <a href="<?= e(lead_tel_href($ph['phone_number'])) ?>" class="block tabular-nums text-slate-700 hover:text-indigo-600"><?= e($ph['phone_number']) ?></a>
                        <?php endforeach; ?>
                        <?php if (count($lp) > 2): ?><a href="<?= e(url('leads/view.php', ['id' => $l['id']])) ?>" class="text-xs text-slate-400">+<?= count($lp) - 2 ?> more</a><?php endif; ?>
                        <?php if (!$lp): ?><span class="text-slate-400">—</span><?php endif; ?>
                    </td>
                    <td class="max-w-[14rem] text-xs">
                        <?php if ($l['emails']): ?><span class="block truncate text-slate-600" title="<?= e($l['emails']) ?>"><?= e(explode('; ', $l['emails'])[0]) ?></span><?php endif; ?>
                        <?php if ($l['website']): ?><a href="<?= e($l['website']) ?>" target="_blank" rel="noopener noreferrer" class="block truncate text-indigo-600 hover:underline"><?= e(preg_replace('#^https?://(www\.)?#', '', rtrim($l['website'], '/'))) ?></a><?php endif; ?>
                        <?php if (!$l['emails'] && !$l['website']): ?><span class="text-slate-400">—</span><?php endif; ?>
                    </td>
                    <td>
                        <form method="post" action="<?= e(url('leads/actions.php')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                            <select name="status_id" class="input !w-auto !py-1 !pl-2 text-xs" onchange="this.form.submit()" aria-label="Status"
                                    style="border-left: 4px solid <?= e($statuses[$l['status_id']]['color'] ?? '#cbd5e1') ?>">
                                <?php if (!$l['status_id']): ?><option value="">No status</option><?php endif; ?>
                                <?php foreach ($statuses as $id => $s): ?><option value="<?= (int) $id ?>" <?= (int) $l['status_id'] === (int) $id ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td class="text-right tabular-nums">
                        <span class="font-medium text-slate-900"><?= number_format((int) $l['total_attempts']) ?></span>
                        <?php if ($l['connected_calls']): ?><span class="block text-xs text-emerald-600"><?= number_format((int) $l['connected_calls']) ?> connected</span><?php endif; ?>
                    </td>
                    <td><?= lead_last_call_badge($l) ?></td>
                    <?php if ($viewAll): ?><td class="whitespace-nowrap text-xs"><?= $l['assignee_name'] ? e($l['assignee_name']) : '<span class="text-slate-400">Unassigned</span>' ?></td><?php endif; ?>
                    <td class="whitespace-nowrap text-xs text-slate-600"><?= $l['rating'] !== null ? '★ ' . e($l['rating']) . ($l['reviews_count'] !== null ? ' <span class="text-slate-400">(' . number_format((int) $l['reviews_count']) . ')</span>' : '') : '<span class="text-slate-400">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$leads): ?>
            <div class="empty-state">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><?= icon('phone-out', 'h-6 w-6') ?></span>
                <h3 class="mt-3 text-sm font-semibold"><?= $filters ? 'No leads match these filters' : ($viewAll ? 'No leads yet' : 'No leads assigned to you yet') ?></h3>
                <p class="mt-1 text-sm text-slate-500"><?= $filters ? 'Try removing some filters.' : ($manage ? 'Import a CSV of businesses to call.' : 'Ask an admin to assign you some leads.') ?></p>
                <?php if (!$filters && $manage): ?><div class="mt-4"><a href="<?= e(url('leads/import.php')) ?>" class="btn-primary">Import CSV</a></div><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?= pagination_links($p) ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
