-- Adds the requisition workflow. Safe to run repeatedly.
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
  `status` VARCHAR(30) NOT NULL DEFAULT 'Pending Approval',
  `reviewed_by` INT UNSIGNED NULL,
  `review_note` VARCHAR(255) NULL,
  `reviewed_at` DATETIME NULL,
  `processed_by` INT UNSIGNED NULL,
  `processed_at` DATETIME NULL,
  `job_order_number` VARCHAR(40) NULL,
  `fulfilled_by` INT UNSIGNED NULL,
  `fulfilled_at` DATETIME NULL,
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_requisition_item` (`requisition_id`, `inventory_id`),
  KEY `idx_requisition_items_inventory` (`inventory_id`),
  CONSTRAINT `fk_requisition_items_requisition` FOREIGN KEY (`requisition_id`) REFERENCES `tbl_requisitions` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_requisition_items_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `tbl_inventory` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @pmqts_schema = DATABASE();
SELECT COUNT(*) INTO @proc_req_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @pmqts_schema
  AND TABLE_NAME = 'tbl_procurement'
  AND COLUMN_NAME = 'requisition_id';

SET @proc_req_ddl = IF(
  @proc_req_exists = 0,
  'ALTER TABLE `tbl_procurement`
     ADD COLUMN `quantity` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `item_name`,
     ADD COLUMN `requisition_id` INT UNSIGNED NULL AFTER `status`,
     ADD KEY `idx_procurement_requisition` (`requisition_id`)',
  'SELECT 1'
);
PREPARE pmqts_proc_req_stmt FROM @proc_req_ddl;
EXECUTE pmqts_proc_req_stmt;
DEALLOCATE PREPARE pmqts_proc_req_stmt;
