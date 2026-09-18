-- Track what challenges each device has synced/installed
CREATE TABLE IF NOT EXISTS device_sync_status (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id INT UNSIGNED NOT NULL,
    challenge_id VARCHAR(190) NOT NULL,
    challenge_version INT UNSIGNED NOT NULL DEFAULT 1,
    sha256 CHAR(64) NOT NULL,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_device_challenge (device_id, challenge_id),
    KEY idx_synced_at (synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
