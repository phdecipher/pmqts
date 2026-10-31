-- Adds optional quantity tracking for countable assets. Safe to run repeatedly.
SET @pmqts_schema = DATABASE();
SELECT COUNT(*) INTO @countable_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @pmqts_schema
  AND TABLE_NAME = 'tbl_inventory'
  AND COLUMN_NAME = 'is_countable';

SET @countable_ddl = IF(
  @countable_exists = 0,
  'ALTER TABLE `tbl_inventory`
     ADD COLUMN `is_countable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `category`,
     ADD COLUMN `quantity` INT UNSIGNED NULL AFTER `is_countable`',
  'SELECT 1'
);
PREPARE pmqts_countable_stmt FROM @countable_ddl;
EXECUTE pmqts_countable_stmt;
DEALLOCATE PREPARE pmqts_countable_stmt;

SELECT COUNT(*) INTO @req_qty_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @pmqts_schema
  AND TABLE_NAME = 'tbl_requisition_items'
  AND COLUMN_NAME = 'quantity';

SET @req_qty_ddl = IF(
  @req_qty_exists = 0,
  'ALTER TABLE `tbl_requisition_items` ADD COLUMN `quantity` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `inventory_id`',
  'SELECT 1'
);
PREPARE pmqts_req_qty_stmt FROM @req_qty_ddl;
EXECUTE pmqts_req_qty_stmt;
DEALLOCATE PREPARE pmqts_req_qty_stmt;
