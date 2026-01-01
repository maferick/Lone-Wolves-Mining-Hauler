-- 002_seed.sql
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- === Minimal seed to get you moving (safe to edit) ===
-- Replace corp_id/corp_name with your actual corp later.

INSERT INTO corp (corp_id, corp_name, ticker, is_active)
VALUES (98746727, 'Lone Wolves Mining', 'LWM', 1)
ON DUPLICATE KEY UPDATE corp_name=VALUES(corp_name), ticker=VALUES(ticker), is_active=VALUES(is_active);

-- Default services (tune later)
INSERT INTO haul_service (corp_id, service_key, service_name, description, is_enabled, default_rules_json)
VALUES
  (98746727, 'secure', 'High-sec Secure', 'Standard in-house hauling in high-sec (safest routes).', 1, JSON_OBJECT('route_policy','safest')),
  (98746727, 'lowsec', 'Low-sec', 'Optional low-sec hauling (risk-adjusted pricing).', 0, JSON_OBJECT('route_policy','avoid_null')),
  (98746727, 'nullsec', 'Null-sec', 'Null-sec hauling (typically JF or specialist).', 0, JSON_OBJECT('route_policy','custom'))
ON DUPLICATE KEY UPDATE service_name=VALUES(service_name), description=VALUES(description), is_enabled=VALUES(is_enabled);

-- Roles
INSERT INTO role (corp_id, role_key, role_name, description, is_system)
VALUES
  (98746727, 'admin', 'Admin', 'Full access to configuration and operations.', 1),
  (98746727, 'hauler', 'Hauler', 'Can accept/execute hauling assignments.', 1),
  (98746727, 'requester', 'Requester', 'Can create and track hauling requests.', 1),
  (98746727, 'dispatcher', 'Dispatcher', 'Can assign jobs and manage workflow.', 1)
ON DUPLICATE KEY UPDATE role_name=VALUES(role_name), description=VALUES(description);

-- Permissions (expand later)
INSERT INTO permission (perm_key, perm_name, description) VALUES
  ('haul.request.create', 'Create haul request', 'Create new haul requests and submit for posting.'),
  ('haul.request.read', 'View haul requests', 'View haul requests for the corporation.'),
  ('haul.request.manage', 'Manage haul requests', 'Edit/quote/cancel/post requests.'),
  ('haul.assign', 'Assign hauls', 'Assign requests to internal haulers.'),
  ('haul.execute', 'Execute hauls', 'Update status (pickup/in-transit/delivered).'),
  ('pricing.manage', 'Manage pricing', 'Create/update pricing rules and lanes.'),
  ('webhook.manage', 'Manage webhooks', 'Create/update Discord webhooks and templates.'),
  ('esi.manage', 'Manage ESI', 'Configure ESI tokens and scheduled pulls.'),
  ('user.manage', 'Manage users', 'Manage user access and role assignments.'),
  ('corp.manage', 'Manage corporation settings', 'Configure corp profile, defaults, and access rules.')
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), description=VALUES(description);

-- Role ↔ Permission mapping (starter)
-- admin gets all current perms
INSERT IGNORE INTO role_permission (role_id, perm_id, allow)
SELECT r.role_id, p.perm_id, 1
FROM role r
JOIN permission p
WHERE r.corp_id = 98746727 AND r.role_key = 'admin';

-- dispatcher
INSERT IGNORE INTO role_permission (role_id, perm_id, allow)
SELECT r.role_id, p.perm_id, 1
FROM role r
JOIN permission p
WHERE r.corp_id = 98746727 AND r.role_key = 'dispatcher'
  AND p.perm_key IN ('haul.request.read','haul.request.manage','haul.assign','webhook.manage','esi.manage');

-- hauler
INSERT IGNORE INTO role_permission (role_id, perm_id, allow)
SELECT r.role_id, p.perm_id, 1
FROM role r
JOIN permission p
WHERE r.corp_id = 98746727 AND r.role_key = 'hauler'
  AND p.perm_key IN ('haul.request.read','haul.execute');

-- requester
INSERT IGNORE INTO role_permission (role_id, perm_id, allow)
SELECT r.role_id, p.perm_id, 1
FROM role r
JOIN permission p
WHERE r.corp_id = 98746727 AND r.role_key = 'requester'
  AND p.perm_key IN ('haul.request.create','haul.request.read');

-- Initial admin user (change later)
INSERT INTO app_user (corp_id, character_id, character_name, email, display_name, status)
VALUES (98746727, 0, 'SYSTEM', 'admin@example.local', 'Initial Admin', 'active')
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name);

-- Assign admin role to initial admin user
INSERT IGNORE INTO user_role (user_id, role_id)
SELECT u.user_id, r.role_id
FROM app_user u
JOIN role r ON r.corp_id = u.corp_id AND r.role_key = 'admin'
WHERE u.corp_id = 98746727 AND u.email = 'admin@example.local';

-- Default app settings placeholders
INSERT INTO app_setting (corp_id, setting_key, setting_json)
VALUES
  (98746727, 'pricing.defaults', JSON_OBJECT('min_fee', 1000000, 'per_jump', 1000000)),
  (98746727, 'routing.defaults', JSON_OBJECT('route_policy', 'safest', 'avoid_low', true, 'avoid_null', true)),
  (98746727, 'discord.templates', JSON_OBJECT('request_post', JSON_OBJECT('enabled', true)))
ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json);
