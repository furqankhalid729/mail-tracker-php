-- Upgrade: website inspector (speed, SEO, schema and technical audits with PDF reports). Developer tool at /inspector/.
-- Run once after 009 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS site_audits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    url VARCHAR(2048) NOT NULL,
    final_url VARCHAR(2048) NULL,
    status ENUM('running','done','failed') NOT NULL DEFAULT 'running',
    error VARCHAR(500) NULL,
    score TINYINT UNSIGNED NULL,
    score_performance TINYINT UNSIGNED NULL,
    score_seo TINYINT UNSIGNED NULL,
    score_schema TINYINT UNSIGNED NULL,
    score_technical TINYINT UNSIGNED NULL,
    -- Each step writes its own column, so the steps can run in parallel
    crawl MEDIUMTEXT NULL,
    psi_mobile MEDIUMTEXT NULL,
    psi_desktop MEDIUMTEXT NULL,
    result MEDIUMTEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    KEY idx_audits_ws_created (workspace_id, created_at),
    CONSTRAINT fk_audits_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_audits_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
