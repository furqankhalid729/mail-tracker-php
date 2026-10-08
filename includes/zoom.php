<?php
declare(strict_types=1);

/**
 * Zoom Phone call history over OAuth 2.0 (account-level app, plain cURL, no SDK).
 *   - An admin connects once; tokens are encrypted at rest and refreshed server-side.
 *   - Cron pulls GET /phone/call_history in date windows and upserts into zoom_calls.
 *   - Calls are attributed to closers by matching caller/callee numbers against closer_numbers,
 *     and linked to call leads by the external number (lead_phones), which refreshes attempts / last call.
 * Zoom rotates refresh tokens: every refresh returns a new one that must be stored before the next call,
 * so all token use for a workspace happens under one named lock (see zoom_sync()).
 */

const ZOOM_SCOPES_NEEDED = 'phone:read:list_call_logs:admin';
const ZOOM_BACKFILL_DAYS = 90;        // history fetched on first sync
const ZOOM_WINDOW_DAYS = 30;          // max date range per request
const ZOOM_OVERLAP_DAYS = 2;          // re-read recent days: Zoom can add records late
// call_result values that count as a conversation (anything else = no answer, voicemail, missed, busy…)
const ZOOM_CONNECTED_RESULTS = ['answered', 'connected', 'call_connected', 'accepted', 'recorded', 'auto_recorded'];

function zoom_enabled(): bool
{
    return ZOOM_CLIENT_ID !== '' && ZOOM_CLIENT_SECRET !== '';
}

function zoom_redirect_uri(): string
{
    return url('calls/zoom.php');
}

function zoom_auth_url(string $state): string
{
    return ZOOM_OAUTH_BASE . '/oauth/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => ZOOM_CLIENT_ID,
        'redirect_uri' => zoom_redirect_uri(),
        'state' => $state,
    ]);
}

/** POST /oauth/token with client credentials in a Basic header. */
function zoom_token_request(array $params): array
{
    [$status, $json] = http_request('POST', ZOOM_OAUTH_BASE . '/oauth/token', [
        'Authorization: Basic ' . base64_encode(ZOOM_CLIENT_ID . ':' . ZOOM_CLIENT_SECRET),
        'Content-Type: application/x-www-form-urlencoded',
    ], $params);
    if ($status !== 200 || empty($json['access_token'])) {
        throw new RuntimeException('Zoom rejected the request: ' . ($json['reason'] ?? $json['error_description'] ?? $json['error'] ?? "HTTP $status"));
    }
    return $json;
}

function zoom_connection(int $wsId): ?array
{
    return q_one('SELECT * FROM zoom_connections WHERE workspace_id = ?', [$wsId]);
}

function zoom_save_tokens(array &$conn, array $tokens): void
{
    $conn['encrypted_access_token'] = encrypt_value($tokens['access_token']);
    if (!empty($tokens['refresh_token'])) {
        $conn['encrypted_refresh_token'] = encrypt_value($tokens['refresh_token']);
    }
    $conn['token_expires_at'] = date('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 3600));
    db_update('zoom_connections', [
        'encrypted_access_token' => $conn['encrypted_access_token'],
        'encrypted_refresh_token' => $conn['encrypted_refresh_token'],
        'token_expires_at' => $conn['token_expires_at'],
        'updated_at' => now(),
    ], 'id = ?', [$conn['id']]);
}

/** Valid access token; refreshes (and stores the rotated refresh token) when it is about to expire. */
function zoom_access_token(array &$conn, bool $force = false): string
{
    if (!$force && $conn['encrypted_access_token'] && strtotime((string) $conn['token_expires_at']) > time() + 300) {
        return (string) decrypt_value($conn['encrypted_access_token']);
    }
    $refresh = decrypt_value($conn['encrypted_refresh_token']);
    if (!$refresh) {
        throw new RuntimeException('Zoom is not connected. Reconnect it from Calls → Zoom settings.');
    }
    try {
        $tokens = zoom_token_request(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
    } catch (RuntimeException $e) {
        // Revoked app, expired (90 days unused) or already-rotated token: only a new authorization fixes it
        throw new RuntimeException('Zoom access expired or was revoked, so reconnect Zoom. (' . $e->getMessage() . ')', 0, $e);
    }
    zoom_save_tokens($conn, $tokens);
    return $tokens['access_token'];
}

/** GET a Zoom API path. Retries once with a fresh token on 401. */
function zoom_api(array &$conn, string $path, array $query = []): array
{
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $token = zoom_access_token($conn, $attempt > 0);
        [$status, $json, $raw] = http_request('GET', ZOOM_API_BASE . $path . ($query ? '?' . http_build_query($query) : ''), [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ], null, 30);
        if ($status === 401 && $attempt === 0) {
            continue;
        }
        if ($status === 429) {
            throw new RuntimeException('Zoom rate limit reached; the next cron run will continue.');
        }
        if ($status < 200 || $status >= 300) {
            $msg = $json['message'] ?? mb_substr($raw, 0, 200);
            if ($status === 400 && str_contains((string) $msg, 'scope')) {
                $msg .= ' (the Zoom app needs the scope ' . ZOOM_SCOPES_NEEDED . ')';
            }
            throw new RuntimeException("Zoom API $path failed (HTTP $status): $msg");
        }
        return is_array($json) ? $json : [];
    }
    throw new RuntimeException('Zoom authorization failed. Reconnect Zoom.');
}

/** Comparable phone key: digits only, last 10 for full numbers so "+1 (408) 533-3518" and "4085333518" match. */
function number_key(?string $number): ?string
{
    $digits = preg_replace('/\D+/', '', (string) $number);
    if ($digits === '') {
        return null;
    }
    return strlen($digits) > 10 ? substr($digits, -10) : $digits;
}

/** Zoom timestamps are UTC ISO-8601; store them in the app timezone like the rest of the app. */
function zoom_parse_time(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    try {
        return (new DateTime($value))->setTimezone(new DateTimeZone(APP_TIMEZONE))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

/** Upsert one call-history record. $customers maps number_key => customer id. */
function zoom_store_call(int $wsId, array $c, array $customers): bool
{
    $zoomId = (string) ($c['call_history_uuid'] ?? $c['id'] ?? $c['call_id'] ?? '');
    $start = zoom_parse_time($c['start_time'] ?? $c['date_time'] ?? null);
    if ($zoomId === '' || !$start) {
        return false;
    }
    $direction = strtolower((string) ($c['direction'] ?? ''));
    $callerNumber = $c['caller_did_number'] ?? $c['caller_number'] ?? null;
    $calleeNumber = $c['callee_did_number'] ?? $c['callee_number'] ?? null;
    $external = $direction === 'outbound' ? $calleeNumber : $callerNumber;
    $result = strtolower(str_replace(' ', '_', (string) ($c['call_result'] ?? $c['result'] ?? '')));
    $duration = max(0, (int) ($c['duration'] ?? 0));
    $connected = in_array($result, ZOOM_CONNECTED_RESULTS, true) || ($result === '' && $duration > 0);
    $externalKey = number_key($external);
    $now = now();

    $row = [
        'workspace_id' => $wsId,
        'zoom_id' => mb_substr($zoomId, 0, 100),
        'call_id' => isset($c['call_id']) ? mb_substr((string) $c['call_id'], 0, 64) : null,
        'direction' => $direction ?: null,
        'connect_type' => $c['connect_type'] ?? null,
        'call_type' => $c['call_type'] ?? null,
        'call_result' => $result ?: null,
        'is_connected' => $connected ? 1 : 0,
        'caller_name' => isset($c['caller_name']) ? mb_substr((string) $c['caller_name'], 0, 190) : null,
        'caller_number' => $callerNumber,
        'caller_ext' => $c['caller_ext_number'] ?? null,
        'caller_key' => number_key($callerNumber),
        'callee_name' => isset($c['callee_name']) ? mb_substr((string) $c['callee_name'], 0, 190) : null,
        'callee_number' => $calleeNumber,
        'callee_ext' => $c['callee_ext_number'] ?? null,
        'callee_key' => number_key($calleeNumber),
        'external_number' => $external,
        'external_key' => $externalKey,
        'start_time' => $start,
        'answer_time' => zoom_parse_time($c['answer_time'] ?? null),
        'end_time' => zoom_parse_time($c['end_time'] ?? null),
        'duration' => $duration,
        'customer_id' => $externalKey !== null ? ($customers[$externalKey] ?? null) : null,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $cols = array_keys($row);
    $update = implode(', ', array_map(fn($k) => "`$k` = VALUES(`$k`)", array_diff($cols, ['workspace_id', 'zoom_id', 'created_at'])));
    q(
        'INSERT INTO zoom_calls (`' . implode('`, `', $cols) . '`) VALUES (' . placeholders($cols) . ") ON DUPLICATE KEY UPDATE $update",
        array_values($row)
    );
    return true;
}

/** Recompute which closer owns each call from the current number assignments. */
function zoom_reattribute(int $wsId): void
{
    q('UPDATE zoom_calls SET closer_user_id = NULL, closer_number_key = NULL WHERE workspace_id = ?', [$wsId]);
    // Closer side first (DID numbers), then extensions
    foreach (['caller_key', 'callee_key', 'caller_ext', 'callee_ext'] as $col) {
        q(
            "UPDATE zoom_calls c JOIN closer_numbers n ON n.workspace_id = c.workspace_id AND n.number_key = c.$col
             SET c.closer_user_id = n.user_id, c.closer_number_key = n.number_key
             WHERE c.workspace_id = ? AND c.closer_user_id IS NULL",
            [$wsId]
        );
    }
}

/**
 * Pull call history for one workspace, oldest unsynced window first, within $budget seconds.
 * Returns counts. Safe to call from cron and from the "Sync now" button at the same time.
 */
function zoom_sync(int $wsId, int $budget = 40): array
{
    $lock = 'mailcrm:' . DB_DATABASE . ':zoom:' . $wsId;
    if ((int) q_val('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
        return ['skipped' => 'another sync is running'];
    }
    $started = time();
    $stats = ['fetched' => 0, 'windows' => 0];
    try {
        $conn = zoom_connection($wsId);
        if (!$conn || $conn['status'] === 'disconnected') {
            return ['skipped' => 'not connected'];
        }
        $customers = [];
        foreach (q_all("SELECT id, phone FROM customers WHERE workspace_id = ? AND phone IS NOT NULL AND phone <> ''", [$wsId]) as $cu) {
            if ($k = number_key($cu['phone'])) {
                $customers[$k] ??= (int) $cu['id'];
            }
        }

        $today = date('Y-m-d');
        $from = $conn['synced_until']
            ? date('Y-m-d', strtotime($conn['synced_until'] . ' -' . ZOOM_OVERLAP_DAYS . ' days'))
            : date('Y-m-d', strtotime('-' . ZOOM_BACKFILL_DAYS . ' days'));

        while ($from <= $today && time() - $started < $budget) {
            $to = min($today, date('Y-m-d', strtotime($from . ' +' . (ZOOM_WINDOW_DAYS - 1) . ' days')));
            $pageToken = '';
            do {
                $query = ['from' => $from, 'to' => $to, 'page_size' => 300];
                if ($pageToken !== '') {
                    $query['next_page_token'] = $pageToken;
                }
                $page = zoom_api($conn, '/phone/call_history', $query);
                // The response array was renamed from call_logs to call_history (Nov 2025); read either
                foreach ($page['call_history'] ?? $page['call_logs'] ?? [] as $item) {
                    if (is_array($item) && zoom_store_call($wsId, $item, $customers)) {
                        $stats['fetched']++;
                    }
                }
                $pageToken = (string) ($page['next_page_token'] ?? '');
            } while ($pageToken !== '' && time() - $started < $budget + 15);

            if ($pageToken !== '') {
                break; // ran out of time mid-window: redo this window next run
            }
            $stats['windows']++;
            q('UPDATE zoom_connections SET synced_until = ?, updated_at = ? WHERE id = ?', [$to, now(), $conn['id']]);
            $from = date('Y-m-d', strtotime($to . ' +1 day'));
        }

        zoom_reattribute($wsId);
        leads_link_calls($wsId);
        q("UPDATE zoom_connections SET status = 'active', last_error = NULL, last_sync_at = ?, updated_at = ? WHERE id = ?", [now(), now(), $conn['id']]);
        return $stats;
    } catch (Throwable $e) {
        q("UPDATE zoom_connections SET status = 'error', last_error = ?, last_sync_at = ?, updated_at = ? WHERE workspace_id = ?", [mb_substr($e->getMessage(), 0, 500), now(), now(), $wsId]);
        throw $e;
    } finally {
        q('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/* ---------------------------------------------------------------------------
 * Reporting
 * ------------------------------------------------------------------------- */

/** Date range + filters from the query string. Closers are always scoped to themselves. */
function calls_filters(): array
{
    $presets = [
        'today' => [date('Y-m-d'), date('Y-m-d')],
        'yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
        '7d' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
        '30d' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
        'month' => [date('Y-m-01'), date('Y-m-d')],
        'last_month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
    ];
    $range = (string) input('range', '7d');
    $isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    if ($range === 'custom' && $isDate(input('from')) && $isDate(input('to'))) {
        [$from, $to] = [min(input('from'), input('to')), max(input('from'), input('to'))];
    } else {
        $range = isset($presets[$range]) ? $range : '7d';
        [$from, $to] = $presets[$range];
    }

    $closer = '';
    if (!allowed('calls.view_all')) {
        $closer = (string) user_id();
    } elseif (input('closer') === 'unassigned' || (int) input('closer') > 0) {
        $closer = (string) input('closer');
    }
    return [
        'range' => $range,
        'from' => $from,
        'to' => $to,
        'closer' => $closer,
        'direction' => in_array(input('direction'), ['inbound', 'outbound'], true) ? (string) input('direction') : '',
        'result' => in_array(input('result'), ['connected', 'not_connected'], true) ? (string) input('result') : '',
        'q' => trim((string) input('q')),
    ];
}

/** WHERE clause (alias c) for zoom_calls matching the filters. */
function calls_where(array $f): array
{
    $where = 'c.workspace_id = ? AND c.start_time >= ? AND c.start_time < ?';
    $params = [ws_id(), $f['from'] . ' 00:00:00', date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00'];
    if ($f['closer'] === 'unassigned') {
        $where .= ' AND c.closer_user_id IS NULL';
    } elseif ($f['closer'] !== '') {
        $where .= ' AND c.closer_user_id = ?';
        $params[] = (int) $f['closer'];
    }
    if ($f['direction']) {
        $where .= ' AND c.direction = ?';
        $params[] = $f['direction'];
    }
    if ($f['result'] === 'connected') {
        $where .= ' AND c.is_connected = 1';
    } elseif ($f['result'] === 'not_connected') {
        $where .= ' AND c.is_connected = 0';
    }
    if ($f['q'] !== '') {
        $key = number_key($f['q']);
        $where .= ' AND (c.caller_name LIKE ? OR c.callee_name LIKE ?' . ($key ? ' OR c.external_key LIKE ? OR c.closer_number_key LIKE ?' : '') . ')';
        $params = [...$params, "%{$f['q']}%", "%{$f['q']}%", ...($key ? ["%$key%", "%$key%"] : [])];
    }
    return [$where, $params];
}

/** SELECT list of the KPI aggregates, shared by totals, per-day and per-closer queries. */
const CALL_KPI_SQL = "COUNT(*) calls,
    COALESCE(SUM(c.direction = 'outbound'), 0) outbound,
    COALESCE(SUM(c.direction = 'inbound'), 0) inbound,
    COALESCE(SUM(c.is_connected), 0) connected,
    COALESCE(SUM(c.direction = 'inbound' AND c.is_connected = 0), 0) missed,
    COALESCE(SUM(CASE WHEN c.is_connected = 1 THEN c.duration END), 0) talk_seconds,
    COALESCE(MAX(c.duration), 0) longest,
    COUNT(DISTINCT c.external_key) contacts";

function format_duration(int $seconds): string
{
    if ($seconds <= 0) {
        return '0s';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h ? sprintf('%dh %02dm', $h, $m) : ($m ? sprintf('%dm %02ds', $m, $s) : $s . 's');
}

function call_result_badge(?string $result, bool $connected): string
{
    $label = $result ? ucfirst(str_replace('_', ' ', $result)) : ($connected ? 'Connected' : 'Unknown');
    return '<span class="badge badge-' . ($connected ? 'green' : (in_array($result, ['voicemail', 'no_answer', 'missed'], true) ? 'amber' : 'slate')) . ' !normal-case">' . e($label) . '</span>';
}

/** Whether the signed-in user should see the Calls menu. */
function can_see_calls(): bool
{
    return allowed('calls.view_all') || is_closer()
        || (bool) q_val('SELECT 1 FROM closer_numbers WHERE workspace_id = ? AND user_id = ? LIMIT 1', [ws_id(), user_id()]);
}
