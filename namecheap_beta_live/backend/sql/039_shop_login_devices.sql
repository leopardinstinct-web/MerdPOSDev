-- MERDPOS shop-login device identity and replay ledger.
-- Safe additive migration; existing devices are backfilled where their numeric id fits 4 digits.

ALTER TABLE devices
  ADD COLUMN IF NOT EXISTS device_code CHAR(4) NULL AFTER store_id;

UPDATE devices
SET device_code = LPAD(id, 4, '0')
WHERE device_code IS NULL AND id BETWEEN 1 AND 9999;

SET @has_device_code_index := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'devices'
    AND index_name = 'uq_devices_client_device_code'
);
SET @device_code_index_sql := IF(
  @has_device_code_index = 0,
  'ALTER TABLE devices ADD UNIQUE KEY uq_devices_client_device_code (client_id, device_code)',
  'SELECT 1'
);
PREPARE merd_stmt FROM @device_code_index_sql;
EXECUTE merd_stmt;
DEALLOCATE PREPARE merd_stmt;

CREATE TABLE IF NOT EXISTS shop_qr_uses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  client_id INT NOT NULL,
  actor_key VARCHAR(191) NOT NULL,
  device_id INT NOT NULL,
  store_id INT NOT NULL,
  action ENUM('IN','OUT') NOT NULL,
  used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shop_qr_actor (token_hash, actor_key),
  KEY idx_shop_qr_scope (client_id, actor_key, used_at),
  KEY idx_shop_qr_device (device_id, used_at),
  CONSTRAINT fk_shop_qr_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_shop_qr_device FOREIGN KEY (device_id) REFERENCES devices(id),
  CONSTRAINT fk_shop_qr_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
