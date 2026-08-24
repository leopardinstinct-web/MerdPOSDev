-- M2.1 catalogue identity and lifecycle foundation.
--
-- IMPORTANT:
-- - This migration is NOT automatically idempotent.
-- - It must never be executed by PHP, Flutter, or application startup code.
-- - Production execution requires separate read-only schema reconciliation,
--   backup/rollback planning, migration approval, and deployment approval.
-- - The DDL statements auto-commit in MySQL/MariaDB. A partial failure requires
--   a reviewed forward-fix; do not blindly rerun this file.
--
-- Tracked-schema assumptions and preconditions are checked before any DDL.
-- Existing product/category IDs and historical reference values are never
-- updated by this migration.

DELIMITER $$
CREATE PROCEDURE merd_m2_1_preconditions()
BEGIN
  DECLARE v_count BIGINT DEFAULT 0;

  IF DATABASE() IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: select a database';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'retail_categories', 'retail_products', 'retail_store_inventory',
       'retail_sales', 'retail_sale_lines', 'retail_stock_movements',
       'retail_purchase_orders', 'retail_purchase_order_lines'
     );
  IF v_count <> 8 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: required tracked retail tables are missing';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'retail_products'
     AND (
       (column_name = 'id' AND column_type = 'bigint(20) unsigned' AND column_key = 'PRI' AND extra LIKE '%auto_increment%') OR
       (column_name = 'client_id' AND data_type = 'int') OR
       (column_name = 'category_id' AND column_type = 'bigint(20) unsigned' AND is_nullable = 'YES') OR
       (column_name = 'sku' AND data_type = 'varchar' AND character_maximum_length = 80 AND is_nullable = 'YES') OR
       (column_name = 'barcode' AND data_type = 'varchar' AND character_maximum_length = 191 AND is_nullable = 'NO') OR
       (column_name = 'status' AND data_type = 'varchar' AND character_maximum_length = 20) OR
       (column_name = 'created_at' AND data_type = 'timestamp') OR
       (column_name = 'updated_at' AND data_type = 'timestamp')
     );
  IF v_count <> 8 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: retail_products shape is incompatible';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'retail_categories'
     AND (
       (column_name = 'id' AND column_type = 'bigint(20) unsigned' AND column_key = 'PRI' AND extra LIKE '%auto_increment%') OR
       (column_name = 'client_id' AND data_type = 'int') OR
       (column_name = 'status' AND data_type = 'varchar' AND character_maximum_length = 20)
     );
  IF v_count <> 3 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: retail_categories shape is incompatible';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND ((table_name = 'retail_sale_lines' AND column_name = 'product_id' AND column_type = 'bigint(20)')
       OR (table_name = 'retail_stock_movements' AND column_name = 'product_id' AND column_type = 'bigint(20)'));
  IF v_count <> 2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: historical product reference types are incompatible';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND ((table_name = 'retail_products' AND constraint_name = 'uq_product_barcode' AND constraint_type = 'UNIQUE')
       OR (table_name = 'retail_products' AND constraint_name = 'fk_product_category' AND constraint_type = 'FOREIGN KEY')
       OR (table_name = 'retail_store_inventory' AND constraint_name = 'fk_inventory_product' AND constraint_type = 'FOREIGN KEY')
       OR (table_name = 'retail_purchase_order_lines' AND constraint_name = 'fk_po_line_product' AND constraint_type = 'FOREIGN KEY'));
  IF v_count <> 4 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: expected tracked constraints are missing';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.referential_constraints
   WHERE constraint_schema = DATABASE()
     AND ((constraint_name = 'fk_product_category' AND delete_rule = 'SET NULL')
       OR (constraint_name = 'fk_inventory_product' AND delete_rule = 'CASCADE')
       OR (constraint_name = 'fk_po_line_product' AND delete_rule = 'RESTRICT'));
  IF v_count <> 3 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: tracked deletion rules are incompatible';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.tables
   WHERE table_schema = DATABASE() AND table_name = 'retail_product_barcodes';
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: retail_product_barcodes already exists';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'retail_products'
     AND column_name IN ('sku_normalized', 'archived_at', 'tombstoned_at');
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: target product columns already exist';
  END IF;

  SELECT COUNT(*) INTO v_count FROM (
    SELECT client_id, LOWER(TRIM(sku)) AS normalized_sku
      FROM retail_products
     WHERE NULLIF(TRIM(sku), '') IS NOT NULL
     GROUP BY client_id, LOWER(TRIM(sku))
    HAVING COUNT(*) > 1
  ) AS duplicate_skus;
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: duplicate normalized SKUs exist';
  END IF;

  SELECT COUNT(*) INTO v_count FROM (
    SELECT client_id, CONVERT(TRIM(barcode) USING utf8mb4) COLLATE utf8mb4_bin AS normalized_barcode
      FROM retail_products
     WHERE NULLIF(TRIM(barcode), '') IS NOT NULL
     GROUP BY client_id, normalized_barcode
    HAVING COUNT(*) > 1
  ) AS duplicate_barcodes;
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: trimmed barcode aliases collide';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM retail_products p
    JOIN retail_categories c ON c.id = p.category_id
   WHERE c.client_id <> p.client_id;
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: cross-client product category references exist';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM retail_store_inventory i
    LEFT JOIN retail_products p ON p.id = i.product_id
   WHERE p.id IS NULL OR p.client_id <> i.client_id;
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: invalid inventory product references exist';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM retail_sale_lines l
    LEFT JOIN retail_products p ON p.id = l.product_id
   WHERE l.product_id < 0 OR p.id IS NULL;
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: invalid historical sale product references exist';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM retail_stock_movements m
    LEFT JOIN retail_products p ON p.id = m.product_id
   WHERE m.product_id < 0 OR p.id IS NULL OR p.client_id <> m.client_id;
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: invalid historical movement product references exist';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM retail_purchase_order_lines l
    JOIN retail_purchase_orders po ON po.id = l.purchase_order_id
    LEFT JOIN retail_products p ON p.id = l.product_id
   WHERE p.id IS NULL OR p.client_id <> po.client_id;
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: invalid purchase product references exist';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM retail_products
   WHERE status NOT IN ('active', 'disabled', 'archived', 'tombstoned');
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.1 precondition failed: unsupported product status values exist';
  END IF;
END$$
DELIMITER ;

CALL merd_m2_1_preconditions();
DROP PROCEDURE merd_m2_1_preconditions;

ALTER TABLE retail_products
  MODIFY barcode VARCHAR(191) NULL,
  ADD COLUMN sku_normalized VARCHAR(80)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    GENERATED ALWAYS AS (NULLIF(LOWER(TRIM(sku)), '')) STORED AFTER sku,
  ADD COLUMN archived_at DATETIME NULL AFTER status,
  ADD COLUMN tombstoned_at DATETIME NULL AFTER archived_at,
  ADD UNIQUE KEY uq_product_client_sku_normalized (client_id, sku_normalized),
  ADD UNIQUE KEY uq_product_id_client (id, client_id);

UPDATE retail_products
   SET barcode = NULLIF(TRIM(barcode), '');

CREATE TABLE retail_product_barcodes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  barcode VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_barcode_alias_client (client_id, barcode),
  UNIQUE KEY uq_product_barcode_alias_product (product_id, barcode),
  KEY idx_product_barcode_alias_product_client (product_id, client_id),
  CONSTRAINT fk_product_barcode_alias_product
    FOREIGN KEY (product_id, client_id)
    REFERENCES retail_products(id, client_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO retail_product_barcodes (client_id, product_id, barcode, is_primary)
SELECT client_id, id, barcode, 1
  FROM retail_products
 WHERE barcode IS NOT NULL;

ALTER TABLE retail_products
  DROP FOREIGN KEY fk_product_category;
ALTER TABLE retail_products
  ADD CONSTRAINT fk_product_category
    FOREIGN KEY (category_id) REFERENCES retail_categories(id) ON DELETE RESTRICT;

ALTER TABLE retail_store_inventory
  DROP FOREIGN KEY fk_inventory_product;
ALTER TABLE retail_store_inventory
  ADD CONSTRAINT fk_inventory_product
    FOREIGN KEY (product_id) REFERENCES retail_products(id) ON DELETE RESTRICT;

ALTER TABLE retail_sale_lines
  MODIFY product_id BIGINT UNSIGNED NOT NULL,
  ADD CONSTRAINT fk_retail_line_product
    FOREIGN KEY (product_id) REFERENCES retail_products(id) ON DELETE RESTRICT;

ALTER TABLE retail_stock_movements
  MODIFY product_id BIGINT UNSIGNED NOT NULL,
  ADD CONSTRAINT fk_retail_movement_product
    FOREIGN KEY (product_id) REFERENCES retail_products(id) ON DELETE RESTRICT;

-- Verification queries for reviewed execution. All counts must be zero except
-- migrated_aliases, which must equal non_empty_legacy_barcodes.
SELECT COUNT(*) AS duplicate_normalized_skus FROM (
  SELECT client_id, sku_normalized
    FROM retail_products
   WHERE sku_normalized IS NOT NULL
   GROUP BY client_id, sku_normalized
  HAVING COUNT(*) > 1
) AS duplicates;

SELECT COUNT(*) AS duplicate_barcode_aliases FROM (
  SELECT client_id, barcode
    FROM retail_product_barcodes
   GROUP BY client_id, barcode
  HAVING COUNT(*) > 1
) AS duplicates;

SELECT COUNT(*) AS non_empty_legacy_barcodes
  FROM retail_products WHERE barcode IS NOT NULL;
SELECT COUNT(*) AS migrated_aliases
  FROM retail_product_barcodes WHERE is_primary = 1;

SELECT COUNT(*) AS orphaned_sale_product_references
  FROM retail_sale_lines l LEFT JOIN retail_products p ON p.id = l.product_id
 WHERE p.id IS NULL;
SELECT COUNT(*) AS orphaned_movement_product_references
  FROM retail_stock_movements m LEFT JOIN retail_products p ON p.id = m.product_id
 WHERE p.id IS NULL;

-- Rollback/forward-fix limitations:
-- - Safe rollback is possible only before barcode-free products, multiple
--   aliases, normalized-SKU dependencies, archived records, or tombstones exist.
-- - Dropping aliases after use loses identity data; restoring NOT NULL barcode
--   cannot represent barcode-free products.
-- - Restoring CASCADE/SET NULL deletion reintroduces historical-reference loss.
-- - Existing product/category IDs must never be renumbered during recovery.
-- - After M2.1 data is used, a reviewed forward-fix is preferred.
