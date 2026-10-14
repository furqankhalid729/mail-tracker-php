-- Upgrade: monthly payroll for employees, and company expenses with recurring subscriptions.
-- Run once after 011 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

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

-- Everything is in PKR (APP_CURRENCY): employee salaries are PKR from now on
UPDATE employees SET salary_currency = 'PKR' WHERE salary_currency IS NULL OR salary_currency <> 'PKR';
