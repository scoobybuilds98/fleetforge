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
            c.company_name AS customer_company_name
       FROM customer_credit_applications ca
       JOIN customers c ON c.id = ca.customer_id
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

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('customers') ?>">Customers</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('customers/show') . '?id=' . (int)$app['customer_id'] ?>">
        <?= e($app['customer_company_name']) ?>
    </a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Credit Application #<?= (int)$appId ?></span>
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
            Regenerate PDF
        </button>
        <?php endif; ?>
        <?php endif; ?>
        <a href="<?= base_url('customers/show') . '?id=' . (int)$app['customer_id'] ?>&tab=credit_applications" class="btn btn-ghost">
            &larr; Back to Customer
        </a>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start;margin-top:20px;">

    <!-- Left: rendered_html snapshot -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Application Snapshot</h3>
            <p class="text-sm text-secondary" style="margin:4px 0 0;">Frozen legal record at time of submission.</p>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (!empty($app['rendered_html'])): ?>
            <iframe
                id="cca-snapshot-frame"
                srcdoc="<?= htmlspecialchars((string)$app['rendered_html'], ENT_QUOTES, 'UTF-8') ?>"
                style="width:100%;border:0;min-height:900px;"
                sandbox="allow-same-origin"
                title="Credit Application Snapshot">
            </iframe>
            <?php else: ?>
            <div style="padding:40px;text-align:center;color:var(--text-secondary);">
                <p>No snapshot available. The HTML render may not have completed at submit time.</p>
                <?php if (can('customers', 'edit')): ?>
                <p style="margin-top:8px;font-size:13px;">Use "Regenerate PDF" to re-attempt PDF generation from the stored data.</p>
                <?php endif; ?>
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

        <!-- PDF card -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">PDF</h3></div>
            <div class="card-body" style="padding:16px;">
                <?php if ($pdfUrl): ?>
                <p style="font-size:13px;margin:0 0 10px;">PDF generated.</p>
                <a href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary" style="width:100%;text-align:center;display:block;">
                    Download PDF
                </a>
                <?php else: ?>
                <p style="font-size:13px;color:var(--text-secondary);margin:0 0 10px;">No PDF generated yet.</p>
                <?php if (($app['status'] === 'submitted' || $app['status'] === 'reviewed') && can('customers', 'edit')): ?>
                <button class="btn btn-sm btn-ghost" style="width:100%;" onclick="regenPdf()" id="btn-regen-pdf-2">
                    Regenerate PDF
                </button>
                <p id="regen-status" style="font-size:12px;color:var(--text-secondary);margin:8px 0 0;display:none;"></p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /sidebar -->
</div>

<script>
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
