<?php
declare(strict_types=1);

/**
 * app/admin/credit_applications/show.php
 *
 * Admin view of a credit application (D-CCA-3-F) — S-RECORD-REDESIGN layout.
 *
 *   Header  — entity hero (lib/Ui/ModuleHero.php): the customer's name with
 *             the status + outcome badges, signer / submitted / requested /
 *             terms facts; Download PDF (or Generate PDF) visible, "Open full
 *             page", "View customer" and the back link in the More menu. The
 *             crumbs honour ?from=customer (they lead back to the customer's
 *             Credit Application tab) — they replace the old back button.
 *   Strip   — where the application stands (waiting N days / decision /
 *             with the customer), and for money roles: credit requested,
 *             approved limit (vs the customer's current limit), what the
 *             customer owes. Other roles: leases on rent + the PDF.
 *   Main    — Application at a glance (the key answers from form_data, so a
 *             reviewer needn't scroll the whole form), then the Filed
 *             Application: the stored rendered_html snapshot (frozen legal
 *             record) in an iframe.
 *   Rail    — Review form FIRST (S-CCA-4; the page's primary work — beside
 *             the application it is being judged against, no scrolling; on
 *             narrow screens the rail stacks above the form, so it is still
 *             the first thing), Needs attention, Decision (read-only roles),
 *             Customer (entity + credit exposure), Signer, Submission trail,
 *             Attachments (uploaded with the application), PDF document.
 *
 * Permission: customers:view (D-CCA-PERM); review needs customers:edit and a
 * submitted/reviewed application (D-CCA-4-A). Money (requested / approved /
 * limits / balances) additionally needs can_view_financials() — dispatchers
 * hold customers:view.
 * Trap 7: rendered_html is rendered server-side as-is (already sanitised at
 * submit time from validated form fields + trusted settings HTML), served by
 * snapshot.php. NO token_hash, signature_path, or file_path exposed to client —
 * the PDF goes through api/v1/credit_applications/pdf (stored copy, or rebuilt
 * from the snapshot — S-PDF-LETTERHEAD); attachments go out as short-lived
 * signed URLs (StorageClient::url).
 *
 * form_data nests the applicant's figure at credit.credit_requested (see
 * app/admin/credit-application.php); the old page read a top-level key that
 * never existed, so "Credit Requested" never showed. Both are read now.
 *
 * @method  GET
 * @query   id (int) — customer_credit_applications.id; from=customer (optional)
 * @auth    Session; customers:view
 * @session S-CCA-3, S-CCA-4, S-RECORD-REDESIGN
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
            ca.opened_at, ca.created_at, ca.reviewed_at, ca.sent_at, ca.token_expires_at,
            ca.approved_credit_limit, ca.review_notes, ca.uploaded_document_ids,
            ca.updated_at, ca.form_data, ca.reviewed_by,
            c.company_name AS customer_company_name,
            c.updated_at AS customer_updated_at, c.credit_limit AS customer_credit_limit,
            c.outstanding_balance AS customer_outstanding, c.currency AS customer_currency,
            c.status AS customer_status,
            rb.name AS reviewed_by_name,
            sb.name AS sent_by_name
       FROM customer_credit_applications ca
       JOIN customers c ON c.id = ca.customer_id
       LEFT JOIN users rb ON rb.id = ca.reviewed_by
       LEFT JOIN users sb ON sb.id = ca.sent_by
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

// ── PDF link (Trap 7 — no file_path to client) ─────────────────────────────
// S-PDF-LETTERHEAD: the streaming endpoint serves the stored PDF, or rebuilds
// it from the frozen snapshot when storage has lost the file. The old direct
// presigned URL was a dead S3 link on prod for exactly that reason.
$pdfUrl = null;
if (in_array($app['status'], ['submitted', 'reviewed'], true)
    && ((string) ($app['rendered_html'] ?? '') !== '' || $app['generated_pdf_document_id'] !== null)) {
    $pdfUrl = base_url('api/v1/credit_applications/pdf') . '?id=' . (int) $appId;
}

// ── Files the applicant uploaded with the form (S-CCA-2 stores them as
//    customer documents). Same signed-URL treatment as the PDF; the page gate
//    (customers:view) is the documents gate for entity_type 'customer'.
$attachments = [];
$uploadedIds = array_values(array_filter(array_map('intval', (array) (json_decode((string) ($app['uploaded_document_ids'] ?? ''), true) ?: []))));
if ($uploadedIds) {
    $ph = implode(',', array_fill(0, count($uploadedIds), '?'));
    foreach (db_select(
        "SELECT id, title, file_name, file_path, file_size_kb
           FROM documents
          WHERE id IN ({$ph}) AND deleted_at IS NULL AND entity_type = 'customer' AND entity_id = ?",
        array_merge($uploadedIds, [(int) $app['customer_id']])
    ) as $d) {
        $url = null;
        if (!empty($d['file_path'])) {
            try {
                $url = StorageClient::url((string) $d['file_path'], 3600);
            } catch (\Throwable $e) {
                error_log('[S-RECORD-REDESIGN] attachment URL failed for doc ' . (int) $d['id'] . ': ' . $e->getMessage());
            }
        }
        $attachments[] = ['title' => (string) ($d['title'] ?: $d['file_name'] ?: 'Document'), 'url' => $url, 'kb' => (int) ($d['file_size_kb'] ?? 0)];
    }
}

// ── The applicant's answers (form_data) — read-only reference ─────────────
// D-CCA-4: never written back to customers — shown for REFERENCE ONLY.
$fd = !empty($app['form_data']) ? (json_decode((string) $app['form_data'], true) ?: []) : [];
/** A trimmed answer from form_data by path (e.g. ['company','gst_number']); '' when absent. */
$fdGet = static function (array $path) use ($fd): string {
    $v = $fd;
    foreach ($path as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) {
            return '';
        }
        $v = $v[$k];
    }
    return is_scalar($v) ? trim((string) $v) : '';
};
$creditRequested = $fdGet(['credit', 'credit_requested']);
if ($creditRequested === '') {
    $creditRequested = $fdGet(['credit_requested']); // legacy top-level key
}
$creditRequested = $creditRequested !== '' ? $creditRequested : null;
/** Applicant's figure as currency when it parses ("10,000" / "$10000"), else as typed. */
$fmtRequested = static function (?string $v): string {
    if ($v === null) {
        return '—';
    }
    $n = str_replace([',', '$', ' '], '', $v);
    return preg_match('/^\d+(\.\d{1,2})?$/', $n) ? e(format_currency($n)) : e($v);
};

// ── Review panel visibility ─────────────────────────────────────────────────
$canReview = can('customers', 'edit')
    && in_array($app['status'], ['submitted', 'reviewed'], true);
$canSeeMoney = can_view_financials();

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

// ── S-RECORD-REDESIGN: strip + rail data ─────────────────────────────────────
// Stamps (sent / opened / submitted / reviewed / token expiry) are UTC
// DATETIMEs (S-UTC-STAMPS) — elapsed days measure against the UTC clock.
$nowUtc   = ff_now_utc();
$daysSince = static function (?string $utc) use ($nowUtc): ?int {
    if ($utc === null || $utc === '') {
        return null;
    }
    return max(0, (int) floor((strtotime($nowUtc . ' UTC') - strtotime($utc . ' UTC')) / 86400));
};
$daysLabel = static fn (int $d): string => $d === 0 ? 'today' : $d . ' day' . ($d === 1 ? '' : 's');
$sentAt    = $app['sent_at'] ?: $app['created_at'];
$waitDays  = $app['status'] === 'submitted' ? $daysSince($app['submitted_at']) : null;
$withCust  = in_array($app['status'], ['sent', 'opened'], true) ? $daysSince($sentAt) : null;
$decideDays = null;
if ($app['status'] === 'reviewed' && $app['submitted_at'] && $app['reviewed_at']) {
    $decideDays = max(0, (int) floor((strtotime($app['reviewed_at'] . ' UTC') - strtotime($app['submitted_at'] . ' UTC')) / 86400));
}
$linkExpired = in_array($app['status'], ['sent', 'opened'], true)
    && !empty($app['token_expires_at']) && (string) $app['token_expires_at'] < $nowUtc;

$customerId  = (int) $app['customer_id'];
$customerUrl = base_url('customers/show') . '?id=' . $customerId;
$signerName  = trim((string) $app['print_name_first'] . ' ' . (string) $app['print_name_last']);

// Other applications for the same customer — re-sends create new rows
// (D-CCA-4-C), so a reviewer must know when this one is superseded.
$newerApp = db_row(
    "SELECT id, status, created_at FROM customer_credit_applications
      WHERE customer_id = ? AND id > ? AND deleted_at IS NULL
      ORDER BY id DESC LIMIT 1",
    [$customerId, $appId]
);
$appCount = db_count(
    "SELECT COUNT(*) FROM customer_credit_applications WHERE customer_id = ? AND deleted_at IS NULL",
    [$customerId]
);
$onRent = db_count(
    "SELECT COUNT(*) FROM leases WHERE customer_id = ? AND status = 'active' AND deleted_at IS NULL",
    [$customerId]
);
// Credit exposure for the decision — money roles only. Company-local day
// for due dates (ff_today), never SQL CURDATE().
$overdue = $canSeeMoney ? (db_row(
    "SELECT COUNT(*) AS n, COALESCE(SUM(balance_due), 0) AS amt
       FROM invoices
      WHERE customer_id = ? AND deleted_at IS NULL
        AND status IN ('sent','partially_paid','overdue') AND balance_due > 0 AND due_date < ?",
    [$customerId, ff_today()]
) ?? []) : [];
$overdueCnt = (int) ($overdue['n'] ?? 0);
$overdueAmt = (string) ($overdue['amt'] ?? '0');

$pageTitle      = 'Credit Application — ' . e($app['customer_company_name']);
$helpModuleSlug = 'customers';
require_once FF_ROOT . '/includes/header.php';
?>

<?php
// Detect whether we came from the module index or the customer profile.
// ?from=customer  → crumbs / back link go to the customer's Credit
//                   Application tab (#hash — the profile's tabs read the
//                   hash, not a ?tab= param)
// default (or ?from=module) → the credit applications module
$fromCustomer = ($_GET['from'] ?? '') === 'customer';
$custAppsUrl  = $customerUrl . '#credit_applications';
$backUrl = $fromCustomer ? $custAppsUrl : base_url('credit_applications');
$backLabel = $fromCustomer ? '← Back to Customer' : '← All Applications';
?>

<style>
/* Credit-application show (S-RECORD-REDESIGN) — review form pieces in the
   rail + the "at a glance" answers. Tokens only. */
.cca-label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; }
.cca-ref,
.cca-optin {
    padding: 8px 10px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: var(--bg-surface-2);
    font-size: 12px;
}
.cca-ref { margin-bottom: 14px; }
.cca-ref small { display: block; margin-top: 2px; font-size: 11px; color: var(--text-secondary); }
.cca-optin { margin-bottom: 8px; }
.cca-optin label { display: flex; align-items: flex-start; gap: 8px; cursor: pointer; }
.cca-optin span span { color: var(--text-secondary); }
.cca-outcomes { display: flex; flex-direction: column; gap: 6px; }
.cca-outcomes label { display: flex; align-items: center; gap: 6px; cursor: pointer; }
.cca-glance h4 {
    margin: 0 0 6px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--text-tertiary);
}
.cca-glance .grid-2 { align-items: start; }
</style>

<?php ob_start(); ?>
    <span class="badge <?= e($statusClasses[$app['status']] ?? 'badge-neutral') ?>">
        <?= e($statusLabels[$app['status']] ?? ucfirst($app['status'])) ?>
    </span>
    <?php if ($app['review_outcome']): ?>
    <span class="badge <?= e($outcomeClasses[$app['review_outcome']] ?? 'badge-neutral') ?>">
        <?= e($outcomeLabels[$app['review_outcome']] ?? $app['review_outcome']) ?>
    </span>
    <?php endif; ?>
<?php $heroBadges = ob_get_clean(); ?>
<?php ob_start(); /* secondary actions → the header's More menu (S-RECORD-REDESIGN) */ ?>
        <?php if (!empty($app['rendered_html'])): ?>
        <a href="<?= base_url('credit_applications/snapshot') ?>?id=<?= (int)$appId ?>"
           target="_blank" rel="noopener" class="btn btn-ghost btn-sm">↗ Open full page</a>
        <?php endif; ?>
        <a href="<?= e($customerUrl) ?>" class="btn btn-ghost btn-sm">View customer</a>
        <a href="<?= e($backUrl) ?>" class="btn btn-ghost btn-sm"><?= e($backLabel) ?></a>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
        <?= help_button('customers') ?>
        <?php if ($pdfUrl): ?>
        <a href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener" class="btn btn-primary btn-sm">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:15px;height:15px;vertical-align:middle;margin-right:4px;" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>
            </svg>
            Download PDF
        </a>
        <?php elseif ($app['status'] === 'submitted' || $app['status'] === 'reviewed'): ?>
        <?php if (can('customers', 'edit')): ?>
        <button class="btn btn-secondary btn-sm" id="btn-regen-pdf" onclick="regenPdf()">
            Generate PDF
        </button>
        <?php endif; ?>
        <?php endif; ?>
        <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?php
$heroFacts = [];
if ($signerName !== '') {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('pencil-square') . 'Signed by ' . e($signerName);
}
if ($app['submitted_at']) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('calendar-days') . 'Submitted ' . e(format_datetime($app['submitted_at'], 'M j, Y'));
} else {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('paper-airplane') . 'Sent ' . e(format_datetime($sentAt, 'M j, Y'));
}
if ($canSeeMoney && $creditRequested !== null) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('banknotes') . 'Requested ' . $fmtRequested($creditRequested);
}
if (!empty($app['terms_version'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('document-text') . 'Terms ' . e($app['terms_version']);
}
$heroCrumbs = $fromCustomer
    ? [['Dashboard', base_url('dashboard')], ['Customers', base_url('customers')], [(string) $app['customer_company_name'], $custAppsUrl], ['Application #' . (int) $appId, null]]
    : [['Dashboard', base_url('dashboard')], ['Credit Applications', base_url('credit_applications')], [(string) $app['customer_company_name'], $customerUrl], ['Application #' . (int) $appId, null]];
?>
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => 'purple',
    'icon'       => 'clipboard-document-check',
    'avatar'     => \FleetForge\Ui\ModuleHero::initials((string) $app['customer_company_name']),
    'mark'       => '#' . (int) $appId,
    'crumbs'     => $heroCrumbs,
    'eyebrow'    => 'Credit application #' . (int) $appId,
    'title_html' => e($app['customer_company_name']) . $heroBadges,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<!-- ============================================================
     KEY NUMBERS — the summary strip (S-RECORD-REDESIGN): where the
     application stands, then (money roles) the figures a credit
     decision rests on — requested, approved, and what the customer
     already owes.
     ============================================================ -->
<div class="stat-grid ff-stats">

    <?php if ($app['status'] === 'submitted'): ?>
    <a class="stat-card <?= ($waitDays ?? 0) >= 3 ? 'stat-card--red' : 'stat-card--amber' ?>"
       href="<?= $canReview ? '#review-panel' : '#cca-attention' ?>" title="Submitted and waiting for a decision">
        <span class="stat-icon <?= ($waitDays ?? 0) >= 3 ? 'stat-icon--red' : 'stat-icon--amber' ?>"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Waiting for review</div>
        <div class="stat-value"><?= e($daysLabel((int) $waitDays)) ?></div>
        <div class="stat-delta">since <?= e(format_datetime($app['submitted_at'], 'M j')) ?></div>
    </a>
    <?php elseif ($app['status'] === 'reviewed'): ?>
    <?php $oc = (string) ($app['review_outcome'] ?? ''); $ocTone = $oc === 'approved' ? 'green' : ($oc === 'declined' ? 'red' : 'amber'); ?>
    <div class="stat-card stat-card--<?= $ocTone ?>">
        <span class="stat-icon stat-icon--<?= $ocTone ?>"><svg><use href="#icon-<?= $oc === 'approved' ? 'check-circle' : ($oc === 'declined' ? 'x-circle' : 'exclamation-triangle') ?>"/></svg></span>
        <div class="stat-label">Decision</div>
        <div class="stat-value"><?= e($outcomeLabels[$oc] ?? 'Reviewed') ?></div>
        <div class="stat-delta"><?= $decideDays !== null ? ($decideDays === 0 ? 'decided same day' : 'decided in ' . e($daysLabel($decideDays))) : e(format_datetime($app['reviewed_at'], 'M j, Y')) ?></div>
    </div>
    <?php else: ?>
    <div class="stat-card <?= $linkExpired ? 'stat-card--red' : 'stat-card--blue' ?>">
        <span class="stat-icon <?= $linkExpired ? 'stat-icon--red' : 'stat-icon--blue' ?>"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">With the customer</div>
        <div class="stat-value"><?= e($daysLabel((int) $withCust)) ?></div>
        <div class="stat-delta"><?= $linkExpired ? 'link expired' : ($app['opened_at'] ? 'opened ' . e(format_datetime($app['opened_at'], 'M j')) : 'not opened yet') ?></div>
    </div>
    <?php endif; ?>

    <?php if ($canSeeMoney): ?>
    <div class="stat-card stat-card--purple">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Credit requested</div>
        <div class="stat-value currency"><?= $fmtRequested($creditRequested) ?></div>
        <div class="stat-delta"><?= $creditRequested !== null ? 'applicant\'s figure' : 'not stated' ?></div>
    </div>

    <div class="stat-card stat-card--green">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-shield-check"/></svg></span>
        <div class="stat-label">Approved limit</div>
        <div class="stat-value currency"><?= $app['approved_credit_limit'] !== null ? e(format_currency($app['approved_credit_limit'])) : '—' ?></div>
        <div class="stat-delta">customer limit <?= e(format_currency($app['customer_credit_limit'])) ?></div>
    </div>

    <a class="stat-card <?= $overdueCnt > 0 ? 'stat-card--red' : 'stat-card--amber' ?>"
       href="<?= e($customerUrl) ?>#invoices" title="The customer's unpaid invoices">
        <span class="stat-icon <?= $overdueCnt > 0 ? 'stat-icon--red' : 'stat-icon--amber' ?>"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Customer owes</div>
        <div class="stat-value currency"><?= e(format_currency($app['customer_outstanding'])) ?></div>
        <div class="stat-delta"><?= $overdueCnt > 0 ? '<span class="text-danger">' . e(format_currency($overdueAmt)) . ' overdue</span>' : 'nothing overdue' ?></div>
    </a>
    <?php else: ?>
    <a class="stat-card stat-card--blue" href="<?= e($customerUrl) ?>#leases" title="The customer's leases">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-key"/></svg></span>
        <div class="stat-label">Customer on rent</div>
        <div class="stat-value font-mono"><?= (int) $onRent ?></div>
        <div class="stat-delta">active lease<?= $onRent === 1 ? '' : 's' ?></div>
    </a>

    <?php if ($pdfUrl): ?>
    <a class="stat-card stat-card--green" href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener" title="Open the PDF">
    <?php else: ?>
    <div class="stat-card stat-card--slate">
    <?php endif; ?>
        <span class="stat-icon <?= $pdfUrl ? 'stat-icon--green' : 'stat-icon--slate' ?>"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">PDF</div>
        <div class="stat-value"><?= $pdfUrl ? 'Ready' : 'Not yet' ?></div>
    <?= $pdfUrl ? '</a>' : '</div>' ?>
    <?php endif; ?>

</div>

<div class="rec-layout">
<div class="rec-main">

    <?php
    // ── Application at a glance — the answers a reviewer weighs, lifted
    //    from form_data so the whole form needn't be scrolled first.
    $ansRows = static function (array $rows): string {
        $out = '';
        foreach ($rows as [$label, $html]) {
            if ($html === '' || $html === null) {
                continue;
            }
            $out .= '<dt>' . e($label) . '</dt><dd>' . $html . '</dd>';
        }
        return $out;
    };
    $principals = [];
    foreach ((array) ($fd['principals'] ?? []) as $p) {
        $n = trim((string) ($p['name'] ?? ''));
        if ($n !== '') {
            $principals[] = e($n) . (trim((string) ($p['title'] ?? '')) !== '' ? ' <span class="text-secondary">· ' . e(trim((string) $p['title'])) . '</span>' : '');
        }
    }
    $refs = [];
    foreach ((array) ($fd['references'] ?? []) as $r) {
        $n = trim((string) ($r['company'] ?? ''));
        if ($n !== '') {
            $refs[] = e($n) . (trim((string) ($r['phone'] ?? '')) !== '' ? ' <span class="text-secondary">· ' . e(trim((string) $r['phone'])) . '</span>' : '');
        }
    }
    $fleet = array_filter([
        $fdGet(['equipment', 'tractors_owned']) !== '' ? e($fdGet(['equipment', 'tractors_owned'])) . ' owned' : '',
        $fdGet(['equipment', 'tractors_leased']) !== '' ? e($fdGet(['equipment', 'tractors_leased'])) . ' leased' : '',
        $fdGet(['equipment', 'owner_operators']) !== '' ? e($fdGet(['equipment', 'owner_operators'])) . ' owner-operators' : '',
    ]);
    $insurance = trim(implode(' · ', array_filter([
        $fdGet(['insurance', 'has_trailer_insurance']),
        $fdGet(['insurance', 'company']),
        $fdGet(['insurance', 'agent']),
    ])));
    $taxIds = implode(' · ', array_filter([
        $fdGet(['company', 'gst_number']) !== '' ? 'GST ' . $fdGet(['company', 'gst_number']) : '',
        $fdGet(['company', 'pst_number']) !== '' ? 'PST ' . $fdGet(['company', 'pst_number']) : '',
        $fdGet(['company', 'wcb_number']) !== '' ? 'WCB ' . $fdGet(['company', 'wcb_number']) : '',
    ]));
    $physical = trim(implode(', ', array_filter([$fdGet(['company', 'physical_address']), $fdGet(['company', 'physical_city']), $fdGet(['company', 'physical_province']), $fdGet(['company', 'physical_postal'])])));
    $leftAns = $ansRows([
        ['Company', $fdGet(['company', 'name']) !== '' ? e($fdGet(['company', 'name'])) : ''],
        ['Email', $fdGet(['company', 'email']) !== '' ? '<a href="mailto:' . e($fdGet(['company', 'email'])) . '">' . e($fdGet(['company', 'email'])) . '</a>' : ''],
        ['Phone', $fdGet(['company', 'phone']) !== '' ? e($fdGet(['company', 'phone'])) : ''],
        ['Address', $physical !== '' ? e($physical) : ''],
        ['Business type', $fdGet(['company', 'business_type']) !== '' ? e($fdGet(['company', 'business_type'])) : ''],
        ['Incorporated', $fdGet(['company', 'incorporation']) !== '' ? e($fdGet(['company', 'incorporation'])) : ''],
        ['In business', $fdGet(['company', 'duration']) !== '' ? e($fdGet(['company', 'duration'])) : ''],
        ['Tax / WCB', $taxIds !== '' ? e($taxIds) : ''],
        ['Principals', $principals ? implode('<br>', $principals) : ''],
    ]);
    $rightAns = $ansRows([
        ['Credit requested', $canSeeMoney && $creditRequested !== null ? '<b>' . $fmtRequested($creditRequested) . '</b>' : ''],
        ['PO required', $fdGet(['credit', 'has_purchase_order']) !== '' ? e($fdGet(['credit', 'has_purchase_order'])) : ''],
        ['Fleet', $fleet ? implode(' · ', $fleet) : ''],
        ['Trailer insurance', $insurance !== '' ? e($insurance) : ''],
        ['References', $refs ? implode('<br>', $refs) : '<span class="text-secondary">None given</span>'],
    ]);
    ?>
    <?php if ($fd): ?>
    <div class="card cca-glance">
        <div class="card-header"><h3 class="card-title">Application at a Glance</h3></div>
        <div class="card-body">
            <div class="grid-2">
                <div>
                    <h4>Business</h4>
                    <?= $leftAns !== '' ? '<dl class="rec-dl">' . $leftAns . '</dl>' : '<p class="text-secondary" style="margin:0;font-size:12.5px;">Not filled in.</p>' ?>
                </div>
                <div>
                    <h4>Credit &amp; operations</h4>
                    <dl class="rec-dl"><?= $rightAns ?></dl>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filed application HTML form -->
    <div class="card">
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
                <?php if (in_array($app['status'], ['sent', 'opened'], true)): ?>
                <p style="margin:0 0 8px;font-size:0.9375rem;">Not submitted yet.</p>
                <p style="font-size:0.8125rem;margin:0;">The filed form appears here once the customer submits it.</p>
                <?php else: ?>
                <p style="margin:0 0 8px;font-size:0.9375rem;">No HTML snapshot stored for this application.</p>
                <p style="font-size:0.8125rem;margin:0;">
                    The form data is intact — use
                    <?= can('customers', 'edit') ? '"Regenerate PDF"' : 'an admin' ?>
                    to rebuild from stored form fields.
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /rec-main -->

<?php
// ── RAIL (S-RECORD-REDESIGN) — the application at a glance ──────────────────
$R = \FleetForge\Ui\RecordUi::class;
$railC = [];

// 1. Review (S-CCA-4) — D-CCA-4-A: allowed on submitted + reviewed. The
//    form's markup, ids and handlers are unchanged; only its styling moved
//    to tokens (the old inline --color-surface-secondary / --color-border
//    were undefined).
if ($canReview) {
    ob_start(); ?>
                <?php if ($creditRequested !== null && $canSeeMoney): ?>
                <!-- D-CCA-4: customer-entered value shown REFERENCE ONLY — never pre-fills inputs -->
                <div class="cca-ref">
                    <span class="text-secondary">Credit Requested (applicant):</span>
                    <strong style="margin-left:4px;"><?= $fmtRequested($creditRequested) ?></strong>
                    <small>Reference only — enter your own approved amount below.</small>
                </div>
                <?php endif; ?>

                <form id="review-form" onsubmit="submitReview(event)">
                    <input type="hidden" id="review-app-id" value="<?= (int)$appId ?>">
                    <input type="hidden" id="review-updated-at" value="<?= e($app['updated_at']) ?>">
                    <input type="hidden" id="review-customer-updated-at" value="<?= e($app['customer_updated_at']) ?>">

                    <!-- Outcome radio -->
                    <div style="margin-bottom:12px;">
                        <label class="cca-label" style="margin-bottom:6px;">Outcome</label>
                        <div class="cca-outcomes">
                            <label>
                                <input type="radio" name="review_outcome" value="approved" onchange="reviewOutcomeChanged()"
                                       <?= $app['review_outcome'] === 'approved' ? 'checked' : '' ?>>
                                <span class="badge badge-success" style="pointer-events:none;">Approved</span>
                            </label>
                            <label>
                                <input type="radio" name="review_outcome" value="declined" onchange="reviewOutcomeChanged()"
                                       <?= $app['review_outcome'] === 'declined' ? 'checked' : '' ?>>
                                <span class="badge badge-danger" style="pointer-events:none;">Declined</span>
                            </label>
                            <label>
                                <input type="radio" name="review_outcome" value="needs_info" onchange="reviewOutcomeChanged()"
                                       <?= $app['review_outcome'] === 'needs_info' ? 'checked' : '' ?>>
                                <span class="badge badge-warning" style="pointer-events:none;">Needs Info</span>
                            </label>
                        </div>
                    </div>

                    <!-- Review notes -->
                    <div style="margin-bottom:12px;">
                        <label for="review_notes" class="cca-label">Notes (internal)</label>
                        <textarea id="review_notes" name="review_notes" rows="3"
                                  style="width:100%;resize:vertical;font-size:13px;"
                                  class="form-control" placeholder="Internal notes about this review…"><?= e($app['review_notes'] ?? '') ?></textarea>
                    </div>

                    <!-- Approved credit limit (always shown, more prominent when approved) -->
                    <div style="margin-bottom:12px;" id="review-credit-section">
                        <label for="approved_credit_limit" class="cca-label">Approved Credit Limit ($)</label>
                        <!-- D-CCA-4: input is EMPTY by default — never pre-filled from applicant data -->
                        <input type="number" id="approved_credit_limit" name="approved_credit_limit"
                               min="0" step="0.01"
                               value="<?= $app['approved_credit_limit'] !== null ? e((string)$app['approved_credit_limit']) : '' ?>"
                               class="form-control" style="font-size:13px;"
                               placeholder="Enter your approved limit…">
                    </div>

                    <!-- Opt-in: apply credit limit to customer record -->
                    <div class="cca-optin">
                        <label>
                            <input type="checkbox" id="apply_credit_limit" name="apply_credit_limit" style="margin-top:2px;" onchange="reviewOptinChanged()">
                            <span>
                                <strong>Apply credit limit to customer record</strong><br>
                                <span>Will update customers.credit_limit to the approved amount above<?= $canSeeMoney ? ' (now ' . e(format_currency($app['customer_credit_limit'])) . ')' : '' ?>.</span>
                            </span>
                        </label>
                    </div>

                    <!-- Opt-in: update customer status -->
                    <div class="cca-optin" style="margin-bottom:12px;">
                        <label style="margin-bottom:4px;">
                            <input type="checkbox" id="apply_customer_status" name="apply_customer_status" style="margin-top:2px;" onchange="reviewOptinChanged()">
                            <span>
                                <strong>Update customer status</strong><br>
                                <span>Current: <strong><?= e(ucfirst(str_replace('_', ' ', $app['customer_status'] ?? ''))) ?></strong></span>
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
                        Sends a fresh link. The notes above will be included in the email, and the form opens with the customer's previous answers filled in.
                    </p>
                    <?php endif; ?>

                </form>
<?php
    $reviewBody = ob_get_clean();
    $railC[] = $R::card($app['status'] === 'submitted' ? 'Review this application' : 'Review', $reviewBody, [
        'icon'  => 'clipboard-document-check',
        'class' => 'rec-card--accent',
        'id'    => 'review-panel',
        'foot'  => $app['reviewed_at'] && $app['reviewed_by_name']
            ? 'Last reviewed ' . e(format_datetime($app['reviewed_at'], 'M j, Y g:i A')) /* S-UTC-STAMPS: UTC column */ . ' by ' . e($app['reviewed_by_name'])
            : '',
    ]);
}

// 2. Needs attention.
$alertsC = [];
if ($app['status'] === 'submitted') {
    $alertsC[] = [($waitDays ?? 0) >= 3 ? 'danger' : 'warning', 'Submitted ' . e($waitDays === 0 ? 'today' : $daysLabel((int) $waitDays) . ' ago') . ' — '
        . ($canReview ? '<a href="#review-panel">waiting for review</a>.' : 'waiting for review.')];
}
if ($app['review_outcome'] === 'needs_info' && !$newerApp) {
    $alertsC[] = ['info', 'Marked <b>Needs Info</b> — re-send the link so the customer can correct it. Their previous answers are filled in for them.'];
}
if (in_array($app['status'], ['sent', 'opened'], true)) {
    $alertsC[] = $linkExpired
        ? ['warning', 'The link expired ' . e(format_datetime($app['token_expires_at'], 'M j, Y')) . ' — send a new one from the <a href="' . e($custAppsUrl) . '">customer\'s profile</a>.']
        : ['info', 'Waiting for the customer to fill it in' . (!empty($app['token_expires_at']) ? ' (link valid until ' . e(format_datetime($app['token_expires_at'], 'M j')) . ')' : '') . '.'];
}
if (in_array($app['status'], ['submitted', 'reviewed'], true) && !(int) $app['terms_accepted']) {
    $alertsC[] = ['danger', 'The terms were <b>not accepted</b>.'];
}
if ($newerApp) {
    $alertsC[] = ['info', 'Superseded — a newer application <a href="' . e(base_url('credit_applications/show')) . '?id=' . (int) $newerApp['id'] . '">#' . (int) $newerApp['id'] . '</a> exists (' . e($statusLabels[$newerApp['status']] ?? $newerApp['status']) . ').'];
}
if ($app['review_outcome'] === 'approved' && $canSeeMoney && $app['approved_credit_limit'] !== null
    && bccomp((string) $app['approved_credit_limit'], (string) $app['customer_credit_limit'], 2) !== 0) {
    $alertsC[] = ['warning', 'Approved ' . e(format_currency($app['approved_credit_limit'])) . ' but the customer\'s limit is ' . e(format_currency($app['customer_credit_limit'])) . ' — tick "Apply credit limit" to sync it.'];
}
if ($app['review_outcome'] === 'approved' && in_array($app['customer_status'], ['pending', 'credit_hold', 'suspended'], true)) {
    $alertsC[] = ['info', 'Approved, but the customer is still <b>' . e(str_replace('_', ' ', (string) $app['customer_status'])) . '</b>.'];
}
if ($canSeeMoney && $overdueCnt > 0) {
    $alertsC[] = ['warning', 'The customer has <a href="' . e($customerUrl) . '#invoices">' . $overdueCnt . ' overdue invoice' . ($overdueCnt === 1 ? '' : 's') . '</a> — ' . e(format_currency($overdueAmt)) . '.'];
}
if (!$pdfUrl && in_array($app['status'], ['submitted', 'reviewed'], true)) {
    $alertsC[] = ['info', 'No PDF stored yet' . (can('customers', 'edit') ? ' — <a href="#cca-pdf">generate it</a> for the file.' : '.')];
}
$railC[] = $R::card('Needs attention', $R::alerts($alertsC, 'All clear — nothing needs attention.'), ['icon' => 'exclamation-triangle', 'id' => 'cca-attention']);

// 3. Decision — for viewers without the review form (it shows the same).
if (!$canReview && $app['status'] === 'reviewed') {
    $railC[] = $R::card('Decision', $R::kv([
        ['Outcome', $app['review_outcome'] ? '<span class="badge ' . e($outcomeClasses[$app['review_outcome']] ?? 'badge-neutral') . '">' . e($outcomeLabels[$app['review_outcome']] ?? $app['review_outcome']) . '</span>' : null],
        ['Approved limit', $canSeeMoney && $app['approved_credit_limit'] !== null ? e(format_currency($app['approved_credit_limit'])) : null, 'mono'],
        ['Reviewed', $app['reviewed_at'] ? e(format_datetime($app['reviewed_at'], 'M j, Y g:i A')) : null],
        ['By', !empty($app['reviewed_by_name']) ? e($app['reviewed_by_name']) : null],
    ]), ['icon' => 'shield-check']);
}

// 4. Customer — who is applying, and (money roles) their current exposure.
$custSub = e(ucfirst(str_replace('_', ' ', (string) $app['customer_status'])));
if ($canSeeMoney) {
    $custSub .= ' · limit ' . e(format_currency($app['customer_credit_limit']));
}
$custBody = $R::entity((string) $app['customer_company_name'], $customerUrl, $custSub,
    \FleetForge\Ui\ModuleHero::initials((string) $app['customer_company_name']));
if ($canSeeMoney) {
    $custBody .= '<div style="margin-top:10px;">' . $R::kv([
        ['Owes', e(format_currency($app['customer_outstanding'])) . ' ' . e($app['customer_currency'] ?? 'CAD'), 'mono'],
        ['Overdue', $overdueCnt > 0 ? '<span class="text-danger">' . e(format_currency($overdueAmt)) . '</span>' : 'None', 'mono'],
        ['On rent', (string) $onRent],
    ]) . '</div>';
}
$custBody .= '<div style="margin-top:10px;">' . $R::links([
    ['Credit applications', $custAppsUrl, 'clipboard-document-list', (string) $appCount],
    ['Leases', $customerUrl . '#leases', 'calendar-days', $onRent > 0 ? $onRent . ' on rent' : ''],
]) . '</div>';
$railC[] = $R::card('Customer', $custBody, ['icon' => 'user-group']);

// 5. Signer — only once there is a signature (sent / opened rows have none,
//    and "Terms accepted: No" there would read as a problem).
$isFiled = in_array($app['status'], ['submitted', 'reviewed'], true);
if ($isFiled) {
$railC[] = $R::card('Signer', $R::kv([
    ['Name', $signerName !== '' ? e($signerName) : '—'],
    ['Signed', !empty($app['signed_date']) ? e(format_date($app['signed_date'])) : '—'],
    ['Terms accepted', (int) $app['terms_accepted'] ? 'Yes' : '<span class="text-danger">No</span>'],
    ['Terms version', !empty($app['terms_version']) ? e($app['terms_version']) : null],
    ['Terms', !empty($app['terms_url']) ? '<a href="' . e($app['terms_url']) . '" target="_blank" rel="noopener">View</a>' : null],
]), ['icon' => 'pencil-square']);
}

// 6. Submission trail (audit).
$ua = (string) ($app['submitted_user_agent'] ?? '');
$railC[] = $R::card('Submission trail', $R::kv([
    ['Link sent', e(format_datetime($sentAt, 'M j, Y')) . (!empty($app['sent_by_name']) ? ' · ' . e($app['sent_by_name']) : '')],
    ['Link expires', in_array($app['status'], ['sent', 'opened'], true) && !empty($app['token_expires_at']) ? ($linkExpired ? '<span class="text-danger">' : '') . e(format_datetime($app['token_expires_at'], 'M j, Y')) . ($linkExpired ? ' (expired)</span>' : '') : null],
    ['Opened', $app['opened_at'] ? e(format_datetime($app['opened_at'], 'M j, Y g:i A')) : '—'],
    ['Submitted', $app['submitted_at'] ? e(format_datetime($app['submitted_at'], 'M j, Y g:i A')) : null],
    ['Reviewed', $app['reviewed_at'] ? e(format_datetime($app['reviewed_at'], 'M j, Y g:i A')) . (!empty($app['reviewed_by_name']) ? ' · ' . e($app['reviewed_by_name']) : '') : null],
    ['IP address', !empty($app['submitted_ip']) ? '<span class="mono">' . e($app['submitted_ip']) . '</span>' : null],
    ['Device', $ua !== '' ? '<span title="' . e($ua) . '" style="font-size:11.5px;">' . e(substr($ua, 0, 100)) . (strlen($ua) > 100 ? '…' : '') . '</span>' : null],
]), ['icon' => 'clock']);

// 7. Attachments uploaded with the application.
if ($attachments) {
    $att = [];
    foreach ($attachments as $a) {
        $att[] = [$a['title'], $a['url'] ?? '#', 'paper-clip', $a['kb'] > 0 ? ($a['kb'] >= 1024 ? round($a['kb'] / 1024, 1) . ' MB' : $a['kb'] . ' KB') : ''];
    }
    $railC[] = $R::card('Attachments', $R::links($att), ['icon' => 'paper-clip']);
}

// 8. PDF document (separate from the HTML form view) — nothing to show
//    before the customer files the form.
if ($isFiled || $pdfUrl) {
ob_start(); ?>
                <?php if ($pdfUrl): ?>
                <p style="font-size:13px;color:var(--color-success);font-weight:600;margin:0 0 10px;">
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
                    The HTML form can be used to generate it.
                    <?php endif; ?>
                </p>
                <?php if (($app['status'] === 'submitted' || $app['status'] === 'reviewed') && can('customers', 'edit')): ?>
                <button class="btn btn-sm btn-secondary" style="width:100%;" onclick="regenPdf()" id="btn-regen-pdf-2">
                    Generate PDF from HTML
                </button>
                <p id="regen-status" style="font-size:12px;color:var(--text-secondary);margin:8px 0 0;display:none;"></p>
                <?php endif; ?>
                <?php endif; ?>
<?php
$railC[] = $R::card('PDF document', ob_get_clean(), ['icon' => 'document-arrow-down', 'id' => 'cca-pdf', 'foot' => 'Stored in Documents — separate from the HTML form.']);
}
?>
<aside class="rec-rail" aria-label="Credit application at a glance">
    <?= implode("\n    ", $railC) ?>
</aside>
</div><!-- /rec-layout -->

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
            // Navigate to the customer's Credit Application tab to see the new
            // row (#hash — the profile's tabs read the hash, not ?tab=).
            window.location.href = <?= json_encode($custAppsUrl) ?>;
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
