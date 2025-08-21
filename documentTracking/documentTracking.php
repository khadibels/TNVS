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

  <!-- MAIN -->
  <div class="nav flex-column mb-4">
    <h6 class="mb-2">Document Tracking</h6>
    <a class="nav-link active" href="docDashboard.php">
      <ion-icon name="home-outline"></ion-icon> Dashboard
    </a>


  
    
    <a class="nav-link" href="documents.php">
      <ion-icon name="documents-outline"></ion-icon> Document Management
    </a>
  

  <!-- LOGISTICS RECORDS -->
  <div class="nav flex-column mb-4">
    
    <a class="nav-link" href="logisticsRecords.php">
      <ion-icon name="file-tray-full-outline"></ion-icon> Logistics Records
    </a>
 

  <!-- REPORTS -->
  <div class="nav flex-column mb-4">
    
    <a class="nav-link" href="docReports.php">
      <ion-icon name="bar-chart-outline"></ion-icon> Reports
    </a>
  

 
    <a class="nav-link" href="docSettings.php">
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