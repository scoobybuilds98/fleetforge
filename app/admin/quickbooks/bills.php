<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/bills.php
 *
 * QBO Bill Push admin — read-only operator surface for troubleshooting
 * the FF→QBO bill push pipeline. Operator works with bills primarily
 * via /admin/accounting/bills/* (the existing FF accounting UI); this
 * page exists for QBO-specific state visibility + retry actions on
 * failed pushes.
 *
 * Surfaces:
 *   - 8 KPI tiles (pushed / pending / failed / failed_preflight /
 *     voided / skipped_voided / skipped_unmapped_void / skipped_by_mode counts)
 *   - Filter by push_status
 *   - Recent push activity table (last 25, paginated)
 *   - Retry button on failed / failed_preflight rows (re-enqueues
 *     via BillEnqueuer per D-QBO-18-1)
 *
 * @session  S-QBO-BILL-SYNC-UI (follow-up to S-QBO-18)
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher visibility surface)
 * @gate     require_permission('quickbooks', 'view') for read; retry needs edit_credentials
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Bills';
require_once FF_ROOT . '/includes/header.php';

$canEditCredentials = can('quickbooks', 'edit_credentials');
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Bills</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Bill Push</h1>
    <div class="text-secondary text-sm" style="margin-top:4px;">
        Read-only visibility into the FF→QBO bill push pipeline (Phase QBO-8 / S-QBO-18).
        Bills enqueue when approved (draft→approved in FF accounting); the worker picks them up +
        creates QBO Bill entities with vendor-mapped expense lines (D-QBO-18-3 AccountBasedExpenseLineDetail).
        Retry failed pushes from this page; investigate failed_preflight states by checking the
        listed reason (usually unmapped vendor, unmapped expense account, or unmapped ap_clearing).
    </div>
</div>

<div x-data="qboBillsAdmin(<?= $canEditCredentials ? 'true' : 'false' ?>)">

    <!-- Flash strip -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ── 8 KPI tiles ─────────────────────────────────────────── -->
    <div class="kpi-grid kpi-grid--qbo" style="grid-template-columns:repeat(8,1fr);margin-bottom:14px;">
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
            <div class="kpi-label">Voided</div>
            <div class="kpi-value text-secondary" x-text="kpis.voided">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Skipped Void</div>
            <div class="kpi-value text-secondary" x-text="kpis.skipped_voided">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Unmapped Void</div>
            <div class="kpi-value text-secondary" x-text="kpis.skipped_unmapped_void">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Mode-Skipped</div>
            <div class="kpi-value text-secondary" x-text="kpis.skipped_by_mode">0</div>
        </div>
    </div>

    <!-- ── Filter bar ──────────────────────────────────────────── -->
    <!-- ── FILTER TOOLBAR ──────────────────────────────────────── -->
    <!-- S-LIST-TOOLBAR: same .table-toolbar shape as customers/invoices. The
         status control stays a checkbox set rather than becoming a <select>:
         push status is genuinely multi-select here, and a single-value select
         would drop that. Clear + row count sit on the right. -->
    <div class="table-toolbar">

        <div class="table-toolbar-left table-toolbar-left--wrap">
            <span class="text-secondary text-sm" style="white-space:nowrap;">Status:</span>
            <template x-for="s in ['pending','pushed','voided','failed','failed_preflight','skipped_voided','skipped_unmapped_void','skipped_by_mode']" :key="s">
                <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;font-size:0.825rem;">
                    <input type="checkbox" :value="s" x-model="filters.statuses" @change="page=1; reload()">
                    <span x-text="s"></span>
                </label>
            </template>
        </div>

        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-text="total + ' row' + (total === 1 ? '' : 's')"></span>
            <button class="btn btn-secondary btn-sm"
                    @click="filters.statuses = []; page=1; reload()">Reset</button>
        </div>

    </div>

    <!-- ── Main table ──────────────────────────────────────────── -->
    <div class="card" style="padding:0;">
        <table class="table table-striped" style="margin:0;">
            <thead>
                <tr>
                    <th>FF Bill</th>
                    <th>Vendor</th>
                    <th class="text-right">Total</th>
                    <th>QBO Id</th>
                    <th>QBO Doc#</th>
                    <th>Status</th>
                    <th>Pushed At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading">
                    <tr><td colspan="8" class="text-center text-secondary" style="padding:24px;">Loading…</td></tr>
                </template>
                <template x-if="!loading && rows.length === 0">
                    <tr><td colspan="8" class="text-center text-secondary" style="padding:24px;">
                        No bill push activity yet. Bills will appear here once the first one is approved (draft→approved in /accounting/bills).
                    </td></tr>
                </template>
                <template x-for="row in rows" :key="row.id">
                    <tr>
                        <td>
                            <a :href="ffBillUrl(row.ff_bill_id)" x-text="row.bill_number || ('#' + row.ff_bill_id)"></a>
                            <div class="text-xs text-secondary" x-text="row.bill_date"></div>
                        </td>
                        <td>
                            <span x-text="row.vendor_name || '—'"></span>
                            <template x-if="row.vendor_bill_number">
                                <div class="text-xs text-secondary">Vendor ref: <span x-text="row.vendor_bill_number"></span></div>
                            </template>
                        </td>
                        <td class="text-right font-mono">
                            <span x-text="formatMoney(row.total_amount, row.ff_currency)"></span>
                            <template x-if="row.balance_due && parseFloat(row.balance_due) > 0">
                                <div class="text-xs text-secondary">Bal: <span x-text="formatMoney(row.balance_due, row.ff_currency)"></span></div>
                            </template>
                        </td>
                        <td class="font-mono text-sm" x-text="row.qbo_bill_id || '—'"></td>
                        <td class="text-sm" x-text="row.qbo_doc_number || '—'"></td>
                        <td>
                            <span class="badge" :class="statusBadgeClass(row.push_status)" x-text="row.push_status"></span>
                            <template x-if="row.push_error">
                                <div class="text-xs text-danger" style="margin-top:4px;cursor:help;" :title="row.push_error">
                                    <span x-text="truncate(row.push_error, 80)"></span>
                                </div>
                            </template>
                        </td>
                        <td class="text-sm text-secondary font-mono" x-text="row.pushed_at ? formatTs(row.pushed_at) : '—'"></td>
                        <td>
                            <template x-if="canRetry && ['failed','failed_preflight'].includes(row.push_status)">
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
function qboBillsAdmin(canEdit) {
    return {
        canRetry: canEdit,
        loading: false,
        rows: [],
        kpis: { pushed: 0, pending: 0, voided: 0, failed: 0, failed_preflight: 0,
                skipped_voided: 0, skipped_unmapped_void: 0, skipped_by_mode: 0 },
        page: 1,
        perPage: 25,
        total: 0,
        filters: { statuses: [] },
        retrying: {},
        flash: { type: '', message: '' },

        async init() {
            await this.reload();
        },

        async reload() {
            this.loading = true;
            try {
                const params = new URLSearchParams({ page: this.page, per_page: this.perPage });
                if (this.filters.statuses.length > 0) {
                    params.set('status', this.filters.statuses.join(','));
                }
                const r = await FF_Api.get('<?= base_url('api/v1/quickbooks/bills/list') ?>?' + params.toString());
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
                const r = await FF_Api.post('<?= base_url('api/v1/quickbooks/bills/retry') ?>', { id: mappingId });
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

        ffBillUrl(id) {
            return '<?= base_url('accounting/bills/show?id=') ?>' + id;
        },

        statusBadgeClass(s) {
            return {
                'pushed': 'badge-success',
                'pending': 'badge-secondary',
                'voided': 'badge-secondary',
                'failed': 'badge-danger',
                'failed_preflight': 'badge-warning',
                'skipped_voided': 'badge-secondary',
                'skipped_unmapped_void': 'badge-secondary',
                'skipped_by_mode': 'badge-secondary',
            }[s] || 'badge-secondary';
        },

        formatMoney(amt, ccy) {
            if (amt == null) return '—';
            const sym = (ccy === 'USD') ? 'US$' : '$';
            return sym + parseFloat(amt).toFixed(2);
        },

        formatTs(ts) {
            return ts ? ts.replace('T', ' ').substring(0, 16) : '—';
        },

        truncate(s, n) {
            if (!s) return '';
            return s.length > n ? s.substring(0, n) + '…' : s;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
