<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="../css/style.css" rel="stylesheet">
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <script src="../js/sidebar-toggle.js"></script>
  
  <title>Procurement System</title>
</head>
<body>
  <div class="container-fluid p-0">
    <div class="row g-0">
        
   <!-- Sidebar Column for Smart Warehousing -->
<div class="sidebar">
  <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
    <img src="../img/logo.png" class="img-fluid me-2" style="height: 55px;" alt="Logo">
  </div>
 
<!-- Sidebar: Project Logistics Tracker -->
<div class="sidebar">
  <!-- Logo -->
  <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
    <img src="../img/logo.png" class="img-fluid me-2" style="height:55px;" alt="TNVS Logo">
  </div>

  <!-- MAIN -->
  <div class="nav flex-column mb-4">
    <h6 class="mb-2">Project Logistics Tracker</h6>
    <a class="nav-link active" href="projectDashboard.php">
      <ion-icon name="home-outline"></ion-icon> Dashboard
    </a>
  </div>

  <!-- SHIPMENTS -->
  <div class="nav flex-column mb-4">
    <h6 class="mb-2">Shipments</h6>
    <a class="nav-link" href="shipmentTracker.php">
      <ion-icon name="cube-outline"></ion-icon> Shipment Tracker
    </a>
  </div>

  <!-- PROJECTS -->
  <div class="nav flex-column mb-4">
    <h6 class="mb-2">Projects</h6>
    <a class="nav-link" href="projectTracking.php">
      <ion-icon name="clipboard-outline"></ion-icon> Project Tracking
    </a>
    <a class="nav-link" href="deliverySchedule.php">
      <ion-icon name="calendar-outline"></ion-icon> Delivery Schedule
    </a>
  </div>

  <!-- REPORTS -->
  <div class="nav flex-column mb-4">
    <h6 class="mb-2">Reports</h6>
    <a class="nav-link" href="projectReports.php">
      <ion-icon name="bar-chart-outline"></ion-icon> Reports
    </a>
  </div>

  <!-- SETTINGS -->
  <div class="nav flex-column mb-4">
    <h6 class="mb-2">Settings</h6>
    <a class="nav-link" href="projectSettings.php">
      <ion-icon name="settings-outline"></ion-icon>Settings
    </a>
  </div>

  <!-- LOGOUT -->
  <div class="logout-section">
    <a class="nav-link text-danger" href="../login.php">
      <ion-icon name="log-out-outline"></ion-icon> Logout
    </a>
  </div>
</div>
