-- ============================================================================
-- 002 — Drop per-student institution fields
-- ============================================================================
-- Removes users.student_number, users.class_name, users.school_name.
--
-- Existing rows have NULL in these columns (they were optional inputs
-- during student registration), so DROP is a safe no-op for data.
--
-- Idempotent: checks for column existence before dropping each one.
-- ============================================================================

USE ctf_server;

-- Drop each column in its own statement (PREPARE/EXECUTE cannot run
-- multiple statements in one go on MariaDB).
SET @col := 'student_number';
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'ctf_server' AND TABLE_NAME = 'users' AND COLUMN_NAME = @col) > 0,
    CONCAT('ALTER TABLE users DROP COLUMN ', @col),
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := 'class_name';
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'ctf_server' AND TABLE_NAME = 'users' AND COLUMN_NAME = @col) > 0,
    CONCAT('ALTER TABLE users DROP COLUMN ', @col),
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := 'school_name';
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'ctf_server' AND TABLE_NAME = 'users' AND COLUMN_NAME = @col) > 0,
    CONCAT('ALTER TABLE users DROP COLUMN ', @col),
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Fix the leaderboard view: it GROUP BYs u.class_name which no longer exists.
DROP VIEW IF EXISTS leaderboard;
CREATE VIEW leaderboard AS
SELECT
    u.id                          AS student_id,
    u.username                    AS username,
    u.display_name                AS display_name,
    u.email                       AS email,
    COUNT(s.id)                    AS solved_count,
    COALESCE(SUM(s.points), 0)     AS score,
    MAX(s.solved_at)               AS last_solve
FROM users u
LEFT JOIN solves s ON s.student_id = u.id
WHERE u.role = 'student' AND u.status = 'active'
GROUP BY u.id, u.username, u.display_name, u.email;
