<?php
$navCurrent = basename((string) parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH));
$navItem = static function (string $href, string $icon, string $label) use ($navCurrent): void {
  $active = basename($href) === $navCurrent;
  echo '<li class="nav-item"><a class="nav-link' . ($active ? ' active' : '') . '" href="' . htmlspecialchars($href, ENT_QUOTES) . '"'
    . ($active ? ' aria-current="page"' : '') . '><i class="bi ' . $icon . '"></i><span>' . htmlspecialchars($label, ENT_QUOTES) . '</span></a></li>';
};
?>
<aside id="sidebar" class="sidebar app-sidebar">

  <ul class="sidebar-nav" id="sidebar-nav">

    <li class="nav-heading">Overview</li>
    <?php
    $navItem('pages/dashboard.php', 'bi-grid', 'Dashboard');
    $navItem('pages/requisitions.php', 'bi-clipboard-check', 'Requisitions');
    if (can_access('joborders')) $navItem('pages/job-orders.php', 'bi-file-earmark-text', 'Job Orders');
    ?>

    <li class="nav-heading">Assets</li>
    <?php
    if (can_access('stock') && !can_access('inventory')) $navItem('pages/stock.php', 'bi-search', 'Item Stock');
    if (can_access('inventory')) {
      $navItem('pages/inventory.php', 'bi-box-seam', 'Inventory');
      $navItem('pages/locations.php', 'bi-geo-alt', 'Locations');
    }
    if (can_access('maintenance')) $navItem('pages/maintenance.php', 'bi-wrench', 'Maintenance');
    if (can_access('inventory')) $navItem('pages/disposal.php', 'bi-archive', 'Disposal');
    $navItem('pages/qrscanner.php', 'bi-qr-code-scan', 'QR Scanner');
    if (can_access('inventory')) $navItem('pages/qr-generator.php', 'bi-qr-code', 'QR Labels');
    ?>

    <li class="nav-heading">Insights</li>
    <?php
    if (can_access('procurement')) $navItem('pages/procurement.php', 'bi-cart-check', 'Procurement');
    $navItem('assets.php', 'bi-robot', 'AI Recommendations');
    $navItem('assets.php', 'bi-file-earmark-bar-graph', 'Reports');
    ?>

    <li class="nav-heading">Account</li>
    <?php
    $navItem('profile.php', 'bi-person', 'Profile');
    if (can_access('users')) $navItem('pages/users.php', 'bi-people', 'User Accounts');
    $navItem('pages/logout.php', 'bi-box-arrow-right', 'Logout');
    ?>

  </ul>

</aside><!-- End Sidebar-->

<style>
  .app-sidebar { background-color: #0b192c !important; padding: 14px 12px 24px; }
  .app-sidebar .sidebar-nav { display: flex; flex-direction: column; gap: 2px; }
  .app-sidebar .nav-heading {
    margin: 20px 0 6px;
    padding: 0 12px;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .09em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, .42);
  }
  .app-sidebar .nav-heading:first-child { margin-top: 4px; }
  .app-sidebar .nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 10px;
    font-size: .92rem;
    font-weight: 500;
    color: rgba(255, 255, 255, .78);
    background: transparent !important;
    transition: background-color .15s ease, color .15s ease;
  }
  .app-sidebar .nav-link i {
    flex: 0 0 auto;
    width: 20px;
    margin: 0;
    font-size: 1.05rem;
    text-align: center;
    color: inherit !important;
    opacity: .85;
  }
  .app-sidebar .nav-link:hover,
  .app-sidebar .nav-link:focus {
    background-color: rgba(255, 255, 255, .08) !important;
    color: #fff;
  }
  .app-sidebar .nav-link.active {
    background-color: rgba(13, 110, 253, .22) !important;
    color: #fff;
    box-shadow: inset 3px 0 0 #4d9aff;
  }
  .app-sidebar .nav-link.active i { color: #8ec0ff !important; opacity: 1; }
</style>
