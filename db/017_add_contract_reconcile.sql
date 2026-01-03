-- 017_add_contract_reconcile.sql
-- Add contract_acceptor_id for reconciling ESI contract acceptor updates.

ALTER TABLE haul_request
  ADD COLUMN IF NOT EXISTS contract_acceptor_id BIGINT UNSIGNED NULL AFTER contract_status;
