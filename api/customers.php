<?php
/**
 * Customer lookup for pickers.
 *   GET ?q=&not_in_campaign=&limit=  → [{id, name, email, company, status}]
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

$filters = ['q' => trim((string) input('q'))];
if (input_int('not_in_campaign')) {
    $filters['not_in_campaign'] = input_int('not_in_campaign');
}
if (input_int('campaign_id')) {
    $filters['campaign_id'] = input_int('campaign_id');
}
if (mb_strlen($filters['q']) < 2 && empty($filters['campaign_id'])) {
    json_response(['ok' => true, 'customers' => []]);
}
[$where, $params] = customer_filter_sql($ws, array_filter($filters));
$limit = min(50, max(1, input_int('limit', 20)));
$rows = q_all("SELECT c.id, c.first_name, c.last_name, c.full_name, c.email, c.company, c.status FROM customers c WHERE $where ORDER BY c.full_name LIMIT $limit", $params);

json_response(['ok' => true, 'customers' => array_map(fn($r) => [
    'id' => (int) $r['id'],
    'name' => customer_name($r),
    'email' => $r['email'],
    'company' => $r['company'],
    'status' => $r['status'],
    'added' => false,
], $rows)]);
