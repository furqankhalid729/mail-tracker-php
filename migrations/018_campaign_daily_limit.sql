-- Upgrade: a daily sending cap per campaign (e.g. 25/day while warming up a new mailbox), editable any time.
-- NULL = no campaign cap (only the mail account and workspace limits apply).
-- Run once after 017 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

-- campaigns.daily_limit (added only if missing)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaigns' AND COLUMN_NAME = 'daily_limit');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE campaigns ADD COLUMN daily_limit INT UNSIGNED NULL AFTER template_id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
