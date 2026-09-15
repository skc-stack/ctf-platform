-- ============================================================================
-- 004 — challenge_groups (Challenge ↔ Group M:N)
-- ============================================================================
-- 決定哪個群組（也就是哪些學生）可以看到哪個 challenge。
-- 沒綁定任何 group 的 challenge = 公開題（所有 active student 可見）。
--
-- Idempotent: CREATE IF NOT EXISTS。
-- ============================================================================

USE ctf_server;

CREATE TABLE IF NOT EXISTS challenge_groups (
    challenge_id  BIGINT UNSIGNED NOT NULL,
    group_id      BIGINT UNSIGNED NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (challenge_id, group_id),
    KEY idx_cg_group (group_id),

    CONSTRAINT fk_cg_challenge FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE CASCADE,
    CONSTRAINT fk_cg_group     FOREIGN KEY (group_id)     REFERENCES `groups`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
