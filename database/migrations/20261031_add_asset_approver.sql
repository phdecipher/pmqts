-- Adds a per-asset approver. Safe to run repeatedly.
SET @pmqts_schema = DATABASE();
SELECT COUNT(*) INTO @approver_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @pmqts_schema
  AND TABLE_NAME = 'tbl_inventory'
  AND COLUMN_NAME = 'approver_id';

SET @approver_ddl = IF(
  @approver_exists = 0,
  'ALTER TABLE `tbl_inventory`
     ADD COLUMN `approver_id` INT UNSIGNED NULL AFTER `custodian_id`,
     ADD CONSTRAINT `fk_inventory_approver`
       FOREIGN KEY (`approver_id`) REFERENCES `tbl_users` (`id`)
       ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE approver_stmt FROM @approver_ddl;
EXECUTE approver_stmt;
DEALLOCATE PREPARE approver_stmt;
