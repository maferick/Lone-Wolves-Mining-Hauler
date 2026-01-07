ALTER TABLE discord_config
  MODIFY COLUMN auto_thread_create_on_request TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN thread_auto_archive_minutes INT NOT NULL DEFAULT 1440 AFTER auto_thread_create_on_request;

ALTER TABLE discord_thread
  DROP FOREIGN KEY fk_discord_thread_request,
  DROP FOREIGN KEY fk_discord_thread_corp,
  DROP PRIMARY KEY,
  DROP INDEX uq_discord_thread_request,
  CHANGE COLUMN channel_id ops_channel_id VARCHAR(64) NOT NULL,
  MODIFY COLUMN thread_id VARCHAR(64) NULL,
  ADD COLUMN anchor_message_id VARCHAR(64) NULL AFTER ops_channel_id,
  ADD COLUMN thread_state ENUM('active','archived','missing','creating') NOT NULL DEFAULT 'active' AFTER anchor_message_id,
  ADD COLUMN thread_created_at DATETIME NULL AFTER thread_state,
  ADD COLUMN thread_last_posted_at DATETIME NULL AFTER thread_created_at,
  ADD PRIMARY KEY (request_id),
  ADD UNIQUE KEY uq_discord_thread_id (thread_id),
  ADD KEY idx_discord_thread_corp (corp_id),
  ADD CONSTRAINT fk_discord_thread_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id),
  ADD CONSTRAINT fk_discord_thread_request FOREIGN KEY (request_id) REFERENCES haul_request(request_id) ON DELETE CASCADE;

UPDATE discord_thread
   SET thread_created_at = COALESCE(thread_created_at, created_at)
 WHERE thread_created_at IS NULL;
