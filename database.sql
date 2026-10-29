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
    role ENUM('owner','admin','manager','marketer','closer','member') NOT NULL DEFAULT 'member',
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
    imap_sent_folder VARCHAR(190) NULL,
    imap_sent_uidvalidity BIGINT UNSIGNED NULL,
    imap_sent_last_uid BIGINT UNSIGNED NULL,
    sent_checked_at DATETIME NULL,
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
    ai_campaign_id INT UNSIGNED NULL,
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
    ai_campaign_id INT UNSIGNED NULL,
    KEY idx_messages_ai_campaign (ai_campaign_id),
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
    is_external TINYINT(1) NOT NULL DEFAULT 0,
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
    KEY idx_messages_ws_dir_sent (workspace_id, direction, sent_at),
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
    ai_campaign_id INT UNSIGNED NULL,
    KEY idx_jobs_ai_campaign (ai_campaign_id, status),
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

-- Statuses an admin defines per workspace; every status is also a filter on the leads list
CREATE TABLE IF NOT EXISTS lead_statuses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    color VARCHAR(20) NOT NULL DEFAULT '#64748b',
    requires_date TINYINT(1) NOT NULL DEFAULT 0,
    in_pending TINYINT(1) NOT NULL DEFAULT 0,
    requires_approval TINYINT(1) NOT NULL DEFAULT 0,
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
    follow_up_on DATE NULL,
    approval_id INT UNSIGNED NULL,
    approval_status ENUM('pending','approved','rejected') NULL,
    approval_by INT UNSIGNED NULL,
    approval_at DATETIME NULL,
    approval_note VARCHAR(500) NULL,
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
    KEY idx_leads_follow_up (workspace_id, follow_up_on),
    KEY idx_leads_approval (workspace_id, approval_status),
    KEY idx_leads_last_call (workspace_id, last_call_at),
    KEY idx_leads_attempts (workspace_id, total_attempts),
    KEY idx_leads_created (workspace_id, created_at),
    KEY idx_leads_name (workspace_id, name(100)),
    KEY idx_leads_source (workspace_id, source),
    CONSTRAINT fk_leads_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_leads_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_status FOREIGN KEY (status_id) REFERENCES lead_statuses(id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_approver FOREIGN KEY (approval_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_approval_req FOREIGN KEY (approval_id) REFERENCES lead_approvals(id) ON DELETE SET NULL
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

-- Approval requests for statuses that need a Super Admin (one per booking, kept for monthly per-closer counts)
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

-- Lead timeline: status changes (with optional note), notes, assignments, edits
CREATE TABLE IF NOT EXISTS lead_activity (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    lead_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    type ENUM('created','imported','status','note','assigned','edited','approval') NOT NULL,
    from_label VARCHAR(100) NULL,
    to_label VARCHAR(100) NULL,
    body TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_la_lead (lead_id, created_at),
    CONSTRAINT fk_la_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_la_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_la_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    recordings_error VARCHAR(500) NULL,
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
    ended_by ENUM('agent','external','unknown') NOT NULL DEFAULT 'unknown',
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
    lead_id INT UNSIGNED NULL,
    recording_id VARCHAR(100) NULL,
    recording_url VARCHAR(500) NULL,
    recording_duration INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_zoom_call (workspace_id, zoom_id),
    KEY idx_zc_ws_time (workspace_id, start_time),
    KEY idx_zc_closer_time (closer_user_id, start_time),
    KEY idx_zc_caller (workspace_id, caller_key),
    KEY idx_zc_callee (workspace_id, callee_key),
    KEY idx_zc_customer (customer_id),
    KEY idx_zc_lead (lead_id, start_time),
    KEY idx_zc_call_id (workspace_id, call_id),
    CONSTRAINT fk_zc_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_zc_closer FOREIGN KEY (closer_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_zc_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_zc_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Employees (HR records) and their contracts / documents
CREATE TABLE IF NOT EXISTS employees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    employee_code VARCHAR(50) NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(50) NULL,
    job_title VARCHAR(150) NULL,
    department VARCHAR(100) NULL,
    employment_type ENUM('full_time','part_time','contract','intern','freelance') NOT NULL DEFAULT 'full_time',
    status ENUM('working','probation','on_leave','notice_period','left','terminated') NOT NULL DEFAULT 'working',
    join_date DATE NULL,
    leave_date DATE NULL,
    leave_reason VARCHAR(500) NULL,
    date_of_birth DATE NULL,
    national_id VARCHAR(50) NULL,
    address TEXT NULL,
    emergency_contact_name VARCHAR(150) NULL,
    emergency_contact_phone VARCHAR(50) NULL,
    salary DECIMAL(12,2) NULL,
    salary_currency CHAR(3) NULL,
    bank_details VARCHAR(500) NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_employees_code (workspace_id, employee_code),
    KEY idx_employees_ws_status (workspace_id, status),
    KEY idx_employees_user (user_id),
    CONSTRAINT fk_employees_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_employees_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contracts, ID copies, CVs… Either an uploaded file (storage_path) or an external link (link_url), or both.
CREATE TABLE IF NOT EXISTS employee_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    doc_type ENUM('contract','offer_letter','id_document','resume','certificate','payslip','other') NOT NULL DEFAULT 'other',
    title VARCHAR(200) NOT NULL,
    filename VARCHAR(255) NULL,
    mime_type VARCHAR(100) NULL,
    size INT UNSIGNED NULL,
    storage_path VARCHAR(500) NULL,
    link_url VARCHAR(1000) NULL,
    expires_at DATE NULL,
    notes VARCHAR(500) NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_empdocs_employee (employee_id),
    KEY idx_empdocs_expiry (workspace_id, expires_at),
    CONSTRAINT fk_empdocs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_empdocs_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_empdocs_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Leave requests (any member asks, a Super Admin approves)
CREATE TABLE IF NOT EXISTS leave_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    leave_type ENUM('annual','sick','casual','unpaid','other') NOT NULL DEFAULT 'casual',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    half_day TINYINT(1) NOT NULL DEFAULT 0,
    days DECIMAL(5,1) NOT NULL DEFAULT 1.0,
    is_paid TINYINT(1) NOT NULL DEFAULT 0,
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

-- Wiki pages (Editor.js blocks; admins write, everyone reads)
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

-- Website inspector audits (developer tool at /inspector/)
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

-- Payroll, expenses and subscriptions
-- One payroll run per month (period = 'YYYY-MM'). Finalized runs are locked; lines can still be marked paid.
CREATE TABLE IF NOT EXISTS payroll_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    period CHAR(7) NOT NULL,
    status ENUM('draft','finalized') NOT NULL DEFAULT 'draft',
    notes VARCHAR(1000) NULL,
    created_by INT UNSIGNED NULL,
    finalized_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    finalized_at DATETIME NULL,
    UNIQUE KEY uq_payroll_period (workspace_id, period),
    CONSTRAINT fk_prun_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_prun_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_prun_finalizer FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One line (payslip) per employee per run. Name, title and currency are copied so old payslips never change.
CREATE TABLE IF NOT EXISTS payroll_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    run_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NULL,
    employee_name VARCHAR(150) NOT NULL,
    employee_code VARCHAR(50) NULL,
    job_title VARCHAR(150) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PKR',
    monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    days_in_period TINYINT UNSIGNED NOT NULL DEFAULT 30,
    days_employed TINYINT UNSIGNED NOT NULL DEFAULT 30,
    base_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
    bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
    allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
    unpaid_days DECIMAL(5,1) NOT NULL DEFAULT 0,
    unpaid_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax DECIMAL(12,2) NOT NULL DEFAULT 0,
    other_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
    note VARCHAR(500) NULL,
    status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    paid_on DATE NULL,
    payment_method VARCHAR(50) NULL,
    payment_reference VARCHAR(100) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_payroll_item (run_id, employee_id),
    KEY idx_pitem_employee (employee_id),
    CONSTRAINT fk_pitem_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_pitem_run FOREIGN KEY (run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_pitem_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recurring costs (Google Workspace, Hostinger…). "Record payment" adds an expense and moves next_due forward.
CREATE TABLE IF NOT EXISTS subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    vendor VARCHAR(150) NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'software',
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PKR',
    billing_cycle ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
    next_due DATE NULL,
    payment_method VARCHAR(50) NULL,
    manage_url VARCHAR(500) NULL,
    notes VARCHAR(1000) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_subs_ws_due (workspace_id, is_active, next_due),
    CONSTRAINT fk_subs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_subs_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every money-out except salaries (those are payroll). Optional receipt file stored under uploads/expenses/.
CREATE TABLE IF NOT EXISTS expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    expense_date DATE NOT NULL,
    title VARCHAR(200) NOT NULL,
    vendor VARCHAR(150) NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'other',
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PKR',
    payment_method VARCHAR(50) NULL,
    reference VARCHAR(100) NULL,
    subscription_id INT UNSIGNED NULL,
    notes VARCHAR(1000) NULL,
    receipt_path VARCHAR(500) NULL,
    receipt_name VARCHAR(255) NULL,
    receipt_mime VARCHAR(100) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_expenses_ws_date (workspace_id, expense_date),
    KEY idx_expenses_category (workspace_id, category),
    KEY idx_expenses_sub (subscription_id),
    CONSTRAINT fk_exp_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_exp_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- AI Campaigns: per-customer research + AI-written emails (see ai-campaigns/)
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
