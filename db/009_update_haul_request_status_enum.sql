-- Ensure haul_request.status accepts requested state used for quote-created requests.
ALTER TABLE haul_request
  MODIFY status ENUM(
    'requested','awaiting_contract','in_queue','in_progress','completed','cancelled',
    'draft','quoted','submitted','posted','accepted','in_transit','delivered','expired','rejected'
  ) NOT NULL DEFAULT 'requested';
