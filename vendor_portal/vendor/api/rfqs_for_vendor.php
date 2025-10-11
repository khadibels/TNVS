<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

require_login(); require_role(['vendor']);
$pdo = db('proc');

$user = $_SESSION['user'] ?? [];
$vendor_id = (int)($user['vendor_id'] ?? 0);
$vendor_status = strtolower($user['vendor_status'] ?? 'pending');
if ($vendor_id<=0 || $vendor_status!=='approved'){ http_response_code(403); exit('Vendor not approved'); }

$sql = "SELECT r.id, r.title, DATE_FORMAT(r.deadline, '%Y-%m-%d %H:%i') AS deadline,
               (NOW() > r.deadline) AS is_closed,
               EXISTS(SELECT 1 FROM quotes q WHERE q.rfq_id=r.id AND q.vendor_id=?) AS has_quote
        FROM rfqs r
        JOIN rfq_recipients rr ON rr.rfq_id=r.id AND rr.vendor_id=?
        WHERE r.status='published'
        ORDER BY r.deadline ASC";
$st = $pdo->prepare($sql);
$st->execute([$vendor_id,$vendor_id]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode(['rows'=>$rows]);
