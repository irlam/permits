-- Activity/admin compatibility migration for MySQL 5.7+ and MariaDB 10.2+
--
-- 1. Back up the database.
-- 2. Select the permits database in phpMyAdmin, then use Import to run this file.
-- 3. It is safe to run this file more than once.
--
-- This deliberately avoids `ADD COLUMN IF NOT EXISTS`, which is unsupported
-- by some MySQL/MariaDB versions used by shared hosting providers.

CREATE TABLE IF NOT EXISTS activity_log (
  id INT NOT NULL AUTO_INCREMENT,
  `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id VARCHAR(36) NULL,
  type VARCHAR(64) NULL,
  user_email VARCHAR(255) NULL,
  action VARCHAR(100) NOT NULL DEFAULT 'unknown',
  category VARCHAR(50) NOT NULL DEFAULT 'general',
  resource_type VARCHAR(50) NULL,
  resource_id VARCHAR(100) NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'success',
  PRIMARY KEY (id),
  KEY idx_timestamp (`timestamp`),
  KEY idx_user_id (user_id),
  KEY idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'timestamp') = 0,
  'ALTER TABLE activity_log ADD COLUMN `timestamp` DATETIME NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'user_id') = 0,
  'ALTER TABLE activity_log ADD COLUMN user_id VARCHAR(36) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'type') = 0,
  'ALTER TABLE activity_log ADD COLUMN type VARCHAR(64) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'user_email') = 0,
  'ALTER TABLE activity_log ADD COLUMN user_email VARCHAR(255) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'action') = 0,
  'ALTER TABLE activity_log ADD COLUMN action VARCHAR(100) NULL DEFAULT ''unknown''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'category') = 0,
  'ALTER TABLE activity_log ADD COLUMN category VARCHAR(50) NULL DEFAULT ''general''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'resource_type') = 0,
  'ALTER TABLE activity_log ADD COLUMN resource_type VARCHAR(50) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'resource_id') = 0,
  'ALTER TABLE activity_log ADD COLUMN resource_id VARCHAR(100) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'description') = 0,
  'ALTER TABLE activity_log ADD COLUMN description TEXT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'created_at') = 0,
  'ALTER TABLE activity_log ADD COLUMN created_at DATETIME NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'ip_address') = 0,
  'ALTER TABLE activity_log ADD COLUMN ip_address VARCHAR(45) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'user_agent') = 0,
  'ALTER TABLE activity_log ADD COLUMN user_agent TEXT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'status') = 0,
  'ALTER TABLE activity_log ADD COLUMN status VARCHAR(20) NULL DEFAULT ''success''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Retain messages from installations that called the field `details`.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND column_name = 'details') > 0,
  'UPDATE activity_log SET description = details WHERE (description IS NULL OR CHAR_LENGTH(description) = 0) AND details IS NOT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE activity_log SET type = action WHERE (type IS NULL OR type = '') AND action IS NOT NULL;
UPDATE activity_log SET action = type WHERE (action IS NULL OR action = '' OR action = 'unknown') AND type IS NOT NULL;
UPDATE activity_log SET action = 'unknown' WHERE action IS NULL OR action = '';
UPDATE activity_log SET category = 'general' WHERE category IS NULL OR category = '';
UPDATE activity_log SET status = 'success' WHERE status IS NULL OR status = '';
UPDATE activity_log SET `timestamp` = COALESCE(`timestamp`, created_at, NOW());
UPDATE activity_log SET created_at = COALESCE(created_at, `timestamp`, NOW());

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND index_name = 'idx_timestamp') = 0,
  'CREATE INDEX idx_timestamp ON activity_log (`timestamp`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND index_name = 'idx_user_id') = 0,
  'CREATE INDEX idx_user_id ON activity_log (user_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'activity_log' AND index_name = 'idx_action') = 0,
  'CREATE INDEX idx_action ON activity_log (action)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Activity log compatibility migration completed.' AS result;
