CREATE DATABASE IF NOT EXISTS `pmqts`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `pmqts`;

CREATE TABLE IF NOT EXISTS `tbl_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(150) NOT NULL DEFAULT '',
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

CREATE TABLE IF NOT EXISTS `tbl_locations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department` VARCHAR(100) NOT NULL,
  `building` VARCHAR(100) NOT NULL,
  `room_number` VARCHAR(40) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_locations_dept_bldg_room` (`department`, `building`, `room_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_inventory` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_tag` VARCHAR(40) NOT NULL,
  `qr_token` CHAR(64) NULL,
  `asset_name` VARCHAR(180) NOT NULL,
  `category` VARCHAR(100) NOT NULL DEFAULT 'Uncategorized',
  `is_countable` TINYINT(1) NOT NULL DEFAULT 0,
  `quantity` INT UNSIGNED NULL,
  `date_acquired` DATE NULL,
  `acquisition_cost` DECIMAL(12,2) NULL,
  `location` VARCHAR(150) NOT NULL DEFAULT 'Unassigned',
  `location_id` INT UNSIGNED NULL,
  `custodian_id` INT UNSIGNED NULL,
  `approver_id` INT UNSIGNED NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'Active',
  `disposal_method` VARCHAR(30) NULL,
  `disposal_date` DATE NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_asset_tag` (`asset_tag`),
  UNIQUE KEY `uq_inventory_qr_token` (`qr_token`),
  KEY `idx_inventory_status` (`status`),
  KEY `idx_inventory_asset_name` (`asset_name`),
  CONSTRAINT `fk_inventory_location`
    FOREIGN KEY (`location_id`) REFERENCES `tbl_locations` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_custodian`
    FOREIGN KEY (`custodian_id`) REFERENCES `tbl_users` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_approver`
    FOREIGN KEY (`approver_id`) REFERENCES `tbl_users` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inventory_id` INT UNSIGNED NOT NULL,
  `custodian_id` INT UNSIGNED NULL,
  `custodian_name` VARCHAR(150) NOT NULL,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `released_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_custodian_history_inventory` (`inventory_id`, `assigned_at`),
  CONSTRAINT `fk_custodian_history_inventory`
    FOREIGN KEY (`inventory_id`) REFERENCES `tbl_inventory` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_custodian_history_user`
    FOREIGN KEY (`custodian_id`) REFERENCES `tbl_users` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_procurement` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pr_number` VARCHAR(40) NOT NULL,
  `item_name` VARCHAR(180) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `source_trigger` VARCHAR(60) NOT NULL DEFAULT 'Manual',
  `estimated_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` VARCHAR(30) NOT NULL DEFAULT 'Pending Approval',
  `requisition_id` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_procurement_pr_number` (`pr_number`),
  KEY `idx_procurement_status` (`status`),
  KEY `idx_procurement_created_at` (`created_at`),
  KEY `idx_procurement_requisition` (`requisition_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_requisitions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `req_number` VARCHAR(40) NOT NULL,
  `requester_id` INT UNSIGNED NOT NULL,
  `item_name` VARCHAR(180) NOT NULL,
  `category` VARCHAR(100) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NULL,
  `use_location` VARCHAR(150) NOT NULL,
  `purpose` VARCHAR(255) NULL,
  `borrow_from` DATETIME NULL,
  `borrow_until` DATETIME NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'Pending Approval',
  `reviewed_by` INT UNSIGNED NULL,
  `review_note` VARCHAR(255) NULL,
  `reviewed_at` DATETIME NULL,
  `processed_by` INT UNSIGNED NULL,
  `processed_at` DATETIME NULL,
  `job_order_number` VARCHAR(40) NULL,
  `fulfilled_by` INT UNSIGNED NULL,
  `fulfilled_at` DATETIME NULL,
  `returned_by` INT UNSIGNED NULL,
  `returned_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_requisitions_number` (`req_number`),
  UNIQUE KEY `uq_requisitions_job_order` (`job_order_number`),
  KEY `idx_requisitions_status` (`status`),
  KEY `idx_requisitions_requester` (`requester_id`),
  CONSTRAINT `fk_requisitions_requester` FOREIGN KEY (`requester_id`) REFERENCES `tbl_users` (`id`),
  CONSTRAINT `fk_requisitions_location` FOREIGN KEY (`location_id`) REFERENCES `tbl_locations` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_requisition_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `requisition_id` INT UNSIGNED NOT NULL,
  `inventory_id` INT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `origin_location` VARCHAR(255) NULL,
  `origin_location_id` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_requisition_item` (`requisition_id`, `inventory_id`),
  KEY `idx_requisition_items_inventory` (`inventory_id`),
  CONSTRAINT `fk_requisition_items_requisition` FOREIGN KEY (`requisition_id`) REFERENCES `tbl_requisitions` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_requisition_items_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `tbl_inventory` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_requisition_reports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `requisition_id` INT UNSIGNED NOT NULL,
  `inventory_id` INT UNSIGNED NOT NULL,
  `report_type` VARCHAR(20) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `disposal_method` VARCHAR(30) NULL,
  `notes` TEXT NULL,
  `split_inventory_id` INT UNSIGNED NULL,
  `reported_by` INT UNSIGNED NULL,
  `reported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_requisition_report_item` (`requisition_id`, `inventory_id`),
  CONSTRAINT `fk_requisition_reports_requisition` FOREIGN KEY (`requisition_id`) REFERENCES `tbl_requisitions` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_requisition_reports_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `tbl_inventory` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_requisition_lines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `requisition_id` INT UNSIGNED NOT NULL,
  `item_name` VARCHAR(180) NOT NULL,
  `category` VARCHAR(100) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `approval_status` VARCHAR(10) NOT NULL DEFAULT 'Approved',
  `decided_by` INT UNSIGNED NULL,
  `decided_at` DATETIME NULL,
  `decision_note` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_requisition_line_item` (`requisition_id`, `item_name`),
  KEY `idx_requisition_lines_item` (`item_name`),
  CONSTRAINT `fk_requisition_lines_requisition` FOREIGN KEY (`requisition_id`) REFERENCES `tbl_requisitions` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_requisition_lines_decider` FOREIGN KEY (`decided_by`) REFERENCES `tbl_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tbl_maintenance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inventory_id` INT UNSIGNED NOT NULL,
  `maintenance_type` VARCHAR(120) NOT NULL,
  `due_date` DATE NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'Scheduled',
  `notes` TEXT NULL,
  `pulled_out_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_maintenance_due_date` (`due_date`),
  KEY `idx_maintenance_status` (`status`),
  CONSTRAINT `fk_maintenance_inventory`
    FOREIGN KEY (`inventory_id`) REFERENCES `tbl_inventory` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
