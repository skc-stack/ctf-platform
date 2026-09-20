-- Migration 006: Add challenge time tracking columns to task_sessions
-- Tracks cumulative solve time and current session start time

ALTER TABLE task_sessions
  ADD COLUMN challenge_time_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER expires_at,
  ADD COLUMN challenge_started_at DATETIME NULL AFTER challenge_time_seconds;
