<?php
require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$email = trim($_POST['email'] ?? '');
$pass  = $_POST['password'] ?? '';

if ($email === '' || $pass === '') {
  header('Location: ' . BASE_URL . '/login.php?err=' . urlencode('Email and password are required'));
  exit;
}

$stmt = $pdo->prepare("SELECT id, name, email, password_hash, role FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($pass, $user['password_hash'])) {
  header('Location: ' . BASE_URL . '/login.php?err=' . urlencode('Invalid credentials'));
  exit;
}

$_SESSION['user'] = [
  'id'    => $user['id'],
  'name'  => $user['name'],
  'email' => $user['email'],
  'role'  => $user['role'],
];

$role = strtolower($user['role'] ?? '');

<<<<<<< HEAD
switch ($role) {
  case 'admin':
    $dest = BASE_URL . 'all-modules-admin-access/Dashboard.php';
    break;

  case 'manager':
    $dest = BASE_URL . 'warehousing/warehouseDashboard.php';
    break;

  default:
    // fallback if role not recognized
    $dest = BASE_URL . 'login.php?err=' . urlencode('Unauthorized role');
    break;
}
=======
// role → destination map
$map = [
  'admin'               => '../all-modules-admin-access/Dashboard.php',
  'manager'             => '../warehousing/warehouseDashboard.php',
  'warehouse_staff'     => '../warehousing/warehouseDashboard.php',
  'procurement_officer' => '../procurement/procurementDashboard.php',
  'asset_manager'       => '../assetlifecycle/ALMS.php',                   
  'document_controller' => '../documentTracking/dashboard.php',                  
  'project_lead'        => '../PLT/projectTracking.php',              
];

$dest = BASE_URL . ($map[$role] ?? 'login.php?err=' . urlencode('Unauthorized role'));
>>>>>>> origin/main

header('Location: ' . $dest);
exit;
