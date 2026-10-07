<?php
declare(strict_types=1);

/**
 * Minimal validator.
 *
 *   $errors = validate($_POST, ['email' => 'required|email|max:190', 'port' => 'required|int|between:1,65535']);
 *
 * Returns field => first error message. Empty array means valid.
 */
function validate(array $data, array $rules, array $labels = []): array
{
    $errors = [];
    foreach ($rules as $field => $ruleString) {
        $value = $data[$field] ?? null;
        $value = is_string($value) ? trim($value) : $value;
        $label = $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
        $rulesList = explode('|', $ruleString);
        $isEmpty = $value === null || $value === '' || $value === [];

        foreach ($rulesList as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            if ($name !== 'required' && $isEmpty) {
                continue;
            }
            $error = match ($name) {
                'required' => $isEmpty ? "$label is required." : null,
                'email' => is_valid_email((string) $value) ? null : "$label must be a valid email address.",
                'max' => mb_strlen((string) $value) > (int) $arg ? "$label may not exceed $arg characters." : null,
                'min' => mb_strlen((string) $value) < (int) $arg ? "$label must be at least $arg characters." : null,
                'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : "$label must be a whole number.",
                'between' => (function () use ($value, $arg, $label) {
                    [$lo, $hi] = array_map('intval', explode(',', (string) $arg));
                    return ((int) $value < $lo || (int) $value > $hi) ? "$label must be between $lo and $hi." : null;
                })(),
                'url' => is_valid_http_url((string) $value) ? null : "$label must be a valid http(s) URL.",
                'in' => in_array((string) $value, explode(',', (string) $arg), true) ? null : "$label is invalid.",
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $value) ? null : "$label must be a hex color like #6366f1.",
                'same' => $value === ($data[$arg] ?? null) ? null : "$label confirmation does not match.",
                default => null,
            };
            if ($error !== null) {
                $errors[$field] = $error;
                break;
            }
        }
    }
    return $errors;
}

function is_valid_email(string $email): bool
{
    return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function is_valid_http_url(string $url): bool
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST);
}

/** Normalise a website: add https:// if missing, return null if unusable. */
function normalize_website(?string $site): ?string
{
    $site = trim((string) $site);
    if ($site === '') {
        return null;
    }
    if (!preg_match('#^https?://#i', $site)) {
        $site = 'https://' . $site;
    }
    return is_valid_http_url($site) ? mb_substr($site, 0, 255) : null;
}

/** Parse a comma/semicolon separated address list; returns [valid[], invalid[]]. */
function parse_email_list(?string $list): array
{
    $valid = $invalid = [];
    foreach (preg_split('/[,;\s]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY) as $addr) {
        $addr = trim($addr, " <>\t\"'");
        if (is_valid_email($addr)) {
            $valid[] = strtolower($addr);
        } else {
            $invalid[] = $addr;
        }
    }
    return [array_values(array_unique($valid)), $invalid];
}

/** Keep the submitted form for re-display after a validation redirect. */
function keep_old_input(): void
{
    $old = $_POST;
    unset($old['_csrf'], $old['password'], $old['password_confirmation'], $old['smtp_password'], $old['imap_password']);
    $_SESSION['_old'] = $old;
}

function clear_old_input(): void
{
    unset($_SESSION['_old']);
}

function field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<p class="field-error">' . e($errors[$field]) . '</p>' : '';
}
