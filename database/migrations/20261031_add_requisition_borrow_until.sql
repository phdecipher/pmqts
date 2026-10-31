-- Date until which the requested assets are borrowed. Safe to run repeatedly.
SET @pmqts_schema = DATABASE();
SELECT COUNT(*) INTO @borrow_until_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @pmqts_schema
  AND TABLE_NAME = 'tbl_requisitions'
  AND COLUMN_NAME = 'borrow_until';

SET @borrow_until_ddl = IF(
  @borrow_until_exists = 0,
  'ALTER TABLE `tbl_requisitions` ADD COLUMN `borrow_until` DATE NULL AFTER `purpose`',
  'SELECT 1'
);
PREPARE pmqts_borrow_until_stmt FROM @borrow_until_ddl;
EXECUTE pmqts_borrow_until_stmt;
DEALLOCATE PREPARE pmqts_borrow_until_stmt;
