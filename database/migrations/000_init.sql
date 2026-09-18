-- CTF Server Database Schema
-- Compatible with MySQL 8 / MariaDB 10.11+
-- Charset: utf8mb4

CREATE DATABASE IF NOT EXISTS ctf_server
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ctf_server;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS nonces;
DROP TABLE IF EXISTS submissions;
DROP TABLE IF EXISTS solves;
DROP TABLE IF EXISTS task_sessions;
DROP TABLE IF EXISTS challenge_packages;
DROP TABLE IF EXISTS challenges;
DROP TABLE IF EXISTS device_activation_codes;
DROP TABLE IF EXISTS devices;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    email VARCHAR(190) NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    student_number VARCHAR(64) NULL,
    class_name VARCHAR(100) NULL,
    school_name VARCHAR(190) NULL,
    role ENUM('admin','teacher','student') NOT NULL DEFAULT 'student',
    status ENUM('pending','active','disabled') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NULL,
    device_token_hash CHAR(64) NOT NULL,
    status ENUM('active','revoked','disabled') NOT NULL DEFAULT 'active',
    agent_version VARCHAR(50) NULL,
    target_version VARCHAR(50) NULL,
    last_ip VARCHAR(45) NULL,
    last_seen_at DATETIME NULL,
    activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_devices_uuid (uuid),
    UNIQUE KEY uq_devices_token_hash (device_token_hash),
    KEY idx_devices_user (user_id),
    KEY idx_devices_status (status),

    CONSTRAINT fk_devices_user
      FOREIGN KEY (user_id) REFERENCES users(id)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE device_activation_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_activation_code_hash (code_hash),
    KEY idx_activation_user (user_id),
    KEY idx_activation_expiry (expires_at),

    CONSTRAINT fk_activation_user
      FOREIGN KEY (user_id) REFERENCES users(id)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE challenges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    teacher_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    category ENUM('web','crypto','reverse','pwn','forensic','misc','network') NOT NULL,
    difficulty ENUM('easy','medium','hard','expert') NOT NULL DEFAULT 'easy',
    description MEDIUMTEXT NOT NULL,
    points INT UNSIGNED NOT NULL DEFAULT 100,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('draft','published','disabled') NOT NULL DEFAULT 'draft',
    verification_type ENUM('flag','automatic') NOT NULL DEFAULT 'flag',
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_challenges_uuid (uuid),
    UNIQUE KEY uq_challenges_slug (slug),
    KEY idx_challenges_teacher (teacher_id),
    KEY idx_challenges_status (status),
    KEY idx_challenges_category_difficulty (category, difficulty),

    CONSTRAINT fk_challenges_teacher
      FOREIGN KEY (teacher_id) REFERENCES users(id)
      ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE challenge_packages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    challenge_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    manifest_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_challenge_package_version (challenge_id, version),
    KEY idx_packages_sha256 (sha256),

    CONSTRAINT fk_packages_challenge
      FOREIGN KEY (challenge_id) REFERENCES challenges(id)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE task_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NULL,
    challenge_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    status ENUM('active','completed','expired','cancelled') NOT NULL DEFAULT 'active',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_task_uuid (uuid),
    UNIQUE KEY uq_task_token_hash (token_hash),
    KEY idx_task_student_status (student_id, status),
    KEY idx_task_device (device_id),
    KEY idx_task_challenge (challenge_id),
    KEY idx_task_expiry (expires_at),

    CONSTRAINT fk_task_student
      FOREIGN KEY (student_id) REFERENCES users(id)
      ON DELETE CASCADE,

    CONSTRAINT fk_task_device
      FOREIGN KEY (device_id) REFERENCES devices(id)
      ON DELETE SET NULL,

    CONSTRAINT fk_task_challenge
      FOREIGN KEY (challenge_id) REFERENCES challenges(id)
      ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE solves (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT UNSIGNED NOT NULL,
    challenge_id BIGINT UNSIGNED NOT NULL,
    task_session_id BIGINT UNSIGNED NOT NULL,
    points INT UNSIGNED NOT NULL,
    solved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_solve_student_challenge (student_id, challenge_id),
    UNIQUE KEY uq_solve_task (task_session_id),
    KEY idx_solve_time (solved_at),

    CONSTRAINT fk_solve_student
      FOREIGN KEY (student_id) REFERENCES users(id)
      ON DELETE CASCADE,

    CONSTRAINT fk_solve_challenge
      FOREIGN KEY (challenge_id) REFERENCES challenges(id)
      ON DELETE RESTRICT,

    CONSTRAINT fk_solve_task
      FOREIGN KEY (task_session_id) REFERENCES task_sessions(id)
      ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT UNSIGNED NOT NULL,
    challenge_id BIGINT UNSIGNED NOT NULL,
    task_session_id BIGINT UNSIGNED NOT NULL,
    submitted_flag_hash CHAR(64) NOT NULL,
    correct TINYINT(1) NOT NULL DEFAULT 0,
    source_ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_submission_student (student_id),
    KEY idx_submission_challenge (challenge_id),
    KEY idx_submission_task (task_session_id),
    KEY idx_submission_time (created_at),

    CONSTRAINT fk_submission_student
      FOREIGN KEY (student_id) REFERENCES users(id)
      ON DELETE CASCADE,

    CONSTRAINT fk_submission_challenge
      FOREIGN KEY (challenge_id) REFERENCES challenges(id)
      ON DELETE RESTRICT,

    CONSTRAINT fk_submission_task
      FOREIGN KEY (task_session_id) REFERENCES task_sessions(id)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE nonces (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id BIGINT UNSIGNED NOT NULL,
    nonce_hash CHAR(64) NOT NULL,
    used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,

    UNIQUE KEY uq_nonce_hash (nonce_hash),
    KEY idx_nonce_device (device_id),
    KEY idx_nonce_expiry (expires_at),

    CONSTRAINT fk_nonce_device
      FOREIGN KEY (device_id) REFERENCES devices(id)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
('site_name', 'CTF Training Platform'),
('registration_enabled', '1'),
('teacher_registration_enabled', '1'),
('default_task_ttl', '120'),
('max_devices_per_student', '3'),
('maintenance_mode', '0'),
('target_sync_interval', '300');

CREATE TABLE rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket_key VARCHAR(190) NOT NULL,
    hit_count INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,

    UNIQUE KEY uq_rate_bucket (bucket_key),
    KEY idx_rate_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(100) NULL,
    target_id VARCHAR(100) NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_audit_user (user_id),
    KEY idx_audit_action (action),
    KEY idx_audit_time (created_at),

    CONSTRAINT fk_audit_user
      FOREIGN KEY (user_id) REFERENCES users(id)
      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Helpful leaderboard view
CREATE OR REPLACE VIEW leaderboard AS
SELECT
    u.id AS student_id,
    u.username,
    u.display_name,
    u.class_name,
    COUNT(s.id) AS solved_count,
    COALESCE(SUM(s.points), 0) AS score,
    MAX(s.solved_at) AS last_solve
FROM users u
LEFT JOIN solves s ON s.student_id = u.id
WHERE u.role = 'student'
  AND u.status = 'active'
GROUP BY u.id, u.username, u.display_name, u.class_name;
