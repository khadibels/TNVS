<?php
require_once __DIR__ . "/../../includes/config.php";
require_once __DIR__ . "/../../includes/auth.php";
require_once __DIR__ . "/../../includes/db.php";
require_login();
require_role(['vendor_manager']);


$section = 'vendor_manager';
$active = 'vm_dash';
?>  

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>SW Dashboard | TNVS</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../../css/style.css" rel="stylesheet" />
  <link href="../../css/modules.css" rel="stylesheet" />
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="../js/sidebar-toggle.js"></script>
  <!-- Charts -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <style>
    .kpi-card .icon-wrap { width:42px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:12px; }
    .chart-card canvas { width:100% !important; height:320px !important; }
  </style>
</head>
<body>
  <div class="container-fluid p-0">
    <div class="row g-0">

    <?php include __DIR__ . '/../../includes/sidebar.php' ?>
    

      