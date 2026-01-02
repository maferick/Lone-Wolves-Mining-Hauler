ALTER TABLE haul_request
  ADD COLUMN IF NOT EXISTS route_profile VARCHAR(32) NULL AFTER route_policy,
  ADD COLUMN IF NOT EXISTS contract_hint_text VARCHAR(255) NOT NULL DEFAULT '' AFTER price_breakdown_json,
  ADD COLUMN IF NOT EXISTS contract_matched_at DATETIME NULL AFTER contract_status,
  ADD COLUMN IF NOT EXISTS contract_validation_json JSON NULL AFTER contract_matched_at,
  ADD COLUMN IF NOT EXISTS mismatch_reason_json JSON NULL AFTER contract_validation_json;

ALTER TABLE haul_request
  MODIFY status ENUM(
    'requested','awaiting_contract','contract_linked','contract_mismatch','in_queue','in_progress','completed','cancelled',
    'draft','quoted','submitted','posted','accepted','in_transit','delivered','expired','rejected'
  ) NOT NULL DEFAULT 'requested';

UPDATE haul_request
   SET route_profile = COALESCE(route_profile, route_policy)
 WHERE route_profile IS NULL;

UPDATE haul_request
   SET contract_hint_text = CASE
     WHEN contract_hint_text IS NOT NULL AND contract_hint_text <> '' THEN contract_hint_text
     WHEN quote_id IS NOT NULL THEN CONCAT('Quote #', quote_id)
     ELSE CONCAT('Request #', request_id)
   END
 WHERE contract_hint_text = '' OR contract_hint_text IS NULL;

ALTER TABLE discord_webhook
  ADD COLUMN IF NOT EXISTS notify_on_contract_link TINYINT(1) NOT NULL DEFAULT 0 AFTER is_enabled;

CREATE OR REPLACE VIEW v_haul_request_display AS
SELECT
  r.request_id,
  r.corp_id,
  c.corp_name,
  r.service_id,
  s.service_name,
  r.status,
  r.title,
  r.volume_m3,
  r.collateral_isk,
  r.reward_isk,
  r.expected_jumps,
  r.route_policy,
  r.route_profile,
  r.contract_id,
  r.contract_status,
  r.contract_matched_at,
  r.mismatch_reason_json,
  r.created_at,
  r.updated_at,
  r.from_location_id,
  r.from_location_type,
  r.to_location_id,
  r.to_location_type,
  COALESCE(f_ent.name, CONCAT(r.from_location_type, ':', r.from_location_id)) AS from_name,
  COALESCE(t_ent.name, CONCAT(r.to_location_type, ':', r.to_location_id)) AS to_name,
  u.display_name AS requester_display_name,
  COALESCE(u.character_name, r.requester_character_name) AS requester_character_name
FROM haul_request r
JOIN corp c ON c.corp_id = r.corp_id
JOIN haul_service s ON s.service_id = r.service_id
JOIN app_user u ON u.user_id = r.requester_user_id
LEFT JOIN eve_entity f_ent
  ON f_ent.entity_id = r.from_location_id
  AND f_ent.entity_type = CASE r.from_location_type
    WHEN 'system' THEN 'system'
    WHEN 'station' THEN 'station'
    WHEN 'structure' THEN 'structure'
    ELSE 'unknown' END
LEFT JOIN eve_entity t_ent
  ON t_ent.entity_id = r.to_location_id
  AND t_ent.entity_type = CASE r.to_location_type
    WHEN 'system' THEN 'system'
    WHEN 'station' THEN 'station'
    WHEN 'structure' THEN 'structure'
    ELSE 'unknown' END;
