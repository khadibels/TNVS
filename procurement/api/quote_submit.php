<?php
// procurement/api/quote_submit.php (public endpoint for suppliers)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

// ---- helpers ----
function bad(string $m, int $code=400){
  http_response_code($code);
  echo json_encode(['error'=>$m]);
  exit;
}
function hasCol(PDO $pdo, string $t, string $c): bool {
  $st=$pdo->prepare("SELECT 1 FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
  $st->execute([$t,$c]); return (bool)$st->fetchColumn();
}

// ---- main ----
try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST required');

  $token = trim($_POST['token'] ?? '');
  if ($token === '') bad('Missing token');

  // detect actual token column (same as quote_info.php)
  $tokCol = hasCol($pdo,'rfq_recipients','invite_token') ? 'invite_token'
          : (hasCol($pdo,'rfq_recipients','token') ? 'token' : null);
  if (!$tokCol) bad('Token column not found on rfq_recipients', 500);

  // look up token
  $sql = "SELECT rr.rfq_id, rr.supplier_id
            FROM rfq_recipients rr
           WHERE rr.$tokCol = ?";
  $st = $pdo->prepare($sql);
  $st->execute([$token]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) bad('Invalid token', 403);

  $rfq_id      = (int)$row['rfq_id'];
  $supplier_id = (int)$row['supplier_id'];

  // read submitted fields
  $total_amount   = isset($_POST['total_amount']) ? (float)$_POST['total_amount'] : null;
  $lead_time_days = isset($_POST['lead_time_days']) ? (int)$_POST['lead_time_days'] : null;
  $notes          = trim($_POST['notes'] ?? '');
  $is_final       = isset($_POST['is_final']) ? (int)!!$_POST['is_final'] : 1; // default final
  $now            = date('Y-m-d H:i:s');

  if ($total_amount === null || $total_amount < 0) bad('total_amount required');
  if ($lead_time_days === null || $lead_time_days < 0) bad('lead_time_days required');

  // quotes table may have different columns; set only those that exist
  $cols = ['rfq_id','supplier_id'];
  $vals = [$rfq_id, $supplier_id];

  if (hasCol($pdo,'quotes','total_amount')) { $cols[]='total_amount'; $vals[]=$total_amount; }
  elseif (hasCol($pdo,'quotes','total'))    { $cols[]='total';        $vals[]=$total_amount; }
  elseif (hasCol($pdo,'quotes','grand_total')) { $cols[]='grand_total'; $vals[]=$total_amount; }
  elseif (hasCol($pdo,'quotes','total_cache')) { $cols[]='total_cache'; $vals[]=$total_amount; }

  if (hasCol($pdo,'quotes','lead_time_days')) { $cols[]='lead_time_days'; $vals[]=$lead_time_days; }
  elseif (hasCol($pdo,'quotes','lead_time'))  { $cols[]='lead_time';      $vals[]=$lead_time_days; }

  if (hasCol($pdo,'quotes','notes')) { $cols[]='notes'; $vals[]=$notes; }
  if (hasCol($pdo,'quotes','is_final')) { $cols[]='is_final'; $vals[]=$is_final; }

  if (hasCol($pdo,'quotes','submitted_at')) { $cols[]='submitted_at'; $vals[]=$now; }
  elseif (hasCol($pdo,'quotes','updated_at')) { $cols[]='updated_at'; $vals[]=$now; }
  elseif (hasCol($pdo,'quotes','created_at')) { $cols[]='created_at'; $vals[]=$now; }

  if (count($cols) < 2) bad('quotes table lacks required columns', 500);

  // insert a new quote (we keep history instead of overwriting)
  $ph = implode(',', array_fill(0, count($cols), '?'));
  $sqlIns = "INSERT INTO quotes (`".implode('`,`', $cols)."`) VALUES ($ph)";
  $pdo->prepare($sqlIns)->execute($vals);
  $qid = (int)$pdo->lastInsertId();

  echo json_encode(['ok'=>true, 'quote_id'=>$qid]);
} catch (Throwable $e) {
  bad('server_error: '.$e->getMessage(), 500);
}
