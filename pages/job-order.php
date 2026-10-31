<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_access('requisitions');
require_once __DIR__ . '/../config/db.php';

$id = (int) filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
$statement = $conn->prepare("SELECT r.*, u.full_name AS requester, a.full_name AS approver, p.full_name AS custodian, f.full_name AS fulfiller
  FROM tbl_requisitions r
  INNER JOIN tbl_users u ON u.id = r.requester_id
  LEFT JOIN tbl_users a ON a.id = r.reviewed_by
  LEFT JOIN tbl_users p ON p.id = r.processed_by
  LEFT JOIN tbl_users f ON f.id = r.fulfilled_by
  WHERE r.id = ? AND r.status IN ('Ready for Fulfillment', 'Fulfilled')");
$statement->bind_param('i', $id);
$statement->execute();
$req = $statement->get_result()->fetch_assoc();

$mayView = $req && (has_role('property_custodian', 'maintenance_personnel') || (int) $req['requester_id'] === (int) $_SESSION['user_id']);
if (!$mayView) {
  http_response_code(404);
  exit('Job order not found.');
}

$statement = $conn->prepare('SELECT i.asset_tag, i.asset_name, i.category, ri.quantity FROM tbl_requisition_items ri INNER JOIN tbl_inventory i ON i.id = ri.inventory_id WHERE ri.requisition_id = ? ORDER BY i.asset_tag');
$statement->bind_param('i', $id);
$statement->execute();
$assets = $statement->get_result()->fetch_all(MYSQLI_ASSOC);

// One entry per approver with the items they approved; requisitions that skipped approval have none.
$statement = $conn->prepare("SELECT u.full_name, MAX(l.decided_at) AS decided_at, GROUP_CONCAT(l.item_name ORDER BY l.id SEPARATOR ', ') AS items
  FROM tbl_requisition_lines l INNER JOIN tbl_users u ON u.id = l.decided_by
  WHERE l.requisition_id = ? AND l.approval_status = 'Approved' GROUP BY u.id, u.full_name ORDER BY MIN(l.id)");
$statement->bind_param('i', $id);
$statement->execute();
$approvers = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <base href="<?= APP_BASE_URL ?>/">
  <title>Job Order <?= h($req['job_order_number']) ?></title>
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #f1f3f5; }
    .sheet { max-width: 820px; margin: 24px auto; background: #fff; padding: 40px; border: 1px solid #dee2e6; }
    .signature { border-top: 1px solid #000; margin-top: 48px; padding-top: 4px; text-align: center; font-size: .9rem; }
    @media print {
      body { background: #fff; }
      .sheet { border: 0; margin: 0; padding: 0; max-width: none; }
      .no-print { display: none !important; }
    }
  </style>
</head>
<body>
  <div class="no-print text-center mt-3">
    <button class="btn btn-primary" onclick="window.print()">Print job order</button>
    <a class="btn btn-outline-secondary" href="pages/requisitions.php">Back</a>
  </div>

  <div class="sheet">
    <div class="text-center mb-4">
      <h3 class="mb-0">JOB ORDER</h3>
      <div class="text-muted">Property &amp; Asset Management</div>
    </div>

    <table class="table table-sm table-borderless mb-4">
      <tr><th style="width:30%">Job order no.</th><td class="fw-bold"><?= h($req['job_order_number']) ?></td><th style="width:20%">Date</th><td><?= h(substr((string) $req['processed_at'], 0, 10)) ?></td></tr>
      <tr><th>Requisition no.</th><td><?= h($req['req_number']) ?></td><th>Status</th><td><?= h($req['status']) ?></td></tr>
      <tr><th>Requested by</th><td><?= h($req['requester']) ?></td><th>Total quantity</th><td><?= (int) $req['quantity'] ?></td></tr>
      <tr><th>Deliver to</th><td><?= h($req['use_location']) ?></td><th>Active period</th><td><?= $req['borrow_until'] ? ($req['borrow_from'] ? h(date('M j, Y g:i A', strtotime($req['borrow_from']))) . ' to ' : 'Until ') . h(date('M j, Y g:i A', strtotime($req['borrow_until']))) : '—' ?></td></tr>
      <?php if ($req['purpose']): ?><tr><th>Purpose</th><td colspan="3"><?= h($req['purpose']) ?></td></tr><?php endif; ?>
      <?php if ($approvers): ?>
        <tr><th class="align-top">Approved by</th><td colspan="3">
          <?php foreach ($approvers as $approver): ?>
            <div><?= h($approver['full_name']) ?> <span class="text-muted small">— <?= h($approver['items']) ?> (<?= h(substr((string) $approver['decided_at'], 0, 16)) ?>)</span></div>
          <?php endforeach; ?>
        </td></tr>
      <?php elseif ($req['approver']): ?>
        <tr><th>Approved by</th><td colspan="3"><?= h($req['approver']) ?> <span class="text-muted small">(<?= h(substr((string) $req['reviewed_at'], 0, 16)) ?>)</span></td></tr>
      <?php endif; ?>
    </table>

    <p class="mb-2">Maintenance personnel: retrieve the following items from storage and set them up at the location above.</p>
    <table class="table table-bordered">
      <thead><tr><th style="width:6%">#</th><th>Asset tag</th><th>Item</th><th>Category</th><th style="width:8%">Qty</th><th style="width:12%">Checked</th></tr></thead>
      <tbody>
        <?php foreach ($assets as $index => $asset): ?>
          <tr><td><?= $index + 1 ?></td><td class="font-monospace fw-bold"><?= h($asset['asset_tag']) ?></td><td><?= h($asset['asset_name']) ?></td><td><?= h($asset['category']) ?></td><td><?= (int) $asset['quantity'] ?></td><td></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($approvers): ?>
      <div class="row mt-5">
        <?php foreach ($approvers as $approver): ?>
          <div class="col-4"><div class="signature"><?= h($approver['full_name']) ?><br>Approver</div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="row mt-5">
      <div class="col-4"><div class="signature"><?= h($req['custodian'] ?? '') ?><br>Property custodian</div></div>
      <div class="col-4"><div class="signature"><?= h($req['fulfiller'] ?? '') ?><br>Maintenance personnel</div></div>
      <div class="col-4"><div class="signature"><?= h($req['requester']) ?><br>Received by (requester)</div></div>
    </div>
  </div>
</body>
</html>
