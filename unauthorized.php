<?php
require_once __DIR__ . '/includes/config.php';
http_response_code(403);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Unauthorized</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="alert alert-warning">
      <h4 class="alert-heading">Unauthorized</h4>
      <p>You don’t have permission to access this page.</p>
      <hr>
      <a class="btn btn-primary" href="<?= rtrim(BASE_URL,'/') ?>/auth/logout.php">Logout</a>
    </div>
  </div>
</body>
</html>
