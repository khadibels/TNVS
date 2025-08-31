<?php
declare(strict_types=1);
$inc = __DIR__ . "/../../includes";
require_once $inc . "/config.php";

function findInvite(PDO $pdo, string $raw)
{
    $hash = hash("sha256", $raw);
    $sql = "SELECT rr.*, r.due_date, r.status
          FROM rfq_recipients rr
          JOIN rfqs r ON r.id = rr.rfq_id
          WHERE rr.invite_token = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$hash]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        throw new Exception("Invalid link.");
    }
    if (
        $inv["token_expires_at"] &&
        strtotime($inv["token_expires_at"]) < time()
    ) {
        throw new Exception("Link expired.");
    }
    if (in_array($inv["status"], ["awarded", "cancelled"], true)) {
        throw new Exception("RFQ closed.");
    }
    return $inv;
}
function rfqItemQtyMap(PDO $pdo, int $rfq_id): array
{
    $st = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rfq_items'"
    );
    $st->execute();
    $cols = array_flip($st->fetchAll(PDO::FETCH_COLUMN, 0));
    $qtyCol = isset($cols["qty"])
        ? "qty"
        : (isset($cols["quantity"])
            ? "quantity"
            : null);
    if ($qtyCol) {
        $s = $pdo->prepare(
            "SELECT id, $qtyCol AS qty FROM rfq_items WHERE rfq_id=?"
        );
    } else {
        $s = $pdo->prepare("SELECT id, 1 AS qty FROM rfq_items WHERE rfq_id=?");
    }
    $s->execute([$rfq_id]);
    $map = [];
    while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
        $map[(int) $r["id"]] = (float) $r["qty"];
    }
    return $map;
}

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new Exception("POST required");
    }
    $raw = $_POST["t"] ?? "";
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) {
        throw new Exception("Bad token");
    }

    $inv = findInvite($pdo, $raw);
    $rfq_id = (int) $inv["rfq_id"];
    $supplier_id = (int) $inv["supplier_id"];

    $prices = $_POST["price"] ?? [];
    if (!is_array($prices)) {
        $prices = [];
    }
    // clean prices
    $clean = [];
    foreach ($prices as $rfq_item_id => $p) {
        $rfq_item_id = (int) $rfq_item_id;
        $val = is_numeric($p) ? (float) $p : null;
        if ($rfq_item_id > 0 && $val !== null) {
            $clean[$rfq_item_id] = $val;
        }
    }

    $lead = isset($_POST["lead_time_days"])
        ? max(0, (int) $_POST["lead_time_days"])
        : 0;
    $notes = trim((string) ($_POST["notes"] ?? ""));

    // compute total
    $qtyMap = rfqItemQtyMap($pdo, $rfq_id);
    $total = 0.0;
    foreach ($clean as $iid => $unit) {
        $qty = $qtyMap[$iid] ?? 1;
        $total += $qty * $unit;
    }

    $pdo->beginTransaction();
    // insert quote
    $stmt = $pdo->prepare("INSERT INTO quotes (rfq_id, supplier_id, submitted_at, lead_time_days, notes, total_cache, is_final)
                         VALUES (?, ?, NOW(), ?, ?, ?, 1)");
    $stmt->execute([$rfq_id, $supplier_id, $lead, $notes, $total]);
    $quote_id = (int) $pdo->lastInsertId();

    if ($clean) {
        $ins = $pdo->prepare("INSERT INTO quote_items (quote_id, rfq_item_id, unit_price, currency)
                          VALUES (?, ?, ?, 'PHP')");
        foreach ($clean as $iid => $unit) {
            $ins->execute([$quote_id, $iid, $unit]);
        }
    }

    // attachments
    if (!empty($_FILES["files"]) && is_array($_FILES["files"]["name"])) {
        $dir =
            __DIR__ .
            "/../../uploads/quotes/" .
            date("Y/m") .
            "/rfq_{$rfq_id}/supplier_{$supplier_id}";
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $insA = $pdo->prepare("INSERT INTO quote_attachments (quote_id, original_name, path, mime, size_bytes)
                           VALUES (?, ?, ?, ?, ?)");
        $allow = ["pdf", "xls", "xlsx", "csv", "jpg", "jpeg", "png"];
        for ($i = 0; $i < count($_FILES["files"]["name"]); $i++) {
            if ($_FILES["files"]["error"][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            $name = $_FILES["files"]["name"][$i];
            $tmp = $_FILES["files"]["tmp_name"][$i];
            $size = (int) $_FILES["files"]["size"][$i];
            $mime = mime_content_type($tmp) ?: "application/octet-stream";
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allow, true)) {
                continue;
            }
            if ($size > 20 * 1024 * 1024) {
                continue;
            } // 20 MB
            $safe = preg_replace("/[^a-zA-Z0-9._-]/", "_", $name);
            $dest = $dir . "/" . uniqid("f_", true) . "_" . $safe;
            if (@move_uploaded_file($tmp, $dest)) {
                $rel = str_replace(
                    realpath(__DIR__ . "/../../"),
                    "",
                    realpath($dest)
                );
                $insA->execute([$quote_id, $name, $rel, $mime, $size]);
            }
        }
    }

    $pdo->prepare(
        "UPDATE rfqs SET status = CASE WHEN status='draft' THEN 'sent' ELSE status END WHERE id=?"
    )->execute([$rfq_id]);

    $pdo->commit();

    echo "<div style='font-family:system-ui;padding:2rem'><h2>Thank you!</h2><p>Your quotation has been submitted.</p></div>";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo "<div style='font-family:system-ui;padding:2rem;color:#b00'><b>Failed:</b> " .
        htmlspecialchars($e->getMessage()) .
        "</div>";
}
