<?php
/**
 * Recent activity feed (email events + CRM activity), newest first.
 *   GET ?since_id=&limit=  → events newer than since_id (for lightweight polling)
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();
$limit = min(100, max(1, input_int('limit', 20)));
$sinceId = input_int('since_id');

$rows = q_all(
    "SELECT ev.id, ev.type, ev.created_at, ev.customer_id, c.first_name, c.last_name, c.full_name, c.email, cp.name campaign
     FROM email_events ev LEFT JOIN customers c ON c.id = ev.customer_id LEFT JOIN campaigns cp ON cp.id = ev.campaign_id
     WHERE ev.workspace_id = ? AND ev.id > ? ORDER BY ev.id DESC LIMIT $limit",
    [$ws, $sinceId]
);
json_response(['ok' => true, 'events' => array_map(fn($r) => [
    'id' => (int) $r['id'],
    'type' => $r['type'],
    'label' => event_label($r['type']),
    'customer' => customer_name($r),
    'customer_url' => url('customers/view.php', ['id' => $r['customer_id']]),
    'campaign' => $r['campaign'],
    'at' => $r['created_at'],
    'ago' => time_ago($r['created_at']),
], $rows)]);
