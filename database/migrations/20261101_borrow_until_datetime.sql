-- Lets the requisition end date carry a time of day. Existing dates become 5:00 PM. Safe to run repeatedly.
ALTER TABLE `tbl_requisitions` MODIFY COLUMN `borrow_until` DATETIME NULL;
UPDATE `tbl_requisitions` SET `borrow_until` = ADDTIME(`borrow_until`, '17:00:00') WHERE `borrow_until` IS NOT NULL AND TIME(`borrow_until`) = '00:00:00';
