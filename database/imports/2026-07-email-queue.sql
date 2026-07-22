-- Add the notification queue to installations created from older schemas.
-- Safe to re-run on MySQL 5.7+ and MariaDB 10.2+.

CREATE TABLE IF NOT EXISTS `email_queue` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempt_count` int unsigned NOT NULL DEFAULT '0',
  `available_at` datetime DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  `claim_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email_status` (`status`),
  KEY `idx_email_created` (`created_at`),
  KEY `idx_email_ready` (`status`,`available_at`,`created_at`),
  KEY `idx_email_claim` (`claim_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MySQL 5.7 needs information_schema guards around each additive column.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'email_queue' AND column_name = 'attempt_count') = 0,
  'ALTER TABLE email_queue ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER status',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'email_queue' AND column_name = 'available_at') = 0,
  'ALTER TABLE email_queue ADD COLUMN available_at DATETIME NULL AFTER attempt_count',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'email_queue' AND column_name = 'claimed_at') = 0,
  'ALTER TABLE email_queue ADD COLUMN claimed_at DATETIME NULL AFTER available_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'email_queue' AND column_name = 'claim_token') = 0,
  'ALTER TABLE email_queue ADD COLUMN claim_token VARCHAR(64) NULL AFTER claimed_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'email_queue' AND column_name = 'last_error') = 0,
  'ALTER TABLE email_queue ADD COLUMN last_error VARCHAR(1000) NULL AFTER claim_token',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'email_queue' AND index_name = 'idx_email_ready') = 0,
  'CREATE INDEX idx_email_ready ON email_queue (status, available_at, created_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'email_queue' AND index_name = 'idx_email_claim') = 0,
  'CREATE INDEX idx_email_claim ON email_queue (claim_token)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
