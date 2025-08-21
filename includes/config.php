<?php
$DB_HOST = 'localhost';
$DB_NAME = 'tnvs';
$DB_USER = 'root';
$DB_PASS = '';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
$pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);


if (!defined('BASE_URL')) {
  define('BASE_URL', '/TNVS_2.0_final');
}

