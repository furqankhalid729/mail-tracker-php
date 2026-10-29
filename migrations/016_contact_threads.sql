-- Upgrade: conversations with people who are not (yet) customers. Every mail sent from a connected
-- mailbox is imported; a thread then has contact_email and no customer until that address becomes one.
-- Run once after 015 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

-- email_threads.customer_id becomes optional
ALTER TABLE email_threads MODIFY customer_id INT UNSIGNED NULL;

-- email_threads.contact_email (added only if missing)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_threads' AND COLUMN_NAME = 'contact_email');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE email_threads ADD COLUMN contact_email VARCHAR(190) NULL AFTER customer_id, ADD KEY idx_threads_contact (workspace_id, contact_email)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
