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
  <title>Procurement System</title>
</head>
<body>
  <div class="container-fluid p-0">
    <div class="row g-0">
        
    <div class="sidebar">
  <!-- Logo -->
  <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
    <img src="../img/logo.png" class="img-fluid me-2" style="height:55px;" alt="TNVS Logo">
  </div>

   
    <!-- Main Navigation -->
    <div class="nav flex-column mb-4">
      <h6 class="text-uppercase mb-2">Asset Lifecycle & Maintenance</h6>
      <nav class="nav flex-column">
        <a class="nav-link active" href="almsDashboard.php"><ion-icon name="home-outline"></ion-icon> Dashboard</a>
        <a class="nav-link" href="assetTracking.php"><ion-icon name="file-tray-full-outline"></ion-icon> Asset Tracking</a>
        <a class="nav-link" href="maintenanceRequests.php"><ion-icon name="build-outline"></ion-icon> Maintenance Requests</a>
        <a class="nav-link" href="repairLogs.php"><ion-icon name="hammer-outline"></ion-icon> Repair Logs</a>
        <a class="nav-link" href="assetReports.php"><ion-icon name="bar-chart-outline"></ion-icon> Asset Reports</a>
        <a class="nav-link" href="settings.php"><ion-icon name="settings-outline"></ion-icon> Settings</a>
      </nav>
    </div>

    <!-- Logout Section -->
    <div class="p-3 border-top mb-2">
      <a class="nav-link text-danger" href="/login.php">
        <ion-icon name="log-out-outline"></ion-icon> Logout
      </a>
    </div>
  </div>
</div>
