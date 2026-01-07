-- 035_add_hauler_profile.sql
CREATE TABLE IF NOT EXISTS user_hauler_profile (
  user_id                BIGINT UNSIGNED NOT NULL,
  can_fly_freighter      TINYINT(1) NOT NULL DEFAULT 0,
  can_fly_jump_freighter TINYINT(1) NOT NULL DEFAULT 0,
  can_fly_dst            TINYINT(1) NOT NULL DEFAULT 0,
  can_fly_br             TINYINT(1) NOT NULL DEFAULT 0,
  preferred_service_class VARCHAR(32) NULL,
  max_cargo_m3_override  DECIMAL(18,3) NULL,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_hauler_profile_user FOREIGN KEY (user_id) REFERENCES app_user(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
