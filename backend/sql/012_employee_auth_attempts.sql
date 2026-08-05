-- Milestone 2A.1: durable PIN lockout state and rolling failure history.
--
-- Preconditions:
--   * MySQL/MariaDB with SIGNAL, stored routines, information_schema, and InnoDB.
--   * The clients table exists with an integer id column.
--   * Existing tables with these names, if any, must match the verified shape.
--
-- Production execution requires separate approval and a schema backup/review.
-- CREATE TABLE IF NOT EXISTS is used only after incompatible existing tables
-- are rejected; raw ALTER TABLE is not presented as idempotent.

DROP PROCEDURE IF EXISTS apply_012_employee_auth_attempts;
DELIMITER //
CREATE PROCEDURE apply_012_employee_auth_attempts()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'clients'
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '012 precondition failed: clients table missing';
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'employee_auth_attempts'
  ) AND (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'employee_auth_attempts'
      AND column_name IN (
        'client_id', 'employee_id', 'user_id', 'device_uuid', 'action',
        'failed_attempts', 'locked_until', 'last_failed_at', 'last_success_at'
      )
  ) <> 9 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '012 incompatible employee_auth_attempts table';
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'employee_auth_failure_events'
  ) AND (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'employee_auth_failure_events'
      AND column_name IN (
        'client_id', 'employee_id', 'user_id', 'device_uuid', 'action', 'occurred_at'
      )
  ) <> 6 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '012 incompatible employee_auth_failure_events table';
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'employee_auth_global_locks'
  ) AND (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'employee_auth_global_locks'
      AND column_name IN ('client_id', 'employee_id', 'user_id', 'action', 'locked_until')
  ) <> 5 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '012 incompatible employee_auth_global_locks table';
  END IF;

  CREATE TABLE IF NOT EXISTS employee_auth_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT NOT NULL,
    employee_id INT NULL,
    user_id VARCHAR(50) NOT NULL,
    device_uuid VARCHAR(150) NOT NULL,
    action VARCHAR(32) NOT NULL,
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_failed_at DATETIME NULL,
    last_success_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_device_counter (client_id, user_id, device_uuid, action),
    KEY idx_auth_device_lock (client_id, user_id, action, locked_until)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  CREATE TABLE IF NOT EXISTS employee_auth_failure_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT NOT NULL,
    employee_id INT NULL,
    user_id VARCHAR(50) NOT NULL,
    device_uuid VARCHAR(150) NOT NULL,
    action VARCHAR(32) NOT NULL,
    occurred_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_auth_failure_window (client_id, user_id, action, occurred_at),
    KEY idx_auth_failure_retention (occurred_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  CREATE TABLE IF NOT EXISTS employee_auth_global_locks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT NOT NULL,
    employee_id INT NULL,
    user_id VARCHAR(50) NOT NULL,
    action VARCHAR(32) NOT NULL,
    locked_until DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_global_lock (client_id, user_id, action),
    KEY idx_auth_global_expiry (locked_until)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
END//
DELIMITER ;

CALL apply_012_employee_auth_attempts();
DROP PROCEDURE apply_012_employee_auth_attempts;

-- Verification (must return the three tables and their expected columns):
SELECT table_name, column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN (
    'employee_auth_attempts',
    'employee_auth_failure_events',
    'employee_auth_global_locks'
  )
ORDER BY table_name, ordinal_position;

SELECT table_name, index_name, non_unique, seq_in_index, column_name
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN (
    'employee_auth_attempts',
    'employee_auth_failure_events',
    'employee_auth_global_locks'
  )
ORDER BY table_name, index_name, seq_in_index;

-- Rollback limitation: dropping these tables destroys lockout and audit history.
-- Rollback should normally revert application code and retain these additive tables.
