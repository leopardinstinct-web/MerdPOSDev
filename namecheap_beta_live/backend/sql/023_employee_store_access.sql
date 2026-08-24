CREATE TABLE IF NOT EXISTS employee_store_access (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  employee_id INT NOT NULL,
  access_mode VARCHAR(20) NOT NULL DEFAULT 'all',
  updated_by_employee_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_employee_store_access (client_id, employee_id),
  KEY idx_employee_store_access_mode (client_id, access_mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_store_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  employee_id INT NOT NULL,
  store_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_employee_store_assignment (client_id, employee_id, store_id),
  KEY idx_employee_store_assignment_store (client_id, store_id),
  KEY idx_employee_store_assignment_employee (client_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO employee_store_access (client_id, employee_id, access_mode)
SELECT e.client_id, e.id, 'all'
FROM employees e
LEFT JOIN employee_store_access a
  ON a.client_id=e.client_id AND a.employee_id=e.id
WHERE a.id IS NULL;
