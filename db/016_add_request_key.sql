-- 016_add_request_key.sql
-- Add opaque request keys for public URLs

ALTER TABLE haul_request
  ADD COLUMN IF NOT EXISTS request_key VARCHAR(64) NOT NULL DEFAULT '' AFTER request_id;

UPDATE haul_request
  SET request_key = LOWER(REPLACE(UUID(), '-', ''))
  WHERE request_key = '' OR request_key IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_request_key
  ON haul_request (request_key);
