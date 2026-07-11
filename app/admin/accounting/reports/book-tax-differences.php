<?php declare(strict_types=1);

/**
 * app/admin/accounting/reports/book-tax-differences.php
 *
 * Book vs Tax Temporary Differences report page per spec §23.4. Renders
 * the 4-row reconciliation between ASPE book amounts (depreciation, accruals,
 * reserves, other) and CRA tax positions (CCA claimed, deductible-when-paid,
 * etc.) for any fiscal year.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           includes/footer.php, includes/partials/accounting-nav.php,
 *           api/v1/accounting/reports/book-tax-differences.php
 * @session  S-ACCT-CCA-2
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

// Default fiscal year: most recent period year with posted activity.
$defaultYear = (int) (db_row(
    "SELECT p.year FROM acc_periods p
      WHERE EXISTS (SELECT 1 FROM acc_journal_entries je
                    WHERE je.period_id = p.id AND je.status = 'posted')
      ORDER BY p.year DESC LIMIT 1"
)['year'] ?? (int) date('Y'));

$availableYears = db_select(
    "SELECT DISTINCT year FROM acc_periods ORDER BY year DESC"
);

$pageTitle = 'Book vs Tax Differences';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Book vs Tax Differences</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Book vs Tax — Temporary Differences</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="bookTaxReport()" x-init="fiscalYear = <?= (int) $defaultYear ?>; load()">

    <!-- Controls -->
    <div class="card" style="padding:14px 18px;margin-bottom:16px;display:flex;gap:14px;align-items:end;flex-wrap:wrap;">
        <div>
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Fiscal Year</label>
            <select x-model.number="fiscalYear" @change="load()" class="form-input" style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                <?php foreach ($availableYears as $y): ?>
                    <option value="<?= (int) $y['year'] ?>"><?= (int) $y['year'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button @click="downloadPdf()" :disabled="loading || !data" class="btn btn-secondary" style="height:36px;">
            Export PDF
        </button>
    </div>

    <template x-if="loading">
        <div class="card" style="padding:36px;text-align:center;">Loading...</div>
    </template>

    <template x-if="error">
        <div class="card" style="padding:18px;color:var(--color-danger);" x-text="error"></div>
    </template>

    <template x-if="!loading && !error && data">
        <div>
            <!-- Method banner -->
            <div class="alert alert-warning" style="margin-bottom:16px;font-size:0.8125rem;">
                <strong>Method: <span x-text="data.method"></span></strong> — <span x-text="data.disclosure_note"></span>
            </div>

            <div class="card" style="padding:0;overflow:auto;">
                <table class="table" style="font-size:0.8125rem;">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-right">Book Amount</th>
                            <th class="text-right">Tax Amount</th>
                            <th class="text-right">Temporary Difference</th>
                            <th>Nature</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="item in data.items" :key="item.item">
                            <template>
                                <tr :style="parseFloat(item.temp_diff) !== 0 ? 'background:var(--bg-subtle);' : ''">
                                    <td x-text="item.item"></td>
                                    <td class="font-mono text-right" x-text="'$' + money(item.book_amount)"></td>
                                    <td class="font-mono text-right" x-text="'$' + money(item.tax_amount)"></td>
                                    <td class="font-mono text-right" style="font-weight:600;" x-text="'$' + money(item.temp_diff)"></td>
                                    <td>
                                        <span class="badge" :class="item.nature === 'timing' ? 'badge-blue' : 'badge-neutral'"
                                              style="padding:2px 8px;font-size:0.6875rem;" x-text="item.nature"></span>
                                    </td>
                                </tr>
                                <tr x-show="item.note">
                                    <td colspan="5" style="font-size:0.75rem;color:var(--text-secondary);font-style:italic;background:#fafafa;padding:4px 14px 8px;border-top:none;" x-text="item.note"></td>
                                </tr>
                            </template>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:600;border-top:2px solid var(--border-default);">
                            <td colspan="3">Total Temporary Difference</td>
                            <td class="font-mono text-right" x-text="'$' + money(data.total_temp_diff)"></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p style="margin-top:14px;font-size:0.75rem;color:var(--text-secondary);">
                Schedule generated for FY <span x-text="data.fiscal_year"></span>. Surfacing context for the T2 preparer; no deferred tax accrued (ASPE 3465 taxes-payable method).
            </p>
        </div>
    </template>
</div>

<script>
function bookTaxReport() {
    return {
        fiscalYear: <?= (int) $defaultYear ?>,
        loading: false,
        error: null,
        data: null,

        async load() {
            if (!this.fiscalYear) return;
            this.loading = true; this.error = null;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/reports/book-tax-differences') ?>?fiscal_year=' + this.fiscalYear);
                if (r.success) this.data = r.data;
                else this.error = (r.error && r.error.message) || 'Failed to load.';
            } catch (e) { this.error = 'Network error.'; }
            this.loading = false;
        },

        downloadPdf() {
            window.open('<?= base_url('api/v1/accounting/reports/book-tax-differences') ?>?fiscal_year=' + this.fiscalYear + '&format=pdf', '_blank');
        },

        money(v) {
            const n = parseFloat(v || 0);
            const sign = n < 0 ? '-' : '';
            return sign + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
