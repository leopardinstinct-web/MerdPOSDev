-- Synthetic CI-only schema fixture. Never apply to production.
CREATE TABLE clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  client_code VARCHAR(50) NOT NULL UNIQUE,
  setup_key VARCHAR(100) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  store_name VARCHAR(150) NOT NULL,
  store_code VARCHAR(50) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  store_id INT NULL,
  device_uuid VARCHAR(150) NOT NULL UNIQUE,
  device_name VARCHAR(150) NULL,
  activation_token VARCHAR(150) NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  last_sync DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
