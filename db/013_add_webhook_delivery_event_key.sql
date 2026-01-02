ALTER TABLE webhook_delivery
  ADD COLUMN IF NOT EXISTS event_key VARCHAR(64) NULL AFTER webhook_id;

UPDATE webhook_delivery
SET event_key = 'unknown'
WHERE event_key IS NULL;

ALTER TABLE webhook_delivery
  MODIFY COLUMN event_key VARCHAR(64) NOT NULL;

SET @idx_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'webhook_delivery'
    AND index_name = 'idx_delivery_event'
);
SET @idx_sql = IF(
  @idx_exists = 0,
  'CREATE INDEX idx_delivery_event ON webhook_delivery (event_key, created_at)',
  'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql;
EXECUTE idx_stmt;
DEALLOCATE PREPARE idx_stmt;
