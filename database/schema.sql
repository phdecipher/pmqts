CREATE DATABASE IF NOT EXISTS `pmqts`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `pmqts`;

CREATE TABLE IF NOT EXISTS `tbl_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(80) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(30) NOT NULL DEFAULT 'staff',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_inventory` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_tag` VARCHAR(40) NOT NULL,
  `qr_token` CHAR(64) NULL,
  `asset_name` VARCHAR(180) NOT NULL,
  `category` VARCHAR(100) NOT NULL DEFAULT 'Uncategorized',
  `location` VARCHAR(150) NOT NULL DEFAULT 'Unassigned',
  `status` VARCHAR(30) NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_asset_tag` (`asset_tag`),
  UNIQUE KEY `uq_inventory_qr_token` (`qr_token`),
  KEY `idx_inventory_status` (`status`),
  KEY `idx_inventory_asset_name` (`asset_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_procurement` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pr_number` VARCHAR(40) NOT NULL,
  `item_name` VARCHAR(180) NOT NULL,
  `source_trigger` VARCHAR(60) NOT NULL DEFAULT 'Manual',
  `estimated_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` VARCHAR(30) NOT NULL DEFAULT 'Pending Approval',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_procurement_pr_number` (`pr_number`),
  KEY `idx_procurement_status` (`status`),
  KEY `idx_procurement_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_maintenance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inventory_id` INT UNSIGNED NOT NULL,
  `maintenance_type` VARCHAR(120) NOT NULL,
  `due_date` DATE NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'Scheduled',
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_maintenance_due_date` (`due_date`),
  KEY `idx_maintenance_status` (`status`),
  CONSTRAINT `fk_maintenance_inventory`
    FOREIGN KEY (`inventory_id`) REFERENCES `tbl_inventory` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
