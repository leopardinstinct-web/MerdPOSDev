-- Milestone 2A.1: hashed, single-use, ten-minute activation grants.
--
-- Preconditions:
--   * MySQL/MariaDB with SIGNAL, stored routines, information_schema, and InnoDB.
--   * clients.id exists and is an integer type.
--   * Any existing activation_grants table must expose grant_hash, client_id,
--     expires_at, and consumed_at with compatible meanings.
--
-- Production execution requires separate approval and schema reconciliation.

DROP PROCEDURE IF EXISTS apply_013_activation_grants;
DELIMITER //
CREATE PROCEDURE apply_013_activation_grants()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'clients'
      AND column_name = 'id' AND data_type IN ('int', 'bigint')
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '013 precondition failed: compatible clients.id missing';
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'activation_grants'
  ) AND (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'activation_grants'
      AND column_name IN ('client_id', 'grant_hash', 'expires_at', 'consumed_at')
  ) <> 4 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '013 incompatible activation_grants table';
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'activation_grants'
      AND column_name = 'grant_hash'
      AND NOT (data_type = 'char' AND character_maximum_length = 64)
  ) OR EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'activation_grants'
      AND column_name IN ('expires_at', 'consumed_at')
      AND data_type NOT IN ('datetime', 'timestamp')
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '013 incompatible activation grant column types';
  END IF;

  CREATE TABLE IF NOT EXISTS activation_grants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT NOT NULL,
    grant_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_activation_grant_hash (grant_hash),
    KEY idx_activation_grant_client (client_id, expires_at, consumed_at),
    KEY idx_activation_grant_cleanup (expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
END//
DELIMITER ;

CALL apply_013_activation_grants();
DROP PROCEDURE apply_013_activation_grants;

-- Verification:
SELECT column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'activation_grants'
ORDER BY ordinal_position;

SELECT index_name, non_unique, seq_in_index, column_name
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'activation_grants'
ORDER BY index_name, seq_in_index;

-- Rollback limitation: dropping this table invalidates every outstanding grant.
-- Application rollback should retain it; expired/consumed rows may be purged later.
