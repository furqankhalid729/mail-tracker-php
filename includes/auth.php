<?php
declare(strict_types=1);

const SESSION_IDLE_TIMEOUT = 7200; // 2 hours

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $path = parse_url(APP_URL, PHP_URL_PATH) ?: '/';
    session_name('mailcrm_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => rtrim($path, '/') . '/',
        'secure' => is_https() || FORCE_HTTPS,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();

    $last = $_SESSION['_last_seen'] ?? null;
    if ($last && time() - $last > SESSION_IDLE_TIMEOUT && !empty($_SESSION['user_id'])) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('info', 'You were signed out after a period of inactivity.');
    }
    $_SESSION['_last_seen'] = time();
}

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = $_SESSION['user_id'] ?? null;
    $user = $id ? q_one('SELECT id, name, email, current_workspace_id, created_at FROM users WHERE id = ?', [$id]) : null;
    return $user;
}

function user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

/** Workspace of the logged-in user, always resolved server-side from the session. */
function current_workspace(): ?array
{
    static $ws = false;
    if ($ws !== false) {
        return $ws;
    }
    $user = current_user();
    if (!$user) {
        return $ws = null;
    }
    $wsId = (int) ($_SESSION['workspace_id'] ?? $user['current_workspace_id'] ?? 0);
    $ws = $wsId ? q_one(
        'SELECT w.*, m.role FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id AND m.user_id = ? WHERE w.id = ?',
        [$user['id'], $wsId]
    ) : null;
    if (!$ws) {
        // Fall back to the first workspace the user belongs to
        $ws = q_one(
            'SELECT w.*, m.role FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id WHERE m.user_id = ? ORDER BY m.id LIMIT 1',
            [$user['id']]
        );
        if ($ws) {
            $_SESSION['workspace_id'] = (int) $ws['id'];
            q('UPDATE users SET current_workspace_id = ? WHERE id = ?', [$ws['id'], $user['id']]);
        }
    }
    return $ws;
}

function ws_id(): int
{
    $ws = current_workspace();
    if (!$ws) {
        abort(403, 'No workspace selected.');
    }
    return (int) $ws['id'];
}

function require_auth(): array
{
    if (!current_user()) {
        if (is_ajax()) {
            json_response(['ok' => false, 'error' => 'Unauthenticated'], 401);
        }
        $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect('login.php');
    }
    if (!current_workspace()) {
        abort(403, 'Your account is not a member of any workspace.');
    }
    return current_user();
}

function require_guest(): void
{
    if (current_user()) {
        redirect('dashboard.php');
    }
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['workspace_id'] = (int) ($user['current_workspace_id'] ?? 0);
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    }
    session_destroy();
}

/** Create a user with a personal workspace (owner). Returns user id. */
function create_user_with_workspace(string $name, string $email, string $password, ?string $workspaceName = null): int
{
    return transaction(function () use ($name, $email, $password, $workspaceName) {
        $now = now();
        $wsId = db_insert('workspaces', [
            'name' => $workspaceName ?: ($name . "'s Workspace"),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = db_insert('users', [
            'name' => $name,
            'email' => strtolower($email),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'current_workspace_id' => $wsId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        db_insert('workspace_members', ['workspace_id' => $wsId, 'user_id' => $userId, 'role' => 'owner', 'created_at' => $now]);
        return $userId;
    });
}
