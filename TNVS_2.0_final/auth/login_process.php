<?php
require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$email = trim($_POST['email'] ?? '');
$pass  = $_POST['password'] ?? '';

if ($email === '' || $pass === '') {
  header('Location: /login.php?err=' . urlencode('Email and password are required')); exit;
}

$stmt = $pdo->prepare("SELECT id, name, email, password_hash, role FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($pass, $user['password_hash'])) {
  header('Location: /login.php?err=' . urlencode('Invalid credentials')); exit;
}

$_SESSION['user'] = [
  'id'    => $user['id'],
  'name'  => $user['name'],
  'email' => $user['email'],
  'role'  => $user['role'],
];
// regenerate session id for security
session_regenerate_id(true);

header('Location: ' . BASE_URL . '/warehousing/warehouseDashboard.php'); 
exit;


