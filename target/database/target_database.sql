-- CTF Target VM Database Schema
-- Compatible with MySQL 8 / MariaDB 10.11+
-- Charset: utf8mb4
--
-- 這個 DB 只能由 Target Agent 寫入。
-- 不得儲存 Server credential / FLAG_MASTER_SECRET / Teacher credential。
-- 每一道 Challenge 另有自己的 `ctf_<challenge_id>` DB（安裝 challenge 時動態建立）。

CREATE DATABASE IF NOT EXISTS ctf_target
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ctf_target;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS agent_events;
DROP TABLE IF EXISTS sync_history;
DROP TABLE IF EXISTS local_tasks;
DROP TABLE IF EXISTS installed_challenges;
DROP TABLE IF EXISTS local_settings;

SET FOREIGN_KEY_CHECKS = 1;

-- 已安裝題目
CREATE TABLE installed_challenges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    challenge_id VARCHAR(64) NOT NULL,
    challenge_uuid CHAR(36) NULL,
    server_version INT UNSIGNED NOT NULL,
    local_version INT UNSIGNED NOT NULL,
    manifest_json LONGTEXT NOT NULL,
    install_path VARCHAR(500) NOT NULL,
    entrypoint VARCHAR(255) NULL,
    verification_type ENUM('flag','automatic') NOT NULL DEFAULT 'flag',
    database_name VARCHAR(64) NULL,
    status ENUM('installed','broken','failed') NOT NULL DEFAULT 'installed',
    last_error TEXT NULL,
    installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_installed_challenge_id (challenge_id),
    KEY idx_installed_status (status),
    KEY idx_installed_challenge_uuid (challenge_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 本地 task session（鏡像 Server task_sessions）
CREATE TABLE local_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_uuid CHAR(36) NOT NULL,
    challenge_id VARCHAR(64) NOT NULL,
    server_task_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    status ENUM('active','completed','expired','cancelled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_local_task_uuid (task_uuid),
    KEY idx_local_task_challenge (challenge_id),
    KEY idx_local_task_status (status),
    KEY idx_local_task_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 同步歷史
CREATE TABLE sync_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    result ENUM('success','partial','failed') NOT NULL,
    challenges_seen INT UNSIGNED NOT NULL DEFAULT 0,
    challenges_installed INT UNSIGNED NOT NULL DEFAULT 0,
    challenges_updated INT UNSIGNED NOT NULL DEFAULT 0,
    challenges_failed INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_sync_started (started_at),
    KEY idx_sync_result (result)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agent 事件 log
CREATE TABLE agent_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(64) NOT NULL,
    challenge_id VARCHAR(64) NULL,
    task_uuid CHAR(36) NULL,
    success TINYINT(1) NOT NULL DEFAULT 1,
    message TEXT NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_event_type (event_type),
    KEY idx_event_challenge (challenge_id),
    KEY idx_event_task (task_uuid),
    KEY idx_event_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Local 設定（k-v 形式）
CREATE TABLE local_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_local_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 預設值
INSERT INTO local_settings (setting_key, setting_value) VALUES
  ('agent_version', '0.1.0'),
  ('last_server_url', ''),
  ('last_sync_at', NULL),
  ('last_heartbeat_at', NULL),
  ('installed_count', '0');
