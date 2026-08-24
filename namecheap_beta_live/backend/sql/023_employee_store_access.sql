CREATE TABLE IF NOT EXISTS employee_store_access (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  employee_id INT NOT NULL,
  access_mode ENUM('all','selected') NOT NULL DEFAULT 'all',
  updated_by_employee_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_employee_store_access (client_id, employee_id),
  KEY idx_employee_store_access_mode (client_id, access_mode),
  KEY idx_employee_store_access_actor (updated_by_employee_id),
  CONSTRAINT fk_employee_store_access_client FOREIGN KEY (client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_employee_store_access_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_employee_store_access_actor FOREIGN KEY (updated_by_employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_store_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  employee_id INT NOT NULL,
  store_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_employee_store_assignment (client_id, employee_id, store_id),
  KEY idx_employee_store_assignment_store (client_id, store_id),
  KEY idx_employee_store_assignment_employee (client_id, employee_id),
  CONSTRAINT fk_employee_store_assignment_client FOREIGN KEY (client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_employee_store_assignment_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_employee_store_assignment_store FOREIGN KEY (store_id) REFERENCES stores(id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO employee_store_access (client_id, employee_id, access_mode)
SELECT e.client_id, e.id, 'all'
FROM employees e
LEFT JOIN employee_store_access a
  ON a.client_id=e.client_id AND a.employee_id=e.id
WHERE a.id IS NULL;
