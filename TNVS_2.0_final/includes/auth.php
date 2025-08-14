<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function current_user() { return $_SESSION['user'] ?? null; }
function user_role()     { return $_SESSION['user']['role'] ?? null; }

function require_login() {
  if (empty($_SESSION['user'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
  }
}
