-- 900_drop_all.sql
-- Drop all objects (use with care)
SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW IF EXISTS v_contract_display;
DROP VIEW IF EXISTS v_haul_request_display;

DROP TABLE IF EXISTS app_setting;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS job_queue;

DROP TABLE IF EXISTS webhook_delivery;
DROP TABLE IF EXISTS discord_webhook;
DROP TABLE IF EXISTS discord_outbox;
DROP TABLE IF EXISTS discord_member_snapshot;
DROP TABLE IF EXISTS discord_channel_map;
DROP TABLE IF EXISTS discord_template;
DROP TABLE IF EXISTS discord_config;
DROP TABLE IF EXISTS discord_link_code;
DROP TABLE IF EXISTS discord_user_link;

DROP TABLE IF EXISTS esi_corp_contract_item;
DROP TABLE IF EXISTS esi_corp_contract;

DROP TABLE IF EXISTS ops_event;
DROP TABLE IF EXISTS haul_event;
DROP TABLE IF EXISTS haul_assignment;
DROP TABLE IF EXISTS haul_offer;
DROP TABLE IF EXISTS haul_request_item;
DROP TABLE IF EXISTS haul_request;

DROP TABLE IF EXISTS route_cache;
DROP TABLE IF EXISTS lane;
DROP TABLE IF EXISTS pricing_rule;
DROP TABLE IF EXISTS haul_service;

DROP TABLE IF EXISTS eve_structure;
DROP TABLE IF EXISTS eve_station;
DROP TABLE IF EXISTS eve_system;
DROP TABLE IF EXISTS eve_constellation;
DROP TABLE IF EXISTS eve_region;

DROP TABLE IF EXISTS eve_entity_alias;
DROP TABLE IF EXISTS eve_entity;
DROP TABLE IF EXISTS esi_cache;

DROP TABLE IF EXISTS sso_token;

DROP TABLE IF EXISTS user_role;
DROP TABLE IF EXISTS role_permission;
DROP TABLE IF EXISTS permission;
DROP TABLE IF EXISTS role;
DROP TABLE IF EXISTS app_user;
DROP TABLE IF EXISTS corp;

SET FOREIGN_KEY_CHECKS = 1;
