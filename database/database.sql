-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 10.35.233.124:3306
-- Generation Time: Oct 30, 2025 at 04:37 PM
-- Server version: 8.0.43
-- PHP Version: 8.4.8

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `k87747_permits`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int NOT NULL,
  `timestamp` datetime DEFAULT CURRENT_TIMESTAMP,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resource_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `old_values` text COLLATE utf8mb4_unicode_ci,
  `new_values` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'success'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attachments`
--

CREATE TABLE `attachments` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `form_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` mediumtext COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forms`
--

CREATE TABLE `forms` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ref_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `template_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `form_data` text COLLATE utf8mb4_unicode_ci,
  `site_block` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ref` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `holder_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `holder_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `holder_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `holder_phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notification_token` text COLLATE utf8mb4_unicode_ci,
  `unique_link` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issuer_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valid_from` datetime DEFAULT NULL,
  `valid_to` datetime DEFAULT NULL,
  `metadata` mediumtext COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `approval_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'pending, approved, rejected',
  `approved_by` int DEFAULT NULL COMMENT 'User ID who approved/rejected',
  `approved_at` datetime DEFAULT NULL COMMENT 'When approved/rejected',
  `approval_notes` text COLLATE utf8mb4_unicode_ci COMMENT 'Notes from approver',
  `requires_approval` tinyint(1) DEFAULT '1' COMMENT 'Does this permit require approval',
  `notified_at` datetime DEFAULT NULL COMMENT 'When approval notification was sent',
  `closed_by` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `closure_reason` text COLLATE utf8mb4_unicode_ci,
  `expiry_duration` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '1 day',
  `expires_at` datetime DEFAULT NULL,
  `expired_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `form_events`
--

CREATE TABLE `form_events` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `form_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `by_user` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` mediumtext COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `form_templates`
--

CREATE TABLE `form_templates` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int NOT NULL DEFAULT '1',
  `json_schema` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `ui_schema` mediumtext COLLATE utf8mb4_unicode_ci,
  `created_by` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `active` tinyint(1) DEFAULT '1' COMMENT 'Is template active/visible',
  `form_structure` text COLLATE utf8mb4_unicode_ci,
  `description` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_approval_links`
--

CREATE TABLE `permit_approval_links` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permit_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `used_action` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `used_comment` text COLLATE utf8mb4_unicode_ci,
  `used_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `metadata` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `push_subscriptions`
--

CREATE TABLE `push_subscriptions` (
  `id` char(36) NOT NULL,
  `user_id` varchar(191) NOT NULL,
  `endpoint` mediumtext NOT NULL,
  `endpoint_hash` char(64) NOT NULL,
  `p256dh` varchar(255) NOT NULL,
  `auth` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int UNSIGNED NOT NULL,
  `key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `site_settings`
--

CREATE TABLE `site_settings` (
  `id` int UNSIGNED NOT NULL,
  `site_code` varchar(64) NOT NULL,
  `working_day_start` time NOT NULL DEFAULT '07:30:00',
  `working_day_end` time NOT NULL DEFAULT '17:30:00',
  `finish_buffer_minutes` int NOT NULL DEFAULT '120',
  `timezone` varchar(64) NOT NULL DEFAULT 'Europe/London',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` varchar(36) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'viewer',
  `status` varchar(50) NOT NULL DEFAULT 'active',
  `invited_by` varchar(36) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_timestamp` (`timestamp`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_resource` (`resource_type`,`resource_id`);

--
-- Indexes for table `attachments`
--
ALTER TABLE `attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_att_form` (`form_id`);

--
-- Indexes for table `forms`
--
ALTER TABLE `forms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_forms_template_status` (`template_id`,`status`),
  ADD KEY `idx_forms_validto` (`valid_to`),
  ADD KEY `idx_forms_lookup` (`site_block`,`ref`),
  ADD KEY `idx_approval_status` (`approval_status`),
  ADD KEY `idx_approved_by` (`approved_by`),
  ADD KEY `idx_ref_number` (`ref_number`);

--
-- Indexes for table `form_events`
--
ALTER TABLE `form_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_events_form_type_at` (`form_id`,`type`,`at`);

--
-- Indexes for table `form_templates`
--
ALTER TABLE `form_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_active` (`active`);

--
-- Indexes for table `permit_approval_links`
--
ALTER TABLE `permit_approval_links`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_approval_links_token` (`token_hash`),
  ADD KEY `idx_approval_links_permit` (`permit_id`),
  ADD KEY `idx_approval_links_expires` (`expires_at`);

--
-- Indexes for table `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_endpoint_hash` (`endpoint_hash`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `settings_key_unique` (`key`);

--
-- Indexes for table `site_settings`
--
ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `site_code` (`site_code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_email` (`email`),
  ADD KEY `idx_users_role` (`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `site_settings`
--
ALTER TABLE `site_settings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attachments`
--
ALTER TABLE `attachments`
  ADD CONSTRAINT `fk_att_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`);

--
-- Constraints for table `forms`
--
ALTER TABLE `forms`
  ADD CONSTRAINT `fk_forms_template` FOREIGN KEY (`template_id`) REFERENCES `form_templates` (`id`);

--
-- Constraints for table `form_events`
--
ALTER TABLE `form_events`
  ADD CONSTRAINT `fk_events_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`);

-- --------------------------------------------------------

--
-- Seed data: default administrator account
--

INSERT INTO `users` (`id`, `email`, `password_hash`, `name`, `role`, `status`, `created_at`)
VALUES
  ('11111111-1111-1111-1111-111111111111', 'admin@example.com', '$2y$12$4b8QG0yXl8k5ZfB5l1yJ2e9twDaV6nQGJbXlIqGn/0mZy6D4nMG1q', 'System Administrator', 'admin', 'active', NOW())
ON DUPLICATE KEY UPDATE
  `email` = VALUES(`email`),
  `password_hash` = VALUES(`password_hash`),
  `name` = VALUES(`name`),
  `role` = VALUES(`role`),
  `status` = VALUES(`status`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
