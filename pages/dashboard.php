  <?php
  require_once __DIR__ . '/../config/app.php';
  require_login();
  require_once __DIR__ . '/../config/db.php';

  $role = current_role();
  $userId = (int) $_SESSION['user_id'];

  function scalar(mysqli $conn, string $sql, string $types = '', array $params = []): int
  {
    $statement = $conn->prepare($sql);
    if ($types !== '') {
      $statement->bind_param($types, ...$params);
    }
    $statement->execute();
    return (int) ($statement->get_result()->fetch_row()[0] ?? 0);
  }

  function req_count(mysqli $conn, string $where, string $types = '', array $params = []): int
  {
    return scalar($conn, "SELECT COUNT(*) FROM tbl_requisitions r WHERE $where", $types, $params);
  }

  $assignedCustodian = sprintf(ASSIGNED_SQL, 'custodian_id');
  $dueSql = "status = 'Scheduled' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
  $inventoryCards = fn() => [
    ['Total Assets', scalar($conn, 'SELECT COUNT(*) FROM tbl_inventory'), 'bi-box', 'primary', 'pages/inventory.php'],
    ['Active Assets', scalar($conn, "SELECT COUNT(*) FROM tbl_inventory WHERE status = 'Active'"), 'bi-check-circle', 'success', 'pages/inventory.php'],
    ['Maintenance Due', scalar($conn, "SELECT COUNT(*) FROM tbl_maintenance WHERE $dueSql"), 'bi-tools', 'warning', 'pages/maintenance.php?filter=due'],
    ['For Replacement', scalar($conn, "SELECT COUNT(*) FROM tbl_inventory WHERE status = 'For Replacement'"), 'bi-arrow-repeat', 'danger', 'pages/inventory.php?q=For%20Replacement'],
  ];

  // Cards and panels shown depend on the user's role.
  $cards = [];
  $panels = [];
  $greeting = 'Here is what needs your attention.';
  $reqLink = 'pages/requisitions.php';
  $summary = "(SELECT GROUP_CONCAT(CONCAT(l.item_name, ' ×', l.quantity) ORDER BY l.id SEPARATOR ', ') FROM tbl_requisition_lines l WHERE l.requisition_id = r.id)";

  if ($role === 'admin') {
    $cards = $inventoryCards();
    $cards[] = ['Awaiting Approval', req_count($conn, "r.status = 'Pending Approval'"), 'bi-hourglass-split', 'warning', $reqLink . '?status=Pending%20Approval'];
    $cards[] = ['Awaiting Custodian', req_count($conn, "r.status IN ('Pending Custodian', 'Awaiting Procurement')"), 'bi-person-gear', 'info', $reqLink . '?status=Pending%20Custodian'];
    $cards[] = ['Pending Purchase Requests', scalar($conn, "SELECT COUNT(*) FROM tbl_procurement WHERE status = 'Pending Approval'"), 'bi-cart', 'primary', 'pages/procurement.php'];
    $cards[] = ['Active Users', scalar($conn, 'SELECT COUNT(*) FROM tbl_users WHERE is_active = 1'), 'bi-people', 'secondary', 'pages/users.php'];
    $panels = ['maintenance', 'requisitions'];
    $reqTitle = 'Recent Requisitions';
    $reqRows = $conn->query("SELECT r.req_number, r.status, r.created_at, r.borrow_from, r.borrow_until, u.full_name AS requester, $summary AS items FROM tbl_requisitions r INNER JOIN tbl_users u ON u.id = r.requester_id ORDER BY r.id DESC LIMIT 5");
  } elseif ($role === 'property_custodian') {
    $cards = $inventoryCards();
    $cards[] = ['To Process', req_count($conn, "r.status = 'Pending Custodian' AND $assignedCustodian", 'i', [$userId]), 'bi-clipboard-check', 'info', $reqLink . '?status=Pending%20Custodian'];
    $cards[] = ['To Fulfill', req_count($conn, "r.status = 'Ready for Fulfillment' AND r.processed_by = ?", 'i', [$userId]), 'bi-box-seam', 'success', $reqLink . '?status=Ready%20for%20Fulfillment'];
    $panels = ['requisitions', 'maintenance'];
    $reqTitle = 'Requisitions Needing Your Action';
    $statement = $conn->prepare("SELECT r.req_number, r.status, r.created_at, r.borrow_from, r.borrow_until, u.full_name AS requester, $summary AS items FROM tbl_requisitions r INNER JOIN tbl_users u ON u.id = r.requester_id
      WHERE (r.status = 'Pending Custodian' AND $assignedCustodian) OR (r.status = 'Ready for Fulfillment' AND r.processed_by = ?) ORDER BY r.id DESC LIMIT 5");
    $statement->bind_param('ii', $userId, $userId);
    $statement->execute();
    $reqRows = $statement->get_result();
  } elseif ($role === 'maintenance_personnel') {
    $cards = [
      ['Maintenance Due', scalar($conn, "SELECT COUNT(*) FROM tbl_maintenance WHERE $dueSql"), 'bi-tools', 'warning', 'pages/maintenance.php?filter=due'],
      ['Overdue', scalar($conn, "SELECT COUNT(*) FROM tbl_maintenance WHERE status = 'Scheduled' AND due_date < CURDATE()"), 'bi-exclamation-triangle', 'danger', 'pages/maintenance.php?filter=overdue'],
      ['Pulled Out for Repair', scalar($conn, "SELECT COUNT(*) FROM tbl_maintenance WHERE status = 'Pulled Out'"), 'bi-wrench-adjustable', 'info', 'pages/maintenance.php?filter=pulled'],
      ['Job Orders Ready', req_count($conn, "r.status = 'Ready for Fulfillment'"), 'bi-file-earmark-text', 'success', $reqLink . '?status=Ready%20for%20Fulfillment'],
    ];
    $panels = ['requisitions', 'maintenance'];
    $reqTitle = 'Job Orders Ready';
    $reqRows = $conn->query("SELECT r.req_number, r.status, r.created_at, r.borrow_from, r.borrow_until, u.full_name AS requester, $summary AS items FROM tbl_requisitions r INNER JOIN tbl_users u ON u.id = r.requester_id WHERE r.status = 'Ready for Fulfillment' ORDER BY r.id DESC LIMIT 5");
  } elseif ($role === 'approver' || $role === 'department_head') {
    $mine = LINE_APPROVER_SQL;
    $pendingSql = "FROM tbl_requisition_lines l INNER JOIN tbl_requisitions r ON r.id = l.requisition_id WHERE r.status = 'Pending Approval' AND l.approval_status = 'Pending' AND $mine";
    $cards = [
      ['Items Awaiting Your Approval', scalar($conn, "SELECT COUNT(*) $pendingSql", 'i', [$userId]), 'bi-hourglass-split', 'warning', $reqLink . '?status=Pending%20Approval'],
      ['Items You Approved', scalar($conn, "SELECT COUNT(*) FROM tbl_requisition_lines WHERE decided_by = ? AND approval_status = 'Approved'", 'i', [$userId]), 'bi-check-circle', 'success', $reqLink],
      ['Items You Rejected', scalar($conn, "SELECT COUNT(*) FROM tbl_requisition_lines WHERE decided_by = ? AND approval_status = 'Rejected'", 'i', [$userId]), 'bi-x-circle', 'danger', $reqLink . '?status=Rejected'],
    ];
    if ($role === 'approver') {
      $cards[] = ['Pending Purchase Requests', scalar($conn, "SELECT COUNT(*) FROM tbl_procurement WHERE status = 'Pending Approval'"), 'bi-cart', 'primary', 'pages/procurement.php'];
    }
    $panels = ['requisitions'];
    $reqTitle = 'Requisitions Awaiting Your Approval';
    $statement = $conn->prepare("SELECT r.req_number, r.status, r.created_at, r.borrow_from, r.borrow_until, u.full_name AS requester, $summary AS items FROM tbl_requisitions r INNER JOIN tbl_users u ON u.id = r.requester_id
      WHERE r.status = 'Pending Approval' AND EXISTS (SELECT 1 FROM tbl_requisition_lines l WHERE l.requisition_id = r.id AND l.approval_status = 'Pending' AND $mine) ORDER BY r.id DESC LIMIT 5");
    $statement->bind_param('i', $userId);
    $statement->execute();
    $reqRows = $statement->get_result();
  } elseif ($role === 'teacher') {
    $cards = [
      ['My Requisitions', req_count($conn, 'r.requester_id = ?', 'i', [$userId]), 'bi-journal-text', 'primary', $reqLink],
      ['Awaiting Approval', req_count($conn, "r.requester_id = ? AND r.status = 'Pending Approval'", 'i', [$userId]), 'bi-hourglass-split', 'warning', $reqLink . '?status=Pending%20Approval'],
      ['In Progress', req_count($conn, "r.requester_id = ? AND r.status IN ('Pending Custodian', 'Awaiting Procurement', 'Ready for Fulfillment')", 'i', [$userId]), 'bi-arrow-repeat', 'info', $reqLink],
      ['Fulfilled', req_count($conn, "r.requester_id = ? AND r.status = 'Fulfilled'", 'i', [$userId]), 'bi-check-circle', 'success', $reqLink . '?status=Fulfilled'],
    ];
    $panels = ['requisitions'];
    $reqTitle = 'My Recent Requisitions';
    $statement = $conn->prepare("SELECT r.req_number, r.status, r.created_at, r.borrow_from, r.borrow_until, u.full_name AS requester, $summary AS items FROM tbl_requisitions r INNER JOIN tbl_users u ON u.id = r.requester_id WHERE r.requester_id = ? ORDER BY r.id DESC LIMIT 5");
    $statement->bind_param('i', $userId);
    $statement->execute();
    $reqRows = $statement->get_result();
  } elseif ($role === 'procurement_personnel') {
    $cards = [
      ['Pending Requests', scalar($conn, "SELECT COUNT(*) FROM tbl_procurement WHERE status = 'Pending Approval'"), 'bi-hourglass-split', 'warning', 'pages/procurement.php'],
      ['Approved Requests', scalar($conn, "SELECT COUNT(*) FROM tbl_procurement WHERE status = 'Approved'"), 'bi-check-circle', 'success', 'pages/procurement.php'],
      ['Rejected Requests', scalar($conn, "SELECT COUNT(*) FROM tbl_procurement WHERE status = 'Rejected'"), 'bi-x-circle', 'danger', 'pages/procurement.php'],
    ];
    $panels = ['procurement'];
    $procRows = $conn->query("SELECT pr_number, item_name, quantity, status, source_trigger FROM tbl_procurement ORDER BY id DESC LIMIT 5");
  } else {
    $greeting = 'Your tools will appear here as they become available.';
  }

  if (in_array('maintenance', $panels, true)) {
    $upcoming = $conn->query("SELECT m.maintenance_type, m.due_date, m.status, i.asset_name, i.location
      FROM tbl_maintenance AS m INNER JOIN tbl_inventory AS i ON i.id = m.inventory_id
      WHERE m.status = 'Scheduled' ORDER BY m.due_date ASC LIMIT 5");
  }
  $statusBadges = [
    'Pending Approval' => 'bg-warning text-dark', 'Pending Custodian' => 'bg-info text-dark', 'Awaiting Procurement' => 'bg-primary',
    'Ready for Fulfillment' => 'bg-success', 'Fulfilled' => 'bg-secondary', 'Rejected' => 'bg-danger', 'Cancelled' => 'bg-dark',
  ];
  ?>
  <!DOCTYPE html>
  <html lang="en">

  <head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <base href="<?= APP_BASE_URL ?>/">

    <title>Dashboard - NiceAdmin Bootstrap Template</title>
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

    <!-- CSS FIX: Tinatanggal ang Search Bar, Entries Dropdown, at Showing Info -->
    <style>
      .datatable-top,
      .datatable-dropdown,
      .datatable-search,
      .datatable-info {
        display: none !important;
      }
    </style>
  </head>

  <body>

  <?php
    include __DIR__ . '/../includes/header.php';
    include __DIR__ . '/../includes/navbar.php';
  ?>

    <main id="main" class="main">

      <div class="pagetitle">
        <h1>Dashboard</h1>
        
      </div><!-- End Page Title -->

      <section class="section dashboard">
        <p class="text-muted mb-4">Welcome back, <strong><?= h($_SESSION['full_name'] ?? $_SESSION['username'] ?? '') ?></strong> <span class="badge bg-light text-dark border"><?= h(role_label($role)) ?></span> &middot; <?= h($greeting) ?></p>
        <div class="row">

          <?php foreach ($cards as [$title, $value, $icon, $color, $href]): ?>
            <div class="col-xl-3 col-md-6 mb-4">
              <div class="card info-card h-100 mb-0">
                <div class="card-body">
                  <h5 class="card-title text-center"><?= h($title) ?></h5>
                  <div class="d-flex align-items-center justify-content-start">
                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-<?= h($color) ?>-subtle text-<?= h($color) ?>">
                      <i class="bi <?= h($icon) ?>"></i>
                    </div>
                    <div class="ps-3">
                      <h6 class="mb-1"><?= (int) $value ?></h6>
                      <a class="link-offset-2 link-underline link-underline-opacity-0 small" href="<?= h($href) ?>">View all</a>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>

          <?php foreach ($panels as $panel): ?>
            <?php if ($panel === 'requisitions'): ?>
              <div class="col-12 mb-4">
                <div class="card recent-sales overflow-auto mb-0">
                  <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                      <h5 class="card-title fw-bold my-0"><?= h($reqTitle) ?></h5>
                      <a class="link-offset-2 link-underline link-underline-opacity-0 small" href="<?= h($reqLink) ?>">View all</a>
                    </div>
                    <table class="table table-borderless mt-3">
                      <thead><tr><th scope="col">Requisition</th><th scope="col">Items</th><th scope="col">Requester</th><th scope="col">Active period</th><th scope="col">Status</th></tr></thead>
                      <tbody>
                        <?php if ($reqRows->num_rows > 0): ?>
                          <?php while ($row = $reqRows->fetch_assoc()): ?>
                            <tr>
                              <td class="fw-bold"><?= h($row['req_number']) ?><div class="small text-muted fw-normal"><?= h(substr($row['created_at'], 0, 16)) ?></div></td>
                              <td><?= h($row['items']) ?></td>
                              <td><?= h($row['requester']) ?></td>
                              <td class="small"><?= $row['borrow_until'] ? ($row['borrow_from'] ? h(date('M j, g:i A', strtotime($row['borrow_from']))) . ' – ' : 'Until ') . h(date('M j, Y g:i A', strtotime($row['borrow_until']))) : '—' ?></td>
                              <td><span class="badge <?= h($statusBadges[$row['status']] ?? 'bg-secondary') ?>"><?= h($row['status']) ?></span></td>
                            </tr>
                          <?php endwhile; ?>
                        <?php else: ?>
                          <tr><td colspan="5" class="text-center text-muted py-4">Nothing here right now.</td></tr>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            <?php elseif ($panel === 'maintenance'): ?>
              <div class="col-12 mb-4">
                <div class="card recent-sales overflow-auto mb-0">
                  <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                      <h5 class="card-title fw-bold my-0">Upcoming Maintenance</h5>
                      <a class="link-offset-2 link-underline link-underline-opacity-0 small" href="pages/maintenance.php">View all</a>
                    </div>
                    <table class="table table-borderless mt-3">
                      <thead><tr><th scope="col">Asset</th><th scope="col">Type</th><th scope="col">Due Date</th><th scope="col">Status</th></tr></thead>
                      <tbody>
                        <?php if ($upcoming->num_rows > 0): ?>
                          <?php while ($schedule = $upcoming->fetch_assoc()): ?>
                            <?php $overdue = $schedule['due_date'] < date('Y-m-d'); ?>
                            <tr>
                              <td class="fw-bold"><?= h($schedule['asset_name']) ?> - <?= h($schedule['location']) ?></td>
                              <td><?= h($schedule['maintenance_type']) ?></td>
                              <td><?= h(date('M j, Y', strtotime($schedule['due_date']))) ?></td>
                              <td><span class="badge <?= $overdue ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= $overdue ? 'Overdue' : 'Scheduled' ?></span></td>
                            </tr>
                          <?php endwhile; ?>
                        <?php else: ?>
                          <tr><td colspan="4" class="text-center text-muted py-4">No maintenance schedules found.</td></tr>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            <?php elseif ($panel === 'procurement'): ?>
              <div class="col-12 mb-4">
                <div class="card recent-sales overflow-auto mb-0">
                  <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                      <h5 class="card-title fw-bold my-0">Recent Purchase Requests</h5>
                      <a class="link-offset-2 link-underline link-underline-opacity-0 small" href="pages/procurement.php">View all</a>
                    </div>
                    <table class="table table-borderless mt-3">
                      <thead><tr><th scope="col">PR No.</th><th scope="col">Item</th><th scope="col">Source</th><th scope="col">Status</th></tr></thead>
                      <tbody>
                        <?php if ($procRows->num_rows > 0): ?>
                          <?php while ($pr = $procRows->fetch_assoc()): ?>
                            <tr>
                              <td class="fw-bold"><?= h($pr['pr_number']) ?></td>
                              <td><?= h($pr['item_name']) ?><?php if ((int) $pr['quantity'] > 1): ?> <span class="text-muted">× <?= (int) $pr['quantity'] ?></span><?php endif; ?></td>
                              <td><?= h($pr['source_trigger']) ?></td>
                              <td><span class="badge <?= $pr['status'] === 'Approved' ? 'bg-success' : ($pr['status'] === 'Rejected' ? 'bg-danger' : 'bg-warning text-dark') ?>"><?= h($pr['status']) ?></span></td>
                            </tr>
                          <?php endwhile; ?>
                        <?php else: ?>
                          <tr><td colspan="4" class="text-center text-muted py-4">No purchase requests yet.</td></tr>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>

        </div><!-- End Row -->
      </section>

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

    <!-- Template Main JS File -->
    <script src="assets/js/main.js"></script>

  </body>

  </html>