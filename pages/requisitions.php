<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_access('requisitions');
require_once __DIR__ . '/../config/db.php';

$flash = take_flash();
$csrfToken = csrf_token();
$userId = (int) $_SESSION['user_id'];
$sawAll = has_role('property_custodian', 'approver', 'department_head', 'maintenance_personnel');
const REQUISITION_STATUSES = ['Pending Approval', 'Pending Custodian', 'Awaiting Procurement', 'Ready for Fulfillment', 'Fulfilled', 'Returned', 'Rejected', 'Cancelled'];

// Rows of the named item that still have stock, as [inventory id => units available].
function available_rows(mysqli $conn, string $itemName, bool $lock = false): array
{
  $sql = 'SELECT i.id, ' . AVAILABLE_EXPR . " AS available FROM tbl_inventory i WHERE i.status = 'Active' AND i.asset_name = ? ORDER BY i.id" . ($lock ? ' FOR UPDATE' : '');
  $statement = $conn->prepare($sql);
  $statement->bind_param('s', $itemName);
  $statement->execute();
  $rows = [];
  foreach ($statement->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    if ((int) $row['available'] > 0) {
      $rows[(int) $row['id']] = (int) $row['available'];
    }
  }
  return $rows;
}

function you_badge(bool $isYou): string
{
  return $isYou ? ' <span class="badge bg-info text-dark">You</span>' : '';
}

// Renders a "id|name||id|name" list as names, flagging the logged-in user.
function assigned_names(string $list, int $userId): string
{
  return implode(', ', array_map(function ($pair) use ($userId) {
    [$id, $name] = explode('|', $pair, 2);
    return h($name) . you_badge((int) $id === $userId);
  }, explode('||', $list)));
}

function location_text(array $row): string
{
  return $row['department'] . ' / ' . $row['building'] . ' / Room ' . $row['room_number'];
}

function can_fulfill(array $req, int $userId): bool
{
  return current_role() === 'admin' || current_role() === 'maintenance_personnel'
    || (has_role('property_custodian') && (int) $req['processed_by'] === $userId);
}

function is_assigned(mysqli $conn, int $requisitionId, int $userId, string $column): bool
{
  if (current_role() === 'admin') {
    return true;
  }
  $statement = $conn->prepare('SELECT ' . sprintf(ASSIGNED_SQL, $column) . ' AS ok FROM tbl_requisitions r WHERE r.id = ?');
  $statement->bind_param('ii', $userId, $requisitionId);
  $statement->execute();
  return (bool) ($statement->get_result()->fetch_assoc()['ok'] ?? false);
}

function requisition_lines(mysqli $conn, int $requisitionId): array
{
  $statement = $conn->prepare("SELECT item_name, category, quantity FROM tbl_requisition_lines WHERE requisition_id = ? AND approval_status = 'Approved' ORDER BY id");
  $statement->bind_param('i', $requisitionId);
  $statement->execute();
  return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
}

function can_decide_line(mysqli $conn, int $lineId, int $userId): bool
{
  if (current_role() === 'admin') {
    return true;
  }
  $statement = $conn->prepare('SELECT ' . LINE_APPROVER_SQL . ' AS ok FROM tbl_requisition_lines l WHERE l.id = ?');
  $statement->bind_param('ii', $userId, $lineId);
  $statement->execute();
  return (bool) ($statement->get_result()->fetch_assoc()['ok'] ?? false);
}

function fetch_requisition(mysqli $conn, int $id): ?array
{
  $statement = $conn->prepare('SELECT * FROM tbl_requisitions WHERE id = ? FOR UPDATE');
  $statement->bind_param('i', $id);
  $statement->execute();
  return $statement->get_result()->fetch_assoc() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid form token. Refresh the page and try again.');
  }

  $action = $_POST['action'] ?? '';
  $id = (int) filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
  try {
    $conn->begin_transaction();

    if ($action === 'create') {
      if (!has_role('teacher', 'department_head', 'approver', 'property_custodian')) {
        throw new InvalidArgumentException('Your role cannot submit requisitions.');
      }
      $names = $_POST['item_name'] ?? [];
      $quantities = $_POST['quantity'] ?? [];
      $locationId = filter_var($_POST['location_id'] ?? null, FILTER_VALIDATE_INT);
      $purpose = trim($_POST['purpose'] ?? '');
      $parseLocal = static function ($value) {
        $value = trim((string) $value);
        $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value);
        return $date && $date->format('Y-m-d\TH:i') === $value ? $date : null;
      };
      $fromDate = $parseLocal($_POST['borrow_from'] ?? '');
      $untilDate = $parseLocal($_POST['borrow_until'] ?? '');
      if (!$fromDate || !$untilDate) {
        throw new InvalidArgumentException('Choose when the items will be active, from and to (date and time).');
      }
      if ($untilDate <= new DateTime() || $untilDate <= $fromDate) {
        throw new InvalidArgumentException('The "to" date and time must be in the future and after the "from" date and time.');
      }
      $borrowFrom = $fromDate->format('Y-m-d H:i:s');
      $borrowUntil = $untilDate->format('Y-m-d H:i:s');
      if (!is_array($names) || !is_array($quantities) || !$locationId || mb_strlen($purpose) > 255) {
        throw new InvalidArgumentException('Select at least one item, enter quantities and choose where they will be used.');
      }

      // Rows that repeat an item are merged into one line.
      $wanted = [];
      foreach ($names as $index => $name) {
        $name = trim((string) $name);
        $qty = filter_var($quantities[$index] ?? null, FILTER_VALIDATE_INT);
        if ($name === '' && !$qty) {
          continue;
        }
        if ($name === '' || !$qty || $qty < 1) {
          throw new InvalidArgumentException('Every row needs an item and a quantity of at least 1.');
        }
        $wanted[$name] = ($wanted[$name] ?? 0) + $qty;
      }
      if (!$wanted || count($wanted) > 30) {
        throw new InvalidArgumentException('Select between 1 and 30 items.');
      }

      $locationStatement = $conn->prepare('SELECT department, building, room_number FROM tbl_locations WHERE id = ?');
      $locationStatement->bind_param('i', $locationId);
      $locationStatement->execute();
      $locationRow = $locationStatement->get_result()->fetch_assoc();
      if (!$locationRow) {
        throw new InvalidArgumentException('Select a valid location.');
      }

      $lines = [];
      foreach ($wanted as $name => $qty) {
        $units = available_rows($conn, $name, true);
        $totalAvailable = array_sum($units);
        if ($totalAvailable === 0) {
          throw new InvalidArgumentException("\"$name\" is not available for requisition.");
        }
        if ($qty > $totalAvailable) {
          throw new InvalidArgumentException("Only $totalAvailable unit(s) of \"$name\" are available. Reduce the quantity.");
        }
        $categoryStatement = $conn->prepare('SELECT category FROM tbl_inventory WHERE id = ?');
        $firstId = (int) array_key_first($units);
        $categoryStatement->bind_param('i', $firstId);
        $categoryStatement->execute();
        $lines[] = ['name' => $name, 'qty' => $qty, 'category' => (string) $categoryStatement->get_result()->fetch_assoc()['category']];
      }

      // The requisition row keeps a summary (first item, total quantity) for lists and procurement.
      $itemName = mb_substr($lines[0]['name'] . (count($lines) > 1 ? ' +' . (count($lines) - 1) . ' more' : ''), 0, 180);
      $quantity = array_sum(array_column($lines, 'qty'));
      $categories = array_unique(array_column($lines, 'category'));
      $category = count($categories) === 1 ? $categories[0] : 'Multiple';

      $number = 'REQ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
      $status = current_role() === 'teacher' ? 'Pending Approval' : 'Pending Custodian';
      $useLocation = mb_substr(location_text($locationRow), 0, 150);
      $statement = $conn->prepare('INSERT INTO tbl_requisitions (req_number, requester_id, item_name, category, quantity, location_id, use_location, purpose, borrow_from, borrow_until, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
      $purposeValue = $purpose === '' ? null : $purpose;
      $statement->bind_param('sissiisssss', $number, $userId, $itemName, $category, $quantity, $locationId, $useLocation, $purposeValue, $borrowFrom, $borrowUntil, $status);
      $statement->execute();
      $requisitionId = (int) $conn->insert_id;
      $lineStatement = $conn->prepare('INSERT INTO tbl_requisition_lines (requisition_id, item_name, category, quantity, approval_status) VALUES (?, ?, ?, ?, ?)');
      $lineApproval = $status === 'Pending Approval' ? 'Pending' : 'Approved';
      foreach ($lines as $line) {
        $lineStatement->bind_param('issis', $requisitionId, $line['name'], $line['category'], $line['qty'], $lineApproval);
        $lineStatement->execute();
      }
      set_flash("Requisition $number submitted" . ($status === 'Pending Approval' ? ' and sent to the approver.' : ' and sent to the property custodian.'));
    } elseif (in_array($action, ['approve', 'reject'], true) && $id) {
      if (!has_role('approver', 'department_head')) {
        throw new InvalidArgumentException('Your role cannot approve requisitions.');
      }
      $req = fetch_requisition($conn, $id);
      if (!$req || $req['status'] !== 'Pending Approval') {
        throw new InvalidArgumentException('This requisition is no longer awaiting approval.');
      }
      $lineId = (int) filter_var($_POST['line_id'] ?? 0, FILTER_VALIDATE_INT);
      $lineStatement = $conn->prepare("SELECT id, item_name FROM tbl_requisition_lines WHERE id = ? AND requisition_id = ? AND approval_status = 'Pending' FOR UPDATE");
      $lineStatement->bind_param('ii', $lineId, $id);
      $lineStatement->execute();
      $line = $lineStatement->get_result()->fetch_assoc();
      if (!$line) {
        throw new InvalidArgumentException('That item has already been decided.');
      }
      if (!can_decide_line($conn, $lineId, $userId)) {
        throw new InvalidArgumentException('Only the approver assigned to this item can approve or reject it.');
      }
      $note = trim($_POST['note'] ?? '');
      if ($action === 'reject' && $note === '') {
        throw new InvalidArgumentException('Enter a reason for rejecting the item.');
      }
      if (mb_strlen($note) > 255) {
        throw new InvalidArgumentException('The note must be 255 characters or fewer.');
      }
      $decision = $action === 'approve' ? 'Approved' : 'Rejected';
      $noteValue = $note === '' ? null : $note;
      $statement = $conn->prepare('UPDATE tbl_requisition_lines SET approval_status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?');
      $statement->bind_param('sisi', $decision, $userId, $noteValue, $lineId);
      $statement->execute();

      $statement = $conn->prepare("SELECT SUM(approval_status = 'Pending') AS pending, SUM(approval_status = 'Approved') AS approved FROM tbl_requisition_lines WHERE requisition_id = ?");
      $statement->bind_param('i', $id);
      $statement->execute();
      $counts = $statement->get_result()->fetch_assoc();
      if ((int) $counts['pending'] > 0) {
        set_flash($decision === 'Approved' ? 'Item approved. Other items are still waiting for their approvers.' : 'Item rejected. Other items are still waiting for their approvers.');
      } elseif ((int) $counts['approved'] > 0) {
        // Rejected items are dropped; the header summary reflects what moves on to the custodian.
        $statement = $conn->prepare("SELECT item_name, category, quantity FROM tbl_requisition_lines WHERE requisition_id = ? AND approval_status = 'Approved' ORDER BY id");
        $statement->bind_param('i', $id);
        $statement->execute();
        $kept = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $summaryName = mb_substr($kept[0]['item_name'] . (count($kept) > 1 ? ' +' . (count($kept) - 1) . ' more' : ''), 0, 180);
        $summaryQty = array_sum(array_column($kept, 'quantity'));
        $summaryCategories = array_unique(array_column($kept, 'category'));
        $summaryCategory = count($summaryCategories) === 1 ? $summaryCategories[0] : 'Multiple';
        $statement = $conn->prepare("UPDATE tbl_requisitions SET status = 'Pending Custodian', item_name = ?, category = ?, quantity = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $statement->bind_param('ssiii', $summaryName, $summaryCategory, $summaryQty, $userId, $id);
        $statement->execute();
        set_flash('All items were reviewed. The requisition was sent to the property custodian.');
      } else {
        $statement = $conn->prepare("UPDATE tbl_requisitions SET status = 'Rejected', reviewed_by = ?, review_note = ?, reviewed_at = NOW() WHERE id = ?");
        $statement->bind_param('isi', $userId, $noteValue, $id);
        $statement->execute();
        set_flash('All items were rejected, so the requisition was rejected.');
      }
    } elseif ($action === 'process' && $id) {
      if (!has_role('property_custodian')) {
        throw new InvalidArgumentException('Only the property custodian can process requisitions.');
      }
      $req = fetch_requisition($conn, $id);
      if (!$req || $req['status'] !== 'Pending Custodian') {
        throw new InvalidArgumentException('This requisition is not waiting for the custodian.');
      }
      if (!is_assigned($conn, $id, $userId, 'custodian_id')) {
        throw new InvalidArgumentException('Only the custodian assigned to these items can process the requisition.');
      }
      $plan = [];
      $shortages = [];
      foreach (requisition_lines($conn, $id) as $line) {
        $units = available_rows($conn, $line['item_name'], true);
        $plan[] = [(int) $line['quantity'], $units];
        $short = (int) $line['quantity'] - array_sum($units);
        if ($short > 0) {
          $shortages[$line['item_name']] = $short;
        }
      }

      if (!$shortages) {
        $link = $conn->prepare('INSERT INTO tbl_requisition_items (requisition_id, inventory_id, quantity) VALUES (?, ?, ?)');
        foreach ($plan as [$remaining, $units]) {
          foreach ($units as $unitId => $available) {
            if ($remaining <= 0) {
              break;
            }
            $take = min($available, $remaining);
            $link->bind_param('iii', $id, $unitId, $take);
            $link->execute();
            $remaining -= $take;
          }
        }
        $jobOrder = 'JO-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $statement = $conn->prepare("UPDATE tbl_requisitions SET status = 'Ready for Fulfillment', processed_by = ?, processed_at = NOW(), job_order_number = ? WHERE id = ?");
        $statement->bind_param('isi', $userId, $jobOrder, $id);
        $statement->execute();
        set_flash("Stock is available. Job order $jobOrder is ready to print.");
      } else {
        $source = 'Requisition';
        $statement = $conn->prepare('INSERT INTO tbl_procurement (pr_number, item_name, quantity, source_trigger, estimated_cost, requisition_id) VALUES (?, ?, ?, ?, 0, ?)');
        $prNumbers = [];
        foreach ($shortages as $shortName => $shortfall) {
          $prNumber = 'PR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
          $prNumbers[] = $prNumber;
          $statement->bind_param('ssisi', $prNumber, $shortName, $shortfall, $source, $id);
          $statement->execute();
        }
        $prNumber = implode(', ', $prNumbers);
        $shortfall = array_sum($shortages);
        $statement = $conn->prepare("UPDATE tbl_requisitions SET status = 'Awaiting Procurement', processed_by = ?, processed_at = NOW() WHERE id = ?");
        $statement->bind_param('ii', $userId, $id);
        $statement->execute();
        set_flash("Not enough stock ($shortfall short). Purchase request $prNumber was created automatically.", 'warning');
      }
    } elseif ($action === 'fulfill' && $id) {
      $req = fetch_requisition($conn, $id);
      if (!$req || $req['status'] !== 'Ready for Fulfillment') {
        throw new InvalidArgumentException('This requisition is not ready for fulfillment.');
      }
      if (!can_fulfill($req, $userId)) {
        throw new InvalidArgumentException('Only the processing custodian or maintenance personnel can mark this requisition fulfilled.');
      }
      $statement = $conn->prepare("UPDATE tbl_requisitions SET status = 'Fulfilled', fulfilled_by = ?, fulfilled_at = NOW() WHERE id = ?");
      $statement->bind_param('ii', $userId, $id);
      $statement->execute();
      // Remember where each single unit came from so it can be returned later.
      $statement = $conn->prepare('UPDATE tbl_requisition_items ri INNER JOIN tbl_inventory i ON i.id = ri.inventory_id SET ri.origin_location = i.location, ri.origin_location_id = i.location_id WHERE ri.requisition_id = ? AND i.is_countable = 0');
      $statement->bind_param('i', $id);
      $statement->execute();
      // Countable stock is deducted; single units are moved to the requested location.
      $statement = $conn->prepare('UPDATE tbl_inventory i INNER JOIN tbl_requisition_items ri ON ri.inventory_id = i.id SET i.quantity = GREATEST(CAST(i.quantity AS SIGNED) - ri.quantity, 0) WHERE ri.requisition_id = ? AND i.is_countable = 1');
      $statement->bind_param('i', $id);
      $statement->execute();
      $statement = $conn->prepare('UPDATE tbl_inventory i INNER JOIN tbl_requisition_items ri ON ri.inventory_id = i.id SET i.location = ?, i.location_id = ? WHERE ri.requisition_id = ? AND i.is_countable = 0');
      $statement->bind_param('sii', $req['use_location'], $req['location_id'], $id);
      $statement->execute();
      set_flash('Requisition fulfilled. Countable stock was deducted and other items were moved to the requested location.');
    } elseif ($action === 'return' && $id) {
      $req = fetch_requisition($conn, $id);
      if (!$req || $req['status'] !== 'Fulfilled') {
        throw new InvalidArgumentException('Only fulfilled requisitions can be returned.');
      }
      if (!has_role('property_custodian', 'maintenance_personnel')) {
        throw new InvalidArgumentException('Only maintenance personnel or the custodian can return assets.');
      }
      if (!$req['borrow_until'] || $req['borrow_until'] > date('Y-m-d H:i:s')) {
        throw new InvalidArgumentException('The active period has not ended yet.');
      }
      $statement = $conn->prepare("UPDATE tbl_requisitions SET status = 'Returned', returned_by = ?, returned_at = NOW() WHERE id = ?");
      $statement->bind_param('ii', $userId, $id);
      $statement->execute();
      $statement = $conn->prepare('UPDATE tbl_inventory i INNER JOIN tbl_requisition_items ri ON ri.inventory_id = i.id SET i.quantity = COALESCE(i.quantity, 0) + GREATEST(CAST(ri.quantity AS SIGNED) - COALESCE((SELECT SUM(rp.quantity) FROM tbl_requisition_reports rp WHERE rp.requisition_id = ri.requisition_id AND rp.inventory_id = ri.inventory_id), 0), 0) WHERE ri.requisition_id = ? AND i.is_countable = 1');
      $statement->bind_param('i', $id);
      $statement->execute();
      $statement = $conn->prepare('UPDATE tbl_inventory i INNER JOIN tbl_requisition_items ri ON ri.inventory_id = i.id SET i.location = ri.origin_location, i.location_id = ri.origin_location_id WHERE ri.requisition_id = ? AND i.is_countable = 0 AND ri.origin_location IS NOT NULL');
      $statement->bind_param('i', $id);
      $statement->execute();
      set_flash('Requisition returned. Assets went back to their original location and countable stock was restored.');
    } elseif ($action === 'report' && $id) {
      $req = fetch_requisition($conn, $id);
      if (!$req || $req['status'] !== 'Fulfilled') {
        throw new InvalidArgumentException('Damage can only be reported while the assets are waiting to be returned.');
      }
      if (!has_role('property_custodian', 'maintenance_personnel')) {
        throw new InvalidArgumentException('Only maintenance personnel or the custodian can report damaged assets.');
      }
      $inventoryId = (int) filter_var($_POST['inventory_id'] ?? 0, FILTER_VALIDATE_INT);
      $type = $_POST['report_type'] ?? '';
      $method = trim($_POST['disposal_method'] ?? '');
      $notes = mb_substr(trim($_POST['notes'] ?? ''), 0, 500);
      if (!in_array($type, ['Repair', 'Disposal'], true)) {
        throw new InvalidArgumentException('Choose whether the asset needs repair or is for disposal.');
      }
      if ($type === 'Disposal' && !in_array($method, REPORT_DISPOSAL_METHODS, true)) {
        throw new InvalidArgumentException('Select a disposal method.');
      }
      $statement = $conn->prepare('SELECT i.id, i.is_countable, ri.quantity AS req_qty FROM tbl_requisition_items ri INNER JOIN tbl_inventory i ON i.id = ri.inventory_id WHERE ri.requisition_id = ? AND ri.inventory_id = ? FOR UPDATE');
      $statement->bind_param('ii', $id, $inventoryId);
      $statement->execute();
      $item = $statement->get_result()->fetch_assoc();
      if (!$item) {
        throw new InvalidArgumentException('That asset is not part of this requisition.');
      }
      $statement = $conn->prepare('SELECT 1 FROM tbl_requisition_reports WHERE requisition_id = ? AND inventory_id = ?');
      $statement->bind_param('ii', $id, $inventoryId);
      $statement->execute();
      if ($statement->get_result()->fetch_row()) {
        throw new InvalidArgumentException('This asset has already been reported.');
      }
      $countable = (int) $item['is_countable'] === 1;
      $qty = $countable ? (int) filter_var($_POST['quantity'] ?? 0, FILTER_VALIDATE_INT) : 1;
      if ($countable && ($qty < 1 || $qty > (int) $item['req_qty'])) {
        throw new InvalidArgumentException('Enter how many units are affected (up to ' . (int) $item['req_qty'] . ').');
      }
      $newStatus = $type === 'Repair' ? 'Under Maintenance' : 'Retired';
      $disposalMethod = $type === 'Disposal' ? $method : null;
      $targetId = $inventoryId;
      $splitId = null;
      if ($countable) {
        // Affected units become their own inventory record so the rest of the stock is untouched.
        $tag = next_asset_tag($conn);
        $statement = $conn->prepare("INSERT INTO tbl_inventory (asset_tag, asset_name, category, is_countable, quantity, date_acquired, acquisition_cost, location, location_id, custodian_id, approver_id, status, disposal_method, disposal_date) SELECT ?, asset_name, category, 1, ?, date_acquired, acquisition_cost, location, location_id, custodian_id, approver_id, ?, ?, IF(? = 'Retired', CURDATE(), NULL) FROM tbl_inventory WHERE id = ?");
        $statement->bind_param('sisssi', $tag, $qty, $newStatus, $disposalMethod, $newStatus, $inventoryId);
        $statement->execute();
        $targetId = (int) $conn->insert_id;
        $splitId = $targetId;
      } else {
        $statement = $conn->prepare("UPDATE tbl_inventory SET status = ?, disposal_method = ?, disposal_date = IF(? = 'Retired', CURDATE(), NULL) WHERE id = ?");
        $statement->bind_param('sssi', $newStatus, $disposalMethod, $newStatus, $inventoryId);
        $statement->execute();
      }
      if ($type === 'Repair') {
        $maintenanceNote = 'Reported while returning ' . $req['req_number'] . ($countable ? " ($qty unit" . ($qty === 1 ? '' : 's') . ')' : '') . ($notes !== '' ? ': ' . $notes : '');
        $statement = $conn->prepare("INSERT INTO tbl_maintenance (inventory_id, maintenance_type, due_date, status, notes, pulled_out_at) VALUES (?, 'Repair', CURDATE(), 'Pulled Out', ?, NOW())");
        $statement->bind_param('is', $targetId, $maintenanceNote);
        $statement->execute();
      }
      $statement = $conn->prepare('INSERT INTO tbl_requisition_reports (requisition_id, inventory_id, report_type, quantity, disposal_method, notes, split_inventory_id, reported_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
      $statement->bind_param('iisissii', $id, $inventoryId, $type, $qty, $disposalMethod, $notes, $splitId, $userId);
      $statement->execute();
      set_flash($type === 'Repair' ? 'Reported as needing repair. It was added to Maintenance as pulled out.' : 'Reported for disposal. It was moved to the Disposal records.');    } elseif ($action === 'cancel' && $id) {
      $req = fetch_requisition($conn, $id);
      $owns = $req && (int) $req['requester_id'] === $userId;
      if (!$req || (!$owns && current_role() !== 'admin') || !in_array($req['status'], ['Pending Approval', 'Pending Custodian'], true)) {
        throw new InvalidArgumentException('This requisition cannot be cancelled.');
      }
      $statement = $conn->prepare("UPDATE tbl_requisitions SET status = 'Cancelled' WHERE id = ?");
      $statement->bind_param('i', $id);
      $statement->execute();
      set_flash('Requisition cancelled.');
    } else {
      throw new InvalidArgumentException('Invalid requisition action.');
    }

    $conn->commit();
  } catch (Throwable $exception) {
    try { $conn->rollback(); } catch (Throwable $rollbackException) {}
    if (!($exception instanceof InvalidArgumentException)) {
      error_log('Requisition operation failed: ' . $exception->getMessage());
    }
    set_flash($exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The requisition could not be saved.', 'danger');
  }

  if (($_POST['return'] ?? '') === 'job-orders' && can_access('joborders')) {
    $failed = (($_SESSION['flash']['type'] ?? '') === 'danger');
    $tabs = ['todo' => 'return', 'return' => 'return'];
    $from = (string) ($_POST['tab'] ?? 'todo');
    $to = isset($tabs[$from]) ? ($failed ? $from : $tabs[$from]) : 'todo';
    header('Location: ' . APP_BASE_URL . '/pages/job-orders.php' . ($to === 'todo' ? '' : '?tab=' . $to));
    exit();
  }
  $backTo = $_GET['status'] ?? '';
  header('Location: ' . APP_BASE_URL . '/pages/requisitions.php' . (in_array($backTo, REQUISITION_STATUSES, true) ? '?status=' . urlencode($backTo) : ''));
  exit();
}

$canRequest = has_role('teacher', 'department_head', 'approver', 'property_custodian');
$items = $conn->query("SELECT i.asset_name, MIN(i.category) AS category, SUM(" . STOCK_DISPLAY_EXPR . ") AS available FROM tbl_inventory i
  WHERE i.status = 'Active'
  GROUP BY i.asset_name HAVING available > 0 ORDER BY i.asset_name")->fetch_all(MYSQLI_ASSOC);
$locations = $conn->query('SELECT id, department, building, room_number FROM tbl_locations ORDER BY department, building, room_number')->fetch_all(MYSQLI_ASSOC);

$listSql = "SELECT r.*, u.full_name AS requester, rv.full_name AS reviewer, pr.full_name AS processor, fu.full_name AS fulfiller,
    " . sprintf(ASSIGNED_SQL, 'approver_id') . " AS approver_ok, " . sprintf(ASSIGNED_SQL, 'custodian_id') . " AS custodian_ok,
    (SELECT GROUP_CONCAT(DISTINCT CONCAT(cu.id, '|', cu.full_name) ORDER BY cu.full_name SEPARATOR '||') FROM tbl_requisition_lines l INNER JOIN tbl_inventory a ON a.asset_name = l.item_name INNER JOIN tbl_users cu ON cu.id = a.custodian_id WHERE l.requisition_id = r.id) AS assigned_custodians,
    (SELECT GROUP_CONCAT(CONCAT(inv.asset_tag, IF(inv.is_countable = 1, CONCAT(' ×', ri.quantity), '')) ORDER BY inv.asset_tag SEPARATOR ', ') FROM tbl_requisition_items ri INNER JOIN tbl_inventory inv ON inv.id = ri.inventory_id WHERE ri.requisition_id = r.id) AS asset_tags
  FROM tbl_requisitions r INNER JOIN tbl_users u ON u.id = r.requester_id LEFT JOIN tbl_users rv ON rv.id = r.reviewed_by LEFT JOIN tbl_users pr ON pr.id = r.processed_by LEFT JOIN tbl_users fu ON fu.id = r.fulfilled_by";
$statement = $conn->prepare($listSql . ' ORDER BY r.id DESC');
$statement->bind_param('ii', $userId, $userId);
$statement->execute();
$requisitions = $statement->get_result()->fetch_all(MYSQLI_ASSOC);

// Show a requisition to its requester, to the approver/custodian assigned to the item, and to
// maintenance staff (who prepare job orders). Admins see everything.
$role = current_role();
$lineStatement = $conn->prepare("SELECT l.*, d.full_name AS decider,
    (SELECT GROUP_CONCAT(DISTINCT CONCAT(ap.id, '|', ap.full_name) ORDER BY ap.full_name SEPARATOR '||') FROM tbl_inventory a INNER JOIN tbl_users ap ON ap.id = a.approver_id WHERE a.asset_name = l.item_name) AS approvers,
    " . LINE_APPROVER_SQL . " AS mine
  FROM tbl_requisition_lines l LEFT JOIN tbl_users d ON d.id = l.decided_by ORDER BY l.id");
$lineStatement->bind_param('i', $userId);
$lineStatement->execute();
$linesByRequisition = [];
foreach ($lineStatement->get_result()->fetch_all(MYSQLI_ASSOC) as $lineRow) {
  $lineRow['mine'] = $role === 'admin' || (int) $lineRow['mine'] === 1;
  $linesByRequisition[$lineRow['requisition_id']][] = $lineRow;
}
foreach ($requisitions as &$row) {
  $row['approver_ok'] = $role === 'admin' || (int) $row['approver_ok'] === 1;
  $row['custodian_ok'] = $role === 'admin' || (int) $row['custodian_ok'] === 1;
  $row['lines'] = $linesByRequisition[$row['id']] ?? [];
  // Approvers still expected to decide: those of the items that are still pending.
  $pendingApprovers = [];
  foreach ($row['lines'] as $lineRow) {
    if ($lineRow['approval_status'] === 'Pending' && $lineRow['approvers']) {
      foreach (explode('||', $lineRow['approvers']) as $pair) {
        $pendingApprovers[(int) explode('|', $pair, 2)[0]] = $pair;
      }
    }
  }
  $row['assigned_approvers'] = implode('||', $pendingApprovers);
}
unset($row);
$requisitions = array_values(array_filter($requisitions, function ($r) use ($role, $userId) {
  return $role === 'admin'
    || (int) $r['requester_id'] === $userId
    || $role === 'maintenance_personnel'
    || (in_array($role, ['approver', 'department_head'], true) && $r['approver_ok'])
    || ($role === 'property_custodian' && ($r['custodian_ok'] || (int) $r['processed_by'] === $userId));
}));

$statusCounts = array_count_values(array_column($requisitions, 'status'));
$activeStatus = $_GET['status'] ?? '';
if (!in_array($activeStatus, REQUISITION_STATUSES, true)) {
  $activeStatus = '';
}
$totalRequisitions = count($requisitions);
$reviewModals = '';
if ($activeStatus !== '') {
  $requisitions = array_values(array_filter($requisitions, fn($r) => $r['status'] === $activeStatus));
}

$badges = [
  'Pending Approval' => 'bg-warning text-dark', 'Pending Custodian' => 'bg-info text-dark',
  'Awaiting Procurement' => 'bg-primary', 'Ready for Fulfillment' => 'bg-success',
  'Fulfilled' => 'bg-secondary', 'Returned' => 'bg-info', 'Rejected' => 'bg-danger', 'Cancelled' => 'bg-dark',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <base href="<?= APP_BASE_URL ?>/">
  <title>Requisitions - NiceAdmin Template</title>
  <link href="assets/img/favicon.png" rel="icon">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Nunito:300,400,600,700|Poppins:300,400,500,600,700" rel="stylesheet">
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
  <link href="assets/css/responsive.css" rel="stylesheet">
  <script src="assets/js/responsive.js" defer></script>
  <style>
    .nav-tabs-custom .nav-link { color: #6c757d; font-weight: 500; white-space: nowrap; }
    .nav-tabs-custom .nav-link.active { color: #0d6efd; border-bottom: 2px solid #0d6efd; font-weight: 600; }
    #reqTable { border-collapse: separate; border-spacing: 0; }
    #reqTable thead th { background: #f6f9ff; color: #012970; font-size: .75rem; letter-spacing: .04em; text-transform: uppercase; border-bottom: 2px solid #bcd0f7; padding: 12px; white-space: nowrap; }
    #reqTable tbody td { padding: 14px 12px; border-bottom: 1px solid #e9ecef; vertical-align: top; }
    #reqTable tbody tr:hover > td { background: #f8fbff; }
    #reqTable tbody td:first-child { border-left: 4px solid var(--req-accent, #adb5bd); }
    #reqTable .req-item { display: flex; align-items: center; gap: .5rem; padding: 3px 0; }
    #reqTable .req-loc { display: flex; align-items: center; gap: .6rem; background: #f1f6ff; border: 1px solid #d3e3ff; border-radius: .6rem; padding: .5rem .75rem; min-width: 11rem; }
    #reqTable .req-loc-icon { flex: 0 0 auto; width: 2rem; height: 2rem; border-radius: 50%; background: #0d6efd; color: #fff; display: flex; align-items: center; justify-content: center; font-size: .9rem; }
    #reqTable .req-loc-room { font-weight: 700; color: #0a3d91; line-height: 1.2; }
    #reqTable .req-loc-sub { font-size: .75rem; color: #6c757d; line-height: 1.3; margin-top: .1rem; }
    #reqTable .req-period { display: inline-flex; flex-direction: column; gap: .3rem; background: #fff8e6; border: 1px solid #ffe29a; border-radius: .5rem; padding: 6px 10px; font-size: .78rem; color: #4d3a00; white-space: nowrap; }
    #reqTable .req-period-row { display: flex; align-items: center; gap: .4rem; }
    #reqTable .req-period .req-until-label { width: 2.6rem; color: #7a5a00; }
    #reqTable .req-period .req-until-date { font-weight: 700; }
    #reqTable .req-period .req-until-time { background: #ffe29a; border-radius: 999px; padding: 0 7px; font-weight: 600; }
    #reqTable .req-period.overdue { background: #fdecea; border-color: #f5b5ae; color: #842029; }
    #reqTable .req-period.overdue .req-until-date, #reqTable .req-period.overdue .req-until-label { color: #842029; }
    #reqTable .req-period.overdue .req-until-time { background: #f5b5ae; }
    #reqTable .req-period .req-until-flag { align-self: flex-start; background: #dc3545; color: #fff; border-radius: 999px; padding: 0 7px; font-size: .65rem; font-weight: 700; text-transform: uppercase; }
    #reqTable .req-until { display: inline-flex; align-items: center; flex-wrap: wrap; gap: .35rem; background: #fff8e6; border: 1px solid #ffe29a; color: #7a5a00; border-radius: .5rem; padding: 3px 8px; font-size: .78rem; }
    #reqTable .req-until i { color: #e0a800; }
    #reqTable .req-until-label { text-transform: uppercase; letter-spacing: .04em; font-size: .65rem; font-weight: 700; opacity: .8; }
    #reqTable .req-until-date { font-weight: 700; color: #4d3a00; }
    #reqTable .req-until-time { background: #ffe29a; border-radius: 999px; padding: 0 7px; font-weight: 600; }
    #reqTable .req-until.overdue { background: #fdecea; border-color: #f5b5ae; color: #842029; }
    #reqTable .req-until.overdue i, #reqTable .req-until.overdue .req-until-date { color: #b02a37; }
    #reqTable .req-until-flag { background: #dc3545; color: #fff; border-radius: 999px; padding: 0 7px; font-size: .65rem; font-weight: 700; text-transform: uppercase; }
    #reqTable .req-qty { background: #e7f1ff; color: #0d6efd; border-radius: 999px; font-size: .75rem; font-weight: 600; padding: 1px 8px; }
    #reqTable .req-handling { border-left: 2px solid #dee2e6; padding-left: .6rem; }
    .shop-card { cursor: pointer; transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease; user-select: none; }
    .shop-card:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(13, 110, 253, .18) !important; border-color: #0d6efd !important; }
    .shop-card:active { transform: translateY(0) scale(.98); }
    .shop-card:focus-visible { outline: 3px solid rgba(13, 110, 253, .45); outline-offset: 2px; }
    .shop-card.selected { background: #f4f8ff; }
    .shop-card .shop-remove { position: absolute; top: .5rem; left: .5rem; width: 26px; height: 26px; padding: 0; line-height: 1; border-radius: 50%; z-index: 2; }
  </style>
</head>

<body>
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

  <main id="main" class="main">
    <div class="container-fluid">

      <div class="pagetitle d-flex justify-content-between align-items-center">
        <div>
          <h1>Requisitions</h1>
          <nav>
            <ol class="breadcrumb">
              <li class="breadcrumb-item"><a href="pages/dashboard.php">Home</a></li>
              <li class="breadcrumb-item active">Requisitions</li>
            </ol>
          </nav>
        </div>
        <?php if ($canRequest): ?>
          <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestModal"><i class="bi bi-plus-lg me-1"></i> New Requisition</button>
        <?php endif; ?>
      </div>

      <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
          <?= h($flash['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <section class="section">
        <div class="card overflow-auto">
          <div class="card-body pt-3">
            <h5 class="card-title fw-bold"><?= $sawAll ? 'Requisitions assigned to you' : 'My requisitions' ?></h5>
            <ul class="nav nav-tabs nav-tabs-custom border-0 flex-nowrap overflow-auto mb-3">
              <li class="nav-item"><a class="nav-link <?= $activeStatus === '' ? 'active' : '' ?>" href="pages/requisitions.php">All <span class="badge rounded-pill bg-light text-dark border"><?= $totalRequisitions ?></span></a></li>
              <?php foreach (REQUISITION_STATUSES as $tabStatus): ?>
                <li class="nav-item"><a class="nav-link <?= $activeStatus === $tabStatus ? 'active' : '' ?>" href="pages/requisitions.php?status=<?= h(urlencode($tabStatus)) ?>"><?= h($tabStatus) ?> <span class="badge rounded-pill <?= h($badges[$tabStatus] ?? 'bg-secondary') ?>"><?= (int) ($statusCounts[$tabStatus] ?? 0) ?></span></a></li>
              <?php endforeach; ?>
            </ul>
            <div class="input-group mb-3">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input type="search" class="form-control" id="reqSearch" placeholder="Search requisitions by number, requester, item, location, status or name..." autocomplete="off">
            </div>
            <table class="table mb-0" id="reqTable">
              <thead>
                <tr>
                  <th scope="col">Requisition</th>
                  <th scope="col">Items</th>
                  <th scope="col">Use location</th>
                  <th scope="col">Active period</th>
                  <th scope="col">Status &amp; handling</th>
                  <th scope="col" class="text-center">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($requisitions): ?>
                  <?php foreach ($requisitions as $req): ?>
                    <?php $status = $req['status']; ?>
                    <tr>
                      <?php
                        $pendingMine = array_filter($req['lines'], fn($l) => $l['approval_status'] === 'Pending' && $l['mine']);
                        $canReview = $status === 'Pending Approval' && has_role('approver', 'department_head') && $pendingMine;
                        if ($canReview) {
                          ob_start(); ?>
                          <div class="modal fade" id="review<?= (int) $req['id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                              <div class="modal-content">
                                <div class="modal-header"><h5 class="modal-title">Review <?= h($req['req_number']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                                <div class="modal-body">
                                  <p class="text-muted small mb-3">Requested by <strong><?= h($req['requester']) ?></strong> for <?= h($req['use_location']) ?><?= $req['borrow_until'] ? ', active ' . ($req['borrow_from'] ? 'from ' . h(date('M j, Y g:i A', strtotime($req['borrow_from']))) . ' ' : '') . 'until ' . h(date('M j, Y g:i A', strtotime($req['borrow_until']))) : '' ?>. You can only decide the items assigned to you.</p>
                                  <ul class="list-group">
                                    <?php foreach ($req['lines'] as $line): ?>
                                      <li class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                          <div>
                                            <div class="fw-semibold"><?= h($line['item_name']) ?> <span class="text-muted fw-normal">×<?= (int) $line['quantity'] ?></span></div>
                                            <div class="small text-muted"><i class="bi bi-person-check"></i> <?= $line['approvers'] ? assigned_names($line['approvers'], $userId) : 'Any approver' ?></div>
                                          </div>
                                          <?php if ($line['approval_status'] === 'Approved'): ?>
                                            <span class="badge bg-success">Approved</span>
                                          <?php elseif ($line['approval_status'] === 'Rejected'): ?>
                                            <span class="badge bg-danger">Rejected</span>
                                          <?php elseif (!$line['mine']): ?>
                                            <span class="badge bg-light text-dark border">Waiting for another approver</span>
                                          <?php endif; ?>
                                        </div>
                                        <?php if ($line['approval_status'] === 'Pending' && $line['mine']): ?>
                                          <form method="post" class="d-flex gap-2 mt-2">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                                            <input type="hidden" name="line_id" value="<?= (int) $line['id'] ?>">
                                            <input class="form-control form-control-sm" name="note" maxlength="255" placeholder="Note (required when rejecting)">
                                            <button class="btn btn-success btn-sm" name="action" value="approve">Approve</button>
                                            <button class="btn btn-outline-danger btn-sm" name="action" value="reject">Reject</button>
                                          </form>
                                        <?php elseif ($line['decider']): ?>
                                          <div class="small text-muted mt-1">by <?= h($line['decider']) ?><?= you_badge((int) $line['decided_by'] === $userId) ?><?= $line['decision_note'] ? ' — ' . h($line['decision_note']) : '' ?></div>
                                        <?php endif; ?>
                                      </li>
                                    <?php endforeach; ?>
                                  </ul>
                                </div>
                              </div>
                            </div>
                          </div>
                          <?php $reviewModals .= ob_get_clean();
                        }
                      ?>
                      <?php
                        $accentColors = ['Pending Approval' => '#ffc107', 'Pending Custodian' => '#0dcaf0', 'Awaiting Procurement' => '#fd7e14', 'Ready for Fulfillment' => '#0d6efd', 'Fulfilled' => '#198754', 'Returned' => '#0dcaf0', 'Rejected' => '#dc3545', 'Cancelled' => '#6c757d'];
                      ?>
                      <td style="--req-accent: <?= $accentColors[$status] ?? '#adb5bd' ?>">
                        <div class="fw-bold text-primary"><?= h($req['req_number']) ?></div>
                        <div class="small text-muted"><i class="bi bi-calendar3 me-1"></i><?= h(date('M j, Y · g:i A', strtotime((string) $req['created_at']))) ?></div>
                        <div class="small mt-1"><i class="bi bi-person-fill text-muted me-1"></i><span class="fw-semibold"><?= h($req['requester']) ?></span></div>
                      </td>
                      <td>
                        <?php foreach ($req['lines'] as $line): ?>
                          <?php $lineStatus = $line['approval_status']; ?>
                          <div class="req-item">
                            <?php if ($lineStatus === 'Rejected'): ?>
                              <i class="bi bi-x-circle text-danger" title="Rejected<?= $line['decider'] ? ' by ' . h($line['decider']) : '' ?>"></i>
                            <?php elseif ($status === 'Pending Approval' && $lineStatus === 'Pending'): ?>
                              <i class="bi bi-hourglass-split text-warning" title="Awaiting approval"></i>
                            <?php else: ?>
                              <i class="bi bi-check-circle text-success" title="Approved"></i>
                            <?php endif; ?>
                            <span class="<?= $lineStatus === 'Rejected' ? 'text-decoration-line-through text-muted' : '' ?>"><?= h($line['item_name']) ?></span><span class="req-qty">×<?= (int) $line['quantity'] ?></span>
                          </div>
                        <?php endforeach; ?>
                        <?php if ($req['asset_tags']): ?><div class="small font-monospace text-muted mt-1"><?= h($req['asset_tags']) ?></div><?php endif; ?>
                        <?php if ($req['purpose']): ?><div class="small text-muted fst-italic mt-1"><?= h($req['purpose']) ?></div><?php endif; ?>
                      </td>
                      <td>
                        <?php $locParts = array_map('trim', explode(' / ', (string) $req['use_location'])); $locRoom = count($locParts) > 1 ? array_pop($locParts) : $req['use_location']; ?>
                        <div class="req-loc">
                          <div class="req-loc-icon"><i class="bi bi-geo-alt-fill"></i></div>
                          <div>
                            <div class="req-loc-room"><?= h($locRoom) ?></div>
                            <?php if ($locParts && count($locParts) >= 1 && $locRoom !== $req['use_location']): ?><div class="req-loc-sub"><?= h(implode(' · ', $locParts)) ?></div><?php endif; ?>
                          </div>
                        </div>
                      </td>
                      <td>
                        <?php if ($req['borrow_until']): ?>
                          <?php $overdue = $req['borrow_until'] < date('Y-m-d H:i:s') && $status === 'Fulfilled'; ?>
                          <div class="req-period <?= $overdue ? 'overdue' : '' ?>">
                            <?php if ($req['borrow_from']): ?><div class="req-period-row"><span class="req-until-label">From</span>
<span class="req-until-date"><?= h(date('M j, Y', strtotime($req['borrow_from']))) ?></span><span class="req-until-time"><?= h(date('g:i A', strtotime($req['borrow_from']))) ?></span>
</div><?php endif; ?>
                            <div class="req-period-row"><span class="req-until-label"><?= $req['borrow_from'] ? 'To' : 'Until' ?></span>
<span class="req-until-date"><?= h(date('M j, Y', strtotime($req['borrow_until']))) ?></span><span class="req-until-time"><?= h(date('g:i A', strtotime($req['borrow_until']))) ?></span>
</div>
                            <?php if ($overdue): ?><span class="req-until-flag">Overdue</span><?php endif; ?>
                          </div>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                      </td>
                      <td>
                        <span class="badge rounded-pill <?= h($badges[$status] ?? 'bg-secondary') ?>"><?= h($status) ?></span>
                        <?php if ($req['job_order_number']): ?><div class="small mt-1"><i class="bi bi-file-earmark-text"></i> <?= h($req['job_order_number']) ?></div><?php endif; ?>
                        <?php if ($req['review_note']): ?><div class="small text-muted mt-1"><?= h($req['review_note']) ?></div><?php endif; ?>
                        <div class="req-handling small text-muted lh-sm mt-2">
                          <?php if ($req['reviewer']): ?>
                            <?php $rejected = $status === 'Rejected'; ?>
                            <div><i class="bi <?= $rejected ? 'bi-x-circle text-danger' : 'bi-check-circle text-success' ?>"></i> <?= $rejected ? 'Rejected' : 'Approved' ?> by <strong class="text-body"><?= h($req['reviewer']) ?></strong><?= you_badge((int) $req['reviewed_by'] === $userId) ?> · <?= h(date('M j, g:i A', strtotime((string) $req['reviewed_at']))) ?></div>
                          <?php elseif ($status === 'Pending Approval'): ?>
                            <div><i class="bi bi-hourglass-split"></i> Awaiting approver<?= $req['assigned_approvers'] ? ': <strong class="text-body">' . assigned_names($req['assigned_approvers'], $userId) . '</strong>' : '' ?></div>
                          <?php endif; ?>
                          <?php if ($req['processor']): ?>
                            <div class="mt-1"><i class="bi bi-person-check"></i> Processed by <strong class="text-body"><?= h($req['processor']) ?></strong><?= you_badge((int) $req['processed_by'] === $userId) ?> · <?= h(date('M j, g:i A', strtotime((string) $req['processed_at']))) ?></div>
                          <?php elseif (in_array($status, ['Pending Custodian', 'Awaiting Procurement'], true)): ?>
                            <div class="mt-1"><i class="bi bi-hourglass-split"></i> Awaiting custodian<?= $req['assigned_custodians'] ? ': <strong class="text-body">' . assigned_names($req['assigned_custodians'], $userId) . '</strong>' : '' ?></div>
                          <?php endif; ?>
                          <?php if ($req['fulfilled_at']): ?>
                            <div class="mt-1"><i class="bi bi-box-seam text-success"></i> Fulfilled<?= $req['fulfiller'] ? ' &amp; validated by <strong class="text-body">' . h($req['fulfiller']) . '</strong>' . you_badge((int) $req['fulfilled_by'] === $userId) : '' ?> · <?= h(date('M j, Y, g:i A', strtotime((string) $req['fulfilled_at']))) ?></div>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td class="text-center">
                        <div class="d-inline-flex gap-2 flex-wrap justify-content-center">
                          <?php if ($canReview): ?>
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#review<?= (int) $req['id'] ?>"><i class="bi bi-clipboard-check"></i> Review</button>
                          <?php endif; ?>                          <?php if ($status === 'Pending Custodian' && has_role('property_custodian') && $req['custodian_ok']): ?>
                            <form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="id" value="<?= (int) $req['id'] ?>"><button class="btn btn-primary btn-sm" name="action" value="process" title="Check availability and process">Process</button></form>
                          <?php endif; ?>
                          <?php if ($status === 'Ready for Fulfillment'): ?>
                            <a class="btn btn-outline-primary btn-sm" target="_blank" href="pages/job-order.php?id=<?= (int) $req['id'] ?>" title="Print job order"><i class="bi bi-printer"></i></a>
                            <?php if (can_fulfill($req, $userId)): ?>
                              <form method="post" class="d-inline confirm-form" data-icon="bi-box-seam text-success" data-title="Mark as fulfilled?" data-message="<?= h($req['req_number']) ?> will be marked fulfilled and its items moved to <?= h($req['use_location']) ?>." data-confirm="Mark fulfilled" data-style="btn-success"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="id" value="<?= (int) $req['id'] ?>"><button class="btn btn-outline-success btn-sm" name="action" value="fulfill" title="Mark fulfilled"><i class="bi bi-box-seam"></i></button></form>
                            <?php endif; ?>
                          <?php elseif ($status === 'Fulfilled'): ?>
                            <a class="btn btn-outline-secondary btn-sm" target="_blank" href="pages/job-order.php?id=<?= (int) $req['id'] ?>" title="View job order"><i class="bi bi-printer"></i></a>
                          <?php endif; ?>
                          <?php if (in_array($status, ['Pending Approval', 'Pending Custodian'], true) && ((int) $req['requester_id'] === $userId || current_role() === 'admin')): ?>
                            <form method="post" class="d-inline confirm-form" data-icon="bi-slash-circle text-danger" data-title="Cancel this requisition?" data-message="<?= h($req['req_number']) ?> will be cancelled. This can't be undone." data-confirm="Cancel requisition" data-style="btn-danger"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="id" value="<?= (int) $req['id'] ?>"><button class="btn btn-outline-secondary btn-sm" name="action" value="cancel" title="Cancel"><i class="bi bi-slash-circle"></i></button></form>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr><td colspan="6" class="text-center text-muted py-4">No requisitions<?= $activeStatus !== '' ? ' with this status' : ' yet' ?>.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
            <?= $reviewModals ?>
            <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content text-center">
                  <div class="modal-body p-4">
                    <i class="bi fs-1 d-block mb-2" id="confirmIcon"></i>
                    <h5 class="mb-2" id="confirmTitle"></h5>
                    <p class="text-muted small mb-4" id="confirmMessage"></p>
                    <div class="d-flex gap-2 justify-content-center">
                      <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Go back</button>
                      <button type="button" class="btn" id="confirmOk"></button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <?php if ($canRequest): ?>
      <div class="modal fade" id="requestModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
          <div class="modal-content">
            <form method="post" action="pages/requisitions.php" id="requestForm">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <input type="hidden" name="action" value="create">
              <div id="cartInputs"></div>
              <div class="modal-header"><h5 class="modal-title">New Requisition</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
              <div class="modal-body bg-light">
                <div class="row g-4">
                  <div class="col-lg-8">
                    <div class="input-group mb-2">
                      <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                      <input type="search" class="form-control" id="shopSearch" placeholder="Search items..." autocomplete="off">
                    </div>
                    <?php $shopCategories = array_values(array_unique(array_column($items, 'category'))); sort($shopCategories); ?>
                    <div class="d-flex flex-wrap gap-2 mb-3" id="shopCategories">
                      <button type="button" class="btn btn-sm btn-primary rounded-pill" data-category="">All</button>
                      <?php foreach ($shopCategories as $shopCategory): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-category="<?= h($shopCategory) ?>"><?= h($shopCategory) ?></button>
                      <?php endforeach; ?>
                    </div>
                    <div style="max-height:52vh;overflow-y:auto;overflow-x:hidden" class="pe-1">
                      <div class="row row-cols-2 row-cols-md-3 g-3" id="shopGrid">
                        <?php foreach ($items as $item): ?>
                          <?php
                            $categoryKey = strtolower((string) $item['category']);
                            $tileIcon = str_contains($categoryKey, 'furniture') ? 'bi-lamp' : (str_contains($categoryKey, 'fixture') ? 'bi-lightbulb' : (str_contains($categoryKey, 'equipment') ? 'bi-pc-display' : 'bi-box-seam'));
                          ?>
                          <div class="col shop-tile" data-name="<?= h($item['asset_name']) ?>" data-category="<?= h($item['category']) ?>" data-stock="<?= (int) $item['available'] ?>">
                            <div class="card h-100 shadow-sm border-2 shop-card" role="button" tabindex="0">
                              <div class="position-relative text-center py-4 bg-white rounded-top">
                                <button type="button" class="btn btn-danger btn-sm shop-remove d-none" title="Remove from request" aria-label="Remove <?= h($item['asset_name']) ?> from request"><i class="bi bi-x-lg"></i></button>
                                <i class="bi <?= h($tileIcon) ?> text-primary" style="font-size:2.5rem"></i>
                                <span class="badge bg-primary position-absolute top-0 end-0 m-2 d-none shop-qty"></span>
                              </div>
                              <div class="card-body p-3 d-flex flex-column">
                                <div class="fw-semibold small lh-sm mb-1"><?= h($item['asset_name']) ?></div>
                                <div class="small text-muted mb-2"><?= h($item['category']) ?></div>
                                <div class="mt-auto d-flex justify-content-between align-items-center">
                                  <span class="badge bg-success-subtle text-success-emphasis"><?= (int) $item['available'] ?> in stock</span>
                                  <span class="small text-primary fw-semibold shop-action"><i class="bi bi-plus-circle"></i> Add</span>
                                </div>
                              </div>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                      <div class="text-center text-muted py-5 <?= $items ? 'd-none' : '' ?>" id="shopEmpty"><?= $items ? 'No items match your search.' : 'No items are currently available from stock.' ?></div>
                    </div>
                  </div>

                  <div class="col-lg-4">
                    <div class="card shadow-sm">
                      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-cart3"></i> Your request</span>
                        <span class="badge bg-primary rounded-pill" id="cartCount">0</span>
                      </div>
                      <div class="card-body">
                        <div id="cartList" style="max-height:26vh;overflow-y:auto"></div>
                        <div class="text-muted small text-center py-3" id="cartEmpty">Click an item to add it to your request.</div>
                        <div class="d-flex justify-content-between fw-semibold border-top pt-2 mt-2 mb-3"><span>Total quantity</span><span id="cartTotal">0</span></div>
                        <div class="mb-3 position-relative">
                          <label class="form-label fw-semibold small mb-1" for="reqLocationText"><i class="bi bi-geo-alt-fill text-primary me-1"></i>Where it will be used</label>
                          <?php $locDepartments = array_values(array_unique(array_column($locations, 'department'))); ?>
                          <div class="d-flex flex-wrap gap-1 mb-2" id="reqLocDepts">
                            <button type="button" class="btn btn-sm py-0 px-2 rounded-pill btn-primary" data-dept="">All</button>
                            <?php foreach ($locDepartments as $locDept): ?>
                              <button type="button" class="btn btn-sm py-0 px-2 rounded-pill btn-outline-secondary" data-dept="<?= h($locDept) ?>"><?= h($locDept) ?></button>
                            <?php endforeach; ?>
                          </div>
                          <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" id="reqLocationText" placeholder="Search room, building or department..." autocomplete="off" required>
                            <button type="button" class="btn btn-outline-secondary d-none" id="reqLocationClear" title="Clear location" aria-label="Clear location"><i class="bi bi-x-lg"></i></button>
                          </div>
                          <input type="hidden" name="location_id" id="reqLocation">
                          <div class="list-group position-absolute w-100 shadow d-none" id="reqLocationList" style="z-index:1060;max-height:220px;overflow-y:auto"></div>
                          <div class="small text-success mt-1 d-none" id="reqLocationOk"><i class="bi bi-check-circle-fill me-1"></i><span></span></div>
                        </div>
                        <div class="mb-3">
                          <label class="form-label fw-semibold small mb-1"><i class="bi bi-calendar-event text-primary me-1"></i>Active period</label>
                          <div class="row g-2">
                            <div class="col-6"><div class="form-text mt-0 mb-1">Active from</div><input type="datetime-local" class="form-control form-control-sm" id="reqActiveFrom" name="borrow_from" min="<?= date('Y-m-d\TH:i') ?>" required></div>
                            <div class="col-6"><div class="form-text mt-0 mb-1">to</div><input type="datetime-local" class="form-control form-control-sm" id="reqBorrowUntil" name="borrow_until" min="<?= date('Y-m-d\TH:i') ?>" required></div>
                          </div>
                          <div class="d-flex flex-wrap gap-1 mt-2" id="reqBorrowQuick">
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 rounded-pill" data-days="0">Today</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 rounded-pill" data-days="1">Tomorrow</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 rounded-pill" data-days="7">1 week</button>
                          </div>
                        </div>
                        <div><label class="form-label fw-semibold small mb-1" for="reqPurpose">Purpose <span class="text-muted fw-normal">(optional)</span></label><input class="form-control form-control-sm" id="reqPurpose" name="purpose" maxlength="255"></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="modal-footer"><span class="text-danger small me-auto d-none" id="cartError">Add at least one item to your request.</span><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Submit Requisition</button></div>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </main>

  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
  <script src="assets/js/table-sort.js"></script>
  <script>
    (() => {
      const confirmEl = document.getElementById('confirmModal');
      if (confirmEl) {
        const confirmModal = new bootstrap.Modal(confirmEl);
        let pending = null;
        document.querySelectorAll('.confirm-form').forEach((form) => {
          form.addEventListener('submit', (e) => {
            if (form.dataset.confirmed) return;
            e.preventDefault();
            pending = { form, submitter: e.submitter };
            document.getElementById('confirmIcon').className = 'bi fs-1 d-block mb-2 ' + form.dataset.icon;
            document.getElementById('confirmTitle').textContent = form.dataset.title;
            document.getElementById('confirmMessage').textContent = form.dataset.message;
            const ok = document.getElementById('confirmOk');
            ok.className = 'btn ' + form.dataset.style;
            ok.textContent = form.dataset.confirm;
            confirmModal.show();
          });
        });
        document.getElementById('confirmOk').addEventListener('click', () => {
          if (!pending) return;
          pending.form.dataset.confirmed = '1';
          pending.form.requestSubmit(pending.submitter);
        });
      }
      const locInput = document.getElementById('reqLocationText');
      if (locInput) {
        const locations = <?= json_encode(array_map(fn($l) => ['id' => (int) $l['id'], 'text' => location_text($l), 'dept' => $l['department'], 'building' => $l['building'], 'room' => $l['room_number']], $locations), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const locId = document.getElementById('reqLocation');
        const locBox = document.getElementById('reqLocationList');
        const locClear = document.getElementById('reqLocationClear');
        const locOk = document.getElementById('reqLocationOk');
        const locDepts = document.getElementById('reqLocDepts');
        let locDept = '';
        let locActive = -1;
        const syncLocation = () => {
          const match = locations.find((l) => l.text.toLowerCase() === locInput.value.trim().toLowerCase());
          locId.value = match ? match.id : '';
          locInput.setCustomValidity(locInput.value.trim() !== '' && !match ? 'Choose a location from the list.' : '');
          locInput.classList.toggle('is-valid', !!match);
          locClear.classList.toggle('d-none', locInput.value === '');
          locOk.classList.toggle('d-none', !match);
          if (match) locOk.querySelector('span').textContent = match.text;
        };
        const showLocations = () => {
          const term = locInput.value.trim().toLowerCase();
          const matches = locations.filter((l) => (!locDept || l.dept === locDept) && l.text.toLowerCase().includes(term)).slice(0, 50);
          locBox.innerHTML = '';
          locActive = -1;
          if (!matches.length) locBox.innerHTML = '<div class="list-group-item small text-muted">No matching locations</div>';
          matches.forEach((l) => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'list-group-item list-group-item-action py-2 d-flex align-items-center gap-2';
            row.dataset.text = l.text;
            row.innerHTML = '<span class="rounded bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:30px;height:30px"><i class="bi bi-door-open"></i></span>'
              + '<span class="lh-sm" style="min-width:0"><span class="d-block fw-semibold small loc-room"></span><span class="d-block text-muted loc-where" style="font-size:.72rem"></span></span>';
            row.querySelector('.loc-room').textContent = 'Room ' + l.room;
            row.querySelector('.loc-where').textContent = l.dept + ' · ' + l.building;
            locBox.appendChild(row);
          });
          locBox.classList.remove('d-none');
        };
        const pickLocation = (text) => { locInput.value = text; locBox.classList.add('d-none'); syncLocation(); };
        locClear.addEventListener('click', () => { locInput.value = ''; syncLocation(); locInput.focus(); showLocations(); });
        locDepts.addEventListener('click', (e) => {
          const chip = e.target.closest('button[data-dept]');
          if (!chip) return;
          locDept = chip.dataset.dept;
          locDepts.querySelectorAll('button').forEach((b) => {
            const active = b === chip;
            b.classList.toggle('btn-primary', active);
            b.classList.toggle('btn-outline-secondary', !active);
          });
          locInput.focus();
          showLocations();
        });
        locInput.addEventListener('focus', showLocations);
        locInput.addEventListener('input', () => { syncLocation(); showLocations(); });
        locBox.addEventListener('mousedown', (e) => {
          const row = e.target.closest('button');
          if (row) { e.preventDefault(); pickLocation(row.dataset.text); }
        });
        locInput.addEventListener('keydown', (e) => {
          const rows = [...locBox.querySelectorAll('button')];
          if (locBox.classList.contains('d-none') || !rows.length) return;
          if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            locActive = (locActive + (e.key === 'ArrowDown' ? 1 : -1) + rows.length) % rows.length;
            rows.forEach((r, i) => r.classList.toggle('active', i === locActive));
            rows[locActive].scrollIntoView({ block: 'nearest' });
          } else if (e.key === 'Enter') {
            e.preventDefault();
            pickLocation((rows[locActive] || rows[0]).dataset.text);
          } else if (e.key === 'Escape') {
            e.stopPropagation();
            locBox.classList.add('d-none');
          }
        });
        document.addEventListener('click', (e) => { if (e.target !== locInput && !e.target.closest('#reqLocDepts, #reqLocationClear')) locBox.classList.add('d-none'); });
        document.getElementById('requestModal').addEventListener('show.bs.modal', () => {
          locInput.value = '';
          locDept = '';
          locDepts.querySelectorAll('button').forEach((b) => {
            const active = b.dataset.dept === '';
            b.classList.toggle('btn-primary', active);
            b.classList.toggle('btn-outline-secondary', !active);
          });
          locBox.classList.add('d-none');
          syncLocation();
        });
      }
      const shopGrid = document.getElementById('shopGrid');
      if (shopGrid) {
        const tiles = [...shopGrid.querySelectorAll('.shop-tile')];
        const cart = new Map();
        const cartList = document.getElementById('cartList');
        const cartInputs = document.getElementById('cartInputs');
        const cartError = document.getElementById('cartError');
        let category = '';

        const filterTiles = () => {
          const term = document.getElementById('shopSearch').value.trim().toLowerCase();
          let shown = 0;
          tiles.forEach((t) => {
            const ok = t.dataset.name.toLowerCase().includes(term) && (category === '' || t.dataset.category === category);
            t.classList.toggle('d-none', !ok);
            if (ok) shown++;
          });
          const empty = document.getElementById('shopEmpty');
          empty.classList.toggle('d-none', shown > 0 || tiles.length === 0);
        };

        const render = () => {
          let total = 0;
          cartList.innerHTML = '';
          cartInputs.innerHTML = '';
          cart.forEach((qty, name) => {
            total += qty;
            const stock = parseInt(tiles.find((t) => t.dataset.name === name).dataset.stock, 10);
            const row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2 mb-2 cart-row';
            row.dataset.name = name;
            row.innerHTML = '<div class="flex-grow-1 small fw-semibold lh-sm"></div>'
              + '<div class="btn-group btn-group-sm" role="group">'
              + '<button type="button" class="btn btn-outline-secondary" data-step="-1" aria-label="Decrease">&minus;</button>'
              + '<input type="number" class="form-control form-control-sm text-center cart-qty" style="width:3.5rem;border-radius:0" min="1">'
              + '<button type="button" class="btn btn-outline-secondary" data-step="1" aria-label="Increase">+</button></div>'
              + '<button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove="1" aria-label="Remove"><i class="bi bi-trash"></i></button>';
            row.firstChild.textContent = name;
            const input = row.querySelector('.cart-qty');
            input.max = stock;
            input.value = qty;
            cartList.appendChild(row);
            const n = document.createElement('input');
            n.type = 'hidden'; n.name = 'item_name[]'; n.value = name;
            const q = document.createElement('input');
            q.type = 'hidden'; q.name = 'quantity[]'; q.value = qty;
            cartInputs.append(n, q);
          });
          tiles.forEach((t) => {
            const qty = cart.get(t.dataset.name) || 0;
            const card = t.querySelector('.shop-card');
            const badge = t.querySelector('.shop-qty');
            card.classList.toggle('border-primary', qty > 0);
            card.classList.toggle('selected', qty > 0);
            t.querySelector('.shop-remove').classList.toggle('d-none', qty === 0);
            badge.classList.toggle('d-none', qty === 0);
            badge.textContent = `×${qty}`;
            const action = t.querySelector('.shop-action');
            const maxed = qty >= parseInt(t.dataset.stock, 10);
            action.innerHTML = qty === 0 ? '<i class="bi bi-plus-circle"></i> Add' : (maxed ? '<i class="bi bi-check-circle-fill"></i> Max' : '<i class="bi bi-plus-circle"></i> Add more');
          });
          document.getElementById('cartCount').textContent = cart.size;
          document.getElementById('cartTotal').textContent = total;
          document.getElementById('cartEmpty').classList.toggle('d-none', cart.size > 0);
          if (cart.size > 0) cartError.classList.add('d-none');
        };

        const setQty = (name, qty) => {
          const tile = tiles.find((t) => t.dataset.name === name);
          const stock = parseInt(tile.dataset.stock, 10);
          if (!Number.isFinite(qty) || qty < 1) { cart.delete(name); } else { cart.set(name, Math.min(qty, stock)); }
          render();
        };

        shopGrid.addEventListener('click', (e) => {
          const tile = e.target.closest('.shop-tile');
          if (!tile) return;
          if (e.target.closest('.shop-remove')) { e.stopPropagation(); setQty(tile.dataset.name, 0); return; }
          setQty(tile.dataset.name, (cart.get(tile.dataset.name) || 0) + 1);
        });
        shopGrid.addEventListener('keydown', (e) => {
          const tile = e.target.closest('.shop-tile');
          if (!tile || e.target.closest('.shop-remove')) return;
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setQty(tile.dataset.name, (cart.get(tile.dataset.name) || 0) + 1); }
          else if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); setQty(tile.dataset.name, 0); }
        });
        cartList.addEventListener('click', (e) => {
          const row = e.target.closest('.cart-row');
          if (!row) return;
          const name = row.dataset.name;
          const step = e.target.closest('[data-step]');
          if (step) setQty(name, (cart.get(name) || 0) + parseInt(step.dataset.step, 10));
          else if (e.target.closest('[data-remove]')) setQty(name, 0);
        });
        cartList.addEventListener('change', (e) => {
          if (e.target.classList.contains('cart-qty')) setQty(e.target.closest('.cart-row').dataset.name, parseInt(e.target.value, 10));
        });
        document.getElementById('shopSearch').addEventListener('input', filterTiles);
        document.getElementById('shopCategories').addEventListener('click', (e) => {
          const chip = e.target.closest('button[data-category]');
          if (!chip) return;
          category = chip.dataset.category;
          document.querySelectorAll('#shopCategories button').forEach((b) => {
            const active = b === chip;
            b.classList.toggle('btn-primary', active);
            b.classList.toggle('btn-outline-secondary', !active);
          });
          filterTiles();
        });
        document.getElementById('requestForm').addEventListener('submit', (e) => {
          if (cart.size === 0) { e.preventDefault(); cartError.classList.remove('d-none'); }
        });
        document.getElementById('requestModal').addEventListener('show.bs.modal', () => {
          cart.clear();
          document.getElementById('shopSearch').value = '';
          document.querySelector('#shopCategories button[data-category=""]').click();
          render();
        });
      }
      const borrowInput = document.getElementById('reqBorrowUntil');
      const fromInput = document.getElementById('reqActiveFrom');
      if (borrowInput && fromInput) {
        const pad = (n) => String(n).padStart(2, '0');
        const stamp = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        const setMin = () => { const now = new Date(); fromInput.min = stamp(now); borrowInput.min = fromInput.value || stamp(now); };
        document.getElementById('reqBorrowQuick').addEventListener('click', (e) => {
          const chip = e.target.closest('button[data-days]');
          if (!chip) return;
          const start = new Date();
          start.setMinutes(Math.ceil(start.getMinutes() / 5) * 5, 0, 0);
          const end = new Date(start);
          end.setDate(end.getDate() + parseInt(chip.dataset.days, 10));
          end.setHours(17, 0, 0, 0);
          if (end <= start) end.setTime(start.getTime() + 3600000);
          fromInput.value = stamp(start);
          borrowInput.value = stamp(end);
          setMin();
        });
        fromInput.addEventListener('change', () => { setMin(); if (borrowInput.value && borrowInput.value < fromInput.value) borrowInput.value = fromInput.value; });
        document.getElementById('requestModal').addEventListener('show.bs.modal', () => { fromInput.value = ''; borrowInput.value = ''; setMin(); });
      }
      const reqSearch = document.getElementById('reqSearch');
      const reqRows = [...document.querySelectorAll('#reqTable tbody tr')].filter((r) => r.cells.length > 1);
      if (reqSearch && reqRows.length) {
        const noMatch = document.createElement('tr');
        noMatch.hidden = true;
        noMatch.innerHTML = '<td colspan="6" class="text-center text-muted py-4">No requisitions match your search.</td>';
        reqRows[0].parentNode.appendChild(noMatch);
        reqSearch.addEventListener('input', () => {
          const terms = reqSearch.value.toLowerCase().split(/\s+/).filter(Boolean);
          let shown = 0;
          reqRows.forEach((row) => {
            const text = [...row.cells].slice(0, -1).map((c) => c.textContent).join(' ').toLowerCase();
            row.hidden = !terms.every((term) => text.includes(term));
            if (!row.hidden) shown++;
          });
          noMatch.hidden = shown > 0;
        });
      }
    })();
  </script>
</body>
</html>
