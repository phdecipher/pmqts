<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_access('inventory');
require_once __DIR__ . '/../config/db.php';

$flash = take_flash();
$csrfToken = csrf_token();

$nextAssetTag = next_asset_tag($conn);

const ASSET_CATEGORIES = ['Furniture', 'Fixtures', 'Equipment'];
const DISPOSAL_METHODS = ['Donation', 'Scrap', 'Thrown Away'];

function location_label(array $row): string
{
  return $row['department'] . ' / ' . $row['building'] . ' / Room ' . $row['room_number'];
}

// Makes sure an asset marked Under Maintenance has an open record on the Maintenance page.
function ensure_open_maintenance(mysqli $conn, int $inventoryId): void
{
  $check = $conn->prepare("SELECT id FROM tbl_maintenance WHERE inventory_id = ? AND status IN ('Scheduled', 'Pulled Out') LIMIT 1");
  $check->bind_param('i', $inventoryId);
  $check->execute();
  if ($check->get_result()->fetch_assoc()) {
    return;
  }
  $insert = $conn->prepare("INSERT INTO tbl_maintenance (inventory_id, maintenance_type, due_date) VALUES (?, 'Repair / Maintenance', CURDATE())");
  $insert->bind_param('i', $inventoryId);
  $insert->execute();
}

// Closes the open custody record (if held by someone else) and opens one for the user.
function assign_custodian(mysqli $conn, int $inventoryId, int $userId, string $userName): void
{
  $open = $conn->prepare('SELECT id, custodian_id FROM tbl_custodian_history WHERE inventory_id = ? AND released_at IS NULL ORDER BY id DESC LIMIT 1');
  $open->bind_param('i', $inventoryId);
  $open->execute();
  $current = $open->get_result()->fetch_assoc();
  if ($current && (int) $current['custodian_id'] === $userId) {
    return;
  }
  $close = $conn->prepare('UPDATE tbl_custodian_history SET released_at = NOW() WHERE inventory_id = ? AND released_at IS NULL');
  $close->bind_param('i', $inventoryId);
  $close->execute();
  $insert = $conn->prepare('INSERT INTO tbl_custodian_history (inventory_id, custodian_id, custodian_name) VALUES (?, ?, ?)');
  $insert->bind_param('iis', $inventoryId, $userId, $userName);
  $insert->execute();
  $update = $conn->prepare('UPDATE tbl_inventory SET custodian_id = ? WHERE id = ?');
  $update->bind_param('ii', $userId, $inventoryId);
  $update->execute();
}

if (isset($_GET['history'])) {
  $historyId = filter_var($_GET['history'], FILTER_VALIDATE_INT);
  $rows = [];
  if ($historyId) {
    $statement = $conn->prepare('SELECT custodian_name, assigned_at, released_at FROM tbl_custodian_history WHERE inventory_id = ? ORDER BY assigned_at DESC, id DESC');
    $statement->bind_param('i', $historyId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
  }
  header('Content-Type: application/json');
  echo json_encode($rows);
  exit();
}

// Active users who can be assigned to assets, as [id => full name].
function users_with_roles(mysqli $conn, array $roles): array
{
  $marks = implode(',', array_fill(0, count($roles), '?'));
  $statement = $conn->prepare("SELECT id, full_name FROM tbl_users WHERE is_active = 1 AND role IN ($marks) ORDER BY full_name");
  $statement->bind_param(str_repeat('s', count($roles)), ...$roles);
  $statement->execute();
  $list = [];
  foreach ($statement->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $list[(int) $row['id']] = $row['full_name'];
  }
  return $list;
}
$custodianNames = users_with_roles($conn, ['property_custodian', 'admin']);
$approverNames = users_with_roles($conn, ['approver', 'department_head']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid form token. Refresh the page and try again.');
  }

  $action = $_POST['action'] ?? '';
  $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
  try {
    if (in_array($action, ['save', 'update'], true)) {
      $assetTag = $action === 'save' ? next_asset_tag($conn) : trim($_POST['asset_tag'] ?? '');
      $assetName = trim($_POST['asset_name'] ?? '');
      $category = trim($_POST['category'] ?? '');
      $status = $_POST['status'] ?? 'Active';
      $locationId = filter_var($_POST['location_id'] ?? null, FILTER_VALIDATE_INT);
      $dateAcquired = trim($_POST['date_acquired'] ?? '');
      $costInput = trim($_POST['acquisition_cost'] ?? '');
      $disposalMethod = trim($_POST['disposal_method'] ?? '');
      $isCountable = !empty($_POST['is_countable']) ? 1 : 0;
      $quantityInput = trim($_POST['quantity'] ?? '');
      $validQuantity = $isCountable === 0 || (ctype_digit($quantityInput) && (int) $quantityInput <= 1000000);
      $quantityValue = $isCountable ? (int) $quantityInput : null;

      $dateObject = $dateAcquired === '' ? null : DateTime::createFromFormat('Y-m-d', $dateAcquired);
      $validDate = $dateAcquired === '' || ($dateObject && $dateObject->format('Y-m-d') === $dateAcquired && $dateAcquired <= date('Y-m-d'));
      $validCost = $costInput === '' || (is_numeric($costInput) && (float) $costInput >= 0 && (float) $costInput < 10000000000);
      if ($assetTag === '' || mb_strlen($assetTag) > 40 || $assetName === '' || mb_strlen($assetName) > 180
        || !in_array($category, ASSET_CATEGORIES, true) || !$locationId || !$validDate || !$validCost || !$validQuantity
        || !in_array($status, ['Active', 'Under Maintenance', 'For Replacement', 'Retired'], true)) {
        throw new InvalidArgumentException('Enter valid asset details, a category, a location, a valid status and, for countable assets, a whole-number quantity.');
      }
      if ($status === 'Retired' && !in_array($disposalMethod, DISPOSAL_METHODS, true)) {
        throw new InvalidArgumentException('Select a disposal method for a retired asset.');
      }

      $locationStatement = $conn->prepare('SELECT department, building, room_number FROM tbl_locations WHERE id = ?');
      $locationStatement->bind_param('i', $locationId);
      $locationStatement->execute();
      $locationRow = $locationStatement->get_result()->fetch_assoc();
      if (!$locationRow) {
        throw new InvalidArgumentException('Select a valid location.');
      }
      $location = mb_substr(location_label($locationRow), 0, 150);
      $dateAcquiredValue = $dateAcquired === '' ? null : $dateAcquired;
      $costValue = $costInput === '' ? null : number_format((float) $costInput, 2, '.', '');
      $disposalMethodValue = $status === 'Retired' ? $disposalMethod : null;
      $disposalDate = $status === 'Retired' ? date('Y-m-d') : null;
      $userId = (int) $_SESSION['user_id'];

      $custodianId = filter_var($_POST['custodian_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
      $approverId = filter_var($_POST['approver_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
      if (($custodianId && !isset($custodianNames[$custodianId])) || ($approverId && !isset($approverNames[$approverId]))) {
        throw new InvalidArgumentException('Select a valid custodian and approver.');
      }

      $conn->begin_transaction();
      $previousStatus = null;
      if ($action === 'update' && $id) {
        $previous = $conn->prepare('SELECT status FROM tbl_inventory WHERE id = ? FOR UPDATE');
        $previous->bind_param('i', $id);
        $previous->execute();
        $previousStatus = $previous->get_result()->fetch_assoc()['status'] ?? null;
      }
      if ($action === 'update' && $id) {
        $statement = $conn->prepare('UPDATE tbl_inventory SET approver_id = ?, is_countable = ?, quantity = ?, asset_tag = ?, asset_name = ?, category = ?, date_acquired = ?, acquisition_cost = ?, location = ?, location_id = ?, status = ?, disposal_method = ?, disposal_date = IF(? = \'Retired\', COALESCE(disposal_date, CURDATE()), NULL) WHERE id = ?');
        $statement->bind_param('iiissssssisssi', $approverId, $isCountable, $quantityValue, $assetTag, $assetName, $category, $dateAcquiredValue, $costValue, $location, $locationId, $status, $disposalMethodValue, $status, $id);
        $statement->execute();
        if ($custodianId) {
          assign_custodian($conn, $id, $custodianId, $custodianNames[$custodianId]);
        }
      } else {
        $statement = $conn->prepare('INSERT INTO tbl_inventory (asset_tag, asset_name, category, date_acquired, acquisition_cost, location, location_id, status, disposal_method, disposal_date, is_countable, quantity, approver_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->bind_param('ssssssisssiii', $assetTag, $assetName, $category, $dateAcquiredValue, $costValue, $location, $locationId, $status, $disposalMethodValue, $disposalDate, $isCountable, $quantityValue, $approverId);
        $statement->execute();
        $inventoryId = (int) $conn->insert_id;
        if ($custodianId) {
          assign_custodian($conn, $inventoryId, $custodianId, $custodianNames[$custodianId]);
        }
      }
      if ($status === 'Under Maintenance') {
        ensure_open_maintenance($conn, $action === 'update' && $id ? (int) $id : $inventoryId);
      } elseif ($previousStatus === 'Under Maintenance' && $id) {
        $close = $conn->prepare("UPDATE tbl_maintenance SET status = 'Completed', completed_at = NOW(), notes = COALESCE(notes, 'Closed from Inventory status change.') WHERE inventory_id = ? AND status IN ('Scheduled', 'Pulled Out')");
        $close->bind_param('i', $id);
        $close->execute();
      }
      $conn->commit();
      set_flash($action === 'update' ? 'Asset updated.' : 'Asset added.');
    } elseif ($action === 'delete' && $id) {
      $statement = $conn->prepare('DELETE FROM tbl_inventory WHERE id = ?');
      $statement->bind_param('i', $id);
      $statement->execute();
      set_flash('Asset deleted. Its maintenance schedules were removed as well.');
    } else {
      throw new InvalidArgumentException('Invalid inventory action.');
    }
  } catch (Throwable $exception) {
    try { $conn->rollback(); } catch (Throwable $rollbackException) {}
    error_log('Inventory operation failed: ' . $exception->getMessage());
    set_flash($exception instanceof InvalidArgumentException
      ? $exception->getMessage()
      : 'The asset could not be saved. Check for a duplicate asset tag and try again.', 'danger');
  }

  header('Location: ' . APP_BASE_URL . '/pages/inventory.php');
  exit();
}

foreach ($conn->query('SELECT id FROM tbl_inventory WHERE qr_token IS NULL')->fetch_all(MYSQLI_ASSOC) as $missing) {
  $tokenUpdate = $conn->prepare('UPDATE tbl_inventory SET qr_token = ? WHERE id = ? AND qr_token IS NULL');
  $newToken = bin2hex(random_bytes(32));
  $tokenUpdate->bind_param('si', $newToken, $missing['id']);
  $tokenUpdate->execute();
}
$assetColumns = 'i.id, i.asset_tag, i.qr_token, i.asset_name, i.category, i.is_countable, i.quantity, i.date_acquired, i.acquisition_cost, i.location, i.location_id, i.status, i.disposal_method, u.full_name AS custodian, i.custodian_id, i.approver_id, ap.full_name AS approver';
$assetFrom = ' FROM tbl_inventory i LEFT JOIN tbl_users u ON u.id = i.custodian_id LEFT JOIN tbl_users ap ON ap.id = i.approver_id';
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
  $like = '%' . $search . '%';
  $statement = $conn->prepare('SELECT ' . $assetColumns . $assetFrom . ' WHERE i.asset_tag LIKE ? OR i.asset_name LIKE ? OR i.category LIKE ? OR i.location LIKE ? ORDER BY i.id DESC');
  $statement->bind_param('ssss', $like, $like, $like, $like);
  $statement->execute();
  $assets = $statement->get_result();
} else {
  $assets = $conn->query('SELECT ' . $assetColumns . $assetFrom . ' ORDER BY i.id DESC');
}
$locations = $conn->query('SELECT id, department, building, room_number FROM tbl_locations ORDER BY department, building, room_number')->fetch_all(MYSQLI_ASSOC);
$departments = array_values(array_unique(array_column($locations, 'department')));
$isAdmin = can_access('inventory');
$currentUser = (string) ($_SESSION['full_name'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <base href="<?= APP_BASE_URL ?>/">

  <title>Inventory / Asset List - NiceAdmin Template</title>
  <meta content="" name="description">
  <meta content="" name="keywords">

  <!-- Favicons -->
  <link href="assets/img/favicon.png" rel="icon">
  <link href="assets/img/apple-touch-icon.png" rel="apple-touch-icon">

  <!-- Google Fonts -->
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="assets/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="assets/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="assets/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="assets/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="assets/css/style.css" rel="stylesheet">
  <link href="assets/css/responsive.css" rel="stylesheet">
  <script src="assets/js/responsive.js" defer></script>

  <!-- CSS FIX: Pinahigpit na Grid Lines / Harang sa bawat cell -->
  <style>
    .datatable-top,
    .datatable-dropdown,
    .datatable-search,
    .datatable-info {
      display: none !important;
    }

    /* Dagdag na malinaw na harang/grid sa bawat column at row */
    .table-grid {
      border: 1px solid #dee2e6 !important;
    }
    .table-grid th, 
    .table-grid td {
      border: 1px solid #dee2e6 !important;
      padding: 12px;
      vertical-align: middle;
    }
    .table-grid thead th {
      background-color: #f6f9ff;
      border-bottom: 2px solid #bcd0f7 !important;
    }
    @media print {
      body * { visibility: hidden !important; }
      #qrLabel, #qrLabel * { visibility: visible !important; }
      #qrModal { position: absolute !important; left: 0; top: 0; overflow: visible !important; }
      #qrModal .modal-dialog { margin: 0 !important; transform: none !important; max-width: none; }
      #qrModal .modal-content { border: 0; box-shadow: none; }
      #qrLabel { position: absolute; left: 0; top: 0; width: 100%; max-width: none !important; border: 0 !important; }
      @page { margin: 10mm; }
    }
  </style>
</head>

<body>

<?php
  include __DIR__ . '/../includes/header.php';
  include __DIR__ . '/../includes/navbar.php';
?>

  <main id="main" class="main">

    <!-- Container Wrapper -->
    <div class="container-fluid">

      <div class="pagetitle d-flex justify-content-between align-items-center">
        <div>
        <h1>Inventory / Asset List</h1>
        <nav>
          <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="pages/dashboard.php">Home</a></li>
            <li class="breadcrumb-item active">Inventory</li>
          </ol>
        </nav>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assetModal" id="addAssetButton">
          <i class="bi bi-plus-lg me-1"></i> Add Asset
        </button>
      </div><!-- End Page Title -->

      <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
          <?= h($flash['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <section class="section dashboard">
        <div class="row">

          <!-- Search Bar -->
          <div class="col-12 mb-3">
            <form class="search-form d-flex align-items-center" method="GET" action="pages/inventory.php">
              <div class="input-group">
                <input type="text" name="q" id="assetSearch" value="<?= h($search) ?>" class="form-control" placeholder="Search assets..." title="Enter search keyword" autocomplete="off">
                <button type="submit" class="btn btn-primary" title="Search">
                  <i class="bi bi-search"></i>
                </button>
              </div>
            </form>
          </div>

          <!-- Asset tiles -->
          <?php
            $assetRows = $assets->fetch_all(MYSQLI_ASSOC);
            $assetCategories = array_values(array_unique(array_column($assetRows, 'category')));
            $assetStatuses = array_values(array_unique(array_column($assetRows, 'status')));
          ?>
          <div class="col-12 mb-2">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
              <div class="d-flex flex-wrap gap-2" id="assetCategories">
                <button type="button" class="btn btn-sm btn-primary" data-cat="">All</button>
                <?php foreach ($assetCategories as $cat): ?>
                  <button type="button" class="btn btn-sm btn-outline-primary" data-cat="<?= h($cat) ?>"><?= h($cat) ?></button>
                <?php endforeach; ?>
              </div>
              <div class="d-flex flex-wrap align-items-center gap-2">
                <select class="form-select form-select-sm" id="assetSort" style="width:auto" title="Sort by stock">
                  <option value="desc" selected>Stock: High to low</option>
                  <option value="asc">Stock: Low to high</option>
                  <option value="">Original order</option>
                </select>
                <select class="form-select form-select-sm" id="assetStockFilter" style="width:auto" title="Stock level">
                  <option value="">All stock levels</option>
                  <option value="in">In stock</option>
                  <option value="low">Low stock (1-3)</option>
                  <option value="out">Out of stock</option>
                </select>
                <select class="form-select form-select-sm" id="assetStatusFilter" style="width:auto">
                  <option value="">All statuses</option>
                  <?php foreach ($assetStatuses as $st): ?><option><?= h($st) ?></option><?php endforeach; ?>
                </select>
                <span class="small text-muted text-nowrap"><span id="assetCount"><?= count($assetRows) ?></span> asset(s)</span>
              </div>
            </div>
          </div>

          <div class="col-12 mb-4">
            <div class="row g-3" id="assetGrid">
              <?php foreach ($assetRows as $asset): ?>
                <?php
                  $statusClass = $asset['status'] === 'Active' ? 'success' : ($asset['status'] === 'Retired' ? 'secondary' : 'warning text-dark');
                  $cat = strtolower($asset['category'] ?? '');
                  $icon = $cat === 'furniture' ? 'bi-lamp' : ($cat === 'fixture' || $cat === 'fixtures' ? 'bi-lightbulb' : ($cat === 'equipment' ? 'bi-pc-display' : 'bi-box-seam'));
                ?>
                <div class="col-12 col-md-6 col-xxl-4 asset-tile" data-cat="<?= h($asset['category']) ?>" data-status="<?= h($asset['status']) ?>" data-qty="<?= $asset['is_countable'] ? (int) $asset['quantity'] : '' ?>" data-stock="<?= $asset['is_countable'] ? ((int) $asset['quantity'] <= 0 ? 'out' : ((int) $asset['quantity'] <= 3 ? 'low' : 'in')) : '' ?>">
                  <div class="card h-100 mb-0 border <?= $asset['status'] === 'Retired' ? 'opacity-75' : '' ?>">
                    <div class="card-body p-4 d-flex flex-column">
                      <div class="d-flex gap-3 mb-3">
                        <div class="rounded bg-light text-secondary d-flex align-items-center justify-content-center flex-shrink-0" style="width:76px;height:76px"><i class="bi <?= $icon ?>" style="font-size:2.2rem"></i></div>
                        <div class="flex-grow-1" style="min-width:0">
                          <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="fw-bold fs-5 text-truncate" title="<?= h($asset['asset_name']) ?>"><?= h($asset['asset_name']) ?></div>
                            <span class="badge bg-<?= $statusClass ?> flex-shrink-0"><?= h($asset['status']) ?></span>
                          </div>
                          <div class="small text-primary fw-semibold"><?= h($asset['asset_tag']) ?></div>
                          <div class="small text-muted"><?= h($asset['category']) ?><?php if ($asset['disposal_method']): ?> · <?= h($asset['disposal_method']) ?><?php endif; ?></div>
                        </div>
                      </div>
                      <div class="mb-3">
                        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle fw-semibold"><i class="bi bi-geo-alt-fill me-1"></i><?= h($asset['location']) ?></span>
                      </div>
                      <div class="d-flex gap-2 mb-3 align-items-stretch">
                      <?php if ($asset['is_countable']): ?>
                        <?php
                          $qty = (int) $asset['quantity'];
                          [$stockTone, $stockIcon, $stockLabel] = $qty <= 0 ? ['danger', 'bi-x-octagon-fill', 'OUT OF STOCK'] : ($qty <= 3 ? ['warning', 'bi-exclamation-triangle-fill', 'LOW STOCK'] : ['primary', 'bi-box-seam-fill', 'IN STOCK']);
                        ?>
                        <div class="rounded px-2 py-3 text-center <?= $stockTone === 'warning' ? 'bg-warning text-dark' : 'bg-' . $stockTone . ' text-white' ?> d-flex flex-column align-items-center justify-content-center flex-shrink-0" style="width:8rem" title="<?= h($stockLabel) ?>">
                          <div class="d-flex align-items-center justify-content-center gap-2">
                            <i class="bi <?= $stockIcon ?>" style="font-size:1.5rem;line-height:1"></i>
                            <span class="fw-bold lh-1" style="font-size:2.6rem"><?= $qty ?></span>
                          </div>
                          <div class="fw-bold mt-2" style="font-size:.8rem;letter-spacing:.04em"><?= h($stockLabel) ?></div>
                          <?php if ($qty > 0 && $qty <= 3): ?><div class="fw-semibold mt-1" style="font-size:.75rem">Restock soon</div><?php endif; ?>
                        </div>
                      <?php endif; ?>
                      <div class="border rounded p-3 flex-grow-1" style="min-width:0">
                        <div class="d-flex align-items-center gap-2 mb-2">
                          <span class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:28px;height:28px"><i class="bi bi-person-fill"></i></span>
                          <div class="lh-sm" style="min-width:0">
                            <div class="text-muted" style="font-size:.7rem">CUSTODIAN</div>
                            <div class="fw-semibold text-truncate"><?= h($asset['custodian'] ?? 'Unassigned') ?></div>
                          </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                          <span class="rounded-circle bg-success text-white d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:28px;height:28px"><i class="bi bi-person-check-fill"></i></span>
                          <div class="lh-sm" style="min-width:0">
                            <div class="text-muted" style="font-size:.7rem">APPROVER</div>
                            <div class="fw-semibold text-truncate"><?= h($asset['approver'] ?? 'Unassigned') ?></div>
                          </div>
                        </div>
                      </div>
                      </div>
                      <div class="row g-2 mb-3">
                        <div class="col-6">
                          <div class="rounded bg-light border p-3 h-100">
                            <div class="text-muted" style="font-size:.7rem"><i class="bi bi-cash me-1"></i>COST</div>
                            <div class="fw-semibold"><?= $asset['acquisition_cost'] !== null ? '₱' . number_format((float) $asset['acquisition_cost'], 2) : '—' ?></div>
                          </div>
                        </div>
                        <div class="col-6">
                          <div class="rounded bg-light border p-3 h-100">
                            <div class="text-muted" style="font-size:.7rem"><i class="bi bi-calendar3 me-1"></i>ACQUIRED</div>
                            <div class="fw-semibold"><?= h($asset['date_acquired'] ?? '—') ?></div>
                          </div>
                        </div>
                      </div>
                      <div class="d-flex gap-2 mt-auto pt-2 border-top align-items-center justify-content-end">
                        <button type="button" class="btn btn-outline-info btn-sm history-button" title="Custodian history" data-id="<?= (int) $asset['id'] ?>" data-tag="<?= h($asset['asset_tag']) ?>" data-bs-toggle="modal" data-bs-target="#historyModal"><i class="bi bi-clock-history"></i></button>
                        <button type="button" class="btn btn-outline-primary btn-sm qr-button" title="QR label" data-token="<?= h($asset['qr_token']) ?>" data-tag="<?= h($asset['asset_tag']) ?>" data-name="<?= h($asset['asset_name']) ?>" data-category="<?= h($asset['category']) ?>" data-location="<?= h($asset['location']) ?>" data-status="<?= h($asset['status']) ?>" data-bs-toggle="modal" data-bs-target="#qrModal"><i class="bi bi-qr-code"></i></button>
                        <button type="button" class="btn btn-outline-secondary btn-sm edit-asset-button" title="Edit"
                          data-id="<?= (int) $asset['id'] ?>" data-tag="<?= h($asset['asset_tag']) ?>"
                          data-name="<?= h($asset['asset_name']) ?>" data-category="<?= h($asset['category']) ?>"
                          data-acquired="<?= h($asset['date_acquired'] ?? '') ?>" data-cost="<?= h($asset['acquisition_cost'] ?? '') ?>"
                          data-countable="<?= (int) $asset['is_countable'] ?>" data-quantity="<?= h($asset['quantity'] ?? '') ?>"
                          data-location-id="<?= (int) $asset['location_id'] ?>" data-status="<?= h($asset['status']) ?>"
                          data-disposal="<?= h($asset['disposal_method'] ?? '') ?>" data-custodian="<?= h($asset['custodian'] ?? '') ?>" data-custodian-id="<?= (int) $asset['custodian_id'] ?: '' ?>" data-approver-id="<?= (int) $asset['approver_id'] ?: '' ?>"
                          data-bs-toggle="modal" data-bs-target="#assetModal"><i class="bi bi-pencil-square"></i></button>
                        <form method="post" onsubmit="return confirm('Delete this asset and its schedules?');">
                          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                          <input type="hidden" name="id" value="<?= (int) $asset['id'] ?>">
                          <button class="btn btn-outline-danger btn-sm" name="action" value="delete" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <div id="assetEmpty" class="text-center text-muted py-5" <?= $assetRows ? 'hidden' : '' ?>><i class="bi bi-box-seam d-block fs-2"></i><?= $assetRows ? 'No assets match your search.' : 'No assets found. Add an asset or update your search.' ?></div>
          </div>

        </div><!-- End Row -->
      </section>

      <div class="modal fade" id="assetModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content border-0 shadow">
            <form method="post" action="pages/inventory.php" class="d-flex flex-column overflow-hidden">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <input type="hidden" name="id" id="assetId">
              <input type="hidden" name="action" id="assetAction" value="save">
              <div class="modal-header">
                <div class="d-flex align-items-center gap-3">
                  <span class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center" style="width:44px;height:44px"><i class="bi bi-box-seam fs-5"></i></span>
                  <div class="lh-sm">
                    <h5 class="modal-title" id="assetModalTitle">Add Asset</h5>
                    <div class="small text-muted">Fill in the asset details below</div>
                  </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body bg-light p-3">

                <div class="bg-white border rounded p-3 mb-3">
                  <div class="fw-semibold text-primary mb-3"><i class="bi bi-info-circle me-2"></i>Basic information</div>
                  <div class="row g-3">
                    <div class="col-md-5"><label class="form-label" for="assetTag">Asset tag</label>
                      <div class="input-group"><span class="input-group-text"><i class="bi bi-upc-scan"></i></span><input class="form-control" id="assetTag" name="asset_tag" maxlength="40" required></div>
                    </div>
                    <div class="col-md-7"><label class="form-label" for="assetName">Asset name</label><input class="form-control" id="assetName" name="asset_name" maxlength="180" placeholder="e.g. Teacher's table" required></div>
                    <div class="col-md-5"><label class="form-label" for="assetCategory">Category</label><select class="form-select" id="assetCategory" name="category" required><option value="">Select category</option><?php foreach (ASSET_CATEGORIES as $categoryOption): ?><option><?= h($categoryOption) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-7">
                      <label class="form-label d-block">Tracking</label>
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="assetCountable" name="is_countable" value="1">
                        <label class="form-check-label" for="assetCountable">Countable item <span class="text-muted small">(tracked by quantity)</span></label>
                      </div>
                    </div>
                    <div class="col-12 d-none" id="quantityGroup">
                      <label class="form-label" for="assetQuantity">Quantity in stock</label>
                      <input type="number" class="form-control" id="assetQuantity" name="quantity" min="0" max="1000000" step="1">
                    </div>
                  </div>
                </div>

                <div class="bg-white border rounded p-3 mb-3">
                  <div class="fw-semibold text-success mb-3"><i class="bi bi-cash-coin me-2"></i>Acquisition</div>
                  <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="assetAcquired">Date acquired</label><input type="date" class="form-control" id="assetAcquired" name="date_acquired" max="<?= date('Y-m-d') ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="assetCost">Acquisition cost</label>
                      <div class="input-group"><span class="input-group-text">₱</span><input type="number" class="form-control" id="assetCost" name="acquisition_cost" min="0" step="0.01" placeholder="0.00"></div>
                    </div>
                  </div>
                </div>

                <div class="bg-white border rounded p-3 mb-3">
                  <div class="fw-semibold text-info-emphasis mb-3"><i class="bi bi-geo-alt me-2"></i>Location</div>
                  <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="assetDepartment">Department</label>
                      <div class="input-group">
                        <select class="form-select" id="assetDepartment" required><option value="">Select department</option><?php foreach ($departments as $department): ?><option><?= h($department) ?></option><?php endforeach; ?></select>
                        <?php if ($isAdmin): ?><a class="btn btn-outline-secondary" title="Manage locations" href="pages/locations.php"><i class="bi bi-plus-lg"></i></a><?php endif; ?>
                      </div>
                    </div>
                    <div class="col-md-6"><label class="form-label" for="assetLocation">Building / Room</label><select class="form-select" id="assetLocation" name="location_id" required><option value="">Select department first</option></select></div>
                  </div>
                </div>

                <div class="bg-white border rounded p-3">
                  <div class="fw-semibold text-warning-emphasis mb-3"><i class="bi bi-people me-2"></i>Status &amp; responsibility</div>
                  <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="assetStatus">Status</label><select class="form-select" id="assetStatus" name="status"><option>Active</option><option>Under Maintenance</option><option>For Replacement</option><option>Retired</option></select></div>
                    <div class="col-md-6 d-none" id="disposalGroup"><label class="form-label" for="assetDisposal">Disposal method</label><select class="form-select" id="assetDisposal" name="disposal_method"><option value="">Select method</option><?php foreach (DISPOSAL_METHODS as $method): ?><option><?= h($method) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label" for="assetCustodianSelect">Custodian</label>
                      <select class="form-select" id="assetCustodianSelect" name="custodian_id"><option value="">— None —</option><?php foreach ($custodianNames as $uid => $uname): ?><option value="<?= (int) $uid ?>"><?= h($uname) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="col-md-6"><label class="form-label" for="assetApproverSelect">Approver</label>
                      <select class="form-select" id="assetApproverSelect" name="approver_id"><option value="">— None —</option><?php foreach ($approverNames as $uid => $uname): ?><option value="<?= (int) $uid ?>"><?= h($uname) ?></option><?php endforeach; ?></select>
                    </div>
                  </div>
                </div>

              </div>
              <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Asset</button></div>
            </form>
          </div>
        </div>
      </div>

      <div class="modal fade" id="qrModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-qr-code me-2"></i>QR Label</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
              <div id="qrLabel" class="border rounded-4 p-4 text-center mx-auto" style="max-width:340px">
                <div class="text-muted fw-bold" style="font-size:.7rem;letter-spacing:.12em">PMQTS · QUALITY TRACKING</div>
                <div id="qrCode" class="d-flex justify-content-center align-items-center my-3" style="min-height:220px"></div>
                <div class="h5 fw-bold mb-1" id="qrName"></div>
                <div class="text-primary fw-bold font-monospace" id="qrTag"></div>
                <div class="text-muted small mt-2" id="qrLocation"></div>
                <div class="text-muted small" id="qrCategory"></div>
              </div>
              <p class="small text-muted text-center mt-3 mb-0">Scanning opens this asset's record in PMQTS.</p>
            </div>
            <div class="modal-footer justify-content-center">
              <button type="button" class="btn btn-primary" id="qrPrint"><i class="bi bi-printer me-1"></i> Print Label</button>
              <button type="button" class="btn btn-outline-primary" id="qrDownload"><i class="bi bi-download me-1"></i> Download QR</button>
              <a class="btn btn-outline-secondary" id="qrOpen" href="#" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i> View Asset</a>
            </div>
          </div>
        </div>
      </div>

      <div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Custodian history — <span id="historyTag"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
              <table class="table table-sm"><thead><tr><th>Custodian</th><th>From</th><th>To</th></tr></thead><tbody id="historyRows"></tbody></table>
            </div>
          </div>
        </div>
      </div>

    </div><!-- End Container -->

  </main><!-- End #main -->

  <a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

  <!-- Vendor JS Files -->
  <script src="assets/vendor/apexcharts/apexcharts.min.js"></script>
  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/vendor/chart.js/chart.umd.js"></script>
  <script src="assets/vendor/echarts/echarts.min.js"></script>
  <script src="assets/vendor/quill/quill.min.js"></script>
  <script src="assets/vendor/simple-datatables/simple-datatables.js"></script>
  <script src="assets/vendor/tinymce/tinymce.min.js"></script>
  <script src="assets/vendor/php-email-form/validate.js"></script>

  <script>
    (() => {
      const input = document.getElementById('assetSearch');
      const tiles = [...document.querySelectorAll('.asset-tile')];
      const count = document.getElementById('assetCount');
      const empty = document.getElementById('assetEmpty');
      const statusSelect = document.getElementById('assetStatusFilter');
      const stockSelect = document.getElementById('assetStockFilter');
      const sortSelect = document.getElementById('assetSort');
      const grid = document.getElementById('assetGrid');
      tiles.forEach((tile, i) => { tile.dataset.order = i; });
      const sortTiles = () => {
        const dir = sortSelect.value;
        const qty = (tile) => (tile.dataset.qty === '' ? null : Number(tile.dataset.qty));
        [...tiles].sort((a, b) => {
          if (dir) {
            const qa = qty(a); const qb = qty(b);
            if (qa === null && qb !== null) return 1;
            if (qb === null && qa !== null) return -1;
            if (qa !== qb) return dir === 'asc' ? qa - qb : qb - qa;
          }
          return a.dataset.order - b.dataset.order;
        }).forEach((tile) => grid.appendChild(tile));
      };
      const chips = [...document.querySelectorAll('#assetCategories button')];
      let cat = '';

      const filter = () => {
        const terms = input.value.toLowerCase().split(/\s+/).filter(Boolean);
        let shown = 0;
        tiles.forEach((tile) => {
          const text = tile.textContent.toLowerCase();
          const match = terms.every((term) => text.includes(term))
            && (!cat || tile.dataset.cat === cat)
            && (!statusSelect.value || tile.dataset.status === statusSelect.value)
            && (!stockSelect.value || tile.dataset.stock === stockSelect.value);
          tile.hidden = !match;
          if (match) shown++;
        });
        count.textContent = shown;
        empty.hidden = shown > 0 || !tiles.length;
      };
      chips.forEach((chip) => chip.addEventListener('click', () => {
        cat = chip.dataset.cat;
        chips.forEach((c) => {
          const active = c === chip;
          c.classList.toggle('btn-primary', active);
          c.classList.toggle('btn-outline-primary', !active);
        });
        filter();
      }));
      statusSelect.addEventListener('change', filter);
      stockSelect.addEventListener('change', filter);
      sortSelect.addEventListener('change', sortTiles);
      input.addEventListener('input', filter);
      input.form.addEventListener('submit', (event) => {
        if (tiles.length) event.preventDefault();
      });
      sortTiles();
      filter();
    })();
  </script>

  <script>
    (() => {
      const $ = (id) => document.getElementById(id);
      const locations = <?= json_encode($locations) ?>;
      const currentUserId = <?= (int) $_SESSION['user_id'] ?>;
      const custodianIds = <?= json_encode((object) $custodianNames) ?>;

      const fillLocations = (department, selectedId) => {
        const select = $('assetLocation');
        select.innerHTML = '';
        const first = new Option(department ? 'Select building / room' : 'Select department first', '');
        select.add(first);
        locations.filter((l) => l.department === department).forEach((l) => {
          select.add(new Option(`${l.building} — Room ${l.room_number}`, l.id, false, String(l.id) === String(selectedId)));
        });
      };
      const syncDisposal = () => $('disposalGroup').classList.toggle('d-none', $('assetStatus').value !== 'Retired');
      const syncCountable = () => {
        const on = $('assetCountable').checked;
        $('quantityGroup').classList.toggle('d-none', !on);
        $('assetQuantity').required = on;
        if (!on) $('assetQuantity').value = '';
      };
      $('assetCountable').addEventListener('change', syncCountable);
      const setLocation = (locationId) => {
        const match = locations.find((l) => String(l.id) === String(locationId));
        $('assetDepartment').value = match ? match.department : '';
        fillLocations(match ? match.department : '', locationId);
      };

      $('assetDepartment').addEventListener('change', () => fillLocations($('assetDepartment').value, ''));
      $('assetStatus').addEventListener('change', syncDisposal);

      const reset = () => {
        $('assetModalTitle').textContent = 'Add Asset';
        $('assetAction').value = 'save';
        $('assetId').value = '';
        ['assetName', 'assetCategory', 'assetAcquired', 'assetCost', 'assetDisposal'].forEach((id) => { $(id).value = ''; });
        const tag = $('assetTag');
        tag.value = <?= json_encode($nextAssetTag) ?>;
        tag.readOnly = true;
        $('assetStatus').value = 'Active';
        $('assetCountable').checked = false;
        syncCountable();
        setLocation('');
        syncDisposal();
        $('assetCustodianSelect').value = currentUserId in custodianIds ? String(currentUserId) : '';
        $('assetApproverSelect').value = '';
      };
      $('addAssetButton').addEventListener('click', reset);
      document.querySelectorAll('.edit-asset-button').forEach((button) => {
        button.addEventListener('click', () => {
          const d = button.dataset;
          $('assetModalTitle').textContent = 'Edit Asset';
          $('assetAction').value = 'update';
          $('assetId').value = d.id;
          $('assetTag').readOnly = false;
          $('assetTag').value = d.tag;
          $('assetName').value = d.name;
          $('assetCategory').value = d.category;
          $('assetAcquired').value = d.acquired;
          $('assetCost').value = d.cost;
          $('assetCountable').checked = d.countable === '1';
          $('assetQuantity').value = d.quantity;
          syncCountable();
          $('assetQuantity').value = d.countable === '1' ? d.quantity : '';
          $('assetStatus').value = d.status;
          $('assetDisposal').value = d.disposal;
          setLocation(d.locationId);
          syncDisposal();
          $('assetCustodianSelect').value = d.custodianId;
          $('assetApproverSelect').value = d.approverId;
        });
      });

      document.querySelectorAll('.history-button').forEach((button) => {
        button.addEventListener('click', async () => {
          $('historyTag').textContent = button.dataset.tag;
          const body = $('historyRows');
          body.innerHTML = '<tr><td colspan="3" class="text-muted">Loading…</td></tr>';
          const response = await fetch(`pages/inventory.php?history=${encodeURIComponent(button.dataset.id)}`);
          const rows = await response.json();
          body.innerHTML = '';
          if (!rows.length) {
            body.innerHTML = '<tr><td colspan="3" class="text-muted">No custodian recorded.</td></tr>';
            return;
          }
          rows.forEach((r) => {
            const tr = document.createElement('tr');
            [r.custodian_name, r.assigned_at, r.released_at || 'Present'].forEach((text) => {
              const td = document.createElement('td');
              td.textContent = text;
              tr.appendChild(td);
            });
            body.appendChild(tr);
          });
        });
      });
    })();
  </script>

  <!-- Template Main JS File -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" referrerpolicy="no-referrer"></script>
  <script>
    (() => {
      const modal = document.getElementById('qrModal');
      const code = document.getElementById('qrCode');
      const open = document.getElementById('qrOpen');
      let currentTag = '';
      document.querySelectorAll('.qr-button').forEach((button) => {
        button.addEventListener('click', () => {
          const { token, tag, name, category, location, status } = button.dataset;
          currentTag = tag;
          document.getElementById('qrName').textContent = name;
          document.getElementById('qrTag').textContent = tag;
          document.getElementById('qrLocation').textContent = `${location} · ${status}`;
          document.getElementById('qrCategory').textContent = category;
          const url = new URL('pages/asset.php', document.baseURI);
          url.searchParams.set('token', token);
          open.href = url.toString();
          code.replaceChildren();
          if (typeof QRCode === 'undefined') {
            code.innerHTML = '<span class="text-danger small">QR library could not be loaded. Check your internet connection.</span>';
            return;
          }
          new QRCode(code, { text: url.toString(), width: 220, height: 220, colorDark: '#102a43', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.H });
        });
      });
      document.getElementById('qrPrint').addEventListener('click', () => window.print());
      document.getElementById('qrDownload').addEventListener('click', () => {
        const canvas = code.querySelector('canvas');
        const dataUrl = canvas ? canvas.toDataURL('image/png') : code.querySelector('img')?.src;
        if (!dataUrl) return;
        const link = document.createElement('a');
        link.href = dataUrl;
        link.download = `PMQTS-${currentTag}.png`;
        link.click();
      });
    })();
  </script>
  <script src="assets/js/main.js"></script>
  <script src="assets/js/table-sort.js"></script>

</body>

</html>