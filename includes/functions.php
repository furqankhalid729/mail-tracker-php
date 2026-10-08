<?php
declare(strict_types=1);

/* ---------------------------------------------------------------------------
 * Output / URLs
 * ------------------------------------------------------------------------- */

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute URL for an app path, e.g. url('customers/view.php', ['id' => 5]). */
function url(string $path = '', array $query = []): string
{
    $u = APP_URL . '/' . ltrim($path, '/');
    $query = array_filter($query, fn($v) => $v !== null && $v !== '' && $v !== []);
    return $query ? $u . '?' . http_build_query($query) : $u;
}

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/'), ['v' => $v]);
}

/** Current URL with some query parameters replaced (used by filters, sorting, pagination). */
function url_with(array $changes): string
{
    $query = array_merge($_GET, $changes);
    $path = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    $query = array_filter($query, fn($v) => $v !== null && $v !== '');
    return $path . ($query ? '?' . http_build_query($query) : '');
}

function redirect(string $to, int $code = 302): never
{
    if (!preg_match('#^https?://#', $to) && !str_starts_with($to, '/')) {
        $to = url($to);
    }
    header('Location: ' . $to, true, $code);
    exit;
}

/** Redirect back to the referring page when it belongs to this app, else to $fallback. */
function redirect_back(string $fallback = 'dashboard.php'): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref !== '' && str_starts_with($ref, APP_URL)) {
        redirect($ref);
    }
    redirect($fallback);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? null) == 443
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function is_ajax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function abort(int $status = 404, string $message = 'Not found'): never
{
    http_response_code($status);
    if (is_ajax()) {
        json_response(['ok' => false, 'error' => $message], $status);
    }
    $page_title = (string) $status;
    $detail = null;
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($status) . '</title><body style="font-family:system-ui;background:#f8fafc;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0">'
        . '<div style="text-align:center"><div style="font-size:48px;font-weight:700;color:#cbd5e1">' . e($status) . '</div>'
        . '<p style="color:#475569">' . e($message) . '</p><a style="color:#4f46e5;font-weight:600;text-decoration:none" href="' . e(url('dashboard.php')) . '">Back to dashboard</a></div></body>';
    exit;
}

/* ---------------------------------------------------------------------------
 * Request input
 * ------------------------------------------------------------------------- */

function input(string $key, mixed $default = null): mixed
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function input_int(string $key, int $default = 0): int
{
    $v = input($key);
    return is_numeric($v) ? (int) $v : $default;
}

/** Array of positive ints from a request field (ids[]=1&ids[]=2 or "1,2"). */
function input_ids(string $key): array
{
    $v = $_POST[$key] ?? $_GET[$key] ?? [];
    if (is_string($v)) {
        $v = explode(',', $v);
    }
    return array_values(array_unique(array_filter(array_map('intval', (array) $v), fn($i) => $i > 0)));
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['_old'][$key] ?? $default;
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/* ---------------------------------------------------------------------------
 * Database helpers (always prepared statements)
 * ------------------------------------------------------------------------- */

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    if (!array_filter(array_keys($params), 'is_string')) {
        $params = array_values($params);
    }
    foreach ($params as $k => $v) {
        $name = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
        $type = match (true) {
            is_int($v) => PDO::PARAM_INT,
            is_bool($v) => PDO::PARAM_BOOL,
            $v === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
        $stmt->bindValue($name, $v, $type);
    }
    $stmt->execute();
    return $stmt;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function q_col(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(fn($c) => "`$c`", $cols)),
        implode(', ', array_fill(0, count($cols), '?'))
    );
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}

/** UPDATE with a simple WHERE; $where like 'id = ? AND workspace_id = ?'. */
function db_update(string $table, array $data, string $where, array $whereParams): int
{
    $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
    return q("UPDATE `$table` SET $set WHERE $where", [...array_values($data), ...$whereParams])->rowCount();
}

/** "?, ?, ?" for an IN() list. */
function placeholders(array $items): string
{
    return implode(', ', array_fill(0, max(1, count($items)), '?'));
}

function transaction(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/* ---------------------------------------------------------------------------
 * Pagination
 * ------------------------------------------------------------------------- */

function per_page(int $default = 25): int
{
    $pp = input_int('per_page', $default);
    return in_array($pp, PER_PAGE_OPTIONS, true) ? $pp : $default;
}

/** Returns ['page','per_page','total','pages','offset']. */
function paginate(int $total, int $perPage): array
{
    $pages = max(1, (int) ceil($total / max(1, $perPage)));
    $page = min(max(1, input_int('page', 1)), $pages);
    return ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages, 'offset' => ($page - 1) * $perPage];
}

function pagination_links(array $p): string
{
    $from = $p['total'] ? $p['offset'] + 1 : 0;
    $to = min($p['offset'] + $p['per_page'], $p['total']);
    $html = '<div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-slate-200 text-sm">';
    $html .= '<div class="text-slate-500">Showing <b class="text-slate-700">' . number_format($from) . '–' . number_format($to) . '</b> of <b class="text-slate-700">' . number_format($p['total']) . '</b></div>';
    $html .= '<div class="flex items-center gap-2">';
    $html .= '<select class="input !w-auto !py-1 text-sm" onchange="location.href=this.value">';
    foreach (PER_PAGE_OPTIONS as $opt) {
        $html .= '<option value="' . e(url_with(['per_page' => $opt, 'page' => 1])) . '"' . ($opt === $p['per_page'] ? ' selected' : '') . '>' . $opt . ' / page</option>';
    }
    $html .= '</select><nav class="flex items-center gap-1">';
    $btn = fn(int $page, string $label, bool $disabled = false, bool $active = false) => $disabled
        ? '<span class="pager-btn opacity-40 cursor-not-allowed">' . $label . '</span>'
        : '<a class="pager-btn' . ($active ? ' pager-active' : '') . '" href="' . e(url_with(['page' => $page])) . '">' . $label . '</a>';
    $html .= $btn($p['page'] - 1, '&lsaquo;', $p['page'] <= 1);
    $start = max(1, $p['page'] - 2);
    $end = min($p['pages'], $p['page'] + 2);
    if ($start > 1) {
        $html .= $btn(1, '1') . ($start > 2 ? '<span class="px-1 text-slate-400">…</span>' : '');
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $btn($i, (string) $i, false, $i === $p['page']);
    }
    if ($end < $p['pages']) {
        $html .= ($end < $p['pages'] - 1 ? '<span class="px-1 text-slate-400">…</span>' : '') . $btn($p['pages'], (string) $p['pages']);
    }
    $html .= $btn($p['page'] + 1, '&rsaquo;', $p['page'] >= $p['pages']);
    return $html . '</nav></div></div>';
}

/** Sortable table header link. $allowed maps request key => SQL column. */
function sort_link(string $key, string $label): string
{
    $current = (string) input('sort', '');
    $dir = strtolower((string) input('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
    $nextDir = ($current === $key && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $current === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    return '<a class="hover:text-slate-900" href="' . e(url_with(['sort' => $key, 'dir' => $nextDir, 'page' => 1])) . '">' . e($label) . $arrow . '</a>';
}

function sort_sql(array $allowed, string $defaultKey, string $defaultDir = 'desc'): string
{
    $key = (string) input('sort', $defaultKey);
    $col = $allowed[$key] ?? $allowed[$defaultKey];
    $dir = strtolower((string) input('dir', $key === $defaultKey ? $defaultDir : 'asc')) === 'desc' ? 'DESC' : 'ASC';
    return "$col $dir";
}

/* ---------------------------------------------------------------------------
 * Formatting
 * ------------------------------------------------------------------------- */

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $diff = time() - strtotime($datetime);
    return match (true) {
        $diff < 0 => format_dt($datetime),
        $diff < 60 => 'just now',
        $diff < 3600 => floor($diff / 60) . 'm ago',
        $diff < 86400 => floor($diff / 3600) . 'h ago',
        $diff < 604800 => floor($diff / 86400) . 'd ago',
        default => date('M j, Y', strtotime($datetime)),
    };
}

function format_dt(?string $datetime, string $format = 'M j, Y g:i A'): string
{
    return $datetime ? date($format, strtotime($datetime)) : '—';
}

function pct(int|float $part, int|float $whole, int $decimals = 1): string
{
    return $whole > 0 ? number_format($part / $whole * 100, $decimals) . '%' : '0%';
}

function initials(?string $name, ?string $fallback = null): string
{
    $name = trim((string) ($name ?: $fallback));
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/[\s@._-]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    $out = mb_strtoupper(mb_substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $out .= mb_strtoupper(mb_substr($parts[1], 0, 1));
    }
    return $out;
}

/** Teammate avatar text: first two letters of the first name ("Max Marketer" → "Ma"), so colleagues sharing initials stay distinct. */
function user_initials(?string $name): string
{
    $first = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';
    return $first === '' ? '?' : mb_strtoupper(mb_substr($first, 0, 1)) . mb_strtolower(mb_substr($first, 1, 1));
}

function customer_name(array $c): string
{
    $n = trim(($c['full_name'] ?? '') ?: trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
    return $n !== '' ? $n : (string) ($c['email'] ?? 'Unknown');
}

/** Deterministic avatar color for a string. */
function avatar_color(string $seed): string
{
    $colors = ['bg-indigo-100 text-indigo-700', 'bg-emerald-100 text-emerald-700', 'bg-amber-100 text-amber-700', 'bg-rose-100 text-rose-700', 'bg-sky-100 text-sky-700', 'bg-violet-100 text-violet-700', 'bg-teal-100 text-teal-700', 'bg-orange-100 text-orange-700'];
    return $colors[abs(crc32($seed)) % count($colors)];
}

function status_badge(?string $status): string
{
    if ($status === null || $status === '') {
        return '<span class="badge badge-slate">not queued</span>';
    }
    $map = [
        'draft' => 'slate', 'queued' => 'slate', 'pending' => 'slate', 'sending' => 'blue', 'processing' => 'blue',
        'sent' => 'blue', 'delivered' => 'sky', 'opened' => 'violet', 'clicked' => 'indigo', 'replied' => 'green',
        'received' => 'green', 'bounced' => 'red', 'failed' => 'red', 'cancelled' => 'slate', 'unsubscribed' => 'amber',
        'active' => 'green', 'paused' => 'amber', 'completed' => 'sky', 'archived' => 'slate', 'error' => 'red',
        'disconnected' => 'slate', 'New' => 'slate', 'Contacted' => 'blue', 'Interested' => 'violet',
        'Qualified' => 'indigo', 'Follow Up' => 'amber', 'Won' => 'green', 'Lost' => 'red',
    ];
    $color = $map[$status] ?? 'slate';
    return '<span class="badge badge-' . $color . '">' . e($status) . '</span>';
}

function tag_chip(array $tag): string
{
    $color = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $tag['color']) ? $tag['color'] : '#6366f1';
    return '<span class="tag-chip" style="--tag:' . e($color) . '">' . e($tag['name']) . '</span>';
}

function event_label(string $type): string
{
    return match ($type) {
        'queued' => 'Email queued', 'sent' => 'Email sent', 'delivered' => 'Email delivered',
        'opened' => 'Email opened', 'clicked' => 'Link clicked', 'replied' => 'Replied',
        'bounced' => 'Email bounced', 'failed' => 'Email failed', 'unsubscribed' => 'Unsubscribed',
        'campaign_added' => 'Added to campaign', 'campaign_removed' => 'Removed from campaign',
        'status_changed' => 'Status changed', 'note_added' => 'Note added', 'customer_created' => 'Customer created',
        'imported' => 'Imported', 'resubscribed' => 'Resubscribed', default => ucfirst(str_replace('_', ' ', $type)),
    };
}

function event_icon(string $type): string
{
    $paths = [
        'sent' => 'M6 12 3.27 3.13a59.77 59.77 0 0 1 18.22 8.87 59.77 59.77 0 0 1-18.22 8.88L6 12Zm0 0h7.5',
        'queued' => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'delivered' => 'm4.5 12.75 6 6 9-13.5',
        'opened' => 'M2.04 12.32a1 1 0 0 1 0-.64C3.42 7.51 7.36 4.5 12 4.5c4.64 0 8.57 3 9.96 7.18.07.2.07.43 0 .64C20.58 16.49 16.64 19.5 12 19.5c-4.64 0-8.57-3-9.96-7.18ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        'clicked' => 'M15.04 21.67 12.6 16.27l-3.97 4.07.76-15.67 10.8 11.38-5.67.26 2.44 5.4-1.92.96Z',
        'replied' => 'M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3',
        'bounced' => 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.01',
        'failed' => 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.01',
        'unsubscribed' => 'M18.36 5.64 5.64 18.36M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'note_added' => 'm16.86 4.49 1.69-1.69a1.88 1.88 0 1 1 2.65 2.65L10.58 16.07a4.5 4.5 0 0 1-1.9 1.13L6 18l.8-2.69a4.5 4.5 0 0 1 1.13-1.9l8.93-8.92Z',
        'campaign_added' => 'M12 4.5v15m7.5-7.5h-15',
        'status_changed' => 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5',
    ];
    $colors = [
        'sent' => 'bg-blue-50 text-blue-600', 'delivered' => 'bg-sky-50 text-sky-600', 'opened' => 'bg-violet-50 text-violet-600',
        'clicked' => 'bg-indigo-50 text-indigo-600', 'replied' => 'bg-emerald-50 text-emerald-600', 'bounced' => 'bg-red-50 text-red-600',
        'failed' => 'bg-red-50 text-red-600', 'unsubscribed' => 'bg-amber-50 text-amber-600', 'note_added' => 'bg-yellow-50 text-yellow-600',
    ];
    $d = $paths[$type] ?? 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z';
    $c = $colors[$type] ?? 'bg-slate-100 text-slate-500';
    return '<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full ' . $c . '"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="' . $d . '"/></svg></span>';
}

/** Inline heroicon by name (only the handful used in the layout). */
function icon(string $name, string $class = 'h-5 w-5'): string
{
    static $icons = [
        'home' => 'm2.25 12 8.95-8.95a1.13 1.13 0 0 1 1.6 0L21.75 12M4.5 9.75v10.13c0 .62.5 1.12 1.13 1.12H9.75v-4.88c0-.62.5-1.12 1.13-1.12h2.25c.62 0 1.12.5 1.12 1.12V21h4.13c.62 0 1.12-.5 1.12-1.13V9.75M8.25 21h8.25',
        'users' => 'M15 19.13a9.38 9.38 0 0 0 2.63.37 9.34 9.34 0 0 0 4.12-.95 4.13 4.13 0 0 0-7.53-2.49M15 19.13v-.01c0-1.11-.29-2.16-.78-3.07M15 19.13v.1A12.32 12.32 0 0 1 8.62 21c-2.33 0-4.51-.64-6.37-1.77v-.1a6.37 6.37 0 0 1 11.96-3.07M12 6.38a3.38 3.38 0 1 1-6.75 0 3.38 3.38 0 0 1 6.75 0Zm8.25 2.25a2.63 2.63 0 1 1-5.25 0 2.63 2.63 0 0 1 5.25 0Z',
        'megaphone' => 'M10.34 15.84c-.69-.03-1.38-.04-2.09-.04H7.5a4.5 4.5 0 1 1 0-9h.75c.7 0 1.4-.01 2.09-.04m0 9.08c.25.96.58 1.88.98 2.77.25.55.06 1.21-.46 1.51l-.66.38c-.51.3-1.17.11-1.42-.43a21.1 21.1 0 0 1-1.32-3.48m2.88-.75a24.3 24.3 0 0 1 0-9.08m0 9.08a51.3 51.3 0 0 1 8.13 1.87A23.85 23.85 0 0 0 20.08 12c0-1.79-.2-3.53-.57-5.2a51.3 51.3 0 0 1-8.13 1.87m8.7 7.14a23.9 23.9 0 0 0 0-8.95m0 0c.08-.32.16-.64.23-.96m-.23 10.87c.07.33.15.65.23.97',
        'inbox' => 'M2.25 13.5h3.86a2.25 2.25 0 0 1 2.01 1.24l.27.53a2.25 2.25 0 0 0 2.01 1.24h3.2a2.25 2.25 0 0 0 2.01-1.24l.27-.53a2.25 2.25 0 0 1 2.01-1.24h3.86M2.25 13.5V18A2.25 2.25 0 0 0 4.5 20.25h15A2.25 2.25 0 0 0 21.75 18v-4.5M2.25 13.5 4.41 5.19A2.25 2.25 0 0 1 6.6 3.75h10.8a2.25 2.25 0 0 1 2.19 1.44l2.16 8.31',
        'activity' => 'M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5m.75-9 3-3 2.15 2.15a12.06 12.06 0 0 1 3.13-4.4',
        'template' => 'M19.5 14.25v-2.63a3.38 3.38 0 0 0-3.38-3.37h-1.5A1.13 1.13 0 0 1 13.5 7.13v-1.5a3.38 3.38 0 0 0-3.38-3.38H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.63c-.62 0-1.13.5-1.13 1.13v17.25c0 .62.5 1.12 1.13 1.12h12.75c.62 0 1.12-.5 1.12-1.12V11.25a9 9 0 0 0-9-9Z',
        'at' => 'M16.5 12a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Zm0 0c0 1.66 1.01 3 2.25 3S21 13.66 21 12a9 9 0 1 0-2.64 6.36M16.5 12V8.25',
        'tag' => 'M9.57 3H5.25A2.25 2.25 0 0 0 3 5.25v4.32c0 .6.24 1.17.66 1.6l9.58 9.58c.7.7 1.78.87 2.6.33a18.1 18.1 0 0 0 5.22-5.22c.54-.82.37-1.9-.33-2.6L11.16 3.66A2.25 2.25 0 0 0 9.57 3ZM6 6h.01v.01H6V6Z',
        'cog' => 'M9.59 3.94c.09-.54.56-.94 1.11-.94h2.59c.55 0 1.02.4 1.11.94l.21 1.28c.06.37.31.68.65.87.08.04.15.08.22.13.33.2.74.26 1.1.12l1.22-.46a1.13 1.13 0 0 1 1.37.49l1.29 2.25c.28.48.17 1.08-.26 1.43l-1 .83c-.29.24-.43.6-.42.98a6.4 6.4 0 0 1 0 .25c-.01.37.13.74.42.98l1 .83c.43.35.54.95.26 1.43l-1.3 2.25a1.13 1.13 0 0 1-1.36.49l-1.22-.46c-.36-.14-.77-.08-1.1.12l-.22.13c-.34.19-.59.5-.65.87l-.21 1.28c-.09.54-.56.94-1.11.94h-2.6c-.55 0-1.02-.4-1.11-.94l-.21-1.28a1.23 1.23 0 0 0-.65-.87l-.22-.13a1.21 1.21 0 0 0-1.1-.12l-1.22.46a1.13 1.13 0 0 1-1.37-.49L3.31 15.4a1.13 1.13 0 0 1 .26-1.43l1-.83c.29-.24.43-.6.42-.98a6.4 6.4 0 0 1 0-.25c.01-.38-.13-.74-.42-.98l-1-.83a1.13 1.13 0 0 1-.26-1.43l1.3-2.25a1.13 1.13 0 0 1 1.36-.49l1.22.46c.36.14.77.08 1.1-.12.07-.05.15-.09.22-.13.34-.19.59-.5.65-.87l.21-1.28ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        'search' => 'm21 21-5.2-5.2m0 0A7.5 7.5 0 1 0 5.2 5.2a7.5 7.5 0 0 0 10.6 10.6Z',
        'plus' => 'M12 4.5v15m7.5-7.5h-15',
        'upload' => 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5',
        'download' => 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3',
        'mail' => 'M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.24a2.25 2.25 0 0 1-1.07 1.92l-7.5 4.61a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.92V6.75',
        'pencil' => 'm16.86 4.49 1.69-1.69a1.88 1.88 0 1 1 2.65 2.65L10.58 16.07a4.5 4.5 0 0 1-1.9 1.13L6 18l.8-2.69a4.5 4.5 0 0 1 1.13-1.9l8.93-8.92Zm0 0L19.5 7.13',
        'trash' => 'm14.74 9-.35 9m-4.78 0L9.26 9m9.97-3.21c.34.05.68.1 1.02.17m-1.02-.17L18.16 19.67A2.25 2.25 0 0 1 15.92 21.75H8.08a2.25 2.25 0 0 1-2.24-2.08L4.77 5.79m14.46 0a48.1 48.1 0 0 0-3.48-.4m-12 .56c.34-.06.68-.11 1.02-.17m0 0a48.1 48.1 0 0 1 3.48-.4m7.5 0v-.92c0-1.18-.91-2.16-2.09-2.2a51.96 51.96 0 0 0-3.32 0c-1.18.04-2.09 1.02-2.09 2.2v.92m7.5 0a48.67 48.67 0 0 0-7.5 0',
        'play' => 'M5.25 5.65c0-.86.92-1.4 1.67-.99l11.54 6.35c.78.43.78 1.55 0 1.98L6.92 19.34c-.75.41-1.67-.13-1.67-.99V5.65Z',
        'pause' => 'M15.75 5.25v13.5m-7.5-13.5v13.5',
        'menu' => 'M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5',
        'x' => 'M6 18 18 6M6 6l12 12',
        'logout' => 'M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9',
        'chevron-down' => 'm19.5 8.25-7.5 7.5-7.5-7.5',
        'send' => 'M6 12 3.27 3.13a59.77 59.77 0 0 1 18.22 8.87 59.77 59.77 0 0 1-18.22 8.88L6 12Zm0 0h7.5',
        'eye' => 'M2.04 12.32a1 1 0 0 1 0-.64C3.42 7.51 7.36 4.5 12 4.5c4.64 0 8.57 3 9.96 7.18.07.2.07.43 0 .64C20.58 16.49 16.64 19.5 12 19.5c-4.64 0-8.57-3-9.96-7.18ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        'copy' => 'M15.75 17.25v3.38c0 .62-.5 1.12-1.12 1.12h-9.75a1.13 1.13 0 0 1-1.13-1.12V7.88c0-.63.5-1.13 1.13-1.13H6.75a9.06 9.06 0 0 1 1.5.12m7.5 10.38h3.38c.62 0 1.12-.5 1.12-1.13V11.25c0-4.46-3.24-8.16-7.5-8.87a9.06 9.06 0 0 0-1.5-.13H9.38c-.62 0-1.13.5-1.13 1.13v3.5m7.5 10.38H9.38a1.13 1.13 0 0 1-1.13-1.13V7.88m12 4.37v-1.88a3.38 3.38 0 0 0-3.38-3.37h-1.5a1.13 1.13 0 0 1-1.12-1.13v-1.5a3.38 3.38 0 0 0-3.38-3.37H9.75',
        'paperclip' => 'm18.38 12.74-7.69 7.69a4.5 4.5 0 0 1-6.36-6.37l10.94-10.94A3 3 0 1 1 19.5 7.37L8.56 18.31m.01-.01-.01.01m5.25-12.78-7.8 7.81a1.5 1.5 0 0 0 2.11 2.12l7.81-7.8',
        'board' => 'M9 4.5v15m6-15v15m-10.88 0h15.75c.62 0 1.13-.5 1.13-1.13V5.63c0-.63-.5-1.13-1.13-1.13H4.13C3.5 4.5 3 5 3 5.63v12.75c0 .62.5 1.12 1.13 1.12Z',
        'table' => 'M3.38 19.5h17.25m-17.25 0a1.13 1.13 0 0 1-1.13-1.13M3.38 19.5h7.5c.62 0 1.12-.5 1.12-1.13M2.25 18.38V5.63m0 12.75v-1.5c0-.62.5-1.13 1.13-1.13m0 3.75c-.63 0-1.13-.5-1.13-1.13m18.38 1.13a1.13 1.13 0 0 0 1.12-1.13M20.63 19.5h-7.5a1.13 1.13 0 0 1-1.13-1.13m9.75 0v-1.5c0-.62-.5-1.13-1.12-1.13m1.12 2.63V5.63M3.38 4.5h17.25m-17.25 0c-.63 0-1.13.5-1.13 1.13M3.38 4.5h7.5c.62 0 1.12.5 1.12 1.13M20.63 4.5c.62 0 1.12.5 1.12 1.13M12 5.63v12.75',
        'chart' => 'M3 13.13C3 12.5 3.5 12 4.13 12h2.25c.62 0 1.12.5 1.12 1.13v6.75c0 .62-.5 1.12-1.12 1.12H4.13A1.13 1.13 0 0 1 3 19.87v-6.75ZM9.75 8.63c0-.63.5-1.13 1.13-1.13h2.25c.62 0 1.12.5 1.12 1.13v11.25c0 .62-.5 1.12-1.12 1.12h-2.25a1.13 1.13 0 0 1-1.13-1.12V8.63ZM16.5 4.13c0-.63.5-1.13 1.13-1.13h2.25C20.5 3 21 3.5 21 4.13v15.75c0 .62-.5 1.12-1.12 1.12h-2.25a1.13 1.13 0 0 1-1.13-1.12V4.13Z',
        'check' => 'm4.5 12.75 6 6 9-13.5',
        'filter' => 'M12 3c2.76 0 5.46.23 8.09.68.53.09.91.55.91 1.09v1.04c0 .6-.24 1.17-.66 1.59l-5.43 5.43a2.25 2.25 0 0 0-.66 1.59v2.93a2.25 2.25 0 0 1-1.24 2.01L9.75 21v-6.57c0-.6-.24-1.17-.66-1.59L3.66 7.4A2.25 2.25 0 0 1 3 5.81V4.77c0-.54.38-1 .91-1.09A48.6 48.6 0 0 1 12 3Z',
        'arrow-left' => 'M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18',
        'reply' => 'M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3',
        'forward' => 'm15 15 6-6m0 0-6-6m6 6H9a6 6 0 0 0 0 12h3',
        'bolt' => 'm3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z',
        'info' => 'm11.25 11.25.04-.02a.75.75 0 0 1 1.06.85l-.7 2.84a.75.75 0 0 0 1.06.85l.04-.02M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.01v.01H12V8.25Z',
        'tasks' => 'M11.35 3.84c-.06.2-.1.42-.1.66 0 .41.34.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.66m-5.8 0A2.25 2.25 0 0 1 13.5 2.25H15a2.25 2.25 0 0 1 2.15 1.59m-5.8 0c-.38.02-.75.05-1.12.08C9.1 4.01 8.25 4.98 8.25 6.11V8.25m8.9-4.41c.38.02.75.05 1.12.08 1.13.1 1.98 1.07 1.98 2.2V16.5a2.25 2.25 0 0 1-2.25 2.25H15.75m-7.5-10.5H4.88c-.63 0-1.13.5-1.13 1.13v11.25c0 .62.5 1.12 1.13 1.12h9.75c.62 0 1.12-.5 1.12-1.12V18.75m-7.5-10.5h6.38c.62 0 1.12.5 1.12 1.13v9.37m-8.25-3 1.5 1.5 3-3.75',
        'calendar' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
        'shield' => 'M9 12.75 11.25 15 15 9.75m-3-7.04A11.96 11.96 0 0 1 3.6 6 12 12 0 0 0 3 9.75c0 5.59 3.82 10.29 9 11.62 5.18-1.33 9-6.03 9-11.62 0-1.31-.21-2.57-.6-3.75h-.15c-3.2 0-6.1-1.25-8.25-3.29Z',
    ];
    return '<svg class="' . e($class) . '" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="' . ($icons[$name] ?? '') . '"/></svg>';
}

/* ---------------------------------------------------------------------------
 * Misc
 * ------------------------------------------------------------------------- */

function random_token(int $bytes = 20): string
{
    return bin2hex(random_bytes($bytes));
}

/** Write to storage/logs/app-YYYY-MM-DD.log. Never pass credentials in $context. */
function app_log(string $level, string $message, array $context = []): void
{
    $dir = STORAGE_PATH . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    unset($context['password'], $context['token'], $context['secret']);
    $line = sprintf("[%s] %s: %s %s\n", date('c'), strtoupper($level), $message, $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : '');
    @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}

/** Record CRM (non-email) activity for timelines and the activity feed. */
function log_activity(int $workspaceId, string $type, string $description, ?int $customerId = null, ?int $campaignId = null): void
{
    db_insert('activity_log', [
        'workspace_id' => $workspaceId,
        'customer_id' => $customerId,
        'campaign_id' => $campaignId,
        'user_id' => $_SESSION['user_id'] ?? null,
        'type' => $type,
        'description' => mb_substr($description, 0, 500),
        'created_at' => now(),
    ]);
}

/**
 * Simple sliding-window rate limiter backed by MySQL.
 * Returns true if the action is allowed (and records the hit).
 */
function rate_limit(string $bucket, int $max, int $windowSeconds): bool
{
    $count = (int) q_val(
        'SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND created_at > ?',
        [$bucket, date('Y-m-d H:i:s', time() - $windowSeconds)]
    );
    if ($count >= $max) {
        return false;
    }
    db_insert('rate_limits', ['bucket' => mb_substr($bucket, 0, 190), 'created_at' => now()]);
    return true;
}

function rate_limit_clear(string $bucket): void
{
    q('DELETE FROM rate_limits WHERE bucket = ?', [$bucket]);
}

function upload_dir(string $sub): string
{
    $dir = UPLOAD_PATH . '/' . trim($sub, '/');
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

function human_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $size = (float) $bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, $i ? 1 : 0) . ' ' . $units[$i];
}

/** Hostname of APP_URL, used for Message-ID domains as a fallback. */
function app_host(): string
{
    return parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost';
}
