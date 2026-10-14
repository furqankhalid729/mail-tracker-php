-- Upgrade: Wiki (Notion-style pages). Admins write; everyone in the workspace reads and searches.
-- Run once after 008 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS wiki_pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    icon VARCHAR(16) NULL,
    content MEDIUMTEXT NULL,
    content_text MEDIUMTEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_wiki_tree (workspace_id, parent_id, sort_order),
    KEY idx_wiki_updated (workspace_id, updated_at),
    CONSTRAINT fk_wiki_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_wiki_parent FOREIGN KEY (parent_id) REFERENCES wiki_pages(id) ON DELETE SET NULL,
    CONSTRAINT fk_wiki_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_wiki_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
