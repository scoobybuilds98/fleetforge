<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/payments.php
 *
 * QBO Payment Sync admin — read-only operator surface for the
 * bidirectional payment sync pipeline (S-QBO-13 pull + S-QBO-14 push).
 * Mirror of /quickbooks/bills with payment-specific deltas:
 *
 *   - Bidirectional: shows BOTH push-originated (origin='ff_native',
 *     S-QBO-14) AND pull-originated (origin='qbo_payments_webhook',
 *     S-QBO-13) rows. push_status='pulled_from_qbo' is the terminal
 *     state for webhook-originated rows per D-QBO-13-2.
 *   - Origin column shown as a separate badge (ff_native = green,
 *     qbo_payments_webhook = blue, qbo_other = grey).
 *   - Retry button DISABLED for non-ff_native rows (D-QBO-14-1
 *     bidirectional dedup; re-pushing webhook-originated payments
 *     creates duplicates in QBO).
 *   - Linked invoice column shows qbo_linked_invoice_id + FF invoice
 *     number via allocation lookup.
 *   - 8 KPI tiles include 'pulled_from_qbo' as a first-class tile
 *     (visibility into webhook-pull activity).
 *
 * @session  S-QBO-PAYMENT-SYNC-UI (follow-up to S-QBO-13 + S-QBO-14)
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 + §8.5
 * @gate     require_permission('quickbooks', 'view') for read; retry needs edit_credentials
 * @decision D-QBO-14-1 (bidirectional dedup at retry surface),
 *           D-QBO-13-1 (origin column display),
 *           D-QBO-13-2 (pulled_from_qbo terminal state badge)
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Payments';
require_once FF_ROOT . '/includes/header.php';

$canEditCredentials = can('quickbooks', 'edit_credentials');
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Payments</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Payment Sync</h1>
    <div class="text-secondary text-sm" style="margin-top:4px;">
        Bidirectional payment sync surface (Phase QBO-6 / S-QBO-13 pull + S-QBO-14 push).
        FF-native payments enqueue at create-time (api/v1/payments/create.php → PaymentEnqueuer);
        QBO-Payments-originated rows arrive via webhook (api/v1/webhooks/qbo_payment_notifications.php
        → PaymentWebhookHandler). The <code>origin</code> badge distinguishes the two flows.
        Per D-QBO-14-1 bidirectional dedup, Retry is disabled on non-ff_native rows
        (webhook-originated payments are already in QBO; re-pushing creates duplicates).
    </div>
</div>

<div x-data="qboPaymentsAdmin(<?= $canEditCredentials ? 'true' : 'false' ?>)" x-init="init()">

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
            <div class="kpi-label">Pulled (Webhook)</div>
            <div class="kpi-value text-info" x-text="kpis.pulled_from_qbo">0</div>
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
            <div class="kpi-label">Mode-Skipped</div>
            <div class="kpi-value text-secondary" x-text="kpis.skipped_by_mode">0</div>
        </div>
    </div>

    <!-- ── Filter bar (status + origin) ────────────────────────── -->
    <div class="card" style="padding:12px 18px;margin-bottom:14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <div class="text-sm text-secondary">Status:</div>
        <template x-for="s in ['pending','pushed','voided','failed','failed_preflight','skipped_voided','skipped_by_mode','pulled_from_qbo']" :key="s">
            <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;font-size:0.825rem;">
                <input type="checkbox" :value="s" x-model="filters.statuses" @change="page=1; reload()">
                <span x-text="s"></span>
            </label>
        </template>
        <div class="text-sm text-secondary" style="margin-left:14px;">Origin:</div>
        <template x-for="o in ['ff_native','qbo_payments_webhook','qbo_other']" :key="o">
            <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;font-size:0.825rem;">
                <input type="checkbox" :value="o" x-model="filters.origins" @change="page=1; reload()">
                <span x-text="o"></span>
            </label>
        </template>
        <button class="btn btn-secondary btn-sm" style="margin-left:auto;"
                @click="filters.statuses = []; filters.origins = []; page=1; reload()">
            Clear filters
        </button>
    </div>

    <!-- ── Main table ──────────────────────────────────────────── -->
    <div class="card" style="padding:0;">
        <table class="table table-striped" style="margin:0;">
            <thead>
                <tr>
                    <th>FF Payment</th>
                    <th>Customer</th>
                    <th class="text-right">Amount</th>
                    <th>Allocation</th>
                    <th>QBO Id</th>
                    <th>Origin</th>
                    <th>Status</th>
                    <th>Last Synced</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading">
                    <tr><td colspan="9" class="text-center text-secondary" style="padding:24px;">Loading…</td></tr>
                </template>
                <template x-if="!loading && rows.length === 0">
                    <tr><td colspan="9" class="text-center text-secondary" style="padding:24px;">
                        No payment sync activity yet. Payments will appear here on create (FF→QBO push) or webhook (QBO→FF pull).
                    </td></tr>
                </template>
                <template x-for="row in rows" :key="row.id">
                    <tr>
                        <td>
                            <template x-if="row.ff_payment_id">
                                <a :href="ffPaymentUrl(row.ff_payment_id)" x-text="row.payment_number || ('#' + row.ff_payment_id)"></a>
                            </template>
                            <template x-if="!row.ff_payment_id">
                                <span class="text-secondary">— (unmatched webhook)</span>
                            </template>
                            <div class="text-xs text-secondary" x-text="row.payment_date || row.qbo_txn_date || ''"></div>
                        </td>
                        <td>
                            <span x-text="row.customer_name || '—'"></span>
                            <template x-if="row.reference_number">
                                <div class="text-xs text-secondary">Ref: <span x-text="row.reference_number"></span></div>
                            </template>
                        </td>
                        <td class="text-right font-mono">
                            <span x-text="formatMoney(row.ff_amount || row.qbo_total_amt, row.ff_currency || row.qbo_currency)"></span>
                        </td>
                        <td class="text-sm">
                            <template x-if="row.first_invoice_number">
                                <div>
                                    <span x-text="row.first_invoice_number"></span>
                                    <template x-if="row.allocation_count > 1">
                                        <span class="text-secondary" x-text="' +' + (row.allocation_count - 1)"></span>
                                    </template>
                                </div>
                            </template>
                            <template x-if="row.qbo_linked_invoice_id">
                                <div class="text-xs text-secondary font-mono">→ QBO Inv <span x-text="row.qbo_linked_invoice_id"></span></div>
                            </template>
                        </td>
                        <td class="font-mono text-sm" x-text="row.qbo_payment_id || '—'"></td>
                        <td>
                            <span class="badge" :class="originBadgeClass(row.origin)" x-text="row.origin"></span>
                        </td>
                        <td>
                            <span class="badge" :class="statusBadgeClass(row.push_status)" x-text="row.push_status"></span>
                            <template x-if="row.push_error">
                                <div class="text-xs text-danger" style="margin-top:4px;cursor:help;" :title="row.push_error">
                                    <span x-text="truncate(row.push_error, 80)"></span>
                                </div>
                            </template>
                        </td>
                        <td class="text-sm text-secondary font-mono"
                            x-text="formatTs(row.last_synced_at || row.pushed_at || row.pulled_at)"></td>
                        <td>
                            <template x-if="canRetry && row.origin === 'ff_native' && ['failed','failed_preflight'].includes(row.push_status)">
                                <button class="btn btn-secondary btn-xs" @click="retry(row.id)" :disabled="retrying[row.id]">
                                    <span x-show="!retrying[row.id]">Retry</span>
                                    <span x-show="retrying[row.id]" x-cloak>…</span>
                                </button>
                            </template>
                            <template x-if="row.origin !== 'ff_native' && ['failed','failed_preflight'].includes(row.push_status)">
                                <span class="text-xs text-secondary" title="D-QBO-14-1: webhook-originated payments can't be re-pushed">no retry</span>
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
function qboPaymentsAdmin(canEdit) {
    return {
        canRetry: canEdit,
        loading: false,
        rows: [],
        kpis: { pushed: 0, pending: 0, voided: 0, failed: 0, failed_preflight: 0,
                skipped_voided: 0, skipped_by_mode: 0, pulled_from_qbo: 0 },
        page: 1,
        perPage: 25,
        total: 0,
        filters: { statuses: [], origins: [] },
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
                if (this.filters.origins.length > 0) {
                    params.set('origin', this.filters.origins.join(','));
                }
                const r = await FF_Api.get('<?= base_url('api/v1/quickbooks/payments/list') ?>?' + params.toString());
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
                const r = await FF_Api.post('<?= base_url('api/v1/quickbooks/payments/retry') ?>', { id: mappingId });
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

        ffPaymentUrl(id) {
            return '<?= base_url('payments/show?id=') ?>' + id;
        },

        statusBadgeClass(s) {
            return {
                'pushed': 'badge-success',
                'pulled_from_qbo': 'badge-info',
                'pending': 'badge-secondary',
                'voided': 'badge-secondary',
                'failed': 'badge-danger',
                'failed_preflight': 'badge-warning',
                'skipped_voided': 'badge-secondary',
                'skipped_by_mode': 'badge-secondary',
            }[s] || 'badge-secondary';
        },

        originBadgeClass(o) {
            return {
                'ff_native': 'badge-success',
                'qbo_payments_webhook': 'badge-info',
                'qbo_other': 'badge-secondary',
            }[o] || 'badge-secondary';
        },

        formatMoney(amt, ccy) {
            if (amt == null) return '—';
            const sym = (ccy === 'USD') ? 'US$' : '$';
            return sym + parseFloat(amt).toFixed(2);
        },

        formatTs(ts) {
            if (!ts) return '—';
            return ts.replace('T', ' ').substring(0, 16);
        },

        truncate(s, n) {
            if (!s) return '';
            return s.length > n ? s.substring(0, n) + '…' : s;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
