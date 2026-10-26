<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_once __DIR__ . '/../config/db.php';

$flash = take_flash();
$csrfToken = csrf_token();
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'due', 'overdue', 'completed'], true)) {
  $filter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid form token. Refresh the page and try again.');
  }

  $action = $_POST['action'] ?? '';
  $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
  try {
    if ($action === 'save') {
      $inventoryId = filter_var($_POST['inventory_id'] ?? null, FILTER_VALIDATE_INT);
      $type = trim($_POST['maintenance_type'] ?? '');
      $dueDate = $_POST['due_date'] ?? '';
      $date = DateTime::createFromFormat('!Y-m-d', $dueDate);
      if (!$inventoryId || $type === '' || mb_strlen($type) > 120 || !$date || $date->format('Y-m-d') !== $dueDate) {
        throw new InvalidArgumentException('Select an asset, enter a maintenance type, and provide a valid due date.');
      }
      $statement = $conn->prepare('INSERT INTO tbl_maintenance (inventory_id, maintenance_type, due_date) VALUES (?, ?, ?)');
      $statement->bind_param('iss', $inventoryId, $type, $dueDate);
      $statement->execute();
      set_flash('Maintenance schedule added.');
    } elseif ($action === 'complete' && $id) {
      $statement = $conn->prepare("UPDATE tbl_maintenance SET status = 'Completed', completed_at = NOW() WHERE id = ? AND status <> 'Completed'");
      $statement->bind_param('i', $id);
      $statement->execute();
      set_flash('Maintenance marked complete.');
    } elseif ($action === 'delete' && $id) {
      $statement = $conn->prepare('DELETE FROM tbl_maintenance WHERE id = ?');
      $statement->bind_param('i', $id);
      $statement->execute();
      set_flash('Maintenance schedule deleted.');
    } else {
      throw new InvalidArgumentException('Invalid maintenance action.');
    }
  } catch (Throwable $exception) {
    error_log('Maintenance operation failed: ' . $exception->getMessage());
    set_flash($exception instanceof InvalidArgumentException
      ? $exception->getMessage()
      : 'The maintenance schedule could not be saved. Check the selected asset and try again.', 'danger');
  }

  header('Location: ' . APP_BASE_URL . '/pages/maintenance.php?filter=' . urlencode($filter));
  exit();
}

$where = match ($filter) {
  'due' => "m.status = 'Scheduled' AND m.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
  'overdue' => "m.status = 'Scheduled' AND m.due_date < CURDATE()",
  'completed' => "m.status = 'Completed'",
  default => '1 = 1',
};
$schedules = $conn->query("SELECT m.id, m.maintenance_type, m.due_date, m.status, i.asset_name, i.location
  FROM tbl_maintenance AS m INNER JOIN tbl_inventory AS i ON i.id = m.inventory_id
  WHERE $where ORDER BY m.due_date ASC, m.id DESC");
$counts = $conn->query("SELECT
  SUM(status = 'Scheduled' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS due_soon,
  SUM(status = 'Scheduled' AND due_date < CURDATE()) AS overdue,
  SUM(status = 'Completed') AS completed
  FROM tbl_maintenance")->fetch_assoc();
$assets = $conn->query("SELECT id, asset_name, location FROM tbl_inventory WHERE status <> 'Retired' ORDER BY asset_name");
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

  <!-- Custom Styles -->
  <style>
    .datatable-top,
    .datatable-dropdown,
    .datatable-search,
    .datatable-info {
      display: none !important;
    }

    .nav-tabs-custom .nav-link {
      color: #6c757d;
      font-weight: 500;
    }

    .nav-tabs-custom .nav-link.active {
      color: #0d6efd;
      border-bottom: 2px solid #0d6efd;
      font-weight: 600;
    }
  </style>
</head>

<body>

<?php
  include __DIR__ . '/../includes/header.php';
  include __DIR__ . '/../includes/navbar.php';
?>

  <!-- MAIN CONTAINER -->
  <main id="main" class="main">

    <div class="pagetitle">
      <h1>Maintenance Schedules</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="pages/dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Maintenance</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row">
        <div class="col-12">

          <div class="card recent-sales overflow-auto">
            <div class="card-body pt-3">

              <!-- Filter Tabs at Add Schedule Button -->
              <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <ul class="nav nav-tabs nav-tabs-custom border-0">
                  <li class="nav-item"><a class="nav-link <?= $filter === 'all' ? 'active' : '' ?>" href="pages/maintenance.php">All</a></li>
                  <li class="nav-item"><a class="nav-link <?= $filter === 'due' ? 'active' : '' ?>" href="pages/maintenance.php?filter=due">Due Soon <span class="badge rounded-pill bg-warning text-dark me-1"><?= (int) ($counts['due_soon'] ?? 0) ?></span></a></li>
                  <li class="nav-item"><a class="nav-link <?= $filter === 'overdue' ? 'active' : '' ?>" href="pages/maintenance.php?filter=overdue">Overdue <span class="badge rounded-pill bg-danger me-1"><?= (int) ($counts['overdue'] ?? 0) ?></span></a></li>
                  <li class="nav-item"><a class="nav-link <?= $filter === 'completed' ? 'active' : '' ?>" href="pages/maintenance.php?filter=completed">Completed <span class="badge rounded-pill bg-success"><?= (int) ($counts['completed'] ?? 0) ?></span></a></li>
                </ul>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#scheduleModal"><i class="bi bi-plus-lg me-1"></i> Add Schedule</button>
              </div>

              <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
                  <?= h($flash['message']) ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
              <?php endif; ?>

              <!-- Maintenance Table -->
              <table class="table table-borderless align-middle">
                <thead>
                  <tr>
                    <th scope="col">Asset</th>
                    <th scope="col">Type</th>
                    <th scope="col">Due Date</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-center">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($schedules->num_rows > 0): ?>
                    <?php while ($schedule = $schedules->fetch_assoc()): ?>
                      <?php
                        $dueDate = new DateTimeImmutable($schedule['due_date']);
                        $today = new DateTimeImmutable('today');
                        $displayStatus = $schedule['status'];
                        $badge = 'bg-info text-dark';
                        if ($displayStatus === 'Completed') {
                            $badge = 'bg-success';
                        } elseif ($dueDate < $today) {
                            $displayStatus = 'Overdue';
                            $badge = 'bg-danger';
                        } elseif ($dueDate <= $today->modify('+7 days')) {
                            $displayStatus = 'Due Soon';
                            $badge = 'bg-warning text-dark';
                        }
                      ?>
                      <tr>
                        <td class="fw-bold"><?= h($schedule['asset_name']) ?> - <?= h($schedule['location']) ?></td>
                        <td><?= h($schedule['maintenance_type']) ?></td>
                        <td><?= h($dueDate->format('M j, Y')) ?></td>
                        <td><span class="badge <?= $badge ?>"><?= h($displayStatus) ?></span></td>
                        <td class="text-center"><div class="d-inline-flex gap-2">
                          <?php if ($schedule['status'] !== 'Completed'): ?>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>"><button class="btn btn-outline-success btn-sm" name="action" value="complete" title="Mark completed"><i class="bi bi-check-lg"></i></button></form>
                          <?php endif; ?>
                          <form method="post" onsubmit="return confirm('Delete this maintenance schedule?');"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>"><button class="btn btn-outline-danger btn-sm" name="action" value="delete" title="Delete"><i class="bi bi-trash"></i></button></form>
                        </div></td>
                      </tr>
                    <?php endwhile; ?>
                  <?php else: ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No maintenance schedules found for this filter.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>

            </div>
          </div>

        </div>
      </div>
    </section>

    <div class="modal fade" id="scheduleModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="post" action="pages/maintenance.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="save">
            <div class="modal-header"><h5 class="modal-title">Add Maintenance Schedule</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
              <div class="mb-3"><label class="form-label" for="maintenanceAsset">Asset</label><select class="form-select" id="maintenanceAsset" name="inventory_id" required><option value="">Choose an asset</option><?php while ($asset = $assets->fetch_assoc()): ?><option value="<?= (int) $asset['id'] ?>"><?= h($asset['asset_name'] . ' — ' . $asset['location']) ?></option><?php endwhile; ?></select></div>
              <div class="mb-3"><label class="form-label" for="maintenanceType">Maintenance type</label><input class="form-control" id="maintenanceType" name="maintenance_type" maxlength="120" required></div>
              <div class="mb-3"><label class="form-label" for="maintenanceDueDate">Due date</label><input class="form-control" id="maintenanceDueDate" type="date" name="due_date" required></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Schedule</button></div>
          </form>
        </div>
      </div>
    </div>

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