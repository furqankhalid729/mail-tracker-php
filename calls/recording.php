<?php
/**
 * Play a Zoom Phone call recording: GET ?id=<zoom_calls.id>. Nothing is stored on this server.
 * Zoom's download_url needs our OAuth token, so we request it here. When Zoom answers with a redirect to a
 * short-lived signed file URL (the usual case) the browser is sent straight there; otherwise the audio is
 * streamed through, passing the browser's Range header so seeking works.
 * Access: anyone who can see the call (calls.view_all, the call's closer) or the lead it belongs to.
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
$ws = ws_id();

$call = q_one('SELECT * FROM zoom_calls WHERE id = ? AND workspace_id = ?', [input_int('id'), $ws]);
$canSee = $call && (
    allowed('calls.view_all')
    || (int) $call['closer_user_id'] === user_id()
    || ($call['lead_id'] && (function () use ($call) {
        [$where, $params] = lead_visibility_sql();
        return (bool) q_val("SELECT 1 FROM leads l WHERE l.id = ? AND $where", [$call['lead_id'], ...$params]);
    })())
);
if (!$canSee || empty($call['recording_url'])) {
    abort(404, 'Recording not found.');
}
$conn = zoom_connection($ws);
if (!$conn || $conn['status'] === 'disconnected') {
    abort(404, 'Zoom is not connected.');
}
session_write_close(); // don't hold the session lock while audio streams

$range = isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=[\d,\s-]+$/', $_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;

/** One request to Zoom; streams a 2xx body straight to the browser. Returns [status, Location header, error body]. */
$fetch = function (string $token, ?string $range) use ($call): array {
    $status = 0;
    $location = '';
    $errorBody = '';
    $sentHeaders = false;
    $passHeaders = [];
    $ch = curl_init($call['recording_url']);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => array_filter([
            'Authorization: Bearer ' . $token,
            $range ? 'Range: ' . $range : null,
        ]),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$status, &$location, &$passHeaders) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $passHeaders = [];
            } elseif (preg_match('/^(location|content-type|content-length|content-range|accept-ranges):\s*(.+)$/i', trim($line), $m)) {
                if (strtolower($m[1]) === 'location') {
                    $location = trim($m[2]);
                } else {
                    $passHeaders[] = ucwords(strtolower($m[1]), '-') . ': ' . trim($m[2]);
                }
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$status, &$sentHeaders, &$passHeaders, &$errorBody) {
            if ($status < 200 || $status >= 300) {
                $errorBody = mb_substr($errorBody . $chunk, 0, 1000); // keep Zoom's reason for the log
                return strlen($chunk);
            }
            if (!$sentHeaders) {
                http_response_code($status);
                foreach ($passHeaders as $h) {
                    header($h);
                }
                header('Cache-Control: private, no-store');
                $sentHeaders = true;
            }
            echo $chunk;
            flush();
            return connection_aborted() ? 0 : strlen($chunk);
        },
    ]);
    curl_exec($ch);
    curl_close($ch);
    return [$status, $location, $errorBody];
};

try {
    $token = zoom_access_token($conn);
    [$status, $location, $errorBody] = $fetch($token, $range);
    if ($status === 401) {
        $token = zoom_access_token($conn, true);
        [$status, $location, $errorBody] = $fetch($token, $range);
    }
    if ($status === 400 && $range) {
        [$status, $location, $errorBody] = $fetch($token, null); // Zoom rejects some Range requests
    }
} catch (Throwable $e) {
    app_log('error', 'Zoom recording: ' . $e->getMessage(), ['call' => $call['id']]);
    abort(502, 'Could not reach Zoom.');
}

if ($status >= 300 && $status < 400 && str_starts_with($location, 'https://')) {
    header('Cache-Control: private, no-store');
    header('Location: ' . $location, true, 302);
    exit;
}
if ($status < 200 || $status >= 300) {
    $reason = $errorBody !== '' ? (json_decode($errorBody, true)['message'] ?? trim(strip_tags($errorBody))) : '';
    app_log('warning', "Zoom recording download failed (HTTP $status)", ['call' => $call['id'], 'url' => $call['recording_url'], 'zoom' => $errorBody]);
    abort($status === 404 ? 404 : 502, $status === 404 ? 'Zoom no longer has this recording.' : "Zoom refused the recording (HTTP $status)" . ($reason !== '' ? ': ' . mb_substr($reason, 0, 300) : ". Check the Zoom app's recording scopes."));
}
exit;
