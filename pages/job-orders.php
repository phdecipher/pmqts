<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_access('joborders');
require_once __DIR__ . '/../config/db.php';

$flash = take_flash();
$csrfToken = csrf_token();
$userId = (int) $_SESSION['user_id'];

$tab = in_array($_GET['tab'] ?? '', ['return', 'done'], true) ? $_GET['tab'] : 'todo';
$sql = "SELECT r.id, r.req_number, r.job_order_number, r.use_location, r.purpose, r.status, r.borrow_from, r.borrow_until,
    r.processed_at, r.fulfilled_at, r.returned_at, rb.full_name AS returner, u.full_name AS requester, pr.full_name AS processor, fu.full_name AS fulfiller, r.fulfilled_by,
    (SELECT GROUP_CONCAT(CONCAT(l.item_name, '|', l.quantity) ORDER BY l.id SEPARATOR '||') FROM tbl_requisition_lines l WHERE l.requisition_id = r.id AND l.approval_status = 'Approved') AS items,
    (SELECT GROUP_CONCAT(CONCAT(inv.asset_tag, IF(inv.is_countable = 1, CONCAT(' ×', ri.quantity), '')) ORDER BY inv.asset_tag SEPARATOR ', ') FROM tbl_requisition_items ri INNER JOIN tbl_inventory inv ON inv.id = ri.inventory_id WHERE ri.requisition_id = r.id) AS asset_tags,
    (SELECT GROUP_CONCAT(DISTINCT COALESCE(NULLIF(ri.origin_location, ''), IF(oi.is_countable = 1, oi.location, NULL)) SEPARATOR ' • ') FROM tbl_requisition_items ri INNER JOIN tbl_inventory oi ON oi.id = ri.inventory_id WHERE ri.requisition_id = r.id AND COALESCE(NULLIF(ri.origin_location, ''), IF(oi.is_countable = 1, oi.location, NULL)) IS NOT NULL) AS origins
  FROM tbl_requisitions r
  INNER JOIN tbl_users u ON u.id = r.requester_id
  LEFT JOIN tbl_users pr ON pr.id = r.processed_by
  LEFT JOIN tbl_users fu ON fu.id = r.fulfilled_by
  LEFT JOIN tbl_users rb ON rb.id = r.returned_by
  WHERE r.status = ? AND r.job_order_number IS NOT NULL
  ORDER BY " . ($tab === 'done' ? 'r.returned_at DESC, r.id DESC' : ($tab === 'return' ? 'r.fulfilled_at DESC' : 'COALESCE(r.processed_at, r.created_at) DESC, r.id DESC'));
$wanted = ['todo' => 'Ready for Fulfillment', 'return' => 'Fulfilled', 'done' => 'Returned'][$tab];
$statement = $conn->prepare($sql);
$statement->bind_param('s', $wanted);
$statement->execute();
$orders = $statement->get_result()->fetch_all(MYSQLI_ASSOC);

$returnLines = [];
if ($orders) {
  $ids = implode(',', array_map('intval', array_column($orders, 'id')));
  $lineResult = $conn->query("SELECT ri.requisition_id, ri.inventory_id, rp.report_type, rp.quantity AS rep_qty, rp.disposal_method AS rep_method, i.asset_name, i.asset_tag, i.is_countable, ri.quantity, i.location AS current_location,
      COALESCE(NULLIF(ri.origin_location, ''), IF(i.is_countable = 1, i.location, NULL)) AS origin
    FROM tbl_requisition_items ri INNER JOIN tbl_inventory i ON i.id = ri.inventory_id
    LEFT JOIN tbl_requisition_reports rp ON rp.requisition_id = ri.requisition_id AND rp.inventory_id = ri.inventory_id
    WHERE ri.requisition_id IN ($ids) ORDER BY i.asset_name, i.asset_tag");
  foreach ($lineResult->fetch_all(MYSQLI_ASSOC) as $line) {
    $returnLines[(int) $line['requisition_id']][] = $line;
  }
}

$todoCount = (int) $conn->query("SELECT COUNT(*) FROM tbl_requisitions WHERE status = 'Ready for Fulfillment' AND job_order_number IS NOT NULL")->fetch_row()[0];
$returnCount = (int) $conn->query("SELECT COUNT(*) FROM tbl_requisitions WHERE status = 'Fulfilled' AND job_order_number IS NOT NULL")->fetch_row()[0];
$doneCount = (int) $conn->query("SELECT COUNT(*) FROM tbl_requisitions WHERE status = 'Returned' AND job_order_number IS NOT NULL")->fetch_row()[0];
$now = date('Y-m-d H:i:s');

$fmt = static fn($value, $format) => $value ? date($format, strtotime((string) $value)) : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <base href="<?= APP_BASE_URL ?>/">
  <title>Job Orders - NiceAdmin Template</title>
  <link href="assets/img/favicon.png" rel="icon">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Nunito:300,400,600,700|Poppins:300,400,500,600,700" rel="stylesheet">
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
  <link href="assets/css/responsive.css" rel="stylesheet">
  <style>
    .jo-item[hidden] { display: none !important; }
    .jo-card { border-left: 5px solid var(--jo-accent, #0d6efd); }
    .jo-card.done { --jo-accent: #198754; }
    .jo-num { font-family: monospace; font-weight: 700; color: #0a3d91; }
    .jo-qty { background: #e7f1ff; color: #0d6efd; border-radius: 999px; font-size: .75rem; font-weight: 600; padding: 1px 8px; }
    .jo-ret { border: 1px solid #d3e3ff; border-radius: .6rem; overflow: hidden; }
    .jo-ret-row { display: flex; align-items: center; gap: .75rem; padding: .55rem .75rem; border-top: 1px solid #e6eefc; }
    .jo-ret-row:first-child { border-top: 0; }
    .jo-ret-main { flex: 1 1 45%; min-width: 0; }
    .jo-ret-to { flex: 1 1 55%; min-width: 0; background: #f1f6ff; border-radius: .5rem; padding: .35rem .6rem; font-size: .85rem; }
    .jo-ret-act { flex: 0 0 auto; }
    .jo-ret-to.missing { background: #f8f9fa; color: #6c757d; font-style: italic; }
    @media (max-width: 575px) { .jo-ret-row { flex-direction: column; align-items: stretch; gap: .35rem; } }
    .jo-loc { background: #f1f6ff; border: 1px solid #d3e3ff; border-radius: .6rem; padding: .5rem .75rem; }
    .jo-period { background: #fff8e6; border: 1px solid #ffe29a; border-radius: .6rem; padding: .5rem .75rem; color: #4d3a00; }
    .jo-period .lbl { font-size: .65rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #7a5a00; display: inline-block; width: 2.6rem; }
    .jo-period .time { background: #ffe29a; border-radius: 999px; padding: 0 7px; font-weight: 600; }
    .jo-period.overdue { background: #fdecea; border-color: #f5b5ae; color: #842029; }
    .jo-period.overdue .lbl { color: #842029; }
    .jo-period.overdue .time { background: #f5b5ae; }
  </style>
</head>

<body>
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

  <main id="main" class="main">
    <div class="container-fluid">

      <div class="pagetitle">
        <h1>Job Orders</h1>
        <nav>
          <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="pages/dashboard.php">Home</a></li>
            <li class="breadcrumb-item active">Job Orders</li>
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
        <ul class="nav nav-pills mb-3 gap-2">
          <li class="nav-item"><a class="nav-link <?= $tab === 'todo' ? 'active' : 'bg-white border' ?>" href="pages/job-orders.php"><i class="bi bi-hourglass-split me-1"></i>To fulfill <span class="badge bg-light text-dark ms-1"><?= $todoCount ?></span></a></li>
          <li class="nav-item"><a class="nav-link <?= $tab === 'return' ? 'active' : 'bg-white border' ?>" href="pages/job-orders.php?tab=return"><i class="bi bi-box-arrow-in-left me-1"></i>To return <span class="badge bg-light text-dark ms-1"><?= $returnCount ?></span></a></li>
          <li class="nav-item"><a class="nav-link <?= $tab === 'done' ? 'active' : 'bg-white border' ?>" href="pages/job-orders.php?tab=done"><i class="bi bi-check-circle me-1"></i>Returned <span class="badge bg-light text-dark ms-1"><?= $doneCount ?></span></a></li>
        </ul>

        <?php if (!$orders): ?>
          <div class="card"><div class="card-body text-center text-muted py-5"><i class="bi bi-file-earmark-text d-block fs-1 mb-2"></i><?= ['todo' => 'No job orders are waiting to be fulfilled.', 'return' => 'Nothing is out on loan right now.', 'done' => 'No returned job orders yet.'][$tab] ?></div></div>
        <?php else: ?>
          <div class="input-group mb-3 jo-search">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input type="search" id="joSearch" class="form-control" placeholder="Search job order, requisition, requester, item, asset tag or location" autocomplete="off">
            <span class="input-group-text bg-white text-muted small"><span id="joCount"><?= count($orders) ?></span>&nbsp;shown</span>
          </div>
          <div class="row g-3" id="joList">
            <?php foreach ($orders as $order): ?>
              <?php
                $items = array_filter(array_map(static fn($pair) => explode('|', $pair, 2), $order['items'] ? explode('||', $order['items']) : []));
                $ended = $order['borrow_until'] && $order['borrow_until'] <= $now;
                $overdue = $tab !== 'done' && $ended;
              ?>
              <div class="col-12 col-xl-6 jo-item">
                <div class="card jo-card <?= $tab !== 'todo' ? 'done' : '' ?> h-100 mb-0">
                  <div class="card-body p-4 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                      <div>
                        <div class="jo-num fs-5"><i class="bi bi-file-earmark-text me-1"></i><?= h($order['job_order_number']) ?></div>
                        <div class="small text-muted"><?= h($order['req_number']) ?> · requested by <strong class="text-body"><?= h($order['requester']) ?></strong></div>
                      </div>
                      <span class="badge rounded-pill <?= ['todo' => 'bg-success', 'return' => 'bg-warning text-dark', 'done' => 'bg-secondary'][$tab] ?>"><?= ['todo' => 'Ready', 'return' => $ended ? 'Due for return' : 'On loan', 'done' => 'Returned'][$tab] ?></span>
                    </div>

                    <?php if (!empty($returnLines[(int) $order['id']])): ?>
                    <div class="mb-3">
                      <div class="text-muted fw-bold mb-1" style="font-size:.65rem;letter-spacing:.05em"><i class="bi <?= $tab === 'todo' ? 'bi-box-arrow-in-down text-primary' : ($tab === 'done' ? 'bi-check2-circle text-success' : 'bi-arrow-return-left text-primary') ?> me-1"></i><?= ['todo' => 'PICK UP EACH ITEM FROM', 'return' => 'RETURN EACH ITEM TO', 'done' => 'RETURNED TO'][$tab] ?></div>
                      <div class="jo-ret">
                        <?php foreach ($returnLines[(int) $order['id']] as $line): ?>
                          <div class="jo-ret-row">
                            <div class="jo-ret-main">
                              <div class="fw-semibold text-truncate"><i class="bi bi-box-seam text-primary me-1"></i><?= h($line['asset_name']) ?><?php if ((int) $line['is_countable'] === 1): ?> <span class="jo-qty">×<?= (int) $line['quantity'] ?></span><?php endif; ?></div>
                              <div class="small font-monospace text-muted"><?= h($line['asset_tag']) ?></div>
                            </div>
                            <?php $itemLocation = $tab === 'todo' ? $line['current_location'] : $line['origin']; ?>
                            <div class="jo-ret-to <?= $itemLocation ? '' : 'missing' ?>"><i class="bi bi-arrow-right-short"></i><?= $itemLocation ? '<strong>' . h($itemLocation) . '</strong>' : ($tab === 'todo' ? 'Pickup location not recorded' : 'Original location not recorded') ?></div>                            <?php if ($tab !== 'todo'): ?>
                              <div class="jo-ret-act">
                                <?php if ($line['report_type']): ?>
                                  <span class="badge rounded-pill <?= $line['report_type'] === 'Repair' ? 'bg-warning text-dark' : 'bg-danger' ?>"><i class="bi <?= $line['report_type'] === 'Repair' ? 'bi-wrench' : 'bi-trash3' ?> me-1"></i><?= $line['report_type'] === 'Repair' ? 'Needs repair' : 'For disposal' . ($line['rep_method'] ? ' · ' . h($line['rep_method']) : '') ?><?= (int) $line['is_countable'] === 1 ? ' ×' . (int) $line['rep_qty'] : '' ?></span>
                                <?php elseif ($tab === 'return'): ?>
                                  <button type="button" class="btn btn-outline-danger btn-sm jo-report" data-req="<?= (int) $order['id'] ?>" data-inv="<?= (int) $line['inventory_id'] ?>" data-name="<?= h($line['asset_name']) ?>" data-tag="<?= h($line['asset_tag']) ?>" data-countable="<?= (int) $line['is_countable'] ?>" data-max="<?= (int) $line['quantity'] ?>"><i class="bi bi-exclamation-triangle me-1"></i>Report</button>
                                <?php endif; ?>
                              </div>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                      <?php if ($order['purpose']): ?><div class="small text-muted fst-italic mt-2"><?= h($order['purpose']) ?></div><?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="mb-3">
                      <?php foreach ($items as [$itemName, $itemQty]): ?>
                        <div class="d-flex align-items-center gap-2 py-1"><i class="bi bi-box-seam text-primary"></i><span class="fw-semibold"><?= h($itemName) ?></span><span class="jo-qty">×<?= (int) $itemQty ?></span></div>
                      <?php endforeach; ?>
                      <?php if ($order['asset_tags']): ?><div class="small font-monospace text-muted mt-1"><?= h($order['asset_tags']) ?></div><?php endif; ?>
                      <?php if ($order['purpose']): ?><div class="small text-muted fst-italic mt-1"><?= h($order['purpose']) ?></div><?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="row g-2 mb-3">
                      <div class="col-sm-6">
                        <div class="jo-loc h-100">
                          <?php if ($tab !== 'todo'): ?>
                            <div class="text-muted fw-bold" style="font-size:.65rem;letter-spacing:.05em"><i class="bi bi-geo-alt-fill text-primary me-1"></i><?= $tab === 'done' ? 'USED AT' : 'CURRENTLY AT' ?></div>
                            <div class="fw-semibold"><?= h($order['use_location']) ?></div>
                          <?php else: ?>
                            <div class="text-muted fw-bold" style="font-size:.65rem;letter-spacing:.05em"><i class="bi bi-geo-alt-fill text-primary me-1"></i>DELIVER TO</div>
                            <div class="fw-semibold"><?= h($order['use_location']) ?></div>
                          <?php endif; ?>
                        </div>
                      </div>
                      <div class="col-sm-6">
                        <?php if ($order['borrow_until']): ?>
                          <div class="jo-period h-100 <?= $overdue ? 'overdue' : '' ?>">
                            <?php if ($order['borrow_from']): ?><div><span class="lbl">From</span><strong><?= h($fmt($order['borrow_from'], 'M j, Y')) ?></strong> <span class="time"><?= h($fmt($order['borrow_from'], 'g:i A')) ?></span></div><?php endif; ?>
                            <div><span class="lbl"><?= $order['borrow_from'] ? 'To' : 'Until' ?></span><strong><?= h($fmt($order['borrow_until'], 'M j, Y')) ?></strong> <span class="time"><?= h($fmt($order['borrow_until'], 'g:i A')) ?></span></div>
                            <?php if ($overdue): ?><div class="small fw-semibold mt-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Past its end time</div><?php endif; ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>

                    <div class="small text-muted mb-3">
                      <i class="bi bi-person-check"></i> Approved and processed by <strong class="text-body"><?= h($order['processor'] ?? '—') ?></strong> · <?= h($fmt($order['processed_at'], 'M j, g:i A')) ?>
                      <?php if ($tab !== 'todo' && $order['fulfilled_at']): ?>
                        <div class="mt-1"><i class="bi bi-box-seam text-success"></i> Fulfilled &amp; confirmed by <strong class="text-body"><?= h($order['fulfiller'] ?? '—') ?></strong><?= (int) $order['fulfilled_by'] === $userId ? ' <span class="badge bg-primary-subtle text-primary">You</span>' : '' ?> · <?= h($fmt($order['fulfilled_at'], 'M j, Y, g:i A')) ?></div>
                      <?php endif; ?>
                    </div>

                    <?php if ($tab === 'done' && $order['returned_at']): ?><div class="small text-muted mb-3"><i class="bi bi-box-arrow-in-left text-info"></i> Returned by <strong class="text-body"><?= h($order['returner'] ?? '—') ?></strong> · <?= h($fmt($order['returned_at'], 'M j, Y, g:i A')) ?></div><?php endif; ?>

                    <div class="d-flex gap-2 mt-auto pt-3 border-top justify-content-end flex-wrap">
                      <a class="btn btn-outline-primary" target="_blank" href="pages/job-order.php?id=<?= (int) $order['id'] ?>"><i class="bi bi-printer me-1"></i>View / print job order</a>
                      <?php if ($tab === 'todo'): ?>
                        <form method="post" action="pages/requisitions.php" class="confirm-form" data-icon="bi-check2-circle text-success" data-title="Confirm fulfillment?" data-message="Confirm that <?= h($order['req_number']) ?> was fulfilled and delivered to <?= h($order['use_location']) ?>." data-confirm="Yes, it's fulfilled" data-style="btn-success">
                          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                          <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                          <input type="hidden" name="return" value="job-orders"><input type="hidden" name="tab" value="<?= h($tab) ?>">
                          <button class="btn btn-success" name="action" value="fulfill"><i class="bi bi-check2-circle me-1"></i>Confirm fulfilled</button>
                        </form>
                      <?php elseif ($tab === 'return'): ?>
                        <?php if ($ended): ?>
                        <form method="post" action="pages/requisitions.php" class="confirm-form" data-icon="bi-box-arrow-in-left text-info" data-title="Have the assets been returned?" data-message="Only confirm if you have physically returned the assets of <?= h($order['req_number']) ?> to their original location. Stock records will be updated." data-confirm="Yes, I returned them" data-style="btn-info">
                          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                          <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                          <input type="hidden" name="return" value="job-orders"><input type="hidden" name="tab" value="<?= h($tab) ?>">
                          <button class="btn btn-info" name="action" value="return"><i class="bi bi-check2-circle me-1"></i>I've returned the assets</button>
                        </form>
                        <?php else: ?>
                        <button class="btn btn-outline-secondary" disabled title="Available once the active period ends"><i class="bi bi-clock me-1"></i>Return assets after <?= h($fmt($order['borrow_until'], 'M j, g:i A')) ?></button>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="card" id="joEmpty" hidden><div class="card-body text-center text-muted py-5"><i class="bi bi-search d-block fs-1 mb-2"></i>No job orders match your search.</div></div>
        <?php endif; ?>
      </section>

      <?php if ($tab === 'return'): ?>
      <div class="modal fade" id="reportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <form method="post" action="pages/requisitions.php">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <input type="hidden" name="action" value="report">
              <input type="hidden" name="return" value="job-orders">
              <input type="hidden" name="tab" value="return">
              <input type="hidden" name="id" id="reportReq">
              <input type="hidden" name="inventory_id" id="reportInv">
              <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Report damaged asset</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <div class="p-2 rounded bg-light border mb-3"><div class="fw-semibold" id="reportName"></div><div class="small font-monospace text-muted" id="reportTag"></div></div>
                <div class="mb-3">
                  <label class="form-label">What did you find?</label>
                  <div class="d-grid gap-2">
                    <input type="radio" class="btn-check" name="report_type" id="repTypeRepair" value="Repair" required>
                    <label class="btn btn-outline-warning text-start" for="repTypeRepair"><i class="bi bi-wrench me-2"></i><strong>Damaged — needs repair</strong><div class="small text-muted">Goes to Maintenance as pulled out.</div></label>
                    <input type="radio" class="btn-check" name="report_type" id="repTypeDisposal" value="Disposal">
                    <label class="btn btn-outline-danger text-start" for="repTypeDisposal"><i class="bi bi-trash3 me-2"></i><strong>Beyond repair — for disposal</strong><div class="small text-muted">Goes to the Disposal records.</div></label>
                  </div>
                </div>
                <div class="mb-3" id="reportMethodWrap" hidden>
                  <label class="form-label" for="reportMethod">Disposal method</label>
                  <select class="form-select" name="disposal_method" id="reportMethod">
                    <option value="">Select method</option>
                    <?php foreach (REPORT_DISPOSAL_METHODS as $method): ?><option><?= h($method) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="mb-3" id="reportQtyWrap" hidden>
                  <label class="form-label" for="reportQty">How many units are affected?</label>
                  <input type="number" class="form-control" name="quantity" id="reportQty" min="1" value="1">
                  <div class="form-text" id="reportQtyHelp"></div>
                </div>
                <div>
                  <label class="form-label" for="reportNotes">Details <span class="text-muted">(optional)</span></label>
                  <textarea class="form-control" name="notes" id="reportNotes" rows="3" maxlength="500" placeholder="Describe the damage or why it can't be repaired"></textarea>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Submit report</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
          <div class="modal-content text-center">
            <div class="modal-body p-4">
              <i class="bi fs-1 d-block mb-2" id="confirmIcon"></i>
              <h5 class="mb-2" id="confirmTitle"></h5>
              <p class="text-muted small mb-4" id="confirmMessage"></p>
              <div class="d-flex gap-2 justify-content-center">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Go back</button>
                <button type="button" class="btn" id="confirmOk"></button>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </main>

  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/main.js"></script>
  <script>
    (() => {
      const search = document.getElementById('joSearch');
      if (search) {
        const items = [...document.querySelectorAll('.jo-item')];
        search.addEventListener('input', () => {
          const terms = search.value.toLowerCase().split(/\s+/).filter(Boolean);
          let shown = 0;
          items.forEach((item) => {
            const text = item.textContent.toLowerCase();
            const match = terms.every((term) => text.includes(term));
            item.hidden = !match;
            if (match) shown++;
          });
          document.getElementById('joCount').textContent = shown;
          document.getElementById('joEmpty').hidden = shown > 0;
        });
      }
      const reportEl = document.getElementById('reportModal');
      if (reportEl) {
        const reportModal = new bootstrap.Modal(reportEl);
        const methodWrap = document.getElementById('reportMethodWrap');
        const method = document.getElementById('reportMethod');
        const qtyWrap = document.getElementById('reportQtyWrap');
        const qty = document.getElementById('reportQty');
        const syncType = () => {
          const disposal = document.getElementById('repTypeDisposal').checked;
          methodWrap.hidden = !disposal;
          method.required = disposal;
        };
        reportEl.querySelectorAll('input[name="report_type"]').forEach((radio) => radio.addEventListener('change', syncType));
        document.querySelectorAll('.jo-report').forEach((button) => {
          button.addEventListener('click', () => {
            reportEl.querySelector('form').reset();
            document.getElementById('reportReq').value = button.dataset.req;
            document.getElementById('reportInv').value = button.dataset.inv;
            document.getElementById('reportName').textContent = button.dataset.name;
            document.getElementById('reportTag').textContent = button.dataset.tag;
            const countable = button.dataset.countable === '1';
            qtyWrap.hidden = !countable;
            qty.disabled = !countable;
            qty.max = button.dataset.max;
            document.getElementById('reportQtyHelp').textContent = 'Up to ' + button.dataset.max + ' unit(s) were borrowed.';
            syncType();
            reportModal.show();
          });
        });
      }      const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
      let pending = null;
      document.querySelectorAll('.confirm-form').forEach((form) => {
        form.addEventListener('submit', (e) => {
          if (form.dataset.confirmed) return;
          e.preventDefault();
          pending = { form, submitter: e.submitter };
          document.getElementById('confirmIcon').className = 'bi fs-1 d-block mb-2 ' + form.dataset.icon;
          document.getElementById('confirmTitle').textContent = form.dataset.title;
          document.getElementById('confirmMessage').textContent = form.dataset.message;
          const ok = document.getElementById('confirmOk');
          ok.className = 'btn ' + form.dataset.style;
          ok.textContent = form.dataset.confirm;
          confirmModal.show();
        });
      });
      document.getElementById('confirmOk').addEventListener('click', () => {
        if (!pending) return;
        pending.form.dataset.confirmed = '1';
        pending.form.requestSubmit(pending.submitter);
      });
    })();
  </script>
</body>

</html>
