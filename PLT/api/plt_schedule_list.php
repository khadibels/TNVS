<?php
declare(strict_types=1);
header("Content-Type: application/json");

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

try {
    if (!isset($pdo)) {
        throw new Exception("DB not initialized");
    }

    // Inputs
    $page = max(1, (int) ($_GET["page"] ?? 1));
    $per = min(100, max(1, (int) ($_GET["per_page"] ?? 10)));
    $status = trim((string) ($_GET["status"] ?? ""));
    $search = trim((string) ($_GET["search"] ?? ""));
    $sort = in_array(
        $_GET["sort"] ?? "schedule",
        ["schedule", "eta", "newest"],
        true
    )
        ? $_GET["sort"]
        : "schedule";
    $from = trim((string) ($_GET["date_from"] ?? ""));
    $to = trim((string) ($_GET["date_to"] ?? ""));

    $where = [];
    $bind = [];

    if ($status !== "") {
        $where[] = "s.status = :status";
        $bind[":status"] = $status;
    }
    if ($search !== "") {
        $where[] =
            "(s.shipment_no LIKE :q OR p.name LIKE :q OR s.origin LIKE :q OR s.destination LIKE :q)";
        $bind[":q"] = "%" . $search . "%";
    }
    if ($from !== "") {
        $where[] = "s.schedule_date >= :from";
        $bind[":from"] = $from;
    }
    if ($to !== "") {
        $where[] = "s.schedule_date <= :to";
        $bind[":to"] = $to;
    }

    $whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

    $orderBy = match ($sort) {
        "eta" => "s.eta_date IS NULL, s.eta_date ASC, s.id DESC",
        "newest" => "s.id DESC",
        default => "s.schedule_date IS NULL, s.schedule_date ASC, s.id DESC",
    };

    // Count
    $sqlCount = "SELECT COUNT(*) FROM plt_shipments s
               LEFT JOIN plt_projects p ON p.id = s.project_id
               $whereSQL";
    $stmt = $pdo->prepare($sqlCount);
    $stmt->execute($bind);
    $total = (int) $stmt->fetchColumn();

    // Page
    $offset = ($page - 1) * $per;
    $sql = "SELECT s.id, s.project_id, s.shipment_no, s.origin, s.destination,
                 s.vehicle, s.driver, s.schedule_date, s.eta_date, s.status, s.notes,
                 p.name AS project_name
          FROM plt_shipments s
          LEFT JOIN plt_projects p ON p.id = s.project_id
          $whereSQL
          ORDER BY $orderBy
          LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($sql);
    foreach ($bind as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(":lim", $per, PDO::PARAM_INT);
    $stmt->bindValue(":off", $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        "data" => $rows,
        "pagination" => ["page" => $page, "perPage" => $per, "total" => $total],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
