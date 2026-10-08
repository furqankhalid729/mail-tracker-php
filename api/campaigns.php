<?php
/**
 * Campaign API.
 *   GET  ?action=list                         → campaigns with basic stats
 *   GET  ?action=preview&campaign_id=&filters → audience size for "add by filter"
 *   GET  ?action=stats&campaign_id=           → campaign_stats()
 *   POST {action: add_contacts, campaign_id, customer_ids[]}
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

if (is_post()) {
    verify_csrf();
    require_permission('campaigns.manage');
    $body = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    if (($body['action'] ?? '') !== 'add_contacts') {
        json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
    $campaign = q_one("SELECT * FROM campaigns WHERE id = ? AND workspace_id = ? AND status <> 'archived'", [(int) ($body['campaign_id'] ?? 0), $ws]);
    if (!$campaign) {
        json_response(['ok' => false, 'error' => 'Campaign not found.'], 404);
    }
    $ids = owned_ids('customers', array_slice(array_map('intval', (array) ($body['customer_ids'] ?? [])), 0, 1000));
    $added = 0;
    foreach ($ids as $id) {
        $added += ensure_campaign_contact($ws, (int) $campaign['id'], $id) ? 1 : 0;
    }
    json_response(['ok' => true, 'added' => $added]);
}

switch ((string) input('action', 'list')) {
    case 'preview':
        $campaign = find_or_404('campaigns', input_int('campaign_id'));
        $filters = customer_filters_from_request();
        if (input('exclude_suppressed') === '1') {
            $filters['status'] = 'active';
        }
        $hasRealFilter = (bool) array_diff_key($filters, ['status' => 1, 'tag_mode' => 1]);
        if (!$hasRealFilter && input('confirm_all') !== '1') {
            json_response(['ok' => true, 'count' => 0, 'new' => 0, 'sample' => []]);
        }
        [$where, $params] = customer_filter_sql($ws, $filters);
        $count = (int) q_val("SELECT COUNT(*) FROM customers c WHERE $where", $params);
        $new = (int) q_val(
            "SELECT COUNT(*) FROM customers c WHERE $where AND NOT EXISTS (SELECT 1 FROM campaign_contacts cc WHERE cc.customer_id = c.id AND cc.campaign_id = ?)",
            [...$params, $campaign['id']]
        );
        $sample = array_map('customer_name', q_all("SELECT c.first_name, c.last_name, c.full_name, c.email FROM customers c WHERE $where ORDER BY c.id DESC LIMIT 5", $params));
        json_response(['ok' => true, 'count' => $count, 'new' => $new, 'sample' => $sample]);

    case 'stats':
        $campaign = find_or_404('campaigns', input_int('campaign_id'));
        json_response(['ok' => true, 'stats' => campaign_stats((int) $campaign['id'])]);

    default:
        $rows = q_all(
            "SELECT id, name, status, (SELECT COUNT(*) FROM campaign_contacts cc WHERE cc.campaign_id = campaigns.id) contacts
             FROM campaigns WHERE workspace_id = ? AND status <> 'archived' ORDER BY created_at DESC LIMIT 200",
            [$ws]
        );
        json_response(['ok' => true, 'campaigns' => $rows]);
}
