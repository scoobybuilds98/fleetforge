<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/refund_receipts.php
 *
 * QBO Refund Receipt Push admin — operator surface for the FF→QBO cash
 * refund-receipt push pipeline (Phase QBO-7 / S-QBO-17, CLOSES Phase QBO-7).
 * Sibling of /quickbooks/credit_memos with refund-specific deltas:
 *   - 6 KPI tiles (pushed / pending / failed / pre-flight / field-too-long / mode-skipped)
 *   - Lease (contract) + Customer + Amount columns
 *   - QBO RefundReceipt Id + Settled-At columns
 *   - Retry button on failed / failed_preflight* rows
 *
 * Cash refunds only (D-QBO-17-2) — credit refunds push via /quickbooks/credit_memos.
 *
 * @session  S-QBO-17
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.7 + §6.8
 * @gate     require_permission('quickbooks', 'view') for read; retry needs edit_credentials
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Refund Receipts';
require_once FF_ROOT . '/includes/header.php';

$canEditCredentials = can('quickbooks', 'edit_credentials');
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Refund Receipts</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Refund Receipt Push</h1>
    <div class="text-secondary text-sm" style="margin-top:4px;">
        Read-only visibility into the FF→QBO cash refund-receipt push pipeline (Phase QBO-7 / S-QBO-17).
        When an operator marks a cash precharge refund settled (Leases → Mark Refund Settled), FF enqueues
        a QBO RefundReceipt. Credit-method refunds are NOT shown here — they push via Credit Memos.
        <strong>Operator pre-reqs:</strong> the refund <em>deposit account</em> + <em>payment method</em>
        (cheque) must be configured on Settings, and the QBO “Automatically apply credits” caveat does not
        apply to refunds. Tax treatment is currently pushed as non-taxable (TaxCodeRef = NON) pending
        accountant confirmation (live-verify follow-up). Retry failed pushes from this page.
    </div>
</div>

<div x-data="qboRefundReceiptsAdmin(<?= $canEditCredentials ? 'true' : 'false' ?>)">

    <!-- Flash strip -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ── 6 KPI tiles ──────────────────────────────────────────── -->
    <div class="kpi-grid kpi-grid--qbo" style="grid-template-columns:repeat(6,1fr);margin-bottom:14px;">
        <div class="kpi-tile">
            <div class="kpi-label">Pushed</div>
            <div class="kpi-value text-success" x-text="kpis.pushed">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Pending</div>
            <div class="kpi-value" x-text="kpis.pending">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Failed</div>
            <div class="kpi-value text-danger" x-text="kpis.failed">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Pre-flight Block</div>
            <div class="kpi-value text-warning" x-text="kpis.failed_preflight">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Field Too Long</div>
            <div class="kpi-value text-warning" x-text="kpis.failed_preflight_field_too_long">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Mode-Skipped</div>
            <div class="kpi-value text-secondary" x-text="kpis.skipped_by_mode">0</div>
        </div>
    </div>

    <!-- ── Filter bar ──────────────────────────────────────────── -->
    <div class="card" style="padding:12px 18px;margin-bottom:14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <div class="text-sm text-secondary">Filter by status:</div>
        <template x-for="s in ['pending','pushed','failed','failed_preflight','failed_preflight_field_too_long','skipped_by_mode']" :key="s">
            <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;font-size:0.825rem;">
                <input type="checkbox" :value="s" x-model="filters.statuses" @change="page=1; reload()">
                <span x-text="s"></span>
            </label>
        </template>
        <button class="btn btn-secondary btn-sm" style="margin-left:auto;" @click="filters.statuses = []; page=1; reload()">
            Clear filters
        </button>
    </div>

    <!-- ── Main table ──────────────────────────────────────────── -->
    <div class="card" style="padding:0;">
        <table class="table table-striped" style="margin:0;">
            <thead>
                <tr>
                    <th>Lease</th>
                    <th>Customer</th>
                    <th class="text-right">Refund Amount</th>
                    <th>QBO RefundReceipt Id</th>
                    <th>Status</th>
                    <th>Settled / Pushed</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading">
                    <tr><td colspan="7" class="text-center text-secondary" style="padding:24px;">Loading…</td></tr>
                </template>
                <template x-if="!loading && rows.length === 0">
                    <tr><td colspan="7" class="text-center text-secondary" style="padding:24px;">
                        No refund receipt push activity yet. Settled cash refunds will appear here once QBO sync is enabled.
                    </td></tr>
                </template>
                <template x-for="row in rows" :key="row.id">
                    <tr>
                        <td>
                            <a :href="ffLeaseUrl(row.ff_lease_id)"
                               x-text="row.contract_number || row.ff_contract_number_snapshot || ('Lease #' + row.ff_lease_id)"></a>
                        </td>
                        <td class="text-sm" x-text="row.customer_name || ('#' + row.customer_id)"></td>
                        <td class="text-right font-mono">
                            <span x-text="formatMoney(row.ff_refund_amount_snapshot || row.precharge_balance, row.qbo_currency || row.lease_currency)"></span>
                        </td>
                        <td class="font-mono text-sm" x-text="row.qbo_refund_receipt_id || '—'"></td>
                        <td>
                            <span class="badge" :class="statusBadgeClass(row.push_status)" x-text="row.push_status"></span>
                            <template x-if="row.push_error">
                                <div class="text-xs text-danger" style="margin-top:4px;cursor:help;" :title="row.push_error">
                                    <span x-text="truncate(row.push_error, 80)"></span>
                                </div>
                            </template>
                        </td>
                        <td class="text-sm text-secondary font-mono"
                            x-text="row.pushed_at ? formatTs(row.pushed_at) : (row.precharge_refund_settled_at ? formatTs(row.precharge_refund_settled_at) : '—')"></td>
                        <td>
                            <template x-if="canRetry && ['failed','failed_preflight','failed_preflight_field_too_long'].includes(row.push_status)">
                                <button class="btn btn-secondary btn-xs" @click="retry(row.id)" :disabled="retrying[row.id]">
                                    <span x-show="!retrying[row.id]">Retry</span>
                                    <span x-show="retrying[row.id]" x-cloak>…</span>
                                </button>
                            </template>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;">
        <div class="text-sm text-secondary">
            Showing <span x-text="rows.length"></span> of <span x-text="total"></span>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-secondary btn-sm" @click="page = Math.max(1, page-1); reload()" :disabled="page <= 1">Prev</button>
            <span class="text-sm text-secondary" style="align-self:center;">Page <span x-text="page"></span></span>
            <button class="btn btn-secondary btn-sm" @click="page++; reload()" :disabled="rows.length < perPage">Next</button>
        </div>
    </div>
</div>

<script>
function qboRefundReceiptsAdmin(canEdit) {
    return {
        canRetry: canEdit,
        loading: false,
        rows: [],
        kpis: {
            pushed: 0, pending: 0, failed: 0,
            failed_preflight: 0,
            failed_preflight_field_too_long: 0,
            skipped_by_mode: 0,
        },
        page: 1,
        perPage: 25,
        total: 0,
        filters: { statuses: [] },
        retrying: {},
        flash: { type: '', message: '' },

        async init() { await this.reload(); },

        async reload() {
            this.loading = true;
            try {
                const params = new URLSearchParams({ page: this.page, per_page: this.perPage });
                if (this.filters.statuses.length > 0) {
                    params.set('status', this.filters.statuses.join(','));
                }
                const r = await FF_Api.get('<?= base_url('api/v1/quickbooks/refund_receipts/list') ?>?' + params.toString());
                if (r.success) {
                    this.rows = r.rows || [];
                    this.kpis = r.kpis || this.kpis;
                    this.total = r.total || 0;
                }
            } catch (e) {
                this.flash = { type: 'danger', message: 'Failed to load: ' + (e.message || e) };
            } finally {
                this.loading = false;
            }
        },

        async retry(mappingId) {
            this.retrying[mappingId] = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/quickbooks/refund_receipts/retry') ?>', { id: mappingId });
                if (r.success) {
                    if (r.action === 'enqueued') {
                        this.flash = { type: 'success', message: 'Re-enqueued for push.' };
                    } else {
                        this.flash = { type: 'danger', message: 'Skipped: ' + (r.reason || 'gate refused') };
                    }
                    await this.reload();
                }
            } catch (e) {
                this.flash = { type: 'danger', message: 'Retry failed: ' + (e.message || e) };
            } finally {
                this.retrying[mappingId] = false;
            }
        },

        ffLeaseUrl(id) { return '<?= base_url('leases/show?id=') ?>' + id; },

        statusBadgeClass(s) {
            return {
                'pushed': 'badge-success',
                'pending': 'badge-secondary',
                'failed': 'badge-danger',
                'failed_preflight': 'badge-warning',
                'failed_preflight_field_too_long': 'badge-warning',
                'skipped_by_mode': 'badge-secondary',
            }[s] || 'badge-secondary';
        },

        formatMoney(amt, ccy) {
            if (amt == null) return '—';
            const sym = (ccy === 'USD') ? 'US$' : '$';
            return sym + parseFloat(amt).toFixed(2);
        },

        formatTs(ts) { return ts ? ts.replace('T', ' ').substring(0, 16) : '—'; },
        truncate(s, n) { if (!s) return ''; return s.length > n ? s.substring(0, n) + '…' : s; },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
