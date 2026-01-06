ALTER TABLE discord_config
  ADD COLUMN IF NOT EXISTS public_key VARCHAR(128) NULL AFTER application_id,
  ADD COLUMN IF NOT EXISTS bot_token_configured TINYINT(1) NOT NULL DEFAULT 0 AFTER public_key,
  ADD COLUMN IF NOT EXISTS channel_mode ENUM('threads','channels') NOT NULL DEFAULT 'threads' AFTER commands_ephemeral_default,
  ADD COLUMN IF NOT EXISTS hauling_channel_id VARCHAR(64) NULL AFTER channel_mode,
  ADD COLUMN IF NOT EXISTS requester_thread_access ENUM('none','read_only','full') NOT NULL DEFAULT 'read_only' AFTER hauling_channel_id,
  ADD COLUMN IF NOT EXISTS auto_thread_create_on_request TINYINT(1) NOT NULL DEFAULT 1 AFTER requester_thread_access,
  ADD COLUMN IF NOT EXISTS auto_archive_on_complete TINYINT(1) NOT NULL DEFAULT 1 AFTER auto_thread_create_on_request,
  ADD COLUMN IF NOT EXISTS auto_lock_on_complete TINYINT(1) NOT NULL DEFAULT 1 AFTER auto_archive_on_complete,
  ADD COLUMN IF NOT EXISTS role_map_json JSON NULL AFTER auto_lock_on_complete,
  ADD COLUMN IF NOT EXISTS last_bot_action_at DATETIME NULL AFTER role_map_json;

CREATE TABLE IF NOT EXISTS discord_thread (
  thread_id   VARCHAR(64) NOT NULL,
  corp_id     BIGINT UNSIGNED NOT NULL,
  request_id  BIGINT UNSIGNED NOT NULL,
  channel_id  VARCHAR(64) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
  updated_at  DATETIME NOT NULL DEFAULT UTC_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (thread_id),
  UNIQUE KEY uq_discord_thread_request (request_id),
  KEY idx_discord_thread_corp (corp_id),
  CONSTRAINT fk_discord_thread_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id),
  CONSTRAINT fk_discord_thread_request FOREIGN KEY (request_id) REFERENCES haul_request(request_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
