<?php
require_once __DIR__ . '/config.php';

function redirect_to(string $path) {
    $base = rtrim(BASE_URL, '/');
    $to   = $base . '/' . ltrim($path, '/');
    header('Location: ' . $to);
    exit;
}
