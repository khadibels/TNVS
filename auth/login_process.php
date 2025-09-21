<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/redirect.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$auth = db('auth');
$proc = db('proc');

$email = trim($_POST['email'] ?? '');
$pass  = $_POST['password'] ?? '';

$fail = function(string $msg){
  redirect_to('login.php?err=' . urlencode($msg));
};

if ($email === '' || $pass === '') $fail('Email and password are required');

$stmt = $auth->prepare("SELECT id, name, email, password_hash, role FROM users WHERE email = ?");
$stmt->execute([$email]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if ($u && password_verify($pass, $u['password_hash'])) {
    $role = strtolower(trim($u['role'] ?? ''));
    $aliases = [
        'proc_officer'      => 'procurement_officer',
        'warehouse_mgr'     => 'manager',
        'warehouse_manager' => 'manager',
    ];
    $role = $aliases[$role] ?? $role;

    $_SESSION['user'] = [
        'id'            => (int)$u['id'],
        'name'          => $u['name'],
        'email'         => $u['email'],
        'role'          => $role,
        'vendor_id'     => null,
        'vendor_status' => null,
        'company_name'  => null,
    ];

    $map = [
        'admin'               => 'all-modules-admin-access/Dashboard.php',
        'manager'             => 'warehousing/warehouseDashboard.php',
        'warehouse_staff'     => 'warehousing/warehouseDashboard.php',
        'procurement_officer' => 'procurement/procurementDashboard.php',
        'asset_manager'       => 'assetlifecycle/ALMS.php',
        'document_controller' => 'documentTracking/dashboard.php',
        'project_lead'        => 'PLT/pltDashboard.php',
    ];
    $dest = $map[$role] ?? 'login.php?err=' . urlencode('Unauthorized role');
    redirect_to($dest);
}

$v = $proc->prepare("SELECT id, company_name, contact_person, email, password, status FROM vendors WHERE email = ? LIMIT 1");
$v->execute([$email]);
$vend = $v->fetch(PDO::FETCH_ASSOC);

if (!$vend || !password_verify($pass, $vend['password'])) {
    $fail('Invalid credentials');
}

$status = strtolower(trim($vend['status'] ?? 'pending'));

$_SESSION['user'] = [
    'id'            => null,
    'name'          => $vend['contact_person'] ?: ($vend['company_name'] ?: $vend['email']),
    'email'         => $vend['email'],
    'role'          => 'vendor',
    'vendor_id'     => (int)$vend['id'],
    'vendor_status' => $status,
    'company_name'  => $vend['company_name'] ?? null,
];

$dest = $status === 'approved'
    ? 'vendor_portal/vendor/dashboard.php'
    : 'vendor_portal/vendor/pending.php';

redirect_to($dest);
