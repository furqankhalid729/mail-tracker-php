<?php
/**
 * Bootstrap shared by every web page, API endpoint and cron script.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/config/mail.php';
require_once APP_ROOT . '/config/constants.php';

date_default_timezone_set(APP_TIMEZONE);

ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');
error_reporting(E_ALL);

if (!is_file(APP_ROOT . '/vendor/autoload.php')) {
    http_response_code(500);
    exit('Dependencies missing: upload the vendor/ directory (run "composer install --no-dev" locally first).');
}
require_once APP_ROOT . '/vendor/autoload.php';

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/sanitizer.php';
require_once __DIR__ . '/mime.php';
require_once __DIR__ . '/tracking.php';
require_once __DIR__ . '/gmail.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/imap.php';
require_once __DIR__ . '/webhooks.php';
require_once __DIR__ . '/customers.php';
require_once __DIR__ . '/campaigns.php';
require_once __DIR__ . '/tasks.php';

set_exception_handler(function (Throwable $e): void {
    app_log('error', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (is_ajax()) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['ok' => false, 'error' => 'Something went wrong. Please try again.']);
        exit;
    }
    $detail = APP_DEBUG ? $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() : null;
    require __DIR__ . '/error-page.php';
    exit;
});

if (PHP_SAPI !== 'cli') {
    if (FORCE_HTTPS && !is_https()) {
        header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (!defined('NO_SESSION')) {
        start_secure_session();
    }
}
