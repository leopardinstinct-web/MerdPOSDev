-- QR attendance, dispute approval, and resilient financial-submission beta.
--
-- Source-only migration. Apply to production only after backup, reconciliation,
-- and explicit migration/deployment approval.
-- All datetimes are UTC. Google Sheets remains a downstream reporting mirror.

CREATE TABLE IF NOT EXISTS attendance_device_keys (
  device_id INT NULL,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  public_key_b64 VARCHAR(64) NOT NULL,
  key_version INT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('active','revoked') NOT NULL DEFAULT 'active',
  registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  PRIMARY KEY (device_id),
  KEY idx_attendance_keys_scope (client_id, store_id, status),
  CONSTRAINT fk_attendance_key_device FOREIGN KEY (device_id) REFERENCES devices(id),
  CONSTRAINT fk_attendance_key_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_attendance_key_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_shifts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) NOT NULL,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  employee_id INT NOT NULL,
  device_id INT NULL,
  clock_in_at DATETIME NOT NULL,
  clock_out_at DATETIME NULL,
  status ENUM('open','closed','void') NOT NULL DEFAULT 'open',
  close_reason ENUM('qr','approved_dispute','super_override','none') NOT NULL DEFAULT 'none',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  open_employee_guard VARCHAR(80)
    GENERATED ALWAYS AS (
      CASE WHEN status = 'open' THEN CONCAT(client_id, ':', employee_id) ELSE NULL END
    ) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_shift_public (public_id),
  UNIQUE KEY uq_attendance_one_open_shift (open_employee_guard),
  KEY idx_attendance_working (client_id, status, store_id, clock_in_at),
  KEY idx_attendance_employee_time (client_id, employee_id, clock_in_at),
  CONSTRAINT fk_attendance_shift_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_attendance_shift_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_attendance_shift_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_attendance_shift_device FOREIGN KEY (device_id) REFERENCES devices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_qr_uses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  employee_id INT NOT NULL,
  shift_id BIGINT UNSIGNED NOT NULL,
  action ENUM('IN','OUT') NOT NULL,
  used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_qr_employee (token_hash, employee_id),
  KEY idx_attendance_qr_shift (shift_id),
  CONSTRAINT fk_attendance_qr_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_attendance_qr_shift FOREIGN KEY (shift_id) REFERENCES attendance_shifts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_disputes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) NOT NULL,
  client_id INT NOT NULL,
  shift_id BIGINT UNSIGNED NULL,
  employee_id INT NOT NULL,
  proposed_store_id INT NULL,
  dispute_type ENUM('missing_out','wrong_in','wrong_out','wrong_store','delete_shift','new_shift','other') NOT NULL,
  requested_clock_in_at DATETIME NULL,
  requested_clock_out_at DATETIME NULL,
  reason VARCHAR(1000) NOT NULL,
  origin ENUM('employee','pos_handover') NOT NULL DEFAULT 'employee',
  status ENUM('awaiting_employee','pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  employee_confirmed_at DATETIME NULL,
  decided_by_employee_id INT NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(1000) NULL,
  applied_at DATETIME NULL,
  before_snapshot LONGTEXT NOT NULL,
  after_snapshot LONGTEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_dispute_public (public_id),
  KEY idx_attendance_dispute_queue (client_id, status, submitted_at),
  CONSTRAINT fk_dispute_shift FOREIGN KEY (shift_id) REFERENCES attendance_shifts(id),
  CONSTRAINT fk_dispute_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_dispute_proposed_store FOREIGN KEY (proposed_store_id) REFERENCES stores(id),
  CONSTRAINT fk_dispute_decider FOREIGN KEY (decided_by_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_account_flags (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) NOT NULL,
  client_id INT NOT NULL,
  employee_id INT NOT NULL,
  open_shift_id BIGINT UNSIGNED NOT NULL,
  attempted_store_id INT NOT NULL,
  attempted_device_id INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_by_employee_id INT NULL,
  resolved_at DATETIME NULL,
  resolution_note VARCHAR(1000) NULL,
  open_employee_guard VARCHAR(80)
    GENERATED ALWAYS AS (CASE WHEN status='open' THEN CONCAT(client_id, ':', employee_id) ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_flag_public (public_id),
  UNIQUE KEY uq_attendance_one_open_flag (open_employee_guard),
  KEY idx_attendance_flags_queue (client_id,status,created_at),
  CONSTRAINT fk_attendance_flag_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_attendance_flag_shift FOREIGN KEY (open_shift_id) REFERENCES attendance_shifts(id),
  CONSTRAINT fk_attendance_flag_store FOREIGN KEY (attempted_store_id) REFERENCES stores(id),
  CONSTRAINT fk_attendance_flag_device FOREIGN KEY (attempted_device_id) REFERENCES devices(id),
  CONSTRAINT fk_attendance_flag_resolver FOREIGN KEY (resolved_by_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS financial_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) NOT NULL,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  employee_id INT NOT NULL,
  submission_type ENUM('open_day','cash_in','cash_out','z_report') NOT NULL,
  business_date DATE NOT NULL,
  payload LONGTEXT NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  status ENUM('accepted','sheet_pending','sheet_synced','sheet_failed','void') NOT NULL DEFAULT 'accepted',
  accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sheet_synced_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_financial_submission_public (public_id),
  KEY idx_financial_store_date (client_id, store_id, business_date),
  CONSTRAINT fk_financial_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_financial_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_financial_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS financial_day_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  business_date DATE NOT NULL,
  account ENUM('Register','Petty Cash') NOT NULL,
  opening_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  in_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  out_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  closing_amount DECIMAL(12,2) NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  opened_by_employee_id INT NOT NULL,
  closed_by_submission_id BIGINT UNSIGNED NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_financial_day_account (client_id,store_id,business_date,account),
  KEY idx_financial_day_status (client_id,store_id,status,business_date),
  CONSTRAINT fk_financial_day_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_financial_day_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_financial_day_opener FOREIGN KEY (opened_by_employee_id) REFERENCES employees(id),
  CONSTRAINT fk_financial_day_closer FOREIGN KEY (closed_by_submission_id) REFERENCES financial_submissions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS financial_ledger_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id BIGINT UNSIGNED NOT NULL,
  line_no SMALLINT UNSIGNED NOT NULL,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  business_date DATE NOT NULL,
  account ENUM('Register','Petty Cash') NOT NULL,
  entry_type ENUM('OPENING','IN','OUT','CLOSING') NOT NULL,
  head VARCHAR(120) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_financial_submission_line (submission_id,line_no),
  KEY idx_financial_ledger_statement (client_id,store_id,business_date,account,id),
  CONSTRAINT fk_financial_ledger_submission FOREIGN KEY (submission_id) REFERENCES financial_submissions(id),
  CONSTRAINT fk_financial_ledger_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_financial_ledger_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS google_sheet_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id CHAR(36) NOT NULL,
  client_id INT NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  aggregate_type VARCHAR(64) NOT NULL,
  aggregate_id VARCHAR(80) NOT NULL,
  payload LONGTEXT NOT NULL,
  status ENUM('pending','processing','synced','failed') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  synced_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sheet_outbox_event (event_id),
  KEY idx_sheet_outbox_ready (status, available_at, id),
  KEY idx_sheet_outbox_aggregate (aggregate_type, aggregate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Verification only.
SELECT table_name
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
    'attendance_device_keys','attendance_shifts','attendance_qr_uses',
    'attendance_disputes','attendance_account_flags','financial_submissions','financial_day_accounts',
    'financial_ledger_entries','google_sheet_outbox'
  )
ORDER BY table_name;
