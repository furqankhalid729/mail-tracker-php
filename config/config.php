<?php
/**
 * Application configuration.
 *
 * Values come from environment variables or a .env file. The .env file is looked up
 * one directory above the app first (outside public_html), then in the app root.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

(function (): void {
    foreach ([dirname(APP_ROOT) . '/.env', APP_ROOT . '/.env'] as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            if (getenv($key) === false && !isset($_ENV[$key])) {
                $_ENV[$key] = $value;
            }
        }
        break;
    }
})();

function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return match (strtolower((string) $value)) {
        'true' => true,
        'false' => false,
        'null' => null,
        default => $value,
    };
}

define('APP_NAME', (string) env('APP_NAME', 'Mail CRM'));
define('APP_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/'));
define('APP_ENV', (string) env('APP_ENV', 'production'));
define('APP_DEBUG', APP_ENV === 'local');
define('APP_TIMEZONE', (string) env('APP_TIMEZONE', 'UTC'));
define('FORCE_HTTPS', (bool) env('FORCE_HTTPS', false));

define('DB_HOST', (string) env('DB_HOST', 'localhost'));
define('DB_PORT', (int) env('DB_PORT', 3306));
define('DB_DATABASE', (string) env('DB_DATABASE', ''));
define('DB_USERNAME', (string) env('DB_USERNAME', ''));
define('DB_PASSWORD', (string) env('DB_PASSWORD', ''));

define('ENCRYPTION_KEY', (string) env('ENCRYPTION_KEY', ''));
define('CRON_SECRET', (string) env('CRON_SECRET', ''));
define('WEBHOOK_SECRET', (string) env('WEBHOOK_SECRET', ''));

define('UPLOAD_LIMIT_MB', (int) env('UPLOAD_LIMIT', 10));
define('UPLOAD_PATH', rtrim((string) env('UPLOAD_PATH', APP_ROOT . '/uploads'), '/\\'));
define('STORAGE_PATH', APP_ROOT . '/storage');

define('GOOGLE_CLIENT_ID', (string) env('GOOGLE_CLIENT_ID', ''));
define('GOOGLE_CLIENT_SECRET', (string) env('GOOGLE_CLIENT_SECRET', ''));
define('ZOOM_CLIENT_ID', (string) env('ZOOM_CLIENT_ID', ''));
define('ZOOM_CLIENT_SECRET', (string) env('ZOOM_CLIENT_SECRET', ''));
// Secret Token from the Zoom app's Event Subscriptions page; enables /webhooks/zoom.php (who ended each call)
define('ZOOM_WEBHOOK_SECRET', (string) env('ZOOM_WEBHOOK_SECRET', ''));
// Overridable only for testing against a mock server
define('ZOOM_OAUTH_BASE', rtrim((string) env('ZOOM_OAUTH_BASE', 'https://zoom.us'), '/'));
define('ZOOM_API_BASE', rtrim((string) env('ZOOM_API_BASE', 'https://api.zoom.us/v2'), '/'));
define('ALLOW_REGISTRATION', (bool) env('ALLOW_REGISTRATION', true));
