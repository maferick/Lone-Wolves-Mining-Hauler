ALTER TABLE app_user
  MODIFY status ENUM('active','disabled','invited','suspended') NOT NULL DEFAULT 'active',
  ADD COLUMN session_revoked_at DATETIME NULL AFTER last_login_at;
