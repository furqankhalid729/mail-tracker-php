<?php
/** Open-tracking pixel. Always returns a 1x1 GIF, even for unknown tokens (no information leak). */
define('NO_SESSION', true);
require_once __DIR__ . '/../includes/init.php';

try {
    $message = find_message_by_token((string) ($_GET['token'] ?? ''));
    if ($message && $message['sent_at'] && (int) q_val('SELECT track_opens FROM workspaces WHERE id = ?', [$message['workspace_id']]) === 1) {
        track_open($message);
    }
} catch (Throwable $e) {
    app_log('error', 'Open tracking failed', ['error' => $e->getMessage()]);
}
output_pixel();
