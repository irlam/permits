-- Persistent public-action throttling for MySQL 5.7+/MariaDB 10.2+.
-- Safe to run more than once.
CREATE TABLE IF NOT EXISTS `public_rate_limits` (
  `key_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `window_started_at` bigint unsigned NOT NULL,
  `last_attempt_at` bigint unsigned NOT NULL,
  PRIMARY KEY (`key_hash`),
  KEY `idx_public_limits_last_attempt` (`last_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
