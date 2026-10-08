<?php
// Fake Zoom (OAuth + Phone call history) for LOCAL TESTING ONLY. Never deploy settings that point at it.
// Run from the project root:  php -S 127.0.0.1:8099 scripts/mock-zoom/router.php
// .env: ZOOM_CLIENT_ID=test-client  ZOOM_CLIENT_SECRET=test-secret  ZOOM_OAUTH_BASE=http://127.0.0.1:8099  ZOOM_API_BASE=http://127.0.0.1:8099/v2
// Sample calls use the lines +1 408 533 3518, +1 408 533 3519 and +1 415 555 0100 (assign these to closers).
$state = __DIR__ . '/state.json';
$s = is_file($state) ? json_decode(file_get_contents($state), true) : ['refresh' => null, 'access' => null, 'refreshes' => 0, 'requests' => []];
$save = function () use (&$s, $state) { file_put_contents($state, json_encode($s)); };
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$json = function ($data, $code = 200) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data); exit; };

if ($path === '/oauth/authorize') {
    // Pretend the admin approved
    header('Location: ' . $_GET['redirect_uri'] . '?code=good-code&state=' . urlencode($_GET['state']));
    exit;
}
if ($path === '/oauth/token') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth !== 'Basic ' . base64_encode('test-client:test-secret')) {
        $json(['reason' => 'Invalid client_id or client_secret', 'error' => 'invalid_client'], 401);
    }
    $g = $_POST['grant_type'] ?? '';
    if ($g === 'authorization_code' && ($_POST['code'] ?? '') !== 'good-code') {
        $json(['reason' => 'Invalid authorization code', 'error' => 'invalid_request'], 400);
    }
    if ($g === 'refresh_token') {
        if (($_POST['refresh_token'] ?? '') !== $s['refresh']) {
            $json(['reason' => 'Invalid Token!', 'error' => 'invalid_request'], 400);
        }
        $s['refreshes']++;
    }
    $s['access'] = 'acc-' . bin2hex(random_bytes(4));
    $s['refresh'] = 'ref-' . bin2hex(random_bytes(4)); // rotated every time
    $save();
    $json(['access_token' => $s['access'], 'refresh_token' => $s['refresh'], 'expires_in' => (int) (getenv('MOCK_EXPIRES') ?: 3599), 'token_type' => 'bearer']);
}

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . $s['access']) {
    $json(['code' => 124, 'message' => 'Invalid access token.'], 401);
}
if ($path === '/v2/users/me') {
    $json(['email' => 'zoomadmin@example.com', 'account_id' => 'ACC123']);
}
if ($path === '/v2/phone/call_history') {
    $s['requests'][] = $_GET;
    $save();
    // Deterministic fake calls: 6 per day over the requested range
    $calls = [];
    $lines = ['+1 (408) 533-3518', '+14085333519', '+14155550100'];
    $results = ['answered', 'no_answer', 'answered', 'voicemail', 'answered', 'missed'];
    for ($d = strtotime($_GET['from']); $d <= strtotime($_GET['to']); $d += 86400) {
        for ($i = 0; $i < 6; $i++) {
            $out = $i % 2 === 0;
            $line = $lines[$i % 3];
            $other = '+1212555' . sprintf('%04d', ($i * 7 + (int) date('j', $d)) % 50);
            $res = $results[$i];
            $start = gmdate('Y-m-d', $d) . 'T' . sprintf('%02d', 14 + $i) . ':0' . $i . ':00Z';
            $dur = $res === 'answered' ? 60 + $i * 45 : 0;
            $calls[] = [
                'call_history_uuid' => gmdate('Ymd', $d) . "-call-$i",
                'call_id' => gmdate('Ymd', $d) . "00$i",
                'direction' => $out ? 'outbound' : 'inbound',
                'connect_type' => 'external', 'call_type' => 'general',
                'caller_name' => $out ? 'Closer line' : 'Prospect ' . $i,
                'caller_did_number' => $out ? $line : $other,
                'callee_name' => $out ? 'Prospect ' . $i : 'Closer line',
                'callee_did_number' => $out ? $other : $line,
                'start_time' => $start,
                'answer_time' => $dur ? $start : null,
                'end_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime($start) + $dur + 20),
                'duration' => $dur,
                'call_result' => $res,
            ];
        }
    }
    $size = 25; // small pages to exercise pagination
    $offset = (int) ($_GET['next_page_token'] ?? 0);
    $page = array_slice($calls, $offset, $size);
    $next = $offset + $size < count($calls) ? (string) ($offset + $size) : '';
    $json(['from' => $_GET['from'], 'to' => $_GET['to'], 'page_size' => $size, 'total_records' => count($calls), 'next_page_token' => $next, 'call_history' => $page]);
}
$json(['message' => 'not found'], 404);
