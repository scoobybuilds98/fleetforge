<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/bill_payments.php
 *
 * QBO Bill Payment Push admin — operator surface for the FF→QBO bill
 * payment push pipeline (Phase QBO-8 / S-QBO-19). Mirror of
 * /quickbooks/bills with bill-payment-specific deltas:
 *   - 10 KPI tiles incl. typed preflight sub-states (currency_mismatch +
 *     field_too_long)
 *   - Pay Type column (Check / CreditCard per D-QBO-19-2)
 *   - Bank Account column (qbo_bank_account_id snapshot per D-QBO-19-3)
 *   - Linked Bill column (first allocation + count badge)
 *   - Retry button on failed / failed_preflight* rows
 *
 * @session  S-QBO-19
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.9 + §6.8
 * @gate     require_permission('quickbooks', 'view') for read; retry needs edit_credentials
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Bill Payments';
require_once FF_ROOT . '/includes/header.php';

$canEditCredentials = can('quickbooks', 'edit_credentials');
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Bill Payments</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Bill Payment Push</h1>
    <div class="text-secondary text-sm" style="margin-top:4px;">
        Read-only visibility into the FF→QBO bill payment push pipeline (Phase QBO-8 / S-QBO-19).
        FF ap_payments enqueue on create (status='cleared') via api/v1/accounting/ap-payments/create.php;
        the worker picks them up + creates QBO BillPayment entities with LinkedTxn[].TxnType='Bill'
        per D-QBO-19-4. PayType maps from FF payment_method per D-QBO-19-2; BankAccountRef from
        acc_qbo_account_map lookup per D-QBO-19-3. Retry failed pushes from this page; investigate
        failed_preflight states by checking the listed reason (typically unmapped vendor, unmapped bank
        account, or per-allocation bill unmapped).
    </div>
</div>

<div x-data="qboBillPaymentsAdmin(<?= $canEditCredentials ? 'true' : 'false' ?>)">

    <!-- Flash strip -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ── 10 KPI tiles ─────────────────────────────────────────── -->
    <div class="kpi-grid kpi-grid--qbo" style="grid-template-columns:repeat(5,1fr);margin-bottom:14px;">
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
            <div class="kpi-label">Currency Mismatch</div>
            <div class="kpi-value text-warning" x-text="kpis.failed_preflight_currency_mismatch">0</div>
        </div>
    </div>
    <div class="kpi-grid kpi-grid--qbo" style="grid-template-columns:repeat(5,1fr);margin-bottom:14px;">
        <div class="kpi-tile">
            <div class="kpi-label">Field Too Long</div>
            <div class="kpi-value text-warning" x-text="kpis.failed_preflight_field_too_long">0</div>
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
    <div class="card" style="padding:12px 18px;margin-bottom:14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <div class="text-sm text-secondary">Filter by status:</div>
        <template x-for="s in ['pending','pushed','voided','failed','failed_preflight','failed_preflight_currency_mismatch','failed_preflight_field_too_long','skipped_voided','skipped_unmapped_void','skipped_by_mode']" :key="s">
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
                    <th>FF Payment</th>
                    <th>Vendor</th>
                    <th class="text-right">Amount</th>
                    <th>Pay Type</th>
                    <th>Bank Account</th>
                    <th>Linked Bill</th>
                    <th>QBO Id</th>
                    <th>Status</th>
                    <th>Pushed At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading">
                    <tr><td colspan="10" class="text-center text-secondary" style="padding:24px;">Loading…</td></tr>
                </template>
                <template x-if="!loading && rows.length === 0">
                    <tr><td colspan="10" class="text-center text-secondary" style="padding:24px;">
                        No bill payment push activity yet. Bill payments will appear here on create (after status='cleared' via /accounting/ap-payments/create).
                    </td></tr>
                </template>
                <template x-for="row in rows" :key="row.id">
                    <tr>
                        <td>
                            <a :href="ffApPaymentUrl(row.ff_ap_payment_id)" x-text="row.payment_number || ('#' + row.ff_ap_payment_id)"></a>
                            <div class="text-xs text-secondary" x-text="row.payment_date"></div>
                        </td>
                        <td>
                            <span x-text="row.vendor_name || '—'"></span>
                            <template x-if="row.check_number">
                                <div class="text-xs text-secondary">Check #<span x-text="row.check_number"></span></div>
                            </template>
                        </td>
                        <td class="text-right font-mono">
                            <span x-text="formatMoney(row.ff_amount, row.ff_currency)"></span>
                        </td>
                        <td>
                            <span class="badge badge-secondary" x-text="row.qbo_pay_type || ffMethodToType(row.payment_method)"></span>
                        </td>
                        <td class="text-sm">
                            <span x-text="row.bank_account_name || '—'"></span>
                            <template x-if="row.qbo_bank_account_id">
                                <div class="text-xs text-secondary font-mono">→ QBO <span x-text="row.qbo_bank_account_id"></span></div>
                            </template>
                        </td>
                        <td class="text-sm">
                            <template x-if="row.first_bill_number">
                                <div>
                                    <span x-text="row.first_bill_number"></span>
                                    <template x-if="row.allocation_count > 1">
                                        <span class="text-secondary" x-text="' +' + (row.allocation_count - 1)"></span>
                                    </template>
                                </div>
                            </template>
                        </td>
                        <td class="font-mono text-sm" x-text="row.qbo_bill_payment_id || '—'"></td>
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
                            <template x-if="canRetry && ['failed','failed_preflight','failed_preflight_currency_mismatch','failed_preflight_field_too_long'].includes(row.push_status)">
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
function qboBillPaymentsAdmin(canEdit) {
    return {
        canRetry: canEdit,
        loading: false,
        rows: [],
        kpis: {
            pushed: 0, pending: 0, voided: 0, failed: 0,
            failed_preflight: 0,
            failed_preflight_currency_mismatch: 0,
            failed_preflight_field_too_long: 0,
            skipped_voided: 0, skipped_unmapped_void: 0, skipped_by_mode: 0,
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
                const r = await FF_Api.get('<?= base_url('api/v1/quickbooks/bill_payments/list') ?>?' + params.toString());
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
                const r = await FF_Api.post('<?= base_url('api/v1/quickbooks/bill_payments/retry') ?>', { id: mappingId });
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

        ffApPaymentUrl(id) {
            return '<?= base_url('accounting/ap-payments/show?id=') ?>' + id;
        },

        ffMethodToType(method) {
            return {
                'check': 'Check', 'eft': 'Check', 'wire': 'Check',
                'credit_card': 'CreditCard',
                'cash': 'Check', 'other': 'Check',
            }[method] || 'Check';
        },

        statusBadgeClass(s) {
            return {
                'pushed': 'badge-success',
                'pending': 'badge-secondary',
                'voided': 'badge-secondary',
                'failed': 'badge-danger',
                'failed_preflight': 'badge-warning',
                'failed_preflight_currency_mismatch': 'badge-warning',
                'failed_preflight_field_too_long': 'badge-warning',
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

        formatTs(ts) { return ts ? ts.replace('T', ' ').substring(0, 16) : '—'; },
        truncate(s, n) { if (!s) return ''; return s.length > n ? s.substring(0, n) + '…' : s; },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
