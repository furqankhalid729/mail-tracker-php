<?php
/**
 * Delivery / bounce webhook receiver.
 *   POST /webhooks/email.php?provider=sendgrid|mailgun|ses|generic
 * Auth: "Authorization: Bearer WEBHOOK_SECRET" header or &token=WEBHOOK_SECRET.
 * Raw events are stored first (idempotent on provider event id), then applied.
 */
define('NO_SESSION', true);
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('{"ok":false}');
}
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$sent = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : (string) ($_GET['token'] ?? '');
if (WEBHOOK_SECRET === '' || !hash_equals(WEBHOOK_SECRET, $sent)) {
    http_response_code(401);
    exit('{"ok":false,"error":"unauthorized"}');
}

$provider = in_array($_GET['provider'] ?? '', ['sendgrid', 'mailgun', 'ses', 'generic'], true) ? $_GET['provider'] : 'generic';
$body = (string) file_get_contents('php://input');
if (strlen($body) > 5 * 1024 * 1024) {
    http_response_code(413);
    exit('{"ok":false}');
}

$events = normalize_webhook($provider, $body);
$ids = store_webhook_events($provider, $events);
foreach (array_slice($ids, 0, 200) as $id) {
    process_webhook_event($id); // remaining events are handled by cron/process-webhooks.php
}
echo json_encode(['ok' => true, 'received' => count($events), 'new' => count($ids)]);
