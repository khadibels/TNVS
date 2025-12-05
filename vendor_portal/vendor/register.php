<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/db.php";
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$proc = db('proc');
$auth = db('auth');

$proc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$auth->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

        if ($company && $person && $email && $pass) {
            try {
                $dup = $proc->prepare("SELECT 1 FROM vendors WHERE email=? LIMIT 1");
                $dup->execute([$email]);
                if ($dup->fetchColumn()) {
                    throw new RuntimeException("That email is already registered.");
                }

                $proc->beginTransaction();

                $hash   = password_hash($pass, PASSWORD_DEFAULT);
                $status = ($action === 'submit') ? 'pending' : 'draft';

                // Insert base vendor row
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

                // Profile photo upload
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

                // Compliance / KYC docs upload
                $baseDir = realpath(__DIR__ . '/../../');
                $docsDir = $baseDir . '/vendor_portal/vendor/uploads';
                if (!is_dir($docsDir)) mkdir($docsDir, 0775, true);

                $uploadFields = [
                    'dti'     => 'dti_doc',
                    'bir'     => 'bir_doc',
                    'permit'  => 'permit_doc',
                    'bank'    => 'bank_doc',
                    'catalog' => 'catalog_doc'
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
                    if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg'], true)) {
                        continue;
                    }

                    $fname = sprintf('%s_vendor%d_%s.%s', $field, $vendorId, bin2hex(random_bytes(4)), $ext);
                    $abs   = $docsDir . '/' . $fname;

                    if (move_uploaded_file($tmp, $abs)) {
                        @chmod($abs, 0644);
                        $setParts[]       = "$col = :$col";
                        $params[":$col"]  = $fname;
                    }
                }

                if ($setParts) {
                    $sql = "UPDATE vendors SET " . implode(', ', $setParts) . " WHERE id = :id";
                    $u   = $proc->prepare($sql);
                    $u->execute($params);
                }

                $proc->commit();

                // Sync with auth.users
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

                header('Location: ' . rtrim(BASE_URL, '/') . '/vendor_portal/vendor/gate.php');
                exit;
            } catch (Throwable $e) {
                if ($proc->inTransaction()) $proc->rollBack();
                $err = "Registration failed: " . $e->getMessage();
            }
        } else {
            $err = "Please fill out all required fields.";
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
  /* your existing styles here (unchanged) */
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
    border: 1px solid #fff;
    box-shadow: 0 4px 12px var(--shadow-color), 0 16px 40px var(--shadow-color);
    overflow: hidden;
  }
  .brand-pane {
    background-color: #f7f5ff;
    background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23e9e3ff' fill-opacity='0.4'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
  }
  .brand-logo {
    height: 56px;
    margin-bottom: 1rem;
  }
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
    height: calc(1.5em + 1.5rem + 2px);
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
  .toggle-pass:hover {
    text-decoration: underline;
  }
  .file-input-wrapper {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    border: 2px dashed var(--border-color);
    border-radius: 8px;
    background-color: #fcfbff;
    transition: background-color 0.2s ease, border-color 0.2s ease;
    cursor: pointer;
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
  }
  .soft-hr {
    height: 1px;
    background: linear-gradient(90deg, transparent, #eee, transparent);
    border: 0;
    margin: 1.5rem 0;
  }
  </style>
</head>
<body>
  <div class="auth-wrapper">
    <div class="card auth-card">
      <div class="row g-0">
        <div class="col-lg-5 d-none d-lg-flex flex-column justify-content-center align-items-center text-center p-4 p-xl-5 brand-pane">
          <img src="<?= BASE_URL ?>img/logo.png" class="brand-logo mb-4" alt="Logo">

          <div class="kicker text-uppercase fw-semibold mb-1">Vendor Onboarding</div>
          <h1 class="display-brand fw-bold mb-3">Partner with TNVS</h1>
          <p class="text-muted mb-5 px-3">
            Join sourcing events, submit quotations, and track awards in real time.
            Your profile will be reviewed by our Procurement team.
          </p>

          <div class="steps-wrapper w-100" style="max-width: 300px;">
            <div class="d-flex flex-column gap-3 text-start">
              <div class="step active"><span class="dot"></span>Step 1: Registration & KYC</div>
              <div class="step"><span class="dot"></span>Step 2: Review & Approval</div>
              <div class="step"><span class="dot"></span>Step 3: Login & Participate</div>
            </div>
          </div>
        </div>

        <div class="col-lg-7 p-4 p-md-5 bg-white">
          <div class="mb-4 text-center d-lg-none">
            <img src="<?= BASE_URL ?>img/logo.png" height="48" alt="Logo">
          </div>
          <h2 class="h4 mb-2 fw-bold d-flex align-items-center gap-2" style="color:var(--brand-deep);">
            <ion-icon name="create-outline"></ion-icon>
            Vendor Registration & Compliance
          </h2>
          <p class="text-muted mb-4">Create your account and submit your compliance documents in one step.</p>

          <?php if ($ok): ?>
            <div class="alert alert-success d-flex align-items-center gap-2">
              <ion-icon name="checkmark-circle-outline" class="fs-4"></ion-icon>
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
              <div class="alert alert-danger d-flex align-items-center gap-2">
                <ion-icon name="alert-circle-outline" class="fs-4"></ion-icon>
                <?= htmlspecialchars($err) ?>
              </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

              <!-- Account / Company info -->
              <div class="section-title">Account & Company Details</div>

              <div class="row g-3">
                <div class="col-12">
                  <label for="company_name" class="form-label">Company Name <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text"><ion-icon name="business-outline"></ion-icon></span>
                    <input type="text" name="company_name" id="company_name" class="form-control" required value="<?= htmlspecialchars($vals['company_name']) ?>">
                    <div class="invalid-feedback">Company name is required.</div>
                  </div>
                </div>

                <div class="col-12 col-md-6">
                  <label for="contact_person" class="form-label">Contact Person <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text"><ion-icon name="person-outline"></ion-icon></span>
                    <input type="text" name="contact_person" id="contact_person" class="form-control" required value="<?= htmlspecialchars($vals['contact_person']) ?>">
                    <div class="invalid-feedback">Contact person is required.</div>
                  </div>
                </div>

                <div class="col-12 col-md-6">
                  <label for="phone" class="form-label">Phone</label>
                  <div class="input-group">
                    <span class="input-group-text"><ion-icon name="call-outline"></ion-icon></span>
                    <input type="tel" name="phone" id="phone" class="form-control" value="<?= htmlspecialchars($vals['phone']) ?>">
                  </div>
                </div>

                <div class="col-12 col-md-6">
                  <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <span class="input-group-text"><ion-icon name="mail-outline"></ion-icon></span>
                    <input type="email" name="email" id="email" class="form-control" required value="<?= htmlspecialchars($vals['email']) ?>">
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
                    <input type="password" name="password" id="password" class="form-control" minlength="8" required autocomplete="new-password">
                    <div class="invalid-feedback">Password must be at least 8 characters.</div>
                  </div>
                </div>

                <div class="col-12">
                  <label for="address" class="form-label">Address</label>
                  <textarea name="address" id="address" class="form-control" rows="2"><?= htmlspecialchars($vals['address']) ?></textarea>
                </div>

                <div class="col-12">
                  <label class="form-label">Profile Photo (Optional, max 2MB)</label>
                  <label class="file-input-wrapper">
                    <input type="file" name="profile_photo" accept="image/png, image/jpeg, image/gif" onchange="document.getElementById('file-name').textContent = this.files[0] ? this.files[0].name : 'Click or drag file to upload';">
                    <span class="file-input-text" id="file-name">
                        <ion-icon name="cloud-upload-outline"></ion-icon>
                        Click or drag file to upload
                    </span>
                  </label>
                </div>
              </div>

              <hr class="soft-hr" />

              <!-- Compliance / KYC -->
              <div class="section-title">Compliance / KYC</div>

              <div class="mb-3">
                <label class="form-label">Product / Service Categories (comma-separated)</label>
                <input type="text" name="categories" class="form-control"
                       value="<?= htmlspecialchars($vals['categories']) ?>">
              </div>

              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">DTI / SEC Registration (PDF / Image)</label>
                  <input type="file" name="dti" class="form-control" accept=".pdf,.png,.jpg,.jpeg">
                </div>

                <div class="col-md-6">
                  <label class="form-label">BIR / TIN Certificate</label>
                  <input type="file" name="bir" class="form-control" accept=".pdf,.png,.jpg,.jpeg">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Business Permit</label>
                  <input type="file" name="permit" class="form-control" accept=".pdf,.png,.jpg,.jpeg">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Bank Certificate (optional)</label>
                  <input type="file" name="bank" class="form-control" accept=".pdf,.png,.jpg,.jpeg">
                </div>

                <div class="col-md-12">
                  <label class="form-label">Product Catalog (optional)</label>
                  <input type="file" name="catalog" class="form-control" accept=".pdf,.png,.jpg,.jpeg">
                </div>
              </div>

              <div class="col-12 mt-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="agree" required>
                  <label class="form-check-label" for="agree">
                    I confirm the information provided is accurate and complete.
                  </label>
                  <div class="invalid-feedback">You must agree to continue.</div>
                </div>
              </div>

              <div class="col-12 d-grid gap-2 mt-4">
                <button type="submit" name="action" value="submit" class="btn btn-brand">
                  <ion-icon name="paper-plane-outline"></ion-icon> Submit for Review
                </button>
                <button type="submit" name="action" value="save" class="btn btn-outline-dark">
                  <ion-icon name="save-outline"></ion-icon> Save as Draft
                </button>
                <a href="../../login.php" class="btn btn-outline-dark text-center">
                  Already have an account? Login
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
      const forms = document.querySelectorAll('.needs-validation');
      Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
          if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
          }
          form.classList.add('was-validated');
        }, false);
      });

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
    })();
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
