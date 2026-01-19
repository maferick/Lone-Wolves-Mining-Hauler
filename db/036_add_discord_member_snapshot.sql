CREATE TABLE IF NOT EXISTS discord_member_snapshot (
  snapshot_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corp_id INT UNSIGNED NOT NULL,
  role_id VARCHAR(64) NOT NULL,
  member_json LONGTEXT NOT NULL,
  scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (snapshot_id),
  KEY idx_discord_member_snapshot_role (corp_id, role_id, scanned_at),
  CONSTRAINT fk_discord_member_snapshot_corp FOREIGN KEY (corp_id) REFERENCES corp(corp_id) ON DELETE CASCADE
);
