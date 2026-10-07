<?php
declare(strict_types=1);

const ROLE_LEVELS = ['member' => 1, 'admin' => 2, 'owner' => 3];

function user_role(): string
{
    return (string) (current_workspace()['role'] ?? 'member');
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
