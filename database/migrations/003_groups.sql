-- ============================================================================
-- 003 — Groups (Teacher creates, Student joins via join_code)
-- ============================================================================
-- 學生 ↔ 老師的關係透過群組管理，不再使用直接連結。
-- 學生加入群組才能看到該老師發布的題目（除非題目為公開）。
--
-- Idempotent: 使用 information_schema 檢查後再 CREATE，可重複執行。
-- ============================================================================

USE ctf_server;

-- groups: 一個群組由一位老師建立。
CREATE TABLE IF NOT EXISTS `groups` (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36) NOT NULL,
    teacher_id      BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    description     TEXT NULL,
    join_code       CHAR(8) NOT NULL,
    max_members     INT UNSIGNED NULL,
    status          ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_groups_uuid (uuid),
    UNIQUE KEY uq_groups_join_code (join_code),
    KEY idx_groups_teacher (teacher_id, status),
    KEY idx_groups_status (status, created_at),

    CONSTRAINT fk_groups_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- group_members: 學生與群組的多對多關係。
-- status 用於標記 left / banned，保留 audit trail（不真刪除）。
CREATE TABLE IF NOT EXISTS `group_members` (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id        BIGINT UNSIGNED NOT NULL,
    student_id      BIGINT UNSIGNED NOT NULL,
    status          ENUM('active','left','banned') NOT NULL DEFAULT 'active',
    joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    left_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_gm_group_student (group_id, student_id),
    KEY idx_gm_student_status (student_id, status),
    KEY idx_gm_group_status (group_id, status),

    CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT fk_gm_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
