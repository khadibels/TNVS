<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_role(['admin']); // only admin can add users

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name  = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';
  $role  = $_POST['role'] ?? '';

    $allowed = [
      'admin',
      'manager',
      'warehouse_staff',
      'procurement_officer',
      'asset_manager',
      'document_controller',
      'project_lead',
      'viewer'
];

  if ($name === '' || $email === '' || $pass === '' || !in_array($role, $allowed, true)) {
    $msg = 'Please fill all fields correctly.';
  } else {
    try {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $st = $pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)");
      $st->execute([$name,$email,$hash,$role]);
      $msg = 'User created: ' . htmlspecialchars($email);
    } catch (Throwable $e) {
      $msg = 'Error: ' . htmlspecialchars($e->getMessage());
    }
  }
}
?>
<!doctype html><meta charset="utf-8">
<title>Add User</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
<div class="container py-4" style="max-width:560px">
  <h3>Add User</h3>
  <?php if ($msg): ?><div class="alert alert-info"><?= $msg ?></div><?php endif; ?>
  <form method="post" class="row g-3">
    <div class="col-12">
      <label class="form-label">Name</label>
      <input class="form-control" name="name" required>
    </div>
    <div class="col-12">
      <label class="form-label">Email</label>
      <input type="email" class="form-control" name="email" required>
    </div>
    <div class="col-12">
      <label class="form-label">Password</label>
      <input type="text" class="form-control" name="password" required>
    </div>
    <div class="col-12">
      <label class="form-label">Role</label>
      <select class="form-select" name="role" required>
        <option value="admin">Admin</option>
        <option value="manager">Warehouse Manager</option>
        <option value="warehouse_staff">Warehouse Staff</option>
        <option value="procurement_officer">Procurement Officer</option>
        <option value="asset_manager">Asset Manager</option>
        <option value="document_controller">Document Controller</option>
        <option value="project_lead">Project Lead</option>
      </select>
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Create</button>
      <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/warehousing/warehouseSettings.php">Back</a>
    </div>
  </form>
</div>
