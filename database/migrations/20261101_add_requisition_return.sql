-- Return tracking: where each asset came from when it was fulfilled, and who returned the requisition when.
-- Safe to run repeatedly.
SET @cols = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_requisition_items' AND COLUMN_NAME = 'origin_location');
SET @ddl = IF(@cols = 0, 'ALTER TABLE `tbl_requisition_items` ADD COLUMN `origin_location` VARCHAR(255) NULL, ADD COLUMN `origin_location_id` INT UNSIGNED NULL', 'SELECT 1');
PREPARE pmqts_ret_stmt FROM @ddl;
EXECUTE pmqts_ret_stmt;
DEALLOCATE PREPARE pmqts_ret_stmt;

SET @cols = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_requisitions' AND COLUMN_NAME = 'returned_at');
SET @ddl = IF(@cols = 0, 'ALTER TABLE `tbl_requisitions` ADD COLUMN `returned_by` INT UNSIGNED NULL, ADD COLUMN `returned_at` DATETIME NULL', 'SELECT 1');
PREPARE pmqts_ret_stmt FROM @ddl;
EXECUTE pmqts_ret_stmt;
DEALLOCATE PREPARE pmqts_ret_stmt;
