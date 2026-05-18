<?php declare(strict_types=1);

/**
 * FleetForge — Tax Filing Period Detail
 *
 * @file        app/admin/accounting/tax/show.php
 * @description Tax filing period drill-down page. Shows the period's
 *              header (tax_type, span, frequency, status, due date,
 *              filed_by/filed_date), the four totals
 *              (sales / collected / ITC / net owing), the remittance
 *              history table, and a per-transaction drill-down of
 *              every contributing invoice and (for GST/HST) bill.
 *              Spec ref §9: "Report shows every transaction making up
 *              each line — full drill-down."
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/accounting/tax/periods/show.php
 *
 * @session     S035 — Tax Management module
 */

// dirname(__DIR__, 4): tax/ -> accounting/ -> admin/ -> app/ -> root
require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('tax_management', 'view');

// Validate the :id query parameter early so a missing/bad value
// returns a friendly 404 instead of 500ing later.
$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Tax Period Not Specified</h1>';
    exit;
}

// Pull the row server-side so we can render the page header without
// waiting on the JS fetch (and so 404 lands on a friendly redirect).
$period = db_row(
    "SELECT p.*, u.name AS filed_by_name
     FROM acc_tax_filing_periods p
     LEFT JOIN users u ON u.id = p.filed_by
     WHERE p.id = ?",
    [$id]
);
if (!$period) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Tax Period Not Found</h1>';
    exit;
}

$pageTitle = 'Tax Period #' . $id;
require_once FF_ROOT . '/includes/header.php';

// ── Display label helpers (page-scope only) ─────────────────
$taxLabels = [
    'gst_hst' => 'GST / HST (Federal)',
    'pst_bc'  => 'PST — British Columbia',
    'pst_sk'  => 'PST — Saskatchewan',
    'pst_mb'  => 'PST — Manitoba',
];
$statusBadges = [
    'open'       => 'badge-blue',
    'calculated' => 'badge-amber',
    'filed'      => 'badge-green',
    'remitted'   => 'badge-neutral',
];
$taxLabel  = $taxLabels[$period['tax_type']] ?? $period['tax_type'];
$statusCls = $statusBadges[$period['status']] ?? 'badge-neutral';
?>

<!-- ── Breadcrumb ──────────────────────────────────────────────── -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/tax') ?>">Tax Management</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Period #<?= e((string) $id) ?></span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">
        <?= e($taxLabel) ?>
        <span class="text-secondary text-sm" style="font-weight:normal;">
            <?= e($period['period_start']) ?> → <?= e($period['period_end']) ?>
        </span>
    </h1>
    <div class="page-header-actions">
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/tax') ?>">← Back to list</a>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- ── Header card with key facts ─────────────────────────────── -->
<div class="card" style="padding:20px;margin-bottom:20px;">
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:18px 24px;">
        <div>
            <div class="text-secondary text-sm">Status</div>
            <span class="badge badge-no-dot <?= e($statusCls) ?>"><?= e($period['status']) ?></span>
        </div>
        <div>
            <div class="text-secondary text-sm">Frequency</div>
            <div><?= e(ucfirst($period['frequency'])) ?></div>
        </div>
        <div>
            <div class="text-secondary text-sm">Filing Due</div>
            <div class="font-mono"><?= e($period['filing_due_date'] ?? '—') ?></div>
        </div>
        <div>
            <div class="text-secondary text-sm">Filed</div>
            <div>
                <?php if ($period['filed_date']): ?>
                    <?= e($period['filed_date']) ?>
                    <?php if ($period['filed_by_name']): ?>
                        <span class="text-secondary text-sm">— <?= e($period['filed_by_name']) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="text-secondary">—</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($period['notes']): ?>
        <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border-default);">
            <div class="text-secondary text-sm">Notes</div>
            <div class="text-sm"><?= nl2br(e($period['notes'])) ?></div>
        </div>
    <?php endif; ?>
</div>

<!-- ── Totals tiles — server-side render ─────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-banknotes"/></svg></span>
        <div class="stat-label">Total Sales (subtotal)</div>
        <div class="stat-value font-mono">$<?= number_format((float) $period['total_sales'], 2) ?></div>
    </div>
    <div class="stat-card stat-card--amber">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Tax Collected</div>
        <div class="stat-value font-mono">$<?= number_format((float) $period['total_tax_collected'], 2) ?></div>
    </div>
    <?php if ($period['tax_type'] === 'gst_hst'): ?>
    <div class="stat-card stat-card--green">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Input Tax Credits (1050)</div>
        <div class="stat-value font-mono">$<?= number_format((float) $period['total_itc'], 2) ?></div>
    </div>
    <?php endif; ?>
    <div class="stat-card">
        <span class="stat-icon"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Net Owing</div>
        <div class="stat-value font-mono">$<?= number_format((float) $period['net_tax_owing'], 2) ?></div>
    </div>
</div>

<!-- ============================================================
     ALPINE COMPONENT — fetches drill-down + remittances
     ============================================================ -->
<div x-data="FF_TaxPeriodDetail(<?= (int) $id ?>)" x-init="init()">

    <!-- ── Remittances ───────────────────────────────────────── -->
    <h2 class="h6" style="margin-bottom:10px;">Remittance History</h2>
    <template x-if="remittances.length === 0">
        <div class="card"><div class="empty-state">
            <p class="empty-state-text text-sm">No remittances recorded yet.</p>
        </div></div>
    </template>
    <template x-if="remittances.length > 0">
        <div class="card" style="padding:0;margin-bottom:24px;">
            <div class="table-responsive">
<table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th class="text-right">Amount</th>
                        <th>Method</th>
                        <th>Bank Account</th>
                        <th>Reference</th>
                        <th>JE #</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="r in remittances" :key="r.id">
                        <tr>
                            <td class="text-sm" x-text="r.remittance_date"></td>
                            <td class="text-right font-mono" x-text="formatMoney(r.amount)"></td>
                            <td class="text-sm" x-text="r.payment_method"></td>
                            <td class="text-sm" x-text="r.bank_account_name || 'Cash 1010'"></td>
                            <td class="text-sm font-mono" x-text="r.reference_number || '—'"></td>
                            <td class="font-mono text-sm" x-text="r.journal_entry_number ? '#' + r.journal_entry_number : '—'"></td>
                            <td class="text-sm" x-text="r.created_by_name || '—'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
</div>
        </div>
    </template>

    <!-- ── Drill-down: contributing invoices ─────────────────── -->
    <h2 class="h6" style="margin-bottom:10px;">Contributing Invoices <span class="text-secondary text-sm" x-text="'(' + invoices.length + ')'"></span></h2>
    <template x-if="loadingDetail">
        <div class="card"><div class="empty-state">Loading drill-down…</div></div>
    </template>
    <template x-if="!loadingDetail && invoices.length === 0">
        <div class="card"><div class="empty-state">
            <p class="empty-state-text text-sm">No invoices contributed to this period.</p>
        </div></div>
    </template>
    <template x-if="!loadingDetail && invoices.length > 0">
        <div class="card" style="padding:0;margin-bottom:24px;max-height:480px;overflow-y:auto;">
            <div class="table-responsive">
<table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Province</th>
                        <th class="text-right">Subtotal</th>
                        <th class="text-right">GST</th>
                        <th class="text-right">HST</th>
                        <th class="text-right">PST</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="i in invoices" :key="i.id">
                        <tr>
                            <td class="font-mono text-sm" x-text="i.invoice_number"></td>
                            <td class="text-sm" x-text="i.invoice_date"></td>
                            <td class="text-sm" x-text="i.company_name || '—'"></td>
                            <td class="text-sm" x-text="i.province || '—'"></td>
                            <td class="text-right font-mono" x-text="formatMoney(i.subtotal)"></td>
                            <td class="text-right font-mono" x-text="formatMoney(i.tax_gst_amount)"></td>
                            <td class="text-right font-mono" x-text="formatMoney(i.tax_hst_amount)"></td>
                            <td class="text-right font-mono" x-text="formatMoney(i.tax_pst_amount)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
</div>
        </div>
    </template>

    <!-- ── Drill-down: contributing bills (gst_hst only) ─────── -->
    <template x-if="taxType === 'gst_hst'">
        <div>
            <h2 class="h6" style="margin-bottom:10px;">Contributing Vendor Bills (GST ITC) <span class="text-secondary text-sm" x-text="'(' + bills.length + ')'"></span></h2>
            <template x-if="bills.length === 0">
                <div class="card"><div class="empty-state">
                    <p class="empty-state-text text-sm">No vendor bills with GST input tax credits in this period.</p>
                </div></div>
            </template>
            <template x-if="bills.length > 0">
                <div class="card" style="padding:0;max-height:400px;overflow-y:auto;">
                    <div class="table-responsive">
<table class="data-table">
                        <thead>
                            <tr>
                                <th>Bill #</th>
                                <th>Vendor Bill #</th>
                                <th>Date</th>
                                <th>Vendor</th>
                                <th class="text-right">Subtotal</th>
                                <th class="text-right">GST (ITC)</th>
                                <th class="text-right">HST</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="b in bills" :key="b.id">
                                <tr>
                                    <td class="font-mono text-sm" x-text="b.bill_number || ('#' + b.id)"></td>
                                    <td class="font-mono text-sm" x-text="b.vendor_bill_number || '—'"></td>
                                    <td class="text-sm" x-text="b.bill_date"></td>
                                    <td class="text-sm" x-text="b.vendor_name || '—'"></td>
                                    <td class="text-right font-mono" x-text="formatMoney(b.subtotal)"></td>
                                    <td class="text-right font-mono" x-text="formatMoney(b.tax_gst_amount)"></td>
                                    <td class="text-right font-mono" x-text="formatMoney(b.tax_hst_amount)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
</div>
                </div>
            </template>
        </div>
    </template>

</div><!-- /x-data -->

<!-- ── Documents ───────────────────────────────────────────────────────── -->
<?php
$entityType = 'tax_filing';
$entityId   = (int) $period['id'];
require FF_ROOT . '/includes/partials/acc-documents-section.php';
?>

<script>
function FF_TaxPeriodDetail(periodId) {
    return {
        periodId: periodId,
        taxType:  '<?= e($period['tax_type']) ?>',
        loadingDetail: true,
        invoices: [],
        bills: [],
        remittances: [],

        async init() {
            await this.loadDetail();
        },

        async loadDetail() {
            this.loadingDetail = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/tax/periods/show.php') ?>?id=' + this.periodId);
                if (r.success) {
                    this.invoices    = r.data.invoices    || [];
                    this.bills       = r.data.bills       || [];
                    this.remittances = r.data.remittances || [];
                } else {
                    FF_Toast.error(r.error?.message || 'Failed to load detail.');
                }
            } catch (e) {
                FF_Toast.error('Network error loading drill-down.');
            }
            this.loadingDetail = false;
        },

        formatMoney(s) {
            if (s === null || s === undefined || s === '') return '—';
            const n = parseFloat(s);
            if (n === 0) return '—';
            return '$' + n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
