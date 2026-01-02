ALTER TABLE webhook_delivery
  ADD COLUMN IF NOT EXISTS next_attempt_at DATETIME NULL AFTER attempts,
  ADD COLUMN IF NOT EXISTS last_http_status INT NULL AFTER next_attempt_at,
  ADD COLUMN IF NOT EXISTS last_error TEXT NULL AFTER last_http_status,
  ADD COLUMN IF NOT EXISTS sent_at DATETIME NULL AFTER last_error;
