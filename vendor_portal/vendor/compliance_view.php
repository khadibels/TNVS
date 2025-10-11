<?php
// vendor_portal/vendor/compliance_view.php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

vendor_require_login();

$proc = db('proc');
if (!$proc instanceof PDO) { http_response_code(500); die('DB error'); }
$proc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// who is the vendor?
$authUser = $_SESSION['user'] ?? [];
$vendorId = (int)($authUser['vendor_id'] ?? 0);
if ($vendorId <= 0) { die('Invalid vendor session.'); }

// fetch vendor row
$st = $proc->prepare("SELECT * FROM vendors WHERE id = ? LIMIT 1");
$st->execute([$vendorId]);
$vendor = $st->fetch(PDO::FETCH_ASSOC);
if (!$vendor) { die('Vendor not found.'); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// web path to uploaded files (same folder your compliance.php saves into)
$base = rtrim(defined('BASE_URL') ? BASE_URL : '', '/');
$uploadWebPath = $base . '/vendor_portal/vendor/uploads/';

// little helpers
$status = strtolower($vendor['status'] ?? 'draft');
$badgeClass = [
  'approved' => 'success',
  'pending'  => 'warning',
  'rejected' => 'danger',
  'draft'    => 'secondary'
][$status] ?? 'secondary';

$files = [
  'DTI / SEC'        => $vendor['dti_doc']     ?? '',
  'BIR / TIN Cert'   => $vendor['bir_doc']     ?? '',
  'Business Permit'  => $vendor['permit_doc']  ?? '',
  'Bank Cert'        => $vendor['bank_doc']    ?? '',
  'Catalog'          => $vendor['catalog_doc'] ?? '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Compliance Summary | Vendor Portal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    body { background:#f6f7fb; }
    .card { border:1px solid #e9e7f2; border-radius:16px; }
    .label { width:200px; color:#6b6a76; }
    .file-pill { display:inline-flex; align-items:center; gap:.4rem; padding:.35rem .6rem; border:1px solid #e9e7f2; background:#fff; border-radius:999px; font-size:.85rem; }
    .file-pill ion-icon { font-size:1rem; }
  </style>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</head>
<body>
<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-lg-9">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h3 class="m-0">Compliance / KYC — Submitted Info</h3>
        <a href="compliance.php" class="btn btn-outline-secondary btn-sm">
          <ion-icon name="chevron-back-outline"></ion-icon> Back
        </a>
      </div>

      <?php if ($status === 'draft'): ?>
        <div class="alert alert-info">
          You haven’t submitted your compliance yet. Fill out the form on the previous page and click <strong>Submit for Review</strong>.
        </div>
      <?php endif; ?>

      <div class="card shadow-sm">
        <div class="card-body p-4">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
              <div class="h5 mb-0"><?= h($vendor['company_name'] ?? 'My Company') ?></div>
              <div class="text-muted small"><?= h($vendor['contact_person'] ?? '') ?></div>
            </div>
            <span class="badge text-bg-<?= $badgeClass ?> px-3 py-2 text-capitalize"><?= h($status) ?></span>
          </div>

          <?php if (!empty($vendor['review_reason']) && $status === 'rejected'): ?>
            <div class="alert alert-danger mb-4">
              <strong>Why it was rejected:</strong><br><?= nl2br(h($vendor['review_reason'])) ?>
            </div>
          <?php endif; ?>

          <div class="row g-4">
            <div class="col-md-6">
              <div class="mb-2 text-uppercase text-muted small">Basic Info</div>
              <dl class="row mb-0">
                <dt class="col-sm-4 label">Company</dt>
                <dd class="col-sm-8"><?= h($vendor['company_name'] ?? '') ?></dd>

                <dt class="col-sm-4 label">Contact Person</dt>
                <dd class="col-sm-8"><?= h($vendor['contact_person'] ?? '') ?></dd>

                <dt class="col-sm-4 label">Email</dt>
                <dd class="col-sm-8"><?= h($vendor['email'] ?? '') ?></dd>

                <dt class="col-sm-4 label">Phone</dt>
                <dd class="col-sm-8"><?= h($vendor['phone'] ?? '') ?></dd>

                <dt class="col-sm-4 label">Address</dt>
                <dd class="col-sm-8"><?= h($vendor['address'] ?? '') ?></dd>

                <dt class="col-sm-4 label">Categories</dt>
                <dd class="col-sm-8"><?= h($vendor['categories'] ?? '') ?></dd>
              </dl>
            </div>

            <div class="col-md-6">
              <div class="mb-2 text-uppercase text-muted small">Uploaded Files</div>
              <div class="d-flex flex-column gap-2">
                <?php foreach ($files as $label=>$fname): ?>
                  <div class="d-flex align-items-center gap-2">
                    <div class="label"><?= h($label) ?></div>
                    <div>
                      <?php if ($fname): ?>
                        <a class="file-pill" href="<?= $uploadWebPath . rawurlencode(basename($fname)) ?>" target="_blank" rel="noopener">
                          <ion-icon name="document-attach-outline"></ion-icon>
                          <span><?= h($fname) ?></span>
                        </a>
                      <?php else: ?>
                        <span class="text-muted">No file</span>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <hr class="my-4">

          <div class="row g-3">
            <div class="col-md-6">
              <div class="small text-muted">Submitted</div>
              <div><?= h($vendor['created_at'] ?? '—') ?></div>
            </div>
            <div class="col-md-6">
              <div class="small text-muted">Reviewed</div>
              <div><?= h($vendor['reviewed_at'] ?? '—') ?></div>
            </div>
          </div>

        </div>
      </div>

    </div>
  </div>
</div>
</body>
</html>
