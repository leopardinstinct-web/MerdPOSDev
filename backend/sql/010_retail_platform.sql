CREATE TABLE IF NOT EXISTS retail_sales (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  local_sale_id INT NOT NULL,
  sale_number VARCHAR(64) NOT NULL,
  cashier_id INT NOT NULL,
  cashier_name VARCHAR(190) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_method VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'completed',
  device_uuid VARCHAR(191) NOT NULL,
  sold_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_retail_sale_device (client_id, store_id, device_uuid, sale_number),
  KEY idx_retail_sales_date (client_id, store_id, sold_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS retail_sale_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  retail_sale_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT NOT NULL,
  barcode VARCHAR(191) NOT NULL,
  product_name VARCHAR(255) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_retail_line_sale FOREIGN KEY (retail_sale_id) REFERENCES retail_sales(id) ON DELETE CASCADE,
  KEY idx_retail_line_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS retail_stock_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  product_id BIGINT NOT NULL,
  movement_type VARCHAR(40) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL,
  reference_code VARCHAR(191) NOT NULL,
  note VARCHAR(255) NULL,
  device_uuid VARCHAR(191) NOT NULL,
  moved_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_retail_movement_device (client_id, store_id, device_uuid, reference_code, product_id, movement_type),
  KEY idx_retail_stock_date (client_id, store_id, moved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
