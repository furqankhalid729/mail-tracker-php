<?php
declare(strict_types=1);

/**
 * Call leads: businesses imported from scraped lists (Google Maps exports, directories) for closers to phone.
 *   - Admins import and assign leads; closers see only leads assigned to them (managers and up see all).
 *   - A lead has several phones (lead_phones.number_key = last 10 digits, the same rule as closer_numbers).
 *   - Zoom calls are linked to leads by the external number; attempts / last call are cached on leads.
 *   - Statuses are defined per workspace by admins (lead_statuses); every status is a filter.
 */

const LEAD_DEFAULT_STATUSES = [
    ['New', '#64748b'], ['No answer', '#f59e0b'], ['Callback', '#0ea5e9'],
    ['Interested', '#6366f1'], ['Not interested', '#ef4444'], ['Won', '#10b981'],
];

/** Import / form targets. Several CSV columns may map to phones or emails; they are merged. */
const LEAD_FIELDS = [
    'name' => 'Business name',
    'phones' => 'Phone(s)',
    'emails' => 'Email(s)',
    'website' => 'Website',
    'address' => 'Address',
    'rating' => 'Rating',
    'reviews_count' => 'Reviews count',
    'maps_url' => 'Google Maps URL',
    'notes' => 'Notes',
];

const LEAD_FILTER_KEYS = ['q', 'status', 'assigned', 'attempts', 'last_call', 'connected', 'has_phone', 'has_email', 'has_website', 'min_rating', 'source', 'field', 'field_value'];

/* ---------------------------------------------------------------------------
 * Statuses
 * ------------------------------------------------------------------------- */

/** Workspace statuses in display order, keyed by id. A workspace without any gets the defaults. */
function lead_statuses(int $wsId, bool $fresh = false): array
{
    static $cache = [];
    if ($fresh || !isset($cache[$wsId])) {
        $sql = 'SELECT * FROM lead_statuses WHERE workspace_id = ? ORDER BY sort_order, id';
        $rows = q_all($sql, [$wsId]);
        if (!$rows) {
            foreach (LEAD_DEFAULT_STATUSES as $i => [$name, $color]) {
                q('INSERT IGNORE INTO lead_statuses (workspace_id, name, color, sort_order, created_at) VALUES (?, ?, ?, ?, ?)', [$wsId, $name, $color, $i + 1, now()]);
            }
            $rows = q_all($sql, [$wsId]);
        }
        $cache[$wsId] = array_column($rows, null, 'id');
    }
    return $cache[$wsId];
}

/** Status given to new leads when none is chosen: the first one in the list. */
function lead_default_status_id(int $wsId): ?int
{
    $first = array_key_first(lead_statuses($wsId));
    return $first === null ? null : (int) $first;
}

function lead_status_chip(?int $statusId): string
{
    $s = $statusId ? (lead_statuses(ws_id())[$statusId] ?? null) : null;
    return $s ? tag_chip($s) : '<span class="text-xs text-slate-400">No status</span>';
}

/* ---------------------------------------------------------------------------
 * Visibility
 * ------------------------------------------------------------------------- */

function can_view_all_leads(): bool
{
    return allowed('leads.view_all');
}

/** WHERE (alias l) limiting leads to what the current user may see. */
function lead_visibility_sql(): array
{
    if (can_view_all_leads()) {
        return ['l.workspace_id = ?', [ws_id()]];
    }
    return ['l.workspace_id = ? AND l.assigned_to = ?', [ws_id(), user_id()]];
}

function find_lead_or_404(int $id): array
{
    [$where, $params] = lead_visibility_sql();
    $lead = q_one(
        "SELECT l.*, a.name assignee_name FROM leads l LEFT JOIN users a ON a.id = l.assigned_to WHERE l.id = ? AND $where",
        [$id, ...$params]
    );
    if (!$lead) {
        abort(404, 'Lead not found.');
    }
    return $lead;
}

/** Whether the signed-in user should see the Leads menu. */
function can_see_leads(): bool
{
    return can_view_all_leads() || is_closer()
        || (bool) q_val('SELECT 1 FROM leads WHERE workspace_id = ? AND assigned_to = ? LIMIT 1', [ws_id(), user_id()]);
}

/* ---------------------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------------------- */

/** Sanitised filters from an array (the request, or the JSON a bulk action posts back). */
function lead_filters_from(array $in): array
{
    $f = [];
    foreach (LEAD_FILTER_KEYS as $k) {
        $v = $in[$k] ?? null;
        if (is_string($v) && ($v = trim($v)) !== '') {
            $f[$k] = mb_substr($v, 0, 200);
        }
    }
    if (isset($f['field']) && !isset($f['field_value'])) {
        unset($f['field']);
    }
    if (!can_view_all_leads()) {
        unset($f['assigned']);
    }
    return $f;
}

function lead_filters_from_request(): array
{
    return lead_filters_from(array_merge($_GET, $_POST));
}

/**
 * WHERE clause (alias l) for lead filters, always limited to what the user may see.
 * $withStatus = false leaves out the status filter (used for the per-status counts).
 */
function lead_filter_sql(array $f, bool $withStatus = true): array
{
    [$base, $params] = lead_visibility_sql();
    $where = [$base];

    if (!empty($f['q'])) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $f['q']) . '%';
        $or = 'l.name LIKE ? OR l.address LIKE ? OR l.emails LIKE ? OR l.website LIKE ?';
        array_push($params, $like, $like, $like, $like);
        $key = number_key($f['q']);
        if ($key && strlen($key) >= 4) {
            $or .= ' OR EXISTS (SELECT 1 FROM lead_phones p WHERE p.lead_id = l.id AND p.number_key LIKE ?)';
            $params[] = "%$key%";
        }
        $where[] = "($or)";
    }
    if ($withStatus && !empty($f['status'])) {
        if ($f['status'] === 'none') {
            $where[] = 'l.status_id IS NULL';
        } else {
            $where[] = 'l.status_id = ?';
            $params[] = (int) $f['status'];
        }
    }
    if (!empty($f['assigned'])) {
        if ($f['assigned'] === 'unassigned') {
            $where[] = 'l.assigned_to IS NULL';
        } else {
            $where[] = 'l.assigned_to = ?';
            $params[] = (int) $f['assigned'];
        }
    }
    $attempts = ['0' => 'l.total_attempts = 0', '1-2' => 'l.total_attempts BETWEEN 1 AND 2', '3-5' => 'l.total_attempts BETWEEN 3 AND 5', '6+' => 'l.total_attempts >= 6'];
    if (isset($f['attempts'], $attempts[$f['attempts']])) {
        $where[] = $attempts[$f['attempts']];
    }
    $lastCall = [
        'today' => ['l.last_call_at >= ?', date('Y-m-d 00:00:00')],
        '7d' => ['l.last_call_at >= ?', date('Y-m-d H:i:s', strtotime('-7 days'))],
        '30d' => ['l.last_call_at >= ?', date('Y-m-d H:i:s', strtotime('-30 days'))],
        'older7' => ['l.last_call_at < ?', date('Y-m-d H:i:s', strtotime('-7 days'))],
        'older30' => ['l.last_call_at < ?', date('Y-m-d H:i:s', strtotime('-30 days'))],
        'never' => ['l.last_call_at IS NULL', null],
    ];
    if (isset($f['last_call'], $lastCall[$f['last_call']])) {
        [$sql, $param] = $lastCall[$f['last_call']];
        $where[] = $sql;
        if ($param !== null) {
            $params[] = $param;
        }
    }
    if (($f['connected'] ?? '') === 'yes') {
        $where[] = 'l.connected_calls > 0';
    } elseif (($f['connected'] ?? '') === 'no') {
        $where[] = 'l.total_attempts > 0 AND l.connected_calls = 0';
    }
    $has = [
        'has_phone' => 'EXISTS (SELECT 1 FROM lead_phones p WHERE p.lead_id = l.id)',
        'has_email' => "(l.emails IS NOT NULL AND l.emails <> '')",
        'has_website' => "(l.website IS NOT NULL AND l.website <> '')",
    ];
    foreach ($has as $k => $sql) {
        if (($f[$k] ?? '') === '1') {
            $where[] = $sql;
        } elseif (($f[$k] ?? '') === '0') {
            $where[] = "NOT $sql";
        }
    }
    if (isset($f['min_rating']) && is_numeric($f['min_rating'])) {
        $where[] = 'l.rating >= ?';
        $params[] = (float) $f['min_rating'];
    }
    if (!empty($f['source'])) {
        $where[] = 'l.source = ?';
        $params[] = $f['source'];
    }
    if (!empty($f['field']) && isset($f['field_value']) && ($path = lead_extra_path($f['field']))) {
        $where[] = 'JSON_UNQUOTE(JSON_EXTRACT(l.extra_fields, ?)) LIKE ?';
        array_push($params, $path, '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $f['field_value']) . '%');
    }
    return [implode(' AND ', $where), $params];
}

/** Extra-field keys are sanitised at import ([A-Za-z0-9_.-]); anything else is rejected. */
function lead_extra_key(string $header, int $i): string
{
    return mb_substr(trim(preg_replace('/[^A-Za-z0-9_.-]+/', '_', $header), '_') ?: 'field_' . ($i + 1), 0, 50);
}

function lead_extra_path(string $key): ?string
{
    return preg_match('/^[A-Za-z0-9_.-]{1,50}$/', $key) ? '$."' . $key . '"' : null;
}

/** Extra-field names used in the workspace (sampled from recent leads), for the filter picker. */
function lead_extra_keys(int $wsId): array
{
    $keys = [];
    foreach (q_col('SELECT extra_fields FROM leads WHERE workspace_id = ? AND extra_fields IS NOT NULL ORDER BY id DESC LIMIT 300', [$wsId]) as $json) {
        foreach (array_keys(json_decode((string) $json, true) ?: []) as $k) {
            $keys[$k] = true;
        }
    }
    $keys = array_keys($keys);
    sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
    return $keys;
}

/* ---------------------------------------------------------------------------
 * Parsing (import and the edit form)
 * ------------------------------------------------------------------------- */

/** "(931) 436-6363; (931) 472-9026" → [[number, key], …], unique by key. Very short values are ignored. */
function lead_parse_phones(string ...$values): array
{
    $out = [];
    foreach ($values as $value) {
        foreach (preg_split('/[;,|\/\n]+/', $value) as $part) {
            $part = trim($part);
            $key = number_key($part);
            if ($key !== null && strlen($key) >= 7 && !isset($out[$key])) {
                $out[$key] = [mb_substr($part, 0, 40), $key];
            }
        }
    }
    return array_values($out);
}

/**
 * tel: link in one consistent format so click-to-call (Zoom, phones) dials correctly:
 * numbers already in +E.164 keep their country code, 10 digits are treated as North American (+1),
 * 11 digits starting with 1 get a +. Anything else (extensions, short codes) is dialled as typed.
 */
function lead_tel_href(string $number): string
{
    $digits = preg_replace('/\D+/', '', $number);
    if (str_starts_with(trim($number), '+')) {
        return 'tel:+' . $digits;
    }
    if (strlen($digits) === 10) {
        return 'tel:+1' . $digits;
    }
    if (strlen($digits) === 11 && $digits[0] === '1') {
        return 'tel:+' . $digits;
    }
    return 'tel:' . $digits;
}

/** Valid, unique, lower-cased emails from any number of "a@x.com; b@y.com" strings. */
function lead_parse_emails(string ...$values): array
{
    $out = [];
    foreach ($values as $value) {
        foreach (preg_split('/[;,\s]+/', $value) as $part) {
            $part = strtolower(trim($part, " \t<>\"'"));
            if ($part !== '' && is_valid_email($part)) {
                $out[$part] = true;
            }
        }
    }
    return array_keys($out);
}

/** Normalise a website; unwraps Google redirect links (google.com/url?q=…) from Maps exports. */
function lead_clean_website(?string $site): ?string
{
    $site = trim((string) $site);
    $host = strtolower((string) parse_url($site, PHP_URL_HOST));
    if (preg_match('/(^|\.)google\.[a-z.]+$/', $host) && parse_url($site, PHP_URL_PATH) === '/url') {
        parse_str((string) parse_url($site, PHP_URL_QUERY), $q);
        $site = (string) ($q['q'] ?? $q['url'] ?? '');
    }
    return normalize_website($site);
}

/** Column data for a lead from raw form / CSV values (phones and emails handled separately). */
function lead_data_from_input(array $in): array
{
    $rating = str_replace(',', '.', (string) ($in['rating'] ?? ''));
    $reviews = preg_replace('/\D+/', '', (string) ($in['reviews_count'] ?? ''));
    $maps = trim((string) ($in['maps_url'] ?? ''));
    return [
        'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 255),
        'website' => lead_clean_website($in['website'] ?? null),
        'address' => ($a = trim((string) ($in['address'] ?? ''))) !== '' ? mb_substr($a, 0, 500) : null,
        'rating' => is_numeric($rating) ? max(0, min(5, round((float) $rating, 1))) : null,
        'reviews_count' => $reviews !== '' ? min(4294967295, (int) $reviews) : null,
        'maps_url' => is_valid_http_url($maps) ? mb_substr($maps, 0, 2000) : null,
        'notes' => ($n = trim((string) ($in['notes'] ?? ''))) !== '' ? mb_substr($n, 0, 10000) : null,
    ];
}

/** A lead must have a name; fall back to the website host, first email or first phone. */
function lead_fallback_name(array $data, array $emails, array $phones): string
{
    if ($data['name'] !== '') {
        return $data['name'];
    }
    if ($data['website']) {
        return preg_replace('/^www\./', '', (string) parse_url($data['website'], PHP_URL_HOST));
    }
    return $emails[0] ?? ($phones[0][0] ?? '');
}

/** Replace a lead's phone numbers. $phones = lead_parse_phones() output. */
function lead_set_phones(int $wsId, int $leadId, array $phones): void
{
    q('DELETE FROM lead_phones WHERE lead_id = ? AND workspace_id = ?', [$leadId, $wsId]);
    foreach ($phones as [$number, $key]) {
        db_insert('lead_phones', ['workspace_id' => $wsId, 'lead_id' => $leadId, 'phone_number' => $number, 'number_key' => $key]);
    }
}

/** Phones for a set of leads: lead_id => [rows]. */
function phones_for_leads(array $leadIds): array
{
    if (!$leadIds) {
        return [];
    }
    $out = [];
    foreach (q_all('SELECT * FROM lead_phones WHERE lead_id IN (' . placeholders($leadIds) . ') ORDER BY id', $leadIds) as $p) {
        $out[$p['lead_id']][] = $p;
    }
    return $out;
}

/** Id of an existing lead with any of these numbers, or (when there are none) the same name and website. */
function lead_find_duplicate(int $wsId, string $name, ?string $website, array $phones): ?int
{
    if ($phones) {
        $keys = array_column($phones, 1);
        $id = q_val('SELECT lead_id FROM lead_phones WHERE workspace_id = ? AND number_key IN (' . placeholders($keys) . ') LIMIT 1', [$wsId, ...$keys]);
    } else {
        $id = q_val('SELECT id FROM leads WHERE workspace_id = ? AND name = ? AND website <=> ? LIMIT 1', [$wsId, $name, $website]);
    }
    return $id ? (int) $id : null;
}

/* ---------------------------------------------------------------------------
 * Timeline
 * ------------------------------------------------------------------------- */

function log_lead_activity(int $leadId, string $type, ?string $body = null, ?string $from = null, ?string $to = null): void
{
    db_insert('lead_activity', [
        'workspace_id' => ws_id(),
        'lead_id' => $leadId,
        'user_id' => user_id() ?: null,
        'type' => $type,
        'from_label' => $from,
        'to_label' => $to,
        'body' => $body,
        'created_at' => now(),
    ]);
}

/** Change a lead's status (and log it with an optional note). Returns false for an unknown status. */
function lead_change_status(array $lead, int $statusId, string $note = ''): bool
{
    $statuses = lead_statuses(ws_id());
    if (!isset($statuses[$statusId])) {
        return false;
    }
    if ((int) $lead['status_id'] !== $statusId) {
        db_update('leads', ['status_id' => $statusId, 'updated_at' => now()], 'id = ? AND workspace_id = ?', [$lead['id'], ws_id()]);
        $from = $lead['status_id'] ? ($statuses[$lead['status_id']]['name'] ?? null) : null;
        log_lead_activity((int) $lead['id'], 'status', $note !== '' ? $note : null, $from, $statuses[$statusId]['name']);
    } elseif ($note !== '') {
        log_lead_activity((int) $lead['id'], 'note', $note);
    }
    return true;
}

/** Assign (or unassign, $userId = null) many leads at once and log it on each. $where/$params select the leads (alias l). */
function leads_assign(string $where, array $params, ?int $userId): int
{
    $label = $userId ? (string) q_val('SELECT name FROM users WHERE id = ?', [$userId]) : 'Unassigned';
    $ids = array_map('intval', q_col("SELECT l.id FROM leads l WHERE $where AND NOT (l.assigned_to <=> ?)", [...$params, $userId]));
    foreach (array_chunk($ids, 1000) as $chunk) {
        $in = placeholders($chunk);
        q("UPDATE leads SET assigned_to = ?, updated_at = ? WHERE workspace_id = ? AND id IN ($in)", [$userId, now(), ws_id(), ...$chunk]);
        q(
            "INSERT INTO lead_activity (workspace_id, lead_id, user_id, type, to_label, created_at)
             SELECT workspace_id, id, ?, 'assigned', ?, ? FROM leads WHERE workspace_id = ? AND id IN ($in)",
            [user_id(), $label, now(), ws_id(), ...$chunk]
        );
    }
    return count($ids);
}

/* ---------------------------------------------------------------------------
 * Zoom calls → leads
 * ------------------------------------------------------------------------- */

/**
 * Link Zoom calls to leads by the external number, then refresh the cached call stats.
 * $leadIds = null: link every unlinked call in the workspace (after a Zoom sync).
 * $leadIds = [...]: relink just these leads (after an import or a phone edit).
 */
function leads_link_calls(int $wsId, ?array $leadIds = null): void
{
    if ($leadIds === null) {
        q(
            'UPDATE zoom_calls c JOIN lead_phones p ON p.workspace_id = c.workspace_id AND p.number_key = c.external_key
             SET c.lead_id = p.lead_id WHERE c.workspace_id = ? AND c.lead_id IS NULL',
            [$wsId]
        );
        leads_refresh_stats($wsId);
        return;
    }
    foreach (array_chunk(array_values(array_unique(array_map('intval', $leadIds))), 500) as $chunk) {
        $in = placeholders($chunk);
        q("UPDATE zoom_calls SET lead_id = NULL WHERE workspace_id = ? AND lead_id IN ($in)", [$wsId, ...$chunk]);
        q(
            "UPDATE zoom_calls c JOIN lead_phones p ON p.workspace_id = c.workspace_id AND p.number_key = c.external_key
             SET c.lead_id = p.lead_id WHERE c.workspace_id = ? AND c.lead_id IS NULL AND p.lead_id IN ($in)",
            [$wsId, ...$chunk]
        );
        leads_refresh_stats($wsId, $chunk);
    }
}

/**
 * Recompute attempts (outbound calls), connected calls (any direction), and the last call's time and result.
 * Restricted to $leadIds when given.
 */
function leads_refresh_stats(int $wsId, ?array $leadIds = null): void
{
    $only = $leadIds ? ' AND lead_id IN (' . placeholders($leadIds) . ')' : '';
    $onlyL = $leadIds ? ' AND l.id IN (' . placeholders($leadIds) . ')' : '';
    q(
        "UPDATE leads l
         LEFT JOIN (
            SELECT lead_id, SUM(COALESCE(direction, '') <> 'inbound') attempts, SUM(is_connected) connected, MAX(start_time) last_at
            FROM zoom_calls WHERE workspace_id = ? AND lead_id IS NOT NULL$only GROUP BY lead_id
         ) s ON s.lead_id = l.id
         SET l.total_attempts = COALESCE(s.attempts, 0),
             l.connected_calls = COALESCE(s.connected, 0),
             l.last_call_at = s.last_at,
             l.last_call_result = IF(s.last_at IS NULL, NULL, (
                SELECT COALESCE(z.call_result, IF(z.is_connected = 1, 'connected', NULL))
                FROM zoom_calls z WHERE z.lead_id = l.id ORDER BY z.start_time DESC, z.id DESC LIMIT 1
             ))
         WHERE l.workspace_id = ?$onlyL",
        [$wsId, ...($leadIds ?: []), $wsId, ...($leadIds ?: [])]
    );
}

function lead_last_call_badge(array $lead): string
{
    if (!$lead['last_call_at']) {
        return '<span class="text-xs text-slate-400">Never called</span>';
    }
    $result = $lead['last_call_result'];
    return '<span class="block whitespace-nowrap text-xs text-slate-600">' . e(time_ago($lead['last_call_at'])) . '</span>'
        . call_result_badge($result, in_array($result, ZOOM_CONNECTED_RESULTS, true));
}
