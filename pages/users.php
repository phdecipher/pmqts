<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_access('users');
require_once __DIR__ . '/../config/db.php';

$flash = take_flash();
$csrfToken = csrf_token();
$selfId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid form token. Refresh the page and try again.');
  }

  $action = $_POST['action'] ?? '';
  $id = (int) filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
  try {
    if ($action === 'create') {
      $fullName = preg_replace('/\s+/', ' ', trim($_POST['full_name'] ?? ''));
      $username = trim($_POST['username'] ?? '');
      $email = trim($_POST['email'] ?? '');
      $password = $_POST['password'] ?? '';
      $role = $_POST['role'] ?? '';
      if ($fullName === '' || mb_strlen($fullName) > 150 || !preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || mb_strlen($email) > 190 || strlen($password) < 12 || !isset(USER_ROLES[$role])) {
        throw new InvalidArgumentException('Enter the complete name, a 3–80 character username, a valid email, a role, and a password of at least 12 characters.');
      }
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $statement = $conn->prepare('      INSERT INTO tbl_users (full_name, username, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
            $statement->bind_param('sssss', $fullName, $username, $email, $hash, $role);
      try {
        $statement->execute();
      } catch (mysqli_sql_exception $exception) {
        throw new InvalidArgumentException('That username or email is already in use.');
      }
      set_flash('User account created.');
    } elseif ($action === 'update' && $id) {
      $fullName = preg_replace('/\s+/', ' ', trim($_POST['full_name'] ?? ''));
      $role = $_POST['role'] ?? '';
      $active = !empty($_POST['is_active']) ? 1 : 0;
      if ($fullName === '' || mb_strlen($fullName) > 150) {
        throw new InvalidArgumentException('Enter the complete name (up to 150 characters).');
      }
      if (!isset(USER_ROLES[$role])) {
        throw new InvalidArgumentException('Select a valid role.');
      }
      if ($id === $selfId && ($role !== current_role() || $active === 0)) {
        throw new InvalidArgumentException('You cannot change your own role or deactivate your own account.');
      }
      $statement = $conn->prepare('      UPDATE tbl_users SET full_name = ?, role = ?, is_active = ? WHERE id = ?');
            $statement->bind_param('ssii', $fullName, $role, $active, $id);
      $statement->execute();
      set_flash('User updated.');
    } elseif ($action === 'reset_password' && $id) {
      $password = $_POST['password'] ?? '';
      if (strlen($password) < 12) {
        throw new InvalidArgumentException('The new password must be at least 12 characters.');
      }
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $statement = $conn->prepare('UPDATE tbl_users SET password_hash = ? WHERE id = ?');
      $statement->bind_param('si', $hash, $id);
      $statement->execute();
      set_flash('Password reset.');
    } else {
      throw new InvalidArgumentException('Invalid user action.');
    }
  } catch (Throwable $exception) {
    if (!($exception instanceof InvalidArgumentException)) {
      error_log('User operation failed: ' . $exception->getMessage());
    }
    set_flash($exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The change could not be saved.', 'danger');
  }

  header('Location: ' . APP_BASE_URL . '/pages/users.php');
  exit();
}

$users = $conn->query('SELECT id, full_name, username, email, role, is_active, created_at FROM tbl_users ORDER BY full_name, username')->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <base href="<?= APP_BASE_URL ?>/">
  <title>User Accounts - NiceAdmin Template</title>
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
          <h1>User Accounts</h1>
          <nav>
            <ol class="breadcrumb">
              <li class="breadcrumb-item"><a href="pages/dashboard.php">Home</a></li>
              <li class="breadcrumb-item active">User Accounts</li>
            </ol>
          </nav>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal"><i class="bi bi-plus-lg me-1"></i> Add User</button>
      </div>

      <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
          <?= h($flash['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <section class="section">
        <div class="card overflow-auto">
          <div class="card-body pt-3">
            <table class="table table-bordered align-middle">
              <thead>
                <tr>
                  <th scope="col">Full name</th>
                  <th scope="col">Username</th>
                  <th scope="col">Email</th>
                  <th scope="col">Role</th>
                  <th scope="col">Status</th>
                  <th scope="col" class="text-center">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $user): ?>
                  <?php $isSelf = (int) $user['id'] === $selfId; ?>
                  <tr>
                    <td style="min-width:220px">
                      <input class="form-control form-control-sm" name="full_name" form="user-<?= (int) $user['id'] ?>" value="<?= h($user['full_name']) ?>" maxlength="150" required aria-label="Full name">
                      <?php if ($isSelf): ?><span class="badge bg-info text-dark mt-1">You</span><?php endif; ?>
                    </td>
                    <td class="text-muted"><?= h($user['username']) ?></td>
                    <td><?= h($user['email']) ?></td>
                    <form method="post" id="user-<?= (int) $user['id'] ?>">
                      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                    </form>
                    <td>
                      <select class="form-select form-select-sm" name="role" form="user-<?= (int) $user['id'] ?>" <?= $isSelf ? 'disabled' : '' ?>>
                        <?php foreach (USER_ROLES as $value => $label): ?>
                          <option value="<?= h($value) ?>" <?= $user['role'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                        <?php if (!isset(USER_ROLES[$user['role']])): ?><option value="<?= h($user['role']) ?>" selected disabled><?= h($user['role']) ?> (legacy)</option><?php endif; ?>
                      </select>
                      <?php if ($isSelf): ?><input type="hidden" name="role" value="<?= h($user['role']) ?>" form="user-<?= (int) $user['id'] ?>"><?php endif; ?>
                    </td>
                    <td>
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" form="user-<?= (int) $user['id'] ?>" <?= (int) $user['is_active'] === 1 ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>>
                        <label class="form-check-label small"><?= (int) $user['is_active'] === 1 ? 'Active' : 'Inactive' ?></label>
                      </div>
                      <?php if ($isSelf): ?><input type="hidden" name="is_active" value="1" form="user-<?= (int) $user['id'] ?>"><?php endif; ?>
                    </td>
                    <td class="text-center">
                      <div class="d-inline-flex gap-2">
                        <button class="btn btn-outline-success btn-sm" form="user-<?= (int) $user['id'] ?>" title="Save changes"><i class="bi bi-check-lg"></i></button>
                        <button type="button" class="btn btn-outline-secondary btn-sm reset-button" title="Reset password" data-id="<?= (int) $user['id'] ?>" data-name="<?= h($user['full_name']) ?>" data-bs-toggle="modal" data-bs-target="#resetModal"><i class="bi bi-key"></i></button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-body pt-3">
            <h5 class="card-title fw-bold">Roles</h5>
            <ul class="mb-0 small">
              <li><strong>Property Custodian</strong> – manages assets, locations, QR labels, maintenance and procurement.</li>
              <li><strong>Teacher</strong>, <strong>Office / Department Head</strong> – requesters (request screens come with the requisition workflow).</li>
              <li><strong>Designated Approver</strong> – reviews and approves procurement requests.</li>
              <li><strong>Maintenance Personnel</strong> – works on the Maintenance page.</li>
              <li><strong>Procurement Personnel</strong> – handles the Procurement page.</li>
              <li><strong>Security Personnel</strong> – scans QR codes (gate pass screens come later).</li>
              <li><strong>Administrator</strong> – full access, including user accounts.</li>
            </ul>
          </div>
        </div>
      </section>

      <div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <form method="post" action="pages/users.php">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <input type="hidden" name="action" value="create">
              <div class="modal-header"><h5 class="modal-title">Add User</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
              <div class="modal-body">
                <div class="mb-3"><label class="form-label" for="newFullName">Full name</label><input class="form-control" id="newFullName" name="full_name" maxlength="150" placeholder="e.g. Juan A. Dela Cruz" autocomplete="off" required></div>
                <div class="mb-3"><label class="form-label" for="newUsername">Username</label><input class="form-control" id="newUsername" name="username" maxlength="80" pattern="[A-Za-z0-9_.\-]{3,80}" required><div class="form-text">Used only to sign in.</div></div>
                <div class="mb-3"><label class="form-label" for="newEmail">Email</label><input type="email" class="form-control" id="newEmail" name="email" maxlength="190" required></div>
                <div class="mb-3"><label class="form-label" for="newRole">Role</label><select class="form-select" id="newRole" name="role" required><option value="">Select role</option><?php foreach (USER_ROLES as $value => $label): ?><option value="<?= h($value) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
                <div class="mb-3"><label class="form-label" for="newPassword">Temporary password</label><input type="password" class="form-control" id="newPassword" name="password" minlength="12" autocomplete="new-password" required><div class="form-text">At least 12 characters.</div></div>
              </div>
              <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Create User</button></div>
            </form>
          </div>
        </div>
      </div>

      <div class="modal fade" id="resetModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <form method="post" action="pages/users.php">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="id" id="resetId">
              <div class="modal-header"><h5 class="modal-title">Reset password — <span id="resetName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
              <div class="modal-body">
                <label class="form-label" for="resetPassword">New password</label>
                <input type="password" class="form-control" id="resetPassword" name="password" minlength="12" autocomplete="new-password" required>
                <div class="form-text">At least 12 characters.</div>
              </div>
              <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Reset Password</button></div>
            </form>
          </div>
        </div>
      </div>

    </div>
  </main>

  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
  <script src="assets/js/table-sort.js"></script>
  <script>
    document.querySelectorAll('.reset-button').forEach((button) => {
      button.addEventListener('click', () => {
        document.getElementById('resetId').value = button.dataset.id;
        document.getElementById('resetName').textContent = button.dataset.name;
        document.getElementById('resetPassword').value = '';
      });
    });
  </script>
</body>
</html>
