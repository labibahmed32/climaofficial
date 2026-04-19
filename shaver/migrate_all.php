<?php
require_once __DIR__ . '/config.php';
$pdo = getDB();
$errors = [];
$created = [];

$tables = [
'affiliate_traffic' => "
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
  KEY `idx_timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'user_behavior_events' => "
CREATE TABLE IF NOT EXISTS `user_behavior_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `traffic_id` INT NOT NULL,
  `domain_id` INT NOT NULL,
  `session_uuid` VARCHAR(100) DEFAULT NULL,
  `event_type` VARCHAR(50) NOT NULL,
  `event_data` JSON DEFAULT NULL,
  `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_traffic_id` (`traffic_id`),
  KEY `idx_domain_event` (`domain_id`, `event_type`),
  KEY `idx_session_uuid` (`session_uuid`),
  KEY `idx_timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'shaving_tracking' => "
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
  KEY `idx_timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'shaving_history' => "
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
  `duration` INT DEFAULT 0,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_domain_id` (`domain_id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_stop_time` (`stop_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'shave_snapshots' => "
CREATE TABLE IF NOT EXISTS `shave_snapshots` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `domain_id` INT NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(512) DEFAULT NULL,
  `phase` ENUM('before','after') NOT NULL,
  `session_id` VARCHAR(50) DEFAULT NULL,
  `aff_id` VARCHAR(100) DEFAULT NULL,
  `sub_id` VARCHAR(100) DEFAULT NULL,
  `mode` VARCHAR(20) DEFAULT NULL,
  `replace_aff_id` VARCHAR(100) DEFAULT NULL,
  `replace_sub_id` VARCHAR(100) DEFAULT NULL,
  `url` TEXT DEFAULT NULL,
  `sessid2` VARCHAR(200) DEFAULT NULL,
  `cookies` JSON DEFAULT NULL,
  `cookie_count` INT DEFAULT 0,
  `url_params` JSON DEFAULT NULL,
  `matched_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_ip_domain_phase` (`ip_address`, `domain_id`, `phase`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($tables as $name => $sql) {
    try {
        $pdo->exec($sql);
        $created[] = $name;
    } catch (PDOException $e) {
        $errors[] = "$name: " . $e->getMessage();
    }
}

echo "<pre>";
echo "=== MIGRATION COMPLETE ===\n\n";
echo "Tables processed (" . count($created) . "):\n";
foreach ($created as $t) echo "  ✓ $t\n";
if ($errors) {
    echo "\nErrors (" . count($errors) . "):\n";
    foreach ($errors as $e) echo "  ✗ $e\n";
} else {
    echo "\nNo errors. All tables OK.\n";
}
echo "</pre>";

// Self-delete
@unlink(__FILE__);
echo "<p>This file has been deleted.</p>";
