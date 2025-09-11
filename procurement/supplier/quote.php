<?php
declare(strict_types=1);

$token = trim($_GET['token'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
  http_response_code(400);
  ?>
  <!doctype html><html><head>
    <meta charset="utf-8"><title>Submit Quote</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  </head><body class="bg-light">
    <div class="container py-4"><div class="alert alert-danger">Missing or invalid token</div></div>
  </body></html>
  <?php
  exit;
}

header('Location: ../quotes/respond.php?t=' . urlencode($token), true, 302);
exit;
