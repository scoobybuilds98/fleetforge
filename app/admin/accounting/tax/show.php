<?php declare(strict_types=1);

/**
 * FleetForge — Tax Filing Period Detail
 *
 * @file        app/admin/accounting/tax/show.php
 * @description Tax filing period drill-down page. Shows the period's
 *              header (tax_type, span, frequency, status, due date,
 *              filed_by/filed_date), the totals (collected / ITC / net
 *              owing / remitted), the remittance history table, and a
 *              per-transaction drill-down of every contributing invoice
 *              and (for GST/HST) bill.
 *              Spec ref §9: "Report shows every transaction making up
 *              each line — full drill-down."
 *
 *              Layout (S-RECORD-REDESIGN):
 *                header — ModuleHero entity: tax type + status badge;
 *                         period span · frequency · filing due chips
 *                nav    — the accounting sub-nav, directly under the header
 *                strip  — tax collected · ITCs (GST/HST) · net owing ·
 *                         remitted · filing due (days left / overdue)
 *                main   — Remittance history · Contributing invoices ·
 *                         Contributing bills (GST/HST) · Documents (the
 *                         Alpine drill-down fetch is unchanged)
 *                rail   — Needs attention (overdue / due soon / filed but
 *                         not remitted / refund due / not calculated) ·
 *                         Remittance meter · Period facts (total sales,
 *                         filed by, notes)
 *              The old tiles used #icon-banknotes, which is not in the
 *              sprite (blank icon) — the strip uses sprite ids only.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/accounting/tax/periods/show.php,
 *              includes/partials/accounting-nav.php,
 *              includes/partials/acc-documents-section.php, lib/Ui/RecordUi.php
 *
 * @session     S035 — Tax Management module; S-RECORD-REDESIGN
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

// ── Remitted so far (S-RECORD-REDESIGN) ─────────────────────────────────────
// Payments to the CRA count up; a refund (direction='refund', the CRA paying
// us on a credit period) counts down — so "balance" is what is still to move
// in either direction. bcmath: money.
$remRow = db_row(
    "SELECT COUNT(*) AS n,
            COALESCE(SUM(CASE WHEN direction = 'refund' THEN -amount ELSE amount END), 0) AS net,
            MAX(remittance_date) AS last_date
       FROM acc_tax_remittances
      WHERE filing_period_id = ?",
    [$id]
);
$remitted   = bcadd((string) ($remRow['net'] ?? '0'), '0', 2);
$remCount   = (int) ($remRow['n'] ?? 0);
$netOwing   = (string) $period['net_tax_owing'];
$remaining  = bcsub($netOwing, $remitted, 2);
$isRefund   = bccomp($netOwing, '0', 2) < 0;
$absNet     = ltrim($netOwing, '-');
$remPct     = bccomp($absNet, '0', 2) > 0
    ? (float) bcmul(bcdiv(ltrim($remitted, '-'), $absNet, 6), '100', 2)
    : 0.0;

$today      = ff_today();
$dueDate    = (string) ($period['filing_due_date'] ?? '');
$daysToDue  = $dueDate !== '' ? (int) round((strtotime($dueDate) - strtotime($today)) / 86400) : null;
$isSettled  = in_array($period['status'], ['filed', 'remitted'], true);
$isOverdue  = $daysToDue !== null && $daysToDue < 0 && !$isSettled;

$pageTitle = 'Tax Period #' . $id;
require_once FF_ROOT . '/includes/header.php';

// ── Display label helpers (page-scope only) ─────────────────
$taxLabels = [
    'gst_hst' => 'GST / HST (Federal)',
    'pst_bc'  => 'PST — British Columbia',
    'pst_sk'  => 'PST — Saskatchewan',
    'pst_mb'  => 'PST — Manitoba',
];
$taxShort = [
    'gst_hst' => 'GST/HST',
    'pst_bc'  => 'PST BC',
    'pst_sk'  => 'PST SK',
    'pst_mb'  => 'PST MB',
];
$statusBadges = [
    'open'       => 'badge-blue',
    'calculated' => 'badge-amber',
    'filed'      => 'badge-green',
    'remitted'   => 'badge-neutral',
];
$taxLabel  = $taxLabels[$period['tax_type']] ?? $period['tax_type'];
$statusCls = $statusBadges[$period['status']] ?? 'badge-neutral';

// ── Header (S-RECORD-REDESIGN) ──────────────────────────────────────────────
$heroFacts = [
    \FleetForge\Sop\SopIcons::svg('calendar-days') . e(format_date($period['period_start'])) . ' → ' . e(format_date($period['period_end'])),
    \FleetForge\Sop\SopIcons::svg('arrow-path') . e(ucfirst((string) $period['frequency'])),
];
if ($dueDate !== '') {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('clock') . 'Due <b>' . e(format_date($dueDate)) . '</b>';
}
if (!empty($period['filed_date'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('check-circle') . 'Filed ' . e(format_date($period['filed_date'])) . (!empty($period['filed_by_name']) ? ' · ' . e($period['filed_by_name']) : '');
}
?>
<?php ob_start(); /* secondary actions → the header's More menu */ ?>
    <a href="<?= base_url('accounting/tax') ?>">All tax periods</a>
    <a href="#tax-documents">Documents</a>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
    <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/tax') ?>"><?= \FleetForge\Sop\SopIcons::svg('calculator') ?> File / remit</a>
    <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => $isOverdue ? 'danger' : ($isSettled ? 'success' : ($period['status'] === 'calculated' ? 'warning' : 'info')),
    'icon'       => 'calculator',
    'mark'       => $taxShort[$period['tax_type']] ?? '',
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Accounting', base_url('accounting/dashboard')], ['Tax Management', base_url('accounting/tax')], ['Period #' . $id, null]],
    'eyebrow'    => 'Tax filing period',
    'title_html' => e($taxLabel) . ' <span class="badge badge-no-dot ' . e($statusCls) . '">' . e($period['status']) . '</span>',
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- KEY NUMBERS (S-RECORD-REDESIGN): collected, credits (GST/HST), what the
     period nets to, what has been remitted, and how long until it is due. -->
<div class="stat-grid <?= $period['tax_type'] === 'gst_hst' ? 'stat-grid--5' : 'stat-grid--4' ?> ff-stats">
    <div class="stat-card stat-card--amber">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Tax collected</div>
        <div class="stat-value font-mono"><?= e(format_currency($period['total_tax_collected'])) ?></div>
    </div>
    <?php if ($period['tax_type'] === 'gst_hst'): ?>
    <div class="stat-card stat-card--green">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Input tax credits</div>
        <div class="stat-value font-mono"><?= e(format_currency($period['total_itc'])) ?></div>
        <div class="stat-delta">1050</div>
    </div>
    <?php endif; ?>
    <div class="stat-card <?= $isRefund ? 'stat-card--teal' : 'stat-card--blue' ?>">
        <span class="stat-icon <?= $isRefund ? 'stat-icon--teal' : 'stat-icon--blue' ?>"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label"><?= $isRefund ? 'Refund due' : 'Net owing' ?></div>
        <div class="stat-value font-mono"><?= e(format_currency($absNet)) ?></div>
    </div>
    <div class="stat-card <?= bccomp($remaining, '0', 2) === 0 ? 'stat-card--green' : 'stat-card--purple' ?>">
        <span class="stat-icon <?= bccomp($remaining, '0', 2) === 0 ? 'stat-icon--green' : 'stat-icon--purple' ?>"><svg><use href="#icon-credit-card"/></svg></span>
        <div class="stat-label"><?= $isRefund ? 'Refunded' : 'Remitted' ?></div>
        <div class="stat-value font-mono"><?= e(format_currency(ltrim($remitted, '-'))) ?></div>
        <div class="stat-delta"><?= bccomp($remaining, '0', 2) === 0 ? 'settled' : e(format_currency(ltrim($remaining, '-'))) . ' left' ?></div>
    </div>
    <div class="stat-card <?= $isOverdue ? 'stat-card--red' : 'stat-card--slate' ?>">
        <span class="stat-icon <?= $isOverdue ? 'stat-icon--red' : 'stat-icon--slate' ?>"><svg><use href="#icon-<?= $isOverdue ? 'exclamation-triangle' : 'clock' ?>"/></svg></span>
        <div class="stat-label">Filing due</div>
        <div class="stat-value stat-value--date font-mono"<?= $isOverdue ? ' style="color:var(--color-danger);"' : '' ?>><?= $dueDate !== '' ? e(format_date($dueDate)) : '—' ?></div>
        <div class="stat-delta"><?php
            if ($isSettled) {
                echo e($period['status']);
            } elseif ($daysToDue !== null) {
                echo $daysToDue < 0 ? e((-$daysToDue) . 'd overdue') : ($daysToDue === 0 ? 'today' : e('in ' . $daysToDue . 'd'));
            }
        ?></div>
    </div>
</div>

<div class="rec-layout">
<div class="rec-main">

<!-- ============================================================
     ALPINE COMPONENT — fetches drill-down + remittances
     ============================================================ -->
<div x-data="FF_TaxPeriodDetail(<?= (int) $id ?>)">

    <!-- ── Remittances ───────────────────────────────────────── -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Remittance history <span class="text-secondary" style="font-weight:400;font-size:0.78rem;" x-text="'(' + remittances.length + ')'"></span></h3></div>
        <template x-if="remittances.length === 0">
            <div class="card-body"><p class="text-secondary text-sm" style="margin:0;" x-text="loadingDetail ? 'Loading…' : 'No remittances recorded yet.'"></p></div>
        </template>
        <template x-if="remittances.length > 0">
            <div class="card-body" style="padding:0;">
            <div class="table-responsive">
            <table class="table">
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
                            <td class="text-right font-mono" x-text="(r.direction === 'refund' ? '−' : '') + formatMoney(r.amount)"></td>
                            <td class="text-sm" x-text="r.payment_method"></td>
                            <td class="text-sm" x-text="r.bank_account_name || 'Cash 1010'"></td>
                            <td class="text-sm font-mono" x-text="r.reference_number || '—'"></td>
                            <td class="font-mono text-sm">
                                <template x-if="r.journal_entry_id && <?= can('journal_entries', 'view') ? 'true' : 'false' ?>">
                                    <a class="link" :href="'<?= base_url('accounting/journal-entries/show') ?>?id=' + r.journal_entry_id" x-text="r.journal_entry_number ? '#' + r.journal_entry_number : 'JE'"></a>
                                </template>
                                <template x-if="!r.journal_entry_id || <?= can('journal_entries', 'view') ? 'false' : 'true' ?>"><span x-text="r.journal_entry_number ? '#' + r.journal_entry_number : '—'"></span></template>
                            </td>
                            <td class="text-sm" x-text="r.created_by_name || '—'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
            </div>
            </div>
        </template>
    </div>

    <!-- ── Drill-down: contributing invoices ─────────────────── -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Contributing invoices <span class="text-secondary" style="font-weight:400;font-size:0.78rem;" x-text="'(' + invoices.length + ')'"></span></h3></div>
        <template x-if="loadingDetail">
            <div class="card-body"><p class="text-secondary text-sm" style="margin:0;">Loading drill-down…</p></div>
        </template>
        <template x-if="!loadingDetail && invoices.length === 0">
            <div class="card-body"><p class="text-secondary text-sm" style="margin:0;">No invoices contributed to this period.</p></div>
        </template>
        <template x-if="!loadingDetail && invoices.length > 0">
            <div class="card-body" style="padding:0;max-height:480px;overflow-y:auto;">
            <div class="table-responsive">
            <table class="table">
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
                            <?php if (can('invoices', 'view')): /* link only when the viewer can open it */ ?>
                            <td class="font-mono text-sm"><a class="link" :href="'<?= base_url('invoices/show') ?>?id=' + i.id" x-text="i.invoice_number"></a></td>
                            <?php else: ?>
                            <td class="font-mono text-sm" x-text="i.invoice_number"></td>
                            <?php endif; ?>
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
    </div>

    <!-- ── Drill-down: contributing bills (gst_hst only) ─────── -->
    <template x-if="taxType === 'gst_hst'">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Contributing vendor bills (GST ITC) <span class="text-secondary" style="font-weight:400;font-size:0.78rem;" x-text="'(' + bills.length + ')'"></span></h3></div>
            <template x-if="!loadingDetail && bills.length === 0">
                <div class="card-body"><p class="text-secondary text-sm" style="margin:0;">No vendor bills with GST input tax credits in this period.</p></div>
            </template>
            <template x-if="bills.length > 0">
                <div class="card-body" style="padding:0;max-height:400px;overflow-y:auto;">
                <div class="table-responsive">
                <table class="table">
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
                                <?php if (can('accounts_payable', 'view')): ?>
                                <td class="font-mono text-sm"><a class="link" :href="'<?= base_url('accounting/bills/show') ?>?id=' + b.id" x-text="b.bill_number || ('#' + b.id)"></a></td>
                                <?php else: ?>
                                <td class="font-mono text-sm" x-text="b.bill_number || ('#' + b.id)"></td>
                                <?php endif; ?>
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
<div id="tax-documents">
<?php
$entityType = 'tax_filing';
$entityId   = (int) $period['id'];
require FF_ROOT . '/includes/partials/acc-documents-section.php';
?>
</div>

</div><!-- /rec-main -->
<?php
// ── RAIL (S-RECORD-REDESIGN) — the period at a glance ───────────────────────
$R = \FleetForge\Ui\RecordUi::class;
$rail = [];

$alerts = [];
if ($isOverdue) {
    $alerts[] = ['danger', 'Filing was due ' . e(format_date($dueDate)) . ' — <b>' . (-$daysToDue) . ' day' . ($daysToDue === -1 ? '' : 's') . ' overdue</b>. File and remit from <a href="' . e(base_url('accounting/tax')) . '">Tax Management</a>.'];
} elseif ($daysToDue !== null && !$isSettled && $daysToDue <= 14) {
    $alerts[] = ['warning', 'Filing due ' . ($daysToDue === 0 ? 'today' : 'in ' . $daysToDue . ' day' . ($daysToDue === 1 ? '' : 's')) . ' (' . e(format_date($dueDate)) . ').'];
}
if ($period['status'] === 'open') {
    $alerts[] = ['info', 'Not calculated yet — the totals fill in when the period is calculated in <a href="' . e(base_url('accounting/tax')) . '">Tax Management</a>.'];
}
if ($period['status'] === 'filed' && bccomp($remaining, '0', 2) !== 0) {
    $alerts[] = ['warning', 'Filed but ' . ($isRefund ? 'the refund is not received' : 'not fully remitted') . ' — <b>' . e(format_currency(ltrim($remaining, '-'))) . '</b> ' . ($isRefund ? 'still to come back.' : 'still to pay.')];
}
if ($isRefund && !$isSettled) {
    $alerts[] = ['info', 'Credits exceed tax collected — this period is a <b>refund</b> of ' . e(format_currency($absNet)) . '.'];
}
$rail[] = $R::card('Needs attention', $R::alerts($alerts, $isSettled ? 'Filed — nothing to do.' : 'All clear — nothing needs attention.'), ['icon' => 'exclamation-triangle']);

$rail[] = $R::card($isRefund ? 'Refund' : 'Remittance',
    $R::meter($isRefund ? 'Refunded' : 'Remitted', e((string) round($remPct)) . '%', $remPct, $remPct >= 99.99 ? 'ok' : ($isOverdue ? 'danger' : 'info'),
        e(format_currency(ltrim($remitted, '-'))) . ' of ' . e(format_currency($absNet)))
    . $R::kv([
        ['Balance', e(format_currency(ltrim($remaining, '-'))), 'mono'],
        ['Remittances', (string) $remCount],
        ['Last', !empty($remRow['last_date']) ? e(format_date($remRow['last_date'])) : null],
    ]),
    ['icon' => 'banknotes', 'class' => 'rec-card--accent']);

$rail[] = $R::card('Period', $R::kv([
    ['Tax', e($taxLabel)],
    ['Span', e(format_date($period['period_start'])) . ' → ' . e(format_date($period['period_end']))],
    ['Frequency', e(ucfirst((string) $period['frequency']))],
    ['Total sales', e(format_currency($period['total_sales'])), 'mono'],
    ['Filed', !empty($period['filed_date']) ? e(format_date($period['filed_date'])) . (!empty($period['filed_by_name']) ? '<br><span class="text-secondary">' . e($period['filed_by_name']) . '</span>' : '') : 'Not filed'],
    ['Notes', !empty($period['notes']) ? nl2br(e($period['notes'])) : null],
]), ['icon' => 'calendar-days']);
?>
<aside class="rec-rail" aria-label="Tax period at a glance">
    <?= implode("\n    ", $rail) ?>
</aside>
</div><!-- /rec-layout -->

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
