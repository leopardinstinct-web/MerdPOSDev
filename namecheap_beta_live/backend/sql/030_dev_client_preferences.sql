CREATE TABLE IF NOT EXISTS dev_client_preferences (
  employee_id INT NOT NULL,
  auth_client_id INT NOT NULL,
  selected_client_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (employee_id),
  KEY idx_dev_client_pref_auth (auth_client_id),
  KEY idx_dev_client_pref_selected (selected_client_id),
  CONSTRAINT fk_dev_client_pref_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT fk_dev_client_pref_auth_client FOREIGN KEY (auth_client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_dev_client_pref_selected_client FOREIGN KEY (selected_client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO dev_client_preferences (employee_id, auth_client_id, selected_client_id)
SELECT e.id, e.client_id, e.client_id
FROM employees e
WHERE UPPER(TRIM(e.employee_type))='DEV'
ON DUPLICATE KEY UPDATE auth_client_id=VALUES(auth_client_id);
