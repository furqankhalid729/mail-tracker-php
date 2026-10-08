-- Upgrade: Call leads (imported business lists for closers), admin-defined lead statuses, Zoom calls linked to leads
-- Run once after 002 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

-- Statuses an admin defines per workspace; every status is also a filter on the leads list
CREATE TABLE IF NOT EXISTS lead_statuses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    color VARCHAR(20) NOT NULL DEFAULT '#64748b',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_lead_status_name (workspace_id, name),
    CONSTRAINT fk_ls_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Businesses to call. Call stats are cached from zoom_calls (see leads_refresh_stats()).
CREATE TABLE IF NOT EXISTS leads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    assigned_to INT UNSIGNED NULL,
    status_id INT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    website VARCHAR(255) NULL,
    emails VARCHAR(1000) NULL,
    address VARCHAR(500) NULL,
    rating DECIMAL(2,1) NULL,
    reviews_count INT UNSIGNED NULL,
    maps_url TEXT NULL,
    notes TEXT NULL,
    source VARCHAR(100) NULL,
    extra_fields JSON NULL,
    total_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    connected_calls INT UNSIGNED NOT NULL DEFAULT 0,
    last_call_at DATETIME NULL,
    last_call_result VARCHAR(40) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_leads_assigned (workspace_id, assigned_to),
    KEY idx_leads_status (workspace_id, status_id),
    KEY idx_leads_last_call (workspace_id, last_call_at),
    KEY idx_leads_attempts (workspace_id, total_attempts),
    KEY idx_leads_created (workspace_id, created_at),
    KEY idx_leads_name (workspace_id, name(100)),
    KEY idx_leads_source (workspace_id, source),
    CONSTRAINT fk_leads_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_leads_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_status FOREIGN KEY (status_id) REFERENCES lead_statuses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A lead can have several numbers. number_key = last 10 digits, matched against zoom_calls.external_key.
CREATE TABLE IF NOT EXISTS lead_phones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    lead_id INT UNSIGNED NOT NULL,
    phone_number VARCHAR(40) NOT NULL,
    number_key VARCHAR(20) NOT NULL,
    KEY idx_lp_key (workspace_id, number_key),
    KEY idx_lp_lead (lead_id),
    CONSTRAINT fk_lp_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_lp_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lead timeline: status changes (with optional note), notes, assignments, edits
CREATE TABLE IF NOT EXISTS lead_activity (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    lead_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    type ENUM('created','imported','status','note','assigned','edited') NOT NULL,
    from_label VARCHAR(100) NULL,
    to_label VARCHAR(100) NULL,
    body TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_la_lead (lead_id, created_at),
    CONSTRAINT fk_la_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_la_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_la_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- zoom_calls.lead_id (added only if missing, so this file can be re-run)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zoom_calls' AND COLUMN_NAME = 'lead_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE zoom_calls ADD COLUMN lead_id INT UNSIGNED NULL AFTER customer_id, ADD KEY idx_zc_lead (lead_id, start_time), ADD CONSTRAINT fk_zc_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
