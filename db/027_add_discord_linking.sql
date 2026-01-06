CREATE TABLE IF NOT EXISTS discord_user_link_new (
  user_id         BIGINT UNSIGNED NOT NULL,
  discord_user_id VARCHAR(64) NOT NULL,
  discord_username VARCHAR(128) NULL,
  linked_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at    TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_discord_user_link_discord (discord_user_id),
  CONSTRAINT fk_discord_link_user FOREIGN KEY (user_id) REFERENCES app_user(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO discord_user_link_new (user_id, discord_user_id, discord_username, linked_at, last_seen_at)
SELECT user_id, discord_user_id, NULL, created_at, updated_at
  FROM discord_user_link;

DROP TABLE discord_user_link;
RENAME TABLE discord_user_link_new TO discord_user_link;

CREATE TABLE IF NOT EXISTS discord_link_code (
  code                    VARCHAR(16) NOT NULL,
  user_id                 BIGINT UNSIGNED NOT NULL,
  expires_at              DATETIME NOT NULL,
  used_at                 DATETIME NULL,
  used_by_discord_user_id VARCHAR(64) NULL,
  created_at              DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
  PRIMARY KEY (code),
  KEY idx_discord_link_code_user (user_id),
  KEY idx_discord_link_code_expires (expires_at),
  CONSTRAINT fk_discord_link_code_user FOREIGN KEY (user_id) REFERENCES app_user(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
