-- 022_add_ops_esi_fields.sql
-- Separate ESI contract state fields from ops tracking fields.

ALTER TABLE haul_request
  ADD COLUMN IF NOT EXISTS esi_contract_id BIGINT UNSIGNED NULL AFTER contract_id,
  ADD COLUMN IF NOT EXISTS esi_status VARCHAR(64) NULL AFTER contract_status_esi,
  ADD COLUMN IF NOT EXISTS esi_acceptor_id BIGINT UNSIGNED NULL AFTER acceptor_name,
  ADD COLUMN IF NOT EXISTS esi_acceptor_name VARCHAR(255) NULL AFTER esi_acceptor_id,
  ADD COLUMN IF NOT EXISTS contract_hash CHAR(64) NULL AFTER last_contract_hash,
  ADD COLUMN IF NOT EXISTS ops_assignee_id BIGINT UNSIGNED NULL AFTER mismatch_reason_json,
  ADD COLUMN IF NOT EXISTS ops_assignee_name VARCHAR(255) NULL AFTER ops_assignee_id,
  ADD COLUMN IF NOT EXISTS ops_status VARCHAR(64) NULL AFTER ops_assignee_name;

UPDATE haul_request
SET esi_contract_id = contract_id
WHERE esi_contract_id IS NULL AND contract_id IS NOT NULL;

UPDATE haul_request
SET esi_status = contract_status_esi
WHERE esi_status IS NULL AND contract_status_esi IS NOT NULL;

UPDATE haul_request
SET esi_status = contract_status
WHERE esi_status IS NULL AND contract_status IS NOT NULL;

UPDATE haul_request
SET esi_acceptor_id = contract_acceptor_id
WHERE esi_acceptor_id IS NULL AND contract_acceptor_id IS NOT NULL;

UPDATE haul_request
SET esi_acceptor_id = acceptor_id
WHERE esi_acceptor_id IS NULL AND acceptor_id IS NOT NULL;

UPDATE haul_request
SET esi_acceptor_name = contract_acceptor_name
WHERE esi_acceptor_name IS NULL AND contract_acceptor_name IS NOT NULL;

UPDATE haul_request
SET esi_acceptor_name = acceptor_name
WHERE esi_acceptor_name IS NULL AND acceptor_name IS NOT NULL;

UPDATE haul_request
SET contract_hash = last_contract_hash
WHERE contract_hash IS NULL AND last_contract_hash IS NOT NULL;

UPDATE haul_request r
LEFT JOIN haul_assignment a ON a.request_id = r.request_id
LEFT JOIN app_user u ON u.user_id = a.hauler_user_id
SET r.ops_assignee_id = a.hauler_user_id,
    r.ops_assignee_name = u.display_name
WHERE r.ops_assignee_id IS NULL
  AND a.hauler_user_id IS NOT NULL;

CREATE OR REPLACE VIEW v_haul_request_display AS
SELECT
  r.request_id,
  r.request_key,
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
  r.esi_contract_id,
  r.contract_status,
  r.contract_status_esi,
  r.esi_status,
  r.contract_lifecycle,
  r.contract_state,
  r.acceptor_id,
  r.acceptor_name,
  r.esi_acceptor_id,
  r.esi_acceptor_name,
  r.contract_acceptor_id,
  r.contract_acceptor_name,
  r.date_accepted,
  r.date_completed,
  r.date_expired,
  r.last_contract_hash,
  r.contract_hash,
  r.last_reconciled_at,
  r.contract_matched_at,
  r.contract_linked_notified_at,
  r.mismatch_reason_json,
  r.ops_assignee_id,
  r.ops_assignee_name,
  r.ops_status,
  r.created_at,
  r.updated_at,
  r.from_location_id,
  r.from_location_type,
  r.to_location_id,
  r.to_location_type,
  COALESCE(f_ent.name, CONCAT(r.from_location_type, ':', r.from_location_id)) AS from_name,
  COALESCE(t_ent.name, CONCAT(r.to_location_type, ':', r.to_location_id)) AS to_name,
  COALESCE(r.esi_acceptor_name, r.contract_acceptor_name, a_ent.name, CONCAT('Character:', r.esi_acceptor_id)) AS esi_acceptor_display_name,
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
    ELSE 'unknown' END
LEFT JOIN eve_entity a_ent
  ON a_ent.entity_id = COALESCE(r.esi_acceptor_id, r.contract_acceptor_id)
  AND a_ent.entity_type = 'character';
