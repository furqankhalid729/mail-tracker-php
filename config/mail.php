<?php
declare(strict_types=1);

/** System mailer settings (password resets etc). Campaign mail uses per-workspace mail accounts. */

define('SYSTEM_SMTP_HOST', (string) env('SYSTEM_SMTP_HOST', ''));
define('SYSTEM_SMTP_PORT', (int) env('SYSTEM_SMTP_PORT', 587));
define('SYSTEM_SMTP_ENCRYPTION', (string) env('SYSTEM_SMTP_ENCRYPTION', 'tls'));
define('SYSTEM_SMTP_USERNAME', (string) env('SYSTEM_SMTP_USERNAME', ''));
define('SYSTEM_SMTP_PASSWORD', (string) env('SYSTEM_SMTP_PASSWORD', ''));
define('SYSTEM_FROM_EMAIL', (string) env('SYSTEM_FROM_EMAIL', 'no-reply@localhost'));
define('SYSTEM_FROM_NAME', (string) env('SYSTEM_FROM_NAME', APP_NAME));

// Timeouts (seconds) used when talking to SMTP/IMAP servers from cron
const SMTP_TIMEOUT = 20;
const IMAP_TIMEOUT = 20;
