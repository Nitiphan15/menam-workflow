-- Production Planning: balance snapshots, alerts and mail audit
-- Target: application MySQL database. Safe to run repeatedly.

CREATE TABLE IF NOT EXISTS pp_production_balance_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_uuid CHAR(36) NOT NULL,
    trigger_type VARCHAR(20) NOT NULL DEFAULT 'SCHEDULE',
    status VARCHAR(20) NOT NULL DEFAULT 'RUNNING',
    source_as_of DATETIME NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    duration_ms INT UNSIGNED NULL,
    total_count INT UNSIGNED NOT NULL DEFAULT 0,
    excess_count INT UNSIGNED NOT NULL DEFAULT 0,
    short_count INT UNSIGNED NOT NULL DEFAULT 0,
    complete_count INT UNSIGNED NOT NULL DEFAULT 0,
    excess_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    short_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pp_balance_runs_uuid (run_uuid),
    KEY ix_pp_balance_runs_status_started (status, started_at),
    KEY ix_pp_balance_runs_source_as_of (source_as_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pp_production_balance_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    site VARCHAR(30) NOT NULL,
    customer VARCHAR(255) NOT NULL,
    item VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    unit VARCHAR(30) NULL,
    so_backorder DECIMAL(18,4) NOT NULL DEFAULT 0,
    fg_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    wip_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    mfg_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    need_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    excess_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    balance_status VARCHAR(20) NOT NULL,
    sales_orders_json JSON NULL,
    mfg_numbers_json JSON NULL,
    missing_so_mfg_json JSON NULL,
    created_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pp_balance_snapshot_run_item (run_id, fingerprint),
    KEY ix_pp_balance_snapshot_fingerprint (fingerprint),
    KEY ix_pp_balance_snapshot_status (balance_status),
    CONSTRAINT fk_pp_balance_snapshot_run FOREIGN KEY (run_id)
        REFERENCES pp_production_balance_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pp_production_balance_alerts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fingerprint CHAR(64) NOT NULL,
    site VARCHAR(30) NOT NULL,
    customer VARCHAR(255) NOT NULL,
    item VARCHAR(100) NOT NULL,
    last_alerted_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    alerted_at DATETIME NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pp_balance_alert_fingerprint (fingerprint),
    KEY ix_pp_balance_alert_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pp_production_balance_mail_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NULL,
    mail_status VARCHAR(30) NOT NULL,
    recipient_to TEXT NULL,
    recipient_cc TEXT NULL,
    row_count INT UNSIGNED NOT NULL DEFAULT 0,
    subject VARCHAR(255) NULL,
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_pp_balance_mail_run (run_id),
    KEY ix_pp_balance_mail_status_created (mail_status, created_at),
    CONSTRAINT fk_pp_balance_mail_run FOREIGN KEY (run_id)
        REFERENCES pp_production_balance_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
