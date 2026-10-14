<?php
/** Open or download an expense receipt: GET ?id=<expenses.id>[&download=1]. */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('expenses.manage');

$expense = find_or_404('expenses', input_int('id'));
if (!private_upload_path_ok($expense['receipt_path'], 'expenses/')) {
    abort(404, 'Receipt not found.');
}
send_private_file($expense['receipt_path'], (string) $expense['receipt_name'], (string) $expense['receipt_mime'], (bool) input('download'));
