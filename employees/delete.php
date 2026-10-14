<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();
require_permission('employees.manage');

$employee = find_employee_or_404(input_int('id'));
$files = q_col('SELECT storage_path FROM employee_documents WHERE employee_id = ? AND workspace_id = ?', [$employee['id'], ws_id()]);
q('DELETE FROM employees WHERE id = ? AND workspace_id = ?', [$employee['id'], ws_id()]); // documents cascade
delete_employee_files($files);
flash('success', 'Employee “' . $employee['full_name'] . '” and their documents deleted.');
redirect('employees/index.php');
