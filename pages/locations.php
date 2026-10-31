<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_access('inventory');
require_once __DIR__ . '/../config/db.php';

$flash = take_flash();
$csrfToken = csrf_token();
$isAdmin = can_access('inventory');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid form token. Refresh the page and try again.');
  }

  try {
    if (!$isAdmin) {
      throw new InvalidArgumentException('Your role cannot manage locations.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'add_location') {
      $department = trim($_POST['department'] ?? '');
      $building = trim($_POST['building'] ?? '');
      $room = trim($_POST['room_number'] ?? '');
      if ($department === '' || mb_strlen($department) > 100 || $building === '' || mb_strlen($building) > 100 || $room === '' || mb_strlen($room) > 40) {
        throw new InvalidArgumentException('Enter a department, building and room number.');
      }
      $statement = $conn->prepare('INSERT IGNORE INTO tbl_locations (department, building, room_number) VALUES (?, ?, ?)');
      $statement->bind_param('sss', $department, $building, $room);
      $statement->execute();
      $added = $statement->affected_rows > 0;
      set_flash($added ? 'Location added.' : 'That location already exists.', $added ? 'success' : 'warning');
    } elseif ($action === 'update_location') {
      $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
      $department = trim($_POST['department'] ?? '');
      $building = trim($_POST['building'] ?? '');
      $room = trim($_POST['room_number'] ?? '');
      if (!$id || $department === '' || mb_strlen($department) > 100 || $building === '' || mb_strlen($building) > 100 || $room === '' || mb_strlen($room) > 40) {
        throw new InvalidArgumentException('Enter a department, building and room number.');
      }
      $conn->begin_transaction();
      try {
        $statement = $conn->prepare('UPDATE tbl_locations SET department = ?, building = ?, room_number = ? WHERE id = ?');
        $statement->bind_param('sssi', $department, $building, $room, $id);
        $statement->execute();
        // Keep the text copies of the location on assets and requisitions in step with the rename.
        $label = mb_substr($department . ' / ' . $building . ' / Room ' . $room, 0, 150);
        foreach (['tbl_inventory' => 'location', 'tbl_requisitions' => 'use_location'] as $table => $column) {
          $statement = $conn->prepare("UPDATE $table SET $column = ? WHERE location_id = ?");
          $statement->bind_param('si', $label, $id);
          $statement->execute();
        }
        $conn->commit();
      } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        if ($exception->getCode() === 1062) {
          throw new InvalidArgumentException('Another location already has that department, building and room number.');
        }
        throw $exception;
      }
      set_flash('Location updated.');
    } elseif ($action === 'delete_location') {
      $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
      if (!$id) {
        throw new InvalidArgumentException('Invalid location.');
      }
      $statement = $conn->prepare('SELECT COUNT(*) AS total FROM tbl_inventory WHERE location_id = ?');
      $statement->bind_param('i', $id);
      $statement->execute();
      if ((int) $statement->get_result()->fetch_assoc()['total'] > 0) {
        throw new InvalidArgumentException('This location is assigned to assets and cannot be deleted.');
      }
      $statement = $conn->prepare('DELETE FROM tbl_locations WHERE id = ?');
      $statement->bind_param('i', $id);
      $statement->execute();
      set_flash('Location deleted.');
    } else {
      throw new InvalidArgumentException('Invalid location action.');
    }
  } catch (Throwable $exception) {
    error_log('Location operation failed: ' . $exception->getMessage());
    set_flash($exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The location could not be saved.', 'danger');
  }

  header('Location: ' . APP_BASE_URL . '/pages/locations.php');
  exit();
}

$locations = $conn->query('SELECT l.id, l.department, l.building, l.room_number, COUNT(i.id) AS asset_count FROM tbl_locations l LEFT JOIN tbl_inventory i ON i.location_id = l.id GROUP BY l.id ORDER BY l.department, l.building, l.room_number')->fetch_all(MYSQLI_ASSOC);
$grouped = [];
foreach ($locations as $loc) { $grouped[$loc['department']][$loc['building']][] = $loc; }
$buildingTotal = 0; foreach ($grouped as $bs) { $buildingTotal += count($bs); }
$departments = array_values(array_unique(array_column($locations, 'department')));
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <base href="<?= APP_BASE_URL ?>/">
  <title>Locations - NiceAdmin Template</title>
  <link href="assets/img/favicon.png" rel="icon">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Nunito:300,400,600,700|Poppins:300,400,500,600,700" rel="stylesheet">
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
  <link href="assets/css/responsive.css" rel="stylesheet">
  <script src="assets/js/responsive.js" defer></script>
  <style>
    .loc-stat { min-height: 76px; display: flex; align-items: center; gap: .75rem; padding: .75rem 1rem; background: #fff; border: 1px solid #e3e6ea; border-radius: .5rem; }
    .loc-stat > i { width: 40px; height: 40px; flex: 0 0 40px; display: grid; place-items: center; border-radius: 50%; background: #eaf2ff; color: #4154f1; font-size: 1.15rem; }
    .loc-num { color: #012970; font-size: 1.35rem; font-weight: 700; line-height: 1.1; }
    .loc-cap { color: #6c757d; font-size: .75rem; }
    .loc-dept { overflow: hidden; }
    .loc-dept[hidden], .loc-group[hidden], .loc-row[hidden] { display: none !important; }
    .loc-head { display: flex; align-items: center; gap: .65rem; padding: .85rem 1rem; background: #f6f9ff; border-bottom: 1px solid #e6e9f2; color: #012970; }
    .loc-head > i { color: #4154f1; font-size: 1.1rem; }
    .loc-group { padding: .6rem 1rem .75rem; }
    .loc-group + .loc-group { border-top: 1px solid #eef0f3; }
    .loc-bname { margin-bottom: .5rem; color: #6c757d; font-size: .72rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .loc-rooms { display: grid; grid-template-columns: repeat(auto-fill, minmax(215px, 1fr)); gap: .5rem; }
    .loc-row { display: flex; align-items: center; gap: .5rem; padding: .4rem .5rem .4rem .7rem; border: 1px solid #e3e6ea; border-radius: .5rem; background: #fff; transition: border-color .15s, box-shadow .15s; }
    .loc-row:hover { border-color: #b9c9f5; box-shadow: 0 1px 4px rgba(65,84,241,.12); }
    .loc-rname { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; line-height: 1.2; }
    .loc-rname small { display: block; color: #6c757d; font-size: .72rem; font-weight: 400; }
    .loc-actions { display: flex; gap: .15rem; }
    .loc-ibtn { width: 28px; height: 28px; display: grid; place-items: center; padding: 0; border: 0; border-radius: .35rem; background: transparent; color: #6c757d; }
    .loc-ibtn:hover:not(:disabled) { background: #eaf2ff; color: #4154f1; }
    .loc-ibtn.danger:hover:not(:disabled) { background: #fdecea; color: #dc3545; }
    .loc-ibtn:disabled { opacity: .3; cursor: not-allowed; }
    .loc-modal-icon { width: 44px; height: 44px; flex: 0 0 44px; display: grid; place-items: center; border-radius: 50%; background: #eaf2ff; color: #4154f1; font-size: 1.25rem; }
    .loc-field { margin-bottom: 1rem; }
    .loc-field .form-label { display: flex; align-items: center; gap: .5rem; font-weight: 600; margin-bottom: .35rem; }
    .loc-step { width: 20px; height: 20px; display: inline-grid; place-items: center; border-radius: 50%; background: #4154f1; color: #fff; font-size: .7rem; font-weight: 700; }
    .loc-field .input-group-text { background: #f6f9ff; color: #4154f1; }
    .loc-preview { padding: .65rem .85rem; border: 1px dashed #b9c9f5; border-radius: .5rem; background: #f6f9ff; }
    .loc-preview-label { color: #6c757d; font-size: .65rem; font-weight: 700; letter-spacing: .05em; }
    .loc-preview .placeholder-text { color: #adb5bd; font-weight: 400; }
    @media (max-width: 575.98px) {
      .loc-stat { min-height: 100px; flex-direction: column; justify-content: center; gap: .25rem; padding: .5rem .2rem; text-align: center; }
      .loc-stat > i { width: 30px; height: 30px; flex-basis: 30px; font-size: .9rem; }
      .loc-num { font-size: 1.05rem; }
      .loc-cap { font-size: .65rem; }
      .loc-head { flex-wrap: wrap; }
      .loc-head .ms-auto { margin-left: 0 !important; width: 100%; }
      .loc-group { padding-left: .75rem; padding-right: .75rem; }
      .loc-rooms { grid-template-columns: minmax(0, 1fr); }
      .loc-ibtn { width: 32px; height: 32px; }
    }
  </style>

<body>
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

  <main id="main" class="main">
    <div class="container-fluid">

      <div class="pagetitle">
        <h1>Locations</h1>
        <nav>
          <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="pages/dashboard.php">Home</a></li>
            <li class="breadcrumb-item active">Locations</li>
          </ol>
        </nav>
      </div>

      <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
          <?= h($flash['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <section class="section">
        <div class="row g-3 mb-3">
          <div class="col-4"><div class="loc-stat"><i class="bi bi-diagram-3"></i><div><div class="loc-num"><?= count($departments) ?></div><div class="loc-cap">Departments</div></div></div></div>
          <div class="col-4"><div class="loc-stat"><i class="bi bi-building"></i><div><div class="loc-num"><?= $buildingTotal ?></div><div class="loc-cap">Buildings</div></div></div></div>
          <div class="col-4"><div class="loc-stat"><i class="bi bi-door-closed"></i><div><div class="loc-num"><?= count($locations) ?></div><div class="loc-cap">Rooms</div></div></div></div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div class="d-flex gap-2 flex-grow-1 flex-wrap">
            <div class="input-group" style="max-width: 340px;">
              <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
              <input type="search" id="locSearch" class="form-control" placeholder="Search locations…" aria-label="Search locations">
            </div>
            <select id="locDept" class="form-select" style="max-width: 240px;" aria-label="Filter by department">
              <option value="">All departments</option>
              <?php foreach ($departments as $name): ?><option value="<?= h(strtolower($name)) ?>"><?= h($name) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php if ($isAdmin): ?>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLocationModal"><i class="bi bi-plus-lg me-1"></i> Add Location</button>
          <?php endif; ?>
        </div>

        <?php if (!$locations): ?>
          <div class="card"><div class="card-body text-center text-muted py-5"><i class="bi bi-geo-alt fs-1 d-block mb-2"></i>No locations yet.<?= $isAdmin ? ' Use “Add Location” to create the first one.' : '' ?></div></div>
        <?php endif; ?>
        <div id="locEmpty" class="card" hidden><div class="card-body text-center text-muted py-5"><i class="bi bi-search fs-1 d-block mb-2"></i>No locations match your search.</div></div>

        <?php foreach ($grouped as $dept => $buildings):
          $deptRooms = array_sum(array_map('count', $buildings));
        ?>
          <div class="card loc-dept mb-3" data-dept="<?= h(strtolower($dept)) ?>">
            <div class="loc-head">
              <i class="bi bi-diagram-3"></i>
              <span class="fw-bold"><?= h($dept) ?></span>
              <span class="text-muted small ms-auto"><?= count($buildings) ?> building(s) · <?= $deptRooms ?> room(s)</span>
            </div>
            <?php foreach ($buildings as $building => $rooms): ?>
              <div class="loc-group">
                <div class="loc-bname"><i class="bi bi-building me-1"></i><?= h($building) ?></div>
                <div class="loc-rooms">
                <?php foreach ($rooms as $room):
                  $assetCount = (int) $room['asset_count'];
                  $search = strtolower($dept . ' ' . $building . ' ' . $room['room_number']);
                ?>
                  <div class="loc-row" data-search="<?= h($search) ?>">
                    <div class="loc-rname" title="Room <?= h($room['room_number']) ?>">Room <?= h($room['room_number']) ?><small><?= $assetCount ?> asset<?= $assetCount === 1 ? '' : 's' ?></small></div>
                    <?php if ($isAdmin): ?>
                      <div class="loc-actions">
                        <button type="button" class="loc-ibtn edit-location" title="Edit" data-id="<?= (int) $room['id'] ?>" data-department="<?= h($room['department']) ?>" data-building="<?= h($room['building']) ?>" data-room="<?= h($room['room_number']) ?>" data-bs-toggle="modal" data-bs-target="#editLocationModal"><i class="bi bi-pencil"></i></button>
                        <form method="post" class="d-inline confirm-form" data-icon="bi-trash" data-title="Delete this location?" data-message="Room <?= h($room['room_number']) ?> in <?= h($building) ?> will be removed." data-confirm="Delete" data-style="danger">
                          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                          <input type="hidden" name="action" value="delete_location">
                          <input type="hidden" name="id" value="<?= (int) $room['id'] ?>">
                          <button class="loc-ibtn danger" title="<?= $assetCount ? 'Assigned to assets and cannot be deleted' : 'Delete' ?>" <?= $assetCount ? 'disabled' : '' ?>><i class="bi bi-trash"></i></button>
                        </form>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </section>

      <?php if ($isAdmin): ?>
      <div class="modal fade" id="addLocationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <form method="post" action="pages/locations.php">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <input type="hidden" name="action" value="add_location">
              <div class="modal-header border-0 pb-0">
                <div class="d-flex align-items-center gap-3">
                  <div class="loc-modal-icon"><i class="bi bi-geo-alt"></i></div>
                  <div>
                    <h5 class="modal-title mb-0">Add Location</h5>
                    <div class="small text-muted">Department → Building → Room</div>
                  </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body pt-3">
                <div class="loc-field">
                  <label class="form-label" for="departmentSelect"><span class="loc-step">1</span>Department</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-diagram-3"></i></span>
                    <select class="form-select" id="departmentSelect"></select>
                  </div>
                  <input class="form-control mt-2 d-none" id="department" name="department" maxlength="100" placeholder="Type the new department name" autocomplete="off">
                </div>
                <div class="loc-field">
                  <label class="form-label" for="buildingSelect"><span class="loc-step">2</span>Building</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-building"></i></span>
                    <select class="form-select" id="buildingSelect"></select>
                  </div>
                  <input class="form-control mt-2 d-none" id="building" name="building" maxlength="100" placeholder="Type the new building name" autocomplete="off">
                </div>
                <div class="loc-field mb-3">
                  <label class="form-label" for="room"><span class="loc-step">3</span>Room number</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-door-closed"></i></span>
                    <input class="form-control" id="room" name="room_number" maxlength="40" placeholder="e.g. 101" autocomplete="off" required>
                  </div>
                </div>
                <div class="loc-preview" aria-live="polite">
                  <div class="loc-preview-label">LOCATION PREVIEW</div>
                  <div id="locPreview" class="fw-semibold"></div>
                </div>
                <div class="form-text mt-2">Choose an existing department or building, or pick “+ Add new…” to create one.</div>
              </div>
              <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Location</button></div>
            </form>
          </div>
        </div>
      </div>

      <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content text-center p-3">
            <div class="modal-body">
              <i class="bi fs-1 text-danger" id="confirmIcon"></i>
              <h5 class="mt-2" id="confirmTitle"></h5>
              <p class="text-muted mb-0" id="confirmMessage"></p>
            </div>
            <div class="d-flex justify-content-center gap-2 pb-2">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-danger" id="confirmOk">Confirm</button>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
      <div class="modal fade" id="editLocationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <form method="post" action="pages/locations.php">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <input type="hidden" name="action" value="update_location">
              <input type="hidden" name="id" id="editLocId">
              <div class="modal-header border-0 pb-0">
                <div class="d-flex align-items-center gap-3">
                  <div class="loc-modal-icon"><i class="bi bi-pencil-square"></i></div>
                  <div>
                    <h5 class="modal-title mb-0">Edit Location</h5>
                    <div class="small text-muted">Update the department, building or room</div>
                  </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body pt-3">
                <div class="loc-field">
                  <label class="form-label" for="editLocDepartment"><span class="loc-step">1</span>Department</label>
                  <div class="input-group"><span class="input-group-text"><i class="bi bi-diagram-3"></i></span><input class="form-control" id="editLocDepartment" name="department" maxlength="100" list="departmentList" autocomplete="off" required></div>
                </div>
                <div class="loc-field">
                  <label class="form-label" for="editLocBuilding"><span class="loc-step">2</span>Building</label>
                  <div class="input-group"><span class="input-group-text"><i class="bi bi-building"></i></span><input class="form-control" id="editLocBuilding" name="building" maxlength="100" list="buildingList" autocomplete="off" required></div>
                </div>
                <div class="loc-field mb-3">
                  <label class="form-label" for="editLocRoom"><span class="loc-step">3</span>Room number</label>
                  <div class="input-group"><span class="input-group-text"><i class="bi bi-door-closed"></i></span><input class="form-control" id="editLocRoom" name="room_number" maxlength="40" autocomplete="off" required></div>
                </div>
                <div class="loc-preview" aria-live="polite">
                  <div class="loc-preview-label">LOCATION PREVIEW</div>
                  <div id="editLocPreview" class="fw-semibold"></div>
                </div>
                <div class="alert alert-info d-flex gap-2 align-items-start small py-2 mt-3 mb-0"><i class="bi bi-info-circle"></i><span>Assets and requisitions using this location are updated to the new name.</span></div>
                <datalist id="departmentList"><?php foreach (array_unique(array_column($locations, 'department')) as $name): ?><option value="<?= h($name) ?>"><?php endforeach; ?></datalist>
                <datalist id="buildingList"><?php foreach (array_unique(array_column($locations, 'building')) as $name): ?><option value="<?= h($name) ?>"><?php endforeach; ?></datalist>
              </div>
              <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Save Changes</button></div>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </main>

  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    (() => {
      const input = document.getElementById('locSearch');
      const deptFilter = document.getElementById('locDept');
      const empty = document.getElementById('locEmpty');
      const apply = () => {
        const q = input.value.trim().toLowerCase();
        const dept = deptFilter.value;
        let shown = 0;
        document.querySelectorAll('.loc-dept').forEach((card) => {
          const deptOk = !dept || card.dataset.dept === dept;
          card.querySelectorAll('.loc-row').forEach((row) => {
            const ok = deptOk && row.dataset.search.includes(q);
            row.hidden = !ok;
            if (ok) shown++;
          });
          card.querySelectorAll('.loc-group').forEach((g) => { g.hidden = !g.querySelector('.loc-row:not([hidden])'); });
          card.hidden = !card.querySelector('.loc-row:not([hidden])');
        });
        empty.hidden = shown > 0 || !document.querySelector('.loc-row');
      };
      input.addEventListener('input', apply);
      deptFilter.addEventListener('change', apply);

      const modalEl = document.getElementById('confirmModal');
      if (!modalEl) return;
      const modal = new bootstrap.Modal(modalEl);
      let pending = null;
      document.querySelectorAll('.confirm-form').forEach((form) => {
        form.addEventListener('submit', (event) => {
          if (form.dataset.confirmed) return;
          event.preventDefault();
          pending = form;
          document.getElementById('confirmIcon').className = 'bi fs-1 text-danger ' + form.dataset.icon;
          document.getElementById('confirmTitle').textContent = form.dataset.title;
          document.getElementById('confirmMessage').textContent = form.dataset.message;
          document.getElementById('confirmOk').textContent = form.dataset.confirm;
          modal.show();
        });
      });
      document.getElementById('confirmOk').addEventListener('click', () => {
        if (!pending) return;
        pending.dataset.confirmed = '1';
        pending.submit();
      });
    })();
  </script>
  <script>
    const editFields = ['editLocDepartment', 'editLocBuilding', 'editLocRoom'].map((id) => document.getElementById(id));
    const updateEditPreview = () => {
      const [d, b, r] = editFields.map((el) => el.value.trim());
      document.getElementById('editLocPreview').textContent = [d || 'Department', b || 'Building', r ? 'Room ' + r : 'Room'].join(' / ');
    };
    editFields.forEach((el) => el.addEventListener('input', updateEditPreview));
    document.querySelectorAll('.edit-location').forEach((button) => {
      button.addEventListener('click', () => {
        document.getElementById('editLocId').value = button.dataset.id;
        document.getElementById('editLocDepartment').value = button.dataset.department;
        document.getElementById('editLocBuilding').value = button.dataset.building;
        document.getElementById('editLocRoom').value = button.dataset.room;
        updateEditPreview();
      });
    });
  </script>
  <script>
    (() => {
      const locations = <?= json_encode($locations) ?>;
      const NEW = '__new__';
      const departmentSelect = document.getElementById('departmentSelect');
      const buildingSelect = document.getElementById('buildingSelect');
      const department = document.getElementById('department');
      const building = document.getElementById('building');
      if (!departmentSelect || !buildingSelect) return;

      const fill = (select, values, placeholder) => {
        select.innerHTML = '';
        select.add(new Option(placeholder, ''));
        values.forEach((value) => select.add(new Option(value, value)));
        select.add(new Option('+ Add new…', NEW));
      };
      // The select picks an existing value; choosing "+ Add new…" reveals the text input that is submitted.
      const sync = (select, input) => {
        const isNew = select.value === NEW;
        input.classList.toggle('d-none', !isNew);
        input.required = isNew;
        if (!isNew) input.value = select.value;
      };
      const refreshBuildings = () => {
        const names = departmentSelect.value && departmentSelect.value !== NEW
          ? [...new Set(locations.filter((l) => l.department === departmentSelect.value).map((l) => l.building))]
          : [];
        fill(buildingSelect, names, 'Select building');
        if (departmentSelect.value === NEW || (departmentSelect.value && !names.length)) {
          buildingSelect.value = NEW;
        }
        building.value = '';
        sync(buildingSelect, building);
      };

      fill(departmentSelect, [...new Set(locations.map((l) => l.department))], 'Select department');
      departmentSelect.required = true;
      buildingSelect.required = true;
      departmentSelect.addEventListener('change', () => {
        department.value = '';
        sync(departmentSelect, department);
        refreshBuildings();
      });
      buildingSelect.addEventListener('change', () => sync(buildingSelect, building));
      if (!locations.length) departmentSelect.value = NEW;
      sync(departmentSelect, department);
      refreshBuildings();

      const room = document.getElementById('room');
      const preview = document.getElementById('locPreview');
      const updatePreview = () => {
        const parts = [department.value.trim(), building.value.trim(), room.value.trim() ? 'Room ' + room.value.trim() : ''];
        preview.textContent = '';
        parts.forEach((part, index) => {
          if (index) preview.append(' / ');
          const span = document.createElement('span');
          span.textContent = part || ['Department', 'Building', 'Room'][index];
          if (!part) span.className = 'placeholder-text';
          preview.append(span);
        });
      };
      ['change', 'input'].forEach((type) => {
        [departmentSelect, buildingSelect, department, building, room].forEach((el) => el.addEventListener(type, updatePreview));
      });
      document.getElementById('addLocationModal').addEventListener('shown.bs.modal', () => departmentSelect.focus());
      updatePreview();
    })();
  </script>
  <script src="assets/js/main.js"></script>
  <script src="assets/js/table-sort.js"></script>
</body>
</html>
