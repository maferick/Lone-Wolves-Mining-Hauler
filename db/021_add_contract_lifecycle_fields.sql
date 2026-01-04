-- 021_add_contract_lifecycle_fields.sql
-- Add canonical contract lifecycle fields and webhook delivery de-duplication.

ALTER TABLE haul_request
  ADD COLUMN IF NOT EXISTS acceptor_id BIGINT UNSIGNED NULL AFTER contract_status_esi,
  ADD COLUMN IF NOT EXISTS acceptor_name VARCHAR(255) NULL AFTER acceptor_id,
  ADD COLUMN IF NOT EXISTS contract_lifecycle ENUM('AWAITING_CONTRACT','AVAILABLE','PICKED_UP','DELIVERED','FAILED','EXPIRED') NULL AFTER acceptor_name,
  ADD COLUMN IF NOT EXISTS last_contract_hash CHAR(64) NULL AFTER contract_lifecycle;

UPDATE haul_request
SET contract_lifecycle = contract_state
WHERE contract_lifecycle IS NULL AND contract_state IS NOT NULL;

UPDATE haul_request
SET contract_lifecycle = 'AWAITING_CONTRACT'
WHERE contract_id IS NULL AND (contract_lifecycle IS NULL OR contract_lifecycle = '');

ALTER TABLE webhook_delivery
  ADD COLUMN IF NOT EXISTS entity_type VARCHAR(64) NULL AFTER event_key,
  ADD COLUMN IF NOT EXISTS entity_id BIGINT UNSIGNED NULL AFTER entity_type,
  ADD COLUMN IF NOT EXISTS transition_to VARCHAR(64) NULL AFTER entity_id;

SET @idx_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'webhook_delivery'
    AND index_name = 'uq_delivery_transition'
);
SET @idx_sql = IF(
  @idx_exists = 0,
  'CREATE UNIQUE INDEX uq_delivery_transition ON webhook_delivery (event_key, entity_type, entity_id, transition_to)',
  'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql;
EXECUTE idx_stmt;
DEALLOCATE PREPARE idx_stmt;

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
  r.contract_status,
  r.contract_status_esi,
  r.contract_lifecycle,
  r.contract_state,
  r.acceptor_id,
  r.acceptor_name,
  r.contract_acceptor_id,
  r.contract_acceptor_name,
  r.date_accepted,
  r.date_completed,
  r.date_expired,
  r.last_contract_hash,
  r.last_reconciled_at,
  r.contract_matched_at,
  r.contract_linked_notified_at,
  r.mismatch_reason_json,
  r.created_at,
  r.updated_at,
  r.from_location_id,
  r.from_location_type,
  r.to_location_id,
  r.to_location_type,
  COALESCE(
    f_sys.system_name,
    f_station.station_name,
    f_structure.structure_name,
    f_ent.name,
    CONCAT(r.from_location_type, ':', r.from_location_id)
  ) AS from_name,
  COALESCE(
    t_sys.system_name,
    t_station.station_name,
    t_structure.structure_name,
    t_ent.name,
    CONCAT(r.to_location_type, ':', r.to_location_id)
  ) AS to_name,
  COALESCE(r.contract_acceptor_name, a_ent.name, CONCAT('Character:', r.contract_acceptor_id)) AS contract_acceptor_name,
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
LEFT JOIN eve_system f_sys
  ON f_sys.system_id = r.from_location_id
  AND r.from_location_type = 'system'
LEFT JOIN eve_station f_station
  ON f_station.station_id = r.from_location_id
  AND r.from_location_type = 'station'
LEFT JOIN eve_structure f_structure
  ON f_structure.structure_id = r.from_location_id
  AND r.from_location_type = 'structure'
LEFT JOIN eve_entity t_ent
  ON t_ent.entity_id = r.to_location_id
  AND t_ent.entity_type = CASE r.to_location_type
    WHEN 'system' THEN 'system'
    WHEN 'station' THEN 'station'
    WHEN 'structure' THEN 'structure'
    ELSE 'unknown' END
LEFT JOIN eve_system t_sys
  ON t_sys.system_id = r.to_location_id
  AND r.to_location_type = 'system'
LEFT JOIN eve_station t_station
  ON t_station.station_id = r.to_location_id
  AND r.to_location_type = 'station'
LEFT JOIN eve_structure t_structure
  ON t_structure.structure_id = r.to_location_id
  AND r.to_location_type = 'structure'
LEFT JOIN eve_entity a_ent
  ON a_ent.entity_id = r.contract_acceptor_id
  AND a_ent.entity_type = 'character';
