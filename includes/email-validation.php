<?php
/**
 * Email validation before campaign mail is sent: free checks first (syntax + domain MX record, see
 * email_precheck), then Amazon SES (SESv2 GetEmailAddressInsights) only for addresses that pass them.
 * Plain curl + AWS Signature V4, so no AWS SDK is needed on shared hosting.
 *
 * Verdicts (IsValid.ConfidenceVerdict): HIGH → 'valid', MEDIUM → 'risky', LOW → 'invalid'.
 * Results are stored on the customer (email_check*) and reused for SES_VALIDATION_CACHE_DAYS, so each
 * address is paid for once.
 */
defined('APP_ROOT') || exit;

/**
 * Ask SES about one address.
 * Returns ['ok' => true, 'result' => valid|risky|invalid, 'detail' => string]
 *      or ['ok' => false, 'error' => string, 'retry_after' => seconds].
 */
function ses_validate_email(string $email): array
{
    $host = 'email.' . AWS_REGION . '.amazonaws.com';
    $path = '/v2/email/email-address-insights/';
    $body = json_encode(['EmailAddress' => $email]);
    $amzDate = gmdate('Ymd\THis\Z');
    $date = substr($amzDate, 0, 8);
    $scope = "$date/" . AWS_REGION . '/ses/aws4_request';

    $canonical = "POST\n$path\n\ncontent-type:application/json\nhost:$host\nx-amz-date:$amzDate\n\ncontent-type;host;x-amz-date\n" . hash('sha256', $body);
    $toSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);
    $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 'ses', hash_hmac('sha256', AWS_REGION, hash_hmac('sha256', $date, 'AWS4' . AWS_SECRET_KEY, true), true), true), true);
    $signature = hash_hmac('sha256', $toSign, $key);

    $ch = curl_init("https://$host$path");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "X-Amz-Date: $amzDate",
            'Authorization: AWS4-HMAC-SHA256 Credential=' . AWS_ACCESS_KEY . "/$scope, SignedHeaders=content-type;host;x-amz-date, Signature=$signature",
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'SES validation request failed: ' . $curlErr, 'retry_after' => 600];
    }
    $data = json_decode((string) $raw, true) ?: [];

    if ($code === 400) {
        // SES rejects addresses it cannot even parse → treat as invalid rather than retrying forever
        return ['ok' => true, 'result' => 'invalid', 'detail' => 'Rejected by SES: ' . mb_substr((string) ($data['message'] ?? $data['Message'] ?? 'bad address'), 0, 150)];
    }
    if ($code !== 200) {
        $msg = (string) ($data['message'] ?? $data['Message'] ?? mb_substr((string) $raw, 0, 150));
        return ['ok' => false, 'error' => "SES validation error (HTTP $code): $msg", 'retry_after' => $code === 429 ? 120 : 900];
    }

    $mv = $data['MailboxValidation'] ?? [];
    $verdict = strtoupper((string) ($mv['IsValid']['ConfidenceVerdict'] ?? ''));
    $result = match ($verdict) {
        'HIGH' => 'valid',
        'MEDIUM' => 'risky',
        'LOW' => 'invalid',
        default => 'risky',
    };

    // Short human-readable reason from the individual checks
    $labels = [
        'HasValidSyntax' => 'bad syntax', 'HasValidDnsRecords' => 'domain cannot receive mail',
        'MailboxExists' => 'mailbox may not exist', 'IsDisposable' => 'disposable address',
        'IsRoleAddress' => 'role address', 'IsRandomInput' => 'looks randomly typed',
    ];
    $problems = [];
    foreach ($labels as $check => $label) {
        $v = strtoupper((string) ($mv['Evaluations'][$check]['ConfidenceVerdict'] ?? ''));
        if ($v === '') {
            continue;
        }
        // "Has/Exists" checks are bad when LOW; "Is…" checks are bad when HIGH
        $bad = str_starts_with($check, 'Is') ? $v === 'HIGH' : $v === 'LOW';
        if ($bad) {
            $problems[] = $label;
        }
    }
    $detail = 'SES confidence ' . ($verdict ?: 'unknown') . ($problems ? ': ' . implode(', ', $problems) : '');

    return ['ok' => true, 'result' => $result, 'detail' => $detail];
}

/* ---------------------------------------------------------------------------
 * Free checks (no SES cost)
 * ------------------------------------------------------------------------- */

/** Common misspellings of big mail providers. Many of these are registered by typo-squatters and DO have MX. */
const EMAIL_TYPO_DOMAINS = [
    'gmial.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gmal.com' => 'gmail.com',
    'gmil.com' => 'gmail.com', 'gnail.com' => 'gmail.com', 'gmaill.com' => 'gmail.com', 'gmali.com' => 'gmail.com',
    'gmaik.com' => 'gmail.com', 'gmailc.om' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmail.cm' => 'gmail.com',
    'gmail.om' => 'gmail.com', 'gmaul.com' => 'gmail.com', 'gimail.com' => 'gmail.com', 'gmeil.com' => 'gmail.com',
    'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'hotmai.com' => 'hotmail.com', 'hotmil.com' => 'hotmail.com',
    'homail.com' => 'hotmail.com', 'hotmaill.com' => 'hotmail.com', 'hotamil.com' => 'hotmail.com', 'hotmail.co' => 'hotmail.com',
    'htomail.com' => 'hotmail.com', 'hotnail.com' => 'hotmail.com',
    'yaho.com' => 'yahoo.com', 'yahooo.com' => 'yahoo.com', 'yhoo.com' => 'yahoo.com', 'yahho.com' => 'yahoo.com',
    'yahoo.cm' => 'yahoo.com', 'yaoo.com' => 'yahoo.com', 'yahool.com' => 'yahoo.com',
    'outlok.com' => 'outlook.com', 'outllok.com' => 'outlook.com', 'outloo.com' => 'outlook.com', 'outlook.co' => 'outlook.com',
    'outlookk.com' => 'outlook.com', 'otulook.com' => 'outlook.com',
    'iclod.com' => 'icloud.com', 'icloud.co' => 'icloud.com', 'icoud.com' => 'icloud.com', 'iclould.com' => 'icloud.com',
    'aol.co' => 'aol.com', 'aoll.com' => 'aol.com',
];

/** Mistyped ".com" endings that are not real TLDs. */
const EMAIL_TYPO_TLDS = ['con', 'cmo', 'ocm', 'comm', 'coom', 'vom', 'xom', 'cpm'];

/** Local parts of shared/role mailboxes (info@, sales@…). Handled by EMAIL_ROLE_POLICY. */
const EMAIL_ROLE_LOCALS = [
    'info', 'sales', 'support', 'admin', 'administrator', 'contact', 'hello', 'office', 'enquiries', 'enquiry', 'inquiries',
    'inquiry', 'help', 'service', 'services', 'billing', 'accounts', 'accounting', 'finance', 'hr', 'jobs', 'careers',
    'marketing', 'team', 'mail', 'email', 'webmaster', 'postmaster', 'hostmaster', 'abuse', 'noreply', 'no-reply',
    'donotreply', 'do-not-reply', 'customerservice', 'customercare', 'reception', 'orders', 'booking', 'bookings',
];

/** Big webmail providers: a bounce from them is about one mailbox, never the whole domain. */
const EMAIL_SHARED_PROVIDERS = [
    'gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'rocketmail.com', 'hotmail.com', 'outlook.com', 'live.com',
    'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'gmx.net', 'gmx.de',
    'mail.com', 'zoho.com', 'yandex.com', 'yandex.ru', 'mail.ru', 'web.de', 'qq.com', '163.com',
];

function email_domain(string $email): string
{
    $domain = strtolower(substr((string) strrchr($email, '@'), 1));
    if ($domain !== '' && function_exists('idn_to_ascii')) {
        $domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain;
    }
    return rtrim($domain, '.');
}

/** True when $domain or one of its parent domains is in the disposable list (includes/data/disposable-domains.txt). */
function email_is_disposable(string $domain): bool
{
    static $list = null;
    if ($list === null) {
        $list = [];
        $file = __DIR__ . '/data/disposable-domains.txt';
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            $line = strtolower(trim($line));
            if ($line !== '' && $line[0] !== '#') {
                $list[$line] = true;
            }
        }
    }
    for ($d = $domain; $d !== '' && str_contains($d, '.'); $d = (string) substr($d, strpos($d, '.') + 1)) {
        if (isset($list[$d])) {
            return true;
        }
    }
    return false;
}

function email_is_role_address(string $email): bool
{
    $local = strtolower(strstr($email, '@', true) ?: '');
    $local = explode('+', $local)[0];
    return in_array($local, EMAIL_ROLE_LOCALS, true);
}

/**
 * Free checks run before SES, in order: syntax, provider typo, disposable domain, domain blocked from earlier
 * bounces, and finally DNS (the domain must publish an MX record whose host resolves — parked domains with only
 * an A record fail here). Returns ['ok' => true] or ['ok' => false, 'detail' => string].
 * A DNS lookup error (as opposed to "no records") passes, so a resolver hiccup never blocks a good address.
 */
function email_precheck(string $email): array
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'detail' => 'Invalid email syntax'];
    }
    $domain = email_domain($email);

    static $cache = []; // per run, per domain
    if (isset($cache[$domain])) {
        return $cache[$domain];
    }

    if (isset(EMAIL_TYPO_DOMAINS[$domain])) {
        return $cache[$domain] = ['ok' => false, 'detail' => 'Looks like a typo of ' . EMAIL_TYPO_DOMAINS[$domain]];
    }
    $tld = (string) substr($domain, (int) strrpos($domain, '.') + 1);
    if (in_array($tld, EMAIL_TYPO_TLDS, true)) {
        return $cache[$domain] = ['ok' => false, 'detail' => 'Domain ending ".' . $tld . '" looks like a typo of ".com"'];
    }
    if (email_is_disposable($domain)) {
        return $cache[$domain] = ['ok' => false, 'detail' => 'Disposable email domain'];
    }
    try {
        $learned = q_one(
            'SELECT reason FROM blocked_email_domains WHERE domain = ? AND last_bounced_at >= ?',
            [$domain, date('Y-m-d H:i:s', time() - EMAIL_DOMAIN_BLOCK_DAYS * 86400)]
        );
    } catch (Throwable) {
        $learned = null; // migration 020 not imported yet
    }
    if ($learned) {
        return $cache[$domain] = ['ok' => false, 'detail' => mb_substr('Domain blocked after earlier bounce: ' . $learned['reason'], 0, 255)];
    }

    $mx = @dns_get_record($domain . '.', DNS_MX);
    if ($mx === false) {
        return $cache[$domain] = ['ok' => true]; // lookup failed; let SES / the send decide
    }
    $hosts = [];
    foreach ($mx as $r) {
        $target = rtrim(strtolower((string) ($r['target'] ?? '')), '.');
        if ($target !== '') {
            $hosts[] = $target;
        }
    }
    if (!$hosts) {
        // No MX at all, or only a "null MX" (RFC 7505: the domain explicitly accepts no mail)
        return $cache[$domain] = ['ok' => false, 'detail' => $mx ? 'Domain does not accept email (null MX)' : 'Domain has no MX record (cannot receive email)'];
    }
    foreach ($hosts as $h) {
        if (@checkdnsrr($h . '.', 'A') || @checkdnsrr($h . '.', 'AAAA')) {
            return $cache[$domain] = ['ok' => true];
        }
    }

    return $cache[$domain] = ['ok' => false, 'detail' => 'Mail server for domain does not resolve (' . implode(', ', array_slice($hosts, 0, 2)) . ')'];
}

/**
 * Proof the mailbox is real, from our own history: the customer opened, clicked or replied to mail sent to this
 * address (and it did not bounce), or they emailed us from it. Such addresses never need a paid check.
 */
function email_has_engagement(array $customer, string $email): ?string
{
    $row = q_one(
        "SELECT direction, replied_at, first_clicked_at FROM email_messages
          WHERE workspace_id = ? AND customer_id = ?
            AND ((direction = 'inbound' AND from_email = ?)
              OR (direction = 'outbound' AND to_email = ? AND bounced_at IS NULL
                  AND (replied_at IS NOT NULL OR first_clicked_at IS NOT NULL OR first_opened_at IS NOT NULL)))
          ORDER BY (direction = 'inbound') DESC, (replied_at IS NOT NULL) DESC, (first_clicked_at IS NOT NULL) DESC
          LIMIT 1",
        [$customer['workspace_id'], $customer['id'], $email, $email]
    );
    if (!$row) {
        return null;
    }
    return match (true) {
        $row['direction'] === 'inbound' => 'they emailed us from this address',
        $row['replied_at'] !== null => 'they replied',
        $row['first_clicked_at'] !== null => 'they clicked a link',
        default => 'they opened an email',
    };
}

/* ---------------------------------------------------------------------------
 * Gate used by the queue
 * ------------------------------------------------------------------------- */

/**
 * Decide whether a campaign message may go to this customer. Cheapest first:
 *   1. free checks (email_precheck) on every send — failures block without calling SES;
 *   2. a stored result for the same address that is still fresh → send;
 *   3. role addresses (info@…) per EMAIL_ROLE_POLICY;
 *   4. proven by our own history (opened/clicked/replied/emailed us) → stored as valid, no SES;
 *   5. SES, when configured; the answer is stored for SES_VALIDATION_CACHE_DAYS.
 *
 * Returns ['send' => true] | ['send' => false, 'reason' => string] | ['defer' => seconds, 'reason' => string].
 * A blocked address marks the customer status 'invalid'. Setting the customer back to 'active' by hand is an
 * override: the stored 'invalid' check is kept, and mail is sent.
 */
function email_validation_gate(array $customer): array
{
    if (!EMAIL_PRECHECK_ENABLED && !SES_VALIDATION_ENABLED) {
        return ['send' => true];
    }
    $email = strtolower(trim((string) $customer['email']));
    $fresh = $customer['email_check'] !== null
        && $customer['email_checked_address'] === $email
        && $customer['email_checked_at'] >= date('Y-m-d H:i:s', time() - SES_VALIDATION_CACHE_DAYS * 86400);
    if ($fresh && $customer['email_check'] === 'invalid') {
        return ['send' => true]; // stored as invalid, yet the status is active again → manual override
    }

    if (EMAIL_PRECHECK_ENABLED) {
        $pre = email_precheck($email);
        if (!$pre['ok']) {
            return email_validation_block($customer, $email, 'invalid', $pre['detail']);
        }
    }
    if ($fresh) {
        return ['send' => true];
    }

    if (EMAIL_PRECHECK_ENABLED && EMAIL_ROLE_POLICY !== 'check' && email_is_role_address($email)) {
        if (EMAIL_ROLE_POLICY === 'block') {
            return email_validation_block($customer, $email, 'invalid', 'Role address (shared mailbox) — blocked by EMAIL_ROLE_POLICY');
        }
        return ['send' => true]; // 'send': role mailboxes almost always exist; not worth a paid check
    }

    if (EMAIL_PRECHECK_ENABLED && ($why = email_has_engagement($customer, $email))) {
        email_validation_store($customer, $email, 'valid', 'Proven by history: ' . $why);
        return ['send' => true];
    }

    if (!SES_VALIDATION_ENABLED) {
        return ['send' => true];
    }
    $r = ses_validate_email($email);
    if (!$r['ok']) {
        return ['defer' => $r['retry_after'], 'reason' => $r['error']];
    }
    if ($r['result'] === 'invalid' || ($r['result'] === 'risky' && SES_VALIDATION_BLOCK_RISKY)) {
        return email_validation_block($customer, $email, $r['result'], $r['detail']);
    }
    email_validation_store($customer, $email, $r['result'], $r['detail']);

    return ['send' => true];
}

function email_validation_store(array $customer, string $email, string $result, string $detail, bool $markInvalid = false): void
{
    q(
        'UPDATE customers SET email_check = ?, email_check_detail = ?, email_checked_address = ?, email_checked_at = ?'
        . ($markInvalid ? ", status = IF(status = 'active', 'invalid', status)" : '') . ', updated_at = ? WHERE id = ?',
        [$result, mb_substr($detail, 0, 255), $email, now(), now(), $customer['id']]
    );
}

/** Store a failing check on the customer, mark them 'invalid' (if active) and return the block decision. */
function email_validation_block(array $customer, string $email, string $result, string $detail): array
{
    email_validation_store($customer, $email, $result, $detail, true);

    return ['send' => false, 'reason' => 'Email failed validation (' . $result . '): ' . $detail];
}

/* ---------------------------------------------------------------------------
 * Learning from bounces
 * ------------------------------------------------------------------------- */

/**
 * Called for every hard bounce. When the bounce says the whole domain is unreachable (domain not found, no MX,
 * host unknown, connection timed out…) the domain is blocked, so every other contact there fails the free check
 * instead of being sent (or paid for). Big webmail providers are never blocked. Entries expire after
 * EMAIL_DOMAIN_BLOCK_DAYS without a new bounce.
 */
function email_learn_from_bounce(string $toEmail, string $bounceText): void
{
    $domain = email_domain($toEmail);
    if ($domain === '' || in_array($domain, EMAIL_SHARED_PROVIDERS, true)
        || preg_match('/^(yahoo|hotmail|outlook|live|gmx|yandex)\.[a-z.]+$/', $domain)) {
        return;
    }
    // Readable error text first (better stored reason), then the DSN status codes for "bad destination domain"
    $text = '/domain (?:name )?(?:not found|does not exist|doesn\'t exist)|no such domain|host (?:or domain name )?not found'
        . '|unrouteable (?:address|domain)|unable to route|no mx (?:record|for)|NXDOMAIN|name or service not known'
        . '|DNS Error|connection timed out|connection refused|no route to host/i';
    if (preg_match($text, $bounceText, $m)) {
        $reason = $m[0];
    } elseif (preg_match('/\b5\.(?:1\.2|4\.4)\b/', $bounceText, $m)) {
        $reason = 'status ' . $m[0] . ' (bad destination domain)';
    } else {
        return;
    }
    $reason = mb_substr(trim($reason), 0, 190);
    $now = now();
    try {
        q(
            'INSERT INTO blocked_email_domains (domain, reason, bounce_count, first_bounced_at, last_bounced_at) VALUES (?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), bounce_count = bounce_count + 1, last_bounced_at = VALUES(last_bounced_at)',
            [$domain, $reason, $now, $now]
        );
    } catch (Throwable) {
        // migration 020 not imported yet; the bounce itself is already recorded
    }
}
