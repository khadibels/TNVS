<?php
require_once __DIR__ . "/includes/config.php";
require_once __DIR__ . "/includes/auth.php";
if (!empty($_SESSION["user"])) {
    header("Location: " . BASE_URL . "/warehousing/warehouseDashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Log in | ViaHale</title>


  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;700&family=Quicksand:wght@500;700&display=swap" rel="stylesheet">


  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>


  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

  <style>
   :root {
  --vh-purple: #6c2bd9;
  --vh-purple-2: #5b21b6;
  --vh-purple-3: #7c3aed;
  --vh-text: #1b1b1b;
}
html,
body {
  height: 100%;
}
body {
  font-family: Poppins, system-ui, -apple-system, "Segoe UI", Roboto,
    "Helvetica Neue", Arial, "Apple Color Emoji", "Segoe UI Emoji";
  color: var(--vh-text);
  background: radial-gradient(
      40rem 40rem at -10% -10%,
      #ede9fe 0%,
      transparent 60%
    ),
    radial-gradient(50rem 50rem at 110% 0%, #f5f3ff 0%, transparent 55%),
    #ffffff;
}
.wrap {
  min-height: 100%;
  display: grid;
  place-items: center;
  padding: 3rem 1rem;
}
.title-xl {
  font-family: Quicksand, Poppins, sans-serif;
  font-weight: 500;
  font-size: clamp(1.5rem, 0.75rem + 2vw, 3rem);
  line-height: 1.1;
  text-align: center;
  margin-bottom: 0.25rem;
}
.subtitle {
  text-align: center;
  color: #6b7280;
  margin-bottom: 2rem;
}
.login-card {
  width: min(640px, 92vw);
  background: linear-gradient(
    180deg,
    var(--vh-purple-3),
    var(--vh-purple-2) 55%,
    var(--vh-purple) 100%
  );
  color: #fff;
  border-radius: 1.25rem;
  padding: 2rem;
  box-shadow: 0 30px 60px rgba(92, 44, 182, 0.25),
    0 6px 18px rgba(92, 44, 182, 0.15);
}
.login-card h3 {
  font-weight: 500;
  margin-bottom: 1.25rem;
}
.form-label {
  font-weight: 500;
  color: #e9e9ff;
}
.form-control {
  background-color: rgba(255, 255, 255, 0.16);
  color: #fff;
  border: 1px solid rgba(255, 255, 255, 0.25);
  padding: 0.85rem 1rem;
  border-radius: 0.75rem;
}
.form-control::placeholder {
  color: #e6e6ff;
  opacity: 0.6;
}
.form-control:focus {
  background-color: rgba(255, 255, 255, 0.22);
  color: #fff;
  border-color: #fff;
  box-shadow: 0 0 0 0.2rem rgba(255, 255, 255, 0.15);
}
.input-icon {
  position: relative;
}
.input-icon button {
  position: absolute;
  right: 0.6rem;
  top: 70%;
  transform: translateY(-50%);
  border: 0;
  background: transparent;
  color: #fff;
  opacity: 0.85;
  width: 36px;
  height: 36px;
  display: grid;
  place-items: center;
  border-radius: 0.5rem;
}
.input-icon button:hover {
  opacity: 1;
}
.btn-login {
  width: 100%;
  padding: 0.95rem 1.25rem;
  border-radius: 0.75rem;
  border: 0;
  background: #fff;
  color: var(--vh-purple-2);
  font-weight: 600;
  letter-spacing: 0.02em;
  transition: transform 0.05s ease, box-shadow 0.2s ease;
}
.btn-login:hover {
  transform: translateY(-1px);
  box-shadow: 0 10px 18px rgba(0, 0, 0, 0.12);
}
.links {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.75rem;
  margin-top: 0.75rem;
}
.links a {
  color: #ffe36e;
  text-decoration: none;
  font-weight: 400;
}
.links a:hover {
  text-decoration: underline;
}
.foot-note {
  text-align: center;
  margin-top: 1rem;
  color: #d8cffc;
}

.login-card::after {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: inherit;
  pointer-events: none;
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
}
.card-shell {
  position: relative;
}


    
  </style>
</head>
<body>
  <div class="wrap">
    <div>
      <h1 class="title-xl">Welcome back, Admin!</h1>
      <p class="subtitle">Please enter your credentials to access the dashboard.</p>

      <div class="card-shell">
        <div class="login-card">
          <h3>Log in to ViaHale</h3>

          <?php if (!empty($_GET["err"])): ?>
            <div class="alert alert-danger py-2 px-3 mb-3">
              <?= htmlspecialchars($_GET["err"]) ?>
            </div>
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

            <div class="links">
              <a href="/auth/forgot.php">Forgot password?</a>
              <div class="text-white-50">Don’t have an account? <a href="/auth/signup.php">Sign up</a></div>
            </div>
          </form>
        </div>
      </div>

      <div class="foot-note small">© <?= date("Y") ?> ViaHale · TNVS</div>
    </div>
  </div>

  <script>
    // password show/hide
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