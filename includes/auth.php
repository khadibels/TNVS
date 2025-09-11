<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_user()
{
    return $_SESSION["user"] ?? null;
}

function user_role()
{
    return $_SESSION["user"]["role"] ?? null;
}

function is_json_request(): bool
{
    $xh = $_SERVER["HTTP_X_REQUESTED_WITH"] ?? "";
    $acc = $_SERVER["HTTP_ACCEPT"] ?? "";
    $ct = $_SERVER["CONTENT_TYPE"] ?? "";
    return strcasecmp($xh, "XMLHttpRequest") === 0 ||
        str_contains($acc, "application/json") ||
        str_contains($ct, "application/json");
}

function require_login(string $mode = "auto")
{
    if (!empty($_SESSION["user"])) {
        return;
    }

    $respondJson = $mode === "json" || ($mode === "auto" && is_json_request());
    if ($respondJson) {
        http_response_code(401);
        header("Content-Type: application/json");
        echo json_encode(["ok" => false, "error" => "AUTH_REQUIRED"]);
        exit();
    }
    header("Location: " . BASE_URL . "/login.php");
    exit();
}

function require_role($roles, string $mode = "auto")
{
    if (session_status() === PHP_SESSION_NONE) session_start();

    $norm = function($v){
        $v = strtolower(trim((string)$v));
        $aliases = [
            'proc_officer'        => 'procurement_officer',
            'warehouse_mgr'       => 'manager',
            'warehouse_manager'   => 'manager',
        ];
        return $aliases[$v] ?? $v;
    };

    require_login($mode);

    $allowed = is_array($roles) ? $roles : [$roles];
    $allowed = array_map($norm, $allowed);
    $userRole = $norm($_SESSION['user']['role'] ?? '');

    if (!in_array($userRole, $allowed, true)) {
        if ($mode === "json" || ($mode === "auto" && is_json_request())) {
            http_response_code(403);
            header("Content-Type: application/json");
            echo json_encode(["ok" => false, "error" => "FORBIDDEN"]);
            exit();
        }
        header("Location: " . BASE_URL . "/unauthorized.php");
        exit();
    }
}

