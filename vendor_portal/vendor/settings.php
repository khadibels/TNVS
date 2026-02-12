<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_login();
require_role(['vendor']);

$proc = db('proc');
if (!$proc instanceof PDO) { http_response_code(500); die('DB connection error'); }

$user = current_user();
$vendorId = (int)($user['vendor_id'] ?? 0);
if ($vendorId <= 0) { http_response_code(403); die('No vendor profile'); }

$vendorName = $user['company_name'] ?? ($user['name'] ?? 'Vendor');
$BASE = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$section = 'vendor';
$active = 'settings';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function vendor_avatar_url(): string {
  $base = rtrim(BASE_URL, '/');
  $id   = (int)($_SESSION['user']['vendor_id'] ?? 0);
  if ($id <= 0) return $base . '/img/profile.jpg';
  $root = realpath(__DIR__ . '/../../');
  $dir = $root . "/vendor_portal/vendor/uploads";
  foreach (['jpg','jpeg','png','webp'] as $ext) {
    $files = glob($dir . "/vendor_{$id}_*.{$ext}");
    if ($files && file_exists($files[0])) return $base . str_replace($root, '', $files[0]);
  }
  return $base . '/img/profile.jpg';
}

$proc->exec("
  CREATE TABLE IF NOT EXISTS vendor_preferences (
    vendor_id INT PRIMARY KEY,
    email_notifications TINYINT(1) NOT NULL DEFAULT 1,
    rfq_alerts TINYINT(1) NOT NULL DEFAULT 1,
    po_alerts TINYINT(1) NOT NULL DEFAULT 1,
    shipment_alerts TINYINT(1) NOT NULL DEFAULT 1,
    weekly_summary TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = isset($_POST['email_notifications']) ? 1 : 0;
  $rfq   = isset($_POST['rfq_alerts']) ? 1 : 0;
  $po    = isset($_POST['po_alerts']) ? 1 : 0;
  $ship  = isset($_POST['shipment_alerts']) ? 1 : 0;
  $week  = isset($_POST['weekly_summary']) ? 1 : 0;
  $up = $proc->prepare("
    INSERT INTO vendor_preferences (vendor_id, email_notifications, rfq_alerts, po_alerts, shipment_alerts, weekly_summary)
    VALUES (?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      email_notifications=VALUES(email_notifications),
      rfq_alerts=VALUES(rfq_alerts),
      po_alerts=VALUES(po_alerts),
      shipment_alerts=VALUES(shipment_alerts),
      weekly_summary=VALUES(weekly_summary)
  ");
  $up->execute([$vendorId, $email, $rfq, $po, $ship, $week]);
  $msg = 'Settings saved.';
}

$st = $proc->prepare("SELECT * FROM vendor_preferences WHERE vendor_id=? LIMIT 1");
$st->execute([$vendorId]);
$pref = $st->fetch(PDO::FETCH_ASSOC) ?: [
  'email_notifications' => 1,
  'rfq_alerts' => 1,
  'po_alerts' => 1,
  'shipment_alerts' => 1,
  'weekly_summary' => 0,
  'updated_at' => null,
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Settings | Vendor Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/style.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/modules.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/vendor_portal_saas.css" rel="stylesheet" />
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<script src="<?= $BASE ?>/js/sidebar-toggle.js"></script>
</head>
<body class="vendor-saas">
<div class="container-fluid p-0">
  <div class="row g-0">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="col main-content">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2" aria-label="Toggle sidebar">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0 d-flex align-items-center gap-2 page-title">
            <ion-icon name="settings-outline"></ion-icon> Settings
          </h2>
        </div>
        <div class="profile-menu" data-profile-menu>
          <button class="profile-trigger" type="button" data-profile-trigger>
            <img src="<?= h(vendor_avatar_url()) ?>" class="rounded-circle" width="36" height="36" alt="">
            <div class="profile-text">
              <div class="profile-name"><?= h($vendorName) ?></div>
              <div class="profile-role">vendor</div>
            </div>
            <ion-icon class="profile-caret" name="chevron-down-outline"></ion-icon>
          </button>
          <div class="profile-dropdown" data-profile-dropdown role="menu">
            <a href="<?= $BASE ?>/vendor_portal/vendor/notifications.php" role="menuitem">Notifications</a>
            <a href="<?= u('auth/logout.php') ?>" role="menuitem">Sign out</a>
          </div>
        </div>
      </div>

      <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>

      <div class="row g-3">
        <div class="col-lg-8">
          <section class="card shadow-sm">
            <div class="card-body">
              <h5 class="mb-3">Notification Preferences</h5>
              <form method="post">
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" id="email_notifications" name="email_notifications" <?= ((int)$pref['email_notifications']===1)?'checked':'' ?>>
                  <label class="form-check-label" for="email_notifications">Enable email notifications</label>
                </div>
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" id="rfq_alerts" name="rfq_alerts" <?= ((int)$pref['rfq_alerts']===1)?'checked':'' ?>>
                  <label class="form-check-label" for="rfq_alerts">RFQ invite alerts</label>
                </div>
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" id="po_alerts" name="po_alerts" <?= ((int)$pref['po_alerts']===1)?'checked':'' ?>>
                  <label class="form-check-label" for="po_alerts">Purchase order alerts</label>
                </div>
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" id="shipment_alerts" name="shipment_alerts" <?= ((int)$pref['shipment_alerts']===1)?'checked':'' ?>>
                  <label class="form-check-label" for="shipment_alerts">Shipment request alerts</label>
                </div>
                <div class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" id="weekly_summary" name="weekly_summary" <?= ((int)$pref['weekly_summary']===1)?'checked':'' ?>>
                  <label class="form-check-label" for="weekly_summary">Weekly summary email</label>
                </div>
                <button class="btn btn-primary" type="submit">
                  <ion-icon name="save-outline" class="me-1"></ion-icon> Save Settings
                </button>
              </form>
            </div>
          </section>
        </div>
        <div class="col-lg-4">
          <section class="card shadow-sm mb-3">
            <div class="card-body">
              <h6 class="mb-3">Account Settings Info</h6>
              <div class="small text-muted mb-1">Last Updated</div>
              <div class="mb-3"><?= h((string)($pref['updated_at'] ?? 'Not set')) ?></div>
              <div class="small text-muted">Tip: My Account contains your company/contact details.</div>
            </div>
          </section>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="<?= $BASE ?>/js/profile-dropdown.js"></script>
</body>
</html>

