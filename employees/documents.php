<?php
/**
 * Employee documents: POST action=add (file upload and/or external link) or action=delete.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();
require_permission('employees.manage');
$ws = ws_id();

if (input('action') === 'delete') {
    $doc = find_or_404('employee_documents', input_int('id'));
    q('DELETE FROM employee_documents WHERE id = ? AND workspace_id = ?', [$doc['id'], $ws]);
    delete_employee_files([$doc['storage_path']]);
    flash('success', 'Document “' . $doc['title'] . '” deleted.');
    redirect(url('employees/view.php', ['id' => $doc['employee_id']]));
}

$emp = find_employee_or_404(input_int('employee_id'));
$back = url('employees/view.php', ['id' => $emp['id']]);
$type = array_key_exists((string) input('doc_type'), EMPLOYEE_DOC_TYPES) ? (string) input('doc_type') : 'other';
$errors = validate($_POST, ['title' => 'max:200', 'link_url' => 'url|max:1000', 'notes' => 'max:500'], ['link_url' => 'Link']);
$expires = trim((string) input('expires_at'));
if ($expires !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) || !strtotime($expires))) {
    $errors['expires_at'] = 'Expiry date is invalid.';
}
$link = trim((string) input('link_url'));
$hasFile = isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE;
if (!$hasFile && $link === '' && !$errors) {
    $errors['file'] = 'Choose a file or paste a link.';
}

$file = null;
if (!$errors) {
    [$file, $fileError] = store_employee_file('file', $ws, (int) $emp['id']);
    if ($fileError) {
        $errors['file'] = $fileError;
    }
}
if ($errors) {
    keep_old_input();
    flash_errors($errors);
    redirect($back);
}

db_insert('employee_documents', ($file ?? []) + [
    'workspace_id' => $ws,
    'employee_id' => (int) $emp['id'],
    'doc_type' => $type,
    'title' => trim((string) input('title')) ?: EMPLOYEE_DOC_TYPES[$type],
    'link_url' => $link ?: null,
    'expires_at' => $expires ?: null,
    'notes' => trim((string) input('notes')) ?: null,
    'uploaded_by' => user_id(),
    'created_at' => now(),
]);
q('UPDATE employees SET updated_at = ? WHERE id = ?', [now(), $emp['id']]);
clear_old_input();
flash('success', 'Document added.');
redirect($back);
