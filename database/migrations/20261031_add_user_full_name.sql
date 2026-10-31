-- Adds the complete name shown across the system. Safe to run repeatedly.
SET @pmqts_schema = DATABASE();
SELECT COUNT(*) INTO @full_name_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @pmqts_schema
  AND TABLE_NAME = 'tbl_users'
  AND COLUMN_NAME = 'full_name';

SET @full_name_ddl = IF(
  @full_name_exists = 0,
  'ALTER TABLE `tbl_users` ADD COLUMN `full_name` VARCHAR(150) NOT NULL DEFAULT '''' AFTER `id`',
  'SELECT 1'
);
PREPARE full_name_stmt FROM @full_name_ddl;
EXECUTE full_name_stmt;
DEALLOCATE PREPARE full_name_stmt;

-- Existing accounts keep their username until an administrator enters the real name.
UPDATE `tbl_users` SET `full_name` = `username` WHERE `full_name` = '';

-- Custodian history stores the name as text, so it must fit a full name.
ALTER TABLE `tbl_custodian_history` MODIFY `custodian_name` VARCHAR(150) NOT NULL;
UPDATE `tbl_custodian_history` h INNER JOIN `tbl_users` u ON u.id = h.custodian_id SET h.custodian_name = u.full_name WHERE h.custodian_name = u.username;
