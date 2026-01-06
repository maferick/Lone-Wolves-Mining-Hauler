ALTER TABLE discord_webhook
  ADD COLUMN IF NOT EXISTS provider VARCHAR(16) NOT NULL DEFAULT 'discord' AFTER webhook_name;

UPDATE discord_webhook
   SET provider = 'discord'
 WHERE provider IS NULL OR provider = '';
