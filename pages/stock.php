<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_access('stock');
require_once __DIR__ . '/../config/db.php';

$search = trim($_GET['q'] ?? '');
$sql = "SELECT i.asset_name, MIN(i.category) AS category,
    SUM(CASE WHEN i.status = 'Active' THEN " . STOCK_DISPLAY_EXPR . " ELSE 0 END) AS available
  FROM tbl_inventory i
  WHERE i.status <> 'Retired'";
if ($search !== '') {
  $like = '%' . $search . '%';
  $statement = $conn->prepare($sql . ' AND (i.asset_name LIKE ? OR i.category LIKE ?) GROUP BY i.asset_name ORDER BY i.asset_name');
  $statement->bind_param('ss', $like, $like);
  $statement->execute();
  $items = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
  $items = $conn->query($sql . ' GROUP BY i.asset_name ORDER BY i.asset_name')->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <base href="<?= APP_BASE_URL ?>/">
  <title>Item Stock - NiceAdmin Template</title>
  <link href="assets/img/favicon.png" rel="icon">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Nunito:300,400,600,700|Poppins:300,400,500,600,700" rel="stylesheet">
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
  <link href="assets/css/responsive.css" rel="stylesheet">
  <script src="assets/js/responsive.js" defer></script>
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
          <h1>Item Stock</h1>
          <nav>
            <ol class="breadcrumb">
              <li class="breadcrumb-item"><a href="pages/dashboard.php">Home</a></li>
              <li class="breadcrumb-item active">Item Stock</li>
            </ol>
          </nav>
        </div>
        <a class="btn btn-primary" href="pages/requisitions.php"><i class="bi bi-clipboard-check me-1"></i> Go to Requisitions</a>
      </div>

      <section class="section">
        <div class="mb-3">
          <form class="d-flex" method="GET" action="pages/stock.php">
            <div class="input-group">
              <input type="text" name="q" id="stockSearch" value="<?= h($search) ?>" class="form-control" placeholder="Search items..." autocomplete="off">
              <button type="submit" class="btn btn-primary" title="Search"><i class="bi bi-search"></i></button>
            </div>
          </form>
        </div>

        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="d-flex flex-wrap gap-2" id="stockCategories">
            <button type="button" class="btn btn-sm btn-primary" data-cat="">All</button>
            <?php foreach (array_values(array_unique(array_column($items, 'category'))) as $cat): ?>
              <button type="button" class="btn btn-sm btn-outline-primary" data-cat="<?= h($cat) ?>"><?= h($cat) ?></button>
            <?php endforeach; ?>
          </div>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="stockOnly">
            <label class="form-check-label small" for="stockOnly">In stock only</label>
          </div>
        </div>
        <div class="small text-muted mb-3"><span id="stockCount"><?= count($items) ?></span> item(s)</div>

        <div class="row g-3" id="stockGrid">
          <?php foreach ($items as $item): ?>
            <?php
              $available = (int) $item['available'];
              $cat = strtolower($item['category'] ?? '');
              $icon = $cat === 'furniture' ? 'bi-lamp' : ($cat === 'fixture' || $cat === 'fixtures' ? 'bi-lightbulb' : ($cat === 'equipment' ? 'bi-pc-display' : 'bi-box-seam'));
              $level = $available <= 0 ? 'danger' : ($available <= 3 ? 'warning' : 'success');
              $label = $available <= 0 ? 'Out of stock' : ($available <= 3 ? 'Low stock' : 'In stock');
            ?>
            <div class="col-6 col-md-4 col-xl-3 stock-tile" data-cat="<?= h($item['category']) ?>" data-avail="<?= $available ?>">
              <div class="card h-100 mb-0 border <?= $available <= 0 ? 'opacity-75' : '' ?>">
                <div class="card-body p-3 text-center d-flex flex-column">
                  <div class="rounded bg-light text-secondary d-flex align-items-center justify-content-center mb-2" style="height:90px"><i class="bi <?= $icon ?>" style="font-size:2.5rem"></i></div>
                  <div class="fw-bold stock-name"><?= h($item['asset_name']) ?></div>
                  <div class="small text-muted mb-2"><?= h($item['category']) ?></div>
                  <div class="mt-auto">
                    <div class="fs-4 fw-bold lh-1"><?= $available ?></div>
                    <div class="small text-muted mb-2">available</div>
                    <span class="badge bg-<?= $level ?>"><?= $label ?></span>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div id="stockEmpty" class="text-center text-muted py-5" <?= $items ? 'hidden' : '' ?>><i class="bi bi-search d-block fs-2"></i>No items found.</div>
      </section>

    </div>
  </main>

  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
  <script src="assets/js/table-sort.js"></script>
  <script>
    (() => {
      const input = document.getElementById('stockSearch');
      const tiles = [...document.querySelectorAll('.stock-tile')];
      const empty = document.getElementById('stockEmpty');
      const count = document.getElementById('stockCount');
      const only = document.getElementById('stockOnly');
      const chips = [...document.querySelectorAll('#stockCategories button')];
      let cat = '';
      const filter = () => {
        const terms = input.value.toLowerCase().split(/\s+/).filter(Boolean);
        let shown = 0;
        tiles.forEach((tile) => {
          const text = tile.textContent.toLowerCase();
          const match = terms.every((t) => text.includes(t))
            && (!cat || tile.dataset.cat === cat)
            && (!only.checked || Number(tile.dataset.avail) > 0);
          tile.hidden = !match;
          if (match) shown++;
        });
        count.textContent = shown;
        empty.hidden = shown > 0;
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
      only.addEventListener('change', filter);
      input.addEventListener('input', filter);
      input.form.addEventListener('submit', (event) => event.preventDefault());
      filter();
    })();
  </script>
</body>
</html>
