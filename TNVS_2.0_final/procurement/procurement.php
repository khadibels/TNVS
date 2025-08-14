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
</body>

<body>
  <div class="container-fluid p-0">
    <div class="row g-0">

       <!-- Sidebar Column for Procurement -->
<div class="sidebar">
  <div class="d-flex justify-content-center align-items-center mb-5 mt-3">
    <img src="../img/logo.png" class="img-fluid me-2" style="height: 55px;" alt="Logo">
  </div>

   <!-- Main Navigation -->
  <div class="mb-4">
    <h6 class="text-uppercase mb-2">Procurement</h6>
    <nav class="nav flex-column">
      <a class="nav-link active" href="dashboard.php"><ion-icon name="home-outline"></ion-icon> Dashboard</a>
      <a class="nav-link" href="supplierManagement.php"><ion-icon name="person-outline"></ion-icon> Supplier Management</a>
      <a class="nav-link" href="rfqManagement.php"><ion-icon name="mail-open-outline"></ion-icon> RFQs & Sourcing</a>
      <a class="nav-link" href="purchaseOrders.php"><ion-icon name="document-text-outline"></ion-icon> Purchase Orders</a>
      <a class="nav-link" href="procurementRequests.php"><ion-icon name="clipboard-outline"></ion-icon> Procurement Requests</a>
      <a class="nav-link" href="inventory.php"><ion-icon name="archive-outline"></ion-icon> Inventory Management</a>
      <a class="nav-link" href="budgetReports.php"><ion-icon name="analytics-outline"></ion-icon> Budget & Reports</a>
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


      <!-- Main Content Column -->
      <div class="col main-content">
        <div class="topbar mb-4">
          <div class="d-flex align-items-center gap-3">
            <!-- Sidebar Toggle Button for Mobile -->
<button class="sidebar-toggle d-lg-none" id="sidebarToggle2" aria-label="Toggle sidebar">
  <ion-icon name="menu-outline"></ion-icon>
</button>

            <nav class="nav">
              <a class="nav-link" href="#">Home</a>
              <a class="nav-link" href="#">Contact</a>
            </nav>
          </div>

          <div class="profile">
            <div style="position:relative;">
              <i class="bi bi-bell"></i>
              <span class="badge">2</span>
            </div>
            <img src="#" class="profile-img" alt="profile">
            <div class="profile-info">
              <strong>Admin</strong><br>
              <small>Admin</small>
            </div>
          </div>
        </div>

        <div class="dashboard-title">
          <h3>Procurement Dashboard</h3>
        </div>

        <!-- Procurement Stats Cards -->
        <div class="stats-cards mb-4">
          <div class="stats-card">
            <div class="icon">
              <ion-icon name="cart-outline"></ion-icon>
            </div>
            <strong>Total Purchases</strong>
            <h4>102</h4>
            <div class="label">+10% from last month</div>
          </div>

          <div class="stats-card">
            <div class="icon">
              <ion-icon name="document-text-outline"></ion-icon>
            </div>
            <strong>Contracts Pending</strong>
            <h4>24</h4>
            <div class="label">-5% from last month</div>
          </div>

          <div class="stats-card">
            <div class="icon">
              <ion-icon name="people-outline"></ion-icon>
            </div>
            <strong>Suppliers</strong>
            <h4>56</h4>
            <div class="label">+3% from last month</div>
          </div>
        </div>

        <!-- Procurement Recent Activity -->
        <div class="card shadow-sm mb-4">
          <div class="card-body">
            <h6 class="mb-3">Recent Procurement Activities</h6>
            <ul class="list-group list-group-flush">
              <li class="list-group-item d-flex justify-content-between align-items-center">Contract signed with Vendor A <small class="text-muted">Today at 10:30 AM</small><span class="badge bg-success">Completed</span></li>
              <li class="list-group-item d-flex justify-content-between align-items-center">Purchase Order approved for Equipment <small class="text-muted">Yesterday at 2:15 PM</small><span class="badge bg-primary">New</span></li>
              <li class="list-group-item d-flex justify-content-between align-items-center">Request for Proposal (RFP) sent <small class="text-muted">Jul 15, 2023</small><span class="badge bg-warning">Pending</span></li>
            </ul>
          </div>
        </div>

        <!-- Procurement Analytics (Chart Section) -->
        <div class="row mb-4 g-3">
          <div class="col-12 col-lg-6">
            <div class="card shadow-sm">
              <div class="card-body">
                <h6>Procurement Overview</h6>
                <div class="chart-container">
                  <canvas id="barChart"></canvas>
                </div>
              </div>
            </div>
          </div>
          <div class="col-12 col-lg-6">
            <div class="card shadow-sm">
              <div class="card-body">
                <h6>Request Status</h6>
                <div class="chart-container">
                  <canvas id="pieChart"></canvas>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>

  <script src="js/script.js"></script>

  <script> 
  // Procurement Overview Bar Chart
const barCtx = document.getElementById('barChart').getContext('2d');
new Chart(barCtx, {
  type: 'bar',
  data: {
    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
    datasets: [
      { label: '2024', data: [14, 17, 26, 15, 20, 22], backgroundColor: '#36a2eb' },
      { label: '2023', data: [21, 23, 28, 20, 21, 19], backgroundColor: '#4bc0c0' }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    scales: { y: { beginAtZero: true } }
  }
});

// Request Status Pie Chart
const pieCtx = document.getElementById('pieChart').getContext('2d');
new Chart(pieCtx, {
  type: 'pie',
  data: {
    labels: ['Approved 65%', 'Rejected 10%', 'Pending 25%'],
    datasets: [{
      data: [65, 10, 25],
      backgroundColor: ['#28a745', '#dc3545', '#ffc107']
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    animation: { animateScale: true }
  }
});

</script>

</body>
</html>
