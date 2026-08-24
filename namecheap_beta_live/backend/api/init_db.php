<?php
require_once __DIR__ . '/includes/maintenance_guard.php';
merd_maintenance_guard();
require_once "config.php";

$sql = [];

$sql[] = "
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    client_code VARCHAR(50) NOT NULL UNIQUE,
    setup_key VARCHAR(100) NOT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

$sql[] = "
CREATE TABLE IF NOT EXISTS stores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    store_name VARCHAR(150) NOT NULL,
    store_code VARCHAR(50) NOT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

$sql[] = "
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    store_id INT NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    pin_code VARCHAR(20) NOT NULL,
    role_name VARCHAR(80) DEFAULT 'Staff',
    hourly_rate DECIMAL(10,2) DEFAULT 0,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

$sql[] = "
CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    store_id INT NULL,
    device_uuid VARCHAR(150) NOT NULL UNIQUE,
    device_name VARCHAR(150),
    activation_token VARCHAR(150),
    status ENUM('active','inactive') DEFAULT 'active',
    last_sync DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

$sql[] = "
CREATE TABLE IF NOT EXISTS shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    store_id INT NOT NULL,
    employee_id INT NOT NULL,
    device_uuid VARCHAR(150) NOT NULL,
    local_shift_id VARCHAR(150) NOT NULL,
    clock_in DATETIME NOT NULL,
    clock_out DATETIME NULL,
    break_minutes INT DEFAULT 0,
    total_minutes INT DEFAULT 0,
    status ENUM('open','closed') DEFAULT 'open',
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_local_shift (device_uuid, local_shift_id),
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (store_id) REFERENCES stores(id),
    FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

foreach ($sql as $query) {
    $pdo->exec($query);
}

$pdo->exec("
INSERT IGNORE INTO clients (id, name, client_code, setup_key)
VALUES (1, 'Merd Retail Group', 'MERD', '123456');
");

$pdo->exec("
INSERT IGNORE INTO stores (id, client_id, store_name, store_code)
VALUES
(1, 1, 'Marrickville Xpress', 'MX'),
(2, 1, 'Enmore Tobacco', 'ET'),
(3, 1, 'Double Bay Tobacco', 'DT'),
(4, 1, 'Rosebay Tobacco', 'RT');
");

$pdo->exec("
INSERT IGNORE INTO employees (id, client_id, store_id, full_name, pin_code, role_name)
VALUES
(1, 1, 1, 'Test Employee MX', '1111', 'Staff'),
(2, 1, 2, 'Test Employee ET', '2222', 'Staff'),
(3, 1, 3, 'Test Employee DT', '3333', 'Staff'),
(4, 1, 4, 'Test Employee RT', '4444', 'Staff');
");

echo json_encode([
    "success" => true,
    "message" => "Database initialized successfully"
]);
