-- 004_add_collateral_to_quote.sql
-- Ensure quote table includes collateral_isk for pricing inserts.

ALTER TABLE quote
  ADD COLUMN IF NOT EXISTS collateral_isk DECIMAL(20,2) NOT NULL DEFAULT 0.00
  AFTER volume_m3;
