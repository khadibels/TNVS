<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

vendor_require_approved();
$me = vendor_session();

$section = 'vp_vendor';
$active  = 'dashboard';

$userName = $_SESSION['user']['name'] ?? 'Vendor';
$userRole = 'Supplier';

function vendor_avatar_url(): string {
    $base = rtrim(BASE_URL, '/');
    $id   = (int)($_SESSION['user']['vendor_id'] ?? 0);

    if ($id <= 0) {
        return $base . '/img/profile.jpg'; 
    }

    $root = realpath(__DIR__ . '/../../'); 
    $uploadDir = $root . "/vendor_portal/vendor/uploads";

    $patterns = [
        $uploadDir . "/vendor_{$id}_*.jpg",
        $uploadDir . "/vendor_{$id}_*.jpeg",
        $uploadDir . "/vendor_{$id}_*.png",
        $uploadDir . "/vendor_{$id}_*.webp",
    ];

    foreach ($patterns as $pattern) {
        $files = glob($pattern);
        if ($files && file_exists($files[0])) {
            $relPath = str_replace($root, '', $files[0]); 
            return $base . $relPath;
        }
    }

    return $base . '/img/profile.jpg';
}

$avatarUrl = vendor_avatar_url();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Vendor Portal — Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../../css/style.css" rel="stylesheet" />
  <link href="../../css/modules.css" rel="stylesheet" />

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../../js/sidebar-toggle.js"></script>
</head>
<body>
  <div class="container-fluid p-0">
    <div class="row g-0">

      <!-- Sidebar -->
      <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

      <!-- Main Content -->
      <div class="col main-content p-3 p-lg-4">

        <!-- Topbar -->
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2">
              <ion-icon name="menu-outline"></ion-icon>
            </button>
            <h2 class="m-0 d-flex align-items-center gap-2">
              <ion-icon name="home-outline"></ion-icon> Vendor Dashboard
            </h2>
          </div>
          <div class="d-flex align-items-center gap-2">
            <img src="<?= htmlspecialchars($avatarUrl) ?>" class="rounded-circle" width="36" height="36" alt="Profile">
            <div class="small">
              <strong><?= htmlspecialchars($userName) ?></strong><br>
              <span class="text-muted"><?= htmlspecialchars($userRole) ?></span>
            </div>
          </div>
        </div>

        <!-- Dashboard Cards -->
        <div class="row g-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center gap-2">
                  <ion-icon name="list-outline"></ion-icon>
                  <div class="fw-semibold">Open Listings</div>
                </div>
                <div class="display-6 mt-2">0</div>
                <a href="<?= BASE_URL ?>vendor_portal/vendor/market_listings.php" class="stretched-link">Browse</a>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center gap-2">
                  <ion-icon name="pricetag-outline"></ion-icon>
                  <div class="fw-semibold">My Active Bids</div>
                </div>
                <div class="display-6 mt-2">0</div>
                <a href="<?= BASE_URL ?>vendor_portal/vendor/my_bids.php" class="stretched-link">View</a>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center gap-2">
                  <ion-icon name="chatbubbles-outline"></ion-icon>
                  <div class="fw-semibold">New Messages</div>
                </div>
                <div class="display-6 mt-2">0</div>
                <a href="<?= BASE_URL ?>vendor_portal/vendor/messages.php" class="stretched-link">Check</a>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center gap-2">
                  <ion-icon name="shield-checkmark-outline"></ion-icon>
                  <div class="fw-semibold">Compliance</div>
                </div>
                <div class="display-6 mt-2">OK</div>
                <a href="<?= BASE_URL ?>vendor_portal/vendor/compliance.php" class="stretched-link">Update</a>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /main-content -->
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
