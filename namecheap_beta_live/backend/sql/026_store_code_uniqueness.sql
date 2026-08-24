ALTER TABLE stores
  ADD UNIQUE KEY uq_stores_client_store_code (client_id, store_code);
