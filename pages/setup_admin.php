<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

$userCount = (int) $conn->query('SELECT COUNT(*) FROM tbl_users')->fetch_row()[0];
$message = null;
$messageType = 'danger';

if ($userCount === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $message = 'Your session expired. Refresh the page and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirmation = $_POST['password_confirmation'] ?? '';

        if (!preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username)
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($password) < 12
            || $password !== $passwordConfirmation) {
            $message = 'Use a 3–80 character username, a valid email, matching passwords, and a password of at least 12 characters.';
        } else {
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $role = 'admin';
                $statement = $conn->prepare('INSERT INTO tbl_users (username, email, password_hash, role) VALUES (?, ?, ?, ?)');
                $statement->bind_param('ssss', $username, $email, $passwordHash, $role);
                $statement->execute();
                set_flash('Administrator account created. Sign in to continue.');
                header('Location: ' . APP_BASE_URL . '/pages/login.php');
                exit();
            } catch (mysqli_sql_exception $exception) {
                error_log('Initial admin setup failed: ' . $exception->getMessage());
                $message = 'The account could not be created. The username or email may already be in use.';
            }
        }
    }
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <base href="<?= h(APP_BASE_URL) ?>/">
  <title>Initial Administrator Setup - PMQTS</title>
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
  <main class="container min-vh-100 d-flex align-items-center justify-content-center">
    <div class="card w-100" style="max-width: 520px">
      <div class="card-body p-4">
        <h1 class="h3 mb-2">Initial administrator setup</h1>
        <?php if ($userCount > 0): ?>
          <div class="alert alert-info">Administrator setup is already complete.</div>
          <a class="btn btn-primary" href="pages/login.php">Go to sign in</a>
        <?php else: ?>
          <p class="text-muted">Create the first administrator account. This setup page becomes unavailable after the first account is created.</p>
          <?php if ($message): ?><div class="alert alert-<?= h($messageType) ?>" role="alert"><?= h($message) ?></div><?php endif; ?>
          <form method="post" action="pages/setup_admin.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="mb-3"><label class="form-label" for="username">Username</label><input class="form-control" id="username" name="username" minlength="3" maxlength="80" autocomplete="username" required></div>
            <div class="mb-3"><label class="form-label" for="email">Email</label><input class="form-control" id="email" type="email" name="email" maxlength="190" autocomplete="email" required></div>
            <div class="mb-3"><label class="form-label" for="password">Password</label><input class="form-control" id="password" type="password" name="password" minlength="12" autocomplete="new-password" required></div>
            <div class="mb-3"><label class="form-label" for="passwordConfirmation">Confirm password</label><input class="form-control" id="passwordConfirmation" type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required></div>
            <button class="btn btn-primary w-100">Create administrator</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </main>
</body>
</html>
