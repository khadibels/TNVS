<?php
// includes/auth.php

if (session_status() === PHP_SESSION_NONE) session_start();

// Make sure BASE_URL exists if some pages include auth.php before config.php
if (!defined('BASE_URL')) {
    $cfg = __DIR__ . '/config.php';
    if (is_file($cfg)) require_once $cfg;
}

function current_user() {
    return $_SESSION['user'] ?? null;
}

function user_role() {
    return $_SESSION['user']['role'] ?? null;
}

function is_json_request(): bool {
    $xh = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $acc = $_SERVER['HTTP_ACCEPT'] ?? '';
    $ct  = $_SERVER['CONTENT_TYPE'] ?? '';
    return strcasecmp($xh, 'XMLHttpRequest') === 0
        || str_contains($acc, 'application/json')
        || str_contains($ct,  'application/json');
}

function require_login(string $mode = 'auto') {
    if (!empty($_SESSION['user'])) return;

    $respondJson = $mode === 'json' || ($mode === 'auto' && is_json_request());
    if ($respondJson) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok'=>false, 'error'=>'AUTH_REQUIRED']);
        exit;
    }
    header('Location: ' . rtrim(BASE_URL,'/') . '/login.php');
    exit;
}

function require_role($roles, string $mode = 'auto') {
    if (session_status() === PHP_SESSION_NONE) session_start();

    $norm = function($v){
        $v = strtolower(trim((string)$v));
        $aliases = [
            'proc_officer'      => 'procurement_officer',
            'warehouse_mgr'     => 'manager',
            'warehouse_manager' => 'manager',
        ];
        return $aliases[$v] ?? $v;
    };

    require_login($mode);

    $allowed  = is_array($roles) ? $roles : [$roles];
    $allowed  = array_map($norm, $allowed);
    $userRole = $norm($_SESSION['user']['role'] ?? '');

    if (!in_array($userRole, $allowed, true)) {
        $respondJson = $mode === 'json' || ($mode === 'auto' && is_json_request());
        if ($respondJson) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok'=>false, 'error'=>'FORBIDDEN']);
            exit;
        }
        header('Location: ' . rtrim(BASE_URL,'/') . '/unauthorized.php');
        exit;
    }
}

/* -------------------- VENDOR HELPERS -------------------- */

/** Returns vendor array from session, or null if not vendor */
function vendor_session(): ?array {
    $u = current_user();
    return ($u && ($u['role'] ?? '') === 'vendor') ? $u : null;
}

/** Require vendor login (any status). Redirects to vendor login if missing. */
function vendor_require_login(): void {
    if (vendor_session()) return;
    header('Location: ' . rtrim(BASE_URL,'/') . '/vendor_portal/login.php?err=' . urlencode('Please sign in as a vendor'));
    exit;
}

/** Require vendor login AND approved status. Redirects pending vendors to pending page. */
function vendor_require_approved(): void {
    $u = vendor_session();
    if (!$u) {
        header('Location: ' . rtrim(BASE_URL,'/') . '/vendor_portal/login.php?err=' . urlencode('Please sign in as a vendor'));
        exit;
    }
    $status = strtolower($u['vendor_status'] ?? 'pending');
    if ($status !== 'approved') {
        header('Location: ' . rtrim(BASE_URL,'/') . '/vendor_portal/vendor/pending.php');
        exit;
    }
}

/** Optional: Require vendor login AND status = pending (for the pending page itself) */
function vendor_require_pending(): void {
    $u = vendor_session();
    if (!$u) {
        header('Location: ' . rtrim(BASE_URL,'/') . '/vendor_portal/login.php?err=' . urlencode('Please sign in as a vendor'));
        exit;
    }
    $status = strtolower($u['vendor_status'] ?? 'pending');
    if ($status !== 'pending') {
        header('Location: ' . rtrim(BASE_URL,'/') . '/vendor_portal/vendor/dashboard.php');
        exit;
    }
}
