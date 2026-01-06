ALTER TABLE discord_config
  ADD COLUMN IF NOT EXISTS bot_permissions_test_json JSON NULL AFTER last_bot_action_at,
  ADD COLUMN IF NOT EXISTS bot_permissions_test_at DATETIME NULL AFTER bot_permissions_test_json;
