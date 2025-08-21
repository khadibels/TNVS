<?php
// procurement/api/quotes_info.php
declare(strict_types=1);
require_once __DIR__.'/../../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

function col_exists(PDO $pdo, string $t, string $c): bool {
  $st=$pdo->prepare("SELECT 1 FROM information_schema.columns
                     WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
  $st->execute([$t,$c]); return (bool)$st->fetchColumn();
}

try {
  $token = trim($_GET['token'] ?? '');
  if ($token === '') throw new Exception('Missing token');

  // decide which column your DB has
  $tokCol = col_exists($pdo,'rfq_recipients','token') ? 'token'
           : (col_exists($pdo,'rfq_recipients','invite_token') ? 'invite_token' : null);
  if (!$tokCol) throw new Exception('No token column on rfq_recipients');

  $sql = "SELECT rr.rfq_id, rr.supplier_id, rr.$tokCol AS token, rr.sent_at,
                 r.rfq_no, r.title, r.due_date, r.notes AS rfq_notes,
                 s.code AS supplier_code, s.name AS supplier_name, s.email AS supplier_email
          FROM rfq_recipients rr
          JOIN rfqs r      ON r.id = rr.rfq_id
          JOIN suppliers s ON s.id = rr.supplier_id
          WHERE rr.$tokCol = ?";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$token]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new Exception('Invalid token');

  // latest quote
  $stmt = $pdo->prepare("
      SELECT id, total_amount, lead_time_days, notes, is_final, submitted_at
      FROM quotes
      WHERE rfq_id = ? AND supplier_id = ?
      ORDER BY id DESC LIMIT 1
  ");
  $stmt->execute([$row['rfq_id'], $row['supplier_id']]);
  $q = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

  echo json_encode([
    'rfq' => [
      'id' => (int)$row['rfq_id'],
      'rfq_no' => $row['rfq_no'],
      'title' => $row['title'],
      'due_date' => $row['due_date'],
      'notes' => $row['rfq_notes'],
    ],
    'supplier' => [
      'id' => (int)$row['supplier_id'],
      'code' => $row['supplier_code'],
      'name' => $row['supplier_name'],
      'email'=> $row['supplier_email'],
    ],
    'quote' => $q
  ]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
