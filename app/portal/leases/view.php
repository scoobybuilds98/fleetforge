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
            et.brand, et.model, et.category, eu.year
     FROM leases l
     JOIN equipment_units eu ON eu.id = l.equipment_unit_id
     JOIN equipment_templates et ON et.id = eu.template_id
     WHERE l.id = ? AND l.customer_id = ? AND l.deleted_at IS NULL
     AND eu.deleted_at IS NULL AND et.deleted_at IS NULL",
    [$leaseId, $cid]
);

if (!$lease) {
    header('Location: ' . base_url('portal/leases'));
    exit;
}

// Invoices for this lease
$invoices = db_select(
    "SELECT id, invoice_number, invoice_date, due_date, total_amount, balance_due, status
     FROM invoices
     WHERE lease_id = ? AND customer_id = ? AND deleted_at IS NULL
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

// ── S-LEASE-MILEAGE: customer-facing mileage usage ─────────
// Customer never sees manager-internal fields (review status, override
// notes, audit log). Show running totals and a per-month progress card
// for active leases; final summary for closed leases.
$mileageCard = null;
$totalAllowanceKm = (float) ($lease['estimated_mileage_km']
                              ?? $lease['estimated_mileage'] ?? 0);
if ($totalAllowanceKm > 0) {
    $allowanceMeta = \FleetForge\Billing\Mileage::monthlyAllowance($lease);

    // Cumulative usage for an active lease comes from the latest invoice's
    // cumulative_distance_km column; for a closed lease it's the canonical
    // lease.total_distance_km.
    $cumulativeKm = null;
    $latestInvoiceNumber = null;
    $latestInvoiceDate   = null;

    if ($lease['status'] === 'completed' && !empty($lease['total_distance_km'])) {
        $cumulativeKm = (float) $lease['total_distance_km'];
    } else {
        $latestInv = db_row(
            "SELECT cumulative_distance_km, invoice_number, invoice_date
               FROM invoices
              WHERE lease_id = ? AND deleted_at IS NULL
                AND cumulative_distance_km IS NOT NULL
              ORDER BY billing_period_end DESC, id DESC LIMIT 1",
            [$leaseId]
        );
        if ($latestInv) {
            $cumulativeKm = (float) $latestInv['cumulative_distance_km'];
            $latestInvoiceNumber = $latestInv['invoice_number'];
            $latestInvoiceDate   = $latestInv['invoice_date'];
        }
    }

    // Display unit conversion. Internal storage stays km — customer sees
    // miles on miles-leases via lease.km_to_miles_conversion (D-E).
    $displayUnit = (string) ($lease['mileage_unit'] ?? 'km');
    $kmToMiles   = (float) ($lease['km_to_miles_conversion'] ?? 0.621371);
    if ($kmToMiles <= 0) $kmToMiles = 0.621371;

    $convert = function (float $km) use ($displayUnit, $kmToMiles): float {
        return $displayUnit === 'miles' ? $km * $kmToMiles : $km;
    };

    $remainingKm = max(0.0, $totalAllowanceKm - (float) ($cumulativeKm ?? 0));
    $usagePct    = $totalAllowanceKm > 0
        ? min(100, max(0, ((float) ($cumulativeKm ?? 0) / $totalAllowanceKm) * 100))
        : 0;

    $mileageCard = [
        'total_allowance' => $convert($totalAllowanceKm),
        'used'            => $convert((float) ($cumulativeKm ?? 0)),
        'remaining'       => $convert($remainingKm),
        'monthly_allowance' => $convert((float) $allowanceMeta['allowance_km']),
        'usage_pct'       => round($usagePct, 1),
        'unit'            => $displayUnit,
        'latest_invoice'  => $latestInvoiceNumber,
        'latest_invoice_date' => $latestInvoiceDate,
        'is_closed'       => ($lease['status'] === 'completed'),
        'lease_months'    => $allowanceMeta['lease_months'],
    ];
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
                <span class="portal-info-value font-mono"><?= e(format_currency($lease['mileage_rate'] ?? '0.00')) ?>/km</span>
            </li>
            <li>
                <span class="portal-info-label">Mileage Allowance</span>
                <span class="portal-info-value font-mono"><?= $lease['estimated_mileage'] ? e(number_format((float)$lease['estimated_mileage'])) . ' km' : 'Unlimited' ?></span>
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

<!-- ── S-LEASE-MILEAGE: customer-facing mileage usage card ──── -->
<?php if ($mileageCard): ?>
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
        <?php if ($mileageCard['is_closed']): ?>
            <!-- ─── Closed lease: final summary ─── -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:16px;">
                <div>
                    <div class="portal-info-label" style="font-size:0.75rem; text-transform:uppercase;">Total driven</div>
                    <div class="font-mono" style="font-size:1.25rem; font-weight:700;">
                        <?= e(number_format($mileageCard['used'], 0)) ?> <?= e($mileageCard['unit']) ?>
                    </div>
                </div>
                <div>
                    <div class="portal-info-label" style="font-size:0.75rem; text-transform:uppercase;">Total allowance</div>
                    <div class="font-mono" style="font-size:1.25rem;">
                        <?= e(number_format($mileageCard['total_allowance'], 0)) ?> <?= e($mileageCard['unit']) ?>
                    </div>
                </div>
                <div>
                    <div class="portal-info-label" style="font-size:0.75rem; text-transform:uppercase;">Final status</div>
                    <div style="font-size:0.875rem; font-weight:600;">
                        <?php if ($mileageCard['used'] > $mileageCard['total_allowance']): ?>
                            <span style="color:#b45309;">Over by <?= e(number_format($mileageCard['used'] - $mileageCard['total_allowance'], 0)) ?> <?= e($mileageCard['unit']) ?></span>
                        <?php elseif ($mileageCard['used'] < $mileageCard['total_allowance']): ?>
                            <span style="color:#0369a1;">Under by <?= e(number_format($mileageCard['total_allowance'] - $mileageCard['used'], 0)) ?> <?= e($mileageCard['unit']) ?></span>
                        <?php else: ?>
                            <span style="color:#15803d;">Exact</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- ─── Active lease: progress bars + monthly snapshot ─── -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:14px;">
                <div>
                    <div class="portal-info-label" style="font-size:0.75rem; text-transform:uppercase;">Total allowance</div>
                    <div class="font-mono" style="font-size:1.125rem;">
                        <?= e(number_format($mileageCard['total_allowance'], 0)) ?> <?= e($mileageCard['unit']) ?>
                    </div>
                </div>
                <div>
                    <div class="portal-info-label" style="font-size:0.75rem; text-transform:uppercase;">Used to date</div>
                    <div class="font-mono" style="font-size:1.125rem; font-weight:700;">
                        <?= e(number_format($mileageCard['used'], 0)) ?> <?= e($mileageCard['unit']) ?>
                    </div>
                </div>
                <div>
                    <div class="portal-info-label" style="font-size:0.75rem; text-transform:uppercase;">Remaining</div>
                    <div class="font-mono" style="font-size:1.125rem;">
                        <?= e(number_format($mileageCard['remaining'], 0)) ?> <?= e($mileageCard['unit']) ?>
                    </div>
                </div>
            </div>

            <!-- Total-usage progress bar -->
            <div style="margin-bottom:8px;">
                <div style="display:flex; justify-content:space-between; align-items:baseline; font-size:0.8125rem; margin-bottom:4px;">
                    <span style="color:var(--text-secondary);">Total usage</span>
                    <span class="font-mono" style="font-weight:600;"><?= e($mileageCard['usage_pct']) ?>%</span>
                </div>
                <div style="background:#f1f5f9; height:8px; border-radius:4px; overflow:hidden;">
                    <div style="height:100%; background:<?= $mileageCard['usage_pct'] >= 100 ? '#dc2626' : ($mileageCard['usage_pct'] >= 80 ? '#f59e0b' : '#0ea5e9') ?>; width:<?= e($mileageCard['usage_pct']) ?>%;"></div>
                </div>
            </div>

            <!-- Monthly allowance reference -->
            <div style="font-size:0.8125rem; color:var(--text-secondary); margin-top:12px;">
                Monthly allowance:
                <span class="font-mono" style="font-weight:600; color:var(--text-primary);">
                    <?= e(number_format($mileageCard['monthly_allowance'], 0)) ?> <?= e($mileageCard['unit']) ?>
                </span>
                <?php if ($mileageCard['latest_invoice_date']): ?>
                · Last reading from invoice <?= e($mileageCard['latest_invoice']) ?> on <?= e(format_date($mileageCard['latest_invoice_date'])) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>


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
