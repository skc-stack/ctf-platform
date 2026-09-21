-- Migration: 008_solve_attempts
-- Add attempts column to solves table to track how many tries a student made before solving
ALTER TABLE solves ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Number of attempts before solving successfully';
