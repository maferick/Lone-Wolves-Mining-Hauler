ALTER TABLE ops_event
  MODIFY old_state ENUM(
    'AWAITING_CONTRACT',
    'AVAILABLE',
    'PICKED_UP',
    'DELIVERED',
    'FAILED',
    'EXPIRED'
  ) NULL,
  MODIFY new_state ENUM(
    'AWAITING_CONTRACT',
    'AVAILABLE',
    'PICKED_UP',
    'DELIVERED',
    'FAILED',
    'EXPIRED'
  ) NOT NULL;
