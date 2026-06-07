<?php
declare(strict_types=1);

/**
 * app/portal/credit-applications/view.php
 *
 * Portal credit application detail — the frozen rendered_html snapshot
 * of what the customer submitted, plus the review outcome and PDF download.
 *
 * Only accessible for status = 'submitted' | 'reviewed' (apps with
 * rendered_html populated). Sent/opened apps have no content to show.
 *
 * Trap 7: token_hash, signature_path, submitted_ip, submitted_user_agent,
 *         review_notes, reviewed_by, form_data are NEVER selected or emitted.
 * Trap 8: WHERE customer_id = ? enforced on every query.
 *
 * @session S-PORTAL-CCA-VIEW
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

use FleetForge\Storage\StorageClient;

$cid   = portal_customer_id();
$appId = clean_int($_GET['id'] ?? null);

if (!$appId) {
    header('Location: ' . base_url('portal/credit-applications'));
    exit;
}

// Load — Trap 7: only safe columns; Trap 8: customer_id filter
$app = db_row(
    "SELECT ca.id, ca.status, ca.review_outcome,
            ca.approved_credit_limit,
            ca.rendered_html,
            ca.generated_pdf_document_id,
            ca.print_name_first, ca.print_name_last,
            ca.signed_date, ca.terms_accepted,
            ca.sent_at, ca.submitted_at, ca.reviewed_at,
            ca.created_at
       FROM customer_credit_applications ca
      WHERE ca.id = ? AND ca.customer_id = ? AND ca.deleted_at IS NULL",
    [$appId, $cid]
);

if (!$app) {
    header('Location: ' . base_url('portal/credit-applications'));
    exit;
}

// Only show detail for submitted/reviewed apps (rendered_html is only
// populated on submit; sent/opened have nothing to display).
if (!in_array($app['status'], ['submitted', 'reviewed'], true)) {
    header('Location: ' . base_url('portal/credit-applications'));
    exit;
}

// ── PDF signed download URL (Trap 7: file_path never sent to client) ─────────
$pdfUrl = null;
if (!empty($app['generated_pdf_document_id'])) {
    $docRow = db_row(
        "SELECT file_path FROM documents WHERE id = ? AND deleted_at IS NULL",
        [(int) $app['generated_pdf_document_id']]
    );
    if ($docRow && !empty($docRow['file_path'])) {
        try {
            $pdfUrl = StorageClient::url((string) $docRow['file_path'], 3600);
        } catch (\Throwable $e) {
            error_log('[portal CCA view] PDF URL failed for app ' . $appId . ': ' . $e->getMessage());
        }
    }
}

$isApproved = $app['status'] === 'reviewed' && $app['review_outcome'] === 'approved';

$pageTitle = 'Credit Application';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= e(base_url('portal')) ?>">Portal</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= e(base_url('portal/credit-applications')) ?>">Credit Application</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Submitted Application</span>
</nav>

<!-- ── Outcome banner (shown prominently at top) ──────────────────────────── -->
<?php if ($app['status'] === 'reviewed'): ?>
    <?php if ($isApproved): ?>
    <div style="background:var(--badge-success-bg);border:1px solid var(--color-success);border-radius:12px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="var(--color-success)" style="width:32px;height:32px;flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
        <div style="flex:1;">
            <div style="font-size:1rem;font-weight:700;color:var(--badge-success-text);">Credit Application Approved</div>
            <?php if (!empty($app['approved_credit_limit'])): ?>
            <div style="font-size:0.875rem;color:var(--badge-success-text);margin-top:2px;">
                Your approved credit limit is
                <strong class="font-mono" style="font-size:1rem;"><?= e(format_currency($app['approved_credit_limit'])) ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($pdfUrl): ?>
        <a href="<?= e($pdfUrl) ?>" target="_blank" class="btn btn-sm" style="background:var(--color-success);color:#fff;border:none;">
            Download PDF
        </a>
        <?php endif; ?>
    </div>

    <?php elseif ($app['review_outcome'] === 'declined'): ?>
    <div style="background:var(--badge-danger-bg);border:1px solid var(--color-danger);border-radius:12px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="var(--color-danger)" style="width:32px;height:32px;flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
        <div>
            <div style="font-size:1rem;font-weight:700;color:var(--badge-danger-text);">Application Not Approved</div>
            <div style="font-size:0.875rem;color:var(--badge-danger-text);margin-top:2px;">
                We were unable to approve this application. Please contact us if you have questions.
            </div>
        </div>
        <?php if ($pdfUrl): ?>
        <a href="<?= e($pdfUrl) ?>" target="_blank" class="btn btn-secondary btn-sm">Download PDF</a>
        <?php endif; ?>
    </div>

    <?php elseif ($app['review_outcome'] === 'needs_info'): ?>
    <div style="background:var(--badge-warning-bg);border:1px solid var(--color-warning);border-radius:12px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="var(--color-warning)" style="width:32px;height:32px;flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"/></svg>
        <div>
            <div style="font-size:1rem;font-weight:700;color:var(--badge-warning-text);">Additional Information Required</div>
            <div style="font-size:0.875rem;color:var(--badge-warning-text);margin-top:2px;">
                Our team needs more information. Please check your email or contact us directly.
            </div>
        </div>
        <?php if ($pdfUrl): ?>
        <a href="<?= e($pdfUrl) ?>" target="_blank" class="btn btn-secondary btn-sm">Download PDF</a>
        <?php endif; ?>
    </div>

    <?php else: /* reviewed, outcome = null or unexpected */ ?>
    <div style="background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:12px;padding:18px 24px;margin-bottom:20px;">
        <span class="badge badge-neutral">Reviewed</span>
        <span style="margin-left:8px;font-size:0.875rem;color:var(--text-secondary);">Your application has been reviewed. Contact us for details.</span>
        <?php if ($pdfUrl): ?> &nbsp; <a href="<?= e($pdfUrl) ?>" target="_blank" class="btn btn-secondary btn-sm">Download PDF</a><?php endif; ?>
    </div>
    <?php endif; ?>

<?php else: /* status = submitted */ ?>
<div style="background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:12px;padding:18px 24px;margin-bottom:20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <span class="badge badge-warning">Under Review</span>
    <span style="font-size:0.875rem;color:var(--text-secondary);">Your application is currently under review. We'll be in touch once a decision is made.</span>
    <?php if ($pdfUrl): ?>
    <a href="<?= e($pdfUrl) ?>" target="_blank" class="btn btn-secondary btn-sm" style="margin-left:auto;">Download PDF</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Submission meta ───────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:20px;margin-bottom:20px;flex-wrap:wrap;font-size:0.8125rem;color:var(--text-secondary);">
    <?php if ($app['submitted_at']): ?>
    <div>
        <span style="font-weight:500;color:var(--text-primary);">Submitted</span>
        <?= e(format_datetime($app['submitted_at'])) ?>
    </div>
    <?php endif; ?>
    <?php if ($app['print_name_first'] || $app['print_name_last']): ?>
    <div>
        <span style="font-weight:500;color:var(--text-primary);">Signed by</span>
        <?= e(trim($app['print_name_first'] . ' ' . $app['print_name_last'])) ?>
        <?php if ($app['signed_date']): ?>
            on <?= e(format_date($app['signed_date'])) ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Rendered application body ────────────────────────────────────────── -->
<?php if (!empty($app['rendered_html'])): ?>
<div class="portal-section">
    <div class="portal-section-header">
        <h2 class="portal-section-title">Your Submitted Application</h2>
    </div>
    <div class="portal-section-body" style="padding:24px;">
        <!--
            WHY: rendered_html is a frozen server-side render of the submitted
            form fields. It was sanitised at submit time from validated form
            fields + trusted settings HTML (identical to admin show.php Trap 7
            note). The customer authored all text in this block; no admin
            content is mixed in. Safe to render as-is.
        -->
        <div class="cca-render-wrap" style="font-size:0.9rem;line-height:1.7;color:var(--text-primary);">
            <?= $app['rendered_html'] ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="portal-section">
    <div class="portal-section-body" style="padding:24px;color:var(--text-muted);font-size:0.875rem;">
        The application snapshot is not available. Please <a href="<?= e(base_url('portal/messages')) ?>" class="portal-form-link">contact us</a> if you need a copy.
    </div>
</div>
<?php endif; ?>

<style>
/* ── Scoped styles for the credit application rendered snapshot ───────────── */
.cca-render-wrap h1, .cca-render-wrap h2, .cca-render-wrap h3 {
    color: var(--text-primary);
    margin-top: 1.25em;
    margin-bottom: 0.5em;
}
.cca-render-wrap table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1em;
    font-size: 0.875rem;
}
.cca-render-wrap th, .cca-render-wrap td {
    border: 1px solid var(--border-color);
    padding: 8px 12px;
    text-align: left;
    vertical-align: top;
}
.cca-render-wrap th {
    background: var(--bg-secondary);
    font-weight: 600;
    color: var(--text-primary);
    white-space: nowrap;
}
.cca-render-wrap tr:nth-child(even) td { background: var(--bg-secondary); }
.cca-render-wrap .cca-section { margin-bottom: 1.5em; }
.cca-render-wrap .cca-label  { font-weight: 600; color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 2px; }
.cca-render-wrap .cca-value  { color: var(--text-primary); }
.cca-render-wrap hr { border: none; border-top: 1px solid var(--border-color); margin: 1.25em 0; }
/* signature block: hide the signature image (never rendered in portal) */
.cca-render-wrap .cca-signature-block img { display: none; }
.cca-render-wrap .cca-signature-block .cca-sig-placeholder {
    display: block;
    font-style: italic;
    color: var(--text-muted);
    font-size: 0.8125rem;
}
</style>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
