<?php
declare(strict_types=1);

/**
 * Delivery/bounce webhooks. Raw payloads are stored first (webhook_events, unique per provider event id),
 * then normalised and applied. Re-delivery of the same event is a no-op.
 *
 * Supported: generic JSON, SendGrid, Mailgun, Amazon SES (via SNS).
 */

/** Split a raw request body into normalised events: list of [provider_event_id, type, message_ref, reason, bounce_type, raw]. */
function normalize_webhook(string $provider, string $body): array
{
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return [];
    }
    $events = [];
    switch ($provider) {
        case 'sendgrid':
            foreach (array_is_list($data) ? $data : [$data] as $ev) {
                $type = match ($ev['event'] ?? '') {
                    'delivered' => 'delivered',
                    'bounce' => 'bounced',
                    'dropped' => 'failed',
                    default => null,
                };
                $events[] = [
                    'id' => (string) ($ev['sg_event_id'] ?? hash('sha256', json_encode($ev))),
                    'type' => $type,
                    'ref' => trim((string) ($ev['smtp-id'] ?? $ev['sg_message_id'] ?? ''), '<> '),
                    'reason' => (string) ($ev['reason'] ?? ''),
                    'bounce_type' => ($ev['type'] ?? 'bounce') === 'blocked' ? 'soft' : 'hard',
                    'raw' => $ev,
                ];
            }
            break;

        case 'mailgun':
            $ev = $data['event-data'] ?? $data;
            $type = match ($ev['event'] ?? '') {
                'delivered' => 'delivered',
                'failed' => ($ev['severity'] ?? '') === 'permanent' ? 'bounced' : null,
                default => null,
            };
            $events[] = [
                'id' => (string) ($ev['id'] ?? hash('sha256', $body)),
                'type' => $type,
                'ref' => trim((string) ($ev['message']['headers']['message-id'] ?? ''), '<> '),
                'reason' => (string) ($ev['delivery-status']['description'] ?? $ev['delivery-status']['message'] ?? ''),
                'bounce_type' => 'hard',
                'raw' => $ev,
            ];
            break;

        case 'ses':
            if (($data['Type'] ?? '') === 'SubscriptionConfirmation') {
                $events[] = ['id' => (string) ($data['MessageId'] ?? hash('sha256', $body)), 'type' => 'subscribe', 'ref' => '', 'reason' => '', 'bounce_type' => '', 'raw' => $data];
                break;
            }
            $msg = isset($data['Message']) && is_string($data['Message']) ? json_decode($data['Message'], true) : $data;
            $kind = $msg['notificationType'] ?? $msg['eventType'] ?? '';
            $ref = '';
            foreach ($msg['mail']['headers'] ?? [] as $h) {
                if (strcasecmp($h['name'] ?? '', 'Message-ID') === 0) {
                    $ref = trim($h['value'], '<> ');
                }
            }
            $permanent = ($msg['bounce']['bounceType'] ?? '') === 'Permanent';
            $events[] = [
                'id' => (string) ($data['MessageId'] ?? hash('sha256', $body)),
                'type' => match ($kind) {
                    'Delivery' => 'delivered',
                    'Bounce' => $permanent ? 'bounced' : null,
                    'Reject' => 'failed',
                    default => null,
                },
                'ref' => $ref ?: (string) ($msg['mail']['messageId'] ?? ''),
                'reason' => (string) ($msg['bounce']['bouncedRecipients'][0]['diagnosticCode'] ?? $kind),
                'bounce_type' => $permanent ? 'hard' : 'soft',
                'raw' => $msg,
            ];
            break;

        default: // generic: {"id": "...", "event": "delivered|bounced|failed", "message_id": "<...>", "reason": "..."}
            foreach (array_is_list($data) ? $data : [$data] as $ev) {
                $type = strtolower((string) ($ev['event'] ?? $ev['type'] ?? ''));
                $events[] = [
                    'id' => (string) ($ev['id'] ?? hash('sha256', json_encode($ev))),
                    'type' => in_array($type, ['delivered', 'bounced', 'failed'], true) ? $type : null,
                    'ref' => trim((string) ($ev['message_id'] ?? $ev['token'] ?? ''), '<> '),
                    'reason' => (string) ($ev['reason'] ?? ''),
                    'bounce_type' => (string) ($ev['bounce_type'] ?? 'hard'),
                    'raw' => $ev,
                ];
            }
    }
    return $events;
}

/** Store raw events (idempotent). Returns ids of webhook_events rows that are new. */
function store_webhook_events(string $provider, array $events): array
{
    $ids = [];
    foreach ($events as $ev) {
        $inserted = q(
            'INSERT IGNORE INTO webhook_events (provider, provider_event_id, event_type, payload, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$provider, mb_substr($ev['id'], 0, 190), $ev['type'], json_encode($ev, JSON_UNESCAPED_SLASHES), 'pending', now()]
        )->rowCount();
        if ($inserted) {
            $ids[] = (int) db()->lastInsertId();
        }
    }
    return $ids;
}

function find_message_by_ref(string $ref): ?array
{
    if ($ref === '') {
        return null;
    }
    if (preg_match('/^[a-f0-9]{40}$/', $ref)) {
        $m = find_message_by_token($ref);
        if ($m) {
            return $m;
        }
    }
    return q_one("SELECT * FROM email_messages WHERE direction = 'outbound' AND (message_id = ? OR provider_message_id = ?) ORDER BY id DESC LIMIT 1", [$ref, $ref]);
}

/** Apply one stored webhook event. Safe to call repeatedly. */
function process_webhook_event(int $id): void
{
    $row = q_one('SELECT * FROM webhook_events WHERE id = ?', [$id]);
    if (!$row || $row['status'] !== 'pending') {
        return;
    }
    $ev = json_decode($row['payload'], true) ?: [];
    try {
        if (($ev['type'] ?? null) === 'subscribe') {
            $urlToConfirm = (string) ($ev['raw']['SubscribeURL'] ?? '');
            $host = (string) parse_url($urlToConfirm, PHP_URL_HOST);
            if (str_starts_with($urlToConfirm, 'https://') && preg_match('/\.amazonaws\.com$/', $host)) {
                http_request('GET', $urlToConfirm);
                q("UPDATE webhook_events SET status = 'processed', processed_at = ? WHERE id = ?", [now(), $id]);
            } else {
                q("UPDATE webhook_events SET status = 'ignored', error_message = 'Untrusted SubscribeURL', processed_at = ? WHERE id = ?", [now(), $id]);
            }
            return;
        }
        if (empty($ev['type'])) {
            q("UPDATE webhook_events SET status = 'ignored', processed_at = ? WHERE id = ?", [now(), $id]);
            return;
        }
        $message = find_message_by_ref((string) $ev['ref']);
        if (!$message) {
            q("UPDATE webhook_events SET status = 'ignored', error_message = 'Message not found', processed_at = ? WHERE id = ?", [now(), $id]);
            return;
        }
        apply_delivery_status($message, $ev['type'], [
            'reason' => mb_substr((string) $ev['reason'], 0, 300),
            'bounce_type' => $ev['bounce_type'] ?: 'hard',
            'provider' => $row['provider'],
        ], $ev['type'] . ':' . $message['id']);
        q("UPDATE webhook_events SET status = 'processed', workspace_id = ?, processed_at = ? WHERE id = ?", [$message['workspace_id'], now(), $id]);
    } catch (Throwable $e) {
        q("UPDATE webhook_events SET status = 'failed', error_message = ?, processed_at = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), now(), $id]);
    }
}
