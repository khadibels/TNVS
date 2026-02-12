<?php
require_once __DIR__ . '/../../includes/config.php';
$BASE = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Vendor Contract & Privacy Policy | TNVS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/style.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/modules.css" rel="stylesheet" />
<link href="<?= $BASE ?>/css/vendor_portal_saas.css" rel="stylesheet" />
<style>
  body{background:#f6f7fb}
  .page-wrap{max-width:980px;margin:40px auto;padding:0 16px}
  .card{border-radius:18px}
  .hero{
    background: linear-gradient(135deg,#f6f1ff 0%, #ffffff 60%);
    border: 1px solid #ece7ff;
    border-radius: 18px;
    padding: 22px 24px;
    box-shadow: 0 10px 30px rgba(78, 52, 201, .08);
  }
  .section-title{font-weight:700; letter-spacing:.2px}
  .muted{color:#6b7280}
  .doc h5{margin-top:20px}
  .doc ol li, .doc ul li{margin-bottom:6px}
  .callout{
    background:#f8f7ff;border:1px solid #ebe7ff;border-radius:12px;padding:12px 14px
  }
</style>
</head>
<body class="vendor-saas">
  <div class="page-wrap">
    <div class="hero mb-4">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <h3 class="m-0">TNVS Vendor Contract & Privacy Policy</h3>
          <div class="muted small">Effective date: <?= date('F j, Y') ?></div>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="javascript:history.back()">Back</a>
      </div>
      <div class="mt-3 muted">
        This page summarizes the Vendor Contract and Privacy Policy applicable to all vendors engaging with TNVS.
        The full agreement and policy are binding upon submission and approval.
      </div>
    </div>

    <div class="card shadow-sm mb-3 doc">
      <div class="card-body">
        <div class="section-title mb-2">Vendor Contract</div>
        <p class="muted">This contract governs the commercial relationship between TNVS and the vendor.</p>

        <h5>1. Scope of Engagement</h5>
        <ul>
          <li>The vendor agrees to provide goods and/or services as described in RFQs, POs, or approved quotations.</li>
          <li>The scope includes supply, delivery coordination, and after‑sales support when applicable.</li>
          <li>TNVS may update scope requirements in writing; the vendor will be notified of any material changes.</li>
        </ul>

        <h5>2. Quotation and Award</h5>
        <ul>
          <li>All quotations must be accurate, complete, and include pricing, specifications, lead time, and terms.</li>
          <li>Awarded quotations are binding unless revised in writing and approved by TNVS.</li>
          <li>TNVS may partially award line items to different vendors when required.</li>
        </ul>

        <h5>3. Delivery, Quality, and Acceptance</h5>
        <ul>
          <li>Deliverables must meet TNVS quality standards and agreed specifications.</li>
          <li>Delivery schedules must be honored; delays must be communicated immediately.</li>
          <li>TNVS may inspect deliveries and reject items that are defective or non‑compliant.</li>
        </ul>

        <h5>4. Compliance and Documentation</h5>
        <ul>
          <li>Vendors must maintain valid business registration, permits, and tax compliance.</li>
          <li>Required documents may include DTI/SEC, BIR, mayor’s permit, bank proof, and similar records.</li>
          <li>TNVS may request updates or re‑verification at any time.</li>
        </ul>

        <h5>5. Pricing, Invoicing, and Payment</h5>
        <ul>
          <li>Pricing shall follow the awarded quotation or PO terms.</li>
          <li>Invoices must reflect actual delivered quantities and accepted items.</li>
          <li>Payment terms are subject to TNVS approval and may vary per procurement.</li>
        </ul>

        <h5>6. Confidentiality</h5>
        <ul>
          <li>Business, procurement, and operational information disclosed by TNVS is confidential.</li>
          <li>Confidential information may not be shared with third parties without written consent.</li>
        </ul>

        <h5>7. Audit and Right to Review</h5>
        <ul>
          <li>TNVS may audit vendor submissions, performance, and compliance documents.</li>
          <li>Vendors may be approved, suspended, or removed based on compliance and performance.</li>
        </ul>

        <h5>8. Termination</h5>
        <ul>
          <li>TNVS may terminate the vendor relationship for non‑compliance, fraud, or repeated performance issues.</li>
          <li>Pending obligations and accepted POs remain enforceable unless otherwise agreed.</li>
        </ul>

        <h5>9. Vendor Process in Logistics 1</h5>
        <ul>
          <li>Vendor identification and screening for capability and fit.</li>
          <li>Verification of required goods/services availability.</li>
          <li>Collection of vendor documents, including:
            <ul>
              <li>Company profile</li>
              <li><strong>Product list / item catalog</strong> (to confirm available items)</li>
              <li>Price list</li>
              <li>Contact information</li>
            </ul>
          </li>
        </ul>
      </div>
    </div>

    <div class="card shadow-sm mb-3 doc">
      <div class="card-body">
        <div class="section-title mb-2">Privacy Policy</div>
        <p class="muted">This policy explains how TNVS collects, uses, and protects vendor data.</p>

        <h5>1. Information We Collect</h5>
        <ul>
          <li>Business profile: company name, registration details, permits, and tax documents.</li>
          <li>Contact details: authorized representatives, phone numbers, email addresses.</li>
          <li>Operational data: quotations, pricing, delivery notes, and compliance documents.</li>
        </ul>

        <h5>2. How We Use Information</h5>
        <ul>
          <li>Vendor evaluation, onboarding, and compliance verification.</li>
          <li>Procurement processing, PO issuance, and delivery coordination.</li>
          <li>Performance monitoring and reporting.</li>
        </ul>

        <h5>3. Data Sharing</h5>
        <ul>
          <li>Access is limited to authorized TNVS staff and systems.</li>
          <li>Data may be shared with logistics partners only when required for delivery execution.</li>
        </ul>

        <h5>4. Data Retention</h5>
        <ul>
          <li>Records are retained as required by business operations and legal obligations.</li>
          <li>Data may be archived for audit and compliance purposes.</li>
        </ul>

        <h5>5. Vendor Rights</h5>
        <ul>
          <li>Vendors may request access to, or correction of, their information.</li>
          <li>Requests should be sent through the TNVS vendor support channel.</li>
        </ul>

        <h5>6. Security</h5>
        <ul>
          <li>TNVS applies administrative and technical safeguards to protect data.</li>
          <li>Vendors are responsible for securing their own credentials and access.</li>
        </ul>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <div class="section-title mb-2">Acceptance</div>
        <div class="callout">
          By submitting your application or accepting a PO, you confirm that you have read, understood, and agreed to the TNVS Vendor Contract and Privacy Policy.
        </div>
      </div>
    </div>
  </div>
</body>
</html>
