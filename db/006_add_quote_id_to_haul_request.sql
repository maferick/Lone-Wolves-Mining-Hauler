-- 006_add_quote_id_to_haul_request.sql
-- Ensure haul_request.quote_id exists for quote-based requests.

ALTER TABLE haul_request
  ADD COLUMN IF NOT EXISTS quote_id BIGINT UNSIGNED NULL AFTER reward_isk;

CREATE INDEX IF NOT EXISTS idx_request_quote ON haul_request (quote_id);
