-- Upgrade: play Zoom Phone call recordings in the CRM. Only Zoom's recording reference is stored; the audio stays at Zoom.
-- Run once after 005 (phpMyAdmin → Import). Fresh installs get this from database.sql. Safe to re-run.

SET NAMES utf8mb4;

-- zoom_calls.recording_* (added only if missing)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zoom_calls' AND COLUMN_NAME = 'recording_url');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE zoom_calls ADD COLUMN recording_id VARCHAR(100) NULL AFTER lead_id, ADD COLUMN recording_url VARCHAR(500) NULL AFTER recording_id, ADD COLUMN recording_duration INT UNSIGNED NULL AFTER recording_url',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- zoom_connections.recordings_error: why recordings could not be read (usually a missing Zoom scope)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zoom_connections' AND COLUMN_NAME = 'recordings_error');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE zoom_connections ADD COLUMN recordings_error VARCHAR(500) NULL AFTER last_error',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
