-- Upgrade: leave requests. Any member (Closers included) asks for leave; a Super Admin approves or rejects it.
-- Run once after 007 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS leave_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    leave_type ENUM('annual','sick','casual','unpaid','other') NOT NULL DEFAULT 'casual',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    half_day TINYINT(1) NOT NULL DEFAULT 0,
    days DECIMAL(5,1) NOT NULL DEFAULT 1.0,
    reason TEXT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_leaves_ws_status (workspace_id, status, start_date),
    KEY idx_leaves_user (user_id, start_date),
    CONSTRAINT fk_leaves_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_leaves_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_leaves_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
