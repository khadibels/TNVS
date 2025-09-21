<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/db.php";
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdo = db('proc');

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$err = "";
$ok  = "";
$vals = [
  'company_name'   => '',
  'contact_person' => '',
  'email'          => '',
  'phone'          => '',
  'address'        => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($vals as $k => $_) { $vals[$k] = trim($_POST[$k] ?? ''); }

    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        $err = "Invalid form token. Please try again.";
    } else {
        $company = $vals['company_name'];
        $person  = $vals['contact_person'];
        $email   = $vals['email'];
        $pass    = $_POST['password'] ?? '';
        $phone   = $vals['phone'];
        $address = $vals['address'];

        $photoPlan = null;
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['profile_photo'];
            if ($f['error'] === UPLOAD_ERR_OK && $f['size'] <= 2 * 1024 * 1024) {
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                $allowedExt  = ['jpg','jpeg','png','gif'];
                $allowedMime = ['image/jpeg','image/png','image/gif'];
                $mime = mime_content_type($f['tmp_name']);
                if (in_array($ext, $allowedExt, true) && in_array($mime, $allowedMime, true)) {
                    $photoPlan = ['tmp' => $f['tmp_name'], 'ext' => ($ext === 'jpeg' ? 'jpg' : $ext)];
                } else {
                    $err = "Only JPG, PNG, or GIF images are allowed.";
                }
            } else if ($f['error'] !== UPLOAD_ERR_NO_FILE) {
                $err = "Profile photo is too large or invalid.";
            }
        }

        if (!$err && $company && $person && $email && $pass) {
            try {
                $dup = $pdo->prepare("SELECT 1 FROM vendors WHERE email = ? LIMIT 1");
                $dup->execute([$email]);
                if ($dup->fetchColumn()) {
                    throw new RuntimeException("That email is already registered. Try signing in or use a different email.");
                }

                $pdo->beginTransaction();

                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO vendors (company_name, contact_person, email, password, phone, address, status)
                    VALUES (?,?,?,?,?,?,'Pending')
                ");
                $stmt->execute([$company, $person, $email, $hash, $phone, $address]);

                $vendorId = (int)$pdo->lastInsertId();

                if ($photoPlan) {
                    $targetDir = __DIR__ . "/uploads/";
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0777, true);
                    }
                    $photoFile  = "vendor_{$vendorId}_" . bin2hex(random_bytes(4)) . "." . $photoPlan['ext'];
                    $targetFile = $targetDir . $photoFile;
                    if (move_uploaded_file($photoPlan['tmp'], $targetFile)) {
                        $up = $pdo->prepare("UPDATE vendors SET profile_photo = ? WHERE id = ?");
                        $up->execute([$photoFile, $vendorId]);
                    }
                }

                $pdo->commit();

                // >>> AUTO-LOGIN + REDIRECT TO GATE (do not change your UI below)
                $_SESSION['vendor'] = [
                    'id'           => $vendorId,
                    'email'        => $email,
                    'company_name' => $company,
                    'status'       => 'Pending',
                ];
                header('Location: ' . BASE_URL . 'vendor_portal/vendor/gate.php');
                exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $msg = $e->getMessage();
                if ($e instanceof PDOException) {
                    $code = $e->errorInfo[1] ?? null;
                    if ((int)$code === 1062) {
                        $msg = "That email is already registered. Try signing in or use a different email.";
                    } else {
                        $msg = "Database error ($code). Please try again.";
                    }
                }
                $err = $msg ?: "Unexpected error. Please try again.";
            }
        } elseif (!$err) {
            $err = "Please fill out all required fields.";
        }
    }
}
?>


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
  /* Subtle SVG background pattern for texture */
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
  font-size: clamp(1.5rem, 4vw, 1.8rem); /* Responsive font size */
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
  height: calc(1.5em + 1.5rem + 2px); /* Consistent height */
}
.form-control:focus {
  border-color: var(--brand-accent);
  box-shadow: 0 0 0 0.25rem rgba(101, 50, 201, 0.15);
  background-color: #fff;
}

/* Polished Buttons */
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

/* Step indicator */
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

.soft-hr {
  height:1px;
  background: linear-gradient(90deg, transparent, #eee, transparent);
  border:0;
}

/* Custom file input style */
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
              <div class="step active"><span class="dot"></span>Step 1: Registration</div>
              <div class="step"><span class="dot"></span>Step 2: Approval</div>
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
            Vendor Registration Form
          </h2>
          <p class="text-muted mb-4">Create your vendor account to get started.</p>

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
                  <button type="submit" class="btn btn-brand">
                    <ion-icon name="person-add-outline"></ion-icon> Submit Registration
                  </button>
                  <a href="login.php" class="btn btn-outline-dark text-center">
                    Already have an account? Login
                  </a>
                </div>
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
      // Bootstrap validation
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

      // Password visibility toggle
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