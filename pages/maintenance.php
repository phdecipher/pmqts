<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_access('maintenance');
require_once __DIR__ . '/../config/db.php';

$flash = take_flash();
$csrfToken = csrf_token();
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'due', 'overdue', 'pulled', 'completed'], true)) {
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
    } elseif ($action === 'pull_out' && $id) {
      $conn->begin_transaction();
      $statement = $conn->prepare("UPDATE tbl_maintenance SET status = 'Pulled Out', pulled_out_at = NOW() WHERE id = ? AND status = 'Scheduled'");
      $statement->bind_param('i', $id);
      $statement->execute();
      if ($statement->affected_rows > 0) {
        $statement = $conn->prepare("UPDATE tbl_inventory i INNER JOIN tbl_maintenance m ON m.inventory_id = i.id SET i.status = 'Under Maintenance' WHERE m.id = ? AND i.status = 'Active'");
        $statement->bind_param('i', $id);
        $statement->execute();
      }
      $conn->commit();
      set_flash('Asset pulled out for repair.');
    } elseif ($action === 'complete' && $id) {
      $notes = trim($_POST['notes'] ?? '');
      if (mb_strlen($notes) > 1000) {
        throw new InvalidArgumentException('Notes must be 1000 characters or fewer.');
      }
      $notes = $notes === '' ? null : $notes;
      $conn->begin_transaction();
      $statement = $conn->prepare("UPDATE tbl_maintenance SET status = 'Completed', completed_at = NOW(), notes = COALESCE(?, notes) WHERE id = ? AND status <> 'Completed'");
      $statement->bind_param('si', $notes, $id);
      $statement->execute();
      if ($statement->affected_rows > 0) {
        $statement = $conn->prepare("UPDATE tbl_inventory i INNER JOIN tbl_maintenance m ON m.inventory_id = i.id SET i.status = 'Active' WHERE m.id = ? AND i.status = 'Under Maintenance' AND NOT EXISTS (SELECT 1 FROM tbl_maintenance o WHERE o.inventory_id = i.id AND o.status = 'Pulled Out')");
        $statement->bind_param('i', $id);
        $statement->execute();
      }
      $conn->commit();
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
    try { $conn->rollback(); } catch (Throwable $rollbackException) {}
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
  'pulled' => "m.status = 'Pulled Out'",
  'completed' => "m.status = 'Completed'",
  default => '1 = 1',
};
$schedules = $conn->query("SELECT m.id, m.maintenance_type, m.due_date, m.status, m.notes, m.pulled_out_at, m.completed_at, i.asset_name, i.asset_tag, i.location
  FROM tbl_maintenance AS m INNER JOIN tbl_inventory AS i ON i.id = m.inventory_id
  WHERE $where ORDER BY m.due_date ASC, m.id DESC");
$counts = $conn->query("SELECT
  SUM(status = 'Scheduled' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS due_soon,
  SUM(status = 'Scheduled' AND due_date < CURDATE()) AS overdue,
  SUM(status = 'Pulled Out') AS pulled,
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
  <link href="assets/css/responsive.css" rel="stylesheet">
  <script src="assets/js/responsive.js" defer></script>

  <!-- Custom Styles -->
  <style>
    .datatable-top,
    .datatable-dropdown,
    .datatable-search,
    .datatable-info {
      display: none !important;
    }

    .min-w-0 { min-width: 0; }
    .mt-item[hidden] { display: none !important; }
    .mt-card { border-left: 5px solid var(--mt-accent, #0dcaf0); }
    .mt-card.mt-soon { --mt-accent: #ffc107; }
    .mt-card.mt-overdue { --mt-accent: #dc3545; }
    .mt-card.mt-pulled { --mt-accent: #6c757d; }
    .mt-card.mt-completed { --mt-accent: #198754; }
    .mt-icon { width: 48px; height: 48px; flex: 0 0 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; color: #fff; background: var(--mt-accent, #0dcaf0); }
    .mt-card.mt-soon .mt-icon { color: #4d3a00; }
    .mt-box { border-radius: .6rem; padding: .5rem .75rem; border: 1px solid #d3e3ff; background: #f1f6ff; }
    .mt-due { background: #f8f9fa; border-color: #e3e6ea; }
    .mt-soon .mt-due { background: #fff8e6; border-color: #ffe29a; }
    .mt-overdue .mt-due { background: #fdecea; border-color: #f5b5ae; color: #842029; }
    .mt-label { font-size: .65rem; font-weight: 700; letter-spacing: .05em; color: #6c757d; }
    .mt-overdue .mt-label { color: #842029; }
    .mt-when { font-size: .75rem; font-weight: 600; border-radius: 999px; padding: 1px 8px; margin-left: .25rem; background: rgba(0, 0, 0, .07); }
    .mt-notes { background: #f8f9fa; border-left: 3px solid #ced4da; border-radius: .4rem; padding: .5rem .75rem; font-style: italic; font-size: .9rem; }  </style>
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
                <ul class="nav nav-pills gap-2 flex-wrap">
                  <li class="nav-item"><a class="nav-link <?= $filter === 'all' ? 'active' : 'bg-white border' ?>" href="pages/maintenance.php">All</a></li>
                  <li class="nav-item"><a class="nav-link <?= $filter === 'due' ? 'active' : 'bg-white border' ?>" href="pages/maintenance.php?filter=due">Due Soon <span class="badge rounded-pill bg-warning text-dark ms-1"><?= (int) ($counts['due_soon'] ?? 0) ?></span></a></li>
                  <li class="nav-item"><a class="nav-link <?= $filter === 'overdue' ? 'active' : 'bg-white border' ?>" href="pages/maintenance.php?filter=overdue">Overdue <span class="badge rounded-pill bg-danger ms-1"><?= (int) ($counts['overdue'] ?? 0) ?></span></a></li>
                  <li class="nav-item"><a class="nav-link <?= $filter === 'pulled' ? 'active' : 'bg-white border' ?>" href="pages/maintenance.php?filter=pulled">Pulled Out <span class="badge rounded-pill bg-secondary ms-1"><?= (int) ($counts['pulled'] ?? 0) ?></span></a></li>
                  <li class="nav-item"><a class="nav-link <?= $filter === 'completed' ? 'active' : 'bg-white border' ?>" href="pages/maintenance.php?filter=completed">Completed <span class="badge rounded-pill bg-success ms-1"><?= (int) ($counts['completed'] ?? 0) ?></span></a></li>
                </ul>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#scheduleModal"><i class="bi bi-plus-lg me-1"></i> Add Schedule</button>
              </div>

              <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
                  <?= h($flash['message']) ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
              <?php endif; ?>

              <!-- Maintenance Schedule Cards -->
              <?php $rows = $schedules->fetch_all(MYSQLI_ASSOC); $today = new DateTimeImmutable('today'); ?>
              <?php if (!$rows): ?>
                <div class="text-center text-muted py-5"><i class="bi bi-wrench-adjustable d-block fs-1 mb-2"></i>No maintenance schedules found for this filter.</div>
              <?php else: ?>
                <div class="input-group mb-3">
                  <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                  <input type="search" id="mtSearch" class="form-control" placeholder="Search asset, tag, location, type or notes" autocomplete="off">
                  <span class="input-group-text bg-white text-muted small"><span id="mtCount"><?= count($rows) ?></span>&nbsp;shown</span>
                </div>
                <div class="row g-3">
                  <?php foreach ($rows as $schedule): ?>
                    <?php
                      $dueDate = new DateTimeImmutable($schedule['due_date']);
                      $daysLeft = (int) $today->diff($dueDate)->format('%r%a');
                      $state = 'scheduled';
                      $displayStatus = 'Scheduled';
                      $badge = 'bg-info text-dark';
                      $when = $daysLeft === 0 ? 'Due today' : ($daysLeft > 0 ? 'in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') : abs($daysLeft) . ' day' . (abs($daysLeft) === 1 ? '' : 's') . ' overdue');
                      if ($schedule['status'] === 'Completed') {
                        $state = 'completed'; $displayStatus = 'Completed'; $badge = 'bg-success'; $when = '';
                      } elseif ($schedule['status'] === 'Pulled Out') {
                        $state = 'pulled'; $displayStatus = 'Pulled out'; $badge = 'bg-secondary'; $when = '';
                      } elseif ($daysLeft < 0) {
                        $state = 'overdue'; $displayStatus = 'Overdue'; $badge = 'bg-danger';
                      } elseif ($daysLeft <= 7) {
                        $state = 'soon'; $displayStatus = 'Due soon'; $badge = 'bg-warning text-dark';
                      }
                    ?>
                    <div class="col-12 col-xl-6 mt-item">
                      <div class="card mt-card mt-<?= $state ?> h-100 mb-0">
                        <div class="card-body p-4 d-flex flex-column">
                          <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                            <div class="d-flex align-items-center gap-3 min-w-0">
                              <div class="mt-icon"><i class="bi <?= $state === 'completed' ? 'bi-check2-circle' : ($state === 'pulled' ? 'bi-box-arrow-right' : 'bi-wrench-adjustable') ?>"></i></div>
                              <div class="min-w-0">
                                <div class="fw-bold fs-5 lh-sm"><?= h($schedule['maintenance_type']) ?></div>
                                <div class="text-muted"><?= h($schedule['asset_name']) ?> · <span class="font-monospace small"><?= h($schedule['asset_tag']) ?></span></div>
                              </div>
                            </div>
                            <span class="badge rounded-pill <?= $badge ?> flex-shrink-0"><?= h($displayStatus) ?></span>
                          </div>

                          <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                              <div class="mt-box mt-loc h-100">
                                <div class="mt-label"><i class="bi bi-geo-alt-fill text-primary me-1"></i>LOCATION</div>
                                <div class="fw-semibold"><?= h($schedule['location']) ?></div>
                              </div>
                            </div>
                            <div class="col-sm-6">
                              <div class="mt-box mt-due h-100">
                                <div class="mt-label"><i class="bi bi-calendar-event me-1"></i>DUE DATE</div>
                                <div><strong><?= h($dueDate->format('M j, Y')) ?></strong><?php if ($when !== ''): ?> <span class="mt-when"><?= h($when) ?></span><?php endif; ?></div>
                              </div>
                            </div>
                          </div>

                          <?php if ($schedule['pulled_out_at'] || $schedule['completed_at']): ?>
                            <div class="small text-muted mb-3">
                              <?php if ($schedule['pulled_out_at']): ?><div><i class="bi bi-box-arrow-right me-1"></i>Pulled out <strong class="text-body"><?= h(date('M j, Y, g:i A', strtotime($schedule['pulled_out_at']))) ?></strong></div><?php endif; ?>
                              <?php if ($schedule['completed_at']): ?><div><i class="bi bi-check2-circle text-success me-1"></i>Completed <strong class="text-body"><?= h(date('M j, Y, g:i A', strtotime($schedule['completed_at']))) ?></strong></div><?php endif; ?>
                            </div>
                          <?php endif; ?>

                          <?php if ($schedule['notes']): ?>
                            <div class="mt-notes mb-3"><i class="bi bi-chat-left-text me-1 text-muted"></i><?= h($schedule['notes']) ?></div>
                          <?php endif; ?>

                          <div class="d-flex gap-2 mt-auto pt-3 border-top justify-content-end flex-wrap">
                            <form method="post" class="confirm-form" data-icon="bi-trash3 text-danger" data-title="Delete this schedule?" data-message="The <?= h($schedule['maintenance_type']) ?> schedule for <?= h($schedule['asset_name']) ?> will be permanently removed." data-confirm="Delete" data-style="btn-danger">
                              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>">
                              <button class="btn btn-outline-danger" name="action" value="delete"><i class="bi bi-trash3 me-1"></i>Delete</button>
                            </form>
                            <?php if ($schedule['status'] === 'Scheduled'): ?>
                              <form method="post" class="confirm-form" data-icon="bi-box-arrow-right text-warning" data-title="Pull out for repair?" data-message="<?= h($schedule['asset_name']) ?> (<?= h($schedule['asset_tag']) ?>) will be marked Under Maintenance." data-confirm="Pull out" data-style="btn-warning">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>">
                                <button class="btn btn-outline-warning" name="action" value="pull_out"><i class="bi bi-box-arrow-right me-1"></i>Pull out</button>
                              </form>
                            <?php endif; ?>
                            <?php if ($schedule['status'] !== 'Completed'): ?>
                              <button type="button" class="btn btn-success complete-button" data-id="<?= (int) $schedule['id'] ?>" data-bs-toggle="modal" data-bs-target="#completeModal"><i class="bi bi-check2-circle me-1"></i>Mark completed</button>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="text-center text-muted py-5" id="mtEmpty" hidden><i class="bi bi-search d-block fs-1 mb-2"></i>No schedules match your search.</div>
              <?php endif; ?>

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

    <div class="modal fade" id="completeModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="post" action="pages/maintenance.php?filter=<?= h(urlencode($filter)) ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="id" id="completeId">
            <div class="modal-header"><h5 class="modal-title">Complete Maintenance</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
              <label class="form-label" for="completeNotes">Maintenance log notes (optional)</label>
              <textarea class="form-control" id="completeNotes" name="notes" rows="3" maxlength="1000"></textarea>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success">Mark Completed</button></div>
          </form>
        </div>
      </div>
    </div>

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
  <script src="assets/js/table-sort.js"></script>
  <script>
    const mtSearch = document.getElementById('mtSearch');
    if (mtSearch) {
      const mtItems = [...document.querySelectorAll('.mt-item')];
      mtSearch.addEventListener('input', () => {
        const terms = mtSearch.value.toLowerCase().split(/\s+/).filter(Boolean);
        let shown = 0;
        mtItems.forEach((item) => {
          const text = item.textContent.toLowerCase();
          const match = terms.every((term) => text.includes(term));
          item.hidden = !match;
          if (match) shown++;
        });
        document.getElementById('mtCount').textContent = shown;
        document.getElementById('mtEmpty').hidden = shown > 0;
      });
    }
    const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
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
    });    document.querySelectorAll('.complete-button').forEach((button) => {
      button.addEventListener('click', () => {
        document.getElementById('completeId').value = button.dataset.id;
        document.getElementById('completeNotes').value = '';
      });
    });
  </script>

</body>

</html>