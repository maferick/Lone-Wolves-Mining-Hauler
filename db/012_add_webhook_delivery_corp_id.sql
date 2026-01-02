ALTER TABLE webhook_delivery
  ADD COLUMN IF NOT EXISTS corp_id BIGINT UNSIGNED NULL AFTER delivery_id;

UPDATE webhook_delivery d
JOIN discord_webhook w ON w.webhook_id = d.webhook_id
SET d.corp_id = w.corp_id
WHERE d.corp_id IS NULL;

ALTER TABLE webhook_delivery
  MODIFY COLUMN corp_id BIGINT UNSIGNED NOT NULL;

SET @idx_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'webhook_delivery'
    AND index_name = 'idx_delivery_corp_status'
);
SET @idx_sql = IF(
  @idx_exists = 0,
  'CREATE INDEX idx_delivery_corp_status ON webhook_delivery (corp_id, status, next_attempt_at)',
  'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql;
EXECUTE idx_stmt;
DEALLOCATE PREPARE idx_stmt;

SET @fk_exists = (
  SELECT COUNT(*)
  FROM information_schema.table_constraints
  WHERE constraint_schema = DATABASE()
    AND table_name = 'webhook_delivery'
    AND constraint_name = 'fk_delivery_corp'
    AND constraint_type = 'FOREIGN KEY'
);
SET @fk_sql = IF(
  @fk_exists = 0,
  'ALTER TABLE webhook_delivery ADD CONSTRAINT fk_delivery_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)',
  'SELECT 1'
);
PREPARE fk_stmt FROM @fk_sql;
EXECUTE fk_stmt;
DEALLOCATE PREPARE fk_stmt;
