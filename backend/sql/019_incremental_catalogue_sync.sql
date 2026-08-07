-- M2.6 server-issued catalogue cursors and replayable incremental batches.
-- Additive only. No catalogue, transaction, or device history is rewritten.

CREATE TABLE IF NOT EXISTS retail_catalogue_cursor_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  cursor_token VARCHAR(120) NOT NULL,
  snapshot_revision VARCHAR(80) NOT NULL,
  snapshot_json LONGTEXT NOT NULL,
  created_at_utc DATETIME(6) NOT NULL,
  expires_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_catalogue_cursor_token (cursor_token),
  KEY ix_catalogue_cursor_scope (client_id,store_id,id),
  KEY ix_catalogue_cursor_expiry (expires_at_utc),
  CONSTRAINT fk_catalogue_cursor_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_catalogue_cursor_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT chk_catalogue_cursor_json CHECK (JSON_VALID(snapshot_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS retail_catalogue_sync_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT NOT NULL,
  store_id INT NOT NULL,
  batch_token VARCHAR(120) NOT NULL,
  source_cursor_token VARCHAR(120) NOT NULL,
  target_cursor_token VARCHAR(120) NOT NULL,
  target_snapshot_revision VARCHAR(80) NOT NULL,
  events_json LONGTEXT NOT NULL,
  page_size INT UNSIGNED NOT NULL,
  created_at_utc DATETIME(6) NOT NULL,
  expires_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_catalogue_batch_token (batch_token),
  KEY ix_catalogue_batch_scope (client_id,store_id,source_cursor_token),
  KEY ix_catalogue_batch_expiry (expires_at_utc),
  CONSTRAINT fk_catalogue_batch_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_catalogue_batch_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT chk_catalogue_batch_events CHECK (JSON_VALID(events_json)),
  CONSTRAINT chk_catalogue_batch_page_size CHECK (page_size BETWEEN 1 AND 250)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
