-- Upgrade: Employees (HR records) with contracts and other documents.
-- Run once after 006 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

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
