-- Synthetic M2.2 additions to the tracked M2.1 fixture. Never use in production.

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
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_m22_store_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO clients (id, name, client_code, setup_key, status) VALUES
  (1, 'Synthetic Client One', 'SYNTHETIC-ONE', 'not-a-real-secret', 'active'),
  (2, 'Synthetic Client Two', 'SYNTHETIC-TWO', 'not-a-real-secret', 'active');

INSERT INTO stores (id, client_id, store_name, store_code, status) VALUES
  (11, 1, 'Synthetic Store One', 'STORE-ONE', 'active'),
  (12, 1, 'Synthetic Store Two', 'STORE-TWO', 'active'),
  (21, 2, 'Synthetic Other Tenant', 'STORE-OTHER', 'active');

UPDATE retail_products
   SET cost_price = CASE id WHEN 1001 THEN 1.20 WHEN 1002 THEN 2.00 ELSE 3.00 END,
       sell_price = CASE id WHEN 1001 THEN 5.25 WHEN 1002 THEN 0.00 ELSE 6.75 END,
       tax_rate = CASE id WHEN 1001 THEN 10.000 WHEN 1002 THEN 0.000 ELSE 10.000 END;

UPDATE retail_store_inventory
   SET store_price = CASE id WHEN 301 THEN 4.95 WHEN 303 THEN 6.50 ELSE NULL END;

UPDATE retail_sales
   SET subtotal = 5.25, tax = 0.00, total = 5.25
 WHERE id = 401;

UPDATE retail_sale_lines
   SET unit_price = 5.25, unit_cost = 1.20, line_total = 5.25
 WHERE id = 501;
