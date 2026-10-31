-- Start of the period in which the requested assets are active. Safe to run repeatedly.
SELECT COUNT(*) INTO @borrow_from_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'tbl_requisitions'
  AND COLUMN_NAME = 'borrow_from';

SET @borrow_from_ddl = IF(
  @borrow_from_exists = 0,
  'ALTER TABLE `tbl_requisitions` ADD COLUMN `borrow_from` DATETIME NULL AFTER `purpose`',
  'SELECT 1'
);
PREPARE pmqts_borrow_from_stmt FROM @borrow_from_ddl;
EXECUTE pmqts_borrow_from_stmt;
DEALLOCATE PREPARE pmqts_borrow_from_stmt;
