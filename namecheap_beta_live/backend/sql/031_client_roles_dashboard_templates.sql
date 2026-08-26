CREATE TABLE IF NOT EXISTS client_roles (
  id INT NOT NULL AUTO_INCREMENT,
  client_id INT NOT NULL,
  role_key VARCHAR(32) NOT NULL,
  role_label VARCHAR(80) NOT NULL,
  base_role VARCHAR(16) NOT NULL,
  authority_level SMALLINT UNSIGNED NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_client_role_key (client_id, role_key),
  KEY idx_client_role_authority (client_id, authority_level),
  CONSTRAINT fk_client_roles_client FOREIGN KEY (client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_client_roles_base CHECK (base_role IN ('USER','ADMIN','SUPER','DEV')),
  CONSTRAINT chk_client_roles_authority CHECK (authority_level BETWEEN 1 AND 1000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO client_roles (client_id,role_key,role_label,base_role,authority_level,is_system,status)
SELECT c.id,'USER','User','USER',COALESCE(a.authority_level,10),1,'active'
FROM clients c LEFT JOIN client_role_authority a ON a.client_id=c.id AND a.role_name='USER'
ON DUPLICATE KEY UPDATE role_label=VALUES(role_label),base_role=VALUES(base_role),is_system=1;

INSERT INTO client_roles (client_id,role_key,role_label,base_role,authority_level,is_system,status)
SELECT c.id,'ADMIN','Admin','ADMIN',COALESCE(a.authority_level,50),1,'active'
FROM clients c LEFT JOIN client_role_authority a ON a.client_id=c.id AND a.role_name='ADMIN'
ON DUPLICATE KEY UPDATE role_label=VALUES(role_label),base_role=VALUES(base_role),is_system=1;

INSERT INTO client_roles (client_id,role_key,role_label,base_role,authority_level,is_system,status)
SELECT c.id,'SUPER','Super','SUPER',COALESCE(a.authority_level,90),1,'active'
FROM clients c LEFT JOIN client_role_authority a ON a.client_id=c.id AND a.role_name='SUPER'
ON DUPLICATE KEY UPDATE role_label=VALUES(role_label),base_role=VALUES(base_role),is_system=1;

INSERT INTO client_roles (client_id,role_key,role_label,base_role,authority_level,is_system,status)
SELECT c.id,'DEV','Developer','DEV',1000,1,'active' FROM clients c
ON DUPLICATE KEY UPDATE role_label=VALUES(role_label),base_role='DEV',authority_level=1000,is_system=1,status='active';

CREATE TABLE IF NOT EXISTS dashboard_role_layouts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT NOT NULL,
  role_id INT NOT NULL,
  widget_key VARCHAR(64) NOT NULL,
  grid_x TINYINT UNSIGNED NOT NULL DEFAULT 0,
  grid_y SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  grid_w TINYINT UNSIGNED NOT NULL DEFAULT 4,
  grid_h TINYINT UNSIGNED NOT NULL DEFAULT 3,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dashboard_role_widget (role_id, widget_key),
  KEY idx_dashboard_role_context (client_id, role_id, grid_y, grid_x),
  CONSTRAINT fk_dashboard_role_client FOREIGN KEY (client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_dashboard_role_role FOREIGN KEY (role_id) REFERENCES client_roles(id) ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
