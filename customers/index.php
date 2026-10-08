<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

$filters = customer_filters_from_request();
[$where, $params] = customer_filter_sql($ws, $filters);

$total = (int) q_val("SELECT COUNT(*) FROM customers c WHERE $where", $params);
$p = paginate($total, per_page(25));
$order = sort_sql([
    'name' => 'c.full_name', 'company' => 'c.company', 'email' => 'c.email',
    'created' => 'c.created_at', 'activity' => 'c.last_activity_at', 'status' => 'c.status',
], 'created', 'desc');

$customers = q_all(
    "SELECT c.*, (SELECT COUNT(*) FROM campaign_contacts cc WHERE cc.customer_id = c.id) AS campaign_count
     FROM customers c WHERE $where ORDER BY $order, c.id DESC LIMIT ? OFFSET ?",
    [...$params, $p['per_page'], $p['offset']]
);
$tagMap = tags_for_customers(array_column($customers, 'id'));
$campaignNames = [];
if ($customers) {
    $ids = array_column($customers, 'id');
    foreach (q_all('SELECT cc.customer_id, cp.name FROM campaign_contacts cc JOIN campaigns cp ON cp.id = cc.campaign_id WHERE cc.customer_id IN (' . placeholders($ids) . ') ORDER BY cc.id DESC', $ids) as $r) {
        $campaignNames[$r['customer_id']][] = $r['name'];
    }
}

$allTags = workspace_tags($ws);
$campaigns = q_all("SELECT id, name FROM campaigns WHERE workspace_id = ? AND status <> 'archived' ORDER BY created_at DESC", [$ws]);
$countries = q_col("SELECT DISTINCT country FROM customers WHERE workspace_id = ? AND country IS NOT NULL AND country <> '' ORDER BY country LIMIT 300", [$ws]);
$pendingBulk = q_all("SELECT * FROM bulk_jobs WHERE workspace_id = ? AND status IN ('pending','processing') ORDER BY id DESC", [$ws]);
$activeFilterCount = count(array_diff_key($filters, ['q' => 1]));

$page_title = 'Customers';
$active_nav = 'customers';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Customers</h1>
        <p class="page-subtitle"><?= number_format($total) ?> <?= $filters ? 'matching' : 'total' ?> contacts</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <?php if (allowed('customers.export')): ?><a href="<?= e(url('customers/export.php', $_GET)) ?>" class="btn-secondary"><?= icon('download', 'h-4 w-4') ?> Export</a><?php endif; ?>
        <?php if (allowed('customers.import')): ?><a href="<?= e(url('customers/import.php')) ?>" class="btn-secondary"><?= icon('upload', 'h-4 w-4') ?> Import CSV</a><?php endif; ?>
        <a href="<?= e(url('customers/create.php')) ?>" class="btn-primary"><?= icon('plus', 'h-4 w-4') ?> Add customer</a>
    </div>
</div>

<?php foreach ($pendingBulk as $bj): ?>
    <div class="mb-4 rounded-lg bg-indigo-50 px-4 py-3 text-sm text-indigo-800 ring-1 ring-indigo-200">
        Bulk “<?= e(str_replace('_', ' ', $bj['action'])) ?>” is running in the background — <?= number_format((int) $bj['processed']) ?> processed so far. Refresh to see progress.
    </div>
<?php endforeach; ?>

<div class="card" x-data="bulkSelect(<?= $total ?>)">
    <!-- Filters -->
    <form method="get" class="border-b border-slate-200 p-4" x-data="{ more: <?= $activeFilterCount ? 'true' : 'false' ?> }">
        <div class="flex flex-col gap-3 sm:flex-row">
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><?= icon('search', 'h-4 w-4') ?></span>
                <input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Search name, email or company…" class="input pl-9">
            </div>
            <button type="button" class="btn-secondary" @click="more = !more"><?= icon('filter', 'h-4 w-4') ?> Filters<?php if ($activeFilterCount): ?> <span class="rounded-full bg-indigo-600 px-1.5 text-[11px] text-white"><?= $activeFilterCount ?></span><?php endif; ?></button>
            <button class="btn-primary">Apply</button>
            <?php if ($filters): ?><a href="<?= e(url('customers/index.php')) ?>" class="btn-ghost">Reset</a><?php endif; ?>
        </div>
        <div x-show="more" x-cloak class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="label">Tags</label>
                <select name="tags[]" multiple class="input h-24">
                    <?php foreach ($allTags as $t): ?><option value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], $filters['tags'] ?? [], true) ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
                </select>
                <select name="tag_mode" class="input mt-1.5 !py-1 text-xs">
                    <option value="any">Has any selected tag</option>
                    <option value="all" <?= ($filters['tag_mode'] ?? '') === 'all' ? 'selected' : '' ?>>Has all selected tags</option>
                </select>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="label">Status</label>
                    <select name="status" class="input">
                        <option value="">Any status</option>
                        <?php foreach (CUSTOMER_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
                        <option value="!unsubscribed" <?= ($filters['status'] ?? '') === '!unsubscribed' ? 'selected' : '' ?>>Not unsubscribed</option>
                    </select>
                </div>
                <div>
                    <label class="label">CRM stage</label>
                    <select name="crm_status" class="input">
                        <option value="">Any stage</option>
                        <?php foreach (CRM_STATUSES as $s): ?><option <?= ($filters['crm_status'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="label">Country</label>
                    <select name="country" class="input">
                        <option value="">Any country</option>
                        <?php foreach ($countries as $co): ?><option <?= ($filters['country'] ?? '') === $co ? 'selected' : '' ?>><?= e($co) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label">Company</label>
                    <select name="has_company" class="input">
                        <option value="">Any</option>
                        <option value="1" <?= ($filters['has_company'] ?? '') === '1' ? 'selected' : '' ?>>Has a company</option>
                        <option value="0" <?= ($filters['has_company'] ?? '') === '0' ? 'selected' : '' ?>>No company</option>
                    </select>
                </div>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="label">Campaign</label>
                    <select name="campaign_id" class="input">
                        <option value="">Any</option>
                        <?php foreach ($campaigns as $cp): ?><option value="<?= (int) $cp['id'] ?>" <?= (int) ($filters['campaign_id'] ?? 0) === (int) $cp['id'] ? 'selected' : '' ?>>In: <?= e($cp['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="label">Added from</label><input type="date" name="created_from" value="<?= e($filters['created_from'] ?? '') ?>" class="input"></div>
                    <div><label class="label">to</label><input type="date" name="created_to" value="<?= e($filters['created_to'] ?? '') ?>" class="input"></div>
                </div>
            </div>
        </div>
    </form>

    <!-- Bulk action bar -->
    <form method="post" action="<?= e(url('customers/bulk.php')) ?>" x-show="count > 0" x-cloak
          class="flex flex-wrap items-center gap-2 border-b border-indigo-100 bg-indigo-50/60 px-4 py-2.5"
          x-data="{ action: '' }"
          data-confirm="Apply this action to the selected customers?" data-confirm-button="Apply" data-confirm-danger="0">
        <?= csrf_field() ?>
        <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
        <input type="hidden" name="all_matching" :value="allMatching ? 1 : 0">
        <input type="hidden" name="filters" value="<?= e(json_encode($filters)) ?>">
        <span class="text-sm font-medium text-indigo-900"><span x-text="count.toLocaleString()"></span> selected</span>
        <template x-if="pageAllSelected && !allMatching && totalMatching > selected.length">
            <button type="button" class="text-sm font-medium text-indigo-600 underline" @click="allMatching = true">Select all <?= number_format($total) ?> matching</button>
        </template>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            <select name="action" x-model="action" class="input !w-auto !py-1.5" required>
                <option value="">Choose action…</option>
                <option value="add_tag">Add tag</option>
                <option value="remove_tag">Remove tag</option>
                <?php if (allowed('campaigns.manage')): ?><option value="add_to_campaign">Add to campaign</option><?php endif; ?>
                <option value="set_crm_status">Set CRM stage</option>
                <option value="set_status">Set status</option>
                <?php if (allowed('customers.export')): ?><option value="export">Export CSV</option><?php endif; ?>
                <?php if (allowed('customers.delete')): ?><option value="delete">Delete</option><?php endif; ?>
            </select>
            <select name="tag_id" x-show="action === 'add_tag' || action === 'remove_tag'" class="input !w-auto !py-1.5">
                <?php foreach ($allTags as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
            </select>
            <select name="campaign_id" x-show="action === 'add_to_campaign'" class="input !w-auto !py-1.5">
                <?php foreach ($campaigns as $cp): ?><option value="<?= (int) $cp['id'] ?>"><?= e($cp['name']) ?></option><?php endforeach; ?>
            </select>
            <select name="crm_status" x-show="action === 'set_crm_status'" class="input !w-auto !py-1.5">
                <?php foreach (CRM_STATUSES as $s): ?><option><?= e($s) ?></option><?php endforeach; ?>
            </select>
            <select name="status" x-show="action === 'set_status'" class="input !w-auto !py-1.5">
                <?php foreach (CUSTOMER_STATUSES as $s): ?><option value="<?= e($s) ?>"><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
            </select>
            <button class="btn-primary btn-sm" :class="action === 'delete' && '!bg-red-600'">Apply</button>
            <button type="button" class="btn-ghost btn-sm" @click="clear()">Cancel</button>
        </div>
    </form>

    <div class="overflow-x-auto">
        <table class="table">
            <thead>
            <tr>
                <th class="w-10"><input type="checkbox" class="checkbox" :checked="pageAllSelected" @change="togglePage($event)" aria-label="Select page"></th>
                <th><?= sort_link('name', 'Name') ?></th>
                <th><?= sort_link('company', 'Company') ?></th>
                <th><?= sort_link('email', 'Email') ?></th>
                <th>Tags</th>
                <th>Campaigns</th>
                <th><?= sort_link('activity', 'Last activity') ?></th>
                <th><?= sort_link('status', 'Status') ?></th>
                <th class="text-right">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($customers as $c): $name = customer_name($c); ?>
                <tr>
                    <td><input type="checkbox" class="checkbox" data-row-id value="<?= (int) $c['id'] ?>" x-model="selected" @change="allMatching = false"></td>
                    <td>
                        <a href="<?= e(url('customers/view.php', ['id' => $c['id']])) ?>" class="flex items-center gap-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold <?= avatar_color($c['email']) ?>"><?= e(initials($name)) ?></span>
                            <span class="min-w-0"><span class="block truncate font-medium text-slate-900 hover:text-indigo-600"><?= e($name) ?></span>
                            <?php if ($c['job_title']): ?><span class="block truncate text-xs text-slate-500"><?= e($c['job_title']) ?></span><?php endif; ?></span>
                        </a>
                    </td>
                    <td class="max-w-[12rem] truncate"><?= e($c['company'] ?: '—') ?></td>
                    <td class="max-w-[14rem] truncate text-slate-600"><?= e($c['email']) ?></td>
                    <td><div class="flex max-w-[14rem] flex-wrap gap-1"><?php foreach (array_slice($tagMap[$c['id']] ?? [], 0, 3) as $t) echo tag_chip($t); ?><?php if (count($tagMap[$c['id']] ?? []) > 3): ?><span class="text-xs text-slate-400">+<?= count($tagMap[$c['id']]) - 3 ?></span><?php endif; ?></div></td>
                    <td class="text-xs text-slate-600">
                        <?php if ($c['campaign_count']): ?><span class="tooltip" data-tip="<?= e(implode(', ', array_slice($campaignNames[$c['id']] ?? [], 0, 4))) ?>"><?= (int) $c['campaign_count'] ?> campaign<?= $c['campaign_count'] > 1 ? 's' : '' ?></span><?php else: ?><span class="text-slate-400">—</span><?php endif; ?>
                    </td>
                    <td class="whitespace-nowrap text-xs text-slate-500"><?= e(time_ago($c['last_activity_at'])) ?></td>
                    <td><?= status_badge($c['status']) ?></td>
                    <td class="whitespace-nowrap text-right">
                        <a href="<?= e(url('customers/email.php', ['customer_id' => $c['id']])) ?>" class="btn-icon tooltip" data-tip="Send email"><?= icon('send', 'h-4 w-4') ?></a>
                        <a href="<?= e(url('customers/edit.php', ['id' => $c['id']])) ?>" class="btn-icon tooltip" data-tip="Edit"><?= icon('pencil', 'h-4 w-4') ?></a>
                        <?php if (allowed('customers.delete')): ?>
                        <form method="post" action="<?= e(url('customers/delete.php')) ?>" class="inline" data-confirm="Delete <?= e($name) ?> and all of their emails, notes and activity?" data-confirm-button="Delete">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button class="btn-icon tooltip hover:!text-red-600" data-tip="Delete"><?= icon('trash', 'h-4 w-4') ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$customers): ?>
            <div class="empty-state">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><?= icon('users', 'h-6 w-6') ?></span>
                <h3 class="mt-3 text-sm font-semibold"><?= $filters ? 'No customers match these filters' : 'No customers yet' ?></h3>
                <p class="mt-1 text-sm text-slate-500"><?= $filters ? 'Try removing some filters.' : 'Import a CSV or add your first customer.' ?></p>
                <?php if (!$filters): ?><div class="mt-4 flex gap-2"><a href="<?= e(url('customers/import.php')) ?>" class="btn-secondary">Import CSV</a><a href="<?= e(url('customers/create.php')) ?>" class="btn-primary">Add customer</a></div><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?= pagination_links($p) ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
