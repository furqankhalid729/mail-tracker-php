<?php
/**
 * Campaign board API.
 *   GET  ?campaign_id=&group=manual|system&<filters>  → columns with cards
 *   POST {contact_id, manual_status}                  → move a card (CRM stage only; never touches system_status)
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

if (is_post()) {
    verify_csrf();
    $body = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    $contactId = (int) ($body['contact_id'] ?? 0);
    $status = (string) ($body['manual_status'] ?? '');
    if (!in_array($status, CRM_STATUSES, true)) {
        json_response(['ok' => false, 'error' => 'Invalid stage.'], 422);
    }
    $cc = q_one(
        'SELECT cc.*, cp.name campaign_name FROM campaign_contacts cc JOIN campaigns cp ON cp.id = cc.campaign_id WHERE cc.id = ? AND cp.workspace_id = ?',
        [$contactId, $ws]
    );
    if (!$cc) {
        json_response(['ok' => false, 'error' => 'Contact not found.'], 404);
    }
    if ($cc['manual_status'] !== $status) {
        transaction(function () use ($cc, $status, $ws) {
            q('UPDATE campaign_contacts SET manual_status = ?, updated_at = ? WHERE id = ?', [$status, now(), $cc['id']]);
            q('UPDATE customers SET crm_status = ?, updated_at = ? WHERE id = ?', [$status, now(), $cc['customer_id']]);
            log_activity($ws, 'status_changed', 'CRM stage changed to ' . $status . ' in "' . $cc['campaign_name'] . '"', (int) $cc['customer_id'], (int) $cc['campaign_id']);
        });
    }
    json_response(['ok' => true]);
}

$campaign = find_or_404('campaigns', input_int('campaign_id'));
$group = input('group') === 'system' ? 'system' : 'manual';
$filters = contact_filters_from_request();
[$where, $params] = contact_filter_sql((int) $campaign['id'], $filters);
$column = $group === 'system' ? 'cc.system_status' : 'cc.manual_status';
$keys = $group === 'system' ? ['', ...SYSTEM_STATUSES] : CRM_STATUSES;
$perColumn = 100;

$counts = [];
foreach (q_all("SELECT COALESCE($column, '') k, COUNT(*) n FROM " . CONTACT_FROM_SQL . " WHERE $where GROUP BY k", $params) as $r) {
    $counts[$r['k']] = (int) $r['n'];
}

$columns = [];
foreach ($keys as $key) {
    if ($group === 'system' && $key === '' && empty($counts[''])) {
        continue; // hide "not queued" column when empty
    }
    $colWhere = $key === '' ? "$column IS NULL" : "$column = ?";
    $colParams = $key === '' ? $params : [...$params, $key];
    $rows = q_all(
        'SELECT cc.id, cc.customer_id, cc.system_status, cc.manual_status, cc.last_activity_at, cc.assigned_at,
                c.first_name, c.last_name, c.full_name, c.email, c.company, m.subject last_subject
         FROM ' . CONTACT_FROM_SQL . " WHERE $where AND $colWhere ORDER BY COALESCE(cc.last_activity_at, cc.assigned_at) DESC LIMIT $perColumn",
        $colParams
    );
    $tagMap = tags_for_customers(array_column($rows, 'customer_id'));
    $columns[] = [
        'key' => $key,
        'label' => $key === '' ? 'Not queued' : ucfirst($key),
        'count' => $counts[$key] ?? 0,
        'cards' => array_map(fn($r) => [
            'id' => (int) $r['id'],
            'name' => customer_name($r),
            'company' => $r['company'],
            'email' => $r['email'],
            'tags' => array_map(fn($t) => ['name' => $t['name'], 'color' => $t['color']], array_slice($tagMap[$r['customer_id']] ?? [], 0, 4)),
            'system_status' => $r['system_status'],
            'manual_status' => $r['manual_status'],
            'last_subject' => $r['last_subject'],
            'activity' => $r['last_activity_at'] ? ucfirst((string) $r['system_status'] ?: 'Updated') . ' ' . time_ago($r['last_activity_at']) : 'Added ' . time_ago($r['assigned_at']),
            'url' => url('customers/view.php', ['id' => $r['customer_id']]),
        ], $rows),
    ];
}
json_response(['ok' => true, 'columns' => $columns]);
