<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Validate the token from the form field or X-CSRF-Token header; aborts with 419 on failure. */
function verify_csrf(): void
{
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $sent)) {
        if (is_ajax()) {
            json_response(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
        }
        flash('error', 'Your session expired. Please try again.');
        redirect_back();
    }
}

/** Every state-changing endpoint calls this: POST only, valid CSRF token. */
function require_post(): void
{
    if (!is_post()) {
        abort(405, 'Method not allowed');
    }
    verify_csrf();
}
