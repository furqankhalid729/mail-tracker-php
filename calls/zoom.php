<?php
/**
 * Zoom Phone connection (admins).
 *   POST action=connect     → redirect to Zoom's authorization URL
 *   GET  ?code&state        → OAuth callback: exchange the code server-side, store encrypted tokens
 *   POST action=sync        → pull call history now
 *   POST action=disconnect  → forget tokens (synced calls are kept)
 */
require_once __DIR__ . '/../includes/init.php';
require_auth();
require_permission('calls.manage');
$ws = ws_id();
$self = 'calls/zoom.php';

if (is_post()) {
    verify_csrf();
    $action = (string) input('action');
    if ($action === 'connect') {
        if (!zoom_enabled()) {
            flash('error', 'Add ZOOM_CLIENT_ID and ZOOM_CLIENT_SECRET to .env first.');
            redirect($self);
        }
        $_SESSION['zoom_oauth_state'] = random_token(24);
        redirect(zoom_auth_url($_SESSION['zoom_oauth_state']));
    }
    if ($action === 'sync') {
        set_time_limit(120);
        try {
            $r = zoom_sync($ws, 50);
            flash(isset($r['skipped']) ? 'info' : 'success', isset($r['skipped'])
                ? 'Sync skipped: ' . $r['skipped'] . '.'
                : number_format($r['fetched']) . ' calls synced.' . ($r['windows'] ? '' : ' More history will be fetched by cron.'));
        } catch (Throwable $e) {
            flash('error', 'Sync failed: ' . $e->getMessage());
        }
        redirect($self);
    }
    if ($action === 'disconnect') {
        q("UPDATE zoom_connections SET status = 'disconnected', encrypted_access_token = NULL, encrypted_refresh_token = NULL, token_expires_at = NULL, updated_at = ? WHERE workspace_id = ?", [now(), $ws]);
        flash('success', 'Zoom disconnected. Calls already synced are kept.');
        redirect($self);
    }
    redirect($self);
}

// OAuth callback
if (input('code') !== null || input('error') !== null) {
    $state = (string) input('state');
    $expected = $_SESSION['zoom_oauth_state'] ?? '';
    unset($_SESSION['zoom_oauth_state']);
    if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
        flash('error', 'Zoom sign-in could not be verified. Please try again.');
        redirect($self);
    }
    if (input('error')) {
        flash('error', 'Zoom connection was cancelled.');
        redirect($self);
    }
    try {
        $tokens = zoom_token_request([
            'grant_type' => 'authorization_code',
            'code' => (string) input('code'),
            'redirect_uri' => zoom_redirect_uri(),
        ]);
        $existing = zoom_connection($ws);
        $data = [
            'encrypted_access_token' => encrypt_value($tokens['access_token']),
            'encrypted_refresh_token' => encrypt_value($tokens['refresh_token'] ?? ''),
            'token_expires_at' => date('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 3600)),
            'status' => 'active',
            'last_error' => null,
            'connected_by' => user_id(),
            'updated_at' => now(),
        ];
        if ($existing) {
            db_update('zoom_connections', $data, 'id = ?', [$existing['id']]);
        } else {
            db_insert('zoom_connections', $data + ['workspace_id' => $ws, 'created_at' => now()]);
        }
        // Who authorised it (best effort: needs a user:read scope, which the app may not have)
        $conn = zoom_connection($ws);
        try {
            $me = zoom_api($conn, '/users/me');
            db_update('zoom_connections', ['zoom_email' => $me['email'] ?? null, 'zoom_account_id' => $me['account_id'] ?? null], 'id = ?', [$conn['id']]);
        } catch (Throwable) {
        }
        flash('success', 'Zoom connected. Call history is being fetched: use “Sync now” or wait for cron.');
    } catch (Throwable $e) {
        app_log('warning', 'Zoom OAuth failed', ['error' => $e->getMessage()]);
        flash('error', 'Could not connect Zoom: ' . $e->getMessage());
    }
    redirect($self);
}

$conn = zoom_connection($ws);
$connected = $conn && $conn['status'] !== 'disconnected' && $conn['encrypted_refresh_token'];
$callCount = (int) q_val('SELECT COUNT(*) FROM zoom_calls WHERE workspace_id = ?', [$ws]);
$unassigned = (int) q_val('SELECT COUNT(*) FROM zoom_calls WHERE workspace_id = ? AND closer_user_id IS NULL', [$ws]);
$range = q_one('SELECT MIN(start_time) first_call, MAX(start_time) last_call FROM zoom_calls WHERE workspace_id = ?', [$ws]);

$page_title = 'Zoom Phone';
$active_nav = 'calls';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <a href="<?= e(url('calls/index.php')) ?>" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"><?= icon('arrow-left', 'h-3.5 w-3.5') ?> Calls</a>
        <h1 class="page-title">Zoom Phone connection</h1>
        <p class="page-subtitle">Pulls your account's call history so every closer's calls, talk time and results show up in Calls.</p>
    </div>
    <a href="<?= e(url('calls/numbers.php')) ?>" class="btn-secondary"><?= icon('users', 'h-4 w-4') ?> Closer numbers</a>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Status</h2>
                <?php if ($connected): ?><?= status_badge($conn['status']) ?><?php else: ?><span class="badge badge-slate">Not connected</span><?php endif; ?>
            </div>
            <div class="card-body space-y-4">
                <?php if (!zoom_enabled()): ?>
                    <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">Zoom credentials are missing. Add <code>ZOOM_CLIENT_ID</code> and <code>ZOOM_CLIENT_SECRET</code> to <code>.env</code> (see the setup steps).</div>
                <?php endif; ?>
                <?php if ($conn && $conn['last_error'] && $conn['status'] === 'error'): ?>
                    <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200"><?= e($conn['last_error']) ?></div>
                <?php endif; ?>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">Calls stored</div><div class="text-lg font-semibold"><?= number_format($callCount) ?></div></div>
                    <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">Last sync</div><div class="font-medium"><?= e($conn['last_sync_at'] ?? null ? time_ago($conn['last_sync_at']) : 'never') ?></div></div>
                    <div class="rounded-lg bg-slate-50 p-3"><div class="text-xs text-slate-500">History range</div><div class="text-sm font-medium"><?= $range['first_call'] ? e(format_dt($range['first_call'], 'M j, Y')) . ' – ' . e(format_dt($range['last_call'], 'M j, Y')) : '—' ?></div></div>
                </div>
                <?php if ($conn && $conn['zoom_email']): ?><p class="text-sm text-slate-600">Authorised by <b><?= e($conn['zoom_email']) ?></b>.</p><?php endif; ?>
                <?php if ($unassigned): ?>
                    <p class="text-sm text-amber-700"><?= number_format($unassigned) ?> calls don't match any closer's number. <a class="font-medium underline" href="<?= e(url('calls/numbers.php')) ?>">Assign numbers</a> or <a class="font-medium underline" href="<?= e(url('calls/log.php', ['closer' => 'unassigned', 'range' => '30d'])) ?>">see them</a>.</p>
                <?php endif; ?>
                <div class="flex flex-wrap gap-2">
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="connect"><button class="btn-primary" <?= zoom_enabled() ? '' : 'disabled' ?>><?= icon('bolt', 'h-4 w-4') ?> <?= $connected ? 'Reconnect Zoom' : 'Connect Zoom' ?></button></form>
                    <?php if ($connected): ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><button class="btn-secondary"><?= icon('download', 'h-4 w-4') ?> Sync now</button></form>
                        <form method="post" data-confirm="Disconnect Zoom? Calls already synced stay; new calls stop arriving." data-confirm-button="Disconnect"><?= csrf_field() ?><input type="hidden" name="action" value="disconnect"><button class="btn-ghost text-red-600">Disconnect</button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card h-fit">
        <div class="card-header"><h2 class="card-title">Setup (one time)</h2></div>
        <ol class="card-body list-decimal space-y-3 pl-9 text-sm text-slate-600">
            <li>In the <a class="text-indigo-600 underline" href="https://marketplace.zoom.us/" target="_blank" rel="noopener">Zoom App Marketplace</a> → <b>Develop → Build App</b>, create a <b>General App</b> (user-managed off: <b>admin-managed</b>).</li>
            <li>OAuth redirect URL and allow list:<code class="mt-1 block break-all rounded bg-slate-100 px-2 py-1 text-xs"><?= e(zoom_redirect_uri()) ?></code></li>
            <li>Scopes: add <code class="rounded bg-slate-100 px-1 text-xs"><?= e(ZOOM_SCOPES_NEEDED) ?></code> (optional: <code class="rounded bg-slate-100 px-1 text-xs">user:read:user:admin</code> to show who connected).</li>
            <li>Copy the Client ID and Client Secret into <code>.env</code> as <code>ZOOM_CLIENT_ID</code> / <code>ZOOM_CLIENT_SECRET</code>.</li>
            <li>Click <b>Connect Zoom</b> and approve as a Zoom account admin with Zoom Phone.</li>
            <li>Add the cron job <code class="break-all text-xs">*/15 * * * * php <?= e(APP_ROOT) ?>/cron/sync-zoom-calls.php</code></li>
        </ol>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php';
