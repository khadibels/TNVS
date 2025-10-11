<?php
if (!defined('BASE_URL')) {
    define('BASE_URL', '');
}
$effective = date('F 1, Y');
?>

<style>
  #legalModal .modal-content {
    border: none;
    border-radius: 0.75rem;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
  }

  #legalModal .modal-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    padding: 1rem 1.5rem;
  }

  #legalModal .modal-title {
    font-weight: 600;
    color: #343a40;
  }

  #legalModal .nav-tabs {
    border-bottom: 1px solid #dee2e6;
    padding: 0 1rem;
  }

  #legalModal .nav-tabs .nav-link {
    border: none;
    border-bottom: 3px solid transparent;
    color: #6c757d;
    font-weight: 500;
    padding: 1rem .75rem;
    margin-bottom: -1px;
    transition: color 0.2s ease, border-color 0.2s ease;
    display: flex;
    align-items: center;
    gap: .5rem;
  }

  #legalModal .nav-tabs .nav-link:hover {
    color: #495057;
  }

  #legalModal .nav-tabs .nav-link.active {
    color: #6c2bd9;
    border-color: #6c2bd9;
    background-color: transparent;
  }

  #legalModal .tab-content {
    padding: 1.5rem 1.75rem;
    line-height: 1.7;
    color: #495057;
  }

  #legalModal h5 {
    font-weight: 600;
    color: #343a40;
    margin-top: 1.5rem;
    margin-bottom: 0.75rem;
  }
  
  #legalModal h5:first-child {
      margin-top: 0;
  }

  #legalModal h6 {
    font-weight: 600;
    color: #495057;
    margin-top: 1.5rem;
  }
  
  #legalModal strong {
    font-weight: 500;
    color: #343a40;
  }

  #legalModal .modal-body ul, 
  #legalModal .modal-body ol {
      padding-left: 1.5rem;
  }

  #legalModal .modal-footer {
    background-color: #f8f9fa;
    border-top: 1px solid #dee2e6;
  }

  #legalModal .modal-footer .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      font-weight: 500;
  }

  @media (max-width: 576px) {
    #legalModal .nav-tabs { padding: 0 .25rem; }
    #legalModal .nav-tabs .nav-link {
      padding: 0.75rem 0.5rem;
      font-size: 0.85rem;
    }
    #legalModal .tab-content {
      padding: 1.25rem;
    }
    #legalModal .modal-footer {
      flex-direction: column;
      align-items: stretch;
    }
    #legalModal .modal-footer .btn {
        width: 100%;
        justify-content: center;
    }
  }
</style>

<div class="modal fade" id="legalModal" tabindex="-1" aria-labelledby="legalModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="legalModalLabel">Privacy Policy & Terms</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-0">
        <ul class="nav nav-tabs nav-fill" id="newLegalTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="policy-tab-btn" data-bs-toggle="tab" data-bs-target="#tab-policy-content" type="button" role="tab" aria-controls="tab-policy-content" aria-selected="true">
              <ion-icon name="shield-checkmark-outline"></ion-icon> Privacy Policy
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="terms-tab-btn" data-bs-toggle="tab" data-bs-target="#tab-terms-content" type="button" role="tab" aria-controls="tab-terms-content" aria-selected="false">
              <ion-icon name="document-text-outline"></ion-icon> Terms of Use
            </button>
          </li>
        </ul>

        <div class="tab-content" id="newLegalTabsContent">

          <div class="tab-pane fade show active" id="tab-policy-content" role="tabpanel" aria-labelledby="policy-tab-btn">
            <p class="text-muted mb-1 small"><strong>Effective date:</strong> <?= htmlspecialchars($effective) ?></p>
            <h5>Privacy Policy (All Modules)</h5>
            <p>
              This Privacy Policy explains how we collect, use, share, and protect information across the ViaHale platform,
              including the five modules: Procurement, Warehousing, Project/PLT, Asset Lifecycle, and Document Tracking.
              By using the system, you consent to this Policy and our Terms of Use.
            </p>

            <h6>1) Information We Collect</h6>
            <ul>
              <li><strong>Account & Identity:</strong> name, email, role, company, vendor details, profile data.</li>
              <li><strong>Operational Data:</strong> RFQs, quotes, POs, inventory, project assignments, asset logs, document metadata and audit trails.</li>
              <li><strong>Technical:</strong> device/browser data, IP, logs, timestamps, and usage analytics for security and performance.</li>
            </ul>

            <h6>2) How We Use Information</h6>
            <ul>
              <li>Authenticate users and authorize access per role/module.</li>
              <li>Operate each module’s workflows (e.g., RFQs/quotes, inventory updates, project tasks, asset registries, document routing).</li>
              <li>Maintain security, prevent fraud, audit activity, and comply with law/policies.</li>
              <li>Improve features, troubleshoot issues, and support operations.</li>
            </ul>

            <h6>3) Sharing</h6>
            <ul>
              <li>With authorized internal users and stakeholders (need-to-know basis).</li>
              <li>With vendors in the context of RFQs, awards, or performance updates.</li>
              <li>With service providers that support hosting, security, logging, or analytics (subject to confidentiality).</li>
              <li>When legally required (court order, compliance, safety).</li>
            </ul>

            <h6>4) Retention</h6>
            <p>We retain records as needed for business, audit, and compliance. Data may be archived or anonymized after operational need ends.</p>

            <h6>5) Your Rights</h6>
            <p>Subject to applicable laws/contracts, you may request access, correction, or deletion of your personal data. Some records must be retained for legal or audit reasons.</p>

            <h6>6) Security</h6>
            <p>We employ reasonable administrative, technical, and physical safeguards. No system is 100% secure; users must follow company security policies.</p>
            
            <h6>Contact</h6>
            <p>Email: example@viahale.com</p>
            
            <hr class="my-4">

            <h5>Module Addendum: Procurement</h5>
            <p><strong>Data Types:</strong> RFQs, invited suppliers, quotes (totals, terms, lead times), awards, PO references, audit logs.</p>
            <p><strong>Purpose:</strong> Sourcing, vendor management, awarding, compliance, reporting.</p>
            <p><strong>Visibility:</strong> Procurement staff and stakeholders with access rights; vendors see only their invited RFQs and submitted quotes.</p>

            <h5>Module Addendum: Warehousing</h5>
            <p><strong>Data Types:</strong> stock items, bin locations, receipts/issues, counts, movement history, user actions.</p>
            <p><strong>Purpose:</strong> Inventory accuracy, reconciliation, fulfillment, audits.</p>
            <p><strong>Visibility:</strong> Warehouse/authorized roles; logs retained for audit.</p>

            <h5>Module Addendum: Project / PLT</h5>
            <p><strong>Data Types:</strong> project metadata, tasks, assignments, progress logs, cost references, communications tags.</p>
            <p><strong>Purpose:</strong> Project tracking, accountability, and performance reporting.</p>
            <p><strong>Visibility:</strong> Project roles and managers; limited to project scope.</p>

            <h5>Module Addendum: Asset Lifecycle</h5>
            <p><strong>Data Types:</strong> asset registry, ownership, maintenance history, inspections, depreciation references.</p>
            <p><strong>Purpose:</strong> Lifecycle tracking, maintenance planning, finance and compliance reporting.</p>
            <p><strong>Visibility:</strong> Asset managers and authorized roles; audit trails retained.</p>

            <h5>Module Addendum: Document Tracking</h5>
            <p><strong>Data Types:</strong> document metadata, versioning, routing, approvals, timestamps, user actions.</p>
            <p><strong>Purpose:</strong> Controlled circulation, traceability, compliance.</p>
            <p><strong>Visibility:</strong> Document controllers and permitted users per document ACLs.</p>
          </div>

          <div class="tab-pane fade" id="tab-terms-content" role="tabpanel" aria-labelledby="terms-tab-btn">
            <h5>Terms of Use</h5>
            <ol class="mb-0">
              <li><strong>Acceptable Use:</strong> Use only for authorized business purposes; no reverse engineering, scraping, or abusive behavior.</li>
              <li><strong>Accounts:</strong> Keep credentials confidential; you’re responsible for actions under your account.</li>
              <li><strong>Access Control:</strong> You must respect roles and data boundaries; do not attempt to access data you’re not authorized to see.</li>
              <li><strong>Content & Records:</strong> Operational records created in the system are company property unless otherwise agreed.</li>
              <li><strong>Availability & Changes:</strong> Features may evolve; we may suspend access for maintenance or security.</li>
              <li><strong>Liability:</strong> Provided “as is” to the extent permitted by law; internal policies govern remediation and support.</li>
              <li><strong>Governing Law:</strong> As specified by your organization’s policy/contract.</li>
            </ol>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <a class="btn btn-outline-secondary" href="<?= rtrim(BASE_URL, '/') ?>/includes/legal/privacy.php" target="_blank" rel="noopener">Open Privacy Page</a>
        <a class="btn btn-outline-secondary" href="<?= rtrim(BASE_URL, '/') ?>/includes/legal/terms.php" target="_blank" rel="noopener">Open Terms Page</a>
        <button class="btn btn-primary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>