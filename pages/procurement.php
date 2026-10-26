<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_once __DIR__ . '/../config/db.php';

$flash = take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid form token. Refresh the page and try again.');
  }

  $action = $_POST['action'] ?? '';
  $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

  try {
    if ($action === 'save_request') {
      $itemName = trim($_POST['item_name'] ?? '');
      $source = $_POST['source_trigger'] ?? 'Manual';
      $cost = filter_var($_POST['estimated_cost'] ?? null, FILTER_VALIDATE_FLOAT);

      if ($itemName === '' || mb_strlen($itemName) > 180 || !in_array($source, ['Manual', 'AI Recommendation', 'Low Stock Alert'], true) || $cost === false || $cost < 0) {
        throw new InvalidArgumentException('Enter a valid item name, source, and non-negative estimated cost.');
      }

      $prNumber = 'PR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
      $statement = $conn->prepare('INSERT INTO tbl_procurement (pr_number, item_name, source_trigger, estimated_cost) VALUES (?, ?, ?, ?)');
      $statement->bind_param('sssd', $prNumber, $itemName, $source, $cost);
      $statement->execute();
      set_flash('Purchase request saved.');
    } elseif (in_array($action, ['approve', 'reject'], true) && $id) {
      $conn->begin_transaction();
      $statement = $conn->prepare('SELECT item_name, status FROM tbl_procurement WHERE id = ? FOR UPDATE');
      $statement->bind_param('i', $id);
      $statement->execute();
      $request = $statement->get_result()->fetch_assoc();

      if (!$request || $request['status'] !== 'Pending Approval') {
        throw new DomainException('This request no longer has a pending action.');
      }

      if ($action === 'approve') {
        $status = 'Approved';
                $statement = $conn->prepare("UPDATE tbl_procurement SET status = ? WHERE id = ? AND status = 'Pending Approval'");
        $statement->bind_param('si', $status, $id);
        $statement->execute();

        $assetTag = 'AST-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $assetName = $request['item_name'];
        $category = 'Procured Item';
        $location = 'Storage Room';
                $inventoryStatus = 'Active';
        $statement = $conn->prepare('INSERT INTO tbl_inventory (asset_tag, asset_name, category, location, status) VALUES (?, ?, ?, ?, ?)');
                $statement->bind_param('sssss', $assetTag, $assetName, $category, $location, $inventoryStatus);
        $statement->execute();
        set_flash('Request approved and the item was added to inventory.');
      } else {
        $status = 'Rejected';
        $statement = $conn->prepare("UPDATE tbl_procurement SET status = ? WHERE id = ? AND status = 'Pending Approval'");
        $statement->bind_param('si', $status, $id);
        $statement->execute();
        set_flash('Request rejected.');
      }

      $conn->commit();
    } else {
      throw new InvalidArgumentException('Invalid procurement action.');
    }
  } catch (Throwable $exception) {
    if ($conn->errno || $conn->thread_id) {
      try {
        $conn->rollback();
      } catch (Throwable $rollbackException) {
        // There may be no active transaction for form-validation errors.
      }
    }
    error_log('Procurement operation failed: ' . $exception->getMessage());
    set_flash($exception instanceof InvalidArgumentException || $exception instanceof DomainException
      ? $exception->getMessage()
      : 'The request could not be completed. Check the database schema and try again.', 'danger');
  }

  header('Location: ' . APP_BASE_URL . '/pages/procurement.php');
  exit();
}

$inventory_items = $conn->query('SELECT asset_name FROM tbl_inventory ORDER BY id DESC');
$requests = $conn->query('SELECT id, pr_number, item_name, source_trigger, estimated_cost, status FROM tbl_procurement ORDER BY id DESC');
$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <base href="<?= APP_BASE_URL ?>/">
  <title>Procurement Management - QT System</title>

  <!-- Favicons & Fonts -->
  <link href="assets/img/favicon.png" rel="icon">
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
</head>

<body>

<?php
  include __DIR__ . '/../includes/header.php';
  include __DIR__ . '/../includes/navbar.php';
?>

  <main id="main" class="main">

    <div class="pagetitle d-flex justify-content-between align-items-center">
      <div>
        <h1>Procurement Management</h1>
        <nav>
          <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="pages/dashboard.php">Home</a></li>
            <li class="breadcrumb-item active">Procurement</li>
          </ol>
        </nav>
      </div>
      <button class="btn btn-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#newRequestModal">
        <i class="bi bi-plus-lg me-1"></i> New Request
      </button>
    </div><!-- End Page Title -->

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= h($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <section class="section dashboard mt-3">
      <div class="card">
        <div class="card-body pt-3">
          
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th>PR No.</th>
                  <th>Item Name</th>
                  <th>Source</th>
                  <th>Est. Cost</th>
                  <th>Status</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($requests && $requests->num_rows > 0): ?>
                  <?php while ($row = $requests->fetch_assoc()): ?>
                    <tr>
                      <td class="fw-bold"><?php echo htmlspecialchars($row['pr_number']); ?></td>
                      <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                      <td>
                        <span class="badge <?php echo ($row['source_trigger'] == 'AI Recommendation') ? 'bg-info text-dark' : 'bg-secondary'; ?>">
                          <?php echo htmlspecialchars($row['source_trigger']); ?>
                        </span>
                      </td>
                      <td>₱<?php echo number_format($row['estimated_cost'], 2); ?></td>
                      <td>
                        <span class="badge <?php 
                          if($row['status'] == 'Approved') echo 'bg-success';
                          elseif($row['status'] == 'Rejected') echo 'bg-danger';
                          else echo 'bg-warning text-dark'; 
                        ?>">
                          <?php echo htmlspecialchars($row['status']); ?>
                        </span>
                      </td>
                      <td class="text-end">
                        <?php if ($row['status'] == 'Pending Approval'): ?>
                          <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="btn btn-sm btn-outline-success rounded-pill px-3" name="action" value="approve">Approve</button>
                          </form>
                          <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger rounded-pill px-3" name="action" value="reject">Reject</button>
                          </form>
                        <?php else: ?>
                          <span class="small text-muted">No pending action</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="6" class="text-center text-muted py-3">No purchase requests found.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </section>

    <!-- MODAL FORM CONNECTED SA INVENTORY -->
    <div class="modal fade" id="newRequestModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form action="pages/procurement.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="modal-header">
              <h5 class="modal-title">New Purchase Request</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              
              <!-- Pwedeng pumili sa Inventory o Mag-type ng Bago -->
              <div class="mb-3">
                <label class="form-label">Select from Existing Inventory or Type New Item</label>
                <input type="text" name="item_name" list="inventory_list" class="form-control" placeholder="Type or select asset..." required autocomplete="off">
                <datalist id="inventory_list">
                  <?php if ($inventory_items && $inventory_items->num_rows > 0): ?>
                    <?php while ($inv = $inventory_items->fetch_assoc()): ?>
                      <option value="<?php echo htmlspecialchars($inv['asset_name']); ?>">
                    <?php endwhile; ?>
                  <?php endif; ?>
                </datalist>
              </div>

              <div class="mb-3">
                <label class="form-label">Source / Trigger</label>
                <select name="source_trigger" class="form-select">
                  <option value="Manual">Manual Request</option>
                  <option value="AI Recommendation">AI Recommendation</option>
                  <option value="Low Stock Alert">Low Stock Alert</option>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label">Estimated Cost (₱)</label>
                <input type="number" step="0.01" name="estimated_cost" class="form-control" placeholder="0.00" required>
              </div>

            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" name="action" value="save_request" class="btn btn-primary">Save Request</button>
            </div>
          </form>
        </div>
      </div>
    </div><!-- End Modal -->

  </main>

  <script src="assets/vendor/tinymce/tinymce.min.js"></script>
  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>

</body>
</html>