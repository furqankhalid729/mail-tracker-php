<?php
defined('APP_ROOT') || exit;
/**
 * Shared create/update handler included by create.php and edit.php.
 * Expects $ws and $existing (?array, the employee being edited). Returns via redirect.
 */
$errors = validate($_POST, [
    'full_name' => 'required|max:150',
    'email' => 'email|max:190',
    'phone' => 'max:50',
    'employee_code' => 'max:50',
    'job_title' => 'max:150',
    'department' => 'max:100',
    'national_id' => 'max:50',
    'address' => 'max:1000',
    'emergency_contact_name' => 'max:150',
    'emergency_contact_phone' => 'max:50',
    'bank_details' => 'max:500',
    'leave_reason' => 'max:500',
    'notes' => 'max:20000',
], ['employee_code' => 'Employee ID', 'national_id' => 'National ID']);

$dates = [];
foreach (['join_date' => 'Joining date', 'leave_date' => 'Leaving date', 'date_of_birth' => 'Date of birth'] as $field => $label) {
    $d = trim((string) input($field));
    if ($d !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d))) {
        $errors[$field] = "$label is invalid.";
    }
    $dates[$field] = $d ?: null;
}

$status = array_key_exists((string) input('status'), EMPLOYEE_STATUSES) ? (string) input('status') : 'working';
$hasLeaveFields = in_array($status, ['notice_period', 'left', 'terminated'], true);
if (!$hasLeaveFields) {
    $dates['leave_date'] = null;
} elseif (!$dates['leave_date'] && $status !== 'notice_period') {
    $dates['leave_date'] = date('Y-m-d'); // marked as left today unless a date was given
}
if ($dates['join_date'] && $dates['leave_date'] && $dates['leave_date'] < $dates['join_date']) {
    $errors['leave_date'] = 'Leaving date cannot be before the joining date.';
}

$salary = trim((string) input('salary'));
if ($salary !== '' && (!is_numeric($salary) || (float) $salary < 0 || (float) $salary >= 1e10)) {
    $errors['salary'] = 'Salary must be a positive amount.';
}

$code = trim((string) input('employee_code'));
if ($code !== '' && q_val('SELECT 1 FROM employees WHERE workspace_id = ? AND employee_code = ? AND id <> ?', [$ws, $code, (int) ($existing['id'] ?? 0)])) {
    $errors['employee_code'] = 'Another employee already has this ID.';
}

$userId = input_int('user_id') ?: null;
if ($userId && !is_workspace_user($userId)) {
    $errors['user_id'] = 'That person is not a member of this workspace.';
}

if ($errors) {
    keep_old_input();
    flash_errors($errors);
    redirect($existing ? url('employees/edit.php', ['id' => $existing['id']]) : url('employees/create.php'));
}

$text = fn(string $k) => trim((string) input($k)) ?: null;
$data = [
    'user_id' => $userId,
    'employee_code' => $code ?: null,
    'full_name' => trim((string) input('full_name')),
    'email' => $text('email') ? strtolower($text('email')) : null,
    'phone' => $text('phone'),
    'job_title' => $text('job_title'),
    'department' => $text('department'),
    'employment_type' => array_key_exists((string) input('employment_type'), EMPLOYEE_TYPES) ? (string) input('employment_type') : 'full_time',
    'status' => $status,
    'join_date' => $dates['join_date'],
    'leave_date' => $dates['leave_date'],
    'leave_reason' => $hasLeaveFields ? $text('leave_reason') : null,
    'date_of_birth' => $dates['date_of_birth'],
    'national_id' => $text('national_id'),
    'address' => $text('address'),
    'emergency_contact_name' => $text('emergency_contact_name'),
    'emergency_contact_phone' => $text('emergency_contact_phone'),
    'salary' => $salary !== '' ? round((float) $salary, 2) : null,
    'salary_currency' => APP_CURRENCY,
    'bank_details' => $text('bank_details'),
    'notes' => $text('notes'),
    'updated_at' => now(),
];

if ($existing) {
    $empId = (int) $existing['id'];
    db_update('employees', $data, 'id = ? AND workspace_id = ?', [$empId, $ws]);
    clear_old_input();
    flash('success', 'Employee updated.');
} else {
    $empId = db_insert('employees', $data + ['workspace_id' => $ws, 'created_by' => user_id(), 'created_at' => now()]);
    clear_old_input();
    [$file, $fileError] = store_employee_file('contract_file', $ws, $empId);
    if ($file) {
        db_insert('employee_documents', $file + [
            'workspace_id' => $ws,
            'employee_id' => $empId,
            'doc_type' => 'contract',
            'title' => 'Employment contract',
            'uploaded_by' => user_id(),
            'created_at' => now(),
        ]);
    }
    flash('success', 'Employee added.');
    if ($fileError) {
        flash('error', 'The contract was not uploaded: ' . $fileError);
    }
}
redirect(url('employees/view.php', ['id' => $empId]));
