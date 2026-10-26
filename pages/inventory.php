<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_once __DIR__ . '/../config/db.php';

$flash = take_flash();
$csrfToken = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid form token. Refresh the page and try again.');
  }

  $action = $_POST['action'] ?? '';
  $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
  try {
    if (in_array($action, ['save', 'update'], true)) {
      $assetTag = trim($_POST['asset_tag'] ?? '');
      $assetName = trim($_POST['asset_name'] ?? '');
      $category = trim($_POST['category'] ?? '');
      $location = trim($_POST['location'] ?? '');
      $status = $_POST['status'] ?? 'Active';
      if ($assetTag === '' || mb_strlen($assetTag) > 40 || $assetName === '' || mb_strlen($assetName) > 180
        || mb_strlen($category) > 100 || mb_strlen($location) > 150
                || !in_array($status, ['Active', 'Under Maintenance', 'For Replacement', 'Retired'], true)) {
        throw new InvalidArgumentException('Enter valid asset details and select a valid status.');
      }

      if ($action === 'update' && $id) {
        $statement = $conn->prepare('UPDATE tbl_inventory SET asset_tag = ?, asset_name = ?, category = ?, location = ?, status = ? WHERE id = ?');
        $statement->bind_param('sssssi', $assetTag, $assetName, $category, $location, $status, $id);
      } else {
        $statement = $conn->prepare('INSERT INTO tbl_inventory (asset_tag, asset_name, category, location, status) VALUES (?, ?, ?, ?, ?)');
        $statement->bind_param('sssss', $assetTag, $assetName, $category, $location, $status);
      }
      $statement->execute();
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
    error_log('Inventory operation failed: ' . $exception->getMessage());
    set_flash($exception instanceof InvalidArgumentException
      ? $exception->getMessage()
      : 'The asset could not be saved. Check for a duplicate asset tag and try again.', 'danger');
  }

  header('Location: ' . APP_BASE_URL . '/pages/inventory.php');
  exit();
}

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
  $like = '%' . $search . '%';
  $statement = $conn->prepare('SELECT id, asset_tag, asset_name, category, location, status FROM tbl_inventory WHERE asset_tag LIKE ? OR asset_name LIKE ? OR category LIKE ? OR location LIKE ? ORDER BY id DESC');
  $statement->bind_param('ssss', $like, $like, $like, $like);
  $statement->execute();
  $assets = $statement->get_result();
} else {
  $assets = $conn->query('SELECT id, asset_tag, asset_name, category, location, status FROM tbl_inventory ORDER BY id DESC');
}
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
                <input type="text" name="q" value="<?= h($search) ?>" class="form-control" placeholder="Search assets..." title="Enter search keyword">
                <button type="submit" class="btn btn-primary" title="Search">
                  <i class="bi bi-search"></i>
                </button>
              </div>
            </form>
          </div>

          <!-- Upcoming Maintenance Table Card -->
          <div class="col-12 mb-4">
            <div class="card recent-sales overflow-auto mb-0">
              <div class="card-body">
                
                <!-- Title at View All link -->
                <div class="d-flex align-items-center justify-content-between pt-3 mb-3">
                  <h5 class="card-title fw-bold my-0">Inventory Assets</h5>
                  <span class="small text-muted"><?= $assets->num_rows ?> asset(s)</span>
                </div>

                <!-- Table na may Grid / Harang -->
                <table class="table table-bordered table-hover table-grid">
                  <thead>
                    <tr>
                      <th scope="col">Asset Tag</th>
                      <th scope="col">Asset Name</th>
                      <th scope="col">Category</th>
                      <th scope="col">Location</th>  
                      <th scope="col">Status</th>
                      <th scope="col" class="text-center">Actions</th>
                    </tr>
                  </thead>
                  <tbody>

                    <?php if ($assets->num_rows > 0): ?>
                      <?php while ($asset = $assets->fetch_assoc()): ?>
                        <?php $statusClass = $asset['status'] === 'Active' ? 'success' : ($asset['status'] === 'Retired' ? 'secondary' : 'warning text-dark'); ?>
                        <tr>
                          <td class="fw-bold"><?= h($asset['asset_tag']) ?></td>
                          <td><?= h($asset['asset_name']) ?></td>
                          <td><?= h($asset['category']) ?></td>
                          <td><?= h($asset['location']) ?></td>
                          <td><span class="badge bg-<?= $statusClass ?>"><?= h($asset['status']) ?></span></td>
                          <td class="text-center">
                            <div class="d-inline-flex gap-2">
                              <a class="btn btn-outline-primary btn-sm" title="Generate QR label" href="pages/qr-generator.php?id=<?= (int) $asset['id'] ?>"><i class="bi bi-qr-code"></i></a>
                              <button type="button" class="btn btn-outline-secondary btn-sm edit-asset-button" title="Edit"
                                data-id="<?= (int) $asset['id'] ?>" data-tag="<?= h($asset['asset_tag']) ?>"
                                data-name="<?= h($asset['asset_name']) ?>" data-category="<?= h($asset['category']) ?>"
                                data-location="<?= h($asset['location']) ?>" data-status="<?= h($asset['status']) ?>"
                                data-bs-toggle="modal" data-bs-target="#assetModal"><i class="bi bi-pencil-square"></i></button>
                              <form method="post" onsubmit="return confirm('Delete this asset and its schedules?');">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= (int) $asset['id'] ?>">
                                <button class="btn btn-outline-danger btn-sm" name="action" value="delete" title="Delete"><i class="bi bi-trash"></i></button>
                              </form>
                            </div>
                          </td>
                        </tr>
                      <?php endwhile; ?>
                    <?php else: ?>
                      <tr><td colspan="6" class="text-center text-muted py-4">No assets found. Add an asset or update your search.</td></tr>
                    <?php endif; ?>

                  </tbody>
                </table>

              </div>
            </div>
          </div>

        </div><!-- End Row -->
      </section>

      <div class="modal fade" id="assetModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <form method="post" action="pages/inventory.php">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <input type="hidden" name="id" id="assetId">
              <input type="hidden" name="action" id="assetAction" value="save">
              <div class="modal-header"><h5 class="modal-title" id="assetModalTitle">Add Asset</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
              <div class="modal-body">
                <div class="mb-3"><label class="form-label" for="assetTag">Asset tag</label><input class="form-control" id="assetTag" name="asset_tag" maxlength="40" required></div>
                <div class="mb-3"><label class="form-label" for="assetName">Asset name</label><input class="form-control" id="assetName" name="asset_name" maxlength="180" required></div>
                <div class="mb-3"><label class="form-label" for="assetCategory">Category</label><input class="form-control" id="assetCategory" name="category" maxlength="100" required></div>
                <div class="mb-3"><label class="form-label" for="assetLocation">Location</label><input class="form-control" id="assetLocation" name="location" maxlength="150" required></div>
                <div class="mb-3"><label class="form-label" for="assetStatus">Status</label><select class="form-select" id="assetStatus" name="status"><option>Active</option><option>Under Maintenance</option><option>For Replacement</option><option>Retired</option></select></div>
              </div>
              <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Asset</button></div>
            </form>
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
      const modal = document.getElementById('assetModal');
      const reset = () => {
        document.getElementById('assetModalTitle').textContent = 'Add Asset';
        document.getElementById('assetAction').value = 'save';
        document.getElementById('assetId').value = '';
        ['assetTag', 'assetName', 'assetCategory', 'assetLocation'].forEach((id) => {
          document.getElementById(id).value = '';
        });
        document.getElementById('assetStatus').value = 'Active';
      };
      document.getElementById('addAssetButton').addEventListener('click', reset);
      document.querySelectorAll('.edit-asset-button').forEach((button) => {
        button.addEventListener('click', () => {
          document.getElementById('assetModalTitle').textContent = 'Edit Asset';
          document.getElementById('assetAction').value = 'update';
          document.getElementById('assetId').value = button.dataset.id;
          document.getElementById('assetTag').value = button.dataset.tag;
          document.getElementById('assetName').value = button.dataset.name;
          document.getElementById('assetCategory').value = button.dataset.category;
          document.getElementById('assetLocation').value = button.dataset.location;
          document.getElementById('assetStatus').value = button.dataset.status;
        });
      });
    })();
  </script>

  <!-- Template Main JS File -->
  <script src="assets/js/main.js"></script>

</body>

</html>