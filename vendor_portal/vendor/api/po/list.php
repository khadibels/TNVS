<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/db.php';
require_once __DIR__ . '/../../../../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

try {
  require_login();
  $u = current_user(); $vendorId = (int)($u['vendor_id'] ?? 0);
  if ($vendorId <= 0) throw new Exception('No vendor');

  $pdo = db('proc'); if(!$pdo instanceof PDO) throw new Exception('DB');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $page = max(1, (int)($_GET['page'] ?? 1));
  $per  = min(100, max(1,(int)($_GET['per'] ?? 10)));
  $search = trim((string)($_GET['search'] ?? ''));
  $status = trim((string)($_GET['status'] ?? ''));

  $w = ["p.vendor_id=?","p.status IN ('issued','acknowledged','closed','cancelled')"];
  $p = [$vendorId];
  if ($search !== '') { $w[]="(p.po_no LIKE ? OR r.rfq_no LIKE ? OR r.title LIKE ?)"; $like="%$search%"; array_push($p,$like,$like,$like); }
  if ($status !== '') { $w[]="p.vendor_ack_status=?"; $p[]=$status; }
  $where = 'WHERE '.implode(' AND ',$w);

  $cnt = $pdo->prepare("SELECT COUNT(*) FROM pos p LEFT JOIN rfqs r ON r.id=p.rfq_id $where");
  $cnt->execute($p); $total=(int)$cnt->fetchColumn();
  $off = ($page-1)*$per;

  $st = $pdo->prepare("
    SELECT p.id, p.po_no, p.currency,
           COALESCE(NULLIF(p.total,0), it.items_total, p.total, 0) AS total,
           p.issued_at, p.vendor_ack_status,
           r.rfq_no, r.title
    FROM pos p
    LEFT JOIN rfqs r ON r.id=p.rfq_id
    LEFT JOIN (
      SELECT po_id, SUM(line_total) AS items_total
      FROM po_items
      GROUP BY po_id
    ) it ON it.po_id = p.id
    $where
    ORDER BY p.id DESC
    LIMIT $per OFFSET $off
  ");
  $st->execute($p);
  echo json_encode(['data'=>$st->fetchAll(PDO::FETCH_ASSOC),'pagination'=>['page'=>$page,'per'=>$per,'total'=>$total]]);
} catch (Throwable $e) {
  http_response_code(400); echo json_encode(['error'=>$e->getMessage()]);
}
