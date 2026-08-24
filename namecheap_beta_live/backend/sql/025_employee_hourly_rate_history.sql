CREATE TABLE IF NOT EXISTS employee_hourly_rate_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT NOT NULL,
    employee_id INT NOT NULL,
    hourly_rate DECIMAL(10,2) NOT NULL,
    effective_from DATE NOT NULL,
    changed_by_employee_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_employee_rate_effective (client_id, employee_id, effective_from),
    KEY idx_employee_rate_lookup (client_id, employee_id, effective_from),
    KEY idx_employee_rate_actor (changed_by_employee_id),
    CONSTRAINT fk_employee_rate_client FOREIGN KEY (client_id) REFERENCES clients(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_rate_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_employee_rate_actor FOREIGN KEY (changed_by_employee_id) REFERENCES employees(id) ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT chk_employee_rate_nonnegative CHECK (hourly_rate >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;