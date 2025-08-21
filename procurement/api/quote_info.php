<?php
// procurement/api/quote_info.php
declare(strict_types=1);
require_once __DIR__.'/../../includes/config.php';
// NO auth here (public link for suppliers)
header('Content-Type: application/json; charset=utf-8');

try {
  $token = trim($_GET['token'] ?? '');
  if ($token === '') throw new Exception('Missing token');

  // recipient (token) -> rfq + supplier
  $sql = "SELECT rr.rfq_id, rr.supplier_id, rr.token, rr.sent_at,
                 r.rfq_no, r.title, r.due_date, r.notes AS rfq_notes,
                 s.code AS supplier_code, s.name AS supplier_name, s.email AS supplier_email
          FROM rfq_recipients rr
          JOIN rfqs r      ON r.id = rr.rfq_id
          JOIN suppliers s ON s.id = rr.supplier_id
          WHERE rr.token = ?";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$token]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new Exception('Invalid token');

  // latest quote (per supplier per rfq)
  $sqlQ = "SELECT id, total_amount, lead_time_days, notes, is_final, submitted_at
           FROM quotes
           WHERE rfq_id = ? AND supplier_id = ?
           ORDER BY id DESC LIMIT 1";
  $stmt = $pdo->prepare($sqlQ);
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
