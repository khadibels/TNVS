<?php
declare(strict_types=1);
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_login();
header("Content-Type: application/json; charset=utf-8");

function tokenCol(PDO $pdo): string
{
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='rfq_recipients' AND column_name IN ('invite_token','token')");
    $st->execute();
    $col = $st->fetchColumn();
    if (!$col) {
        throw new Exception("Token column not found on rfq_recipients");
    }
    return $col;
}
function ensureToken(
    PDO $pdo,
    int $rfq_id,
    int $supplier_id,
    string $col
): string {
    $sel = $pdo->prepare(
        "SELECT `$col` FROM rfq_recipients WHERE rfq_id=? AND supplier_id=?"
    );
    $sel->execute([$rfq_id, $supplier_id]);
    $tok = $sel->fetchColumn();
    if ($tok && preg_match('/^[a-f0-9]{64}$/', $tok)) {
        return $tok;
    }
    $tok = bin2hex(random_bytes(32)); // 64 hex
    $exp = (new DateTime("+7 days"))->format("Y-m-d H:i:s");
    $up = $pdo->prepare(
        "UPDATE rfq_recipients SET `$col`=?, token_expires_at=?, sent_at=NOW() WHERE rfq_id=? AND supplier_id=?"
    );
    $up->execute([$tok, $exp, $rfq_id, $supplier_id]);
    return $tok;
}

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new Exception("POST required");
    }
    $rfq_id = (int) ($_POST["rfq_id"] ?? 0);
    if ($rfq_id <= 0) {
        throw new Exception("rfq_id required");
    }

    $col = tokenCol($pdo);

    $st = $pdo->prepare("SELECT rr.supplier_id, s.name, s.email
                       FROM rfq_recipients rr
                       JOIN suppliers s ON s.id=rr.supplier_id
                       WHERE rr.rfq_id=?");
    $st->execute([$rfq_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $base = rtrim(defined("BASE_URL") ? BASE_URL : "", "/");
    if (!$base) {
        $scheme =
            !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off"
                ? "https"
                : "http";
        $host = $_SERVER["HTTP_HOST"] ?? "localhost";
        $prefix =
            dirname(dirname($_SERVER["REQUEST_URI"] ?? "/procurement/api/")) ?:
            "";
        $base = $scheme . "://" . $host . $prefix;
    }

    $out = [];
    foreach ($rows as $r) {
        $tok = ensureToken($pdo, $rfq_id, (int) $r["supplier_id"], $col);
        $link = $base . "/procurement/supplier/quote.php?token=" . $tok;
        $mailto =
            "mailto:" .
            rawurlencode($r["email"]) .
            "?subject=" .
            rawurlencode("Invitation to Quote — RFQ #$rfq_id") .
            "&body=" .
            rawurlencode(
                "Hi {$r["name"]},\n\nPlease submit your quotation here:\n$link\n\nThanks!"
            );
        $out[] = [
            "supplier_id" => (int) $r["supplier_id"],
            "name" => $r["name"],
            "email" => $r["email"],
            "link" => $link,
            "mailto" => $mailto,
        ];
    }

    echo json_encode(["sent" => count($out), "links" => $out]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
