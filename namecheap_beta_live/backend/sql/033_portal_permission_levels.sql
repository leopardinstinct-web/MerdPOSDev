CREATE TABLE IF NOT EXISTS client_permission_levels (
  id INT NOT NULL AUTO_INCREMENT,
  client_id INT NOT NULL,
  permission_key VARCHAR(120) NOT NULL,
  min_authority_level SMALLINT UNSIGNED NOT NULL,
  updated_by_employee_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_client_permission_key (client_id, permission_key),
  KEY idx_client_permission_level (client_id, min_authority_level),
  KEY idx_client_permission_updater (updated_by_employee_id),
  CONSTRAINT fk_client_permission_client FOREIGN KEY (client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT fk_client_permission_updater FOREIGN KEY (updated_by_employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_client_permission_level CHECK (min_authority_level BETWEEN 1 AND 1000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
