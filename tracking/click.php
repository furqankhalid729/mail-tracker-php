<?php
/**
 * Click tracking redirect.
 * The destination is HMAC-signed together with the message token when the email is sent, so this endpoint
 * can only redirect to URLs that were actually in one of our emails (no open redirect). Only http(s) allowed.
 */
define('NO_SESSION', true);
require_once __DIR__ . '/../includes/init.php';

$token = (string) ($_GET['t'] ?? '');
$dest = (string) ($_GET['u'] ?? '');
$sig = (string) ($_GET['s'] ?? '');

$validScheme = is_valid_http_url($dest);
$validSig = $token !== '' && $sig !== '' && hash_equals(sign_value($token . '|' . $dest), $sig);

if (!$validScheme || !$validSig) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Invalid link</title><body style="font-family:system-ui;padding:40px;text-align:center;color:#334155"><h1 style="font-size:20px">This link is invalid or has expired.</h1></body>';
    exit;
}

try {
    $message = find_message_by_token($token);
    if ($message && (int) q_val('SELECT track_clicks FROM workspaces WHERE id = ?', [$message['workspace_id']]) === 1) {
        track_click($message, $dest);
    }
} catch (Throwable $e) {
    app_log('error', 'Click tracking failed', ['error' => $e->getMessage()]);
}

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('Location: ' . $dest, true, 302);
exit;
