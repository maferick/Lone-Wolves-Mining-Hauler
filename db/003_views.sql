-- 003_views.sql
-- Optional extra views for “name-first” UI patterns

CREATE OR REPLACE VIEW v_contract_display AS
SELECT
  cc.corp_id,
  c.corp_name,
  cc.contract_id,
  cc.type,
  cc.status,
  cc.title,
  cc.volume_m3,
  cc.collateral_isk,
  cc.reward_isk,
  cc.date_issued,
  cc.date_expired,
  cc.start_location_id,
  cc.end_location_id,
  COALESCE(
    s_sys.system_name,
    s_station.station_name,
    s_structure.structure_name,
    s_ent.name,
    CONCAT('loc:', cc.start_location_id)
  ) AS start_name,
  COALESCE(
    e_sys.system_name,
    e_station.station_name,
    e_structure.structure_name,
    e_ent.name,
    CONCAT('loc:', cc.end_location_id)
  ) AS end_name
FROM esi_corp_contract cc
JOIN corp c ON c.corp_id = cc.corp_id
LEFT JOIN eve_entity s_ent
  ON s_ent.entity_id = cc.start_location_id
  AND s_ent.entity_type IN ('station','structure','system')
LEFT JOIN eve_system s_sys
  ON s_sys.system_id = cc.start_location_id
LEFT JOIN eve_station s_station
  ON s_station.station_id = cc.start_location_id
LEFT JOIN eve_structure s_structure
  ON s_structure.structure_id = cc.start_location_id
LEFT JOIN eve_entity e_ent
  ON e_ent.entity_id = cc.end_location_id
  AND e_ent.entity_type IN ('station','structure','system')
LEFT JOIN eve_system e_sys
  ON e_sys.system_id = cc.end_location_id
LEFT JOIN eve_station e_station
  ON e_station.station_id = cc.end_location_id
LEFT JOIN eve_structure e_structure
  ON e_structure.structure_id = cc.end_location_id;
