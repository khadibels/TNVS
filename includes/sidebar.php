<?php
// includes/sidebar.php

// Base URL helper (set BASE_URL in config; safe default = '')
if (!defined('BASE_URL')) { define('BASE_URL', ''); }
$BASE    = rtrim(BASE_URL, '/');
$role    = strtolower($_SESSION['user']['role'] ?? ''); // 'admin', 'manager', etc.
$active  = $active  ?? '';   // current page key to highlight
$section = $section ?? '';   // module for non-admin sidebars

// Build absolute URL from BASE_URL
function u($path){ global $BASE; return $BASE . '/' . ltrim($path, '/'); }
// Return " active" if matches current page key
function a($key){ global $active; return $active === $key ? ' active' : ''; }

/* =========================================================
 * ADMIN (full system sidebar)
 * =======================================================*/
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

    <!-- Smart Warehousing -->
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

    <!-- Procurement -->
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
    </nav>

    <!-- PLT -->
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

    <!-- ALMS -->
    <h6 class="text-uppercase mb-2">ALMS</h6>
    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('assets') ?>" href="<?= u('assetlifecycle/ALMS.php') ?>">
        <ion-icon name="cube-outline"></ion-icon><span>Asset Tracking</span>
      </a>
      <a class="nav-link<?= a('requests') ?>" href="<?= u('assetlifecycle/mainReq.php') ?>">
        <ion-icon name="layers-outline"></ion-icon><span>Maintenance Requests</span>
      </a>
      <a class="nav-link<?= a('repair') ?>" href="<?= u('assetlifecycle/repair.php') ?>">
        <ion-icon name="hammer-outline"></ion-icon><span>Repair Logs</span>
      </a>
    </nav>

    <!-- Document Tracking -->
    <h6 class="text-uppercase mb-2">Document Tracking</h6>
    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('documents') ?>" href="<?= u('all-modules-admin-access/document.php') ?>">
        <ion-icon name="document-text-outline"></ion-icon><span>Documents</span>
      </a>
      <a class="nav-link<?= a('logistics') ?>" href="<?= u('all-modules-admin-access/logistic.php') ?>">
        <ion-icon name="cube-outline"></ion-icon><span>Logistics</span>
      </a>
    </nav>

    <hr class="mx-3 my-2">

    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('budgets') ?>" href="<?= u('procurement/budgetReports.php') ?>">
        <ion-icon name="analytics-outline"></ion-icon><span>Budgets</span>
      </a>
      <a class="nav-link<?= a('reports') ?>" href="<?= u('reports/globalReports.php') ?>">
        <ion-icon name="bar-chart-outline"></ion-icon><span>Reports</span>
      </a>
      <a class="nav-link<?= a('settings') ?>" href="<?= u('settings.php') ?>">
        <ion-icon name="settings-outline"></ion-icon><span>Settings</span>
      </a>
    </nav>

    <div class="logout-section mt-auto">
      <a class="nav-link text-danger" href="<?= u('auth/logout.php') ?>">
        <ion-icon name="log-out-outline"></ion-icon> Logout
      </a>
    </div>
  </div>
  <?php
  return;
}

/* =========================================================
 * NON-ADMIN: render sidebar based on $section
 * =======================================================*/

/* -------- Warehousing -------- */
if ($section === 'warehousing') { ?>
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
      <a class="nav-link text-danger" href="<?= u('auth/logout.php') ?>">
        <ion-icon name="log-out-outline"></ion-icon> Logout
      </a>
    </div>
  </div>
<?php return; }

/* -------- Procurement -------- */
if ($section === 'procurement') { ?>
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
      <a class="nav-link<?= a('budget') ?>" href="<?= u('procurement/budgetReports.php') ?>">
        <ion-icon name="analytics-outline"></ion-icon><span>Budget & Reports</span>
      </a>
      <a class="nav-link<?= a('settings') ?>" href="<?= u('procurement/settings.php') ?>">
        <ion-icon name="settings-outline"></ion-icon><span>Settings</span>
      </a>
    </nav>
    <div class="logout-section">
      <a class="nav-link text-danger" href="<?= u('auth/logout.php') ?>">
        <ion-icon name="log-out-outline"></ion-icon> Logout
      </a>
    </div>
  </div>
<?php return; }

/* -------- PLT -------- */
if ($section === 'plt') { ?>
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
      <a class="nav-link text-danger" href="<?= u('auth/logout.php') ?>">
        <ion-icon name="log-out-outline"></ion-icon> Logout
      </a>
    </div>
  </div>
<?php return; }

/* -------- ALMS -------- */
if ($section === 'alms') { ?>
  <div class="sidebar d-flex flex-column">
    <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
      <img src="<?= u('img/logo.png') ?>" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
    </div>
    <h6 class="text-uppercase mb-2">Asset Lifecycle & Maintenance</h6>
    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('dashboard') ?>" href="<?= u('assetlifecycle/ALMS.php') ?>">
        <ion-icon name="home-outline"></ion-icon><span>Dashboard</span>
      </a>
      <a class="nav-link<?= a('assets') ?>" href="<?= u('assetlifecycle/assetTracker.php') ?>">
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
      <a class="nav-link text-danger" href="<?= u('auth/logout.php') ?>">
        <ion-icon name="log-out-outline"></ion-icon> Logout
      </a>
    </div>
  </div>
<?php return; }

/* -------- Document Tracking -------- */
if ($section === 'docs') { ?>
  <div class="sidebar d-flex flex-column">
    <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
      <img src="<?= u('img/logo.png') ?>" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
    </div>
    <h6 class="text-uppercase mb-2">Document Tracking</h6>
    <nav class="nav flex-column px-2 mb-4">
      <a class="nav-link<?= a('dashboard') ?>" href="<?= u('documenttracking/docDashboard.php') ?>">
        <ion-icon name="home-outline"></ion-icon><span>Dashboard</span>
      </a>
      <a class="nav-link<?= a('register') ?>" href="<?= u('documenttracking/documentRegister.php') ?>">
        <ion-icon name="document-text-outline"></ion-icon><span>Register Documents</span>
      </a>
      <a class="nav-link<?= a('logistics') ?>" href="<?= u('documenttracking/logistics.php') ?>">
        <ion-icon name="cube-outline"></ion-icon><span>Logistics</span>
      </a>
      <a class="nav-link<?= a('search') ?>" href="<?= u('documenttracking/search.php') ?>">
        <ion-icon name="search-outline"></ion-icon><span>Search</span>
      </a>
      <a class="nav-link<?= a('reports') ?>" href="<?= u('documenttracking/reports.php') ?>">
        <ion-icon name="file-tray-stacked-outline"></ion-icon><span>Reports</span>
      </a>
      <a class="nav-link<?= a('settings') ?>" href="<?= u('documenttracking/settings.php') ?>">
        <ion-icon name="settings-outline"></ion-icon><span>Settings</span>
      </a>
    </nav>
    <div class="logout-section">
      <a class="nav-link text-danger" href="<?= u('auth/logout.php') ?>">
        <ion-icon name="log-out-outline"></ion-icon> Logout
      </a>
    </div>
  </div>
<?php return; }

/* -------- Fallback (unknown section/role) -------- */
?>
<div class="sidebar d-flex flex-column p-3">
  <div class="text-muted small">No sidebar configured.</div>
</div>
