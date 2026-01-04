-- 001_schema.sql
-- In-house hauling platform database (MariaDB 10.6+ recommended)
-- Charset/Collation: utf8mb4 / utf8mb4_unicode_ci
-- Engine: InnoDB

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- =========================
-- Core / Tenancy
-- =========================

CREATE TABLE IF NOT EXISTS corp (
  corp_id            BIGINT UNSIGNED NOT NULL COMMENT 'EVE corporation_id',
  corp_name          VARCHAR(255) NOT NULL,
  alliance_id        BIGINT UNSIGNED NULL,
  alliance_name      VARCHAR(255) NULL,
  ticker             VARCHAR(16) NULL,
  home_system_id     BIGINT UNSIGNED NULL,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  settings_json      JSON NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (corp_id),
  KEY idx_corp_active (is_active),
  KEY idx_corp_home_system (home_system_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_user (
  user_id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NOT NULL,
  character_id       BIGINT UNSIGNED NULL COMMENT 'EVE character_id if linked via SSO',
  character_name     VARCHAR(255) NULL,
  email              VARCHAR(320) NULL,
  display_name       VARCHAR(255) NOT NULL,
  avatar_url         VARCHAR(1024) NULL,
  timezone           VARCHAR(64) NOT NULL DEFAULT 'Europe/Amsterdam',
  locale             VARCHAR(16) NOT NULL DEFAULT 'en',
  status             ENUM('active','disabled','invited') NOT NULL DEFAULT 'active',
  last_login_at      DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_user_email (email),
  UNIQUE KEY uq_user_character (corp_id, character_id),
  KEY idx_user_corp (corp_id),
  CONSTRAINT fk_user_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role (
  role_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NOT NULL,
  role_key           VARCHAR(64) NOT NULL COMMENT 'machine key, e.g. admin, hauler, requester',
  role_name          VARCHAR(128) NOT NULL,
  description        VARCHAR(512) NULL,
  is_system          TINYINT(1) NOT NULL DEFAULT 0,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id),
  UNIQUE KEY uq_role_key (corp_id, role_key),
  KEY idx_role_corp (corp_id),
  CONSTRAINT fk_role_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permission (
  perm_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  perm_key           VARCHAR(96) NOT NULL COMMENT 'e.g. haul.request.create',
  perm_name          VARCHAR(160) NOT NULL,
  description        VARCHAR(512) NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (perm_id),
  UNIQUE KEY uq_perm_key (perm_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permission (
  role_id            INT UNSIGNED NOT NULL,
  perm_id            INT UNSIGNED NOT NULL,
  allow              TINYINT(1) NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, perm_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES role(role_id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (perm_id) REFERENCES permission(perm_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_role (
  user_id            BIGINT UNSIGNED NOT NULL,
  role_id            INT UNSIGNED NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES app_user(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES role(role_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- Auth (EVE SSO tokens)
-- =========================

CREATE TABLE IF NOT EXISTS sso_token (
  token_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NOT NULL,
  owner_type         ENUM('character','corporation') NOT NULL DEFAULT 'character',
  owner_id           BIGINT UNSIGNED NOT NULL COMMENT 'character_id or corp_id depending on owner_type',
  owner_name         VARCHAR(255) NULL,
  access_token       TEXT NOT NULL,
  refresh_token      TEXT NOT NULL,
  expires_at         DATETIME NOT NULL,
  scopes             TEXT NOT NULL,
  token_status       ENUM('ok','revoked','error') NOT NULL DEFAULT 'ok',
  last_error         VARCHAR(1024) NULL,
  last_refreshed_at  DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (token_id),
  UNIQUE KEY uq_token_owner (corp_id, owner_type, owner_id),
  KEY idx_token_expires (expires_at),
  KEY idx_token_corp (corp_id),
  CONSTRAINT fk_token_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- ESI Cache & Name Resolution Cache
-- =========================

CREATE TABLE IF NOT EXISTS esi_cache (
  cache_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NULL COMMENT 'NULL = shared cache',
  cache_key          VARBINARY(64) NOT NULL COMMENT 'SHA-256 of method+url+query+body',
  http_method        VARCHAR(8) NOT NULL,
  url                VARCHAR(1024) NOT NULL,
  query_json         JSON NULL,
  body_json          JSON NULL,
  status_code        SMALLINT UNSIGNED NOT NULL,
  etag               VARCHAR(255) NULL,
  last_modified      VARCHAR(255) NULL,
  expires_at         DATETIME NOT NULL,
  fetched_at         DATETIME NOT NULL,
  ttl_seconds        INT UNSIGNED NOT NULL,
  response_json      LONGTEXT NOT NULL COMMENT 'JSON string to avoid MariaDB JSON limitations on huge payloads',
  response_sha256    VARBINARY(32) NOT NULL,
  error_text         VARCHAR(2048) NULL,
  PRIMARY KEY (cache_id),
  UNIQUE KEY uq_cache_key (corp_id, cache_key),
  KEY idx_cache_expires (expires_at),
  KEY idx_cache_url (url(191)),
  CONSTRAINT fk_cache_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eve_entity (
  entity_id          BIGINT UNSIGNED NOT NULL,
  entity_type        ENUM('character','corporation','alliance','system','station','structure','region','constellation','type','market_group','faction','unknown') NOT NULL DEFAULT 'unknown',
  name               VARCHAR(255) NOT NULL,
  category           VARCHAR(64) NULL,
  extra_json         JSON NULL,
  source             ENUM('esi','manual','sde') NOT NULL DEFAULT 'esi',
  last_seen_at       DATETIME NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (entity_id, entity_type),
  KEY idx_entity_name (name),
  KEY idx_entity_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional “display name” materialization for UI
CREATE TABLE IF NOT EXISTS eve_entity_alias (
  alias_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_id          BIGINT UNSIGNED NOT NULL,
  entity_type        ENUM('character','corporation','alliance','system','station','structure','region','constellation','type','market_group','faction','unknown') NOT NULL DEFAULT 'unknown',
  alias              VARCHAR(255) NOT NULL,
  reason             VARCHAR(255) NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (alias_id),
  UNIQUE KEY uq_alias (entity_type, alias),
  KEY idx_alias_entity (entity_id, entity_type),
  CONSTRAINT fk_alias_user FOREIGN KEY (created_by_user_id) REFERENCES app_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- Static “map” normalization (optional – can be hydrated from ESI)
-- =========================

CREATE TABLE IF NOT EXISTS eve_region (
  region_id          BIGINT UNSIGNED NOT NULL,
  region_name        VARCHAR(255) NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (region_id),
  KEY idx_region_name (region_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eve_constellation (
  constellation_id   BIGINT UNSIGNED NOT NULL,
  region_id          BIGINT UNSIGNED NOT NULL,
  constellation_name VARCHAR(255) NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (constellation_id),
  KEY idx_const_region (region_id),
  KEY idx_const_name (constellation_name),
  CONSTRAINT fk_const_region FOREIGN KEY (region_id) REFERENCES eve_region(region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eve_system (
  system_id          BIGINT UNSIGNED NOT NULL,
  constellation_id   BIGINT UNSIGNED NOT NULL,
  system_name        VARCHAR(255) NOT NULL,
  security_status    DECIMAL(4,3) NOT NULL DEFAULT 0.000,
  has_security       TINYINT(1) NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (system_id),
  KEY idx_sys_const (constellation_id),
  KEY idx_sys_name (system_name),
  KEY idx_sys_sec (security_status),
  CONSTRAINT fk_sys_const FOREIGN KEY (constellation_id) REFERENCES eve_constellation(constellation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eve_station (
  station_id         BIGINT UNSIGNED NOT NULL,
  system_id          BIGINT UNSIGNED NOT NULL,
  station_name       VARCHAR(255) NOT NULL,
  station_type_id    BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (station_id),
  KEY idx_station_system (system_id),
  KEY idx_station_name (station_name),
  CONSTRAINT fk_station_system FOREIGN KEY (system_id) REFERENCES eve_system(system_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eve_structure (
  structure_id       BIGINT UNSIGNED NOT NULL,
  system_id          BIGINT UNSIGNED NOT NULL,
  structure_name     VARCHAR(255) NOT NULL,
  owner_corp_id      BIGINT UNSIGNED NULL,
  type_id            BIGINT UNSIGNED NULL,
  is_public          TINYINT(1) NOT NULL DEFAULT 0,
  last_fetched_at    DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (structure_id),
  KEY idx_struct_system (system_id),
  KEY idx_struct_owner (owner_corp_id),
  CONSTRAINT fk_struct_system FOREIGN KEY (system_id) REFERENCES eve_system(system_id),
  CONSTRAINT fk_struct_owner FOREIGN KEY (owner_corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- Hauling Products, Pricing & Rules
-- =========================

CREATE TABLE IF NOT EXISTS haul_service (
  service_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NOT NULL,
  service_key        VARCHAR(64) NOT NULL COMMENT 'e.g. secure, lowsec, nullsec, jf, bulk',
  service_name       VARCHAR(128) NOT NULL,
  description        VARCHAR(512) NULL,
  is_enabled         TINYINT(1) NOT NULL DEFAULT 1,
  default_rules_json JSON NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (service_id),
  UNIQUE KEY uq_service_key (corp_id, service_key),
  KEY idx_service_corp (corp_id),
  CONSTRAINT fk_service_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pricing_rule (
  rule_id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NOT NULL,
  service_id         INT UNSIGNED NOT NULL,
  rule_name          VARCHAR(128) NOT NULL,
  priority           INT NOT NULL DEFAULT 100 COMMENT 'lower wins',
  rule_type          ENUM(
                        'base',
                        'per_jump',
                        'per_m3',
                        'per_collateral',
                        'min_fee',
                        'max_fee',
                        'security_multiplier',
                        'system_blacklist',
                        'system_whitelist',
                        'region_blacklist',
                        'region_whitelist',
                        'mass_limit',
                        'volume_limit',
                        'collateral_limit',
                        'rush_fee',
                        'corp_discount',
                        'character_discount'
                      ) NOT NULL,
  params_json        JSON NOT NULL,
  is_enabled         TINYINT(1) NOT NULL DEFAULT 1,
  valid_from         DATETIME NULL,
  valid_to           DATETIME NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rule_id),
  KEY idx_rule (corp_id, service_id, is_enabled, priority),
  KEY idx_rule_valid (valid_from, valid_to),
  CONSTRAINT fk_rule_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id),
  CONSTRAINT fk_rule_service FOREIGN KEY (service_id) REFERENCES haul_service(service_id),
  CONSTRAINT fk_rule_user FOREIGN KEY (created_by_user_id) REFERENCES app_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lane (
  lane_id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NOT NULL,
  service_id         INT UNSIGNED NOT NULL,
  lane_name          VARCHAR(128) NOT NULL,
  from_location_id   BIGINT UNSIGNED NOT NULL,
  from_location_type ENUM('system','station','structure') NOT NULL DEFAULT 'system',
  to_location_id     BIGINT UNSIGNED NOT NULL,
  to_location_type   ENUM('system','station','structure') NOT NULL DEFAULT 'system',
  route_policy       ENUM('shortest','safest','avoid_low','avoid_null','custom') NOT NULL DEFAULT 'safest',
  waypoints_json     JSON NULL,
  is_enabled         TINYINT(1) NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (lane_id),
  UNIQUE KEY uq_lane (corp_id, service_id, lane_name),
  KEY idx_lane_corp (corp_id),
  KEY idx_lane_from (from_location_id),
  KEY idx_lane_to (to_location_id),
  CONSTRAINT fk_lane_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id),
  CONSTRAINT fk_lane_service FOREIGN KEY (service_id) REFERENCES haul_service(service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS route_cache (
  route_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NULL,
  route_key          VARBINARY(64) NOT NULL COMMENT 'SHA-256 of route inputs',
  from_system_id     BIGINT UNSIGNED NOT NULL,
  to_system_id       BIGINT UNSIGNED NOT NULL,
  route_policy       ENUM('shortest','safest','avoid_low','avoid_null','custom') NOT NULL,
  avoid_json         JSON NULL,
  waypoints_json     JSON NULL,
  jumps              INT UNSIGNED NOT NULL,
  route_system_ids   LONGTEXT NOT NULL COMMENT 'JSON array of system_ids',
  computed_at        DATETIME NOT NULL,
  expires_at         DATETIME NOT NULL,
  source             ENUM('esi','manual','gatecheck','sde') NOT NULL DEFAULT 'esi',
  PRIMARY KEY (route_id),
  UNIQUE KEY uq_route (corp_id, route_key),
  KEY idx_route_expires (expires_at),
  KEY idx_route_pair (from_system_id, to_system_id),
  CONSTRAINT fk_route_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- Haul Requests (the “quote + workflow” object)
-- =========================

CREATE TABLE IF NOT EXISTS haul_request (
  request_id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_key        VARCHAR(64) NOT NULL DEFAULT '',
  corp_id            BIGINT UNSIGNED NOT NULL,
  service_id         INT UNSIGNED NOT NULL,
  requester_user_id  BIGINT UNSIGNED NOT NULL,
  requester_character_id BIGINT UNSIGNED NULL,
  requester_character_name VARCHAR(255) NULL,
  title              VARCHAR(255) NULL,
  notes              TEXT NULL,

  -- Origin / Destination (system/station/structure)
  from_location_id   BIGINT UNSIGNED NOT NULL,
  from_location_type ENUM('system','station','structure') NOT NULL,
  to_location_id     BIGINT UNSIGNED NOT NULL,
  to_location_type   ENUM('system','station','structure') NOT NULL,

  -- Operational constraints
  volume_m3          DECIMAL(18,3) NOT NULL DEFAULT 0.000,
  collateral_isk     DECIMAL(20,2) NOT NULL DEFAULT 0.00,
  reward_isk         DECIMAL(20,2) NOT NULL DEFAULT 0.00,
  quote_id           BIGINT UNSIGNED NULL,
  ship_class         VARCHAR(32) NULL,
  rush               TINYINT(1) NOT NULL DEFAULT 0,
  expected_jumps     INT UNSIGNED NULL,
  route_policy       ENUM('shortest','balanced','safest','avoid_low','avoid_null','custom') NOT NULL DEFAULT 'safest',
  route_profile      VARCHAR(32) NULL,
  route_system_ids   LONGTEXT NULL COMMENT 'Cached JSON array of system_ids (optional, can be derived)',
  price_breakdown_json JSON NULL,
  contract_hint_text VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Substring required in contract description',

  -- Linkage to in-game contract (optional)
  contract_id        BIGINT UNSIGNED NULL COMMENT 'ESI contract_id',
  esi_contract_id    BIGINT UNSIGNED NULL COMMENT 'ESI contract_id (source of truth)',
  contract_type      ENUM('courier','item_exchange','auction','unknown') NULL,
  contract_status    VARCHAR(64) NULL,
  contract_status_esi VARCHAR(64) NULL,
  esi_status         VARCHAR(64) NULL,
  acceptor_id        BIGINT UNSIGNED NULL,
  acceptor_name      VARCHAR(255) NULL,
  esi_acceptor_id    BIGINT UNSIGNED NULL,
  esi_acceptor_name  VARCHAR(255) NULL,
  contract_lifecycle ENUM('AWAITING_CONTRACT','AVAILABLE','PICKED_UP','DELIVERED','FAILED','EXPIRED') NULL,
  contract_state     ENUM('AVAILABLE','PICKED_UP','DELIVERED','FAILED','EXPIRED') NULL,
  contract_acceptor_id BIGINT UNSIGNED NULL,
  contract_acceptor_name VARCHAR(255) NULL,
  date_accepted      DATETIME NULL,
  date_completed     DATETIME NULL,
  date_expired       DATETIME NULL,
  last_contract_hash CHAR(64) NULL,
  contract_hash      CHAR(64) NULL,
  last_reconciled_at DATETIME NULL,
  contract_matched_at DATETIME NULL,
  contract_linked_notified_at DATETIME NULL,
  contract_validation_json JSON NULL,
  mismatch_reason_json JSON NULL,

  -- Ops tracking fields (website truth)
  ops_assignee_id    BIGINT UNSIGNED NULL,
  ops_assignee_name  VARCHAR(255) NULL,
  ops_status         VARCHAR(64) NULL,

  status             ENUM('requested','awaiting_contract','contract_linked','contract_mismatch','in_queue','in_progress','completed','cancelled','draft','quoted','submitted','posted','accepted','in_transit','delivered','expired','rejected') NOT NULL DEFAULT 'requested',
  discord_webhook_id BIGINT UNSIGNED NULL,
  discord_message_id VARCHAR(128) NULL,
  posted_at          DATETIME NULL,
  accepted_at        DATETIME NULL,
  delivered_at       DATETIME NULL,
  cancelled_at       DATETIME NULL,

  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (request_id),
  UNIQUE KEY uq_request_key (request_key),
  KEY idx_request_corp (corp_id, status, created_at),
  KEY idx_request_service (service_id),
  KEY idx_request_requester (requester_user_id),
  KEY idx_request_contract (contract_id),
  KEY idx_request_quote (quote_id),
  KEY idx_request_from (from_location_id),
  KEY idx_request_to (to_location_id),
  CONSTRAINT fk_request_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id),
  CONSTRAINT fk_request_service FOREIGN KEY (service_id) REFERENCES haul_service(service_id),
  CONSTRAINT fk_request_requester FOREIGN KEY (requester_user_id) REFERENCES app_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE haul_request
  ADD COLUMN IF NOT EXISTS request_key VARCHAR(64) NOT NULL DEFAULT '' AFTER request_id,
  ADD COLUMN IF NOT EXISTS route_profile VARCHAR(32) NULL AFTER route_policy,
  ADD COLUMN IF NOT EXISTS contract_hint_text VARCHAR(255) NOT NULL DEFAULT '' AFTER price_breakdown_json,
  ADD COLUMN IF NOT EXISTS esi_contract_id BIGINT UNSIGNED NULL AFTER contract_id,
  ADD COLUMN IF NOT EXISTS contract_status_esi VARCHAR(64) NULL AFTER contract_status,
  ADD COLUMN IF NOT EXISTS esi_status VARCHAR(64) NULL AFTER contract_status_esi,
  ADD COLUMN IF NOT EXISTS acceptor_id BIGINT UNSIGNED NULL AFTER contract_status_esi,
  ADD COLUMN IF NOT EXISTS acceptor_name VARCHAR(255) NULL AFTER acceptor_id,
  ADD COLUMN IF NOT EXISTS esi_acceptor_id BIGINT UNSIGNED NULL AFTER acceptor_name,
  ADD COLUMN IF NOT EXISTS esi_acceptor_name VARCHAR(255) NULL AFTER esi_acceptor_id,
  ADD COLUMN IF NOT EXISTS contract_lifecycle ENUM('AWAITING_CONTRACT','AVAILABLE','PICKED_UP','DELIVERED','FAILED','EXPIRED') NULL AFTER acceptor_name,
  ADD COLUMN IF NOT EXISTS contract_state ENUM('AVAILABLE','PICKED_UP','DELIVERED','FAILED','EXPIRED') NULL AFTER contract_lifecycle,
  ADD COLUMN IF NOT EXISTS contract_matched_at DATETIME NULL AFTER contract_status,
  ADD COLUMN IF NOT EXISTS contract_acceptor_id BIGINT UNSIGNED NULL AFTER contract_status,
  ADD COLUMN IF NOT EXISTS contract_acceptor_name VARCHAR(255) NULL AFTER contract_acceptor_id,
  ADD COLUMN IF NOT EXISTS date_accepted DATETIME NULL AFTER contract_acceptor_name,
  ADD COLUMN IF NOT EXISTS date_completed DATETIME NULL AFTER date_accepted,
  ADD COLUMN IF NOT EXISTS date_expired DATETIME NULL AFTER date_completed,
  ADD COLUMN IF NOT EXISTS last_contract_hash CHAR(64) NULL AFTER date_expired,
  ADD COLUMN IF NOT EXISTS contract_hash CHAR(64) NULL AFTER last_contract_hash,
  ADD COLUMN IF NOT EXISTS last_reconciled_at DATETIME NULL AFTER last_contract_hash,
  ADD COLUMN IF NOT EXISTS contract_linked_notified_at DATETIME NULL AFTER contract_matched_at,
  ADD COLUMN IF NOT EXISTS contract_validation_json JSON NULL AFTER contract_matched_at,
  ADD COLUMN IF NOT EXISTS mismatch_reason_json JSON NULL AFTER contract_validation_json,
  ADD COLUMN IF NOT EXISTS ops_assignee_id BIGINT UNSIGNED NULL AFTER mismatch_reason_json,
  ADD COLUMN IF NOT EXISTS ops_assignee_name VARCHAR(255) NULL AFTER ops_assignee_id,
  ADD COLUMN IF NOT EXISTS ops_status VARCHAR(64) NULL AFTER ops_assignee_name;

CREATE TABLE IF NOT EXISTS haul_request_item (
  request_item_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id         BIGINT UNSIGNED NOT NULL,
  type_id            BIGINT UNSIGNED NOT NULL COMMENT 'EVE type_id',
  type_name          VARCHAR(255) NULL,
  quantity           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  volume_m3_each     DECIMAL(18,6) NULL,
  total_volume_m3    DECIMAL(18,3) NULL,
  is_packaged        TINYINT(1) NOT NULL DEFAULT 1,
  meta_json          JSON NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (request_item_id),
  KEY idx_req_item (request_id),
  KEY idx_req_item_type (type_id),
  CONSTRAINT fk_req_item_req FOREIGN KEY (request_id) REFERENCES haul_request(request_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Many haulers can “bid”/offer internally, even if the in-game contract reward is fixed
CREATE TABLE IF NOT EXISTS haul_offer (
  offer_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id         BIGINT UNSIGNED NOT NULL,
  hauler_user_id     BIGINT UNSIGNED NOT NULL,
  offer_reward_isk   DECIMAL(20,2) NOT NULL,
  offer_notes        VARCHAR(1024) NULL,
  status             ENUM('open','accepted','rejected','withdrawn') NOT NULL DEFAULT 'open',
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (offer_id),
  KEY idx_offer_req (request_id, status),
  KEY idx_offer_hauler (hauler_user_id),
  CONSTRAINT fk_offer_req FOREIGN KEY (request_id) REFERENCES haul_request(request_id) ON DELETE CASCADE,
  CONSTRAINT fk_offer_hauler FOREIGN KEY (hauler_user_id) REFERENCES app_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assignment = which internal hauler owns it
CREATE TABLE IF NOT EXISTS haul_assignment (
  assignment_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id         BIGINT UNSIGNED NOT NULL,
  hauler_user_id     BIGINT UNSIGNED NOT NULL,
  assigned_by_user_id BIGINT UNSIGNED NULL,
  status             ENUM('assigned','in_transit','delivered','cancelled') NOT NULL DEFAULT 'assigned',
  started_at         DATETIME NULL,
  completed_at       DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (assignment_id),
  UNIQUE KEY uq_assignment_req (request_id),
  KEY idx_assignment_hauler (hauler_user_id, status),
  CONSTRAINT fk_assign_req FOREIGN KEY (request_id) REFERENCES haul_request(request_id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_hauler FOREIGN KEY (hauler_user_id) REFERENCES app_user(user_id),
  CONSTRAINT fk_assign_by FOREIGN KEY (assigned_by_user_id) REFERENCES app_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Status history / audit trail
CREATE TABLE IF NOT EXISTS haul_event (
  event_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id         BIGINT UNSIGNED NOT NULL,
  event_type         ENUM(
                        'created','quoted','submitted','posted',
                        'offer_created','offer_accepted','offer_rejected',
                        'assigned','pickup','in_transit','delivered',
                        'cancelled','expired','rejected',
                        'discord_sent','discord_failed',
                        'esi_import','esi_error'
                      ) NOT NULL,
  message            VARCHAR(2048) NULL,
  payload_json       JSON NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id),
  KEY idx_event_req (request_id, created_at),
  KEY idx_event_type (event_type),
  CONSTRAINT fk_event_req FOREIGN KEY (request_id) REFERENCES haul_request(request_id) ON DELETE CASCADE,
  CONSTRAINT fk_event_user FOREIGN KEY (created_by_user_id) REFERENCES app_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ops_event (
  ops_event_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NOT NULL,
  request_id         BIGINT UNSIGNED NOT NULL,
  contract_id        BIGINT UNSIGNED NOT NULL,
  old_state          ENUM('AVAILABLE','PICKED_UP','DELIVERED','FAILED','EXPIRED') NULL,
  new_state          ENUM('AVAILABLE','PICKED_UP','DELIVERED','FAILED','EXPIRED') NOT NULL,
  acceptor_name      VARCHAR(255) NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ops_event_id),
  KEY idx_ops_event_request (request_id, created_at),
  KEY idx_ops_event_contract (corp_id, contract_id, created_at),
  CONSTRAINT fk_ops_event_request FOREIGN KEY (request_id) REFERENCES haul_request(request_id) ON DELETE CASCADE,
  CONSTRAINT fk_ops_event_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- ESI: Corporation Contracts Mirror
-- =========================

CREATE TABLE IF NOT EXISTS esi_corp_contract (
  corp_id            BIGINT UNSIGNED NOT NULL,
  contract_id        BIGINT UNSIGNED NOT NULL,
  issuer_id          BIGINT UNSIGNED NULL,
  issuer_corp_id     BIGINT UNSIGNED NULL,
  assignee_id        BIGINT UNSIGNED NULL,
  acceptor_id        BIGINT UNSIGNED NULL,
  start_location_id  BIGINT UNSIGNED NULL,
  end_location_id    BIGINT UNSIGNED NULL,
  title              VARCHAR(255) NULL,
  type               ENUM('courier','item_exchange','auction','unknown') NOT NULL DEFAULT 'unknown',
  status             VARCHAR(64) NOT NULL,
  availability       VARCHAR(64) NULL,
  date_issued        DATETIME NULL,
  date_expired       DATETIME NULL,
  date_accepted      DATETIME NULL,
  date_completed     DATETIME NULL,
  days_to_complete   INT NULL,

  collateral_isk     DECIMAL(20,2) NULL,
  reward_isk         DECIMAL(20,2) NULL,
  buyout_isk         DECIMAL(20,2) NULL,
  price_isk          DECIMAL(20,2) NULL,
  volume_m3          DECIMAL(18,3) NULL,

  raw_json           LONGTEXT NOT NULL,
  last_fetched_at    DATETIME NOT NULL,

  PRIMARY KEY (corp_id, contract_id),
  KEY idx_contract_status (corp_id, status),
  KEY idx_contract_dates (date_issued, date_expired),
  KEY idx_contract_route (start_location_id, end_location_id),
  CONSTRAINT fk_contract_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS esi_corp_contract_item (
  corp_id            BIGINT UNSIGNED NOT NULL,
  contract_id        BIGINT UNSIGNED NOT NULL,
  item_id            BIGINT UNSIGNED NOT NULL COMMENT 'unique per item row in ESI response (can be synthetic)',
  type_id            BIGINT UNSIGNED NOT NULL,
  quantity           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  is_included        TINYINT(1) NOT NULL DEFAULT 1,
  is_singleton       TINYINT(1) NOT NULL DEFAULT 0,
  raw_json           LONGTEXT NOT NULL,
  PRIMARY KEY (corp_id, contract_id, item_id),
  KEY idx_contract_item_type (type_id),
  CONSTRAINT fk_citem_contract FOREIGN KEY (corp_id, contract_id) REFERENCES esi_corp_contract(corp_id, contract_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- Discord/Webhooks
-- =========================

CREATE TABLE IF NOT EXISTS discord_webhook (
  webhook_id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NOT NULL,
  webhook_name       VARCHAR(128) NOT NULL,
  webhook_url        VARCHAR(1024) NOT NULL,
  channel_hint       VARCHAR(255) NULL,
  is_enabled         TINYINT(1) NOT NULL DEFAULT 1,
  notify_on_contract_link TINYINT(1) NOT NULL DEFAULT 0,
  secrets_json       JSON NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (webhook_id),
  KEY idx_webhook_corp (corp_id, is_enabled),
  CONSTRAINT fk_webhook_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_webhook_event (
  webhook_id         BIGINT UNSIGNED NOT NULL,
  event_key          VARCHAR(64) NOT NULL,
  is_enabled         TINYINT(1) NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (webhook_id, event_key),
  KEY idx_webhook_event_key (event_key, is_enabled),
  CONSTRAINT fk_webhook_event_webhook FOREIGN KEY (webhook_id) REFERENCES discord_webhook(webhook_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_delivery (
  delivery_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NOT NULL,
  webhook_id         BIGINT UNSIGNED NOT NULL,
  event_key          VARCHAR(64) NOT NULL,
  entity_type        VARCHAR(64) NULL,
  entity_id          BIGINT UNSIGNED NULL,
  transition_to      VARCHAR(64) NULL,
  payload_json       JSON NOT NULL,
  status             ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts           INT NOT NULL DEFAULT 0,
  next_attempt_at    DATETIME NULL,
  last_http_status   INT NULL,
  last_error         TEXT NULL,
  sent_at            DATETIME NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (delivery_id),
  KEY idx_delivery_corp_status (corp_id, status, next_attempt_at),
  KEY idx_delivery_webhook (webhook_id, status),
  KEY idx_delivery_event (event_key, created_at),
  UNIQUE KEY uq_delivery_transition (webhook_id, event_key, entity_type, entity_id, transition_to),
  CONSTRAINT fk_delivery_webhook FOREIGN KEY (webhook_id) REFERENCES discord_webhook(webhook_id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- Background Jobs / Scheduling
-- =========================

CREATE TABLE IF NOT EXISTS job_queue (
  job_id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NULL,
  job_type           VARCHAR(96) NOT NULL COMMENT 'e.g. esi.contracts.pull, webhook.send',
  priority           INT NOT NULL DEFAULT 100,
  status             ENUM('queued','running','succeeded','failed','dead') NOT NULL DEFAULT 'queued',
  run_at             DATETIME NOT NULL,
  started_at         DATETIME NULL,
  finished_at        DATETIME NULL,
  locked_by          VARCHAR(128) NULL,
  locked_at          DATETIME NULL,
  attempt            INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts       INT UNSIGNED NOT NULL DEFAULT 5,
  last_error         VARCHAR(2048) NULL,
  payload_json       JSON NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (job_id),
  KEY idx_job_status (status, run_at, priority),
  KEY idx_job_type (job_type),
  CONSTRAINT fk_job_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_task_setting (
  task_setting_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NULL,
  task_key           VARCHAR(96) NOT NULL,
  is_enabled         TINYINT(1) NOT NULL DEFAULT 1,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (task_setting_id),
  UNIQUE KEY uq_cron_task (corp_id, task_key),
  KEY idx_cron_task (task_key, is_enabled),
  CONSTRAINT fk_cron_task_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- Operational Audit & Configuration
-- =========================

CREATE TABLE IF NOT EXISTS audit_log (
  audit_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NULL,
  actor_user_id      BIGINT UNSIGNED NULL,
  actor_character_id BIGINT UNSIGNED NULL,
  action             VARCHAR(128) NOT NULL,
  entity_table       VARCHAR(128) NULL,
  entity_pk          VARCHAR(255) NULL,
  before_json        JSON NULL,
  after_json         JSON NULL,
  ip_address         VARBINARY(16) NULL,
  user_agent         VARCHAR(512) NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (audit_id),
  KEY idx_audit_corp (corp_id, created_at),
  KEY idx_audit_actor (actor_user_id, created_at),
  CONSTRAINT fk_audit_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (actor_user_id) REFERENCES app_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_setting (
  setting_id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NOT NULL,
  setting_key        VARCHAR(128) NOT NULL,
  setting_value      LONGTEXT NULL,
  setting_json       JSON NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_id),
  UNIQUE KEY uq_setting (corp_id, setting_key),
  CONSTRAINT fk_setting_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id),
  CONSTRAINT fk_setting_user FOREIGN KEY (updated_by_user_id) REFERENCES app_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- Hauling Routing & Pricing (local stargate graph + quoting)
-- =========================

CREATE TABLE IF NOT EXISTS map_system (
  system_id          BIGINT UNSIGNED NOT NULL,
  system_name        VARCHAR(255) NOT NULL,
  security           DECIMAL(4,3) NOT NULL DEFAULT 0.000,
  region_id          BIGINT UNSIGNED NOT NULL,
  constellation_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (system_id),
  UNIQUE KEY uq_map_system_name (system_name),
  KEY idx_map_system_name (system_name),
  KEY idx_map_system_security (security),
  KEY idx_map_system_region (region_id),
  KEY idx_map_system_const (constellation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS map_edge (
  from_system_id     BIGINT UNSIGNED NOT NULL,
  to_system_id       BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (from_system_id, to_system_id),
  KEY idx_map_edge_to (to_system_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dnf_rule (
  dnf_rule_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope_type         ENUM('system','constellation','region','edge') NOT NULL,
  id_a               BIGINT UNSIGNED NOT NULL,
  id_b               BIGINT UNSIGNED NULL,
  severity           INT NOT NULL DEFAULT 1,
  is_hard_block      TINYINT(1) NOT NULL DEFAULT 0,
  reason             VARCHAR(255) NULL,
  active             TINYINT(1) NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (dnf_rule_id),
  KEY idx_dnf_scope (scope_type, id_a),
  KEY idx_dnf_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote (
  quote_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NOT NULL,
  from_system_id     BIGINT UNSIGNED NOT NULL,
  to_system_id       BIGINT UNSIGNED NOT NULL,
  profile            VARCHAR(32) NOT NULL,
  route_json         JSON NOT NULL,
  breakdown_json     JSON NOT NULL,
  volume_m3          DECIMAL(18,3) NOT NULL DEFAULT 0.000,
  collateral_isk     DECIMAL(20,2) NOT NULL DEFAULT 0.00,
  price_total        DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  created_at         DATETIME NOT NULL,
  PRIMARY KEY (quote_id),
  KEY idx_quote_corp (corp_id),
  KEY idx_quote_route (from_system_id, to_system_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_plan (
  rate_plan_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id            BIGINT UNSIGNED NOT NULL,
  service_class      VARCHAR(32) NOT NULL,
  rate_per_jump      DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  collateral_rate    DECIMAL(10,6) NOT NULL DEFAULT 0.000000,
  min_price          DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (rate_plan_id),
  UNIQUE KEY uniq_rate_plan (corp_id, service_class)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- Helpful “name first” views (UI should join these instead of showing raw IDs)
-- =========================

CREATE OR REPLACE VIEW v_haul_request_display AS
SELECT
  r.request_id,
  r.request_key,
  r.corp_id,
  c.corp_name,
  r.service_id,
  s.service_name,
  r.status,
  r.title,
  r.volume_m3,
  r.collateral_isk,
  r.reward_isk,
  r.expected_jumps,
  r.route_policy,
  r.route_profile,
  r.contract_id,
  r.esi_contract_id,
  r.contract_status,
  r.contract_status_esi,
  r.esi_status,
  r.contract_lifecycle,
  r.contract_state,
  r.acceptor_id,
  r.acceptor_name,
  r.esi_acceptor_id,
  r.esi_acceptor_name,
  r.contract_acceptor_id,
  r.contract_acceptor_name,
  r.date_accepted,
  r.date_completed,
  r.date_expired,
  r.last_contract_hash,
  r.contract_hash,
  r.last_reconciled_at,
  r.contract_matched_at,
  r.contract_linked_notified_at,
  r.mismatch_reason_json,
  r.ops_assignee_id,
  r.ops_assignee_name,
  r.ops_status,
  r.created_at,
  r.updated_at,
  r.from_location_id,
  r.from_location_type,
  r.to_location_id,
  r.to_location_type,
  COALESCE(f_ent.name, CONCAT(r.from_location_type, ':', r.from_location_id)) AS from_name,
  COALESCE(t_ent.name, CONCAT(r.to_location_type, ':', r.to_location_id)) AS to_name,
  COALESCE(r.esi_acceptor_name, r.contract_acceptor_name, a_ent.name, CONCAT('Character:', r.esi_acceptor_id)) AS esi_acceptor_display_name,
  u.display_name AS requester_display_name,
  COALESCE(u.character_name, r.requester_character_name) AS requester_character_name
FROM haul_request r
JOIN corp c ON c.corp_id = r.corp_id
JOIN haul_service s ON s.service_id = r.service_id
JOIN app_user u ON u.user_id = r.requester_user_id
LEFT JOIN eve_entity f_ent
  ON f_ent.entity_id = r.from_location_id
  AND f_ent.entity_type = CASE r.from_location_type
    WHEN 'system' THEN 'system'
    WHEN 'station' THEN 'station'
    WHEN 'structure' THEN 'structure'
    ELSE 'unknown' END
LEFT JOIN eve_entity t_ent
  ON t_ent.entity_id = r.to_location_id
  AND t_ent.entity_type = CASE r.to_location_type
    WHEN 'system' THEN 'system'
    WHEN 'station' THEN 'station'
    WHEN 'structure' THEN 'structure'
    ELSE 'unknown' END
LEFT JOIN eve_entity a_ent
  ON a_ent.entity_id = COALESCE(r.esi_acceptor_id, r.contract_acceptor_id)
  AND a_ent.entity_type = 'character';
