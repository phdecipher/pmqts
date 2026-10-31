<?php
// Keep PHP's clock aligned with the server/MySQL local time (active periods are compared against it).
date_default_timezone_set('Asia/Manila');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));

if (basename($scriptDirectory) === 'pages') {
    $scriptDirectory = dirname($scriptDirectory);
}

define('APP_BASE_URL', rtrim($scriptDirectory, '/') ?: '');
define('APP_ROOT_PATH', dirname(__DIR__));

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function set_flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $appPrefix = APP_BASE_URL . '/';
        if (is_string($requestPath) && str_starts_with($requestPath, $appPrefix)) {
            $_SESSION['after_login'] = $requestPath;
            $query = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                $_SESSION['after_login'] .= '?' . $query;
            }
        }
        set_flash('Sign in to continue.', 'warning');
        header('Location: ' . APP_BASE_URL . '/pages/login.php');
        exit();
    }

    refresh_session_user();
}

const USER_ROLES = [
    'admin' => 'Administrator',
    'property_custodian' => 'Property Custodian',
    'teacher' => 'Teacher',
    'department_head' => 'Office / Department Head',
    'approver' => 'Designated Approver / Signatory',
    'maintenance_personnel' => 'Maintenance Personnel',
    'procurement_personnel' => 'Procurement Personnel',
    'security_personnel' => 'Security Personnel',
];

// Roles allowed per area. Administrators are always allowed.
const ROLE_ACCESS = [
    'inventory' => ['property_custodian'],
    'maintenance' => ['property_custodian', 'maintenance_personnel'],
    'joborders' => ['property_custodian', 'maintenance_personnel'],
    'procurement' => ['property_custodian', 'procurement_personnel', 'approver'],
    'requisitions' => ['teacher', 'department_head', 'approver', 'property_custodian', 'maintenance_personnel'],
    'stock' => ['teacher', 'department_head', 'approver', 'property_custodian'],
    'users' => [],
];

// Units available per inventory row. A countable row offers its quantity minus what is reserved by
// requisitions awaiting fulfillment (fulfilling deducts the stock). A one-unit row is available
// unless a requisition has it reserved or already took it.
const AVAILABLE_EXPR = "CASE WHEN i.is_countable = 1
  THEN GREATEST(CAST(COALESCE(i.quantity, 0) AS SIGNED) - COALESCE((SELECT SUM(ri.quantity) FROM tbl_requisition_items ri INNER JOIN tbl_requisitions r ON r.id = ri.requisition_id WHERE ri.inventory_id = i.id AND r.status = 'Ready for Fulfillment'), 0), 0)
  ELSE IF(EXISTS (SELECT 1 FROM tbl_requisition_items ri INNER JOIN tbl_requisitions r ON r.id = ri.requisition_id WHERE ri.inventory_id = i.id AND r.status IN ('Ready for Fulfillment', 'Fulfilled')), 0, 1) END";

// Stock shown to users: a countable row shows its on-hand quantity, which only drops when a requisition
// is fulfilled. Reservations still limit allocation (AVAILABLE_EXPR) but are not subtracted from the display.
const STOCK_DISPLAY_EXPR = "CASE WHEN i.is_countable = 1 THEN GREATEST(CAST(COALESCE(i.quantity, 0) AS SIGNED), 0)
  ELSE IF(EXISTS (SELECT 1 FROM tbl_requisition_items ri INNER JOIN tbl_requisitions r ON r.id = ri.requisition_id WHERE ri.inventory_id = i.id AND r.status IN ('Ready for Fulfillment', 'Fulfilled')), 0, 1) END";

// A user is assigned to a requisition when they are the approver/custodian of an asset of any requested
// item. If none of the requested items has anyone assigned, it stays open to every eligible user.
const ASSIGNED_SQL = "(EXISTS (SELECT 1 FROM tbl_requisition_lines l INNER JOIN tbl_inventory a ON a.asset_name = l.item_name WHERE l.requisition_id = r.id AND a.%1\$s = ?)
  OR NOT EXISTS (SELECT 1 FROM tbl_requisition_lines l INNER JOIN tbl_inventory a ON a.asset_name = l.item_name WHERE l.requisition_id = r.id AND a.%1\$s IS NOT NULL))";

// Each item is decided by the approver assigned to that item; unassigned items are open to any approver.
const LINE_APPROVER_SQL = "(EXISTS (SELECT 1 FROM tbl_inventory a WHERE a.asset_name = l.item_name AND a.approver_id = ?)
  OR NOT EXISTS (SELECT 1 FROM tbl_inventory a WHERE a.asset_name = l.item_name AND a.approver_id IS NOT NULL))";

function role_label(?string $role): string
{
    return USER_ROLES[$role] ?? ucfirst((string) $role);
}

function current_role(): string
{
    return (string) ($_SESSION['user_role'] ?? '');
}

function has_role(string ...$roles): bool
{
    return current_role() === 'admin' || in_array(current_role(), $roles, true);
}

function can_access(string $area): bool
{
    return has_role(...(ROLE_ACCESS[$area] ?? []));
}

function require_access(string $area): void
{
    if (!can_access($area)) {
        http_response_code(403);
        exit('Your role does not have access to this page.');
    }
}

// Re-reads the account so role changes and deactivation apply without waiting for a new sign-in.
function refresh_session_user(): void
{
    global $conn;
    if (!isset($conn)) {
        require_once __DIR__ . '/db.php';
    }
    $statement = $conn->prepare('SELECT full_name, username, role, is_active FROM tbl_users WHERE id = ?');
    $statement->bind_param('i', $_SESSION['user_id']);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc();
    if (!$user || (int) $user['is_active'] !== 1) {
        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['full_name'], $_SESSION['user_role']);
        set_flash('Your account is no longer active.', 'warning');
        header('Location: ' . APP_BASE_URL . '/pages/login.php');
        exit();
    }
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'] !== '' ? $user['full_name'] : $user['username'];
    $_SESSION['user_role'] = $user['role'];
}

// Disposal methods offered when damaged assets are reported during a return.
const REPORT_DISPOSAL_METHODS = ['Donation', 'Scrap', 'Thrown Away'];

// Next sequential tag in the form AST-YYYY-0001 for the current year.
function next_asset_tag(mysqli $conn): string
{
  $prefix = 'AST-' . date('Y') . '-';
  $like = $prefix . '%';
  $statement = $conn->prepare('SELECT asset_tag FROM tbl_inventory WHERE asset_tag LIKE ?');
  $statement->bind_param('s', $like);
  $statement->execute();
  $result = $statement->get_result();
  $max = 0;
  while ($row = $result->fetch_assoc()) {
    $suffix = substr($row['asset_tag'], strlen($prefix));
    if (ctype_digit($suffix)) {
      $max = max($max, (int) $suffix);
    }
  }
  return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}
