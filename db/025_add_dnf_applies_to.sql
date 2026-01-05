ALTER TABLE dnf_rule
  ADD COLUMN apply_pickup TINYINT(1) NOT NULL DEFAULT 1 AFTER is_hard_block,
  ADD COLUMN apply_delivery TINYINT(1) NOT NULL DEFAULT 1 AFTER apply_pickup,
  ADD COLUMN apply_transit TINYINT(1) NOT NULL DEFAULT 1 AFTER apply_delivery;
