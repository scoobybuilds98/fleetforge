<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/credit_memos.php
 *
 * QBO Credit Memo Push admin — operator surface for the FF→QBO credit memo
 * push pipeline (Phase QBO-7 / S-QBO-16). Mirror of /quickbooks/journal_entries
 * with credit-memo-specific deltas:
 *   - 9 KPI tiles incl. typed preflight sub-states (currency_mismatch +
 *     field_too_long)
 *   - Source column (mileage_overpayment / goodwill / damage_resolution / etc.)
 *   - Item Type column (resolved via SOURCE_TO_ITEM_TYPE map per D-QBO-16-1)
 *   - Customer + Amount columns
 *   - Retry button on failed / failed_preflight* rows
 *
 * @session  S-QBO-16
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.6 + §6.8
 * @gate     require_permission('quickbooks', 'view') for read; retry needs edit_credentials
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Credit Memos';
require_once FF_ROOT . '/includes/header.php';

$canEditCredentials = can('quickbooks', 'edit_credentials');
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Credit Memos</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Credit Memo Push</h1>
    <div class="text-secondary text-sm" style="margin-top:4px;">
        Read-only visibility into the FF→QBO credit memo push pipeline (Phase QBO-7 / S-QBO-16).
        FF credit_notes enqueue on creation (status='active') via api/v1/credit_notes/create.php;
        the worker picks them up + creates QBO CreditMemo entities. credit_notes are header-only,
        so each pushes as a single CreditMemo line whose ItemRef resolves from credit_notes.source
        via the SOURCE_TO_ITEM_TYPE map (D-QBO-16-1). Tax-override per D-QBO-CORE-6 (TotalTax=0 —
        credit notes carry no tax). Apply→LinkedTxn + void deferred to S-QBO-16-UPDATE-FOLLOWUP (F20).
        Retry failed pushes from this page; investigate failed_preflight states by checking the listed
        reason (typically unmapped customer or unmapped line item_type).
    </div>
</div>

<div x-data="qboCreditMemosAdmin(<?= $canEditCredentials ? 'true' : 'false' ?>)">

    <!-- Flash strip -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ── 9 KPI tiles ──────────────────────────────────────────── -->
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
            <div class="kpi-label">Mode-Skipped</div>
            <div class="kpi-value text-secondary" x-text="kpis.skipped_by_mode">0</div>
        </div>
        <div class="kpi-tile">
            <div class="kpi-label">Soft-Deleted</div>
            <div class="kpi-value text-secondary" x-text="kpis.skipped_soft_deleted">0</div>
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
            <template x-for="s in ['pending','pushed','voided','failed','failed_preflight','failed_preflight_currency_mismatch','failed_preflight_field_too_long','skipped_voided','skipped_by_mode','skipped_soft_deleted']" :key="s">
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
                    <th>FF Credit Note</th>
                    <th>Customer</th>
                    <th>Source</th>
                    <th>Item Type</th>
                    <th class="text-right">Amount</th>
                    <th>QBO Id</th>
                    <th>Status</th>
                    <th>Pushed At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading">
                    <tr><td colspan="9" class="text-center text-secondary" style="padding:24px;">Loading…</td></tr>
                </template>
                <template x-if="!loading && rows.length === 0">
                    <tr><td colspan="9" class="text-center text-secondary" style="padding:24px;">
                        No credit memo push activity yet. Active credit notes created via the credit-note flow will appear here once QBO sync is enabled.
                    </td></tr>
                </template>
                <template x-for="row in rows" :key="row.id">
                    <tr>
                        <td>
                            <a :href="ffCreditNoteUrl(row.ff_credit_note_id)" x-text="row.credit_note_number || ('#' + row.ff_credit_note_id)"></a>
                            <div class="text-xs text-secondary" x-text="row.cn_created_at ? row.cn_created_at.substring(0,10) : ''"></div>
                        </td>
                        <td class="text-sm" x-text="row.customer_name || ('#' + row.customer_id)"></td>
                        <td><span class="badge badge-secondary" x-text="row.source || '—'"></span></td>
                        <td class="text-xs font-mono" x-text="row.qbo_item_type_used || '—'"></td>
                        <td class="text-right font-mono">
                            <span x-text="formatMoney(row.ff_credit_note_snapshot_total || row.cn_amount, row.qbo_currency || row.cn_currency)"></span>
                        </td>
                        <td class="font-mono text-sm" x-text="row.qbo_credit_memo_id || '—'"></td>
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

<!-- ════════════════════════════════════════════════════════════════════ -->
<!--                                                                      -->
<!--  Credit-memo APPLICATIONS sub-section                                -->
<!--                                                                      -->
<!--  S-QBO-CREDIT-MEMO-APPLY (closes F25). Shares this admin surface     -->
<!--  with CreditMemoPusher per D-QBO-CREDIT-MEMO-APPLY-5: applications   -->
<!--  are operationally a sub-view of their parent credit memo            -->
<!--  (1 credit → N applications). CLASS 12 allowlists                    -->
<!--  CreditApplicationPusher → credit_memos.php.                         -->
<!--                                                                      -->
<!--  Backed by acc_qbo_credit_application_map. Each row pushes as a      -->
<!--  zero-dollar QBO Payment carrying 2 LinkedTxns (CreditMemo +         -->
<!--  Invoice). Auto-apply pre-req (QBO "Automatically apply credits" =   -->
<!--  OFF) is an operator follow-up per D-QBO-CREDIT-MEMO-APPLY-3 — NOT   -->
<!--  runtime-probed.                                                     -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div style="margin-top:36px;" x-data="qboCreditApplicationsAdmin(<?= $canEditCredentials ? 'true' : 'false' ?>)">

    <div class="page-header">
        <h2 class="h5" style="margin:0;">Applications → QBO LinkedTxn</h2>
        <div class="text-secondary text-sm" style="margin-top:4px;">
            FF credit-note applications (api/v1/credit_notes/apply.php) propagate to QBO as a
            zero-dollar Payment carrying 2 LinkedTxns (CreditMemo + Invoice). One row per
            application — a credit applied to N invoices produces N rows here.
            <strong>Operator pre-req:</strong> QBO Account &amp; Settings → Advanced → Automation →
            "Automatically apply credits" must be <strong>OFF</strong> before live cutover, otherwise
            QBO will double-apply.
        </div>
    </div>

    <!-- Flash strip -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- 5 KPI tiles (slimmer than parent credit-memo set — apply has no
         soft-delete / void / field_too_long / currency_mismatch paths) -->
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
            <div class="kpi-label">Mode-Skipped</div>
            <div class="kpi-value text-secondary" x-text="kpis.skipped_by_mode">0</div>
        </div>
    </div>

    <!-- ── FILTER TOOLBAR (refund receipts section) ────────────── -->
    <div class="table-toolbar">

        <div class="table-toolbar-left table-toolbar-left--wrap">
            <span class="text-secondary text-sm" style="white-space:nowrap;">Status:</span>
            <template x-for="s in ['pending','pushed','failed','failed_preflight','skipped_by_mode']" :key="s">
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

    <!-- Main table -->
    <div class="card" style="padding:0;">
        <table class="table table-striped" style="margin:0;">
            <thead>
                <tr>
                    <th>Credit Note</th>
                    <th>Invoice</th>
                    <th>Customer</th>
                    <th class="text-right">Amount Applied</th>
                    <th>QBO Payment Id</th>
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
                        No credit-application push activity yet. Applications created via
                        api/v1/credit_notes/apply.php will appear here once QBO sync is enabled.
                    </td></tr>
                </template>
                <template x-for="row in rows" :key="row.id">
                    <tr>
                        <td>
                            <a :href="ffCreditNoteUrl(row.ff_credit_note_id_snapshot)"
                               x-text="row.credit_note_number || ('CN#' + row.ff_credit_note_id_snapshot)"></a>
                        </td>
                        <td>
                            <a :href="ffInvoiceUrl(row.ff_invoice_id_snapshot)"
                               x-text="row.invoice_number || ('INV#' + row.ff_invoice_id_snapshot)"></a>
                        </td>
                        <td class="text-sm" x-text="row.customer_name || '—'"></td>
                        <td class="text-right font-mono">
                            <span x-text="formatMoney(row.amount_applied_snapshot || row.amount_applied, row.qbo_currency || row.cn_currency)"></span>
                        </td>
                        <td class="font-mono text-sm" x-text="row.qbo_payment_id || '—'"></td>
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

<?php
/* ── Invoice write-offs (S-QBO-INVOICE-WRITEOFF) ─────────────────────────
 * An FF write-off (AR bad debt, or a damage claim written off) reaches
 * QuickBooks as a credit memo on the "Bad Debt Write-off" item applied to
 * the invoice, so the invoice closes there too (InvoiceWriteoffPusher).
 * Server-rendered: write-offs are rare, the newest 100 are enough. */
$writeoffs = db_select(
    "SELECT w.id, w.writeoff_date, w.amount, w.recovered, i.id AS invoice_id, i.invoice_number, i.currency,
            c.company_name, dc.claim_number, m.push_status, m.push_error, m.qbo_credit_memo_id, m.qbo_payment_id, m.pushed_at
       FROM acc_bad_debt_writeoffs w
       JOIN invoices i ON i.id = w.invoice_id
  LEFT JOIN customers c ON c.id = w.customer_id
  LEFT JOIN damage_claims dc ON dc.id = w.damage_claim_id
  LEFT JOIN acc_qbo_invoice_writeoff_map m ON m.ff_writeoff_id = w.id
   ORDER BY w.id DESC
      LIMIT 100"
);
$woStatus = static fn(?string $s): array => match ($s) {
    'pushed'           => ['In QuickBooks', 'badge-success'],
    'pending'          => ['Credit memo created, not yet applied', 'badge-warning'],
    'failed'           => ['Failed', 'badge-danger'],
    'failed_preflight' => ['Blocked', 'badge-warning'],
    'skipped_by_mode'  => ['Skipped (sync mode)', 'badge-secondary'],
    default            => ['Not sent', 'badge-secondary'],
};
?>
<div style="margin-top:36px;" x-data="qboInvoiceWriteoffs(<?= $canEditCredentials ? 'true' : 'false' ?>)">
    <h2 class="h5" style="margin:0 0 4px;">Invoice write-offs → QuickBooks credit memo</h2>
    <div class="text-secondary text-sm" style="margin-bottom:12px;max-width:900px;">
        A write-off (bad debt, or a damage claim written off) closes the invoice in QuickBooks with a credit memo on the
        <strong>Bad Debt Write-off</strong> item (map it on QuickBooks → Items; its account should be Bad Debt Expense),
        applied to the invoice. Only invoices that are in QuickBooks can be closed there.
    </div>
    <div x-show="flash.message" x-cloak :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:12px;" x-text="flash.message"></div>
    <div class="card" style="padding:0;overflow-x:auto;">
        <table class="table" style="margin:0;font-size:0.875rem;">
            <thead>
                <tr>
                    <th>Written off</th>
                    <th>Invoice</th>
                    <th>Customer</th>
                    <th>Source</th>
                    <th style="text-align:right;">Amount</th>
                    <th>QuickBooks</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($writeoffs === []): ?>
                <tr><td colspan="7" class="text-secondary" style="padding:16px;">No invoices have been written off.</td></tr>
            <?php endif; ?>
            <?php foreach ($writeoffs as $w): [$label, $badge] = $woStatus($w['push_status']); ?>
                <tr>
                    <td class="font-mono"><?= e(format_date($w['writeoff_date'])) ?></td>
                    <td><a href="<?= base_url('invoices/show?id=' . (int) $w['invoice_id']) ?>"><?= e($w['invoice_number']) ?></a></td>
                    <td><?= e($w['company_name'] ?? '—') ?></td>
                    <td class="text-sm"><?= $w['claim_number'] ? 'Damage claim ' . e($w['claim_number']) : 'Bad debt' ?></td>
                    <td style="text-align:right;" class="font-mono"><?= e(format_currency($w['amount'], $w['currency'] === 'USD' ? 'US$' : '$')) ?></td>
                    <td>
                        <span class="badge <?= e($badge) ?>"><?= e($label) ?></span>
                        <?php if ($w['recovered']): ?><span class="badge badge-secondary">Recovered</span><?php endif; ?>
                        <?php if ($w['qbo_credit_memo_id']): ?><div class="text-xs text-secondary">Credit memo #<?= e($w['qbo_credit_memo_id']) ?></div><?php endif; ?>
                        <?php if ($w['push_error'] && $w['push_status'] !== 'pushed'): ?><div class="text-xs text-danger" style="max-width:420px;white-space:normal;"><?= e($w['push_error']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$w['recovered'] && in_array($w['push_status'], [null, 'failed', 'failed_preflight', 'skipped_by_mode'], true)): ?>
                        <template x-if="canRetry">
                            <button class="btn btn-secondary btn-xs" @click="retry(<?= (int) $w['id'] ?>)" :disabled="busy[<?= (int) $w['id'] ?>]">
                                <?= $w['push_status'] === null ? 'Send' : 'Retry' ?>
                            </button>
                        </template>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// S-QBO-INVOICE-WRITEOFF: queue a write-off's QuickBooks credit memo.
function qboInvoiceWriteoffs(canEdit) {
    return {
        canRetry: canEdit,
        busy: {},
        flash: { type: '', message: '' },
        async retry(writeoffId) {
            this.busy[writeoffId] = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/quickbooks/invoice_writeoffs/retry') ?>', { writeoff_id: writeoffId });
                if (r.success && (r.data || {}).action === 'enqueued') {
                    this.flash = { type: 'success', message: 'Queued — the worker sends it to QuickBooks on its next run.' };
                } else if (r.success) {
                    this.flash = { type: 'danger', message: 'Not queued: ' + ((r.data || {}).reason || 'refused') };
                } else {
                    this.flash = { type: 'danger', message: (r.error && r.error.message) || 'Retry failed.' };
                }
            } catch (e) {
                this.flash = { type: 'danger', message: 'Retry failed: ' + (e.message || e) };
            } finally {
                this.busy[writeoffId] = false;
            }
        },
    };
}

function qboCreditMemosAdmin(canEdit) {
    return {
        canRetry: canEdit,
        loading: false,
        rows: [],
        kpis: {
            pushed: 0, pending: 0, voided: 0, failed: 0,
            failed_preflight: 0,
            failed_preflight_currency_mismatch: 0,
            failed_preflight_field_too_long: 0,
            skipped_voided: 0,
            skipped_by_mode: 0,
            skipped_soft_deleted: 0,
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
                const r = await FF_Api.get('<?= base_url('api/v1/quickbooks/credit_memos/list') ?>?' + params.toString());
                if (r.success) {
                    // Envelope contract: json_success([...]) nests EVERY key under
                    // `data`, so these reads must go through r.data — reading
                    // r.rows / r.kpis / r.total off the envelope yields undefined,
                    // which the `|| []` / `|| 0` fallbacks silently turned into a
                    // permanently empty table and zeroed KPI tiles. Matches the
                    // `const d = j.data` convention already used by the
                    // accounts / customers / vendors / items / tax_codes consoles.
                    const d = r.data || {};
                    this.rows = d.rows || [];
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
                const r = await FF_Api.post('<?= base_url('api/v1/quickbooks/credit_memos/retry') ?>', { id: mappingId });
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

        ffCreditNoteUrl(id) {
            return '<?= base_url('credit_notes/show?id=') ?>' + id;
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
                'skipped_by_mode': 'badge-secondary',
                'skipped_soft_deleted': 'badge-secondary',
            }[s] || 'badge-secondary';
        },

        formatMoney(amt, ccy) {
            if (amt == null) return '—';
            const sym = (ccy === 'USD') ? 'US$' : '$';
            return sym + parseFloat(amt).toFixed(2);
        },

        // S-UTC-STAMPS: pushed_at (and refund settled_at) are UTC DATETIMEs —
        // printing the raw string showed UTC wall time. Same 'YYYY-MM-DD HH:MM'
        // shape, converted to the company timezone.
        formatTs(ts) { return ts ? FF_formatUtc(ts, { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).replace(',', '') : '—'; },
        truncate(s, n) { if (!s) return ''; return s.length > n ? s.substring(0, n) + '…' : s; },
    };
}

// Credit-applications controller (S-QBO-CREDIT-MEMO-APPLY).
// Independent Alpine scope so it filters/paginates separately from the
// parent credit-memos table.
function qboCreditApplicationsAdmin(canEdit) {
    return {
        canRetry: canEdit,
        loading: false,
        rows: [],
        kpis: {
            pushed: 0, pending: 0, failed: 0,
            failed_preflight: 0,
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
                const r = await FF_Api.get('<?= base_url('api/v1/quickbooks/credit_applications/list') ?>?' + params.toString());
                if (r.success) {
                    // Envelope contract — see the note on the first block above.
                    const d = r.data || {};
                    this.rows = d.rows || [];
                    this.kpis = d.kpis || this.kpis;
                    this.total = d.total || 0;
                }
            } catch (e) {
                this.flash = { type: 'danger', message: 'Failed to load applications: ' + (e.message || e) };
            } finally {
                this.loading = false;
            }
        },

        async retry(mappingId) {
            this.retrying[mappingId] = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/quickbooks/credit_applications/retry') ?>', { id: mappingId });
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

        ffCreditNoteUrl(id) { return '<?= base_url('credit_notes/show?id=') ?>' + id; },
        ffInvoiceUrl(id)    { return '<?= base_url('invoices/show?id=') ?>'      + id; },

        statusBadgeClass(s) {
            return {
                'pushed': 'badge-success',
                'pending': 'badge-secondary',
                'failed': 'badge-danger',
                'failed_preflight': 'badge-warning',
                'skipped_by_mode': 'badge-secondary',
            }[s] || 'badge-secondary';
        },

        formatMoney(amt, ccy) {
            if (amt == null) return '—';
            const sym = (ccy === 'USD') ? 'US$' : '$';
            return sym + parseFloat(amt).toFixed(2);
        },

        // S-UTC-STAMPS: pushed_at (and refund settled_at) are UTC DATETIMEs —
        // printing the raw string showed UTC wall time. Same 'YYYY-MM-DD HH:MM'
        // shape, converted to the company timezone.
        formatTs(ts) { return ts ? FF_formatUtc(ts, { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).replace(',', '') : '—'; },
        truncate(s, n) { if (!s) return ''; return s.length > n ? s.substring(0, n) + '…' : s; },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
