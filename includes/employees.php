<?php
declare(strict_types=1);

/**
 * Employees: HR records for the workspace, with contracts and other documents.
 * Only roles with the employees.manage permission (Admin and Super Admin) can see or change any of it.
 */

const EMPLOYEE_STATUSES = [
    'working' => 'Working',
    'probation' => 'On probation',
    'on_leave' => 'On leave',
    'notice_period' => 'Notice period',
    'left' => 'Left',
    'terminated' => 'Terminated',
];
// Still on the payroll; "left" and "terminated" are former employees
const EMPLOYEE_CURRENT_STATUSES = ['working', 'probation', 'on_leave', 'notice_period'];
const EMPLOYEE_TYPES = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'contract' => 'Contract', 'intern' => 'Intern', 'freelance' => 'Freelance'];
const EMPLOYEE_DOC_TYPES = [
    'contract' => 'Contract',
    'offer_letter' => 'Offer letter',
    'id_document' => 'ID document',
    'resume' => 'CV / Resume',
    'certificate' => 'Certificate',
    'payslip' => 'Payslip',
    'other' => 'Other',
];

function employee_status_badge(?string $status): string
{
    $color = ['working' => 'green', 'probation' => 'sky', 'on_leave' => 'amber', 'notice_period' => 'violet', 'left' => 'slate', 'terminated' => 'red'][$status] ?? 'slate';
    return '<span class="badge badge-' . $color . '">' . e(EMPLOYEE_STATUSES[$status] ?? (string) $status) . '</span>';
}

function find_employee_or_404(int $id): array
{
    $emp = q_one(
        'SELECT e.*, u.name user_name, u.email user_email, cr.name creator_name
         FROM employees e
         LEFT JOIN users u ON u.id = e.user_id
         LEFT JOIN users cr ON cr.id = e.created_by
         WHERE e.id = ? AND e.workspace_id = ?',
        [$id, ws_id()]
    );
    if (!$emp) {
        abort(404, 'Employee not found.');
    }
    return $emp;
}

/** Distinct departments already in use, for the form's suggestions and the list filter. */
function employee_departments(): array
{
    return q_col("SELECT DISTINCT department FROM employees WHERE workspace_id = ? AND department IS NOT NULL AND department <> '' ORDER BY department", [ws_id()]);
}

/** Store one uploaded document for an employee (see store_private_upload()). */
function store_employee_file(string $field, int $wsId, int $employeeId): array
{
    return store_private_upload($field, "employees/$wsId/$employeeId");
}

/** Remove employee documents from disk (after their rows are deleted). */
function delete_employee_files(array $storagePaths): void
{
    delete_private_uploads($storagePaths, 'employees/');
}

/** "Expires in 12 days" style label; red when expired, amber within 30 days. */
function employee_doc_expiry_label(?string $date): string
{
    if (!$date) {
        return '';
    }
    $days = (int) floor((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
    if ($days < 0) {
        return '<span class="badge badge-red">Expired ' . e(format_dt($date, 'M j, Y')) . '</span>';
    }
    $cls = $days <= 30 ? 'badge-amber' : 'badge-slate';
    return '<span class="badge ' . $cls . '">Expires ' . e(format_dt($date, 'M j, Y')) . '</span>';
}

/** Years and months since $from (to $to or today), e.g. "2 yrs 3 mos". */
function employee_tenure(?string $from, ?string $to = null): string
{
    if (!$from) {
        return '—';
    }
    $diff = (new DateTime($from))->diff(new DateTime($to ?: 'today'));
    if ($diff->invert) {
        return 'Starts ' . format_dt($from, 'M j, Y');
    }
    $out = [];
    if ($diff->y) {
        $out[] = $diff->y . ' yr' . ($diff->y === 1 ? '' : 's');
    }
    if ($diff->m) {
        $out[] = $diff->m . ' mo' . ($diff->m === 1 ? '' : 's');
    }
    return $out ? implode(' ', $out) : ($diff->d . ' day' . ($diff->d === 1 ? '' : 's'));
}
