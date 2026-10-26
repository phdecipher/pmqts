USE `pmqts`;

-- Sample assets are idempotent: unique asset tags prevent duplicate rows.
INSERT IGNORE INTO `tbl_inventory` (`asset_tag`, `asset_name`, `category`, `location`, `status`) VALUES
  ('AST-2026-DEMO001', 'Library Air Conditioner', 'Air Conditioning', 'Library', 'Active'),
  ('AST-2026-DEMO002', 'Faculty Epson L3210 Printer', 'Printer', 'Faculty Office', 'Active'),
  ('AST-2026-DEMO003', 'AVR Projector', 'Projector', 'Audio Visual Room', 'Under Maintenance'),
  ('AST-2026-DEMO004', 'Computer Lab 3 - PC 12', 'Computer', 'Computer Lab 3', 'Active'),
  ('AST-2026-DEMO005', 'Admin Office Air Conditioner', 'Air Conditioning', 'Admin Office', 'For Replacement'),
  ('AST-2026-DEMO006', 'Cisco Core Router', 'Network Equipment', 'Server Room', 'Active');

-- Add varied dates/statuses so All, Due Soon, Overdue, and Completed views are populated.
INSERT INTO `tbl_maintenance` (`inventory_id`, `maintenance_type`, `due_date`, `status`, `completed_at`)
SELECT i.id, 'Preventive cleaning', '2026-10-10', 'Scheduled', NULL
FROM `tbl_inventory` AS i
WHERE i.asset_tag = 'AST-2026-DEMO001'
  AND NOT EXISTS (
    SELECT 1 FROM `tbl_maintenance` AS m
    WHERE m.inventory_id = i.id AND m.maintenance_type = 'Preventive cleaning' AND m.due_date = '2026-10-10'
  );

INSERT INTO `tbl_maintenance` (`inventory_id`, `maintenance_type`, `due_date`, `status`, `completed_at`)
SELECT i.id, 'Printer inspection', '2026-10-20', 'Scheduled', NULL
FROM `tbl_inventory` AS i
WHERE i.asset_tag = 'AST-2026-DEMO002'
  AND NOT EXISTS (
    SELECT 1 FROM `tbl_maintenance` AS m
    WHERE m.inventory_id = i.id AND m.maintenance_type = 'Printer inspection' AND m.due_date = '2026-10-20'
  );

INSERT INTO `tbl_maintenance` (`inventory_id`, `maintenance_type`, `due_date`, `status`, `completed_at`)
SELECT i.id, 'Projector lamp check', '2026-10-01', 'Scheduled', NULL
FROM `tbl_inventory` AS i
WHERE i.asset_tag = 'AST-2026-DEMO003'
  AND NOT EXISTS (
    SELECT 1 FROM `tbl_maintenance` AS m
    WHERE m.inventory_id = i.id AND m.maintenance_type = 'Projector lamp check' AND m.due_date = '2026-10-01'
  );

INSERT INTO `tbl_maintenance` (`inventory_id`, `maintenance_type`, `due_date`, `status`, `completed_at`)
SELECT i.id, 'Lab workstation check-up', '2026-10-12', 'Scheduled', NULL
FROM `tbl_inventory` AS i
WHERE i.asset_tag = 'AST-2026-DEMO004'
  AND NOT EXISTS (
    SELECT 1 FROM `tbl_maintenance` AS m
    WHERE m.inventory_id = i.id AND m.maintenance_type = 'Lab workstation check-up' AND m.due_date = '2026-10-12'
  );

INSERT INTO `tbl_maintenance` (`inventory_id`, `maintenance_type`, `due_date`, `status`, `completed_at`)
SELECT i.id, 'Annual network maintenance', '2026-09-25', 'Completed', '2026-09-25 15:00:00'
FROM `tbl_inventory` AS i
WHERE i.asset_tag = 'AST-2026-DEMO006'
  AND NOT EXISTS (
    SELECT 1 FROM `tbl_maintenance` AS m
    WHERE m.inventory_id = i.id AND m.maintenance_type = 'Annual network maintenance' AND m.due_date = '2026-09-25'
  );
