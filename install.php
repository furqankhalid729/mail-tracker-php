<?php
/**
 * One-time web installer.
 *   - tests the database connection, creates tables from database.sql
 *   - writes .env with freshly generated secrets (or shows it for manual upload)
 *   - creates the first admin user + workspace
 * It locks itself (storage/installed.lock) afterwards. DELETE THIS FILE after installing.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

$lock = __DIR__ . '/storage/installed.lock';
function h(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

if (is_file($lock)) {
    http_response_code(403);
    exit('<!doctype html><meta charset="utf-8"><body style="font-family:system-ui;padding:40px"><h1>Already installed</h1><p>For security, delete <code>install.php</code> from the server.</p><p><a href="login.php">Go to login</a></p>');
}

session_start();
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$guessUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$f = array_merge([
    'app_url' => $guessUrl, 'timezone' => 'UTC', 'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'admin_name' => '', 'admin_email' => '', 'admin_password' => '', 'workspace' => '',
], array_map(fn($v) => is_string($v) ? trim($v) : '', $_POST));
$errors = [];
$envContent = null;
$envWrittenTo = null;
$done = false;

$checks = [
    'PHP 8.2+' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'OpenSSL' => extension_loaded('openssl'),
    'mbstring' => extension_loaded('mbstring'),
    'cURL' => extension_loaded('curl'),
    'fileinfo' => extension_loaded('fileinfo'),
    'DOM' => extension_loaded('dom'),
    'vendor/ (PHPMailer)' => is_file(__DIR__ . '/vendor/autoload.php'),
    'storage/ writable' => is_writable(__DIR__ . '/storage'),
    'uploads/ writable' => is_writable(__DIR__ . '/uploads'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['install_csrf'], (string) ($_POST['_csrf'] ?? ''))) {
        $errors[] = 'Session expired, reload the page.';
    }
    if (in_array(false, $checks, true)) {
        $errors[] = 'Fix the failed requirements first.';
    }
    if (!filter_var($f['app_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Application URL is invalid.';
    }
    if (!in_array($f['timezone'], DateTimeZone::listIdentifiers(), true)) {
        $errors[] = 'Unknown time zone.';
    }
    if ($f['admin_name'] === '' || !filter_var($f['admin_email'], FILTER_VALIDATE_EMAIL) || strlen($f['admin_password']) < 8) {
        $errors[] = 'Admin name, a valid email and a password of 8+ characters are required.';
    }

    if (!$errors) {
        try {
            $pdo = new PDO("mysql:host={$f['db_host']};port=" . (int) $f['db_port'] . ";dbname={$f['db_name']};charset=utf8mb4", $f['db_user'], $f['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            $errors[] = 'Database connection failed: ' . $e->getMessage();
        }
    }

    if (!$errors) {
        try {
            $exists = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
            if ($exists && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                throw new RuntimeException('This database already contains users. Installation aborted to protect existing data.');
            }
            $sql = (string) file_get_contents(__DIR__ . '/database.sql');
            $sql = preg_replace('/^--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', $sql))) as $stmt) {
                $pdo->exec($stmt);
            }

            $now = date('Y-m-d H:i:s');
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO workspaces (name, created_at, updated_at) VALUES (?, ?, ?)')->execute([$f['workspace'] ?: $f['admin_name'] . "'s Workspace", $now, $now]);
            $wsId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO users (name, email, password_hash, current_workspace_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$f['admin_name'], strtolower($f['admin_email']), password_hash($f['admin_password'], PASSWORD_DEFAULT), $wsId, $now, $now]);
            $userId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO workspace_members (workspace_id, user_id, role, created_at) VALUES (?, ?, 'owner', ?)")->execute([$wsId, $userId, $now]);
            $pdo->commit();

            $q = fn($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
            $envContent = implode("\n", [
                'APP_NAME="Mail CRM"',
                'APP_URL=' . rtrim($f['app_url'], '/'),
                'APP_ENV=production',
                'APP_TIMEZONE=' . $f['timezone'],
                'FORCE_HTTPS=' . (str_starts_with($f['app_url'], 'https://') ? 'true' : 'false'),
                'DB_HOST=' . $f['db_host'],
                'DB_PORT=' . (int) $f['db_port'],
                'DB_DATABASE=' . $f['db_name'],
                'DB_USERNAME=' . $f['db_user'],
                'DB_PASSWORD=' . $q($f['db_pass']),
                'ENCRYPTION_KEY=' . bin2hex(random_bytes(32)),
                'CRON_SECRET=' . bin2hex(random_bytes(24)),
                'WEBHOOK_SECRET=' . bin2hex(random_bytes(24)),
                'UPLOAD_LIMIT=10',
                'ALLOW_REGISTRATION=false',
                'SYSTEM_FROM_EMAIL=no-reply@' . (parse_url($f['app_url'], PHP_URL_HOST) ?: 'example.com'),
                '',
            ]);
            // Prefer a location outside public_html
            foreach ([dirname(__DIR__) . '/.env', __DIR__ . '/.env'] as $target) {
                if (!is_file($target) && is_writable(dirname($target)) && @file_put_contents($target, $envContent, LOCK_EX)) {
                    @chmod($target, 0600);
                    $envWrittenTo = $target;
                    break;
                }
            }
            file_put_contents($lock, date('c'));
            $done = true;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install Mail CRM</title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="bg-slate-50">
<div class="mx-auto max-w-2xl px-4 py-12">
    <h1 class="text-2xl font-semibold">Install Mail CRM</h1>
    <p class="mt-1 text-sm text-slate-500">Create the database tables, your admin account and the configuration file.</p>

    <?php if ($done): ?>
        <div class="card mt-6 p-6 space-y-4">
            <h2 class="text-lg font-semibold text-emerald-700">Installation complete</h2>
            <?php if ($envWrittenTo): ?>
                <p class="text-sm">Configuration written to <code><?= h($envWrittenTo) ?></code>.</p>
            <?php else: ?>
                <p class="text-sm">Could not write <code>.env</code> automatically. Create it <b>one folder above public_html</b> (or in the app folder) with this content:</p>
                <pre class="overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100"><?= h($envContent) ?></pre>
            <?php endif; ?>
            <div class="rounded-lg bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-amber-200"><b>Important:</b> delete <code>install.php</code> from the server now. It is locked, but should not stay public.</div>
            <p class="text-sm">Next: set up the cron jobs (Settings → Cron &amp; webhooks shows the exact commands), then add a mail account.</p>
            <a href="login.php" class="btn-primary">Go to login</a>
        </div>
    <?php else: ?>
        <div class="card mt-6 p-5">
            <h2 class="card-title">Requirements</h2>
            <ul class="mt-3 grid grid-cols-2 gap-2 text-sm">
                <?php foreach ($checks as $label => $ok): ?><li class="<?= $ok ? 'text-emerald-700' : 'text-red-600 font-medium' ?>"><?= $ok ? '✓' : '✗' ?> <?= h($label) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php foreach ($errors as $err): ?><div class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200"><?= h($err) ?></div><?php endforeach; ?>
        <form method="post" class="card mt-6 space-y-6 p-6">
            <input type="hidden" name="_csrf" value="<?= h($_SESSION['install_csrf']) ?>">
            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-2 text-sm font-semibold">Application</legend>
                <div class="sm:col-span-2"><label class="label">Application URL</label><input class="input" name="app_url" value="<?= h($f['app_url']) ?>" required></div>
                <div><label class="label">Time zone</label><input class="input" name="timezone" value="<?= h($f['timezone']) ?>" placeholder="Asia/Karachi"></div>
                <div><label class="label">Workspace name</label><input class="input" name="workspace" value="<?= h($f['workspace']) ?>" placeholder="Acme Sales"></div>
            </fieldset>
            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-2 text-sm font-semibold">MySQL database (create it in hPanel → Databases first)</legend>
                <div><label class="label">Host</label><input class="input" name="db_host" value="<?= h($f['db_host']) ?>" required></div>
                <div><label class="label">Port</label><input class="input" name="db_port" value="<?= h($f['db_port']) ?>" required></div>
                <div><label class="label">Database name</label><input class="input" name="db_name" value="<?= h($f['db_name']) ?>" required></div>
                <div><label class="label">Username</label><input class="input" name="db_user" value="<?= h($f['db_user']) ?>" required></div>
                <div class="sm:col-span-2"><label class="label">Password</label><input class="input" type="password" name="db_pass" value=""></div>
            </fieldset>
            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-2 text-sm font-semibold">Admin account</legend>
                <div><label class="label">Name</label><input class="input" name="admin_name" value="<?= h($f['admin_name']) ?>" required></div>
                <div><label class="label">Email</label><input class="input" type="email" name="admin_email" value="<?= h($f['admin_email']) ?>" required></div>
                <div class="sm:col-span-2"><label class="label">Password (8+ characters)</label><input class="input" type="password" name="admin_password" minlength="8" required></div>
            </fieldset>
            <button class="btn-primary w-full py-2.5">Install</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
