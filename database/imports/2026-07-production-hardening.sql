-- Production hardening migration for MySQL 5.7+/MariaDB 10.2+
-- Back up the database, then import with:
-- mysql -u USER -p DATABASE < database/imports/2026-07-production-hardening.sql

-- DDL implicitly commits in MySQL, so each operation is guarded and rerunnable.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'forms' AND column_name = 'work_started_at') = 0,
  'ALTER TABLE forms ADD COLUMN work_started_at DATETIME NULL AFTER approved_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- "viewer" was a legacy role that no current permission path recognises.
-- Convert existing accounts and make regular "user" the safe schema default.
UPDATE users SET role = 'user' WHERE role = 'viewer';
ALTER TABLE users
  MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'user';

-- User identifiers are UUID strings throughout the application.
ALTER TABLE forms
  MODIFY COLUMN approved_by VARCHAR(36) NULL;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'forms' AND index_name = 'idx_forms_unique_link') = 0,
  'CREATE INDEX idx_forms_unique_link ON forms (unique_link)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'forms' AND index_name = 'idx_forms_holder_status') = 0,
  'CREATE INDEX idx_forms_holder_status ON forms (holder_id, status)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
