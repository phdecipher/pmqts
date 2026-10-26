<?php
require_once __DIR__ . '/../config/app.php';
require_login();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['valid' => false, 'message' => 'POST required.']);
    exit();
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['valid' => false, 'message' => 'Invalid session token.']);
    exit();
}

$token = $_POST['token'] ?? '';
if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    echo json_encode(['valid' => false]);
    exit();
}

$statement = $conn->prepare("SELECT asset_tag, asset_name FROM tbl_inventory WHERE qr_token = ? AND status <> 'Retired' LIMIT 1");
$statement->bind_param('s', $token);
$statement->execute();
$asset = $statement->get_result()->fetch_assoc();

echo json_encode($asset
    ? ['valid' => true, 'asset_tag' => $asset['asset_tag'], 'asset_name' => $asset['asset_name']]
    : ['valid' => false]);
