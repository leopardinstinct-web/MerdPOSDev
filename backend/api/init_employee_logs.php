<?php
require_once __DIR__ . '/includes/maintenance_guard.php';
merd_maintenance_guard();
require_once "config.php";

$pdo->exec("
CREATE TABLE IF NOT EXISTS employee_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    store_id INT NOT NULL,
    employee_id INT NULL,
    user_name VARCHAR(150) NOT NULL,
    store_name VARCHAR(150) NOT NULL,
    log_type ENUM('IN','OUT') NOT NULL,
    log_date DATE NOT NULL,
    log_time TIME NOT NULL,
    log_datetime DATETIME NOT NULL,
    device_uuid VARCHAR(150) NOT NULL,
    local_log_id VARCHAR(150) NOT NULL,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_device_log (device_uuid, local_log_id),
    INDEX idx_employee_datetime (employee_id, log_datetime),
    INDEX idx_store_datetime (store_id, log_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

echo json_encode([
    "success" => true,
    "message" => "employee_logs table created successfully"
]);
