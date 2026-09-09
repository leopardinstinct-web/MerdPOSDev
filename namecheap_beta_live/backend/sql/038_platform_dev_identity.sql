CREATE TABLE IF NOT EXISTS platform_identities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id VARCHAR(50) NOT NULL,
  full_name VARCHAR(190) NOT NULL,
  login_password VARCHAR(255) NOT NULL,
  pin_code VARCHAR(255) NULL,
  role_key VARCHAR(16) NOT NULL DEFAULT 'DEV',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  legacy_employee_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_platform_identity_user_id (user_id),
  UNIQUE KEY uq_platform_identity_legacy_employee (legacy_employee_id),
  CONSTRAINT chk_platform_identity_role CHECK (role_key='DEV')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_identity_preferences (
  platform_identity_id BIGINT UNSIGNED NOT NULL,
  selected_client_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (platform_identity_id),
  KEY idx_platform_identity_selected_client (selected_client_id),
  CONSTRAINT fk_platform_identity_pref_identity FOREIGN KEY (platform_identity_id) REFERENCES platform_identities(id) ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT fk_platform_identity_pref_client FOREIGN KEY (selected_client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_role_usability (
  client_id INT NOT NULL,
  role_key VARCHAR(16) NOT NULL,
  permission_key VARCHAR(120) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_by_employee_id INT NULL,
  updated_by_platform_identity_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (client_id,role_key,permission_key),
  KEY idx_role_usability_permission (client_id,permission_key,enabled),
  CONSTRAINT fk_role_usability_client FOREIGN KEY (client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT fk_role_usability_employee FOREIGN KEY (updated_by_employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_role_usability_platform FOREIGN KEY (updated_by_platform_identity_id) REFERENCES platform_identities(id) ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_role_usability_role CHECK (role_key IN ('SUPER','USER')),
  CONSTRAINT chk_role_usability_enabled CHECK (enabled IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE admin_audit_logs MODIFY employee_id INT NULL;
ALTER TABLE admin_audit_logs ADD COLUMN IF NOT EXISTS platform_identity_id BIGINT UNSIGNED NULL AFTER employee_id;
ALTER TABLE security_audit_events ADD COLUMN IF NOT EXISTS platform_identity_id BIGINT UNSIGNED NULL AFTER employee_id;
ALTER TABLE legacy_migration_batches MODIFY started_by_employee_id INT NULL;
ALTER TABLE legacy_migration_batches ADD COLUMN IF NOT EXISTS started_by_platform_identity_id BIGINT UNSIGNED NULL AFTER started_by_employee_id;
ALTER TABLE client_legacy_sources ADD COLUMN IF NOT EXISTS created_by_platform_identity_id BIGINT UNSIGNED NULL AFTER created_by_employee_id;
ALTER TABLE client_legacy_sources ADD COLUMN IF NOT EXISTS updated_by_platform_identity_id BIGINT UNSIGNED NULL AFTER updated_by_employee_id;
ALTER TABLE client_migration_state ADD COLUMN IF NOT EXISTS cutover_by_platform_identity_id BIGINT UNSIGNED NULL AFTER cutover_by_employee_id;

ALTER TABLE client_role_authority MODIFY updated_by_employee_id INT NULL;
ALTER TABLE client_role_authority ADD COLUMN IF NOT EXISTS updated_by_platform_identity_id BIGINT UNSIGNED NULL AFTER updated_by_employee_id;
ALTER TABLE client_permission_levels MODIFY updated_by_employee_id INT NULL;
ALTER TABLE client_permission_levels ADD COLUMN IF NOT EXISTS updated_by_platform_identity_id BIGINT UNSIGNED NULL AFTER updated_by_employee_id;
ALTER TABLE employee_store_access MODIFY updated_by_employee_id INT NULL;
ALTER TABLE employee_store_access ADD COLUMN IF NOT EXISTS updated_by_platform_identity_id BIGINT UNSIGNED NULL AFTER updated_by_employee_id;
ALTER TABLE employee_hourly_rate_history MODIFY changed_by_employee_id INT NULL;
ALTER TABLE employee_hourly_rate_history ADD COLUMN IF NOT EXISTS changed_by_platform_identity_id BIGINT UNSIGNED NULL AFTER changed_by_employee_id;
ALTER TABLE store_weekly_hours MODIFY updated_by_employee_id INT NULL;
ALTER TABLE store_weekly_hours ADD COLUMN IF NOT EXISTS updated_by_platform_identity_id BIGINT UNSIGNED NULL AFTER updated_by_employee_id;
ALTER TABLE legacy_migration_conflicts ADD COLUMN IF NOT EXISTS resolved_by_platform_identity_id BIGINT UNSIGNED NULL AFTER resolved_by_employee_id;
ALTER TABLE ui_studio_state ADD COLUMN IF NOT EXISTS updated_by_platform_identity_id BIGINT UNSIGNED NULL AFTER updated_by_employee_id;
ALTER TABLE ui_studio_history ADD COLUMN IF NOT EXISTS actor_platform_identity_id BIGINT UNSIGNED NULL AFTER actor_employee_id;
ALTER TABLE ui_studio_history ADD COLUMN IF NOT EXISTS deleted_by_platform_identity_id BIGINT UNSIGNED NULL AFTER deleted_by_employee_id;
