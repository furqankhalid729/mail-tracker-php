<?php
declare(strict_types=1);

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/** Validation errors carried across a redirect. */
function flash_errors(array $errors): void
{
    $_SESSION['_errors'] = $errors;
}

function get_errors(): array
{
    $e = $_SESSION['_errors'] ?? [];
    unset($_SESSION['_errors']);
    return $e;
}
