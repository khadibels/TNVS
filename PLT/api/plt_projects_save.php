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

function jerr(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(["error" => $msg], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    if (!isset($pdo)) {
        jerr("DB missing", 500);
    }

    // Gather inputs
    $id = (int) ($_POST["id"] ?? 0);
    $code = trim((string) ($_POST["code"] ?? ""));
    $name = trim((string) ($_POST["name"] ?? ""));
    $scope = trim((string) ($_POST["scope"] ?? ""));
    $start = trim((string) ($_POST["start_date"] ?? ""));
    $dead = trim((string) ($_POST["deadline_date"] ?? ""));
    $status = strtolower(trim((string) ($_POST["status"] ?? "planned")));
    $owner = trim((string) ($_POST["owner_name"] ?? ""));

    if ($name === "") {
        jerr("Name required");
    }

    // Normalize empties to NULL
    $code = $code === "" ? null : $code;
    $scope = $scope === "" ? null : $scope;
    $start = $start === "" ? null : $start;
    $dead = $dead === "" ? null : $dead;
    $owner = $owner === "" ? null : $owner;

    // Validate status
    $allowed = ["planned", "ongoing", "completed", "delayed", "closed"];
    if (!in_array($status, $allowed, true)) {
        $status = "planned";
    }

    $pdo->beginTransaction();

    if ($id > 0) {
        // UPDATE
        $sql = 'UPDATE plt_projects
               SET code = ?, name = ?, scope = ?, start_date = ?, deadline_date = ?, status = ?, owner_name = ?
             WHERE id = ?';
        $pdo->prepare($sql)->execute([
            $code,
            $name,
            $scope,
            $start,
            $dead,
            $status,
            $owner,
            $id,
        ]);
    } else {
        // Auto-generate a code if missing
        if ($code === null) {
            $code =
                "PRJ-" .
                date("ymd") .
                "-" .
                substr((string) microtime(true), -4);
        }
        // INSERT
        $sql = 'INSERT INTO plt_projects (code, name, scope, start_date, deadline_date, status, owner_name)
            VALUES (?, ?, ?, ?, ?, ?, ?)';
        $pdo->prepare($sql)->execute([
            $code,
            $name,
            $scope,
            $start,
            $dead,
            $status,
            $owner,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    // ---- Milestones ----
    $pdo->prepare("DELETE FROM plt_milestones WHERE project_id = ?")->execute([
        $id,
    ]);

    $msJson = (string) ($_POST["milestones_json"] ?? "[]");
    $rows = json_decode($msJson, true);
    if (!is_array($rows)) {
        $rows = [];
    }

    if ($rows) {
        $ins = $pdo->prepare('INSERT INTO plt_milestones (project_id, title, due_date, status, owner)
                          VALUES (?, ?, ?, ?, ?)');
        foreach ($rows as $m) {
            $title = trim((string) ($m["title"] ?? ""));
            if ($title === "") {
                continue;
            }

            $due = trim((string) ($m["due_date"] ?? ""));
            $due = $due === "" ? null : $due;

            $mstat = strtolower(trim((string) ($m["status"] ?? "pending")));
            if (
                !in_array(
                    $mstat,
                    ["pending", "ongoing", "done", "delayed"],
                    true
                )
            ) {
                $mstat = "pending";
            }

            $mowner = trim((string) ($m["owner"] ?? ""));
            $mowner = $mowner === "" ? null : $mowner;

            $ins->execute([$id, $title, $due, $mstat, $mowner]);
        }
    }

    $pdo->commit();
    echo json_encode(
        ["ok" => 1, "id" => $id, "code" => $code],
        JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jerr($e->getMessage(), 400);
}
