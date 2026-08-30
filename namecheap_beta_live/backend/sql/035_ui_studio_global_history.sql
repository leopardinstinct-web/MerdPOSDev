CREATE TABLE IF NOT EXISTS ui_studio_state (
  client_id INT NOT NULL,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
  patches_json LONGTEXT NOT NULL,
  updated_by_employee_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (client_id),
  CONSTRAINT fk_ui_studio_state_client FOREIGN KEY (client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT fk_ui_studio_state_actor FOREIGN KEY (updated_by_employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ui_studio_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(64) NOT NULL,
  client_id INT NOT NULL,
  revision BIGINT UNSIGNED NOT NULL,
  actor_employee_id INT NULL,
  actor_label VARCHAR(120) NOT NULL,
  role_scope VARCHAR(32) NOT NULL DEFAULT 'DEV',
  action VARCHAR(48) NOT NULL,
  summary VARCHAR(500) NOT NULL,
  selector VARCHAR(1024) NOT NULL DEFAULT '',
  runtime_key VARCHAR(128) NOT NULL DEFAULT '',
  page_path VARCHAR(255) NOT NULL DEFAULT '',
  panel_id VARCHAR(96) NOT NULL DEFAULT '',
  nav_group VARCHAR(96) NOT NULL DEFAULT '',
  dialog_id VARCHAR(96) NOT NULL DEFAULT '',
  popover_id VARCHAR(96) NOT NULL DEFAULT '',
  mobile_tools TINYINT(1) NOT NULL DEFAULT 0,
  mutation_json LONGTEXT NOT NULL,
  legacy_only TINYINT(1) NOT NULL DEFAULT 0,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  deleted_by_employee_id INT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ui_studio_history_public (public_id),
  KEY idx_ui_studio_history_client_revision (client_id, revision, id),
  KEY idx_ui_studio_history_client_selector (client_id, selector(191)),
  KEY idx_ui_studio_history_deleted (client_id, deleted_at, id),
  CONSTRAINT fk_ui_studio_history_client FOREIGN KEY (client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT fk_ui_studio_history_actor FOREIGN KEY (actor_employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT fk_ui_studio_history_deleted_by FOREIGN KEY (deleted_by_employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO ui_studio_state (client_id, revision, patches_json)
SELECT id, 0, '[]' FROM clients;