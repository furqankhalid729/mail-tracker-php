-- Upgrade: domains learned from domain-level bounces (no MX, domain not found, timed out…).
--   Contacts at a blocked domain fail the free email check before SES is called (saves the SES fee).
--   Entries expire EMAIL_DOMAIN_BLOCK_DAYS after the last bounce; delete a row to unblock a domain now.
-- Run once after 019 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS blocked_email_domains (
    domain VARCHAR(190) NOT NULL PRIMARY KEY,
    reason VARCHAR(190) NULL,
    bounce_count INT UNSIGNED NOT NULL DEFAULT 1,
    first_bounced_at DATETIME NOT NULL,
    last_bounced_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
