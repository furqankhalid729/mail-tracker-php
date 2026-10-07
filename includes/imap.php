<?php
declare(strict_types=1);

/**
 * Tiny IMAP client over a TLS socket (LOGIN, SELECT, UID SEARCH, UID FETCH).
 * Works without ext-imap, which many hosts (and PHP 8.4+) no longer ship.
 */
final class ImapClient
{
    /** @var resource */
    private $sock;
    private int $tag = 0;
    public ?int $uidValidity = null;

    public function __construct(string $host, int $port, string $encryption, int $timeout = IMAP_TIMEOUT)
    {
        $scheme = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $sock = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new RuntimeException("Cannot connect to $host:$port ($errstr)");
        }
        stream_set_timeout($sock, $timeout);
        $this->sock = $sock;
        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new RuntimeException('Unexpected IMAP greeting');
        }
        if ($encryption === 'tls') {
            $this->command('STARTTLS');
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
        }
    }

    private function readLine(): string
    {
        $line = fgets($this->sock);
        if ($line === false) {
            $meta = stream_get_meta_data($this->sock);
            throw new RuntimeException($meta['timed_out'] ? 'IMAP read timed out' : 'IMAP connection closed');
        }
        return $line;
    }

    private static function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    /**
     * Send a command and collect untagged responses. Literals ({n}) are read in full and
     * attached to the response they belong to. Returns list of [line, literals[]].
     */
    public function command(string $cmd): array
    {
        $tag = 'A' . str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
        fwrite($this->sock, "$tag $cmd\r\n");
        $responses = [];
        $current = null;
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, $tag . ' ')) {
                if ($current) {
                    $responses[] = $current;
                }
                if (!preg_match('/^' . $tag . ' OK/i', $line)) {
                    throw new RuntimeException('IMAP: ' . trim(substr($line, strlen($tag) + 1)));
                }
                return $responses;
            }
            if (str_starts_with($line, '* ') || str_starts_with($line, '+ ')) {
                if ($current) {
                    $responses[] = $current;
                }
                $current = ['line' => $line, 'literals' => []];
            } elseif ($current) {
                $current['line'] .= $line;
            }
            // Literal: read exactly n bytes, then continue the same response
            while ($current && preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $len = (int) $m[1];
                $data = '';
                while (strlen($data) < $len) {
                    $chunk = fread($this->sock, min(8192, $len - strlen($data)));
                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException('IMAP literal read failed');
                    }
                    $data .= $chunk;
                }
                $current['literals'][] = $data;
                $line = $this->readLine();
                $current['line'] .= $line;
            }
        }
    }

    public function login(string $user, string $pass): void
    {
        $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($pass));
    }

    public function select(string $mailbox = 'INBOX'): void
    {
        foreach ($this->command('EXAMINE ' . self::quote($mailbox)) as $r) {
            if (preg_match('/UIDVALIDITY (\d+)/i', $r['line'], $m)) {
                $this->uidValidity = (int) $m[1];
            }
        }
    }

    /** @return int[] */
    public function uidSearch(string $criteria): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH ' . $criteria) as $r) {
            if (preg_match('/^\* SEARCH([\d ]*)/i', $r['line'], $m)) {
                $uids = array_merge($uids, array_map('intval', preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY)));
            }
        }
        sort($uids);
        return $uids;
    }

    /** Full raw message (BODY.PEEK keeps it unread for the mailbox owner). */
    public function fetchRaw(int $uid): ?string
    {
        foreach ($this->command("UID FETCH $uid (UID BODY.PEEK[])") as $r) {
            if (!empty($r['literals'])) {
                return $r['literals'][0];
            }
        }
        return null;
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (Throwable) {
        }
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
    }
}

function imap_client_for_account(array $account): ImapClient
{
    $client = new ImapClient((string) $account['imap_host'], (int) ($account['imap_port'] ?: 993), (string) ($account['imap_encryption'] ?: 'ssl'));
    $client->login((string) ($account['imap_username'] ?: $account['email']), (string) decrypt_value($account['encrypted_imap_password']));
    return $client;
}

/* ---------------------------------------------------------------------------
 * Inbound processing (shared by IMAP and Gmail)
 * ------------------------------------------------------------------------- */

/**
 * Match an inbound email to our outbound mail and record reply/bounce.
 * Returns 'reply', 'bounce' or 'ignored'.
 */
function process_inbound_email(array $account, array $parsed, ?string $providerId = null, ?string $providerThreadId = null): string
{
    $wsId = (int) $account['workspace_id'];

    if ($parsed['is_bounce']) {
        foreach ($parsed['bounce_message_ids'] as $mid) {
            $orig = q_one("SELECT * FROM email_messages WHERE workspace_id = ? AND message_id = ? AND direction = 'outbound'", [$wsId, $mid]);
            if ($orig) {
                apply_delivery_status($orig, 'bounced', [
                    'reason' => 'Bounce: ' . ($parsed['bounce_status'] ?: mb_substr($parsed['subject'], 0, 120)),
                    'bounce_type' => 'hard',
                    'source' => 'imap',
                ], 'bounced:' . $orig['id']);
                return 'bounce';
            }
        }
        return 'ignored';
    }

    if ($parsed['from_email'] && strcasecmp($parsed['from_email'], (string) $account['email']) === 0) {
        return 'ignored'; // our own sent copy
    }

    $inboundId = $parsed['message_id'] ?: ($providerId ? 'provider-' . $providerId : null);
    if ($inboundId && q_val("SELECT id FROM email_messages WHERE workspace_id = ? AND direction = 'inbound' AND message_id = ?", [$wsId, $inboundId])) {
        return 'ignored'; // already imported (idempotent)
    }

    // 1) Header match: In-Reply-To / References → one of our outbound Message-IDs
    $candidates = array_values(array_unique(array_filter([$parsed['in_reply_to'], ...array_reverse($parsed['references'])])));
    $original = null;
    if ($candidates) {
        $original = q_one(
            'SELECT * FROM email_messages WHERE workspace_id = ? AND message_id IN (' . placeholders($candidates) . ') ORDER BY id DESC LIMIT 1',
            [$wsId, ...$candidates]
        );
    }
    // 2) Gmail thread id
    if (!$original && $providerThreadId) {
        $original = q_one("SELECT * FROM email_messages WHERE workspace_id = ? AND provider_thread_id = ? AND direction = 'outbound' ORDER BY id DESC LIMIT 1", [$wsId, $providerThreadId]);
    }
    // 3) Fallback: sender is a customer we emailed from this account and the subject is "Re: <our subject>"
    if (!$original && $parsed['from_email']) {
        $base = trim(preg_replace('/^((re|aw|sv|fw|fwd)\s*:\s*)+/i', '', $parsed['subject']));
        if ($base !== '') {
            $original = q_one(
                "SELECT m.* FROM email_messages m JOIN customers c ON c.id = m.customer_id
                 WHERE m.workspace_id = ? AND m.mail_account_id = ? AND m.direction = 'outbound' AND c.email = ? AND m.subject = ?
                 ORDER BY m.id DESC LIMIT 1",
                [$wsId, $account['id'], $parsed['from_email'], $base]
            );
        }
    }
    if (!$original || !$original['customer_id']) {
        return 'ignored';
    }

    $now = now();
    $html = $parsed['html'] !== '' ? sanitize_html($parsed['html']) : '';
    $text = $parsed['text'] !== '' ? $parsed['text'] : html_to_text($parsed['html']);

    $threadId = $original['thread_id'];
    if (!$threadId) {
        $threadId = create_thread($wsId, (int) $original['customer_id'], $original['campaign_id'] ? (int) $original['campaign_id'] : null, (int) $account['id'], (string) $original['subject']);
        q('UPDATE email_messages SET thread_id = ? WHERE id = ?', [$threadId, $original['id']]);
    }

    transaction(function () use ($wsId, $original, $account, $parsed, $inboundId, $providerId, $providerThreadId, $html, $text, $now, $threadId) {
        db_insert('email_messages', [
            'workspace_id' => $wsId,
            'customer_id' => $original['customer_id'],
            'campaign_id' => $original['campaign_id'],
            'thread_id' => $threadId,
            'mail_account_id' => $account['id'],
            'direction' => 'inbound',
            'message_id' => $inboundId,
            'provider_message_id' => $providerId,
            'provider_thread_id' => $providerThreadId,
            'in_reply_to' => $parsed['in_reply_to'],
            'references_header' => $parsed['references'] ? implode(' ', array_map(fn($r) => "<$r>", $parsed['references'])) : null,
            'from_email' => $parsed['from_email'],
            'from_name' => mb_substr((string) $parsed['from_name'], 0, 190),
            'to_email' => $parsed['to'] ?: $account['email'],
            'subject' => mb_substr($parsed['subject'], 0, 255),
            'html_body' => $html,
            'text_body' => $text,
            'status' => 'received',
            'is_read' => 0,
            'sent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        q('UPDATE email_threads SET is_unread = 1, has_reply = 1, last_message_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $threadId]);
        q('UPDATE email_messages SET replied_at = COALESCE(replied_at, ?), updated_at = ? WHERE id = ?', [$now, $now, $original['id']]);
        record_event($original, 'replied', ['from' => $parsed['from_email'], 'subject' => mb_substr($parsed['subject'], 0, 200)], 'replied:' . ($inboundId ?? $original['id'] . ':' . $now));
        advance_message_status($original, 'replied');
        advance_contact_status($original['campaign_id'] ? (int) $original['campaign_id'] : null, (int) $original['customer_id'], 'replied');
    });
    return 'reply';
}

/** Check one IMAP mailbox for new replies/bounces. Bounded per run. */
function check_imap_account(array $account, int $maxMessages = 50): array
{
    $stats = ['fetched' => 0, 'reply' => 0, 'bounce' => 0, 'ignored' => 0];
    $client = imap_client_for_account($account);
    try {
        $client->select('INBOX');
        $lastUid = (int) $account['imap_last_uid'];
        if ($account['imap_uidvalidity'] && (int) $account['imap_uidvalidity'] !== $client->uidValidity) {
            $lastUid = 0; // mailbox was rebuilt; rescan recent mail (dedupe prevents doubles)
        }
        $uids = $lastUid > 0
            ? array_filter($client->uidSearch('UID ' . ($lastUid + 1) . ':*'), fn($u) => $u > $lastUid)
            : $client->uidSearch('SINCE ' . date('d-M-Y', strtotime('-3 days')));
        $uids = array_slice(array_values($uids), 0, $maxMessages);

        foreach ($uids as $uid) {
            $raw = $client->fetchRaw($uid);
            $lastUid = max($lastUid, $uid);
            if ($raw === null) {
                continue;
            }
            $stats['fetched']++;
            $result = process_inbound_email($account, parse_raw_email($raw));
            $stats[$result]++;
        }
        q(
            'UPDATE mail_accounts SET imap_last_uid = ?, imap_uidvalidity = ?, inbox_checked_at = ?, updated_at = ? WHERE id = ?',
            [$lastUid ?: null, $client->uidValidity, now(), now(), $account['id']]
        );
    } finally {
        $client->logout();
    }
    return $stats;
}

/** Check a Gmail account via the API for new inbound mail since the last check. */
function check_gmail_account(array $account, int $maxMessages = 50): array
{
    $stats = ['fetched' => 0, 'reply' => 0, 'bounce' => 0, 'ignored' => 0];
    $since = $account['inbox_checked_at'] ? strtotime($account['inbox_checked_at']) - 600 : strtotime('-3 days');
    $list = gmail_api($account, 'GET', '/messages?' . http_build_query(['q' => 'in:inbox after:' . $since, 'maxResults' => $maxMessages]));
    foreach ($list['messages'] ?? [] as $ref) {
        if (q_val("SELECT id FROM email_messages WHERE workspace_id = ? AND provider_message_id = ? AND direction = 'inbound'", [$account['workspace_id'], $ref['id']])) {
            continue;
        }
        $msg = gmail_api($account, 'GET', '/messages/' . rawurlencode($ref['id']) . '?format=raw');
        if (empty($msg['raw'])) {
            continue;
        }
        $stats['fetched']++;
        $result = process_inbound_email($account, parse_raw_email(base64url_decode($msg['raw'])), $msg['id'], $msg['threadId'] ?? null);
        $stats[$result]++;
    }
    q('UPDATE mail_accounts SET inbox_checked_at = ?, updated_at = ? WHERE id = ?', [now(), now(), $account['id']]);
    return $stats;
}
