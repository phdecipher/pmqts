<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_once __DIR__ . '/../config/db.php';

$token = $_GET['token'] ?? '';
$statement = $conn->prepare('SELECT id, asset_tag, asset_name, category, location, status, created_at FROM tbl_inventory WHERE qr_token = ? AND status <> \'Retired\' LIMIT 1');
$statement->bind_param('s', $token);
$statement->execute();
$asset = $statement->get_result()->fetch_assoc();
$upcoming = null;
if ($asset) {
    $statement = $conn->prepare('SELECT maintenance_type, due_date, status, completed_at FROM tbl_maintenance WHERE inventory_id = ? ORDER BY due_date DESC LIMIT 20');
    $statement->bind_param('i', $asset['id']);
    $statement->execute();
    $upcoming = $statement->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <base href="<?= h(APP_BASE_URL) ?>/">
  <title><?= $asset ? h($asset['asset_name']) . ' - Equipment Record' : 'Equipment Not Found' ?> - PMQTS</title>
  <link href="assets/img/favicon.png" rel="icon">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Nunito:300,400,600,700|Poppins:300,400,600,700" rel="stylesheet">
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/navbar.php'; ?>
<main id="main" class="main">
  <div class="pagetitle">
    <h1>Equipment Quality Record</h1>
    <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="pages/dashboard.php">Home</a></li><li class="breadcrumb-item"><a href="pages/inventory.php">Inventory</a></li><li class="breadcrumb-item active">Equipment Record</li></ol></nav>
  </div>

  <?php if (!$asset): ?>
    <div class="alert alert-warning">No inventory equipment was found for this QR code.</div>
    <a class="btn btn-primary" href="pages/inventory.php">Open Inventory</a>
  <?php else: ?>
    <section class="section">
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
              <div><span class="badge bg-primary-subtle text-primary mb-2">Equipment</span><h2 class="h4"><?= h($asset['asset_name']) ?></h2></div>
              <span class="badge <?= $asset['status'] === 'Active' ? 'bg-success' : ($asset['status'] === 'Retired' ? 'bg-secondary' : 'bg-warning text-dark') ?>"><?= h($asset['status']) ?></span>
            </div>
            <dl class="row mt-4 mb-0">
              <dt class="col-sm-5">Asset tag</dt><dd class="col-sm-7 font-monospace fw-bold"><?= h($asset['asset_tag']) ?></dd>
              <dt class="col-sm-5">Category</dt><dd class="col-sm-7"><?= h($asset['category']) ?></dd>
              <dt class="col-sm-5">Location</dt><dd class="col-sm-7"><?= h($asset['location']) ?></dd>
              <dt class="col-sm-5">Added</dt><dd class="col-sm-7"><?= h(date('M j, Y', strtotime($asset['created_at']))) ?></dd>
            </dl>
            <div class="d-flex gap-2 mt-3">
              <a class="btn btn-outline-primary" href="pages/qr-generator.php?id=<?= (int) $asset['id'] ?>"><i class="bi bi-qr-code me-1"></i> Print QR label</a>
              <a class="btn btn-outline-secondary" href="pages/maintenance.php"><i class="bi bi-wrench me-1"></i> Maintenance</a>
            </div>
          </div></div>
        </div>
        <div class="col-lg-7">
          <div class="card h-100"><div class="card-body">
            <h2 class="h5 card-title">Quality & maintenance history</h2>
            <?php if ($upcoming && $upcoming->num_rows > 0): ?>
              <div class="table-responsive"><table class="table align-middle">
                <thead><tr><th>Maintenance</th><th>Due date</th><th>Status</th></tr></thead>
                <tbody><?php while ($schedule = $upcoming->fetch_assoc()): ?>
                  <tr><td><?= h($schedule['maintenance_type']) ?></td><td><?= h(date('M j, Y', strtotime($schedule['due_date']))) ?></td><td><span class="badge <?= $schedule['status'] === 'Completed' ? 'bg-success' : 'bg-info text-dark' ?>"><?= h($schedule['status']) ?></span></td></tr>
                <?php endwhile; ?></tbody>
              </table></div>
            <?php else: ?>
              <div class="text-center text-muted py-5"><i class="bi bi-clipboard-check display-5"></i><p class="mt-2 mb-0">No maintenance history has been recorded for this equipment.</p></div>
            <?php endif; ?>
          </div></div>
        </div>
      </div>
    </section>
  <?php endif; ?>
</main>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js"></script>
</body>
</html>
