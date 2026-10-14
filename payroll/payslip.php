<?php
/**
 * Payslip PDF for one payroll line: GET ?id=<payroll_items.id>[&inline=1].
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('payroll.manage');

$it = q_one(
    'SELECT i.*, r.period, r.status run_status, e.department, e.bank_details, e.national_id, e.join_date
     FROM payroll_items i JOIN payroll_runs r ON r.id = i.run_id LEFT JOIN employees e ON e.id = i.employee_id
     WHERE i.id = ? AND i.workspace_id = ?',
    [input_int('id'), ws_id()]
);
if (!$it) {
    abort(404, 'Payslip not found.');
}
session_write_close();
$logo = report_brand_logo();
$cur = $it['currency'];
$m = fn($v) => number_format((float) $v, 2);
$earnings = array_filter([
    'Basic salary' . ((int) $it['days_employed'] < (int) $it['days_in_period'] ? ' (' . (int) $it['days_employed'] . ' of ' . (int) $it['days_in_period'] . ' days)' : '') => (float) $it['base_pay'],
    'Bonus / commission' => (float) $it['bonus'],
    'Allowances' => (float) $it['allowances'],
], fn($v, $k) => $v != 0 || str_starts_with($k, 'Basic'), ARRAY_FILTER_USE_BOTH);
$deductions = array_filter([
    'Unpaid leave (' . rtrim(rtrim(number_format((float) $it['unpaid_days'], 1), '0'), '.') . ' days)' => (float) $it['unpaid_deduction'],
    'Tax' => (float) $it['tax'],
    'Other deductions' => (float) $it['other_deductions'],
], fn($v) => $v != 0);
$gross = array_sum($earnings);
$totalDed = array_sum($deductions);

ob_start();
?><!doctype html>
<html><head><meta charset="utf-8">
<style>
    @page { margin: 0; }
    body { font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2937; line-height: 1.25; margin: 0; }
    .semi { font-family: 'Poppins SemiBold', 'Poppins', sans-serif; }
    table { border-collapse: collapse; width: 100%; }
    .head { background: #05070b; color: #fff; padding: 12mm 15mm 10mm; }
    .head .brand { font-size: 11pt; color: #fff; }
    .head h1 { font-family: 'Poppins'; font-weight: bold; font-size: 24pt; color: #fff; margin: 6mm 0 0; line-height: 1; }
    .head .period { color: #A7C953; font-size: 10pt; margin-top: 2mm; }
    .accent { height: 1.4mm; background: #A7C953; }
    .body { padding: 9mm 15mm; }
    .label { color: #6b7280; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .6pt; }
    .value { font-size: 9.5pt; color: #05070b; }
    .info td { padding: 0 0 4mm; vertical-align: top; width: 33%; }
    .box { border: 1px solid #e5e7eb; border-radius: 2mm; }
    .lines th { text-align: left; font-size: 7.5pt; color: #6b7280; font-weight: normal; text-transform: uppercase; letter-spacing: .6pt; padding: 3mm 4mm; border-bottom: 1px solid #e5e7eb; background: #f9fafb; }
    .lines td { padding: 2.6mm 4mm; border-bottom: 1px solid #f1f5f9; }
    .lines .num { text-align: right; }
    .lines tr.total td { border-bottom: 0; border-top: 1px solid #e5e7eb; }
    .net { background: #05070b; color: #fff; border-radius: 2.5mm; padding: 6mm 7mm; margin-top: 7mm; }
    .net .amount { font-family: 'Poppins'; font-weight: bold; font-size: 22pt; color: #A7C953; text-align: right; }
    .foot { position: absolute; left: 15mm; right: 15mm; bottom: 12mm; font-size: 7.5pt; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 3mm; }
</style></head><body>
<div class="head">
    <table><tr>
        <td><?php if ($logo): ?><img src="<?= $logo ?>" style="height: 10mm;"><?php else: ?><span class="brand semi"><?= e(REPORT_BRAND_NAME) ?></span><?php endif; ?></td>
        <td style="text-align: right; font-size: 8pt; color: #9ca3af;"><?= e(implode('  ·  ', array_filter([REPORT_BRAND_URL ? preg_replace('#^https?://#', '', REPORT_BRAND_URL) : null, REPORT_BRAND_EMAIL ?: null]))) ?></td>
    </tr></table>
    <h1>Payslip</h1>
    <div class="period semi"><?= e(payroll_period_label($it['period'])) ?></div>
</div>
<div class="accent"></div>

<div class="body">
    <table class="info">
        <tr>
            <td><div class="label">Employee</div><div class="value semi"><?= e($it['employee_name']) ?></div></td>
            <td><div class="label">Employee ID</div><div class="value"><?= e($it['employee_code'] ?: '—') ?></div></td>
            <td><div class="label">Pay period</div><div class="value"><?= e(date('M j', strtotime($it['period'] . '-01'))) ?> – <?= e(date('M t, Y', strtotime($it['period'] . '-01'))) ?></div></td>
        </tr>
        <tr>
            <td><div class="label">Job title</div><div class="value"><?= e($it['job_title'] ?: '—') ?></div></td>
            <td><div class="label">Department</div><div class="value"><?= e($it['department'] ?: '—') ?></div></td>
            <td><div class="label">Monthly salary</div><div class="value"><?= e(money($it['monthly_salary'], $cur)) ?></div></td>
        </tr>
        <tr>
            <td><div class="label">Payment</div><div class="value"><?= $it['status'] === 'paid' ? e('Paid ' . format_dt($it['paid_on'], 'M j, Y') . ($it['payment_method'] ? ' · ' . $it['payment_method'] : '')) : 'Not paid yet' ?></div></td>
            <td><div class="label">Reference</div><div class="value"><?= e($it['payment_reference'] ?: '—') ?></div></td>
            <td><div class="label">Bank details</div><div class="value" style="font-size: 8pt;"><?= e($it['bank_details'] ?: '—') ?></div></td>
        </tr>
    </table>

    <table style="margin-top: 2mm;"><tr>
        <td style="width: 50%; vertical-align: top; padding-right: 3mm;">
            <div class="box"><table class="lines">
                <tr><th>Earnings</th><th class="num">Amount (<?= e($cur) ?>)</th></tr>
                <?php foreach ($earnings as $label => $v): ?><tr><td><?= e($label) ?></td><td class="num"><?= $m($v) ?></td></tr><?php endforeach; ?>
                <tr class="total"><td class="semi">Gross pay</td><td class="num semi"><?= $m($gross) ?></td></tr>
            </table></div>
        </td>
        <td style="width: 50%; vertical-align: top; padding-left: 3mm;">
            <div class="box"><table class="lines">
                <tr><th>Deductions</th><th class="num">Amount (<?= e($cur) ?>)</th></tr>
                <?php foreach ($deductions as $label => $v): ?><tr><td><?= e($label) ?></td><td class="num"><?= $m($v) ?></td></tr><?php endforeach; ?>
                <?php if (!$deductions): ?><tr><td style="color: #9ca3af;">No deductions</td><td class="num" style="color: #9ca3af;">0.00</td></tr><?php endif; ?>
                <tr class="total"><td class="semi">Total deductions</td><td class="num semi"><?= $m($totalDed) ?></td></tr>
            </table></div>
        </td>
    </tr></table>

    <div class="net"><table><tr>
        <td><div style="color: #9ca3af; font-size: 8pt; letter-spacing: .6pt; text-transform: uppercase;">Net pay</div><div class="semi" style="font-size: 10pt; color: #fff;"><?= e($it['employee_name']) ?></div></td>
        <td class="amount"><?= e(money($it['net_pay'], $cur)) ?></td>
    </tr></table></div>

    <?php if ($it['note']): ?><p style="margin-top: 6mm; font-size: 8.5pt; color: #4b5563;"><span class="semi">Note:</span> <?= e($it['note']) ?></p><?php endif; ?>
</div>
<div class="foot"><table><tr>
    <td>This payslip was generated by <?= e(REPORT_BRAND_NAME) ?> and is valid without a signature.</td>
    <td style="text-align: right;">Issued <?= e(date('M j, Y')) ?></td>
</tr></table></div>
</body></html>
<?php
$html = (string) ob_get_clean();
$file = 'payslip-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($it['employee_name'])) . '-' . $it['period'] . '.pdf';
send_pdf(render_branded_pdf($html), $file, (bool) input('inline'));
