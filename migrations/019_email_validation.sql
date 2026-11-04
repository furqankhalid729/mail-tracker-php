-- Upgrade: Amazon SES email validation before campaign sends.
--   customers.status gains 'invalid' (failed validation → never gets campaign mail; set back to 'active' to override)
--   customers.email_check* store the last SES verdict so each address is checked (and paid for) once.
-- Run once after 018 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

ALTER TABLE customers MODIFY status ENUM('active','unsubscribed','bounced','invalid','archived') NOT NULL DEFAULT 'active';

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'email_check');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE customers
        ADD COLUMN email_check ENUM(''valid'',''risky'',''invalid'') NULL AFTER status,
        ADD COLUMN email_check_detail VARCHAR(255) NULL AFTER email_check,
        ADD COLUMN email_checked_address VARCHAR(190) NULL AFTER email_check_detail,
        ADD COLUMN email_checked_at DATETIME NULL AFTER email_checked_address,
        ADD KEY idx_customers_email_check (workspace_id, email_check)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
