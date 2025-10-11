<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

require_login();
require_role(['admin','vendor_manager']);

$pdo = db('proc');
$id  = max(0, (int)($_GET['id'] ?? 0));

$st = $pdo->prepare("SELECT id, company_name, contact_person, email, phone, address,
                            status, categories, dti_doc, bir_doc, permit_doc, bank_doc, catalog_doc,
                            review_note, reviewed_at, created_at, profile_photo
                     FROM vendors WHERE id = ? LIMIT 1");
$st->execute([$id]);
$v = $st->fetch(PDO::FETCH_ASSOC);
if (!$v) { http_response_code(404); die('Vendor not found'); }

function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function fileLink($name){
  if (!$name) return '<span class="text-muted">Not uploaded</span>';
  $safe = h(basename($name));
  return '<a target="_blank" href="../vendor/uploads/'.$safe.'">View file</a>';
}

$section = 'vendor_manager';
$active  = 'vm_suppliers';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Vendor Profile | <?= h($v['company_name'] ?: $v['email']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid p-0">
  <div class="row g-0">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="col p-4">
      <h3 class="mb-3">Vendor Profile</h3>

      <div class="mb-3">
        <span class="badge <?= $v['status']==='approved'?'bg-success':($v['status']==='rejected'?'bg-danger':($v['status']==='pending'?'bg-warning text-dark':'bg-secondary')) ?>">
          <?= h(ucfirst($v['status'])) ?>
        </span>
      </div>

      <div class="row g-3">
        <div class="col-md-6">
          <div class="card"><div class="card-body">
            <h5 class="card-title mb-3"><?= h($v['company_name'] ?: '—') ?></h5>
            <div class="mb-2"><strong>Contact:</strong> <?= h($v['contact_person'] ?: '—') ?></div>
            <div class="mb-2"><strong>Email:</strong> <?= h($v['email'] ?: '—') ?></div>
            <div class="mb-2"><strong>Phone:</strong> <?= h($v['phone'] ?: '—') ?></div>
            <div class="mb-2"><strong>Address:</strong><br><?= nl2br(h($v['address'] ?: '—')) ?></div>
            <div class="mb-2"><strong>Categories:</strong><br><?= nl2br(h($v['categories'] ?: '—')) ?></div>
            <div class="text-muted small mt-3">Created: <?= h($v['created_at'] ?: '—') ?></div>
            <?php if (!empty($v['review_note'])): ?>
              <div class="alert alert-light border mt-3">
                <strong>Reviewer note:</strong><br><?= nl2br(h($v['review_note'])) ?><br>
                <span class="small text-muted">Reviewed at: <?= h($v['reviewed_at'] ?: '—') ?></span>
              </div>
            <?php endif; ?>
          </div></div>
        </div>

        <div class="col-md-6">
          <div class="card"><div class="card-body">
            <h5 class="card-title mb-3">Documents</h5>
            <ul class="list-group">
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <span>DTI/SEC</span><span><?= fileLink($v['dti_doc'] ?? null) ?></span>
              </li>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <span>BIR / TIN Cert</span><span><?= fileLink($v['bir_doc'] ?? null) ?></span>
              </li>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <span>Business Permit</span><span><?= fileLink($v['permit_doc'] ?? null) ?></span>
              </li>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <span>Bank Certificate</span><span><?= fileLink($v['bank_doc'] ?? null) ?></span>
              </li>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <span>Catalog</span><span><?= fileLink($v['catalog_doc'] ?? null) ?></span>
              </li>
            </ul>
          </div></div>
        </div>
      </div>

      <a href="./supplierManagement.php" class="btn btn-outline-secondary mt-3">Back</a>
    </div>
  </div>
</div>
</body>
</html>
