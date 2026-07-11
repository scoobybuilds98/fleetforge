<?php declare(strict_types=1);

/**
 * app/admin/accounting/tax/gst34.php
 *
 * GST34 Generator admin page per spec §23.7. Filing-period picker →
 * 13-line GST34 table with subtotal highlights + ITC restrictions banner +
 * 3-tab tax detail section (By Code / By Customer / ITC Documentation).
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           api/v1/accounting/tax/{gst34,itc-report,tax-detail}.php
 * @session  S-ACCT-GST34
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('tax_management', 'view');

$periods = db_select(
    "SELECT id, tax_type, period_start, period_end, filing_due_date, status, frequency
       FROM acc_tax_filing_periods
      ORDER BY period_start DESC LIMIT 36"
);
$defaultPeriodId = (int) ($periods[0]['id'] ?? 0);

$craBn         = (string) settings_get('accounting.cra_business_number', '');
$quickEnabled  = ((string) settings_get('accounting.gst_quick_method_enabled', '0')) === '1';
$filingFreq    = (string) settings_get('accounting.gst_filing_frequency', 'quarterly');

$pageTitle = 'GST34 Generator';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/tax') ?>">Tax Management</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">GST34 Generator</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">GST34 Generator (CRA T6020/T2151)</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="gst34Page()" x-init="periodId = <?= (int) $defaultPeriodId ?>; if (periodId) load()">

    <!-- Context banner -->
    <div class="card" style="padding:12px 16px;margin-bottom:14px;display:flex;gap:18px;align-items:center;flex-wrap:wrap;font-size:0.8125rem;">
        <div><strong>CRA Business Number:</strong>
            <span class="font-mono" style="<?= $craBn === '' ? 'color:var(--color-danger);' : '' ?>">
                <?= $craBn === '' ? '(not configured — set in Tax → Settings)' : e($craBn) ?>
            </span>
        </div>
        <div><strong>Filing Frequency:</strong> <span class="font-mono"><?= e($filingFreq) ?></span></div>
        <div><strong>Quick Method:</strong>
            <?php if ($quickEnabled): ?>
                <span class="badge badge-warning" style="padding:2px 8px;">ENABLED</span>
            <?php else: ?>
                <span class="badge badge-neutral" style="padding:2px 8px;">Disabled (regular method)</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Period picker + actions -->
    <div class="card" style="padding:14px 18px;margin-bottom:16px;display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
        <div>
            <label class="form-label" style="display:block;font-size:0.7rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Filing Period</label>
            <select x-model.number="periodId" class="form-input" style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;font-size:0.8125rem;min-width:340px;">
                <?php if (empty($periods)): ?>
                    <option value="">— No filing periods on disk — create one under Tax → Periods —</option>
                <?php endif; ?>
                <?php foreach ($periods as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= e($p['period_start'] . ' to ' . $p['period_end']) ?> &mdash; <?= e($p['tax_type']) ?> &mdash; <?= e($p['status']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button @click="load()" :disabled="!periodId || loading" class="btn btn-primary" style="height:36px;">
            <span x-show="!loading">Generate GST34</span>
            <span x-show="loading">Computing...</span>
        </button>
        <button @click="downloadCsv()" :disabled="!data" class="btn btn-secondary" style="height:36px;">Download CSV</button>
        <button @click="downloadPdf()" :disabled="!data" class="btn btn-secondary" style="height:36px;">Download PDF</button>
        <button @click="downloadXml()" :disabled="!data" class="btn btn-secondary" style="height:36px;">Download XML</button>
    </div>

    <template x-if="loading">
        <div class="card" style="padding:36px;text-align:center;">Loading GST34...</div>
    </template>
    <template x-if="error">
        <div class="card" style="padding:18px;color:var(--color-danger);" x-text="error"></div>
    </template>

    <template x-if="!loading && !error && data">
        <div>
            <!-- Quick Method banner -->
            <template x-if="data.quick_method">
                <div class="alert alert-warning" style="margin-bottom:14px;font-size:0.8125rem;">
                    <strong>⚠ Quick Method active</strong> (<span x-text="data.quick_rate"></span>%): Line 103 computed as revenue × Quick Method rate. ITCs limited to capital purchases only. Mainland likely exceeds the $400K Quick Method ceiling — confirm with accountant before relying on this filing.
                </div>
            </template>

            <!-- ITC restrictions banner -->
            <template x-if="(data.lines.L106.restrictions_applied || []).length > 0">
                <div class="alert alert-warning" style="margin-bottom:14px;font-size:0.8125rem;">
                    <strong>ITC restrictions applied:</strong>
                    <span x-text="data.lines.L106.restrictions_applied.length"></span> restriction(s) reducing claimed ITCs.
                    Total reduction:
                    <span class="font-mono">
                        $<span x-text="restrictionTotal()"></span>
                    </span>
                    (Click Line 106 below for detail.)
                </div>
            </template>

            <!-- Warnings -->
            <template x-if="(data.warnings || []).length > 0">
                <div style="background:#eef2ff;border:1px solid #6366f1;color:#3730a3;padding:10px 14px;margin-bottom:14px;font-size:0.75rem;border-radius:6px;">
                    <template x-for="w in data.warnings" :key="w">
                        <div>⚠ <span x-text="w"></span></div>
                    </template>
                </div>
            </template>

            <!-- 13-line table -->
            <div class="card" style="padding:0;overflow:auto;">
                <table class="table" style="font-size:0.875rem;">
                    <thead>
                        <tr>
                            <th style="width:60px;">Line</th>
                            <th>Description</th>
                            <th class="text-right" style="width:160px;">Amount</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(line, key) in data.lines" :key="key">
                            <template>
                                <tr :style="['L105','L108','L112'].includes(key) ? 'background:var(--bg-subtle);font-weight:600;' :
                                            ['L109','L113'].includes(key) ? 'background:' + bannerColor(line.status) + ';font-weight:700;' : ''">
                                    <td class="font-mono" style="font-weight:600;" x-text="key.replace(/^L/, '')"></td>
                                    <td x-text="line.description"></td>
                                    <td class="font-mono text-right" x-text="'$' + money(line.amount)"></td>
                                    <td>
                                        <button class="btn btn-ghost btn-xs"
                                                x-show="line.source_ids || line.restrictions_applied"
                                                @click="toggleDrill(key)"
                                                :title="expanded[key] ? 'Hide detail' : 'Show detail'">
                                            <span x-text="expanded[key] ? '−' : '+'"></span>
                                        </button>
                                    </td>
                                </tr>
                                <template x-if="expanded[key]">
                                    <tr>
                                        <td colspan="4" style="background:var(--bg-subtle);padding:10px 16px;font-size:0.75rem;">
                                            <template x-if="line.source_ids">
                                                <div>
                                                    <strong>Source invoices</strong> (<span x-text="line.source_count"></span> total, first 50 ids):
                                                    <span class="font-mono" x-text="line.source_ids"></span>
                                                </div>
                                            </template>
                                            <template x-if="line.gl_account_id">
                                                <div><strong>GL account:</strong> #<span x-text="line.gl_account_id"></span></div>
                                            </template>
                                            <template x-if="line.restrictions_applied && line.restrictions_applied.length > 0">
                                                <div>
                                                    <strong>ITC restrictions (<span x-text="line.restrictions_applied.length"></span>):</strong>
                                                    <table class="table" style="font-size:0.7rem;margin-top:6px;">
                                                        <thead><tr><th>Bill #</th><th>Reason</th><th>Detail</th><th class="text-right">Original</th><th class="text-right">Adjusted</th><th class="text-right">Reduction</th></tr></thead>
                                                        <tbody>
                                                            <template x-for="r in line.restrictions_applied" :key="r.bill_id">
                                                                <tr>
                                                                    <td class="font-mono" x-text="r.bill_number"></td>
                                                                    <td x-text="r.reason"></td>
                                                                    <td x-text="r.detail"></td>
                                                                    <td class="font-mono text-right" x-text="'$' + money(r.original_itc)"></td>
                                                                    <td class="font-mono text-right" x-text="'$' + money(r.adjusted_itc)"></td>
                                                                    <td class="font-mono text-right" style="color:var(--color-danger);" x-text="'−$' + money(r.reduction)"></td>
                                                                </tr>
                                                            </template>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </template>
                                        </td>
                                    </tr>
                                </template>
                            </template>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </template>

    <!-- Tax Detail section -->
    <template x-if="data">
        <div class="card" style="margin-top:18px;padding:0;">
            <div class="tab-bar" style="border-bottom:1px solid var(--border-default);padding:0 16px;">
                <button class="tab-btn" :class="{ 'is-active': detailTab === 'by_code' }"
                        @click="loadDetail('by_code')">By Province / Tax Code</button>
                <button class="tab-btn" :class="{ 'is-active': detailTab === 'by_customer' }"
                        @click="loadDetail('by_customer')">By Customer</button>
                <button class="tab-btn" :class="{ 'is-active': detailTab === 'itc_docs' }"
                        @click="loadDetail('itc_docs')">ITC Documentation</button>
            </div>

            <div style="padding:16px;">
                <template x-if="detailTab === 'by_code' && taxDetail">
                    <table class="table" style="font-size:0.8125rem;">
                        <thead><tr>
                            <th>Province</th>
                            <th class="text-right">Invoices</th>
                            <th class="text-right">Taxable Sales</th>
                            <th class="text-right">GST</th>
                            <th class="text-right">HST</th>
                            <th class="text-right">PST</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="r in (taxDetail.by_code || [])" :key="r.province">
                                <tr>
                                    <td class="font-mono" x-text="r.province || '(unknown)'"></td>
                                    <td class="text-right font-mono" x-text="r.invoice_count"></td>
                                    <td class="font-mono text-right" x-text="'$' + money(r.taxable_sales)"></td>
                                    <td class="font-mono text-right" x-text="'$' + money(r.gst_collected)"></td>
                                    <td class="font-mono text-right" x-text="'$' + money(r.hst_collected)"></td>
                                    <td class="font-mono text-right" x-text="'$' + money(r.pst_collected)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </template>

                <template x-if="detailTab === 'by_customer' && taxDetail">
                    <table class="table" style="font-size:0.8125rem;">
                        <thead><tr>
                            <th>Customer</th>
                            <th>Applied</th>
                            <th>POS Derived</th>
                            <th class="text-right">Invoices</th>
                            <th class="text-right">Revenue</th>
                            <th class="text-right">GST+HST</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="r in (taxDetail.by_customer || [])" :key="r.customer_id">
                                <tr :style="r.pos_mismatch ? 'background:var(--color-warning-light);' : ''">
                                    <td x-text="r.company_name"></td>
                                    <td class="font-mono" x-text="r.province_applied"></td>
                                    <td class="font-mono">
                                        <span x-text="r.pos_derived_province || '—'"></span>
                                        <span x-show="r.pos_mismatch" class="badge badge-warning" style="padding:1px 6px;font-size:0.625rem;margin-left:4px;">mismatch</span>
                                    </td>
                                    <td class="text-right font-mono" x-text="r.invoice_count"></td>
                                    <td class="font-mono text-right" x-text="'$' + money(r.total_revenue)"></td>
                                    <td class="font-mono text-right" x-text="'$' + money((parseFloat(r.gst_collected || 0) + parseFloat(r.hst_collected || 0)).toFixed(2))"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </template>

                <template x-if="detailTab === 'itc_docs' && itcReport">
                    <div>
                        <div style="font-size:0.875rem;margin-bottom:10px;">
                            <strong x-text="itcReport.documentation.total_bills"></strong> bill(s) in period —
                            <span class="badge badge-success" style="padding:2px 10px;font-size:0.75rem;" x-text="itcReport.documentation.compliant_count + ' compliant'"></span>
                            <span :class="itcReport.documentation.non_compliant_count === 0 ? 'badge badge-success' : 'badge badge-warning'"
                                  style="padding:2px 10px;font-size:0.75rem;margin-left:6px;"
                                  x-text="itcReport.documentation.non_compliant_count + ' non-compliant'"></span>
                        </div>
                        <template x-if="itcReport.documentation.schema_gaps && itcReport.documentation.schema_gaps.length > 0">
                            <div style="background:#eef2ff;border:1px solid #6366f1;color:#3730a3;padding:8px 12px;margin-bottom:12px;font-size:0.75rem;border-radius:6px;">
                                <strong>Known schema gaps:</strong>
                                <ul style="margin:4px 0 0 18px;">
                                    <template x-for="g in itcReport.documentation.schema_gaps" :key="g">
                                        <li x-text="g"></li>
                                    </template>
                                </ul>
                            </div>
                        </template>
                        <template x-if="itcReport.documentation.non_compliant.length > 0">
                            <table class="table" style="font-size:0.8125rem;">
                                <thead><tr><th>Bill #</th><th>Date</th><th>Vendor</th><th class="text-right">Amount</th><th>Tier</th><th>Missing fields</th></tr></thead>
                                <tbody>
                                    <template x-for="b in itcReport.documentation.non_compliant" :key="b.bill_id">
                                        <tr>
                                            <td class="font-mono" x-text="b.bill_number"></td>
                                            <td x-text="b.bill_date"></td>
                                            <td x-text="b.vendor_name"></td>
                                            <td class="font-mono text-right" x-text="'$' + money(b.amount)"></td>
                                            <td class="font-mono" x-text="b.tier"></td>
                                            <td x-text="b.missing_fields.join(', ')"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>

<script>
function gst34Page() {
    return {
        periodId: <?= (int) $defaultPeriodId ?>,
        loading: false, error: null, data: null,
        expanded: {},
        detailTab: 'by_code', taxDetail: null, itcReport: null,

        async load() {
            if (!this.periodId) return;
            this.loading = true; this.error = null;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/tax/gst34') ?>?period_id=' + this.periodId);
                if (r.success) this.data = r.data;
                else this.error = (r.error && r.error.message) || 'Generate failed.';
                // Reset detail panels so they reload against the new period.
                this.taxDetail = null; this.itcReport = null;
                this.detailTab = 'by_code';
                if (this.data) this.loadDetail('by_code');
            } catch (e) { this.error = 'Network error.'; }
            this.loading = false;
        },

        async loadDetail(tab) {
            this.detailTab = tab;
            if (tab === 'itc_docs') {
                if (this.itcReport) return;
                try {
                    const r = await FF_Api.get('<?= base_url('api/v1/accounting/tax/itc-report') ?>?period_id=' + this.periodId);
                    if (r.success) this.itcReport = r.data;
                } catch (e) { /* non-critical */ }
            } else {
                if (this.taxDetail) return;
                try {
                    const r = await FF_Api.get('<?= base_url('api/v1/accounting/tax/tax-detail') ?>?period_id=' + this.periodId);
                    if (r.success) this.taxDetail = r.data;
                } catch (e) { /* non-critical */ }
            }
        },

        toggleDrill(key) { this.expanded[key] = !this.expanded[key]; },

        downloadCsv() { window.open('<?= base_url('api/v1/accounting/tax/gst34') ?>?period_id=' + this.periodId + '&format=csv', '_blank'); },
        downloadPdf() { window.open('<?= base_url('api/v1/accounting/tax/gst34') ?>?period_id=' + this.periodId + '&format=pdf', '_blank'); },
        downloadXml() { window.open('<?= base_url('api/v1/accounting/tax/gst34') ?>?period_id=' + this.periodId + '&format=xml', '_blank'); },

        bannerColor(status) {
            if (status === 'owing' || status === 'balance_due') return '#fee2e2';
            if (status === 'refund') return '#d1fae5';
            return 'var(--bg-subtle)';
        },

        money(v) {
            const n = parseFloat(v || 0);
            const sign = n < 0 ? '-' : '';
            return sign + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        restrictionTotal() {
            const arr = this.data?.lines?.L106?.restrictions_applied || [];
            let total = 0;
            for (const r of arr) total += parseFloat(r.reduction || 0);
            return total.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
