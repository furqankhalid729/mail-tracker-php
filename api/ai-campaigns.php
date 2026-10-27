<?php
/**
 * AI Campaign API.
 *   GET  ?action=preview&ai_campaign_id=&filters → audience size for "add by filter"
 *   GET  ?action=search&ai_campaign_id=&q=       → customers not yet in the AI campaign
 *   POST {action: add_contacts, ai_campaign_id, customer_ids[]}
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
    $campaign = q_one("SELECT * FROM ai_campaigns WHERE id = ? AND workspace_id = ? AND status <> 'archived'", [(int) ($body['ai_campaign_id'] ?? 0), $ws]);
    if (!$campaign) {
        json_response(['ok' => false, 'error' => 'AI campaign not found.'], 404);
    }
    $added = ai_add_contacts($ws, (int) $campaign['id'], (array) ($body['customer_ids'] ?? []));
    json_response(['ok' => true, 'added' => $added]);
}

$campaign = find_or_404('ai_campaigns', input_int('ai_campaign_id'));
switch ((string) input('action')) {
    case 'preview':
        $filters = customer_filters_from_request();
        unset($filters['campaign_id'], $filters['not_in_campaign']);
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
            "SELECT COUNT(*) FROM customers c WHERE $where AND NOT EXISTS (SELECT 1 FROM ai_campaign_contacts a WHERE a.customer_id = c.id AND a.ai_campaign_id = ?)",
            [...$params, $campaign['id']]
        );
        $sample = array_map('customer_name', q_all("SELECT c.first_name, c.last_name, c.full_name, c.email FROM customers c WHERE $where ORDER BY c.id DESC LIMIT 5", $params));
        json_response(['ok' => true, 'count' => $count, 'new' => $new, 'sample' => $sample]);

    case 'search':
        $q = trim((string) input('q'));
        if (mb_strlen($q) < 2) {
            json_response(['ok' => true, 'customers' => []]);
        }
        [$where, $params] = customer_filter_sql($ws, ['q' => $q]);
        $rows = q_all(
            "SELECT c.id, c.first_name, c.last_name, c.full_name, c.email, c.company, c.website, c.status FROM customers c
             WHERE $where AND NOT EXISTS (SELECT 1 FROM ai_campaign_contacts a WHERE a.customer_id = c.id AND a.ai_campaign_id = ?)
             ORDER BY c.id DESC LIMIT 25",
            [...$params, $campaign['id']]
        );
        json_response(['ok' => true, 'customers' => array_map(fn($c) => [
            'id' => (int) $c['id'], 'name' => customer_name($c), 'email' => $c['email'], 'company' => $c['company'],
            'website' => $c['website'], 'status' => $c['status'],
        ], $rows)]);

    default:
        json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
}
