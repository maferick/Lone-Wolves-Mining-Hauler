-- 034_add_discord_rights_source.sql
ALTER TABLE discord_config
  ADD COLUMN IF NOT EXISTS rights_source ENUM('portal','discord') NOT NULL DEFAULT 'portal' AFTER role_map_json;
