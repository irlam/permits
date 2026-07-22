-- Cross-worker leases for MySQL 5.7+/MariaDB 10.2+.
-- Safe to run more than once.
CREATE TABLE IF NOT EXISTS `worker_locks` (
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner_token` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `acquired_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`name`),
  KEY `idx_worker_locks_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
