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
  COALESCE(s_ent.name, CONCAT('loc:', cc.start_location_id)) AS start_name,
  COALESCE(e_ent.name, CONCAT('loc:', cc.end_location_id)) AS end_name
FROM esi_corp_contract cc
JOIN corp c ON c.corp_id = cc.corp_id
LEFT JOIN eve_entity s_ent
  ON s_ent.entity_id = cc.start_location_id
  AND s_ent.entity_type IN ('station','structure','system')
LEFT JOIN eve_entity e_ent
  ON e_ent.entity_id = cc.end_location_id
  AND e_ent.entity_type IN ('station','structure','system');

