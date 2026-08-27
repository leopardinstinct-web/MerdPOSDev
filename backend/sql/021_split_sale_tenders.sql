-- M3.3 split sale tender foundation.
-- Source-only migration draft. Never execute against production without
-- reconciliation, backup/rollback review, and explicit migration approval.

DELIMITER $$
CREATE PROCEDURE merd_m3_3_preconditions()
BEGIN
  DECLARE v_count INT DEFAULT 0;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN ('retail_sales','retail_sale_tenders');
  IF v_count <> 2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M3.3 precondition failed: M3.1 sale/tender tables are missing';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'retail_sale_tenders'
     AND index_name = 'uq_retail_tender_sale';
  IF v_count <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M3.3 precondition failed: expected M3.1 one-tender constraint is missing';
  END IF;

  SELECT COUNT(*) INTO v_count
    FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'retail_sale_tenders'
     AND column_name IN ('sequence_number','actor_id','device_uuid','external_reference');
  IF v_count <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M3.3 precondition failed: split-tender target already or partially exists';
  END IF;
END$$
DELIMITER ;

CALL merd_m3_3_preconditions();
DROP PROCEDURE merd_m3_3_preconditions;

ALTER TABLE retail_sale_tenders
  DROP INDEX uq_retail_tender_sale,
  DROP CONSTRAINT chk_retail_tender_amounts,
  ADD COLUMN sequence_number SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER store_id,
  ADD COLUMN actor_id VARCHAR(191) NULL AFTER recorded_at_utc,
  ADD COLUMN device_uuid VARCHAR(191) NULL AFTER actor_id,
  ADD COLUMN external_reference VARCHAR(191) NULL AFTER device_uuid,
  ADD UNIQUE KEY uq_retail_tender_sale_sequence (retail_sale_id, sequence_number),
  ADD CONSTRAINT chk_retail_tender_sequence CHECK (sequence_number > 0),
  ADD CONSTRAINT chk_retail_tender_component_amounts CHECK (
    amount_due >= 0 AND amount_tendered > 0 AND change_due >= 0
    AND amount_tendered - change_due > 0
    AND (tender_type <> 'card_recorded' OR change_due = 0)
  );

-- Verification queries for a separately approved reviewed execution.
SELECT COUNT(*) AS preserved_sequence_one_tenders
  FROM retail_sale_tenders WHERE sequence_number = 1;
SELECT COUNT(*) AS invalid_component_amounts
  FROM retail_sale_tenders
 WHERE amount_tendered <= 0 OR change_due < 0
    OR amount_tendered - change_due <= 0
    OR (tender_type = 'card_recorded' AND change_due <> 0);

-- Rollback/forward-fix limitations:
-- DDL auto-commits. The one-tender unique constraint cannot be restored after
-- any sale receives multiple tender rows. Existing or new tender history must
-- never be deleted to force rollback.
