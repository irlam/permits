-- Production hardening migration for MySQL 8.0+
-- Back up the database, then import with:
-- mysql -u USER -p DATABASE < database/imports/2026-07-production-hardening.sql

START TRANSACTION;

ALTER TABLE forms
  ADD COLUMN IF NOT EXISTS work_started_at DATETIME NULL AFTER approved_at;

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

COMMIT;
