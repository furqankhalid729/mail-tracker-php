<?php
/**
 * Tiny CLI test harness (no framework). Usage in a test file:
 *   require __DIR__ . '/_assert.php';
 *   t('name', function () { eq(1 + 1, 2); });
 *   t_done();
 * Runs against the local dev database; tests create and clean up their own rows.
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
define('NO_SESSION', true);
require_once dirname(__DIR__, 2) . '/includes/init.php';

$GLOBALS['__t'] = ['pass' => 0, 'fail' => 0];

final class AssertionFailed extends Exception {}

function t(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['__t']['pass']++;
        echo "PASS $name\n";
    } catch (Throwable $e) {
        $GLOBALS['__t']['fail']++;
        $where = $e instanceof AssertionFailed ? '' : ' (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        echo "FAIL $name: " . $e->getMessage() . $where . "\n";
    }
}

function eq(mixed $actual, mixed $expected, string $msg = ''): void
{
    if ($actual !== $expected) {
        throw new AssertionFailed(($msg !== '' ? $msg . ' — ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function ok(bool $cond, string $msg = ''): void
{
    if (!$cond) {
        throw new AssertionFailed($msg !== '' ? $msg : 'assertion failed');
    }
}

function t_done(): never
{
    ['pass' => $p, 'fail' => $f] = $GLOBALS['__t'];
    echo "\n$p passed, $f failed\n";
    exit($f > 0 ? 1 : 0);
}
