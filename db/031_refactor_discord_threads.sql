ALTER TABLE discord_config
  MODIFY COLUMN auto_thread_create_on_request TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN thread_auto_archive_minutes INT NOT NULL DEFAULT 1440 AFTER auto_thread_create_on_request;

DROP INDEX IF EXISTS uq_discord_thread_id ON discord_thread;

SET @has_channel_id = (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE table_schema = DATABASE()
     AND table_name = 'discord_thread'
     AND column_name = 'channel_id'
);

SET @rename_channel_sql = IF(
  @has_channel_id > 0,
  'ALTER TABLE discord_thread CHANGE COLUMN channel_id ops_channel_id VARCHAR(64) NOT NULL',
  'SELECT 1'
);
PREPARE rename_channel_stmt FROM @rename_channel_sql;
EXECUTE rename_channel_stmt;
DEALLOCATE PREPARE rename_channel_stmt;

SET @has_request_index = (
  SELECT COUNT(*)
    FROM information_schema.STATISTICS
   WHERE table_schema = DATABASE()
     AND table_name = 'discord_thread'
     AND index_name = 'uq_discord_thread_request'
);

SET @drop_request_index_sql = IF(
  @has_request_index > 0,
  'DROP INDEX uq_discord_thread_request ON discord_thread',
  'SELECT 1'
);
PREPARE drop_request_index_stmt FROM @drop_request_index_sql;
EXECUTE drop_request_index_stmt;
DEALLOCATE PREPARE drop_request_index_stmt;

ALTER TABLE discord_thread
  DROP FOREIGN KEY fk_discord_thread_request,
  DROP FOREIGN KEY fk_discord_thread_corp,
  DROP PRIMARY KEY,
  DROP INDEX idx_discord_thread_corp,
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
