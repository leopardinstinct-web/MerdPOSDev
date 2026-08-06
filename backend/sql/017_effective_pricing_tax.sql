-- M2.2 effective pricing and tax foundation.
--
-- IMPORTANT:
-- - This migration is NOT automatically idempotent.
-- - It must never be executed by PHP, Flutter, or application startup code.
-- - Production execution requires separate read-only schema reconciliation,
--   confirmation that every existing client uses AUD, backup/forward-fix
--   planning, migration approval, and deployment approval.
-- - MySQL/MariaDB DDL auto-commits. A partial failure requires a reviewed
--   forward-fix; do not blindly rerun this file.
-- - The new structures are shadow-only. Existing runtime readers and writers
--   continue to use sell_price, store_price, and tax_rate until a later,
--   separately approved integration and cutover.
-- - Legacy zero prices and zero tax rates are intentionally not backfilled.

DELIMITER $$
CREATE PROCEDURE merd_m2_2_preconditions()
BEGIN
  DECLARE v_count BIGINT DEFAULT 0;

  IF DATABASE() IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: select a database';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'clients', 'stores', 'retail_products', 'retail_product_barcodes',
       'retail_store_inventory', 'retail_sales', 'retail_sale_lines'
     );
  IF v_count <> 7 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: required M2.1 tables are missing';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'retail_tax_codes', 'retail_tax_rate_versions',
       'retail_product_tax_assignments', 'retail_price_versions',
       'retail_catalogue_settings'
     );
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: target tables already exist';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'trg_price_versions_before_insert', 'trg_price_versions_before_update',
       'trg_price_versions_before_delete', 'trg_tax_codes_before_update',
       'trg_tax_codes_before_delete', 'trg_tax_rates_before_insert',
       'trg_tax_rates_before_update', 'trg_tax_rates_before_delete',
       'trg_tax_assignments_before_insert', 'trg_tax_assignments_before_update',
       'trg_tax_assignments_before_delete'
     );
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: target triggers already exist';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND constraint_name IN (
       'chk_catalogue_currency_code', 'fk_catalogue_settings_client',
       'chk_product_unit_of_measure', 'fk_price_product_client',
       'fk_price_store', 'fk_price_currency', 'fk_tax_code_client',
       'fk_tax_rate_code_client', 'fk_tax_assignment_product_client',
       'fk_tax_assignment_code_client', 'fk_sale_line_price_version',
       'fk_sale_line_tax_code', 'fk_sale_line_tax_rate_version',
       'chk_sale_line_snapshot_unit', 'chk_sale_line_snapshot_quantity',
       'chk_sale_line_each_quantity'
     );
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: target constraint names already exist';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'clients'
     AND ((column_name = 'id' AND data_type = 'int' AND column_key = 'PRI' AND extra LIKE '%auto_increment%')
       OR (column_name = 'status' AND data_type = 'enum'));
  IF v_count <> 2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: clients shape is incompatible';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'stores'
     AND ((column_name = 'id' AND data_type = 'int' AND column_key = 'PRI' AND extra LIKE '%auto_increment%')
       OR (column_name = 'client_id' AND data_type = 'int')
       OR (column_name = 'status' AND data_type = 'enum'));
  IF v_count <> 3 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: stores shape is incompatible';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'retail_products'
     AND ((column_name = 'id' AND column_type = 'bigint(20) unsigned' AND column_key = 'PRI')
       OR (column_name = 'client_id' AND data_type = 'int')
       OR (column_name = 'sku_normalized' AND data_type = 'varchar' AND extra LIKE '%STORED GENERATED%')
       OR (column_name = 'cost_price' AND column_type = 'decimal(12,2)')
       OR (column_name = 'sell_price' AND column_type = 'decimal(12,2)')
       OR (column_name = 'tax_rate' AND column_type = 'decimal(6,3)')
       OR (column_name = 'archived_at' AND data_type = 'datetime')
       OR (column_name = 'tombstoned_at' AND data_type = 'datetime'));
  IF v_count <> 8 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: M2.1 retail_products shape is incompatible';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'retail_store_inventory'
     AND ((column_name = 'client_id' AND data_type = 'int')
       OR (column_name = 'store_id' AND data_type = 'int')
       OR (column_name = 'product_id' AND column_type = 'bigint(20) unsigned')
       OR (column_name = 'store_price' AND column_type = 'decimal(12,2)' AND is_nullable = 'YES'));
  IF v_count <> 4 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: retail_store_inventory shape is incompatible';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'retail_sale_lines'
     AND ((column_name = 'product_id' AND column_type = 'bigint(20) unsigned')
       OR (column_name = 'quantity' AND column_type = 'decimal(12,3)')
       OR (column_name = 'unit_price' AND column_type = 'decimal(12,2)')
       OR (column_name = 'unit_cost' AND column_type = 'decimal(12,2)')
       OR (column_name = 'line_total' AND column_type = 'decimal(12,2)'));
  IF v_count <> 5 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: retail_sale_lines shape is incompatible';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'retail_products'
     AND column_name = 'unit_of_measure';
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: unit_of_measure already exists';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'retail_sale_lines'
     AND column_name IN (
       'barcode_used', 'sku_snapshot', 'unit_of_measure',
       'catalogue_unit_price', 'price_type', 'price_version_id',
       'promotion_name', 'discount_reason', 'campaign_reference',
       'tax_code_id', 'tax_code_snapshot', 'tax_rate_version_id',
       'tax_rate_basis_points', 'tax_inclusive', 'net_amount',
       'tax_amount', 'gross_line_total', 'currency_code',
       'authoritative_sold_at_utc'
     );
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: target sale-line columns already exist';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND ((table_name = 'retail_products' AND constraint_name = 'uq_product_client_sku_normalized' AND constraint_type = 'UNIQUE')
       OR (table_name = 'retail_products' AND constraint_name = 'uq_product_id_client' AND constraint_type = 'UNIQUE')
       OR (table_name = 'retail_product_barcodes' AND constraint_name = 'fk_product_barcode_alias_product' AND constraint_type = 'FOREIGN KEY')
       OR (table_name = 'retail_sale_lines' AND constraint_name = 'fk_retail_line_product' AND constraint_type = 'FOREIGN KEY'));
  IF v_count <> 4 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: required M2.1 constraints are missing';
  END IF;

  SELECT COUNT(*) INTO v_count FROM retail_products WHERE sell_price < 0 OR cost_price < 0;
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: negative legacy product prices exist';
  END IF;

  SELECT COUNT(*) INTO v_count FROM retail_store_inventory WHERE store_price < 0;
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: negative legacy store prices exist';
  END IF;

  SELECT COUNT(*) INTO v_count FROM retail_products WHERE tax_rate < 0 OR tax_rate > 100;
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: legacy tax rates are outside 0 to 100 percent';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM retail_store_inventory i
    LEFT JOIN stores s ON s.id = i.store_id
    LEFT JOIN retail_products p ON p.id = i.product_id
   WHERE s.id IS NULL OR p.id IS NULL OR s.client_id <> i.client_id OR p.client_id <> i.client_id;
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 precondition failed: invalid tenant-scoped inventory references exist';
  END IF;
END$$
DELIMITER ;

CALL merd_m2_2_preconditions();
DROP PROCEDURE merd_m2_2_preconditions;

CREATE TABLE retail_catalogue_settings (
  client_id INT NOT NULL PRIMARY KEY,
  currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_catalogue_currency_code CHECK (currency_code REGEXP '^[A-Z]{3}$'),
  UNIQUE KEY uq_catalogue_settings_client_currency (client_id, currency_code),
  CONSTRAINT fk_catalogue_settings_client
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO retail_catalogue_settings (client_id, currency_code)
SELECT id, 'AUD' FROM clients;

ALTER TABLE retail_products
  ADD COLUMN unit_of_measure VARCHAR(20) NOT NULL DEFAULT 'each' AFTER description,
  ADD CONSTRAINT chk_product_unit_of_measure
    CHECK (unit_of_measure IN ('each', 'kilogram', 'litre'));

CREATE TABLE retail_price_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  store_id INT NULL,
  price_type VARCHAR(20) NOT NULL,
  precedence_rank TINYINT UNSIGNED GENERATED ALWAYS AS (
    CASE
      WHEN store_id IS NOT NULL AND price_type = 'promotion' THEN 1
      WHEN store_id IS NULL AND price_type = 'promotion' THEN 2
      WHEN store_id IS NOT NULL AND price_type = 'regular' THEN 3
      ELSE 4
    END
  ) STORED,
  unit_price DECIMAL(19,4) NOT NULL,
  currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  effective_from_utc DATETIME(6) NOT NULL,
  effective_to_utc DATETIME(6) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  promotion_name VARCHAR(190) NULL,
  reason VARCHAR(255) NULL,
  campaign_reference VARCHAR(190) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  approved_by INT NULL,
  approved_at_utc DATETIME(6) NULL,
  cancelled_by INT NULL,
  cancelled_at_utc DATETIME(6) NULL,
  cancellation_reason VARCHAR(255) NULL,
  store_scope_key INT GENERATED ALWAYS AS (COALESCE(store_id, 0)) STORED,
  CONSTRAINT chk_price_type CHECK (price_type IN ('regular', 'promotion')),
  CONSTRAINT chk_price_positive CHECK (unit_price > 0),
  CONSTRAINT chk_price_interval CHECK (effective_to_utc IS NULL OR effective_to_utc > effective_from_utc),
  CONSTRAINT chk_price_status CHECK (status IN ('draft', 'published', 'cancelled')),
  CONSTRAINT chk_price_promotion_name CHECK (price_type <> 'promotion' OR NULLIF(TRIM(promotion_name), '') IS NOT NULL),
  CONSTRAINT chk_price_cancellation CHECK (
    (status <> 'cancelled' AND cancelled_at_utc IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
    OR (status = 'cancelled' AND cancelled_at_utc IS NOT NULL AND cancelled_by IS NOT NULL AND NULLIF(TRIM(cancellation_reason), '') IS NOT NULL)
  ),
  UNIQUE KEY uq_price_scope_start
    (client_id, product_id, store_scope_key, price_type, effective_from_utc),
  UNIQUE KEY uq_price_id_product (id, product_id),
  KEY idx_price_selection
    (client_id, product_id, store_scope_key, status, effective_from_utc, effective_to_utc),
  CONSTRAINT fk_price_product_client FOREIGN KEY (product_id, client_id)
    REFERENCES retail_products(id, client_id) ON DELETE RESTRICT,
  CONSTRAINT fk_price_store FOREIGN KEY (store_id)
    REFERENCES stores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_price_currency FOREIGN KEY (client_id, currency_code)
    REFERENCES retail_catalogue_settings(client_id, currency_code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE retail_tax_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(120) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_by INT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT chk_tax_code_status CHECK (status IN ('active', 'disabled', 'archived')),
  CONSTRAINT chk_tax_code_text CHECK (
    NULLIF(TRIM(code), '') IS NOT NULL AND code = TRIM(code)
    AND NULLIF(TRIM(name), '') IS NOT NULL AND name = TRIM(name)
  ),
  UNIQUE KEY uq_tax_code_client_code (client_id, code),
  UNIQUE KEY uq_tax_code_client_name (client_id, name),
  UNIQUE KEY uq_tax_code_id_client (id, client_id),
  CONSTRAINT fk_tax_code_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO retail_tax_codes (client_id, code, name)
SELECT id, 'NO_TAX', 'No Tax' FROM clients;

CREATE TABLE retail_tax_rate_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  tax_code_id BIGINT UNSIGNED NOT NULL,
  rate_basis_points INT UNSIGNED NOT NULL,
  effective_from_utc DATETIME(6) NOT NULL,
  effective_to_utc DATETIME(6) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  created_by INT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  cancelled_by INT NULL,
  cancelled_at_utc DATETIME(6) NULL,
  cancellation_reason VARCHAR(255) NULL,
  CONSTRAINT chk_tax_rate_range CHECK (rate_basis_points <= 10000),
  CONSTRAINT chk_tax_rate_interval CHECK (effective_to_utc IS NULL OR effective_to_utc > effective_from_utc),
  CONSTRAINT chk_tax_rate_status CHECK (status IN ('draft', 'published', 'cancelled')),
  CONSTRAINT chk_tax_rate_cancellation CHECK (
    (status <> 'cancelled' AND cancelled_at_utc IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
    OR (status = 'cancelled' AND cancelled_at_utc IS NOT NULL AND cancelled_by IS NOT NULL AND NULLIF(TRIM(cancellation_reason), '') IS NOT NULL)
  ),
  UNIQUE KEY uq_tax_rate_start (client_id, tax_code_id, effective_from_utc),
  UNIQUE KEY uq_tax_rate_id_client_code (id, client_id, tax_code_id),
  UNIQUE KEY uq_tax_rate_id_code (id, tax_code_id),
  CONSTRAINT fk_tax_rate_code_client FOREIGN KEY (tax_code_id, client_id)
    REFERENCES retail_tax_codes(id, client_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO retail_tax_rate_versions
  (client_id, tax_code_id, rate_basis_points, effective_from_utc, status)
SELECT client_id, id, 0, '1970-01-01 00:00:00.000000', 'published'
  FROM retail_tax_codes WHERE code = 'NO_TAX';

CREATE TABLE retail_product_tax_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  tax_code_id BIGINT UNSIGNED NOT NULL,
  effective_from_utc DATETIME(6) NOT NULL,
  effective_to_utc DATETIME(6) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  created_by INT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  cancelled_by INT NULL,
  cancelled_at_utc DATETIME(6) NULL,
  cancellation_reason VARCHAR(255) NULL,
  CONSTRAINT chk_tax_assignment_interval CHECK (effective_to_utc IS NULL OR effective_to_utc > effective_from_utc),
  CONSTRAINT chk_tax_assignment_status CHECK (status IN ('draft', 'published', 'cancelled')),
  CONSTRAINT chk_tax_assignment_cancellation CHECK (
    (status <> 'cancelled' AND cancelled_at_utc IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
    OR (status = 'cancelled' AND cancelled_at_utc IS NOT NULL AND cancelled_by IS NOT NULL AND NULLIF(TRIM(cancellation_reason), '') IS NOT NULL)
  ),
  UNIQUE KEY uq_tax_assignment_start (client_id, product_id, effective_from_utc),
  CONSTRAINT fk_tax_assignment_product_client FOREIGN KEY (product_id, client_id)
    REFERENCES retail_products(id, client_id) ON DELETE RESTRICT,
  CONSTRAINT fk_tax_assignment_code_client FOREIGN KEY (tax_code_id, client_id)
    REFERENCES retail_tax_codes(id, client_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER trg_price_versions_before_insert
BEFORE INSERT ON retail_price_versions FOR EACH ROW
BEGIN
  DECLARE v_count BIGINT DEFAULT 0;
  DECLARE v_currency CHAR(3);
  DECLARE v_store_client INT;

  SELECT currency_code INTO v_currency FROM retail_catalogue_settings WHERE client_id = NEW.client_id;
  IF v_currency IS NULL OR BINARY v_currency <> BINARY NEW.currency_code THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 price rejected: currency does not match client settings';
  END IF;
  IF NEW.store_id IS NOT NULL THEN
    SELECT client_id INTO v_store_client FROM stores WHERE id = NEW.store_id;
    IF v_store_client IS NULL OR v_store_client <> NEW.client_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 price rejected: store is outside client';
    END IF;
  END IF;
  IF NEW.status = 'published' THEN
    SELECT COUNT(*) INTO v_count FROM retail_price_versions p
     WHERE p.client_id = NEW.client_id AND p.product_id = NEW.product_id
       AND p.store_id <=> NEW.store_id AND p.price_type = NEW.price_type
       AND p.status = 'published'
       AND (p.effective_to_utc IS NULL OR p.effective_to_utc > NEW.effective_from_utc)
       AND (NEW.effective_to_utc IS NULL OR NEW.effective_to_utc > p.effective_from_utc);
    IF v_count <> 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 price rejected: equal-scope interval overlap';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_price_versions_before_update
BEFORE UPDATE ON retail_price_versions FOR EACH ROW
BEGIN
  DECLARE v_count BIGINT DEFAULT 0;
  DECLARE v_currency CHAR(3);
  DECLARE v_store_client INT;
  IF OLD.status = 'published' AND (
    NOT (NEW.client_id <=> OLD.client_id) OR NOT (NEW.product_id <=> OLD.product_id)
    OR NOT (NEW.store_id <=> OLD.store_id) OR NOT (NEW.price_type <=> OLD.price_type)
    OR NOT (NEW.unit_price <=> OLD.unit_price) OR NOT (NEW.currency_code <=> OLD.currency_code)
    OR NOT (NEW.effective_from_utc <=> OLD.effective_from_utc)
    OR NOT (NEW.effective_to_utc <=> OLD.effective_to_utc)
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 price rejected: published price fields are immutable; cancel and replace';
  END IF;
  SELECT currency_code INTO v_currency FROM retail_catalogue_settings WHERE client_id = NEW.client_id;
  IF v_currency IS NULL OR BINARY v_currency <> BINARY NEW.currency_code THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 price rejected: currency does not match client settings';
  END IF;
  IF NEW.store_id IS NOT NULL THEN
    SELECT client_id INTO v_store_client FROM stores WHERE id = NEW.store_id;
    IF v_store_client IS NULL OR v_store_client <> NEW.client_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 price rejected: store is outside client';
    END IF;
  END IF;
  IF NEW.status = 'published' THEN
    SELECT COUNT(*) INTO v_count FROM retail_price_versions p
     WHERE p.id <> NEW.id AND p.client_id = NEW.client_id
       AND p.product_id = NEW.product_id AND p.store_id <=> NEW.store_id
       AND p.price_type = NEW.price_type AND p.status = 'published'
       AND (p.effective_to_utc IS NULL OR p.effective_to_utc > NEW.effective_from_utc)
       AND (NEW.effective_to_utc IS NULL OR NEW.effective_to_utc > p.effective_from_utc);
    IF v_count <> 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 price rejected: equal-scope interval overlap';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_price_versions_before_delete
BEFORE DELETE ON retail_price_versions FOR EACH ROW
BEGIN
  IF OLD.status IN ('published', 'cancelled') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 price rejected: published and cancelled history cannot be deleted';
  END IF;
END$$

CREATE TRIGGER trg_tax_codes_before_update
BEFORE UPDATE ON retail_tax_codes FOR EACH ROW
BEGIN
  IF NEW.client_id <> OLD.client_id OR NEW.code <> OLD.code THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 tax code rejected: client and code are immutable';
  END IF;
  IF OLD.code = 'NO_TAX' AND (NEW.code <> 'NO_TAX' OR NEW.status <> 'active') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 tax code rejected: NO_TAX identity and active state are protected';
  END IF;
END$$

CREATE TRIGGER trg_tax_rates_before_update
BEFORE UPDATE ON retail_tax_rate_versions FOR EACH ROW
BEGIN
  DECLARE v_count BIGINT DEFAULT 0;
  DECLARE v_code VARCHAR(40);
  IF OLD.status = 'published' AND (
    NEW.client_id <> OLD.client_id OR NEW.tax_code_id <> OLD.tax_code_id
    OR NEW.rate_basis_points <> OLD.rate_basis_points
    OR NEW.effective_from_utc <> OLD.effective_from_utc
    OR NOT (NEW.effective_to_utc <=> OLD.effective_to_utc)
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 tax rate rejected: published rate fields are immutable; cancel and replace';
  END IF;
  SELECT code INTO v_code FROM retail_tax_codes
   WHERE id = NEW.tax_code_id AND client_id = NEW.client_id;
  IF v_code = 'NO_TAX' AND NEW.rate_basis_points <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 tax rate rejected: NO_TAX must remain zero';
  END IF;
  IF NEW.status = 'published' THEN
    SELECT COUNT(*) INTO v_count FROM retail_tax_rate_versions r
     WHERE r.id <> NEW.id AND r.client_id = NEW.client_id
       AND r.tax_code_id = NEW.tax_code_id AND r.status = 'published'
       AND (r.effective_to_utc IS NULL OR r.effective_to_utc > NEW.effective_from_utc)
       AND (NEW.effective_to_utc IS NULL OR NEW.effective_to_utc > r.effective_from_utc);
    IF v_count <> 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 tax rate rejected: interval overlap';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_tax_rates_before_delete
BEFORE DELETE ON retail_tax_rate_versions FOR EACH ROW
BEGIN
  IF OLD.status IN ('published', 'cancelled') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 tax rate rejected: published and cancelled history cannot be deleted';
  END IF;
END$$

CREATE TRIGGER trg_tax_codes_before_delete
BEFORE DELETE ON retail_tax_codes FOR EACH ROW
BEGIN
  IF OLD.code = 'NO_TAX' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 tax code rejected: NO_TAX cannot be deleted';
  END IF;
END$$

CREATE TRIGGER trg_tax_rates_before_insert
BEFORE INSERT ON retail_tax_rate_versions FOR EACH ROW
BEGIN
  DECLARE v_count BIGINT DEFAULT 0;
  DECLARE v_code VARCHAR(40);
  SELECT code INTO v_code FROM retail_tax_codes
   WHERE id = NEW.tax_code_id AND client_id = NEW.client_id;
  IF v_code = 'NO_TAX' AND NEW.rate_basis_points <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 tax rate rejected: NO_TAX must remain zero';
  END IF;
  IF NEW.status = 'published' THEN
    SELECT COUNT(*) INTO v_count FROM retail_tax_rate_versions r
     WHERE r.client_id = NEW.client_id AND r.tax_code_id = NEW.tax_code_id
       AND r.status = 'published'
       AND (r.effective_to_utc IS NULL OR r.effective_to_utc > NEW.effective_from_utc)
       AND (NEW.effective_to_utc IS NULL OR NEW.effective_to_utc > r.effective_from_utc);
    IF v_count <> 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 tax rate rejected: interval overlap';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_tax_assignments_before_insert
BEFORE INSERT ON retail_product_tax_assignments FOR EACH ROW
BEGIN
  DECLARE v_count BIGINT DEFAULT 0;
  IF NEW.status = 'published' THEN
    SELECT COUNT(*) INTO v_count FROM retail_product_tax_assignments a
     WHERE a.client_id = NEW.client_id AND a.product_id = NEW.product_id
       AND a.status = 'published'
       AND (a.effective_to_utc IS NULL OR a.effective_to_utc > NEW.effective_from_utc)
       AND (NEW.effective_to_utc IS NULL OR NEW.effective_to_utc > a.effective_from_utc);
    IF v_count <> 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 tax assignment rejected: interval overlap';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_tax_assignments_before_update
BEFORE UPDATE ON retail_product_tax_assignments FOR EACH ROW
BEGIN
  DECLARE v_count BIGINT DEFAULT 0;
  IF OLD.status = 'published' AND (
    NEW.client_id <> OLD.client_id OR NEW.product_id <> OLD.product_id
    OR NEW.tax_code_id <> OLD.tax_code_id
    OR NEW.effective_from_utc <> OLD.effective_from_utc
    OR NOT (NEW.effective_to_utc <=> OLD.effective_to_utc)
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 tax assignment rejected: published assignment fields are immutable; cancel and replace';
  END IF;
  IF NEW.status = 'published' THEN
    SELECT COUNT(*) INTO v_count FROM retail_product_tax_assignments a
     WHERE a.id <> NEW.id AND a.client_id = NEW.client_id
       AND a.product_id = NEW.product_id AND a.status = 'published'
       AND (a.effective_to_utc IS NULL OR a.effective_to_utc > NEW.effective_from_utc)
       AND (NEW.effective_to_utc IS NULL OR NEW.effective_to_utc > a.effective_from_utc);
    IF v_count <> 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 tax assignment rejected: interval overlap';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_tax_assignments_before_delete
BEFORE DELETE ON retail_product_tax_assignments FOR EACH ROW
BEGIN
  IF OLD.status IN ('published', 'cancelled') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.2 tax assignment rejected: published and cancelled history cannot be deleted';
  END IF;
END$$
DELIMITER ;

ALTER TABLE retail_sale_lines
  ADD COLUMN barcode_used VARCHAR(191) NULL AFTER barcode,
  ADD COLUMN sku_snapshot VARCHAR(80) NULL AFTER product_name,
  ADD COLUMN unit_of_measure VARCHAR(20) NULL AFTER quantity,
  ADD COLUMN catalogue_unit_price DECIMAL(19,4) NULL AFTER unit_price,
  ADD COLUMN price_type VARCHAR(20) NULL AFTER catalogue_unit_price,
  ADD COLUMN price_version_id BIGINT UNSIGNED NULL AFTER price_type,
  ADD COLUMN promotion_name VARCHAR(190) NULL AFTER price_version_id,
  ADD COLUMN discount_reason VARCHAR(255) NULL AFTER promotion_name,
  ADD COLUMN campaign_reference VARCHAR(190) NULL AFTER discount_reason,
  ADD COLUMN tax_code_id BIGINT UNSIGNED NULL AFTER campaign_reference,
  ADD COLUMN tax_code_snapshot VARCHAR(40) NULL AFTER tax_code_id,
  ADD COLUMN tax_rate_version_id BIGINT UNSIGNED NULL AFTER tax_code_snapshot,
  ADD COLUMN tax_rate_basis_points INT UNSIGNED NULL AFTER tax_rate_version_id,
  ADD COLUMN tax_inclusive TINYINT(1) NULL AFTER tax_rate_basis_points,
  ADD COLUMN net_amount DECIMAL(19,2) NULL AFTER tax_inclusive,
  ADD COLUMN tax_amount DECIMAL(19,2) NULL AFTER net_amount,
  ADD COLUMN gross_line_total DECIMAL(19,2) NULL AFTER tax_amount,
  ADD COLUMN currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER gross_line_total,
  ADD COLUMN authoritative_sold_at_utc DATETIME(6) NULL AFTER currency_code,
  ADD CONSTRAINT chk_sale_line_snapshot_unit CHECK (
    unit_of_measure IS NULL OR unit_of_measure IN ('each', 'kilogram', 'litre')
  ),
  ADD CONSTRAINT chk_sale_line_snapshot_quantity CHECK (
    unit_of_measure IS NULL OR quantity > 0
  ),
  ADD CONSTRAINT chk_sale_line_each_quantity CHECK (
    unit_of_measure IS NULL OR unit_of_measure <> 'each' OR quantity = FLOOR(quantity)
  ),
  ADD CONSTRAINT fk_sale_line_price_version FOREIGN KEY (price_version_id, product_id)
    REFERENCES retail_price_versions(id, product_id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_sale_line_tax_code FOREIGN KEY (tax_code_id)
    REFERENCES retail_tax_codes(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_sale_line_tax_rate_version FOREIGN KEY (tax_rate_version_id, tax_code_id)
    REFERENCES retail_tax_rate_versions(id, tax_code_id) ON DELETE RESTRICT;

-- Verification queries for a separately approved reviewed execution.
SELECT COUNT(*) AS clients_without_currency
  FROM clients c LEFT JOIN retail_catalogue_settings s ON s.client_id = c.id
 WHERE s.client_id IS NULL;
SELECT COUNT(*) AS clients_without_no_tax
  FROM clients c LEFT JOIN retail_tax_codes t
    ON t.client_id = c.id AND t.code = 'NO_TAX'
 WHERE t.id IS NULL;
SELECT COUNT(*) AS authoritative_price_rows FROM retail_price_versions;
SELECT COUNT(*) AS authoritative_tax_assignments FROM retail_product_tax_assignments;
SELECT COUNT(*) AS ambiguous_zero_sell_prices FROM retail_products WHERE sell_price = 0;
SELECT COUNT(*) AS ambiguous_zero_tax_rates FROM retail_products WHERE tax_rate = 0;
SELECT COUNT(*) AS legacy_sale_lines_with_new_snapshots
  FROM retail_sale_lines
 WHERE price_version_id IS NOT NULL OR tax_code_id IS NOT NULL
    OR net_amount IS NOT NULL OR tax_amount IS NOT NULL OR gross_line_total IS NOT NULL;

-- Rollback/forward-fix limitations:
-- - DDL auto-commits; partial execution is not transactionally reversible.
-- - New tables can be dropped only before any later runtime integration writes
--   authoritative price, tax, assignment, or sale-snapshot data.
-- - Removing unit_of_measure after non-each products exist loses meaning.
-- - Historical price/tax/sale snapshot records must never be rewritten or
--   deleted to force a rollback. Use a reviewed forward-fix after cutover.
-- - Legacy sell_price, store_price, tax_rate, cost_price, purchase-order cost,
--   and sale-line unit_cost remain unchanged by this migration.
