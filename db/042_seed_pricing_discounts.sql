INSERT INTO pricing_discount_rules
  (name, description, enabled, priority, stackable, customer_scope, route_scope, discount_type, discount_value, max_discount_isk, created_at, updated_at)
VALUES
  ('New Customer Welcome', '10% off for new customers, capped at 50M ISK.', 1, 50, 0, 'any', 'any', 'percent', 10.0000, 50000000.00, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('Volume Tier', '7% off for contracts >= 500,000 m3.', 1, 60, 0, 'any', 'any', 'percent', 7.0000, 100000000.00, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('Backhaul Lane Promo', '5% off for specific lane pairing.', 1, 40, 0, 'any', 'lane', 'percent', 5.0000, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  ('Off-Peak Hours', '5% off for off-peak hours.', 1, 70, 0, 'any', 'any', 'percent', 5.0000, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP());

UPDATE pricing_discount_rules
SET min_volume_m3 = 500000.00
WHERE name = 'Volume Tier';

UPDATE pricing_discount_rules
SET pickup_id = 30000142, drop_id = 30002187
WHERE name = 'Backhaul Lane Promo';

UPDATE pricing_discount_rules
SET offpeak_start_hhmm = '02:00', offpeak_end_hhmm = '10:00'
WHERE name = 'Off-Peak Hours';
