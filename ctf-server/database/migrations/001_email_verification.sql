-- ============================================================================
-- 001 — Email verification + password reset tokens
-- ============================================================================
-- Adds:
--   users.email_verified_at
--   email_verification_tokens
--   password_reset_tokens
--
-- Idempotent: safe to re-run. Existing users are auto-marked verified so
-- they don't get locked out by the new login eligibility check.
-- ============================================================================

USE ctf_server;

-- 1. Add users.email_verified_at (skip if already exists)
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'ctf_server'
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'email_verified_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER email',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Backfill: existing users (incl. admin) are already verified.
UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL;

-- 3. email_verification_tokens
CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_evt_token_hash (token_hash),
    KEY idx_evt_user (user_id),
    KEY idx_evt_expiry (expires_at),

    CONSTRAINT fk_evt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. password_reset_tokens
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_prt_token_hash (token_hash),
    KEY idx_prt_user (user_id),
    KEY idx_prt_expiry (expires_at),

    CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
