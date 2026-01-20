ALTER TABLE app_user
  ADD COLUMN is_in_scope TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

UPDATE app_user
  SET is_in_scope = 1;
