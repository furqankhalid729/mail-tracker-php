-- Upgrade: who ended each outbound Zoom Phone call (agent / external / unknown), from Zoom Phone webhooks
-- Run once after 003 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

-- Raw Zoom Phone webhook events we use (phone.caller_ended and the call-completed events), one row per call
-- and event; Zoom retries carry the same event_ts, so the unique key makes delivery idempotent.
CREATE TABLE IF NOT EXISTS zoom_call_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    call_id VARCHAR(64) NOT NULL,
    event VARCHAR(60) NOT NULL,
    event_ts BIGINT UNSIGNED NOT NULL DEFAULT 0,
    payload JSON NULL,
    received_at DATETIME NOT NULL,
    UNIQUE KEY uq_zce (workspace_id, call_id, event, event_ts),
    KEY idx_zce_received (workspace_id, received_at),
    CONSTRAINT fk_zce_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- zoom_calls.ended_by (added only if missing, so this file can be re-run). Existing calls stay 'unknown'.
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zoom_calls' AND COLUMN_NAME = 'ended_by');
SET @sql := IF(@has_col = 0,
    "ALTER TABLE zoom_calls ADD COLUMN ended_by ENUM('agent','external','unknown') NOT NULL DEFAULT 'unknown' AFTER is_connected, ADD KEY idx_zc_call_id (workspace_id, call_id)",
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
