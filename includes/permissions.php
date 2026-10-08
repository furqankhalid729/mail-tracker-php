<?php
declare(strict_types=1);

/**
 * Workspace roles, highest first. A workspace can have several owners ("Super Admins").
 * Levels are used for "at least this role" checks via can().
 */
const ROLE_LEVELS = ['closer' => 1, 'member' => 1, 'marketer' => 2, 'manager' => 3, 'admin' => 4, 'owner' => 5];

const ROLE_LABELS = [
    'owner' => 'Super Admin',
    'admin' => 'Admin',
    'manager' => 'Manager',
    'marketer' => 'Email Marketer',
    'closer' => 'Closer',
    'member' => 'Member',
];

const ROLE_DESCRIPTIONS = [
    'owner' => 'Full control, including other Super Admins.',
    'admin' => 'Members, settings and mail accounts. Cannot change Super Admins.',
    'manager' => 'Assigns tasks to anyone, sees team progress, deletes and exports customers.',
    'marketer' => 'Runs campaigns and templates, imports customers, works on own tasks.',
    'closer' => 'Only their assigned leads, the inbox, emailing customers, and a Zoom calls dashboard for their own numbers.',
    'member' => 'Works with customers and the inbox, views campaigns, works on own tasks.',
];

/** Permission => minimum role. The single place to change who can do what. */
const PERMISSIONS = [
    'campaigns.manage' => 'marketer',     // create, edit, start, pause, archive, delete, add contacts
    'templates.manage' => 'marketer',
    'customers.import' => 'marketer',
    'customers.export' => 'manager',
    'customers.delete' => 'manager',
    'tasks.manage_all' => 'manager',      // see every task, assign to anyone, edit/delete any task
    'team.view' => 'manager',             // team progress report
    'members.manage' => 'admin',
    'settings.manage' => 'admin',
    'mail_accounts.manage' => 'admin',
    'calls.view_all' => 'admin',          // every closer's calls, combined and per-closer stats
    'calls.manage' => 'admin',            // connect Zoom, assign numbers to closers
    'leads.view_all' => 'manager',        // see every lead, not only those assigned to you
    'leads.manage' => 'admin',            // import, add, edit, assign and delete leads; define lead statuses
];

/**
 * Closers are limited to these pages (paths relative to the app root; a trailing / means the whole folder).
 * Change this list to widen or narrow what a Closer can open.
 */
const CLOSER_PAGES = [
    'calls/index.php', 'calls/log.php',
    'leads/index.php', 'leads/view.php', 'leads/actions.php',
    'inbox/',
    'customers/index.php', 'customers/view.php', 'customers/email.php', 'customers/actions.php',
    'api/customers.php', 'api/search.php', 'api/kanban.php', 'api/tags.php',
    'settings/index.php', 'settings/workspace.php', 'logout.php',
];

function user_role(): string
{
    return (string) (current_workspace()['role'] ?? 'member');
}

function role_label(?string $role): string
{
    return ROLE_LABELS[$role] ?? ucfirst((string) $role);
}

function role_badge(?string $role): string
{
    $color = ['owner' => 'violet', 'admin' => 'indigo', 'manager' => 'blue', 'marketer' => 'sky'][$role] ?? 'slate';
    return '<span class="badge badge-' . $color . ' !normal-case">' . e(role_label($role)) . '</span>';
}

function is_closer(): bool
{
    return user_role() === 'closer';
}

/** Keep Closers inside CLOSER_PAGES. Called from require_auth() on every signed-in page. */
function enforce_closer_scope(): void
{
    if (!is_closer() || PHP_SAPI === 'cli') {
        return;
    }
    $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
    $root = realpath(APP_ROOT) ?: APP_ROOT;
    $path = ltrim(str_replace('\\', '/', substr($script, strlen($root))), '/');
    if (in_array($path, ['dashboard.php', 'index.php'], true)) {
        redirect('leads/index.php');
    }
    foreach (CLOSER_PAGES as $allowed) {
        if ($path === $allowed || (str_ends_with($allowed, '/') && str_starts_with($path, $allowed))) {
            return;
        }
    }
    abort(403, 'Closers can use Leads, Calls, the Inbox and customer emails only.');
}

function can(string $minRole): bool
{
    return (ROLE_LEVELS[user_role()] ?? 0) >= (ROLE_LEVELS[$minRole] ?? 99);
}

function require_role(string $minRole): void
{
    if (!can($minRole)) {
        abort(403, 'You do not have permission to do that.');
    }
}

function allowed(string $permission): bool
{
    return can(PERMISSIONS[$permission] ?? 'owner');
}

function require_permission(string $permission): void
{
    if (!allowed($permission)) {
        if (is_ajax()) {
            json_response(['ok' => false, 'error' => 'You do not have permission to do that.'], 403);
        }
        abort(403, 'Your role (' . role_label(user_role()) . ') does not have permission to do that.');
    }
}

/** Roles the current user may give to others: owners grant anything, admins anything below owner. */
function assignable_roles(): array
{
    if (!allowed('members.manage')) {
        return [];
    }
    return array_values(array_filter(array_keys(ROLE_LABELS), fn(string $r) => $r !== 'owner' || user_role() === 'owner'));
}

/** Whether the current user may change or remove a member who currently has $targetRole. */
function can_manage_member(string $targetRole): bool
{
    return allowed('members.manage') && ($targetRole !== 'owner' || user_role() === 'owner');
}

/**
 * Load a row by id scoped to the current workspace, or 404.
 * $table is always a hard-coded identifier from calling code, never user input.
 */
function find_or_404(string $table, int $id, string $columns = '*'): array
{
    $row = q_one("SELECT $columns FROM `$table` WHERE id = ? AND workspace_id = ?", [$id, ws_id()]);
    if (!$row) {
        abort(404, 'Record not found.');
    }
    return $row;
}

/** Filter a list of ids to those that belong to the current workspace. */
function owned_ids(string $table, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $params = [...$ids, ws_id()];
    return array_map('intval', q_col("SELECT id FROM `$table` WHERE id IN (" . placeholders($ids) . ') AND workspace_id = ?', $params));
}
