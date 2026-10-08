-- Upgrade: Closer role + Zoom Phone call history and KPIs
-- Run once after 001 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

ALTER TABLE workspace_members
    MODIFY role ENUM('owner','admin','manager','marketer','closer','member') NOT NULL DEFAULT 'member';

-- Zoom Phone: one OAuth connection per workspace
CREATE TABLE IF NOT EXISTS zoom_connections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    zoom_account_id VARCHAR(64) NULL,
    zoom_email VARCHAR(190) NULL,
    encrypted_access_token TEXT NULL,
    encrypted_refresh_token TEXT NULL,
    token_expires_at DATETIME NULL,
    status ENUM('active','error','disconnected') NOT NULL DEFAULT 'active',
    last_error VARCHAR(500) NULL,
    synced_until DATE NULL,
    last_sync_at DATETIME NULL,
    connected_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_zoom_ws (workspace_id),
    CONSTRAINT fk_zoom_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phone numbers / extensions an admin assigns to each closer. number_key = last 10 digits, used for matching.
CREATE TABLE IF NOT EXISTS closer_numbers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    phone_number VARCHAR(40) NOT NULL,
    number_key VARCHAR(20) NOT NULL,
    label VARCHAR(100) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_closer_number (workspace_id, number_key),
    KEY idx_closer_numbers_user (user_id),
    CONSTRAINT fk_cn_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_cn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calls pulled from Zoom Phone call history. closer_user_id is derived from closer_numbers.
CREATE TABLE IF NOT EXISTS zoom_calls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    zoom_id VARCHAR(100) NOT NULL,
    call_id VARCHAR(64) NULL,
    direction VARCHAR(20) NULL,
    connect_type VARCHAR(30) NULL,
    call_type VARCHAR(30) NULL,
    call_result VARCHAR(40) NULL,
    is_connected TINYINT(1) NOT NULL DEFAULT 0,
    caller_name VARCHAR(190) NULL,
    caller_number VARCHAR(40) NULL,
    caller_ext VARCHAR(20) NULL,
    caller_key VARCHAR(20) NULL,
    callee_name VARCHAR(190) NULL,
    callee_number VARCHAR(40) NULL,
    callee_ext VARCHAR(20) NULL,
    callee_key VARCHAR(20) NULL,
    external_number VARCHAR(40) NULL,
    external_key VARCHAR(20) NULL,
    start_time DATETIME NOT NULL,
    answer_time DATETIME NULL,
    end_time DATETIME NULL,
    duration INT UNSIGNED NOT NULL DEFAULT 0,
    closer_user_id INT UNSIGNED NULL,
    closer_number_key VARCHAR(20) NULL,
    customer_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_zoom_call (workspace_id, zoom_id),
    KEY idx_zc_ws_time (workspace_id, start_time),
    KEY idx_zc_closer_time (closer_user_id, start_time),
    KEY idx_zc_caller (workspace_id, caller_key),
    KEY idx_zc_callee (workspace_id, callee_key),
    KEY idx_zc_customer (customer_id),
    CONSTRAINT fk_zc_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_zc_closer FOREIGN KEY (closer_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_zc_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
