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

    // Single record (for edit)
    if (isset($_GET["id"]) && (int) $_GET["id"] > 0) {
        $id = (int) $_GET["id"];
        $st = $pdo->prepare("SELECT * FROM plt_projects WHERE id=?");
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

    $page = max(1, (int) ($_GET["page"] ?? 1));
    $per = min(100, max(1, (int) ($_GET["per_page"] ?? 10)));
    $off = ($page - 1) * $per;
    $search = trim((string) ($_GET["search"] ?? ""));
    $status = trim((string) ($_GET["status"] ?? ""));
    $sort = $_GET["sort"] ?? "newest";

    $where = [];
    $args = [];

    if ($search !== "") {
        $where[] = "(p.code LIKE ? OR p.name LIKE ? OR p.scope LIKE ?)";
        $args[] = "%$search%";
        $args[] = "%$search%";
        $args[] = "%$search%";
    }
    if ($status !== "") {
        $where[] = "p.status=?";
        $args[] = $status;
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $order = "ORDER BY p.id DESC";
    if ($sort === "name") {
        $order = "ORDER BY p.name ASC";
    }
    if ($sort === "deadline") {
        $order = "ORDER BY p.deadline_date ASC";
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM plt_projects p $whereSql");
    $st->execute($args);
    $total = (int) $st->fetchColumn();

    $sql = "SELECT p.*,
            (SELECT GROUP_CONCAT(m.title SEPARATOR '|') FROM plt_milestones m WHERE m.project_id=p.id LIMIT 3) milestone_summary
          FROM plt_projects p
          $whereSql
          $order
          LIMIT $per OFFSET $off";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "data" => $rows,
        "pagination" => ["page" => $page, "perPage" => $per, "total" => $total],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
