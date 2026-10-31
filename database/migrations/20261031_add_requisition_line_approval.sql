-- Per-item approval for requisitions. Safe to run repeatedly.
SET @pmqts_schema = DATABASE();
SELECT COUNT(*) INTO @line_approval_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @pmqts_schema
  AND TABLE_NAME = 'tbl_requisition_lines'
  AND COLUMN_NAME = 'approval_status';

-- Existing lines default to Approved so requisitions already past approval are unaffected.
SET @line_approval_ddl = IF(
  @line_approval_exists = 0,
  'ALTER TABLE `tbl_requisition_lines`
     ADD COLUMN `approval_status` VARCHAR(10) NOT NULL DEFAULT ''Approved'',
     ADD COLUMN `decided_by` INT UNSIGNED NULL,
     ADD COLUMN `decided_at` DATETIME NULL,
     ADD COLUMN `decision_note` VARCHAR(255) NULL,
     ADD CONSTRAINT `fk_requisition_lines_decider` FOREIGN KEY (`decided_by`) REFERENCES `tbl_users` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE pmqts_line_approval_stmt FROM @line_approval_ddl;
EXECUTE pmqts_line_approval_stmt;
DEALLOCATE PREPARE pmqts_line_approval_stmt;

-- Lines of requisitions still awaiting approval are pending; ones already reviewed keep the reviewer.
UPDATE `tbl_requisition_lines` l
INNER JOIN `tbl_requisitions` r ON r.`id` = l.`requisition_id`
SET l.`approval_status` = 'Pending'
WHERE r.`status` = 'Pending Approval' AND l.`approval_status` = 'Approved' AND l.`decided_by` IS NULL;

UPDATE `tbl_requisition_lines` l
INNER JOIN `tbl_requisitions` r ON r.`id` = l.`requisition_id`
SET l.`decided_by` = r.`reviewed_by`, l.`decided_at` = r.`reviewed_at`,
    l.`approval_status` = IF(r.`status` = 'Rejected' AND r.`reviewed_by` IS NOT NULL, 'Rejected', l.`approval_status`),
    l.`decision_note` = r.`review_note`
WHERE r.`reviewed_by` IS NOT NULL AND l.`decided_by` IS NULL AND l.`approval_status` <> 'Pending';
