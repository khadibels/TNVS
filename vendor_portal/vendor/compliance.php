<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/vendor_capability.php';

vendor_require_login();

$proc = db('proc');
$auth = db('auth');
$proc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$auth->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$authUser = $_SESSION['user'] ?? [];
$vendorId = (int)($authUser['vendor_id'] ?? 0);
if ($vendorId <= 0) { die('Invalid vendor session.'); }

ensure_vendor_capability_tables($proc);

$cats = [];
try {
    $wms = db('wms');
    if ($wms instanceof PDO) {
        $cats = $wms->query("SELECT name FROM inventory_categories ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable $e) { $cats = []; }

$st = $proc->prepare("SELECT * FROM vendors WHERE id = ? LIMIT 1");
$st->execute([$vendorId]);
$vendor = $st->fetch(PDO::FETCH_ASSOC);
if (!$vendor) { die('Vendor not found.'); }

$err = "";
$ok  = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? 'save';
    $categories = trim($_POST['categories'] ?? '');
    $catalog_category = trim($_POST['catalog_category'] ?? '');
    $receipt_category = trim($_POST['receipt_category'] ?? '');
    $website_link     = trim($_POST['website_link'] ?? '');

    $set    = ['categories = :cats'];
    $params = [':cats' => $categories, ':id' => $vendorId];

    $uploadFields = [
        'dti'     => 'dti_doc',
        'bir'     => 'bir_doc',
        'permit'  => 'permit_doc',
        'bank'    => 'bank_doc',
        'catalog' => 'catalog_doc',
        'receipt' => 'delivery_receipt_doc'
    ];

    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    foreach ($uploadFields as $field => $col) {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) continue;

        $tmp  = $_FILES[$field]['tmp_name'];
        $orig = $_FILES[$field]['name'];
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

        if (!in_array($ext, ['pdf','png','jpg','jpeg'], true)) continue;

        $fname = sprintf('%s_vendor%d_%s.%s', $field, $vendorId, bin2hex(random_bytes(4)), $ext);
        $abs   = $dir . '/' . $fname;

        if (move_uploaded_file($tmp, $abs)) {
            @chmod($abs, 0644);
            $set[] = "$col = :$col";
            $params[":$col"] = $fname;

            try {
                $docType = $field === 'receipt' ? 'delivery_receipt' : $field;
                $docCategory = null;
                if ($docType === 'catalog') $docCategory = $catalog_category ?: null;
                if ($docType === 'delivery_receipt') $docCategory = $receipt_category ?: null;
                if ($docType === 'permit') $docCategory = 'ALL';
                $ins = $proc->prepare("
                  INSERT INTO vendor_documents (vendor_id, doc_type, category, file_path, status)
                  VALUES (?,?,?,?, 'pending')
                ");
                $ins->execute([$vendorId, $docType, $docCategory, $fname]);
            } catch (Throwable $e) { }
        }
    }

    if ($website_link !== '') {
        try {
            $ins = $proc->prepare("
              INSERT INTO vendor_documents (vendor_id, doc_type, category, url, status)
              VALUES (?,?,?,?, 'pending')
            ");
            $ins->execute([$vendorId, 'website', null, $website_link]);
        } catch (Throwable $e) { }
    }

    $current   = strtolower((string)($vendor['status'] ?? 'draft'));
    $newStatus = $current;

    if ($action === 'submit') {
        $newStatus = 'pending';
        $set[] = "status = :status";
        $params[':status'] = $newStatus;
    } else {
        if (!in_array($current, ['approved', 'rejected'], true)) {
            $newStatus = 'draft';
            $set[] = "status = :status";
            $params[':status'] = $newStatus;
        }
    }

    $sql = "UPDATE vendors SET " . implode(', ', $set) . " WHERE id = :id";
    $u = $proc->prepare($sql);
    $u->execute($params);

    $ua = $auth->prepare("UPDATE users SET vendor_status = :vs WHERE id = :id");
    $ua->execute([':vs' => $newStatus, ':id' => (int)($authUser['id'] ?? 0)]);
    $_SESSION['user']['vendor_status'] = $newStatus;

    $ok = ($action === 'submit')
        ? "Your profile has been submitted for review."
        : "Draft saved.";

    $st = $proc->prepare("SELECT * FROM vendors WHERE id = ? LIMIT 1");
    $st->execute([$vendorId]);
    $vendor = $st->fetch(PDO::FETCH_ASSOC);
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$status = strtolower($vendor['status'] ?? 'draft');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Compliance / KYC | Vendor Portal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="<?= rtrim(BASE_URL, '/') ?>/css/style.css" rel="stylesheet" />
  <link href="<?= rtrim(BASE_URL, '/') ?>/css/modules.css" rel="stylesheet" />
  <link href="<?= rtrim(BASE_URL, '/') ?>/css/vendor_portal_saas.css" rel="stylesheet" />
</head>
<body class="vendor-saas">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-8">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0">Compliance / KYC</h3>
        <?php if ($status !== 'draft'): ?>
          <a class="btn btn-outline-primary btn-sm" href="compliance_view.php">
            View submitted info
          </a>
        <?php endif; ?>
      </div>

      <?php if ($ok): ?>
        <div class="alert alert-success"><?= h($ok) ?></div>
      <?php elseif ($err): ?>
        <div class="alert alert-danger"><?= h($err) ?></div>
      <?php endif; ?>

      <?php if ($status === 'approved'): ?>
        <div class="alert alert-success">
          Your vendor profile has been <strong>approved</strong>. You may still update some details, but major changes may trigger a new review.
        </div>
      <?php elseif ($status === 'pending'): ?>
        <div class="alert alert-warning">
          Your profile is currently <strong>pending review</strong>. Updating and resubmitting may restart the review.
        </div>
      <?php elseif ($status === 'rejected'): ?>
        <div class="alert alert-danger">
          Your profile was <strong>rejected</strong>. Please adjust your details and submit again.
        </div>
      <?php endif; ?>

      <div class="card shadow-sm">
        <div class="card-body p-4">
          <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
              <label class="form-label">Product / Service Categories (comma-separated)</label>
              <input type="text" name="categories" class="form-control"
                     value="<?= h($vendor['categories'] ?? '') ?>">
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">DTI / SEC</label>
                <input type="file" name="dti" class="form-control">
                <?php if (!empty($vendor['dti_doc'])): ?>
                  <a href="uploads/<?= h($vendor['dti_doc']) ?>" target="_blank" class="small">View uploaded</a>
                <?php endif; ?>
              </div>

              <div class="col-md-6">
                <label class="form-label">BIR / TIN Cert</label>
                <input type="file" name="bir" class="form-control">
                <?php if (!empty($vendor['bir_doc'])): ?>
                  <a href="uploads/<?= h($vendor['bir_doc']) ?>" target="_blank" class="small">View uploaded</a>
                <?php endif; ?>
              </div>

              <div class="col-md-6">
                <label class="form-label">Business Permit</label>
                <input type="file" name="permit" class="form-control">
                <?php if (!empty($vendor['permit_doc'])): ?>
                  <a href="uploads/<?= h($vendor['permit_doc']) ?>" target="_blank" class="small">View uploaded</a>
                <?php endif; ?>
                <div class="form-text">Applies to all categories.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Bank Cert (optional)</label>
                <input type="file" name="bank" class="form-control">
                <?php if (!empty($vendor['bank_doc'])): ?>
                  <a href="uploads/<?= h($vendor['bank_doc']) ?>" target="_blank" class="small">View uploaded</a>
                <?php endif; ?>
              </div>

              <div class="col-md-12">
                <label class="form-label">Catalog / Price List (optional)</label>
                <input type="file" name="catalog" class="form-control">
                <?php if (!empty($vendor['catalog_doc'])): ?>
                  <a href="uploads/<?= h($vendor['catalog_doc']) ?>" target="_blank" class="small">View uploaded</a>
                <?php endif; ?>
              </div>
              <div class="col-md-6">
                <label class="form-label">Catalog Category</label>
                <select name="catalog_category" class="form-select">
                  <option value="">Select category</option>
                  <?php foreach ($cats as $c): ?>
                    <option value="<?= h($c) ?>"><?= h($c) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Website / Online Store Link</label>
                <input type="url" name="website_link" class="form-control" placeholder="https://">
              </div>
              <div class="col-md-6">
                <label class="form-label">Delivery Receipt / Invoice (optional)</label>
                <input type="file" name="receipt" class="form-control">
              </div>
              <div class="col-md-6">
                <label class="form-label">Receipt Category</label>
                <select name="receipt_category" class="form-select">
                  <option value="">Select category</option>
                  <?php foreach ($cats as $c): ?>
                    <option value="<?= h($c) ?>"><?= h($c) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="d-flex gap-2 mt-4">
              <button type="submit" name="action" value="save" class="btn btn-outline-secondary">Save Draft</button>
              <button type="submit" name="action" value="submit" class="btn btn-primary">Submit for Review</button>
              <a href="gate.php" class="btn btn-light">Back</a>
            </div>
          </form>
        </div>
      </div>

    </div>
  </div>
</div>
</body>
</html>
