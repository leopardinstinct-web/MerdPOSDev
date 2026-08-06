-- M2.3 authoritative stock ledger and maintained balance foundation.
--
-- IMPORTANT:
-- - This migration is NOT automatically idempotent.
-- - It must never be executed by PHP, Flutter, application startup, or CI
--   against a live database.
-- - Production execution requires read-only schema/data reconciliation,
--   reviewed inventory snapshots, backup and forward-fix planning, explicit
--   migration approval, and post-execution verification.
-- - MySQL/MariaDB DDL auto-commits. A partial failure cannot be transactionally
--   rolled back; use a reviewed forward-fix and never blindly rerun this file.
-- - These structures are shadow-only. Existing APIs, Flutter, SQLite,
--   checkout, receiving, retail_stock_movements, and
--   retail_store_inventory.quantity remain unchanged and authoritative until
--   a later separately approved reconciliation and runtime cutover.
-- - Existing quantities are deliberately NOT copied into the ledger or
--   balance table. Opening/reconciliation movements require reviewed source
--   identities and must never be guessed by this migration.

DELIMITER $$
CREATE PROCEDURE merd_m2_3_preconditions()
BEGIN
  DECLARE v_count BIGINT DEFAULT 0;

  IF DATABASE() IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 precondition failed: select a database';
  END IF;

  SELECT COUNT(*) INTO v_count FROM information_schema.tables
   WHERE table_schema = DATABASE() AND table_name IN (
     'clients', 'stores', 'retail_products', 'retail_product_barcodes',
     'retail_store_inventory', 'retail_sales', 'retail_sale_lines',
     'retail_stock_movements', 'retail_purchase_orders',
     'retail_purchase_order_lines', 'retail_catalogue_settings',
     'retail_price_versions', 'retail_tax_codes',
     'retail_tax_rate_versions', 'retail_product_tax_assignments'
   );
  IF v_count <> 15 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 precondition failed: required M2.2 tables are missing';
  END IF;

  SELECT COUNT(*) INTO v_count FROM information_schema.tables
   WHERE table_schema = DATABASE() AND table_name IN (
     'retail_stock_balances', 'retail_stock_ledger_movements',
     'retail_stock_transfers', 'retail_negative_stock_exceptions',
     'retail_stock_reconciliation_candidates'
   );
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 precondition failed: target tables already exist';
  END IF;

  SELECT COUNT(*) INTO v_count FROM information_schema.triggers
   WHERE trigger_schema = DATABASE() AND trigger_name IN (
     'trg_stock_transfer_before_insert', 'trg_stock_transfer_before_update',
     'trg_stock_ledger_before_insert', 'trg_stock_ledger_after_insert',
     'trg_stock_ledger_before_update', 'trg_stock_ledger_before_delete'
   );
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 precondition failed: target triggers already exist';
  END IF;

  SELECT COUNT(*) INTO v_count FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'retail_products'
     AND ((column_name = 'id' AND column_type = 'bigint(20) unsigned')
       OR (column_name = 'client_id' AND data_type = 'int')
       OR (column_name = 'unit_of_measure' AND data_type = 'varchar')
       OR (column_name = 'sku_normalized' AND extra LIKE '%STORED GENERATED%'));
  IF v_count <> 4 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 precondition failed: M2.2 product shape is incompatible';
  END IF;

  SELECT COUNT(*) INTO v_count FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'retail_store_inventory'
     AND ((column_name = 'client_id' AND data_type = 'int')
       OR (column_name = 'store_id' AND data_type = 'int')
       OR (column_name = 'product_id' AND column_type = 'bigint(20) unsigned')
       OR (column_name = 'quantity' AND column_type = 'decimal(12,3)'));
  IF v_count <> 4 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 precondition failed: legacy inventory shape is incompatible';
  END IF;

  SELECT COUNT(*) INTO v_count FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'retail_stock_movements'
     AND ((column_name = 'id' AND column_type = 'bigint(20) unsigned')
       OR (column_name = 'client_id' AND data_type = 'int')
       OR (column_name = 'store_id' AND data_type = 'int')
       OR (column_name = 'product_id' AND column_type = 'bigint(20) unsigned')
       OR (column_name = 'reference_code' AND data_type = 'varchar'));
  IF v_count <> 5 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 precondition failed: legacy movement shape is incompatible';
  END IF;

  SELECT COUNT(*) INTO v_count FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE() AND (
     (table_name = 'retail_products' AND constraint_name = 'uq_product_id_client' AND constraint_type = 'UNIQUE')
     OR (table_name = 'retail_sale_lines' AND constraint_name = 'fk_retail_line_product' AND constraint_type = 'FOREIGN KEY')
     OR (table_name = 'retail_store_inventory' AND constraint_name = 'fk_inventory_product' AND constraint_type = 'FOREIGN KEY')
     OR (table_name = 'retail_price_versions' AND constraint_name = 'fk_price_product_client' AND constraint_type = 'FOREIGN KEY')
     OR (table_name = 'retail_product_tax_assignments' AND constraint_name = 'fk_tax_assignment_product_client' AND constraint_type = 'FOREIGN KEY')
   );
  IF v_count <> 5 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 precondition failed: required M2.1/M2.2 constraints are missing';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM retail_store_inventory i
    LEFT JOIN stores s ON s.id = i.store_id
    LEFT JOIN retail_products p ON p.id = i.product_id
   WHERE s.id IS NULL OR p.id IS NULL
      OR s.client_id <> i.client_id OR p.client_id <> i.client_id;
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 precondition failed: invalid tenant-scoped inventory references exist';
  END IF;
END$$
DELIMITER ;

CALL merd_m2_3_preconditions();
DROP PROCEDURE merd_m2_3_preconditions;

CREATE TABLE retail_stock_balances (
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(19,3) NOT NULL DEFAULT 0,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_movement_id BIGINT UNSIGNED NULL,
  updated_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (client_id, store_id, product_id),
  KEY idx_stock_balance_store_product (store_id, product_id),
  CONSTRAINT fk_stock_balance_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_balance_store FOREIGN KEY (store_id)
    REFERENCES stores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_balance_product_client FOREIGN KEY (product_id, client_id)
    REFERENCES retail_products(id, client_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE retail_stock_transfers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  transfer_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_store_id INT NOT NULL,
  destination_store_id INT NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(19,3) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  created_by_actor_type VARCHAR(30) NOT NULL,
  created_by_actor_id VARCHAR(191) NOT NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  dispatched_at_utc DATETIME(6) NULL,
  received_at_utc DATETIME(6) NULL,
  cancelled_at_utc DATETIME(6) NULL,
  cancellation_reason VARCHAR(255) NULL,
  CONSTRAINT chk_stock_transfer_stores CHECK (source_store_id <> destination_store_id),
  CONSTRAINT chk_stock_transfer_quantity CHECK (quantity > 0),
  CONSTRAINT chk_stock_transfer_status CHECK (status IN ('draft','dispatched','received','cancelled')),
  CONSTRAINT chk_stock_transfer_lifecycle CHECK (
    (status = 'draft' AND dispatched_at_utc IS NULL AND received_at_utc IS NULL AND cancelled_at_utc IS NULL)
    OR (status = 'dispatched' AND dispatched_at_utc IS NOT NULL AND received_at_utc IS NULL AND cancelled_at_utc IS NULL)
    OR (status = 'received' AND dispatched_at_utc IS NOT NULL AND received_at_utc IS NOT NULL AND cancelled_at_utc IS NULL)
    OR (status = 'cancelled' AND cancelled_at_utc IS NOT NULL AND NULLIF(TRIM(cancellation_reason), '') IS NOT NULL)
  ),
  UNIQUE KEY uq_stock_transfer_client_key (client_id, transfer_key),
  UNIQUE KEY uq_stock_transfer_id_client (id, client_id),
  CONSTRAINT fk_stock_transfer_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_transfer_source_store FOREIGN KEY (source_store_id)
    REFERENCES stores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_transfer_destination_store FOREIGN KEY (destination_store_id)
    REFERENCES stores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_transfer_product_client FOREIGN KEY (product_id, client_id)
    REFERENCES retail_products(id, client_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE retail_stock_ledger_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  movement_type VARCHAR(40) NOT NULL,
  signed_quantity DECIMAL(19,3) NOT NULL,
  balance_before DECIMAL(19,3) NOT NULL,
  balance_after DECIMAL(19,3) NOT NULL,
  balance_revision BIGINT UNSIGNED NOT NULL,
  source_type VARCHAR(40) NOT NULL,
  source_record_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  idempotency_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  device_uuid VARCHAR(191) NULL,
  retail_sale_id BIGINT UNSIGNED NULL,
  purchase_order_id BIGINT UNSIGNED NULL,
  purchase_order_line_id BIGINT UNSIGNED NULL,
  legacy_stock_movement_id BIGINT UNSIGNED NULL,
  legacy_inventory_id BIGINT UNSIGNED NULL,
  actor_type VARCHAR(30) NOT NULL,
  actor_id VARCHAR(191) NOT NULL,
  reason_code VARCHAR(80) NULL,
  note VARCHAR(500) NULL,
  occurred_at_utc DATETIME(6) NULL,
  authoritative_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  reversal_of_movement_id BIGINT UNSIGNED NULL,
  transfer_id BIGINT UNSIGNED NULL,
  transfer_leg VARCHAR(10) NULL,
  metadata_json JSON NULL,
  opening_product_key BIGINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN movement_type = 'opening_balance' THEN product_id ELSE NULL END
  ) STORED,
  CONSTRAINT chk_stock_movement_type CHECK (movement_type IN (
    'opening_balance','sale','sale_return','purchase_receiving','supplier_return',
    'transfer_out','transfer_in','wastage_damage','adjustment_increase',
    'adjustment_decrease','reconciliation','reversal'
  )),
  CONSTRAINT chk_stock_movement_nonzero CHECK (signed_quantity <> 0),
  CONSTRAINT chk_stock_movement_arithmetic CHECK (balance_after = balance_before + signed_quantity),
  CONSTRAINT chk_stock_movement_text CHECK (
    NULLIF(TRIM(source_type), '') IS NOT NULL
    AND NULLIF(TRIM(source_record_key), '') IS NOT NULL
    AND NULLIF(TRIM(idempotency_key), '') IS NOT NULL
    AND NULLIF(TRIM(actor_type), '') IS NOT NULL
    AND NULLIF(TRIM(actor_id), '') IS NOT NULL
  ),
  CONSTRAINT chk_stock_adjustment_reason CHECK (
    movement_type NOT IN ('opening_balance','adjustment_increase','adjustment_decrease','reconciliation','wastage_damage','reversal')
    OR NULLIF(TRIM(reason_code), '') IS NOT NULL
  ),
  CONSTRAINT chk_stock_movement_direction CHECK (
    movement_type NOT IN ('sale','supplier_return','transfer_out','wastage_damage','adjustment_decrease')
    OR signed_quantity < 0
  ),
  CONSTRAINT chk_stock_positive_direction CHECK (
    movement_type NOT IN ('sale_return','purchase_receiving','transfer_in','adjustment_increase')
    OR signed_quantity > 0
  ),
  CONSTRAINT chk_stock_reversal_link CHECK (
    (movement_type = 'reversal' AND reversal_of_movement_id IS NOT NULL)
    OR (movement_type <> 'reversal' AND reversal_of_movement_id IS NULL)
  ),
  CONSTRAINT chk_stock_transfer_link CHECK (
    (movement_type = 'transfer_out' AND transfer_id IS NOT NULL AND transfer_leg = 'out')
    OR (movement_type = 'transfer_in' AND transfer_id IS NOT NULL AND transfer_leg = 'in')
    OR (movement_type NOT IN ('transfer_out','transfer_in') AND transfer_id IS NULL AND transfer_leg IS NULL)
  ),
  UNIQUE KEY uq_stock_movement_idempotency (client_id, store_id, idempotency_key),
  UNIQUE KEY uq_stock_movement_source (client_id, store_id, source_type, source_record_key, product_id),
  UNIQUE KEY uq_stock_opening_once (client_id, store_id, opening_product_key),
  UNIQUE KEY uq_stock_reversal_once (reversal_of_movement_id),
  UNIQUE KEY uq_stock_transfer_leg (transfer_id, transfer_leg),
  UNIQUE KEY uq_stock_movement_id_scope (id, client_id, store_id, product_id),
  KEY idx_stock_ledger_product_time (client_id, store_id, product_id, authoritative_at_utc, id),
  KEY idx_stock_ledger_device (client_id, device_uuid),
  CONSTRAINT fk_stock_ledger_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_ledger_store FOREIGN KEY (store_id)
    REFERENCES stores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_ledger_product_client FOREIGN KEY (product_id, client_id)
    REFERENCES retail_products(id, client_id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_ledger_reversal FOREIGN KEY (reversal_of_movement_id)
    REFERENCES retail_stock_ledger_movements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_ledger_transfer_client FOREIGN KEY (transfer_id, client_id)
    REFERENCES retail_stock_transfers(id, client_id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_ledger_sale FOREIGN KEY (retail_sale_id)
    REFERENCES retail_sales(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_ledger_purchase_order FOREIGN KEY (purchase_order_id)
    REFERENCES retail_purchase_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_ledger_purchase_line FOREIGN KEY (purchase_order_line_id)
    REFERENCES retail_purchase_order_lines(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_ledger_legacy_movement FOREIGN KEY (legacy_stock_movement_id)
    REFERENCES retail_stock_movements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_ledger_legacy_inventory FOREIGN KEY (legacy_inventory_id)
    REFERENCES retail_store_inventory(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE retail_stock_balances
  ADD CONSTRAINT fk_stock_balance_last_movement
    FOREIGN KEY (last_movement_id) REFERENCES retail_stock_ledger_movements(id)
    ON DELETE RESTRICT;

CREATE TABLE retail_negative_stock_exceptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  first_detected_at_utc DATETIME(6) NOT NULL,
  latest_detected_at_utc DATETIME(6) NOT NULL,
  lowest_observed_balance DECIMAL(19,3) NOT NULL,
  latest_balance DECIMAL(19,3) NOT NULL,
  first_movement_id BIGINT UNSIGNED NOT NULL,
  latest_movement_id BIGINT UNSIGNED NOT NULL,
  balance_recovered_at_utc DATETIME(6) NULL,
  acknowledged_by_actor_type VARCHAR(30) NULL,
  acknowledged_by_actor_id VARCHAR(191) NULL,
  acknowledged_at_utc DATETIME(6) NULL,
  resolution_note VARCHAR(500) NULL,
  updated_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT chk_negative_exception_status CHECK (status IN ('open','acknowledged','resolved')),
  CONSTRAINT chk_negative_exception_balance CHECK (lowest_observed_balance < 0),
  CONSTRAINT chk_negative_exception_ack CHECK (
    (status = 'open' AND acknowledged_at_utc IS NULL)
    OR (status IN ('acknowledged','resolved') AND acknowledged_at_utc IS NOT NULL
      AND NULLIF(TRIM(acknowledged_by_actor_type), '') IS NOT NULL
      AND NULLIF(TRIM(acknowledged_by_actor_id), '') IS NOT NULL)
  ),
  UNIQUE KEY uq_negative_stock_scope (client_id, store_id, product_id),
  CONSTRAINT fk_negative_stock_balance FOREIGN KEY (client_id, store_id, product_id)
    REFERENCES retail_stock_balances(client_id, store_id, product_id) ON DELETE RESTRICT,
  CONSTRAINT fk_negative_stock_first_movement FOREIGN KEY (first_movement_id)
    REFERENCES retail_stock_ledger_movements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_negative_stock_latest_movement FOREIGN KEY (latest_movement_id)
    REFERENCES retail_stock_ledger_movements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE retail_stock_reconciliation_candidates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  legacy_inventory_id BIGINT UNSIGNED NOT NULL,
  legacy_quantity_snapshot DECIMAL(19,3) NOT NULL,
  ledger_quantity_snapshot DECIMAL(19,3) NOT NULL,
  quantity_difference DECIMAL(19,3) NOT NULL,
  snapshot_at_utc DATETIME(6) NOT NULL,
  candidate_source_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  review_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  reviewed_by_actor_type VARCHAR(30) NULL,
  reviewed_by_actor_id VARCHAR(191) NULL,
  reviewed_at_utc DATETIME(6) NULL,
  review_note VARCHAR(500) NULL,
  generated_movement_id BIGINT UNSIGNED NULL,
  CONSTRAINT chk_stock_reconciliation_difference CHECK (
    quantity_difference = legacy_quantity_snapshot - ledger_quantity_snapshot
  ),
  CONSTRAINT chk_stock_reconciliation_status CHECK (review_status IN ('pending','approved','rejected','applied')),
  CONSTRAINT chk_stock_reconciliation_review CHECK (
    (review_status = 'pending' AND reviewed_at_utc IS NULL)
    OR (review_status <> 'pending' AND reviewed_at_utc IS NOT NULL
      AND NULLIF(TRIM(reviewed_by_actor_type), '') IS NOT NULL
      AND NULLIF(TRIM(reviewed_by_actor_id), '') IS NOT NULL)
  ),
  UNIQUE KEY uq_stock_reconciliation_source (client_id, candidate_source_key),
  KEY idx_stock_reconciliation_review (client_id, review_status, store_id, product_id),
  CONSTRAINT fk_stock_reconciliation_inventory FOREIGN KEY (legacy_inventory_id)
    REFERENCES retail_store_inventory(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_reconciliation_balance FOREIGN KEY (client_id, store_id, product_id)
    REFERENCES retail_stock_balances(client_id, store_id, product_id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_reconciliation_movement FOREIGN KEY (generated_movement_id)
    REFERENCES retail_stock_ledger_movements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER trg_stock_transfer_before_insert
BEFORE INSERT ON retail_stock_transfers FOR EACH ROW
BEGIN
  DECLARE v_source_client INT DEFAULT NULL;
  DECLARE v_destination_client INT DEFAULT NULL;
  SELECT client_id INTO v_source_client FROM stores WHERE id = NEW.source_store_id;
  SELECT client_id INTO v_destination_client FROM stores WHERE id = NEW.destination_store_id;
  IF v_source_client IS NULL OR v_destination_client IS NULL
     OR v_source_client <> NEW.client_id OR v_destination_client <> NEW.client_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 transfer rejected: store is outside client';
  END IF;
END$$

CREATE TRIGGER trg_stock_transfer_before_update
BEFORE UPDATE ON retail_stock_transfers FOR EACH ROW
BEGIN
  IF NEW.client_id <> OLD.client_id OR NEW.transfer_key <> OLD.transfer_key
     OR NEW.source_store_id <> OLD.source_store_id
     OR NEW.destination_store_id <> OLD.destination_store_id
     OR NEW.product_id <> OLD.product_id OR NEW.quantity <> OLD.quantity THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 transfer identity and quantity are immutable';
  END IF;
END$$

CREATE TRIGGER trg_stock_ledger_before_insert
BEFORE INSERT ON retail_stock_ledger_movements FOR EACH ROW
BEGIN
  DECLARE v_store_client INT DEFAULT NULL;
  DECLARE v_before DECIMAL(19,3) DEFAULT 0;
  DECLARE v_revision BIGINT UNSIGNED DEFAULT 0;
  DECLARE v_original_client INT DEFAULT NULL;
  DECLARE v_original_store INT DEFAULT NULL;
  DECLARE v_original_product BIGINT UNSIGNED DEFAULT NULL;
  DECLARE v_original_quantity DECIMAL(19,3) DEFAULT NULL;
  DECLARE v_transfer_client INT DEFAULT NULL;
  DECLARE v_transfer_source INT DEFAULT NULL;
  DECLARE v_transfer_destination INT DEFAULT NULL;
  DECLARE v_transfer_product BIGINT UNSIGNED DEFAULT NULL;
  DECLARE v_transfer_quantity DECIMAL(19,3) DEFAULT NULL;
  DECLARE v_source_client INT DEFAULT NULL;
  DECLARE v_source_store INT DEFAULT NULL;
  DECLARE v_source_product BIGINT UNSIGNED DEFAULT NULL;
  DECLARE v_source_parent BIGINT UNSIGNED DEFAULT NULL;

  SELECT client_id INTO v_store_client FROM stores WHERE id = NEW.store_id;
  IF v_store_client IS NULL OR v_store_client <> NEW.client_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 movement rejected: store is outside client';
  END IF;

  IF NEW.movement_type = 'reversal' THEN
    SELECT client_id, store_id, product_id, signed_quantity
      INTO v_original_client, v_original_store, v_original_product, v_original_quantity
      FROM retail_stock_ledger_movements WHERE id = NEW.reversal_of_movement_id;
    IF v_original_client IS NULL OR v_original_client <> NEW.client_id
       OR v_original_store <> NEW.store_id OR v_original_product <> NEW.product_id
       OR NEW.signed_quantity <> -v_original_quantity THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 movement rejected: invalid reversal scope or quantity';
    END IF;
  END IF;

  IF NEW.transfer_id IS NOT NULL THEN
    SELECT client_id, source_store_id, destination_store_id, product_id, quantity
      INTO v_transfer_client, v_transfer_source, v_transfer_destination,
           v_transfer_product, v_transfer_quantity
      FROM retail_stock_transfers WHERE id = NEW.transfer_id;
    IF v_transfer_client IS NULL OR v_transfer_client <> NEW.client_id
       OR v_transfer_product <> NEW.product_id
       OR (NEW.transfer_leg = 'out' AND (NEW.store_id <> v_transfer_source OR NEW.signed_quantity <> -v_transfer_quantity))
       OR (NEW.transfer_leg = 'in' AND (NEW.store_id <> v_transfer_destination OR NEW.signed_quantity <> v_transfer_quantity)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 movement rejected: transfer leg does not match transfer';
    END IF;
  END IF;

  IF NEW.retail_sale_id IS NOT NULL THEN
    SET v_source_client = NULL;
    SELECT client_id, store_id INTO v_source_client, v_source_store
      FROM retail_sales WHERE id = NEW.retail_sale_id;
    IF v_source_client IS NULL OR v_source_client <> NEW.client_id
       OR v_source_store <> NEW.store_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 movement rejected: sale is outside stock scope';
    END IF;
  END IF;

  IF NEW.purchase_order_id IS NOT NULL THEN
    SET v_source_client = NULL;
    SELECT client_id, store_id INTO v_source_client, v_source_store
      FROM retail_purchase_orders WHERE id = NEW.purchase_order_id;
    IF v_source_client IS NULL OR v_source_client <> NEW.client_id
       OR v_source_store <> NEW.store_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 movement rejected: purchase order is outside stock scope';
    END IF;
  END IF;

  IF NEW.purchase_order_line_id IS NOT NULL THEN
    SET v_source_product = NULL;
    SELECT purchase_order_id, product_id INTO v_source_parent, v_source_product
      FROM retail_purchase_order_lines WHERE id = NEW.purchase_order_line_id;
    IF v_source_product IS NULL OR v_source_product <> NEW.product_id
       OR NEW.purchase_order_id IS NULL OR v_source_parent <> NEW.purchase_order_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 movement rejected: purchase line is outside stock scope';
    END IF;
  END IF;

  IF NEW.legacy_stock_movement_id IS NOT NULL THEN
    SET v_source_client = NULL;
    SELECT client_id, store_id, product_id
      INTO v_source_client, v_source_store, v_source_product
      FROM retail_stock_movements WHERE id = NEW.legacy_stock_movement_id;
    IF v_source_client IS NULL OR v_source_client <> NEW.client_id
       OR v_source_store <> NEW.store_id OR v_source_product <> NEW.product_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 movement rejected: legacy movement is outside stock scope';
    END IF;
  END IF;

  IF NEW.legacy_inventory_id IS NOT NULL THEN
    SET v_source_client = NULL;
    SELECT client_id, store_id, product_id
      INTO v_source_client, v_source_store, v_source_product
      FROM retail_store_inventory WHERE id = NEW.legacy_inventory_id;
    IF v_source_client IS NULL OR v_source_client <> NEW.client_id
       OR v_source_store <> NEW.store_id OR v_source_product <> NEW.product_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 movement rejected: legacy inventory is outside stock scope';
    END IF;
  END IF;

  INSERT INTO retail_stock_balances
    (client_id, store_id, product_id, quantity, revision, updated_at_utc)
  VALUES (NEW.client_id, NEW.store_id, NEW.product_id, 0, 0, CURRENT_TIMESTAMP(6))
  ON DUPLICATE KEY UPDATE client_id = VALUES(client_id);

  SELECT quantity, revision INTO v_before, v_revision
    FROM retail_stock_balances
   WHERE client_id = NEW.client_id AND store_id = NEW.store_id
     AND product_id = NEW.product_id
   FOR UPDATE;

  SET NEW.balance_before = v_before;
  SET NEW.balance_after = v_before + NEW.signed_quantity;
  SET NEW.balance_revision = v_revision + 1;
  SET NEW.authoritative_at_utc = CURRENT_TIMESTAMP(6);
  SET NEW.created_at_utc = CURRENT_TIMESTAMP(6);
END$$

CREATE TRIGGER trg_stock_ledger_after_insert
AFTER INSERT ON retail_stock_ledger_movements FOR EACH ROW
BEGIN
  UPDATE retail_stock_balances
     SET quantity = NEW.balance_after,
         revision = NEW.balance_revision,
         last_movement_id = NEW.id,
         updated_at_utc = NEW.authoritative_at_utc
   WHERE client_id = NEW.client_id AND store_id = NEW.store_id
     AND product_id = NEW.product_id;

  IF NEW.balance_after < 0 THEN
    INSERT INTO retail_negative_stock_exceptions
      (client_id, store_id, product_id, status, first_detected_at_utc,
       latest_detected_at_utc, lowest_observed_balance, latest_balance,
       first_movement_id, latest_movement_id, updated_at_utc)
    VALUES
      (NEW.client_id, NEW.store_id, NEW.product_id, 'open',
       NEW.authoritative_at_utc, NEW.authoritative_at_utc, NEW.balance_after,
       NEW.balance_after, NEW.id, NEW.id, NEW.authoritative_at_utc)
    ON DUPLICATE KEY UPDATE
      status = IF(status = 'resolved', 'open', status),
      latest_detected_at_utc = VALUES(latest_detected_at_utc),
      lowest_observed_balance = LEAST(lowest_observed_balance, VALUES(lowest_observed_balance)),
      latest_balance = VALUES(latest_balance),
      latest_movement_id = VALUES(latest_movement_id),
      balance_recovered_at_utc = NULL,
      acknowledged_by_actor_type = IF(status = 'open', NULL, acknowledged_by_actor_type),
      acknowledged_by_actor_id = IF(status = 'open', NULL, acknowledged_by_actor_id),
      acknowledged_at_utc = IF(status = 'open', NULL, acknowledged_at_utc),
      resolution_note = IF(status = 'open', NULL, resolution_note),
      updated_at_utc = VALUES(updated_at_utc);
  ELSE
    UPDATE retail_negative_stock_exceptions
       SET latest_balance = NEW.balance_after,
           latest_movement_id = NEW.id,
           balance_recovered_at_utc = COALESCE(balance_recovered_at_utc, NEW.authoritative_at_utc),
           updated_at_utc = NEW.authoritative_at_utc
     WHERE client_id = NEW.client_id AND store_id = NEW.store_id
       AND product_id = NEW.product_id AND status <> 'resolved';
  END IF;
END$$

CREATE TRIGGER trg_stock_ledger_before_update
BEFORE UPDATE ON retail_stock_ledger_movements FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 ledger movements are immutable; post a compensating reversal';
END$$

CREATE TRIGGER trg_stock_ledger_before_delete
BEFORE DELETE ON retail_stock_ledger_movements FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M2.3 ledger movements cannot be deleted';
END$$
DELIMITER ;

-- Reviewed-execution verification queries. Migration-time results must all be
-- zero: the shadow ledger begins empty and legacy data remains untouched.
SELECT COUNT(*) AS authoritative_movements_created
  FROM retail_stock_ledger_movements;
SELECT COUNT(*) AS authoritative_balances_created
  FROM retail_stock_balances;
SELECT COUNT(*) AS reconciliation_candidates_created
  FROM retail_stock_reconciliation_candidates;
SELECT COUNT(*) AS legacy_inventory_rows_without_matching_ids
  FROM retail_store_inventory i
  LEFT JOIN stores s ON s.id = i.store_id AND s.client_id = i.client_id
  LEFT JOIN retail_products p ON p.id = i.product_id AND p.client_id = i.client_id
 WHERE s.id IS NULL OR p.id IS NULL;

-- Reconciliation boundary:
-- 1. Snapshot each legacy inventory row under a separately approved procedure.
-- 2. Compare it with the ledger balance (zero where no reviewed movements yet).
-- 3. Persist a deterministic candidate_source_key and the exact difference.
-- 4. Review tenant/store/product mapping, source provenance, active operational
--    cutover window, and mismatches before approving any opening/reconciliation.
-- 5. Post approved candidates through the same idempotent ledger writer.
-- Never rewrite sales, purchase-order lines, legacy movements, product IDs,
-- store IDs, or retail_store_inventory.quantity. Runtime cutover and any
-- legacy-column retirement require a later approved migration.
