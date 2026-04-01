<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Detail Page
 *
 * @file        app/admin/leases/show.php
 * @description Lease detail view. Server-side hero render (contract number, status,
 *              customer, unit). Three-tab Alpine.js component: Overview (all fields),
 *              Amendments (list — stub for S008+), Status Log (from lease.status_log).
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
            l.company_name_snapshot, l.customer_name_snapshot,
            l.unit_number_snapshot, l.template_name_snapshot,
            l.daily_rate, l.weekly_rate, l.monthly_rate, l.currency,
            l.outstanding_balance, l.total_invoiced, l.po_number,
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
    </div>

    <!-- ── TAB: OVERVIEW ──────────────────────────────────────── -->
    <template x-if="tab === 'overview'">
        <div>
            <template x-if="loading">
                <div class="skeleton skeleton-row"></div>
            </template>
            <template x-if="!loading && lease">
                <div>
                    <!-- Quick stats — stat-grid is defined in app.css (auto-fit minmax 200px) -->
                    <div class="stat-grid">
                        <div class="stat-card">
                            <div class="stat-label">Total Invoiced</div>
                            <div class="stat-value font-mono"
                                 x-text="'$' + parseFloat(lease.total_invoiced || 0).toFixed(2)"></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Total Paid</div>
                            <div class="stat-value font-mono"
                                 x-text="'$' + parseFloat(lease.total_paid || 0).toFixed(2)"></div>
                        </div>
                        <div class="stat-card"
                             :class="parseFloat(lease.outstanding_balance) > 0 ? 'stat-card--danger' : ''">
                            <div class="stat-label">Outstanding</div>
                            <div class="stat-value font-mono"
                                 x-text="'$' + parseFloat(lease.outstanding_balance || 0).toFixed(2)"></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Currency</div>
                            <div class="stat-value" x-text="lease.currency"></div>
                        </div>
                    </div>

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
                <div class="table-wrapper">
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

    <!-- ── TAB: INVOICES (FIX #40) ──────────────────────────────── -->
    <template x-if="tab === 'invoices'">
        <div class="card">
            <div class="card-header"><div class="card-title">Invoices</div></div>
            <div x-show="invoicesLoading" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading invoices…</span>
            </div>
            <template x-if="!invoicesLoading && invoices.length === 0">
                <div class="empty-state">
                    <p class="empty-state-title">No invoices yet</p>
                    <p class="empty-state-text">Invoices are generated automatically on activation and close.</p>
                </div>
            </template>
            <template x-if="!invoicesLoading && invoices.length > 0">
                <div class="table-wrapper">
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
            </template>
        </div>
    </template>

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

</div><!-- /x-data -->

<script>
function FF_LeaseDetail() {
    return {
        lease:           null,
        loading:         true,
        tab:             'overview',
        actionInProgress: false,
        activating:      false,
        closing:         false,
        actionError:     null,
        showCloseModal:  false,
        invoices:        [],
        invoicesLoading: false,
        closeForm: {
            actual_return_date: new Date().toISOString().slice(0,10),
            mileage_at_end:     '',
            close_notes:        '',
        },

        async init() {
            await this.loadLease();
        },

        // FIX #40: Load invoices for this lease
        async loadInvoices() {
            if (this.invoices.length > 0) return; // already loaded
            this.invoicesLoading = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/invoices') ?>?lease_id=<?= $leaseId ?>&per_page=50&sort=created_at&dir=DESC');
                if (r.success) this.invoices = r.data.items || [];
            } catch(e) { /* non-fatal */ }
            this.invoicesLoading = false;
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
