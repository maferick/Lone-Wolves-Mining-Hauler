-- create_db_and_user.sql
-- Paste into phpMyAdmin (SQL tab) or run via mysql CLI.
-- CHANGE PASSWORDS BEFORE PROD.

CREATE DATABASE IF NOT EXISTS corp_hauling
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Create a dedicated app user
CREATE USER IF NOT EXISTS 'corp_hauling_app'@'%' IDENTIFIED BY 'ChangeMe_123!';

-- Least privilege baseline (tighten later if you split read/write users)
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, EXECUTE
  ON corp_hauling.* TO 'corp_hauling_app'@'%';

FLUSH PRIVILEGES;

-- Optional: verify
-- SHOW GRANTS FOR 'corp_hauling_app'@'%';
