<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

if (!empty($_SESSION['user_id'])) {
  header('Location: ' . APP_BASE_URL . '/pages/dashboard.php');
  exit();
}

$flash = take_flash();
$loginError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    $loginError = 'Your session expired. Refresh the page and try again.';
  } else {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $statement = $conn->prepare('SELECT id, username, email, password_hash, role FROM tbl_users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1');
    $statement->bind_param('ss', $username, $username);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc();

    if ($user && password_verify($password, $user['password_hash'])) {
      session_regenerate_id(true);
      $_SESSION['user_id'] = (int) $user['id'];
      $_SESSION['username'] = $user['username'];
      $_SESSION['user_role'] = $user['role'];
            $destination = $_SESSION['after_login'] ?? (APP_BASE_URL . '/pages/dashboard.php');
            unset($_SESSION['after_login']);
            header('Location: ' . $destination);
      exit();
    }

    $loginError = 'Invalid username/email or password.';
  }
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <base href="<?= APP_BASE_URL ?>/">

  <title>pmqts</title>

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
</head>

<body>

  <main>
    <div class="container-fluid p-0">
      <div class="row g-0 min-vh-100">

      
        <div class="col-lg-6 d-none d-lg-flex flex-column align-items-center justify-content-between p-5 text-white" style="background-color: #061525;">
          
          
          <div class="mt-3">
            <img src="assets/img/2.jpg" alt="QT Logo" style="max-height: 200px;">
          </div>

          
          <div class="text-center px-4" style="margin-top: -40px;">
            <h2 class="fw-bold mb-3">Preventive Management <br> Quality Tracking (QT) System</h2>
            <p class="fs-6 opacity-75">Smart inventory and preventive maintenance solution for institutional assets</p>
          </div>

          <div class="mb-5">
            <img src="assets/img/1.jpg" alt="Illustration" class="img-fluid" style="max-height: 250px;">
          </div>

        </div>

        
        <div class="col-lg-6 d-flex flex-column align-items-center justify-content-center p-4">

          <div class="card mb-3 w-100" style="max-width: 500px;">
            <div class="card-body">

              <div class="pt-4 pb-2">
                <h4 class="card-title text-center pb-0 fs-3 fw-bold">Welcome Back!</h4>
                <p class="text-center small text-muted">Please sign in to continue</p>
              </div>

              <form class="row g-3 needs-validation" method="post" action="pages/login.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <?php if ($flash): ?><div class="col-12"><div class="alert alert-<?= h($flash['type']) ?> mb-0"><?= h($flash['message']) ?></div></div><?php endif; ?>
                <?php if ($loginError): ?><div class="col-12"><div class="alert alert-danger mb-0"><?= h($loginError) ?></div></div><?php endif; ?>

                <div class="col-12">
                  <label for="email" class="form-label">Email</label>
                  <div class="input-group has-validation">                   
                    <input type="text" name="username" class="form-control" id="email" autocomplete="username" required>
                    <div class="invalid-feedback">Please enter your username.</div>
                  </div>
                </div>

                <div class="col-12">
                  <label for="yourPassword" class="form-label">Password</label>
                  <input type="password" name="password" class="form-control" id="yourPassword" autocomplete="current-password" required>
                  <div class="invalid-feedback">Please enter your password!</div>
                </div>

                <div class="col-12">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="remember" value="true" id="rememberMe">
                    <label class="form-check-label text-muted" for="rememberMe">Remember me</label>
                  </div>
                </div>

                <div class="col-12">
                  <button class="btn btn-primary w-100 py-2 fs-6 fw-bold" type="submit">Sign in</button>
                </div>

                <div class="col-12 text-center">
                  <p class="small mb-0">Need access? Contact your system administrator.</p>
                </div>
              </form>

            </div>
          </div>

        </div>

      </div>
    </div>
  </main>

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