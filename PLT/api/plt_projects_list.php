<?php
declare(strict_types=1);

$inc = __DIR__ . "/../../includes";
if (file_exists($inc . "/config.php"))  require_once $inc . "/config.php";
if (file_exists($inc . "/auth.php"))    require_once $inc . "/auth.php";
if (file_exists($inc . "/db.php"))      require_once $inc . "/db.php"; 
if (function_exists("require_login"))    require_login();

if (!isset($pdo) || !$pdo instanceof PDO) {
    if (function_exists('db')) {
        $pdo = db('plt');  
    }
}

header("Content-Type: application/json; charset=utf-8");


try {
    if (!isset($pdo)) throw new Exception("DB missing");

    // params
    $id     = (int)($_GET["id"] ?? 0);
    $page   = max(1, (int)($_GET["page"] ?? 1));
    $per    = max(1, (int)($_GET["per_page"] ?? 10));
    $off    = ($page - 1) * $per;
    $search = trim($_GET["search"] ?? "");
    $status = trim($_GET["status"] ?? "");
    $sort   = $_GET["sort"] ?? "newest";

    // single project by id
    if ($id > 0) {
        $st = $pdo->prepare("SELECT * FROM plt_projects WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(["error" => "not_found"]);
            exit;
        }

        // milestone summary
        $ms = $pdo->prepare("SELECT title FROM plt_milestones WHERE project_id=? ORDER BY due_date ASC LIMIT 3");
        $ms->execute([$id]);
        $row["milestone_summary"] = implode("|", $ms->fetchAll(PDO::FETCH_COLUMN));

        echo json_encode(["data" => [$row]]);
        exit;
    }

    // filters
    $where = [];
    $p = [];
    if ($search !== "") {
        $where[] = "(code LIKE ? OR name LIKE ? OR scope LIKE ?)";
        $p = array_merge($p, ["%$search%", "%$search%", "%$search%"]);
    }
    if ($status !== "") {
        $where[] = "status=?";
        $p[] = $status;
    }
    $sqlWhere = $where ? "WHERE " . implode(" AND ", $where) : "";

    // sorting
    $order = "ORDER BY id DESC";
    if ($sort === "name") $order = "ORDER BY name ASC";
    if ($sort === "deadline") $order = "ORDER BY deadline_date ASC";

    // count
    $stc = $pdo->prepare("SELECT COUNT(*) FROM plt_projects $sqlWhere");
    $stc->execute($p);
    $total = (int)$stc->fetchColumn();

    // rows
    $sql = "SELECT * FROM plt_projects $sqlWhere $order LIMIT $per OFFSET $off";
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $ms = $pdo->prepare("SELECT title FROM plt_milestones WHERE project_id=? ORDER BY due_date ASC LIMIT 3");
        $ms->execute([$r["id"]]);
        $r["milestone_summary"] = implode("|", $ms->fetchAll(PDO::FETCH_COLUMN));
    }

    echo json_encode([
        "data" => $rows,
        "pagination" => [
            "page" => $page,
            "perPage" => $per,
            "total" => $total
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["error" => $e->getMessage()]);
}
