-- MERDPOS controlled legacy Google -> SQL migration subsystem.
-- DEV-only orchestration; spreadsheet credentials/URLs are never stored here.
-- Public Google spreadsheet IDs and tab names are configuration, not secrets.

CREATE TABLE IF NOT EXISTS client_legacy_sources (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT NOT NULL,
  source_type ENUM('attendance','financial') NOT NULL,
  provider ENUM('google_public_csv') NOT NULL DEFAULT 'google_public_csv',
  spreadsheet_id VARCHAR(160) NOT NULL,
  sheet_names_json LONGTEXT NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_by_employee_id INT NULL,
  updated_by_employee_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_client_legacy_source (client_id,source_type),
  KEY idx_client_legacy_source_status (client_id,status,source_type),
  CONSTRAINT fk_client_legacy_source_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_migration_state (
  client_id INT NOT NULL,
  attendance_authority ENUM('google_legacy','merdpos_sql') NOT NULL DEFAULT 'google_legacy',
  financial_authority ENUM('google_legacy','merdpos_sql') NOT NULL DEFAULT 'google_legacy',
  last_preview_batch_id BIGINT UNSIGNED NULL,
  last_sync_batch_id BIGINT UNSIGNED NULL,
  attendance_cutover_at DATETIME NULL,
  financial_cutover_at DATETIME NULL,
  cutover_by_employee_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (client_id),
  CONSTRAINT fk_client_migration_state_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS legacy_migration_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) NOT NULL,
  client_id INT NOT NULL,
  mode ENUM('preview','sync','final') NOT NULL,
  status ENUM('running','staged','completed','completed_with_conflicts','failed') NOT NULL DEFAULT 'running',
  started_by_employee_id INT NOT NULL,
  source_snapshot_hash CHAR(64) NULL,
  attendance_rows INT UNSIGNED NOT NULL DEFAULT 0,
  financial_rows INT UNSIGNED NOT NULL DEFAULT 0,
  inserted_rows INT UNSIGNED NOT NULL DEFAULT 0,
  updated_rows INT UNSIGNED NOT NULL DEFAULT 0,
  unchanged_rows INT UNSIGNED NOT NULL DEFAULT 0,
  conflict_rows INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_rows INT UNSIGNED NOT NULL DEFAULT 0,
  warning_rows INT UNSIGNED NOT NULL DEFAULT 0,
  summary_json LONGTEXT NULL,
  error_message VARCHAR(1000) NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_legacy_migration_batch_public (public_id),
  KEY idx_legacy_migration_batches_client (client_id,started_at,id),
  CONSTRAINT fk_legacy_migration_batch_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS legacy_migration_stage_rows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id BIGINT UNSIGNED NOT NULL,
  client_id INT NOT NULL,
  source_type ENUM('attendance_log','employee_setup','payrate','start_time','financial') NOT NULL,
  sheet_name VARCHAR(160) NOT NULL,
  source_row_no INT UNSIGNED NOT NULL,
  source_key VARCHAR(255) NOT NULL,
  content_hash CHAR(64) NOT NULL,
  payload_redacted LONGTEXT NOT NULL,
  validation_status ENUM('valid','warning','conflict','rejected') NOT NULL DEFAULT 'valid',
  resolution_code VARCHAR(80) NULL,
  resolution_message VARCHAR(1000) NULL,
  matched_employee_id INT NULL,
  matched_store_id INT NULL,
  target_table VARCHAR(80) NULL,
  target_key VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_legacy_stage_source (batch_id,source_type,source_key),
  KEY idx_legacy_stage_status (batch_id,validation_status,source_type),
  KEY idx_legacy_stage_client_source (client_id,source_type,source_key),
  CONSTRAINT fk_legacy_stage_batch FOREIGN KEY (batch_id) REFERENCES legacy_migration_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_legacy_stage_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS legacy_migration_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT NOT NULL,
  source_type ENUM('attendance_log','attendance_shift','employee_setup','payrate','start_time','financial') NOT NULL,
  source_key VARCHAR(255) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  target_table VARCHAR(80) NOT NULL,
  target_key VARCHAR(160) NOT NULL,
  target_hash CHAR(64) NULL,
  first_batch_id BIGINT UNSIGNED NOT NULL,
  last_batch_id BIGINT UNSIGNED NOT NULL,
  status ENUM('active','superseded','conflict') NOT NULL DEFAULT 'active',
  first_imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_legacy_lineage_source (client_id,source_type,source_key),
  KEY idx_legacy_lineage_target (client_id,target_table,target_key),
  KEY idx_legacy_lineage_last_batch (last_batch_id),
  CONSTRAINT fk_legacy_lineage_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_legacy_lineage_first_batch FOREIGN KEY (first_batch_id) REFERENCES legacy_migration_batches(id),
  CONSTRAINT fk_legacy_lineage_last_batch FOREIGN KEY (last_batch_id) REFERENCES legacy_migration_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS legacy_migration_conflicts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id BIGINT UNSIGNED NOT NULL,
  stage_row_id BIGINT UNSIGNED NULL,
  client_id INT NOT NULL,
  source_type VARCHAR(40) NOT NULL,
  source_key VARCHAR(255) NOT NULL,
  conflict_code VARCHAR(80) NOT NULL,
  message VARCHAR(1000) NOT NULL,
  existing_target_table VARCHAR(80) NULL,
  existing_target_key VARCHAR(160) NULL,
  status ENUM('open','resolved','ignored') NOT NULL DEFAULT 'open',
  resolved_by_employee_id INT NULL,
  resolution_note VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_legacy_conflicts_batch (batch_id,status,id),
  KEY idx_legacy_conflicts_client (client_id,status,created_at),
  CONSTRAINT fk_legacy_conflict_batch FOREIGN KEY (batch_id) REFERENCES legacy_migration_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_legacy_conflict_stage FOREIGN KEY (stage_row_id) REFERENCES legacy_migration_stage_rows(id) ON DELETE SET NULL,
  CONSTRAINT fk_legacy_conflict_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO client_migration_state (client_id)
SELECT id FROM clients;

-- Verification only.
SELECT table_name
FROM information_schema.tables
WHERE table_schema=DATABASE()
  AND table_name IN (
    'client_legacy_sources','client_migration_state','legacy_migration_batches',
    'legacy_migration_stage_rows','legacy_migration_records','legacy_migration_conflicts'
  )
ORDER BY table_name;
