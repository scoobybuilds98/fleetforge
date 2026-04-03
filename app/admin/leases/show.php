<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Detail Page
 *
 * @file        app/admin/leases/show.php
 * @description Lease detail view. Server-side hero render (contract number, status,
 *              customer, unit). Three-tab Alpine.js component: Overview (all fields),
 *              Amendments (placeholder — requires amendments table), Status Log (from lease.status_log).
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
            l.equipment_unit_id,
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
        <?php if (can('leases', 'edit') && $lease['status'] === 'pending'): ?>
        <a href="<?= base_url('leases/edit') ?>?id=<?= $leaseId ?>" class="btn btn-secondary btn-sm">Edit</a>
        <?php endif; ?>
        <?php if (can('leases', 'delete') && $lease['status'] === 'pending'): ?>
        <button class="btn btn-danger btn-sm" onclick="if(confirm('Delete this pending lease? This cannot be undone.')){FF_Api.post('<?= base_url('api/v1/leases/delete') ?>',{id:<?= $leaseId ?>}).then(r=>{if(r.success)window.location.href='<?= base_url('leases') ?>';else alert(r.error?.message||'Failed to delete');})}">Delete</button>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     STATS ROW — server-rendered so tiles are always visible
     across all tabs, not just Overview.
     ============================================================ -->
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card">
        <div class="stat-label">Total Invoiced</div>
        <div class="stat-value currency"><?= e(format_currency($lease['total_invoiced'] ?? 0)) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Total Paid</div>
        <div class="stat-value currency"><?= e(format_currency($lease['total_paid'] ?? 0)) ?></div>
    </div>

    <div class="stat-card<?= (float)($lease['outstanding_balance'] ?? 0) > 0 ? ' stat-card--danger' : '' ?>">
        <div class="stat-label">Outstanding</div>
        <div class="stat-value currency"><?= e(format_currency($lease['outstanding_balance'] ?? 0)) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Currency</div>
        <div class="stat-value"><?= e($lease['currency']) ?></div>
    </div>

</div>

<!-- ============================================================
     LEASE DETAIL — Alpine component
     ============================================================ -->
<div x-data="FF_LeaseDetail()" x-init="init()">

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
        <button class="btn btn-warning" @click="showCloseModal = true" :disabled="actionInProgress">
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
                @click="tab = 'amendments'" :aria-selected="tab === 'amendments'" role="tab">Amendments</button>
        <button class="tab-btn" :class="{ 'is-active': tab === 'invoices' }"
                @click="tab = 'invoices'; loadInvoices()" :aria-selected="tab === 'invoices'" role="tab">Invoices</button>
        <button class="tab-btn" :class="{ 'is-active': tab === 'damage_claims' }"
                @click="tab = 'damage_claims'; loadDamageClaims()" :aria-selected="tab === 'damage_claims'" role="tab">Damage Claims</button>
        <button class="tab-btn" :class="{ 'is-active': tab === 'mileage_logs' }"
                @click="tab = 'mileage_logs'; loadMileageLogs()" :aria-selected="tab === 'mileage_logs'" role="tab">Mileage Log</button>
        <button class="tab-btn" :class="{ 'is-active': tab === 'inspections' }"
                @click="tab = 'inspections'; loadInspections()" :aria-selected="tab === 'inspections'" role="tab">Inspections</button>
    </div>

    <!-- ── TAB: OVERVIEW ──────────────────────────────────────── -->
    <template x-if="tab === 'overview'">
        <div>
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
                                        <tr x-show="parseFloat(lease.mileage_rate) > 0">
                                            <td class="text-secondary">Mileage Rate</td>
                                            <td class="font-mono" x-text="'$' + parseFloat(lease.mileage_rate).toFixed(4) + '/' + lease.mileage_unit"></td>
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

                    </div>
                </div>
            </template>
        </div>
    </template>

    <!-- ── TAB: STATUS LOG ────────────────────────────────────── -->
    <template x-if="tab === 'status_log'">
        <div class="card">
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

    <!-- ── TAB: AMENDMENTS ────────────────────────────────────── -->
    <template x-if="tab === 'amendments'">
        <div class="card">
            <div class="empty-state">
                <p class="empty-state-title">Amendments coming soon</p>
                <p class="empty-state-text">Rate change and date extension amendments will be available in a future session.</p>
            </div>
        </div>
    </template>

    <!-- ── TAB: INVOICES ─────────────────────────────────────────── -->
    <div x-show="tab === 'invoices'" class="card">
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
    <div x-show="tab === 'damage_claims'" class="card">
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
    <div x-show="tab === 'mileage_logs'" class="card">
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
            <div class="modal">
                <div class="modal-header">
                    <div class="modal-title">Close Lease</div>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="actual_return_date">Actual Return Date</label>
                        <input type="date" id="actual_return_date" class="form-control"
                               x-model="closeForm.actual_return_date">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mileage_at_end">End Mileage</label>
                        <input type="number" id="mileage_at_end" class="form-control font-mono"
                               x-model="closeForm.mileage_at_end" min="0"
                               placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="close_notes">Close Notes</label>
                        <textarea id="close_notes" class="form-control"
                                  x-model="closeForm.close_notes" rows="2"></textarea>
                    </div>
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
    <div x-show="tab === 'inspections'" class="card">
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

        closeForm: {
            actual_return_date: new Date().toISOString().slice(0,10),
            mileage_at_end:     '',
            close_notes:        '',
        },

        async init() {
            await this.loadLease();
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
            if (!confirm('Activate lease <?= e($lease['contract_number']) ?>? This will mark the unit as On Lease.')) return;
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
