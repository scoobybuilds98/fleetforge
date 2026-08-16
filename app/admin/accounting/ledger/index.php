<?php declare(strict_types=1);

/**
 * app/admin/accounting/ledger/index.php
 *
 * General Ledger account ledger page — select an account and date range,
 * view all posted journal entry lines with running balance.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php,
 *           api/v1/accounting/ledger/index.php
 * @session  S030
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

// Fetch accounts for the dropdown
$accounts = db_select(
    "SELECT id, code, name, account_type, normal_balance
     FROM acc_accounts WHERE is_active = 1 AND is_header = 0
     ORDER BY code ASC",
    []
);

$pageTitle = 'General Ledger';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Ledger</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">General Ledger</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="ledgerPage()">

    <!-- Filter Bar -->
    <div class="card" style="padding:16px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:end;">
        <div style="flex:1;min-width:250px;">
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Account</label>
            <select x-model="filters.account_id" @change="loadLedger()"
                    class="form-select" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                <option value="">Select an account...</option>
                <?php foreach ($accounts as $acct): ?>
                <option value="<?= $acct['id'] ?>"><?= e($acct['code']) ?> — <?= e($acct['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="min-width:150px;">
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">From</label>
            <input type="date" x-model="filters.date_from" @change="loadLedger()"
                   class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
        </div>
        <div style="min-width:150px;">
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">To</label>
            <input type="date" x-model="filters.date_to" @change="loadLedger()"
                   class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
        </div>
    </div>

    <!-- Summary Strip -->
    <template x-if="data && filters.account_id">
        <div class="card" style="padding:16px;margin-bottom:20px;display:flex;gap:24px;flex-wrap:wrap;">
            <div>
                <div style="font-size:0.75rem;color:var(--text-secondary);font-weight:600;">Account</div>
                <div style="font-size:0.9375rem;font-weight:600;color:var(--text-primary);" x-text="data.account.code + ' — ' + data.account.name"></div>
            </div>
            <div>
                <div style="font-size:0.75rem;color:var(--text-secondary);font-weight:600;">Opening Balance</div>
                <div class="font-mono" style="font-size:0.9375rem;font-weight:600;color:var(--text-primary);" x-text="'$' + Number(data.opening_balance).toLocaleString('en-CA', {minimumFractionDigits:2})"></div>
            </div>
            <div>
                <div style="font-size:0.75rem;color:var(--text-secondary);font-weight:600;">Closing Balance</div>
                <div class="font-mono" style="font-size:0.9375rem;font-weight:600;color:var(--text-primary);" x-text="'$' + Number(data.closing_balance).toLocaleString('en-CA', {minimumFractionDigits:2})"></div>
            </div>
            <div>
                <div style="font-size:0.75rem;color:var(--text-secondary);font-weight:600;">Entries</div>
                <div style="font-size:0.9375rem;font-weight:600;color:var(--text-primary);" x-text="data.total"></div>
            </div>
        </div>
    </template>

    <!-- Empty State -->
    <template x-if="!filters.account_id">
        <div class="card" style="padding:48px;text-align:center;">
            <div style="color:var(--text-tertiary);margin-bottom:8px;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:48px;height:48px;margin:0 auto;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/>
                </svg>
            </div>
            <div style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:4px;">Select an Account</div>
            <div style="font-size:0.8125rem;color:var(--text-secondary);">Choose an account from the dropdown above to view its ledger.</div>
        </div>
    </template>

    <!-- Loading -->
    <template x-if="loading && filters.account_id">
        <div class="card" style="padding:48px;text-align:center;">
            <div class="spinner" style="margin:0 auto 12px;"></div>
            <div style="color:var(--text-secondary);font-size:0.875rem;">Loading ledger...</div>
        </div>
    </template>

    <!-- Data Table -->
    <template x-if="data && data.rows.length > 0 && !loading">
        <div class="card" style="overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-default);">
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);white-space:nowrap;">Date</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Entry #</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Description</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Reference</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Debit</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Credit</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, i) in data.rows" :key="row.id">
                        <tr style="border-bottom:1px solid var(--border-default);" :style="i % 2 ? 'background:var(--bg-surface-2)' : ''">
                            <td class="font-mono" style="padding:8px 12px;white-space:nowrap;" x-text="row.entry_date"></td>
                            <td style="padding:8px 12px;">
                                <a :href="FF_Api.base + '/accounting/journal-entries?entry=' + row.journal_entry_id"
                                   style="color:var(--color-primary);text-decoration:none;font-weight:500;"
                                   x-text="row.entry_number"></a>
                            </td>
                            <td style="padding:8px 12px;max-width:300px;overflow:hidden;text-overflow:ellipsis;" x-text="row.line_description || row.description"></td>
                            <td style="padding:8px 12px;color:var(--text-secondary);" x-text="row.reference || '—'"></td>
                            <td class="font-mono" style="padding:8px 12px;text-align:right;" x-text="Number(row.debit) > 0 ? '$' + Number(row.debit).toLocaleString('en-CA', {minimumFractionDigits:2}) : ''"></td>
                            <td class="font-mono" style="padding:8px 12px;text-align:right;" x-text="Number(row.credit) > 0 ? '$' + Number(row.credit).toLocaleString('en-CA', {minimumFractionDigits:2}) : ''"></td>
                            <td class="font-mono" style="padding:8px 12px;text-align:right;font-weight:600;" x-text="'$' + Number(row.running_balance).toLocaleString('en-CA', {minimumFractionDigits:2})"></td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <!-- Pagination -->
            <div style="padding:12px 16px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border-default);">
                <span style="font-size:0.8125rem;color:var(--text-secondary);" x-text="'Showing ' + ((data.page-1)*data.per_page+1) + '–' + Math.min(data.page*data.per_page, data.total) + ' of ' + data.total"></span>
                <div style="display:flex;gap:6px;">
                    <button class="btn btn-secondary btn-sm" @click="prevPage()" :disabled="data.page <= 1">Prev</button>
                    <button class="btn btn-secondary btn-sm" @click="nextPage()" :disabled="data.page * data.per_page >= data.total">Next</button>
                </div>
            </div>
        </div>
    </template>

    <!-- No Results -->
    <template x-if="data && data.rows.length === 0 && !loading && filters.account_id">
        <div class="card" style="padding:48px;text-align:center;">
            <div style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:4px;">No Entries Found</div>
            <div style="font-size:0.8125rem;color:var(--text-secondary);">No posted journal entries for this account in the selected date range.</div>
        </div>
    </template>
</div>

<script>
function ledgerPage() {
    return {
        filters: {
            account_id: '',
            date_from: new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10),
            date_to: new Date().toISOString().slice(0, 10),
        },
        data: null,
        loading: false,
        page: 1,

        init() {},

        async loadLedger() {
            if (!this.filters.account_id) { this.data = null; return; }
            this.loading = true;
            try {
                const params = new URLSearchParams({
                    account_id: this.filters.account_id,
                    date_from: this.filters.date_from,
                    date_to: this.filters.date_to,
                    page: this.page,
                    per_page: 50,
                });
                const resp = await fetch(FF_Api.url('/api/v1/accounting/ledger/index.php?' + params));
                const json = await resp.json();
                if (json.success) this.data = json.data;
            } catch (e) {
                console.error('Ledger load error:', e);
            }
            this.loading = false;
        },

        prevPage() { if (this.page > 1) { this.page--; this.loadLedger(); } },
        nextPage() { this.page++; this.loadLedger(); },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
