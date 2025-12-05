<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/vendor_notifications.php';

header('Content-Type: application/json');

require_login();
require_role(['admin','vendor_manager']);

$proc = db('proc');
$auth = db('auth');

$id     = (int)($_POST['id'] ?? 0);
$action = trim($_POST['action'] ?? '');
$reason = trim($_POST['reason'] ?? '');

if ($id <= 0 || !in_array($action, ['approve','reject'], true)) {
  http_response_code(400); echo json_encode(['error'=>'Invalid request']); exit;
}

$newStatus = ($action === 'approve') ? 'approved' : 'rejected';

$proc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// helper
$colExists = function(PDO $pdo,string $table,string $col): bool {
  $q=$pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($col));
  return (bool)$q->fetch(PDO::FETCH_ASSOC);
};

try {
  $proc->beginTransaction();

  // Fetch full vendor info for email
  $st = $proc->prepare("SELECT * FROM vendors WHERE id = ? FOR UPDATE");
  $st->execute([$id]);
  $vendor = $st->fetch(PDO::FETCH_ASSOC);

  if (!$vendor) {
    $proc->rollBack();
    http_response_code(404);
    echo json_encode(['error'=>'Not found']);
    exit;
  }

  // Build update query
  $set = ["status = :s"];
  $params = [':s'=>$newStatus, ':id'=>$id];

  if ($colExists($proc,'vendors','review_note')) {
    $set[] = "review_note = :n";
    $params[':n'] = ($reason !== '' ? $reason : null);
    $vendor['review_note'] = $params[':n']; // reflect change for email
  }
  if ($colExists($proc,'vendors','reviewed_at')) {
    $set[] = "reviewed_at = CURRENT_TIMESTAMP";
  }

  $sql = "UPDATE vendors SET ".implode(', ',$set)." WHERE id = :id";
  $u = $proc->prepare($sql);
  $u->execute($params);

  // Update users table
  $ua = $auth->prepare("UPDATE users SET vendor_status = :vs WHERE vendor_id = :vid OR email = :em");
  $ua->execute([':vs'=>$newStatus, ':vid'=>$id, ':em'=>$vendor['email']]);

  $proc->commit();

  // Update vendor array for email
  $vendor['status'] = $newStatus;

  // ---- SEND EMAIL NOTIFICATION ---- //
  sendVendorStatusEmail($vendor, $newStatus);

  echo json_encode(['ok'=>true,'message'=>"Vendor {$newStatus}. Email sent."]);

} catch (Throwable $e) {
  if ($proc->inTransaction()) $proc->rollBack();
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
}
