CREATE TABLE IF NOT EXISTS client_role_authority (
  id INT NOT NULL AUTO_INCREMENT,
  client_id INT NOT NULL,
  role_name VARCHAR(16) NOT NULL,
  authority_level TINYINT UNSIGNED NOT NULL,
  updated_by_employee_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_client_role_authority (client_id, role_name),
  KEY idx_client_role_level (client_id, authority_level),
  CONSTRAINT fk_role_authority_client FOREIGN KEY (client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_role_authority_updater FOREIGN KEY (updated_by_employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE SET NULL,
  CONSTRAINT chk_role_authority_name CHECK (role_name IN ('USER','ADMIN','SUPER')),
  CONSTRAINT chk_role_authority_level CHECK (authority_level BETWEEN 1 AND 99)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO client_role_authority (client_id, role_name, authority_level)
SELECT c.id, r.role_name, r.authority_level
FROM clients c
CROSS JOIN (
  SELECT 'USER' AS role_name, 10 AS authority_level
  UNION ALL SELECT 'ADMIN', 50
  UNION ALL SELECT 'SUPER', 90
) r
LEFT JOIN client_role_authority a
  ON a.client_id=c.id AND a.role_name=r.role_name
WHERE a.id IS NULL;
