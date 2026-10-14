<?php
declare(strict_types=1);

/**
 * HTTP layer for the website inspector: parallel requests (curl_multi) that follow redirects by hand.
 *
 * SSRF protection: every hop (including links found on audited pages) must be http(s) on a hostname whose
 * addresses are all public. The connection is pinned to the checked IP with CURLOPT_RESOLVE, so a DNS answer
 * that changes between the check and the request cannot point it at an internal host.
 */

const INSPECTOR_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36 SiteInspector/1.0';

/** Validate an URL for fetching. Returns ['url', 'scheme', 'host', 'port', 'ip'] or an error message. */
function inspector_target(string $url): array|string
{
    $p = parse_url($url);
    $scheme = strtolower((string) ($p['scheme'] ?? ''));
    $host = strtolower(rtrim((string) ($p['host'] ?? ''), '.'));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return 'Only http(s) URLs can be inspected.';
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return 'URLs with credentials are not allowed.';
    }
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, [80, 443, 8080, 8443], true)) {
        return "Port $port is not allowed.";
    }
    $bareHost = trim($host, '[]');
    if (filter_var($bareHost, FILTER_VALIDATE_IP)) {
        $ips = [$bareHost];
    } else {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if (!$ips) {
            return "Could not resolve $host.";
        }
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return "$host points to a private or reserved address.";
        }
    }
    $ipv4 = array_values(array_filter($ips, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
    return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ipv4[0] ?? $ips[0]];
}

/** Resolve $href against $base (RFC 3986, enough for crawling). Returns null for non-http(s) links. */
function inspector_absolute_url(string $href, string $base): ?string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || preg_match('#^(mailto|tel|javascript|data|sms|whatsapp|ftp):#i', $href)) {
        return null;
    }
    $href = preg_replace('/#.*$/', '', $href);
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
        return null;
    }
    $b = parse_url($base);
    $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($href, '//')) {
        return ($b['scheme'] ?? 'https') . ':' . $href;
    }
    $basePath = $b['path'] ?? '/';
    if ($href === '') {
        return $origin . $basePath . (isset($b['query']) ? '?' . $b['query'] : '');
    }
    if ($href[0] === '?') {
        return $origin . $basePath . $href;
    }
    $path = $href[0] === '/' ? $href : substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $href;
    [$path, $query] = array_pad(explode('?', $path, 2), 2, null);
    $out = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '..') {
            if (count($out) > 1) {
                array_pop($out);
            }
        } elseif ($seg !== '.') {
            $out[] = $seg;
        }
    }
    $path = implode('/', $out);
    return $origin . ($path === '' || $path[0] !== '/' ? '/' : '') . $path . ($query !== null ? '?' . $query : '');
}

/**
 * Fetch several URLs in parallel. $requests: key => ['url', 'method' => 'GET'|'HEAD', 'max_bytes', 'follow' => bool,
 * 'timeout' => seconds, 'insecure' => bool]. Returns key => result with status, final_url, headers (lowercase name
 * => values[]), body, redirects[], timings, http_version, transfer size, ip and error.
 */
function inspector_fetch_many(array $requests): array
{
    $results = [];
    $pending = [];
    foreach ($requests as $k => $r) {
        $results[$k] = [
            'url' => $r['url'], 'final_url' => $r['url'], 'status' => 0, 'headers' => [], 'body' => '', 'redirects' => [],
            'error' => null, 'time' => [], 'total_time' => 0.0, 'http_version' => '', 'size' => 0, 'ip' => null,
        ];
        $pending[$k] = $r + ['method' => 'GET', 'max_bytes' => 2_000_000, 'follow' => true, 'timeout' => 15, 'insecure' => false, 'current' => $r['url']];
    }

    for ($hop = 0; $pending && $hop <= 6; $hop++) {
        $mh = curl_multi_init();
        $handles = $bufs = $targets = [];
        foreach ($pending as $k => $r) {
            $target = inspector_target($r['current']);
            if (is_string($target)) {
                $results[$k]['error'] = $target;
                unset($pending[$k]);
                continue;
            }
            $targets[$k] = $target;
            $bufs[$k] = ['headers' => [], 'body' => ''];
            $max = (int) $r['max_bytes'];
            $ch = curl_init($r['current']);
            curl_setopt_array($ch, [
                CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . (str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'])],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_NOBODY => $r['method'] === 'HEAD',
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => (int) $r['timeout'],
                CURLOPT_USERAGENT => INSPECTOR_UA,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.9'],
                CURLOPT_SSL_VERIFYPEER => !$r['insecure'],
                CURLOPT_SSL_VERIFYHOST => $r['insecure'] ? 0 : 2,
                CURLOPT_HTTP_VERSION => defined('CURL_HTTP_VERSION_2TLS') ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_1_1,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$bufs, $k) {
                    if (preg_match('#^HTTP/\S+\s+\d{3}#', $line)) {
                        $bufs[$k]['headers'] = []; // new response (after 100 Continue)
                    } elseif (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $bufs[$k]['headers'][strtolower(trim($name))][] = trim($value);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$bufs, $k, $max) {
                    $bufs[$k]['body'] .= $chunk;
                    return strlen($bufs[$k]['body']) > $max ? 0 : strlen($chunk);
                },
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$k] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $k => $ch) {
            $info = curl_getinfo($ch);
            $errno = curl_errno($ch);
            $res = &$results[$k];
            $res['total_time'] += (float) $info['total_time'];
            $httpCode = (int) $info['http_code'];
            if ($errno && $errno !== CURLE_WRITE_ERROR && !$httpCode) { // a write error only means the body was cut at max_bytes
                $res['error'] = curl_error($ch);
                $res['curl_errno'] = $errno;
                unset($pending[$k]);
            } else {
                $location = $bufs[$k]['headers']['location'][0] ?? null;
                if ($httpCode >= 300 && $httpCode < 400 && $location && $pending[$k]['follow'] && $hop < 6) {
                    $next = inspector_absolute_url($location, $pending[$k]['current']);
                    $res['redirects'][] = ['url' => $pending[$k]['current'], 'status' => $httpCode];
                    if ($next) {
                        $pending[$k]['current'] = $next;
                        unset($res);
                        curl_multi_remove_handle($mh, $ch);
                        curl_close($ch);
                        continue;
                    }
                }
                $res['status'] = $httpCode;
                $res['final_url'] = $pending[$k]['current'];
                $res['headers'] = $bufs[$k]['headers'];
                $res['body'] = $bufs[$k]['body'];
                $res['size'] = (int) $info['size_download'];
                $res['ip'] = $targets[$k]['ip'];
                $res['time'] = [
                    'dns' => round((float) $info['namelookup_time'] * 1000),
                    'connect' => round((float) $info['connect_time'] * 1000),
                    'tls' => round((float) curl_getinfo($ch, CURLINFO_APPCONNECT_TIME) * 1000),
                    'ttfb' => round((float) $info['starttransfer_time'] * 1000),
                    'total' => round((float) $info['total_time'] * 1000),
                ];
                $res['http_version'] = match ((int) $info['http_version']) {
                    1 => 'HTTP/1.0', 2 => 'HTTP/1.1', 3 => 'HTTP/2', 30 => 'HTTP/3', default => '',
                };
                unset($pending[$k]);
            }
            unset($res);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    foreach ($pending as $k => $r) {
        $results[$k]['error'] = 'Too many redirects.';
    }
    return $results;
}

/** First value of a response header (lowercase name). */
function inspector_header(array $res, string $name): ?string
{
    return $res['headers'][$name][0] ?? null;
}

/** TLS certificate of host (connecting to an already-checked IP). Returns details or ['error' => …]. */
function inspector_certificate(string $host, string $ip): array
{
    $ctx = stream_context_create(['ssl' => [
        'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false,
        'peer_name' => $host, 'SNI_enabled' => true,
    ]]);
    $addr = str_contains($ip, ':') ? "[$ip]" : $ip;
    $fp = @stream_socket_client("ssl://$addr:443", $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return ['error' => $errstr ?: 'Could not open a TLS connection.'];
    }
    $params = stream_context_get_params($fp);
    fclose($fp);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    $info = $cert ? openssl_x509_parse($cert) : false;
    if (!$info) {
        return ['error' => 'Could not read the certificate.'];
    }
    $names = [];
    foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $san) {
        if (str_starts_with(trim($san), 'DNS:')) {
            $names[] = strtolower(substr(trim($san), 4));
        }
    }
    $covers = false;
    foreach ($names as $n) {
        if ($n === $host || (str_starts_with($n, '*.') && str_ends_with($host, substr($n, 1)) && substr_count($host, '.') === substr_count($n, '.'))) {
            $covers = true;
        }
    }
    return [
        'issuer' => $info['issuer']['O'] ?? $info['issuer']['CN'] ?? '',
        'valid_from' => date('Y-m-d', (int) $info['validFrom_time_t']),
        'valid_to' => date('Y-m-d', (int) $info['validTo_time_t']),
        'days_left' => (int) floor(((int) $info['validTo_time_t'] - time()) / 86400),
        'covers_host' => $covers,
        'names' => array_slice($names, 0, 10),
    ];
}
