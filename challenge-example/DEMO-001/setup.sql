-- ============================================================================
-- DEMO-001 setup.sql
-- ============================================================================
-- Runs on Target VM when this challenge is installed by the Agent.
-- Creates the challenge's own DB with a `secrets` table.
--
-- In real challenges, this could be SQLi-prone (intentionally!) — e.g. a
-- web form that lets students dump this table. For DEMO-001 the table is
-- just here to validate that the install pipeline runs setup.sql correctly.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS ctf_demo_001
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE ctf_demo_001;

CREATE TABLE IF NOT EXISTS secrets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(190) NOT NULL,
  value TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO secrets (label, value) VALUES
  ('hint', 'the flag is HMAC-derived, not literal'),
  ('note', 'real challenges store real secrets here')
ON DUPLICATE KEY UPDATE value = VALUES(value);
