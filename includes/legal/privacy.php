<?php
require_once __DIR__ . '/../config.php';
$effective = date('F j, Y');
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Privacy Policy | ViaHale</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light">
<div class="container my-4">
  <h1>Privacy Policy</h1>
  <p class="text-muted">Effective date: <?= htmlspecialchars($effective) ?></p>
  <p>This Privacy Policy applies to the ViaHale platform and the following modules: Procurement, Warehousing, Project/PLT, Asset Lifecycle, and Document Tracking.</p>

  <h5>Information We Collect</h5>
  <ul>
    <li>Account & Identity data (name, email, role, company, vendor info)</li>
    <li>Operational data per module (RFQs/quotes, inventory, tasks, assets, documents, logs)</li>
    <li>Technical data (IP, device/browser info, usage logs)</li>
  </ul>

  <h5>Use of Information</h5>
  <ul>
    <li>Authenticate users; authorize access per role/module</li>
    <li>Operate workflows across all modules</li>
    <li>Security, fraud prevention, compliance and auditing</li>
    <li>Support, maintenance, analytics and improvements</li>
  </ul>

  <h5>Sharing</h5>
  <ul>
    <li>Authorized internal users/stakeholders; vendors as needed for RFQs/awards</li>
    <li>Service providers under confidentiality</li>
    <li>Legal compliance and safety</li>
  </ul>

  <h5>Retention & Security</h5>
  <p>Records are retained for operational, audit and legal needs. We apply reasonable safeguards; users must follow company security policies.</p>

  <h5>Your Rights</h5>
  <p>Subject to applicable law/contract, you may request access, correction, or deletion of personal data; some records must be retained.</p>

  <h5>Contact</h5>
  <p>example@viahale.com</p>

  <hr>
  <p><a class="btn btn-outline-primary" href="<?= rtrim(BASE_URL,'/') ?>/includes/legal/terms.php">View Terms of Use</a></p>
</div>
</body></html>
