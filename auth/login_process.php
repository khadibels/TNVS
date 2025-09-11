<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// use the AUTH database
$pdo = db('auth');

$email = trim($_POST['email'] ?? '');
$pass  = $_POST['password'] ?? '';

if ($email === '' || $pass === '') {
  header('Location: ' . BASE_URL . '/login.php?err=' . urlencode('Email and password are required'));
  exit;
}

$stmt = $pdo->prepare("SELECT id, name, email, password_hash, role FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($pass, $user['password_hash'])) {
  header('Location: ' . BASE_URL . '/login.php?err=' . urlencode('Invalid credentials'));
  exit;
}

/* ---- normalize role + aliases ---- */
$roleRaw = $user['role'] ?? '';
$role    = strtolower(trim($roleRaw));
$aliases = [
  'proc_officer'        => 'procurement_officer',
  'warehouse_mgr'       => 'manager',
  'warehouse_manager'   => 'manager',
];
$role = $aliases[$role] ?? $role;

/* ---- persist session (use normalized role) ---- */
$_SESSION['user'] = [
  'id'    => $user['id'],
  'name'  => $user['name'],
  'email' => $user['email'],
  'role'  => $role,
];

/* ---- role → destination map ---- */
$map = [
  'admin'               => '/all-modules-admin-access/Dashboard.php',
  'manager'             => '/warehousing/warehouseDashboard.php',
  'warehouse_staff'     => '/warehousing/warehouseDashboard.php',
  'procurement_officer' => '/procurement/procurementDashboard.php',
  'asset_manager'       => '/assetlifecycle/ALMS.php',
  'document_controller' => '/documentTracking/dashboard.php',
  'project_lead'        => '/PLT/projectTracking.php',
];

$dest = BASE_URL . ($map[$role] ?? '/login.php?err=' . urlencode('Unauthorized role'));
header('Location: ' . $dest);
exit;
