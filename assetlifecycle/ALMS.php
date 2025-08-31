<?php
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/auth.php";
require_login();

// Use your shared user system
$user = current_user();
$userName = $user['name'] ?? 'User';
$userRole = $user['role'] ?? 'User';

// Fetch stats
$totalAssets = (int) $pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
$activeAssets = (int) $pdo->query("SELECT COUNT(*) FROM assets WHERE status='Active'")->fetchColumn();
$maintenanceAssets = (int) $pdo->query("SELECT COUNT(*) FROM assets WHERE status='In Maintenance'")->fetchColumn();
$retiredAssets = (int) $pdo->query("SELECT COUNT(*) FROM assets WHERE status='Retired'")->fetchColumn();

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
  <link href="../css/modules.css" rel="stylesheet">
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  
</head>
<body>
  <div class="container-fluid p-0">
    <div class="row g-0">
     <!-- Sidebar -->
      <div class="sidebar d-flex flex-column">
        <div class="d-flex justify-content-center align-items-center mb-4 mt-3">
          <img src="../img/logo.png" id="logo" class="img-fluid me-2" style="height:55px" alt="Logo">
        </div>

        <h6 class="text-uppercase mb-2">Asset Lifecycle & Maintenance</h6>

        <nav class="nav flex-column px-2 mb-4">
          <a class="nav-link active" href="Dashboard.php">
            <ion-icon name="home-outline"></ion-icon><span>Dashboard</span>
          </a>

          <a class="nav-link" href="./assetTracker.php">
            <ion-icon name="cube-outline"></ion-icon><span>Asset Tracking</span>
          </a>

          <a class="nav-link" href="./mainReq.php">
            <ion-icon name="layers-outline"></ion-icon><span>Maintenance Requests</span>
          </a>

          <a class="nav-link" href="./repair.php">
            <ion-icon name="hammer-outline"></ion-icon><span>Repair Logs</span>
          </a>

          <a class="nav-link" href="./ass2.php">
            <ion-icon name="file-tray-stacked-outline"></ion-icon><span>Reports</span>
          </a>

          <a class="nav-link" href="./settings.php">
            <ion-icon name="settings-outline"></ion-icon><span>Settings</span>
          </a>
        </nav>

        <div class="logout-section">
          <a class="nav-link text-danger" href="<?= BASE_URL ?>/auth/logout.php">
            <ion-icon name="log-out-outline"></ion-icon> Logout
          </a>
        </div>
      </div>

        <div class="p-3 border-top mb-2">
          <a class="nav-link text-danger" href="/login.php">
            <ion-icon name="log-out-outline"></ion-icon> Logout
          </a>
        </div>
      </div>
      <!-- Main Content -->
      <div class="main-content col">
        <div class="topbar mb-4">
          <button class="sidebar-toggle d-md-none btn btn-light" onclick="toggleSidebar()" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <div class="profile">
            <img src="../img/profile.jpg" class="profile-img" alt="Profile">
            <div class="profile-info">
            <strong><?= htmlspecialchars($userName) ?></strong>
            <small><?= htmlspecialchars($userRole) ?></small>
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
            <a href="assetTracker.php" class="btn btn-sm btn-primary mt-2">View All Assets</a>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script>
    function toggleSidebar() {
      document.getElementById('sidebar').classList.toggle('show');
    }
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
    });
  </script>
</body>
</html>