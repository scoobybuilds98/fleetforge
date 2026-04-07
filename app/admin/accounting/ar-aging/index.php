<?php declare(strict_types=1);

/**
 * app/admin/accounting/ar-aging/index.php
 *
 * AR Aging report page — displays open invoices grouped by customer
 * and aging bucket (current, 1-30, 31-60, 61-90, 90+ days past due).
 * Includes AR/GL reconciliation check alert.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php,
 *           api/v1/accounting/reports/ar-aging.php, api/v1/accounting/ar/reconcile_check.php
 * @session  S030
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$pageTitle = 'AR Aging';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">AR Aging</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Accounts Receivable Aging</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="arAgingPage()" x-init="load()">
    <div style="margin-bottom:20px;display:flex;justify-content:flex-end;align-items:end;gap:12px;">
        <div style="display:flex;gap:8px;align-items:end;">
            <div>
                <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">As of Date</label>
                <input type="date" x-model="asOfDate" @change="load()"
                       class="form-input" style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
            </div>
            <button class="btn btn-secondary btn-sm" @click="loadRecon()" style="height:36px;">
                Check Reconciliation
            </button>
        </div>
    </div>

    <!-- Reconciliation Alert -->
    <template x-if="recon && !recon.is_reconciled">
        <div style="padding:12px 16px;margin-bottom:16px;border-radius:8px;background:var(--badge-danger-bg);border:1px solid var(--color-danger);display:flex;align-items:center;gap:10px;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="var(--color-danger)" style="width:20px;height:20px;flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
            </svg>
            <div style="flex:1;">
                <div style="font-weight:600;color:var(--badge-danger-text);font-size:0.875rem;">AR Reconciliation Mismatch</div>
                <div style="font-size:0.8125rem;color:var(--badge-danger-text);">
                    GL AR balance: <span class="font-mono" x-text="'$' + Number(recon.gl_balance).toLocaleString('en-CA', {minimumFractionDigits:2})"></span>
                    &nbsp;|&nbsp;
                    Subledger: <span class="font-mono" x-text="'$' + Number(recon.subledger_balance).toLocaleString('en-CA', {minimumFractionDigits:2})"></span>
                    &nbsp;|&nbsp;
                    Difference: <span class="font-mono" x-text="'$' + Number(recon.difference).toLocaleString('en-CA', {minimumFractionDigits:2})"></span>
                </div>
            </div>
        </div>
    </template>

    <template x-if="recon && recon.is_reconciled">
        <div style="padding:12px 16px;margin-bottom:16px;border-radius:8px;background:var(--badge-success-bg);border:1px solid var(--color-success);display:flex;align-items:center;gap:10px;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="var(--color-success)" style="width:20px;height:20px;flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
            </svg>
            <div style="font-weight:600;color:var(--badge-success-text);font-size:0.875rem;">
                AR Reconciled — GL and subledger balances match
                (<span class="font-mono" x-text="'$' + Number(recon.gl_balance).toLocaleString('en-CA', {minimumFractionDigits:2})"></span>)
            </div>
        </div>
    </template>

    <!-- Summary KPI Tiles -->
    <template x-if="data">
        <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:20px;">
            <div class="card" style="padding:14px;">
                <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Current</div>
                <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-success);" x-text="'$' + Number(data.totals.current).toLocaleString('en-CA', {minimumFractionDigits:2})"></div>
            </div>
            <div class="card" style="padding:14px;">
                <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">1–30 Days</div>
                <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-warning);" x-text="'$' + Number(data.totals.days_1_30).toLocaleString('en-CA', {minimumFractionDigits:2})"></div>
            </div>
            <div class="card" style="padding:14px;">
                <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">31–60 Days</div>
                <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-warning);" x-text="'$' + Number(data.totals.days_31_60).toLocaleString('en-CA', {minimumFractionDigits:2})"></div>
            </div>
            <div class="card" style="padding:14px;">
                <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">61–90 Days</div>
                <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-danger);" x-text="'$' + Number(data.totals.days_61_90).toLocaleString('en-CA', {minimumFractionDigits:2})"></div>
            </div>
            <div class="card" style="padding:14px;">
                <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">90+ Days</div>
                <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-danger);" x-text="'$' + Number(data.totals.days_90_plus).toLocaleString('en-CA', {minimumFractionDigits:2})"></div>
            </div>
            <div class="card" style="padding:14px;">
                <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Total AR</div>
                <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--text-primary);" x-text="'$' + Number(data.totals.total).toLocaleString('en-CA', {minimumFractionDigits:2})"></div>
            </div>
        </div>
    </template>

    <!-- Loading -->
    <template x-if="loading">
        <div class="card" style="padding:48px;text-align:center;">
            <div class="spinner" style="margin:0 auto 12px;"></div>
            <div style="color:var(--text-secondary);font-size:0.875rem;">Loading AR aging...</div>
        </div>
    </template>

    <!-- Customer Table -->
    <template x-if="data && data.customers.length > 0 && !loading">
        <div class="card" style="overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-default);">
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Customer</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Current</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">1–30</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">31–60</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">61–90</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">90+</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in flatRows" :key="row._key">
                        <tr :style="row._type === 'customer' ? 'border-bottom:1px solid var(--border-default);cursor:pointer;' + (row._idx % 2 ? 'background:var(--bg-surface-2)' : '') : 'background:var(--bg-surface-2);'"
                            @click="row._type === 'customer' ? (expanded === row.customer_id ? expanded = null : expanded = row.customer_id) : null">
                            <!-- Customer summary row -->
                            <template x-if="row._type === 'customer'">
                                <td style="padding:8px 12px;font-weight:500;">
                                    <span x-text="expanded === row.customer_id ? '▼' : '▶'" style="font-size:0.625rem;margin-right:6px;color:var(--text-tertiary);"></span>
                                    <span x-text="row.company_name"></span>
                                    <span style="color:var(--text-tertiary);font-size:0.75rem;margin-left:6px;" x-text="'(' + row.invoices.length + ' inv)'"></span>
                                </td>
                            </template>
                            <template x-if="row._type === 'customer'">
                                <td class="font-mono" style="padding:8px 12px;text-align:right;" x-text="fmtAmt(row.current)"></td>
                            </template>
                            <template x-if="row._type === 'customer'">
                                <td class="font-mono" style="padding:8px 12px;text-align:right;" x-text="fmtAmt(row.days_1_30)"></td>
                            </template>
                            <template x-if="row._type === 'customer'">
                                <td class="font-mono" style="padding:8px 12px;text-align:right;" x-text="fmtAmt(row.days_31_60)"></td>
                            </template>
                            <template x-if="row._type === 'customer'">
                                <td class="font-mono" style="padding:8px 12px;text-align:right;" x-text="fmtAmt(row.days_61_90)"></td>
                            </template>
                            <template x-if="row._type === 'customer'">
                                <td class="font-mono" style="padding:8px 12px;text-align:right;" x-text="fmtAmt(row.days_90_plus)"></td>
                            </template>
                            <template x-if="row._type === 'customer'">
                                <td class="font-mono" style="padding:8px 12px;text-align:right;font-weight:700;" x-text="fmtAmt(row.total)"></td>
                            </template>
                            <!-- Invoice detail row -->
                            <template x-if="row._type === 'invoice'">
                                <td style="padding:6px 12px 6px 36px;font-size:0.75rem;color:var(--text-secondary);">
                                    <span x-text="row.invoice_number" style="font-weight:500;color:var(--text-primary);"></span>
                                    <span style="margin-left:8px;" x-text="'Due: ' + row.due_date"></span>
                                    <span style="margin-left:8px;color:var(--text-tertiary);" x-text="row.days_overdue > 0 ? row.days_overdue + 'd overdue' : 'current'"></span>
                                </td>
                            </template>
                            <template x-if="row._type === 'invoice'">
                                <td class="font-mono" style="padding:6px 12px;text-align:right;font-size:0.75rem;color:var(--text-secondary);" x-text="row.bucket === 'current' ? fmtAmt(row.balance_due) : ''"></td>
                            </template>
                            <template x-if="row._type === 'invoice'">
                                <td class="font-mono" style="padding:6px 12px;text-align:right;font-size:0.75rem;color:var(--text-secondary);" x-text="row.bucket === 'days_1_30' ? fmtAmt(row.balance_due) : ''"></td>
                            </template>
                            <template x-if="row._type === 'invoice'">
                                <td class="font-mono" style="padding:6px 12px;text-align:right;font-size:0.75rem;color:var(--text-secondary);" x-text="row.bucket === 'days_31_60' ? fmtAmt(row.balance_due) : ''"></td>
                            </template>
                            <template x-if="row._type === 'invoice'">
                                <td class="font-mono" style="padding:6px 12px;text-align:right;font-size:0.75rem;color:var(--text-secondary);" x-text="row.bucket === 'days_61_90' ? fmtAmt(row.balance_due) : ''"></td>
                            </template>
                            <template x-if="row._type === 'invoice'">
                                <td class="font-mono" style="padding:6px 12px;text-align:right;font-size:0.75rem;color:var(--text-secondary);" x-text="row.bucket === 'days_90_plus' ? fmtAmt(row.balance_due) : ''"></td>
                            </template>
                            <template x-if="row._type === 'invoice'">
                                <td class="font-mono" style="padding:6px 12px;text-align:right;font-size:0.75rem;font-weight:500;" x-text="fmtAmt(row.balance_due)"></td>
                            </template>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr style="border-top:3px double var(--border-default);font-weight:700;">
                        <td style="padding:10px 12px;">TOTALS</td>
                        <td class="font-mono" style="padding:10px 12px;text-align:right;" x-text="fmtAmt(data.totals.current)"></td>
                        <td class="font-mono" style="padding:10px 12px;text-align:right;" x-text="fmtAmt(data.totals.days_1_30)"></td>
                        <td class="font-mono" style="padding:10px 12px;text-align:right;" x-text="fmtAmt(data.totals.days_31_60)"></td>
                        <td class="font-mono" style="padding:10px 12px;text-align:right;" x-text="fmtAmt(data.totals.days_61_90)"></td>
                        <td class="font-mono" style="padding:10px 12px;text-align:right;" x-text="fmtAmt(data.totals.days_90_plus)"></td>
                        <td class="font-mono" style="padding:10px 12px;text-align:right;" x-text="fmtAmt(data.totals.total)"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </template>

    <!-- Empty -->
    <template x-if="data && data.customers.length === 0 && !loading">
        <div class="card" style="padding:48px;text-align:center;">
            <div style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:4px;">No Outstanding AR</div>
            <div style="font-size:0.8125rem;color:var(--text-secondary);">All invoices are paid or there are no open invoices.</div>
        </div>
    </template>
</div>

<script>
function arAgingPage() {
    return {
        asOfDate: new Date().toISOString().slice(0, 10),
        data: null,
        recon: null,
        loading: false,
        expanded: null,

        async load() {
            this.loading = true;
            try {
                const params = new URLSearchParams({ as_of_date: this.asOfDate });
                const resp = await fetch(FF_Api.url('/api/v1/accounting/reports/ar-aging.php?' + params));
                const json = await resp.json();
                if (json.success) this.data = json.data;
            } catch (e) {
                console.error('AR aging load error:', e);
            }
            this.loading = false;
        },

        async loadRecon() {
            try {
                const resp = await fetch(FF_Api.url('/api/v1/accounting/ar/reconcile_check.php'));
                const json = await resp.json();
                if (json.success) this.recon = json.data;
            } catch (e) {
                console.error('Reconciliation check error:', e);
            }
        },

        get flatRows() {
            if (!this.data) return [];
            const rows = [];
            this.data.customers.forEach((cust, i) => {
                rows.push({ ...cust, _type: 'customer', _key: 'c' + cust.customer_id, _idx: i });
                if (this.expanded === cust.customer_id) {
                    cust.invoices.forEach(inv => {
                        rows.push({
                            ...inv,
                            _type: 'invoice',
                            _key: 'i' + inv.invoice_id,
                            days_overdue: inv.days_past_due,
                        });
                    });
                }
            });
            return rows;
        },

        fmtAmt(val) {
            const n = Number(val);
            return n > 0 ? '$' + n.toLocaleString('en-CA', {minimumFractionDigits:2}) : '—';
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
