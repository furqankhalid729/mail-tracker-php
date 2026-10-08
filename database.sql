-- Mail CRM schema (MySQL 5.7+/MariaDB 10.3+)
-- Import with: mysql -u USER -p DATABASE < database.sql   (or phpMyAdmin → Import)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    current_workspace_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspaces (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    emails_per_run SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    delay_ms INT UNSIGNED NOT NULL DEFAULT 1000,
    daily_limit INT UNSIGNED NOT NULL DEFAULT 200,
    track_opens TINYINT(1) NOT NULL DEFAULT 1,
    track_clicks TINYINT(1) NOT NULL DEFAULT 1,
    store_ip TINYINT(1) NOT NULL DEFAULT 1,
    ip_retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 90,
    company_address VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('owner','admin','manager','marketer','member') NOT NULL DEFAULT 'member',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_member (workspace_id, user_id),
    KEY idx_member_user (user_id),
    CONSTRAINT fk_wm_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_wm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    full_name VARCHAR(200) NULL,
    email VARCHAR(190) NOT NULL,
    company VARCHAR(190) NULL,
    job_title VARCHAR(150) NULL,
    phone VARCHAR(60) NULL,
    website VARCHAR(255) NULL,
    country VARCHAR(100) NULL,
    source VARCHAR(100) NULL,
    status ENUM('active','unsubscribed','bounced','archived') NOT NULL DEFAULT 'active',
    crm_status ENUM('New','Contacted','Interested','Qualified','Follow Up','Won','Lost') NOT NULL DEFAULT 'New',
    notes TEXT NULL,
    custom_fields JSON NULL,
    unsubscribed_at DATETIME NULL,
    last_activity_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_customers_email (workspace_id, email),
    KEY idx_customers_company (workspace_id, company),
    KEY idx_customers_status (workspace_id, status),
    KEY idx_customers_country (workspace_id, country),
    KEY idx_customers_activity (workspace_id, last_activity_at),
    KEY idx_customers_created (workspace_id, created_at),
    CONSTRAINT fk_customers_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    color VARCHAR(20) NOT NULL DEFAULT '#6366f1',
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_tags_name (workspace_id, name),
    CONSTRAINT fk_tags_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_tags (
    customer_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    workspace_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (customer_id, tag_id),
    KEY idx_ct_tag (tag_id, customer_id),
    KEY idx_ct_workspace (workspace_id),
    CONSTRAINT fk_ct_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_ct_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    html_body MEDIUMTEXT NULL,
    text_body MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_templates_ws (workspace_id),
    CONSTRAINT fk_templates_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    provider ENUM('smtp','gmail') NOT NULL DEFAULT 'smtp',
    email VARCHAR(190) NOT NULL,
    from_name VARCHAR(120) NULL,
    reply_to VARCHAR(190) NULL,
    smtp_host VARCHAR(190) NULL,
    smtp_port SMALLINT UNSIGNED NULL,
    smtp_username VARCHAR(190) NULL,
    encrypted_smtp_password TEXT NULL,
    smtp_encryption ENUM('tls','ssl','none') NULL DEFAULT 'tls',
    imap_host VARCHAR(190) NULL,
    imap_port SMALLINT UNSIGNED NULL,
    imap_encryption ENUM('ssl','tls','none') NULL DEFAULT 'ssl',
    imap_username VARCHAR(190) NULL,
    encrypted_imap_password TEXT NULL,
    imap_uidvalidity BIGINT UNSIGNED NULL,
    imap_last_uid BIGINT UNSIGNED NULL,
    inbox_checked_at DATETIME NULL,
    oauth_provider VARCHAR(30) NULL,
    encrypted_access_token TEXT NULL,
    encrypted_refresh_token TEXT NULL,
    oauth_expires_at DATETIME NULL,
    daily_limit INT UNSIGNED NOT NULL DEFAULT 100,
    status ENUM('active','error','disconnected') NOT NULL DEFAULT 'active',
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_mail_accounts_ws (workspace_id),
    CONSTRAINT fk_mail_accounts_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status ENUM('draft','active','paused','completed','archived') NOT NULL DEFAULT 'draft',
    mail_account_id INT UNSIGNED NULL,
    template_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_campaigns_status (workspace_id, status),
    CONSTRAINT fk_campaigns_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_campaigns_account FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_campaigns_template FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    campaign_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    system_status ENUM('queued','sent','delivered','opened','clicked','replied','bounced','failed') NULL,
    manual_status ENUM('New','Contacted','Interested','Qualified','Follow Up','Won','Lost') NOT NULL DEFAULT 'New',
    last_email_id INT UNSIGNED NULL,
    last_activity_at DATETIME NULL,
    assigned_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_campaign_customer (campaign_id, customer_id),
    KEY idx_cc_system (campaign_id, system_status),
    KEY idx_cc_manual (campaign_id, manual_status),
    KEY idx_cc_customer (customer_id),
    KEY idx_cc_pending (campaign_id, last_email_id),
    CONSTRAINT fk_cc_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_cc_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_threads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    campaign_id INT UNSIGNED NULL,
    mail_account_id INT UNSIGNED NULL,
    subject VARCHAR(255) NULL,
    provider_thread_id VARCHAR(190) NULL,
    is_unread TINYINT(1) NOT NULL DEFAULT 0,
    has_reply TINYINT(1) NOT NULL DEFAULT 0,
    last_message_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_threads_customer (customer_id, last_message_at),
    KEY idx_threads_ws (workspace_id, last_message_at),
    KEY idx_threads_provider (provider_thread_id),
    CONSTRAINT fk_threads_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_threads_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_threads_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    campaign_id INT UNSIGNED NULL,
    thread_id INT UNSIGNED NULL,
    mail_account_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    direction ENUM('outbound','inbound') NOT NULL DEFAULT 'outbound',
    message_id VARCHAR(255) NULL,
    provider_message_id VARCHAR(190) NULL,
    provider_thread_id VARCHAR(190) NULL,
    in_reply_to VARCHAR(255) NULL,
    references_header TEXT NULL,
    tracking_token CHAR(40) NULL,
    from_email VARCHAR(190) NULL,
    from_name VARCHAR(190) NULL,
    to_email VARCHAR(190) NULL,
    cc VARCHAR(1000) NULL,
    bcc VARCHAR(1000) NULL,
    subject VARCHAR(255) NULL,
    html_body MEDIUMTEXT NULL,
    text_body MEDIUMTEXT NULL,
    status ENUM('draft','queued','sending','sent','delivered','opened','clicked','replied','bounced','failed','cancelled','received') NOT NULL DEFAULT 'draft',
    error_message VARCHAR(500) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 1,
    queued_at DATETIME NULL,
    sent_at DATETIME NULL,
    delivered_at DATETIME NULL,
    first_opened_at DATETIME NULL,
    last_opened_at DATETIME NULL,
    first_clicked_at DATETIME NULL,
    last_clicked_at DATETIME NULL,
    replied_at DATETIME NULL,
    bounced_at DATETIME NULL,
    open_count INT UNSIGNED NOT NULL DEFAULT 0,
    click_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_messages_token (tracking_token),
    KEY idx_messages_customer (workspace_id, customer_id),
    KEY idx_messages_campaign (campaign_id, status),
    KEY idx_messages_thread (thread_id),
    KEY idx_messages_message_id (message_id(191)),
    KEY idx_messages_provider (provider_message_id),
    KEY idx_messages_account_sent (mail_account_id, sent_at),
    KEY idx_messages_ws_sent (workspace_id, sent_at),
    CONSTRAINT fk_messages_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_messages_thread FOREIGN KEY (thread_id) REFERENCES email_threads(id) ON DELETE SET NULL,
    CONSTRAINT fk_messages_account FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    email_message_id INT UNSIGNED NULL,
    customer_id INT UNSIGNED NULL,
    campaign_id INT UNSIGNED NULL,
    type ENUM('queued','sent','delivered','opened','clicked','replied','bounced','failed','unsubscribed') NOT NULL,
    metadata JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    dedupe_key VARCHAR(190) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_events_dedupe (dedupe_key),
    KEY idx_events_message (email_message_id, type),
    KEY idx_events_campaign (campaign_id, type, created_at),
    KEY idx_events_ws (workspace_id, created_at),
    KEY idx_events_ws_type (workspace_id, type, created_at),
    KEY idx_events_customer (customer_id, created_at),
    KEY idx_events_created (created_at),
    CONSTRAINT fk_events_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_message FOREIGN KEY (email_message_id) REFERENCES email_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    email_message_id INT UNSIGNED NOT NULL,
    campaign_id INT UNSIGNED NULL,
    status ENUM('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    lock_token CHAR(32) NULL,
    scheduled_at DATETIME NOT NULL,
    locked_at DATETIME NULL,
    processed_at DATETIME NULL,
    error_message VARCHAR(500) NULL,
    is_permanent_failure TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_jobs_message (email_message_id),
    KEY idx_jobs_status (status, scheduled_at),
    KEY idx_jobs_ws (workspace_id, status),
    KEY idx_jobs_locked (locked_at),
    KEY idx_jobs_lock_token (lock_token),
    KEY idx_jobs_campaign (campaign_id, status),
    CONSTRAINT fk_jobs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_jobs_message FOREIGN KEY (email_message_id) REFERENCES email_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_notes_customer (customer_id, created_at),
    CONSTRAINT fk_notes_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_notes_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    email_message_id INT UNSIGNED NULL,
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size INT UNSIGNED NOT NULL,
    storage_path VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_attachments_message (email_message_id),
    CONSTRAINT fk_attachments_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_attachments_message FOREIGN KEY (email_message_id) REFERENCES email_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unsubscribe_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    email VARCHAR(190) NOT NULL,
    email_message_id INT UNSIGNED NULL,
    campaign_id INT UNSIGNED NULL,
    action ENUM('unsubscribed','resubscribed') NOT NULL DEFAULT 'unsubscribed',
    source VARCHAR(50) NOT NULL DEFAULT 'link',
    user_id INT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_unsub_customer (customer_id),
    KEY idx_unsub_ws (workspace_id, created_at),
    CONSTRAINT fk_unsub_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_unsub_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NULL,
    provider VARCHAR(40) NOT NULL,
    provider_event_id VARCHAR(190) NOT NULL,
    event_type VARCHAR(60) NULL,
    payload MEDIUMTEXT NOT NULL,
    status ENUM('pending','processed','ignored','failed') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(500) NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_webhook_event (provider, provider_event_id),
    KEY idx_webhook_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CRM activity that is not an email event: campaign membership, status changes, notes, imports
CREATE TABLE IF NOT EXISTS activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    campaign_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    type VARCHAR(40) NOT NULL,
    description VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_activity_ws (workspace_id, created_at),
    KEY idx_activity_customer (customer_id, created_at),
    KEY idx_activity_campaign (campaign_id, created_at),
    CONSTRAINT fk_activity_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_activity_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_activity_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_reset_token (token_hash),
    CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_rate_bucket (bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Long-running bulk operations (e.g. tagging 50k customers) are chunked by cron instead of one HTTP request
CREATE TABLE IF NOT EXISTS bulk_jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    payload JSON NOT NULL,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    last_id INT UNSIGNED NOT NULL DEFAULT 0,
    processed INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_bulk_status (status),
    CONSTRAINT fk_bulk_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
