-- Adds structured locations, acquisition details, custodian history and disposal fields. Safe to run repeatedly.
CREATE TABLE IF NOT EXISTS `tbl_locations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department` VARCHAR(100) NOT NULL,
  `building` VARCHAR(100) NOT NULL,
  `room_number` VARCHAR(40) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_locations_dept_bldg_room` (`department`, `building`, `room_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @pmqts_schema = DATABASE();
SELECT COUNT(*) INTO @inventory_details_exist
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @pmqts_schema
  AND TABLE_NAME = 'tbl_inventory'
  AND COLUMN_NAME = 'date_acquired';

SET @inventory_details_ddl = IF(
  @inventory_details_exist = 0,
  'ALTER TABLE `tbl_inventory`
     ADD COLUMN `date_acquired` DATE NULL AFTER `category`,
     ADD COLUMN `acquisition_cost` DECIMAL(12,2) NULL AFTER `date_acquired`,
     ADD COLUMN `location_id` INT UNSIGNED NULL AFTER `location`,
     ADD COLUMN `custodian_id` INT UNSIGNED NULL AFTER `location_id`,
     ADD COLUMN `disposal_method` VARCHAR(30) NULL AFTER `status`,
     ADD COLUMN `disposal_date` DATE NULL AFTER `disposal_method`,
     ADD CONSTRAINT `fk_inventory_location` FOREIGN KEY (`location_id`) REFERENCES `tbl_locations` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
     ADD CONSTRAINT `fk_inventory_custodian` FOREIGN KEY (`custodian_id`) REFERENCES `tbl_users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE pmqts_inventory_details_stmt FROM @inventory_details_ddl;
EXECUTE pmqts_inventory_details_stmt;
DEALLOCATE PREPARE pmqts_inventory_details_stmt;

CREATE TABLE IF NOT EXISTS `tbl_custodian_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inventory_id` INT UNSIGNED NOT NULL,
  `custodian_id` INT UNSIGNED NULL,
  `custodian_name` VARCHAR(80) NOT NULL,
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
