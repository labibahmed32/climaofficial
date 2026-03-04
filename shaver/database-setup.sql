-- ================================================================
-- MULTI-TENANT SHAVING SYSTEM - DATABASE SETUP
-- Deploy to: bg.climaofficial.com MySQL database
-- Version: 1.0
-- ================================================================

-- ----------------------------------------------------------------
-- Table: domains
-- Stores registered domains with their BuyGoods configuration
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `domains` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `domain_key` VARCHAR(64) NOT NULL UNIQUE COMMENT 'URL-safe identifier used in check.php?d=KEY',
  `domain_url` VARCHAR(500) NOT NULL COMMENT 'Full URL pattern e.g. bg.climaofficial.com/heater/',
  `label` VARCHAR(255) NOT NULL COMMENT 'Display name e.g. ClimaHeater EN',
  `bg_account_id` VARCHAR(20) DEFAULT NULL COMMENT 'Extracted: ?a=12105',
  `bg_product_codes` VARCHAR(500) DEFAULT NULL COMMENT 'Extracted: &product=hea2,hea3,hea6',
  `bg_conversion_token` VARCHAR(100) DEFAULT NULL COMMENT 'Extracted: &t=d357a9...',
  `bg_tracking_script` TEXT NOT NULL COMMENT 'Full BG tracking script block as pasted by user',
  `bg_iframe_script` TEXT DEFAULT NULL COMMENT 'Full BG conversion iframe script block',
  `status` ENUM('active', 'paused', 'deleted') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_domain_key` (`domain_key`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------
-- Table: shaving_sessions
-- Active and stopped shaving sessions, linked to domain_id
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shaving_sessions` (
  `id` VARCHAR(64) PRIMARY KEY COMMENT 'Unique session ID',
  `domain_id` INT NOT NULL COMMENT 'FK to domains.id',
  `aff_id` VARCHAR(100) NOT NULL COMMENT 'Affiliate ID to shave',
  `sub_id` VARCHAR(100) DEFAULT NULL COMMENT 'Optional sub-affiliate filter',
  `mode` ENUM('remove', 'replace') DEFAULT 'remove',
  `replace_aff_id` VARCHAR(100) DEFAULT NULL,
  `replace_sub_id` VARCHAR(100) DEFAULT NULL,
  `active` TINYINT(1) DEFAULT 1,
  `start_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `stop_time` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_domain_active` (`domain_id`, `active`),
  KEY `idx_aff_id` (`aff_id`),
  KEY `idx_active` (`active`),
  KEY `idx_domain_aff_active` (`domain_id`, `aff_id`, `active`),
  FOREIGN KEY (`domain_id`) REFERENCES `domains`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------
-- Table: shaving_history
-- Archived sessions after stopping
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shaving_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` VARCHAR(64) NOT NULL,
  `domain_id` INT NOT NULL,
  `aff_id` VARCHAR(100) NOT NULL,
  `sub_id` VARCHAR(100) DEFAULT NULL,
  `mode` ENUM('remove', 'replace') DEFAULT 'remove',
  `replace_aff_id` VARCHAR(100) DEFAULT NULL,
  `replace_sub_id` VARCHAR(100) DEFAULT NULL,
  `start_time` DATETIME NOT NULL,
  `stop_time` DATETIME NOT NULL,
  `total_visits` INT DEFAULT 0,
  `total_clicks` INT DEFAULT 0,
  `duration` INT DEFAULT 0 COMMENT 'Duration in seconds',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_domain_id` (`domain_id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_stop_time` (`stop_time`),
  FOREIGN KEY (`domain_id`) REFERENCES `domains`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------
-- Table: shaving_tracking
-- Per-event counters (visit/click) for active sessions
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shaving_tracking` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` VARCHAR(64) NOT NULL,
  `domain_id` INT NOT NULL,
  `aff_id` VARCHAR(100) DEFAULT NULL,
  `sub_id` VARCHAR(100) DEFAULT NULL,
  `event_type` ENUM('visit', 'click') NOT NULL,
  `page` TEXT DEFAULT NULL,
  `referrer` TEXT DEFAULT NULL,
  `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_session_event` (`session_id`, `event_type`),
  KEY `idx_domain_id` (`domain_id`),
  KEY `idx_timestamp` (`timestamp`),
  FOREIGN KEY (`domain_id`) REFERENCES `domains`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------
-- Table: affiliate_traffic
-- ALL visitor traffic (shaved and not shaved), per-domain
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `affiliate_traffic` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `domain_id` INT NOT NULL,
  `aff_id` VARCHAR(100) DEFAULT NULL,
  `sub_id` VARCHAR(100) DEFAULT NULL,
  `page_url` TEXT DEFAULT NULL,
  `referrer` TEXT DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `browser` VARCHAR(100) DEFAULT NULL,
  `device` VARCHAR(50) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `country` VARCHAR(100) DEFAULT NULL,
  `country_code` VARCHAR(10) DEFAULT NULL,
  `was_shaved` TINYINT(1) DEFAULT 0,
  `shaving_session_id` VARCHAR(64) DEFAULT NULL,
  `session_uuid` VARCHAR(100) DEFAULT NULL,
  `screen_width` INT DEFAULT NULL,
  `screen_height` INT DEFAULT NULL,
  `viewport_width` INT DEFAULT NULL,
  `viewport_height` INT DEFAULT NULL,
  `session_duration` INT DEFAULT NULL,
  `max_scroll_depth` INT DEFAULT NULL,
  `total_clicks` INT DEFAULT 0,
  `reached_checkout` TINYINT(1) DEFAULT 0,
  `checkout_url` TEXT DEFAULT NULL,
  `time_to_first_click` INT DEFAULT NULL,
  `time_to_checkout` INT DEFAULT NULL,
  `page_load_time` INT DEFAULT NULL,
  `bounce` TINYINT(1) DEFAULT 0,
  `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_domain_timestamp` (`domain_id`, `timestamp`),
  KEY `idx_aff_id` (`aff_id`),
  KEY `idx_was_shaved` (`was_shaved`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_domain_shaved_time` (`domain_id`, `was_shaved`, `timestamp`),
  FOREIGN KEY (`domain_id`) REFERENCES `domains`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------
-- Table: user_behavior_events
-- Detailed granular behavior events
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_behavior_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `traffic_id` INT NOT NULL COMMENT 'FK to affiliate_traffic.id',
  `domain_id` INT NOT NULL,
  `session_uuid` VARCHAR(100) DEFAULT NULL,
  `event_type` VARCHAR(50) NOT NULL,
  `event_data` JSON DEFAULT NULL,
  `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_traffic_id` (`traffic_id`),
  KEY `idx_domain_event` (`domain_id`, `event_type`),
  KEY `idx_session_uuid` (`session_uuid`),
  KEY `idx_timestamp` (`timestamp`),
  FOREIGN KEY (`traffic_id`) REFERENCES `affiliate_traffic`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`domain_id`) REFERENCES `domains`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
