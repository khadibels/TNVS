<?php
declare(strict_types=1);
$inc = __DIR__ . "/../../includes";
if (file_exists($inc . "/config.php")) {
    require_once $inc . "/config.php";
}
if (file_exists($inc . "/auth.php")) {
    require_once $inc . "/auth.php";
}
if (function_exists("require_login")) {
    require_login();
}
header("Content-Type: application/json; charset=utf-8");

try {
    if (!isset($pdo)) {
        throw new Exception("DB missing");
    }

    $id = (int) ($_GET["id"] ?? 0);
    $force = (int) ($_GET["force"] ?? 0);
    if (!$id) {
        throw new Exception("Missing project id");
    }
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM plt_shipments WHERE project_id=? AND status <> 'cancelled'"
    );
    $st->execute([$id]);
    $totalShip = (int) $st->fetchColumn();

    if ($totalShip === 0) {
        $pdo->prepare(
            "UPDATE plt_projects SET status='closed' WHERE id=?"
        )->execute([$id]);
        echo json_encode(["ok" => 1, "note" => "closed_without_shipments"]);
        exit();
    }

    $st2 = $pdo->prepare(
        "SELECT COUNT(*) FROM plt_shipments WHERE project_id=? AND status NOT IN ('delivered','cancelled')"
    );
    $st2->execute([$id]);
    $openShip = (int) $st2->fetchColumn();
    if ($openShip > 0) {
        echo json_encode([
            "ok" => 0,
            "error" => "There are $openShip shipment(s) not yet delivered/cancelled.",
        ]);
        exit();
    }

    if ($force === 0) {
        $sets = [
            "POD" => [
                "'POD'",
                "'PROOF_OF_DELIVERY'",
                "'PROOF-OF-DELIVERY'",
                "'PROOF OF DELIVERY'",
            ],
            "DR" => ["'DR'", "'DELIVERY_RECEIPT'", "'DELIVERY RECEIPT'"],
            "BOL" => ["'BOL'", "'BILL_OF_LADING'", "'BILL OF LADING'", "'B/L'"],
        ];
        $missing = [];

        foreach ($sets as $label => $alts) {
            $inList = implode(",", $alts);
            $sql = "
        SELECT 1
        FROM plt_documents d
        WHERE
          (d.project_id = :pid1 AND UPPER(d.doc_type) IN ($inList))
          OR
          (
            UPPER(d.doc_type) IN ($inList)
            AND EXISTS (
              SELECT 1 FROM plt_shipments s
              WHERE s.id = d.shipment_id AND s.project_id = :pid2
            )
          )
        LIMIT 1
      ";
            $check = $pdo->prepare($sql);
            $check->execute([":pid1" => $id, ":pid2" => $id]);
            if ($check->fetchColumn() === false) {
                $missing[] = $label;
            }
        }

        if ($missing) {
            echo json_encode([
                "ok" => 0,
                "error" =>
                    "Missing required documents: " . implode(", ", $missing),
            ]);
            exit();
        }
    }

    // Close it
    $pdo->prepare(
        "UPDATE plt_projects SET status='closed' WHERE id=?"
    )->execute([$id]);
    echo json_encode(["ok" => 1]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
