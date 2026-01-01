-- Ensure haul_request.route_policy supports balanced profiles.
ALTER TABLE haul_request
  MODIFY route_policy ENUM('shortest','balanced','safest','avoid_low','avoid_null','custom') NOT NULL DEFAULT 'safest';
