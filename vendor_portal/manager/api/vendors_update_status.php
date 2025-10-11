<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

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

$newStatus = ($action === 'approve') ? 'approved' : 'rejected'; // <-- lowercase

$proc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$colExists = function(PDO $pdo,string $table,string $col): bool {
  $q=$pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($col));
  return (bool)$q->fetch(PDO::FETCH_ASSOC);
};

try {
  $proc->beginTransaction();

  $st = $proc->prepare("SELECT email FROM vendors WHERE id = ? FOR UPDATE");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { $proc->rollBack(); http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
  $email = $row['email'];

  $set = ["status = :s"];
  $params = [':s'=>$newStatus, ':id'=>$id];

  if ($colExists($proc,'vendors','review_note')) { $set[] = "review_note = :n"; $params[':n'] = ($reason !== '' ? $reason : null); }
  if ($colExists($proc,'vendors','reviewed_at')) { $set[] = "reviewed_at = CURRENT_TIMESTAMP"; }

  $sql = "UPDATE vendors SET ".implode(', ',$set)." WHERE id = :id";
  $u = $proc->prepare($sql); $u->execute($params);

  // users.vendor_status stays lowercase too
  $ua = $auth->prepare("UPDATE users SET vendor_status = :vs WHERE vendor_id = :vid OR email = :em");
  $ua->execute([':vs'=>$newStatus, ':vid'=>$id, ':em'=>$email]);

  $proc->commit();
  echo json_encode(['ok'=>true,'message'=>"Vendor status set to {$newStatus}."]);
} catch (Throwable $e) {
  if ($proc->inTransaction()) $proc->rollBack();
  http_response_code(500); echo json_encode(['error'=>$e->getMessage()]);
}
