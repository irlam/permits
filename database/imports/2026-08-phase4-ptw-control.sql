-- Phase 4 permit-to-work control support.
-- Safe to run repeatedly on MySQL/MariaDB.

CREATE TABLE IF NOT EXISTS `permit_links` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `form_a_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `form_b_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `relation_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'related',
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_by` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permit_links_pair_type` (`form_a_id`,`form_b_id`,`relation_type`),
  KEY `idx_permit_links_a` (`form_a_id`),
  KEY `idx_permit_links_b` (`form_b_id`),
  KEY `idx_permit_links_type` (`relation_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- form_events is already part of the normal fresh-install schema. The CLI
-- migration also creates it for older installations before Phase 4 is used.
