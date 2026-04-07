<?php declare(strict_types=1);

/**
 * app/admin/accounting/reports/trial-balance.php
 *
 * Trial balance report page — all accounts with debit/credit totals
 * from posted JEs. Total debits must equal total credits.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php,
 *           api/v1/accounting/reports/trial-balance.php
 * @session  S030
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$pageTitle = 'Trial Balance';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Trial Balance</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Trial Balance</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="trialBalancePage()" x-init="load()">
    <div style="margin-bottom:20px;display:flex;justify-content:flex-end;align-items:end;gap:12px;">
        <div style="display:flex;gap:8px;align-items:end;">
            <div>
                <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">As of Date</label>
                <input type="date" x-model="asOfDate" @change="load()"
                       class="form-input" style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
            </div>
        </div>
    </div>

    <!-- Balance Status -->
    <template x-if="data">
        <div class="card" style="padding:16px;margin-bottom:20px;display:flex;gap:24px;align-items:center;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:8px;">
                <template x-if="data.is_balanced">
                    <span class="badge badge-success" style="padding:4px 10px;font-size:0.75rem;">Balanced</span>
                </template>
                <template x-if="!data.is_balanced">
                    <span class="badge badge-danger" style="padding:4px 10px;font-size:0.75rem;">Out of Balance</span>
                </template>
            </div>
            <div>
                <span style="font-size:0.75rem;color:var(--text-secondary);">Total Debits:</span>
                <span class="font-mono" style="font-weight:600;margin-left:4px;" x-text="'$' + Number(data.total_debits).toLocaleString('en-CA', {minimumFractionDigits:2})"></span>
            </div>
            <div>
                <span style="font-size:0.75rem;color:var(--text-secondary);">Total Credits:</span>
                <span class="font-mono" style="font-weight:600;margin-left:4px;" x-text="'$' + Number(data.total_credits).toLocaleString('en-CA', {minimumFractionDigits:2})"></span>
            </div>
            <template x-if="!data.is_balanced">
                <div>
                    <span style="font-size:0.75rem;color:var(--color-danger);">Difference:</span>
                    <span class="font-mono" style="font-weight:600;color:var(--color-danger);margin-left:4px;" x-text="'$' + Math.abs(Number(data.total_debits) - Number(data.total_credits)).toLocaleString('en-CA', {minimumFractionDigits:2})"></span>
                </div>
            </template>
        </div>
    </template>

    <!-- Loading -->
    <template x-if="loading">
        <div class="card" style="padding:48px;text-align:center;">
            <div class="spinner" style="margin:0 auto 12px;"></div>
            <div style="color:var(--text-secondary);font-size:0.875rem;">Loading trial balance...</div>
        </div>
    </template>

    <!-- Data Table -->
    <template x-if="data && data.accounts.length > 0 && !loading">
        <div class="card" style="overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-default);">
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Code</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Account Name</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Type</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Debit</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, i) in data.accounts" :key="row.account_id">
                        <tr style="border-bottom:1px solid var(--border-default);" :style="i % 2 ? 'background:var(--bg-surface-2)' : ''">
                            <td class="font-mono" style="padding:8px 12px;font-weight:500;" x-text="row.code"></td>
                            <td style="padding:8px 12px;">
                                <a :href="FF_Api.base + '/accounting/ledger?account_id=' + row.account_id"
                                   style="color:var(--color-primary);text-decoration:none;"
                                   x-text="row.name"></a>
                            </td>
                            <td style="padding:8px 12px;">
                                <span class="badge badge-neutral" style="font-size:0.6875rem;padding:2px 6px;text-transform:capitalize;"
                                      x-text="row.account_type.replace(/_/g, ' ')"></span>
                            </td>
                            <td class="font-mono" style="padding:8px 12px;text-align:right;"
                                x-text="Number(row.trial_debit) > 0 ? '$' + Number(row.trial_debit).toLocaleString('en-CA', {minimumFractionDigits:2}) : ''"></td>
                            <td class="font-mono" style="padding:8px 12px;text-align:right;"
                                x-text="Number(row.trial_credit) > 0 ? '$' + Number(row.trial_credit).toLocaleString('en-CA', {minimumFractionDigits:2}) : ''"></td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr style="border-top:3px double var(--border-default);font-weight:700;">
                        <td colspan="3" style="padding:10px 12px;">TOTALS</td>
                        <td class="font-mono" style="padding:10px 12px;text-align:right;" x-text="'$' + Number(data.total_debits).toLocaleString('en-CA', {minimumFractionDigits:2})"></td>
                        <td class="font-mono" style="padding:10px 12px;text-align:right;" x-text="'$' + Number(data.total_credits).toLocaleString('en-CA', {minimumFractionDigits:2})"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </template>

    <!-- Empty -->
    <template x-if="data && data.accounts.length === 0 && !loading">
        <div class="card" style="padding:48px;text-align:center;">
            <div style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:4px;">No Journal Entries</div>
            <div style="font-size:0.8125rem;color:var(--text-secondary);">No posted journal entries found. Trial balance will populate once entries are posted.</div>
        </div>
    </template>
</div>

<script>
function trialBalancePage() {
    return {
        asOfDate: new Date().toISOString().slice(0, 10),
        data: null,
        loading: false,

        async load() {
            this.loading = true;
            try {
                const params = new URLSearchParams({ as_of_date: this.asOfDate });
                const resp = await fetch(FF_Api.url('/api/v1/accounting/reports/trial-balance.php?' + params));
                const json = await resp.json();
                if (json.success) this.data = json.data;
            } catch (e) {
                console.error('Trial balance load error:', e);
            }
            this.loading = false;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
