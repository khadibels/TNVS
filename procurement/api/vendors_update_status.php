<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";

header('Content-Type: application/json');

require_login();
require_role(['admin','procurement_officer']);

$pdo = db('proc');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(['error'=>'DB error: no PDO']); exit; }

// TEMP: surface DB errors while you debug locally
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id     = (int)($_POST['id'] ?? 0);
$action = trim($_POST['action'] ?? '');
$reason = trim($_POST['reason'] ?? '');

if ($id <= 0 || !in_array($action, ['approve','reject'], true)) {
  http_response_code(400);
  echo json_encode(['error'=>'Invalid request']);
  exit;
}

$newStatus = ($action === 'approve') ? 'Approved' : 'Rejected';

/* ==== schema helpers ==== */
function columnExists(PDO $pdo, string $table, string $col): bool {
  $sql = "SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col);
  $q = $pdo->query($sql);
  return (bool)$q->fetch(PDO::FETCH_ASSOC);
}

/** If review_note exists and has a max length, trim to fit to avoid “Data too long” */
function trimToColumnLength(PDO $pdo, string $table, string $col, ?string $val): ?string {
  if ($val === null) return null;
  $stmt = $pdo->prepare("
    SELECT CHARACTER_MAXIMUM_LENGTH AS maxlen
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    LIMIT 1
  ");
  $stmt->execute([':t'=>$table, ':c'=>$col]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row || !$row['maxlen']) return $val; // TEXT types return null; keep as is
  $max = (int)$row['maxlen'];
  return ($max > 0) ? mb_substr($val, 0, $max) : $val;
}

/** If status is ENUM and doesn’t allow our value, this will fail later.
 * We can pre-check to give a clearer error message.
 */
function statusAcceptsValue(PDO $pdo, string $table, string $col, string $val): bool {
  $q = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'status'");
  $c = $q->fetch(PDO::FETCH_ASSOC);
  if (!$c) return true; // column not found here (let update fail with a clear error)
  if (stripos($c['Type'] ?? '', 'enum(') === false) return true; // not enum
  // Extract enum values
  if (preg_match("/^enum\\((.*)\\)$/i", $c['Type'], $m)) {
    $parts = str_getcsv($m[1], ',', "'");
    return in_array($val, $parts, true);
  }
  return true;
}

try {
  // Nice explicit error if enum doesn't include 'Rejected'
  if (!statusAcceptsValue($pdo, 'vendors', 'status', $newStatus)) {
    http_response_code(400);
    echo json_encode([
      'error'  => "The 'status' column enum does not include '{$newStatus}'.",
      'hint'   => "Adjust your schema, e.g.: ALTER TABLE vendors MODIFY status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending';"
    ]);
    exit;
  }

  $hasReviewNote = columnExists($pdo, 'vendors', 'review_note');
  $hasReviewedAt = columnExists($pdo, 'vendors', 'reviewed_at');

  // Trim reason to fit the column length if needed
  if ($hasReviewNote && $reason !== '') {
    $reason = trimToColumnLength($pdo, 'vendors', 'review_note', $reason);
  } elseif (!$hasReviewNote) {
    $reason = null; // do not try to bind if column missing
  }

  // Build dynamic SET
  $set = ["status = :s"];
  $params = [':s'=>$newStatus, ':id'=>$id];

  if ($hasReviewNote) {
    $set[] = "review_note = :note";
    $params[':note'] = ($reason !== '' ? $reason : null);
  }
  if ($hasReviewedAt) {
    // works for TIMESTAMP/DATETIME; database fills value
    $set[] = "reviewed_at = CURRENT_TIMESTAMP";
  }

  $sql = "UPDATE vendors SET " . implode(", ", $set) . " WHERE id = :id";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  echo json_encode(['ok'=>true, 'message'=>"Vendor status set to {$newStatus}."]);
} catch (PDOException $e) {
  // Send back the real DB error so you can see the root cause in the modal
  http_response_code(500);
  echo json_encode([
    'error'  => 'Database error',
    'detail' => $e->getMessage()
  ]);
}
