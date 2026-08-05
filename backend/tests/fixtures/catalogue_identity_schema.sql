-- Synthetic M2.1 migration fixture. Never apply to production.

CREATE TABLE retail_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_category_name (client_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE retail_products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  sku VARCHAR(80) NULL,
  barcode VARCHAR(191) NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  sell_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_barcode (client_id, barcode),
  KEY idx_product_category (category_id),
  CONSTRAINT fk_product_category FOREIGN KEY (category_id)
    REFERENCES retail_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE retail_store_inventory (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  reorder_level DECIMAL(12,3) NOT NULL DEFAULT 0,
  store_price DECIMAL(12,2) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_store_product (client_id, store_id, product_id),
  CONSTRAINT fk_inventory_product FOREIGN KEY (product_id)
    REFERENCES retail_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE retail_sales (
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
  UNIQUE KEY uq_retail_sale_device (client_id, store_id, device_uuid, sale_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE retail_sale_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  retail_sale_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT NOT NULL,
  barcode VARCHAR(191) NOT NULL,
  product_name VARCHAR(255) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_retail_line_sale FOREIGN KEY (retail_sale_id)
    REFERENCES retail_sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE retail_stock_movements (
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
  UNIQUE KEY uq_retail_movement_device
    (client_id, store_id, device_uuid, reference_code, product_id, movement_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE retail_purchase_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  supplier_id BIGINT UNSIGNED NULL,
  po_number VARCHAR(64) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  ordered_at DATETIME NULL,
  received_at DATETIME NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_po_number (client_id, po_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE retail_purchase_order_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity_ordered DECIMAL(12,3) NOT NULL DEFAULT 0,
  quantity_received DECIMAL(12,3) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_po_line_header FOREIGN KEY (purchase_order_id)
    REFERENCES retail_purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_po_line_product FOREIGN KEY (product_id)
    REFERENCES retail_products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO retail_categories (id, client_id, name, status) VALUES
  (101, 1, 'Drinks', 'active'),
  (102, 1, 'Archived label', 'disabled'),
  (201, 2, 'Second client', 'active');

INSERT INTO retail_products
  (id, client_id, category_id, sku, barcode, name, status) VALUES
  (1001, 1, 101, '  ABC-123  ', '0012345', 'Leading zero product', 'active'),
  (1002, 1, 101, NULL, '12345', 'Distinct barcode product', 'disabled'),
  (2001, 2, 201, 'ABC-123', '0012345', 'Second-client product', 'active');

INSERT INTO retail_store_inventory
  (id, client_id, store_id, product_id, quantity, reorder_level) VALUES
  (301, 1, 11, 1001, 10, 2),
  (302, 1, 11, 1002, 5, 1),
  (303, 2, 21, 2001, 8, 1);

INSERT INTO retail_sales
  (id, client_id, store_id, local_sale_id, sale_number, cashier_id,
   cashier_name, total, payment_method, device_uuid, sold_at) VALUES
  (401, 1, 11, 1, 'SYNTHETIC-1', 1, 'Synthetic Cashier', 5, 'cash',
   'synthetic-device', '2026-08-05 00:00:00');

INSERT INTO retail_sale_lines
  (id, retail_sale_id, product_id, barcode, product_name, quantity,
   unit_price, line_total) VALUES
  (501, 401, 1001, '0012345', 'Leading zero product', 1, 5, 5);

INSERT INTO retail_stock_movements
  (id, client_id, store_id, product_id, movement_type, quantity,
   reference_code, device_uuid, moved_at) VALUES
  (601, 1, 11, 1001, 'sale', -1, 'SYNTHETIC-1', 'synthetic-device',
   '2026-08-05 00:00:00');

INSERT INTO retail_purchase_orders
  (id, client_id, store_id, po_number, status, created_by) VALUES
  (701, 1, 11, 'PO-SYNTHETIC-1', 'ordered', 1);

INSERT INTO retail_purchase_order_lines
  (id, purchase_order_id, product_id, quantity_ordered) VALUES
  (801, 701, 1001, 4);
