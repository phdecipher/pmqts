<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_access('inventory');
require_once __DIR__ . '/../config/db.php';

$methods = ['Donation', 'Scrap', 'Thrown Away'];
$method = $_GET['method'] ?? 'all';
if ($method !== 'all' && !in_array($method, $methods, true)) {
  $method = 'all';
}

$sql = "SELECT i.asset_tag, i.asset_name, i.category, i.location, i.acquisition_cost, i.disposal_method, i.disposal_date
  FROM tbl_inventory i WHERE i.status = 'Retired'";
if ($method !== 'all') {
  $statement = $conn->prepare($sql . ' AND i.disposal_method = ? ORDER BY i.disposal_date DESC, i.id DESC');
  $statement->bind_param('s', $method);
  $statement->execute();
  $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
  $rows = $conn->query($sql . ' ORDER BY i.disposal_date DESC, i.id DESC')->fetch_all(MYSQLI_ASSOC);
}
$icons = ['Donation' => 'bi-gift', 'Scrap' => 'bi-recycle', 'Thrown Away' => 'bi-trash3'];
$methodClass = ['Donation' => 'ds-donation', 'Scrap' => 'ds-scrap', 'Thrown Away' => 'ds-thrown'];
$counts = array_fill_keys($methods, 0);
$total = 0;
$result = $conn->query("SELECT disposal_method, COUNT(*) AS total FROM tbl_inventory WHERE status = 'Retired' GROUP BY disposal_method");
while ($row = $result->fetch_assoc()) {
  $total += (int) $row['total'];
  if (isset($counts[$row['disposal_method']])) {
    $counts[$row['disposal_method']] = (int) $row['total'];
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <base href="<?= APP_BASE_URL ?>/">
  <title>Disposal Records - NiceAdmin Template</title>
  <link href="assets/img/favicon.png" rel="icon">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Nunito:300,400,600,700|Poppins:300,400,500,600,700" rel="stylesheet">
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
  <link href="assets/css/responsive.css" rel="stylesheet">
  <script src="assets/js/responsive.js" defer></script>
  <style>
    .min-w-0 { min-width: 0; }
    .ds-item[hidden] { display: none !important; }
    .ds-card { border: 1px solid #e3e6ea; border-left: 5px solid var(--ds-accent, #6c757d); border-radius: .75rem; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
    .ds-card.ds-donation { --ds-accent: #198754; }
    .ds-card.ds-scrap { --ds-accent: #fd7e14; }
    .ds-card.ds-thrown { --ds-accent: #dc3545; }
    .ds-icon { width: 44px; height: 44px; flex: 0 0 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; color: #fff; background: var(--ds-accent, #6c757d); }
    .ds-badge { background: var(--ds-accent, #6c757d); }
    .ds-box { border-radius: .6rem; padding: .5rem .75rem; border: 1px solid #e3e6ea; background: #f8f9fa; }
    .ds-label { font-size: .65rem; font-weight: 700; letter-spacing: .05em; color: #6c757d; }
  </style></head>

<body>
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

  <main id="main" class="main">
    <div class="container-fluid">

      <div class="pagetitle">
        <h1>Disposal Records</h1>
        <nav>
          <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="pages/dashboard.php">Home</a></li>
            <li class="breadcrumb-item active">Disposal</li>
          </ol>
        </nav>
      </div>

      <section class="section">
        <div class="card">
          <div class="card-body pt-3">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
              <ul class="nav nav-pills gap-2 flex-wrap">
                <li class="nav-item"><a class="nav-link <?= $method === 'all' ? 'active' : 'bg-white border' ?>" href="pages/disposal.php">All <span class="badge rounded-pill bg-secondary ms-1"><?= $total ?></span></a></li>
                <?php foreach ($methods as $option): ?>
                  <li class="nav-item"><a class="nav-link <?= $method === $option ? 'active' : 'bg-white border' ?>" href="pages/disposal.php?method=<?= h(urlencode($option)) ?>"><i class="bi <?= $icons[$option] ?> me-1"></i><?= h($option) ?> <span class="badge rounded-pill bg-secondary ms-1"><?= $counts[$option] ?></span></a></li>
                <?php endforeach; ?>
              </ul>
              <div class="input-group" style="max-width: 320px;">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="search" id="dsSearch" class="form-control" placeholder="Search asset, tag, location…" aria-label="Search disposal records">
              </div>
            </div>
            <div class="small text-muted mb-3"><span id="dsCount"><?= count($rows) ?></span> record(s)</div>

            <?php if (!$rows): ?>
              <div class="text-center text-muted py-5"><i class="bi bi-trash3 fs-1 d-block mb-2"></i>No disposed or retired assets found.</div>
            <?php endif; ?>
            <div id="dsEmpty" class="text-center text-muted py-5" hidden><i class="bi bi-search fs-1 d-block mb-2"></i>No records match your search.</div>

            <div class="row g-3" id="dsList">
              <?php foreach ($rows as $row):
                $m = $row['disposal_method'] ?? '';
                $cls = $methodClass[$m] ?? 'ds-none';
                $icon = $icons[$m] ?? 'bi-question-circle';
                $search = strtolower(implode(' ', [$row['asset_tag'], $row['asset_name'], $row['category'], $row['location'], $m]));
              ?>
                <div class="col-12 col-md-6 col-xl-4 ds-item" data-search="<?= h($search) ?>">
                  <div class="ds-card <?= $cls ?> h-100 p-3">
                    <div class="d-flex align-items-start gap-3">
                      <div class="ds-icon"><i class="bi <?= $icon ?>"></i></div>
                      <div class="min-w-0 flex-grow-1">
                        <div class="fw-bold text-truncate"><?= h($row['asset_name']) ?></div>
                        <div class="small text-muted"><?= h($row['asset_tag']) ?> · <?= h($row['category']) ?></div>
                      </div>
                      <span class="badge ds-badge"><?= h($m !== '' ? $m : 'Not recorded') ?></span>
                    </div>
                    <div class="row g-2 mt-2">
                      <div class="col-6"><div class="ds-box"><div class="ds-label">LAST LOCATION</div><div class="fw-semibold small"><?= h($row['location'] ?: '—') ?></div></div></div>
                      <div class="col-6"><div class="ds-box"><div class="ds-label">DISPOSED ON</div><div class="fw-semibold small"><?= $row['disposal_date'] ? h(date('M j, Y', strtotime($row['disposal_date']))) : '—' ?></div></div></div>
                      <div class="col-12"><div class="ds-box"><div class="ds-label">ACQUISITION COST</div><div class="fw-semibold small"><?= $row['acquisition_cost'] !== null ? '₱' . number_format((float) $row['acquisition_cost'], 2) : '—' ?></div></div></div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </section>

    </div>
  </main>

  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
  <script>
    (function () {
      var input = document.getElementById('dsSearch');
      var items = document.querySelectorAll('.ds-item');
      var count = document.getElementById('dsCount');
      var empty = document.getElementById('dsEmpty');
      input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase(), shown = 0;
        items.forEach(function (el) {
          var ok = el.dataset.search.indexOf(q) !== -1;
          el.hidden = !ok;
          if (ok) shown++;
        });
        count.textContent = shown;
        empty.hidden = shown > 0 || items.length === 0;
      });
    })();
  </script></body>
</html>
