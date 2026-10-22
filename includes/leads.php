<?php
declare(strict_types=1);

/**
 * Call leads: businesses imported from scraped lists (Google Maps exports, directories) for closers to phone.
 *   - Admins import and assign leads; closers see only leads assigned to them (managers and up see all).
 *   - A lead has several phones (lead_phones.number_key = last 10 digits, the same rule as closer_numbers).
 *   - Zoom calls are linked to leads by the external number; attempts / last call are cached on leads.
 *   - Statuses are defined per workspace by admins (lead_statuses); every status is a filter.
 *   - Statuses with requires_approval (e.g. "Appointment booked") put a lead into approval_status = pending;
 *     a Super Admin (leads.approve) approves or disapproves it. Leaving the status clears the approval.
 */

/** [name, color, requires_date, in_pending] */
const LEAD_DEFAULT_STATUSES = [
    ['New', '#64748b', 0, 0], ['No answer', '#f59e0b', 0, 0], ['Callback', '#0ea5e9', 1, 1],
    ['Interested', '#6366f1', 0, 1], ['Not interested', '#ef4444', 0, 0], ['Won', '#10b981', 0, 0],
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

const LEAD_FILTER_KEYS = ['q', 'status', 'approval', 'assigned', 'attempts', 'last_call', 'connected', 'has_phone', 'has_email', 'has_website', 'min_rating', 'source', 'field', 'field_value', 'tz'];

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
            foreach (LEAD_DEFAULT_STATUSES as $i => [$name, $color, $requiresDate, $inPending]) {
                q('INSERT IGNORE INTO lead_statuses (workspace_id, name, color, requires_date, in_pending, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$wsId, $name, $color, $requiresDate, $inPending, $i + 1, now()]);
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
 * Approval (statuses with requires_approval, decided by a Super Admin)
 *   lead_approvals keeps one row per booking (who booked it, when, the decision) for the monthly report;
 *   leads.approval_* copies the current request's state for filters and badges.
 * ------------------------------------------------------------------------- */

const LEAD_APPROVAL_LABELS = ['pending' => 'Awaiting approval', 'approved' => 'Approved', 'rejected' => 'Disapproved'];

/** Whether a status name looks like "Appointment booked" (new statuses with such names need approval by default). */
function lead_status_name_needs_approval(string $name): bool
{
    $n = mb_strtolower($name);
    return str_contains($n, 'appointment') && str_contains($n, 'book');
}

/** Who last set $statusName on a lead, from its timeline (for requests opened after the fact). */
function lead_status_setter(int $leadId, string $statusName): ?int
{
    $id = q_val("SELECT user_id FROM lead_activity WHERE lead_id = ? AND type = 'status' AND to_label = ? ORDER BY id DESC LIMIT 1", [$leadId, $statusName]);
    return $id ? (int) $id : null;
}

/**
 * Bring a lead's approval in line with its status (call after the status changed).
 * An open (pending) request is cancelled; if the new status needs approval a new pending request is opened,
 * booked by $bookedBy (default: the signed-in user). Decided requests are never touched, so monthly counts stay.
 */
function lead_approval_sync(int $leadId, ?int $statusId, ?int $bookedBy = null): void
{
    $ws = ws_id();
    $lead = q_one('SELECT id, name, assigned_to, approval_id FROM leads WHERE id = ? AND workspace_id = ?', [$leadId, $ws]);
    if (!$lead) {
        return;
    }
    if ($lead['approval_id']) {
        q("UPDATE lead_approvals SET decision = 'cancelled' WHERE id = ? AND decision = 'pending'", [$lead['approval_id']]);
    }
    $status = $statusId ? (lead_statuses($ws)[$statusId] ?? null) : null;
    $clear = ['approval_id' => null, 'approval_status' => null, 'approval_by' => null, 'approval_at' => null, 'approval_note' => null];
    if (!$status || empty($status['requires_approval'])) {
        if ($lead['approval_id']) {
            db_update('leads', $clear, 'id = ? AND workspace_id = ?', [$leadId, $ws]);
        }
        return;
    }
    $requestId = db_insert('lead_approvals', [
        'workspace_id' => $ws,
        'lead_id' => $leadId,
        'lead_name' => mb_substr((string) $lead['name'], 0, 255),
        'status_id' => $statusId,
        'status_name' => $status['name'],
        'booked_by' => $bookedBy ?? (user_id() ?: null),
        'booked_at' => now(),
        'assigned_to' => $lead['assigned_to'],
        'decision' => 'pending',
        'created_at' => now(),
    ]);
    db_update('leads', ['approval_id' => $requestId, 'approval_status' => 'pending'] + $clear, 'id = ? AND workspace_id = ?', [$leadId, $ws]);
}

/** Approval badge for a lead row (approval_status; approver_name, approval_at, approval_note and booked_by_name add a tooltip). */
function lead_approval_badge(array $lead, string $class = ''): string
{
    $state = $lead['approval_status'] ?? null;
    if (!$state || !isset(LEAD_APPROVAL_LABELS[$state])) {
        return '';
    }
    $color = ['pending' => 'amber', 'approved' => 'green', 'rejected' => 'red'][$state];
    $icon = ['pending' => 'clock', 'approved' => 'check', 'rejected' => 'x'][$state];
    $booked = !empty($lead['booked_by_name']) ? 'Booked by ' . $lead['booked_by_name'] . '. ' : '';
    $tip = $booked . ($state === 'pending' ? 'Waiting for a Super Admin.' : LEAD_APPROVAL_LABELS[$state] . ' by ' . ($lead['approver_name'] ?? 'a Super Admin') . (!empty($lead['approval_at']) ? ' · ' . format_dt($lead['approval_at']) : '') . (!empty($lead['approval_note']) ? ' — ' . $lead['approval_note'] : ''));
    return '<span class="badge badge-' . $color . ' inline-flex items-center gap-1 ' . e($class) . '" title="' . e($tip) . '">' . icon($icon, 'h-3 w-3') . e(LEAD_APPROVAL_LABELS[$state]) . '</span>';
}

/** Approve ($approve = true) or disapprove a lead's current request (or change an earlier decision). Returns an error or null. */
function lead_decide_approval(array $lead, bool $approve, string $note = ''): ?string
{
    if (empty($lead['approval_status'])) {
        return 'This lead is not waiting for approval.';
    }
    if (empty($lead['approval_id'])) { // safety net: open the missing request first
        lead_approval_sync((int) $lead['id'], $lead['status_id'] ? (int) $lead['status_id'] : null, $lead['assigned_to'] ? (int) $lead['assigned_to'] : null);
        $lead['approval_id'] = q_val('SELECT approval_id FROM leads WHERE id = ?', [$lead['id']]);
    }
    $state = $approve ? 'approved' : 'rejected';
    $note = mb_substr(trim($note), 0, 500);
    $note = $note !== '' ? $note : null;
    db_update('lead_approvals', ['decision' => $state, 'note' => $note, 'decided_by' => user_id(), 'decided_at' => now()], 'id = ? AND workspace_id = ?', [$lead['approval_id'], ws_id()]);
    db_update('leads', ['approval_status' => $state, 'approval_by' => user_id(), 'approval_at' => now(), 'approval_note' => $note, 'updated_at' => now()],
        'id = ? AND workspace_id = ?', [$lead['id'], ws_id()]);
    $statusName = $lead['status_id'] ? (lead_statuses(ws_id())[$lead['status_id']]['name'] ?? null) : null;
    log_lead_activity((int) $lead['id'], 'approval', $note, $statusName, LEAD_APPROVAL_LABELS[$state]);
    return null;
}

/** Leads awaiting approval in the workspace, for the Super Admin's sidebar badge (0 for everyone else). */
function lead_pending_approval_count(): int
{
    if (!allowed('leads.approve')) {
        return 0;
    }
    return (int) q_val("SELECT COUNT(*) FROM leads WHERE workspace_id = ? AND approval_status = 'pending'", [ws_id()]);
}

/** Whether the signed-in user may open Leads → Approvals (the per-closer monthly report). */
function can_view_lead_approvals(): bool
{
    return allowed('leads.approve') || allowed('leads.manage');
}

/** Statuses whose leads are listed on Leads → Pending tasks, keyed by id. */
function lead_pending_statuses(int $wsId): array
{
    return array_filter(lead_statuses($wsId), fn($s) => !empty($s['in_pending']));
}

/** Pending-task leads due today or overdue for the signed-in user (assigned to them), for the sidebar badge. */
function lead_pending_due_count(): int
{
    $ids = array_keys(lead_pending_statuses(ws_id()));
    if (!$ids) {
        return 0;
    }
    return (int) q_val(
        'SELECT COUNT(*) FROM leads WHERE workspace_id = ? AND assigned_to = ? AND follow_up_on <= ? AND status_id IN (' . placeholders($ids) . ')',
        [ws_id(), user_id(), date('Y-m-d'), ...$ids]
    );
}

/** A Y-m-d date from a form field, or null when empty / invalid / in the past. */
function lead_follow_up_from_input(mixed $value): ?string
{
    $d = DateTime::createFromFormat('!Y-m-d', trim((string) $value));
    if (!$d || $d->format('Y-m-d') !== trim((string) $value)) {
        return null;
    }
    return $d->format('Y-m-d') >= date('Y-m-d') ? $d->format('Y-m-d') : null;
}

/** Small "Oct 14" label for a lead's follow-up date; red once it is overdue. */
function lead_follow_up_badge(?string $date, string $class = 'text-xs'): string
{
    if (!$date) {
        return '';
    }
    $overdue = $date < date('Y-m-d');
    $label = $date === date('Y-m-d') ? 'Today' : date(substr($date, 0, 4) === date('Y') ? 'M j' : 'M j, Y', strtotime($date));
    return '<span class="inline-flex items-center gap-1 ' . $class . ' ' . ($overdue ? 'font-medium text-red-600' : 'text-slate-500') . '" title="Follow-up date' . ($overdue ? ' (overdue)' : '') . '">'
        . icon('calendar', 'h-3.5 w-3.5') . e($label) . '</span>';
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
        "SELECT l.*, a.name assignee_name, ap.name approver_name, bk.name booked_by_name, r.booked_at FROM leads l
         LEFT JOIN users a ON a.id = l.assigned_to LEFT JOIN users ap ON ap.id = l.approval_by
         LEFT JOIN lead_approvals r ON r.id = l.approval_id LEFT JOIN users bk ON bk.id = r.booked_by WHERE l.id = ? AND $where",
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
    // Time zone from the phones' area codes: a lead matches when any of its numbers is in the zone.
    if (($f['tz'] ?? '') === 'unknown') {
        $where[] = lead_unknown_zone_sql();
    } elseif (isset($f['tz'], US_AREA_CODES[$f['tz']])) {
        $where[] = 'EXISTS (SELECT 1 FROM lead_phones p WHERE p.lead_id = l.id AND ' . area_code_in_zones_sql('p.number_key', [$f['tz']]) . ')';
    }
    if (isset($f['approval']) && isset(LEAD_APPROVAL_LABELS[$f['approval']])) {
        $where[] = 'l.approval_status = ?';
        $params[] = $f['approval'];
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

/**
 * Leads per time zone for a filter (its own tz filter ignored): ['eastern' => n, …, 'unknown' => n].
 * A lead with numbers in two zones counts in both, matching the filter.
 */
function lead_time_zone_counts(array $f): array
{
    [$where, $params] = lead_filter_sql(array_diff_key($f, ['tz' => 1]));
    $counts = array_fill_keys(array_keys(US_TIME_ZONES), 0);
    foreach (q_all(
        'SELECT z.tz, COUNT(*) n FROM (
            SELECT DISTINCT l.id, ' . area_code_zone_case_sql('lp.number_key') . " tz
            FROM leads l JOIN lead_phones lp ON lp.lead_id = l.id WHERE $where
         ) z WHERE z.tz IS NOT NULL GROUP BY z.tz",
        $params
    ) as $r) {
        $counts[$r['tz']] = (int) $r['n'];
    }
    $counts['unknown'] = (int) q_val(
        "SELECT COUNT(*) FROM leads l WHERE $where AND " . lead_unknown_zone_sql(),
        $params
    );
    return $counts;
}

/** WHERE (alias l): none of the lead's numbers has a known US area code. */
function lead_unknown_zone_sql(): string
{
    return 'NOT EXISTS (SELECT 1 FROM lead_phones p WHERE p.lead_id = l.id AND ' . area_code_in_zones_sql('p.number_key', array_keys(US_AREA_CODES)) . ')';
}

/** Time-zone key of a lead from its phones (first number with a known area code). */
function lead_time_zone(array $phones): ?string
{
    foreach ($phones as $ph) {
        if ($zone = area_code_zone($ph['number_key'] ?? null)) {
            return $zone;
        }
    }
    return null;
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

/**
 * Change a lead's status (and log it with an optional note). Statuses with requires_date need a
 * follow-up date ($followUpOn, Y-m-d, validated by lead_follow_up_from_input()); others clear it.
 * Returns an error message, or null on success.
 */
function lead_change_status(array $lead, int $statusId, string $note = '', ?string $followUpOn = null): ?string
{
    $statuses = lead_statuses(ws_id());
    if (!isset($statuses[$statusId])) {
        return 'Choose a status.';
    }
    $needsDate = !empty($statuses[$statusId]['requires_date']);
    if ($needsDate && !$followUpOn) {
        return 'Pick a date (today or later) for ' . $statuses[$statusId]['name'] . '.';
    }
    $followUpOn = $needsDate ? $followUpOn : null;
    $statusChanged = (int) $lead['status_id'] !== $statusId;
    $dateChanged = ($lead['follow_up_on'] ?? null) !== $followUpOn;
    if ($statusChanged || $dateChanged) {
        db_update('leads', ['status_id' => $statusId, 'follow_up_on' => $followUpOn, 'updated_at' => now()], 'id = ? AND workspace_id = ?', [$lead['id'], ws_id()]);
        if ($statusChanged) {
            lead_approval_sync((int) $lead['id'], $statusId); // entering an approval status opens a request; leaving cancels it
        }
    }
    $body = trim(($followUpOn ? 'Date: ' . date('M j, Y', strtotime($followUpOn)) . "\n" : '') . $note);
    if ($statusChanged || ($followUpOn && $dateChanged)) {
        $from = $lead['status_id'] ? ($statuses[$lead['status_id']]['name'] ?? null) : null;
        log_lead_activity((int) $lead['id'], 'status', $body !== '' ? $body : null, $statusChanged ? $from : null, $statuses[$statusId]['name']);
    } elseif ($note !== '') {
        log_lead_activity((int) $lead['id'], 'note', $note);
    }
    return null;
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

/* ---------------------------------------------------------------------------
 * Leads → customers (so they can be emailed and put in campaigns; the lead stays as it is)
 * ------------------------------------------------------------------------- */

/** Customer id for one of a lead's emails: the existing customer with that email, or a new one built from the lead. Returns [id, created]. */
function lead_customer_for_email(int $wsId, array $lead, string $email, array $phones): array
{
    $id = (int) q_val('SELECT id FROM customers WHERE workspace_id = ? AND email = ? ORDER BY id LIMIT 1', [$wsId, $email]);
    if ($id) {
        return [$id, false];
    }
    $now = now();
    $id = db_insert('customers', customer_data_from_input([
        'email' => $email,
        'company' => $lead['name'],
        'phone' => $phones[0]['phone_number'] ?? '',
        'website' => $lead['website'] ?? '',
        'source' => mb_substr('Lead' . ($lead['source'] ? ': ' . $lead['source'] : ''), 0, 100),
    ]) + [
        'workspace_id' => $wsId,
        'status' => 'active',
        'crm_status' => 'New',
        'created_at' => $now,
        'updated_at' => $now,
        'last_activity_at' => $now,
    ]);
    log_activity($wsId, 'created', 'Created from lead "' . $lead['name'] . '"', $id);
    return [$id, true];
}

/**
 * Add every email on the matching leads to Customers and give those customers $tagIds (on top of their
 * existing tags). Leads without a valid email are skipped. $where/$params as from lead_filter_sql().
 * Returns ['leads' => converted, 'created' => new customers, 'existing' => already customers, 'skipped' => leads with no email].
 */
function leads_to_customers(int $wsId, string $where, array $params, array $tagIds): array
{
    $out = ['leads' => 0, 'created' => 0, 'existing' => 0, 'skipped' => 0];
    $tagNames = $tagIds ? q_col('SELECT name FROM tags WHERE workspace_id = ? AND id IN (' . placeholders($tagIds) . ') ORDER BY name', [$wsId, ...$tagIds]) : [];
    $ids = array_map('intval', q_col("SELECT l.id FROM leads l WHERE $where", $params));
    foreach (array_chunk($ids, 500) as $chunk) {
        transaction(function () use ($wsId, $chunk, $tagIds, $tagNames, &$out) {
            $leads = q_all('SELECT id, name, emails, website, source FROM leads WHERE workspace_id = ? AND id IN (' . placeholders($chunk) . ')', [$wsId, ...$chunk]);
            $phones = phones_for_leads($chunk);
            $now = now();
            foreach ($leads as $lead) {
                $emails = array_values(array_filter(
                    array_map(fn($e) => strtolower(trim($e)), explode(';', (string) $lead['emails'])),
                    fn($e) => $e !== '' && strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL)
                ));
                if (!$emails) {
                    $out['skipped']++;
                    continue;
                }
                foreach ($emails as $email) {
                    [$customerId, $created] = lead_customer_for_email($wsId, $lead, $email, $phones[$lead['id']] ?? []);
                    $out[$created ? 'created' : 'existing']++;
                    foreach ($tagIds as $t) {
                        q('INSERT IGNORE INTO customer_tags (customer_id, tag_id, workspace_id, created_at) VALUES (?, ?, ?, ?)', [$customerId, $t, $wsId, $now]);
                    }
                }
                log_lead_activity((int) $lead['id'], 'note', 'Added to customers: ' . implode(', ', $emails) . ($tagNames ? "\nTags: " . implode(', ', $tagNames) : ''));
                $out['leads']++;
            }
        });
    }
    return $out;
}

/** Flash message for a leads_to_customers() result. */
function leads_to_customers_message(array $r): string
{
    $plural = fn(int $n, string $word) => number_format($n) . " $word" . ($n === 1 ? '' : 's');
    if (!$r['leads']) {
        return $r['skipped'] === 1 ? 'This lead has no email, so it was not added to customers.' : 'None of the selected leads have an email, so nothing was added.';
    }
    $msg = $plural($r['leads'], 'lead') . ' added to customers (' . number_format($r['created']) . ' new'
        . ($r['existing'] ? ', ' . number_format($r['existing']) . ' already there' : '') . ').';
    return $msg . ($r['skipped'] ? ' ' . $plural($r['skipped'], 'lead') . ' without an email skipped.' : '');
}

/** Tag ids picked when adding leads to customers: checked tags[] plus new_tag (found or created by name). */
function lead_customer_tags_from_input(int $wsId): array
{
    $ids = owned_ids('tags', input_ids('tags'));
    $name = mb_substr(trim((string) input('new_tag')), 0, 80);
    if ($name !== '') {
        $id = (int) q_val('SELECT id FROM tags WHERE workspace_id = ? AND name = ?', [$wsId, $name]);
        $ids[] = $id ?: db_insert('tags', ['workspace_id' => $wsId, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }
    return array_values(array_unique(array_map('intval', $ids)));
}
