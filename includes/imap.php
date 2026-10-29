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

    /**
     * All mailboxes as [name, flags[]] (flags lowercased, e.g. '\sent' from RFC 6154 SPECIAL-USE).
     * @return array<int, array{0: string, 1: string[]}>
     */
    public function listMailboxes(): array
    {
        $boxes = [];
        foreach ($this->command('LIST "" "*"') as $r) {
            $parsed = self::parseListLine($r['line'], $r['literals']);
            if ($parsed) {
                $boxes[] = $parsed;
            }
        }
        return $boxes;
    }

    /** Parse one "* LIST (\flags) "/" name" response; the name may be quoted, an atom or a literal. */
    public static function parseListLine(string $line, array $literals = []): ?array
    {
        if (!preg_match('/^\* (?:X?LIST|LSUB) \(([^)]*)\) (?:NIL|"(?:[^"\\\\]|\\\\.)*") ?(.*)$/is', rtrim($line), $m)) {
            return null;
        }
        $flags = array_map('strtolower', preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY));
        $name = trim($m[2]);
        if (preg_match('/^\{\d+\}/', $name) && $literals) {
            $name = $literals[0];
        } elseif (str_starts_with($name, '"')) {
            $name = str_replace(['\\"', '\\\\'], ['"', '\\'], substr($name, 1, -1));
        }
        return $name === '' ? null : [$name, $flags];
    }

    /** The Sent folder: the \Sent special-use flag if the server reports it, else a well-known name. */
    public function findSentMailbox(): ?string
    {
        return self::pickSentMailbox($this->listMailboxes());
    }

    /** @param array<int, array{0: string, 1: string[]}> $boxes as returned by listMailboxes() */
    public static function pickSentMailbox(array $boxes): ?string
    {
        foreach ($boxes as [$name, $flags]) {
            if (in_array('\\sent', $flags, true)) {
                return $name;
            }
        }
        $byName = [];
        foreach ($boxes as [$name, $flags]) {
            if (!in_array('\\noselect', $flags, true) && !in_array('\\nonexistent', $flags, true)) {
                $byName[strtolower($name)] = $name;
            }
        }
        foreach (IMAP_SENT_FOLDER_NAMES as $candidate) {
            if (isset($byName[strtolower($candidate)])) {
                return $byName[strtolower($candidate)];
            }
        }
        return null;
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
    // A reply to mail we have on record; its customer may be unknown (conversation held by contact_email)
    if (!$original || (!$original['customer_id'] && !$original['thread_id'])) {
        return 'ignored';
    }

    $now = now();
    $html = $parsed['html'] !== '' ? sanitize_html($parsed['html']) : '';
    $text = $parsed['text'] !== '' ? $parsed['text'] : html_to_text($parsed['html']);

    $threadId = $original['thread_id'];
    if (!$threadId) {
        $threadId = create_thread($wsId, (int) $original['customer_id'], $original['campaign_id'] ? (int) $original['campaign_id'] : null, (int) $account['id'], (string) $original['subject'], $original['ai_campaign_id'] ? (int) $original['ai_campaign_id'] : null);
        q('UPDATE email_messages SET thread_id = ? WHERE id = ?', [$threadId, $original['id']]);
    }

    transaction(function () use ($wsId, $original, $account, $parsed, $inboundId, $providerId, $providerThreadId, $html, $text, $now, $threadId) {
        db_insert('email_messages', [
            'workspace_id' => $wsId,
            'customer_id' => $original['customer_id'],
            'campaign_id' => $original['campaign_id'],
            'ai_campaign_id' => $original['ai_campaign_id'],
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

/**
 * Import a message from the mailbox's Sent folder that was sent outside the CRM (webmail, phone, Outlook).
 * Mail the CRM sent itself is recognised by Message-ID / provider id and skipped. Everything else is imported:
 * into the known thread it replies to, else a conversation with the customer, else one held by the recipient's
 * address. Returns 'imported' or 'skipped'.
 */
function process_sent_email(array $account, array $parsed, ?string $providerId = null, ?string $providerThreadId = null): string
{
    $wsId = (int) $account['workspace_id'];
    $toAll = $parsed['to_all'] ?? array_filter([$parsed['to'] ?? null]);
    $recipients = array_values(array_unique([...$toAll, ...($parsed['cc_all'] ?? [])]));
    $messageId = $parsed['message_id']
        ?: ($providerId ? 'provider-' . $providerId : 'nomsgid-' . sha1(implode('|', [$account['id'], $parsed['date'], $parsed['subject'], implode(',', $recipients)])));

    $ts = $parsed['date'] ? strtotime((string) $parsed['date']) : false;
    $sentAt = $ts && $ts <= time() + 300 ? date('Y-m-d H:i:s', $ts) : now();

    if (q_val(
        "SELECT id FROM email_messages WHERE workspace_id = ? AND direction = 'outbound' AND (message_id = ? OR provider_message_id = ?) LIMIT 1",
        [$wsId, $messageId, $providerId]
    )) {
        return 'skipped'; // sent by the CRM, or already imported
    }
    // Some servers rewrite Message-ID on submission: also treat a CRM send to the same person with the same subject within minutes as ours
    if ($recipients && q_val(
        'SELECT id FROM email_messages WHERE mail_account_id = ? AND direction = \'outbound\' AND is_external = 0 AND subject = ?
         AND to_email IN (' . placeholders($recipients) . ') AND sent_at BETWEEN ? AND ? LIMIT 1',
        [$account['id'], mb_substr($parsed['subject'], 0, 255), ...$recipients, date('Y-m-d H:i:s', strtotime($sentAt) - 900), date('Y-m-d H:i:s', strtotime($sentAt) + 900)]
    )) {
        return 'skipped';
    }

    // 1) Reply inside a conversation we already have: In-Reply-To / References, then the Gmail thread id
    $thread = null;
    $candidates = array_values(array_unique(array_filter([$parsed['in_reply_to'], ...array_reverse($parsed['references'])])));
    if ($candidates) {
        $thread = q_one(
            'SELECT t.* FROM email_messages m JOIN email_threads t ON t.id = m.thread_id
             WHERE m.workspace_id = ? AND m.message_id IN (' . placeholders($candidates) . ') ORDER BY m.id DESC LIMIT 1',
            [$wsId, ...$candidates]
        );
    }
    if (!$thread && $providerThreadId) {
        $thread = q_one('SELECT * FROM email_threads WHERE workspace_id = ? AND provider_thread_id = ? ORDER BY id DESC LIMIT 1', [$wsId, $providerThreadId]);
    }
    // 2) New mail to a customer: continue their conversation with the same subject, or start one
    if (!$thread) {
        $customer = null;
        if ($recipients) {
            $found = q_all('SELECT id, email FROM customers WHERE workspace_id = ? AND email IN (' . placeholders($recipients) . ')', [$wsId, ...$recipients]);
            $byEmail = array_column($found, null, 'email');
            foreach ($recipients as $r) {
                if (isset($byEmail[$r])) {
                    $customer = $byEmail[$r];
                    break;
                }
            }
        }
        // Not a customer: the conversation is held by their address until it becomes one (link_contact_threads)
        $contact = $customer ? null : ($recipients[0] ?? null);
        $base = trim(preg_replace('/^((re|aw|sv|fw|fwd)\s*:\s*)+/i', '', $parsed['subject']));
        if ($base !== '' && ($customer || $contact)) {
            $thread = q_one(
                'SELECT t.* FROM email_threads t WHERE t.workspace_id = ? AND ' . ($customer ? 't.customer_id = ?' : 't.customer_id IS NULL AND t.contact_email = ?') . '
                 AND t.subject IN (?, ?) ORDER BY t.last_message_at DESC LIMIT 1',
                [$wsId, $customer ? $customer['id'] : $contact, $base, mb_substr($parsed['subject'], 0, 255)]
            );
        }
        if (!$thread) {
            $tid = create_thread($wsId, $customer ? (int) $customer['id'] : null, null, (int) $account['id'], $parsed['subject'] !== '' ? $parsed['subject'] : '(no subject)', null, $contact);
            q('UPDATE email_threads SET last_message_at = ?, provider_thread_id = ? WHERE id = ?', [$sentAt, $providerThreadId, $tid]);
            $thread = q_one('SELECT * FROM email_threads WHERE id = ?', [$tid]);
        }
    }

    $now = now();
    $html = $parsed['html'] !== '' ? sanitize_html($parsed['html']) : '';
    $text = $parsed['text'] !== '' ? $parsed['text'] : html_to_text($parsed['html']);
    $threadId = (int) $thread['id'];

    transaction(function () use ($wsId, $thread, $threadId, $account, $parsed, $toAll, $messageId, $providerId, $providerThreadId, $html, $text, $sentAt, $now) {
        $id = db_insert('email_messages', [
            'workspace_id' => $wsId,
            'customer_id' => $thread['customer_id'],
            'campaign_id' => $thread['campaign_id'],
            'ai_campaign_id' => $thread['ai_campaign_id'],
            'thread_id' => $threadId,
            'mail_account_id' => $account['id'],
            'direction' => 'outbound',
            'message_id' => $messageId,
            'provider_message_id' => $providerId,
            'provider_thread_id' => $providerThreadId,
            'in_reply_to' => $parsed['in_reply_to'],
            'references_header' => $parsed['references'] ? implode(' ', array_map(fn($r) => "<$r>", $parsed['references'])) : null,
            'from_email' => $parsed['from_email'] ?: $account['email'],
            'from_name' => mb_substr((string) $parsed['from_name'], 0, 190),
            'to_email' => $toAll[0] ?? null,
            'cc' => ($parsed['cc_all'] ?? []) ? mb_substr(implode(', ', $parsed['cc_all']), 0, 1000) : null,
            'subject' => mb_substr($parsed['subject'], 0, 255),
            'html_body' => $html,
            'text_body' => $text,
            'status' => 'sent',
            'is_read' => 1,
            'is_external' => 1,
            'sent_at' => $sentAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // Answering from another client means the customer's earlier messages have been dealt with
        q("UPDATE email_messages SET is_read = 1 WHERE thread_id = ? AND direction = 'inbound' AND is_read = 0 AND sent_at <= ?", [$threadId, $sentAt]);
        q(
            "UPDATE email_threads SET last_message_at = GREATEST(COALESCE(last_message_at, ?), ?), provider_thread_id = COALESCE(provider_thread_id, ?),
                is_unread = (SELECT COUNT(*) > 0 FROM email_messages WHERE thread_id = ? AND direction = 'inbound' AND is_read = 0), updated_at = ?
             WHERE id = ?",
            [$sentAt, $sentAt, $providerThreadId, $threadId, $now, $threadId]
        );
        $message = q_one('SELECT * FROM email_messages WHERE id = ?', [$id]);
        record_event($message, 'sent', ['account' => $account['email'], 'source' => 'mailbox'], 'sent:' . $id);
    });
    return 'imported';
}

/**
 * Import new messages from the IMAP Sent folder (found via LIST, remembered on the account).
 * Uses the already logged-in client; bounded per run.
 */
function check_imap_sent(array $account, ImapClient $client, int $maxMessages = 25): array
{
    $stats = ['sent_fetched' => 0, 'imported' => 0, 'skipped' => 0];
    $folder = $account['imap_sent_folder'] ?: $client->findSentMailbox();
    $lastUid = (int) $account['imap_sent_last_uid'];
    $uidValidity = $account['imap_sent_uidvalidity'];
    try {
        if (!$folder) {
            throw new RuntimeException('no Sent folder found');
        }
        $client->select($folder);
    } catch (RuntimeException) {
        // The saved folder was renamed or removed: look it up again and start fresh
        $folder = $account['imap_sent_folder'] ? $client->findSentMailbox() : null;
        if (!$folder) {
            q('UPDATE mail_accounts SET imap_sent_folder = NULL, sent_checked_at = ? WHERE id = ?', [now(), $account['id']]);
            return $stats + ['sent_folder' => 'not found'];
        }
        $client->select($folder);
        $lastUid = 0;
        $uidValidity = null;
    }
    if ($uidValidity && (int) $uidValidity !== $client->uidValidity) {
        $lastUid = 0; // folder was rebuilt; rescan recent mail (dedupe prevents doubles)
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
        $stats['sent_fetched']++;
        $stats[process_sent_email($account, parse_raw_email($raw))]++;
    }
    q(
        'UPDATE mail_accounts SET imap_sent_folder = ?, imap_sent_last_uid = ?, imap_sent_uidvalidity = ?, sent_checked_at = ?, updated_at = ? WHERE id = ?',
        [$folder, $lastUid ?: null, $client->uidValidity, now(), now(), $account['id']]
    );
    return $stats;
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
        try {
            $stats += check_imap_sent($account, $client);
        } catch (Throwable $e) {
            // A Sent-folder problem must not undo the inbox check that already succeeded
            app_log('warning', 'Sent folder sync failed', ['account' => $account['id'], 'error' => $e->getMessage()]);
            $stats['sent_error'] = $e->getMessage();
        }
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
    try {
        $stats += check_gmail_sent($account);
    } catch (Throwable $e) {
        app_log('warning', 'Sent folder sync failed', ['account' => $account['id'], 'error' => $e->getMessage()]);
        $stats['sent_error'] = $e->getMessage();
    }
    return $stats;
}

/** Import mail sent from Gmail itself (web, phone) since the last check; the CRM's own API sends are skipped by id. */
function check_gmail_sent(array $account, int $maxMessages = 25): array
{
    $stats = ['sent_fetched' => 0, 'imported' => 0, 'skipped' => 0];
    $since = $account['sent_checked_at'] ? strtotime($account['sent_checked_at']) - 600 : strtotime('-3 days');
    $list = gmail_api($account, 'GET', '/messages?' . http_build_query(['q' => 'in:sent after:' . $since, 'maxResults' => $maxMessages]));
    foreach ($list['messages'] ?? [] as $ref) {
        if (q_val("SELECT id FROM email_messages WHERE workspace_id = ? AND provider_message_id = ? AND direction = 'outbound'", [$account['workspace_id'], $ref['id']])) {
            $stats['skipped']++;
            continue;
        }
        $msg = gmail_api($account, 'GET', '/messages/' . rawurlencode($ref['id']) . '?format=raw');
        if (empty($msg['raw'])) {
            continue;
        }
        $stats['sent_fetched']++;
        $stats[process_sent_email($account, parse_raw_email(base64url_decode($msg['raw'])), $msg['id'], $msg['threadId'] ?? null)]++;
    }
    q('UPDATE mail_accounts SET sent_checked_at = ?, updated_at = ? WHERE id = ?', [now(), now(), $account['id']]);
    return $stats;
}
