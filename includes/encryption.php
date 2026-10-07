<?php
declare(strict_types=1);

/**
 * Authenticated encryption (AES-256-GCM) for credentials stored in the database.
 * The key lives in ENCRYPTION_KEY (environment / .env), never in the database.
 */

function encryption_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $raw = ENCRYPTION_KEY;
    if ($raw === '') {
        throw new RuntimeException('ENCRYPTION_KEY is not configured.');
    }
    if (str_starts_with($raw, 'base64:')) {
        $raw = base64_decode(substr($raw, 7), true) ?: '';
    } elseif (ctype_xdigit($raw) && strlen($raw) === 64) {
        $raw = hex2bin($raw);
    }
    if (strlen($raw) < 32) {
        throw new RuntimeException('ENCRYPTION_KEY must be at least 32 bytes (64 hex characters).');
    }
    // Derive a fixed-size key so any sufficiently long secret works
    return $key = hash_hkdf('sha256', $raw, 32, 'mailcrm-credentials');
}

function encrypt_value(?string $plain): ?string
{
    if ($plain === null || $plain === '') {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function decrypt_value(?string $encoded): ?string
{
    if ($encoded === null || $encoded === '' || !str_starts_with($encoded, 'v1:')) {
        return null;
    }
    $bin = base64_decode(substr($encoded, 3), true);
    if ($bin === false || strlen($bin) < 29) {
        return null;
    }
    $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', encryption_key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
    return $plain === false ? null : $plain;
}

/** HMAC signature used for click-tracking URLs and other tamper-proof links. */
function sign_value(string $value): string
{
    return substr(hash_hmac('sha256', $value, encryption_key()), 0, 32);
}
