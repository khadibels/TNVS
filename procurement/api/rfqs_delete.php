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


function bad($m, $code = 400)
{
    http_response_code($code);
    echo json_encode(["error" => $m]);
    exit();
}
function table_exists(PDO $pdo, string $t): bool
{
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?"
    );
    $st->execute([$t]);
    return (bool) $st->fetchColumn();
}

try {
    $id = (int) ($_POST["id"] ?? 0);
    $force = (int) ($_POST["force"] ?? 0) === 1;
    if ($id <= 0) {
        bad("id required");
    }

    // Load RFQ
    $st = $pdo->prepare("SELECT id, status FROM rfqs WHERE id=?");
    $st->execute([$id]);
    $rfq = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rfq) {
        bad("RFQ not found", 404);
    }

    if (!$force && strtolower((string) $rfq["status"]) !== "draft") {
        bad("Only DRAFT RFQs can be deleted (use force=1 to hard-delete)", 409);
    }

    if (!$force) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM quotes WHERE rfq_id=?");
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            bad(
                "RFQ has quotes; cannot delete (use force=1 to hard-delete)",
                409
            );
        }
    }

    $pdo->beginTransaction();

    $quoteIds = [];
    if (table_exists($pdo, "quotes")) {
        $st = $pdo->prepare("SELECT id FROM quotes WHERE rfq_id=?");
        $st->execute([$id]);
        $quoteIds = array_map("intval", $st->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    if ($quoteIds) {
        $in = implode(",", array_fill(0, count($quoteIds), "?"));

        if (table_exists($pdo, "quote_attachments")) {
            $pdo->prepare(
                "DELETE FROM quote_attachments WHERE quote_id IN ($in)"
            )->execute($quoteIds);
        }
        if (table_exists($pdo, "quote_items")) {
            $pdo->prepare(
                "DELETE FROM quote_items WHERE quote_id IN ($in)"
            )->execute($quoteIds);
        }
        $pdo->prepare("DELETE FROM quotes WHERE id IN ($in)")->execute(
            $quoteIds
        );
    }

    if (table_exists($pdo, "rfq_recipients")) {
        $pdo->prepare("DELETE FROM rfq_recipients WHERE rfq_id=?")->execute([
            $id,
        ]);
    }
    if (table_exists($pdo, "rfq_suppliers")) {
        $pdo->prepare("DELETE FROM rfq_suppliers WHERE rfq_id=?")->execute([
            $id,
        ]);
    }
    if (table_exists($pdo, "rfq_items")) {
        $pdo->prepare("DELETE FROM rfq_items WHERE rfq_id=?")->execute([$id]);
    }

    $pdo->prepare("DELETE FROM rfqs WHERE id=?")->execute([$id]);

    $pdo->commit();
    echo json_encode([
        "ok" => true,
        "deleted_rfq_id" => $id,
        "force" => $force,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
