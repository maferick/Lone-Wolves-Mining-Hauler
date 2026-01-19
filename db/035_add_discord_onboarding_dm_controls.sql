ALTER TABLE discord_config
  ADD COLUMN IF NOT EXISTS onboarding_dm_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER rights_source,
  ADD COLUMN IF NOT EXISTS onboarding_dm_bypass_json JSON NULL AFTER onboarding_dm_enabled;
