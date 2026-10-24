-- Upgrade: AI Campaigns — per-customer website/competitor research with OpenRouter, AI-written emails, auto-send.
-- Run once after 012 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ai_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status ENUM('draft','active','paused','completed','archived') NOT NULL DEFAULT 'draft',
    mail_account_id INT UNSIGNED NULL,
    model VARCHAR(120) NOT NULL,
    research_prompt TEXT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    keep_subject TINYINT(1) NOT NULL DEFAULT 0,
    email_template MEDIUMTEXT NOT NULL,
    web_search TINYINT(1) NOT NULL DEFAULT 1,
    last_error VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_ai_campaigns_status (workspace_id, status),
    CONSTRAINT fk_ai_campaigns_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_campaigns_account FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per customer in an AI campaign; also holds that customer's research + draft
CREATE TABLE IF NOT EXISTS ai_campaign_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    ai_campaign_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    draft_status ENUM('pending','processing','ready','queued','failed','skipped') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    research TEXT NULL,
    subject VARCHAR(255) NULL,
    html_body MEDIUMTEXT NULL,
    text_body MEDIUMTEXT NULL,
    prompt_tokens INT UNSIGNED NULL,
    completion_tokens INT UNSIGNED NULL,
    web_requests SMALLINT UNSIGNED NULL,
    cost_usd DECIMAL(10,6) NULL,
    error VARCHAR(500) NULL,
    email_message_id INT UNSIGNED NULL,
    lock_token VARCHAR(32) NULL,
    locked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_ai_cc (ai_campaign_id, customer_id),
    KEY idx_ai_cc_status (ai_campaign_id, draft_status),
    KEY idx_ai_cc_lock (lock_token),
    CONSTRAINT fk_ai_cc_campaign FOREIGN KEY (ai_campaign_id) REFERENCES ai_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_cc_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link sent mail back to its AI campaign (nullable; standard campaign mail is unaffected)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_messages' AND COLUMN_NAME = 'ai_campaign_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE email_messages ADD COLUMN ai_campaign_id INT UNSIGNED NULL AFTER campaign_id, ADD KEY idx_messages_ai_campaign (ai_campaign_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_jobs' AND COLUMN_NAME = 'ai_campaign_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE email_jobs ADD COLUMN ai_campaign_id INT UNSIGNED NULL AFTER campaign_id, ADD KEY idx_jobs_ai_campaign (ai_campaign_id, status)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_threads' AND COLUMN_NAME = 'ai_campaign_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE email_threads ADD COLUMN ai_campaign_id INT UNSIGNED NULL AFTER campaign_id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
