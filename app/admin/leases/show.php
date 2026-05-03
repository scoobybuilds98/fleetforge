<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Detail Page
 *
 * @file        app/admin/leases/show.php
 * @description Lease detail view. Server-side hero render (contract number, status,
 *              customer, unit). Multi-tab Alpine.js component: Overview (all fields),
 *              Amendments (AMEND-1 — full implementation with record-amendment modal,
 *              sourced from the lease_amendments table), Status Log (from lease.status_log),
 *              Invoices, Damage Claims, Documents, Inspections, Mileage Logs.
 *              Activate and Close buttons shown based on current status and permissions.
 *              Alpine component: FF_LeaseDetail().
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/leases/show.php,
 *              api/v1/leases/activate.php, api/v1/leases/close.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D19 (optimistic lock on activate/close), D30 (asset_url), D32 (CSS classes)
 * @session     S007
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('leases', 'view');

$leaseId = clean_int($_GET['id'] ?? null);
if (!$leaseId) {
    header('Location: ' . base_url('leases'));
    exit;
}

// Server-side pre-load for hero render and page title
$lease = db_row(
    "SELECT l.id, l.contract_number, l.status, l.start_date, l.end_date,
            l.customer_id, l.equipment_unit_id,
            l.company_name_snapshot, l.customer_name_snapshot,
            l.unit_number_snapshot, l.template_name_snapshot,
            l.daily_rate, l.weekly_rate, l.monthly_rate, l.currency,
            l.outstanding_balance, l.total_invoiced, l.total_paid, l.po_number,
            l.created_at, l.closed_at,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_display_name,
            COALESCE(u.unit_number, l.unit_number_snapshot)   AS unit_display_number
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.id = ? AND l.deleted_at IS NULL",
    [$leaseId]
);

if (!$lease) {
    header('Location: ' . base_url('leases'));
    exit;
}

/** Returns badge CSS class for a given lease status. */
function leaseBadgeClass(string $status): string
{
    return match($status) {
        'active'    => 'badge-success',
        'pending'   => 'badge-info',
        'completed' => 'badge-neutral',
        'cancelled' => 'badge-danger',
        default     => 'badge-neutral',
    };
}

$pageTitle = $lease['contract_number'];
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('leases') ?>">Leases</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e($lease['contract_number']) ?></span>
</nav>
<div class="page-header">
    <div>
        <h1 class="page-header-title h4">
            <?= e($lease['contract_number']) ?>
            <span class="badge badge-no-dot <?= leaseBadgeClass($lease['status']) ?>"
                  style="margin-left:0.5rem;font-size:0.8125rem;">
                <?= ucfirst(e($lease['status'])) ?>
            </span>
        </h1>
        <div class="text-secondary text-sm">
            <?= e($lease['customer_display_name']) ?> &nbsp;·&nbsp;
            Unit <?= e($lease['unit_display_number']) ?>
            <?php if ($lease['template_name_snapshot']): ?>
            &nbsp;·&nbsp; <?= e($lease['template_name_snapshot']) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="page-header-actions">
        <?php if (can('customers', 'create')): /* EMAIL-1: send lease confirmation email */ ?>
        <button type="button"
                class="btn btn-secondary btn-sm"
                onclick="openEmailCompose({
                    customerId:   <?= (int)$lease['customer_id'] ?>,
                    templateSlug: <?= e(json_encode($lease['status'] === 'completed' ? 'lease_closing' : 'lease_activation')) ?>,
                    entityType:   'lease',
                    entityId:     <?= (int)$lease['id'] ?>
                })"
                title="Email customer about this lease">
            <?= heroicon('envelope', 'btn-icon') ?>
            Email Customer
        </button>
        <?php endif; ?>
        <?php if (can('leases', 'edit') && $lease['status'] === 'pending'): ?>
        <a href="<?= base_url('leases/edit') ?>?id=<?= $leaseId ?>" class="btn btn-secondary btn-sm">Edit</a>
        <?php endif; ?>
        <?php if (can('leases', 'delete') && $lease['status'] === 'pending'): ?>
        <button class="btn btn-danger btn-sm" onclick="if((await FF_Confirm.ask('Delete this pending lease? This cannot be undone.'))){FF_Api.post('<?= base_url('api/v1/leases/delete') ?>',{id:<?= $leaseId ?>}).then(r=>{if(r.success)window.location.href='<?= base_url('leases') ?>';else FF_Toast.error(r.error?.message||'Failed to delete');})}">Delete</button>
        <?php endif; ?>
    </div>
</div>

<!-- ── AI Summary Card ──────────────────────────────────────── -->
<?php
$aiSummaryEntityType = 'lease';
$aiSummaryEntityId   = $lease['id'];
$aiSummaryType       = 'lease_summary';
$aiSummaryTitle      = 'AI Lease Summary';
include FF_ROOT . '/includes/partials/ai-summary-card.php';
?>

<!-- ============================================================
     STATS ROW — server-rendered so tiles are always visible
     across all tabs, not just Overview.
     ============================================================ -->
<!-- TILES-2: lease-level financial tiles now drill to invoices / payments
     filtered by this specific lease. Currency tile remains display-only. -->
<div class="stat-grid" style="margin-bottom:24px;">

    <a class="stat-card"
       href="<?= base_url('invoices') ?>?lease_id=<?= (int)$lease['id'] ?>"
       style="cursor:pointer;text-decoration:none"
       title="View all invoices for this lease">
        <div class="stat-label">Total Invoiced</div>
        <div class="stat-value currency"><?= e(format_currency($lease['total_invoiced'] ?? 0)) ?></div>
    </a>

    <a class="stat-card"
       href="<?= base_url('payments') ?>?lease_id=<?= (int)$lease['id'] ?>"
       style="cursor:pointer;text-decoration:none"
       title="View all payments against this lease">
        <div class="stat-label">Total Paid</div>
        <div class="stat-value currency"><?= e(format_currency($lease['total_paid'] ?? 0)) ?></div>
    </a>

    <a class="stat-card<?= (float)($lease['outstanding_balance'] ?? 0) > 0 ? ' stat-card--danger' : '' ?>"
       href="<?= base_url('invoices') ?>?lease_id=<?= (int)$lease['id'] ?>&status=overdue"
       style="cursor:pointer;text-decoration:none"
       title="View outstanding invoices for this lease">
        <div class="stat-label">Outstanding</div>
        <div class="stat-value currency"><?= e(format_currency($lease['outstanding_balance'] ?? 0)) ?></div>
    </a>

    <!-- Currency is metadata, not a drill target — stays display-only -->
    <div class="stat-card">
        <div class="stat-label">Currency</div>
        <div class="stat-value"><?= e($lease['currency']) ?></div>
    </div>

</div>

<!-- ============================================================
     LEASE DETAIL — Alpine component
     ============================================================ -->
<div x-data="FF_LeaseDetail()" x-init="init()">

    <!-- ── S-LEASE-MILEAGE: starting-odometer banner ──────────────
         High-visibility prompt shown at the top of every active lease
         where the starting odometer is null. The retroactive capture
         widget lives inside the Mileage Tracking card lower down — this
         banner ensures the manager sees the missing value immediately
         on page load. Hidden once odometer_start_km is set OR when the
         lease is closed (cannot capture retroactively per D12).
         ──────────────────────────────────────────────────────────── -->
    <template x-if="lease && lease.status === 'active'
                    && (lease.odometer_start_km === null || lease.odometer_start_km === undefined)">
        <div class="alert alert-warning" style="margin-bottom:1rem;display:flex;align-items:center;gap:12px;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:20px;height:20px;flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
            </svg>
            <div style="flex:1;">
                <strong>Starting odometer not captured.</strong>
                Mileage tracking and per-period excess calculations will not run until a starting reading is recorded.
                Scroll to the <a href="#" @click.prevent="tab='overview';
                    document.getElementById('mileage-tracking-card')?.scrollIntoView({behavior:'smooth', block:'start'})"
                   style="text-decoration:underline;">Mileage Tracking card</a> on this page to enter it.
            </div>
        </div>
    </template>

    <!-- ── Action buttons (status-driven) ─────────────────────── -->
    <?php if (can('leases', 'edit')): ?>
    <div class="d-flex gap-2" style="margin-bottom:1.5rem;">

        <?php if ($lease['status'] === 'pending'): ?>
        <button class="btn btn-primary" @click="activate()" :disabled="actionInProgress">
            <span x-show="!activating">Activate Lease</span>
            <span x-show="activating">Activating…</span>
        </button>
        <?php endif; ?>

        <?php if ($lease['status'] === 'active'): ?>
        <button class="btn btn-warning" @click="openCloseModal()" :disabled="actionInProgress">
            Close Lease
        </button>
        <?php endif; ?>

        <template x-if="actionError">
            <div class="badge badge-danger badge-no-dot" style="padding:0.5rem 1rem;" x-text="actionError"></div>
        </template>

    </div>
    <?php endif; ?>

    <!-- ── TABS ──────────────────────────────────────────────────── -->
    <div class="tab-bar" role="tablist">
        <button class="tab-btn" :class="{ 'is-active': tab === 'overview' }"
                @click="tab = 'overview'" :aria-selected="tab === 'overview'" role="tab">Overview</button>
        <button class="tab-btn" :class="{ 'is-active': tab === 'status_log' }"
                @click="tab = 'status_log'" :aria-selected="tab === 'status_log'" role="tab">
            Status Log
            <span class="tab-badge" x-show="lease && lease.status_log && lease.status_log.length > 0"
                  x-text="lease && lease.status_log ? lease.status_log.length : ''"></span>
        </button>
        <button class="tab-btn" :class="{ 'is-active': tab === 'amendments' }"
                @click="tab = 'amendments'; loadAmendments()" :aria-selected="tab === 'amendments'" role="tab">
            Amendments
            <span class="tab-count" x-show="amendments.length > 0" x-text="amendments.length"></span>
        </button>
        <button class="tab-btn" :class="{ 'is-active': tab === 'invoices' }"
                @click="tab = 'invoices'; loadInvoices()" :aria-selected="tab === 'invoices'" role="tab">Invoices</button>
        <button class="tab-btn" :class="{ 'is-active': tab === 'damage_claims' }"
                @click="tab = 'damage_claims'; loadDamageClaims()" :aria-selected="tab === 'damage_claims'" role="tab">Damage Claims</button>
        <button class="tab-btn" :class="{ 'is-active': tab === 'mileage_logs' }"
                @click="tab = 'mileage_logs'; loadMileageLogs()" :aria-selected="tab === 'mileage_logs'" role="tab">Mileage Log</button>
        <button class="tab-btn" :class="{ 'is-active': tab === 'inspections' }"
                @click="tab = 'inspections'; loadInspections()" :aria-selected="tab === 'inspections'" role="tab">Inspections</button>
        <button class="tab-btn" :class="{ 'is-active': tab === 'documents' }"
                @click="tab = 'documents'; loadDocuments()" :aria-selected="tab === 'documents'" role="tab">Documents
            <span class="tab-badge" x-show="documents.length > 0" x-text="documents.length"></span>
        </button>
    </div>

    <!-- ── TAB: OVERVIEW ──────────────────────────────────────── -->
    <template x-if="tab === 'overview'">
        <div class="ff-tab-animated">
            <template x-if="loading">
                <div class="skeleton skeleton-row"></div>
            </template>
            <template x-if="!loading && lease">
                <div>
                    <!-- Lease details grid — grid-2 is defined in app.css -->
                    <div class="grid-2">

                        <div class="card">
                            <div class="card-header"><div class="card-title">Lease Details</div></div>
                            <div class="card-body">
                                <table class="table">
                                    <tbody>
                                        <tr><td class="text-secondary">Contract #</td><td class="font-mono" x-text="lease.contract_number"></td></tr>
                                        <tr><td class="text-secondary">Status</td>
                                            <td><span class="badge badge-no-dot" :class="statusBadgeClass(lease.status)"
                                                      x-text="lease.status.charAt(0).toUpperCase() + lease.status.slice(1)"></span></td>
                                        </tr>
                                        <tr><td class="text-secondary">Start Date</td><td x-text="formatDate(lease.start_date)"></td></tr>
                                        <tr><td class="text-secondary">End Date</td><td x-text="lease.end_date ? formatDate(lease.end_date) : 'Open-ended'"></td></tr>
                                        <tr x-show="lease.actual_return_date"><td class="text-secondary">Return Date</td><td x-text="formatDate(lease.actual_return_date)"></td></tr>
                                        <tr><td class="text-secondary">Billing Cycle</td><td x-text="lease.billing_cycle === 'monthly' ? 'Monthly' : 'On Close Only'"></td></tr>
                                        <!-- ADV-BILL-1: only show when activation generated a prepaid batch -->
                                        <tr x-show="(lease.advance_billing_periods || 0) > 0">
                                            <td class="text-secondary">Advance Billing</td>
                                            <td>
                                                <span x-text="lease.advance_billing_periods"></span>
                                                future period<span x-show="lease.advance_billing_periods != 1">s</span>
                                                prepaid at activation
                                                <span style="color:var(--color-text-muted,#6b7280);">
                                                    (Invoice 1 + <span x-text="lease.advance_billing_periods"></span> advance)
                                                </span>
                                            </td>
                                        </tr>
                                        <tr x-show="lease.po_number"><td class="text-secondary">PO Number</td><td class="font-mono" x-text="lease.po_number"></td></tr>
                                        <tr x-show="lease.next_billing_date"><td class="text-secondary">Next Billing</td><td x-text="formatDate(lease.next_billing_date)"></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header"><div class="card-title">Rates</div></div>
                            <div class="card-body">
                                <table class="table">
                                    <tbody>
                                        <tr><td class="text-secondary">Daily Rate</td><td class="font-mono" x-text="'$' + parseFloat(lease.daily_rate).toFixed(2)"></td></tr>
                                        <tr><td class="text-secondary">Weekly Rate</td><td class="font-mono" x-text="'$' + parseFloat(lease.weekly_rate).toFixed(2)"></td></tr>
                                        <tr><td class="text-secondary">Monthly Rate</td><td class="font-mono" x-text="'$' + parseFloat(lease.monthly_rate).toFixed(2)"></td></tr>
                                        <!-- S-LEASE-UNITS: dual-unit mileage rate + allowance — primary prominent,
                                             secondary muted with ≈ prefix, custom-conversion badge when factors
                                             differ from international standard. -->
                                        <tr x-show="parseFloat(lease.mileage_rate_km || lease.mileage_rate) > 0 || parseFloat(lease.mileage_rate_miles) > 0 || parseFloat(lease.estimated_mileage_km || lease.estimated_mileage) > 0 || parseFloat(lease.estimated_mileage_miles) > 0">
                                            <td class="text-secondary" style="vertical-align:top;padding-top:9px;">Mileage &amp; allowance</td>
                                            <td>
                                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                                                    <!-- Per-unit rate -->
                                                    <div>
                                                        <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:4px;letter-spacing:-0.005em;">Per-unit rate</div>
                                                        <template x-if="lease.mileage_unit === 'km'">
                                                            <div>
                                                                <div class="ff-show-primary"
                                                                     x-text="'$' + parseFloat(lease.mileage_rate_km || lease.mileage_rate || 0).toFixed(4) + ' / km'"></div>
                                                                <div class="ff-show-secondary"
                                                                     x-text="'≈ $' + parseFloat(lease.mileage_rate_miles || 0).toFixed(4) + ' / mile'"></div>
                                                            </div>
                                                        </template>
                                                        <template x-if="lease.mileage_unit !== 'km'">
                                                            <div>
                                                                <div class="ff-show-primary"
                                                                     x-text="'$' + parseFloat(lease.mileage_rate_miles || lease.mileage_rate || 0).toFixed(4) + ' / mile'"></div>
                                                                <div class="ff-show-secondary"
                                                                     x-text="'≈ $' + parseFloat(lease.mileage_rate_km || 0).toFixed(4) + ' / km'"></div>
                                                            </div>
                                                        </template>
                                                    </div>
                                                    <!-- Allowance -->
                                                    <div>
                                                        <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:4px;letter-spacing:-0.005em;">Allowance</div>
                                                        <template x-if="lease.mileage_unit === 'km'">
                                                            <div>
                                                                <div class="ff-show-primary"
                                                                     x-text="parseFloat(lease.estimated_mileage_km || lease.estimated_mileage || 0).toLocaleString('en-CA', {maximumFractionDigits:0}) + ' km'"></div>
                                                                <div class="ff-show-secondary"
                                                                     x-text="'≈ ' + parseFloat(lease.estimated_mileage_miles || 0).toLocaleString('en-CA', {maximumFractionDigits:0}) + ' miles'"></div>
                                                            </div>
                                                        </template>
                                                        <template x-if="lease.mileage_unit !== 'km'">
                                                            <div>
                                                                <div class="ff-show-primary"
                                                                     x-text="parseFloat(lease.estimated_mileage_miles || lease.estimated_mileage || 0).toLocaleString('en-CA', {maximumFractionDigits:0}) + ' miles'"></div>
                                                                <div class="ff-show-secondary"
                                                                     x-text="'≈ ' + parseFloat(lease.estimated_mileage_km || 0).toLocaleString('en-CA', {maximumFractionDigits:0}) + ' km'"></div>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                                <div class="ff-show-caption">
                                                    <span x-text="'1 km = ' + Number(lease.km_to_miles_conversion || 0.621371).toFixed(6) + ' mi · 1 mile = ' + Number(lease.miles_to_km_conversion || 1.609344).toFixed(6) + ' km'"></span>
                                                    <span class="ff-badge-custom"
                                                          x-show="Math.abs(Number(lease.km_to_miles_conversion || 0.621371) - 0.621371) > 0.0001
                                                               || Math.abs(Number(lease.miles_to_km_conversion || 1.609344) - 1.609344) > 0.0001">
                                                        Custom conversion
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr x-show="lease.discount_type !== 'none'">
                                            <td class="text-secondary">Discount</td>
                                            <td x-text="lease.discount_type === 'percentage'
                                                ? parseFloat(lease.discount_value).toFixed(2) + '%'
                                                : '$' + parseFloat(lease.discount_value).toFixed(2)"></td>
                                        </tr>
                                        <tr x-show="lease.rate_notes"><td class="text-secondary">Rate Notes</td><td class="text-sm" x-text="lease.rate_notes"></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header"><div class="card-title">Customer &amp; Unit</div></div>
                            <div class="card-body">
                                <table class="table">
                                    <tbody>
                                        <tr><td class="text-secondary">Customer</td>
                                            <td><a :href="'<?= base_url('customers/show') ?>?id=' + lease.customer_id"
                                                   class="link" x-text="lease.customer_display_name || lease.company_name_snapshot"></a></td>
                                        </tr>
                                        <tr><td class="text-secondary">Unit</td>
                                            <td><a :href="'<?= base_url('equipment/show') ?>?id=' + lease.equipment_unit_id"
                                                   class="font-mono link" x-text="lease.unit_display_number || lease.unit_number_snapshot"></a></td>
                                        </tr>
                                        <tr x-show="lease.template_name_snapshot"><td class="text-secondary">Template</td><td x-text="lease.template_name_snapshot"></td></tr>
                                        <tr><td class="text-secondary">GST Exempt</td><td x-text="lease.gst_exempt ? 'Yes' : 'No'"></td></tr>
                                        <tr><td class="text-secondary">PST Exempt</td><td x-text="lease.pst_exempt ? 'Yes' : 'No'"></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="card" x-show="lease.notes || lease.internal_notes">
                            <div class="card-header"><div class="card-title">Notes</div></div>
                            <div class="card-body">
                                <div x-show="lease.notes" class="form-group">
                                    <div class="form-label text-secondary" style="font-size:0.8125rem;">Notes</div>
                                    <div class="text-sm" x-text="lease.notes"></div>
                                </div>
                                <div x-show="lease.internal_notes" class="form-group">
                                    <div class="form-label text-secondary" style="font-size:0.8125rem;">Internal Notes</div>
                                    <div class="text-sm" x-text="lease.internal_notes"></div>
                                </div>
                            </div>
                        </div>

                        <!-- ── SAMSARA-3: Odometer & Distance summary ────
                             Shows starting odometer, latest reading from
                             invoices, and total km driven. If no starting
                             odometer captured, exposes a retroactive
                             capture flow (Fetch from Samsara or manual
                             entry) against /api/v1/leases/update_odometer. -->
                        <div class="card" id="mileage-tracking-card" style="grid-column: span 2;">
                            <div class="card-header"><div class="card-title">Odometer &amp; Distance</div></div>
                            <div class="card-body">
                                <!-- Has starting odometer → full summary -->
                                <template x-if="lease.odometer_start_km !== null && lease.odometer_start_km !== undefined">
                                    <div>
                                        <div class="stat-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;">
                                            <div>
                                                <div class="stat-label">Starting Odometer</div>
                                                <div class="stat-value font-mono"
                                                     x-text="Number(lease.odometer_start_km).toLocaleString('en-CA', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' km'"></div>
                                                <div class="text-xs text-secondary" style="margin-top:2px;">
                                                    <template x-if="lease.odometer_start_source === 'gps'">
                                                        <span>captured via GPS
                                                            <template x-if="lease.odometer_start_fetched_at">
                                                                <span>on <span x-text="formatDate(lease.odometer_start_fetched_at.slice(0,10))"></span></span>
                                                            </template>
                                                        </span>
                                                    </template>
                                                    <template x-if="lease.odometer_start_source === 'manual'">
                                                        <span>entered manually</span>
                                                    </template>
                                                </div>
                                            </div>
                                            <div>
                                                <div class="stat-label">Latest Recorded</div>
                                                <div class="stat-value font-mono"
                                                     x-text="lease.latest_invoice_odometer_km !== null && lease.latest_invoice_odometer_km !== undefined
                                                        ? Number(lease.latest_invoice_odometer_km).toLocaleString('en-CA', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' km'
                                                        : '—'"></div>
                                                <template x-if="lease.latest_invoice_number_for_odo">
                                                    <div class="text-xs text-secondary" style="margin-top:2px;">
                                                        from <a :href="`<?= base_url('invoices/show') ?>?id=${lease.latest_invoice_id_for_odo}`" class="link" x-text="lease.latest_invoice_number_for_odo"></a>
                                                    </div>
                                                </template>
                                                <template x-if="!lease.latest_invoice_number_for_odo">
                                                    <div class="text-xs text-secondary" style="margin-top:2px;">No invoices yet with odometer data</div>
                                                </template>
                                            </div>
                                            <div>
                                                <div class="stat-label">Total KM Driven</div>
                                                <div class="stat-value font-mono"
                                                     x-text="lease.latest_invoice_cumulative_km !== null && lease.latest_invoice_cumulative_km !== undefined
                                                        ? Number(lease.latest_invoice_cumulative_km).toLocaleString('en-CA', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' km'
                                                        : '—'"></div>
                                                <div class="text-xs text-secondary" style="margin-top:2px;">since lease start</div>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                <!-- No starting odometer → retroactive capture flow -->
                                <template x-if="lease.odometer_start_km === null || lease.odometer_start_km === undefined">
                                    <div>
                                        <div class="text-sm text-secondary" style="margin-bottom:0.75rem;">
                                            Starting Odometer: <strong>Not recorded</strong>
                                        </div>

                                        <!-- Capture form (only enabled for non-closed leases) -->
                                        <template x-if="lease.status === 'active' || lease.status === 'pending'">
                                            <div style="display:flex;gap:0.5rem;align-items:flex-start;flex-wrap:wrap;">
                                                <input type="number"
                                                       class="form-control font-mono"
                                                       x-model="retroOdo.value"
                                                       step="0.01" min="0"
                                                       placeholder="e.g. 1234.56"
                                                       style="flex:1 1 180px;max-width:240px;">
                                                <button type="button" class="btn btn-secondary"
                                                        x-show="lease.samsara_vehicle_id"
                                                        @click="fetchRetroOdometer()"
                                                        :disabled="retroOdo.fetching">
                                                    <span x-show="!retroOdo.fetching">Fetch from Samsara</span>
                                                    <span x-show="retroOdo.fetching">Fetching…</span>
                                                </button>
                                                <button type="button" class="btn btn-primary"
                                                        @click="saveRetroOdometer()"
                                                        :disabled="retroOdo.saving || !retroOdo.value">
                                                    <span x-show="!retroOdo.saving">Save</span>
                                                    <span x-show="retroOdo.saving">Saving…</span>
                                                </button>
                                            </div>
                                        </template>
                                        <template x-if="lease.status !== 'active' && lease.status !== 'pending'">
                                            <div class="text-xs text-secondary">
                                                This lease is closed. Starting odometer cannot be captured retroactively.
                                            </div>
                                        </template>

                                        <div x-show="retroOdo.banner"
                                             :class="retroOdo.banner && retroOdo.banner.type === 'success' ? 'alert alert-success' : 'alert alert-warning'"
                                             style="margin-top:0.75rem;padding:0.5rem 0.75rem;font-size:0.875rem;"
                                             x-text="retroOdo.banner && retroOdo.banner.message"></div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- ── SAMSARA-1: Live GPS card ─────────────────
                             Only renders if the underlying equipment unit
                             has been mapped to a Samsara vehicle. Data is
                             read-through from equipment_units (cron-cached)
                             so this card adds zero latency to the page. -->
                        <div class="card" x-show="lease.samsara_vehicle_id" style="grid-column: span 2;">
                            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                                <div class="card-title">
                                    Live GPS &amp; Telemetry
                                    <span class="ff-live-badge" x-show="samsaraIsOnline()" style="margin-left:0.5rem;">
                                        <span class="ff-live-dot"></span>Live
                                    </span>
                                    <span class="badge badge-no-dot badge-neutral" x-show="!samsaraIsOnline()" style="margin-left:0.5rem;">Offline</span>
                                </div>
                                <a :href="'<?= base_url('equipment/show') ?>?id=' + lease.equipment_unit_id + '&tab=tracking'"
                                   class="btn btn-sm btn-ghost">Open Tracking →</a>
                            </div>
                            <div class="card-body">
                                <div class="stat-grid" style="grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;">
                                    <div>
                                        <div class="stat-label">Last Location</div>
                                        <div class="text-sm"
                                             x-text="lease.samsara_last_location_address || (lease.samsara_last_location_lat !== null
                                                ? lease.samsara_last_location_lat.toFixed(5) + ', ' + lease.samsara_last_location_lng.toFixed(5)
                                                : '—')"></div>
                                    </div>
                                    <div>
                                        <div class="stat-label">Speed</div>
                                        <div class="stat-value font-mono"
                                             x-text="lease.samsara_last_speed_kph !== null
                                                ? lease.samsara_last_speed_kph.toFixed(1) + ' km/h'
                                                : '—'"></div>
                                    </div>
                                    <div>
                                        <div class="stat-label">Odometer</div>
                                        <div class="stat-value font-mono"
                                             x-text="lease.samsara_odometer_km !== null
                                                ? lease.samsara_odometer_km.toLocaleString('en-CA',{maximumFractionDigits:0}) + ' km'
                                                : '—'"></div>
                                    </div>
                                    <div>
                                        <div class="stat-label">Battery</div>
                                        <div class="stat-value font-mono"
                                             x-text="lease.samsara_battery_pct !== null
                                                ? lease.samsara_battery_pct + '%' + (lease.samsara_battery_charging ? ' ⚡' : '')
                                                : '—'"></div>
                                    </div>
                                </div>
                                <div class="text-xs text-secondary" style="margin-top:0.75rem;">
                                    Vehicle: <span class="font-mono" x-text="lease.samsara_vehicle_name || lease.samsara_vehicle_id"></span>
                                    &nbsp;·&nbsp; Last connected: <span x-text="formatRelative(lease.samsara_last_connected_at)"></span>
                                    &nbsp;·&nbsp; Last synced: <span x-text="formatRelative(lease.samsara_last_synced_at)"></span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </template>
        </div>
    </template>

    <!-- ── TAB: STATUS LOG ────────────────────────────────────── -->
    <template x-if="tab === 'status_log'">
        <div class="card ff-tab-animated">
            <div class="card-header"><div class="card-title">Status Log</div></div>
            <template x-if="!lease || !lease.status_log || lease.status_log.length === 0">
                <div class="empty-state">
                    <p class="empty-state-title">No status history</p>
                    <p class="empty-state-text">Status changes will appear here.</p>
                </div>
            </template>
            <template x-if="lease && lease.status_log && lease.status_log.length > 0">
                <div class="tab-table-container">
                    <table class="table">
                        <thead>
                            <tr><th>When</th><th>Transition</th><th>By</th><th>Notes</th></tr>
                        </thead>
                        <tbody>
                            <template x-for="entry in lease.status_log" :key="entry.id">
                                <tr>
                                    <td class="text-sm text-secondary" x-text="formatDateTime(entry.changed_at)"></td>
                                    <td>
                                        <span class="badge badge-no-dot" :class="statusBadgeClass(entry.old_status)"
                                              x-text="entry.old_status || 'created'" style="margin-right:0.25rem;"></span>
                                        →
                                        <span class="badge badge-no-dot" :class="statusBadgeClass(entry.new_status)"
                                              x-text="entry.new_status" style="margin-left:0.25rem;"></span>
                                    </td>
                                    <td class="text-sm" x-text="entry.changed_by"></td>
                                    <td class="text-sm text-secondary" x-text="entry.notes || '—'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </template>

    <!-- ── TAB: AMENDMENTS (AMEND-1) ─────────────────────────── -->
    <template x-if="tab === 'amendments'">
        <div class="card ff-tab-animated">

            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <div class="card-title">Amendments</div>
                <?php if (can('leases', 'edit')): ?>
                <button class="btn btn-primary btn-sm"
                        @click="amendModal.open = true; prefillAmendOldValues()">
                    + Record Amendment
                </button>
                <?php endif; ?>
            </div>

            <!-- Loading -->
            <div x-show="amendmentsLoading" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading amendments…</span>
            </div>

            <!-- Empty -->
            <div x-show="!amendmentsLoading && amendments.length === 0" class="empty-state">
                <p class="empty-state-title">No amendments recorded</p>
                <p class="empty-state-text">Use the button above to record rate changes, date extensions, or other lease modifications.</p>
            </div>

            <!-- Table -->
            <div x-show="!amendmentsLoading && amendments.length > 0" class="tab-table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Previous</th>
                            <th>Updated to</th>
                            <th>Recorded by</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="a in amendments" :key="a.id">
                            <tr>
                                <td>
                                    <span class="badge badge-neutral badge-no-dot"
                                          x-text="amendTypeLabel(a.amendment_type)"></span>
                                </td>
                                <td x-text="a.description" style="max-width:280px;word-break:break-word;"></td>
                                <td class="text-sm text-secondary" style="max-width:160px;word-break:break-word;"
                                    x-text="a.old_values ? formatAmendValues(a.old_values) : '—'"></td>
                                <td class="text-sm" style="max-width:160px;word-break:break-word;"
                                    x-text="a.new_values ? formatAmendValues(a.new_values) : '—'"></td>
                                <td class="text-sm text-secondary" x-text="a.created_by_name"></td>
                                <td class="text-sm text-secondary" x-text="formatDateTime(a.created_at)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

        </div>
    </template>

    <!-- ── AMENDMENT RECORD MODAL (AMEND-1) ─────────────────── -->
    <template x-if="amendModal.open">
        <div class="modal-overlay" @click.self="amendModal.open = false">
            <div class="modal modal-md" @click.stop>
                <div class="modal-header">
                    <h3 class="modal-title">Record Amendment</h3>
                    <button class="modal-close-btn" @click="amendModal.open = false" aria-label="Close">
                        <?= heroicon('x-mark', 'modal-icon') ?>
                    </button>
                </div>
                <div class="modal-body">

                    <!-- Error -->
                    <div x-show="amendModal.error" class="alert alert-danger" style="margin-bottom:12px;">
                        <span x-text="amendModal.error"></span>
                    </div>

                    <!-- Type -->
                    <div class="form-group">
                        <label class="form-label">Amendment Type <span class="required">*</span></label>
                        <select class="form-control" x-model="amendModal.amendment_type"
                                @change="prefillAmendOldValues()">
                            <option value="rate_change">Rate Change</option>
                            <option value="date_extension">Date Extension</option>
                            <option value="unit_swap">Unit Swap</option>
                            <option value="add_on">Add-On</option>
                            <option value="tax_change">Tax Change</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <!-- Description -->
                    <div class="form-group">
                        <label class="form-label">Description <span class="required">*</span></label>
                        <textarea class="form-control"
                                  x-model="amendModal.description"
                                  rows="3"
                                  placeholder="Briefly describe what changed and why…"
                                  maxlength="2000"></textarea>
                        <div class="form-hint" x-text="amendModal.description.length + '/2000'"></div>
                    </div>

                    <!-- Old / New values side by side -->
                    <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group">
                            <label class="form-label">Previous Values
                                <span class="form-hint" style="font-weight:400;">(optional)</span>
                            </label>
                            <textarea class="form-control"
                                      x-model="amendModal.old_values_text"
                                      rows="4"
                                      placeholder="e.g. Monthly rate: $2,200&#10;End date: 2027-02-08"
                                      style="font-family:ui-monospace,monospace;font-size:12px;"></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">New Values
                                <span class="form-hint" style="font-weight:400;">(optional)</span>
                            </label>
                            <textarea class="form-control"
                                      x-model="amendModal.new_values_text"
                                      rows="4"
                                      placeholder="e.g. Monthly rate: $2,400&#10;End date: 2027-08-08"
                                      style="font-family:ui-monospace,monospace;font-size:12px;"></textarea>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost btn-sm" @click="amendModal.open = false">Cancel</button>
                    <button class="btn btn-primary btn-sm"
                            @click="submitAmendment()"
                            :disabled="amendModal.saving">
                        <span x-show="!amendModal.saving">Save Amendment</span>
                        <span x-show="amendModal.saving">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <!-- ── TAB: INVOICES ─────────────────────────────────────────── -->
    <div x-show="tab === 'invoices'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to" class="card">
        <div class="card-header"><div class="card-title">Invoices</div></div>

        <!-- Filter bar -->
        <div class="tab-filter-bar">
            <select class="form-control form-control-sm" x-model="invoicesFilters.status" @change="applyInvoicesFilters()" style="width:auto;">
                <option value="">All Statuses</option>
                <option value="draft">Draft</option>
                <option value="sent">Sent</option>
                <option value="paid">Paid</option>
                <option value="partially_paid">Partially Paid</option>
                <option value="overdue">Overdue</option>
                <option value="void">Void</option>
            </select>
            <select class="form-control form-control-sm" x-model="invoicesFilters.sort" @change="applyInvoicesFilters()" style="width:auto;">
                <option value="created_at">Sort: Date</option>
                <option value="total_amount">Sort: Amount</option>
                <option value="invoice_number">Sort: Invoice #</option>
            </select>
            <select class="form-control form-control-sm" x-model="invoicesFilters.dir" @change="applyInvoicesFilters()" style="width:auto;">
                <option value="DESC">Newest First</option>
                <option value="ASC">Oldest First</option>
            </select>
        </div>

        <div x-show="invoicesLoading" class="card-body" style="text-align:center;padding:32px;">
            <span class="text-secondary">Loading invoices…</span>
        </div>
        <div x-show="!invoicesLoading && invoices.length === 0" class="empty-state">
            <p class="empty-state-title">No invoices yet</p>
            <p class="empty-state-text">Invoices are generated automatically on activation and close.</p>
        </div>
        <div x-show="!invoicesLoading && invoices.length > 0" class="tab-table-container">
            <table class="table">
                <thead><tr>
                    <th>Invoice #</th>
                    <th>Date</th>
                    <th>Period</th>
                    <th>Status</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Balance Due</th>
                    <th></th>
                </tr></thead>
                <tbody>
                    <template x-for="inv in invoices" :key="inv.id">
                        <tr>
                            <td class="font-mono" x-text="inv.invoice_number"></td>
                            <td x-text="inv.invoice_date"></td>
                            <td x-text="inv.billing_period_start + ' → ' + inv.billing_period_end"></td>
                            <td><span class="badge" :class="invBadgeClass(inv.status)" x-text="inv.status"></span></td>
                            <td class="text-right font-mono" x-text="'$' + parseFloat(inv.total_amount).toFixed(2)"></td>
                            <td class="text-right font-mono" x-text="'$' + parseFloat(inv.balance_due).toFixed(2)"></td>
                            <td><a :href="'<?= base_url('invoices/show') ?>?id=' + inv.id" class="btn btn-sm btn-secondary">View</a></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div x-show="invoices.length > 0" class="tab-table-footer">
            <span x-text="'Showing ' + invoices.length + ' of ' + invoicesTotal"></span>
            <button x-show="invoices.length < invoicesTotal" class="btn btn-sm btn-ghost"
                    @click="loadMoreInvoices()" :disabled="invoicesLoading">Load more</button>
        </div>
    </div>

    <!-- ── TAB: DAMAGE CLAIMS ────────────────────────────────────── -->
    <div x-show="tab === 'damage_claims'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to" class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <div class="card-title">Damage Claims</div>
            <?php if (can('maintenance', 'create')): ?>
            <a href="<?= base_url('damage_claims/create') ?>?lease_id=<?= $leaseId ?>"
               class="btn btn-primary btn-sm">+ New Claim</a>
            <?php endif; ?>
        </div>

        <!-- Filter bar -->
        <div class="tab-filter-bar">
            <select class="form-control form-control-sm" x-model="damageClaimsFilters.severity" @change="applyDamageClaimsFilters()" style="width:auto;">
                <option value="">All Severities</option>
                <option value="minor">Minor</option>
                <option value="moderate">Moderate</option>
                <option value="major">Major</option>
                <option value="total_loss">Total Loss</option>
            </select>
            <select class="form-control form-control-sm" x-model="damageClaimsFilters.status" @change="applyDamageClaimsFilters()" style="width:auto;">
                <option value="">All Statuses</option>
                <option value="reported">Reported</option>
                <option value="assessed">Assessed</option>
                <option value="repair_ordered">Repair Ordered</option>
                <option value="invoiced">Invoiced</option>
                <option value="resolved">Resolved</option>
                <option value="written_off">Written Off</option>
            </select>
            <select class="form-control form-control-sm" x-model="damageClaimsFilters.dir" @change="applyDamageClaimsFilters()" style="width:auto;">
                <option value="DESC">Newest First</option>
                <option value="ASC">Oldest First</option>
            </select>
        </div>

        <div x-show="damageClaimsLoading" class="card-body" style="text-align:center;padding:32px;">
            <span class="text-secondary">Loading damage claims…</span>
        </div>
        <div x-show="!damageClaimsLoading && damageClaims.length === 0" class="empty-state">
            <p class="empty-state-title">No damage claims</p>
            <p class="empty-state-text">No damage claims have been filed against this lease.</p>
        </div>
        <div x-show="!damageClaimsLoading && damageClaims.length > 0" class="tab-table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Claim #</th>
                        <th>Severity</th>
                        <th>Status</th>
                        <th style="text-align:right;">Est. Cost</th>
                        <th>Reported</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="dc in damageClaims" :key="dc.id">
                        <tr>
                            <td class="font-mono" x-text="dc.claim_number"></td>
                            <td>
                                <span class="badge" :class="dcSeverityBadge(dc.severity)"
                                      x-text="dcSeverityLabel(dc.severity)"></span>
                            </td>
                            <td>
                                <span class="badge" :class="dcStatusBadge(dc.status)"
                                      x-text="dcStatusLabel(dc.status)"></span>
                            </td>
                            <td class="font-mono" style="text-align:right;"
                                x-text="dc.estimated_repair_cost ? '$' + parseFloat(dc.estimated_repair_cost).toLocaleString('en-CA', {minimumFractionDigits:2}) : '—'"></td>
                            <td x-text="dc.created_at ? dc.created_at.substring(0,10) : '—'"></td>
                            <td>
                                <a :href="'<?= base_url('damage_claims/show') ?>?id=' + dc.id"
                                   class="btn btn-sm btn-secondary">View</a>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div x-show="damageClaims.length > 0" class="tab-table-footer">
            <span x-text="'Showing ' + damageClaims.length + ' of ' + damageClaimsTotal"></span>
            <button x-show="damageClaims.length < damageClaimsTotal" class="btn btn-sm btn-ghost"
                    @click="loadMoreDamageClaims()" :disabled="damageClaimsLoading">Load more</button>
        </div>
    </div>

    <!-- ── TAB: MILEAGE LOG ──────────────────────────────────────── -->
    <div x-show="tab === 'mileage_logs'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to" class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <div class="card-title">Mileage Log</div>
            <?php if (can('maintenance', 'create')): ?>
            <a href="<?= base_url('mileage_logs/create') ?>?lease_id=<?= $leaseId ?>"
               class="btn btn-primary btn-sm">+ Record Mileage</a>
            <?php endif; ?>
        </div>

        <!-- Filter bar -->
        <div class="tab-filter-bar">
            <select class="form-control form-control-sm" x-model="mileageLogsFilters.log_type" @change="applyMileageLogsFilters()" style="width:auto;">
                <option value="">All Types</option>
                <option value="manual">Manual</option>
                <option value="gps_sync">GPS Sync</option>
                <option value="lease_start">Lease Start</option>
                <option value="lease_end">Lease End</option>
                <option value="service">Service</option>
            </select>
            <select class="form-control form-control-sm" x-model="mileageLogsFilters.dir" @change="applyMileageLogsFilters()" style="width:auto;">
                <option value="DESC">Newest First</option>
                <option value="ASC">Oldest First</option>
            </select>
        </div>

        <div x-show="mileageLogsLoading" class="card-body" style="text-align:center;padding:32px;">
            <span class="text-secondary">Loading mileage log…</span>
        </div>
        <div x-show="!mileageLogsLoading && mileageLogs.length === 0" class="empty-state">
            <p class="empty-state-title">No mileage entries</p>
            <p class="empty-state-text">No odometer readings have been recorded for this lease.</p>
        </div>
        <div x-show="!mileageLogsLoading && mileageLogs.length > 0" class="tab-table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Odometer</th>
                        <th>Type</th>
                        <th>Recorded By</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="ml in mileageLogs" :key="ml.id">
                        <tr>
                            <td x-text="ml.log_date"></td>
                            <td class="font-mono"
                                x-text="Number(ml.odometer_reading).toLocaleString('en-CA') + ' ' + (ml.mileage_unit === 'miles' ? 'mi' : 'km')">
                            </td>
                            <td>
                                <span class="badge" :class="mlTypeBadge(ml.log_type)"
                                      x-text="mlTypeLabel(ml.log_type)"></span>
                            </td>
                            <td x-text="ml.recorded_by_name || '—'"></td>
                            <td>
                                <a :href="'<?= base_url('mileage_logs/show') ?>?id=' + ml.id"
                                   class="btn btn-sm btn-secondary">View</a>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div x-show="mileageLogs.length > 0" class="tab-table-footer">
            <span x-text="'Showing ' + mileageLogs.length + ' of ' + mileageLogsTotal"></span>
            <button x-show="mileageLogs.length < mileageLogsTotal" class="btn btn-sm btn-ghost"
                    @click="loadMoreMileageLogs()" :disabled="mileageLogsLoading">Load more</button>
        </div>
    </div>

    <!-- ── CLOSE LEASE MODAL ──────────────────────────────────── -->
    <template x-if="showCloseModal">
        <div class="modal-overlay" @click.self="showCloseModal = false">
            <div class="modal" style="max-width:560px;">
                <div class="modal-header">
                    <div class="modal-title">Close Lease</div>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="actual_return_date">Actual Return Date</label>
                        <input type="date" id="actual_return_date" class="form-control"
                               x-model="closeForm.actual_return_date">
                    </div>

                    <!-- ── SAMSARA-3: Closing Odometer section ─────────
                         Shows lease's starting odometer (if captured),
                         lets user enter / fetch the current odometer,
                         auto-calculates total km driven, and auto-fills
                         the Actual Mileage (for billing) field below.
                         ───────────────────────────────────────────── -->
                    <div style="border:1px solid var(--border-color);border-radius:8px;padding:12px 14px;background:var(--bg-surface-2);margin-bottom:12px;">
                        <div style="font-weight:600;margin-bottom:0.5rem;font-size:0.9rem;">Closing Odometer</div>

                        <!-- Starting odometer display -->
                        <div class="text-xs text-secondary" style="margin-bottom:0.75rem;">
                            <template x-if="lease && lease.odometer_start_km !== null && lease.odometer_start_km !== undefined">
                                <span>
                                    Lease started with odometer:
                                    <span class="font-mono" x-text="Number(lease.odometer_start_km).toLocaleString('en-CA', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' km'"></span>
                                    <template x-if="lease.odometer_start_source === 'gps'">
                                        <span> (captured <span x-text="lease.odometer_start_fetched_at ? formatDate(lease.odometer_start_fetched_at) : formatDate(lease.start_date)"></span> via GPS)</span>
                                    </template>
                                    <template x-if="lease.odometer_start_source === 'manual'">
                                        <span> (entered manually)</span>
                                    </template>
                                </span>
                            </template>
                            <template x-if="lease && (lease.odometer_start_km === null || lease.odometer_start_km === undefined)">
                                <span>No starting odometer captured for this lease.</span>
                            </template>
                        </div>

                        <!-- Current odometer input + live fetch -->
                        <div class="form-group" style="margin-bottom:0.5rem;">
                            <label class="form-label" for="odometer_at_close_km">Current Odometer (km)</label>
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <input type="number" id="odometer_at_close_km" class="form-control font-mono"
                                       x-model="closeForm.odometer_at_close_km"
                                       @input="onClosingOdoEdited()"
                                       step="0.01" min="0" placeholder="e.g. 2456.78"
                                       style="flex:1 1 180px;min-width:0;">
                                <span x-show="closeOdoSource === 'gps'" class="badge badge-info" title="Fetched live from Samsara">GPS</span>
                                <span x-show="closeOdoSource === 'manual' && closeForm.odometer_at_close_km !== '' && closeForm.odometer_at_close_km !== null"
                                      class="badge badge-neutral" title="Manually entered">Manual</span>
                                <button type="button" class="btn btn-sm btn-secondary"
                                        x-show="lease && lease.samsara_vehicle_id"
                                        @click="fetchClosingOdometer()"
                                        :disabled="closeOdoFetching">
                                    <span x-show="!closeOdoFetching">Fetch from Samsara</span>
                                    <span x-show="closeOdoFetching">Fetching…</span>
                                </button>
                            </div>
                        </div>

                        <!-- Live-calculated total km driven this lease -->
                        <div class="text-xs text-secondary" style="margin-top:0.5rem;">
                            Total km driven this lease:
                            <span class="font-mono" style="font-weight:600;color:var(--text-primary);"
                                  x-text="closingTotalKmDisplay"></span>
                        </div>

                        <div x-show="closeOdoBanner"
                             :class="closeOdoBanner && closeOdoBanner.type === 'success' ? 'alert alert-success' : 'alert alert-warning'"
                             style="margin-top:0.5rem;padding:0.4rem 0.6rem;font-size:0.8rem;"
                             x-text="closeOdoBanner && closeOdoBanner.message"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="mileage_at_end">
                            Actual Mileage (for billing)
                            <span class="text-secondary text-xs"
                                  x-text="lease && lease.mileage_unit ? '(' + lease.mileage_unit + ')' : ''"></span>
                        </label>
                        <input type="number" id="mileage_at_end" class="form-control font-mono"
                               x-model="closeForm.mileage_at_end" min="0"
                               placeholder="Auto-filled from closing odometer">
                        <div class="form-hint">
                            Auto-filled from total km above when you enter a closing odometer. Override to bill a different amount.
                        </div>
                    </div>

                    <!-- ── S-LEASE-MILEAGE: Manager Reconciliation Review ──
                         Visible whenever closing odometer + starting odometer
                         are both known and the lease has a positive allowance
                         and rate. Manager picks credit_note (default for
                         underage), final_invoice_adjustment, waived, or
                         no_adjustment. Refunds-to-payment-method deferred per
                         D-H — credit notes are the standard path.
                         ─────────────────────────────────────────────────── -->
                    <template x-if="closeReconciliation && closeReconciliation.kind !== 'no_billing'">
                        <div style="border:2px solid var(--border-color);border-radius:8px;padding:14px 16px;background:var(--bg-surface-2);margin-bottom:12px;">
                            <div style="font-weight:600;margin-bottom:0.5rem;font-size:0.9rem;display:flex;align-items:center;justify-content:space-between;">
                                Mileage Reconciliation
                                <span class="badge"
                                      :class="closeReconciliation.kind === 'excess' ? 'badge-warning'
                                              : closeReconciliation.kind === 'underage' ? 'badge-info'
                                              : 'badge-success'"
                                      x-text="closeReconciliation.kind === 'excess' ? 'Excess'
                                              : closeReconciliation.kind === 'underage' ? 'Underage'
                                              : 'Exact match'"></span>
                            </div>

                            <!-- ── S-MILEAGE-FIX-0 (Q9 D-B): prior monthly excess banner ──
                                 Surface when prior_excess_km > 0 (some kilometres were
                                 already billed via per-period excess on prior monthly
                                 invoices). Two flavours:
                                   • INFO: prior excess covers part/all of the lease
                                     overage cleanly. Customer is being correctly billed
                                     without double-charge.
                                   • WARNING: prior excess EXCEEDED actual lease overage
                                     (the inverse case). Customer was over-billed
                                     during the lease; manager should consider issuing
                                     a manual credit_note before closing.
                                 ─────────────────────────────────────────────────── -->
                            <template x-if="closeReconciliation.priorExcessKm > 0">
                                <div style="border-radius:6px;padding:10px 12px;margin-bottom:10px;"
                                     :style="closeReconciliation.priorOverbillKm > 0
                                             ? 'background:var(--bg-warning-subtle, #fff7e6);border:1px solid var(--color-warning, #d97706);color:var(--text-warning, #92400e);'
                                             : 'background:var(--bg-info-subtle, #eff6ff);border:1px solid var(--color-info, #2563eb);color:var(--text-info, #1e40af);'">
                                    <div style="font-size:0.8125rem;font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:6px;">
                                        <span x-show="closeReconciliation.priorOverbillKm > 0">⚠ Prior monthly excess exceeds lease overage</span>
                                        <span x-show="closeReconciliation.priorOverbillKm === 0">Prior monthly excess already billed</span>
                                    </div>
                                    <div style="font-size:0.75rem;line-height:1.45;">
                                        <span x-show="closeReconciliation.priorOverbillKm === 0">
                                            <span x-text="Number(closeReconciliation.priorExcessKm).toLocaleString('en-CA',{minimumFractionDigits:2, maximumFractionDigits:2}) + ' km'"></span>
                                            of excess kilometres have already been billed on prior monthly invoices.
                                            Close-time charge is computed on the remaining
                                            <span x-text="Number(closeReconciliation.diffKm).toLocaleString('en-CA',{minimumFractionDigits:2, maximumFractionDigits:2}) + ' km'"></span>
                                            so the customer is not charged twice for the same kilometres.
                                        </span>
                                        <span x-show="closeReconciliation.priorOverbillKm > 0">
                                            Prior monthly excess billed
                                            <strong x-text="Number(closeReconciliation.priorExcessKm).toLocaleString('en-CA',{minimumFractionDigits:2, maximumFractionDigits:2}) + ' km'"></strong>
                                            but the lease was only
                                            <strong x-text="Number(closeReconciliation.rawOverageKm).toLocaleString('en-CA',{minimumFractionDigits:2, maximumFractionDigits:2}) + ' km'"></strong>
                                            over allowance. Customer was over-billed by
                                            <strong x-text="Number(closeReconciliation.priorOverbillKm).toLocaleString('en-CA',{minimumFractionDigits:2, maximumFractionDigits:2}) + ' km'"></strong>
                                            (≈ $<span x-text="(closeReconciliation.priorOverbillKm * closeReconciliation.rate).toFixed(2)"></span>).
                                            Close-time charge has been auto-clamped to $0 to prevent further over-billing;
                                            consider issuing a manual credit_note before closing if business policy requires correction.
                                        </span>
                                    </div>
                                </div>
                            </template>

                            <dl style="display:grid;grid-template-columns:160px 1fr;gap:4px 12px;font-size:0.8125rem;margin:0 0 8px 0;">
                                <dt class="text-secondary">Total driven</dt>
                                <dd class="font-mono"
                                    x-text="Number(closeReconciliation.total).toLocaleString('en-CA',{minimumFractionDigits:2, maximumFractionDigits:2}) + ' km'"></dd>
                                <dt class="text-secondary">Allowance</dt>
                                <dd class="font-mono"
                                    x-text="Number(closeReconciliation.allowance).toLocaleString('en-CA',{maximumFractionDigits:0}) + ' km'"></dd>
                                <template x-if="closeReconciliation.priorExcessKm > 0">
                                    <template x-if="true">
                                        <div style="display:contents;">
                                            <dt class="text-secondary">Prior monthly excess</dt>
                                            <dd class="font-mono"
                                                x-text="Number(closeReconciliation.priorExcessKm).toLocaleString('en-CA',{minimumFractionDigits:2, maximumFractionDigits:2}) + ' km'"></dd>
                                        </div>
                                    </template>
                                </template>
                                <dt class="text-secondary"
                                    x-text="closeReconciliation.kind === 'excess' ? 'Excess'
                                            : closeReconciliation.kind === 'underage' ? 'Underage'
                                            : 'Net adjustment'"></dt>
                                <dd class="font-mono" style="font-weight:600;"
                                    x-text="Number(closeReconciliation.diffKm).toLocaleString('en-CA',{minimumFractionDigits:2, maximumFractionDigits:2}) + ' km @ $' + Number(closeReconciliation.rate).toFixed(4) + '/km'"></dd>
                                <dt class="text-secondary"
                                    x-text="closeReconciliation.kind === 'excess' ? 'Charge'
                                            : closeReconciliation.kind === 'underage' ? 'Credit value'
                                            : 'Charge'"></dt>
                                <dd class="font-mono" style="font-weight:700;"
                                    x-text="'$' + Number(closeReconciliation.amount).toLocaleString('en-CA',{minimumFractionDigits:2, maximumFractionDigits:2})"></dd>
                            </dl>

                            <template x-if="closeReconciliation.kind !== 'exact'">
                                <div>
                                    <div style="font-size:0.8125rem;font-weight:600;margin:8px 0 4px;">
                                        Manager decision (required)
                                    </div>
                                    <div style="display:flex;flex-direction:column;gap:4px;">
                                        <template x-if="closeReconciliation.kind === 'underage'">
                                            <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;font-size:0.8125rem;">
                                                <input type="radio" value="credit_note"
                                                       x-model="closeForm.adjustment_decision" style="margin-top:3px;">
                                                <span><strong>Issue credit note</strong>
                                                    <span class="text-secondary text-xs" style="display:block;">Customer receives an account credit for the underage value (default).</span>
                                                </span>
                                            </label>
                                        </template>
                                        <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;font-size:0.8125rem;">
                                            <input type="radio" value="final_invoice_adjustment"
                                                   x-model="closeForm.adjustment_decision" style="margin-top:3px;">
                                            <span><strong x-text="closeReconciliation.kind === 'excess' ? 'Add charge to final invoice' : 'Reduce final invoice'"></strong>
                                                <span class="text-secondary text-xs" style="display:block;"
                                                      x-text="closeReconciliation.kind === 'excess'
                                                              ? 'Excess mileage line item added to the final invoice.'
                                                              : 'Final invoice total reduced by the underage value.'"></span>
                                            </span>
                                        </label>
                                        <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;font-size:0.8125rem;">
                                            <input type="radio" value="waived"
                                                   x-model="closeForm.adjustment_decision" style="margin-top:3px;">
                                            <span><strong>Waive</strong>
                                                <span class="text-secondary text-xs" style="display:block;">No charge, no credit. Documented in audit log.</span>
                                            </span>
                                        </label>
                                        <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;font-size:0.8125rem;">
                                            <input type="radio" value="no_adjustment"
                                                   x-model="closeForm.adjustment_decision" style="margin-top:3px;">
                                            <span><strong>No adjustment</strong>
                                                <span class="text-secondary text-xs" style="display:block;">Do not record a close adjustment row.</span>
                                            </span>
                                        </label>
                                    </div>

                                    <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;">
                                        <div class="form-group" style="flex:1 1 140px;margin:0;"
                                             x-show="closeForm.adjustment_decision === 'credit_note' || closeForm.adjustment_decision === 'final_invoice_adjustment'">
                                            <label class="form-label" style="font-size:0.75rem;">Override amount ($)</label>
                                            <input type="number" step="0.01" min="0"
                                                   class="form-control font-mono"
                                                   x-model="closeForm.adjustment_override"
                                                   :placeholder="Number(closeReconciliation.amount).toFixed(2)">
                                        </div>
                                    </div>
                                    <div class="form-group" style="margin:8px 0 0;"
                                         x-show="closeForm.adjustment_decision && closeForm.adjustment_decision !== 'no_adjustment'">
                                        <label class="form-label" style="font-size:0.75rem;">Manager notes (required)</label>
                                        <textarea x-model="closeForm.adjustment_notes" rows="2" class="form-control"
                                                  placeholder="e.g. Customer goodwill — long-term renewal."></textarea>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <div class="form-group">
                        <label class="form-label" for="close_notes">Close Notes</label>
                        <textarea id="close_notes" class="form-control"
                                  x-model="closeForm.close_notes" rows="2"></textarea>
                    </div>

                    <!-- ADV-BILL-1 D-H: only relevant for leases that activated with prepaid future periods. -->
                    <template x-if="lease && (lease.advance_billing_periods || 0) > 0">
                        <div class="form-group" style="border-top:1px solid var(--border-color);padding-top:0.75rem;margin-top:0.75rem;">
                            <label class="form-label">Advance-Billing Reconciliation</label>
                            <div class="text-xs text-secondary" style="margin-bottom:0.5rem;">
                                This lease prepaid <strong x-text="lease.advance_billing_periods"></strong>
                                future period<span x-show="lease.advance_billing_periods != 1">s</span> at activation.
                                Choose how to handle the unused portion.
                            </div>
                            <div style="display:flex;flex-direction:column;gap:6px;">
                                <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;">
                                    <input type="radio" value="refund_unused"
                                           x-model="closeForm.reconciliation_mode"
                                           style="margin-top:3px;">
                                    <span>
                                        <strong>Refund unused</strong>
                                        <span class="text-secondary text-xs" style="display:block;">
                                            Void or credit unused future invoices and refund the unused
                                            portion of the period containing the return date.
                                        </span>
                                    </span>
                                </label>
                                <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;">
                                    <input type="radio" value="no_refund"
                                           x-model="closeForm.reconciliation_mode"
                                           style="margin-top:3px;">
                                    <span>
                                        <strong>No refund</strong>
                                        <span class="text-secondary text-xs" style="display:block;">
                                            Leave every advance invoice intact — customer keeps the
                                            full prepaid coverage even though the lease is closing.
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </template>

                    <template x-if="actionError">
                        <div class="form-error" x-text="actionError"></div>
                    </template>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" @click="showCloseModal = false">Cancel</button>
                    <button class="btn btn-warning" @click="closeLease()" :disabled="actionInProgress">
                        <span x-show="!closing">Close Lease</span>
                        <span x-show="closing">Closing…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <!-- ── TAB: INSPECTIONS ──────────────────────────────────────── -->
    <div x-show="tab === 'inspections'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to" class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <div class="card-title">Inspections</div>
            <div style="display:flex;gap:8px;">
                <?php if (can('inspections', 'create')): ?>
                <a href="<?= base_url('inspections/create') ?>?lease_id=<?= $leaseId ?>&unit_id=<?= (int)($lease['equipment_unit_id'] ?? 0) ?>&type=pre_lease"
                   class="btn btn-sm btn-primary">+ Pre-Lease Inspection</a>
                <a href="<?= base_url('inspections/create') ?>?lease_id=<?= $leaseId ?>&unit_id=<?= (int)($lease['equipment_unit_id'] ?? 0) ?>&type=post_lease"
                   class="btn btn-sm btn-secondary">+ Post-Lease Inspection</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="tab-filter-bar">
            <select class="form-control form-control-sm" x-model="inspectionsFilters.inspection_type" @change="applyInspectionsFilters()" style="width:auto;">
                <option value="">All Types</option>
                <option value="pre_lease">Pre-Lease</option>
                <option value="post_lease">Post-Lease</option>
                <option value="periodic">Periodic</option>
                <option value="damage">Damage</option>
                <option value="compliance">Compliance</option>
            </select>
            <select class="form-control form-control-sm" x-model="inspectionsFilters.status" @change="applyInspectionsFilters()" style="width:auto;">
                <option value="">All Statuses</option>
                <option value="draft">Draft</option>
                <option value="complete">Complete</option>
                <option value="signed">Signed</option>
            </select>
            <select class="form-control form-control-sm" x-model="inspectionsFilters.dir" @change="applyInspectionsFilters()" style="width:auto;">
                <option value="ASC">Oldest First</option>
                <option value="DESC">Newest First</option>
            </select>
        </div>

        <div x-show="inspectionsLoading" class="card-body" style="text-align:center;padding:32px;">
            <span class="text-secondary">Loading inspections…</span>
        </div>
        <div x-show="!inspectionsLoading && inspections.length === 0" class="empty-state">
            <p class="empty-state-title">No inspections for this lease</p>
            <p class="empty-state-text">Create a pre-lease inspection before the unit leaves the yard, and a post-lease inspection when it returns.</p>
        </div>
        <div x-show="!inspectionsLoading && inspections.length > 0" class="tab-table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Inspection #</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Inspector</th>
                        <th>Condition</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="ins in inspections" :key="ins.id">
                        <tr>
                            <td class="font-mono" x-text="ins.inspection_number || ('#'+ins.id)"></td>
                            <td><span class="badge" :class="inspTypeBadge(ins.inspection_type)" x-text="inspTypeLabel(ins.inspection_type)"></span></td>
                            <td class="font-mono" x-text="ins.inspection_date"></td>
                            <td x-text="ins.inspected_by || '—'"></td>
                            <td x-text="ins.overall_condition ? ins.overall_condition.charAt(0).toUpperCase()+ins.overall_condition.slice(1) : '—'"></td>
                            <td><span class="badge" :class="inspStatusBadge(ins.status)" x-text="inspStatusLabel(ins.status)"></span></td>
                            <td><a :href="'<?= base_url('inspections/show') ?>?id='+ins.id" class="btn btn-xs btn-ghost">View</a></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div x-show="inspections.length > 0" class="tab-table-footer">
            <span x-text="'Showing ' + inspections.length + ' of ' + inspectionsTotal"></span>
            <button x-show="inspections.length < inspectionsTotal" class="btn btn-sm btn-ghost"
                    @click="loadMoreInspections()" :disabled="inspectionsLoading">Load more</button>
        </div>
    </div>

    <!-- ── TAB: DOCUMENTS ──────────────────────────────────────── -->
    <template x-if="tab === 'documents'">
        <div class="ff-tab-animated">
            <template x-if="docsLoading && documents.length === 0">
                <div class="skeleton skeleton-row"></div>
            </template>

            <div class="card" style="margin-bottom:16px;">
                <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <h3 class="card-title">Lease Documents</h3>
                    <button class="btn btn-sm btn-primary" @click="openLeaseDocModal()">+ Upload Document</button>
                </div>

                <template x-if="!docsLoading && documents.length === 0">
                    <div class="empty-state">No documents uploaded yet.</div>
                </template>

                <template x-if="documents.length > 0">
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>File</th>
                                    <th>Size</th>
                                    <th>Expiry</th>
                                    <th>Uploaded</th>
                                    <th>By</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="doc in documents" :key="doc.id">
                                    <tr>
                                        <td><span :class="leaseDocTypeBadge(doc.document_type)" x-text="leaseDocTypeLabel(doc.document_type)"></span></td>
                                        <td><span class="font-mono text-sm" x-text="doc.file_name"></span></td>
                                        <td x-text="doc.file_size_kb + ' KB'"></td>
                                        <td x-text="doc.expiration_date ? formatDate(doc.expiration_date) : '—'"></td>
                                        <td x-text="formatDate(doc.uploaded_at)"></td>
                                        <td x-text="doc.uploaded_by_name || '—'"></td>
                                        <td style="white-space:nowrap;">
                                            <a :href="doc.url" target="_blank" class="btn btn-xs btn-ghost">View PDF</a>
                                            <button class="btn btn-xs btn-outline-danger" @click="confirmDeleteDoc(doc.id)">Remove</button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>

            <!-- Upload Document Modal -->
            <template x-if="leaseDocUploadModal.open">
                <div class="modal-overlay" @click.self="leaseDocUploadModal.open = false">
                    <div class="modal" style="max-width:480px;">
                        <div class="modal-header">
                            <h3>Upload Document</h3>
                            <button class="modal-close-btn" aria-label="Close" @click="leaseDocUploadModal.open = false">✕</button>
                        </div>
                        <div class="modal-body">
                            <template x-if="leaseDocUploadModal.error">
                                <div class="alert alert-danger" x-text="leaseDocUploadModal.error"></div>
                            </template>

                            <div class="form-group">
                                <label class="form-label">Document Type</label>
                                <select class="form-control" x-model="leaseDocUploadModal.docType">
                                    <option value="">Select type…</option>
                                    <option value="contract">Lease Contract</option>
                                    <option value="inspection_in">Pre-Lease Inspection</option>
                                    <option value="inspection_out">Post-Lease Inspection</option>
                                    <option value="amendment">Lease Amendment</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">File <span class="text-muted">(PDF, JPEG, or PNG — max 20 MB)</span></label>
                                <input type="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png"
                                       @change="leaseDocUploadModal.file = $event.target.files[0]">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Title <span class="text-muted">(optional)</span></label>
                                <input type="text" class="form-control" x-model="leaseDocUploadModal.title"
                                       placeholder="Leave blank to use default">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Expiration Date <span class="text-muted">(optional)</span></label>
                                <input type="date" class="form-control" x-model="leaseDocUploadModal.expiryDate">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
                                <textarea class="form-control" rows="2" x-model="leaseDocUploadModal.notes"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-ghost" @click="leaseDocUploadModal.open = false"
                                    :disabled="leaseDocUploadModal.uploading">Cancel</button>
                            <button class="btn btn-primary" @click="submitLeaseDocUpload()"
                                    :disabled="leaseDocUploadModal.uploading || !leaseDocUploadModal.docType || !leaseDocUploadModal.file">
                                <span x-show="!leaseDocUploadModal.uploading">Upload</span>
                                <span x-show="leaseDocUploadModal.uploading">Uploading…</span>
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </template>

</div><!-- /x-data -->

<script>
function FF_LeaseDetail() {
    return {
        lease:               null,
        loading:             true,
        tab:                 'overview',
        actionInProgress:    false,
        activating:          false,
        closing:             false,
        actionError:         null,
        showCloseModal:      false,

        // Invoices tab state
        invoices:            [],
        invoicesLoading:     false,
        invoicesTotal:       0,
        invoicesPage:        1,
        invoicesFilters:     { status: '', sort: 'created_at', dir: 'DESC' },

        // Damage Claims tab state
        damageClaims:        [],
        damageClaimsLoading: false,
        damageClaimsTotal:   0,
        damageClaimsPage:    1,
        damageClaimsFilters: { severity: '', status: '', sort: 'created_at', dir: 'DESC' },

        // Mileage Logs tab state
        mileageLogs:         [],
        mileageLogsLoading:  false,
        mileageLogsTotal:    0,
        mileageLogsPage:     1,
        mileageLogsFilters:  { log_type: '', dir: 'DESC' },

        // Inspections tab state
        inspections:         [],
        inspectionsLoading:  false,
        inspectionsTotal:    0,
        inspectionsPage:     1,
        inspectionsFilters:  { inspection_type: '', status: '', dir: 'ASC' },

        // Amendments tab state (AMEND-1)
        amendments:          [],
        amendmentsLoading:   false,
        amendmentsLoaded:    false,
        amendModal: {
            open: false, saving: false, error: '',
            amendment_type: 'rate_change',
            description:    '',
            old_values_text: '',
            new_values_text: '',
        },

        // Documents tab state
        documents:           [],
        docsLoading:         false,
        docsLoaded:          false,
        leaseDocUploadModal: {
            open: false, docType: '', title: '', file: null,
            expiryDate: '', notes: '', uploading: false, error: '',
        },

        closeForm: {
            actual_return_date: new Date().toISOString().slice(0,10),
            mileage_at_end:     '',
            close_notes:        '',
            // SAMSARA-3: closing odometer (decimal km, live from Samsara or manual)
            odometer_at_close_km:  '',
            odometer_source:       null,   // 'gps' | 'manual'
            odometer_fetched_at:   null,   // ISO datetime if GPS
            // ADV-BILL-1 D-H: only sent for advance leases; ignored otherwise.
            reconciliation_mode:   'refund_unused',
            // S-LEASE-MILEAGE: manager close-adjustment decision
            adjustment_decision:   '',     // '' | 'credit_note' | 'final_invoice_adjustment' | 'waived' | 'no_adjustment'
            adjustment_override:   '',     // optional manager override of calculated amount
            adjustment_notes:      '',
        },
        // SAMSARA-1: hint shown beneath End Mileage explaining where the
        // value came from (e.g. "Pulled from Samsara: 184,233 km").
        closeFormSamsaraHint: '',
        // SAMSARA-3 closing odometer state
        closeOdoSource:    null,    // 'gps' | 'manual' — drives badge
        closeOdoFetching:  false,
        closeOdoBanner:    null,    // { type: 'success'|'warning', message: string }

        // SAMSARA-3 retroactive starting odometer capture (Overview tab)
        retroOdo: {
            value:    '',
            source:   null,            // 'gps' | 'manual'
            fetchedAt:null,
            fetching: false,
            saving:   false,
            banner:   null,
        },

        async init() {
            await this.loadLease();
        },

        // ── Amendments (AMEND-1) ─────────────────────────────────
        async loadAmendments() {
            if (this.amendmentsLoaded) return; // cached — only reload after submit
            this.amendmentsLoading = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/leases/amendments') ?>?lease_id=<?= $leaseId ?>');
                if (r.success) {
                    this.amendments       = r.data.amendments || [];
                    this.amendmentsLoaded = true;
                }
            } catch (e) { /* non-fatal */ }
            this.amendmentsLoading = false;
        },

        // Pre-fill the "Previous Values" textarea with current lease fields
        // relevant to the selected amendment type, so the manager doesn't have
        // to type them manually.
        prefillAmendOldValues() {
            if (!this.lease) return;
            const l = this.lease;
            if (this.amendModal.amendment_type === 'rate_change') {
                const lines = [];
                if (l.daily_rate   && parseFloat(l.daily_rate)   > 0) lines.push('Daily rate: $'   + parseFloat(l.daily_rate).toFixed(2));
                if (l.weekly_rate  && parseFloat(l.weekly_rate)  > 0) lines.push('Weekly rate: $'  + parseFloat(l.weekly_rate).toFixed(2));
                if (l.monthly_rate && parseFloat(l.monthly_rate) > 0) lines.push('Monthly rate: $' + parseFloat(l.monthly_rate).toFixed(2));
                this.amendModal.old_values_text = lines.join('\n');
            } else if (this.amendModal.amendment_type === 'date_extension') {
                this.amendModal.old_values_text = l.end_date ? 'End date: ' + l.end_date : '';
            } else if (this.amendModal.amendment_type === 'unit_swap') {
                this.amendModal.old_values_text = l.unit_number_snapshot ? 'Unit: ' + l.unit_number_snapshot : '';
            } else {
                // Don't overwrite for other types — let manager fill manually
            }
        },

        async submitAmendment() {
            this.amendModal.error = '';
            if (!this.amendModal.description.trim()) {
                this.amendModal.error = 'Description is required.';
                return;
            }
            this.amendModal.saving = true;
            try {
                // Convert plain-text value snapshots to simple {text} objects
                const oldVal = this.amendModal.old_values_text.trim()
                    ? { text: this.amendModal.old_values_text.trim() } : null;
                const newVal = this.amendModal.new_values_text.trim()
                    ? { text: this.amendModal.new_values_text.trim() } : null;

                const r = await FF_Api.post('<?= base_url('api/v1/leases/amendments/create') ?>', {
                    lease_id:       <?= $leaseId ?>,
                    amendment_type: this.amendModal.amendment_type,
                    description:    this.amendModal.description.trim(),
                    old_values:     oldVal,
                    new_values:     newVal,
                });
                if (r.success) {
                    // Prepend new record immediately — no reload
                    this.amendments.unshift(r.data.amendment);
                    this.amendModal = {
                        open: false, saving: false, error: '',
                        amendment_type: 'rate_change',
                        description: '', old_values_text: '', new_values_text: '',
                    };
                    if (window.FF_Toast) FF_Toast.success('Amendment recorded', 'The amendment has been saved.');
                } else {
                    this.amendModal.error = (r.error && r.error.message) || 'Could not save amendment.';
                }
            } catch (e) {
                this.amendModal.error = 'Network error — please try again.';
            }
            this.amendModal.saving = false;
        },

        amendTypeLabel(type) {
            const map = {
                rate_change:    'Rate Change',
                date_extension: 'Date Extension',
                unit_swap:      'Unit Swap',
                add_on:         'Add-On',
                tax_change:     'Tax Change',
                other:          'Other',
            };
            return map[type] || type;
        },

        // Format old_values/new_values for display.
        // We store plain {text: "..."} objects, so just return the text.
        formatAmendValues(obj) {
            if (!obj) return '—';
            if (obj.text) return obj.text;
            // Fallback: stringify any other shape
            return Object.entries(obj).map(([k, v]) => k + ': ' + v).join(', ');
        },

        // ── Invoices ──────────────────────────────────────────────
        // WHY append flag: "Load more" appends; filter change replaces
        async loadInvoices(append = false) {
            if (!append && this.invoices.length > 0) return; // already loaded for this filter set
            this.invoicesLoading = true;
            try {
                const p = new URLSearchParams({
                    lease_id: '<?= $leaseId ?>',
                    per_page: '50',
                    page:     this.invoicesPage,
                    sort:     this.invoicesFilters.sort,
                    dir:      this.invoicesFilters.dir,
                });
                if (this.invoicesFilters.status) p.set('status', this.invoicesFilters.status);
                const r = await FF_Api.get('<?= base_url('api/v1/invoices') ?>?' + p.toString());
                if (r.success) {
                    const newItems      = r.data.items || [];
                    this.invoices       = append ? [...this.invoices, ...newItems] : newItems;
                    this.invoicesTotal  = r.data.pagination?.total ?? newItems.length;
                }
            } catch(e) { /* non-fatal */ }
            this.invoicesLoading = false;
        },
        loadMoreInvoices()    { this.invoicesPage++; this.loadInvoices(true); },
        applyInvoicesFilters() { this.invoices = []; this.invoicesPage = 1; this.invoicesTotal = 0; this.loadInvoices(); },

        // ── Damage Claims ─────────────────────────────────────────
        async loadDamageClaims(append = false) {
            if (!append && this.damageClaims.length > 0) return;
            this.damageClaimsLoading = true;
            try {
                const p = new URLSearchParams({
                    lease_id: '<?= $leaseId ?>',
                    per_page: '50',
                    page:     this.damageClaimsPage,
                    sort:     this.damageClaimsFilters.sort,
                    dir:      this.damageClaimsFilters.dir,
                });
                if (this.damageClaimsFilters.severity) p.set('severity', this.damageClaimsFilters.severity);
                if (this.damageClaimsFilters.status)   p.set('status',   this.damageClaimsFilters.status);
                const r = await FF_Api.get('<?= base_url('api/v1/damage_claims') ?>?' + p.toString());
                if (r.success) {
                    const newItems          = r.data?.items ?? [];
                    this.damageClaims       = append ? [...this.damageClaims, ...newItems] : newItems;
                    this.damageClaimsTotal  = r.data?.pagination?.total ?? newItems.length;
                }
            } catch(e) { /* non-fatal */ }
            this.damageClaimsLoading = false;
        },
        loadMoreDamageClaims()    { this.damageClaimsPage++; this.loadDamageClaims(true); },
        applyDamageClaimsFilters() { this.damageClaims = []; this.damageClaimsPage = 1; this.damageClaimsTotal = 0; this.loadDamageClaims(); },

        // ── Mileage Logs ──────────────────────────────────────────
        async loadMileageLogs(append = false) {
            if (!append && this.mileageLogs.length > 0) return;
            this.mileageLogsLoading = true;
            try {
                const p = new URLSearchParams({
                    lease_id: '<?= $leaseId ?>',
                    per_page: '50',
                    page:     this.mileageLogsPage,
                    sort:     'log_date',
                    dir:      this.mileageLogsFilters.dir,
                });
                if (this.mileageLogsFilters.log_type) p.set('log_type', this.mileageLogsFilters.log_type);
                const r = await FF_Api.get('<?= base_url('api/v1/mileage_logs/index') ?>?' + p.toString());
                if (r.success) {
                    const newItems        = r.data?.items ?? [];
                    this.mileageLogs      = append ? [...this.mileageLogs, ...newItems] : newItems;
                    this.mileageLogsTotal = r.data?.pagination?.total ?? newItems.length;
                }
            } catch(e) { /* non-fatal */ }
            this.mileageLogsLoading = false;
        },
        loadMoreMileageLogs()    { this.mileageLogsPage++; this.loadMileageLogs(true); },
        applyMileageLogsFilters() { this.mileageLogs = []; this.mileageLogsPage = 1; this.mileageLogsTotal = 0; this.loadMileageLogs(); },

        // ── Inspections ───────────────────────────────────────────
        async loadInspections(append = false) {
            if (!append && this.inspections.length > 0) return;
            this.inspectionsLoading = true;
            try {
                const p = new URLSearchParams({
                    lease_id: '<?= $leaseId ?>',
                    per_page: '50',
                    page:     this.inspectionsPage,
                    sort:     'inspection_date',
                    dir:      this.inspectionsFilters.dir,
                });
                if (this.inspectionsFilters.inspection_type) p.set('inspection_type', this.inspectionsFilters.inspection_type);
                if (this.inspectionsFilters.status)          p.set('status',          this.inspectionsFilters.status);
                const r = await FF_Api.get('<?= base_url('api/v1/inspections/index.php') ?>?' + p.toString());
                if (r.success) {
                    const newItems        = r.data?.items ?? [];
                    this.inspections      = append ? [...this.inspections, ...newItems] : newItems;
                    this.inspectionsTotal = r.data?.pagination?.total ?? newItems.length;
                }
            } catch(e) { /* non-fatal */ }
            this.inspectionsLoading = false;
        },
        loadMoreInspections()    { this.inspectionsPage++; this.loadInspections(true); },
        applyInspectionsFilters() { this.inspections = []; this.inspectionsPage = 1; this.inspectionsTotal = 0; this.loadInspections(); },

        // ── Documents ─────────────────────────────────────────────
        // WHY docsLoaded guard: load once on first tab visit; in-place updates
        // after upload/delete mean we never need to re-fetch the full list.
        async loadDocuments() {
            if (this.docsLoaded) return;
            this.docsLoading = true;
            try {
                const r = await FF_Api.get(
                    '<?= base_url('api/v1/documents/index') ?>?entity_type=lease&entity_id=<?= $leaseId ?>'
                );
                if (r.success) this.documents = r.data.items || [];
                this.docsLoaded = true;
            } catch(e) { /* non-fatal */ }
            this.docsLoading = false;
        },

        openLeaseDocModal() {
            this.leaseDocUploadModal = {
                open: true, docType: '', title: '', file: null,
                expiryDate: '', notes: '', uploading: false, error: '',
            };
        },

        async submitLeaseDocUpload() {
            const m = this.leaseDocUploadModal;
            if (!m.docType || !m.file) return;
            m.uploading = true;
            m.error     = '';
            try {
                const fd = new FormData();
                fd.append('entity_type',   'lease');
                fd.append('entity_id',     '<?= $leaseId ?>');
                fd.append('document_type', m.docType);
                fd.append('document',      m.file);
                if (m.title)      fd.append('title',           m.title);
                if (m.expiryDate) fd.append('expiration_date', m.expiryDate);
                if (m.notes)      fd.append('notes',           m.notes);

                // WHY: FF_Api.upload() handles multipart FormData — sends no
                // Content-Type so the browser sets the correct boundary,
                // and attaches X-CSRF-Token automatically.
                const data = await FF_Api.upload('<?= base_url('api/v1/documents/upload') ?>', fd);
                if (data.success) {
                    // WHY prepend: multiple docs per type are valid for leases
                    // (e.g. multiple amendments); keep full history visible.
                    this.documents.unshift(data.data);
                    m.open = false;
                } else {
                    // WHY: API error envelope is { error: { message } } not top-level message.
                    m.error = data.error?.message || 'Upload failed. Please try again.';
                }
            } catch(e) {
                m.error = 'Network error. Please try again.';
            }
            m.uploading = false;
        },

        async confirmDeleteDoc(id) {
            if (!(await FF_Confirm.ask('Remove this document? This cannot be undone.'))) return;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/documents/delete') ?>', { id });
                if (r.success) {
                    this.documents = this.documents.filter(d => d.id !== id);
                } else {
                    FF_Toast.error(r.message || 'Delete failed.');
                }
            } catch(e) {
                FF_Toast.error('Network error. Please try again.');
            }
        },

        leaseDocTypeBadge(t) {
            return {
                contract:       'badge badge-info',
                inspection_in:  'badge badge-neutral',
                inspection_out: 'badge badge-neutral',
                amendment:      'badge badge-warning',
                other:          'badge badge-neutral',
            }[t] ?? 'badge badge-neutral';
        },

        leaseDocTypeLabel(t) {
            return {
                contract:       'Contract',
                inspection_in:  'Pre-Lease Insp.',
                inspection_out: 'Post-Lease Insp.',
                amendment:      'Amendment',
                other:          'Other',
            }[t] ?? t;
        },

        inspTypeBadge(t) {
            return { pre_lease:'badge badge-info', post_lease:'badge badge-warning', periodic:'badge badge-neutral',
                     damage:'badge badge-danger', compliance:'badge badge-success' }[t] ?? 'badge badge-neutral';
        },
        inspTypeLabel(t) {
            return { pre_lease:'Pre-Lease', post_lease:'Post-Lease', periodic:'Periodic',
                     damage:'Damage', compliance:'Compliance' }[t] ?? t;
        },
        inspStatusBadge(s) {
            return { draft:'badge badge-warning', complete:'badge badge-info', signed:'badge badge-success' }[s] ?? 'badge badge-neutral';
        },
        inspStatusLabel(s) {
            return { draft:'Draft', complete:'Complete', signed:'Signed' }[s] ?? s;
        },

        mlTypeBadge(t) {
            return { manual:'badge badge-info', gps_sync:'badge badge-success',
                     lease_start:'badge badge-neutral', lease_end:'badge badge-neutral',
                     service:'badge badge-warning' }[t] ?? 'badge badge-neutral';
        },

        mlTypeLabel(t) {
            return { manual:'Manual', gps_sync:'GPS Sync', lease_start:'Lease Start',
                     lease_end:'Lease End', service:'Service' }[t] ?? t;
        },

        dcSeverityBadge(s) {
            return { minor:'badge badge-info', moderate:'badge badge-warning', major:'badge badge-danger', total_loss:'badge badge-danger' }[s] ?? 'badge badge-neutral';
        },

        dcSeverityLabel(s) {
            return { minor:'Minor', moderate:'Moderate', major:'Major', total_loss:'Total Loss' }[s] ?? s;
        },

        dcStatusBadge(s) {
            return { reported:'badge badge-info', assessed:'badge badge-warning', repair_ordered:'badge badge-warning',
                     invoiced:'badge badge-purple', resolved:'badge badge-success', written_off:'badge badge-neutral' }[s] ?? 'badge badge-neutral';
        },

        dcStatusLabel(s) {
            return { reported:'Reported', assessed:'Assessed', repair_ordered:'Repair Ordered',
                     invoiced:'Invoiced', resolved:'Resolved', written_off:'Written Off' }[s] ?? s;
        },

        invBadgeClass(status) {
            const m = { draft:'badge-neutral', sent:'badge-info', paid:'badge-success',
                        partially_paid:'badge-warning', overdue:'badge-danger',
                        void:'badge-neutral line-through', written_off:'badge-danger' };
            return m[status] || 'badge-neutral';
        },

        async loadLease() {
            this.loading = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/leases/show') ?>?id=<?= $leaseId ?>');
                if (r.success) {
                    this.lease = r.data;
                }
            } catch(e) {
                // Non-fatal — page still renders with server-side data
            }
            this.loading = false;
        },

        async activate() {
            if (!(await FF_Confirm.ask('Activate lease <?= e($lease['contract_number']) ?>? This will mark the unit as On Lease.'))) return;
            this.actionInProgress = true;
            this.activating       = true;
            this.actionError      = null;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/leases/activate') ?>', { id: <?= $leaseId ?> });
                if (r.success) {
                    window.location.reload();
                } else {
                    this.actionError = r.message || 'Failed to activate lease.';
                }
            } catch(e) {
                this.actionError = 'Network error. Please try again.';
            }
            this.actionInProgress = false;
            this.activating       = false;
        },

        // ── SAMSARA-1: Close-modal pre-fill helpers ──────────────
        // openCloseModal: pops the dialog AND, if the unit is linked to
        // Samsara with a known odometer, seeds End Mileage automatically
        // (in the lease's mileage_unit — converting km→mi if necessary).
        // The user can still clear or override the value before submit.
        openCloseModal() {
            this.showCloseModal       = true;
            this.closeFormSamsaraHint = '';
            if (this.lease && this.lease.samsara_odometer_km !== null && this.closeForm.mileage_at_end === '') {
                this.prefillMileageFromSamsara();
            }
        },

        // Convert the cron-cached samsara_odometer_km into the lease's
        // mileage_unit and drop it into the form. Updates the hint line so
        // the user knows the source. Manual button on the modal also calls
        // this so people can re-pull after a fresh cron tick.
        prefillMileageFromSamsara() {
            if (!this.lease || this.lease.samsara_odometer_km === null) return;
            const km   = this.lease.samsara_odometer_km;
            const unit = (this.lease.mileage_unit || 'km').toLowerCase();
            // Samsara reports km natively; convert to miles only if the
            // lease was contracted in miles. 1 km = 0.621371 miles.
            const value = unit === 'miles' ? Math.round(km * 0.621371) : Math.round(km);
            this.closeForm.mileage_at_end = String(value);
            this.closeFormSamsaraHint = 'Pulled from Samsara: '
                + value.toLocaleString('en-CA') + ' ' + unit
                + ' (last synced ' + this.formatRelative(this.lease.samsara_last_synced_at) + ')';
        },

        // ── SAMSARA-3: Live fetch the current odometer from Samsara
        //     for the closing lease and drop it into the form. Also
        //     auto-calculates and fills the Actual Mileage (for billing)
        //     field with the total km driven since lease start.
        async fetchClosingOdometer() {
            if (!this.lease || !this.lease.equipment_unit_id) return;
            this.closeOdoFetching = true;
            this.closeOdoBanner   = null;
            try {
                const r = await FF_Api.get(
                    `<?= base_url('api/v1/samsara/current_odometer') ?>?equipment_unit_id=${this.lease.equipment_unit_id}`
                );
                const d = r.data || {};
                if (d.linked === false || d.odometer_km === null || d.odometer_km === undefined) {
                    this.closeOdoBanner = { type: 'warning', message: d.message || 'Could not fetch current odometer. Enter manually.' };
                    return;
                }
                const km = Number(d.odometer_km).toFixed(2);
                this.closeForm.odometer_at_close_km = km;
                this.closeForm.odometer_source      = 'gps';
                this.closeForm.odometer_fetched_at  = d.fetched_at;
                this.closeOdoSource                 = 'gps';
                this.autoFillMileageFromClosingOdo();

                const kmDisplay = Number(d.odometer_km).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                this.closeOdoBanner = { type: 'success', message: `✓ Live odometer fetched: ${kmDisplay} km from Samsara` };
            } catch (e) {
                this.closeOdoBanner = { type: 'warning', message: 'Could not reach Samsara. Enter odometer manually.' };
            } finally {
                this.closeOdoFetching = false;
            }
        },

        // Fired whenever user types in the closing odometer field
        onClosingOdoEdited() {
            if (this.closeOdoSource === 'gps') {
                // User overrode a GPS value — flip to manual
                this.closeOdoSource                = 'manual';
                this.closeForm.odometer_source     = 'manual';
                this.closeForm.odometer_fetched_at = null;
            } else if (this.closeForm.odometer_at_close_km !== '' && this.closeForm.odometer_at_close_km !== null) {
                this.closeOdoSource            = 'manual';
                this.closeForm.odometer_source = 'manual';
            } else {
                this.closeOdoSource            = null;
                this.closeForm.odometer_source = null;
            }
            this.autoFillMileageFromClosingOdo();
        },

        // Auto-fill the "Actual Mileage (for billing)" field from the
        // total km driven (closing - starting) — but only if the user
        // hasn't already put a custom override value in there. Users can
        // always override by typing directly into the mileage field.
        autoFillMileageFromClosingOdo() {
            const closeKm = parseFloat(this.closeForm.odometer_at_close_km);
            const startKm = parseFloat(this.lease?.odometer_start_km);
            if (isNaN(closeKm) || isNaN(startKm)) return;
            const total = closeKm - startKm;
            if (total < 0) return;
            // Convert to lease's mileage_unit (default km)
            const unit = (this.lease.mileage_unit || 'km').toLowerCase();
            const val  = unit === 'miles' ? Math.round(total * 0.621371) : Math.round(total);
            this.closeForm.mileage_at_end = String(val);
        },

        // ── SAMSARA-3: Retroactive starting odometer capture ───────
        async fetchRetroOdometer() {
            if (!this.lease || !this.lease.equipment_unit_id) return;
            this.retroOdo.fetching = true;
            this.retroOdo.banner   = null;
            try {
                const r = await FF_Api.get(
                    `<?= base_url('api/v1/samsara/current_odometer') ?>?equipment_unit_id=${this.lease.equipment_unit_id}`
                );
                const d = r.data || {};
                if (d.linked === false || d.odometer_km === null || d.odometer_km === undefined) {
                    this.retroOdo.banner = { type: 'warning', message: d.message || 'Could not fetch from Samsara.' };
                    return;
                }
                this.retroOdo.value      = Number(d.odometer_km).toFixed(2);
                this.retroOdo.source     = 'gps';
                this.retroOdo.fetchedAt  = d.fetched_at;
                const kmDisplay = Number(d.odometer_km).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                this.retroOdo.banner = { type: 'success', message: `✓ Live odometer fetched: ${kmDisplay} km from Samsara` };
            } catch (e) {
                this.retroOdo.banner = { type: 'warning', message: 'Could not reach Samsara. Enter value manually.' };
            } finally {
                this.retroOdo.fetching = false;
            }
        },

        async saveRetroOdometer() {
            if (!this.lease || !this.retroOdo.value) return;
            this.retroOdo.saving = true;
            this.retroOdo.banner = null;
            try {
                const payload = {
                    lease_id:          this.lease.id,
                    odometer_start_km: parseFloat(this.retroOdo.value),
                    source:            this.retroOdo.source || 'manual',
                };
                if (payload.source === 'gps' && this.retroOdo.fetchedAt) {
                    payload.fetched_at = this.retroOdo.fetchedAt;
                }
                const r = await FF_Api.post('<?= base_url('api/v1/leases/update_odometer') ?>', payload);
                if (r.success) {
                    // Refresh to show the full summary view
                    this.retroOdo.banner = { type: 'success', message: '✓ Starting odometer saved. Refreshing…' };
                    setTimeout(() => { window.location.reload(); }, 800);
                } else {
                    this.retroOdo.banner = { type: 'warning', message: r.error?.message || r.message || 'Could not save.' };
                }
            } catch (e) {
                this.retroOdo.banner = { type: 'warning', message: 'Network error — could not save.' };
            } finally {
                this.retroOdo.saving = false;
            }
        },

        // Getter: display string for total km driven (live-calculated)
        get closingTotalKmDisplay() {
            const closeKm = parseFloat(this.closeForm.odometer_at_close_km);
            const startKm = parseFloat(this.lease?.odometer_start_km);
            if (isNaN(closeKm) || isNaN(startKm)) return '— km';
            const total = closeKm - startKm;
            if (total < 0) return '⚠ negative — check values';
            return Number(total).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' km';
        },

        // ── S-LEASE-MILEAGE: live mileage reconciliation getters ─
        // Drive the close-modal review section. Returns null when we can't
        // compute reconciliation (e.g. start odometer not captured yet).
        //
        // S-MILEAGE-FIX-0 (Q9 D-B): also returns prior_excess_km (sum of
        // per-period excess on prior monthly invoices, supplied by the
        // lease show API) and prior_overbill_km (positive when prior
        // monthly excess billed MORE kilometres than the lease was over
        // allowance — the inverse case). Excess kind is computed against
        // the adjusted overage (raw - prior) so the manager sees the
        // delta that should still be charged at close, not the full raw
        // overage that would double-bill.
        get closeReconciliation() {
            if (!this.lease) return null;
            const closeKm = parseFloat(this.closeForm.odometer_at_close_km);
            const startKm = parseFloat(this.lease.odometer_start_km);
            if (isNaN(closeKm) || isNaN(startKm)) return null;

            const total = Math.max(0, closeKm - startKm);
            const allowance = parseFloat(this.lease.estimated_mileage_km
                                         || this.lease.estimated_mileage || 0);
            const rate      = parseFloat(this.lease.mileage_rate_km
                                         || this.lease.mileage_rate || 0);
            const priorExcessKm = parseFloat(this.lease.prior_excess_km || 0) || 0;

            if (allowance <= 0 || rate <= 0) {
                return {
                    total, allowance, rate,
                    kind: 'no_billing', amount: 0, diffKm: 0,
                    priorExcessKm: priorExcessKm,
                    rawOverageKm: 0,
                    priorOverbillKm: 0,
                };
            }

            const rawOverageKm = total - allowance;
            // priorOverbillKm > 0 means monthly excess already billed
            // MORE kilometres than the lease was actually over allowance.
            // The customer was over-billed; close-time adjusted excess
            // is clamped to 0 and the manager sees a warning banner.
            const priorOverbillKm = (rawOverageKm > 0 && priorExcessKm > rawOverageKm)
                ? (priorExcessKm - rawOverageKm)
                : 0;

            if (rawOverageKm > 0) {
                const adjustedExcess = Math.max(0, rawOverageKm - priorExcessKm);
                if (adjustedExcess > 0) {
                    return {
                        total, allowance, rate,
                        kind: 'excess',
                        diffKm: adjustedExcess,
                        amount: Math.round(adjustedExcess * rate * 100) / 100,
                        priorExcessKm,
                        rawOverageKm,
                        priorOverbillKm,
                    };
                }
                // Adjusted excess = 0 (and rawOverageKm > 0): prior monthly
                // excess fully covered the lease overage. No close-time
                // charge; surface as 'exact' but with priorOverbill flag
                // when over-billed.
                return {
                    total, allowance, rate,
                    kind: 'exact',
                    diffKm: 0,
                    amount: 0,
                    priorExcessKm,
                    rawOverageKm,
                    priorOverbillKm,
                };
            }
            if (rawOverageKm < 0) {
                const under = -rawOverageKm;
                return {
                    total, allowance, rate,
                    kind: 'underage',
                    diffKm: under,
                    amount: Math.round(under * rate * 100) / 100,
                    priorExcessKm,
                    rawOverageKm,
                    priorOverbillKm,
                };
            }
            return {
                total, allowance, rate,
                kind: 'exact', diffKm: 0, amount: 0,
                priorExcessKm,
                rawOverageKm,
                priorOverbillKm,
            };
        },

        // ── SAMSARA-1: Live GPS card helpers ─────────────────────
        // "Online" means we have a recent connection (<8h) — same rule the
        // Fleet Tracking dashboard uses, kept consistent on purpose.
        samsaraIsOnline() {
            if (!this.lease || !this.lease.samsara_last_connected_at) return false;
            const last = new Date(this.lease.samsara_last_connected_at).getTime();
            if (isNaN(last)) return false;
            return (Date.now() - last) < (8 * 3600 * 1000);
        },

        // Human relative time (e.g. "12m ago") for telemetry footers.
        // Falls back to "—" rather than "Invalid Date" so empty rows
        // render cleanly even before the first cron tick.
        formatRelative(ts) {
            if (!ts) return '—';
            const t = new Date(ts).getTime();
            if (isNaN(t)) return '—';
            const diff = Math.max(0, Date.now() - t);
            const m    = Math.floor(diff / 60000);
            if (m < 1)    return 'just now';
            if (m < 60)   return m + 'm ago';
            const h = Math.floor(m / 60);
            if (h < 24)   return h + 'h ago';
            const d = Math.floor(h / 24);
            return d + 'd ago';
        },

        async closeLease() {
            this.actionInProgress = true;
            this.closing          = true;
            this.actionError      = null;
            const payload = {
                id:                 <?= $leaseId ?>,
                actual_return_date: this.closeForm.actual_return_date || null,
                close_notes:        this.closeForm.close_notes || null,
            };
            if (this.closeForm.mileage_at_end !== '') {
                payload.mileage_at_end = parseInt(this.closeForm.mileage_at_end);
            }
            // SAMSARA-3: closing odometer goes to the final invoice
            if (this.closeForm.odometer_at_close_km !== '' && this.closeForm.odometer_at_close_km !== null) {
                payload.odometer_at_close_km = parseFloat(this.closeForm.odometer_at_close_km);
                payload.odometer_source      = this.closeForm.odometer_source || 'manual';
                if (this.closeForm.odometer_fetched_at) {
                    payload.odometer_fetched_at = this.closeForm.odometer_fetched_at;
                }
            }
            // ADV-BILL-1 D-H: only meaningful when this lease has prepaid advance periods.
            if ((this.lease?.advance_billing_periods || 0) > 0) {
                payload.reconciliation_mode = this.closeForm.reconciliation_mode || 'refund_unused';
            }
            // ── S-LEASE-MILEAGE: include close_adjustment when manager
            // has reviewed the reconciliation panel. Only attached when a
            // decision was selected — leases without billable mileage skip
            // it entirely and the legacy partial-month overage logic on the
            // server takes over for backwards compatibility.
            const recon = this.closeReconciliation;
            if (recon && recon.kind !== 'no_billing' && recon.kind !== 'exact'
                && this.closeForm.adjustment_decision) {
                if (this.closeForm.adjustment_decision !== 'no_adjustment'
                    && !this.closeForm.adjustment_notes.trim()) {
                    this.actionError = 'Manager notes are required for the chosen adjustment decision.';
                    this.actionInProgress = false;
                    this.closing = false;
                    return;
                }
                payload.close_adjustment = {
                    decision: this.closeForm.adjustment_decision,
                    notes:    this.closeForm.adjustment_notes,
                };
                if (this.closeForm.adjustment_override !== '' && this.closeForm.adjustment_override !== null) {
                    payload.close_adjustment.final_amount = parseFloat(this.closeForm.adjustment_override);
                }
            }
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/leases/close') ?>', payload);
                if (r.success) {
                    window.location.reload();
                } else {
                    this.actionError = r.message || 'Failed to close lease.';
                }
            } catch(e) {
                this.actionError = 'Network error. Please try again.';
            }
            this.actionInProgress = false;
            this.closing          = false;
        },

        statusBadgeClass(status) {
            const map = {
                active:    'badge-success',
                pending:   'badge-warning',
                completed: 'badge-neutral',
                cancelled: 'badge-danger',
            };
            return map[status] || 'badge-neutral';
        },

        formatDate(d) {
            if (!d) return '—';
            const dt = new Date(d + 'T00:00:00');
            return dt.toLocaleDateString('en-CA', { year:'numeric', month:'short', day:'numeric' });
        },

        formatDateTime(d) {
            if (!d) return '—';
            const dt = new Date(d);
            return dt.toLocaleDateString('en-CA', { year:'numeric', month:'short', day:'numeric',
                hour:'2-digit', minute:'2-digit' });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
