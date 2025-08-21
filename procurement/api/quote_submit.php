<?php
// procurement/api/quote_submit.php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

function bad($m,$c=400){ http_response_code($c); echo json_encode(['error'=>$m]); exit; }

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST required');

  $token = trim($_POST['token'] ?? '');
  if ($token==='') bad('Missing token');

  // token -> rfq + supplier
  $sql = "SELECT rr.rfq_id, rr.supplier_id, r.due_date
          FROM rfq_recipients rr
          JOIN rfqs r ON r.id = rr.rfq_id
          WHERE rr.token = ?";
  $st = $pdo->prepare($sql);
  $st->execute([$token]);
  $rec = $st->fetch(PDO::FETCH_ASSOC);
  if (!$rec) bad('Invalid token', 404);

  // (optional) block after due date
  // if (!empty($rec['due_date']) && date('Y-m-d') > $rec['due_date']) bad('RFQ is past due', 409);

  // inputs from supplier
  $total = (float)($_POST['total_amount'] ?? $_POST['total'] ?? 0);
  $lead  = (int)($_POST['lead_time_days'] ?? $_POST['lead'] ?? 0);
  $notes = trim($_POST['notes'] ?? '');
  $is_final = (int)($_POST['is_final'] ?? 1);

  if ($total <= 0) bad('Total amount must be > 0');
  if ($lead < 0)  bad('Lead time must be >= 0');

  // insert new quote row (allow multiple submissions; newest wins in lists)
  $now = date('Y-m-d H:i:s');
  $sql = "INSERT INTO quotes (rfq_id, supplier_id, total_amount, lead_time_days, notes, is_final, submitted_at)
          VALUES (?,?,?,?,?,?,?)";
  $pdo->prepare($sql)->execute([
    (int)$rec['rfq_id'],
    (int)$rec['supplier_id'],
    $total, $lead, $notes, $is_final, $now
  ]);

  echo json_encode(['ok'=>true, 'quote_id'=>(int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
  bad('server_error: '.$e->getMessage(), 500);
}
