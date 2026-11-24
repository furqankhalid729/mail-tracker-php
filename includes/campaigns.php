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

/* ---------------------------------------------------------------------------
 * Per-domain cap (campaigns.max_per_domain)
 *
 * Several customers at one company domain (pr@lomi.com, partnerships@lomi.com) would all get the same
 * campaign email. With a cap, only the best N of them are queued; the rest are marked 'skipped' and kept
 * as backups. A backup is queued when an emailed contact at that domain bounces or fails.
 * Webmail domains (gmail.com…) are never grouped: those addresses belong to unrelated people.
 * ------------------------------------------------------------------------- */

/** Statuses that use up a slot of the domain's cap (queued or already emailed and not bounced/failed). */
const CAMPAIGN_DOMAIN_SLOT_STATUSES = ['queued', 'sent', 'delivered', 'opened', 'clicked', 'replied'];

/** The campaign's per-domain cap; 0 = no cap. */
function campaign_domain_cap(array $campaign): int
{
    return max(0, (int) ($campaign['max_per_domain'] ?? 0));
}

/** The company domain an address is grouped by, or null when it must not be grouped (webmail, no domain). */
function campaign_domain_key(string $email): ?string
{
    $at = strrchr($email, '@');
    $domain = $at === false ? '' : rtrim(strtolower(trim(substr($at, 1))), '.');
    if ($domain === '' || in_array($domain, EMAIL_SHARED_PROVIDERS, true)) {
        return null;
    }
    return $domain;
}

/** SQL ORDER BY that puts the best contact of a domain first: named people before info@/sales@, then the SES result, then oldest. */
function campaign_domain_preference_sql(): array
{
    $sql = 'CASE WHEN SUBSTRING_INDEX(c.email, \'@\', 1) IN (' . placeholders(EMAIL_ROLE_LOCALS) . ') THEN 1 ELSE 0 END,
            CASE c.email_check WHEN \'valid\' THEN 0 WHEN \'risky\' THEN 2 WHEN \'invalid\' THEN 3 ELSE 1 END,
            cc.id';
    return [$sql, EMAIL_ROLE_LOCALS];
}

/** LIKE pattern that matches every address at $domain. */
function campaign_domain_like(string $domain): string
{
    return '%@' . addcslashes($domain, '%_\\');
}

/** How many contacts at $domain hold a slot of the cap in this campaign. */
function campaign_domain_used(int $campaignId, string $domain): int
{
    return (int) q_val(
        'SELECT COUNT(*) FROM campaign_contacts cc JOIN customers c ON c.id = cc.customer_id
         WHERE cc.campaign_id = ? AND cc.system_status IN (' . placeholders(CAMPAIGN_DOMAIN_SLOT_STATUSES) . ') AND c.email LIKE ?',
        [$campaignId, ...CAMPAIGN_DOMAIN_SLOT_STATUSES, campaign_domain_like($domain)]
    );
}

/** True when the whole domain is blocked after a domain-level bounce (no MX…): backups there would bounce too. */
function campaign_domain_blocked(string $domain): bool
{
    try {
        return (bool) q_val(
            'SELECT 1 FROM blocked_email_domains WHERE domain = ? AND last_bounced_at >= ?',
            [$domain, date('Y-m-d H:i:s', time() - EMAIL_DOMAIN_BLOCK_DAYS * 86400)]
        );
    } catch (Throwable) {
        return false; // migration 020 not imported yet
    }
}

/**
 * A contact of a capped campaign bounced or failed: free its slot by turning the next skipped backup(s)
 * at the same domain back into "not queued", so cron queues them. A completed campaign is reopened for it.
 */
function campaign_promote_domain_backup(int $campaignId, int $customerId): void
{
    $campaign = q_one('SELECT id, workspace_id, name, status, max_per_domain FROM campaigns WHERE id = ?', [$campaignId]);
    $cap = $campaign ? campaign_domain_cap($campaign) : 0;
    if (!$cap || in_array($campaign['status'], ['draft', 'archived'], true)) {
        return;
    }
    $domain = campaign_domain_key((string) q_val('SELECT email FROM customers WHERE id = ?', [$customerId]));
    if ($domain === null || campaign_domain_blocked($domain)) {
        return;
    }
    $free = $cap - campaign_domain_used($campaignId, $domain);
    if ($free <= 0) {
        return;
    }
    [$orderSql, $orderParams] = campaign_domain_preference_sql();
    $ids = q_col(
        "SELECT cc.id FROM campaign_contacts cc JOIN customers c ON c.id = cc.customer_id
         WHERE cc.campaign_id = ? AND cc.system_status = 'skipped' AND c.email LIKE ?
           AND c.unsubscribed_at IS NULL AND c.status = 'active'
         ORDER BY $orderSql LIMIT $free",
        [$campaignId, campaign_domain_like($domain), ...$orderParams]
    );
    if (!$ids) {
        return;
    }
    q("UPDATE campaign_contacts SET system_status = NULL, updated_at = ? WHERE id IN (" . placeholders($ids) . ") AND system_status = 'skipped'", [now(), ...$ids]);
    if ($campaign['status'] === 'completed') {
        q("UPDATE campaigns SET status = 'active', completed_at = NULL, updated_at = ? WHERE id = ? AND status = 'completed'", [now(), $campaignId]);
        log_activity((int) $campaign['workspace_id'], 'campaign_started', 'Campaign "' . $campaign['name'] . '" reopened to email a backup contact at ' . $domain, null, $campaignId);
    }
}

/** The cap changed: every skipped contact goes back to "not queued" and the queue builder decides again. */
function campaign_reset_domain_skips(int $campaignId): void
{
    q("UPDATE campaign_contacts SET system_status = NULL, updated_at = ? WHERE campaign_id = ? AND system_status = 'skipped'", [now(), $campaignId]);
}
