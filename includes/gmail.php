<?php
declare(strict_types=1);

/**
 * Gmail / Google Workspace via OAuth 2.0 + Gmail REST API (plain cURL, no SDK).
 * Tokens are encrypted at rest and never sent to the browser.
 */

const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GMAIL_API = 'https://gmail.googleapis.com/gmail/v1/users/me';
const GMAIL_SCOPES = 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.readonly';

function gmail_enabled(): bool
{
    return GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '';
}

function gmail_redirect_uri(): string
{
    return url('mail-accounts/oauth.php');
}

function gmail_auth_url(string $state): string
{
    return GOOGLE_AUTH_URL . '?' . http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => gmail_redirect_uri(),
        'response_type' => 'code',
        'scope' => GMAIL_SCOPES,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
        'state' => $state,
    ]);
}

/** Minimal HTTP helper. Returns [status, decoded-json|null, raw]. */
function http_request(string $method, string $url, array $headers = [], string|array|null $body = null, int $timeout = 20): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : $body;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('HTTP request failed: ' . $err);
    }
    return [$status, json_decode((string) $raw, true), (string) $raw];
}

function gmail_exchange_code(string $code): array
{
    [$status, $json] = http_request('POST', GOOGLE_TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], [
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => gmail_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]);
    if ($status !== 200 || empty($json['access_token'])) {
        throw new RuntimeException('Google rejected the authorization: ' . ($json['error_description'] ?? $json['error'] ?? "HTTP $status"));
    }
    return $json;
}

/** Valid access token for an account; refreshes server-side when expired. */
function gmail_access_token(array &$account): string
{
    $expires = $account['oauth_expires_at'] ? strtotime($account['oauth_expires_at']) : 0;
    $token = decrypt_value($account['encrypted_access_token']);
    if ($token && $expires > time() + 60) {
        return $token;
    }
    $refresh = decrypt_value($account['encrypted_refresh_token']);
    if (!$refresh) {
        throw new RuntimeException('Gmail account is not connected (missing refresh token). Reconnect it.');
    }
    [$status, $json] = http_request('POST', GOOGLE_TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], [
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => $refresh,
        'grant_type' => 'refresh_token',
    ]);
    if ($status !== 200 || empty($json['access_token'])) {
        q("UPDATE mail_accounts SET status = 'error', last_error = ? WHERE id = ?", ['Token refresh failed: ' . ($json['error'] ?? $status), $account['id']]);
        throw new RuntimeException('Could not refresh Gmail token: ' . ($json['error_description'] ?? $json['error'] ?? "HTTP $status"));
    }
    $account['encrypted_access_token'] = encrypt_value($json['access_token']);
    $account['oauth_expires_at'] = date('Y-m-d H:i:s', time() + (int) ($json['expires_in'] ?? 3600));
    q(
        'UPDATE mail_accounts SET encrypted_access_token = ?, oauth_expires_at = ?, updated_at = ? WHERE id = ?',
        [$account['encrypted_access_token'], $account['oauth_expires_at'], now(), $account['id']]
    );
    return $json['access_token'];
}

function gmail_api(array &$account, string $method, string $path, ?array $json = null): array
{
    $token = gmail_access_token($account);
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    [$status, $data, $raw] = http_request($method, GMAIL_API . $path, $headers, $json !== null ? json_encode($json) : null, 30);
    if ($status < 200 || $status >= 300) {
        $msg = $data['error']['message'] ?? substr($raw, 0, 200);
        throw new RuntimeException("Gmail API error ($status): $msg", $status);
    }
    return $data ?? [];
}

function gmail_profile_email(string $accessToken): string
{
    [$status, $json] = http_request('GET', GMAIL_API . '/profile', ['Authorization: Bearer ' . $accessToken]);
    if ($status !== 200 || empty($json['emailAddress'])) {
        throw new RuntimeException('Could not read Gmail profile.');
    }
    return strtolower($json['emailAddress']);
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    return (string) base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Send a PHPMailer-built MIME message through the Gmail API.
 * Returns [providerMessageId, providerThreadId, actualMessageIdHeader].
 */
function gmail_send_raw(array &$account, string $mime, ?string $threadId = null): array
{
    $payload = ['raw' => base64url_encode($mime)];
    if ($threadId) {
        $payload['threadId'] = $threadId;
    }
    $sent = gmail_api($account, 'POST', '/messages/send', $payload);
    $actualMessageId = null;
    try {
        // Gmail may rewrite Message-ID; read back the real header so reply matching works
        $meta = gmail_api($account, 'GET', '/messages/' . rawurlencode($sent['id']) . '?format=metadata&metadataHeaders=Message-ID');
        foreach ($meta['payload']['headers'] ?? [] as $h) {
            if (strcasecmp($h['name'], 'Message-ID') === 0) {
                $actualMessageId = trim($h['value'], '<> ');
            }
        }
    } catch (Throwable) {
        // Non-fatal: keep our generated Message-ID
    }
    return [$sent['id'] ?? null, $sent['threadId'] ?? null, $actualMessageId];
}
