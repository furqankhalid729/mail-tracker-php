-- Upgrade: cap how many contacts at the same company domain one campaign emails (e.g. 1 → only one of
--   pr@lomi.com / partnerships@lomi.com gets the email). The others are marked 'skipped' and kept as backups:
--   if the one emailed bounces or fails, the next backup is queued automatically.
--   Webmail domains (gmail.com, outlook.com…) are never grouped. NULL = no per-domain cap.
-- Run once after 020 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

-- campaigns.max_per_domain (added only if missing)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaigns' AND COLUMN_NAME = 'max_per_domain');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE campaigns ADD COLUMN max_per_domain SMALLINT UNSIGNED NULL AFTER daily_limit',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- campaign_contacts.system_status gains 'skipped' (re-running is harmless)
ALTER TABLE campaign_contacts
    MODIFY system_status ENUM('queued','sent','delivered','opened','clicked','replied','bounced','failed','skipped') NULL;
