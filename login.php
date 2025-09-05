<?php
require_once __DIR__ . "/includes/config.php";
require_once __DIR__ . "/includes/auth.php";

if (!empty($_SESSION["user"])) {
    $role = strtolower($_SESSION['user']['role'] ?? '');

    switch ($role) {
        case 'admin':
            $dest = BASE_URL . 'all-modules-admin-access/Dashboard.php';
            break;
        case 'manager':
            $dest = BASE_URL . 'warehousing/warehouseDashboard.php';
            break;
        default:
            $dest = BASE_URL . 'all-modules-admin-access/Dashboard.php';
            break;
    }
    header('Location: ' . $dest);
    exit();
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
    :root{
      --vh-purple:#6c2bd9;
      --vh-purple-2:#5b21b6;
      --vh-purple-3:#7c3aed;
    }
    body{
      font-family:Poppins,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;
      min-height:100vh;
      margin:0;
      display:flex;
      flex-direction:column;
      background:
        radial-gradient(40rem 40rem at -10% -10%, #ede9fe 0%, transparent 60%),
        radial-gradient(50rem 50rem at 110% 0%, #f5f3ff 0%, transparent 55%),
        #ffffff;
    }

    /* top-left brand */
    .brandbar{
      padding:18px 20px;
    }
    .brand{
      font-weight:700;
      font-size:1.15rem;
      color:var(--vh-purple-2);
      letter-spacing:.2px;
      user-select:none;
    }

    .wrap{
      flex:1;
      display:flex;
      flex-direction:column;
      justify-content:center;
      align-items:center;
      padding:2rem 1rem;
    }
    .title-xl{
      font-weight:600;
      font-size:clamp(1.4rem,1rem + 2vw,2rem);
      text-align:center;
      margin-bottom:.25rem;
      color:var(--vh-purple-2);
    }
    .subtitle{
      text-align:center;
      color:#6b7280;
      margin-bottom:1.5rem;
      font-size:.95rem;
    }

    .login-card{
      width:min(460px,92vw);
      background:linear-gradient(180deg,var(--vh-purple-3),var(--vh-purple-2) 55%,var(--vh-purple) 100%);
      color:#fff;
      border-radius:1rem;
      padding:1.5rem;
      box-shadow:0 20px 40px rgba(92,44,182,.25), 0 4px 12px rgba(92,44,182,.15);
    }
    .login-card h3{
      font-weight:500;
      margin-bottom:1rem;
    }
    .form-label{
      font-weight:500;
      color:#e9e9ff;
      font-size:.85rem;
    }
    .form-control{
      background-color:rgba(255,255,255,.16);
      color:#fff;
      border:1px solid rgba(255,255,255,.25);
      padding:.75rem 1rem;
      border-radius:.65rem;
      font-size:.9rem;
    
    }
    .form-control::placeholder{ color:#e6e6ff; opacity:.6; }
    .form-control:focus{
      background-color:rgba(255, 255, 255, 0.22);
      border-color:#fff;
      box-shadow:0 0 0 .15rem rgba(255,255,255,.15);
      color: #fff;
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

    

    /* footer */
    .footer-bar{
      background:var(--vh-purple-2);
      color:#fff;
      font-size:.8rem;
      padding:1.35rem 1rem;
      display:flex; justify-content:space-between; align-items:center; gap:.75rem;
      flex-wrap:wrap;
    }
    .footer-bar a{ color:#fff; text-decoration:none; }
    .footer-bar a:hover{ text-decoration:underline; }

    @media (max-width:480px){
      .footer-bar{ justify-content:center; text-align:center; }
    }
  </style>
</head>
<body>
  
  <header class="brandbar">
    <div class="brand">ViaHale</div>
  </header>

  <div class="wrap">
    <h1 class="title-xl">Welcome to Viahale!</h1>
    <p class="subtitle">Please enter your credentials to access the dashboard.</p>

    <div class="login-card">
      

      <?php if (!empty($_GET["err"])): ?>
        <div class="alert alert-danger py-2 px-3 mb-3"><?= htmlspecialchars($_GET["err"]) ?></div>
      <?php endif; ?>

      <form method="post" action="auth/login_process.php" novalidate>
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" placeholder="Email" required>
        </div>

        <div class="mb-3 input-icon">
          <label class="form-label">Password</label>
          <input type="password" name="password" id="password" class="form-control" placeholder="••••••••" required>
          <button type="button" id="togglePass" aria-label="Show/Hide password">
            <ion-icon name="eye-outline" id="eyeOpen"></ion-icon>
          </button>
        </div>

        <button class="btn-login" type="submit">LOGIN <span class="ms-1">►</span></button>
      </form>
    </div>
  </div>

  <!-- footer -->
  <div class="footer-bar">
    <div>BCP Capstone &nbsp; | &nbsp; <a href="#">Privacy Policy</a></div>
    <div><a href="#">Need Help?</a></div>
  </div>

  <script>
    const btn = document.getElementById('togglePass');
    const input = document.getElementById('password');
    const icon = document.getElementById('eyeOpen');
    btn?.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      icon.setAttribute('name', show ? 'eye-off-outline' : 'eye-outline');
    });
  </script>
</body>
</html>
