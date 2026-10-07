<?php
declare(strict_types=1);

/** Shared PDO connection. Session time zone follows APP_TIMEZONE so NOW()/CURDATE() match PHP. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_DATABASE);
    $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $offset = (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P');
    $pdo->exec("SET time_zone = '{$offset}'");
    return $pdo;
}
