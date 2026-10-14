-- Upgrade: Super Admin approval for lead statuses such as "Appointment booked".
-- A status flagged requires_approval puts every lead set to it into "awaiting approval"; a Super Admin approves or disapproves.
-- Run once after 010 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

-- lead_statuses.requires_approval
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_statuses' AND COLUMN_NAME = 'requires_approval');
SET @sql := IF(@has_col = 0, 'ALTER TABLE lead_statuses ADD COLUMN requires_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER in_pending', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- leads.approval_* (the current decision for a lead in an approval status)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'approval_status');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE leads ADD COLUMN approval_status ENUM(''pending'',''approved'',''rejected'') NULL AFTER follow_up_on, ADD COLUMN approval_by INT UNSIGNED NULL AFTER approval_status, ADD COLUMN approval_at DATETIME NULL AFTER approval_by, ADD COLUMN approval_note VARCHAR(500) NULL AFTER approval_at, ADD KEY idx_leads_approval (workspace_id, approval_status), ADD CONSTRAINT fk_leads_approver FOREIGN KEY (approval_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Timeline entries for approval decisions
ALTER TABLE lead_activity MODIFY type ENUM('created','imported','status','note','assigned','edited','approval') NOT NULL;

-- Existing "Appointment booked" statuses need approval, and their leads start as awaiting approval
UPDATE lead_statuses SET requires_approval = 1 WHERE LOWER(name) LIKE '%appointment%' AND LOWER(name) LIKE '%book%';
UPDATE leads l JOIN lead_statuses s ON s.id = l.status_id
SET l.approval_status = 'pending'
WHERE s.requires_approval = 1 AND l.approval_status IS NULL;
