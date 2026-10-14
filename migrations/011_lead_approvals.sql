-- Upgrade: Super Admin approval for lead statuses such as "Appointment booked".
-- A status flagged requires_approval opens an approval request (lead_approvals) for every lead set to it; a Super Admin
-- approves or disapproves it. Requests are kept per closer and month for reporting (Leads → Approvals).
-- Run once after 010 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

-- lead_statuses.requires_approval
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_statuses' AND COLUMN_NAME = 'requires_approval');
SET @sql := IF(@has_col = 0, 'ALTER TABLE lead_statuses ADD COLUMN requires_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER in_pending', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- One row per approval request: who booked it (the closer), and the Super Admin's decision.
-- Kept when the lead changes status or is deleted, so monthly counts stay correct.
CREATE TABLE IF NOT EXISTS lead_approvals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    lead_id INT UNSIGNED NULL,
    lead_name VARCHAR(255) NOT NULL DEFAULT '',
    status_id INT UNSIGNED NULL,
    status_name VARCHAR(80) NOT NULL DEFAULT '',
    booked_by INT UNSIGNED NULL,
    booked_at DATETIME NOT NULL,
    assigned_to INT UNSIGNED NULL,
    decision ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    note VARCHAR(500) NULL,
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_la_ws_booked (workspace_id, booked_at),
    KEY idx_la_ws_closer (workspace_id, booked_by, booked_at),
    KEY idx_la_lead (lead_id),
    CONSTRAINT fk_lappr_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_lappr_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL,
    CONSTRAINT fk_lappr_status FOREIGN KEY (status_id) REFERENCES lead_statuses(id) ON DELETE SET NULL,
    CONSTRAINT fk_lappr_booker FOREIGN KEY (booked_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_lappr_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_lappr_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- leads.approval_*: the lead's current request (approval_id) and a copy of its state for fast filters and badges
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'approval_status');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE leads ADD COLUMN approval_status ENUM(''pending'',''approved'',''rejected'') NULL AFTER follow_up_on, ADD COLUMN approval_by INT UNSIGNED NULL AFTER approval_status, ADD COLUMN approval_at DATETIME NULL AFTER approval_by, ADD COLUMN approval_note VARCHAR(500) NULL AFTER approval_at, ADD KEY idx_leads_approval (workspace_id, approval_status), ADD CONSTRAINT fk_leads_approver FOREIGN KEY (approval_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'approval_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE leads ADD COLUMN approval_id INT UNSIGNED NULL AFTER follow_up_on, ADD CONSTRAINT fk_leads_approval_req FOREIGN KEY (approval_id) REFERENCES lead_approvals(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Timeline entries for approval decisions
ALTER TABLE lead_activity MODIFY type ENUM('created','imported','status','note','assigned','edited','approval') NOT NULL;

-- Existing "Appointment booked" statuses need approval
UPDATE lead_statuses SET requires_approval = 1 WHERE LOWER(name) LIKE '%appointment%' AND LOWER(name) LIKE '%book%';

-- Leads already in those statuses get a pending request. The closer is whoever last set the status (from the
-- timeline), else the lead's assignee; the booking time is when the status was set, else the lead's last update.
INSERT INTO lead_approvals (workspace_id, lead_id, lead_name, status_id, status_name, booked_by, booked_at, assigned_to, decision, created_at)
SELECT l.workspace_id, l.id, LEFT(l.name, 255), s.id, s.name,
    COALESCE((SELECT a.user_id FROM lead_activity a WHERE a.lead_id = l.id AND a.type = 'status' AND a.to_label = s.name ORDER BY a.id DESC LIMIT 1), l.assigned_to),
    COALESCE((SELECT a.created_at FROM lead_activity a WHERE a.lead_id = l.id AND a.type = 'status' AND a.to_label = s.name ORDER BY a.id DESC LIMIT 1), l.updated_at),
    l.assigned_to, 'pending', NOW()
FROM leads l JOIN lead_statuses s ON s.id = l.status_id
WHERE s.requires_approval = 1 AND l.approval_id IS NULL AND (l.approval_status IS NULL OR l.approval_status = 'pending');

UPDATE leads l JOIN lead_approvals r ON r.lead_id = l.id AND r.decision = 'pending'
SET l.approval_id = r.id, l.approval_status = 'pending', l.approval_by = NULL, l.approval_at = NULL, l.approval_note = NULL
WHERE l.approval_id IS NULL;
