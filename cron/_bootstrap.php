<?php
/**
 * Shared cron bootstrap.
 *  - CLI:  php /path/to/cron/process-email-queue.php
 *  - HTTP: GET/POST with "Authorization: Bearer CRON_SECRET" (preferred) or ?token=CRON_SECRET
 * Each job takes a MySQL named lock so overlapping runs exit immediately instead of piling up.
 */
define('NO_SESSION', true);
require_once dirname(__DIR__) . '/includes/init.php';

if (PHP_SAPI !== 'cli') {
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $sent = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : (string) ($_GET['token'] ?? '');
    if (CRON_SECRET === '' || strlen(CRON_SECRET) < 16 || !hash_equals(CRON_SECRET, $sent)) {
        http_response_code(401);
        header('Content-Type: text/plain');
        exit("Unauthorized\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    ignore_user_abort(true);
}
set_time_limit(CRON_MAX_RUNTIME + 30);

/** Run $fn under a named DB lock; skip if another run holds it. */
function cron_run(string $name, callable $fn): void
{
    $lockName = 'mailcrm:' . DB_DATABASE . ':' . $name;
    if ((int) q_val('SELECT GET_LOCK(?, 0)', [$lockName]) !== 1) {
        cron_output("[$name] another run is in progress, skipping");
        return;
    }
    $start = microtime(true);
    try {
        $result = $fn();
        cron_output("[$name] " . json_encode($result) . ' in ' . round(microtime(true) - $start, 2) . 's');
    } catch (Throwable $e) {
        app_log('error', "Cron $name failed", ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
        cron_output("[$name] ERROR: " . $e->getMessage());
        if (PHP_SAPI === 'cli') {
            exit(1);
        }
        http_response_code(500);
    } finally {
        q('SELECT RELEASE_LOCK(?)', [$lockName]);
    }
}

function cron_output(string $line): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL;
}
