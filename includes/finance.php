<?php
declare(strict_types=1);

/**
 * Payroll and expenses (payroll.manage / expenses.manage, Admins and up).
 *
 * Payroll: one run per month. Each current employee gets a line (payslip): monthly salary, prorated when they
 * joined or left during the month, minus approved unpaid leave (for employees linked to a CRM account), plus
 * bonus / allowances, minus tax / other deductions. Lines are edited while the run is a draft, marked paid, and
 * the run is finalized to lock it.
 * Expenses: one-off costs with an optional receipt, and subscriptions whose "Record payment" adds an expense and
 * moves the next due date forward. Everything is in APP_CURRENCY (PKR by default); for services billed in another
 * currency, the amount actually charged in APP_CURRENCY is entered.
 */

const EXPENSE_CATEGORIES = [
    'software' => ['Software & subscriptions', '#6366f1'],
    'hosting' => ['Hosting, domains & email', '#0ea5e9'],
    'marketing' => ['Marketing & ads', '#ec4899'],
    'office' => ['Office & rent', '#f59e0b'],
    'utilities' => ['Utilities & internet', '#14b8a6'],
    'equipment' => ['Equipment & hardware', '#8b5cf6'],
    'contractors' => ['Contractors & freelancers', '#10b981'],
    'professional' => ['Professional services', '#64748b'],
    'travel' => ['Travel & meals', '#f97316'],
    'taxes' => ['Taxes, fees & bank charges', '#ef4444'],
    'other' => ['Other', '#94a3b8'],
];
const BILLING_CYCLES = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'];
const PAYMENT_METHODS = ['Bank transfer', 'Credit card', 'Debit card', 'Cash', 'PayPal', 'Wise', 'Payoneer', 'JazzCash', 'Easypaisa', 'Cheque'];
// Images and PDFs only for receipts
const RECEIPT_TYPES = [
    'pdf' => ['application/pdf'], 'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'webp' => ['image/webp'], 'gif' => ['image/gif'],
];

function money(float|string|null $amount, ?string $currency = APP_CURRENCY): string
{
    $s = number_format((float) $amount, 2);
    return $currency ? "$currency $s" : $s;
}

/** "PKR 85,000.00" from [currency => amount] (older records may carry another currency). */
function money_list(array $byCurrency): string
{
    if (!$byCurrency) {
        return '—';
    }
    ksort($byCurrency);
    return implode(' · ', array_map(fn($c, $a) => money($a, $c), array_keys($byCurrency), $byCurrency));
}

function expense_category_label(?string $key): string
{
    return EXPENSE_CATEGORIES[$key][0] ?? 'Other';
}

function expense_category_chip(?string $key): string
{
    [$label, $color] = EXPENSE_CATEGORIES[$key] ?? EXPENSE_CATEGORIES['other'];
    return '<span class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs text-slate-600"><span class="h-2 w-2 rounded-full" style="background:' . e($color) . '"></span>' . e($label) . '</span>';
}

/** Positive money amount from input (commas allowed), or null when empty/invalid. */
function amount_from_input(mixed $value): ?float
{
    $v = str_replace([',', ' '], '', trim((string) $value));
    return $v !== '' && is_numeric($v) && (float) $v >= 0 && (float) $v < 1e10 ? round((float) $v, 2) : null;
}

/* ---------------------------------------------------------------------------
 * Payroll
 * ------------------------------------------------------------------------- */

function payroll_period_label(string $period): string
{
    return date('F Y', strtotime($period . '-01'));
}

/** [first day, last day, days in month] of a 'YYYY-MM' period. */
function payroll_period_range(string $period): array
{
    $start = $period . '-01';
    $days = (int) date('t', strtotime($start));
    return [$start, $period . '-' . str_pad((string) $days, 2, '0', STR_PAD_LEFT), $days];
}

/** Employees on the payroll for a period: employed on at least one day of it. */
function payroll_eligible_employees(string $period): array
{
    [$start, $end] = payroll_period_range($period);
    return q_all(
        "SELECT * FROM employees WHERE workspace_id = ?
           AND (join_date IS NULL OR join_date <= ?)
           AND (leave_date IS NULL OR leave_date >= ? OR status NOT IN ('left', 'terminated'))
           AND NOT (status IN ('left', 'terminated') AND leave_date IS NULL)
         ORDER BY full_name",
        [ws_id(), $end, $start]
    );
}

/** Days of approved unpaid leave of a CRM user between two dates (half days count 0.5). */
function payroll_unpaid_leave_days(?int $userId, string $from, string $to): float
{
    if (!$userId) {
        return 0.0;
    }
    $days = 0.0;
    foreach (q_all("SELECT start_date, end_date, half_day FROM leave_requests WHERE workspace_id = ? AND user_id = ? AND status = 'approved' AND leave_type = 'unpaid' AND start_date <= ? AND end_date >= ?", [ws_id(), $userId, $to, $from]) as $l) {
        if ($l['half_day']) {
            $days += 0.5;
            continue;
        }
        $s = max($l['start_date'], $from);
        $e = min($l['end_date'], $to);
        $days += (new DateTime($s))->diff(new DateTime($e))->days + 1;
    }
    return $days;
}

/** The automatic part of a payslip line for an employee and period (salary, proration, unpaid leave). */
function payroll_auto_fields(array $emp, string $period): array
{
    [$start, $end, $daysInPeriod] = payroll_period_range($period);
    $from = $emp['join_date'] && $emp['join_date'] > $start ? $emp['join_date'] : $start;
    $to = $emp['leave_date'] && $emp['leave_date'] < $end && in_array($emp['status'], ['left', 'terminated', 'notice_period'], true) ? $emp['leave_date'] : $end;
    $daysEmployed = $to < $from ? 0 : (new DateTime($from))->diff(new DateTime($to))->days + 1;
    $salary = (float) ($emp['salary'] ?? 0);
    $daily = $daysInPeriod ? $salary / $daysInPeriod : 0;
    $basePay = $daysEmployed >= $daysInPeriod ? $salary : round($daily * $daysEmployed, 2);
    $unpaidDays = min((float) $daysEmployed, payroll_unpaid_leave_days($emp['user_id'] ? (int) $emp['user_id'] : null, $from, $to));
    return [
        'employee_name' => mb_substr((string) $emp['full_name'], 0, 150),
        'employee_code' => $emp['employee_code'],
        'job_title' => $emp['job_title'],
        'currency' => APP_CURRENCY,
        'monthly_salary' => $salary,
        'days_in_period' => $daysInPeriod,
        'days_employed' => $daysEmployed,
        'base_pay' => $basePay,
        'unpaid_days' => $unpaidDays,
        'unpaid_deduction' => min($basePay, round($daily * $unpaidDays, 2)),
    ];
}

function payroll_net(array $item): float
{
    return round((float) $item['base_pay'] + (float) $item['bonus'] + (float) $item['allowances']
        - (float) $item['unpaid_deduction'] - (float) $item['tax'] - (float) $item['other_deductions'], 2);
}

/**
 * Create the lines of a draft run, or refresh them from the employee records: salary, proration and unpaid
 * leave are recalculated; bonus, allowances, tax, other deductions, notes and payments are kept.
 * New employees are added. Returns [added, updated].
 */
function payroll_build_run(array $run): array
{
    $existing = [];
    foreach (q_all('SELECT * FROM payroll_items WHERE run_id = ? AND workspace_id = ?', [$run['id'], ws_id()]) as $it) {
        $existing[(int) $it['employee_id']] = $it;
    }
    $added = $updated = 0;
    foreach (payroll_eligible_employees($run['period']) as $emp) {
        $auto = payroll_auto_fields($emp, $run['period']);
        if ($auto['days_employed'] <= 0) {
            continue;
        }
        if ($item = $existing[(int) $emp['id']] ?? null) {
            if ($item['status'] === 'paid') {
                continue; // a paid payslip never changes
            }
            $merged = $auto + $item;
            db_update('payroll_items', $auto + ['net_pay' => payroll_net($merged), 'updated_at' => now()], 'id = ?', [$item['id']]);
            $updated++;
        } else {
            $row = $auto + ['bonus' => 0, 'allowances' => 0, 'tax' => 0, 'other_deductions' => 0];
            db_insert('payroll_items', $row + [
                'workspace_id' => ws_id(), 'run_id' => $run['id'], 'employee_id' => $emp['id'], 'net_pay' => payroll_net($row),
                'status' => 'unpaid', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $added++;
        }
    }
    return [$added, $updated];
}

function find_payroll_run_or_404(int $id): array
{
    $run = q_one(
        'SELECT r.*, c.name creator_name, f.name finalizer_name FROM payroll_runs r
         LEFT JOIN users c ON c.id = r.created_by LEFT JOIN users f ON f.id = r.finalized_by
         WHERE r.id = ? AND r.workspace_id = ?',
        [$id, ws_id()]
    );
    if (!$run) {
        abort(404, 'Payroll run not found.');
    }
    return $run;
}

/** Net pay of a run per currency: ['PKR' => ['total' => …, 'paid' => …, 'count' => …, 'paid_count' => …]]. */
function payroll_run_totals(int $runId): array
{
    $out = [];
    foreach (q_all("SELECT currency, SUM(net_pay) total, SUM(CASE WHEN status = 'paid' THEN net_pay ELSE 0 END) paid, COUNT(*) n, SUM(status = 'paid') paid_n FROM payroll_items WHERE run_id = ? AND workspace_id = ? GROUP BY currency", [$runId, ws_id()]) as $r) {
        $out[$r['currency']] = ['total' => (float) $r['total'], 'paid' => (float) $r['paid'], 'count' => (int) $r['n'], 'paid_count' => (int) $r['paid_n']];
    }
    ksort($out);
    return $out;
}

/* ---------------------------------------------------------------------------
 * Subscriptions
 * ------------------------------------------------------------------------- */

/** The next due date after $date for a billing cycle (end-of-month safe: Jan 31 → Feb 28/29). */
function subscription_next_due(string $date, string $cycle): string
{
    $months = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12][$cycle] ?? 1;
    $d = new DateTime($date);
    $day = (int) $d->format('j');
    $d->modify('first day of this month')->modify("+$months months");
    $d->setDate((int) $d->format('Y'), (int) $d->format('n'), min($day, (int) $d->format('t')));
    return $d->format('Y-m-d');
}

/** What a subscription costs per month on average. */
function subscription_monthly_cost(array $s): float
{
    return (float) $s['amount'] / (['monthly' => 1, 'quarterly' => 3, 'yearly' => 12][$s['billing_cycle']] ?? 1);
}

/** Badge for a subscription's next due date: overdue (red), within 7 days (amber), or the date. */
function subscription_due_badge(?string $date): string
{
    if (!$date) {
        return '<span class="text-xs text-slate-400">No date</span>';
    }
    $days = (int) floor((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
    $label = format_dt($date, 'M j, Y');
    if ($days < 0) {
        return '<span class="badge badge-red" title="' . e(abs($days)) . ' days overdue">Overdue · ' . e($label) . '</span>';
    }
    if ($days <= 7) {
        return '<span class="badge badge-amber">' . ($days === 0 ? 'Due today' : 'Due in ' . $days . ' day' . ($days === 1 ? '' : 's')) . '</span>';
    }
    return '<span class="text-xs text-slate-600">' . e($label) . '</span>';
}

/** Active subscriptions due within $days (or overdue), for reminders. */
function subscriptions_due_soon(int $days = 7): array
{
    return q_all('SELECT * FROM subscriptions WHERE workspace_id = ? AND is_active = 1 AND next_due IS NOT NULL AND next_due <= ? ORDER BY next_due', [ws_id(), date('Y-m-d', strtotime("+$days days"))]);
}
