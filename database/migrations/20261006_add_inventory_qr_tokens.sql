-- Add persistent per-asset QR tokens to existing installations. Safe to run repeatedly.
SET @pmqts_schema = DATABASE();
SELECT COUNT(*) INTO @qr_token_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @pmqts_schema
  AND TABLE_NAME = 'tbl_inventory'
  AND COLUMN_NAME = 'qr_token';

SET @qr_token_ddl = IF(
  @qr_token_exists = 0,
  'ALTER TABLE `tbl_inventory` ADD COLUMN `qr_token` CHAR(64) NULL AFTER `asset_tag`, ADD UNIQUE KEY `uq_inventory_qr_token` (`qr_token`)',
  'SELECT 1'
);
PREPARE pmqts_qr_token_stmt FROM @qr_token_ddl;
EXECUTE pmqts_qr_token_stmt;
DEALLOCATE PREPARE pmqts_qr_token_stmt;
