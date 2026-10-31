-- Lets one requisition request several items. Safe to run repeatedly.
CREATE TABLE IF NOT EXISTS `tbl_requisition_lines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `requisition_id` INT UNSIGNED NOT NULL,
  `item_name` VARCHAR(180) NOT NULL,
  `category` VARCHAR(100) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_requisition_line_item` (`requisition_id`, `item_name`),
  KEY `idx_requisition_lines_item` (`item_name`),
  CONSTRAINT `fk_requisition_lines_requisition` FOREIGN KEY (`requisition_id`) REFERENCES `tbl_requisitions` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing single-item requisitions become one-line requisitions.
INSERT INTO `tbl_requisition_lines` (`requisition_id`, `item_name`, `category`, `quantity`)
SELECT r.`id`, r.`item_name`, r.`category`, r.`quantity`
FROM `tbl_requisitions` r
WHERE NOT EXISTS (SELECT 1 FROM `tbl_requisition_lines` l WHERE l.`requisition_id` = r.`id`);
