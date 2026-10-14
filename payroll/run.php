<?php
/**
 * One payroll run.
 *   POST action=save      items[id][bonus|allowances|tax|other_deductions|note]   (draft only)
 *   POST action=pay       ids[], paid_on, payment_method, payment_reference
 *   POST action=unpay     id
 *   POST action=refresh   recalculate salary, proration and unpaid leave from employee records (draft only)
 *   POST action=finalize | reopen (Super Admin) | delete (draft only)
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('payroll.manage');
$ws = ws_id();
$run = find_payroll_run_or_404(input_int('id'));
$rid = (int) $run['id'];
$isDraft = $run['status'] === 'draft';
$self = url('payroll/run.php', ['id' => $rid]);

if (is_post()) {
    verify_csrf();
    $action = (string) input('action');
    if (in_array($action, ['save', 'refresh', 'delete', 'finalize'], true) && !$isDraft) {
        flash('error', 'This payroll is finalized. Reopen it first.');
        redirect($self);
    }
    switch ($action) {
        case 'save':
            $n = 0;
            foreach ((array) input('items', []) as $itemId => $f) {
                $item = q_one("SELECT * FROM payroll_items WHERE id = ? AND run_id = ? AND workspace_id = ? AND status = 'unpaid'", [(int) $itemId, $rid, $ws]);
                if (!$item || !is_array($f)) {
                    continue;
                }
                $data = [];
                foreach (['bonus', 'allowances', 'tax', 'other_deductions'] as $k) {
                    $data[$k] = amount_from_input($f[$k] ?? '') ?? 0.0;
                }
                $data['note'] = mb_substr(trim((string) ($f['note'] ?? '')), 0, 500) ?: null;
                db_update('payroll_items', $data + ['net_pay' => payroll_net($data + $item), 'updated_at' => now()], 'id = ?', [$item['id']]);
                $n++;
            }
            flash('success', 'Payroll saved.');
            break;
        case 'pay':
            $ids = input_ids('ids');
            $paidOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) input('paid_on')) ? (string) input('paid_on') : date('Y-m-d');
            if (!$ids) {
                flash('error', 'Select the employees you paid.');
                break;
            }
            $n = q('UPDATE payroll_items SET status = ?, paid_on = ?, payment_method = ?, payment_reference = ?, updated_at = ? WHERE run_id = ? AND workspace_id = ? AND status = ? AND id IN (' . placeholders($ids) . ')', [
                'paid', $paidOn, mb_substr(trim((string) input('payment_method')), 0, 50) ?: null, mb_substr(trim((string) input('payment_reference')), 0, 100) ?: null, now(), $rid, $ws, 'unpaid', ...$ids,
            ])->rowCount();
            flash('success', $n . ' payslip' . ($n === 1 ? '' : 's') . ' marked paid.');
            break;
        case 'unpay':
            q("UPDATE payroll_items SET status = 'unpaid', paid_on = NULL, payment_method = NULL, payment_reference = NULL, updated_at = ? WHERE id = ? AND run_id = ? AND workspace_id = ?", [now(), input_int('item'), $rid, $ws]);
            flash('success', 'Marked as not paid.');
            break;
        case 'refresh':
            [$added, $updated] = payroll_build_run($run);
            flash('success', "Refreshed from employee records: $updated updated" . ($added ? ", $added added" : '') . '. Paid lines were not changed.');
            break;
        case 'finalize':
            db_update('payroll_runs', ['status' => 'finalized', 'finalized_by' => user_id(), 'finalized_at' => now()], 'id = ? AND workspace_id = ?', [$rid, $ws]);
            flash('success', 'Payroll finalized. Amounts are locked; you can still mark payslips paid.');
            break;
        case 'reopen':
            require_role('owner');
            db_update('payroll_runs', ['status' => 'draft', 'finalized_by' => null, 'finalized_at' => null], 'id = ? AND workspace_id = ?', [$rid, $ws]);
            flash('success', 'Payroll reopened for changes.');
            break;
        case 'delete':
            if (q_val("SELECT 1 FROM payroll_items WHERE run_id = ? AND status = 'paid' LIMIT 1", [$rid])) {
                flash('error', 'Some payslips are already paid. Mark them unpaid before deleting this payroll.');
                break;
            }
            q('DELETE FROM payroll_runs WHERE id = ? AND workspace_id = ?', [$rid, $ws]);
            flash('success', 'Payroll for ' . payroll_period_label($run['period']) . ' deleted.');
            redirect('payroll/index.php');
    }
    redirect($self);
}

$items = q_all(
    'SELECT i.*, e.status employee_status, e.bank_details FROM payroll_items i LEFT JOIN employees e ON e.id = i.employee_id
     WHERE i.run_id = ? AND i.workspace_id = ? ORDER BY i.employee_name',
    [$rid, $ws]
);
$totals = payroll_run_totals($rid);
$missing = 0;
if ($isDraft) {
    $inRun = array_map('intval', array_column($items, 'employee_id'));
    foreach (payroll_eligible_employees($run['period']) as $emp) {
        if (!in_array((int) $emp['id'], $inRun, true) && payroll_auto_fields($emp, $run['period'])['days_employed'] > 0) {
            $missing++;
        }
    }
}
$fmt = fn($v) => number_format((float) $v, 2, '.', '');

$page_title = 'Payroll · ' . payroll_period_label($run['period']);
$active_nav = 'payroll';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('payroll/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Payroll</a>
        <div class="flex items-center gap-2">
            <h1 class="page-title"><?= e(payroll_period_label($run['period'])) ?></h1>
            <?= $isDraft ? '<span class="badge badge-amber">Draft</span>' : '<span class="badge badge-green">Finalized</span>' ?>
        </div>
        <p class="page-subtitle"><?= $isDraft ? 'Adjust bonuses and deductions, mark payslips paid, then finalize.' : 'Finalized by ' . e($run['finalizer_name'] ?: 'a former member') . ' · ' . e(format_dt($run['finalized_at'])) ?></p>
    </div>
    <div class="flex flex-wrap gap-2">
        <?php if ($isDraft): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refresh"><button class="btn-secondary" title="Recalculate salary, proration and unpaid leave from employee records"><?= icon('bolt', 'h-4 w-4') ?> Refresh</button></form>
            <form method="post" data-confirm="Finalize <?= e(payroll_period_label($run['period'])) ?>? Amounts will be locked." data-confirm-button="Finalize"><?= csrf_field() ?><input type="hidden" name="action" value="finalize"><button class="btn-primary"><?= icon('check', 'h-4 w-4') ?> Finalize</button></form>
            <form method="post" data-confirm="Delete the payroll for <?= e(payroll_period_label($run['period'])) ?>?" data-confirm-button="Delete"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn-secondary text-red-600" title="Delete payroll" aria-label="Delete payroll"><?= icon('trash', 'h-4 w-4') ?></button></form>
        <?php elseif (can('owner')): ?>
            <form method="post" data-confirm="Reopen this payroll for changes?" data-confirm-button="Reopen"><?= csrf_field() ?><input type="hidden" name="action" value="reopen"><button class="btn-secondary"><?= icon('pencil', 'h-4 w-4') ?> Reopen</button></form>
        <?php endif; ?>
    </div>
</div>

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <?php foreach ($totals as $cur => $t): ?>
        <div class="stat-card"><div class="stat-label">Net pay · <?= e($cur) ?></div><div class="stat-value"><?= e(number_format($t['total'], 2)) ?></div><div class="mt-1 text-xs text-slate-500"><?= e(number_format($t['paid'], 2)) ?> paid · <?= $t['paid_count'] ?>/<?= $t['count'] ?> payslips</div></div>
    <?php endforeach; ?>
    <?php if (!$totals): ?><div class="stat-card"><div class="stat-label">Net pay</div><div class="stat-value">0</div></div><?php endif; ?>
</div>

<?php if ($missing): ?>
    <div class="mb-4 flex items-center justify-between gap-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">
        <span><?= $missing ?> employee<?= $missing === 1 ? ' is' : 's are' ?> not in this payroll yet (added after it was created).</span>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refresh"><button class="font-medium underline">Add them</button></form>
    </div>
<?php endif; ?>

<form method="post" class="card overflow-hidden" x-data="{ selected: [], paying: false }">
    <?= csrf_field() ?>
    <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3" x-show="selected.length" x-cloak>
        <span class="text-sm font-medium text-slate-700"><span x-text="selected.length"></span> selected</span>
        <input type="date" name="paid_on" value="<?= date('Y-m-d') ?>" class="input !w-auto !py-1.5" aria-label="Paid on">
        <input name="payment_method" list="pay-methods" class="input !w-44 !py-1.5" placeholder="Method (Bank transfer…)" maxlength="50">
        <datalist id="pay-methods"><?php foreach (PAYMENT_METHODS as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist>
        <input name="payment_reference" class="input !w-40 !py-1.5" placeholder="Reference (optional)" maxlength="100">
        <button name="action" value="pay" class="btn-primary btn-sm"><?= icon('check', 'h-4 w-4') ?> Mark paid</button>
    </div>
    <div class="overflow-x-auto">
        <table class="table text-sm">
            <thead><tr>
                <th class="w-8"><input type="checkbox" class="checkbox" aria-label="Select all unpaid" @change="selected = $event.target.checked ? [...$root.querySelectorAll('[data-pay]')].map(c => c.value) : []"></th>
                <th>Employee</th>
                <th class="text-right">Base pay</th>
                <th class="text-right">Bonus</th>
                <th class="text-right">Allowances</th>
                <th class="text-right">Unpaid leave</th>
                <th class="text-right">Tax</th>
                <th class="text-right">Other ded.</th>
                <th class="text-right">Net pay</th>
                <th>Status</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($items as $it): $edit = $isDraft && $it['status'] === 'unpaid'; $iid = (int) $it['id']; ?>
                <tr class="align-top">
                    <td class="pt-4"><?php if ($it['status'] === 'unpaid'): ?><input type="checkbox" class="checkbox" name="ids[]" value="<?= $iid ?>" data-pay x-model="selected" aria-label="Select <?= e($it['employee_name']) ?>"><?php endif; ?></td>
                    <td class="min-w-[12rem]">
                        <?php if ($it['employee_id']): ?><a href="<?= e(url('employees/view.php', ['id' => $it['employee_id']])) ?>" class="font-medium text-slate-900 hover:text-indigo-600"><?= e($it['employee_name']) ?></a><?php else: ?><span class="font-medium text-slate-900"><?= e($it['employee_name']) ?></span><?php endif; ?>
                        <div class="text-xs text-slate-500"><?= e(implode(' · ', array_filter([$it['job_title'], $it['currency'] . ' ' . number_format((float) $it['monthly_salary'], 2) . '/mo']))) ?></div>
                        <?php if ((int) $it['days_employed'] < (int) $it['days_in_period']): ?><div class="text-xs text-amber-600">Prorated: <?= (int) $it['days_employed'] ?> of <?= (int) $it['days_in_period'] ?> days</div><?php endif; ?>
                        <?php if ($edit): ?>
                            <input name="items[<?= $iid ?>][note]" value="<?= e($it['note']) ?>" maxlength="500" placeholder="Note on payslip (optional)" class="input mt-1 !py-1 text-xs">
                        <?php elseif ($it['note']): ?><div class="mt-1 text-xs italic text-slate-500"><?= e($it['note']) ?></div><?php endif; ?>
                    </td>
                    <td class="whitespace-nowrap pt-4 text-right tabular-nums"><?= e(number_format((float) $it['base_pay'], 2)) ?></td>
                    <?php foreach (['bonus', 'allowances'] as $k): ?>
                        <td class="text-right"><?php if ($edit): ?><input name="items[<?= $iid ?>][<?= $k ?>]" value="<?= e($fmt($it[$k])) ?>" inputmode="decimal" class="input !w-24 !py-1 text-right text-xs tabular-nums" aria-label="<?= e(ucfirst($k)) ?>"><?php else: ?><span class="tabular-nums leading-8"><?= e(number_format((float) $it[$k], 2)) ?></span><?php endif; ?></td>
                    <?php endforeach; ?>
                    <td class="whitespace-nowrap pt-4 text-right tabular-nums <?= (float) $it['unpaid_deduction'] ? 'text-red-600' : 'text-slate-400' ?>" title="<?= e((float) $it['unpaid_days'] . ' day(s) of approved unpaid leave') ?>"><?= (float) $it['unpaid_deduction'] ? '−' . e(number_format((float) $it['unpaid_deduction'], 2)) . '<div class="text-[11px] text-slate-400">' . e(rtrim(rtrim(number_format((float) $it['unpaid_days'], 1), '0'), '.')) . ' day(s)</div>' : '0.00' ?></td>
                    <?php foreach (['tax', 'other_deductions'] as $k): ?>
                        <td class="text-right"><?php if ($edit): ?><input name="items[<?= $iid ?>][<?= $k ?>]" value="<?= e($fmt($it[$k])) ?>" inputmode="decimal" class="input !w-24 !py-1 text-right text-xs tabular-nums" aria-label="<?= e($k === 'tax' ? 'Tax' : 'Other deductions') ?>"><?php else: ?><span class="tabular-nums leading-8"><?= e(number_format((float) $it[$k], 2)) ?></span><?php endif; ?></td>
                    <?php endforeach; ?>
                    <td class="whitespace-nowrap pt-4 text-right font-semibold tabular-nums text-slate-900"><?= e(money($it['net_pay'], $it['currency'])) ?></td>
                    <td class="whitespace-nowrap pt-3">
                        <?php if ($it['status'] === 'paid'): ?>
                            <span class="badge badge-green">Paid</span>
                            <div class="mt-0.5 text-[11px] text-slate-500"><?= e(format_dt($it['paid_on'], 'M j')) ?><?= $it['payment_method'] ? ' · ' . e($it['payment_method']) : '' ?></div>
                        <?php else: ?><span class="badge badge-slate">Unpaid</span><?php endif; ?>
                    </td>
                    <td class="whitespace-nowrap pt-3 text-right">
                        <a href="<?= e(url('payroll/payslip.php', ['id' => $iid])) ?>" class="btn-ghost !px-2 !py-1 text-xs" title="Payslip PDF"><?= icon('download', 'h-4 w-4') ?> Payslip</a>
                        <?php if ($it['status'] === 'paid'): ?><button name="action" value="unpay" formaction="<?= e(url('payroll/run.php', ['id' => $rid, 'item' => $iid])) ?>" class="btn-ghost !px-2 !py-1 text-xs text-slate-500" title="Mark not paid">Undo</button><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?><tr><td colspan="11" class="py-10 text-center text-sm text-slate-400">No employees were on the payroll in <?= e(payroll_period_label($run['period'])) ?>.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($isDraft && $items): ?>
        <div class="flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-3">
            <p class="text-xs text-slate-500">Net pay = base pay + bonus + allowances − unpaid leave − tax − other deductions. Paid payslips are locked.</p>
            <button name="action" value="save" class="btn-primary">Save changes</button>
        </div>
    <?php endif; ?>
</form>
<?php require __DIR__ . '/../includes/footer.php';
