<?php
declare(strict_types=1);

$inc = __DIR__ . "/../../includes";
if (file_exists($inc."/config.php")) require_once $inc."/config.php";
if (file_exists($inc."/auth.php"))   require_once $inc."/auth.php";
if (file_exists($inc."/db.php"))     require_once $inc."/db.php";
if (function_exists("require_login")) require_login();

header("Content-Type: application/json; charset=utf-8");

$pdo = db('plt');
if (!$pdo) { http_response_code(500); echo json_encode(["error"=>"DB connection failed (plt)"]); exit; }

$id     = (int)($_POST["id"] ?? 0);
$status = strtolower(trim((string)($_POST["status"] ?? "")));

if (!$id || $status === "") {
  http_response_code(400);
  echo json_encode(["error"=>"Missing id or status"]);
  exit;
}

try {
  if ($status === "delivered") {
    $sql = "UPDATE plt_shipments
              SET status='delivered',
                  delivered_at = COALESCE(delivered_at, NOW())
            WHERE id = :id";
    $pdo->prepare($sql)->execute([":id" => $id]);
  } else {
    // Use UNIQUE placeholders (no reuse) because emulated prepares are off
    $sql = "UPDATE plt_shipments
              SET status = :st1,
                  delivered_at = CASE WHEN :st2 <> 'delivered' THEN NULL ELSE delivered_at END
            WHERE id = :id";
    $pdo->prepare($sql)->execute([":id"=>$id, ":st1"=>$status, ":st2"=>$status]);
  }

  echo json_encode(["ok" => true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["error" => $e->getMessage()]);
}
