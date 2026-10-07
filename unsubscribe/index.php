<?php
/**
 * Public unsubscribe page.
 *  GET  → confirmation page (GET never changes state; link scanners can't unsubscribe people)
 *  POST → unsubscribe. Also handles RFC 8058 one-click (List-Unsubscribe-Post) from mailbox providers.
 * The random per-message token authorises the request, so no session/CSRF is involved.
 */
define('NO_SESSION', true);
require_once __DIR__ . '/../includes/init.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$message = find_message_by_token($token);
$customer = $message && $message['customer_id'] ? q_one('SELECT * FROM customers WHERE id = ?', [$message['customer_id']]) : null;
$done = false;

if ($customer && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (rate_limit('unsub:' . client_ip(), 30, 600)) {
        unsubscribe_customer($customer, ($_POST['List-Unsubscribe'] ?? '') === 'One-Click' ? 'one-click' : 'link', $message);
    }
    if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
        http_response_code(200);
        exit('OK');
    }
    $done = true;
    $customer['unsubscribed_at'] = $customer['unsubscribed_at'] ?: now();
}
$workspaceName = $customer ? (string) q_val('SELECT name FROM workspaces WHERE id = ?', [$customer['workspace_id']]) : '';
$masked = $customer ? preg_replace('/(?<=.).(?=[^@]*@)/', '•', $customer['email']) : '';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Unsubscribe</title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-50 p-6">
<div class="card w-full max-w-md p-8 text-center">
    <?php if (!$customer): ?>
        <h1 class="text-lg font-semibold">Link not recognised</h1>
        <p class="mt-2 text-sm text-slate-500">This unsubscribe link is invalid. If you keep receiving emails, reply to one and ask to be removed.</p>
    <?php elseif ($done || $customer['unsubscribed_at']): ?>
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600"><?= icon('check', 'h-6 w-6') ?></div>
        <h1 class="mt-4 text-lg font-semibold">You're unsubscribed</h1>
        <p class="mt-2 text-sm text-slate-500"><?= e($masked) ?> will no longer receive campaign emails<?= $workspaceName ? ' from ' . e($workspaceName) : '' ?>.</p>
    <?php else: ?>
        <h1 class="text-lg font-semibold">Unsubscribe</h1>
        <p class="mt-2 text-sm text-slate-500">Stop receiving campaign emails at <b><?= e($masked) ?></b><?= $workspaceName ? ' from ' . e($workspaceName) : '' ?>?</p>
        <form method="post" class="mt-6">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <button class="btn-primary w-full py-2.5">Unsubscribe me</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
