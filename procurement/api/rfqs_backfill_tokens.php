<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";

require_role(['admin','procurement_officer'], 'json');

header('Content-Type: application/json; charset=utf-8');

$pdo = db('proc') ?: db('wms');
if (!$pdo instanceof PDO) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'err'=>'DB not available']);
  exit;
}


function col_exists(PDO $pdo, string $t, string $c): bool
{
    $st = $pdo->prepare("SELECT 1 FROM information_schema.columns
                     WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $st->execute([$t, $c]);
    return (bool) $st->fetchColumn();
}

try {
    $tokCol = col_exists($pdo, "rfq_recipients", "token")
        ? "token"
        : (col_exists($pdo, "rfq_recipients", "invite_token")
            ? "invite_token"
            : null);
    if (!$tokCol) {
        throw new Exception("No token column on rfq_recipients");
    }

    $pdo->beginTransaction();
    $st = $pdo->query(
        "SELECT id FROM rfq_recipients WHERE $tokCol IS NULL OR $tokCol = '' FOR UPDATE"
    );
    $ids = $st->fetchAll(PDO::FETCH_COLUMN, 0);

    $upd = $pdo->prepare("UPDATE rfq_recipients SET $tokCol = ? WHERE id=?");
    $n = 0;
    foreach ($ids as $id) {
        $token = hash(
            "sha256",
            $id . "-" . bin2hex(random_bytes(16)) . "-" . microtime(true)
        );
        $upd->execute([$token, $id]);
        $n++;
    }
    $pdo->commit();

    echo json_encode([
        "ok" => true,
        "updated" => $n,
        "token_column" => $tokCol,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
