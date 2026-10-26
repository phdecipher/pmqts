<?php
require_once __DIR__ . '/../config/app.php';
require_login();
$csrfToken = csrf_token();
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <base href="<?= APP_BASE_URL ?>/">

  <title>QR Scanner - NiceAdmin Template</title>
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

  <!-- QR Scanner Library -->
  <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

  <!-- Custom Styles para sa QR Scanner Layout -->
  <style>
    .qr-container {
      width: 100%;
      display: grid;
      grid-template-columns: 58% 40%;
      gap: 2%;
    }

    .scanner-box {
      width: 100%;
      min-height: 480px;
      background: #2e3135;
      border-radius: 12px;
      position: relative;
      overflow: hidden;
    }

    #reader {
      width: 100%;
      height: 100%;
    }

    #reader__dashboard {
      display: none !important;
    }

    #reader video {
      width: 100% !important;
      height: 100% !important;
      object-fit: cover;
    }

    .camera-overlay {
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, 0.25);
      z-index: 2;
      pointer-events: none;
    }

    .outer-corner {
      position: absolute;
      width: 45px;
      height: 45px;
      border-color: #38d9b7;
      border-style: solid;
      z-index: 5;
      pointer-events: none;
    }

    .outer-top-left { top: 25px; left: 25px; border-width: 3px 0 0 3px; }
    .outer-top-right { top: 25px; right: 25px; border-width: 3px 3px 0 0; }
    .outer-bottom-left { bottom: 25px; left: 25px; border-width: 0 0 3px 3px; }
    .outer-bottom-right { bottom: 25px; right: 25px; border-width: 0 3px 3px 0; }

    .qr-frame {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: 250px;
      height: 250px;
      z-index: 5;
      pointer-events: none;
    }

    .frame-corner {
      position: absolute;
      width: 40px;
      height: 40px;
      border-color: white;
      border-style: solid;
    }

    .frame-top-left { top: 0; left: 0; border-width: 4px 0 0 4px; }
    .frame-top-right { top: 0; right: 0; border-width: 4px 4px 0 0; }
    .frame-bottom-left { bottom: 0; left: 0; border-width: 0 0 4px 4px; }
    .frame-bottom-right { bottom: 0; right: 0; border-width: 0 4px 4px 0; }

    .result-box {
      min-height: 180px;
      border: 1px solid #dce1e7;
      border-radius: 10px;
      background: #fafafa;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      color: #6b7280;
      padding: 20px;
      word-break: break-word;
    }

    .upload-area {
      min-height: 180px;
      border: 2px dashed #cfd6df;
      background: #fafbfc;
      border-radius: 10px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      text-align: center;
      transition: 0.2s;
    }

    .upload-area:hover {
      background: #f1f5f9;
      border-color: #0d6efd;
    }

    @media (max-width: 992px) {
      .qr-container { grid-template-columns: 1fr; }
      .scanner-box { min-height: 380px; }
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
      <h1>QR Scanner</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="pages/dashboard.php">Home</a></li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="qr-container">

        <!-- KALIWA: LIVE CAMERA SCANNER -->
        <div class="card p-2">
          <div class="scanner-box">
            <div id="reader"></div>
            <div class="camera-overlay"></div>
            <div class="outer-corner outer-top-left"></div>
            <div class="outer-corner outer-top-right"></div>
            <div class="outer-corner outer-bottom-left"></div>
            <div class="outer-corner outer-bottom-right"></div>

            <div class="qr-frame">
              <div class="frame-corner frame-top-left"></div>
              <div class="frame-corner frame-top-right"></div>
              <div class="frame-corner frame-bottom-left"></div>
              <div class="frame-corner frame-bottom-right"></div>
            </div>
          </div>
        </div>

        <!-- KANAN: RESULTA AT UPLOAD OPTION -->
        <div class="d-flex flex-column gap-3">

          <!-- SCAN RESULT CARD -->
          <div class="card p-3 mb-0">
            <h5 class="card-title p-0 mb-3 fw-bold">Scan Result</h5>
            <div class="result-box" id="scan-result">
              <i class="bi bi-file-earmark-text text-secondary display-5 mb-2"></i>
              <span id="result-text">No result yet</span>
            </div>
          </div>

          <div class="text-center text-muted fw-bold">Or</div>

          <!-- UPLOAD CARD -->
          <div class="card p-3 mb-0">
            <h5 class="card-title p-0 mb-3 fw-bold">Upload QR Image</h5>
            <label for="qr-image" class="upload-area p-3">
              <i class="bi bi-cloud-arrow-up text-primary display-4 mb-2"></i>
              <div class="fw-bold text-dark">Click to upload</div>
              <div class="small text-muted">or drag and drop</div>
            </label>
            <input type="file" id="qr-image" accept="image/jpeg,image/png" hidden>
            <div class="text-center text-muted small mt-2">Supported formats: JPG, PNG</div>
          </div>

        </div>

      </div>
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

  <!-- QR Scanner Logic Script -->
  <script>
    let html5QrCode = null;
    let validatingScan = false;

    function showSuccessResult(decodedText, asset, assetUrl) {
      document.getElementById("scan-result").innerHTML = `
        <i class="bi bi-check-circle-fill text-success display-4 mb-2"></i>
        <div class="text-success fw-bold fs-5"></div>
        <div class="small text-muted mt-1">Verified PMQTS equipment label</div>
        <a class="btn btn-outline-primary mt-3" id="open-equipment-record">
          <i class="bi bi-box-arrow-up-right me-1"></i> Open Equipment Record
        </a>
        <button type="button" class="btn btn-primary mt-2" id="scan-again">
          <i class="bi bi-arrow-repeat me-1"></i> Scan Again
        </button>
      `;
      document.querySelector("#scan-result .text-success").textContent = `${asset.asset_tag} — ${asset.asset_name}`;
      document.getElementById('open-equipment-record').href = assetUrl.href;
      document.getElementById("scan-again").addEventListener("click", restartScanner);
    }

    function showErrorResult(message) {
      document.getElementById("scan-result").innerHTML = `
        <i class="bi bi-x-circle-fill text-danger display-4 mb-2"></i>
        <div class="text-danger fw-semibold"></div>
        <button type="button" class="btn btn-primary mt-3" id="scan-again">
          <i class="bi bi-arrow-repeat me-1"></i> Scan Again
        </button>
      `;
      document.querySelector('#scan-result .text-danger').textContent = message;
      document.getElementById("scan-again").addEventListener("click", restartScanner);
    }

    async function onScanSuccess(decodedText) {
      if (validatingScan) return;
      validatingScan = true;
      if (html5QrCode && html5QrCode.isScanning) {
        html5QrCode.stop().then(() => {
          console.log("Scanner stopped after success.");
        }).catch(err => console.error(err));
      }

      try {
        const scannedUrl = new URL(decodedText);
        const tokens = scannedUrl.searchParams.getAll('token');
        const validPayload = scannedUrl.origin === window.location.origin
          && scannedUrl.pathname === new URL('pages/asset.php', document.baseURI).pathname
          && tokens.length === 1
          && /^[a-f0-9]{64}$/.test(tokens[0]);

        if (!validPayload) {
          showErrorResult('This QR code was not generated for PMQTS equipment.');
          return;
        }

        const formData = new FormData();
        formData.append('csrf_token', <?= json_encode($csrfToken) ?>);
        formData.append('token', tokens[0]);
        const response = await fetch('pages/qr-validate.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' }
        });
        const result = await response.json();
        if (!response.ok || !result.valid) {
          showErrorResult('This QR code is not registered to active equipment in PMQTS.');
          return;
        }
        showSuccessResult(decodedText, result, scannedUrl);
      } catch (error) {
        console.error('QR validation failed:', error);
        showErrorResult('The QR code could not be verified. Check your connection and try again.');
      }
    }

    function startScanner() {
      html5QrCode = new Html5Qrcode("reader");
      const config = { fps: 10, qrbox: { width: 250, height: 250 } };

      html5QrCode.start({ facingMode: "environment" }, config, onScanSuccess, (errorMessage) => {
        // Ignore operational scanning errors
      }).catch(err => {
        console.error("Camera Error: ", err);
        document.getElementById("result-text").innerText = "Camera access denied or unavailable.";
      });
    }

    function restartScanner() {
      validatingScan = false;
      document.getElementById("scan-result").innerHTML = `
        <i class="bi bi-file-earmark-text text-secondary display-5 mb-2"></i>
        <span id="result-text">Starting camera...</span>
      `;
      startScanner();
    }

    function processImageFile(file) {
      if (!file) return;

      document.getElementById("scan-result").innerHTML = `
        <i class="bi bi-hourglass-split text-secondary display-5 mb-2"></i>
        <span>Scanning image...</span>
      `;

      if (html5QrCode && html5QrCode.isScanning) {
        html5QrCode.stop().then(() => scanFileDirectly(file)).catch(() => scanFileDirectly(file));
      } else {
        scanFileDirectly(file);
      }
    }

    function scanFileDirectly(file) {
      const fileScanner = new Html5Qrcode("reader");
      fileScanner.scanFile(file, true)
        .then(decodedText => onScanSuccess(decodedText))
        .catch(err => showErrorResult("Unable to read QR code from image."));
    }

    document.getElementById("qr-image").addEventListener("change", function (e) {
      processImageFile(e.target.files[0]);
    });

    const uploadArea = document.querySelector(".upload-area");
    uploadArea.addEventListener("dragover", (e) => {
      e.preventDefault();
      uploadArea.style.borderColor = "#0d6efd";
    });

    uploadArea.addEventListener("dragleave", () => {
      uploadArea.style.borderColor = "#cfd6df";
    });

    uploadArea.addEventListener("drop", (e) => {
      e.preventDefault();
      uploadArea.style.borderColor = "#cfd6df";
      const file = e.dataTransfer.files[0];
      if (file && (file.type === "image/jpeg" || file.type === "image/png")) {
        processImageFile(file);
      } else {
        alert("Please upload a valid JPG or PNG image.");
      }
    });

    window.addEventListener("load", startScanner);
  </script>

</body>

</html>