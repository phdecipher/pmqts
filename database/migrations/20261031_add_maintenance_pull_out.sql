-- Adds repair pull-out tracking to maintenance records. Safe to run repeatedly.
SET @pmqts_schema = DATABASE();
SELECT COUNT(*) INTO @pulled_out_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @pmqts_schema
  AND TABLE_NAME = 'tbl_maintenance'
  AND COLUMN_NAME = 'pulled_out_at';

SET @pulled_out_ddl = IF(
  @pulled_out_exists = 0,
  'ALTER TABLE `tbl_maintenance` ADD COLUMN `pulled_out_at` DATETIME NULL AFTER `notes`',
  'SELECT 1'
);
PREPARE pmqts_pulled_out_stmt FROM @pulled_out_ddl;
EXECUTE pmqts_pulled_out_stmt;
DEALLOCATE PREPARE pmqts_pulled_out_stmt;
