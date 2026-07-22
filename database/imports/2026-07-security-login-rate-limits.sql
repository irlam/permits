-- Persistent login throttling for MySQL 5.7+/MariaDB 10.2+.
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `key_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `window_started_at` bigint unsigned NOT NULL,
  `last_failed_at` bigint unsigned NOT NULL,
  PRIMARY KEY (`key_hash`),
  KEY `idx_login_attempts_last_failed` (`last_failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
