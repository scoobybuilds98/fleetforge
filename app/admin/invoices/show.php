<?php
declare(strict_types=1);

/**
 * app/admin/invoices/show.php
 *
 * Comprehensive invoice detail page — the most feature-rich module in FleetForge.
 * Designed to be sent to customers, printed, and used for internal tracking.
 *
 * Sections:
 *   1. Status timeline + KPI stat cards
 *   2. From/To billing addresses + invoice metadata
 *   3. Rate method explanation (collapsible)
 *   4. Detailed line items with expandable calculation breakdowns
 *   5. Financial summary with full tax breakdown
 *   6. Delivery & tracking (sent date/by/method, late fees, credit notes)
 *   7. Payment history with allocations
 *   8. Notes (inline-editable for drafts)
 *   9. Activity log (audit trail)
 *  10. Action modals (Send, Void, Delete) + print support
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.7 Invoices
 * @decisions D5 (soft-delete), D12 (immutability), D16 (bcmath), D19 (optimistic lock), D22 (tax)
 * @session  S019
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'view');

/* ─── Resolve invoice ID ────────────────────────────────────────── */
$invoiceId = clean_int($_GET['id'] ?? null);
if (!$invoiceId || $invoiceId <= 0) {
    header('Location: ' . base_url('invoices'));
    exit;
}

/* ─── Load invoice ──────────────────────────────────────────────── */
$invoice = db_row(
    "SELECT * FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$invoiceId]
);

if (!$invoice) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Invoice Not Found</h1>';
    exit;
}

// S-LEASE-CLOSE-ACTUAL-DATE: merge the lease's pickup/return time fields so
// ff_invoice_display_period_end() can show the ACTUAL rental end (real return
// date) in the Billing Period row when the return-day-not-billed time-of-day
// rule trimmed the final day. billing_period_end stays the trimmed billed
// extent for all billing math. ($invoice has none of these keys → no clobber.)
if (!empty($invoice['lease_id'])) {
    $_leasePeriodFields = db_row(
        "SELECT actual_return_date, actual_return_time, start_time, billing_days_removed
           FROM leases WHERE id = ?",
        [$invoice['lease_id']]
    );
    if ($_leasePeriodFields) {
        $invoice += $_leasePeriodFields;
    }
}

/* ─── QBO push mapping + sync log (S-QBO-INVOICE-SHOW-RICH-PANEL) ── */
// Drives the QuickBooks Sync inline panel that replaced the simple
// S-QBO-11 header badge. Only loads when the connection is established
// (no point loading mapping/log when the panel won't render anyway).
// Mirrors the S-QBO-6 customer-show pattern.
$qboConnected         = ((string) settings_get('quickbooks.connection_status', 'disconnected') === 'connected');
$qboCanEdit           = $qboConnected && can('quickbooks', 'edit_credentials');
$qboInvoiceMapping    = null;
$qboSyncLogHistory    = [];
$qboSyncLogTotalCount = 0;
$qboEnvironment       = 'sandbox';
if ($qboConnected) {
    // Full mapping row — qbo_currency snapshot column added in
    // S-QBO-PUSHER-CONTRACT-PAYDOWN drives the panel currency line.
    $qboInvoiceMapping = db_row(
        "SELECT id, qbo_invoice_id, qbo_sync_token, qbo_doc_number, qbo_currency,
                push_status, push_error, pushed_at, last_synced_at, created_at, updated_at
           FROM acc_qbo_invoice_map
          WHERE ff_invoice_id = ?",
        [$invoiceId]
    );
    // Push history — last 20 rows per the panel design. Filtered to
    // entity_type='invoice' AND entity_id=ff_invoice_id; uses the
    // idx_entity(entity_type, entity_id) covering index.
    $qboSyncLogHistory = db_select(
        "SELECT id, direction, operation, http_method, response_status,
                duration_ms, error_code, error_message, created_at
           FROM acc_qbo_sync_log
          WHERE entity_type = 'invoice' AND entity_id = ?
          ORDER BY created_at DESC
          LIMIT 20",
        [$invoiceId]
    );
    // Total count drives the "+N more attempts" footer when >20.
    $qboSyncLogTotalRow = db_row(
        "SELECT COUNT(*) AS cnt FROM acc_qbo_sync_log
          WHERE entity_type = 'invoice' AND entity_id = ?",
        [$invoiceId]
    );
    $qboSyncLogTotalCount = (int) ($qboSyncLogTotalRow['cnt'] ?? 0);
    $qboEnvironment       = (string) settings_get('quickbooks.environment', 'sandbox');
}

/* ─── Decode JSON fields ────────────────────────────────────────── */
$rateExplanation = null;
if (!empty($invoice['rate_method_explanation'])) {
    $rateExplanation = json_decode($invoice['rate_method_explanation'], true);
}
$sentCcEmails = [];
if (!empty($invoice['sent_cc_emails'])) {
    $sentCcEmails = json_decode($invoice['sent_cc_emails'], true) ?: [];
}

/* ─── Load line items ───────────────────────────────────────────── */
$lineItems = db_select(
    "SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order ASC",
    [$invoiceId]
);

// WHY: Decode detail_lines JSON per item for rendering calculation breakdowns
foreach ($lineItems as &$item) {
    if (!empty($item['detail_lines'])) {
        $item['_detail'] = json_decode($item['detail_lines'], true);
    } else {
        $item['_detail'] = null;
    }
}
unset($item);

// I03: server-rendered financial redaction. Dispatchers (invoices:view,
// payments:NONE) get the operational invoice — number/status/dates/parties and
// line-item descriptions — but NOT dollar amounts (the documented "status +
// dates only — no amounts" contract). Zero the money fields at the SOURCE so
// every downstream format_currency() (incl. the @media print summary card)
// renders $0.00 instead of the real figure, and drop the per-line calc
// breakdown (which embeds rates/amounts). The list + API already redact; this
// closes the server-rendered detail page. NOTE: the payments / credit-application
// / drawdown-audit sub-panels lower in this page still render their own amounts
// (tracked I03 follow-up); the summary + line-items surface is covered here.
if (!can_view_financials()) {
    foreach ([
        'subtotal', 'subtotal_after_discount', 'discount_value', 'discount_amount',
        'tax_gst_amount', 'tax_pst_amount', 'tax_hst_amount', 'tax_total',
        'total_amount', 'amount_paid', 'balance_due', 'credits_applied', 'late_fee_amount',
    ] as $mk) {
        if (array_key_exists($mk, $invoice)) {
            $invoice[$mk] = '0.00';
        }
    }
    foreach ($lineItems as &$li) {
        foreach (['unit_price', 'amount', 'tax_gst_amount', 'tax_pst_amount', 'tax_hst_amount', 'mileage_rate'] as $lk) {
            if (array_key_exists($lk, $li)) {
                $li[$lk] = '0.00';
            }
        }
        $li['_detail'] = null;
    }
    unset($li);
}

/**
 * S-BILLING-INVOICE-CALC-DISPLAY — render a clean, detailed calculation
 * breakdown for a line item's decoded detail_lines.
 *
 * Holistic-engine base-rental/credit lines store a structured audit_meta
 * object ({engine,tier,basis,rates,segments,cumulative_correct,already_billed,
 * delta,...}); previously the template JSON-dumped it, which was unreadable.
 * This renders the calendar-month segments + the running reconciliation
 * (cumulative_correct − already_billed = this invoice) in plain terms. Legacy
 * (period_independent / ProRateCalculator) details — a flat list of
 * explanation strings — render as a simple list. Returns escaped HTML.
 */
function ff_calc_breakdown_html($detail): string
{
    if (!is_array($detail) || $detail === []) {
        return '';
    }

    $isHolistic = (($detail['engine'] ?? '') === 'holistic') || isset($detail['basis']);

    if (!$isHolistic) {
        // Legacy: {explanation:[...]} or a flat array of strings.
        $lines = $detail['explanation'] ?? $detail;
        if (!is_array($lines)) {
            return '';
        }
        $h = '<ul class="calc-list">';
        foreach ($lines as $l) {
            $h .= '<li>' . e(is_string($l) ? $l : json_encode($l)) . '</li>';
        }
        return $h . '</ul>';
    }

    $money   = static fn ($v) => '$' . number_format((float) $v, 2);
    $shortDt = static function ($d) {
        $t = strtotime((string) $d);
        return $t ? date('M j', $t) : e((string) $d);
    };

    $basis   = (string) ($detail['basis'] ?? '');
    $monthly = $detail['rates']['monthly'] ?? null;
    $daily   = $detail['rates']['daily'] ?? null;
    $weekly  = $detail['rates']['weekly'] ?? null;
    $perDay  = ($monthly !== null) ? bcdiv((string) $monthly, '30', 2) : null;
    $isCredit = !empty($detail['is_credit']);

    $basisLabel = match ($basis) {
        'daily'                => 'Daily rate',
        'weekly_flat'          => 'Weekly flat rate',
        'weekly_math'          => 'Weekly rate (full weeks + leftover days)',
        'monthly_single_month' => 'Monthly rate · single calendar month (flat)',
        'monthly_multi_month'  => 'Monthly rate · spans calendar months',
        default                => ucfirst(str_replace('_', ' ', $basis ?: ($detail['tier'] ?? 'rate'))),
    };

    ob_start();
    ?>
    <div class="calc-breakdown">
        <div class="calc-head"><?= $isCredit ? 'Reconciliation credit' : 'How this charge was calculated' ?> — Holistic engine (Revision 2)</div>
        <div class="calc-sub">
            Basis: <?= e($basisLabel) ?>.
            <?php if ($monthly !== null): ?>
                Rates: <?= e($money($daily)) ?>/day · <?= e($money($weekly)) ?>/week · <?= e($money($monthly)) ?>/month
                <?php if ($perDay !== null && str_starts_with($basis, 'monthly')): ?>
                    — partial months prorated at <?= e($money($perDay)) ?>/day (monthly ÷ 30)
                <?php endif; ?>.
            <?php endif; ?>
            <?php if (!empty($detail['extent_end'])): ?>
                Billed through <?= $shortDt($detail['extent_end']) ?> · <?= (int) ($detail['total_days_so_far'] ?? 0) ?> cumulative lease-days.
            <?php endif; ?>
        </div>

        <?php if (!empty($detail['segments']) && is_array($detail['segments'])): ?>
            <table class="calc-seg">
                <thead>
                    <tr><th>Calendar-month segment</th><th>Days</th><th>Basis</th><th class="amt">Amount</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($detail['segments'] as $seg):
                        $complete = !empty($seg['complete']);
                        $segBasis = $complete
                            ? 'full month (flat)'
                            : ((int) ($seg['days'] ?? 0)) . ' × ' . ($perDay !== null ? $money($perDay) : '/30') . '/day';
                    ?>
                        <tr class="<?= $complete ? 'complete' : '' ?>">
                            <td><?= $shortDt($seg['period_start']) ?> – <?= $shortDt($seg['period_end']) ?></td>
                            <td><?= (int) ($seg['days'] ?? 0) ?></td>
                            <td><?= e($segBasis) ?></td>
                            <td class="amt"><?= e($money($seg['amount'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3">Cumulative correct through <?= $shortDt($detail['period_end'] ?? $detail['extent_end'] ?? '') ?></td>
                        <td class="amt"><?= e($money($detail['cumulative_correct'] ?? 0)) ?></td>
                    </tr>
                </tfoot>
            </table>
        <?php elseif ($basis === 'monthly_single_month'): ?>
            <div class="calc-sub" style="margin:6px 0;">
                Single calendar month — weekly math exceeds the month, so it is capped at the flat monthly rate
                <?= e($money($monthly)) ?> (a 22–30 day lease inside one month is not prorated).
            </div>
        <?php endif; ?>

        <div class="calc-recon">
            <div class="calc-row">
                <span>Cumulative correct (through <?= $shortDt($detail['period_end'] ?? $detail['extent_end'] ?? '') ?>)</span>
                <span><?= e($money($detail['cumulative_correct'] ?? 0)) ?></span>
            </div>
            <div class="calc-row">
                <span>− Already billed on prior invoices</span>
                <span><?= e($money($detail['already_billed'] ?? 0)) ?></span>
            </div>
            <div class="calc-row calc-total">
                <span>= This invoice (<?= $isCredit ? 'reconciliation credit' : 'base rental' ?>)</span>
                <span><?= ($isCredit ? '−' : '') . e($money($detail['amount'] ?? $detail['delta'] ?? 0)) ?></span>
            </div>
            <?php // S-LEASE-CLOSE-REMOVE-DAYS: staff-only removal note. Rendered from
                  // the holistic audit_meta (detail_lines), which only this admin panel
                  // reads — the customer invoice (portal view.php) shows the description
                  // line alone and never sees this.
            if (!empty($detail['billing_days_removed_note'])): ?>
            <div class="calc-row" style="font-style:italic;opacity:.85;margin-top:6px;">
                <span><?= e($detail['billing_days_removed_note']) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/* ─── Load payment allocations with payment details ─────────────── */
$invoicePayments = db_select(
    "SELECT
        pa.id AS allocation_id,
        pa.amount AS applied_amount,
        pa.allocation_type,
        pa.created_at AS allocated_at,
        p.id AS payment_id,
        p.payment_number,
        p.payment_method,
        p.reference_number,
        p.payment_date,
        p.status AS payment_status,
        p.currency
     FROM payment_allocations pa
     JOIN payments p ON p.id = pa.payment_id AND p.deleted_at IS NULL
     WHERE pa.invoice_id = ?
     ORDER BY p.payment_date ASC, pa.created_at ASC",
    [$invoiceId]
);

$totalApplied = array_reduce($invoicePayments, function($sum, $row) {
    return bcadd($sum, (string)$row['applied_amount'], 6);
}, '0');

/* ─── Load credit applications against this invoice (S-INVOICE-DISPLAY-COMPREHENSIVE D-G) ─── */
// WHY: credit_note_applications captures each CN application — invoice's aggregate
// credits_applied column shows the total $ but not the breakdown (which CN, when, by whom).
// Mirrors the $invoicePayments query shape (same JOIN pattern, same deleted_at IS NULL guard,
// same null-safety, same ORDER BY applied-time-ascending). The display contract in D-C requires
// this breakdown to render between Line Items and Financial Summary when any rows exist.
$creditApplications = db_select(
    "SELECT
        cna.id AS application_id,
        cna.amount_applied,
        cna.applied_at,
        cna.applied_by,
        cn.id AS credit_note_id,
        cn.credit_note_number,
        cn.amount AS credit_note_total,
        cn.currency AS credit_note_currency,
        cn.source AS credit_note_source,
        cn.reason AS credit_note_reason,
        cn.source_invoice_id,
        u.name AS applied_by_name
     FROM credit_note_applications cna
     JOIN credit_notes cn ON cn.id = cna.credit_note_id AND cn.deleted_at IS NULL
     LEFT JOIN users u ON u.id = cna.applied_by
     WHERE cna.invoice_id = ?
     ORDER BY cna.applied_at ASC",
    [$invoiceId]
);

$totalCreditsApplied = array_reduce($creditApplications, function($sum, $row) {
    return bcadd($sum, (string)$row['amount_applied'], 6);
}, '0');

/* ─── Load credit notes linked to this invoice ─────────────────── */
// WHY: Credits sourced FROM this invoice (e.g., mileage overpayment generated a credit)
$creditNotesFrom = db_select(
    "SELECT id, credit_note_number, amount, amount_remaining, status, source, reason, created_at
     FROM credit_notes
     WHERE source_invoice_id = ? AND deleted_at IS NULL
     ORDER BY created_at DESC",
    [$invoiceId]
);
// WHY: If this invoice IS a credit note for another invoice
$creditNoteForInvoice = null;
if (!empty($invoice['credit_note_for_invoice_id'])) {
    $creditNoteForInvoice = db_row(
        "SELECT id, invoice_number, total_amount FROM invoices WHERE id = ? AND deleted_at IS NULL",
        [$invoice['credit_note_for_invoice_id']]
    );
}

/* ─── Load late fee linked invoice ─────────────────────────────── */
$lateFeeInvoice = null;
if (!empty($invoice['late_fee_invoice_id'])) {
    $lateFeeInvoice = db_row(
        "SELECT id, invoice_number, total_amount, status FROM invoices WHERE id = ? AND deleted_at IS NULL",
        [$invoice['late_fee_invoice_id']]
    );
}

/* ─── Load user names for tracking fields ──────────────────────── */
// WHY: Display human-readable names instead of raw user IDs
$userIds = array_filter([
    $invoice['created_by'] ?? null,
    $invoice['updated_by'] ?? null,
    $invoice['sent_by'] ?? null,
    $invoice['voided_by'] ?? null,
    $invoice['written_off_by'] ?? null,
]);
$userNames = [];
if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $users = db_select(
        "SELECT id, name FROM users WHERE id IN ({$placeholders})",
        array_values($userIds)
    );
    foreach ($users as $u) {
        $userNames[(int)$u['id']] = $u['name'];
    }
}

/* ─── S-LEASE-MILEAGE mileage-review breakdown load — RETIRED ────── */
// Retired in S-MILEAGE-2B C5 per D-I locked (i) wholesale retire +
// D-G column DROP (mileage_review_status, mileage_reviewed_by_user_id no
// longer exist post-migration 202605120907_S-MILEAGE-2B_model_c_retirement.sql).
// The Mileage Review card converts to a Drawdown Reconciliation panel in
// S-MILEAGE-2B C6 per D-L (using $lease.precharge_balance + per-invoice
// drawdown audit_log entity_type='lease_precharge_balance_drawdown').
$mileageReviewLease = null;
$mileageReviewedByName = null;

/* ─── Load activity log (most recent 50 entries) ────────────────── */
$activityLog = db_select(
    "SELECT action, user_name, notes, old_values, new_values, ip_address, created_at
     FROM audit_log
     WHERE entity_type = 'invoice' AND entity_id = ?
     ORDER BY created_at DESC
     LIMIT 50",
    [$invoiceId]
);

/* ─── Computed display values ───────────────────────────────────── */
$statusBadgeClass = match($invoice['status']) {
    'draft'          => 'badge-neutral',
    'sent'           => 'badge-info',
    'paid'           => 'badge-success',
    'partially_paid' => 'badge-warning',
    'overdue'        => 'badge-danger',
    'void'           => 'badge-neutral',
    'written_off'    => 'badge-danger',
    default          => 'badge-neutral',
};

$typeBadgeClass = match($invoice['invoice_type']) {
    'regular'     => 'badge-info',
    'final'       => 'badge-warning',
    'credit_note' => 'badge-success',
    'late_fee'    => 'badge-danger',
    'mileage_only'=> 'badge-neutral',
    'adjustment'  => 'badge-warning',
    default       => 'badge-neutral',
};

$typeLabels = [
    'regular'      => 'Regular',
    'final'        => 'Final',
    'credit_note'  => 'Credit Note',
    'late_fee'     => 'Late Fee',
    'mileage_only' => 'Mileage Only',
    'adjustment'   => 'Adjustment',
];

$methodLabels = [
    'check' => 'Cheque', 'ach' => 'ACH', 'wire' => 'Wire',
    'credit_card' => 'Credit Card', 'cash' => 'Cash',
    'e_transfer' => 'e-Transfer', 'account_credit' => 'Acct Credit', 'other' => 'Other',
];

$isOverdue = ($invoice['status'] !== 'paid' && $invoice['status'] !== 'void'
    && $invoice['status'] !== 'written_off' && !empty($invoice['due_date'])
    && $invoice['due_date'] < date('Y-m-d'));

$isDraft      = ($invoice['status'] === 'draft');
$isVoid       = ($invoice['status'] === 'void');
$isWrittenOff = ($invoice['status'] === 'written_off');
$isPaid       = ($invoice['status'] === 'paid');
$canRecordPayment = in_array($invoice['status'], ['sent', 'partially_paid', 'overdue']);
// WHY: super_admin role can edit/delete invoices of any status — all other roles are draft-only (D12)
$isSuperAdmin = is_super_admin();
$canEdit   = ($isDraft || $isSuperAdmin) && can('invoices', 'edit');
$canDelete = ($isDraft || $isSuperAdmin) && can('invoices', 'delete');

// WHY: Count line items by category for summary badges
// S-MILEAGE-FIX-0 (D-G): use the actual schema enum values for mileage
// line items. Pre-fix referenced 'mileage_charge' / 'mileage_overage'
// which are NOT in invoice_line_items.item_type — the count silently
// returned 0 even when real mileage_adjustment / mileage_credit /
// mileage_precharge lines existed.
$creditLineCount = 0;
$mileageLineCount = 0;
$mileageItemTypes = ['mileage_precharge', 'mileage_estimate', 'mileage_adjustment', 'mileage_credit', 'mileage_usage', 'mileage_drawdown_credit'];
foreach ($lineItems as $li) {
    if ($li['is_credit']) $creditLineCount++;
    if (in_array($li['item_type'], $mileageItemTypes, true)) $mileageLineCount++;
}

/* ─── Company settings for the print letterhead ─────────────────
 * WHY: The "Print" button calls window.print(); the browser then
 * re-renders the page through @media print. The on-screen header
 * contains sidebar / breadcrumb / action buttons (all hidden in
 * print), so we need a dedicated print-only letterhead block at
 * the top of the printed document that identifies the supplier
 * with company name, address, contact info, and the regulatory
 * tax IDs (GST/PST) that PASS-13:T3/I1 requires on every invoice.
 */
$companyName    = (string) settings_get('company.name',        'FleetForge');
$companyAddress = (string) settings_get('company.address',     '');
$companyCity    = (string) settings_get('company.city',        '');
$companyProv    = (string) settings_get('company.province',    '');
$companyPostal  = (string) settings_get('company.postal_code', '');
$companyPhone   = (string) settings_get('company.phone',       '');
$companyEmail   = (string) settings_get('company.email',       '');
$companyWebsite = (string) settings_get('company.website',     '');
$companyGst     = (string) settings_get('company.gst_number',  '');
$companyPst     = (string) settings_get('company.pst_number',  '');

// WHY: Compose the "City, Province Postal" line, skipping empties
$companyCityLine = trim(
    $companyCity
    . ($companyProv   ? ($companyCity ? ', ' : '') . $companyProv : '')
    . ($companyPostal ? ' ' . $companyPostal : '')
);

// WHY: Hide the Notes card in print when there's nothing customer-facing
// to show. Without this the card renders as an ugly empty box with the
// "No notes or references on this invoice" placeholder text.
$hasPrintableNotes = !empty($invoice['notes'])
                  || !empty($invoice['po_number'])
                  || !empty($invoice['void_reason'])
                  || !empty($invoice['write_off_reason']);

$pageTitle      = 'Invoice ' . $invoice['invoice_number'];
$helpModuleSlug = 'invoices';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ================================================================
     PRINT-ONLY STYLES — Scoped to this page
     WHY: Redesigned from scratch (S043) so the printed PDF is
     professional letterhead output that fits on 1–2 letter pages.
     Key decisions:
       • Hide the sidebar, topbar, breadcrumb, stat-timeline, action
         buttons, rate-calc breakdown, delivery tracking, payment
         history, internal notes, and activity log — customers don't
         need any of that on their printed invoice.
       • Show a dedicated .ff-print-header letterhead with company
         name/address/tax IDs and a big "INVOICE" wordmark plus the
         invoice number and dates (so the print is standalone).
       • Shrink stat cards into a 4-up summary strip (Date, Due,
         Total, Balance Due) instead of the chunky on-screen tiles.
       • Compact typography: 9pt body, 6.5pt stat labels, table
         rows 5/6px padding. Targets density:compact even if the
         user is on spacious.
       • Single bordered card look (no rounded corners/shadows).
     ================================================================ -->
<style>
    /* Print-only helper classes — hidden on-screen by default */
    @media screen {
        .print-only, .ff-print-only { display: none !important; }
    }

    @media print {
        /* ───── PAGE SETUP — US Letter with tight margins ─────
           0.35in top/bottom and 0.4in left/right maximize content
           area while staying within safe printable margins for
           most office printers. */
        @page {
            size: letter;
            margin: 0.35in 0.4in;
        }

        html, body {
            background: #ffffff !important;
            color: #111 !important;
            font-family: 'DM Sans', Arial, sans-serif !important;
            font-size: 9pt !important;
            line-height: 1.3 !important;
        }

        /* Reset the app shell so only invoice content flows */
        .app-layout, .app-main, .page-content, main#main-content,
        [x-data="FF_InvoiceShow()"] {
            display: block !important;
            width: 100% !important;
            max-width: 100% !important;
            min-height: 0 !important;
            height: auto !important;
            padding: 0 !important;
            margin: 0 !important;
            background: #ffffff !important;
            overflow: visible !important;
        }

        /* ───── HIDE app chrome + all screen-only sections ───── */
        .no-print,
        .breadcrumb,
        .page-header,
        .page-header-actions,
        .invoice-status-timeline,
        .invoice-actions-bar,
        .activity-log-section,
        .btn, button,
        .toast,
        .rate-explanation-body,
        .inline-edit-section,
        .app-footer,
        .sidebar, .topbar, #ff-toast-container,
        .modal-overlay, .chat-widget,
        .ff-print-hide {
            display: none !important;
        }

        /* Show print-only elements */
        .ff-print-only, .print-only {
            display: block !important;
        }
        .ff-print-header.ff-print-only {
            display: flex !important;
        }

        /* ───── LETTERHEAD — company info + INVOICE wordmark ───── */
        .ff-print-header {
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #111;
            padding: 0 0 8px 0;
            margin: 0 0 10px 0;
            gap: 24px;
        }
        .ff-print-header .pc-company { flex: 1 1 60%; max-width: 60%; }
        .ff-print-header .pc-name {
            font-size: 14pt;
            font-weight: 700;
            letter-spacing: 0.2px;
            color: #111;
            margin: 0 0 3px 0;
            line-height: 1.2;
        }
        .ff-print-header .pc-addr {
            font-size: 8.5pt;
            line-height: 1.45;
            color: #333;
        }
        .ff-print-header .pc-reg {
            font-size: 7.5pt;
            color: #555;
            margin-top: 4px;
            font-family: 'DM Mono', monospace;
        }
        .ff-print-header .pc-title {
            flex: 0 0 auto;
            text-align: right;
            min-width: 200px;
        }
        .ff-print-header .pc-title-text {
            font-size: 22pt;
            font-weight: 700;
            letter-spacing: 3px;
            color: #111;
            margin: 0;
            line-height: 1;
        }
        .ff-print-header .pc-number {
            font-size: 10pt;
            font-family: 'DM Mono', monospace;
            margin-top: 4px;
            color: #111;
            font-weight: 600;
        }
        .ff-print-header .pc-meta {
            font-size: 8pt;
            color: #333;
            margin-top: 6px;
            line-height: 1.55;
            font-family: 'DM Mono', monospace;
        }
        .ff-print-header .pc-status-badge {
            display: inline-block;
            border: 1px solid #111;
            padding: 1px 6px;
            font-size: 7.5pt;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        .ff-print-header .pc-status-badge.is-overdue,
        .ff-print-header .pc-status-badge.is-void,
        .ff-print-header .pc-status-badge.is-written-off {
            background: #111;
            color: #fff;
        }

        /* ───── STAT SUMMARY STRIP — 5 compact tiles across ─────
           Five cards (Date, Due, Total, Paid, Balance) so nothing
           wraps to a second row on letter paper. */
        .stat-grid {
            display: grid !important;
            grid-template-columns: repeat(5, 1fr) !important;
            gap: 0 !important;
            margin: 0 0 8px 0 !important;
            break-inside: avoid;
        }
        .stat-card {
            padding: 6px 8px 6px 10px !important;
            border: 1px solid #bbb !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            background: #ffffff !important;
            margin: 0 !important;
            color: #111 !important;
            min-height: 0 !important;
            overflow: hidden !important;
        }
        .stat-card + .stat-card { border-left: none !important; }
        /* Kill the orange ::before accent stripe in print — it's a
           screen-design flourish that clashes with the clean print look */
        .stat-card::before { display: none !important; content: none !important; }
        .stat-card .stat-label {
            font-size: 6.5pt !important;
            font-weight: 600 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            color: #666 !important;
            margin: 0 0 2px 0 !important;
        }
        .stat-card .stat-value {
            font-size: 11pt !important;
            font-weight: 700 !important;
            color: #111 !important;
            font-family: 'DM Mono', monospace !important;
            line-height: 1.15 !important;
        }
        /* Neutralise on-screen danger/success inline colors in print */
        .stat-card .stat-value[style*="color"],
        .stat-card .stat-value * {
            color: #111 !important;
        }
        .stat-card .text-sm,
        .stat-card .stat-value div {
            font-size: 6.5pt !important;
            font-weight: 400 !important;
            color: #666 !important;
            margin-top: 1px !important;
        }

        /* ───── BILL TO / INVOICE DETAILS ───── */
        .invoice-addresses {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 10px !important;
            margin: 0 0 8px 0 !important;
            break-inside: avoid;
        }
        .invoice-addresses .card {
            padding: 10px 12px !important;
            border: 1px solid #bbb !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            margin: 0 !important;
            background: #ffffff !important;
        }
        .invoice-addresses h3 {
            font-size: 7.5pt !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            color: #666 !important;
            margin: 0 0 6px 0 !important;
        }
        .invoice-addresses dl {
            grid-template-columns: 95px 1fr !important;
            gap: 3px 10px !important;
            font-size: 8.5pt !important;
            margin: 0 !important;
        }
        .invoice-addresses dd,
        .invoice-addresses dt {
            font-size: 8.5pt !important;
            line-height: 1.4 !important;
            color: #111 !important;
        }
        .invoice-addresses dt { color: #666 !important; }
        .invoice-addresses .card > div { font-size: 8.5pt !important; line-height: 1.4 !important; }
        .invoice-addresses .card > div > div[style*="font-weight:600"] {
            font-size: 9pt !important;
        }

        /* ───── LINE ITEMS CARD ───── */
        .line-items-card {
            border: 1px solid #111 !important;
            border-radius: 0 !important;
            margin: 0 0 8px 0 !important;
            padding: 0 !important;
            box-shadow: none !important;
            background: #ffffff !important;
            break-inside: auto;
        }
        .line-items-card > div:first-child {
            padding: 6px 10px !important;
            border-bottom: 1px solid #111 !important;
            background: #f2f2f2 !important;
        }
        .line-items-card h3 {
            font-size: 9pt !important;
            margin: 0 !important;
        }
        /* Hide only header-bar badges (the "1 item" / "credits" counts),
           keep the per-row Type badges inside the table body visible. */
        .line-items-card > div:first-child .badge { display: none !important; }
        .line-items-card table { font-size: 8pt !important; }
        .line-items-card th {
            font-size: 6.5pt !important;
            text-transform: uppercase !important;
            letter-spacing: 0.4px !important;
            background: #fafafa !important;
            color: #444 !important;
            border-bottom: 1px solid #111 !important;
            padding: 5px 6px !important;
            font-weight: 700 !important;
            vertical-align: middle !important;
        }
        .line-items-card td {
            padding: 5px 6px !important;
            font-size: 8pt !important;
            color: #111 !important;
            border-bottom: 1px solid #e5e5e5 !important;
            vertical-align: top !important;
        }
        .line-items-card .mileage-detail { font-size: 7pt !important; color: #555 !important; }
        .line-items-card .detail-toggle { display: none !important; }
        .line-items-card .detail-expansion { display: none !important; }
        .line-items-card tfoot td {
            background: #f2f2f2 !important;
            border-top: 1px solid #111 !important;
            border-bottom: none !important;
            font-weight: 700 !important;
            font-size: 8.5pt !important;
            padding: 6px 8px !important;
            color: #111 !important;
        }
        /* Column type badges — flatten for print */
        .line-items-card td .badge {
            border: 1px solid #777 !important;
            background: #ffffff !important;
            color: #333 !important;
            padding: 0 3px !important;
            font-size: 6pt !important;
            border-radius: 2px !important;
        }

        /* ───── FINANCIAL SUMMARY ───── */
        .financial-summary-card {
            border: 1px solid #bbb !important;
            border-radius: 0 !important;
            margin: 0 0 8px 0 !important;
            padding: 0 !important;
            box-shadow: none !important;
            background: #ffffff !important;
            break-inside: avoid;
        }
        .financial-summary-card > div:first-child {
            padding: 6px 10px !important;
            border-bottom: 1px solid #bbb !important;
            background: #f2f2f2 !important;
        }
        .financial-summary-card > div:first-child h3 {
            font-size: 9pt !important;
            margin: 0 !important;
        }
        .financial-summary-card > div:last-child {
            padding: 10px 12px !important;
        }
        .financial-summary-table {
            max-width: 360px !important;
            margin-left: auto !important;
        }
        .financial-summary-table td {
            padding: 3px 12px !important;
            font-size: 8.5pt !important;
            color: #111 !important;
        }
        .financial-summary-table .fs-label { color: #444 !important; }
        .financial-summary-table .fs-total {
            font-size: 10pt !important;
            font-weight: 700 !important;
        }
        .financial-summary-table .fs-grand td {
            border-top: 1.5px solid #111 !important;
            padding-top: 6px !important;
            padding-bottom: 6px !important;
        }
        .financial-summary-table .fs-divider td {
            border-top: 1px solid #111 !important;
            padding-top: 5px !important;
            padding-bottom: 5px !important;
        }
        .financial-summary-table .fs-value[style*="color"],
        .financial-summary-table td[style*="color"] {
            color: #111 !important;
        }

        /* ───── NOTES & REFERENCES ───── */
        #invoice-edit-section {
            border: 1px solid #bbb !important;
            border-radius: 0 !important;
            margin: 0 0 6px 0 !important;
            padding: 0 !important;
            box-shadow: none !important;
            background: #ffffff !important;
            break-inside: avoid;
        }
        #invoice-edit-section > div:first-child {
            padding: 4px 10px !important;
            border-bottom: 1px solid #bbb !important;
            background: #f2f2f2 !important;
        }
        #invoice-edit-section > div:first-child h3 {
            font-size: 8.5pt !important;
            margin: 0 !important;
        }
        #invoice-edit-section > div:nth-child(2) {
            padding: 6px 10px !important;
            font-size: 8pt !important;
        }
        #invoice-edit-section > div:nth-child(2) > div { gap: 6px !important; }
        #invoice-edit-section .text-secondary { color: #555 !important; }
        /* Empty notes message is hidden in print */
        #invoice-edit-section p.text-secondary { display: none !important; }

        /* ───── GENERIC CARD POLISH ───── */
        .card {
            border: 1px solid #bbb !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            background: #ffffff !important;
            color: #111 !important;
            break-inside: avoid;
        }

        /* ───── BADGES — flat, monochrome ───── */
        .badge {
            border: 1px solid #777 !important;
            background: #ffffff !important;
            color: #333 !important;
            padding: 0 4px !important;
            font-size: 6.5pt !important;
            border-radius: 2px !important;
            box-shadow: none !important;
            letter-spacing: 0.2px !important;
        }

        /* ───── LINKS — plain ink ───── */
        a, a:link, a:visited {
            color: #111 !important;
            text-decoration: none !important;
        }

        /* ───── PRINT FOOTER line ─────
           Use #ff-print-footer ID selector so we beat the inline
           style's margin-top:40px / padding:24px 0 without fighting
           attribute selector specificity. */
        #ff-print-footer {
            border-top: 1px solid #999 !important;
            padding: 5px 0 0 0 !important;
            margin-top: 8px !important;
            font-size: 7pt !important;
            color: #666 !important;
            text-align: center !important;
        }
        #ff-print-footer strong {
            font-family: 'DM Mono', monospace;
            color: #111 !important;
        }

        /* ───── PAGE BREAK BEHAVIOUR ───── */
        h1, h2, h3 { break-after: avoid; }
        table { break-inside: auto; }
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        tr { break-inside: avoid; }
    }

    /* Status timeline styles */
    .invoice-status-timeline {
        display: flex;
        align-items: center;
        gap: 0;
        padding: 16px 20px;
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        margin-bottom: 24px;
    }
    .timeline-step {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: var(--text-muted);
        white-space: nowrap;
    }
    .timeline-step.active {
        color: var(--text-primary);
        font-weight: 600;
    }
    .timeline-step.completed {
        color: var(--color-success);
    }
    .timeline-step.void-step {
        color: var(--color-danger);
    }
    .timeline-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: var(--border-color);
        flex-shrink: 0;
    }
    .timeline-step.active .timeline-dot {
        background: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(var(--color-primary-rgb, 59,130,246), 0.2);
    }
    .timeline-step.completed .timeline-dot { background: var(--color-success); }
    .timeline-step.void-step .timeline-dot { background: var(--color-danger); }
    .timeline-connector {
        flex: 1;
        height: 2px;
        background: var(--border-color);
        margin: 0 8px;
        min-width: 20px;
    }
    .timeline-connector.completed { background: var(--color-success); }

    /* Detail lines toggle */
    .detail-toggle {
        cursor: pointer;
        color: var(--color-primary);
        font-size: 12px;
        text-decoration: underline;
        text-decoration-style: dotted;
    }
    .detail-toggle:hover { text-decoration-style: solid; }
    .detail-expansion {
        background: var(--bg-muted);
        border-radius: 4px;
        padding: 8px 12px;
        margin-top: 6px;
        font-size: 12px;
        line-height: 1.6;
    }
    .detail-expansion li {
        color: var(--text-secondary);
        list-style: none;
        padding: 1px 0;
    }
    .detail-expansion li:last-child {
        font-weight: 600;
        color: var(--text-primary);
        border-top: 1px dashed var(--border-color);
        padding-top: 4px;
        margin-top: 4px;
    }

    /* S-BILLING-INVOICE-CALC-DISPLAY: the .hidden utility was never defined, so
       every "Show calculation" toggle (which flips .hidden) silently no-op'd.
       Page-scoped here so the existing toggles work and the breakdown collapses. */
    .hidden { display: none !important; }

    /* Holistic calculation breakdown — clean, detailed, readable */
    .calc-breakdown { font-size: 12.5px; line-height: 1.55; }
    .calc-breakdown .calc-head {
        font-weight: 700; color: var(--text-primary); margin-bottom: 2px;
    }
    .calc-breakdown .calc-sub {
        color: var(--text-secondary); margin-bottom: 10px;
    }
    .calc-breakdown table.calc-seg {
        width: 100%; border-collapse: collapse; margin: 8px 0 10px;
        font-family: 'DM Mono', ui-monospace, monospace; font-size: 12px;
    }
    .calc-breakdown table.calc-seg th {
        text-align: left; font-weight: 600; color: var(--text-secondary);
        border-bottom: 1px solid var(--border-color); padding: 3px 8px 3px 0;
        font-family: var(--font-sans, inherit); font-size: 11px; text-transform: uppercase; letter-spacing: .03em;
    }
    .calc-breakdown table.calc-seg td { padding: 3px 8px 3px 0; color: var(--text-secondary); }
    .calc-breakdown table.calc-seg td.amt,
    .calc-breakdown table.calc-seg th.amt { text-align: right; padding-right: 0; }
    .calc-breakdown table.calc-seg tr.complete td { color: var(--text-primary); }
    .calc-breakdown table.calc-seg tfoot td {
        border-top: 1px dashed var(--border-color); font-weight: 600; color: var(--text-primary); padding-top: 5px;
    }
    .calc-recon { margin-top: 8px; max-width: 360px; font-family: 'DM Mono', ui-monospace, monospace; font-size: 12px; }
    .calc-recon .calc-row { display: flex; justify-content: space-between; gap: 16px; padding: 2px 0; color: var(--text-secondary); }
    .calc-recon .calc-row.calc-total {
        border-top: 1px solid var(--border-color); margin-top: 4px; padding-top: 5px;
        font-weight: 700; color: var(--text-primary);
    }
    .calc-breakdown ul.calc-list { margin: 0; padding: 0; }
    .calc-breakdown ul.calc-list li { list-style: none; padding: 1px 0; color: var(--text-secondary); }

    /* Mileage detail block */
    .mileage-detail {
        display: inline-flex;
        gap: 12px;
        font-size: 11px;
        color: var(--text-secondary);
        margin-top: 4px;
    }
    .mileage-detail span { white-space: nowrap; }

    /* Financial summary */
    .financial-summary-table { width: 100%; border-collapse: collapse; }
    .financial-summary-table td { padding: 6px 16px; font-size: 13px; }
    .financial-summary-table .fs-label { color: var(--text-secondary); }
    .financial-summary-table .fs-value { text-align: right; font-family: 'DM Mono', monospace; }
    .financial-summary-table .fs-total { font-weight: 700; font-size: 15px; }
    .financial-summary-table .fs-divider td {
        border-top: 1px solid var(--border-color);
        padding-top: 10px;
    }
    .financial-summary-table .fs-grand td {
        border-top: 2px solid var(--text-primary);
        padding-top: 12px;
        padding-bottom: 12px;
    }

    /* Activity log */
    .activity-item {
        display: flex;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid var(--border-color);
        font-size: 13px;
    }
    .activity-item:last-child { border-bottom: none; }
    .activity-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--color-primary);
        margin-top: 6px;
        flex-shrink: 0;
    }
    .activity-dot.action-create { background: var(--color-success); }
    .activity-dot.action-update { background: var(--color-primary); }
    .activity-dot.action-status_change { background: var(--color-warning); }
    .activity-dot.action-invoice_sent { background: var(--color-info); }
    .activity-dot.action-invoice_voided { background: var(--color-danger); }
    .activity-dot.action-payment_recorded { background: var(--color-success); }
    .activity-dot.action-delete { background: var(--color-danger); }

    /* Inline edit form */
    .inline-edit-section {
        background: var(--bg-muted);
        border-top: 1px solid var(--border-color);
        padding: 20px;
        border-radius: 0 0 var(--radius) var(--radius);
    }

    /* ───────────────────────────────────────────────────────────────
       S-INVOICE-SHOW-RESPONSIVE — page-scoped responsive layer
       Sits ON TOP of RESPONSIVE-1 (app.css). Targets only patterns
       unique to this page: hardcoded inline grid widths in <dl>
       blocks, the status timeline, the section grid wrapper, and
       the FX breakdown table. RESPONSIVE-1 already handles
       .invoice-addresses single-column collapse and
       .page-header-actions wrap, so we don't repeat those.
       ─────────────────────────────────────────────────────────────── */

    /* Tablet (≤768px) — shrink dl label columns, collapse section grid */
    @media (max-width: 768px) {
        /* WHY: !important needed because the matching grid-template-columns
           is on an inline style attribute (specificity 1,0,0,0). */
        .invoice-meta-dl {
            grid-template-columns: 110px 1fr !important;
        }
        .invoice-section-grid {
            grid-template-columns: 1fr !important;
        }
    }

    /* Mobile (≤540px) — stack dt above dd, vertical timeline, FX tighten */
    @media (max-width: 540px) {
        /* dt/dd stack: full-width rows, dt becomes a small uppercase label */
        .invoice-meta-dl {
            grid-template-columns: 1fr !important;
            gap: 2px 0 !important;
        }
        .invoice-meta-dl dt {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--text-secondary);
            margin-top: 8px;
        }
        .invoice-meta-dl dt:first-child {
            margin-top: 0;
        }
        .invoice-meta-dl dd {
            margin: 0;
            padding-bottom: 4px;
            border-bottom: 1px dashed var(--border-color);
        }
        .invoice-meta-dl dd:last-of-type {
            border-bottom: none;
        }

        /* Status timeline: horizontal flex → vertical column.
           Connectors become 2px×16px vertical lines between dots. */
        .invoice-status-timeline {
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
            padding: 14px 16px;
        }
        .invoice-status-timeline .timeline-step {
            white-space: normal;
            width: 100%;
        }
        .invoice-status-timeline .timeline-connector {
            width: 2px;
            height: 16px;
            min-width: 0;
            min-height: 16px;
            margin: 0 0 0 4px;
            flex: 0 0 auto;
        }

        /* FX breakdown table: tighten so it doesn't overflow the card */
        .invoice-fx-block {
            max-width: 100% !important;
        }
        .invoice-fx-block table {
            font-size: 0.75rem !important;
        }
        .invoice-fx-block td {
            padding: 2px 6px 2px 0 !important;
            white-space: normal !important;
        }
    }
</style>

<!-- ================================================================
     Breadcrumb + Page Header
     ================================================================ -->
<nav class="breadcrumb no-print">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('invoices') ?>">Invoices</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e($invoice['invoice_number']) ?></span>
</nav>

<div x-data="FF_InvoiceShow()" x-cloak>

<!-- ================================================================
     PRINT-ONLY LETTERHEAD
     WHY: When the user clicks Print → Save as PDF, the browser uses
     @media print which hides the sidebar/topbar/breadcrumb/buttons.
     Without this block, the printed invoice would have no company
     branding. This block is hidden on-screen (display:none via the
     .ff-print-only rule in @media screen) and visible only when
     printing. It shows the supplier letterhead on the left and the
     "INVOICE" wordmark + number/dates/status on the right.
     ================================================================ -->
<div class="ff-print-header ff-print-only">
    <div class="pc-company">
        <div class="pc-name"><?= e($companyName) ?></div>
        <div class="pc-addr">
            <?php if ($companyAddress): ?><?= nl2br(e($companyAddress)) ?><br><?php endif; ?>
            <?php if ($companyCityLine): ?><?= e($companyCityLine) ?><br><?php endif; ?>
            <?php if ($companyPhone): ?>Tel: <?= e($companyPhone) ?><?php endif; ?>
            <?php if ($companyPhone && $companyEmail): ?> &middot; <?php endif; ?>
            <?php if ($companyEmail): ?><?= e($companyEmail) ?><?php endif; ?>
            <?php if ($companyWebsite): ?><br><?= e($companyWebsite) ?><?php endif; ?>
        </div>
        <?php if ($companyGst || $companyPst): ?>
        <div class="pc-reg">
            <?php if ($companyGst): ?>GST/HST: <?= e($companyGst) ?><?php endif; ?>
            <?php if ($companyGst && $companyPst): ?> &middot; <?php endif; ?>
            <?php if ($companyPst): ?>PST: <?= e($companyPst) ?><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="pc-title">
        <div class="pc-title-text">INVOICE</div>
        <div class="pc-number">#<?= e($invoice['invoice_number']) ?></div>
        <div class="pc-meta">
            Date: <?= format_date($invoice['invoice_date']) ?><br>
            Due:&nbsp; <?= format_date($invoice['due_date']) ?>
            <?php if ($invoice['po_number']): ?><br>PO:&nbsp;&nbsp; <?= e($invoice['po_number']) ?><?php endif; ?>
            <?php if ($invoice['currency'] !== 'CAD'): ?><br>Curr: <?= e($invoice['currency']) ?><?php endif; ?>
        </div>
        <?php
        // WHY: Badge class names in print-CSS drive whether the status
        // box gets a black background (overdue/void/written-off) or stays
        // an outlined ink box (sent/paid/draft/partially_paid).
        $printBadgeClass = '';
        if ($isOverdue)       $printBadgeClass = ' is-overdue';
        elseif ($isVoid)      $printBadgeClass = ' is-void';
        elseif ($isWrittenOff)$printBadgeClass = ' is-written-off';
        ?>
        <div class="pc-status-badge<?= $printBadgeClass ?>">
            <?= e($isOverdue ? 'Overdue' : ucfirst(str_replace('_', ' ', $invoice['status']))) ?>
        </div>
    </div>
</div>

<div class="page-header">
    <div>
        <h1 class="page-header-title h4">
            <?= e($invoice['invoice_number']) ?>
            <span class="badge badge-no-dot <?= $statusBadgeClass ?>" style="vertical-align:middle; margin-left:8px;">
                <?= e(ucfirst(str_replace('_', ' ', $invoice['status']))) ?>
            </span>
            <?php if ($invoice['invoice_type'] !== 'regular'): ?>
            <span class="badge badge-no-dot <?= $typeBadgeClass ?>" style="vertical-align:middle; margin-left:4px; font-size:11px;">
                <?= e($typeLabels[$invoice['invoice_type']] ?? $invoice['invoice_type']) ?>
            </span>
            <?php endif; ?>
            <?php if ($isOverdue): ?>
            <span class="badge badge-no-dot badge-danger" style="vertical-align:middle; margin-left:4px; font-size:11px;">
                OVERDUE
            </span>
            <?php endif; ?>
            <?php /* S-QBO-INVOICE-SHOW-RICH-PANEL: header badge retired —
                     replaced by the full QuickBooks Sync panel rendered
                     below the KPI stat cards (lines ~1410). Operator
                     feedback: header badge "disappeared" for invoices
                     without a mapping row + showed no info beyond the
                     state. Maximalist inline panel surfaces everything
                     visible at once. */ ?>
        </h1>
        <div class="text-secondary text-sm" style="margin-top:4px;">
            <?php if ($invoice['customer_id']): ?>
                <a href="<?= base_url('customers/show') ?>?id=<?= (int)$invoice['customer_id'] ?>" class="link">
                    <?= e($invoice['company_name_snapshot'] ?? $invoice['customer_name_snapshot'] ?? '—') ?>
                </a>
            <?php else: ?>
                <?= e($invoice['company_name_snapshot'] ?? '—') ?>
            <?php endif; ?>
            <?php if ($invoice['contract_number_snapshot']): ?>
                <span class="text-muted">·</span> <?= e($invoice['contract_number_snapshot']) ?>
            <?php endif; ?>
            <?php if ($invoice['unit_number_invoice_snapshot']): ?>
                <span class="text-muted">·</span> Unit <?= e($invoice['unit_number_invoice_snapshot']) ?>
            <?php endif; ?>
            <?php if ($invoice['currency'] !== 'CAD'): ?>
                <span class="text-muted">·</span>
                <span class="badge badge-no-dot badge-warning" style="font-size:10px;"><?= e($invoice['currency']) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="page-header-actions">
        <?= help_button('invoices') ?>
        <?php if (function_exists('can') && can('ai', 'view') && (bool)settings_get('ai.enabled', false) && (settings_get('ai.anthropic_api_key') ?: env('AI_ANTHROPIC_API_KEY', ''))): ?>
        <button type="button" class="btn btn-secondary btn-sm no-print"
                onclick="aiPanel_invoice_<?= (int)$invoiceId ?>_invoice_analysis_open()"
                title="Open AI Invoice Analysis">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:14px;height:14px;margin-right:4px;vertical-align:-2px;" aria-hidden="true">
                <path d="M12 2L14.5 9.5L22 12L14.5 14.5L12 22L9.5 14.5L2 12L9.5 9.5L12 2Z" fill="currentColor"/>
            </svg>
            AI Analysis
        </button>
        <?php endif; ?>
        <!-- Print -->
        <button class="btn btn-secondary btn-sm no-print" onclick="window.print();" title="Print Invoice">
            <?= heroicon('document-text', 'icon-sm') ?>
            Print
        </button>

        <?php if (can('customers', 'create')): /* EMAIL-1: email this invoice */ ?>
        <button type="button"
                class="btn btn-primary btn-sm no-print"
                onclick="openEmailCompose({
                    customerId:   <?= (int)$invoice['customer_id'] ?>,
                    toEmail:      <?= e(json_encode((string)($invoice['customer_email_snapshot'] ?? ''))) ?>,
                    toName:       <?= e(json_encode((string)($invoice['customer_name_snapshot'] ?? $invoice['company_name_snapshot']))) ?>,
                    templateSlug: 'invoice_ready',
                    entityType:   'invoice',
                    entityId:     <?= (int)$invoice['id'] ?>
                })"
                title="Email invoice to customer">
            <?= heroicon('envelope', 'icon-sm') ?>
            Email Invoice
        </button>
        <?php endif; ?>

        <?php if ($canEdit): ?>
            <!-- Edit Notes -->
            <button class="btn btn-secondary btn-sm" @click="startEdit()">
                <?= heroicon('pencil-square', 'icon-sm') ?>
                Edit
            </button>
            <!-- Send Invoice (S-MILEAGE-2B C5: HARD review gate retired per D-I) -->
            <button class="btn btn-primary btn-sm"
                    @click="sendInvoice()"
                    :disabled="sending">
                <span x-show="!sending">Send Invoice</span>
                <span x-show="sending">Sending…</span>
            </button>
        <?php endif; ?>

        <?php if ($canRecordPayment && can('payments', 'create')): ?>
            <a href="<?= base_url('/payments/create') ?>?invoice_id=<?= (int)$invoiceId ?>"
               class="btn btn-success btn-sm">
                <?= heroicon('banknotes', 'icon-sm') ?>
                Record Payment
            </a>
        <?php endif; ?>

        <?php if ($invoice['lease_id']): ?>
            <a href="<?= base_url('leases/show') ?>?id=<?= (int)$invoice['lease_id'] ?>" class="btn btn-secondary btn-sm">
                View Lease
            </a>
        <?php endif; ?>

        <?php /* S-INVOICE-DRAFT-EDIT: hand-edit a draft's line items (totals recomputed
                 server-side). Draft-only + non-advance — sent invoices are immutable. */ ?>
        <?php if ($invoice['status'] === 'draft' && ($invoice['generation_source'] ?? '') !== 'advance' && can('invoices', 'edit')): ?>
            <a href="<?= base_url('invoices/edit') ?>?id=<?= $invoiceId ?>" class="btn btn-secondary btn-sm">Edit Line Items</a>
        <?php endif; ?>
        <?php /* S-INVOICE-DRAFT-EDIT: rebuild a draft from current lease state (regular, lease-linked, non-advance). */ ?>
        <?php if ($invoice['status'] === 'draft' && ($invoice['generation_source'] ?? '') !== 'advance'
                  && ($invoice['invoice_type'] ?? 'regular') === 'regular' && $invoice['lease_id'] && can('invoices', 'edit')): ?>
            <button class="btn btn-secondary btn-sm"
                    @click="regenerateFromLease(<?= $invoiceId ?>, <?= e(json_encode($invoice['updated_at'])) ?>)"
                    :disabled="regenerating">
                <span x-show="!regenerating">Regenerate from Lease</span>
                <span x-show="regenerating">Regenerating…</span>
            </button>
        <?php endif; ?>

        <?php if (in_array($invoice['status'], ['draft', 'sent']) && can('invoices', 'edit')): ?>
            <button class="btn btn-danger btn-sm" @click="showVoidModal = true">Void</button>
        <?php endif; ?>
        <?php if ($canDelete): ?>
            <button class="btn btn-danger btn-sm" @click="showDeleteModal = true">Delete</button>
        <?php endif; ?>
    </div>
</div><!-- end .page-header -->

<!-- ============================================================
     MODALS — Void, Delete
     ============================================================ -->

    <!-- Void modal -->
    <template x-if="showVoidModal">
        <div class="modal-overlay" @click.self="showVoidModal = false">
            <div class="modal modal-sm">
                <div class="modal-header">
                    <h3 class="modal-title">Void Invoice</h3>
                    <button class="modal-close-btn" aria-label="Close" @click="showVoidModal = false">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="text-sm text-secondary" style="margin-bottom:12px;">
                        This will permanently void invoice <strong><?= e($invoice['invoice_number']) ?></strong>.
                        Voided invoices cannot be edited or sent.
                    </p>
                    <label class="form-label">Reason for voiding <span class="text-danger">*</span></label>
                    <textarea class="form-control" x-model="voidReason" rows="3"
                              placeholder="Why is this invoice being voided?"></textarea>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary btn-sm" @click="showVoidModal = false">Cancel</button>
                    <button class="btn btn-danger btn-sm" @click="voidInvoice()" :disabled="voiding || !voidReason.trim()">
                        <span x-show="!voiding">Void Invoice</span>
                        <span x-show="voiding">Voiding…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <!-- Delete confirmation modal -->
    <template x-if="showDeleteModal">
        <div class="modal-overlay" @click.self="showDeleteModal = false">
            <div class="modal modal-sm">
                <div class="modal-header">
                    <h3 class="modal-title">Delete Invoice</h3>
                    <button class="modal-close-btn" aria-label="Close" @click="showDeleteModal = false">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete invoice <strong><?= e($invoice['invoice_number']) ?></strong>
                       (status: <strong><?= e($invoice['status']) ?></strong>)?</p>
                    <p class="text-sm text-danger" style="margin-top:8px;">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary btn-sm" @click="showDeleteModal = false">Cancel</button>
                    <button class="btn btn-danger btn-sm" @click="deleteInvoice()" :disabled="deleting">
                        <span x-show="!deleting">Delete Invoice</span>
                        <span x-show="deleting">Deleting…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <!-- Toast -->
    <div x-show="toast" x-transition class="toast" :class="'toast-' + toastType"
         style="position:fixed; top:16px; right:16px; z-index:9999;"
         x-text="toast"></div>


<!-- ================================================================
     STATUS TIMELINE — Visual invoice lifecycle
     ================================================================ -->
<?php
// WHY: Determine which steps are completed/active for visual timeline
$steps = [
    ['key' => 'draft',     'label' => 'Draft',     'date' => $invoice['created_at']],
    ['key' => 'sent',      'label' => 'Sent',      'date' => $invoice['sent_date']],
    ['key' => 'paid',      'label' => 'Paid',      'date' => $invoice['paid_date']],
];

$statusOrder = ['draft' => 0, 'sent' => 1, 'partially_paid' => 2, 'overdue' => 2, 'paid' => 3];
$currentIdx = $statusOrder[$invoice['status']] ?? 0;
?>
<div class="invoice-status-timeline no-print">
    <?php foreach ($steps as $i => $step): ?>
        <?php if ($i > 0): ?>
            <div class="timeline-connector <?= ($i <= $currentIdx && !$isVoid && !$isWrittenOff) ? 'completed' : '' ?>"></div>
        <?php endif; ?>
        <?php
        $stepClass = '';
        if ($isVoid || $isWrittenOff) {
            // WHY: If void/written-off, show completed steps up to where it stopped
            if ($step['date']) $stepClass = 'completed';
        } elseif ($i < $currentIdx) {
            $stepClass = 'completed';
        } elseif ($i === $currentIdx) {
            $stepClass = 'active';
        }
        ?>
        <div class="timeline-step <?= $stepClass ?>">
            <div class="timeline-dot"></div>
            <div>
                <div><?= e($step['label']) ?></div>
                <?php if ($step['date']): ?>
                    <div class="text-sm" style="font-size:11px; opacity:0.7;"><?= format_date($step['date']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($isVoid): ?>
        <div class="timeline-connector"></div>
        <div class="timeline-step void-step active">
            <div class="timeline-dot"></div>
            <div>
                <div>Voided</div>
                <?php if ($invoice['voided_date']): ?>
                    <div class="text-sm" style="font-size:11px; opacity:0.7;"><?= format_date($invoice['voided_date']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($isWrittenOff): ?>
        <div class="timeline-connector"></div>
        <div class="timeline-step void-step active">
            <div class="timeline-dot"></div>
            <div>
                <div>Written Off</div>
                <?php if ($invoice['written_off_at']): ?>
                    <div class="text-sm" style="font-size:11px; opacity:0.7;"><?= format_date($invoice['written_off_at']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>


<!-- ================================================================
     KPI STAT CARDS
     ================================================================ -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Invoice Date</div>
        <div class="stat-value font-mono"><?= format_date($invoice['invoice_date']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Due Date</div>
        <div class="stat-value font-mono" <?php if ($isOverdue): ?>style="color:var(--color-danger);"<?php endif; ?>>
            <?= format_date($invoice['due_date']) ?>
            <?php if ($isOverdue): ?>
                <?php
                $daysOverdue = (int)((strtotime('today') - strtotime($invoice['due_date'])) / 86400);
                ?>
                <div class="text-sm" style="font-size:11px; color:var(--color-danger); margin-top:2px;">
                    <?= $daysOverdue ?> day<?= $daysOverdue !== 1 ? 's' : '' ?> overdue
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Amount</div>
        <div class="stat-value font-mono"><?= format_currency($invoice['total_amount']) ?></div>
        <?php if ($invoice['currency'] !== 'CAD' && !empty($invoice['exchange_rate_to_cad'])): ?>
            <?php
                // CURRENCY-MARKUP-1: use effective rate (bank + markup) for CAD display
                $_tileMarkup = (string)($invoice['currency_markup_pct'] ?? '0.0000');
                if (bccomp($_tileMarkup, '0', 4) > 0) {
                    $_tileFactor = bcadd('1', bcdiv($_tileMarkup, '100', 10), 10);
                    $_tileEffRate = bcmul((string)$invoice['exchange_rate_to_cad'], $_tileFactor, 6);
                } else {
                    $_tileEffRate = (string)$invoice['exchange_rate_to_cad'];
                }
            ?>
            <div class="text-sm text-secondary" style="margin-top:2px; font-size:11px;">
                ≈ <?= format_currency(bcmul($invoice['total_amount'], $_tileEffRate, 2)) ?> CAD
            </div>
        <?php endif; ?>
    </div>
    <!-- TILES-2: Amount Paid drills to payments scoped to this invoice.
         Invoice Date / Due Date / Total Amount stay as display-only info. -->
    <a class="stat-card"
       href="<?= base_url('payments') ?>?invoice_id=<?= (int)$invoice['id'] ?>"
       style="cursor:pointer;text-decoration:none"
       title="View payments applied to this invoice">
        <div class="stat-label">Amount Paid</div>
        <div class="stat-value font-mono" style="<?= bccomp($invoice['amount_paid'], '0', 2) > 0 ? 'color:var(--color-success);' : '' ?>">
            <?= format_currency($invoice['amount_paid']) ?>
        </div>
    </a>

    <!-- TILES-2: Balance Due jumps to the Record Payment form pre-loaded
         with this invoice when there's still an outstanding balance.
         When fully paid the tile stays as display-only (nothing to pay). -->
    <?php if (bccomp($invoice['balance_due'], '0', 2) > 0 && can('payments', 'create')): ?>
    <a class="stat-card"
       href="<?= base_url('payments/create') ?>?invoice_id=<?= (int)$invoice['id'] ?>"
       style="cursor:pointer;text-decoration:none"
       title="Record a payment against this invoice">
        <div class="stat-label">Balance Due</div>
        <div class="stat-value font-mono" style="color:var(--color-danger);">
            <?= format_currency($invoice['balance_due']) ?>
        </div>
    </a>
    <?php else: ?>
    <div class="stat-card">
        <div class="stat-label">Balance Due</div>
        <div class="stat-value font-mono">
            <?= format_currency($invoice['balance_due']) ?>
        </div>
        <?php if ($isPaid): ?>
            <div class="text-sm" style="color:var(--color-success); font-size:11px; margin-top:2px;">
                ✓ Paid in full<?php if ($invoice['paid_date']): ?> · <?= format_date($invoice['paid_date']) ?><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>


</div>


<!-- ================================================================
     QUICKBOOKS SYNC PANEL (S-QBO-INVOICE-SHOW-RICH-PANEL)
     Maximalist inline replacement for the retired S-QBO-11 header
     badge. Surfaces 6-state badge + QBO IDs + deep-link + last-pushed
     timestamp + currency + sync token + last-20 push history +
     Retry/View-in-QBO actions. Hidden in print (operator-facing only).
     Conditional on quickbooks.connection_status='connected'; renders
     nothing when QBO is disconnected.
     ================================================================ -->
<?php if ($qboConnected):
    // ── State classification ──────────────────────────────────────
    // 6-state vocabulary mapped from the canonical push_status ENUM
    // (FLEETFORGE_DATABASE_MASTER.sql line 1112). Synced = pushed
    // (single ENUM value, not the prompt's hypothetical
    // success_created/success_updated/already_mapped). When no
    // mapping row exists, the panel still renders in "Not Synced"
    // state — addressing the operator complaint that the prior
    // header badge "disappeared" for unmapped invoices.
    $rp_status = $qboInvoiceMapping['push_status'] ?? null;
    $rp_qbo_id = $qboInvoiceMapping['qbo_invoice_id'] ?? null;

    if ($qboInvoiceMapping === null) {
        $rp_state       = 'not_synced';
        $rp_badge_class = 'badge-neutral';
        $rp_state_icon  = '○';
        $rp_state_label = 'Not Synced';
    } elseif ($rp_status === 'pushed') {
        $rp_state       = 'synced';
        $rp_badge_class = 'badge-success';
        $rp_state_icon  = '✓';
        $rp_state_label = 'Synced';
    } elseif ($rp_status === 'pending') {
        $rp_state       = 'pending';
        $rp_badge_class = 'badge-neutral';
        $rp_state_icon  = '⋯';
        $rp_state_label = 'Pending';
    } elseif ($rp_status === 'failed') {
        $rp_state       = 'failed';
        $rp_badge_class = 'badge-danger';
        $rp_state_icon  = '✗';
        $rp_state_label = 'Failed';
    } elseif (str_starts_with((string)$rp_status, 'failed_preflight')) {
        $rp_state       = 'failed_preflight';
        $rp_badge_class = 'badge-warning';
        $rp_state_icon  = '⚠';
        $rp_state_label = 'Failed Pre-flight';
    } elseif (str_starts_with((string)$rp_status, 'skipped_')) {
        $rp_state       = 'skipped';
        $rp_badge_class = 'badge-neutral';
        $rp_state_icon  = '–';
        $rp_state_label = 'Skipped';
    } else {
        $rp_state       = 'unknown';
        $rp_badge_class = 'badge-neutral';
        $rp_state_icon  = '○';
        $rp_state_label = ucfirst(str_replace('_', ' ', (string)$rp_status));
    }

    // Retryable iff edit perm held AND push_status is in the
    // retryable set — matches retry.php line 49's whitelist.
    $rp_retryable_statuses = ['failed', 'failed_preflight', 'failed_preflight_field_too_long', 'failed_preflight_currency_mismatch'];
    $rp_show_retry         = $qboCanEdit && $qboInvoiceMapping !== null
                          && in_array($rp_status, $rp_retryable_statuses, true);

    // QBO web app deep-link — host swap on sandbox/production.
    $rp_qbo_host    = $qboEnvironment === 'production' ? 'app.qbo.intuit.com' : 'app.sandbox.qbo.intuit.com';
    $rp_qbo_url     = $rp_qbo_id ? "https://{$rp_qbo_host}/app/invoice?txnId=" . urlencode((string)$rp_qbo_id) : null;

    // Inline time-ago helper — no canonical helper exists in lib/.
    // Returns NULL for NULL/empty input so the caller can skip rendering.
    $rp_time_ago = static function (?string $ts): ?string {
        if (!$ts) return null;
        $t = strtotime($ts);
        if ($t === false) return null;
        $diff = time() - $t;
        if ($diff < 60)      return $diff <= 1 ? 'just now' : $diff . ' seconds ago';
        if ($diff < 3600)    return floor($diff / 60) . ' min ago';
        if ($diff < 86400)   return floor($diff / 3600) . ' hr ago';
        if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
        return date('Y-m-d', $t);
    };
    $rp_pushed_rel = $rp_time_ago($qboInvoiceMapping['pushed_at'] ?? null);
?>
<div class="card ff-print-hide" id="qbo-sync-panel" style="margin-bottom:24px;">

    <!-- Panel header: state badge + identifiers row -->
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:10px;">
            <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); margin:0;">QuickBooks Sync</h3>
            <span class="badge badge-no-dot <?= $rp_badge_class ?>"
                  style="font-size:12px; padding:3px 10px;"
                  title="<?= e($rp_status ?? 'no mapping row') ?>">
                <span style="font-family:'DM Mono',monospace;"><?= e($rp_state_icon) ?></span>
                <?= e($rp_state_label) ?>
            </span>
        </div>

        <!-- Identifiers row: QBO ID + last-pushed + currency + sync token -->
        <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap; font-size:13px; margin-left:auto;">
            <?php if ($rp_qbo_id): ?>
                <div>
                    <span class="text-secondary">QBO</span>
                    <a href="<?= e($rp_qbo_url) ?>" target="_blank" rel="noopener noreferrer"
                       class="font-mono link" title="Open in QuickBooks">#<?= e($rp_qbo_id) ?> ↗</a>
                </div>
            <?php endif; ?>
            <?php if ($qboInvoiceMapping && !empty($qboInvoiceMapping['pushed_at'])): ?>
                <div title="<?= e((string)$qboInvoiceMapping['pushed_at']) ?>">
                    <span class="text-secondary">Pushed</span>
                    <span class="font-mono"><?= e($rp_pushed_rel ?? (string)$qboInvoiceMapping['pushed_at']) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($qboInvoiceMapping && !empty($qboInvoiceMapping['qbo_currency'])): ?>
                <div>
                    <span class="text-secondary">Currency</span>
                    <span class="font-mono"><?= e($qboInvoiceMapping['qbo_currency']) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($qboInvoiceMapping && $qboInvoiceMapping['qbo_sync_token'] !== null && $qboInvoiceMapping['qbo_sync_token'] !== ''): ?>
                <div class="text-secondary text-sm" title="QBO optimistic-lock token — used to detect divergent updates.">
                    Token: <span class="font-mono"><?= e($qboInvoiceMapping['qbo_sync_token']) ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Push history table -->
    <div style="padding:14px 20px;">
        <h4 style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); margin:0 0 8px 0;">
            Push History
        </h4>
        <?php if (empty($qboSyncLogHistory)): ?>
            <p class="text-secondary text-sm" style="margin:0;">No push attempts yet.</p>
        <?php else: ?>
            <table class="table" style="margin:0; font-size:12px;">
                <thead>
                    <tr>
                        <th style="font-size:10px;">Timestamp</th>
                        <th style="font-size:10px;">Operation</th>
                        <th style="font-size:10px;">Status</th>
                        <th style="font-size:10px;">Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($qboSyncLogHistory as $lr): ?>
                        <?php
                        // Status row color: HTTP 2xx → success, 4xx/5xx → danger,
                        // null → neutral (queued but not yet dispatched).
                        $lr_status = (int)($lr['response_status'] ?? 0);
                        if ($lr_status >= 200 && $lr_status < 300) {
                            $lr_badge = 'badge-success';
                            $lr_icon  = '✓';
                        } elseif ($lr_status >= 400) {
                            $lr_badge = 'badge-danger';
                            $lr_icon  = '✗';
                        } else {
                            $lr_badge = 'badge-neutral';
                            $lr_icon  = '⋯';
                        }
                        ?>
                        <tr>
                            <td class="font-mono text-sm" style="white-space:nowrap;" title="<?= e((string)$lr['created_at']) ?>">
                                <?= e($rp_time_ago($lr['created_at']) ?? (string)$lr['created_at']) ?>
                            </td>
                            <td class="text-sm"><?= e(($lr['http_method'] ?? '') . ' ' . ($lr['operation'] ?? '')) ?></td>
                            <td>
                                <span class="badge badge-no-dot <?= $lr_badge ?>" style="font-size:10px;">
                                    <?= e($lr_icon) ?> <?= e($lr_status > 0 ? (string)$lr_status : 'queued') ?>
                                </span>
                            </td>
                            <td class="text-sm text-secondary" style="max-width:340px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                                title="<?= e((string)($lr['error_message'] ?? '')) ?>">
                                <?php if (!empty($lr['error_code'])): ?>
                                    <span class="font-mono text-danger"><?= e((string)$lr['error_code']) ?></span>
                                <?php endif; ?>
                                <?= e((string)($lr['error_message'] ?? '')) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($qboSyncLogTotalCount > count($qboSyncLogHistory)): ?>
                <p class="text-secondary text-sm" style="margin:8px 0 0 0; font-size:11px;">
                    +<?= (int)($qboSyncLogTotalCount - count($qboSyncLogHistory)) ?> more attempts (showing most recent 20)
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Actions row -->
    <?php if ($rp_show_retry || $rp_qbo_url): ?>
    <div style="padding:12px 20px; border-top:1px solid var(--border-color); display:flex; gap:8px; align-items:center; flex-wrap:wrap;"
         x-data="qboSyncPanel(<?= (int)($qboInvoiceMapping['id'] ?? 0) ?>)">
        <?php if ($rp_show_retry): ?>
            <button type="button" class="btn btn-secondary btn-sm"
                    @click="retry()" :disabled="retrying">
                <span x-show="!retrying">Retry Push</span>
                <span x-show="retrying" x-cloak>Retrying…</span>
            </button>
        <?php endif; ?>
        <?php if ($rp_qbo_url): ?>
            <a href="<?= e($rp_qbo_url) ?>" target="_blank" rel="noopener noreferrer"
               class="btn btn-secondary btn-sm">
                View in QBO ↗
            </a>
        <?php endif; ?>
        <span x-show="flash" x-cloak x-text="flash" class="text-sm" :class="flashType === 'success' ? 'text-success' : 'text-danger'"></span>
    </div>
    <?php endif; ?>

</div>
<?php endif; /* $qboConnected */ ?>


<!-- ================================================================
     FROM / TO ADDRESSES + INVOICE METADATA
     ================================================================ -->
<div class="invoice-addresses">

    <!-- Left: Customer Billing Info (the "Bill To" section) -->
    <div class="card" style="padding:20px;">
        <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); margin:0 0 12px 0;">Bill To</h3>
        <div style="font-size:14px; line-height:1.7;">
            <?php if ($invoice['company_name_snapshot']): ?>
                <div style="font-weight:600; font-size:15px;"><?= e($invoice['company_name_snapshot']) ?></div>
            <?php endif; ?>
            <?php if ($invoice['customer_name_snapshot'] && $invoice['customer_name_snapshot'] !== $invoice['company_name_snapshot']): ?>
                <div><?= e($invoice['customer_name_snapshot']) ?></div>
            <?php endif; ?>
            <?php if ($invoice['billing_address_snapshot']): ?>
                <div style="margin-top:6px; color:var(--text-secondary);">
                    <?= nl2br(e($invoice['billing_address_snapshot'])) ?>
                </div>
            <?php endif; ?>
            <?php if ($invoice['customer_email_snapshot']): ?>
                <div style="margin-top:6px;">
                    <span class="text-secondary">Email:</span>
                    <a href="mailto:<?= e($invoice['customer_email_snapshot']) ?>" class="link"><?= e($invoice['customer_email_snapshot']) ?></a>
                </div>
            <?php endif; ?>
        </div>
        <!-- Tax exemptions -->
        <?php if ($invoice['gst_exempt_snapshot'] || $invoice['pst_exempt_snapshot'] || $invoice['tax_exempt_snapshot']): ?>
        <div style="margin-top:12px; padding-top:10px; border-top:1px solid var(--border-color);">
            <?php if ($invoice['tax_exempt_snapshot']): ?>
                <span class="badge badge-no-dot badge-warning" style="font-size:11px;">Fully Tax Exempt</span>
            <?php else: ?>
                <?php if ($invoice['gst_exempt_snapshot']): ?>
                    <span class="badge badge-no-dot badge-warning" style="font-size:11px;">GST Exempt</span>
                    <?php if (!empty($invoice['gst_exempt_number_snapshot'])): ?>
                        <span class="text-secondary" style="font-size:11px; margin-left:4px;">#<?= e($invoice['gst_exempt_number_snapshot']) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($invoice['pst_exempt_snapshot']): ?>
                    <span class="badge badge-no-dot badge-warning" style="font-size:11px; margin-left:4px;">PST Exempt</span>
                    <?php if (!empty($invoice['pst_exempt_number_snapshot'])): ?>
                        <span class="text-secondary" style="font-size:11px; margin-left:4px;">#<?= e($invoice['pst_exempt_number_snapshot']) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: Invoice Metadata -->
    <div class="card" style="padding:20px;">
        <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); margin:0 0 12px 0;">Invoice Details</h3>
        <dl class="invoice-meta-dl" style="display:grid; grid-template-columns:140px 1fr; gap:6px 16px; font-size:13px; margin:0;">
            <dt class="text-secondary">Billing Period</dt>
            <dd class="font-mono"><?= format_date($invoice['billing_period_start']) ?> → <?= format_date(ff_invoice_display_period_end($invoice)) ?></dd>

            <dt class="text-secondary">Billing Days</dt>
            <dd><?= (int)$invoice['billing_period_days'] ?> days
                <span class="badge badge-no-dot badge-neutral" style="font-size:10px; margin-left:4px;"><?= e($invoice['billing_type']) ?></span>
            </dd>

            <dt class="text-secondary">Rate Method</dt>
            <dd><?= e(ucfirst($invoice['rate_method_used'] ?? '—')) ?></dd>

            <dt class="text-secondary">Currency</dt>
            <dd>
                <?= e($invoice['currency']) ?>
                <?php if ($invoice['currency'] !== 'CAD' && !empty($invoice['exchange_rate_to_cad'])): ?>
                    <span class="text-secondary text-sm">(1 <?= e($invoice['currency']) ?> = <?= e(rtrim(rtrim(number_format((float)$invoice['exchange_rate_to_cad'], 6), '0'), '.')) ?> CAD)</span>
                <?php endif; ?>
            </dd>

            <?php if ($invoice['po_number']): ?>
            <dt class="text-secondary">PO Number</dt>
            <dd class="font-mono"><?= e($invoice['po_number']) ?></dd>
            <?php endif; ?>

            <?php if ($invoice['auto_generated']): ?>
            <dt class="text-secondary">Source</dt>
            <dd>
                <span class="badge badge-no-dot badge-neutral" style="font-size:11px;">Auto-generated</span>
                <?= e($invoice['generation_source'] ?? '') ?>
                <?php if ($invoice['auto_generated_at']): ?>
                    <span class="text-sm text-secondary">· <?= format_datetime($invoice['auto_generated_at']) ?></span>
                <?php endif; ?>
            </dd>
            <?php endif; ?>

            <dt class="text-secondary">Created</dt>
            <dd>
                <?= format_datetime($invoice['created_at']) ?>
                <?php if (isset($userNames[(int)($invoice['created_by'] ?? 0)])): ?>
                    <span class="text-secondary">by <?= e($userNames[(int)$invoice['created_by']]) ?></span>
                <?php endif; ?>
            </dd>
        </dl>
    </div>
</div>


<!-- ================================================================
     SAMSARA-3: ODOMETER & DISTANCE
     Shows per-period and cumulative km driven when the invoice was
     created with odometer data. Silent when no odometer captured.
     ================================================================ -->
<?php
// S-MILEAGE-2B C6 (D-K): Odometer & Distance card rewrite — Model B aware.
// Shows period distance + computed period charge + (when precharge present)
// pre/post balance + drawdown applied. Also surfaces Samsara advisory
// warnings from the audit_log entity_type='samsara_history_query' row if
// the C3 D-C fallback fetch ran for this invoice's lease+period.
$hasOdometer = ($invoice['odometer_at_period_start_km'] ?? null) !== null
            || ($invoice['odometer_at_period_end_km']   ?? null) !== null
            || ($invoice['period_distance_km']           ?? null) !== null;

// Drawdown lookup: did C3's drawdown emit fire for THIS invoice?
// audit_log row entity_type='lease_precharge_balance_drawdown' carries:
//   old_values.precharge_balance = pre-emission balance
//   new_values.precharge_balance = post-emission balance
//   new_values.drawdown_amount    = amount applied
$drawdownAudit = null;
if (!empty($invoice['lease_id'])) {
    $drawdownAudit = db_row(
        "SELECT old_values, new_values FROM audit_log
          WHERE entity_type = 'lease_precharge_balance_drawdown'
            AND JSON_EXTRACT(new_values, '$.invoice_id') = ?
          ORDER BY created_at DESC LIMIT 1",
        [(int) $invoice['id']]
    );
}
$drawdownMeta = null;
if ($drawdownAudit && !empty($drawdownAudit['new_values'])) {
    $new = json_decode((string) $drawdownAudit['new_values'], true);
    $old = json_decode((string) ($drawdownAudit['old_values'] ?? '{}'), true);
    $drawdownMeta = [
        'pre_balance'     => (string) ($old['precharge_balance'] ?? '0'),
        'post_balance'    => (string) ($new['precharge_balance'] ?? '0'),
        'drawdown_amount' => (string) ($new['drawdown_amount']   ?? '0'),
        'period_charge'   => (string) ($new['period_charge']     ?? '0'),
    ];
}

// Samsara advisory warnings: did C3's D-C fallback fetch run for this
// invoice's lease+period? Pull the latest matching audit_log row.
$samsaraWarnings = [];
if (!empty($invoice['lease_id']) && ($invoice['odometer_source'] ?? null) === 'gps') {
    $samsaraAudit = db_row(
        "SELECT new_values FROM audit_log
          WHERE entity_type = 'samsara_history_query'
            AND entity_id = ?
            AND created_at BETWEEN ? AND ?
          ORDER BY created_at DESC LIMIT 1",
        [
            (int) $invoice['lease_id'],
            $invoice['billing_period_start'] . ' 00:00:00',
            date('Y-m-d H:i:s', strtotime($invoice['billing_period_end'] . ' +2 days')),
        ]
    );
    if ($samsaraAudit && !empty($samsaraAudit['new_values'])) {
        $samsaraData = json_decode((string) $samsaraAudit['new_values'], true);
        $samsaraWarnings = (array) ($samsaraData['warnings'] ?? []);
    }
}

if ($hasOdometer):
    // Badge class for source
    $odoSourceBadge = match ($invoice['odometer_source'] ?? 'manual') {
        'gps'       => ['badge-info',    'GPS'],
        'estimated' => ['badge-warning', 'Estimated'],
        default     => ['badge-neutral', 'Manual'],
    };
    // Read mileage_rate_km from frozen line-item detail_lines rather than the live
    // lease — if the lease rate changes post-invoice, the display stays accurate.
    $frozenMileageRateKm = null;
    foreach ($lineItems as $li) {
        if (!empty($li['_detail']['mileage_rate_km'])) {
            $frozenMileageRateKm = $li['_detail']['mileage_rate_km'];
            break;
        }
    }
    // Fetch only immutable fields (start_date, odometer_start_km) from the lease.
    // mileage_rate_km is intentionally excluded — use $frozenMileageRateKm instead.
    $leaseOdoContext = $invoice['lease_id']
        ? db_row("SELECT start_date, odometer_start_km, mileage_unit, km_to_miles_conversion, miles_to_km_conversion FROM leases WHERE id = ? AND deleted_at IS NULL", [$invoice['lease_id']])
        : null;
    // S-MILEAGE-RATE-CONVERT-FIX: render this usage panel in the lease's unit so it
    // doesn't contradict the (now unit-correct) mileage line below. $mDispLease is a
    // lease-shaped array for the ff_mileage_* helpers; the per-unit rate is the frozen
    // per-km rate scaled to miles when needed.
    $mDispLease   = $leaseOdoContext ?: ['mileage_unit' => 'km'];
    $mDispUnit    = ff_mileage_unit_label($mDispLease);
    $mDispRate    = ($mDispUnit === 'miles' && $frozenMileageRateKm !== null)
        ? bcround(bcmul((string) $frozenMileageRateKm, (string) ($leaseOdoContext['miles_to_km_conversion'] ?? '1.609344'), 8), 4)
        : $frozenMileageRateKm;

    // Compute period charge for display: distance × rate (per Model B)
    $periodCharge = null;
    if (($invoice['period_distance_km'] ?? null) !== null
        && !empty($frozenMileageRateKm)
        && bccomp((string) $frozenMileageRateKm, '0', 4) > 0
    ) {
        $periodCharge = bcround(bcmul(
            (string) $invoice['period_distance_km'],
            (string) $frozenMileageRateKm,
            6
        ), 2);
    }
?>
<!-- WHY: Odometer detail is internal telematics context; hidden in print -->
<div class="card ff-print-hide" style="padding:20px; margin-bottom:20px;">
    <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); margin:0 0 12px 0;">
        Odometer &amp; Distance
    </h3>
    <dl class="invoice-meta-dl" style="display:grid; grid-template-columns:200px 1fr; gap:8px 16px; font-size:13px; margin:0;">
        <?php if ($invoice['odometer_at_period_start_km'] !== null): ?>
        <dt class="text-secondary">Period Start</dt>
        <dd class="font-mono"><?= number_format((float) ff_km_to_lease_unit($mDispLease, $invoice['odometer_at_period_start_km']), 2) ?> <?= e($mDispUnit) ?></dd>
        <?php endif; ?>

        <?php if ($invoice['odometer_at_period_end_km'] !== null): ?>
        <dt class="text-secondary">Period End</dt>
        <dd class="font-mono"><?= number_format((float) ff_km_to_lease_unit($mDispLease, $invoice['odometer_at_period_end_km']), 2) ?> <?= e($mDispUnit) ?></dd>
        <?php endif; ?>

        <?php if ($invoice['period_distance_km'] !== null): ?>
        <dt class="text-secondary">Period Distance</dt>
        <dd>
            <span class="font-mono" style="font-weight:600;"><?= number_format((float) ff_km_to_lease_unit($mDispLease, $invoice['period_distance_km']), 2) ?> <?= e($mDispUnit) ?></span>
            <span class="badge badge-no-dot <?= $odoSourceBadge[0] ?>" style="font-size:10px; margin-left:6px;"><?= $odoSourceBadge[1] ?></span>
        </dd>
        <?php endif; ?>

        <?php if ($periodCharge !== null): ?>
        <dt class="text-secondary">Period Charge</dt>
        <dd class="font-mono">
            $<?= number_format((float) $periodCharge, 2) ?>
            <span class="text-secondary text-sm">(<?= number_format((float) ff_km_to_lease_unit($mDispLease, $invoice['period_distance_km']), 2) ?> <?= e($mDispUnit) ?> × $<?= e($mDispRate) ?>/<?= e(ff_mileage_unit_label($mDispLease, true)) ?>)</span>
        </dd>
        <?php endif; ?>

        <?php if ($invoice['cumulative_distance_km'] !== null): ?>
        <dt class="text-secondary">Lease-to-Date Distance</dt>
        <dd class="font-mono">
            <?= number_format((float) ff_km_to_lease_unit($mDispLease, $invoice['cumulative_distance_km']), 2) ?> <?= e($mDispUnit) ?>
            <?php if ($leaseOdoContext && $leaseOdoContext['start_date']): ?>
            <span class="text-secondary text-sm" style="margin-left:4px;">since lease start on <?= format_date($leaseOdoContext['start_date']) ?></span>
            <?php endif; ?>
        </dd>
        <?php endif; ?>

        <dt class="text-secondary">Source</dt>
        <dd>
            <?php if (($invoice['odometer_source'] ?? null) === 'gps'): ?>
                GPS via Samsara
                <?php if (!empty($invoice['odometer_fetched_at'])): ?>
                <span class="text-secondary text-sm">· fetched <?= format_datetime($invoice['odometer_fetched_at']) ?></span>
                <?php endif; ?>
            <?php elseif (($invoice['odometer_source'] ?? null) === 'estimated'): ?>
                Estimated
            <?php else: ?>
                Manually entered
            <?php endif; ?>
        </dd>
    </dl>

    <?php if (!empty($samsaraWarnings)): ?>
    <!-- Samsara advisory warnings per D121 — yellow info banner. -->
    <div style="margin-top:12px; padding:10px 12px; background:#fef3c7; border:1px solid #fcd34d; border-radius:6px; font-size:12px;">
        <strong style="color:#92400e;">Samsara advisory warnings:</strong>
        <ul style="margin:6px 0 0 18px; color:#78350f;">
            <?php foreach ($samsaraWarnings as $w): ?>
            <li><code><?= e((string) $w) ?></code></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>


<!-- ================================================================
     S-MILEAGE-2B C6 (D-L): DRAWDOWN RECONCILIATION PANEL
     Replaces the Model C Mileage Review card retired in C5. Renders
     when this invoice carries a mileage_usage line (Model B drawdown
     emit fired in C3) — shows the precharge balance pre/post + drawdown
     applied, sourced from the audit_log lease_precharge_balance_drawdown
     row written by C3 at emit time.

     Hidden in print (ff-print-hide) per K-13 — internal audit context.
     ================================================================ -->
<?php
$usageLine = !empty($invoice['lease_id'])
    ? db_row(
        "SELECT id, amount, mileage_distance, mileage_rate, mileage_unit
           FROM invoice_line_items
          WHERE invoice_id = ? AND item_type = 'mileage_usage' LIMIT 1",
        [(int) $invoice['id']]
    )
    : null;
if ($usageLine):
?>
<div class="card ff-print-hide" id="drawdown-reconciliation-card"
     style="padding:20px; margin-bottom:20px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); margin:0;">
            Drawdown Reconciliation
        </h3>
        <span class="badge badge-info" style="font-size:11px;">Model B</span>
    </div>

    <dl class="invoice-meta-dl" style="display:grid; grid-template-columns:200px 1fr; gap:6px 16px; font-size:13px; margin:0;">
        <dt class="text-secondary">Period Distance</dt>
        <dd class="font-mono"><?= number_format((float) $usageLine['mileage_distance'], 2) ?> <?= e($usageLine['mileage_unit'] ?? 'km') ?></dd>

        <dt class="text-secondary">Period Charge</dt>
        <dd class="font-mono">$<?= number_format((float) $usageLine['amount'], 2) ?></dd>

        <?php if ($drawdownMeta !== null): ?>
        <dt class="text-secondary">Precharge Balance Pre</dt>
        <dd class="font-mono">$<?= number_format((float) $drawdownMeta['pre_balance'], 2) ?></dd>

        <dt class="text-secondary">Drawdown Applied</dt>
        <dd class="font-mono" style="color:#16a34a; font-weight:700;">−$<?= number_format((float) $drawdownMeta['drawdown_amount'], 2) ?></dd>

        <dt class="text-secondary">Precharge Balance Post</dt>
        <dd class="font-mono"><strong>$<?= number_format((float) $drawdownMeta['post_balance'], 2) ?></strong></dd>
        <?php else: ?>
        <dt class="text-secondary">Drawdown Applied</dt>
        <dd class="text-secondary text-sm" style="font-style:italic;">No precharge balance at invoice generation (Model B Lite — bill every km).</dd>
        <?php endif; ?>
    </dl>
</div>
<?php endif; ?>



<!-- ================================================================
     RATE METHOD EXPLANATION — How the rental charge was calculated
     ================================================================ -->
<?php
// WHY: rate_method_explanation can be either {amount, method, explanation:[...]} or just [string, string, ...]
$rateLines = [];
$rateMethodLabel = $invoice['rate_method_used'] ?? '';
if ($rateExplanation) {
    if (isset($rateExplanation['explanation']) && is_array($rateExplanation['explanation'])) {
        $rateLines = $rateExplanation['explanation'];
        $rateMethodLabel = $rateExplanation['method'] ?? $rateMethodLabel;
    } elseif (is_array($rateExplanation) && !isset($rateExplanation['explanation'])) {
        // Flat array of explanation strings
        $rateLines = $rateExplanation;
    }
}
?>
<?php if (!empty($rateLines)): ?>
<!-- WHY: Rate calc breakdown is an internal audit trail; hidden in print -->
<div class="card ff-print-hide" style="margin-bottom:24px; padding:16px 20px;">
    <div style="display:flex; align-items:center; gap:8px; cursor:pointer;"
         onclick="this.parentElement.querySelector('.rate-explanation-body').classList.toggle('hidden')">
        <?= heroicon('calculator', 'icon-sm') ?>
        <h3 style="font-size:13px; font-weight:600; margin:0;">Rate Calculation Breakdown</h3>
        <span class="badge badge-no-dot badge-neutral" style="font-size:11px;"><?= e(ucfirst($rateMethodLabel)) ?> method</span>
        <span class="text-sm text-secondary" style="margin-left:auto;">click to toggle</span>
    </div>
    <div class="rate-explanation-body" style="margin-top:12px;">
        <ul style="list-style:none; padding:0; margin:0;">
            <?php foreach ($rateLines as $line): ?>
                <li style="padding:3px 0; font-size:13px; color:var(--text-secondary); font-family:'DM Mono',monospace;">
                    <?= e(is_string($line) ? $line : json_encode($line)) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>


<?php
/* ─── S-INVOICE-DISPLAY-COMPREHENSIVE D-A: $0 line item display policy ───
 * Strict by status: drafts hide $0 lines (operator workflow — drafts in
 * progress shouldn't show noise from lines that resolved to $0). Sent /
 * partially_paid / paid / overdue / void / written_off render all lines
 * in full for CRA defensibility (the auditor needs to see every billable
 * line, including ones that resolved to $0 after credit application or
 * drawdown — forward-looking discipline for S-MILEAGE-2A/2B drawdown
 * credit lines which will produce $0-mileage-after-credit invoices).
 *
 * Live data at lock time (2026-05-11): zero invoices currently have
 * $0 line items. Production verification of this branch will happen
 * organically when 2A/2B starts producing those line shapes.
 */
$renderLineItems = $lineItems;
if ($isDraft) {
    $renderLineItems = array_values(array_filter($lineItems, function($li) {
        return bccomp((string)$li['amount'], '0', 2) !== 0;
    }));
}
$hiddenZeroLineCount = count($lineItems) - count($renderLineItems);

/* ─── S-INVOICE-DISPLAY-COMPREHENSIVE D-B: taxable vs non-taxable subtotal split ───
 * Computed from the line items themselves so the Financial Summary block
 * can show "Subtotal (taxable) / Subtotal (non-taxable) / Discount /
 * Tax (X% on $base)" in accountant-friendly format. The per-line
 * `taxable` boolean drives the split; credits subtract from their side.
 *
 * S-MILEAGE-2A/2B note: when drawdown credit + usage lines land, the
 * taxable subtotal must include the NET amount (usage − credit), not the
 * gross usage. The current bccomp by sign handles this because is_credit
 * lines already carry negative semantics in the rendering and the
 * underlying amount column is positive but with is_credit=1. Confirm the
 * sign handling when 2A wires up — see SESSION LOG S-MILEAGE-2A entry
 * for the canonical drawdown line shape.
 */
$taxableSubtotal = '0';
$nonTaxableSubtotal = '0';
$taxableLineCount = 0;
$nonTaxableLineCount = 0;
foreach ($lineItems as $li) {
    $amt = (string)$li['amount'];
    if (!empty($li['is_credit'])) {
        $amt = '-' . $amt;
    }
    if (!empty($li['taxable'])) {
        $taxableSubtotal = bcadd($taxableSubtotal, $amt, 6);
        $taxableLineCount++;
    } else {
        $nonTaxableSubtotal = bcadd($nonTaxableSubtotal, $amt, 6);
        $nonTaxableLineCount++;
    }
}
$taxableSubtotal = bcadd($taxableSubtotal, '0', 2);
$nonTaxableSubtotal = bcadd($nonTaxableSubtotal, '0', 2);
?>

<!-- ================================================================
     LINE ITEMS TABLE — Comprehensive with expandable details
     ================================================================ -->
<div class="card line-items-card" style="margin-bottom:24px;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <h3 style="font-size:14px; font-weight:600; margin:0;">Line Items</h3>
        <span class="badge badge-no-dot badge-neutral"><?= count($renderLineItems) ?> item<?= count($renderLineItems) !== 1 ? 's' : '' ?></span>
        <?php if ($creditLineCount > 0): ?>
            <span class="badge badge-no-dot badge-success" style="font-size:11px;"><?= $creditLineCount ?> credit<?= $creditLineCount !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
        <?php if ($mileageLineCount > 0): ?>
            <span class="badge badge-no-dot badge-info" style="font-size:11px;"><?= $mileageLineCount ?> mileage</span>
        <?php endif; ?>
        <?php if ($hiddenZeroLineCount > 0): ?>
            <span class="text-sm text-secondary" style="font-size:11px;">(<?= $hiddenZeroLineCount ?> $0 line<?= $hiddenZeroLineCount !== 1 ? 's' : '' ?> hidden — draft view)</span>
        <?php endif; ?>
    </div>

    <?php if (empty($renderLineItems)): ?>
        <div class="empty-state" style="padding:40px;">
            <p class="empty-state-title">No line items<?= $hiddenZeroLineCount > 0 ? ' to show' : '' ?></p>
            <p class="empty-state-text">
                <?php if ($hiddenZeroLineCount > 0): ?>
                    This draft has <?= $hiddenZeroLineCount ?> $0 line<?= $hiddenZeroLineCount !== 1 ? 's' : '' ?> hidden. They will appear once the invoice is sent.
                <?php else: ?>
                    This invoice has no line items.
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table" aria-label="Invoice line items">
                <thead>
                    <tr>
                        <th style="width:36px;">#</th>
                        <th style="width:110px;">Type</th>
                        <th>Description</th>
                        <th style="width:140px;">Period</th>
                        <th style="text-align:right; width:60px;">Qty</th>
                        <th style="text-align:right; width:100px;">Unit Price</th>
                        <th style="text-align:right; width:80px;">Tax</th>
                        <th style="text-align:right; width:110px;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($renderLineItems as $i => $item):
                        $isCreditLine = (bool)$item['is_credit'];
                        $rowStyle = $isCreditLine ? 'color:var(--color-success);' : '';
                        $hasDetail = !empty($item['_detail']);
                        // S-MILEAGE-FIX-0 (D-G): match the schema enum values
                        // (mileage_precharge / mileage_adjustment / mileage_credit)
                        // rather than the stale mileage_charge / mileage_overage
                        // which are not in invoice_line_items.item_type.
                        $isMileage = in_array($item['item_type'], ['mileage_precharge', 'mileage_estimate', 'mileage_adjustment', 'mileage_credit', 'mileage_usage', 'mileage_drawdown_credit'], true);

                        // WHY: Calculate per-line tax total for display
                        $lineTaxTotal = bcadd(
                            bcadd($item['tax_gst_amount'] ?? '0', $item['tax_pst_amount'] ?? '0', 2),
                            $item['tax_hst_amount'] ?? '0',
                            2
                        );

                        // ────────────────────────────────────────────────────────
                        // LINE TYPE DISPATCH CONTRACT (S-MILEAGE-2A SHIPPED; S-MILEAGE-2B SHIPPED 2026-05-12)
                        // Each item_type value maps to a badge color + display behavior. To add a new line type:
                        //   1. Add the item_type value to the invoice_line_items.item_type ENUM (schema migration)
                        //   2. Add a case to the $itemTypeBadge match() expression below
                        //   3. Add any type-specific rendering hooks to the per-row template
                        //      (e.g., the $isMileage block for mileage detail, mirror that pattern)
                        // Display contract: every line type receives the same row data shape (id, item_type,
                        // description, detail_lines, quantity, unit, unit_price, amount, is_credit, taxable,
                        // tax_gst/pst/hst_amount, mileage_*, billing_days, rate_method, period_start/end) and
                        // must render into the same 8-column table structure (#, Type, Description, Period,
                        // Qty, Unit Price, Tax, Amount).
                        // Currently supported item_types (16 values from invoice_line_items.item_type ENUM):
                        //   base_rental, mileage_precharge, mileage_adjustment, mileage_credit,
                        //   insurance, warranty, late_fee, early_return_credit, manual_adjustment,
                        //   damage, discount, account_credit_applied, other, gps,
                        //   mileage_usage, mileage_drawdown_credit.
                        // Model B drawdown (S-MILEAGE-2B): mileage_usage (per-km charge, positive,
                        // is_credit=0) + mileage_drawdown_credit (precharge balance drawdown, positive
                        // amount + is_credit=1 per the signed-aggregator convention in InvoiceGenerator).
                        // mileage_adjustment retained as a closed historical category for legacy Model C
                        // invoices (INV-91 + INV-92 in production at 2B ship time).
                        // ────────────────────────────────────────────────────────
                        // S-MILEAGE-FIX-0 (D-G): replaced stale mileage_charge /
                        // mileage_overage badges with the actual enum values.
                        $itemTypeBadge = match($item['item_type']) {
                            'base_rental'             => 'badge-info',
                            'mileage_precharge'       => 'badge-info',
                            'mileage_estimate'        => 'badge-info',
                            'mileage_adjustment'      => 'badge-warning',
                            'mileage_credit'          => 'badge-success',
                            'mileage_usage'           => 'badge-info',
                            'mileage_drawdown_credit' => 'badge-success',
                            'late_fee'                => 'badge-danger',
                            'damage_charge'           => 'badge-danger',
                            'credit'                  => 'badge-success',
                            'adjustment'              => 'badge-warning',
                            'discount'                => 'badge-success',
                            'tax_adjustment'          => 'badge-neutral',
                            'fuel_surcharge'          => 'badge-neutral',
                            'insurance'               => 'badge-neutral',
                            'warranty'                => 'badge-neutral',
                            'gps'                     => 'badge-neutral',
                            'admin_fee'               => 'badge-neutral',
                            default                   => 'badge-neutral',
                        };
                    ?>
                    <tr style="<?= $rowStyle ?>">
                        <td class="text-secondary"><?= $i + 1 ?></td>
                        <td>
                            <span class="badge badge-no-dot <?= $itemTypeBadge ?>" style="font-size:11px; white-space:nowrap;">
                                <?= e(ucwords(str_replace('_', ' ', $item['item_type']))) ?>
                            </span>
                        </td>
                        <td>
                            <div><?= e($item['description']) ?></div>

                            <?php if ($item['billing_days'] || $item['rate_method']): ?>
                                <div class="text-sm text-secondary" style="margin-top:2px;">
                                    <?php if ($item['billing_days']): ?>
                                        <?= (int)$item['billing_days'] ?> billing days
                                    <?php endif; ?>
                                    <?php if ($item['rate_method']): ?>
                                        · <?= e(ucfirst($item['rate_method'])) ?> rate
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($isMileage): ?>
                                <div class="mileage-detail">
                                    <?php if ($item['mileage_distance']): ?>
                                        <span>Distance: <?= e(rtrim(rtrim(number_format((float)$item['mileage_distance'], 2), '0'), '.')) ?> <?= e($item['mileage_unit'] ?? 'km') ?></span>
                                    <?php endif; ?>
                                    <?php if ($item['mileage_rate']): ?>
                                        <span>Rate: <?= format_currency($item['mileage_rate']) ?>/<?= e($item['mileage_unit'] ?? 'km') ?></span>
                                    <?php endif; ?>
                                    <?php if ($item['mileage_estimated']): ?>
                                        <span>Est: <?= e(number_format((float)$item['mileage_estimated'])) ?></span>
                                    <?php endif; ?>
                                    <?php if ($item['mileage_actual']): ?>
                                        <span>Act: <?= e(number_format((float)$item['mileage_actual'])) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- WHY: Expandable detail lines show the pro-rate calculation breakdown -->
                            <?php if ($hasDetail): ?>
                                <div style="margin-top:4px;">
                                    <span class="detail-toggle"
                                          onclick="this.nextElementSibling.classList.toggle('hidden'); this.textContent = this.nextElementSibling.classList.contains('hidden') ? 'Show calculation ▸' : 'Hide calculation ▾'">
                                        Show calculation ▸
                                    </span>
                                    <div class="detail-expansion hidden">
                                        <?= ff_calc_breakdown_html($item['_detail']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="font-mono text-sm">
                            <?php if ($item['period_start'] && $item['period_end']): ?>
                                <?= format_date($item['period_start']) ?>
                                <br>→ <?= format_date($item['period_end']) ?>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="font-mono" style="text-align:right;">
                            <?= e(rtrim(rtrim(number_format((float)$item['quantity'], 4), '0'), '.')) ?>
                            <?php if ($item['unit']): ?>
                                <div class="text-sm text-secondary" style="font-size:11px;"><?= e($item['unit']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="font-mono" style="text-align:right;">
                            <?= format_currency($item['unit_price']) ?>
                        </td>
                        <td class="font-mono text-sm" style="text-align:right;">
                            <?php if (bccomp($lineTaxTotal, '0', 2) > 0): ?>
                                <?= format_currency($lineTaxTotal) ?>
                                <?php if (!$item['taxable']): ?>
                                    <div class="text-sm text-secondary" style="font-size:10px;">non-taxable</div>
                                <?php endif; ?>
                            <?php elseif (!$item['taxable']): ?>
                                <span class="text-secondary" style="font-size:11px;">exempt</span>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="font-mono" style="text-align:right; font-weight:600;">
                            <?php if ($isCreditLine): ?>−<?php endif; ?>
                            <?= format_currency($item['amount']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg-muted);">
                        <td colspan="7" style="text-align:right; font-weight:600; font-size:13px; padding:12px 14px;">
                            Subtotal
                        </td>
                        <td class="font-mono" style="text-align:right; font-weight:700; padding:12px 14px;">
                            <?= format_currency($invoice['subtotal']) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>


<!-- ================================================================
     CREDIT APPLICATIONS — S-INVOICE-DISPLAY-COMPREHENSIVE D-C
     Renders when credit_note_applications rows exist OR the invoice's
     aggregate credits_applied > 0. Sits between Line Items and
     Financial Summary so the auditor can see WHICH credits reduced the
     balance before they look at the totals.
     ================================================================ -->
<?php if (!empty($creditApplications) || bccomp($invoice['credits_applied'] ?? '0', '0', 2) > 0): ?>
<div class="card credit-applications-card" style="margin-bottom:24px;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:12px;">
        <h3 style="font-size:14px; font-weight:600; margin:0;">Credits Applied</h3>
        <span class="badge badge-no-dot badge-success"><?= count($creditApplications) ?> credit<?= count($creditApplications) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="table-wrapper">
        <table class="table" aria-label="Credit applications against this invoice">
            <thead>
                <tr>
                    <th style="width:160px;">Credit Note</th>
                    <th style="width:120px;">Source</th>
                    <th>Reason</th>
                    <th style="width:130px;">Applied</th>
                    <th style="text-align:right; width:130px;">Amount Applied</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $creditSourceLabels = [
                    'mileage_overpayment' => 'Mileage Overpayment',
                    'invoice_adjustment'  => 'Invoice Adjustment',
                    'damage_resolution'   => 'Damage Resolution',
                    'goodwill'            => 'Goodwill',
                    'payment_returned'    => 'Payment Returned',
                    'overpayment'         => 'Overpayment',
                    'other'               => 'Other',
                ];
                foreach ($creditApplications as $ca):
                ?>
                <tr>
                    <td class="font-mono text-sm">
                        <a href="<?= e(base_url('credit_notes/show?id=' . (int)$ca['credit_note_id'])) ?>"
                           target="_blank" rel="noopener"
                           style="color:var(--color-primary);">
                            <?= e($ca['credit_note_number']) ?>
                        </a>
                    </td>
                    <td>
                        <span class="text-sm"><?= e($creditSourceLabels[$ca['credit_note_source']] ?? ucwords(str_replace('_', ' ', (string)$ca['credit_note_source']))) ?></span>
                    </td>
                    <td>
                        <div class="text-sm"><?= e($ca['credit_note_reason'] ?? '') ?></div>
                    </td>
                    <td class="font-mono text-sm">
                        <div><?= format_date($ca['applied_at']) ?></div>
                        <?php if (!empty($ca['applied_by_name'])): ?>
                            <div class="text-sm text-secondary" style="font-size:11px;">by <?= e($ca['applied_by_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="font-mono" style="text-align:right; font-weight:600; color:var(--color-success);">
                        −<?= format_currency($ca['amount_applied']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($creditApplications) && bccomp($invoice['credits_applied'] ?? '0', '0', 2) > 0): ?>
                <tr>
                    <td colspan="4" class="text-sm text-secondary" style="padding:14px;">
                        Credit application total recorded on invoice ($<?= format_currency($invoice['credits_applied']) ?>) but no per-application breakdown is available. This typically means credits were applied through a legacy path before per-application tracking was wired.
                    </td>
                    <td class="font-mono" style="text-align:right; font-weight:600; color:var(--color-success);">
                        −<?= format_currency($invoice['credits_applied']) ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($creditApplications)): ?>
            <tfoot>
                <tr style="background:var(--bg-muted);">
                    <td colspan="4" style="text-align:right; font-weight:600; font-size:13px; padding:12px 14px;">
                        Total Credits Applied
                    </td>
                    <td class="font-mono" style="text-align:right; font-weight:700; padding:12px 14px; color:var(--color-success);">
                        −<?= format_currency($totalCreditsApplied) ?>
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
<?php endif; ?>


<!-- ================================================================
     FINANCIAL SUMMARY — Step-by-step CRA-defensible breakdown
     S-INVOICE-DISPLAY-COMPREHENSIVE D-B (tax base transparency) + D-D
     (step-by-step balance due). Every row hides on zero except Subtotal,
     Total, and Balance Due which always render for completeness.
     ================================================================ -->
<div class="card financial-summary-card" style="margin-bottom:24px;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color);">
        <h3 style="font-size:14px; font-weight:600; margin:0;">Financial Summary</h3>
    </div>
    <div style="padding:16px 20px;">
        <table class="financial-summary-table" style="max-width:520px; margin-left:auto;">
            <tbody>
                <!-- D-B: split subtotal into taxable + non-taxable so the tax-base reconciliation
                     question (does tax_base × rate match the displayed tax amount?) is auditable
                     row-by-row. Three render shapes:
                       (a) Both taxable + non-taxable lines → show both rows (the split).
                       (b) Only taxable lines (most common) → show plain "Subtotal" — the
                           "(taxable lines)" qualifier would be misleading on tax-exempt
                           customers (lines ARE taxable but no tax is applied per the
                           customer's gst_exempt/pst_exempt_snapshot).
                       (c) Only non-taxable lines → show "Subtotal (non-taxable)".
                -->
                <?php $hasBothTaxClasses = $taxableLineCount > 0 && $nonTaxableLineCount > 0; ?>
                <?php if ($hasBothTaxClasses): ?>
                <tr>
                    <td class="fs-label">Subtotal (taxable lines)</td>
                    <td class="fs-value"
                        title="<?= $taxableLineCount > 0 ? 'Applied to ' . $taxableLineCount . ' taxable line' . ($taxableLineCount !== 1 ? 's' : '') . ' totaling ' . format_currency($taxableSubtotal) : '' ?>">
                        <?= format_currency($taxableSubtotal) ?>
                    </td>
                </tr>
                <tr>
                    <td class="fs-label">Subtotal (non-taxable lines)</td>
                    <td class="fs-value"
                        title="Applied to <?= $nonTaxableLineCount ?> non-taxable line<?= $nonTaxableLineCount !== 1 ? 's' : '' ?> totaling <?= format_currency($nonTaxableSubtotal) ?>">
                        <?= format_currency($nonTaxableSubtotal) ?>
                    </td>
                </tr>
                <?php elseif ($nonTaxableLineCount > 0 && $taxableLineCount === 0): ?>
                <tr>
                    <td class="fs-label">Subtotal (non-taxable)</td>
                    <td class="fs-value"><?= format_currency($nonTaxableSubtotal) ?></td>
                </tr>
                <?php else: ?>
                <tr>
                    <td class="fs-label">Subtotal</td>
                    <td class="fs-value"><?= format_currency($invoice['subtotal']) ?></td>
                </tr>
                <?php endif; ?>

                <!-- Discount: applies before tax computation. The Subtotal After Discount row
                     renders only when there's a discount applied (otherwise it duplicates Subtotal). -->
                <?php if (bccomp($invoice['discount_amount'] ?? '0', '0', 2) > 0): ?>
                <tr>
                    <td class="fs-label">
                        Discount
                        <?php if ($invoice['discount_type'] === 'percentage'): ?>
                            (<?= e(rtrim(rtrim(number_format((float)($invoice['discount_value'] ?? 0), 2), '0'), '.')) ?>%)
                        <?php elseif ($invoice['discount_type'] === 'fixed'): ?>
                            (fixed)
                        <?php endif; ?>
                    </td>
                    <td class="fs-value" style="color:var(--color-success);">−<?= format_currency($invoice['discount_amount']) ?></td>
                </tr>
                <tr>
                    <td class="fs-label">Subtotal after discount</td>
                    <td class="fs-value"><?= format_currency($invoice['subtotal_after_discount']) ?></td>
                </tr>
                <?php endif; ?>

                <!-- ── S-MILEAGE-2B C6 (D-F): Model B drawdown breakdown ──
                     Renders when this invoice carries both mileage_usage and
                     mileage_drawdown_credit lines (full drawdown case). The
                     POSITIVE-amount + is_credit=1 convention for the credit
                     line (K-16 clarification) means the aggregator at
                     InvoiceGenerator.php:357-362 already subtracted the credit
                     from $subtotal. Tax was computed on the NET subtotal
                     (TaxCalculator's bcmul sign-propagation handles it
                     correctly per D-D spike). This breakdown surfaces the
                     intermediate math (usage / credit / net) for operator
                     visibility — purely display, no recomputation. -->
                <?php
                $mileageUsageRow      = null;
                $mileageDrawdownRow   = null;
                foreach ($lineItems as $li) {
                    if (($li['item_type'] ?? null) === 'mileage_usage')           $mileageUsageRow    = $li;
                    if (($li['item_type'] ?? null) === 'mileage_drawdown_credit') $mileageDrawdownRow = $li;
                }
                if ($mileageUsageRow && $mileageDrawdownRow):
                    $netMileage = bcsub((string) $mileageUsageRow['amount'], (string) $mileageDrawdownRow['amount'], 2);
                ?>
                <tr>
                    <td class="fs-label" style="padding-left:24px;">
                        <span class="text-secondary text-sm">Mileage usage</span>
                    </td>
                    <td class="fs-value text-sm"><?= format_currency($mileageUsageRow['amount']) ?></td>
                </tr>
                <tr>
                    <td class="fs-label" style="padding-left:24px;">
                        <span class="text-secondary text-sm">Precharge credit applied</span>
                    </td>
                    <td class="fs-value text-sm" style="color:var(--color-success);">−<?= format_currency($mileageDrawdownRow['amount']) ?></td>
                </tr>
                <tr>
                    <td class="fs-label" style="padding-left:24px;">
                        <strong>Net mileage charge</strong>
                    </td>
                    <td class="fs-value"><strong><?= format_currency($netMileage) ?></strong></td>
                </tr>
                <?php endif; ?>


                <!-- D-B: tax rows show "rate% on $base" inline so the auditor's first-glance
                     reconciliation (rate × base == tax_amount) works without scrolling.
                     Per-tax-row tooltip lists how many taxable lines the tax applies to
                     and the taxable subtotal — answers the accountant's "does the tax
                     base reconcile to the taxable subtotal?" audit question. -->
                <?php
                // Derive each tax's actual base from the stored amount/rate (lossy reverse-math
                // but matches what TaxCalculator wrote at invoice time).
                $taxBaseFromAmount = function(string $amount, string $rate): string {
                    if (bccomp($rate, '0', 6) <= 0) return '0';
                    return bcdiv($amount, $rate, 2);
                };
                ?>
                <?php if (bccomp($invoice['tax_gst_amount'] ?? '0', '0', 2) > 0): ?>
                <?php $gstBase = $taxBaseFromAmount((string)$invoice['tax_gst_amount'], (string)($invoice['tax_gst_rate'] ?? '0')); ?>
                <tr>
                    <td class="fs-label">
                        GST (<?= e(rtrim(rtrim(number_format((float)($invoice['tax_gst_rate'] ?? 0) * 100, 4), '0'), '.')) ?>% on <?= format_currency($gstBase) ?>)
                    </td>
                    <td class="fs-value"
                        title="Applied to <?= $taxableLineCount ?> taxable line<?= $taxableLineCount !== 1 ? 's' : '' ?> totaling <?= format_currency($taxableSubtotal) ?>">
                        <?= format_currency($invoice['tax_gst_amount']) ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if (bccomp($invoice['tax_pst_amount'] ?? '0', '0', 2) > 0): ?>
                <?php $pstBase = $taxBaseFromAmount((string)$invoice['tax_pst_amount'], (string)($invoice['tax_pst_rate'] ?? '0')); ?>
                <tr>
                    <td class="fs-label">
                        PST (<?= e(rtrim(rtrim(number_format((float)($invoice['tax_pst_rate'] ?? 0) * 100, 4), '0'), '.')) ?>% on <?= format_currency($pstBase) ?>)
                    </td>
                    <td class="fs-value"
                        title="Applied to <?= $taxableLineCount ?> taxable line<?= $taxableLineCount !== 1 ? 's' : '' ?> totaling <?= format_currency($taxableSubtotal) ?>">
                        <?= format_currency($invoice['tax_pst_amount']) ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if (bccomp($invoice['tax_hst_amount'] ?? '0', '0', 2) > 0): ?>
                <?php $hstBase = $taxBaseFromAmount((string)$invoice['tax_hst_amount'], (string)($invoice['tax_hst_rate'] ?? '0')); ?>
                <tr>
                    <td class="fs-label">
                        HST (<?= e(rtrim(rtrim(number_format((float)($invoice['tax_hst_rate'] ?? 0) * 100, 4), '0'), '.')) ?>% on <?= format_currency($hstBase) ?>)
                    </td>
                    <td class="fs-value"
                        title="Applied to <?= $taxableLineCount ?> taxable line<?= $taxableLineCount !== 1 ? 's' : '' ?> totaling <?= format_currency($taxableSubtotal) ?>">
                        <?= format_currency($invoice['tax_hst_amount']) ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if (bccomp($invoice['tax_total'] ?? '0', '0', 2) > 0): ?>
                <tr>
                    <td class="fs-label" style="font-weight:500;">Tax total</td>
                    <td class="fs-value" style="font-weight:500;"><?= format_currency($invoice['tax_total']) ?></td>
                </tr>
                <?php endif; ?>

                <!-- Total Amount — always shown (D-D: Subtotal + Total + Balance Due always render) -->
                <tr class="fs-grand">
                    <td class="fs-label fs-total">Total</td>
                    <td class="fs-value fs-total"><?= format_currency($invoice['total_amount']) ?> <?= e($invoice['currency']) ?></td>
                </tr>

                <!-- D-D: Payments / Credits / Late Fee each render only when their value is non-zero. -->
                <?php if (bccomp($invoice['amount_paid'] ?? '0', '0', 2) > 0): ?>
                <tr>
                    <td class="fs-label">Payments received</td>
                    <td class="fs-value" style="color:var(--color-success);">−<?= format_currency($invoice['amount_paid']) ?></td>
                </tr>
                <?php endif; ?>
                <?php if (bccomp($invoice['credits_applied'] ?? '0', '0', 2) > 0): ?>
                <tr>
                    <td class="fs-label">Credits applied</td>
                    <td class="fs-value" style="color:var(--color-success);">−<?= format_currency($invoice['credits_applied']) ?></td>
                </tr>
                <?php endif; ?>

                <?php if ($invoice['late_fee_applied'] && bccomp($invoice['late_fee_amount'] ?? '0', '0', 2) > 0): ?>
                <tr>
                    <td class="fs-label" style="color:var(--color-danger);">Late fee applied</td>
                    <td class="fs-value" style="color:var(--color-danger);">+<?= format_currency($invoice['late_fee_amount']) ?></td>
                </tr>
                <?php endif; ?>

                <!-- Balance Due — always shown -->
                <tr class="fs-divider">
                    <td class="fs-label fs-total" style="font-size:16px;">Balance Due</td>
                    <td class="fs-value fs-total" style="font-size:16px; <?= bccomp($invoice['balance_due'], '0', 2) > 0 ? 'color:var(--color-danger);' : 'color:var(--color-success);' ?>">
                        <?= format_currency($invoice['balance_due']) ?> <?= e($invoice['currency']) ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- FX block: shown for USD invoices that have a frozen exchange rate -->
        <?php if ($invoice['currency'] !== 'CAD' && !empty($invoice['exchange_rate_to_cad'])): ?>
        <?php
            // CURRENCY-MARKUP-1: compute CAD equivalent via effective rate (bank + markup).
            // bank rate and markup_pct are both frozen at invoice creation time.
            $fxBankRate  = (string) $invoice['exchange_rate_to_cad'];
            $fxMarkupPct = (string) ($invoice['currency_markup_pct'] ?? '0.0000');
            $fxHasMarkup = bccomp($fxMarkupPct, '0', 4) > 0;
            if ($fxHasMarkup) {
                // effective_rate = bank_rate × (1 + markup_pct / 100)
                $fxFactor        = bcadd('1', bcdiv($fxMarkupPct, '100', 10), 10);
                $fxEffectiveRate = bcmul($fxBankRate, $fxFactor, 6);
                $fxCadEquiv      = bcmul($invoice['total_amount'], $fxEffectiveRate, 2);
            } else {
                $fxEffectiveRate = $fxBankRate;
                $fxCadEquiv      = bcmul($invoice['total_amount'], $fxBankRate, 2);
            }
            $fxFmtRate = static fn(string $r): string => rtrim(rtrim(number_format((float)$r, 6), '0'), '.');
        ?>
        <div class="invoice-fx-block" style="text-align:right; margin-top:12px; max-width:480px; margin-left:auto;">
        <?php if ($fxHasMarkup): ?>
            <!-- Full breakdown: bank rate + markup + effective rate + CAD equivalent -->
            <table style="margin-left:auto; border-collapse:collapse; font-size:0.8125rem; width:100%;">
                <tr>
                    <td class="text-secondary" style="padding:2px 12px 2px 0; white-space:nowrap;">Bank rate (<?= e($invoice['currency']) ?> → CAD):</td>
                    <td class="font-mono" style="text-align:right;"><?= e($fxFmtRate($fxBankRate)) ?></td>
                </tr>
                <tr>
                    <td class="text-secondary" style="padding:2px 12px 2px 0; white-space:nowrap;">Markup applied:</td>
                    <td class="font-mono" style="text-align:right;"><?= e(rtrim(rtrim(number_format((float)$fxMarkupPct, 4), '0'), '.')) ?>%</td>
                </tr>
                <tr>
                    <td class="text-secondary" style="padding:2px 12px 2px 0; white-space:nowrap;">Effective rate:</td>
                    <td class="font-mono" style="text-align:right;"><?= e($fxFmtRate($fxEffectiveRate)) ?></td>
                </tr>
                <tr>
                    <td class="text-secondary" style="padding:2px 12px 2px 0; white-space:nowrap;">USD total:</td>
                    <td class="font-mono" style="text-align:right;"><?= format_currency($invoice['total_amount']) ?> USD</td>
                </tr>
                <tr style="border-top:1px solid var(--border-default); font-weight:600;">
                    <td class="text-secondary" style="padding:4px 12px 2px 0; white-space:nowrap;">CAD equivalent:</td>
                    <td class="font-mono" style="text-align:right;"><?= format_currency($fxCadEquiv) ?> CAD</td>
                </tr>
            </table>
        <?php else: ?>
            <!-- No markup — show existing compact note -->
            <span class="text-sm text-secondary">
                Exchange rate: 1 <?= e($invoice['currency']) ?> = <?= e($fxFmtRate($fxBankRate)) ?> CAD ·
                CAD equivalent: <?= format_currency($fxCadEquiv) ?> CAD
            </span>
        <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Tax exemption notes -->
        <?php if ($invoice['gst_exempt_snapshot'] || $invoice['pst_exempt_snapshot'] || $invoice['tax_exempt_snapshot']): ?>
        <div class="text-sm text-secondary" style="text-align:right; margin-top:8px; max-width:480px; margin-left:auto;">
            Tax exemptions:
            <?php if ($invoice['tax_exempt_snapshot']): ?>
                Fully tax exempt
            <?php else: ?>
                <?php if ($invoice['gst_exempt_snapshot']): ?>
                    GST exempt<?php if (!empty($invoice['gst_exempt_number_snapshot'])): ?> (#<?= e($invoice['gst_exempt_number_snapshot']) ?>)<?php endif; ?>
                <?php endif; ?>
                <?php if ($invoice['gst_exempt_snapshot'] && $invoice['pst_exempt_snapshot']): ?>, <?php endif; ?>
                <?php if ($invoice['pst_exempt_snapshot']): ?>
                    PST exempt<?php if (!empty($invoice['pst_exempt_number_snapshot'])): ?> (#<?= e($invoice['pst_exempt_number_snapshot']) ?>)<?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>


<!-- ================================================================
     DELIVERY & TRACKING — Sent info, Late fees, Credit notes
     ================================================================ -->
<?php
$hasDeliveryInfo = $invoice['sent_date'] || $invoice['sent_to_email'] || $invoice['delivery_method'];
$hasLateFee = $invoice['late_fee_applied'] && bccomp($invoice['late_fee_amount'] ?? '0', '0', 2) > 0;
$hasCreditNotes = !empty($creditNotesFrom) || $creditNoteForInvoice;
$hasVoidInfo = $isVoid && $invoice['void_reason'];
$hasWriteOffInfo = $isWrittenOff && $invoice['write_off_reason'];

if ($hasDeliveryInfo || $hasLateFee || $hasCreditNotes || $hasVoidInfo || $hasWriteOffInfo):
?>
<!-- WHY: Delivery/late/credit/void tracking is internal context; hidden in print.
     Customers get the essential info (status badge, notes) via the letterhead. -->
<div class="ff-print-hide invoice-section-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:24px;">

    <!-- Delivery Tracking -->
    <?php if ($hasDeliveryInfo): ?>
    <div class="card" style="padding:20px;">
        <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); margin:0 0 12px 0;">
            Delivery Tracking
        </h3>
        <dl class="invoice-meta-dl" style="display:grid; grid-template-columns:120px 1fr; gap:6px 16px; font-size:13px; margin:0;">
            <?php if ($invoice['sent_date']): ?>
            <dt class="text-secondary">Sent Date</dt>
            <dd class="font-mono"><?= format_date($invoice['sent_date']) ?></dd>
            <?php endif; ?>

            <?php if (!empty($invoice['sent_by']) && isset($userNames[(int)$invoice['sent_by']])): ?>
            <dt class="text-secondary">Sent By</dt>
            <dd><?= e($userNames[(int)$invoice['sent_by']]) ?></dd>
            <?php endif; ?>

            <?php if ($invoice['sent_to_email']): ?>
            <dt class="text-secondary">Sent To</dt>
            <dd>
                <a href="mailto:<?= e($invoice['sent_to_email']) ?>" class="link"><?= e($invoice['sent_to_email']) ?></a>
            </dd>
            <?php endif; ?>

            <?php if (!empty($sentCcEmails)): ?>
            <dt class="text-secondary">CC</dt>
            <dd><?= e(implode(', ', $sentCcEmails)) ?></dd>
            <?php endif; ?>

            <?php if ($invoice['delivery_method']): ?>
            <dt class="text-secondary">Method</dt>
            <dd>
                <span class="badge badge-no-dot badge-neutral" style="font-size:11px;">
                    <?= e(ucfirst($invoice['delivery_method'])) ?>
                </span>
            </dd>
            <?php endif; ?>
        </dl>
    </div>
    <?php endif; ?>

    <!-- Late Fee / Credit Notes / Void Info -->
    <?php if ($hasLateFee || $hasCreditNotes || $hasVoidInfo || $hasWriteOffInfo): ?>
    <div class="card" style="padding:20px;">
        <!-- Late Fee -->
        <?php if ($hasLateFee): ?>
        <div style="<?= ($hasCreditNotes || $hasVoidInfo || $hasWriteOffInfo) ? 'margin-bottom:16px; padding-bottom:16px; border-bottom:1px solid var(--border-color);' : '' ?>">
            <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--color-danger); margin:0 0 8px 0;">
                Late Fee
            </h3>
            <div style="font-size:13px;">
                <span class="font-mono" style="font-weight:600; color:var(--color-danger);"><?= format_currency($invoice['late_fee_amount']) ?></span>
                <?php if ($invoice['late_fee_date']): ?>
                    <span class="text-secondary">applied <?= format_date($invoice['late_fee_date']) ?></span>
                <?php endif; ?>
                <?php if ($lateFeeInvoice): ?>
                    <div style="margin-top:4px;">
                        Late fee invoice:
                        <a href="<?= base_url('invoices/show') ?>?id=<?= (int)$lateFeeInvoice['id'] ?>" class="link font-mono">
                            <?= e($lateFeeInvoice['invoice_number']) ?>
                        </a>
                        <span class="text-secondary">(<?= format_currency($lateFeeInvoice['total_amount']) ?>)</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Credit Notes -->
        <?php if ($hasCreditNotes): ?>
        <div style="<?= ($hasVoidInfo || $hasWriteOffInfo) ? 'margin-bottom:16px; padding-bottom:16px; border-bottom:1px solid var(--border-color);' : '' ?>">
            <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--color-success); margin:0 0 8px 0;">
                Credit Notes
            </h3>

            <?php if ($creditNoteForInvoice): ?>
            <div style="font-size:13px; margin-bottom:8px;">
                <span class="text-secondary">This invoice is a credit note for:</span>
                <a href="<?= base_url('invoices/show') ?>?id=<?= (int)$creditNoteForInvoice['id'] ?>" class="link font-mono">
                    <?= e($creditNoteForInvoice['invoice_number']) ?>
                </a>
                <span class="text-secondary">(<?= format_currency($creditNoteForInvoice['total_amount']) ?>)</span>
            </div>
            <?php endif; ?>

            <?php if (!empty($creditNotesFrom)): ?>
            <div style="font-size:13px;">
                <span class="text-secondary">Credits generated from this invoice:</span>
                <?php foreach ($creditNotesFrom as $cn): ?>
                <div style="margin-top:4px; padding:6px 10px; background:var(--bg-muted); border-radius:4px;">
                    <span class="font-mono" style="font-weight:500;"><?= e($cn['credit_note_number']) ?></span>
                    <span class="font-mono"><?= format_currency($cn['amount']) ?></span>
                    <span class="badge badge-no-dot <?= $cn['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>" style="font-size:10px;">
                        <?= e($cn['status']) ?>
                    </span>
                    <?php if ($cn['reason']): ?>
                        <span class="text-secondary text-sm">— <?= e($cn['reason']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Void Info -->
        <?php if ($hasVoidInfo): ?>
        <div style="<?= $hasWriteOffInfo ? 'margin-bottom:16px; padding-bottom:16px; border-bottom:1px solid var(--border-color);' : '' ?>">
            <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--color-danger); margin:0 0 8px 0;">
                Void Details
            </h3>
            <div style="font-size:13px;">
                <div style="color:var(--color-danger); font-weight:500;"><?= e($invoice['void_reason']) ?></div>
                <div class="text-secondary text-sm" style="margin-top:4px;">
                    Voided <?= format_datetime($invoice['voided_date'] ?? '') ?>
                    <?php if (!empty($invoice['voided_by']) && isset($userNames[(int)$invoice['voided_by']])): ?>
                        by <?= e($userNames[(int)$invoice['voided_by']]) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Write-Off Info -->
        <?php if ($hasWriteOffInfo): ?>
        <div>
            <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--color-danger); margin:0 0 8px 0;">
                Write-Off Details
            </h3>
            <div style="font-size:13px;">
                <div style="color:var(--color-danger); font-weight:500;"><?= e($invoice['write_off_reason']) ?></div>
                <div class="text-secondary text-sm" style="margin-top:4px;">
                    Written off <?= format_datetime($invoice['written_off_at'] ?? '') ?>
                    <?php if (!empty($invoice['written_off_by']) && isset($userNames[(int)$invoice['written_off_by']])): ?>
                        by <?= e($userNames[(int)$invoice['written_off_by']]) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- WHY: If only one card in the grid, fill the gap with an empty div -->
    <?php if ($hasDeliveryInfo && !($hasLateFee || $hasCreditNotes || $hasVoidInfo || $hasWriteOffInfo)): ?>
        <div></div>
    <?php endif; ?>
    <?php if (!$hasDeliveryInfo && ($hasLateFee || $hasCreditNotes || $hasVoidInfo || $hasWriteOffInfo)): ?>
        <div></div>
    <?php endif; ?>
</div>
<?php endif; ?>


<!-- ================================================================
     PAYMENT HISTORY — Allocations table with Record Payment button
     WHY hidden in print: customers get "Amount Paid / Balance Due"
     in the KPI strip and "Payments Applied" in the financial summary.
     The full allocation history is internal AR detail.
     ================================================================ -->
<div class="card ff-print-hide" style="margin-bottom:24px;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:12px;">
        <h3 style="font-size:14px; font-weight:600; margin:0;">Payment History</h3>
        <span class="badge badge-no-dot badge-neutral"><?= count($invoicePayments) ?> payment<?= count($invoicePayments) !== 1 ? 's' : '' ?></span>
        <?php if ($canRecordPayment && can('payments', 'create')): ?>
            <a href="<?= base_url('/payments/create') ?>?invoice_id=<?= (int)$invoiceId ?>"
               class="btn btn-primary btn-sm" style="margin-left:auto;">
                <?= heroicon('plus', 'icon-sm') ?>
                Record Payment
            </a>
        <?php endif; ?>
    </div>

    <?php if (empty($invoicePayments)): ?>
        <div class="empty-state" style="padding:32px;">
            <p class="empty-state-title">No payments recorded</p>
            <p class="empty-state-text">No payments have been applied to this invoice yet.</p>
            <?php if ($canRecordPayment && can('payments', 'create')): ?>
                <a href="<?= base_url('/payments/create') ?>?invoice_id=<?= (int)$invoiceId ?>"
                   class="btn btn-primary btn-sm" style="margin-top:8px;">Record First Payment</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table" style="width:100;" aria-label="Payment allocations">
                <thead>
                    <tr>
                        <th>Payment #</th>
                        <th>Date</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th style="text-align:right;">Applied Amount</th>
                        <th>Status</th>
                        <th>Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoicePayments as $pay):
                        $payBadge = match($pay['payment_status']) {
                            'cleared'  => 'badge-success',
                            'pending'  => 'badge-info',
                            'failed'   => 'badge-danger',
                            'refunded' => 'badge-warning',
                            'void'     => 'badge-neutral',
                            'returned' => 'badge-warning',
                            default    => 'badge-neutral',
                        };
                    ?>
                    <tr>
                        <td>
                            <a href="<?= base_url('/payments/show') ?>?id=<?= (int)$pay['payment_id'] ?>"
                               class="link font-mono"><?= e($pay['payment_number']) ?></a>
                        </td>
                        <td class="font-mono"><?= format_date($pay['payment_date']) ?></td>
                        <td><?= e($methodLabels[$pay['payment_method']] ?? ucfirst($pay['payment_method'])) ?></td>
                        <td class="font-mono"><?= e($pay['reference_number'] ?? '—') ?></td>
                        <td class="font-mono" style="text-align:right; color:var(--color-success); font-weight:600;">
                            <?= format_currency($pay['applied_amount']) ?> <?= e($pay['currency']) ?>
                        </td>
                        <td><span class="badge badge-no-dot <?= $payBadge ?>" style="font-size:11px;"><?= e(ucfirst($pay['payment_status'])) ?></span></td>
                        <td><span class="badge badge-no-dot badge-neutral" style="font-size:11px;"><?= e(ucfirst(str_replace('_', ' ', $pay['allocation_type']))) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg-muted);">
                        <td colspan="4" style="padding:10px 14px; font-weight:600; font-size:13px;">Total Applied</td>
                        <td class="font-mono" style="text-align:right; font-weight:700; color:var(--color-success); padding:10px 14px;">
                            <?= format_currency(bcround($totalApplied, 2)) ?> <?= e($invoice['currency']) ?>
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>


<!-- ================================================================
     NOTES — Customer-facing, Internal, Void reason
     With inline editing for draft invoices
     WHY: Whole card is hidden in print when there's nothing
     customer-facing to show. Internal notes are always hidden in
     print regardless (they're staff-only).
     ================================================================ -->
<div class="card<?= $hasPrintableNotes ? '' : ' ff-print-hide' ?>" id="invoice-edit-section" style="margin-bottom:24px;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:12px;">
        <h3 style="font-size:14px; font-weight:600; margin:0;">Notes & References</h3>
        <?php if ($canEdit): ?>
            <button class="btn btn-secondary btn-sm" style="margin-left:auto;" @click="startEdit()" x-show="!editing">
                <?= heroicon('pencil-square', 'icon-sm') ?> Edit
            </button>
        <?php endif; ?>
    </div>

    <!-- Read-only display -->
    <div style="padding:20px;" x-show="!editing">
        <?php if (!$invoice['notes'] && !$invoice['internal_notes'] && !$invoice['po_number'] && !$invoice['void_reason'] && !$invoice['write_off_reason']): ?>
            <p class="text-secondary text-sm">No notes or references on this invoice.</p>
        <?php else: ?>
            <div style="display:grid; gap:16px;">
                <?php if ($invoice['po_number']): ?>
                <div>
                    <div class="text-secondary text-sm" style="font-weight:600; margin-bottom:4px;">PO Number</div>
                    <div class="font-mono"><?= e($invoice['po_number']) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($invoice['notes']): ?>
                <div>
                    <div class="text-secondary text-sm" style="font-weight:600; margin-bottom:4px;">Customer-Facing Notes</div>
                    <div style="white-space:pre-wrap;"><?= e($invoice['notes']) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($invoice['internal_notes']): ?>
                <!-- WHY: Internal notes are staff-only; hidden in print -->
                <div class="ff-print-hide">
                    <div class="text-secondary text-sm" style="font-weight:600; margin-bottom:4px;">Internal Notes <span class="badge badge-no-dot badge-warning" style="font-size:10px;">Staff Only</span></div>
                    <div style="white-space:pre-wrap;"><?= e($invoice['internal_notes']) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($invoice['void_reason']): ?>
                <div>
                    <div class="text-danger text-sm" style="font-weight:600; margin-bottom:4px;">Void Reason</div>
                    <div style="color:var(--color-danger);"><?= e($invoice['void_reason']) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($invoice['write_off_reason']): ?>
                <div>
                    <div class="text-danger text-sm" style="font-weight:600; margin-bottom:4px;">Write-Off Reason</div>
                    <div style="color:var(--color-danger);"><?= e($invoice['write_off_reason']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Inline edit form -->
    <?php if ($canEdit): ?>
    <div class="inline-edit-section" x-show="editing" x-cloak>
        <div style="display:grid; gap:16px; max-width:600px;">
            <div>
                <label class="form-label">PO Number</label>
                <input type="text" class="form-control" x-model="editForm.po_number" placeholder="e.g., PO-2025-001">
            </div>
            <div>
                <label class="form-label">Sent To Email</label>
                <input type="email" class="form-control" x-model="editForm.sent_to_email" placeholder="billing@customer.com">
            </div>
            <div>
                <label class="form-label">Customer-Facing Notes</label>
                <textarea class="form-control" x-model="editForm.notes" rows="3"
                          placeholder="Notes visible to the customer on the invoice"></textarea>
            </div>
            <div>
                <label class="form-label">Internal Notes <span class="badge badge-no-dot badge-warning" style="font-size:10px;">Staff Only</span></label>
                <textarea class="form-control" x-model="editForm.internal_notes" rows="3"
                          placeholder="Internal notes (not visible to customer)"></textarea>
            </div>
        </div>

        <div style="margin-top:16px; display:flex; gap:8px; align-items:center;">
            <button class="btn btn-primary btn-sm" @click="saveEdit()" :disabled="editSaving">
                <span x-show="!editSaving">Save Changes</span>
                <span x-show="editSaving">Saving…</span>
            </button>
            <button class="btn btn-secondary btn-sm" @click="cancelEdit()">Cancel</button>
            <span class="text-danger text-sm" x-show="editError" x-text="editError"></span>
        </div>
    </div>
    <?php endif; ?>
</div>


<!-- ================================================================
     ACTIVITY LOG — Audit trail timeline
     ================================================================ -->
<?php if (!empty($activityLog)): ?>
<div class="card activity-log-section" style="margin-bottom:24px;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:12px;">
        <h3 style="font-size:14px; font-weight:600; margin:0;">Activity Log</h3>
        <span class="badge badge-no-dot badge-neutral"><?= count($activityLog) ?> event<?= count($activityLog) !== 1 ? 's' : '' ?></span>
    </div>
    <div style="padding:16px 20px;">
        <?php foreach ($activityLog as $event):
            // WHY: Map action types to human-readable labels
            $actionLabel = match($event['action']) {
                'create'            => 'Created',
                'update'            => 'Updated',
                'delete'            => 'Deleted',
                'status_change'     => 'Status Changed',
                'invoice_sent'      => 'Sent',
                'invoice_voided'    => 'Voided',
                'payment_recorded'  => 'Payment Recorded',
                'view'              => 'Viewed',
                'export'            => 'Exported',
                default             => ucfirst(str_replace('_', ' ', $event['action'])),
            };
        ?>
        <div class="activity-item">
            <div class="activity-dot action-<?= e($event['action']) ?>"></div>
            <div style="flex:1;">
                <div>
                    <strong><?= e($actionLabel) ?></strong>
                    <?php if ($event['user_name']): ?>
                        <span class="text-secondary">by <?= e($event['user_name']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($event['notes']): ?>
                    <div class="text-sm text-secondary" style="margin-top:2px;"><?= e($event['notes']) ?></div>
                <?php endif; ?>
                <div class="text-sm text-secondary" style="margin-top:2px; font-size:11px;">
                    <?= format_datetime($event['created_at']) ?>
                    <?php if ($event['ip_address'] && $event['ip_address'] !== '127.0.0.1'): ?>
                        · IP <?= e($event['ip_address']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>


<!-- ================================================================
     PRINT-ONLY FOOTER — Shows on printed invoice
     WHY: id="ff-print-footer" lets the print CSS override the inline
     style's margin-top:40px / padding:24px 0 cleanly with !important.
     ================================================================ -->
<div id="ff-print-footer" class="print-only" style="text-align:center; padding:24px 0; border-top:2px solid #333; margin-top:40px; font-size:12px; color:#666;">
    <strong>Invoice <?= e($invoice['invoice_number']) ?></strong> ·
    Generated by FleetForge ·
    <?= date('M j, Y g:i A') ?>
</div>


</div><!-- end x-data="FF_InvoiceShow()" -->


<!-- ================================================================
     ALPINE.JS — Invoice actions, inline editing, modals
     ================================================================ -->
<script>
function FF_InvoiceShow() {
    return {
        /* ── Action states ──────────────────────────────────── */
        sending:        false,
        voiding:        false,
        deleting:       false,
        regenerating:   false,

        // S-INVOICE-DRAFT-EDIT: rebuild this draft from the lease's CURRENT state
        // (same invoice number). Use after editing the lease so the draft catches
        // up. Server-side draft-only/precharge guards return a clear message.
        async regenerateFromLease(id, updatedAt) {
            if (!confirm('Regenerate this invoice from the current lease state?\n\n'
                + 'This replaces the line items with freshly-computed ones based on the lease as it is now '
                + '(the invoice number is kept). Use this after editing the lease.')) return;
            this.regenerating = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/regenerate') ?>', { id: id, updated_at: updatedAt });
                if (r.success) {
                    window.location.href = '<?= base_url('invoices/show') ?>?id=' + r.data.id;
                } else {
                    alert((r.error && r.error.message) || 'Failed to regenerate this invoice.');
                    this.regenerating = false;
                }
            } catch (e) {
                alert('Network error. Please try again.');
                this.regenerating = false;
            }
        },

        /* ── Modal states ───────────────────────────────────── */
        showVoidModal:  false,
        voidReason:     '',
        showDeleteModal: false,

        /* ── Inline edit (draft only) ───────────────────────── */
        editing:    false,
        editSaving: false,
        editError:  '',
        editForm: {
            po_number:      <?= json_encode($invoice['po_number'] ?? '') ?>,
            notes:          <?= json_encode($invoice['notes'] ?? '') ?>,
            internal_notes: <?= json_encode($invoice['internal_notes'] ?? '') ?>,
            sent_to_email:  <?= json_encode($invoice['sent_to_email'] ?? $invoice['customer_email_snapshot'] ?? '') ?>,
        },

        /* ── Toast ──────────────────────────────────────────── */
        toast:     '',
        toastType: 'success',

        /* ── S-LEASE-MILEAGE manager review modal state RETIRED ──
         * Removed in S-MILEAGE-2B C5 per D-I (i) wholesale retire.
         * Endpoint api/v1/invoices/review_mileage.php deleted.
         * Card markup deleted; the site will receive the Drawdown
         * Reconciliation panel in C6 per D-L (read-only display, no
         * review actions). */

        /* ── Send Invoice ───────────────────────────────────── */
        async sendInvoice() {
            this.sending = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/send') ?>', {
                    id: <?= (int)$invoiceId ?>
                });
                if (r.success) {
                    this.showToast('Invoice sent successfully', 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    this.showToast(r.error?.message || 'Failed to send', 'error');
                }
            } catch(e) {
                this.showToast('Network error', 'error');
            }
            this.sending = false;
        },

        /* ── Void Invoice ───────────────────────────────────── */
        async voidInvoice() {
            this.voiding = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/void') ?>', {
                    id: <?= (int)$invoiceId ?>,
                    void_reason: this.voidReason
                });
                if (r.success) {
                    this.showToast('Invoice voided', 'success');
                    this.showVoidModal = false;
                    setTimeout(() => location.reload(), 1200);
                } else {
                    this.showToast(r.error?.message || 'Failed to void', 'error');
                }
            } catch(e) {
                this.showToast('Network error', 'error');
            }
            this.voiding = false;
        },

        /* ── Delete Invoice (draft only) ────────────────────── */
        async deleteInvoice() {
            this.deleting = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/delete') ?>', {
                    id: <?= (int)$invoiceId ?>
                });
                if (r.success) {
                    this.showToast('Invoice deleted', 'success');
                    this.showDeleteModal = false;
                    setTimeout(() => {
                        // Return to where this invoice lives: its lease's Invoices tab
                        // when it belongs to a lease, else the invoices module home.
                        window.location.href = '<?= $invoice['lease_id']
                            ? base_url('leases/show') . '?id=' . (int) $invoice['lease_id'] . '#invoices'
                            : base_url('invoices') ?>';
                    }, 1200);
                } else {
                    this.showToast(r.error?.message || 'Failed to delete', 'error');
                }
            } catch(e) {
                this.showToast('Network error', 'error');
            }
            this.deleting = false;
        },

        /* ── Inline Edit — Start / Cancel / Save ────────────── */
        startEdit() {
            this.editing = true;
            this.editError = '';
            // Scroll to the edit form — it lives in the Notes section at the bottom of the page
            this.$nextTick(() => {
                document.getElementById('invoice-edit-section')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        },

        cancelEdit() {
            this.editing = false;
            this.editError = '';
            // WHY: Reset form to original values on cancel
            this.editForm = {
                po_number:      <?= json_encode($invoice['po_number'] ?? '') ?>,
                notes:          <?= json_encode($invoice['notes'] ?? '') ?>,
                internal_notes: <?= json_encode($invoice['internal_notes'] ?? '') ?>,
                sent_to_email:  <?= json_encode($invoice['sent_to_email'] ?? $invoice['customer_email_snapshot'] ?? '') ?>,
            };
        },

        async saveEdit() {
            this.editSaving = true;
            this.editError  = '';
            try {
                // WHY: D19 optimistic lock — must send updated_at
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/update') ?>', {
                    id:         <?= (int)$invoiceId ?>,
                    updated_at: <?= json_encode($invoice['updated_at']) ?>,
                    po_number:      this.editForm.po_number,
                    notes:          this.editForm.notes,
                    internal_notes: this.editForm.internal_notes,
                    sent_to_email:  this.editForm.sent_to_email,
                });
                if (r.success) {
                    this.showToast('Invoice updated', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    this.editError = r.error?.message || 'Failed to save';
                    if (r.error?.code === 'STALE_DATA') {
                        this.editError = 'This invoice was modified by another user. Please refresh the page.';
                    }
                }
            } catch(e) {
                this.editError = 'Network error — please try again';
            }
            this.editSaving = false;
        },

        /* ── Toast ──────────────────────────────────────────── */
        showToast(msg, type) {
            this.toast = msg;
            this.toastType = type;
            setTimeout(() => { this.toast = ''; }, 4000);
        }
    };
}

/* ── S-QBO-INVOICE-SHOW-RICH-PANEL: Retry button factory ──────────
   Scoped to the QuickBooks Sync panel only. Wires Retry → POST
   api/v1/quickbooks/invoices/retry.php with the mapping row id
   (NOT ff_invoice_id — matches retry.php's contract at lines 28-32).
   On success reloads the page so the new sync_log row + updated
   push_status render in the panel immediately. */
function qboSyncPanel(mappingId) {
    return {
        retrying:  false,
        flash:     '',
        flashType: '',
        async retry() {
            if (!mappingId) {
                this.flash = 'No mapping row to retry.';
                this.flashType = 'danger';
                return;
            }
            this.retrying = true;
            this.flash    = '';
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/quickbooks/invoices/retry') ?>', { id: mappingId });
                if (r.success) {
                    if (r.action === 'enqueued') {
                        this.flash = 'Re-enqueued for push — reloading…';
                        this.flashType = 'success';
                        setTimeout(() => window.location.reload(), 600);
                    } else {
                        this.flash = 'Skipped: ' + (r.reason || 'gate refused');
                        this.flashType = 'danger';
                    }
                } else {
                    this.flash = 'Retry failed: ' + (r.message || 'unknown error');
                    this.flashType = 'danger';
                }
            } catch (e) {
                this.flash = 'Retry failed: ' + (e.message || e);
                this.flashType = 'danger';
            } finally {
                this.retrying = false;
            }
        },
    };
}
</script>

<?php
$aiSummaryEntityType = 'invoice';
$aiSummaryEntityId   = $invoiceId;
$aiSummaryType       = 'invoice_analysis';
$aiSummaryTitle      = 'Invoice Analysis — ' . e($invoice['invoice_number'] ?? '');
require_once FF_ROOT . '/includes/partials/ai-panel.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
