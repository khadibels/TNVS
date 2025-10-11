<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

vendor_require_login();

$proc = db('proc');
$auth = db('auth');

// catch SQL issues early
$proc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$auth->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$authUser = $_SESSION['user'] ?? [];
$vendorId = (int)($authUser['vendor_id'] ?? 0);
if ($vendorId <= 0) { die('Invalid vendor session.'); }

// load vendor record
$st = $proc->prepare("SELECT * FROM vendors WHERE id = ? LIMIT 1");
$st->execute([$vendorId]);
$vendor = $st->fetch(PDO::FETCH_ASSOC);
if (!$vendor) { die('Vendor not found.'); }

$err = ""; $ok = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $categories = trim($_POST['categories'] ?? '');

    // base update set + params
    $set    = ['categories = :cats'];
    $params = [':cats' => $categories, ':id' => $vendorId];

    // whitelist for uploads: input name => column name
    $uploadFields = [
        'dti'     => 'dti_doc',
        'bir'     => 'bir_doc',
        'permit'  => 'permit_doc',
        'bank'    => 'bank_doc',
        'catalog' => 'catalog_doc'
    ];

    foreach ($uploadFields as $field => $col) {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) continue;

        $tmp  = $_FILES[$field]['tmp_name'];
        $orig = $_FILES[$field]['name'];
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

        if (!in_array($ext, ['pdf','png','jpg','jpeg'], true)) continue;

        $dir = __DIR__ . '/uploads';
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        $fname = sprintf('%s_vendor%d_%s.%s', $field, $vendorId, bin2hex(random_bytes(4)), $ext);
        $abs   = $dir . '/' . $fname;

        if (move_uploaded_file($tmp, $abs)) {
            @chmod($abs, 0644);
            $set[] = "$col = :$col";
            $params[":$col"] = $fname;
        }
    }

    // ---- status logic (ALWAYS lowercase) ----
    // current vendor status (normalize)
    $current = strtolower((string)($vendor['status'] ?? 'draft'));
    $newStatus = $current;

    if ($action === 'submit') {
        // submitting KYC -> pending review
        $newStatus = 'pending';
        $set[] = "status = :status";
        $params[':status'] = $newStatus;
    } else {
        // saving draft: only set draft if not already approved/rejected
        if (!in_array($current, ['approved', 'rejected'], true)) {
            $newStatus = 'draft';
            $set[] = "status = :status";
            $params[':status'] = $newStatus;
        }
    }

    // write vendor row
    $sql = "UPDATE vendors SET " . implode(', ', $set) . " WHERE id = :id";
    $u = $proc->prepare($sql);
    $u->execute($params);

    // sync auth.users.vendor_status (lowercase)
    $ua = $auth->prepare("UPDATE users SET vendor_status = :vs WHERE id = :id");
    $ua->execute([':vs' => $newStatus, ':id' => (int)($authUser['id'] ?? 0)]);
    $_SESSION['user']['vendor_status'] = $newStatus;

    $ok = ($action === 'submit')
        ? "Your profile has been submitted for review."
        : "Draft saved.";
}

// reload fresh vendor data for the form
$st = $proc->prepare("SELECT * FROM vendors WHERE id = ? LIMIT 1");
$st->execute([$vendorId]);
$vendor = $st->fetch(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Compliance / KYC | Vendor Portal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>


<body class="bg-light">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-8">
      <div class="card shadow-sm">
        <div class="card-body p-4">
          <h3 class="mb-3">Compliance / KYC</h3>

          


        <?php
        $status = strtolower($vendor['status'] ?? 'draft');
        if ($status !== 'draft') {
        echo '<div class="mb-3"><a class="btn btn-outline-primary btn-sm" href="compliance_view.php">View submitted info</a></div>';
        }
?>


          <?php if ($ok): ?>
            <div class="alert alert-success"><?= h($ok) ?></div>
          <?php elseif ($err): ?>
            <div class="alert alert-danger"><?= h($err) ?></div>
          <?php endif; ?>

          <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
              <label class="form-label">Product/Service Categories (comma-separated)</label>
              <input type="text" name="categories" class="form-control"
                     value="<?= h($vendor['categories'] ?? '') ?>">
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">DTI/SEC</label>
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
              </div>

              <div class="col-md-6">
                <label class="form-label">Bank Cert (optional)</label>
                <input type="file" name="bank" class="form-control">
                <?php if (!empty($vendor['bank_doc'])): ?>
                  <a href="uploads/<?= h($vendor['bank_doc']) ?>" target="_blank" class="small">View uploaded</a>
                <?php endif; ?>
              </div>

              <div class="col-md-12">
                <label class="form-label">Catalog (optional)</label>
                <input type="file" name="catalog" class="form-control">
                <?php if (!empty($vendor['catalog_doc'])): ?>
                  <a href="uploads/<?= h($vendor['catalog_doc']) ?>" target="_blank" class="small">View uploaded</a>
                <?php endif; ?>
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
