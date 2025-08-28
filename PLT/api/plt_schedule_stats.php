<?php
// PLT/api/plt_schedule_stats.php
declare(strict_types=1);
header('Content-Type: application/json');

$inc = __DIR__ . '/../../includes';
if (file_exists($inc . '/config.php')) require_once $inc . '/config.php';
if (file_exists($inc . '/auth.php'))  require_once $inc . '/auth.php';
if (function_exists('require_login')) require_login();

try {
  if (!isset($pdo)) throw new Exception('DB not initialized');

  // Compute week (Mon..Sun) in PHP to keep SQL simple/portable
  $today    = new DateTimeImmutable('today');
  $dow      = (int)$today->format('N'); // 1..7, Mon..Sun
  $monday   = $today->modify('-'.($dow-1).' days');
  $sunday   = $monday->modify('+6 days');

  // Today
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM plt_shipments WHERE schedule_date = CURDATE()");
  $stmt->execute(); $todayCnt = (int)$stmt->fetchColumn();

  // Tomorrow
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM plt_shipments WHERE schedule_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)");
  $stmt->execute(); $tomorrowCnt = (int)$stmt->fetchColumn();

  // This week (Mon..Sun)
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM plt_shipments WHERE schedule_date BETWEEN :mon AND :sun");
  $stmt->execute([':mon'=>$monday->format('Y-m-d'), ':sun'=>$sunday->format('Y-m-d')]);
  $weekCnt = (int)$stmt->fetchColumn();

  // Delivered in last 7 days (by schedule_date; change to eta_date if you prefer)
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM plt_shipments
                         WHERE status='delivered' AND schedule_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()");
  $stmt->execute(); $del7 = (int)$stmt->fetchColumn();

  echo json_encode([
    'today'      => $todayCnt,
    'tomorrow'   => $tomorrowCnt,
    'week'       => $weekCnt,
    'delivered7' => $del7
  ]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
