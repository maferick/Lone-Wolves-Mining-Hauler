CREATE TABLE IF NOT EXISTS discord_webhook_event (
  webhook_id         BIGINT UNSIGNED NOT NULL,
  event_key          VARCHAR(64) NOT NULL,
  is_enabled         TINYINT(1) NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (webhook_id, event_key),
  KEY idx_webhook_event_key (event_key, is_enabled),
  CONSTRAINT fk_webhook_event_webhook FOREIGN KEY (webhook_id) REFERENCES discord_webhook(webhook_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
