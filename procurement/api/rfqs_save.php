<?php
// procurement/api/rfqs_save.php
declare(strict_types=1);
$inc = __DIR__ . '/../../includes';
require_once $inc . '/config.php';
if (file_exists($inc . '/auth.php')) require_once $inc . '/auth.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=utf-8');

function day8(): string { return date('Ymd'); }
function build_no(string $day, int $n): string {
  return 'RFQ-' . $day . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/**
 * Atomically get the next sequence for the given day.
 * Seeds the counter to >= current max suffix found in rfqs for that day, then increments atomically.
 */
function next_seq_atomic(PDO $pdo, string $day): int {
  $pdo->beginTransaction();
  try {
    // Get existing max numeric suffix for this day from rfqs (e.g., 0007 => 7)
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(rfq_no, 14) AS UNSIGNED)), 0)
                           FROM rfqs
                           WHERE rfq_no LIKE ?");
    $stmt->execute(['RFQ-'.$day.'-%']);
    $maxInRfqs = (int)$stmt->fetchColumn();

    // Ensure a counter row exists and is at least maxInRfqs
    $stmt = $pdo->prepare("INSERT INTO rfq_counters (day, seq) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE seq = GREATEST(seq, VALUES(seq))");
    $stmt->execute([$day, $maxInRfqs]);

    // Atomic increment; LAST_INSERT_ID(seq) trick returns the new seq from this UPDATE
    $stmt = $pdo->prepare("UPDATE rfq_counters SET seq = LAST_INSERT_ID(seq + 1) WHERE day = ?");
    $stmt->execute([$day]);
    $seq = (int)$pdo->lastInsertId();

    $pdo->commit();
    return $seq;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');

  $id      = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
  $title   = trim((string)($_POST['title'] ?? ''));
  $due     = trim((string)($_POST['due_date'] ?? ''));
  $status  = trim((string)($_POST['status'] ?? 'draft'));
  $notes   = trim((string)($_POST['notes'] ?? ''));

  if ($title === '') throw new Exception('Title is required');
  $allowed = ['draft','sent','awarded','closed','cancelled','Draft','Sent','Awarded','Closed','Cancelled'];
  if (!in_array($status, $allowed, true)) throw new Exception('Invalid status');

  $dueSql = $due !== '' ? $due : null;

  if ($id) {
    // UPDATE
    $sql = "UPDATE rfqs SET title=:title, due_date=:due_date, status=:status, notes=:notes WHERE id=:id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':title'=>$title, ':due_date'=>$dueSql, ':status'=>$status, ':notes'=>$notes, ':id'=>$id]);

    $stmt = $pdo->prepare("SELECT rfq_no FROM rfqs WHERE id=:id");
    $stmt->execute([':id'=>$id]);
    $rfq_no = (string)$stmt->fetchColumn();
  } else {
    // INSERT — get a collision-proof sequence and build rfq_no
    $day = day8();
    $seq = next_seq_atomic($pdo, $day);
    $rfq_no = build_no($day, $seq);

    $sql = "INSERT INTO rfqs (rfq_no, title, due_date, status, notes)
            VALUES (:rfq_no, :title, :due_date, :status, :notes)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':rfq_no'=>$rfq_no, ':title'=>$title, ':due_date'=>$dueSql, ':status'=>$status, ':notes'=>$notes
    ]);
    $id = (int)$pdo->lastInsertId();
  }

  // ----- invited suppliers upsert -----
  $invited_count = 0;
  if (isset($_POST['suppliers'])) {
    $sel = $_POST['suppliers'];
    if (!is_array($sel)) $sel = [];
    $sel = array_values(array_unique(array_map('intval', $sel)));

    $emails = [];
    if ($sel) {
      $in = implode(',', array_fill(0, count($sel), '?'));
      $st = $pdo->prepare("SELECT id, COALESCE(email,'') AS email FROM suppliers WHERE id IN ($in)");
      $st->execute($sel);
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) $emails[(int)$r['id']] = $r['email'];
    }

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM rfq_recipients WHERE rfq_id=?")->execute([$id]);

    if ($sel) {
      $ins = $pdo->prepare("INSERT INTO rfq_recipients (rfq_id, supplier_id, email, token_expires_at)
                            VALUES (?,?,?,?)");
      $expires = $dueSql ?: date('Y-m-d H:i:s', time()+14*86400);
      foreach ($sel as $sid) {
        $ins->execute([$id, $sid, $emails[$sid] ?? '', $expires]);
      }
      $invited_count = count($sel);
    }
    $pdo->commit();
  }

  echo json_encode(['ok'=>true, 'id'=>$id, 'rfq_no'=>$rfq_no, 'invited_count'=>$invited_count]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
