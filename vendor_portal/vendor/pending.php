<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

vendor_require_login();

$pdo = db('proc');
$vid = (int)($_SESSION['user']['vendor_id'] ?? 0);

$stmt = $pdo->prepare("SELECT company_name, email, status, review_note FROM vendors WHERE id = ? LIMIT 1");
$stmt->execute([$vid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    $_SESSION = [];
    header('Location: ' . rtrim(BASE_URL,'/') . '/login.php');
    exit;
}

$_SESSION['user']['vendor_status'] = strtolower($row['status'] ?? 'pending');


$status = strtolower($row['status'] ?? 'pending');
if ($status === 'approved') {
    header('Location: ' . BASE_URL . '/vendor_portal/vendor/dashboard.php');
    exit;
}

$company = $row['company_name'] ?: ($_SESSION['user']['company_name'] ?? '');
$email   = $row['email'] ?? $_SESSION['user']['email'];
$reason  = trim((string)($row['review_note'] ?? ''));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Vendor Status | TNVS Vendor Portal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>body{background:#faf9ff}.card{border-radius:16px}</style>
</head>
<body>
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-12 col-lg-7">
        <div class="card shadow-sm">
          <div class="card-body p-4 p-lg-5">
            <div class="d-flex align-items-center justify-content-between">
              <h3 class="mb-0"><?= h($company ?: $email) ?></h3>
              <?= $status==='rejected'
                    ? '<span class="badge bg-danger">Rejected</span>'
                    : '<span class="badge bg-warning text-dark">Pending</span>' ?>
            </div>

            <p class="text-muted mt-2 mb-4">
              <?= $status==='rejected'
                   ? 'Unfortunately, your vendor application was not approved.'
                   : 'Your vendor application is currently under review by our Procurement team.' ?>
              You’ll receive an email once there’s an update.
            </p>

            <ul class="list-group mb-3">
              <li class="list-group-item d-flex align-items-center"><span class="me-2">Registered Email:</span><strong><?= h($email) ?></strong></li>
              <li class="list-group-item d-flex align-items-center"><span class="me-2">Current Status:</span><strong class="text-capitalize"><?= h($status) ?></strong></li>
            </ul>

            <?php if ($status==='rejected' && $reason!==''): ?>
              <div class="alert alert-light border"><strong>Reason provided:</strong><br><?= nl2br(h($reason)) ?></div>
            <?php endif; ?>

            <div class="d-flex gap-2 mt-3">
              <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>auth/logout.php">Logout</a>
              <a class="btn btn-primary" href="<?= BASE_URL ?>vendor_portal/vendor/pending.php">Refresh Status</a>
            </div>
          </div>
        </div>

        <p class="small text-muted text-center mt-3 mb-0">Need help? Contact Procurement.</p>
      </div>
    </div>
  </div>
</body>
</html>
