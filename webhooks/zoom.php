<?php
/**
 * Zoom Phone webhook receiver (who ended each outbound call).
 *   POST /webhooks/zoom.php   configured in the Zoom app → Features → Event Subscriptions
 * Every request is verified with the app's Secret Token (ZOOM_WEBHOOK_SECRET):
 *   x-zm-signature = "v0=" . HMAC-SHA256(secret, "v0:{x-zm-request-timestamp}:{raw body}")
 * Handles Zoom's endpoint.url_validation challenge, stores the events we use (see ZOOM_HANGUP_EVENTS)
 * and updates zoom_calls.ended_by. Zoom expects a 2xx within 3 seconds.
 */
define('NO_SESSION', true);
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('{"ok":false}');
}
$body = (string) file_get_contents('php://input');
if (strlen($body) > 1024 * 1024) {
    http_response_code(413);
    exit('{"ok":false}');
}
if (!zoom_webhook_signature_ok($body, (string) ($_SERVER['HTTP_X_ZM_REQUEST_TIMESTAMP'] ?? ''), (string) ($_SERVER['HTTP_X_ZM_SIGNATURE'] ?? ''))) {
    http_response_code(401);
    exit('{"ok":false,"error":"invalid signature"}');
}
$event = json_decode($body, true);
if (!is_array($event)) {
    http_response_code(400);
    exit('{"ok":false}');
}

// Zoom checks the endpoint when it is saved (and periodically): echo the token, hashed with the secret
if (($event['event'] ?? '') === 'endpoint.url_validation') {
    $plain = (string) ($event['payload']['plainToken'] ?? '');
    echo json_encode(['plainToken' => $plain, 'encryptedToken' => hash_hmac('sha256', $plain, ZOOM_WEBHOOK_SECRET)]);
    exit;
}

$recorded = zoom_record_call_event($event);
foreach ($recorded as $wsId => $callIds) {
    zoom_resolve_hangups($wsId, $callIds);
}
echo json_encode(['ok' => true, 'calls' => array_sum(array_map('count', $recorded))]);
