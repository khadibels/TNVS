<?php
require_once __DIR__ . "/includes/config.php";
require_once __DIR__ . "/includes/auth.php";

if (!empty($_SESSION['otp_pending']) && empty($_SESSION['user'])) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/auth/verify_otp.php');
    exit();
}

if (!empty($_SESSION["user"]) && empty($_GET['err'])) {
    $role   = strtolower($_SESSION['user']['role'] ?? '');
    $vendSt = strtolower($_SESSION['user']['vendor_status'] ?? '');

    switch ($role) {
      case 'admin':
        $dest = rtrim(BASE_URL, '/') . '/all-modules-admin-access/Dashboard.php';
        break;
      case 'manager':
      case 'warehouse_staff':
        $dest = rtrim(BASE_URL, '/') . '/warehousing/warehouseDashboard.php';
        break;
      case 'procurement_officer':
        $dest = rtrim(BASE_URL, '/') . '/procurement/procurementDashboard.php';
        break;
      case 'project_lead':
        $dest = rtrim(BASE_URL, '/') . '/PLT/pltDashboard.php';
        break;
      case 'asset_manager':
        $dest = rtrim(BASE_URL, '/') . '/assetlifecycle/ALMS.php';
        break;
      case 'document_controller':
        $dest = rtrim(BASE_URL, '/') . '/documentTracking/dashboard.php';
        break;
      case 'vendor_manager':
        $dest = rtrim(BASE_URL, '/') . '/vendor_portal/manager/dashboard.php';
        break;
      case 'vendor':
        $dest = rtrim(BASE_URL, '/') . (
            $vendSt === 'approved'
              ? '/vendor_portal/vendor/dashboard.php'
              : '/vendor_portal/vendor/pending.php'
        );
        break;
      default:
        $dest = rtrim(BASE_URL, '/') . '/login.php';
        break;
    }

    header('Location: ' . $dest);
    exit();
}

$errRaw = isset($_GET['err']) ? trim((string)$_GET['err']) : '';
$vhNotice = null;
if ($errRaw !== '') {
    $kind  = 'error';
    $title = 'Login failed';
    $e = strtolower($errRaw);
    if (str_contains($e, 'too many attempts') || str_contains($e, 'locked')) {
        $kind  = 'warning';
        $title = 'Too many attempts';
    } elseif (str_contains($e, 'incorrect') || str_contains($e, 'invalid')) {
        $kind  = 'error';
        $title = 'Incorrect email or password';
    } elseif (in_array($e, ['auth','ua','ip','idle'], true)) {
        $kind  = 'info';
        $title = 'Please sign in again';
        if ($e === 'ua')   $errRaw = 'Your session ended because your browser changed.';
        if ($e === 'ip')   $errRaw = 'Your session ended because your network changed.';
        if ($e === 'idle') $errRaw = 'You were signed out due to inactivity.';
        if ($e === 'auth') $errRaw = 'Please sign in to continue.';
    }
    $vhNotice = ['kind'=>$kind, 'title'=>$title, 'msg'=>$errRaw];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Log in | ViaHale</title>

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

  <style>
    :root{ --vh-purple:#6c2bd9; --vh-purple-2:#5b21b6; --vh-purple-3:#7c3aed; }
    body{
      font-family:Poppins,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;
      min-height:100vh; margin:0; display:flex; flex-direction:column;
      background:
        radial-gradient(40rem 40rem at -10% -10%, #ede9fe 0%, transparent 60%),
        radial-gradient(50rem 50rem at 110% 0%, #f5f3ff 0%, transparent 55%),
        #ffffff;
    }
    .brandbar{ padding:18px 20px; }
    .brand{ font-weight:700; font-size:1.15rem; color:var(--vh-purple-2); letter-spacing:.2px; user-select:none; }
    .wrap{ flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center; padding:2rem 1rem; }
    .title-xl{ font-weight:600; font-size:clamp(1.4rem,1rem + 2vw,2rem); text-align:center; margin-bottom:.25rem; color:var(--vh-purple-2); }
    .subtitle{ text-align:center; color:#6b7280; margin-bottom:1.5rem; font-size:.95rem; }
    .login-card{
      width:min(460px,92vw);
      background:linear-gradient(180deg,var(--vh-purple-3),var(--vh-purple-2) 55%,var(--vh-purple) 100%);
      color:#fff; border-radius:1rem; padding:1.5rem;
      box-shadow:0 20px 40px rgba(92,44,182,.25), 0 4px 12px rgba(92,44,182,.15);
    }
    .form-label{ font-weight:500; color:#e9e9ff; font-size:.85rem; }
    .form-control{
      background-color:rgba(255,255,255,.16); color:#fff; border:1px solid rgba(255,255,255,.25);
      padding:.75rem 1rem; border-radius:.65rem; font-size:.9rem;
    }
    .form-control::placeholder{ color:#e6e6ff; opacity:.6; }
    .form-control:focus{
      background-color:rgba(255,255,255,.22); border-color:#fff;
      box-shadow:0 0 0 .15rem rgba(255,255,255,.15); color:#fff;
    }
    .input-icon{ position:relative; }
    .input-icon button{
      position:absolute; right:.6rem; top:72%; transform:translateY(-50%);
      border:0; background:transparent; color:#fff; opacity:.85;
    }
    .btn-login{
      width:100%; padding:.8rem 1rem; border-radius:.65rem; border:0;
      background:#fff; color:var(--vh-purple-2);
      font-weight:600; font-size:.95rem; margin-top:.5rem;
      transition:transform .05s ease, box-shadow .2s ease;
    }
    .btn-login:hover{ transform:translateY(-1px); box-shadow:0 8px 16px rgba(255, 255, 255, 0.1); }
    .footer-bar{
      background:var(--vh-purple-2); color:#fff; font-size:.8rem; padding:1.35rem 1rem;
      display:flex; justify-content:space-between; align-items:center; gap:.75rem; flex-wrap:wrap;
    }
    .footer-bar a{ color:#fff; text-decoration:none; } .footer-bar a:hover{ text-decoration:underline; }
    @media (max-width:480px){ .footer-bar{ justify-content:center; text-align:center; } }

    .vh-alert{
      --bg:#fff; --fg:#2b2349; --ring:#e9e3ff;
      display:flex; align-items:flex-start; gap:.75rem;
      background:var(--bg); color:var(--fg); border-radius:.9rem; padding:.9rem 1rem; position:relative;
      box-shadow:0 12px 30px rgba(92,44,182,.18), 0 4px 10px rgba(92,44,182,.12);
      border:1px solid var(--ring); animation:vh-slide-in .28s ease-out both; margin-bottom:14px;
    }
    .vh-alert__icon{
      flex:0 0 auto; display:grid; place-items:center; width:34px; height:34px; border-radius:10px;
      background:linear-gradient(180deg,#f6f2ff,#efe9ff); border:1px solid #e7defd;
    }
    .vh-alert__icon ion-icon{ font-size:20px; color:#5b21b6; }
    .vh-alert__body{ flex:1 1 auto; }
    .vh-alert__title{ font-weight:700; letter-spacing:.2px; margin-top:2px; }
    .vh-alert__msg{ opacity:.9; font-size:.92rem; line-height:1.35; }
    .vh-alert__close{
      border:0; background:transparent; color:#6b5ca8; opacity:.85; cursor:pointer; padding:.25rem; border-radius:8px; margin-top:2px;
    }
    .vh-alert__close:hover{ opacity:1; background:#f4f1ff; }
    .vh-alert__bar{
      position:absolute; left:8px; right:8px; bottom:6px; height:3px; border-radius:3px;
      background:rgba(101,50,201,.12); overflow:hidden;
    }
    .vh-alert__bar::after{
      content:""; display:block; width:100%; height:100%; transform-origin:left center; transform:scaleX(1);
      background:linear-gradient(90deg,#7c3aed,#6c2bd9,#5b21b6);
      animation:vh-countdown linear forwards;
    }
    .vh-alert.vh-error{ --ring:#ffd7df; }
    .vh-alert.vh-error .vh-alert__icon{ background:linear-gradient(180deg,#fff4f6,#ffe9ed); border-color:#ffd7df; }
    .vh-alert.vh-error .vh-alert__icon ion-icon{ color:#b42318; }
    .vh-alert.vh-warning{ --ring:#ffe6bf; }
    .vh-alert.vh-warning .vh-alert__icon{ background:linear-gradient(180deg,#fff8ec,#fff1d6); border-color:#ffe6bf; }
    .vh-alert.vh-warning .vh-alert__icon ion-icon{ color:#b45309; }
    .vh-alert.vh-info{ --ring:#d8e7ff; }
    .vh-alert.vh-info .vh-alert__icon{ background:linear-gradient(180deg,#f2f7ff,#e6f0ff); border-color:#d8e7ff; }
    .vh-alert.vh-info .vh-alert__icon ion-icon{ color:#1d4ed8; }
    @keyframes vh-slide-in{ from{opacity:0;transform:translateY(-6px) scale(.98);filter:blur(3px);} to{opacity:1;transform:translateY(0) scale(1);filter:blur(0);} }
    @keyframes vh-countdown{ from{transform:scaleX(1);} to{transform:scaleX(0);} }
  </style>
</head>
<body>
  <header class="brandbar"><div class="brand">ViaHale</div></header>

  <div class="wrap">
    <h1 class="title-xl">Welcome to Viahale!</h1>
    <p class="subtitle">Please enter your credentials to access the dashboard.</p>

    <div class="login-card">
      <?php if ($vhNotice): ?>
        <div class="vh-alert vh-<?= htmlspecialchars($vhNotice['kind']) ?>" role="alert" aria-live="assertive"
             data-autoclose="<?= $vhNotice['kind']==='warning' ? '0' : '6000' ?>">
          <div class="vh-alert__icon">
            <?php if ($vhNotice['kind']==='warning'): ?>
              <ion-icon name="warning-outline"></ion-icon>
            <?php elseif ($vhNotice['kind']==='info'): ?>
              <ion-icon name="information-circle-outline"></ion-icon>
            <?php else: ?>
              <ion-icon name="alert-circle-outline"></ion-icon>
            <?php endif; ?>
          </div>
          <div class="vh-alert__body">
            <div class="vh-alert__title"><?= htmlspecialchars($vhNotice['title']) ?></div>
            <div class="vh-alert__msg"><?= htmlspecialchars($vhNotice['msg']) ?></div>
          </div>
          <button class="vh-alert__close" type="button" aria-label="Dismiss">
            <ion-icon name="close-outline"></ion-icon>
          </button>
          <div class="vh-alert__bar"></div>
        </div>
      <?php endif; ?>

      <form method="post" action="auth/login_process.php">
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" id="email" name="email" class="form-control" placeholder="Enter your email" required>
        </div>

        <div class="mb-3 input-icon">
          <label class="form-label">Password</label>
          <input type="password" name="password" id="password" class="form-control" placeholder="••••••••" required>
          <button type="button" id="togglePass" aria-label="Show/Hide password">
            <ion-icon name="eye-outline" id="eyeOpen"></ion-icon>
          </button>
        </div>

        <button class="btn-login" type="submit">LOGIN <span class="ms-1">►</span></button>

        <a class="btn btn-outline-light w-100 mt-2"
           href="<?= rtrim(BASE_URL,'/') ?>/landing_page.php">
          <ion-icon name="person-add-outline"></ion-icon>
          REGISTER AS VENDOR
        </a>

        <p class="mt-3 mb-0" style="font-size:.8rem;opacity:.9">
          By continuing, you agree to our
          <a href="#" class="text-white text-decoration-underline" data-bs-toggle="modal" data-bs-target="#legalModal">Privacy Policy & Terms</a>.
        </p>
      </form>
    </div>
  </div>

  <!-- Replace your old footer with the shared legal footer -->
  <?php include __DIR__ . '/includes/legal_footer.php'; ?>

  <!-- Bootstrap JS (required for modal) -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <!-- Drop-in modal with tabs (Overview + all 5 modules + Terms) -->
  <?php include __DIR__ . '/includes/policy_modal.php'; ?>

  <script>
    const btn = document.getElementById('togglePass');
    const input = document.getElementById('password');
    const icon = document.getElementById('eyeOpen');
    btn?.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      icon.setAttribute('name', show ? 'eye-off-outline' : 'eye-outline');
    });

    (function(){
      const n = document.querySelector('.vh-alert');
      if(!n) return;
      const closeBtn = n.querySelector('.vh-alert__close');
      closeBtn?.addEventListener('click', () => n.remove());
      const auto = parseInt(n.dataset.autoclose || '0', 10);
      if (auto > 0) {
        const styleTag = document.createElement('style');
        styleTag.textContent = `.vh-alert__bar::after{animation-duration:${auto/1000}s}`;
        document.head.appendChild(styleTag);
        setTimeout(()=> n.remove(), auto);
      } else {
        const styleTag = document.createElement('style');
        styleTag.textContent = `.vh-alert__bar::after{animation: none}`;
        document.head.appendChild(styleTag);
      }
    })();
  </script>
</body>
</html>
