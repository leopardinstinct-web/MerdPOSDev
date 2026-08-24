-- Milestone 2A.1: hashed device tokens with expiry, rotation, and revocation.
--
-- Tracked-schema assumptions (backend/api/init_db.php):
--   devices.id INT, client_id INT, store_id INT NULL,
--   device_uuid VARCHAR(150), activation_token VARCHAR(150),
--   status ENUM('active','inactive'), created_at TIMESTAMP.
--
-- The production schema is unknown. This migration checks required legacy
-- columns and every target column, adds only missing compatible metadata, and
-- aborts visibly on conflicts. It intentionally adds no updated_at column and
-- no speculative indexes. Conditional DDL is implemented through
-- information_schema checks; raw ALTER TABLE is not claimed to be idempotent.
--
-- Production execution and the legacy-token backfill require separate approval,
-- a database backup, and reconciliation against the actual devices table.

DROP PROCEDURE IF EXISTS apply_014_device_token_security;
DELIMITER //
CREATE PROCEDURE apply_014_device_token_security()
BEGIN
  IF (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'devices'
      AND column_name IN (
        'id', 'client_id', 'store_id', 'device_uuid', 'activation_token',
        'status', 'created_at'
      )
  ) <> 7 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '014 precondition failed: tracked legacy devices columns missing';
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'devices'
      AND column_name IN ('id', 'client_id', 'store_id')
      AND data_type NOT IN ('int', 'bigint')
  ) OR EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'devices'
      AND column_name = 'status'
      AND data_type NOT IN ('enum', 'varchar', 'char')
  ) OR EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'devices'
      AND column_name = 'created_at'
      AND data_type NOT IN ('datetime', 'timestamp')
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '014 incompatible tracked devices column types';
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'devices'
      AND column_name = 'device_uuid' AND data_type = 'varchar'
      AND character_maximum_length >= 150
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '014 incompatible devices.device_uuid';
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'devices'
      AND column_name = 'activation_token' AND data_type = 'varchar'
      AND character_maximum_length >= 150
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '014 incompatible devices.activation_token';
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'devices'
      AND column_name = 'token_hash'
      AND NOT (data_type = 'char' AND character_maximum_length = 64)
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '014 incompatible devices.token_hash';
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'devices'
      AND column_name = 'previous_token_hash'
      AND NOT (data_type = 'char' AND character_maximum_length = 64)
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '014 incompatible devices.previous_token_hash';
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'devices'
      AND column_name IN (
        'token_expires_at', 'previous_token_valid_until', 'token_rotated_at',
        'revoked_at', 'activated_at'
      ) AND data_type NOT IN ('datetime', 'timestamp')
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '014 incompatible device token date column';
  END IF;

  -- All compatibility checks above complete before the first auto-committing DDL.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'devices' AND column_name = 'token_hash'
  ) THEN
    ALTER TABLE devices ADD COLUMN token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER activation_token;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'devices' AND column_name = 'previous_token_hash'
  ) THEN
    ALTER TABLE devices ADD COLUMN previous_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER token_hash;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'devices' AND column_name = 'token_expires_at') THEN
    ALTER TABLE devices ADD COLUMN token_expires_at DATETIME NULL AFTER previous_token_hash;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'devices' AND column_name = 'previous_token_valid_until') THEN
    ALTER TABLE devices ADD COLUMN previous_token_valid_until DATETIME NULL AFTER token_expires_at;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'devices' AND column_name = 'token_rotated_at') THEN
    ALTER TABLE devices ADD COLUMN token_rotated_at DATETIME NULL AFTER previous_token_valid_until;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'devices' AND column_name = 'revoked_at') THEN
    ALTER TABLE devices ADD COLUMN revoked_at DATETIME NULL AFTER token_rotated_at;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'devices' AND column_name = 'activated_at') THEN
    ALTER TABLE devices ADD COLUMN activated_at DATETIME NULL AFTER revoked_at;
  END IF;
END//
DELIMITER ;

CALL apply_014_device_token_security();
DROP PROCEDURE apply_014_device_token_security;

-- Approved legacy compatibility backfill. This preserves activation_token for
-- two application releases; newly issued tokens must never populate it.
UPDATE devices
SET token_hash = SHA2(activation_token, 256),
    token_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 180 DAY),
    activated_at = COALESCE(activated_at, created_at)
WHERE token_hash IS NULL
  AND activation_token IS NOT NULL
  AND activation_token <> '';

-- Verification:
SELECT column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'devices'
  AND column_name IN (
    'activation_token', 'token_hash', 'previous_token_hash',
    'token_expires_at', 'previous_token_valid_until', 'token_rotated_at',
    'revoked_at', 'activated_at'
  )
ORDER BY ordinal_position;

SELECT
  COUNT(*) AS legacy_tokens_without_hash
FROM devices
WHERE activation_token IS NOT NULL AND activation_token <> '' AND token_hash IS NULL;

-- Rollback limitations:
--   * Newly issued tokens have no plaintext database value and cannot be used by
--     old code after rollback; those devices require controlled reactivation.
--   * Do not drop metadata columns or restore revoked devices automatically.
--   * Retaining additive columns is safer than reversing this migration.
