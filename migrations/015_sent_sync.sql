-- Upgrade: sync each mailbox's Sent folder (IMAP) / SENT label (Gmail) so mail sent from other
-- clients (Outlook, Gmail web, phone) shows up in the CRM. Imported rows are flagged is_external.
-- Run once after 014 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

-- mail_accounts.imap_sent_folder / imap_sent_uidvalidity / imap_sent_last_uid / sent_checked_at (added only if missing)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_accounts' AND COLUMN_NAME = 'imap_sent_folder');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE mail_accounts
        ADD COLUMN imap_sent_folder VARCHAR(190) NULL AFTER inbox_checked_at,
        ADD COLUMN imap_sent_uidvalidity BIGINT UNSIGNED NULL AFTER imap_sent_folder,
        ADD COLUMN imap_sent_last_uid BIGINT UNSIGNED NULL AFTER imap_sent_uidvalidity,
        ADD COLUMN sent_checked_at DATETIME NULL AFTER imap_sent_last_uid',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- email_messages.is_external (added only if missing)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_messages' AND COLUMN_NAME = 'is_external');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE email_messages ADD COLUMN is_external TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index for the inbox "Sent" view (added only if missing)
SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_messages' AND INDEX_NAME = 'idx_messages_ws_dir_sent');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE email_messages ADD KEY idx_messages_ws_dir_sent (workspace_id, direction, sent_at)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
