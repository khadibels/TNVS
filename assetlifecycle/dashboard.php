<?php
session_start();
if (!isset($_SESSION['Account_type'])) { header('Location: ALMS.php'); exit; }

// Database connection
$DB_HOST = '127.0.0.1';
$DB_NAME = 'alms_db';
$DB_USER = 'root';
$DB_PASS = '';
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    die("Database connection failed");
}

// Fetch stats from submodules
$totalAssets = $pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
$activeAssets = $pdo->query("SELECT COUNT(*) FROM assets WHERE status='Active'")->fetchColumn();
$maintenanceAssets = $pdo->query("SELECT COUNT(*) FROM assets WHERE status='In Maintenance'")->fetchColumn();
$retiredAssets = $pdo->query("SELECT COUNT(*) FROM assets WHERE status='Retired'")->fetchColumn();

$recentAssets = $pdo->query("SELECT name, status, asset_type, department FROM assets ORDER BY id DESC LIMIT 3")->fetchAll();

$recentRequests = $pdo->query("SELECT asset_name, type, status FROM maintenance_requests ORDER BY id DESC LIMIT 3")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Asset Lifecycle Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="../css/style.css" rel="stylesheet">
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    body {
      background: #f7f9fc;
      font-family: 'Montserrat', 'Inter', Arial, sans-serif;
      color: #22223b;
    }
    .sidebar {
      background: #181c2f;
      color: #fff;
      min-height: 100vh;
      width: 220px;
      position: fixed;
      left: 0;
      top: 0;
      z-index: 1040;
      padding: 2rem 1rem 1rem 1rem;
      box-shadow: 2px 0 12px rgba(30,40,90,0.07);
    }
    .sidebar .nav-link {
      color: #bfc7d1;
      border-radius: 8px;
      margin-bottom: 4px;
      font-size: 1rem;
      padding: 0.7rem 1rem;
      transition: background 0.2s, color 0.2s;
      display: flex;
      align-items: center;
      gap: 0.7rem;
    }
    .sidebar .nav-link.active, .sidebar .nav-link:hover {
      background: linear-gradient(90deg, #6d28d9 0%, #6d28d9 100%);
      color: #fff;
    }
    .sidebar .nav-link.text-danger {
      color: #ef4444;
      font-weight: 600;
    }
    .sidebar .nav-link ion-icon {
      font-size: 1.3rem;
    }
    .topbar {
      background: #fff;
      border-bottom: 1px solid #e5e7eb;
      padding: 1rem 2rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: 0 2px 8px rgba(30,40,90,0.03);
    }
    .profile {
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    .profile-img {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid #6d28d9;
    }
    .profile-info strong {
      font-size: 1rem;
      color: #22223b;
    }
    .profile-info small {
      font-size: 0.9rem;
      color: #6c757d;
    }
    .main-content {
      margin-left: 220px;
      padding: 2.5rem 2rem 2rem 2rem;
      min-height: 100vh;
      background: #f7f9fc;
    }
    .dashboard-title {
      font-size: 2rem;
      font-weight: 700;
      margin-bottom: 0.5rem;
      color: #6d28d9;
      letter-spacing: 1px;
    }
    .breadcrumbs {
      font-size: 0.95rem;
      color: #6c757d;
      margin-bottom: 2rem;
    }
    .stats-cards {
      display: flex;
      gap: 1.5rem;
      flex-wrap: wrap;
      margin-bottom: 2rem;
    }
    .stats-card {
      background: #fff;
      border-radius: 14px;
      box-shadow: 0 4px 16px rgba(30,40,90,0.07);
      padding: 1.5rem 2rem;
      flex: 1 1 180px;
      min-width: 180px;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 0.7rem;
    }
    .stats-card .icon {
      font-size: 2rem;
      color: #6d28d9;
      margin-bottom: 0.5rem;
    }
    .stats-card .label {
      font-size: 1rem;
      color: #6c757d;
    }
    .stats-card .value {
      font-size: 1.7rem;
      font-weight: 700;
      color: #22223b;
    }
    .dashboard-row {
      display: flex;
      gap: 2rem;
      flex-wrap: wrap;
      margin-bottom: 2rem;
    }
    .dashboard-col {
      background: #fff;
      border-radius: 14px;
      box-shadow: 0 4px 16px rgba(30,40,90,0.07);
      padding: 2rem 1.5rem;
      flex: 1 1 320px;
      min-width: 320px;
      margin-bottom: 1rem;
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }
    .dashboard-col h5 {
      font-size: 1.15rem;
      font-weight: 600;
      margin-bottom: 1rem;
      color: #6d28d9;
    }
    .table {
      background: #fff;
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(30,40,90,0.04);
    }
    .table th {
      background: #f3f6fa;
      color: #6d28d9;
      font-weight: 600;
      border: none;
      font-size: 1rem;
      padding: 0.8rem;
    }
    .table td {
      border: none;
      background: #fff;
      font-size: 0.98rem;
      padding: 0.7rem;
      color: #22223b;
    }
    .status-badge {
      padding: 3px 12px;
      border-radius: 12px;
      font-size: 0.85rem;
      font-weight: 600;
      display: inline-block;
    }
    .status-badge.online {
      background: #dbeafe;
      color: #6d28d9;
    }
    .status-badge.offline {
      background: #fee2e2;
      color: #b91c1c;
    }
    @media (max-width: 1200px) {
      .main-content { padding: 1.5rem 0.7rem 1.5rem 0.7rem; }
      .dashboard-row, .stats-cards { gap: 1rem; }
      .dashboard-col { min-width: 220px; padding: 1.2rem 0.7rem; }
    }
    @media (max-width: 900px) {
      .sidebar { left: -220px; transition: left 0.3s; }
      .sidebar.show { left: 0; }
      .main-content { margin-left: 0; }
      .sidebar-toggle { display: inline-block; }
      .sidebar-backdrop {display:block;}
    }
    @media (max-width: 700px) {
      .dashboard-title { font-size: 1.3rem; }
      .main-content { padding: 0.7rem 0.2rem; }
      .stats-card { min-width: 120px; padding: 1rem 0.7rem; }
      .dashboard-col { padding: 1rem 0.7rem; }
      .topbar { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
    }
    @media (max-width: 500px) {
      .sidebar { width: 100vw; left: -100vw; padding: 0.7rem 0.2rem; }
      .sidebar.show { left: 0; }
      .main-content { padding: 0.3rem 0.1rem; }
      .dashboard-row, .stats-cards { gap: 0.5rem; }
      .dashboard-col { min-width: 0; padding: 0.7rem 0.3rem; }
    }
    .sidebar-backdrop {
      display: none;
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.3);
      z-index: 1039;
    }
    .sidebar.show ~ .sidebar-backdrop {
      display:block;
    }
    .sidebar-close-btn {
      position:absolute;
      right:10px;
      top:10px;
      background:none;
      border:none;
      color:#fff;
      font-size:2rem;
      z-index:1050;
      display:none;
    }
    @media (max-width:900px) {
      .sidebar-close-btn {
        display:block;
      }
    }
  </style>
</head>
<body>
  <div class="container-fluid p-0">
    <div class="row g-0">
      <!-- Sidebar -->
      <div class="sidebar" id="sidebar" aria-label="Main Navigation">
        <button class="sidebar-close-btn" onclick="toggleSidebar()" aria-label="Close sidebar">&times;</button>
        <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
          <img src="logo.png" class="img-fluid me-2" style="height:55px;" alt="TNVS Logo">
        </div>
        <div class="nav flex-column mb-4">
          <h6 class="text-uppercase mb-2" style="color:#2563eb;">Asset Lifecycle & Maintenance</h6>
          <nav class="nav flex-column" role="navigation">
            <a class="nav-link active" href="dashboard.php"><ion-icon name="home-outline"></ion-icon> Dashboard</a>
            <a class="nav-link" href="ass1.php"><ion-icon name="file-tray-full-outline"></ion-icon> Asset Tracking</a>
            <a class="nav-link" href="mainReq.php"><ion-icon name="build-outline"></ion-icon> Maintenance Requests</a>
            <a class="nav-link" href="repair.php"><ion-icon name="hammer-outline"></ion-icon> Repair Logs</a>
            <a class="nav-link" href="ass2.php"><ion-icon name="bar-chart-outline"></ion-icon> Asset Reports</a>
            <a class="nav-link" href="settings.php"><ion-icon name="settings-outline"></ion-icon> Settings</a>
          </nav>
        </div>
        <div class="p-3 border-top mb-2">
          <a class="nav-link text-danger" href="/login.php">
            <ion-icon name="log-out-outline"></ion-icon> Logout
          </a>
        </div>
      </div>
      <div class="sidebar-backdrop" id="sidebar-backdrop" onclick="closeSidebar()"></div>
      <!-- Main Content -->
      <div class="main-content col">
        <div class="topbar mb-4">
          <button class="sidebar-toggle d-md-none btn btn-light" onclick="toggleSidebar()" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <div class="profile">
            <img src="logo.png" class="profile-img" alt="Profile">
            <div class="profile-info">
              <strong><?= htmlspecialchars($_SESSION['Email'] ?? 'User') ?></strong>
              <small><?= ($_SESSION['Account_type'] ?? '') == '1' ? 'Administrator' : 'User' ?></small>
            </div>
          </div>
        </div>
        <div class="dashboard-title mb-3">Asset Lifecycle Dashboard</div>
        <div class="breadcrumbs mb-3">Home / Dashboard</div>
        <!-- Stats Cards -->
        <div class="stats-cards mb-4">
          <div class="stats-card">
            <div class="icon"><ion-icon name="cube-outline"></ion-icon></div>
            <div class="label">Total Assets</div>
            <div class="value"><?= $totalAssets ?></div>
          </div>
          <div class="stats-card">
            <div class="icon"><ion-icon name="checkmark-done-outline"></ion-icon></div>
            <div class="label">Active</div>
            <div class="value"><?= $activeAssets ?></div>
          </div>
          <div class="stats-card">
            <div class="icon"><ion-icon name="build-outline"></ion-icon></div>
            <div class="label">In Maintenance</div>
            <div class="value"><?= $maintenanceAssets ?></div>
          </div>
          <div class="stats-card">
            <div class="icon"><ion-icon name="archive-outline"></ion-icon></div>
            <div class="label">Retired</div>
            <div class="value"><?= $retiredAssets ?></div>
          </div>
        </div>
        <!-- Chart Example & Maintenance -->
        <div class="dashboard-row mb-4">
          <div class="dashboard-col">
            <h5>Asset Status Overview</h5>
            <canvas id="assetChart" height="120"></canvas>
          </div>
          <div class="dashboard-col">
            <h5>Recent Maintenance Requests</h5>
            <ul class="mb-0">
              <?php foreach ($recentRequests as $req): ?>
                <li>
                  <?= htmlspecialchars($req['asset_name']) ?> - <?= htmlspecialchars($req['type']) ?>
                  <span class="status-badge <?= $req['status']=='Completed' ? 'online' : 'offline' ?>">
                    <?= htmlspecialchars($req['status']) ?>
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
            <a href="mainReq.php" class="btn btn-sm btn-primary mt-2">View All Requests</a>
          </div>
        </div>
        <!-- Recent Assets Table -->
        <div class="dashboard-col">
          <h5>Recent Assets</h5>
          <div class="table-responsive">
            <table class="table mb-0">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Status</th>
                  <th>Type</th>
                  <th>Department</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentAssets as $a): ?>
                  <tr>
                    <td><?= htmlspecialchars($a['name']) ?></td>
                    <td>
                      <span class="status-badge <?= $a['status']=='Active' ? 'online' : 'offline' ?>">
                        <?= htmlspecialchars($a['status']) ?>
                      </span>
                    </td>
                    <td><?= htmlspecialchars($a['asset_type']) ?></td>
                    <td><?= htmlspecialchars($a['department']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <a href="ass1.php" class="btn btn-sm btn-primary mt-2">View All Assets</a>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script>
    function toggleSidebar() {
      document.getElementById('sidebar').classList.toggle('show');
      document.getElementById('sidebar-backdrop').style.display = document.getElementById('sidebar').classList.contains('show') ? 'block' : 'none';
    }
    function closeSidebar() {
      document.getElementById('sidebar').classList.remove('show');
      document.getElementById('sidebar-backdrop').style.display = 'none';
    }
    // Close sidebar on pressing Escape key
    window.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') closeSidebar();
    });
    // Chart.js example
    window.addEventListener('DOMContentLoaded', function() {
      var ctx = document.getElementById('assetChart').getContext('2d');
      new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: ['Active', 'Maintenance', 'Retired'],
          datasets: [{
            data: [<?= $activeAssets ?>, <?= $maintenanceAssets ?>, <?= $retiredAssets ?>],
            backgroundColor: ['#2563eb', '#f59e0b', '#b91c1c'],
          }]
        },
        options: {
          plugins: { legend: { position: 'bottom' } },
          responsive: true,
        }
      });
      // Mobile fix: if screen resized larger, auto-close sidebar
      window.addEventListener('resize', function() {
        if (window.innerWidth > 900) closeSidebar();
      });
    });
  </script>
</body>
</html>