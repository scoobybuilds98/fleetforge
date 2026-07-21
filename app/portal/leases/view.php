<?php
declare(strict_types=1);

/**
 * app/portal/leases/view.php
 *
 * Portal lease detail page — shows customer-facing lease info,
 * rates, documents, timeline, and action buttons.
 * Trap 8: query filters by portal_customer_id().
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$cid = portal_customer_id();
$leaseId = clean_int($_GET['id'] ?? null);

if (!$leaseId) {
    header('Location: ' . base_url('portal/leases'));
    exit;
}

// Fetch lease — MUST filter by customer_id (Trap 8)
$lease = db_row(
    "SELECT l.*, eu.unit_number, eu.samsara_vehicle_url, eu.yard_location,
            eb.label AS brand, et.model, et.category, eu.year
     FROM leases l
     JOIN equipment_units eu ON eu.id = l.equipment_unit_id
     JOIN equipment_templates et ON et.id = eu.template_id
     LEFT JOIN equipment_brands eb ON eb.id = eu.brand_id
     WHERE l.id = ? AND l.customer_id = ? AND l.deleted_at IS NULL
     AND eu.deleted_at IS NULL AND et.deleted_at IS NULL",
    [$leaseId, $cid]
);

if (!$lease) {
    header('Location: ' . base_url('portal/leases'));
    exit;
}

// S-LEASE-CLOSE-ACTUAL-DATE: helper inputs for ff_invoice_display_period_end() —
// every invoice rendered on this page belongs to THIS lease, so its pickup/return
// time fields come straight from $lease. Lets the mileage-history tables show the
// ACTUAL rental end (real return date) when the time-of-day rule trimmed the
// billed final day, consistent with the portal invoice list + view.
$_leasePeriodCtx = [
    'actual_return_date'   => $lease['actual_return_date']   ?? null,
    'actual_return_time'   => $lease['actual_return_time']   ?? null,
    'start_time'           => $lease['start_time']           ?? null,
    'billing_days_removed' => $lease['billing_days_removed'] ?? 0,
];

// Invoices for this lease
// L08: customers must never see draft (internal-only) or void invoices — only
// invoices disclosed to them. Mirrors the portal draft-hiding invariant applied
// elsewhere (the unfiltered query previously leaked drafts + voids to the customer).
$invoices = db_select(
    "SELECT id, invoice_number, invoice_date, due_date, total_amount, balance_due, status
     FROM invoices
     WHERE lease_id = ? AND customer_id = ? AND deleted_at IS NULL
       AND status NOT IN ('draft', 'void')
     ORDER BY invoice_date DESC",
    [$leaseId, $cid]
);

// Status log / timeline
$timeline = db_select(
    "SELECT new_status, changed_at, notes
     FROM lease_status_log
     WHERE lease_id = ?
     ORDER BY changed_at DESC LIMIT 20",
    [$leaseId]
);

// Documents
$documents = db_select(
    "SELECT id, title, document_type, file_name, file_size_kb, uploaded_at
     FROM documents
     WHERE entity_type = 'lease' AND entity_id = ? AND deleted_at IS NULL
     ORDER BY uploaded_at DESC",
    [$leaseId]
);

// Days active
$startTs = strtotime($lease['start_date']);
$endTs   = $lease['end_date'] ? strtotime($lease['end_date']) : time();
$daysActive = max(1, (int) ceil(($endTs - $startTs) / 86400) + 1);

// ── S-PORTAL-MILEAGE-MODEL-B: customer-facing mileage section ───
// Replaces the S-LEASE-MILEAGE Model C allowance card (D154 + D167
// final retirement). Renders per the D135 three-configuration matrix:
//   Config 1 (Precharge):    rate>0 + precharge_enabled=1 → precharge
//                                                            card with
//                                                            paid /
//                                                            credits
//                                                            applied /
//                                                            balance +
//                                                            refund
//                                                            block +
//                                                            drawdown
//                                                            history.
//   Config 2 (Model B Lite): rate>0 + precharge_enabled=0 → usage-only
//                                                            card with
//                                                            rate +
//                                                            history.
//   Config 3 (Disabled):     rate=0                       → no section
//                                                            rendered.
// Customer never sees manager-internal fields (review status, override
// notes, audit log) — same discipline as the retired Model C card.
$mileageRateKm    = (float) ($lease['mileage_rate_km']     ?? 0);
$prechargeEnabled = (int)   ($lease['precharge_enabled']   ?? 0);

$mileageConfig  = null;       // 'precharge' | 'usage_only' | null (hide)
$mileageHistory = [];
$prechargeData  = null;
$refundData     = null;

if ($mileageRateKm > 0) {
    $mileageConfig = $prechargeEnabled === 1 ? 'precharge' : 'usage_only';

    // Last 5 sent or paid invoices with mileage_usage lines for this
    // lease. Customers never see drafts — restrict to status that has
    // been disclosed to them (draft = internal-only; void = exclude).
    $mileageHistory = db_select(
        "SELECT ili.amount        AS usage_amount,
                ili.quantity      AS km,
                ili.unit_price    AS rate,
                ili.description   AS description,
                i.id              AS invoice_id,
                i.invoice_number  AS invoice_number,
                i.billing_period_start,
                i.billing_period_end,
                i.status          AS invoice_status,
                (SELECT cn.amount FROM invoice_line_items cn
                  WHERE cn.invoice_id = i.id
                    AND cn.item_type  = 'mileage_drawdown_credit'
                  LIMIT 1)        AS credit_amount
           FROM invoice_line_items ili
           JOIN invoices i ON i.id = ili.invoice_id
          WHERE i.lease_id = ?
            AND i.deleted_at IS NULL
            AND i.status IN ('sent','partially_paid','paid','overdue')
            -- Distance-bearing lines only: quantity holds the distance IN THE LEASE
            -- UNIT for mileage_usage + mileage_estimate. mileage_adjustment/credit
            -- true-ups carry quantity=1 (a flat dollar delta, not a distance) so they
            -- are excluded from this Distance table (they still show on the invoice).
            AND ili.item_type IN ('mileage_usage','mileage_estimate')
          ORDER BY i.billing_period_end DESC, i.id DESC
          LIMIT 5",
        [$leaseId]
    );

    if ($mileageConfig === 'precharge') {
        // Sum of every drawdown credit applied to date — bounded to
        // non-void disclosed invoices, mirroring history filter.
        $creditsRow = db_row(
            "SELECT COALESCE(SUM(ili.amount), 0) AS credits_applied
               FROM invoice_line_items ili
               JOIN invoices i ON i.id = ili.invoice_id
              WHERE i.lease_id = ?
                AND i.deleted_at IS NULL
                AND i.status IN ('sent','partially_paid','paid','overdue')
                AND ili.item_type = 'mileage_drawdown_credit'",
            [$leaseId]
        );
        $creditsApplied = (float) ($creditsRow['credits_applied'] ?? 0);

        $prechargeData = [
            'paid'              => (float) ($lease['precharge_amount']  ?? 0),
            'credits_applied'   => $creditsApplied,
            'balance'           => (float) ($lease['precharge_balance'] ?? 0),
            'invoiced_at'       => $lease['precharge_invoiced_at']      ?? null,
            'refund_method'     => $lease['precharge_refund_method']    ?? null,
            'refund_settled_at' => $lease['precharge_refund_settled_at']?? null,
        ];

        // Refund block surfaces only when a refund method has been
        // chosen at lease close (state machine: NULL → cash|credit
        // immutable per D-D / D171). credit branch: pull the
        // credit_notes row created at close-commit so we can show the
        // CN number + amount. cash branch: derive amount from the
        // historical residual balance preserved on the lease row
        // (precharge_balance NOT zeroed post-refund per D182 — keeps
        // the at-close value as forensic record).
        if ($prechargeData['refund_method'] !== null) {
            $creditNote = null;
            if ($prechargeData['refund_method'] === 'credit') {
                $creditNote = db_row(
                    "SELECT credit_note_number, amount, created_at
                       FROM credit_notes
                      WHERE lease_id = ?
                        AND source    = 'precharge_refund'
                        AND deleted_at IS NULL
                        AND status    != 'void'
                      ORDER BY id DESC LIMIT 1",
                    [$leaseId]
                );
            }
            $refundData = [
                'method'             => $prechargeData['refund_method'],
                'settled_at'         => $prechargeData['refund_settled_at'],
                'amount'             => $creditNote
                    ? (float) $creditNote['amount']
                    : (float) $prechargeData['balance'],
                'credit_note_number' => $creditNote['credit_note_number'] ?? null,
                'issued_at'          => $creditNote['created_at']         ?? null,
            ];
        }
    }
}

$pageTitle = 'Lease ' . $lease['contract_number'];
require_once dirname(__DIR__) . '/includes/header.php';

$statusBadge = match($lease['status']) {
    'active'    => 'badge-success',
    'completed' => 'badge-neutral',
    'cancelled' => 'badge-danger',
    'pending'   => 'badge-info',
    default     => 'badge-neutral',
};
?>

<!-- Header -->
<div class="portal-detail-header">
    <div>
        <a href="<?= e(base_url('portal/leases')) ?>" class="portal-form-link" style="font-size:0.8125rem;display:inline-flex;align-items:center;gap:4px;margin-bottom:8px;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
            Back to Leases
        </a>
        <h1 class="portal-detail-title"><?= e($lease['contract_number']) ?></h1>
        <p class="portal-detail-subtitle">
            <?= e($lease['unit_number']) ?> — <?= e($lease['brand']) ?> <?= e($lease['model']) ?> (<?= e($lease['category']) ?>)
            &nbsp; <span class="badge <?= e($statusBadge) ?>"><?= e(ucfirst($lease['status'])) ?></span>
        </p>
    </div>
    <div class="portal-detail-actions">
        <?php if ($lease['status'] === 'active'): ?>
            <a href="<?= e(base_url('portal/requests/create?type=lease_extension&lease_id=' . $leaseId)) ?>" class="btn btn-primary btn-sm">Request Extension</a>
            <a href="<?= e(base_url('portal/requests/create?type=early_return&lease_id=' . $leaseId)) ?>" class="btn btn-secondary btn-sm">Report Early Return</a>
            <a href="<?= e(base_url('portal/requests/create?type=damage_report&lease_id=' . $leaseId . '&equipment_id=' . $lease['equipment_unit_id'])) ?>" class="btn btn-warning btn-sm">Report Damage</a>
        <?php endif; ?>
    </div>
</div>

<!-- Detail Cards -->
<div class="portal-detail-grid">

    <!-- Lease Details -->
    <div class="portal-info-card">
        <div class="portal-info-card-header">Lease Details</div>
        <ul class="portal-info-list">
            <li>
                <span class="portal-info-label">Contract #</span>
                <span class="portal-info-value font-mono"><?= e($lease['contract_number']) ?></span>
            </li>
            <li>
                <span class="portal-info-label">Status</span>
                <span class="portal-info-value"><span class="badge <?= e($statusBadge) ?>"><?= e(ucfirst($lease['status'])) ?></span></span>
            </li>
            <li>
                <span class="portal-info-label">Start Date</span>
                <span class="portal-info-value font-mono"><?= e(format_date($lease['start_date'])) ?></span>
            </li>
            <li>
                <span class="portal-info-label">End Date</span>
                <span class="portal-info-value font-mono"><?= $lease['end_date'] ? e(format_date($lease['end_date'])) : '—' ?></span>
            </li>
            <li>
                <span class="portal-info-label">Days Active</span>
                <span class="portal-info-value font-mono"><?= e(number_format($daysActive)) ?></span>
            </li>
            <li>
                <span class="portal-info-label">Pickup Yard</span>
                <span class="portal-info-value"><?= e($lease['yard_location'] ?? '—') ?></span>
            </li>
        </ul>
    </div>

    <!-- Rates -->
    <div class="portal-info-card">
        <div class="portal-info-card-header">Rates</div>
        <ul class="portal-info-list">
            <li>
                <span class="portal-info-label">Daily Rate</span>
                <span class="portal-info-value font-mono"><?= e(format_currency($lease['daily_rate'])) ?></span>
            </li>
            <li>
                <span class="portal-info-label">Weekly Rate</span>
                <span class="portal-info-value font-mono"><?= e(format_currency($lease['weekly_rate'])) ?></span>
            </li>
            <li>
                <span class="portal-info-label">Monthly Rate</span>
                <span class="portal-info-value font-mono" style="font-weight:700;font-size:0.9375rem;"><?= e(format_currency($lease['monthly_rate'])) ?></span>
            </li>
            <li>
                <span class="portal-info-label">Mileage Rate</span>
                <span class="portal-info-value font-mono"><?= e(format_currency($lease['mileage_rate'] ?? '0.00')) ?>/<?= e(ff_mileage_unit_label($lease, true)) ?></span>
            </li>
            <li>
                <span class="portal-info-label">Mileage Allowance</span>
                <span class="portal-info-value font-mono"><?= $lease['estimated_mileage'] ? e(number_format((float)$lease['estimated_mileage'])) . ' ' . e(ff_mileage_unit_label($lease)) : 'Unlimited' ?></span>
            </li>
            <li>
                <span class="portal-info-label">Currency</span>
                <span class="portal-info-value"><?= e($lease['currency'] ?? 'CAD') ?></span>
            </li>
        </ul>
    </div>

    <!-- Unit Info -->
    <div class="portal-info-card">
        <div class="portal-info-card-header">Unit Information</div>
        <ul class="portal-info-list">
            <li>
                <span class="portal-info-label">Unit Number</span>
                <span class="portal-info-value" style="font-weight:700;"><?= e($lease['unit_number']) ?></span>
            </li>
            <li>
                <span class="portal-info-label">Type</span>
                <span class="portal-info-value"><?= e($lease['category']) ?></span>
            </li>
            <li>
                <span class="portal-info-label">Make / Model</span>
                <span class="portal-info-value"><?= e($lease['brand']) ?> <?= e($lease['model']) ?></span>
            </li>
            <li>
                <span class="portal-info-label">Year</span>
                <span class="portal-info-value"><?= e((string)($lease['year'] ?? '—')) ?></span>
            </li>
            <?php if ($lease['samsara_vehicle_url']): ?>
            <li>
                <span class="portal-info-label">GPS Tracking</span>
                <span class="portal-info-value">
                    <a href="<?= e($lease['samsara_vehicle_url']) ?>" target="_blank" rel="noopener" class="portal-form-link">Track Live</a>
                </span>
            </li>
            <?php endif; ?>
        </ul>
    </div>

</div>

<!-- ── S-PORTAL-MILEAGE-MODEL-B: Model B mileage section ───────
     Renders per the D135 three-configuration matrix:
       precharge   → "Mileage Precharge" card (paid / credits applied
                     / balance remaining + refund block + drawdown
                     history)
       usage_only  → "Mileage Usage" card (rate + usage history)
       null (rate=0) → no section rendered
     The card surface is read-only — portal is a customer-facing view
     only. Drawdown history is bounded to non-void disclosed invoices
     (status IN ('sent','partially_paid','paid','overdue')) so customers
     don't see drafts. Last 5 invoices per D-D. -->
<?php if ($mileageConfig === 'precharge'): ?>
<div class="portal-section">
    <div class="portal-section-header">
        <h2 class="portal-section-title">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/>
            </svg>
            Mileage Precharge
        </h2>
    </div>
    <div class="portal-section-body">
        <!-- Summary tiles: paid / credits applied / balance remaining -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:16px; margin-bottom:14px;">
            <div>
                <div class="portal-info-label" style="font-size:0.75rem; text-transform:uppercase;">Precharge paid</div>
                <div class="font-mono" style="font-size:1.125rem;">
                    <?= e(format_currency($prechargeData['paid'])) ?>
                </div>
            </div>
            <div>
                <div class="portal-info-label" style="font-size:0.75rem; text-transform:uppercase;">Credits applied to date</div>
                <div class="font-mono" style="font-size:1.125rem; font-weight:700;">
                    <?= e(format_currency($prechargeData['credits_applied'])) ?>
                </div>
            </div>
            <div>
                <div class="portal-info-label" style="font-size:0.75rem; text-transform:uppercase;">Balance remaining</div>
                <div class="font-mono" style="font-size:1.125rem;">
                    <?= e(format_currency($prechargeData['balance'])) ?>
                </div>
            </div>
        </div>

        <?php if ($refundData): ?>
            <!-- Refund block — visible only after lease close + refund method chosen -->
            <?php
            $refundLabel = '';
            $refundColor = '';
            if ($refundData['method'] === 'credit') {
                $refundLabel = 'Credit applied';
                $refundColor = '#0369a1';
            } elseif ($refundData['method'] === 'cash' && empty($refundData['settled_at'])) {
                $refundLabel = 'Cash refund pending';
                $refundColor = '#b45309';
            } else {
                $refundLabel = 'Cash refund issued';
                $refundColor = '#15803d';
            }
            ?>
            <div style="margin-top:12px; padding:10px 12px; background:var(--bg-surface-2); border-left:3px solid <?= e($refundColor) ?>; border-radius:4px;">
                <span style="color:<?= e($refundColor) ?>; font-weight:600; font-size:0.875rem;">
                    <?= e($refundLabel) ?>:
                </span>
                <span class="font-mono" style="font-weight:700; font-size:0.9375rem;">
                    <?= e(format_currency($refundData['amount'])) ?>
                </span>
                <?php if ($refundData['method'] === 'credit' && $refundData['credit_note_number']): ?>
                    <span style="color:var(--text-secondary); font-size:0.8125rem;">
                        · Credit note <?= e($refundData['credit_note_number']) ?>
                        <?php if ($refundData['issued_at']): ?>
                            issued on <?= e(format_date($refundData['issued_at'])) ?>
                        <?php endif; ?>
                    </span>
                <?php elseif ($refundData['method'] === 'cash' && !empty($refundData['settled_at'])): ?>
                    <span style="color:var(--text-secondary); font-size:0.8125rem;">
                        · Paid on <?= e(format_date($refundData['settled_at'])) ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($mileageHistory)): ?>
            <!-- Drawdown history — last 5 disclosed invoices with mileage_usage -->
            <div style="margin-top:16px;">
                <div class="portal-info-label" style="font-size:0.75rem; text-transform:uppercase; margin-bottom:8px;">Recent drawdown history</div>
                <div class="table-responsive">
                    <table class="portal-table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Period</th>
                                <th class="text-right">Distance</th>
                                <th class="text-right">Charge</th>
                                <th class="text-right">Credit applied</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mileageHistory as $row): ?>
                            <tr>
                                <td><a href="<?= e(base_url('portal/invoices/view?id=' . $row['invoice_id'])) ?>" class="portal-table-link"><?= e($row['invoice_number']) ?></a></td>
                                <td class="font-mono" style="font-size:0.8125rem;">
                                    <?= e(format_date($row['billing_period_start'])) ?>
                                    <span style="color:var(--text-secondary);">→</span>
                                    <?= e(format_date(ff_invoice_display_period_end($row + $_leasePeriodCtx))) ?>
                                </td>
                                <?php /* ili.quantity is ALREADY stored in the lease's display unit (the engine
                                         renders mileage lines via ff_mileage_line_display) — do NOT re-convert. */ ?>
                                <td class="text-right font-mono"><?= e(number_format((float) $row['km'], 2)) ?> <?= e(ff_mileage_unit_label($lease)) ?></td>
                                <td class="text-right font-mono"><?= e(format_currency($row['usage_amount'])) ?></td>
                                <td class="text-right font-mono" style="color:#0369a1;">
                                    <?= $row['credit_amount'] !== null ? e(format_currency($row['credit_amount'])) : '—' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($mileageConfig === 'usage_only'): ?>
<div class="portal-section">
    <div class="portal-section-header">
        <h2 class="portal-section-title">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0 1 12 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 1.5v-1.5m0 0c0-.621.504-1.125 1.125-1.125m0 0h7.5"/>
            </svg>
            Mileage Usage
        </h2>
    </div>
    <div class="portal-section-body">
        <!-- Rate row — read-only customer view of the per-km charge -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:16px; margin-bottom:14px;">
            <div>
                <div class="portal-info-label" style="font-size:0.75rem; text-transform:uppercase;">Rate</div>
                <div class="font-mono" style="font-size:1.125rem;">
                    <?= e(format_currency($lease['mileage_rate'] ?? $mileageRateKm)) ?>/<?= e(ff_mileage_unit_label($lease, true)) ?>
                </div>
            </div>
        </div>

        <?php if (!empty($mileageHistory)): ?>
            <!-- Usage history — last 5 disclosed invoices with mileage_usage -->
            <div style="margin-top:8px;">
                <div class="portal-info-label" style="font-size:0.75rem; text-transform:uppercase; margin-bottom:8px;">Recent usage</div>
                <div class="table-responsive">
                    <table class="portal-table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Period</th>
                                <th class="text-right">Distance</th>
                                <th class="text-right">Charge</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mileageHistory as $row): ?>
                            <tr>
                                <td><a href="<?= e(base_url('portal/invoices/view?id=' . $row['invoice_id'])) ?>" class="portal-table-link"><?= e($row['invoice_number']) ?></a></td>
                                <td class="font-mono" style="font-size:0.8125rem;">
                                    <?= e(format_date($row['billing_period_start'])) ?>
                                    <span style="color:var(--text-secondary);">→</span>
                                    <?= e(format_date(ff_invoice_display_period_end($row + $_leasePeriodCtx))) ?>
                                </td>
                                <?php /* ili.quantity is ALREADY stored in the lease's display unit (the engine
                                         renders mileage lines via ff_mileage_line_display) — do NOT re-convert. */ ?>
                                <td class="text-right font-mono"><?= e(number_format((float) $row['km'], 2)) ?> <?= e(ff_mileage_unit_label($lease)) ?></td>
                                <td class="text-right font-mono"><?= e(format_currency($row['usage_amount'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <p style="margin:0; color:var(--text-secondary); font-size:0.875rem;">
                No mileage usage recorded yet. Mileage charges will appear here once invoices begin reporting distance.
            </p>
        <?php endif; ?>
    </div>
</div>
<?php endif; /* end mileageConfig === 'precharge'|'usage_only' */ ?>


<!-- Invoices for this lease -->
<div class="portal-section">
    <div class="portal-section-header">
        <h2 class="portal-section-title">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/></svg>
            Invoices
        </h2>
    </div>
    <div class="portal-section-body--flush">
        <?php if (empty($invoices)): ?>
            <div class="portal-empty">
                <p class="portal-empty-title">No invoices yet</p>
                <p class="portal-empty-text">Invoices will appear here once generated.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
<table class="portal-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Due Date</th>
                        <th class="text-right">Amount</th>
                        <th class="text-right">Balance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                    <?php
                    $invBadge = match($inv['status']) {
                        'paid'           => 'badge-success',
                        'overdue'        => 'badge-danger',
                        'sent'           => 'badge-info',
                        'partially_paid' => 'badge-warning',
                        'void'           => 'badge-neutral line-through',
                        default          => 'badge-neutral',
                    };
                    ?>
                    <tr>
                        <td><a href="<?= e(base_url('portal/invoices/view?id=' . $inv['id'])) ?>" class="portal-table-link"><?= e($inv['invoice_number']) ?></a></td>
                        <td class="font-mono"><?= e(format_date($inv['invoice_date'])) ?></td>
                        <td class="font-mono"><?= e(format_date($inv['due_date'])) ?></td>
                        <td class="text-right font-mono"><?= e(format_currency($inv['total_amount'])) ?></td>
                        <td class="text-right font-mono" style="font-weight:600;"><?= e(format_currency($inv['balance_due'])) ?></td>
                        <td><span class="badge <?= e($invBadge) ?>"><?= e(ucfirst(str_replace('_', ' ', $inv['status']))) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
</div>
        <?php endif; ?>
    </div>
</div>

<!-- Documents -->
<?php if (!empty($documents)): ?>
<div class="portal-section">
    <div class="portal-section-header">
        <h2 class="portal-section-title">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
            Documents
        </h2>
    </div>
    <div class="portal-section-body--flush">
        <div class="table-responsive">
<table class="portal-table">
            <thead>
                <tr>
                    <th>Document</th>
                    <th>Type</th>
                    <th>Size</th>
                    <th>Uploaded</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($documents as $doc): ?>
                <tr>
                    <td style="font-weight:600;"><?= e($doc['title'] ?: $doc['file_name']) ?></td>
                    <td><span class="badge badge-neutral"><?= e(ucfirst(str_replace('_', ' ', $doc['document_type']))) ?></span></td>
                    <td class="font-mono" style="font-size:0.75rem;"><?= e(number_format((float)$doc['file_size_kb'])) ?> KB</td>
                    <td class="font-mono"><?= e(format_date($doc['uploaded_at'])) ?></td>
                    <td>
                        <a href="<?= e(base_url('api/v1/documents/serve.php?id=' . $doc['id'])) ?>" target="_blank" class="portal-form-link" style="font-size:0.8125rem;">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</div>
    </div>
</div>
<?php endif; ?>

<!-- Timeline -->
<?php if (!empty($timeline)): ?>
<div class="portal-section">
    <div class="portal-section-header">
        <h2 class="portal-section-title">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            Timeline
        </h2>
    </div>
    <div class="portal-timeline">
        <?php foreach ($timeline as $ev): ?>
        <?php
        $dotClass = match($ev['new_status']) {
            'active'    => 'portal-timeline-dot--green',
            'completed' => 'portal-timeline-dot--blue',
            'cancelled' => '',
            default     => 'portal-timeline-dot--amber',
        };
        ?>
        <div class="portal-timeline-item">
            <div class="portal-timeline-dot <?= e($dotClass) ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
            </div>
            <div class="portal-timeline-content">
                <div class="portal-timeline-title">Status changed to <?= e(ucfirst($ev['new_status'])) ?></div>
                <div class="portal-timeline-date"><?= e(format_datetime($ev['changed_at'])) ?></div>
                <?php if ($ev['notes']): ?>
                    <p style="margin:4px 0 0;font-size:0.8125rem;color:var(--text-secondary);"><?= e($ev['notes']) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
