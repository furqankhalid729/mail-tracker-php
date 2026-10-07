<?php
declare(strict_types=1);

/**
 * Small RFC 822 / MIME parser used for IMAP and Gmail messages.
 * No ext-imap or ext-mailparse needed, which keeps it portable on shared hosting.
 */

function mime_split(string $raw): array
{
    $raw = str_replace("\r\n", "\n", $raw);
    $pos = strpos($raw, "\n\n");
    if ($pos === false) {
        return [$raw, ''];
    }
    return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
}

/** Parse a header block into lowercase name => list of values (unfolded). */
function mime_parse_headers(string $block): array
{
    $block = preg_replace("/\n[ \t]+/", ' ', str_replace("\r\n", "\n", $block));
    $headers = [];
    foreach (explode("\n", $block) as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))][] = trim($value);
    }
    return $headers;
}

function mime_header(array $headers, string $name): ?string
{
    return $headers[strtolower($name)][0] ?? null;
}

function mime_decode_header(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (str_contains($value, '=?')) {
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($decoded !== false) {
            return $decoded;
        }
        return mb_decode_mimeheader($value);
    }
    return mime_to_utf8($value, null);
}

/** Split "type/subtype; a=b; c="d"" into [type, params]. */
function mime_parse_content_type(?string $value): array
{
    $value = (string) ($value ?: 'text/plain; charset=us-ascii');
    $parts = explode(';', $value);
    $type = strtolower(trim(array_shift($parts)));
    $params = [];
    if (preg_match_all('/([a-zA-Z0-9*_-]+)\s*=\s*("([^"]*)"|[^;\s]+)/', $value, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $params[strtolower(rtrim($match[1], '*'))] = isset($match[3]) && $match[3] !== '' ? $match[3] : trim($match[2], '"');
        }
    }
    return [$type, $params];
}

function mime_to_utf8(string $text, ?string $charset): string
{
    $charset = strtoupper(trim((string) $charset));
    if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    }
    $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
    if ($converted === false || $converted === '') {
        $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
    }
    return $converted !== false ? $converted : $text;
}

function mime_decode_body(string $body, ?string $encoding): string
{
    return match (strtolower(trim((string) $encoding))) {
        'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body)),
        'quoted-printable' => quoted_printable_decode($body),
        default => $body,
    };
}

/**
 * Parse a full raw message. Returns:
 *   headers, subject, from_email, from_name, to, message_id, in_reply_to, references[], date,
 *   text, html, attachments[], is_bounce, bounce_message_ids[], bounce_status
 */
function parse_raw_email(string $raw): array
{
    [$headBlock, $body] = mime_split($raw);
    $headers = mime_parse_headers($headBlock);

    $result = [
        'headers' => $headers,
        'subject' => mime_decode_header(mime_header($headers, 'subject')),
        'message_id' => extract_message_ids(mime_header($headers, 'message-id'))[0] ?? null,
        'in_reply_to' => extract_message_ids(mime_header($headers, 'in-reply-to'))[0] ?? null,
        'references' => extract_message_ids(mime_header($headers, 'references')),
        'date' => mime_header($headers, 'date'),
        'text' => '',
        'html' => '',
        'attachments' => [],
        'delivery_status' => '',
    ];
    [$result['from_email'], $result['from_name']] = parse_address(mime_header($headers, 'from'));
    [$result['to']] = parse_address(mime_header($headers, 'to'));

    mime_walk_part($headers, $body, $result, 0);

    // Bounce detection (DSN reports or mailer-daemon notices)
    [$ctype, $cparams] = mime_parse_content_type(mime_header($headers, 'content-type'));
    $from = strtolower($result['from_email'] ?? '');
    $isReport = $ctype === 'multipart/report' && strtolower($cparams['report-type'] ?? '') === 'delivery-status';
    $isDaemon = (bool) preg_match('/^(mailer-daemon|postmaster|mail-daemon)@/', $from)
        || preg_match('/(undeliver|delivery status notification|returned mail|delivery failure|failure notice)/i', $result['subject']);
    $result['is_bounce'] = $isReport || $isDaemon;
    $result['bounce_message_ids'] = [];
    $result['bounce_status'] = null;
    if ($result['is_bounce']) {
        $haystack = $result['delivery_status'] . "\n" . $result['text'] . "\n" . $body;
        if (preg_match_all('/^\s*Message-ID:\s*(<[^>]+>)/im', $haystack, $m)) {
            $result['bounce_message_ids'] = array_values(array_unique(array_map(fn($id) => trim($id, '<>'), $m[1])));
        }
        if (preg_match('/^Status:\s*([245]\.\d{1,3}\.\d{1,3})/im', $haystack, $sm)) {
            $result['bounce_status'] = $sm[1];
        }
        // A 4.x.x status is a delay notification, not a bounce
        if ($result['bounce_status'] && $result['bounce_status'][0] !== '5') {
            $result['is_bounce'] = false;
        }
    }
    return $result;
}

function mime_walk_part(array $headers, string $body, array &$result, int $depth): void
{
    if ($depth > 10) {
        return;
    }
    [$type, $params] = mime_parse_content_type(mime_header($headers, 'content-type'));
    $encoding = mime_header($headers, 'content-transfer-encoding');
    $disposition = strtolower((string) mime_header($headers, 'content-disposition'));

    if (str_starts_with($type, 'multipart/') && !empty($params['boundary'])) {
        $boundary = '--' . $params['boundary'];
        $sections = explode($boundary, str_replace("\r\n", "\n", $body));
        array_shift($sections); // preamble
        foreach ($sections as $section) {
            if (str_starts_with($section, '--')) {
                break; // closing boundary
            }
            $section = ltrim($section, "\n");
            [$h, $b] = mime_split($section);
            mime_walk_part(mime_parse_headers($h), rtrim($b, "\n"), $result, $depth + 1);
        }
        return;
    }

    if ($type === 'message/delivery-status') {
        $result['delivery_status'] .= mime_decode_body($body, $encoding) . "\n";
        return;
    }
    if ($type === 'message/rfc822' || $type === 'text/rfc822-headers') {
        // Original message inside a bounce: keep its headers for Message-ID matching
        $result['delivery_status'] .= mime_decode_body($body, $encoding) . "\n";
        return;
    }

    $filename = $params['name'] ?? null;
    if (preg_match('/filename\*?=(?:"([^"]+)"|([^;\s]+))/i', $disposition, $fm)) {
        $filename = $fm[1] ?: $fm[2];
    }
    if (str_starts_with($disposition, 'attachment') || ($filename && !str_starts_with($type, 'text/'))) {
        $result['attachments'][] = ['filename' => mime_decode_header($filename ?: 'attachment'), 'type' => $type];
        return;
    }

    $decoded = mime_to_utf8(mime_decode_body($body, $encoding), $params['charset'] ?? null);
    if ($type === 'text/html' && $result['html'] === '') {
        $result['html'] = $decoded;
    } elseif ($type === 'text/plain' && $result['text'] === '') {
        $result['text'] = $decoded;
    }
}

/** All <message-id> tokens in a header value, without angle brackets. */
function extract_message_ids(?string $value): array
{
    if (!$value) {
        return [];
    }
    if (preg_match_all('/<([^<>\s]+)>/', $value, $m)) {
        return array_values(array_unique($m[1]));
    }
    $v = trim($value);
    return $v !== '' && str_contains($v, '@') ? [$v] : [];
}

/** "Name <a@b.c>" → [email, name] */
function parse_address(?string $value): array
{
    $value = mime_decode_header($value);
    if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $value, $m)) {
        return [strtolower(trim($m[2])), trim($m[1])];
    }
    if (preg_match('/[A-Z0-9._%+\'-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $value, $m)) {
        return [strtolower($m[0]), ''];
    }
    return [null, ''];
}

/** Strip quoted history from a reply so the inbox shows just the new text. */
function strip_quoted_reply(string $text): string
{
    $lines = preg_split('/\R/', $text);
    $out = [];
    foreach ($lines as $line) {
        if (preg_match('/^On .+wrote:\s*$/i', trim($line)) || preg_match('/^-{2,}\s*Original Message\s*-{2,}/i', trim($line)) || preg_match('/^From:\s.+/i', trim($line)) && count($out) > 2) {
            break;
        }
        if (str_starts_with(ltrim($line), '>')) {
            continue;
        }
        $out[] = $line;
    }
    $result = trim(implode("\n", $out));
    return $result !== '' ? $result : trim($text);
}
