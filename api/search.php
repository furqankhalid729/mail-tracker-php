<?php
/** Global search (Ctrl+K): customers, companies, campaigns, email subjects. Server-side, workspace-scoped. */
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

$q = trim((string) input('q'));
if (mb_strlen($q) < 2) {
    json_response(['ok' => true, 'results' => []]);
}
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
$prefix = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
$results = [];

foreach (q_all(
    'SELECT id, first_name, last_name, full_name, email, company FROM customers
     WHERE workspace_id = ? AND (email LIKE ? OR full_name LIKE ? OR first_name LIKE ? OR last_name LIKE ?)
     ORDER BY (email LIKE ?) DESC, full_name LIMIT 8',
    [$ws, $like, $like, $prefix, $prefix, $prefix]
) as $c) {
    $results[] = ['type' => 'customer', 'id' => (int) $c['id'], 'title' => customer_name($c), 'subtitle' => trim($c['email'] . ($c['company'] ? ' · ' . $c['company'] : '')), 'url' => url('customers/view.php', ['id' => $c['id']])];
}
foreach (q_all(
    'SELECT company, COUNT(*) n FROM customers WHERE workspace_id = ? AND company LIKE ? GROUP BY company ORDER BY n DESC LIMIT 5',
    [$ws, $like]
) as $co) {
    $results[] = ['type' => 'company', 'id' => md5($co['company']), 'title' => $co['company'], 'subtitle' => $co['n'] . ' contact' . ($co['n'] > 1 ? 's' : ''), 'url' => url('customers/index.php', ['q' => $co['company']])];
}
foreach (q_all('SELECT id, name, status FROM campaigns WHERE workspace_id = ? AND name LIKE ? ORDER BY updated_at DESC LIMIT 5', [$ws, $like]) as $cp) {
    $results[] = ['type' => 'campaign', 'id' => (int) $cp['id'], 'title' => $cp['name'], 'subtitle' => ucfirst($cp['status']), 'url' => url('campaigns/view.php', ['id' => $cp['id']])];
}
foreach (q_all(
    "SELECT m.id, m.subject, m.thread_id, m.customer_id, m.direction, m.to_email, m.from_email FROM email_messages m
     WHERE m.workspace_id = ? AND m.subject LIKE ? AND m.status <> 'draft' ORDER BY m.id DESC LIMIT 6",
    [$ws, $like]
) as $m) {
    $results[] = [
        'type' => 'email', 'id' => (int) $m['id'], 'title' => $m['subject'],
        'subtitle' => $m['direction'] === 'inbound' ? 'From ' . $m['from_email'] : 'To ' . $m['to_email'],
        'url' => $m['thread_id'] ? url('inbox/thread.php', ['id' => $m['thread_id']]) : url('customers/view.php', ['id' => $m['customer_id'], 'tab' => 'emails']),
    ];
}
json_response(['ok' => true, 'results' => $results]);
