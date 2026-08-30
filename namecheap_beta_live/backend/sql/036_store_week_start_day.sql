ALTER TABLE stores
  ADD COLUMN IF NOT EXISTS week_start_day TINYINT UNSIGNED NOT NULL DEFAULT 1;

UPDATE stores SET week_start_day=1 WHERE week_start_day IS NULL OR week_start_day<1 OR week_start_day>7;
