-- Add time_limit_minutes column to challenges table
-- This allows teachers to specify custom time limits per challenge

ALTER TABLE challenges
ADD COLUMN time_limit_minutes INT UNSIGNED DEFAULT NULL COMMENT 'Custom time limit in minutes. NULL means use system default.' AFTER points;

-- Add index for faster lookups
ALTER TABLE challenges ADD INDEX idx_time_limit (time_limit_minutes);
