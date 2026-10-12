-- Upgrade: statuses that require a date (Callback, Follow up, Presentation) and the date chosen on each lead
-- Run once after 004 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

-- lead_statuses.requires_date (added only if missing)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_statuses' AND COLUMN_NAME = 'requires_date');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE lead_statuses ADD COLUMN requires_date TINYINT(1) NOT NULL DEFAULT 0 AFTER color',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- leads.follow_up_on (added only if missing)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'follow_up_on');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE leads ADD COLUMN follow_up_on DATE NULL AFTER status_id, ADD KEY idx_leads_follow_up (workspace_id, follow_up_on)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Turn it on for the existing date statuses (admins can change this on the Lead statuses page)
UPDATE lead_statuses SET requires_date = 1 WHERE name IN ('Callback', 'Follow up', 'Presentation');
