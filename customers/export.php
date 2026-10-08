<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('customers.export');
$ws = ws_id();

// Source: bulk selection (stored in session by bulk.php) or the current list filters (GET)
$ids = null;
if (input('from') === 'bulk' && isset($_SESSION['_export'])) {
    $sel = $_SESSION['_export'];
    unset($_SESSION['_export']);
    if (isset($sel['ids'])) {
        $ids = owned_ids('customers', array_map('intval', $sel['ids']));
        $filters = [];
    } else {
        $filters = array_intersect_key($sel['filters'] ?? [], array_flip(CUSTOMER_FILTER_KEYS));
    }
} else {
    $filters = customer_filters_from_request();
}

[$where, $params] = customer_filter_sql($ws, $filters);
if ($ids !== null) {
    $where .= $ids ? ' AND c.id IN (' . placeholders($ids) . ')' : ' AND 0';
    $params = [...$params, ...$ids];
}

set_time_limit(120);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="customers-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
$columns = ['first_name', 'last_name', 'full_name', 'email', 'company', 'job_title', 'phone', 'website', 'country', 'source', 'status', 'crm_status', 'notes', 'unsubscribed_at', 'created_at'];
fputcsv($out, [...$columns, 'tags', 'custom_fields']);

/** Prevent CSV formula injection when the file is opened in a spreadsheet. */
$safe = fn($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;

// Stream in id-ordered chunks so memory stays flat for large exports
$lastId = 0;
do {
    $rows = q_all("SELECT c.* FROM customers c WHERE $where AND c.id > ? ORDER BY c.id LIMIT 1000", [...$params, $lastId]);
    $tagMap = tags_for_customers(array_column($rows, 'id'));
    foreach ($rows as $r) {
        $line = array_map(fn($col) => $safe($r[$col]), $columns);
        $line[] = $safe(implode(', ', array_column($tagMap[$r['id']] ?? [], 'name')));
        $line[] = $r['custom_fields'];
        fputcsv($out, $line);
        $lastId = (int) $r['id'];
    }
    flush();
} while (count($rows) === 1000);
fclose($out);
