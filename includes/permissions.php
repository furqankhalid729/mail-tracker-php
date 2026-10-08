<?php
declare(strict_types=1);

/**
 * Workspace roles, highest first. A workspace can have several owners ("Super Admins").
 * Levels are used for "at least this role" checks via can().
 */
const ROLE_LEVELS = ['member' => 1, 'marketer' => 2, 'manager' => 3, 'admin' => 4, 'owner' => 5];

const ROLE_LABELS = [
    'owner' => 'Super Admin',
    'admin' => 'Admin',
    'manager' => 'Manager',
    'marketer' => 'Email Marketer',
    'member' => 'Member',
];

const ROLE_DESCRIPTIONS = [
    'owner' => 'Full control, including other Super Admins.',
    'admin' => 'Members, settings and mail accounts. Cannot change Super Admins.',
    'manager' => 'Assigns tasks to anyone, sees team progress, deletes and exports customers.',
    'marketer' => 'Runs campaigns and templates, imports customers, works on own tasks.',
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
