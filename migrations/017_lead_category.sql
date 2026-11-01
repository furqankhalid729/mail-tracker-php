-- Upgrade: a category per lead (e.g. "Plumber", "Dentist"), picked from a CSV column on import and filterable.
-- Run once after 016 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

-- leads.category (added only if missing)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'category');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE leads ADD COLUMN category VARCHAR(150) NULL AFTER source, ADD KEY idx_leads_category (workspace_id, category)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
