-- 007_add_ship_class_to_haul_request.sql
-- Ensure haul_request has the columns needed for quote-based requests.

ALTER TABLE haul_request
  ADD COLUMN IF NOT EXISTS ship_class VARCHAR(32) NULL AFTER quote_id,
  ADD COLUMN IF NOT EXISTS expected_jumps INT UNSIGNED NULL AFTER rush,
  ADD COLUMN IF NOT EXISTS route_policy ENUM('shortest','balanced','safest','avoid_low','avoid_null','custom') NOT NULL DEFAULT 'safest' AFTER expected_jumps,
  ADD COLUMN IF NOT EXISTS route_system_ids LONGTEXT NULL COMMENT 'Cached JSON array of system_ids (optional, can be derived)' AFTER route_policy,
  ADD COLUMN IF NOT EXISTS price_breakdown_json JSON NULL AFTER route_system_ids;
