  <?php
  require_once __DIR__ . '/../config/app.php';
  require_login();
  require_once __DIR__ . '/../config/db.php';

  $metrics = $conn->query("SELECT
    COUNT(*) AS total_assets,
    SUM(status = 'Active') AS active_assets,
    SUM(status = 'For Replacement') AS replacement_assets
    FROM tbl_inventory")->fetch_assoc();
  $dueMetric = $conn->query("SELECT COUNT(*) AS due_count FROM tbl_maintenance
    WHERE status = 'Scheduled' AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc();
  $upcoming = $conn->query("SELECT m.maintenance_type, m.due_date, m.status, i.asset_name, i.location
    FROM tbl_maintenance AS m INNER JOIN tbl_inventory AS i ON i.id = m.inventory_id
    WHERE m.status = 'Scheduled' ORDER BY m.due_date ASC LIMIT 5");
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
        <div class="row">

          <!-- 1. Total Assets (Blue Icon) -->
          <div class="col-xl-3 col-md-6 mb-4">
            <div class="card info-card sales-card h-100 mb-0">
              <div class="card-body">
                <h5 class="card-title text-center">Total Assets</h5>
                <div class="d-flex align-items-center justify-content-start">
                  <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-primary-subtle text-primary">
                    <i class="bi bi-box"></i>
                  </div>
                  <div class="ps-3">
                    <h6 class="mb-1"><?= (int) ($metrics['total_assets'] ?? 0) ?></h6>
                    <a class="link-offset-2 link-underline link-underline-opacity-0 small" href="pages/inventory.php">View all</a>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 2. Active Assets (Green Icon) -->
          <div class="col-xl-3 col-md-6 mb-4">
            <div class="card info-card revenue-card h-100 mb-0">
              <div class="card-body">
                <h5 class="card-title text-center">Active Assets</h5>
                <div class="d-flex align-items-center justify-content-start">
                  <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-success-subtle text-success">
                    <i class="bi bi-check-circle"></i>
                  </div>
                  <div class="ps-3">
                    <h6 class="mb-1"><?= (int) ($metrics['active_assets'] ?? 0) ?></h6>
                    <a class="link-offset-2 link-underline link-underline-opacity-0 small" href="pages/inventory.php">View all</a>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 3. Maintenance Due (Orange/Warning Icon) -->
          <div class="col-xl-3 col-md-6 mb-4">
            <div class="card info-card h-100 mb-0">
              <div class="card-body">
                <h5 class="card-title text-center">Maintenance Due</h5>
                <div class="d-flex align-items-center justify-content-start">
                  <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-warning-subtle text-warning">
                    <i class="bi bi-tools"></i>
                  </div>
                  <div class="ps-3">
                    <h6 class="mb-1"><?= (int) ($dueMetric['due_count'] ?? 0) ?></h6>
                    <a class="link-offset-2 link-underline link-underline-opacity-0 small" href="pages/maintenance.php?filter=due">View all</a>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 4. For Replacement (Red/Danger Icon) -->
          <div class="col-xl-3 col-md-6 mb-4">
            <div class="card info-card customers-card h-100 mb-0">
              <div class="card-body">
                <h5 class="card-title text-center">For Replacement</h5>
                <div class="d-flex align-items-center justify-content-start">
                  <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-danger-subtle text-danger">
                    <i class="bi bi-arrow-repeat"></i>
                  </div>
                  <div class="ps-3">
                    <h6 class="mb-1"><?= (int) ($metrics['replacement_assets'] ?? 0) ?></h6>
                    <a class="link-offset-2 link-underline link-underline-opacity-0 small" href="pages/inventory.php?q=For%20Replacement">View all</a>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 5. Upcoming Maintenance Table Card -->
          <div class="col-12 mb-4">
            <div class="card recent-sales overflow-auto mb-0">
              <div class="card-body">
                <!-- Title at View All link -->
                <div class="d-flex align-items-center justify-content-between">
                  <h5 class="card-title fw-bold my-0">Upcoming Maintenance</h5>
                  <a class="link-offset-2 link-underline link-underline-opacity-0 small" href="pages/maintenance.php">View all</a>
                </div>

                <table class="table table-borderless mt-3">
                  <thead>
                    <tr>
                      <th scope="col">Asset</th>
                      <th scope="col">Type</th>
                      <th scope="col">Due Date</th>
                      <th scope="col">Status</th>
                    </tr>
                  </thead>
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