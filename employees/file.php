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
$rel = (string) $doc['storage_path'];
$path = UPLOAD_PATH . '/' . $rel;
if ($rel === '' || !str_starts_with($rel, 'employees/') || str_contains($rel, '..') || !is_file($path)) {
    abort(404, 'File not found.');
}
session_write_close();

$inlineTypes = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];
$inline = !input('download') && in_array($doc['mime_type'], $inlineTypes, true);
$name = (string) $doc['filename'];
$ascii = preg_replace('/[^\x20\x21\x23-\x5B\x5D-\x7E]/', '_', $name); // printable ASCII minus " and \

header('Content-Type: ' . ($inline ? $doc['mime_type'] : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Cache-Control: private, no-store');
readfile($path);
exit;
