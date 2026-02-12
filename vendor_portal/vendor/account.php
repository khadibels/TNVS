<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_login();
require_role(['vendor']);

$proc = db('proc');
if (!$proc instanceof PDO) { http_response_code(500); die('DB connection error'); }
$auth = db('auth');

$user = current_user();
$vendorId = (int)($user['vendor_id'] ?? 0);
if ($vendorId <= 0) { http_response_code(403); die('No vendor profile'); }

$vendorName = $user['company_name'] ?? ($user['name'] ?? 'Vendor');
$BASE = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$section = 'vendor';
$active = 'account';

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

$msg = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $contact = trim((string)($_POST['contact_person'] ?? ''));
  $phone   = trim((string)($_POST['phone'] ?? ''));
  $address = trim((string)($_POST['address'] ?? ''));
  if ($contact === '') {
    $err = 'Contact person is required.';
  } else {
    try {
      $up = $proc->prepare("UPDATE vendors SET contact_person=?, phone=?, address=? WHERE id=?");
      $up->execute([$contact, $phone ?: null, $address ?: null, $vendorId]);
      if ($auth instanceof PDO) {
        $ua = $auth->prepare("UPDATE users SET name=? WHERE id=?");
        $ua->execute([$contact, (int)($user['id'] ?? 0)]);
      }
      $_SESSION['user']['name'] = $contact;
      $msg = 'Account details updated.';
    } catch (Throwable $e) {
      $err = 'Failed to update account details.';
    }
  }
}

$st = $proc->prepare("SELECT id, company_name, contact_person, email, phone, address, status, created_at, review_note, reviewed_at FROM vendors WHERE id=? LIMIT 1");
$st->execute([$vendorId]);
$v = $st->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>My Account | Vendor Portal</title>
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
            <ion-icon name="person-circle-outline"></ion-icon> My Account
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
      <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

      <div class="row g-3">
        <div class="col-lg-8">
          <section class="card shadow-sm">
            <div class="card-body">
              <h5 class="mb-3">Account Details</h5>
              <form method="post" class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Company Name</label>
                  <input class="form-control" value="<?= h($v['company_name'] ?? '') ?>" disabled>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Company Email</label>
                  <input class="form-control" value="<?= h($v['email'] ?? '') ?>" disabled>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Contact Person</label>
                  <input class="form-control" name="contact_person" value="<?= h($v['contact_person'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Phone</label>
                  <input class="form-control" name="phone" value="<?= h($v['phone'] ?? '') ?>">
                </div>
                <div class="col-12">
                  <label class="form-label">Address</label>
                  <textarea class="form-control" rows="3" name="address"><?= h($v['address'] ?? '') ?></textarea>
                </div>
                <div class="col-12 d-flex justify-content-end">
                  <button class="btn btn-primary" type="submit">
                    <ion-icon name="save-outline" class="me-1"></ion-icon> Save Details
                  </button>
                </div>
              </form>
            </div>
          </section>
        </div>
        <div class="col-lg-4">
          <section class="card shadow-sm mb-3">
            <div class="card-body">
              <h6 class="mb-3">Vendor Status</h6>
              <div class="small text-muted mb-1">Current Status</div>
              <div class="mb-2"><span class="badge bg-success-subtle text-success"><?= h(ucfirst((string)($v['status'] ?? 'pending'))) ?></span></div>
              <div class="small text-muted">Created</div>
              <div class="mb-2"><?= h((string)($v['created_at'] ?? '-')) ?></div>
              <div class="small text-muted">Reviewed At</div>
              <div><?= h((string)($v['reviewed_at'] ?? '-')) ?></div>
            </div>
          </section>
          <section class="card shadow-sm">
            <div class="card-body">
              <h6 class="mb-3">Review Note</h6>
              <div class="text-muted small"><?= nl2br(h((string)($v['review_note'] ?? 'No review note available.'))) ?></div>
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

