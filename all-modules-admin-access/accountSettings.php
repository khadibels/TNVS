<?php
declare(strict_types=1);

$inc = __DIR__ . "/../includes";
if (file_exists($inc . "/config.php"))  require_once $inc . "/config.php";
if (file_exists($inc . "/auth.php"))    require_once $inc . "/auth.php";
if (file_exists($inc . "/db.php"))      require_once $inc . "/db.php";
if (function_exists("require_login"))   require_login();
if (function_exists("require_role"))    require_role(['admin']);

$active = 'settings_account';
$BASE = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$pdo = function_exists('db') ? db('auth') : null;

if (!$pdo instanceof PDO) {
  http_response_code(500);
  echo "Auth DB unavailable.";
  exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf_token'];

$uid = (int)($_SESSION['user']['id'] ?? 0);
$okMsg = '';
$errMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (($_POST['csrf'] ?? '') !== $csrf) {
    $errMsg = 'Invalid CSRF token.';
  } else {
    $op = trim((string)($_POST['op'] ?? ''));

    if ($op === 'profile') {
      $name = trim((string)($_POST['name'] ?? ''));
      $email = trim((string)($_POST['email'] ?? ''));
      if ($name === '' || $email === '') {
        $errMsg = 'Name and email are required.';
      } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errMsg = 'Invalid email format.';
      } else {
        try {
          $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
          $chk->execute([$email, $uid]);
          if ($chk->fetchColumn()) {
            $errMsg = 'Email is already used by another account.';
          } else {
            $up = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $up->execute([$name, $email, $uid]);
            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['email'] = $email;
            $okMsg = 'Profile updated successfully.';
          }
        } catch (Throwable $e) {
          $errMsg = 'Failed to update profile.';
        }
      }
    }

    if ($op === 'password') {
      $current = (string)($_POST['current_password'] ?? '');
      $new = (string)($_POST['new_password'] ?? '');
      $confirm = (string)($_POST['confirm_password'] ?? '');
      if ($current === '' || $new === '' || $confirm === '') {
        $errMsg = 'All password fields are required.';
      } elseif (strlen($new) < 8) {
        $errMsg = 'New password must be at least 8 characters.';
      } elseif ($new !== $confirm) {
        $errMsg = 'New password and confirmation do not match.';
      } else {
        try {
          $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
          $st->execute([$uid]);
          $hash = (string)($st->fetchColumn() ?? '');
          if ($hash === '' || !password_verify($current, $hash)) {
            $errMsg = 'Current password is incorrect.';
          } else {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $up = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $up->execute([$newHash, $uid]);
            $okMsg = 'Password updated successfully.';
          }
        } catch (Throwable $e) {
          $errMsg = 'Failed to update password.';
        }
      }
    }
  }
}

$user = [];
try {
  $st = $pdo->prepare("SELECT id, name, email, role, created_at FROM users WHERE id = ? LIMIT 1");
  $st->execute([$uid]);
  $user = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $user = $_SESSION['user'] ?? [];
}

$userName = (string)($user['name'] ?? ($_SESSION['user']['name'] ?? 'Admin'));
$userRole = (string)($user['role'] ?? ($_SESSION['user']['role'] ?? 'Admin'));

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Account Settings | Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="../css/style.css" rel="stylesheet"/>
  <link href="../css/modules.css" rel="stylesheet"/>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <style>
    .settings-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(320px,1fr)); gap:1rem; }
    .hint { color:#64748b; font-size:.86rem; }
    .meta-kv { display:grid; grid-template-columns: 140px 1fr; row-gap:.4rem; column-gap:.8rem; font-size:.92rem; }
    .meta-kv .k { color:#64748b; }
  </style>
</head>
<body class="saas-page">
<div class="container-fluid p-0">
  <div class="row g-0">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="col main-content p-3 p-lg-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
          <button class="sidebar-toggle d-lg-none btn btn-outline-secondary btn-sm" id="sidebarToggle2">
            <ion-icon name="menu-outline"></ion-icon>
          </button>
          <h2 class="m-0 d-flex align-items-center gap-2 page-title">
            <ion-icon name="person-circle-outline"></ion-icon>Account Settings
          </h2>
        </div>
        <div class="profile-menu" data-profile-menu>
          <button class="profile-trigger" type="button" data-profile-trigger aria-expanded="false" aria-haspopup="true">
            <img src="<?= $BASE ?>/img/profile.jpg" class="rounded-circle" width="36" height="36" alt="">
            <div class="profile-text">
              <div class="profile-name"><?= h($userName) ?></div>
              <div class="profile-role"><?= h($userRole) ?></div>
            </div>
            <ion-icon class="profile-caret" name="chevron-down-outline"></ion-icon>
          </button>
          <div class="profile-dropdown" data-profile-dropdown role="menu">
            <a href="<?= $BASE ?>/auth/logout.php" role="menuitem">Sign out</a>
          </div>
        </div>
      </div>

      <?php if ($okMsg !== ''): ?>
        <div class="alert alert-success"><?= h($okMsg) ?></div>
      <?php endif; ?>
      <?php if ($errMsg !== ''): ?>
        <div class="alert alert-danger"><?= h($errMsg) ?></div>
      <?php endif; ?>

      <div class="settings-grid">
        <section class="card shadow-sm">
          <div class="card-body">
            <h5 class="mb-3">Profile Details</h5>
            <form method="post" class="row g-3">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="op" value="profile">
              <div class="col-12">
                <label class="form-label">Full Name</label>
                <input class="form-control" name="name" value="<?= h((string)($user['name'] ?? '')) ?>" required>
              </div>
              <div class="col-12">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="email" value="<?= h((string)($user['email'] ?? '')) ?>" required>
              </div>
              <div class="col-12 d-grid">
                <button class="btn btn-primary" type="submit">
                  <ion-icon name="save-outline"></ion-icon> Save Profile
                </button>
              </div>
            </form>
          </div>
        </section>

        <section class="card shadow-sm">
          <div class="card-body">
            <h5 class="mb-3">Security</h5>
            <form method="post" class="row g-3">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="op" value="password">
              <div class="col-12">
                <label class="form-label">Current Password</label>
                <input type="password" class="form-control" name="current_password" required>
              </div>
              <div class="col-12">
                <label class="form-label">New Password</label>
                <input type="password" class="form-control" name="new_password" minlength="8" required>
              </div>
              <div class="col-12">
                <label class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" name="confirm_password" minlength="8" required>
              </div>
              <div class="col-12 hint">Password must be at least 8 characters.</div>
              <div class="col-12 d-grid">
                <button class="btn btn-outline-primary" type="submit">
                  <ion-icon name="lock-closed-outline"></ion-icon> Change Password
                </button>
              </div>
            </form>
          </div>
        </section>
      </div>

      <section class="card shadow-sm mt-3">
        <div class="card-body">
          <h6 class="mb-3">Account Information</h6>
          <div class="meta-kv">
            <div class="k">User ID</div><div>#<?= h((string)($user['id'] ?? $uid)) ?></div>
            <div class="k">Role</div><div><?= h((string)($user['role'] ?? 'admin')) ?></div>
            <div class="k">Created At</div><div><?= h((string)($user['created_at'] ?? 'N/A')) ?></div>
            <div class="k">Session Email</div><div><?= h((string)($_SESSION['user']['email'] ?? '')) ?></div>
          </div>
        </div>
      </section>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= $BASE ?>/js/profile-dropdown.js"></script>
</body>
</html>
