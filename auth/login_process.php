<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rate_limit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . rtrim(BASE_URL,'/') . '/login.php');
    exit;
}

$pdo   = db('auth');
$email = trim($_POST['email'] ?? '');
$pass  = $_POST['password'] ?? '';
$ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($email === '' || $pass === '') {
    header('Location: ' . rtrim(BASE_URL,'/') . '/login.php?err=' . urlencode('Please enter your email and password.'));
    exit;
}

[$blocked, $minsLeft, $fails] = login_blocked($pdo, $email, $ip, 5, 15*60);
if ($blocked) {
    $msg = "Too many attempts. Try again in about {$minsLeft} minutes.";
    header('Location: ' . rtrim(BASE_URL,'/') . '/login.php?err=' . urlencode($msg));
    exit;
}

$st = $pdo->prepare("
    SELECT id, name, email, role, vendor_id, vendor_status, password_hash
    FROM users
    WHERE email = ?
");
$st->execute([$email]);
$user = $st->fetch(PDO::FETCH_ASSOC);

$ok = $user && password_verify($pass, (string)($user['password_hash'] ?? ''));

record_login_attempt($pdo, $email, $ip, $ok);

if (!$ok) {
    [, , $nowFails] = login_blocked($pdo, $email, $ip, 5, 15*60);
    $remaining = max(0, 5 - $nowFails);
    $msg = $remaining > 0
        ? "Incorrect email or password. Attempts left: {$remaining}."
        : "Too many attempts. Your account is locked for 15 minutes.";
    header('Location: ' . rtrim(BASE_URL,'/') . '/login.php?err=' . urlencode($msg));
    exit;
}

clear_attempts($pdo, $email, $ip);

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user'] = [
    'id'            => (int)$user['id'],
    'name'          => (string)($user['name'] ?? ''),
    'email'         => (string)($user['email'] ?? ''),
    'role'          => (string)($user['role'] ?? ''),
    'vendor_id'     => isset($user['vendor_id']) ? (int)$user['vendor_id'] : null,
    'vendor_status' => (string)($user['vendor_status'] ?? ''),
];

header('Location: ' . rtrim(BASE_URL,'/') . '/login.php');
exit;
