<?php
declare(strict_types=1);

/**
 * Customer filtering and bulk operations, shared by the list page, export, campaign audience builder and cron.
 */

/** Filter keys accepted from the request. */
const CUSTOMER_FILTER_KEYS = ['q', 'tags', 'tag_mode', 'status', 'crm_status', 'country', 'company', 'has_company', 'source', 'campaign_id', 'not_in_campaign', 'created_from', 'created_to'];

function customer_filters_from_request(): array
{
    $f = [];
    foreach (CUSTOMER_FILTER_KEYS as $k) {
        $v = $k === 'tags' ? input_ids('tags') : input($k);
        if ($v !== null && $v !== '' && $v !== []) {
            $f[$k] = $v;
        }
    }
    return $f;
}

/**
 * WHERE clause (alias "c") for customer filters. Workspace isolation is always applied.
 * Returns [sql, params].
 */
function customer_filter_sql(int $wsId, array $f): array
{
    $where = ['c.workspace_id = ?'];
    $params = [$wsId];

    if (!empty($f['q'])) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $f['q']) . '%';
        $where[] = '(c.email LIKE ? OR c.full_name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if (!empty($f['tags'])) {
        $tags = array_map('intval', (array) $f['tags']);
        if (($f['tag_mode'] ?? 'any') === 'all') {
            foreach ($tags as $t) {
                $where[] = 'EXISTS (SELECT 1 FROM customer_tags ct WHERE ct.customer_id = c.id AND ct.tag_id = ?)';
                $params[] = $t;
            }
        } else {
            $where[] = 'EXISTS (SELECT 1 FROM customer_tags ct WHERE ct.customer_id = c.id AND ct.tag_id IN (' . placeholders($tags) . '))';
            array_push($params, ...$tags);
        }
    }
    if (!empty($f['status'])) {
        if (str_starts_with((string) $f['status'], '!')) {
            $where[] = 'c.status <> ?';
            $params[] = substr((string) $f['status'], 1);
        } elseif (in_array($f['status'], CUSTOMER_STATUSES, true)) {
            $where[] = 'c.status = ?';
            $params[] = $f['status'];
        }
    }
    if (!empty($f['crm_status']) && in_array($f['crm_status'], CRM_STATUSES, true)) {
        $where[] = 'c.crm_status = ?';
        $params[] = $f['crm_status'];
    }
    if (!empty($f['country'])) {
        $where[] = 'c.country = ?';
        $params[] = $f['country'];
    }
    if (!empty($f['company'])) {
        $where[] = 'c.company LIKE ?';
        $params[] = '%' . $f['company'] . '%';
    }
    if (isset($f['has_company']) && $f['has_company'] !== '') {
        $where[] = $f['has_company'] ? "(c.company IS NOT NULL AND c.company <> '')" : "(c.company IS NULL OR c.company = '')";
    }
    if (!empty($f['source'])) {
        $where[] = 'c.source = ?';
        $params[] = $f['source'];
    }
    if (!empty($f['campaign_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM campaign_contacts cc WHERE cc.customer_id = c.id AND cc.campaign_id = ?)';
        $params[] = (int) $f['campaign_id'];
    }
    if (!empty($f['not_in_campaign'])) {
        $where[] = 'NOT EXISTS (SELECT 1 FROM campaign_contacts cc WHERE cc.customer_id = c.id AND cc.campaign_id = ?)';
        $params[] = (int) $f['not_in_campaign'];
    }
    if (!empty($f['created_from'])) {
        $where[] = 'c.created_at >= ?';
        $params[] = $f['created_from'] . ' 00:00:00';
    }
    if (!empty($f['created_to'])) {
        $where[] = 'c.created_at <= ?';
        $params[] = $f['created_to'] . ' 23:59:59';
    }
    return [implode(' AND ', $where), $params];
}

/** Tags for a set of customers: customer_id => [tag rows]. */
function tags_for_customers(array $customerIds): array
{
    if (!$customerIds) {
        return [];
    }
    $out = [];
    foreach (q_all(
        'SELECT ct.customer_id, t.id, t.name, t.color FROM customer_tags ct JOIN tags t ON t.id = ct.tag_id
         WHERE ct.customer_id IN (' . placeholders($customerIds) . ') ORDER BY t.name',
        $customerIds
    ) as $r) {
        $out[$r['customer_id']][] = $r;
    }
    return $out;
}

function workspace_tags(int $wsId): array
{
    return q_all('SELECT * FROM tags WHERE workspace_id = ? ORDER BY name', [$wsId]);
}

function set_customer_tags(int $wsId, int $customerId, array $tagIds): void
{
    $tagIds = $tagIds ? owned_ids('tags', $tagIds) : [];
    q('DELETE FROM customer_tags WHERE customer_id = ?', [$customerId]);
    foreach ($tagIds as $t) {
        q('INSERT IGNORE INTO customer_tags (customer_id, tag_id, workspace_id, created_at) VALUES (?, ?, ?, ?)', [$customerId, $t, $wsId, now()]);
    }
}

/** Normalise customer form/CSV input into column data. */
function customer_data_from_input(array $in): array
{
    $first = trim((string) ($in['first_name'] ?? ''));
    $last = trim((string) ($in['last_name'] ?? ''));
    $full = trim((string) ($in['full_name'] ?? ''));
    if ($full === '' && ($first !== '' || $last !== '')) {
        $full = trim("$first $last");
    }
    if ($full !== '' && $first === '' && $last === '') {
        $parts = preg_split('/\s+/', $full, 2);
        $first = $parts[0];
        $last = $parts[1] ?? '';
    }
    $clean = fn($k, $max = 190) => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
    return [
        'first_name' => $first !== '' ? mb_substr($first, 0, 100) : null,
        'last_name' => $last !== '' ? mb_substr($last, 0, 100) : null,
        'full_name' => $full !== '' ? mb_substr($full, 0, 200) : null,
        'email' => strtolower(trim((string) ($in['email'] ?? ''))),
        'company' => $clean('company'),
        'job_title' => $clean('job_title', 150),
        'phone' => $clean('phone', 60),
        'website' => normalize_website($in['website'] ?? null),
        'country' => $clean('country', 100),
        'source' => $clean('source', 100),
        'notes' => $clean('notes', 5000),
    ];
}

/* ---------------------------------------------------------------------------
 * Bulk operations
 * ------------------------------------------------------------------------- */

const BULK_ACTIONS = ['add_tag', 'remove_tag', 'add_to_campaign', 'set_status', 'set_crm_status', 'delete'];

/** Apply a bulk action to a chunk of (already workspace-verified) customer ids. Returns affected count. */
function apply_bulk_chunk(int $wsId, string $action, array $payload, array $ids): int
{
    if (!$ids) {
        return 0;
    }
    $in = placeholders($ids);
    $now = now();
    switch ($action) {
        case 'add_tag':
            $n = 0;
            foreach ($ids as $id) {
                $n += q('INSERT IGNORE INTO customer_tags (customer_id, tag_id, workspace_id, created_at) VALUES (?, ?, ?, ?)', [$id, (int) $payload['tag_id'], $wsId, $now])->rowCount();
            }
            return $n;
        case 'remove_tag':
            return q("DELETE FROM customer_tags WHERE tag_id = ? AND customer_id IN ($in)", [(int) $payload['tag_id'], ...$ids])->rowCount();
        case 'add_to_campaign':
            $n = 0;
            foreach ($ids as $id) {
                $n += ensure_campaign_contact($wsId, (int) $payload['campaign_id'], $id) ? 1 : 0;
            }
            return $n;
        case 'set_status':
            $status = (string) $payload['status'];
            if ($status === 'unsubscribed') {
                $n = 0;
                foreach (q_all("SELECT * FROM customers WHERE id IN ($in) AND workspace_id = ?", [...$ids, $wsId]) as $c) {
                    unsubscribe_customer($c, 'manual', null, $payload['user_id'] ?? null);
                    $n++;
                }
                return $n;
            }
            return q("UPDATE customers SET status = ?, unsubscribed_at = IF(? = 'active', NULL, unsubscribed_at), updated_at = ? WHERE workspace_id = ? AND id IN ($in)", [$status, $status, $now, $wsId, ...$ids])->rowCount();
        case 'set_crm_status':
            return q("UPDATE customers SET crm_status = ?, updated_at = ? WHERE workspace_id = ? AND id IN ($in)", [(string) $payload['crm_status'], $now, $wsId, ...$ids])->rowCount();
        case 'delete':
            return q("DELETE FROM customers WHERE workspace_id = ? AND id IN ($in)", [$wsId, ...$ids])->rowCount();
    }
    return 0;
}

/** Validate bulk payload against the workspace. Returns error string or null. */
function validate_bulk_payload(int $wsId, string $action, array $payload): ?string
{
    return match ($action) {
        'add_tag', 'remove_tag' => q_val('SELECT id FROM tags WHERE id = ? AND workspace_id = ?', [(int) ($payload['tag_id'] ?? 0), $wsId]) ? null : 'Choose a tag.',
        'add_to_campaign' => q_val("SELECT id FROM campaigns WHERE id = ? AND workspace_id = ? AND status <> 'archived'", [(int) ($payload['campaign_id'] ?? 0), $wsId]) ? null : 'Choose a campaign.',
        'set_status' => in_array($payload['status'] ?? '', CUSTOMER_STATUSES, true) ? null : 'Choose a status.',
        'set_crm_status' => in_array($payload['crm_status'] ?? '', CRM_STATUSES, true) ? null : 'Choose a CRM status.',
        'delete' => null,
        default => 'Unknown action.',
    };
}

/** Cron: advance queued bulk jobs in id-ordered chunks. */
function process_bulk_jobs(int $maxSeconds = 20): int
{
    $start = microtime(true);
    $done = 0;
    foreach (q_all("SELECT * FROM bulk_jobs WHERE status IN ('pending','processing') ORDER BY id LIMIT 5") as $job) {
        $payload = json_decode($job['payload'], true) ?: [];
        $wsId = (int) $job['workspace_id'];
        q("UPDATE bulk_jobs SET status = 'processing', updated_at = ? WHERE id = ?", [now(), $job['id']]);
        $lastId = (int) $job['last_id'];
        try {
            while (microtime(true) - $start < $maxSeconds) {
                [$where, $params] = customer_filter_sql($wsId, $payload['filters'] ?? []);
                $ids = array_map('intval', q_col("SELECT c.id FROM customers c WHERE $where AND c.id > ? ORDER BY c.id LIMIT 500", [...$params, $lastId]));
                if (!$ids) {
                    q("UPDATE bulk_jobs SET status = 'completed', updated_at = ? WHERE id = ?", [now(), $job['id']]);
                    break;
                }
                $n = apply_bulk_chunk($wsId, $job['action'], $payload, $ids);
                $lastId = end($ids);
                $done += $n;
                q('UPDATE bulk_jobs SET last_id = ?, processed = processed + ?, updated_at = ? WHERE id = ?', [$lastId, count($ids), now(), $job['id']]);
            }
        } catch (Throwable $e) {
            q("UPDATE bulk_jobs SET status = 'failed', error_message = ?, updated_at = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), now(), $job['id']]);
        }
    }
    return $done;
}
