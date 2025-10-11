<?php
// File: vendor_portal/vendor/api/notifications_list.php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo = db('proc');
if (!$pdo instanceof PDO) { echo json_encode(['error' => 'DB error']); exit; }

$sessionVendorId = (int)($_SESSION['user']['vendor_id'] ?? 0);
// allow ?forceVendor=ID for debugging different accounts
$vendorId = isset($_GET['forceVendor']) ? (int)$_GET['forceVendor'] : $sessionVendorId;

if ($vendorId <= 0) {
  echo json_encode(['data'=>[], 'unread'=>0, 'me_vendor_id'=>$vendorId, 'note'=>'no vendor in session']);
  exit;
}

try {
  $q = $pdo->prepare("
    SELECT id, title, body, rfq_id, is_read, created_at
    FROM vendor_notifications
    WHERE vendor_id = :vid
    ORDER BY id DESC
    LIMIT 200
  ");
  $q->execute([':vid'=>$vendorId]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);

  $u = $pdo->prepare("SELECT COUNT(*) FROM vendor_notifications WHERE vendor_id = :vid AND is_read = 0");
  $u->execute([':vid'=>$vendorId]);
  $unread = (int)$u->fetchColumn();

  $out = [
    'data'         => $rows,
    'unread'       => $unread,
    'me_vendor_id' => $vendorId
  ];

  // Optional: ?peek=1 → show last 10 rows for quick sanity check
  if (!empty($_GET['peek'])) {
    $out['peek'] = $pdo->query("
      SELECT id, vendor_id, rfq_id, title, is_read, created_at
      FROM vendor_notifications
      ORDER BY id DESC
      LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    $out['total'] = (int)$pdo->query("SELECT COUNT(*) FROM vendor_notifications")->fetchColumn();
  }

  echo json_encode($out);
} catch (Throwable $e) {
  echo json_encode(['error'=>$e->getMessage(), 'me_vendor_id'=>$vendorId]);
}
