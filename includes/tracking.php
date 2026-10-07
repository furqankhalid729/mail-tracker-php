<?php
declare(strict_types=1);

/**
 * Open/click tracking, unsubscribe links and the email_events ledger.
 * email_events is the source of truth for analytics; message/contact statuses are derived summaries.
 */

function tracking_click_url(string $token, string $destination): string
{
    return url('tracking/click.php', ['t' => $token, 'u' => $destination, 's' => sign_value($token . '|' . $destination)]);
}

function unsubscribe_url(string $token): string
{
    return url('unsubscribe/index.php', ['token' => $token]);
}

/**
 * Prepare the outgoing HTML for one message: rewrite links, append unsubscribe footer and open pixel.
 * The stored html_body stays clean; only the transmitted copy is instrumented.
 */
function instrument_html(string $html, array $message, array $workspace, bool $isCampaign): string
{
    $token = (string) $message['tracking_token'];

    if ($workspace['track_clicks'] ?? true) {
        $html = preg_replace_callback(
            '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/is',
            function ($m) use ($token) {
                $href = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!preg_match('#^https?://#i', $href) || str_contains($href, '/unsubscribe/index.php')) {
                    return $m[0];
                }
                return $m[1] . $m[2] . e(tracking_click_url($token, $href)) . $m[2];
            },
            $html
        );
    }

    if ($isCampaign) {
        $footer = '<div style="margin-top:28px;padding-top:12px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;font-family:Arial,sans-serif">';
        if (!empty($workspace['company_address'])) {
            $footer .= e($workspace['company_address']) . '<br>';
        }
        $footer .= 'Don\'t want to hear from us? <a href="' . e(unsubscribe_url($token)) . '" style="color:#6b7280">Unsubscribe</a></div>';
        $html = str_ireplace('</body>', $footer . '</body>', $html, $count);
        if (!$count) {
            $html .= $footer;
        }
    }

    if ($workspace['track_opens'] ?? true) {
        $pixel = '<img src="' . e(url('tracking/open.php', ['token' => $token])) . '" width="1" height="1" style="display:none;width:1px;height:1px;border:0" alt="">';
        $html = str_ireplace('</body>', $pixel . '</body>', $html, $count);
        if (!$count) {
            $html .= $pixel;
        }
    }
    return $html;
}

function instrument_text(string $text, array $message, array $workspace, bool $isCampaign): string
{
    if ($isCampaign) {
        $text .= "\n\n--\n" . (!empty($workspace['company_address']) ? $workspace['company_address'] . "\n" : '')
            . 'Unsubscribe: ' . unsubscribe_url((string) $message['tracking_token']);
    }
    return $text;
}

/**
 * Insert an email event. $dedupeKey makes the insert idempotent (webhooks, cron re-runs).
 * Returns false when the event already existed.
 */
function record_event(array $message, string $type, array $metadata = [], ?string $dedupeKey = null, bool $withClientInfo = false): bool
{
    $ws = q_one('SELECT store_ip FROM workspaces WHERE id = ?', [$message['workspace_id']]);
    $storeIp = $withClientInfo && (int) ($ws['store_ip'] ?? 1) === 1;
    try {
        db_insert('email_events', [
            'workspace_id' => $message['workspace_id'],
            'email_message_id' => $message['id'],
            'customer_id' => $message['customer_id'],
            'campaign_id' => $message['campaign_id'],
            'type' => $type,
            'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => $storeIp ? client_ip() : null,
            'user_agent' => $storeIp ? user_agent() : null,
            'dedupe_key' => $dedupeKey ? mb_substr($dedupeKey, 0, 190) : null,
            'created_at' => now(),
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000' && $dedupeKey) {
            return false; // duplicate → already recorded
        }
        throw $e;
    }
    if ($message['customer_id']) {
        q('UPDATE customers SET last_activity_at = ? WHERE id = ?', [now(), $message['customer_id']]);
    }
    return true;
}

/** Move a campaign contact's system status forward (never backwards, except bounce/failure rules). */
function advance_contact_status(?int $campaignId, ?int $customerId, string $status, ?int $messageId = null): void
{
    if (!$campaignId || !$customerId) {
        return;
    }
    $cc = q_one('SELECT id, system_status FROM campaign_contacts WHERE campaign_id = ? AND customer_id = ?', [$campaignId, $customerId]);
    if (!$cc) {
        return;
    }
    $current = $cc['system_status'];
    $apply = match ($status) {
        'bounced' => $current !== 'replied',
        'failed' => in_array($current, [null, 'queued', 'sent'], true),
        'replied' => true,
        default => match (true) {
            $current === 'bounced' => false,
            $current === null, $current === 'failed' => true,
            default => (SYSTEM_STATUS_RANK[$status] ?? 0) > (SYSTEM_STATUS_RANK[$current] ?? 0),
        },
    };
    $data = ['last_activity_at' => now(), 'updated_at' => now()];
    if ($apply) {
        $data['system_status'] = $status;
    }
    if ($messageId) {
        $data['last_email_id'] = $messageId;
    }
    db_update('campaign_contacts', $data, 'id = ?', [$cc['id']]);
}

/** Same forward-only rule for the message's own status column. */
function advance_message_status(array $message, string $status): void
{
    $current = $message['status'];
    $rank = SYSTEM_STATUS_RANK;
    if (in_array($current, ['bounced', 'failed', 'cancelled'], true) && $status !== 'replied') {
        return;
    }
    if (($rank[$status] ?? 0) > ($rank[$current] ?? 0) || in_array($status, ['bounced', 'failed'], true)) {
        q('UPDATE email_messages SET status = ?, updated_at = ? WHERE id = ?', [$status, now(), $message['id']]);
    }
}

/** Find an outbound message by its tracking token (tokens are random, never sequential ids). */
function find_message_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
        return null;
    }
    return q_one("SELECT * FROM email_messages WHERE tracking_token = ? AND direction = 'outbound'", [$token]);
}

function track_open(array $message): void
{
    $now = now();
    q(
        'UPDATE email_messages SET open_count = open_count + 1, first_opened_at = COALESCE(first_opened_at, ?), last_opened_at = ?, updated_at = ? WHERE id = ?',
        [$now, $now, $now, $message['id']]
    );
    // Throttle duplicate events from image proxies re-fetching the pixel within a minute
    $dedupe = 'open:' . $message['id'] . ':' . date('YmdHi');
    record_event($message, 'opened', [], $dedupe, true);
    advance_message_status($message, 'opened');
    advance_contact_status($message['campaign_id'] ? (int) $message['campaign_id'] : null, $message['customer_id'] ? (int) $message['customer_id'] : null, 'opened');
}

function track_click(array $message, string $destination): void
{
    $now = now();
    q(
        'UPDATE email_messages SET click_count = click_count + 1, first_clicked_at = COALESCE(first_clicked_at, ?), last_clicked_at = ?,
         first_opened_at = COALESCE(first_opened_at, ?), last_opened_at = COALESCE(last_opened_at, ?), updated_at = ? WHERE id = ?',
        [$now, $now, $now, $now, $now, $message['id']]
    );
    record_event($message, 'clicked', ['url' => mb_substr($destination, 0, 1000)], null, true);
    advance_message_status($message, 'clicked');
    advance_contact_status($message['campaign_id'] ? (int) $message['campaign_id'] : null, $message['customer_id'] ? (int) $message['customer_id'] : null, 'clicked');
}

/** Mark a message delivered/bounced/failed from webhook or IMAP DSN data. Idempotent. */
function apply_delivery_status(array $message, string $type, array $meta = [], ?string $dedupeKey = null): void
{
    $dedupeKey ??= $type . ':' . $message['id'];
    if (!record_event($message, $type, $meta, $dedupeKey)) {
        return;
    }
    $now = now();
    if ($type === 'delivered') {
        q('UPDATE email_messages SET delivered_at = COALESCE(delivered_at, ?), updated_at = ? WHERE id = ?', [$now, $now, $message['id']]);
    } elseif ($type === 'bounced') {
        q('UPDATE email_messages SET bounced_at = COALESCE(bounced_at, ?), error_message = ?, updated_at = ? WHERE id = ?', [$now, mb_substr((string) ($meta['reason'] ?? 'Bounced'), 0, 500), $now, $message['id']]);
        if (($meta['bounce_type'] ?? 'hard') === 'hard' && $message['customer_id']) {
            q("UPDATE customers SET status = 'bounced', updated_at = ? WHERE id = ? AND status = 'active'", [$now, $message['customer_id']]);
        }
    }
    advance_message_status($message, $type);
    advance_contact_status($message['campaign_id'] ? (int) $message['campaign_id'] : null, $message['customer_id'] ? (int) $message['customer_id'] : null, $type);
}

/** Unsubscribe a customer (idempotent) and write the audit record. */
function unsubscribe_customer(array $customer, string $source, ?array $message = null, ?int $userId = null): void
{
    if ($customer['unsubscribed_at']) {
        return;
    }
    $now = now();
    transaction(function () use ($customer, $source, $message, $userId, $now) {
        q("UPDATE customers SET unsubscribed_at = ?, status = 'unsubscribed', updated_at = ? WHERE id = ?", [$now, $now, $customer['id']]);
        db_insert('unsubscribe_records', [
            'workspace_id' => $customer['workspace_id'],
            'customer_id' => $customer['id'],
            'email' => $customer['email'],
            'email_message_id' => $message['id'] ?? null,
            'campaign_id' => $message['campaign_id'] ?? null,
            'action' => 'unsubscribed',
            'source' => $source,
            'user_id' => $userId,
            'ip_address' => $source === 'link' ? client_ip() : null,
            'created_at' => $now,
        ]);
        if ($message) {
            record_event($message, 'unsubscribed', ['source' => $source], 'unsub:' . $message['id']);
        }
        // Cancel anything still waiting in the queue for this customer's campaigns
        q(
            "UPDATE email_jobs j JOIN email_messages m ON m.id = j.email_message_id
             SET j.status = 'cancelled', j.error_message = 'Recipient unsubscribed', m.status = 'cancelled'
             WHERE m.customer_id = ? AND m.campaign_id IS NOT NULL AND j.status = 'pending'",
            [$customer['id']]
        );
    });
}

function resubscribe_customer(array $customer, int $userId): void
{
    $now = now();
    transaction(function () use ($customer, $userId, $now) {
        q("UPDATE customers SET unsubscribed_at = NULL, status = 'active', updated_at = ? WHERE id = ?", [$now, $customer['id']]);
        db_insert('unsubscribe_records', [
            'workspace_id' => $customer['workspace_id'],
            'customer_id' => $customer['id'],
            'email' => $customer['email'],
            'action' => 'resubscribed',
            'source' => 'manual',
            'user_id' => $userId,
            'created_at' => $now,
        ]);
        log_activity((int) $customer['workspace_id'], 'resubscribed', 'Resubscribed manually', (int) $customer['id']);
    });
}

/** Transparent 1x1 GIF. */
function output_pixel(): never
{
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}
