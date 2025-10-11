<?php
if (!defined('BASE_URL')) { define('BASE_URL',''); }
$effective = date('F j, Y');
?>
<!-- Legal Modal -->
<div class="modal fade" id="legalModal" tabindex="-1" aria-labelledby="legalModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title" id="legalModalLabel">Privacy Policy & Terms</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-0">
        <div class="row g-0">
          <div class="col-12 col-md-3 border-end">
            <div class="p-3">
              <div class="fw-semibold small text-muted mb-2">Sections</div>
              <ul class="nav nav-pills flex-column gap-1" id="legalTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button">Overview</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-procurement" type="button">Procurement</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-warehousing" type="button">Warehousing</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-plt" type="button">Project / PLT</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-asset" type="button">Asset Lifecycle</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-docs" type="button">Document Tracking</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-terms" type="button">Terms of Use</button></li>
              </ul>
            </div>
          </div>

          <div class="col-12 col-md-9">
            <div class="tab-content p-4">

              <!-- OVERVIEW -->
              <div class="tab-pane fade show active" id="tab-overview">
                <p class="text-muted mb-1"><strong>Effective date:</strong> <?= htmlspecialchars($effective) ?></p>
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
              </div>

              <!-- PROCUREMENT -->
              <div class="tab-pane fade" id="tab-procurement">
                <h5>Module Addendum: Procurement</h5>
                <p><strong>Data Types:</strong> RFQs, invited suppliers, quotes (totals, terms, lead times), awards, PO references, audit logs.</p>
                <p><strong>Purpose:</strong> Sourcing, vendor management, awarding, compliance, reporting.</p>
                <p><strong>Visibility:</strong> Procurement staff and stakeholders with access rights; vendors see only their invited RFQs and submitted quotes.</p>
              </div>

              <!-- WAREHOUSING -->
              <div class="tab-pane fade" id="tab-warehousing">
                <h5>Module Addendum: Warehousing</h5>
                <p><strong>Data Types:</strong> stock items, bin locations, receipts/issues, counts, movement history, user actions.</p>
                <p><strong>Purpose:</strong> Inventory accuracy, reconciliation, fulfillment, audits.</p>
                <p><strong>Visibility:</strong> Warehouse/authorized roles; logs retained for audit.</p>
              </div>

              <!-- PROJECT / PLT -->
              <div class="tab-pane fade" id="tab-plt">
                <h5>Module Addendum: Project / PLT</h5>
                <p><strong>Data Types:</strong> project metadata, tasks, assignments, progress logs, cost references, communications tags.</p>
                <p><strong>Purpose:</strong> Project tracking, accountability, and performance reporting.</p>
                <p><strong>Visibility:</strong> Project roles and managers; limited to project scope.</p>
              </div>

              <!-- ASSET LIFECYCLE -->
              <div class="tab-pane fade" id="tab-asset">
                <h5>Module Addendum: Asset Lifecycle</h5>
                <p><strong>Data Types:</strong> asset registry, ownership, maintenance history, inspections, depreciation references.</p>
                <p><strong>Purpose:</strong> Lifecycle tracking, maintenance planning, finance and compliance reporting.</p>
                <p><strong>Visibility:</strong> Asset managers and authorized roles; audit trails retained.</p>
              </div>

              <!-- DOCUMENT TRACKING -->
              <div class="tab-pane fade" id="tab-docs">
                <h5>Module Addendum: Document Tracking</h5>
                <p><strong>Data Types:</strong> document metadata, versioning, routing, approvals, timestamps, user actions.</p>
                <p><strong>Purpose:</strong> Controlled circulation, traceability, compliance.</p>
                <p><strong>Visibility:</strong> Document controllers and permitted users per document ACLs.</p>
              </div>

              <!-- TERMS -->
              <div class="tab-pane fade" id="tab-terms">
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
        </div>
      </div>

      <div class="modal-footer">
        <a class="btn btn-outline-secondary" href="<?= rtrim(BASE_URL,'/') ?>/includes/legal/privacy.php" target="_blank" rel="noopener">Open Privacy Page</a>
        <a class="btn btn-outline-secondary" href="<?= rtrim(BASE_URL,'/') ?>/includes/legal/terms.php" target="_blank" rel="noopener">Open Terms Page</a>
        <button class="btn btn-primary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
