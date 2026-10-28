-- Upgrade: a Super Admin marks each leave request as paid or unpaid. Unpaid is the default;
-- approved unpaid leave is deducted on payroll.
-- Run once after 013 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

-- leave_requests.is_paid (added only if missing)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'is_paid');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE leave_requests ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0 AFTER days',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
