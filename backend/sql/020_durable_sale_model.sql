-- M3.1 durable sale model foundation.
-- Source-only migration draft. Never execute against production without
-- reconciliation, backup/rollback review, and explicit migration approval.

DELIMITER $$
CREATE PROCEDURE merd_m3_1_preconditions()
BEGIN
  DECLARE v_count INT DEFAULT 0;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN ('retail_sales','retail_sale_lines','retail_stock_ledger_movements');
  IF v_count <> 3 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M3.1 precondition failed: required M2 sale and stock tables are missing';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND ((table_name='retail_sales' AND column_name IN ('client_id','store_id','device_uuid','sold_at'))
       OR (table_name='retail_sale_lines' AND column_name IN
         ('product_id','barcode_used','sku_snapshot','unit_of_measure','catalogue_unit_price',
          'price_version_id','tax_code_id','tax_rate_version_id','net_amount','tax_amount',
          'gross_line_total','currency_code')));
  IF v_count <> 16 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M3.1 precondition failed: required M2.2 sale snapshot columns are missing';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.tables
   WHERE table_schema = DATABASE() AND table_name = 'retail_sale_tenders';
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M3.1 precondition failed: durable sale target already exists';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND ((table_name='retail_sales' AND column_name='sale_uid')
       OR (table_name='retail_sale_lines' AND column_name='line_uid'));
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M3.1 precondition failed: partial durable sale target exists';
  END IF;
END$$
DELIMITER ;

CALL merd_m3_1_preconditions();
DROP PROCEDURE merd_m3_1_preconditions;

ALTER TABLE retail_sales
  ADD COLUMN sale_uid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id,
  ADD COLUMN occurred_at_utc DATETIME(6) NULL AFTER sold_at,
  ADD COLUMN accepted_at_utc DATETIME(6) NULL AFTER occurred_at_utc,
  ADD COLUMN currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER accepted_at_utc,
  ADD COLUMN subtotal_exact DECIMAL(19,2) NULL AFTER currency_code,
  ADD COLUMN manual_discount_exact DECIMAL(19,2) NULL AFTER subtotal_exact,
  ADD COLUMN tax_exact DECIMAL(19,2) NULL AFTER manual_discount_exact,
  ADD COLUMN total_exact DECIMAL(19,2) NULL AFTER tax_exact,
  ADD COLUMN receipt_contract_version VARCHAR(40) NULL AFTER total_exact,
  ADD UNIQUE KEY uq_retail_sale_client_uid (client_id, sale_uid),
  ADD UNIQUE KEY uq_retail_sale_id_scope (id, client_id, store_id),
  ADD CONSTRAINT chk_retail_sale_exact_amounts CHECK (
    (subtotal_exact IS NULL AND manual_discount_exact IS NULL
      AND tax_exact IS NULL AND total_exact IS NULL AND currency_code IS NULL)
    OR (subtotal_exact >= 0 AND manual_discount_exact >= 0 AND tax_exact >= 0
      AND total_exact >= 0 AND currency_code REGEXP '^[A-Z]{3}$')
  );

ALTER TABLE retail_sale_lines
  ADD COLUMN line_uid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id,
  ADD COLUMN original_unit_price DECIMAL(19,4) NULL AFTER catalogue_unit_price,
  ADD COLUMN automatic_promotion_snapshot JSON NULL AFTER campaign_reference,
  ADD COLUMN manual_discount_amount DECIMAL(19,2) NULL AFTER automatic_promotion_snapshot,
  ADD COLUMN manual_discount_reason VARCHAR(255) NULL AFTER manual_discount_amount,
  ADD COLUMN manual_discount_actor_id VARCHAR(191) NULL AFTER manual_discount_reason,
  ADD COLUMN taxable_amount DECIMAL(19,2) NULL AFTER manual_discount_actor_id,
  ADD UNIQUE KEY uq_retail_sale_line_uid (retail_sale_id, line_uid),
  ADD CONSTRAINT chk_retail_line_m3_amounts CHECK (
    (original_unit_price IS NULL OR original_unit_price >= 0)
    AND (manual_discount_amount IS NULL OR manual_discount_amount >= 0)
    AND (taxable_amount IS NULL OR taxable_amount >= 0)
  );

CREATE TABLE retail_sale_tenders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tender_uid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  retail_sale_id BIGINT UNSIGNED NOT NULL,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  tender_type VARCHAR(20) NOT NULL,
  currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  amount_due DECIMAL(19,2) NOT NULL,
  amount_tendered DECIMAL(19,2) NOT NULL,
  change_due DECIMAL(19,2) NOT NULL DEFAULT 0,
  recorded_at_utc DATETIME(6) NOT NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_retail_tender_uid (client_id, tender_uid),
  UNIQUE KEY uq_retail_tender_sale (retail_sale_id),
  CONSTRAINT chk_retail_tender_type CHECK (tender_type IN ('cash','card_recorded')),
  CONSTRAINT chk_retail_tender_amounts CHECK (
    amount_due >= 0 AND amount_tendered >= amount_due
    AND change_due = amount_tendered - amount_due
    AND (tender_type <> 'card_recorded'
      OR (amount_tendered = amount_due AND change_due = 0))
  ),
  CONSTRAINT chk_retail_tender_currency CHECK (currency_code REGEXP '^[A-Z]{3}$'),
  CONSTRAINT fk_retail_tender_sale_scope
    FOREIGN KEY (retail_sale_id, client_id, store_id)
    REFERENCES retail_sales(id, client_id, store_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification queries for a separately approved reviewed execution.
SELECT COUNT(*) AS preserved_sales_without_m3_identity
  FROM retail_sales WHERE sale_uid IS NULL;
SELECT COUNT(*) AS preserved_lines_without_m3_identity
  FROM retail_sale_lines WHERE line_uid IS NULL;
SELECT COUNT(*) AS new_tenders FROM retail_sale_tenders;

-- Rollback/forward-fix limitations:
-- DDL auto-commits. New columns/tables may be removed only before M3 writers
-- persist durable identities or tenders. Historical M3 sales, lines, and
-- tenders must never be rewritten or deleted to force rollback.
