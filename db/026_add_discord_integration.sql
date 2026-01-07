CREATE TABLE IF NOT EXISTS discord_config (
  config_id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id               BIGINT UNSIGNED NOT NULL,
  enabled_webhooks      TINYINT(1) NOT NULL DEFAULT 1,
  enabled_bot           TINYINT(1) NOT NULL DEFAULT 0,
  application_id        VARCHAR(64) NULL,
  guild_id              VARCHAR(64) NULL,
  rate_limit_per_minute INT NOT NULL DEFAULT 20,
  dedupe_window_seconds INT NOT NULL DEFAULT 60,
  commands_ephemeral_default TINYINT(1) NOT NULL DEFAULT 1,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (config_id),
  UNIQUE KEY uq_discord_config_corp (corp_id),
  CONSTRAINT fk_discord_config_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_channel_map (
  channel_map_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id          BIGINT UNSIGNED NOT NULL,
  event_key        VARCHAR(64) NOT NULL,
  mode             ENUM('webhook','bot') NOT NULL DEFAULT 'webhook',
  channel_id       VARCHAR(64) NULL,
  webhook_url      VARCHAR(1024) NULL,
  is_enabled       TINYINT(1) NOT NULL DEFAULT 1,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (channel_map_id),
  KEY idx_discord_channel_event (corp_id, event_key, is_enabled),
  CONSTRAINT fk_discord_channel_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_template (
  template_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id         BIGINT UNSIGNED NOT NULL,
  event_key       VARCHAR(64) NOT NULL,
  title_template  TEXT NULL,
  body_template   TEXT NULL,
  footer_template TEXT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (template_id),
  UNIQUE KEY uq_discord_template (corp_id, event_key),
  CONSTRAINT fk_discord_template_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_outbox (
  outbox_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id         BIGINT UNSIGNED NOT NULL,
  channel_map_id  BIGINT UNSIGNED NULL,
  event_key       VARCHAR(64) NOT NULL,
  payload_json    JSON NOT NULL,
  status          ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  attempts        INT NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NULL,
  last_error      TEXT NULL,
  dedupe_key      VARCHAR(128) NULL,
  idempotency_key VARCHAR(191) NULL,
  sent_at         DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (outbox_id),
  KEY idx_discord_outbox_status (status, next_attempt_at),
  KEY idx_discord_outbox_dedupe (dedupe_key),
  UNIQUE KEY uq_discord_outbox_idempotency (idempotency_key),
  KEY idx_discord_outbox_channel (channel_map_id, status),
  CONSTRAINT fk_discord_outbox_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id),
  CONSTRAINT fk_discord_outbox_channel FOREIGN KEY (channel_map_id) REFERENCES discord_channel_map(channel_map_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_user_link (
  link_id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id         BIGINT UNSIGNED NOT NULL,
  discord_user_id VARCHAR(64) NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (link_id),
  UNIQUE KEY uq_discord_user_link (corp_id, discord_user_id),
  KEY idx_discord_user (user_id),
  CONSTRAINT fk_discord_link_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id),
  CONSTRAINT fk_discord_link_user FOREIGN KEY (user_id) REFERENCES app_user(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
