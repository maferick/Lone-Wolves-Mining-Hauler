ALTER TABLE discord_config
  ADD COLUMN auto_link_username TINYINT(1) NOT NULL DEFAULT 0
  AFTER onboarding_dm_bypass_json;
