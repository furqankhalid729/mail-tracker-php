<?php
/**
 * Stream an employee document: GET ?id=<employee_documents.id>[&download=1].
 * Files live outside the web root's reach (uploads/ denies HTTP), so this is the only way to read them.
 * PDFs and images open in the browser; everything else (and ?download=1) downloads.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('employees.manage');

$doc = find_or_404('employee_documents', input_int('id'));
if (!private_upload_path_ok($doc['storage_path'], 'employees/')) {
    abort(404, 'File not found.');
}
send_private_file($doc['storage_path'], (string) $doc['filename'], (string) $doc['mime_type'], (bool) input('download'));
