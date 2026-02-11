<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/db.php";
require_once __DIR__ . "/../../includes/vendor_capability.php";
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$proc = db('proc');
$auth = db('auth');
$wms  = db('wms');

$proc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$auth->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

ensure_vendor_capability_tables($proc);

$catOptions = [];
try {
    if ($wms instanceof PDO) {
        $catOptions = $wms->query("SELECT name FROM inventory_categories ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable $e) { $catOptions = []; }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$err = "";
$ok  = "";
$vals = [
    'company_name'   => '',
    'contact_person' => '',
    'email'          => '',
    'phone'          => '',
    'address'        => '',
    'categories'     => ''
];

$requirementFields = [
    'req_legal' => 'Our business is legally registered (DTI / SEC / BIR).',
    'req_permit' => 'We have a valid Mayor’s / Business Permit.',
    'req_tax' => 'We are compliant with tax obligations and can issue OR/Invoice.',
    'req_bank' => 'We have an active bank account under the business name.',
    'req_policy' => 'We agree to follow the TNVS Vendor Code of Conduct.'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($vals as $k => $_) {
        $vals[$k] = trim($_POST[$k] ?? '');
    }

    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        $err = "Invalid form token.";
    } else {
        $company    = $vals['company_name'];
        $person     = $vals['contact_person'];
        $email      = $vals['email'];
        $phone      = $vals['phone'];
        $address    = $vals['address'];
        $categories = $vals['categories'];
        $pass       = $_POST['password'] ?? '';
        $action     = $_POST['action'] ?? 'save'; // save | submit

        $errors = [];

        if (!$company || !$person || !$email || !$pass) {
            $errors[] = "Please fill out all required fields.";
        }

        if ($action === 'submit') {
            foreach ($requirementFields as $key => $label) {
                if (empty($_POST[$key])) {
                    $errors[] = "Please confirm: " . $label;
                }
            }
            if (empty($_POST['terms'])) {
                $errors[] = "You must agree to the TNVS Vendor Contract & Privacy Policy before submitting for review.";
            }
            $hasCatalog = isset($_FILES['catalog']) && $_FILES['catalog']['error'] === UPLOAD_ERR_OK;
            $hasLink = trim((string)($_POST['website_link'] ?? '')) !== '';
            if (!$hasCatalog && !$hasLink) {
                $errors[] = "Please upload a product catalog or provide a website/online store link.";
            }
        }

        if ($errors) {
            $err = implode(' ', $errors);
        } else {
            try {
                $dup = $proc->prepare("SELECT 1 FROM vendors WHERE email=? LIMIT 1");
                $dup->execute([$email]);
                if ($dup->fetchColumn()) {
                    throw new RuntimeException("That email is already registered.");
                }

                $proc->beginTransaction();

                $hash   = password_hash($pass, PASSWORD_DEFAULT);
                $status = ($action === 'submit') ? 'pending' : 'draft';

                $ins = $proc->prepare("
                    INSERT INTO vendors
                      (company_name, contact_person, email, password, phone, address, categories, status)
                    VALUES
                      (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $company,
                    $person,
                    $email,
                    $hash,
                    $phone,
                    $address,
                    $categories,
                    $status
                ]);
                $vendorId = (int)$proc->lastInsertId();

                $filename = null;
                if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $tmp  = $_FILES['profile_photo']['tmp_name'];
                    $orig = $_FILES['profile_photo']['name'];
                    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                    if ($ext === 'jpeg') $ext = 'jpg';

                    if (in_array($ext, ['jpg', 'png', 'gif', 'webp'], true)) {
                        $baseDir = realpath(__DIR__ . '/../../');
                        $dir = $baseDir . '/vendor_portal/vendor/uploads';
                        if (!is_dir($dir)) mkdir($dir, 0775, true);

                        $filename = 'vendor_' . $vendorId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $abs = $dir . '/' . $filename;
                        if (move_uploaded_file($tmp, $abs)) {
                            @chmod($abs, 0644);
                        } else {
                            $filename = null;
                        }
                    }
                }
                if ($filename) {
                    $up = $proc->prepare("UPDATE vendors SET profile_photo=? WHERE id=?");
                    $up->execute([$filename, $vendorId]);
                }

                $baseDir = realpath(__DIR__ . '/../../');
                $docsDir = $baseDir . '/vendor_portal/vendor/uploads';
                if (!is_dir($docsDir)) mkdir($docsDir, 0775, true);

                $catalog_category = trim($_POST['catalog_category'] ?? '');
                $receipt_category = trim($_POST['receipt_category'] ?? '');
                $website_link     = trim($_POST['website_link'] ?? '');

                $uploadFields = [
                    'dti'     => 'dti_doc',
                    'bir'     => 'bir_doc',
                    'permit'  => 'permit_doc',
                    'bank'    => 'bank_doc',
                    'catalog' => 'catalog_doc',
                    'receipt' => 'delivery_receipt_doc'
                ];
                $allowedExtsByField = [
                    'dti'     => ['pdf', 'png', 'jpg', 'jpeg'],
                    'bir'     => ['pdf', 'png', 'jpg', 'jpeg'],
                    'permit'  => ['pdf', 'png', 'jpg', 'jpeg'],
                    'bank'    => ['pdf', 'png', 'jpg', 'jpeg'],
                    'catalog' => ['pdf', 'png', 'jpg', 'jpeg', 'xlsx', 'xls', 'csv'],
                    'receipt' => ['pdf', 'png', 'jpg', 'jpeg']
                ];

                $setParts = [];
                $params   = [':id' => $vendorId];

                foreach ($uploadFields as $field => $col) {
                    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                        continue;
                    }

                    $tmp  = $_FILES[$field]['tmp_name'];
                    $orig = $_FILES[$field]['name'];
                    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                    $allowedExts = $allowedExtsByField[$field] ?? ['pdf', 'png', 'jpg', 'jpeg'];
                    if (!in_array($ext, $allowedExts, true)) {
                        continue;
                    }

                    $fname = sprintf('%s_vendor%d_%s.%s', $field, $vendorId, bin2hex(random_bytes(4)), $ext);
                    $abs   = $docsDir . '/' . $fname;

                    if (move_uploaded_file($tmp, $abs)) {
                        @chmod($abs, 0644);
                        $setParts[]       = "$col = :$col";
                        $params[":$col"]  = $fname;

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

                if ($setParts) {
                    $sql = "UPDATE vendors SET " . implode(', ', $setParts) . " WHERE id = :id";
                    $u   = $proc->prepare($sql);
                    $u->execute($params);
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

                $proc->commit();

                $dup = $auth->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
                $dup->execute([$email]);
                if ($u = $dup->fetch(PDO::FETCH_ASSOC)) {
                    $upd = $auth->prepare("
                        UPDATE users
                        SET role='vendor', vendor_id=?, vendor_status=?
                        WHERE id=?
                    ");
                    $upd->execute([$vendorId, $status, $u['id']]);
                    $userId = (int)$u['id'];
                } else {
                    $insU = $auth->prepare("
                        INSERT INTO users (name, email, password_hash, role, vendor_id, vendor_status)
                        VALUES (?, ?, ?, 'vendor', ?, ?)
                    ");
                    $insU->execute([$person ?: $company, $email, $hash, $vendorId, $status]);
                    $userId = (int)$auth->lastInsertId();
                }

                $_SESSION['user'] = [
                    'id'            => $userId,
                    'email'         => $email,
                    'name'          => $person ?: $company,
                    'role'          => 'vendor',
                    'vendor_id'     => $vendorId,
                    'vendor_status' => $status
                ];

                require_once __DIR__ . "/../../includes/vendor_notifications.php";

                sendVendorPendingEmail([
                    'email'          => $email,
                    'contact_person' => $person,
                    'company_name'   => $company
                ]);


                header('Location: ' . rtrim(BASE_URL, '/') . '/vendor_portal/vendor/gate.php');
                exit;
            } catch (Throwable $e) {
                if ($proc->inTransaction()) $proc->rollBack();
                $err = "Registration failed: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Vendor Registration | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

  <link href="<?= BASE_URL ?>css/style.css" rel="stylesheet" />
  <link href="<?= BASE_URL ?>css/modules.css" rel="stylesheet" />

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

  <style>
  /* --- UI FIXES START: Refined existing custom styles --- */
  :root {
    --brand-primary: #6532C9;
    --brand-deep: #4311A5;
    --brand-accent: #7c3bffff;
    --brand-light: #f4f2ff;
    --text-dark: #2b2349;
    --text-muted: #6f6c80;
    --border-color: #e3dbff;
    --shadow-color: rgba(67, 17, 165, 0.08);
    --shadow-color-hover: rgba(67, 17, 165, 0.2);
  }
  body {
    font-family: 'Inter', sans-serif;
    color: var(--text-dark);
    background-color: #FBFBFF;
    background-image: radial-gradient(circle at top left, #f5f2ff, transparent 50%);
  }
  .auth-wrapper {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 2rem 1rem;
  }
  .auth-card {
    max-width: 1100px;
    width: 100%;
    border-radius: 24px;
    background: #fff;
    border: 1px solid rgba(101, 50, 201, 0.08);
    box-shadow: 0 8px 24px rgba(67, 17, 165, 0.08), 0 24px 60px rgba(67, 17, 165, 0.08);
    overflow: hidden;
  }
  .auth-card .bg-white {
    background: linear-gradient(180deg, #ffffff 0%, #fbfaff 100%);
  }
  .split-form {
    position: relative;
  }
  .split-form::after {
    content: "";
    position: absolute;
    top: 8px;
    bottom: 8px;
    left: 50%;
    width: 1px;
    background: linear-gradient(180deg, transparent, var(--border-color), transparent);
    transform: translateX(-50%);
    pointer-events: none;
  }
  @media (max-width: 991.98px) {
    .split-form::after { display: none; }
  }
  .section-card {
    background: #ffffff;
    border: 1px solid rgba(101, 50, 201, 0.08);
    border-radius: 16px;
    padding: 1.25rem;
    box-shadow: 0 10px 26px rgba(67, 17, 165, 0.04);
  }
  .page-title {
    font-family: 'Bricolage Grotesque', sans-serif;
    letter-spacing: .2px;
  }
  .brand-pane {
    background-color: #f7f5ff;
    /* Data URL background pattern */
    background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23e9e3ff' fill-opacity='0.4'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
  }
  .brand-logo { height: 56px; margin-bottom: 1rem; }
  .kicker {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--brand-deep);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.25rem;
  }
  .display-brand {
    font-family: 'Bricolage Grotesque', sans-serif;
    font-size: clamp(1.5rem, 4vw, 1.8rem);
    font-weight: 700;
    color: var(--brand-primary);
  }
  .form-label {
    font-weight: 500;
    color: var(--text-dark);
    margin-bottom: 0.5rem;
  }
  .input-group-text {
    background: #f7f5ff;
    border: 1px solid var(--border-color);
    color: var(--brand-deep);
  }
  .form-control, .form-select {
    border: 1px solid #ddd;
    transition: all 0.2s ease;
    padding: 0.75rem 1rem;
    height: 48px;
  }
  .form-control[rows] {
      height: auto;
  }
  .form-text {
    margin-top: .35rem;
  }
  .form-section .row > [class*="col-"] {
    display: flex;
    flex-direction: column;
  }
  .form-section .row.g-3 {
    row-gap: 1rem;
  }
  .form-section .form-label {
    min-height: 22px;
  }
  .form-section .form-control,
  .form-section .form-select {
    width: 100%;
  }
  .form-section .input-group {
    align-items: stretch;
  }
  .form-section .input-group .input-group-text {
    height: 48px;
    display: inline-flex;
    align-items: center;
  }
  .form-control:focus {
    border-color: var(--brand-accent);
    box-shadow: 0 0 0 0.25rem rgba(101, 50, 201, 0.15);
    background-color: #fff;
  }
  .btn {
    padding: 0.75rem 1.5rem;
    font-weight: 500;
    border-radius: 8px;
    transition: all 0.2s ease;
  }
  .btn-brand {
    background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-accent) 100%);
    border: none;
    color: #fff !important;
    box-shadow: 0 4px 12px var(--shadow-color-hover);
  }
  .btn-brand:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px var(--shadow-color-hover);
  }
  .btn-outline-dark {
    border: 1px solid var(--border-color);
    color: var(--text-muted);
    background-color: #fff;
  }
  .btn-outline-dark:hover {
    border-color: var(--brand-deep);
    color: var(--brand-deep);
    background: var(--brand-light);
  }
  .steps-wrapper {
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1rem;
    background-color: rgba(255,255,255,0.5);
  }
  .step {
    font-size: 0.8rem;
    font-weight: 500;
    color: var(--text-muted);
  }
  .step.active {
    color: var(--brand-deep);
    font-weight: 600;
  }
  .step .dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background-color: var(--border-color);
    margin-right: 0.5rem;
  }
  .step.active .dot {
    background-color: var(--brand-accent);
  }
  .toggle-pass {
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--brand-primary);
    cursor: pointer;
    text-decoration: none;
  }
  .toggle-pass:hover { text-decoration: underline; }
  .file-input-wrapper {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    border: 2px dashed var(--border-color);
    border-radius: 8px;
    background-color: #fcfbff;
    transition: background-color 0.2s ease, border-color 0.2s ease;
    cursor: pointer;
    min-height: 48px;
  }
  .file-input-wrapper:hover {
    border-color: var(--brand-accent);
    background-color: var(--brand-light);
  }
  .file-input-wrapper input[type="file"] {
    position: absolute;
    font-size: 100px;
    width: 100%;
    height: 100%;
    top: 0;
    left: 0;
    opacity: 0;
    cursor: pointer;
  }
  .file-input-text {
    color: var(--text-muted);
    font-weight: 500;
    text-align: center;
    word-break: break-all;
  }
  .file-input-text ion-icon {
    font-size: 1.5rem;
    margin-right: 0.5rem;
    vertical-align: middle;
  }
  .section-title {
    font-size: 0.9rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-muted);
    margin-top: 2rem;
    margin-bottom: .75rem;
    display: flex;
    align-items: center;
    gap: .5rem;
  }
  .section-title::after {
    content: "";
    height: 1px;
    flex: 1;
    background: linear-gradient(90deg, var(--border-color), transparent);
  }
  .soft-hr {
    height: 1px;
    background: linear-gradient(90deg, transparent, #eee, transparent);
    border: 0;
    margin: 1.5rem 0;
  }
  input[type="file"].form-control {
    padding: 0.4rem 1rem;
    height: 48px;
  }
  .form-actions {
    display: flex;
    gap: .75rem;
    flex-wrap: wrap;
    align-items: center;
  }
  .form-actions .btn {
    min-width: 200px;
  }
  .form-actions .btn-link {
    padding-left: 0;
  }

  /* FIX: Style for the requirement card */
  .requirement-card {
    border: 1px solid var(--border-color);
    border-radius: 16px;
    background-color: #fbf9ff;
    box-shadow: 0 10px 30px rgba(67, 17, 165, 0.06);
  }
  .requirement-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: .75rem;
  }
  .requirement-card .form-check {
    padding: .75rem .75rem .75rem 2.25rem;
    border-radius: 10px;
    background: #ffffff;
    border: 1px solid rgba(101, 50, 201, 0.08);
  }
  .requirement-card .form-check + .form-check {
    margin-top: .65rem;
  }
  .requirement-card .form-check-input {
    margin-left: -1.6rem;
  }
  </style>
</head>
<body>
  <div class="auth-wrapper">
    <div class="card auth-card">
      <div class="row g-0">
        <div class="col-12 p-4 p-md-5 bg-white">
          <div class="mb-4 text-center d-lg-none">
            <img src="<?= BASE_URL ?>img/logo.png" height="48" alt="TNVS Logo">
          </div>
          <h2 class="h4 mb-2 fw-bold d-flex align-items-center gap-2 page-title" style="color:var(--brand-deep);">
            <ion-icon name="create-outline"></ion-icon>
            Vendor Registration & Compliance
          </h2>
          <p class="text-muted mb-4">Complete the form below.</p>

          <?php if ($ok): ?>
            <div class="alert alert-success d-flex align-items-center gap-2 p-3" role="alert">
              <ion-icon name="checkmark-circle-outline" class="fs-4 flex-shrink-0"></ion-icon>
              <div>
                <strong class="d-block">Success!</strong>
                <?= $ok ?>
              </div>
            </div>
            <a href="login.php" class="btn btn-brand mt-3 w-100">
              <ion-icon name="log-in-outline"></ion-icon> Go to Login
            </a>
          <?php else: ?>
            <?php if ($err): ?>
              <div class="alert alert-danger d-flex align-items-center gap-2 p-3" role="alert">
                <ion-icon name="alert-circle-outline" class="fs-4 flex-shrink-0"></ion-icon>
                <div class="flex-grow-1">
                  <?= htmlspecialchars($err) ?>
                </div>
              </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

              <div class="row g-4 split-form">
                <div class="col-12 col-lg-6 form-section">
                  <div class="section-card h-100">
                    <div class="section-title mt-0">Account & Company Details</div>

                  <div class="row g-3">
                    <div class="col-12">
                      <label for="company_name" class="form-label">Company Name <span class="text-danger">*</span></label>
                      <div class="input-group">
                        <span class="input-group-text"><ion-icon name="business-outline"></ion-icon></span>
                        <input type="text" name="company_name" id="company_name" class="form-control" required value="<?= htmlspecialchars($vals['company_name']) ?>" placeholder="e.g., ABC Trading Corp.">
                        <div class="invalid-feedback">Company name is required.</div>
                      </div>
                    </div>

                    <div class="col-12 col-md-6">
                      <label for="contact_person" class="form-label">Contact Person <span class="text-danger">*</span></label>
                      <div class="input-group">
                        <span class="input-group-text"><ion-icon name="person-outline"></ion-icon></span>
                        <input type="text" name="contact_person" id="contact_person" class="form-control" required value="<?= htmlspecialchars($vals['contact_person']) ?>" placeholder="Full Name">
                        <div class="invalid-feedback">Contact person is required.</div>
                      </div>
                    </div>

                    <div class="col-12 col-md-6">
                      <label for="phone" class="form-label">Phone</label>
                      <div class="input-group">
                        <span class="input-group-text"><ion-icon name="call-outline"></ion-icon></span>
                        <input type="tel" name="phone" id="phone" class="form-control" value="<?= htmlspecialchars($vals['phone']) ?>" placeholder="e.g., +63 9xx xxx xxxx">
                      </div>
                    </div>

                    <div class="col-12 col-md-6">
                      <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                      <div class="input-group">
                        <span class="input-group-text"><ion-icon name="mail-outline"></ion-icon></span>
                        <input type="email" name="email" id="email" class="form-control" required value="<?= htmlspecialchars($vals['email']) ?>" placeholder="business@example.com">
                        <div class="invalid-feedback">A valid email is required.</div>
                      </div>
                    </div>

                    <div class="col-12 col-md-6">
                      <label for="password" class="form-label d-flex justify-content-between align-items-center">
                        <span>Password <span class="text-danger">*</span></span>
                        <a href="#" class="toggle-pass" id="togglePass">Show</a>
                      </label>
                      <div class="input-group">
                        <span class="input-group-text"><ion-icon name="lock-closed-outline"></ion-icon></span>
                        <input type="password" name="password" id="password" class="form-control" minlength="8" required autocomplete="new-password" placeholder="At least 8 characters">
                        <div class="invalid-feedback">Password must be at least 8 characters.</div>
                      </div>
                    </div>

                    <div class="col-12">
                      <label for="address" class="form-label">Address</label>
                      <textarea name="address" id="address" class="form-control" rows="2" placeholder="Street, City, Province, Zip Code"><?= htmlspecialchars($vals['address']) ?></textarea>
                    </div>

                    <div class="col-12">
                      <label class="form-label">Profile Photo (Optional, max 2MB)</label>
                      <label class="file-input-wrapper">
                        <input type="file" name="profile_photo" accept="image/png, image/jpeg, image/gif, image/webp" onchange="document.getElementById('file-name').textContent = this.files[0] ? this.files[0].name : 'Click or drag file to upload';">
                        <span class="file-input-text" id="file-name">
                            <ion-icon name="cloud-upload-outline"></ion-icon>
                            Click or drag file to upload
                        </span>
                      </label>
                      <small class="form-text text-muted d-block mt-1">Accepted formats: JPG, PNG, GIF, WEBP.</small>
                    </div>
                  </div>
                  </div>
                </div>

                <div class="col-12 col-lg-6 form-section">
                  <div class="section-card h-100">
                    <div class="section-title mt-0">Compliance / KYC Documents</div>

                  <div class="mb-3">
                    <label class="form-label">Product / Service Categories (comma-separated)</label>
                    <input type="text" name="categories" class="form-control"
                           value="<?= htmlspecialchars($vals['categories']) ?>" placeholder="e.g., IT Services, Office Supplies, Logistics">
                    <small class="form-text text-muted d-block mt-1">List the main categories of goods/services you offer.</small>
                  </div>

                  <div class="row g-3">
                    <div class="col-md-6">
                      <label class="form-label">DTI / SEC Registration (PDF / Image)</label>
                      <input type="file" name="dti" class="form-control" accept=".pdf,.png,.jpg,.jpeg">
                      <small class="form-text text-muted d-block mt-1">Required for legal verification.</small>
                    </div>

                    <div class="col-md-6">
                      <label class="form-label">BIR / TIN Certificate</label>
                      <input type="file" name="bir" class="form-control" accept=".pdf,.png,.jpg,.jpeg">
                      <small class="form-text text-muted d-block mt-1">Required for tax compliance.</small>
                    </div>

                    <div class="col-md-6">
                      <label class="form-label">Business Permit</label>
                      <input type="file" name="permit" class="form-control" accept=".pdf,.png,.jpg,.jpeg">
                      <small class="form-text text-muted d-block mt-1">Mayor's Permit / Business Permit.</small>
                    </div>

                    <div class="col-md-6">
                      <label class="form-label">Bank Certificate (Optional)</label>
                      <input type="file" name="bank" class="form-control" accept=".pdf,.png,.jpg,.jpeg">
                      <small class="form-text text-muted d-block mt-1">Under business name.</small>
                    </div>

                    <div class="col-md-12">
                      <label class="form-label">Product Catalog (Required)</label>
                      <input type="file" name="catalog" class="form-control" accept=".pdf,.png,.jpg,.jpeg,.xlsx,.xls,.csv">
                      <small class="form-text text-muted d-block mt-1">Upload your product list / catalog (PDF, Excel, or image).</small>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Catalog Category</label>
                      <select name="catalog_category" class="form-select">
                        <option value="">Select category</option>
                        <?php foreach ($catOptions as $c): ?>
                          <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Website / Online Store Link (Optional)</label>
                      <input type="url" name="website_link" class="form-control" placeholder="https://">
                      <small class="form-text text-muted d-block mt-1">Optional if you already uploaded a catalog.</small>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Delivery Receipt / Invoice (Optional)</label>
                      <input type="file" name="receipt" class="form-control" accept=".pdf,.png,.jpg,.jpeg">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Receipt Category</label>
                      <select name="receipt_category" class="form-select">
                        <option value="">Select category</option>
                        <?php foreach ($catOptions as $c): ?>
                          <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  </div>
                </div>
              </div>

              <hr class="soft-hr" />

              <div class="section-title">Vendor Requirements & Contract</div>
              <p class="text-muted small mb-3">
                Please review and confirm that your company meets the following requirements. Confirmation is **required** to submit your application for review.
              </p>

              <div class="card requirement-card mb-4">
                <div class="card-body py-3">
                  <div class="requirement-header">
                    <div class="fw-semibold">Checklist</div>
                    <div class="form-check m-0">
                      <input class="form-check-input" type="checkbox" id="selectAllRequirements">
                      <label class="form-check-label small" for="selectAllRequirements">Select all</label>
                    </div>
                  </div>
                  <?php foreach ($requirementFields as $key => $label): ?>
                    <div class="form-check mb-2">
                      <input class="form-check-input" type="checkbox"
                             id="<?= htmlspecialchars($key) ?>"
                             name="<?= htmlspecialchars($key) ?>"
                             <?= !empty($_POST[$key]) ? 'checked' : '' ?>>
                      <label class="form-check-label small" for="<?= htmlspecialchars($key) ?>">
                        <ion-icon name="checkmark-circle-outline" class="text-success me-1"></ion-icon>
                        <?= htmlspecialchars($label) ?>
                      </label>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="terms" name="terms"
                       <?= !empty($_POST['terms']) ? 'checked' : '' ?> aria-describedby="termsHelp">
                <label class="form-check-label" for="terms">
                  I have read and agree to the
                  <a href="<?= BASE_URL ?>vendor_portal/vendor/contract_policy.php" target="_blank" class="fw-semibold" style="color: var(--brand-deep);">
                    TNVS Vendor Contract &amp; Privacy Policy
                  </a>.
                </label>
                <div class="invalid-feedback">You must agree to the contract and policy before submitting.</div>
              </div>

              <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" id="agree" required>
                <label class="form-check-label fw-semibold" for="agree">
                  I confirm the information provided is **accurate and complete**.
                </label>
                <div class="invalid-feedback">You must confirm the accuracy of your information to continue.</div>
              </div>

              <div class="form-actions mt-4">
                <button type="submit" name="action" value="submit" class="btn btn-brand btn-lg">
                  <ion-icon name="paper-plane-outline"></ion-icon> Submit for Review
                </button>
                <a href="../../login.php" class="btn btn-link text-muted" style="text-decoration: none;">
                  Already have an account? Login here.
                </a>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      'use strict';
    
      const form = document.querySelector('.needs-validation');
      const submitBtn = document.querySelector('button[name="action"][value="submit"]');

      Array.from(document.querySelectorAll('.needs-validation')).forEach(f => {
        f.addEventListener('submit', event => {
          if (!f.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
          }

          if (event.submitter && event.submitter.value === 'submit') {
             let isTermsChecked = document.getElementById('terms').checked;
             let isAgreeChecked = document.getElementById('agree').checked;

             if (!isTermsChecked || !isAgreeChecked) {
                 event.preventDefault();
                 event.stopPropagation();
                 // This ensures the custom validation messages show up
                 document.getElementById('terms').classList.toggle('is-invalid', !isTermsChecked);
                 document.getElementById('agree').classList.toggle('is-invalid', !isAgreeChecked);
             }
          }

          f.classList.add('was-validated');
        }, false);
      });

      // Toggle Password Visibility
      const toggle = document.getElementById('togglePass');
      const pw  = document.getElementById('password');
      if (toggle && pw) {
        toggle.addEventListener('click', (e) => {
          e.preventDefault();
          const isText = pw.getAttribute('type') === 'text';
          pw.setAttribute('type', isText ? 'password' : 'text');
          toggle.textContent = isText ? 'Show' : 'Hide';
        });
      }

      // Select-all for requirements
      const selectAll = document.getElementById('selectAllRequirements');
      const requirementChecks = Array.from(document.querySelectorAll('.requirement-card .form-check-input'))
        .filter(el => el.id !== 'selectAllRequirements');
      if (selectAll) {
        selectAll.addEventListener('change', () => {
          requirementChecks.forEach(cb => { cb.checked = selectAll.checked; });
          selectAll.indeterminate = false;
        });
        requirementChecks.forEach(cb => {
          cb.addEventListener('change', () => {
            const allChecked = requirementChecks.every(i => i.checked);
            selectAll.checked = allChecked;
            selectAll.indeterminate = !allChecked && requirementChecks.some(i => i.checked);
          });
        });
      }
    })();
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
