<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_login();
require_role(['vendor_manager', 'admin']);  // lock this page to VM

$section = 'vp_manager';
$active  = 'listings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Vendor Portal — Manager Dashboard</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>css/style.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>css/modules.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
  <div class="d-flex">
    <?php include __DIR__ . "/../../includes/vp_sidebar.php"; ?>

    <main class="flex-grow-1">
      <div class="container-fluid p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h1 class="h4 mb-0">Manager Dashboard</h1>
          <span class="badge bg-secondary">Vendor Manager</span>
        </div>

        <div class="row g-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center gap-2">
                  <ion-icon name="mail-unread-outline"></ion-icon>
                  <div class="fw-semibold">New PRs</div>
                </div>
                <div class="display-6 mt-2">0</div>
                <a href="<?= BASE_URL ?>vendor_portal/manager/pr_inbox.php" class="stretched-link">View PR Inbox</a>
              </div>
            </div>
          </div>
          <!-- Add more summary cards as you wire data -->
        </div>
      </div>
    </main>
  </div>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</body>
</html>
