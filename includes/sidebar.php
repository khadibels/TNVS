<?php
// includes/sidebar.php

if (!defined('BASE_URL')) { define('BASE_URL', ''); }
$BASE    = rtrim(BASE_URL, '/');
$role    = strtolower($_SESSION['user']['role'] ?? '');
$active  = $active  ?? '';
$section = $section ?? '';

function u($path){ global $BASE; return $BASE . '/' . ltrim($path, '/'); }
function a($key){ global $active; return $active === $key ? ' active' : ''; }
function a_any(array $keys){ global $active; return in_array($active ?? '', $keys, true); }

if ($role === 'admin') {
  ?>
  <div class="sidebar d-flex flex-column">
    <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
      <img src="<?= u('img/logo.png') ?>" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
    </div>

    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('dashboard') ?>" href="<?= u('all-modules-admin-access/Dashboard.php') ?>">
        <ion-icon name="home-outline"></ion-icon><span>Dashboard</span>
      </a>
    </nav>

    <h6 class="text-uppercase mb-2">Smart Warehousing</h6>
    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('inventory') ?>" href="<?= u('warehousing/inventory/inventoryTracking.php') ?>">
        <ion-icon name="cube-outline"></ion-icon><span>Track Inventory</span>
      </a>
      <a class="nav-link<?= a('stock') ?>" href="<?= u('warehousing/stockmanagement/stockLevelManagement.php') ?>">
        <ion-icon name="layers-outline"></ion-icon><span>Stock Management</span>
      </a>
      <a class="nav-link<?= a('shipments') ?>" href="<?= u('warehousing/TrackShipment/shipmentTracking.php') ?>">
        <ion-icon name="paper-plane-outline"></ion-icon><span>Track Shipments</span>
      </a>
    </nav>

    <h6 class="text-uppercase mb-2">Procurement and Sourcing Management</h6>
    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('suppliers') ?>" href="<?= u('procurement/supplierManagement.php') ?>">
        <ion-icon name="person-outline"></ion-icon><span>Supplier Management</span>
      </a>
      <a class="nav-link<?= a('rfqs') ?>" href="<?= u('procurement/rfqManagement.php') ?>">
        <ion-icon name="mail-open-outline"></ion-icon><span>RFQs & Sourcing</span>
      </a>
      <a class="nav-link<?= a('pos') ?>" href="<?= u('procurement/purchaseOrders.php') ?>">
        <ion-icon name="document-text-outline"></ion-icon><span>Purchase Orders</span>
      </a>
      <a class="nav-link<?= a('reqs') ?>" href="<?= u('procurement/procurementRequests.php') ?>">
        <ion-icon name="clipboard-outline"></ion-icon><span>Procurement Requests</span>
      </a>
      <a class="nav-link<?= a('budgets') ?>" href="<?= u('procurement/budgets.php') ?>">
        <ion-icon name="wallet-outline"></ion-icon><span>Budgets</span>
      </a>
    </nav>

    <h6 class="text-uppercase mb-2">PLT</h6>
    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('projects') ?>" href="<?= u('PLT/projectTracking.php') ?>">
        <ion-icon name="briefcase-outline"></ion-icon><span>Project Tracking</span>
      </a>
      <a class="nav-link<?= a('tracker') ?>" href="<?= u('PLT/shipmentTracker.php') ?>">
        <ion-icon name="trail-sign-outline"></ion-icon><span>Shipment Tracker</span>
      </a>
      <a class="nav-link<?= a('delivery') ?>" href="<?= u('PLT/deliverySchedule.php') ?>">
        <ion-icon name="calendar-outline"></ion-icon><span>Delivery Schedule</span>
      </a>
    </nav>

    <h6 class="text-uppercase mb-2">ALMS</h6>
    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('assettracker') ?>" href="<?= u('assetlifecycle/assetTracker.php') ?>">
        <ion-icon name="cube-outline"></ion-icon><span>Asset Tracking</span>
      </a>
      <a class="nav-link<?= a('requests') ?>" href="<?= u('assetlifecycle/mainReq.php') ?>">
        <ion-icon name="layers-outline"></ion-icon><span>Maintenance Requests</span>
      </a>
      <a class="nav-link<?= a('repair') ?>" href="<?= u('assetlifecycle/repair.php') ?>">
        <ion-icon name="hammer-outline"></ion-icon><span>Repair Logs</span>
      </a>
    </nav>

    <h6 class="text-uppercase mb-2">Document Tracking</h6>
    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('documents') ?>" href="<?= u('documentTracking/document.php') ?>">
        <ion-icon name="document-text-outline"></ion-icon><span>Documents</span>
      </a>
      <a class="nav-link<?= a('logistics') ?>" href="<?= u('documentTracking/logistic.php') ?>">
        <ion-icon name="cube-outline"></ion-icon><span>Logistics</span>
      </a>
    </nav>

    <hr class="mx-3 my-2">

    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('reports') ?>" href="<?= u('reports/globalReports.php') ?>">
        <ion-icon name="bar-chart-outline"></ion-icon><span>Reports</span>
      </a>

      <?php
  $settingsChildren = ['settings_access','settings_locations','settings_categories','settings_departments'];
  $isSettingsOpen = a_any($settingsChildren) || $active === 'settings';
?>
<a
  class="nav-link d-flex align-items-center justify-content-between settings-parent<?= $isSettingsOpen ? ' active is-open' : '' ?>"
  data-bs-toggle="collapse"
  data-bs-target="#adminSettings"
  href="#"
  role="button"
  aria-expanded="<?= $isSettingsOpen ? 'true' : 'false' ?>"
  aria-controls="adminSettings"
>
  <span><ion-icon name="settings-outline"></ion-icon><span>Settings</span></span>
  <ion-icon name="<?= $isSettingsOpen ? 'chevron-down-outline' : 'chevron-forward-outline' ?>"></ion-icon>
</a>


<div class="collapse<?= $isSettingsOpen ? ' show' : '' ?>" id="adminSettings">
  <div class="nav flex-column mt-1"><!-- removed ms-3 to make children full width -->
    <a class="nav-link sub-link<?= a('settings_access') ?>" href="<?= u('all-modules-admin-access/accessControl.php') ?>">
      <ion-icon name="lock-closed-outline"></ion-icon><span>Access Control</span>
    </a>
    <a class="nav-link sub-link<?= a('settings_locations') ?>" href="<?= u('all-modules-admin-access/locations.php') ?>">
      <ion-icon name="location-outline"></ion-icon><span>Warehouse Locations</span>
    </a>
    <a class="nav-link sub-link<?= a('settings_categories') ?>" href="<?= u('all-modules-admin-access/categories.php') ?>">
      <ion-icon name="pricetags-outline"></ion-icon><span>Inventory Categories</span>
    </a>
    <a class="nav-link sub-link<?= a('settings_departments') ?>" href="<?= u('all-modules-admin-access/departments.php') ?>">
      <ion-icon name="business-outline"></ion-icon><span>Departments</span>
    </a>
  </div>
</div>
    </nav>

    <div class="logout-section mt-auto">
      <a class="nav-link text-danger d-flex align-items-center gap-2" href="<?= u('auth/logout.php') ?>">
        <ion-icon name="log-out-outline"></ion-icon><span>Logout</span>
      </a>
    </div>
  </div>
  <?php
  return;
}
?>

<?php if ($section === 'warehousing') { ?>
  <div class="sidebar d-flex flex-column">
    <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
      <img src="<?= u('img/logo.png') ?>" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
    </div>
    <h6 class="text-uppercase mb-2">Smart Warehousing</h6>
    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('dashboard') ?>" href="<?= u('warehousing/warehouseDashboard.php') ?>">
        <ion-icon name="home-outline"></ion-icon><span>Dashboard</span>
      </a>
      <a class="nav-link<?= a('inventory') ?>" href="<?= u('warehousing/inventory/inventoryTracking.php') ?>">
        <ion-icon name="cube-outline"></ion-icon><span>Track Inventory</span>
      </a>
      <a class="nav-link<?= a('stock') ?>" href="<?= u('warehousing/stockmanagement/stockLevelManagement.php') ?>">
        <ion-icon name="layers-outline"></ion-icon><span>Stock Management</span>
      </a>
      <a class="nav-link<?= a('shipments') ?>" href="<?= u('warehousing/TrackShipment/shipmentTracking.php') ?>">
        <ion-icon name="paper-plane-outline"></ion-icon><span>Track Shipments</span>
      </a>
      <a class="nav-link<?= a('reports') ?>" href="<?= u('warehousing/warehouseReports.php') ?>">
        <ion-icon name="file-tray-stacked-outline"></ion-icon><span>Reports</span>
      </a>
      <a class="nav-link<?= a('settings') ?>" href="<?= u('warehousing/warehouseSettings.php') ?>">
        <ion-icon name="settings-outline"></ion-icon><span>Settings</span>
      </a>
    </nav>
    <div class="logout-section">
      <a class="nav-link text-danger d-flex align-items-center gap-2" href="<?= u('auth/logout.php') ?>">
        <ion-icon name="log-out-outline"></ion-icon><span>Logout</span>
      </a>
    </div>
  </div>
<?php return; } ?>

<?php if ($section === 'procurement') { ?>
  <div class="sidebar d-flex flex-column">
    <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
      <img src="<?= u('img/logo.png') ?>" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
    </div>
    <h6 class="text-uppercase mb-2">Procurement</h6>
    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('dashboard') ?>" href="<?= u('procurement/procurementDashboard.php') ?>">
        <ion-icon name="home-outline"></ion-icon><span>Dashboard</span>
      </a>
      <a class="nav-link<?= a('suppliers') ?>" href="<?= u('procurement/supplierManagement.php') ?>">
        <ion-icon name="person-outline"></ion-icon><span>Supplier Management</span>
      </a>
      <a class="nav-link<?= a('rfqs') ?>" href="<?= u('procurement/rfqManagement.php') ?>">
        <ion-icon name="mail-open-outline"></ion-icon><span>RFQs & Sourcing</span>
      </a>
      <a class="nav-link<?= a('pos') ?>" href="<?= u('procurement/purchaseOrders.php') ?>">
        <ion-icon name="document-text-outline"></ion-icon><span>Purchase Orders</span>
      </a>
      <a class="nav-link<?= a('reqs') ?>" href="<?= u('procurement/procurementRequests.php') ?>">
        <ion-icon name="clipboard-outline"></ion-icon><span>Procurement Requests</span>
      </a>
      <a class="nav-link<?= a('inventoryview') ?>" href="<?= u('procurement/inventoryView.php') ?>">
        <ion-icon name="archive-outline"></ion-icon><span>Inventory Management</span>
      </a>
      <a class="nav-link<?= a('budget') ?>" href="<?= u('procurement/budgetReports.php') ?>">
        <ion-icon name="analytics-outline"></ion-icon><span>Budget & Reports</span>
      </a>
      <a class="nav-link<?= a('settings') ?>" href="<?= u('procurement/settings.php') ?>">
        <ion-icon name="settings-outline"></ion-icon><span>Settings</span>
      </a>
    </nav>
    <div class="logout-section">
      <a class="nav-link text-danger d-flex align-items-center gap-2" href="<?= u('auth/logout.php') ?>">
        <ion-icon name="log-out-outline"></ion-icon><span>Logout</span>
      </a>
    </div>
  </div>
<?php return; } ?>

<?php if ($section === 'plt') { ?>
  <div class="sidebar d-flex flex-column">
    <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
      <img src="<?= u('img/logo.png') ?>" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
    </div>
    <h6 class="text-uppercase mb-2">PLT</h6>
    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('dashboard') ?>" href="<?= u('PLT/pltDashboard.php') ?>">
        <ion-icon name="home-outline"></ion-icon><span>Dashboard</span>
      </a>
      <a class="nav-link<?= a('projects') ?>" href="<?= u('PLT/projectTracking.php') ?>">
        <ion-icon name="briefcase-outline"></ion-icon><span>Project Tracking</span>
      </a>
      <a class="nav-link<?= a('tracker') ?>" href="<?= u('PLT/shipmentTracker.php') ?>">
        <ion-icon name="trail-sign-outline"></ion-icon><span>Shipment Tracker</span>
      </a>
      <a class="nav-link<?= a('delivery') ?>" href="<?= u('PLT/deliverySchedule.php') ?>">
        <ion-icon name="calendar-outline"></ion-icon><span>Delivery Schedule</span>
      </a>
      <a class="nav-link<?= a('reports') ?>" href="<?= u('PLT/pltReports.php') ?>">
        <ion-icon name="file-tray-stacked-outline"></ion-icon><span>Reports</span>
      </a>
    </nav>
    <div class="logout-section">
      <a class="nav-link text-danger d-flex align-items-center gap-2" href="<?= u('auth/logout.php') ?>">
        <ion-icon name="log-out-outline"></ion-icon><span>Logout</span>
      </a>
    </div>
  </div>
<?php return; } ?>

<?php if ($section === 'alms') { ?>
  <div class="sidebar d-flex flex-column">
    <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
      <img src="<?= u('img/logo.png') ?>" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
    </div>
    <h6 class="text-uppercase mb-2">Asset Lifecycle & Maintenance</h6>
    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('dashboard') ?>" href="<?= u('assetlifecycle/ALMS.php') ?>">
        <ion-icon name="home-outline"></ion-icon><span>Dashboard</span>
      </a>
      <a class="nav-link<?= a('assettracker') ?>" href="<?= u('assetlifecycle/assetTracker.php') ?>">
        <ion-icon name="cube-outline"></ion-icon><span>Asset Tracking</span>
      </a>
      <a class="nav-link<?= a('requests') ?>" href="<?= u('assetlifecycle/mainReq.php') ?>">
        <ion-icon name="layers-outline"></ion-icon><span>Maintenance Requests</span>
      </a>
      <a class="nav-link<?= a('repair') ?>" href="<?= u('assetlifecycle/repair.php') ?>">
        <ion-icon name="hammer-outline"></ion-icon><span>Repair Logs</span>
      </a>
      <a class="nav-link<?= a('reports') ?>" href="<?= u('assetlifecycle/reports.php') ?>">
        <ion-icon name="file-tray-stacked-outline"></ion-icon><span>Reports</span>
      </a>
      <a class="nav-link<?= a('settings') ?>" href="<?= u('assetlifecycle/settings.php') ?>">
        <ion-icon name="settings-outline"></ion-icon><span>Settings</span>
      </a>
    </nav>
    <div class="logout-section">
      <a class="nav-link text-danger d-flex align-items-center gap-2" href="<?= u('auth/logout.php') ?>">
        <ion-icon name="log-out-outline"></ion-icon><span>Logout</span>
      </a>
    </div>
  </div>
<?php return; } ?>

<?php if ($section === 'docs') { ?>
  <div class="sidebar d-flex flex-column">
    <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
      <img src="<?= u('img/logo.png') ?>" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
    </div>
    <h6 class="text-uppercase mb-2">Document Tracking</h6>
    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('dashboard') ?>" href="<?= u('documentTracking/Dashboard.php') ?>">
        <ion-icon name="home-outline"></ion-icon><span>Dashboard</span>
      </a>
      <a class="nav-link<?= a('documents') ?>" href="<?= u('documentTracking/document.php') ?>">
        <ion-icon name="document-text-outline"></ion-icon><span>Documents</span>
      </a>
      <a class="nav-link<?= a('logistics') ?>" href="<?= u('documentTracking/logistic.php') ?>">
        <ion-icon name="cube-outline"></ion-icon><span>Logistics</span>
      </a>
      <a class="nav-link<?= a('settings') ?>" href="<?= u('documenttracking/settings.php') ?>">
        <ion-icon name="settings-outline"></ion-icon><span>Settings</span>
      </a>
    </nav>
    <div class="logout-section">
      <a class="nav-link text-danger d-flex align-items-center gap-2" href="<?= u('auth/logout.php') ?>">
        <ion-icon name="log-out-outline"></ion-icon><span>Logout</span>
      </a>
    </div>
  </div>
<?php return; } ?>

<div class="sidebar d-flex flex-column p-3">
  <div class="text-muted small">No sidebar configured.</div>
</div>
