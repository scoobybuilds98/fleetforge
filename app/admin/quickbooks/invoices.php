<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/invoices.php
 *
 * QBO Invoice Push admin — minimal read-only operator surface for
 * troubleshooting the FF→QBO invoice push pipeline. Operator works
 * with invoices primarily via /admin/invoices/* (the existing FF
 * invoice UI); this page exists for QBO-specific state visibility +
 * retry actions on failed pushes.
 *
 * Surfaces:
 *   - 6 KPI tiles (pushed / pending / failed / failed_preflight /
 *     skipped_voided / skipped_by_mode counts)
 *   - Filter by push_status
 *   - Recent push activity table (last 25, paginated)
 *   - Retry button on failed / failed_preflight rows (re-enqueues
 *     via InvoiceEnqueuer per D-QBO-11-1)
 *
 * @session  S-QBO-11
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher visibility surface)
 * @gate     require_permission('quickbooks', 'view') for read; retry needs edit_credentials
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Invoices';
require_once FF_ROOT . '/includes/header.php';

$canEditCredentials = can('quickbooks', 'edit_credentials');
// S-QBO-GOLIVE-AUDIT: go-live linking creates FF payments and decides what
// reaches QuickBooks — the heavy QBO permission, like Manual Sync.
$canLink = can('quickbooks', 'force_full_resync');
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Invoices</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Invoice Push</h1>
    <div class="text-secondary text-sm" style="margin-top:4px;">
        Read-only visibility into the FF→QBO invoice push pipeline. Invoices enqueue when
        sent (draft→sent in FF); the worker picks them up + creates QBO Invoice entities.
        Retry failed pushes from this page; investigate failed_preflight states by checking
        the listed reason (usually unmapped customer or item type).
    </div>
</div>

<?php /* ── Go-live linker (S-QBO-GOLIVE-AUDIT) ─────────────────────────── */ ?>
<div x-data="qboCutoverLinker(<?= $canLink ? 'true' : 'false' ?>)" class="card" style="padding:18px;margin-bottom:18px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
        <div style="max-width:760px;">
            <h2 class="h5" style="margin:0 0 4px;">Go-live: link documents QuickBooks already has</h2>
            <div class="text-secondary text-sm">
                Invoices, credit notes and bills dated before go-live were entered in QuickBooks by hand. Link each one to its
                QuickBooks copy so FleetForge never pushes a duplicate, and so the payments QuickBooks holds show up
                in FleetForge (payment, invoice status and books). Linking never changes anything in QuickBooks, and
                FleetForge never edits or voids a linked QuickBooks document afterwards.
            </div>
        </div>
        <template x-if="canLink">
            <button class="btn btn-secondary btn-sm" @click="catchUpPayments()" :disabled="busy"
                    title="For every open FleetForge invoice and bill that is in QuickBooks, bring in payments QuickBooks has and FleetForge does not.">
                Check QuickBooks for payments
            </button>
        </template>
    </div>

    <div x-show="msg.text" x-cloak :class="msg.type === 'success' ? 'alert alert-success' : (msg.type === 'warning' ? 'alert alert-warning' : 'alert alert-danger')"
         style="margin:12px 0 0;white-space:pre-line;" x-text="msg.text"></div>

    <div class="table-toolbar" style="margin-top:12px;">
        <div class="table-toolbar-left table-toolbar-left--wrap" style="gap:10px;">
            <select class="form-control form-control-sm" style="width:auto;" x-model="f.kind">
                <option value="invoice">Invoices</option>
                <option value="credit_memo">Credit notes</option>
                <option value="bill">Bills</option>
            </select>
            <label class="text-sm text-secondary">From <input type="date" class="form-control form-control-sm" style="width:auto;display:inline-block;" x-model="f.from"></label>
            <label class="text-sm text-secondary">To <input type="date" class="form-control form-control-sm" style="width:auto;display:inline-block;" x-model="f.to"></label>
            <label class="text-sm text-secondary" title="How many days apart the FleetForge and QuickBooks dates may be">
                Date window ±<input type="number" min="0" max="45" class="form-control form-control-sm" style="width:64px;display:inline-block;" x-model.number="f.window_days"> days
            </label>
            <label class="text-sm text-secondary" x-show="f.kind !== 'credit_memo'" style="display:inline-flex;gap:4px;align-items:center;">
                <input type="checkbox" x-model="f.include_drafts"> Include drafts
            </label>
            <button class="btn btn-primary btn-sm" @click="find()" :disabled="busy">
                <span x-show="!busy">Find matches</span><span x-show="busy" x-cloak>Working…</span>
            </button>
        </div>
    </div>

    <template x-if="searched">
        <div>
            <div class="text-sm" style="display:flex;gap:14px;flex-wrap:wrap;margin:8px 0;">
                <span><span class="badge badge-success">exact</span> <span x-text="summary.exact || 0"></span></span>
                <span><span class="badge badge-success">amount + date</span> <span x-text="summary.amount_date || 0"></span></span>
                <span><span class="badge badge-success">amount + unit</span> <span x-text="summary.amount_unit || 0"></span></span>
                <span><span class="badge badge-warning">same number, other amount</span> <span x-text="summary.doc_number || 0"></span></span>
                <span><span class="badge badge-warning">check</span> <span x-text="summary.review || 0"></span></span>
                <span><span class="badge badge-secondary">no match</span> <span x-text="summary.none || 0"></span></span>
                <span><span class="badge badge-danger">customer / vendor not linked</span> <span x-text="summary.customer_unmapped || 0"></span></span>
                <span x-show="summary.qbo_error"><span class="badge badge-danger">QuickBooks error</span> <span x-text="summary.qbo_error"></span></span>
                <span x-show="truncated" class="text-warning">Only the first 200 are shown — narrow the dates, link these, then search again.</span>
            </div>

            <div x-show="canLink && rows.length" style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                <button class="btn btn-secondary btn-sm" @click="selectConfident()">Select exact + amount/date/unit</button>
                <button class="btn btn-secondary btn-sm" @click="selectNone()">Clear selection</button>
                <button class="btn btn-primary btn-sm" @click="linkSelected()" :disabled="busy || selectedCount === 0">
                    Link selected (<span x-text="selectedCount"></span>)
                </button>
            </div>

            <div style="overflow-x:auto;">
            <table class="table table-striped" style="margin:0;">
                <thead>
                    <tr>
                        <th style="width:28px;"></th>
                        <th>FleetForge</th>
                        <th x-text="f.kind === 'bill' ? 'Vendor' : 'Customer'">Customer</th>
                        <th class="text-right">FF total</th>
                        <th>QuickBooks match</th>
                        <th>Match</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-if="rows.length === 0">
                        <tr><td colspan="7" class="text-center text-secondary" style="padding:18px;">Nothing to link — every document in this range is already linked or pushed.</td></tr>
                    </template>
                    <template x-for="r in rows" :key="r.key">
                        <tr>
                            <td><input type="checkbox" x-model="r.selected" :disabled="!canLink || !r.choice" @change="recount()"></td>
                            <td>
                                <span class="font-mono" x-text="r.number"></span>
                                <div class="text-xs text-secondary"><span x-text="r.doc_date"></span> · <span x-text="r.status"></span></div>
                            </td>
                            <td class="text-sm" x-text="r.customer"></td>
                            <td class="text-right font-mono" x-text="r.total_label"></td>
                            <td>
                                <template x-if="r.options.length">
                                    <select class="form-control form-control-sm" x-model="r.choice" @change="choiceChanged(r)" :disabled="!canLink">
                                        <option value="">— not in QuickBooks / skip —</option>
                                        <template x-for="o in r.options" :key="o.id">
                                            <option :value="o.id" x-text="o.label"></option>
                                        </template>
                                    </select>
                                </template>
                                <div class="text-xs text-secondary" x-show="r.note" x-text="r.note" style="margin-top:2px;"></div>
                                <div class="text-xs text-danger" x-show="r.error" x-text="r.error" style="margin-top:2px;"></div>
                            </td>
                            <td><span class="badge" :class="r.badge" x-text="r.conf_label"></span></td>
                            <td style="white-space:nowrap;">
                                <template x-if="canLink && r.needs_confirm">
                                    <button class="btn btn-warning btn-xs" @click="linkOne(r, true)" :disabled="busy">Link anyway</button>
                                </template>
                                <template x-if="canLink && !r.choice && r.can_push_new">
                                    <button class="btn btn-secondary btn-xs" @click="pushNew(r)" :disabled="busy"
                                            title="QuickBooks really does not have this one — push it as a new QuickBooks document">Push as new</button>
                                </template>
                                <span x-show="r.done" class="text-success text-sm" x-text="r.done"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            </div>
        </div>
    </template>
</div>

<div x-data="qboInvoicesAdmin(<?= $canEditCredentials ? 'true' : 'false' ?>, <?= $canLink ? 'true' : 'false' ?>)">

    <!-- Flash strip -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ── 7 KPI tiles (S-QBO-PUSHER-SKIP-RECORD-FIX-INVOICE: +Soft-Deleted) ── -->
    <div class="kpi-grid kpi-grid--qbo" style="grid-template-columns:repeat(7,1fr);margin-bottom:14px;">
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
            <div class="kpi-value text-secondary" x-text="kpis.skipped_voided">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Mode-Skipped</div>
            <div class="kpi-value text-secondary" x-text="kpis.skipped_by_mode">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Soft-Deleted</div>
            <div class="kpi-value text-secondary" x-text="kpis.skipped_soft_deleted">0</div>
        </div>
    </div>
    <div class="text-sm text-secondary" style="margin:-6px 0 12px;">
        Of the pushed invoices, <strong x-text="kpis.linked || 0"></strong> are go-live links to invoices QuickBooks already had.
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
            <template x-for="s in ['pending','pushed','failed','failed_preflight','failed_preflight_field_too_long','failed_preflight_currency_mismatch','skipped_voided','skipped_by_mode','skipped_soft_deleted']" :key="s">
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
                    <th>FF Invoice</th>
                    <th>Customer</th>
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
                        No invoice push activity yet. Invoices will appear here once the first one is sent (draft→sent).
                    </td></tr>
                </template>
                <template x-for="row in rows" :key="row.id">
                    <tr>
                        <td>
                            <a :href="ffInvoiceUrl(row.ff_invoice_id)" x-text="row.invoice_number || ('#' + row.ff_invoice_id)"></a>
                            <div class="text-xs text-secondary" x-text="row.invoice_date"></div>
                        </td>
                        <td>
                            <span x-text="row.company_name_snapshot || row.customer_name_snapshot || '—'"></span>
                        </td>
                        <td class="text-right font-mono">
                            <span x-text="formatMoney(row.total_amount, row.ff_currency)"></span>
                        </td>
                        <td class="font-mono text-sm" x-text="row.qbo_invoice_id || '—'"></td>
                        <td class="text-sm" x-text="row.qbo_doc_number || '—'"></td>
                        <td>
                            <span class="badge" :class="statusBadgeClass(row.push_status)" x-text="row.push_status"></span>
                            <span class="badge badge-info" x-show="row._linked" x-cloak title="Linked at go-live to an invoice QuickBooks already had — FleetForge never edits or voids it in QuickBooks">linked</span>
                            <template x-if="row.push_error">
                                <div class="text-xs text-danger" style="margin-top:4px;cursor:help;" :title="row.push_error">
                                    <span x-text="truncate(row.push_error, 80)"></span>
                                </div>
                            </template>
                        </td>
                        <td class="text-sm text-secondary font-mono" x-text="row.pushed_at ? formatTs(row.pushed_at) : '—'"></td>
                        <td>
                            <template x-if="canRetry && row._retryable">
                                <button class="btn btn-secondary btn-xs" @click="retry(row.id)" :disabled="retrying[row.id]">
                                    <span x-show="!retrying[row.id]">Retry</span>
                                    <span x-show="retrying[row.id]" x-cloak>…</span>
                                </button>
                            </template>
                            <template x-if="canLink && row._preGoLive">
                                <button class="btn btn-secondary btn-xs" @click="linkAction('push_new', row)" :disabled="retrying[row.id]"
                                        title="QuickBooks really does not have this invoice — push it as a new QuickBooks invoice">Push as new</button>
                            </template>
                            <template x-if="canLink && row._canImport">
                                <button class="btn btn-secondary btn-xs" @click="linkAction('import_payments', row)" :disabled="retrying[row.id]"
                                        title="Bring in payments QuickBooks has recorded against this invoice">Payments</button>
                            </template>
                            <template x-if="canLink && row._linked">
                                <button class="btn btn-secondary btn-xs" @click="linkAction('unlink', row)" :disabled="retrying[row.id]"
                                        title="Undo the go-live link">Unlink</button>
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
function qboInvoicesAdmin(canEdit, canLink) {
    return {
        canRetry: canEdit,
        canLink: canLink,
        loading: false,
        rows: [],
        kpis: { pushed: 0, pending: 0, failed: 0, failed_preflight: 0, failed_preflight_field_too_long: 0, failed_preflight_currency_mismatch: 0, skipped_voided: 0, skipped_by_mode: 0, skipped_soft_deleted: 0 },
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
                const r = await FF_Api.get('<?= base_url('api/v1/quickbooks/invoices/list') ?>?' + params.toString());
                if (r.success) {
                    // Envelope contract: json_success([...]) nests EVERY key under
                    // `data`, so these reads must go through r.data — reading
                    // r.rows / r.kpis / r.total off the envelope yields undefined,
                    // which the `|| []` / `|| 0` fallbacks silently turned into a
                    // permanently empty table and zeroed KPI tiles. Matches the
                    // `const d = j.data` convention already used by the
                    // accounts / customers / vendors / items / tax_codes consoles.
                    const d = r.data || {};
                    // Per-row flags computed once here, not by calls inside x-for.
                    const retryable = ['failed','failed_preflight','failed_preflight_field_too_long','failed_preflight_currency_mismatch'];
                    this.rows = (d.rows || []).map(row => Object.assign(row, {
                        _linked: row.origin === 'cutover_link',
                        _retryable: retryable.includes(row.push_status),
                        _preGoLive: row.push_status === 'failed_preflight' && !row.qbo_invoice_id
                            && (row.push_error || '').indexOf('before QuickBooks go-live') !== -1,
                        _canImport: !!row.qbo_invoice_id && ['sent','partially_paid','overdue'].includes(row.ff_status)
                            && parseFloat(row.balance_due || 0) > 0,
                    }));
                    this.kpis = d.kpis || this.kpis;
                    this.total = d.total || 0;
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
                const r = await FF_Api.post('<?= base_url('api/v1/quickbooks/invoices/retry') ?>', { id: mappingId });
                if (r.success) {
                    // Envelope contract (same class as reload above): retry.php
                    // emits json_success(['action' => …, 'reason' => …]), so the
                    // keys live under r.data. Reading r.action off the envelope
                    // was always undefined, which sent EVERY successful re-enqueue
                    // down the else branch and reported "Skipped: gate refused".
                    const d = r.data || {};
                    if (d.action === 'enqueued') {
                        this.flash = { type: 'success', message: 'Re-enqueued for push.' };
                    } else {
                        this.flash = { type: 'danger', message: 'Skipped: ' + (d.reason || 'gate refused') };
                    }
                    await this.reload();
                }
            } catch (e) {
                this.flash = { type: 'danger', message: 'Retry failed: ' + (e.message || e) };
            } finally {
                this.retrying[mappingId] = false;
            }
        },

        // S-QBO-GOLIVE-AUDIT: row actions backed by cutover_link.php.
        async linkAction(action, row) {
            if (action === 'unlink' && !confirm('Undo the go-live link of ' + (row.invoice_number || row.ff_invoice_id) + '? FleetForge will treat it as not in QuickBooks again.')) return;
            if (action === 'push_new' && !confirm('Push ' + (row.invoice_number || row.ff_invoice_id) + ' to QuickBooks as a NEW invoice? Only do this if QuickBooks does not already have it.')) return;
            this.retrying[row.id] = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/quickbooks/cutover_link') ?>', { action: action, kind: 'invoice', ff_id: row.ff_invoice_id });
                if (r.success) {
                    const d = r.data || {};
                    let m = { unlink: 'Link removed.', push_new: d.enqueued ? 'Queued to push as a new QuickBooks invoice.' : 'Released — it will push when the invoice is next sent or retried.' }[action];
                    if (action === 'import_payments') {
                        m = qboCutoverPaymentsText(d);
                    }
                    this.flash = { type: 'success', message: m };
                    await this.reload();
                } else {
                    this.flash = { type: 'danger', message: (r.error && r.error.message) || 'Action failed.' };
                }
            } catch (e) {
                this.flash = { type: 'danger', message: 'Action failed: ' + (e.message || e) };
            } finally {
                this.retrying[row.id] = false;
            }
        },

        ffInvoiceUrl(id) {
            return '<?= base_url('admin/invoices/show?id=') ?>' + id;
        },

        statusBadgeClass(s) {
            return {
                'pushed': 'badge-success',
                'pending': 'badge-secondary',
                'failed': 'badge-danger',
                'failed_preflight': 'badge-warning',
                'failed_preflight_field_too_long': 'badge-warning',
                'failed_preflight_currency_mismatch': 'badge-warning',
                'skipped_voided': 'badge-secondary',
                'skipped_by_mode': 'badge-secondary',
                'skipped_soft_deleted': 'badge-secondary',
            }[s] || 'badge-secondary';
        },

        formatMoney(amt, ccy) {
            if (amt == null) return '—';
            const sym = (ccy === 'USD') ? 'US$' : '$';
            return sym + parseFloat(amt).toFixed(2);
        },

        // S-UTC-STAMPS: the stamps shown here are UTC DATETIMEs — printing the
        // raw string showed UTC wall time. Same 'YYYY-MM-DD HH:MM' shape,
        // converted to the company timezone.
        formatTs(ts) {
            return ts ? FF_formatUtc(ts, { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).replace(',', '') : '—';
        },

        truncate(s, n) {
            if (!s) return '';
            return s.length > n ? s.substring(0, n) + '…' : s;
        },
    };
}

// ── Go-live linker (S-QBO-GOLIVE-AUDIT) ─────────────────────────────────
function qboCutoverPaymentsText(d) {
    if (!d) return 'Done.';
    if (d.status === 'none') return 'QuickBooks has no payments on this invoice.';
    if (d.status !== 'imported') return d.detail || ('Payments: ' + d.status);
    const created = (d.results || []).filter(x => ['payment_created', 'payment_resynced'].includes(x.result)).length;
    const other = (d.results || []).filter(x => !['payment_created', 'payment_resynced', 'already_mapped', 'unchanged'].includes(x.result))
        .map(x => 'QBO payment ' + x.qbo_payment_id + ': ' + x.result + (x.detail ? ' (' + x.detail + ')' : ''));
    let t = created + ' payment(s) brought in. FleetForge balance ' + d.ff_balance + (d.qbo_balance !== null && d.qbo_balance !== undefined ? ', QuickBooks balance ' + d.qbo_balance : '') + '.';
    if (other.length) t += '\n' + other.join('\n');
    return t;
}

function qboCutoverLinker(canLink) {
    const CONF = {
        exact:             ['exact', 'badge-success'],
        amount_date:       ['amount + date', 'badge-success'],
        amount_unit:       ['amount + unit', 'badge-success'],
        doc_number:        ['same number, other amount', 'badge-warning'],
        review:            ['check', 'badge-warning'],
        none:              ['no match', 'badge-secondary'],
        customer_unmapped: ['customer / vendor not linked', 'badge-danger'],
        qbo_error:         ['QuickBooks error', 'badge-danger'],
    };
    return {
        canLink: canLink,
        busy: false,
        searched: false,
        truncated: false,
        f: { kind: 'invoice', from: '', to: '', window_days: 7, include_drafts: false },
        rows: [],
        summary: {},
        selectedCount: 0,
        msg: { type: '', text: '' },

        async find() {
            this.busy = true;
            this.msg = { type: '', text: '' };
            try {
                const p = new URLSearchParams({ kind: this.f.kind, window_days: this.f.window_days || 0, include_drafts: this.f.include_drafts ? 1 : 0 });
                if (this.f.from) p.set('from', this.f.from);
                if (this.f.to) p.set('to', this.f.to);
                const r = await FF_Api.get('<?= base_url('api/v1/quickbooks/cutover_link') ?>?' + p.toString());
                if (!r.success) {
                    this.msg = { type: 'danger', text: (r.error && r.error.message) || 'Could not load matches.' };
                    return;
                }
                const d = r.data || {};
                this.summary = d.summary || {};
                this.truncated = !!d.truncated;
                this.rows = (d.rows || []).map(x => this.shape(x));
                this.searched = true;
                this.recount();
            } catch (e) {
                this.msg = { type: 'danger', text: 'Could not load matches: ' + (e.message || e) };
            } finally {
                this.busy = false;
            }
        },

        // Flatten one API row into display fields (no function calls in x-for).
        shape(x) {
            const money = (v, c) => (c === 'USD' ? 'US$' : '$') + parseFloat(v || 0).toFixed(2);
            const opt = q => ({
                id: q.id,
                label: (q.doc_number || ('id ' + q.id)) + ' · ' + q.txn_date + ' · ' + money(q.total, x.ff.currency)
                    + (parseFloat(q.amount_diff) !== 0 ? ' (' + (parseFloat(q.amount_diff) > 0 ? '+' : '') + parseFloat(q.amount_diff).toFixed(2) + ')' : '')
                    + (q.balance !== null && parseFloat(q.balance) === 0 ? ' · paid' : ''),
                total: q.total,
            });
            const options = [];
            const seen = {};
            if (x.proposal) { options.push(opt(x.proposal)); seen[x.proposal.id] = true; }
            (x.alternatives || []).forEach(q => { if (!seen[q.id]) { options.push(opt(q)); seen[q.id] = true; } });
            const conf = CONF[x.confidence] || [x.confidence, 'badge-secondary'];
            return {
                key: x.ff.kind + ':' + x.ff.id,
                ff_id: x.ff.id,
                // A bill shows its vendor's bill number too — that is what the
                // accountant typed into QuickBooks.
                number: x.ff.number + (x.ff.kind === 'bill' && x.ff.match_number ? ' · vendor #' + x.ff.match_number : ''),
                doc_date: x.ff.doc_date,
                status: x.ff.status,
                customer: x.ff.customer_name,
                total_label: money(x.ff.total, x.ff.currency),
                confidence: x.confidence,
                conf_label: conf[0],
                badge: conf[1],
                options: options,
                choice: x.proposal ? x.proposal.id : '',
                method: x.proposal ? x.confidence : 'manual',
                note: x.note || '',
                error: '',
                done: '',
                needs_confirm: false,
                can_push_new: x.confidence === 'none',
                selected: false,
            };
        },

        choiceChanged(r) {
            r.method = 'manual';
            r.needs_confirm = false;
            r.error = '';
            if (!r.choice) r.selected = false;
            this.recount();
        },

        recount() {
            this.selectedCount = this.rows.filter(r => r.selected && r.choice && !r.done).length;
        },

        selectConfident() {
            this.rows.forEach(r => { r.selected = !r.done && !!r.choice && ['exact', 'amount_date', 'amount_unit'].includes(r.confidence) && r.method !== 'manual'; });
            this.recount();
        },

        selectNone() {
            this.rows.forEach(r => { r.selected = false; });
            this.recount();
        },

        async linkSelected() {
            const pick = this.rows.filter(r => r.selected && r.choice && !r.done);
            if (!pick.length) return;
            if (!confirm('Link ' + pick.length + ' document(s) to their QuickBooks copies? Nothing is written to QuickBooks; QuickBooks payments on them will be brought into FleetForge.')) return;
            await this.sendLinks(pick, false);
        },

        async linkOne(r, accept) {
            await this.sendLinks([r], accept);
        },

        async sendLinks(pick, accept) {
            this.busy = true;
            this.msg = { type: '', text: '' };
            let linked = 0, failed = 0, paymentsIn = 0;
            try {
                // Small batches + server time budget: anything the server
                // didn't reach comes back 'deferred' and is re-sent.
                let queue = pick.slice();
                let guard = 0;
                while (queue.length && guard++ < 200) {
                    const chunk = queue.slice(0, 10);
                    queue = queue.slice(10);
                    const r = await FF_Api.post('<?= base_url('api/v1/quickbooks/cutover_link') ?>', {
                        action: 'link',
                        kind: this.f.kind,
                        items: chunk.map(x => ({ ff_id: x.ff_id, qbo_id: x.choice, method: x.method, accept_difference: accept })),
                    });
                    if (!r.success) {
                        this.msg = { type: 'danger', text: (r.error && r.error.message) || 'Link failed.' };
                        break;
                    }
                    (r.data.results || []).forEach(res => {
                        const row = chunk.find(x => x.ff_id === res.ff_id);
                        if (!row) return;
                        if (res.code === 'deferred') {
                            queue.push(row);
                            return;
                        }
                        if (res.ok) {
                            linked++;
                            row.done = 'Linked';
                            row.selected = false;
                            row.error = '';
                            row.needs_confirm = false;
                            const p = res.payments;
                            if (p && p.status === 'imported') {
                                const n = (p.results || []).filter(x => ['payment_created', 'payment_resynced'].includes(x.result)).length;
                                paymentsIn += n;
                                row.done = 'Linked' + (n ? ' · ' + n + ' payment(s) in' : '');
                                if (p.qbo_balance !== null && p.qbo_balance !== undefined && parseFloat(p.qbo_balance) !== parseFloat(p.ff_balance)) {
                                    row.error = 'Balances differ after import — FleetForge ' + p.ff_balance + ', QuickBooks ' + p.qbo_balance + '. See Drift.';
                                }
                            } else if (p && p.status === 'not_payable') {
                                row.error = p.detail;
                            }
                        } else {
                            failed++;
                            row.error = res.error || 'Failed';
                            row.needs_confirm = res.code === 'amount_differs';
                        }
                    });
                }
                this.recount();
                if (this.msg.type !== 'danger') {
                    this.msg = { type: failed ? 'warning' : 'success',
                                 text: linked + ' linked' + (paymentsIn ? ', ' + paymentsIn + ' QuickBooks payment(s) brought in' : '') + (failed ? ', ' + failed + ' need attention (see rows).' : '.') };
                }
            } catch (e) {
                this.msg = { type: 'danger', text: 'Link failed: ' + (e.message || e) };
            } finally {
                this.busy = false;
            }
        },

        async pushNew(r) {
            if (!confirm('Push ' + r.number + ' to QuickBooks as a NEW document? Only do this if QuickBooks does not already have it.')) return;
            this.busy = true;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/quickbooks/cutover_link') ?>', { action: 'push_new', kind: this.f.kind, ff_id: r.ff_id });
                if (res.success) {
                    r.done = (res.data && res.data.enqueued) ? 'Queued to push' : 'Released to push';
                    r.error = '';
                } else {
                    r.error = (res.error && res.error.message) || 'Failed';
                }
            } catch (e) {
                r.error = 'Failed: ' + (e.message || e);
            } finally {
                this.busy = false;
            }
        },

        async catchUpPayments() {
            if (!confirm('Check every open FleetForge invoice and bill that is in QuickBooks and bring in payments QuickBooks has recorded?')) return;
            this.busy = true;
            try {
                // Invoices (customer payments), then bills (bill payments the
                // accountant made in QuickBooks — S-QBO-BILLPAY-MIRROR). Each
                // kind runs in time-boxed slices: the server stops before its
                // limit and says where to resume (next_after_id).
                const totals = {};
                let r = null;
                for (const kind of ['invoice', 'bill']) {
                    const d = { checked: 0, imported: 0, unchanged: 0, errors: 0, details: [] };
                    let after = 0, loops = 0;
                    do {
                        r = await FF_Api.post('<?= base_url('api/v1/quickbooks/cutover_link') ?>', { action: 'catch_up_payments', kind: kind, limit: 500, after_id: after });
                        if (!r.success) break;
                        ['checked', 'imported', 'unchanged', 'errors'].forEach(k => { d[k] += (r.data[k] || 0); });
                        d.details = d.details.concat(r.data.details || []);
                        after = r.data.next_after_id;
                    } while (after !== null && after !== undefined && ++loops < 100);
                    totals[kind] = d;
                    if (!r.success) break;
                }
                if (r && r.success) {
                    const inv = totals.invoice, bil = totals.bill;
                    const errors = inv.errors + bil.errors;
                    const attention = inv.details.filter(x => x.status !== 'imported').map(x => x.invoice + ': ' + (x.detail || x.status))
                        .concat(bil.details.filter(x => x.status !== 'imported').map(x => 'Bill ' + x.bill + ': ' + (x.detail || x.status)));
                    this.msg = { type: errors ? 'warning' : 'success',
                                 text: 'Checked ' + inv.checked + ' invoice(s): ' + inv.imported + ' had QuickBooks payments brought in, ' + inv.unchanged + ' unchanged.\n'
                                     + 'Checked ' + bil.checked + ' bill(s): ' + bil.imported + ' had QuickBooks bill payments brought in, ' + bil.unchanged + ' unchanged'
                                     + (errors ? '.\n' + errors + ' need attention:\n' + attention.join('\n') : '.') };
                } else {
                    this.msg = { type: 'danger', text: (r && r.error && r.error.message) || 'Check failed.' };
                }
            } catch (e) {
                this.msg = { type: 'danger', text: 'Check failed: ' + (e.message || e) };
            } finally {
                this.busy = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
