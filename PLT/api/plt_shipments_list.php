<?php
declare(strict_types=1);

$inc = __DIR__ . "/../../includes";
if (file_exists($inc . "/config.php")) require_once $inc . "/config.php";
if (file_exists($inc . "/auth.php"))   require_once $inc . "/auth.php";
if (file_exists($inc . "/db.php"))     require_once $inc . "/db.php";

if (function_exists("require_login")) require_login();

header("Content-Type: application/json; charset=utf-8");

function bad(string $m, int $c = 400): void {
    http_response_code($c);
    echo json_encode(["error" => $m]);
    exit();
}

try {
    //PLT DB connection
    $pdo = db('plt');
    if (!$pdo) {
        bad("DB connection failed (plt)", 500);
    }

    // ---- Single fetch for edit ----
    if (isset($_GET["id"]) && (int) $_GET["id"] > 0) {
        $id = (int) $_GET["id"];
        $st = $pdo->prepare(
            "SELECT s.*, p.name AS project_name, p.code AS project_code
             FROM plt_shipments s
             LEFT JOIN plt_projects p ON p.id = s.project_id
             WHERE s.id = ?"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            "data" => $row ? [$row] : [],
            "pagination" => [
                "page" => 1,
                "perPage" => 1,
                "total" => $row ? 1 : 0,
            ],
        ]);
        exit();
    }

    // ---- List with filters + pagination ----
    $page   = max(1, (int) ($_GET["page"] ?? 1));
    $per    = min(100, max(1, (int) ($_GET["per_page"] ?? 10)));
    $off    = ($page - 1) * $per;
    $search = trim((string) ($_GET["search"] ?? ""));
    $status = trim((string) ($_GET["status"] ?? ""));
    $sort   = $_GET["sort"] ?? "newest";

    $where = [];
    $args  = [];

    if ($search !== "") {
        $where[] = "(s.shipment_no LIKE ? OR s.origin LIKE ? OR s.destination LIKE ? 
                     OR s.vehicle LIKE ? OR s.driver LIKE ? OR p.name LIKE ?)";
        for ($i = 0; $i < 6; $i++) {
            $args[] = "%$search%";
        }
    }
    if ($status !== "") {
        $where[] = "s.status = ?";
        $args[]  = $status;
    }

    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $order = "ORDER BY s.id DESC";
    if ($sort === "eta")      $order = "ORDER BY s.eta_date ASC, s.id DESC";
    if ($sort === "schedule") $order = "ORDER BY s.schedule_date ASC, s.id DESC";

    // ---- Count total ----
    $st = $pdo->prepare(
        "SELECT COUNT(*) 
         FROM plt_shipments s 
         LEFT JOIN plt_projects p ON p.id = s.project_id 
         $whereSql"
    );
    $st->execute($args);
    $total = (int) $st->fetchColumn();

    // ---- Fetch rows ----
    $sql = "SELECT s.*, p.name AS project_name, p.code AS project_code
            FROM plt_shipments s
            LEFT JOIN plt_projects p ON p.id = s.project_id
            $whereSql
            $order
            LIMIT $per OFFSET $off";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "data"       => $rows,
        "pagination" => ["page" => $page, "perPage" => $per, "total" => $total],
    ]);
} catch (Throwable $e) {
    bad($e->getMessage(), 400);
}
