<?php
declare(strict_types=1);

require_once __DIR__ . "/../../includes/config.php";
if (file_exists(__DIR__ . "/../../includes/auth.php")) {
    require_once __DIR__ . "/../../includes/auth.php";
}
if (function_exists("require_login")) {
    require_login();
}
header("Content-Type: application/json; charset=utf-8");

function bad($m, $c = 400)
{
    http_response_code($c);
    echo json_encode(["error" => $m]);
    exit();
}

/* ---- helpers ---- */
function has_col(PDO $pdo, string $table, string $col): bool
{
    $q = $pdo->prepare("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?
  ");
    $q->execute([$table, $col]);
    return (bool) $q->fetchColumn();
}
function next_pr_no(PDO $pdo): string
{
    $base = "PR-" . date("Ymd") . "-";
    $num = str_pad((string) random_int(1, 9999), 4, "0", STR_PAD_LEFT);
    return $base . $num;
}

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        bad("POST required", 405);
    }

    $id =
        isset($_POST["id"]) && $_POST["id"] !== "" ? (int) $_POST["id"] : null;
    $title = trim((string) ($_POST["title"] ?? ""));
    $needed_by = trim((string) ($_POST["needed_by"] ?? ""));
    $priority = trim((string) ($_POST["priority"] ?? "normal"));
    $requestor = trim((string) ($_POST["requestor"] ?? ""));
    $department_id =
        $_POST["department_id"] === ""
            ? null
            : (int) ($_POST["department_id"] ?? 0);
    $status = strtolower(trim((string) ($_POST["status"] ?? "draft")));
    $notes = trim((string) ($_POST["notes"] ?? ""));

    if ($title === "") {
        bad("title_required");
    }
    $allowed = [
        "draft",
        "submitted",
        "approved",
        "rejected",
        "fulfilled",
        "cancelled",
    ];
    if (!in_array($status, $allowed, true)) {
        bad("invalid_status");
    }

    $descrs = (array) ($_POST["items"]["descr"] ?? []);
    $qtys = (array) ($_POST["items"]["qty"] ?? []);
    $prices = (array) ($_POST["items"]["price"] ?? []);

    $items = [];
    $n = max(count($descrs), count($qtys), count($prices));
    for ($i = 0; $i < $n; $i++) {
        $d = trim((string) ($descrs[$i] ?? ""));
        $q = (float) ($qtys[$i] ?? 0);
        $p = (float) ($prices[$i] ?? 0);
        if ($d !== "" || $q > 0 || $p > 0) {
            if ($d === "") {
                $d = "Item " . ($i + 1);
            }
            $items[] = ["descr" => $d, "qty" => $q, "price" => $p];
        }
    }
    if (!$items) {
        bad("items_required");
    }

    if ($id) {
        $st = $pdo->prepare(
            "SELECT status FROM procurement_requests WHERE id=?"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            bad("not_found", 404);
        }

        $current = strtolower((string) $row["status"]);
        if (!in_array($current, ["draft", "submitted"], true)) {
            bad("not_editable_in_current_status", 409);
        }
    }

    $pdo->beginTransaction();

    // header upsert
    if ($id) {
        // UPDATE
        $sql = "UPDATE procurement_requests
            SET title=:title, needed_by=:needed_by, priority=:priority,
                requestor=:requestor, department_id=:department_id,
                status=:status, notes=:notes
            WHERE id=:id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ":title" => $title,
            ":needed_by" => $needed_by !== "" ? $needed_by : null,
            ":priority" => $priority,
            ":requestor" => $requestor,
            ":department_id" => $department_id ?: null,
            ":status" => $status,
            ":notes" => $notes,
            ":id" => $id,
        ]);
    } else {
        // INSERT
        $pr_no = next_pr_no($pdo);
        $sql = "INSERT INTO procurement_requests
              (pr_no, title, needed_by, priority, requestor, department_id, status, notes, estimated_total)
            VALUES
              (:pr_no, :title, :needed_by, :priority, :requestor, :department_id, :status, :notes, 0.00)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ":pr_no" => $pr_no,
            ":title" => $title,
            ":needed_by" => $needed_by !== "" ? $needed_by : null,
            ":priority" => $priority,
            ":requestor" => $requestor,
            ":department_id" => $department_id ?: null,
            ":status" => $status,
            ":notes" => $notes,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    // replace items
    $pdo->prepare(
        "DELETE FROM procurement_request_items WHERE pr_id=?"
    )->execute([$id]);

    $ins = $pdo->prepare("
    INSERT INTO procurement_request_items (pr_id, descr, qty, price)
    VALUES (?, ?, ?, ?)
  ");

    $total = 0.0;
    foreach ($items as $it) {
        $lt = (float) $it["qty"] * (float) $it["price"];
        $total += $lt;
        $ins->execute([$id, $it["descr"], $it["qty"], $it["price"]]);
    }

    // update header total
    if (has_col($pdo, "procurement_requests", "estimated_total")) {
        $pdo->prepare(
            "UPDATE procurement_requests SET estimated_total=? WHERE id=?"
        )->execute([$total, $id]);
    }

    $pdo->commit();
    $pr_no = null;
    $st = $pdo->prepare("SELECT pr_no FROM procurement_requests WHERE id=?");
    $st->execute([$id]);
    $pr_no = $st->fetchColumn();

    echo json_encode([
        "ok" => true,
        "id" => $id,
        "pr_no" => $pr_no,
        "estimated_total" => $total,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    bad("server_error: " . $e->getMessage(), 500);
}
