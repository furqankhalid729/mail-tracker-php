<?php
/**
 * Subscriptions (recurring costs such as Google Workspace or Hostinger). ?edit=<id>|new opens the form.
 * "Record payment" adds the expense and moves the next due date one billing cycle forward.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('expenses.manage');
$ws = ws_id();

$subs = q_all(
    "SELECT s.*, (SELECT MAX(expense_date) FROM expenses x WHERE x.subscription_id = s.id) last_paid,
        (SELECT COUNT(*) FROM expenses x WHERE x.subscription_id = s.id) payments
     FROM subscriptions s WHERE s.workspace_id = ? ORDER BY s.is_active DESC, s.next_due IS NULL, s.next_due, s.name",
    [$ws]
);
$monthly = $yearly = [];
foreach ($subs as $s) {
    if ($s['is_active']) {
        $monthly[$s['currency']] = ($monthly[$s['currency']] ?? 0) + subscription_monthly_cost($s);
        $yearly[$s['currency']] = ($yearly[$s['currency']] ?? 0) + subscription_monthly_cost($s) * 12;
    }
}
$editParam = (string) input('edit');
$editing = ctype_digit($editParam) ? find_or_404('subscriptions', (int) $editParam) : null;
$showForm = $editing || $editParam === 'new' || !$subs;
$errors = get_errors();
$v = fn(string $k, mixed $default = '') => old($k, $editing[$k] ?? $default);

$page_title = 'Subscriptions';
$active_nav = 'expenses';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('expenses/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Expenses</a>
        <h1 class="page-title">Subscriptions</h1>
        <p class="page-subtitle">Recurring tools and services. Record each payment to log it as an expense.</p>
    </div>
    <?php if (!$showForm): ?><a href="<?= e(url('expenses/subscriptions.php', ['edit' => 'new'])) ?>" class="btn-primary"><?= icon('plus', 'h-4 w-4') ?> Add subscription</a><?php endif; ?>
</div>

<?php if ($monthly): ?>
<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="stat-card"><div class="stat-label">Per month (active)</div><div class="stat-value text-lg"><?= e(money_list(array_map(fn($a) => round($a, 2), $monthly))) ?></div></div>
    <div class="stat-card"><div class="stat-label">Per year (active)</div><div class="stat-value text-lg"><?= e(money_list(array_map(fn($a) => round($a, 2), $yearly))) ?></div></div>
</div>
<?php endif; ?>

<div class="grid gap-6 <?= $showForm ? 'xl:grid-cols-3' : '' ?>">
    <div class="card overflow-hidden <?= $showForm ? 'xl:col-span-2' : '' ?>">
        <?php if ($subs): ?>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Service</th><th>Category</th><th class="text-right">Amount</th><th>Next payment</th><th>Last paid</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($subs as $s): $sid = (int) $s['id']; ?>
                    <tr class="<?= $s['is_active'] ? '' : 'opacity-60' ?>">
                        <td>
                            <div class="font-medium text-slate-900"><?= e($s['name']) ?><?= $s['is_active'] ? '' : ' <span class="badge badge-slate">Paused</span>' ?></div>
                            <div class="text-xs text-slate-500"><?= e(implode(' · ', array_filter([$s['vendor'], $s['payment_method']]))) ?>
                                <?php if ($s['manage_url']): ?> · <a href="<?= e($s['manage_url']) ?>" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">Manage</a><?php endif; ?></div>
                        </td>
                        <td><?= expense_category_chip($s['category']) ?></td>
                        <td class="whitespace-nowrap text-right">
                            <span class="font-medium tabular-nums text-slate-900"><?= e(money($s['amount'], $s['currency'])) ?></span>
                            <div class="text-xs text-slate-500"><?= e(strtolower(BILLING_CYCLES[$s['billing_cycle']])) ?><?= $s['billing_cycle'] !== 'monthly' ? ' · ' . e(number_format(subscription_monthly_cost($s), 2)) . '/mo' : '' ?></div>
                        </td>
                        <td class="whitespace-nowrap"><?= $s['is_active'] ? subscription_due_badge($s['next_due']) : '<span class="text-xs text-slate-400">—</span>' ?></td>
                        <td class="whitespace-nowrap text-xs text-slate-500"><?= $s['last_paid'] ? e(format_dt($s['last_paid'], 'M j, Y')) . ' <span class="text-slate-400">(' . (int) $s['payments'] . 'x)</span>' : 'Never' ?></td>
                        <td class="whitespace-nowrap text-right">
                            <?php if ($s['is_active']): ?>
                            <div class="relative inline-block text-left" x-data="{ open: false }" @click.outside="open = false">
                                <button type="button" class="btn-primary btn-sm" @click="open = !open"><?= icon('check', 'h-4 w-4') ?> Record payment</button>
                                <form method="post" action="<?= e(url('expenses/actions.php')) ?>" x-show="open" x-cloak class="dropdown right-0 w-64 space-y-2 p-3">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="pay_subscription"><input type="hidden" name="id" value="<?= $sid ?>">
                                    <label class="label !mb-0 text-xs">Paid on</label>
                                    <input type="date" name="paid_on" value="<?= e($s['next_due'] && $s['next_due'] <= date('Y-m-d') ? $s['next_due'] : date('Y-m-d')) ?>" class="input !py-1.5">
                                    <label class="label !mb-0 text-xs">Amount charged (<?= e(APP_CURRENCY) ?>)</label>
                                    <input name="amount" value="<?= e(number_format((float) $s['amount'], 2, '.', '')) ?>" inputmode="decimal" class="input !py-1.5">
                                    <button class="btn-primary w-full justify-center">Save payment</button>
                                    <p class="text-[11px] text-slate-500">Next payment moves to <?= e(format_dt(subscription_next_due($s['next_due'] ?: date('Y-m-d'), $s['billing_cycle']), 'M j, Y')) ?>.</p>
                                </form>
                            </div>
                            <?php endif; ?>
                            <a href="<?= e(url('expenses/subscriptions.php', ['edit' => $sid])) ?>" class="btn-icon" title="Edit" aria-label="Edit <?= e($s['name']) ?>"><?= icon('pencil', 'h-4 w-4') ?></a>
                            <form method="post" action="<?= e(url('expenses/actions.php')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_subscription"><input type="hidden" name="id" value="<?= $sid ?>"><button class="btn-icon" title="<?= $s['is_active'] ? 'Pause' : 'Resume' ?>" aria-label="<?= $s['is_active'] ? 'Pause' : 'Resume' ?>"><?= icon($s['is_active'] ? 'pause' : 'play', 'h-4 w-4') ?></button></form>
                            <form method="post" action="<?= e(url('expenses/actions.php')) ?>" class="inline" data-confirm="Delete <?= e($s['name']) ?>? Payments already recorded stay in expenses." data-confirm-button="Delete"><?= csrf_field() ?><input type="hidden" name="action" value="delete_subscription"><input type="hidden" name="id" value="<?= $sid ?>"><button class="btn-icon text-red-600" title="Delete" aria-label="Delete <?= e($s['name']) ?>"><?= icon('trash', 'h-4 w-4') ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="empty-state">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-50 text-indigo-600"><?= icon('receipt', 'h-6 w-6') ?></span>
                <h3 class="mt-3 text-sm font-semibold">No subscriptions yet</h3>
                <p class="mt-1 text-sm text-slate-500">Add Google Workspace, Hostinger, Zoom… to see renewals coming up.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($showForm): ?>
    <form method="post" action="<?= e(url('expenses/actions.php')) ?>" class="card h-fit">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_subscription"><?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
        <div class="card-header"><h2 class="card-title"><?= $editing ? 'Edit subscription' : 'Add subscription' ?></h2><?php if ($subs): ?><a href="<?= e(url('expenses/subscriptions.php')) ?>" class="text-xs text-slate-500">Cancel</a><?php endif; ?></div>
        <div class="card-body space-y-4">
            <div>
                <label class="label" for="name">Service <span class="text-red-500">*</span></label>
                <input class="input" id="name" name="name" value="<?= e($v('name')) ?>" required maxlength="150" placeholder="Google Workspace" list="sub-names">
                <datalist id="sub-names"><?php foreach (['Google Workspace', 'Hostinger', 'Microsoft 365', 'Zoom', 'Canva', 'ChatGPT', 'Claude', 'Shopify', 'Namecheap', 'GoDaddy', 'Cloudflare', 'Slack', 'Notion', 'Adobe Creative Cloud', 'LinkedIn Sales Navigator'] as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
                <?= field_error($errors, 'name') ?>
            </div>
            <div>
                <label class="label" for="amount">Amount per cycle (<?= e(APP_CURRENCY) ?>) <span class="text-red-500">*</span></label>
                <input class="input" id="amount" name="amount" value="<?= e($v('amount')) ?>" required inputmode="decimal" placeholder="4,000">
                <p class="help">For services billed in dollars, enter what your card is charged in <?= e(APP_CURRENCY) ?>. You can adjust it each time you record a payment.</p>
                <?= field_error($errors, 'amount') ?>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="label" for="billing_cycle">Billed</label>
                    <select class="input" id="billing_cycle" name="billing_cycle">
                        <?php foreach (BILLING_CYCLES as $k => $l): ?><option value="<?= $k ?>" <?= $v('billing_cycle', 'monthly') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="label" for="next_due">Next payment</label>
                    <input class="input" type="date" id="next_due" name="next_due" value="<?= e($v('next_due')) ?>">
                    <?= field_error($errors, 'next_due') ?>
                </div>
            </div>
            <div>
                <label class="label" for="category">Category</label>
                <select class="input" id="category" name="category">
                    <?php foreach (EXPENSE_CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>" <?= $v('category', 'software') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="label" for="vendor">Vendor</label>
                    <input class="input" id="vendor" name="vendor" value="<?= e($v('vendor')) ?>" maxlength="150" placeholder="Google">
                </div>
                <div>
                    <label class="label" for="payment_method">Paid with</label>
                    <input class="input" id="payment_method" name="payment_method" value="<?= e($v('payment_method')) ?>" maxlength="50" list="pay-methods" placeholder="Credit card">
                    <datalist id="pay-methods"><?php foreach (PAYMENT_METHODS as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist>
                </div>
            </div>
            <div>
                <label class="label" for="manage_url">Billing page link</label>
                <input class="input" type="url" id="manage_url" name="manage_url" value="<?= e($v('manage_url')) ?>" maxlength="500" placeholder="https://admin.google.com/ac/billing">
                <?= field_error($errors, 'manage_url') ?>
            </div>
            <div>
                <label class="label" for="notes">Notes</label>
                <textarea class="input" id="notes" name="notes" rows="2" maxlength="1000" placeholder="Seats, plan, account email…"><?= e($v('notes')) ?></textarea>
            </div>
            <div class="flex justify-end"><button class="btn-primary"><?= $editing ? 'Save subscription' : 'Add subscription' ?></button></div>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php';
