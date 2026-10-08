<?php
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_post();
$ws = ws_id();

$action = (string) input('action');
$allMatching = input('all_matching') === '1';
$filters = json_decode((string) ($_POST['filters'] ?? '{}'), true) ?: [];
$filters = array_intersect_key($filters, array_flip(CUSTOMER_FILTER_KEYS));

if ($action === 'export') {
    require_permission('customers.export');
    $_SESSION['_export'] = $allMatching ? ['filters' => $filters] : ['ids' => input_ids('ids')];
    redirect('customers/export.php?from=bulk');
}

$payload = [
    'tag_id' => input_int('tag_id'),
    'campaign_id' => input_int('campaign_id'),
    'status' => (string) input('status'),
    'crm_status' => (string) input('crm_status'),
    'user_id' => user_id(),
];
if ($error = validate_bulk_payload($ws, $action, $payload)) {
    flash('error', $error);
    redirect_back('customers/index.php');
}
$required = ['delete' => 'customers.delete', 'add_to_campaign' => 'campaigns.manage'][$action] ?? null;
if ($required && !allowed($required)) {
    flash('error', 'Your role (' . role_label(user_role()) . ') cannot do that.');
    redirect_back('customers/index.php');
}

if ($allMatching) {
    [$where, $params] = customer_filter_sql($ws, $filters);
    $total = (int) q_val("SELECT COUNT(*) FROM customers c WHERE $where", $params);
    if ($total > BULK_SYNC_LIMIT) {
        // Too large for one request: hand off to cron (processed in id-ordered chunks)
        db_insert('bulk_jobs', [
            'workspace_id' => $ws,
            'user_id' => user_id(),
            'action' => $action,
            'payload' => json_encode([...$payload, 'filters' => $filters]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        flash('info', number_format($total) . ' customers will be updated in the background over the next few minutes.');
        redirect_back('customers/index.php');
    }
    $ids = array_map('intval', q_col("SELECT c.id FROM customers c WHERE $where", $params));
} else {
    $ids = owned_ids('customers', input_ids('ids'));
}

if (!$ids) {
    flash('error', 'No customers selected.');
    redirect_back('customers/index.php');
}

$affected = 0;
foreach (array_chunk($ids, 500) as $chunk) {
    $affected += transaction(fn() => apply_bulk_chunk($ws, $action, $payload, $chunk));
}

$labels = [
    'add_tag' => 'Tag added to %d customers.',
    'remove_tag' => 'Tag removed from %d customers.',
    'add_to_campaign' => '%d customers added to the campaign (existing members skipped).',
    'set_status' => 'Status updated for %d customers.',
    'set_crm_status' => 'CRM stage updated for %d customers.',
    'delete' => '%d customers deleted.',
];
flash('success', sprintf($labels[$action], $affected));
redirect_back('customers/index.php');
