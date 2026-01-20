-- 036_add_discord_rights_source_per_role.sql
ALTER TABLE discord_config
  ADD COLUMN IF NOT EXISTS rights_source_member ENUM('portal','discord') NOT NULL DEFAULT 'portal' AFTER rights_source,
  ADD COLUMN IF NOT EXISTS rights_source_hauler ENUM('portal','discord') NOT NULL DEFAULT 'portal' AFTER rights_source_member;

UPDATE discord_config
  SET rights_source_member = rights_source,
      rights_source_hauler = rights_source
  WHERE rights_source_member IS NULL
     OR rights_source_hauler IS NULL;
