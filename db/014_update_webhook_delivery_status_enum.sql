-- Ensure webhook_delivery.status supports pending state used by webhook queue.
UPDATE webhook_delivery SET status = 'pending' WHERE status = 'queued';

ALTER TABLE webhook_delivery
  MODIFY status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending';
