ALTER TABLE discord_outbox
  ADD COLUMN idempotency_key VARCHAR(191) NULL AFTER dedupe_key,
  ADD UNIQUE KEY uq_discord_outbox_idempotency (idempotency_key);
