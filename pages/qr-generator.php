<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_once __DIR__ . '/../config/db.php';

$assets = $conn->query("SELECT id, asset_tag, qr_token, asset_name, category, location, status FROM tbl_inventory WHERE status <> 'Retired' ORDER BY asset_name");
$selectedId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <base href="<?= h(APP_BASE_URL) ?>/">
  <title>Equipment QR Labels - PMQTS</title>
  <link href="assets/img/favicon.png" rel="icon">
  <link href="https://fonts.googleapis.com" rel="preconnect">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Nunito:300,400,600,700|Poppins:300,400,600,700" rel="stylesheet">
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
  <style>
    .qr-workspace { display:grid; grid-template-columns:minmax(280px, .8fr) minmax(320px, 1.2fr); gap:1.25rem; }
    .equipment-label { background:#fff; border:1px solid #dce3eb; border-radius:16px; padding:28px; text-align:center; max-width:420px; margin:auto; }
    #qr-code { display:flex; justify-content:center; min-height:230px; align-items:center; margin:16px auto; }
    #qr-code img, #qr-code canvas { max-width:100%; height:auto !important; }
    .label-brand { color:#64748b; font-size:.75rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase; }
    .label-tag { font-family:monospace; font-weight:800; letter-spacing:.08em; }
    @media (max-width: 900px) { .qr-workspace { grid-template-columns:1fr; } }
    @media print {
      body * { visibility:hidden !important; }
      #equipment-label, #equipment-label * { visibility:visible !important; }
      #equipment-label { position:absolute; left:0; top:0; width:100%; max-width:none; border:0; border-radius:0; box-shadow:none; }
      .label-instructions { display:none !important; }
      @page { margin:10mm; }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/navbar.php'; ?>
<main id="main" class="main">
  <div class="pagetitle">
    <h1>Equipment QR Labels</h1>
    <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="pages/dashboard.php">Home</a></li><li class="breadcrumb-item active">QR Labels</li></ol></nav>
  </div>

  <section class="section">
    <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Each QR code opens the matching equipment record in PMQTS. Print the label and attach it to the equipment for quick quality-tracking access.</div>
    <?php if ($assets->num_rows === 0): ?>
      <div class="card"><div class="card-body text-center py-5">
        <i class="bi bi-box-seam display-4 text-muted"></i>
        <h2 class="h5 mt-3">No equipment available</h2>
        <p class="text-muted">Add equipment to inventory before generating QR labels.</p>
        <a class="btn btn-primary" href="pages/inventory.php">Open Inventory</a>
      </div></div>
    <?php else: ?>
      <div class="qr-workspace">
        <div class="card"><div class="card-body">
          <h2 class="h5 card-title">Choose equipment</h2>
          <label class="form-label" for="asset-select">Inventory asset</label>
          <select class="form-select" id="asset-select">
            <option value="">Select an asset...</option>
            <?php while ($asset = $assets->fetch_assoc()): ?>
              <?php if (empty($asset['qr_token'])): ?>
                <?php
                  $asset['qr_token'] = bin2hex(random_bytes(32));
                  $tokenUpdate = $conn->prepare('UPDATE tbl_inventory SET qr_token = ? WHERE id = ? AND qr_token IS NULL');
                  $tokenUpdate->bind_param('si', $asset['qr_token'], $asset['id']);
                  $tokenUpdate->execute();
                ?>
              <?php endif; ?>
              <option value="<?= (int) $asset['id'] ?>" data-token="<?= h($asset['qr_token']) ?>" data-tag="<?= h($asset['asset_tag']) ?>" data-name="<?= h($asset['asset_name']) ?>" data-category="<?= h($asset['category']) ?>" data-location="<?= h($asset['location']) ?>" data-status="<?= h($asset['status']) ?>" <?= $selectedId === (int) $asset['id'] ? 'selected' : '' ?>><?= h($asset['asset_tag'] . ' — ' . $asset['asset_name']) ?></option>
            <?php endwhile; ?>
          </select>
          <p class="small text-muted mt-3 mb-0">Codes contain an internal PMQTS asset link, not a password or other private data.</p>
        </div></div>

        <div class="card"><div class="card-body">
          <div id="equipment-label" class="equipment-label">
            <div class="label-brand">PMQTS · Quality Tracking</div>
            <div id="qr-code"><span class="text-muted">Select an asset to generate its QR code.</span></div>
            <div id="label-asset-name" class="h5 fw-bold mb-1">Equipment name</div>
            <div id="label-asset-tag" class="label-tag text-primary">ASSET TAG</div>
            <div id="label-location" class="text-muted small mt-2"></div>
            <div id="label-category" class="text-muted small"></div>
          </div>
          <div class="label-instructions d-flex flex-wrap gap-2 justify-content-center mt-3">
            <button class="btn btn-primary" id="print-label" type="button" disabled><i class="bi bi-printer me-1"></i> Print Label</button>
            <button class="btn btn-outline-primary" id="download-qr" type="button" disabled><i class="bi bi-download me-1"></i> Download QR</button>
            <a class="btn btn-outline-secondary disabled" id="open-asset" href="#" aria-disabled="true"><i class="bi bi-box-arrow-up-right me-1"></i> View Asset</a>
          </div>
        </div></div>
      </div>
    <?php endif; ?>
  </section>
</main>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" referrerpolicy="no-referrer"></script>
<script src="assets/js/main.js"></script>
<script>
(() => {
  const select = document.getElementById('asset-select');
  if (!select) return;

  const qrContainer = document.getElementById('qr-code');
  const printButton = document.getElementById('print-label');
  const downloadButton = document.getElementById('download-qr');
  const viewLink = document.getElementById('open-asset');
  let currentTag = '';

  const render = () => {
    const option = select.options[select.selectedIndex];
    qrContainer.replaceChildren();
    if (!option || !option.value) {
      qrContainer.innerHTML = '<span class="text-muted">Select an asset to generate its QR code.</span>';
      printButton.disabled = true;
      downloadButton.disabled = true;
      viewLink.classList.add('disabled');
      viewLink.setAttribute('aria-disabled', 'true');
      return;
    }

    const { tag, token, name, category, location, status } = option.dataset;
    currentTag = tag;
    document.getElementById('label-asset-name').textContent = name;
    document.getElementById('label-asset-tag').textContent = tag;
    document.getElementById('label-location').textContent = `${location} · ${status}`;
    document.getElementById('label-category').textContent = category;

    const assetUrl = new URL('pages/asset.php', document.baseURI);
    assetUrl.searchParams.set('token', token);
    new QRCode(qrContainer, { text: assetUrl.toString(), width: 220, height: 220, colorDark: '#102a43', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.H });
    viewLink.href = assetUrl.toString();
    viewLink.classList.remove('disabled');
    viewLink.removeAttribute('aria-disabled');
    printButton.disabled = false;
    downloadButton.disabled = false;
  };

  select.addEventListener('change', render);
  printButton.addEventListener('click', () => window.print());
  downloadButton.addEventListener('click', () => {
    const canvas = qrContainer.querySelector('canvas');
    const image = qrContainer.querySelector('img');
    const dataUrl = canvas ? canvas.toDataURL('image/png') : image?.src;
    if (!dataUrl) return;
    const link = document.createElement('a');
    link.href = dataUrl;
    link.download = `PMQTS-${currentTag}.png`;
    link.click();
  });

  if (select.value) render();
})();
</script>
</body>
</html>
