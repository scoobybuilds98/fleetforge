<?php
declare(strict_types=1);

/**
 * app/admin/credit_applications/show.php
 *
 * Admin view of a submitted credit application (D-CCA-3-F).
 *
 * Renders:
 *   - The stored rendered_html snapshot (frozen legal record)
 *   - Metadata sidebar: status, submitted_at, signer name, IP, terms_version
 *   - Download PDF button (signed URL via StorageClient, if PDF exists)
 *   - "Regenerate PDF" button (for rows where generated_pdf_document_id IS NULL)
 *
 * Permission: customers:view (D-CCA-PERM)
 * Review controls (approve/decline/credit_limit writes) are S-CCA-4 scope.
 * Trap 7: rendered_html is rendered server-side as-is (already sanitised at
 * submit time from validated form fields + trusted settings HTML).
 * NO token_hash, signature_path, or file_path exposed to client.
 *
 * @method  GET
 * @query   id (int) — customer_credit_applications.id
 * @auth    Session; customers:view
 * @session S-CCA-3
 */

// dirname(__DIR__, 3): app/admin/credit_applications/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\Storage\StorageClient;

require_auth();
require_permission('customers', 'view');

// ── Resolve application ID ─────────────────────────────────────────────────
$appId = clean_int($_GET['id'] ?? null);
if (!$appId || $appId <= 0) {
    header('Location: ' . base_url('customers'));
    exit;
}

// ── Load application + customer ────────────────────────────────────────────
$app = db_row(
    "SELECT ca.id, ca.customer_id, ca.status, ca.review_outcome,
            ca.rendered_html, ca.generated_pdf_document_id,
            ca.print_name_first, ca.print_name_last, ca.signed_date,
            ca.terms_accepted, ca.terms_version, ca.terms_url,
            ca.submitted_at, ca.submitted_ip, ca.submitted_user_agent,
            ca.opened_at, ca.created_at, ca.reviewed_at,
            ca.approved_credit_limit, ca.review_notes,
            ca.updated_at, ca.form_data, ca.reviewed_by,
            c.company_name AS customer_company_name,
            c.updated_at AS customer_updated_at, c.credit_limit AS customer_credit_limit,
            c.status AS customer_status,
            rb.name AS reviewed_by_name
       FROM customer_credit_applications ca
       JOIN customers c ON c.id = ca.customer_id
       LEFT JOIN users rb ON rb.id = ca.reviewed_by
      WHERE ca.id = ? AND ca.deleted_at IS NULL",
    [$appId]
);

if (!$app) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Credit Application Not Found</h1>';
    exit;
}

// ── Signed URL for the PDF download (Trap 7 — no file_path to client) ─────
$pdfUrl = null;
if ($app['generated_pdf_document_id'] !== null) {
    $docRow = db_row(
        "SELECT file_path FROM documents WHERE id = ? AND deleted_at IS NULL",
        [(int)$app['generated_pdf_document_id']]
    );
    if ($docRow && $docRow['file_path'] !== null) {
        try {
            $pdfUrl = StorageClient::url((string)$docRow['file_path'], 3600);
        } catch (\Throwable $e) {
            error_log('[S-CCA-3] PDF URL generation failed for app ' . $appId . ': ' . $e->getMessage());
        }
    }
}

// ── Extract credit_requested from form_data (read-only reference for reviewers) ──
// D-CCA-4: never written back to customers — shown for REFERENCE ONLY in the review panel.
$creditRequested = null;
if (!empty($app['form_data'])) {
    $fd = json_decode((string) $app['form_data'], true);
    $creditRequested = $fd['credit_requested'] ?? null;
}

// ── Review panel visibility ─────────────────────────────────────────────────
$canReview = can('customers', 'edit')
    && in_array($app['status'], ['submitted', 'reviewed'], true);

// ── Status badge helpers ────────────────────────────────────────────────────
$statusLabels = [
    'sent'      => 'Sent',
    'opened'    => 'Opened',
    'submitted' => 'Submitted',
    'reviewed'  => 'Reviewed',
];
$statusClasses = [
    'sent'      => 'badge-info',
    'opened'    => 'badge-warning',
    'submitted' => 'badge-primary',
    'reviewed'  => 'badge-success',
];
$outcomeLabels = [
    'approved'   => 'Approved',
    'declined'   => 'Declined',
    'needs_info' => 'Needs Info',
];
$outcomeClasses = [
    'approved'   => 'badge-success',
    'declined'   => 'badge-danger',
    'needs_info' => 'badge-warning',
];

$pageTitle      = 'Credit Application — ' . e($app['customer_company_name']);
$helpModuleSlug = 'customers';
require_once FF_ROOT . '/includes/header.php';
?>

<?php
// Detect whether we came from the module index or the customer profile.
// ?from=customer  → back button goes to customers/show?tab=credit_applications
// default (or ?from=module) → back button goes to credit_applications module
$fromCustomer = ($_GET['from'] ?? '') === 'customer';
$backUrl = $fromCustomer
    ? base_url('customers/show') . '?id=' . (int)$app['customer_id'] . '&tab=credit_applications'
    : base_url('credit_applications');
$backLabel = $fromCustomer ? '← Back to Customer' : '← All Applications';
?>

<style>
/* Credit-application show: two-column layout + mobile collapse (S-FIX-CREDIT-APP-SHOW-MOBILE) */
.cca-show-grid {
    display: grid;
    grid-template-columns: 1fr 280px;
    gap: 20px;
    align-items: start;
    margin-top: 20px;
}
@media (max-width: 767px) {
    .cca-show-grid {
        grid-template-columns: 1fr;
    }
    /* WHY: grid child won't shrink below iframe content width without min-width:0
       (the classic CSS grid blowout fix — iframe has an intrinsic width that
       overrides the 1fr constraint unless the column itself opts out of it). */
    .cca-snapshot-col {
        min-width: 0;
    }
}
</style>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('credit_applications') ?>">Credit Applications</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('customers/show') . '?id=' . (int)$app['customer_id'] ?>">
        <?= e($app['customer_company_name']) ?>
    </a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Application #<?= (int)$appId ?></span>
</nav>

<div class="page-header">
    <div>
        <h1 class="page-title">Credit Application #<?= (int)$appId ?></h1>
        <p class="text-secondary" style="margin:4px 0 0;">
            <?= e($app['customer_company_name']) ?>
        </p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        <?php if ($pdfUrl): ?>
        <a href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener" class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>
            </svg>
            Download PDF
        </a>
        <?php elseif ($app['status'] === 'submitted' || $app['status'] === 'reviewed'): ?>
        <?php if (can('customers', 'edit')): ?>
        <button class="btn btn-ghost" id="btn-regen-pdf" onclick="regenPdf()">
            Generate PDF
        </button>
        <?php endif; ?>
        <?php endif; ?>
        <a href="<?= e($backUrl) ?>" class="btn btn-ghost">
            <?= e($backLabel) ?>
        </a>
    </div>
</div>

<div class="cca-show-grid">

    <!-- Left: filed application HTML form -->
    <div class="card cca-snapshot-col">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <div>
                <h3 class="card-title">Filed Application</h3>
                <p class="text-sm text-secondary" style="margin:4px 0 0;">
                    The HTML form the customer submitted — frozen legal record.
                </p>
            </div>
            <?php if (!empty($app['rendered_html'])): ?>
            <a href="<?= base_url('credit_applications/snapshot') ?>?id=<?= (int)$appId ?>"
               target="_blank" rel="noopener"
               class="btn btn-ghost btn-sm" title="Open form in new tab">
                ↗ Open full page
            </a>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding:0;overflow-x:auto;">
            <?php if (!empty($app['rendered_html'])): ?>
            <!-- WHY src not srcdoc: srcdoc stuffs the full HTML document into a
                 single HTML attribute after htmlspecialchars escaping — causes
                 browser size limits and double-encoding issues for large forms.
                 snapshot.php serves rendered_html as a proper text/html response
                 with tight CSP (no external resources, data: URIs only). -->
            <iframe
                id="cca-snapshot-frame"
                src="<?= base_url('credit_applications/snapshot') ?>?id=<?= (int)$appId ?>"
                style="width:100%;border:0;min-height:1000px;display:block;"
                title="Filed Credit Application"
                onload="this.style.minHeight = (this.contentWindow.document.body ? this.contentWindow.document.body.scrollHeight + 40 : 1000) + 'px'">
            </iframe>
            <?php else: ?>
            <div style="padding:40px;text-align:center;color:var(--text-secondary);">
                <p style="margin:0 0 8px;font-size:0.9375rem;">No HTML snapshot stored for this application.</p>
                <p style="font-size:0.8125rem;margin:0;">
                    The form data is intact — use
                    <?= can('customers', 'edit') ? '"Regenerate PDF"' : 'an admin' ?>
                    to rebuild from stored form fields.
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: metadata sidebar -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- Status card -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">Status</h3></div>
            <div class="card-body" style="padding:16px;">
                <div style="margin-bottom:12px;">
                    <span class="badge <?= e($statusClasses[$app['status']] ?? 'badge-neutral') ?>">
                        <?= e($statusLabels[$app['status']] ?? ucfirst($app['status'])) ?>
                    </span>
                    <?php if ($app['review_outcome']): ?>
                    &nbsp;<span class="badge <?= e($outcomeClasses[$app['review_outcome']] ?? 'badge-neutral') ?>">
                        <?= e($outcomeLabels[$app['review_outcome']] ?? $app['review_outcome']) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <table style="width:100%;font-size:13px;border-collapse:collapse;">
                    <?php if ($app['submitted_at']): ?>
                    <tr><td style="color:var(--text-secondary);padding:3px 8px 3px 0;">Submitted</td>
                        <td><?= e(date('M j, Y g:i A', strtotime($app['submitted_at']))) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($app['reviewed_at']): ?>
                    <tr><td style="color:var(--text-secondary);padding:3px 8px 3px 0;">Reviewed</td>
                        <td><?= e(date('M j, Y g:i A', strtotime($app['reviewed_at']))) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Signer details card -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">Signer Details</h3></div>
            <div class="card-body" style="padding:16px;">
                <table style="width:100%;font-size:13px;border-collapse:collapse;">
                    <tr><td style="color:var(--text-secondary);padding:3px 8px 3px 0;vertical-align:top;">Name</td>
                        <td><?= e(trim($app['print_name_first'] . ' ' . $app['print_name_last'])) ?: '—' ?></td></tr>
                    <tr><td style="color:var(--text-secondary);padding:3px 8px 3px 0;vertical-align:top;">Signed Date</td>
                        <td><?= e($app['signed_date'] ?? '—') ?></td></tr>
                    <tr><td style="color:var(--text-secondary);padding:3px 8px 3px 0;vertical-align:top;">Terms Accepted</td>
                        <td><?= $app['terms_accepted'] ? 'Yes' : 'No' ?></td></tr>
                    <tr><td style="color:var(--text-secondary);padding:3px 8px 3px 0;vertical-align:top;">Terms Version</td>
                        <td><?= e($app['terms_version'] ?? '—') ?></td></tr>
                    <?php if ($app['terms_url']): ?>
                    <tr><td style="color:var(--text-secondary);padding:3px 8px 3px 0;vertical-align:top;">Terms URL</td>
                        <td><a href="<?= e($app['terms_url']) ?>" target="_blank" rel="noopener" style="font-size:11px;">View</a></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Submission audit card -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">Submission Audit</h3></div>
            <div class="card-body" style="padding:16px;">
                <table style="width:100%;font-size:12px;border-collapse:collapse;">
                    <tr><td style="color:var(--text-secondary);padding:3px 8px 3px 0;vertical-align:top;">IP Address</td>
                        <td style="word-break:break-all;"><?= e($app['submitted_ip'] ?? '—') ?></td></tr>
                    <?php if ($app['submitted_user_agent']): ?>
                    <tr><td style="color:var(--text-secondary);padding:3px 8px 3px 0;vertical-align:top;">User Agent</td>
                        <td style="word-break:break-all;font-size:11px;"><?= e(substr($app['submitted_user_agent'], 0, 100)) ?><?= strlen((string)$app['submitted_user_agent']) > 100 ? '…' : '' ?></td></tr>
                    <?php endif; ?>
                    <tr><td style="color:var(--text-secondary);padding:3px 8px 3px 0;vertical-align:top;">Link Sent</td>
                        <td><?= $app['created_at'] ? e(date('M j, Y', strtotime($app['created_at']))) : '—' ?></td></tr>
                    <tr><td style="color:var(--text-secondary);padding:3px 8px 3px 0;vertical-align:top;">Opened</td>
                        <td><?= $app['opened_at'] ? e(date('M j, Y g:i A', strtotime($app['opened_at']))) : '—' ?></td></tr>
                </table>
            </div>
        </div>

        <?php if ($canReview): ?>
        <!-- Review panel (S-CCA-4) — D-CCA-4-A: allowed on submitted+reviewed -->
        <div class="card" id="review-panel">
            <div class="card-header">
                <h3 class="card-title">Review</h3>
                <?php if ($app['review_outcome']): ?>
                <span class="badge <?= e($outcomeClasses[$app['review_outcome']] ?? 'badge-neutral') ?>">
                    <?= e($outcomeLabels[$app['review_outcome']] ?? $app['review_outcome']) ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="card-body" style="padding:16px;">

                <?php if ($creditRequested): ?>
                <!-- D-CCA-4: customer-entered value shown REFERENCE ONLY — never pre-fills inputs -->
                <div style="background:var(--color-surface-secondary);border:1px solid var(--color-border);border-radius:4px;padding:8px 12px;margin-bottom:14px;font-size:12px;">
                    <span style="color:var(--text-secondary);">Credit Requested (applicant):</span>
                    <strong style="margin-left:4px;"><?= e($creditRequested) ?></strong>
                    <span style="color:var(--text-secondary);font-size:11px;display:block;margin-top:2px;">Reference only — enter your own approved amount below.</span>
                </div>
                <?php endif; ?>

                <form id="review-form" onsubmit="submitReview(event)">
                    <input type="hidden" id="review-app-id" value="<?= (int)$appId ?>">
                    <input type="hidden" id="review-updated-at" value="<?= e($app['updated_at']) ?>">
                    <input type="hidden" id="review-customer-updated-at" value="<?= e($app['customer_updated_at']) ?>">

                    <!-- Outcome radio -->
                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">Outcome</label>
                        <label style="display:flex;align-items:center;gap:6px;margin-bottom:6px;cursor:pointer;">
                            <input type="radio" name="review_outcome" value="approved" onchange="reviewOutcomeChanged()"
                                   <?= $app['review_outcome'] === 'approved' ? 'checked' : '' ?>>
                            <span class="badge badge-success" style="pointer-events:none;">Approved</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;margin-bottom:6px;cursor:pointer;">
                            <input type="radio" name="review_outcome" value="declined" onchange="reviewOutcomeChanged()"
                                   <?= $app['review_outcome'] === 'declined' ? 'checked' : '' ?>>
                            <span class="badge badge-danger" style="pointer-events:none;">Declined</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="radio" name="review_outcome" value="needs_info" onchange="reviewOutcomeChanged()"
                                   <?= $app['review_outcome'] === 'needs_info' ? 'checked' : '' ?>>
                            <span class="badge badge-warning" style="pointer-events:none;">Needs Info</span>
                        </label>
                    </div>

                    <!-- Review notes -->
                    <div style="margin-bottom:12px;">
                        <label for="review_notes" style="display:block;font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:4px;">Notes (internal)</label>
                        <textarea id="review_notes" name="review_notes" rows="3"
                                  style="width:100%;resize:vertical;font-size:13px;"
                                  class="form-control" placeholder="Internal notes about this review…"><?= e($app['review_notes'] ?? '') ?></textarea>
                    </div>

                    <!-- Approved credit limit (always shown, more prominent when approved) -->
                    <div style="margin-bottom:12px;" id="review-credit-section">
                        <label for="approved_credit_limit" style="display:block;font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:4px;">Approved Credit Limit ($)</label>
                        <!-- D-CCA-4: input is EMPTY by default — never pre-filled from applicant data -->
                        <input type="number" id="approved_credit_limit" name="approved_credit_limit"
                               min="0" step="0.01"
                               value="<?= $app['approved_credit_limit'] !== null ? e((string)$app['approved_credit_limit']) : '' ?>"
                               class="form-control" style="font-size:13px;"
                               placeholder="Enter your approved limit…">
                    </div>

                    <!-- Opt-in: apply credit limit to customer record -->
                    <div style="margin-bottom:8px;padding:8px 10px;background:var(--color-surface-secondary);border-radius:4px;border:1px solid var(--color-border);">
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                            <input type="checkbox" id="apply_credit_limit" name="apply_credit_limit" style="margin-top:2px;" onchange="reviewOptinChanged()">
                            <span style="font-size:12px;">
                                <strong>Apply credit limit to customer record</strong><br>
                                <span style="color:var(--text-secondary);">Will update customers.credit_limit to the approved amount above.</span>
                            </span>
                        </label>
                    </div>

                    <!-- Opt-in: update customer status -->
                    <div style="margin-bottom:12px;padding:8px 10px;background:var(--color-surface-secondary);border-radius:4px;border:1px solid var(--color-border);">
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;margin-bottom:4px;">
                            <input type="checkbox" id="apply_customer_status" name="apply_customer_status" style="margin-top:2px;" onchange="reviewOptinChanged()">
                            <span style="font-size:12px;">
                                <strong>Update customer status</strong><br>
                                <span style="color:var(--text-secondary);">Current: <strong><?= e(ucfirst(str_replace('_', ' ', $app['customer_status'] ?? ''))) ?></strong></span>
                            </span>
                        </label>
                        <select id="customer_status" name="customer_status"
                                class="form-control" style="font-size:12px;margin-top:4px;display:none;"
                                disabled>
                            <?php foreach (['active','inactive','pending','suspended','credit_hold'] as $cs): ?>
                            <option value="<?= $cs ?>" <?= $app['customer_status'] === $cs ? 'selected' : '' ?>>
                                <?= ucfirst(str_replace('_', ' ', $cs)) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="review-error" style="display:none;color:var(--color-danger);font-size:12px;margin-bottom:8px;"></div>

                    <button type="submit" class="btn btn-primary btn-sm" id="review-submit-btn" style="width:100%;">
                        Save Review
                    </button>

                    <?php if ($app['review_outcome'] === 'needs_info'): ?>
                    <!-- Re-send: D-CCA-4-C — creates a NEW application row, review_notes carried as note -->
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-resend"
                            style="width:100%;margin-top:8px;" onclick="resendApplication()">
                        Re-send Application Link
                    </button>
                    <p style="font-size:11px;color:var(--text-secondary);margin:4px 0 0;">
                        Sends a fresh link. The notes above will be included in the email.
                    </p>
                    <?php endif; ?>

                </form>

                <?php if ($app['reviewed_at'] && $app['reviewed_by_name']): ?>
                <p style="font-size:11px;color:var(--text-secondary);margin:12px 0 0;padding-top:10px;border-top:1px solid var(--color-border);">
                    Last reviewed <?= e(date('M j, Y g:i A', strtotime($app['reviewed_at']))) ?>
                    by <?= e($app['reviewed_by_name']) ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- PDF document card (separate from HTML form view above) -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">PDF Document</h3>
                <p class="text-sm text-secondary" style="margin:4px 0 0;">
                    Stored in Documents — separate from the HTML form above.
                </p>
            </div>
            <div class="card-body" style="padding:16px;">
                <?php if ($pdfUrl): ?>
                <p style="font-size:13px;color:var(--color-success,#16a34a);font-weight:600;margin:0 0 10px;">
                    ✓ PDF stored in documents.
                </p>
                <a href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener"
                   class="btn btn-sm btn-primary" style="width:100%;text-align:center;display:block;">
                    Download PDF
                </a>
                <?php else: ?>
                <p style="font-size:13px;color:var(--text-secondary);margin:0 0 10px;">
                    PDF not yet generated.
                    <?php if (!empty($app['rendered_html'])): ?>
                    The HTML form above can be used to generate it.
                    <?php endif; ?>
                </p>
                <?php if (($app['status'] === 'submitted' || $app['status'] === 'reviewed') && can('customers', 'edit')): ?>
                <button class="btn btn-sm btn-secondary" style="width:100%;" onclick="regenPdf()" id="btn-regen-pdf-2">
                    Generate PDF from HTML
                </button>
                <p id="regen-status" style="font-size:12px;color:var(--text-secondary);margin:8px 0 0;display:none;"></p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /sidebar -->
</div>

<script>
// ── Review panel helpers ──────────────────────────────────────────────────
function reviewOutcomeChanged() {
    // Show/hide the Re-send button dynamically when outcome changes.
    // (The server-rendered Re-send button is shown based on the DB value;
    //  this function handles the dynamic state after a successful save.)
}

function reviewOptinChanged() {
    const applyStatus = document.getElementById('apply_customer_status');
    const statusSel   = document.getElementById('customer_status');
    if (!applyStatus || !statusSel) return;
    if (applyStatus.checked) {
        statusSel.style.display = '';
        statusSel.disabled = false;
    } else {
        statusSel.style.display = 'none';
        statusSel.disabled = true;
    }
}

async function submitReview(e) {
    e.preventDefault();
    const btn = document.getElementById('review-submit-btn');
    const err = document.getElementById('review-error');
    if (err) { err.style.display = 'none'; err.textContent = ''; }

    const outcome = document.querySelector('input[name="review_outcome"]:checked')?.value;
    if (!outcome) {
        if (err) { err.style.display = ''; err.textContent = 'Please select an outcome.'; }
        return;
    }

    const notes           = document.getElementById('review_notes')?.value ?? '';
    const approvedLimit   = document.getElementById('approved_credit_limit')?.value ?? '';
    const applyLimit      = document.getElementById('apply_credit_limit')?.checked ?? false;
    const applyStatus     = document.getElementById('apply_customer_status')?.checked ?? false;
    const custStatus      = document.getElementById('customer_status')?.value ?? '';
    const appId           = parseInt(document.getElementById('review-app-id')?.value ?? '0', 10);
    const updatedAt       = document.getElementById('review-updated-at')?.value ?? '';
    const custUpdatedAt   = document.getElementById('review-customer-updated-at')?.value ?? '';

    const payload = {
        id:             appId,
        updated_at:     updatedAt,
        review_outcome: outcome,
        review_notes:   notes || null,
    };
    if (approvedLimit !== '') { payload.approved_credit_limit = parseFloat(approvedLimit); }
    if (applyLimit) {
        payload.apply_credit_limit  = true;
        payload.customer_updated_at = custUpdatedAt;
    }
    if (applyStatus) {
        payload.apply_customer_status = true;
        payload.customer_status       = custStatus;
        payload.customer_updated_at   = custUpdatedAt;
    }

    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
    try {
        const res  = await fetch('<?= base_url('api/v1/credit_applications/review') ?>', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (res.ok && json.success) {
            // Reload page to reflect updated state (new outcome badge, reviewed_at, Re-send button)
            window.location.reload();
        } else {
            const msg = json.error?.message ?? 'Failed to save review.';
            if (err) { err.style.display = ''; err.textContent = msg; }
            if (btn) { btn.disabled = false; btn.textContent = 'Save Review'; }
        }
    } catch (ex) {
        if (err) { err.style.display = ''; err.textContent = 'Network error. Please try again.'; }
        if (btn) { btn.disabled = false; btn.textContent = 'Save Review'; }
    }
}

async function resendApplication() {
    const btn   = document.getElementById('btn-resend');
    const notes = document.getElementById('review_notes')?.value ?? '';

    if (!confirm('Send a new credit-application link to this customer? The previous application stays on record.')) return;
    if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }

    try {
        const res  = await fetch('<?= base_url('api/v1/credit_applications/send') ?>', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                customer_id:  <?= (int)$app['customer_id'] ?>,
                resend_note:  notes || null,
            }),
        });
        const json = await res.json();
        if (res.ok && json.success) {
            const msg = json.data?.email_sent
                ? 'New application link sent to customer.'
                : 'Application created, but the email could not be sent: ' + (json.data?.email_error ?? 'unknown error');
            alert(msg);
            // Navigate to the customer's Credit Application tab to see the new row
            window.location.href = '<?= base_url('customers/show') . '?id=' . (int)$app['customer_id'] ?>&tab=credit_applications';
        } else {
            alert(json.error?.message ?? 'Failed to send application.');
            if (btn) { btn.disabled = false; btn.textContent = 'Re-send Application Link'; }
        }
    } catch (ex) {
        alert('Network error. Please try again.');
        if (btn) { btn.disabled = false; btn.textContent = 'Re-send Application Link'; }
    }
}

function regenPdf() {
    const btns = document.querySelectorAll('#btn-regen-pdf, #btn-regen-pdf-2');
    btns.forEach(b => { b.disabled = true; b.textContent = 'Regenerating…'; });
    const status = document.getElementById('regen-status');

    fetch('<?= base_url('api/v1/credit_applications/generate_pdf') ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? ''
        },
        body: JSON.stringify({ id: <?= (int)$appId ?> })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (status) { status.style.display = 'block'; status.textContent = 'PDF regenerated. Reload to download.'; }
            setTimeout(() => window.location.reload(), 1200);
        } else {
            const msg = data.error?.message ?? 'Regeneration failed.';
            if (status) { status.style.display = 'block'; status.textContent = msg; }
            btns.forEach(b => { b.disabled = false; b.textContent = 'Regenerate PDF'; });
        }
    })
    .catch(() => {
        if (status) { status.style.display = 'block'; status.textContent = 'Network error. Please try again.'; }
        btns.forEach(b => { b.disabled = false; b.textContent = 'Regenerate PDF'; });
    });
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
