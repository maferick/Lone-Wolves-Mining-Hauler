-- 005_extend_source_enums.sql
-- Ensure SDE source values are accepted for cached entities/routes.

ALTER TABLE eve_entity
  MODIFY COLUMN source ENUM('esi','manual','sde') NOT NULL DEFAULT 'esi';

ALTER TABLE route_cache
  MODIFY COLUMN source ENUM('esi','manual','gatecheck','sde') NOT NULL DEFAULT 'esi';
