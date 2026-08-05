-- Milestone 2A.1: redacted security-event persistence.
--
-- Preconditions:
--   * MySQL/MariaDB with SIGNAL, stored routines, information_schema, and InnoDB.
--   * Existing table, if present, must include the verified core columns.
--
-- Production execution and retention scheduling require separate approval.

DROP PROCEDURE IF EXISTS apply_015_security_audit_events;
DELIMITER //
CREATE PROCEDURE apply_015_security_audit_events()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'security_audit_events'
  ) AND (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'security_audit_events'
      AND column_name IN ('event_type', 'outcome', 'ip_address', 'metadata', 'created_at')
  ) <> 5 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '015 incompatible security_audit_events table';
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'security_audit_events'
      AND column_name IN ('event_type', 'outcome', 'ip_address')
      AND data_type NOT IN ('varchar', 'char')
  ) OR EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'security_audit_events'
      AND column_name = 'created_at'
      AND data_type NOT IN ('datetime', 'timestamp')
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '015 incompatible security audit column types';
  END IF;

  CREATE TABLE IF NOT EXISTS security_audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT NULL,
    employee_id INT NULL,
    device_id INT NULL,
    event_type VARCHAR(80) NOT NULL,
    outcome VARCHAR(32) NOT NULL,
    actor_type VARCHAR(32) NULL,
    actor_id VARCHAR(80) NULL,
    request_id VARCHAR(64) NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    metadata TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_security_client_date (client_id, created_at),
    KEY idx_security_event_date (event_type, created_at),
    KEY idx_security_retention (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
END//
DELIMITER ;

CALL apply_015_security_audit_events();
DROP PROCEDURE apply_015_security_audit_events;

-- Verification:
SELECT column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'security_audit_events'
ORDER BY ordinal_position;

SELECT index_name, non_unique, seq_in_index, column_name
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'security_audit_events'
ORDER BY index_name, seq_in_index;

-- Approved retention operation (schedule only during a separately approved
-- deployment): DELETE FROM security_audit_events
--              WHERE created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY;
-- Rollback limitation: dropping this table destroys security evidence. Retain
-- it when rolling application code back.
