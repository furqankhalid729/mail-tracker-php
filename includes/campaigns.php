<?php
declare(strict_types=1);

/** Campaign statistics, audience building and contact filtering. */

/** Aggregated stats from the event ledger (one GROUP BY query) plus queue state. */
function campaign_stats(int $campaignId): array
{
    $s = array_fill_keys(['contacts', 'queued', 'unqueued', 'sent', 'delivered', 'opened', 'clicked', 'replied', 'bounced', 'failed', 'unsubscribed'], 0);
    $s['contacts'] = (int) q_val('SELECT COUNT(*) FROM campaign_contacts WHERE campaign_id = ?', [$campaignId]);
    $s['queued'] = (int) q_val("SELECT COUNT(*) FROM email_jobs WHERE campaign_id = ? AND status IN ('pending','processing')", [$campaignId]);
    $s['unqueued'] = campaign_unqueued_count($campaignId);
    foreach (q_all(
        "SELECT type, COUNT(DISTINCT email_message_id) n FROM email_events WHERE campaign_id = ? AND type <> 'queued' GROUP BY type",
        [$campaignId]
    ) as $r) {
        $s[$r['type']] = (int) $r['n'];
    }
    $sent = max(1, $s['sent']);
    $s['rates'] = [
        'delivery' => $s['sent'] ? $s['delivered'] / $sent * 100 : 0,
        'open' => $s['sent'] ? $s['opened'] / $sent * 100 : 0,
        'click' => $s['sent'] ? $s['clicked'] / $sent * 100 : 0,
        'reply' => $s['sent'] ? $s['replied'] / $sent * 100 : 0,
        'bounce' => $s['sent'] ? $s['bounced'] / $sent * 100 : 0,
    ];
    return $s;
}

/** Filter keys for campaign contacts (board + table). */
const CONTACT_FILTER_KEYS = ['q', 'tags', 'company', 'system_status', 'manual_status', 'sender', 'date_from', 'date_to', 'opened', 'clicked', 'replied', 'bounced'];

function contact_filters_from_request(): array
{
    $f = [];
    foreach (CONTACT_FILTER_KEYS as $k) {
        $v = $k === 'tags' ? input_ids('tags') : input($k);
        if ($v !== null && $v !== '' && $v !== []) {
            $f[$k] = $v;
        }
    }
    return $f;
}

/** WHERE for campaign_contacts (alias cc) joined to customers (c) and last message (m). */
function contact_filter_sql(int $campaignId, array $f): array
{
    $where = ['cc.campaign_id = ?'];
    $params = [$campaignId];
    if (!empty($f['q'])) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $f['q']) . '%';
        $where[] = '(c.email LIKE ? OR c.full_name LIKE ? OR c.company LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if (!empty($f['tags'])) {
        $tags = array_map('intval', (array) $f['tags']);
        $where[] = 'EXISTS (SELECT 1 FROM customer_tags ct WHERE ct.customer_id = c.id AND ct.tag_id IN (' . placeholders($tags) . '))';
        array_push($params, ...$tags);
    }
    if (!empty($f['company'])) {
        $where[] = 'c.company LIKE ?';
        $params[] = '%' . $f['company'] . '%';
    }
    if (!empty($f['system_status'])) {
        if ($f['system_status'] === 'none') {
            $where[] = 'cc.system_status IS NULL';
        } elseif (in_array($f['system_status'], SYSTEM_STATUSES, true)) {
            $where[] = 'cc.system_status = ?';
            $params[] = $f['system_status'];
        }
    }
    if (!empty($f['manual_status']) && in_array($f['manual_status'], CRM_STATUSES, true)) {
        $where[] = 'cc.manual_status = ?';
        $params[] = $f['manual_status'];
    }
    if (!empty($f['sender'])) {
        $where[] = 'm.mail_account_id = ?';
        $params[] = (int) $f['sender'];
    }
    if (!empty($f['date_from'])) {
        $where[] = 'COALESCE(cc.last_activity_at, cc.assigned_at) >= ?';
        $params[] = $f['date_from'] . ' 00:00:00';
    }
    if (!empty($f['date_to'])) {
        $where[] = 'COALESCE(cc.last_activity_at, cc.assigned_at) <= ?';
        $params[] = $f['date_to'] . ' 23:59:59';
    }
    $flags = ['opened' => 'm.first_opened_at', 'clicked' => 'm.first_clicked_at', 'replied' => 'm.replied_at', 'bounced' => 'm.bounced_at'];
    foreach ($flags as $k => $col) {
        if (isset($f[$k]) && $f[$k] !== '') {
            $where[] = $f[$k] === '1' ? "$col IS NOT NULL" : "$col IS NULL";
        }
    }
    return [implode(' AND ', $where), $params];
}

const CONTACT_FROM_SQL = 'campaign_contacts cc JOIN customers c ON c.id = cc.customer_id LEFT JOIN email_messages m ON m.id = cc.last_email_id';

/**
 * Add every customer matching $filters (customer_filter_sql format) to a campaign.
 * Uses INSERT IGNORE ... SELECT so it is fast and never duplicates (UNIQUE campaign_id, customer_id).
 */
function add_contacts_by_filter(int $wsId, int $campaignId, array $filters, string $campaignName): int
{
    [$where, $params] = customer_filter_sql($wsId, $filters);
    $now = now();
    $added = q(
        "INSERT IGNORE INTO campaign_contacts (workspace_id, campaign_id, customer_id, manual_status, assigned_at, created_at, updated_at)
         SELECT ?, ?, c.id, 'New', ?, ?, ? FROM customers c WHERE $where",
        [$wsId, $campaignId, $now, $now, $now, ...$params]
    )->rowCount();
    if ($added) {
        q(
            "INSERT INTO activity_log (workspace_id, customer_id, campaign_id, user_id, type, description, created_at)
             SELECT ?, cc.customer_id, cc.campaign_id, ?, 'campaign_added', ?, ? FROM campaign_contacts cc WHERE cc.campaign_id = ? AND cc.created_at = ?",
            [$wsId, user_id() ?: null, 'Added to campaign "' . $campaignName . '"', $now, $campaignId, $now]
        );
    }
    return $added;
}

/** Daily event counts for charts: ['Y-m-d' => [type => n]]. */
function campaign_daily_series(int $campaignId, int $days = 30): array
{
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $series[date('Y-m-d', strtotime("-$i days"))] = ['sent' => 0, 'opened' => 0, 'clicked' => 0, 'replied' => 0, 'bounced' => 0];
    }
    foreach (q_all(
        "SELECT DATE(created_at) d, type, COUNT(*) n FROM email_events
         WHERE campaign_id = ? AND created_at >= ? AND type IN ('sent','opened','clicked','replied','bounced')
         GROUP BY DATE(created_at), type",
        [$campaignId, date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'))]
    ) as $r) {
        if (isset($series[$r['d']])) {
            $series[$r['d']][$r['type']] = (int) $r['n'];
        }
    }
    return $series;
}
