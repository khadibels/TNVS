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

$id = (int)($_POST["id"] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(["error"=>"Invalid id"]); exit; }

try {
    $pdo->prepare("DELETE FROM plt_drivers WHERE id=?")->execute([$id]);
    echo json_encode(["ok"=>true]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["error"=>$e->getMessage()]);
}
