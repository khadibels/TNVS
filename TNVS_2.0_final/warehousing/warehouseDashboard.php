
<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login(); ?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>SW Dashboard | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../css/style.css" rel="stylesheet" />
  <link href="../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>

 
</head>
<body>
  <div class="container-fluid p-0">
    <div class="row g-0">

      <!-- Sidebar -->
      <div class="sidebar d-flex flex-column">
        <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
          <img src="../img/logo.png" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
        </div>

        <h6 class="text-uppercase mb-2">Smart Warehousing</h6>

        <nav class="nav flex-column px-2 mb-4">
          <a class="nav-link active" href="warehouseDashboard.php"><ion-icon name="home-outline"></ion-icon><span>Dashboard</span></a>
          <a class="nav-link" href="inventory/inventoryTracking.php"><ion-icon name="cube-outline"></ion-icon><span>Track Inventory</span></a>
          <a class="nav-link" href="stockmanagement/stockLevelManagement.php"><ion-icon name="layers-outline"></ion-icon><span>Stock Management</span></a>
          <a class="nav-link" href="shipmentTracking.php"><ion-icon name="paper-plane-outline"></ion-icon><span>Track Shipments</span></a>
          <a class="nav-link" href="warehouseReports.php"><ion-icon name="file-tray-stacked-outline"></ion-icon><span>Reports</span></a>
          <a class="nav-link" href="warehouseSettings.php"><ion-icon name="settings-outline"></ion-icon><span>Settings</span></a>
        </nav>

         <!-- LOGOUT -->
  <div class="logout-section">
    <a class="nav-link text-danger" href="<?= BASE_URL ?>/auth/logout.php">
      <ion-icon name="log-out-outline"></ion-icon> Logout
    </a>
  </div>
</div>

      <!-- Main Content -->
      <div class="col main-content p-3 p-lg-4">

        <!-- Topbar -->
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
              <ion-icon name="menu-outline"></ion-icon>
            </button>
            <h2 class="m-0">Dashboard</h2>
            <p>heelloo</p>
          </div>

          <div class="d-flex align-items-center gap-2">
            <img src="../img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
            <div class="small">
              <strong>Nicole Malitao</strong><br/>
              <span class="text-muted">Warehouse Manager</span>
            </div>
          </div>
        </div>