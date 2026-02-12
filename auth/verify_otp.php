<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_otp.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user'])) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/login.php');
    exit;
}

$pending = $_SESSION['otp_pending'] ?? null;
if (!$pending || empty($pending['user_id']) || empty($pending['otp_id'])) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/login.php?err=' . urlencode('OTP session expired. Please log in again.'));
    exit;
}

$pdo = db('auth');
$error = '';
$message = '';
$issuedAt = (int)($pending['issued_at'] ?? time());
$remainingSeconds = max(0, 600 - (time() - $issuedAt));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? 'verify'));

    if ($action === 'cancel') {
        unset($_SESSION['otp_pending']);
        header('Location: ' . rtrim(BASE_URL, '/') . '/login.php?err=' . urlencode('OTP login canceled.'));
        exit;
    }

    if ($action === 'resend') {
        $otp = otp_create($pdo, (int)$pending['user_id'], (string)$pending['email'], (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        $sent = otp_send_email((string)$pending['email'], (string)$pending['name'], (string)$otp['code']);
        if (!$sent) {
            $error = 'Failed to resend OTP. Please try again.';
        } else {
            $_SESSION['otp_pending']['otp_id'] = (int)$otp['id'];
            $_SESSION['otp_pending']['issued_at'] = time();
            $pending = $_SESSION['otp_pending'];
            $issuedAt = (int)$pending['issued_at'];
            $remainingSeconds = max(0, 600 - (time() - $issuedAt));
            $message = 'A new OTP was sent to your email.';
        }
    } else {
        $code = preg_replace('/\D+/', '', (string)($_POST['otp_code'] ?? ''));
        if ($code === '' || strlen($code) !== 6) {
            $error = 'Enter the 6-digit OTP code.';
        } else {
            [$ok, $msg] = otp_verify($pdo, (int)$pending['otp_id'], (int)$pending['user_id'], $code);
            if (!$ok) {
                $error = $msg;
            } else {
                $_SESSION['user'] = [
                    'id'            => (int)$pending['user_id'],
                    'name'          => (string)$pending['name'],
                    'email'         => (string)$pending['email'],
                    'role'          => (string)$pending['role'],
                    'vendor_id'     => isset($pending['vendor_id']) ? (int)$pending['vendor_id'] : null,
                    'vendor_status' => (string)($pending['vendor_status'] ?? ''),
                ];
                unset($_SESSION['otp_pending']);
                header('Location: ' . rtrim(BASE_URL, '/') . '/login.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verify OTP | ViaHale</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --vh-1: #6d39df;
      --vh-2: #5330c4;
      --vh-ink: #2f245b;
      --vh-muted: #5f5487;
      --vh-line: #e7ddff;
      --vh-bg: #f7f4ff;
    }
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 24px;
      font-family: Poppins, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
      background:
        radial-gradient(40rem 30rem at 8% -10%, #efe9ff 0%, transparent 58%),
        radial-gradient(38rem 30rem at 95% 5%, #f8f5ff 0%, transparent 52%),
        #ffffff;
      color: var(--vh-ink);
    }
    .card {
      width: min(460px, 100%);
      background: #fff;
      border: 1px solid var(--vh-line);
      border-radius: 18px;
      box-shadow: 0 18px 42px rgba(93, 52, 168, 0.14);
      overflow: hidden;
    }
    .head {
      background: linear-gradient(120deg, var(--vh-1), var(--vh-2));
      color: #fff;
      padding: 18px 20px;
    }
    .head h1 {
      margin: 0;
      font-size: 1.35rem;
      letter-spacing: .2px;
    }
    .head p {
      margin: 6px 0 0;
      color: rgba(255,255,255,.9);
      font-size: 0.9rem;
    }
    .body {
      padding: 18px 20px 20px;
    }
    p { margin: 0 0 12px; color: var(--vh-muted); font-size: 0.93rem; }
    .email {
      font-size: 0.86rem;
      background: var(--vh-bg);
      border: 1px solid var(--vh-line);
      border-radius: 10px;
      padding: 8px 10px;
      margin-bottom: 12px;
    }
    .meta {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
      margin-bottom: 10px;
      font-size: 0.84rem;
      color: #6b5fa0;
    }
    .timer {
      background: #f2edff;
      border: 1px solid #dfd4ff;
      border-radius: 999px;
      padding: 4px 10px;
      font-weight: 600;
    }
    input[type="text"] {
      width: 100%;
      box-sizing: border-box;
      border: 1px solid #c9b8f6;
      border-radius: 12px;
      padding: 13px 14px;
      font-size: 1.2rem;
      letter-spacing: 0.45rem;
      font-weight: 700;
      text-align: center;
      margin-bottom: 12px;
      color: #3f2a96;
      background: #fff;
    }
    input[type="text"]:focus {
      outline: 0;
      border-color: #8a67eb;
      box-shadow: 0 0 0 3px rgba(109,57,223,.13);
    }
    .msg, .err {
      border-radius: 10px;
      padding: 10px 12px;
      margin: 0 0 12px;
      font-size: 0.9rem;
    }
    .msg { background: #eefaf0; color: #1f6b2e; border: 1px solid #c6eccc; }
    .err { background: #fff0f2; color: #9c1b2f; border: 1px solid #ffd0d9; }
    .row { display: flex; gap: 10px; margin-top: 2px; }
    button {
      border-radius: 11px;
      border: 1px solid #cdbdf8;
      padding: 10px 12px;
      font-weight: 600;
      cursor: pointer;
      background: #fff;
      color: #4f35ad;
    }
    .primary {
      background: linear-gradient(120deg, #6d39df, #5430c6);
      border-color: #5d35cc;
      color: #fff;
      flex: 1;
    }
    .ghost { flex: 1; }
    .cancel { margin-top: 10px; width: 100%; }
  </style>
</head>
<body>
  <div class="card">
    <div class="head">
      <h1>Admin OTP Verification</h1>
      <p>Secure sign-in confirmation</p>
    </div>
    <div class="body">
      <p>Enter the 6-digit code sent to your email. The code is valid for 10 minutes.</p>
      <div class="email">Email: <?= htmlspecialchars((string)$pending['email']) ?></div>
      <div class="meta">
        <span>One-time code</span>
        <span class="timer">Expires in <span id="otpTimer">10:00</span></span>
      </div>

      <?php if ($message !== ''): ?><div class="msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <form method="post">
        <input type="hidden" name="action" value="verify">
        <input type="text" name="otp_code" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" required>
        <div class="row">
          <button class="primary" type="submit">Verify OTP</button>
        </div>
      </form>

      <form method="post" style="margin-top:10px;">
        <input type="hidden" name="action" value="resend">
        <button class="ghost" type="submit" style="width:100%;">Resend OTP</button>
      </form>

      <form method="post">
        <input type="hidden" name="action" value="cancel">
        <button class="cancel" type="submit">Back to Login</button>
      </form>
    </div>
  </div>
  <script>
    (function () {
      let remain = <?= (int)$remainingSeconds ?>;
      const timerEl = document.getElementById('otpTimer');
      if (!timerEl) return;
      const draw = () => {
        if (remain < 0) remain = 0;
        const m = Math.floor(remain / 60);
        const s = remain % 60;
        timerEl.textContent = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
      };
      draw();
      setInterval(() => { remain -= 1; draw(); }, 1000);
    })();
  </script>
</body>
</html>
