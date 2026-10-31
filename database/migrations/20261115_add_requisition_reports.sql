-- Damage reports filed by maintenance personnel while assets are being returned
-- (needs repair, or deemed for disposal). Safe to run repeatedly.
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
