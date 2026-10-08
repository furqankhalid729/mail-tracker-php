-- Upgrade: team roles (Manager, Email Marketer) + Tasks
-- Run once on an existing install (phpMyAdmin → Import, or: mysql -u USER -p DATABASE < migrations/001_roles_and_tasks.sql).
-- Fresh installs already get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

ALTER TABLE workspace_members
    MODIFY role ENUM('owner','admin','manager','marketer','member') NOT NULL DEFAULT 'member';

-- Team tasks: assigned to workspace members, optionally linked to a customer or campaign
CREATE TABLE IF NOT EXISTS tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status ENUM('backlog','todo','in_progress','review','done','cancelled') NOT NULL DEFAULT 'todo',
    priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
    assigned_to INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    customer_id INT UNSIGNED NULL,
    campaign_id INT UNSIGNED NULL,
    due_date DATE NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_tasks_ws_status (workspace_id, status, due_date),
    KEY idx_tasks_assignee (assigned_to, status),
    KEY idx_tasks_creator (created_by),
    KEY idx_tasks_customer (customer_id),
    CONSTRAINT fk_tasks_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Task timeline: comments, status and progress changes, reassignments
CREATE TABLE IF NOT EXISTS task_updates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    type ENUM('created','comment','status','progress','assigned','edited') NOT NULL,
    body TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_task_updates_task (task_id, created_at),
    CONSTRAINT fk_task_updates_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_updates_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_updates_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Installs that ran an earlier version of this file: add the Backlog status
ALTER TABLE tasks
    MODIFY status ENUM('backlog','todo','in_progress','review','done','cancelled') NOT NULL DEFAULT 'todo';
