<?php declare(strict_types=1);

/**
 * app/admin/accounting/bank-reconciliation/index.php
 *
 * Bank reconciliation workspace — select account, enter statement balance,
 * check off cleared transactions one by one, running difference shown.
 * Must reach $0.00 difference to close. Locked once completed.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @session S033
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('bank_accounts', 'view');

$bankAccounts = db_select(
    "SELECT id, name, currency FROM acc_bank_accounts WHERE is_active = 1 ORDER BY name",
    []
);
$expenseAccounts = db_select(
    "SELECT id, code, name FROM acc_accounts WHERE is_active = 1 AND is_header = 0 AND account_type IN ('operating_expense','other_expense') ORDER BY code",
    []
);

$canCreate = can('bank_accounts', 'create');
$canEdit   = can('bank_accounts', 'edit');

$pageTitle = 'Bank Reconciliation';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Bank Reconciliation</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Bank Reconciliation</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="reconPage()" x-init="loadHistory()">

    <!-- ═══ START NEW OR VIEW EXISTING ═══ -->
    <template x-if="!activeRecon">
        <div>
            <!-- Start New Reconciliation -->
            <?php if ($canCreate): ?>
            <div class="card" style="padding:20px;margin-bottom:20px;">
                <h3 class="h5" style="margin-bottom:12px;">Start New Reconciliation</h3>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:end;">
                    <div>
                        <label class="form-label">Bank Account</label>
                        <select class="form-select" x-model="newRecon.bank_account_id">
                            <option value="">Select...</option>
                            <?php foreach ($bankAccounts as $ba): ?>
                            <option value="<?= $ba['id'] ?>"><?= e($ba['name']) ?> (<?= e($ba['currency']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Statement Date</label>
                        <input type="date" class="form-input" x-model="newRecon.statement_date">
                    </div>
                    <div>
                        <label class="form-label">Statement Ending Balance</label>
                        <input type="text" class="form-input font-mono" x-model="newRecon.statement_ending_balance" placeholder="0.00">
                    </div>
                    <button class="btn btn-primary btn-sm" @click="startRecon()" :disabled="saving">Start</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Reconciliation History -->
            <div class="card" style="overflow:hidden;">
                <div style="padding:14px 16px;border-bottom:1px solid var(--border-default);">
                    <h3 class="h5" style="margin:0;">Reconciliation History</h3>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Period</th>
                            <th>Statement Date</th>
                            <th class="text-right">Statement Balance</th>
                            <th class="text-right">Difference</th>
                            <th>Status</th>
                            <th>Completed By</th>
                            <th style="width:80px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in history" :key="r.id">
                            <tr>
                                <td x-text="r.bank_account_name || 'Account #' + r.bank_account_id"></td>
                                <td x-text="r.period_name || '—'"></td>
                                <td class="font-mono" x-text="r.statement_date"></td>
                                <td class="text-right font-mono" x-text="fmtAmt(r.statement_ending_balance)"></td>
                                <td class="text-right font-mono" :style="r.difference !== '0.00' ? 'color:var(--color-danger)' : 'color:var(--color-success)'" x-text="fmtAmt(r.difference)"></td>
                                <td>
                                    <span class="badge" :class="{'badge-warning':r.status==='in_progress','badge-success':r.status==='completed','badge-neutral':r.status==='locked'}" x-text="r.status.replace('_',' ')"></span>
                                </td>
                                <td x-text="r.completed_by_name || '—'"></td>
                                <td>
                                    <button class="btn btn-ghost btn-xs" @click="openRecon(r.id)">
                                        <span x-text="r.status === 'in_progress' ? 'Continue' : 'View'"></span>
                                    </button>
                                </td>
                            </tr>
                        </template>
                        <template x-if="history.length === 0">
                            <tr><td colspan="8" class="text-center" style="padding:30px;color:var(--text-secondary);">No reconciliations yet.</td></tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </template>

    <!-- ═══ ACTIVE RECONCILIATION WORKSPACE ═══ -->
    <template x-if="activeRecon">
        <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <button class="btn btn-ghost btn-sm" @click="activeRecon=null;loadHistory()">← Back to History</button>
                <div style="display:flex;gap:8px;">
                    <template x-if="activeRecon.status === 'in_progress'">
                        <button class="btn btn-success btn-sm" @click="completeRecon()" :disabled="!reconSummary.is_balanced || saving">
                            Complete Reconciliation
                        </button>
                    </template>
                </div>
            </div>

            <!-- Summary Cards -->
            <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px;">
                <div class="card" style="padding:12px;">
                    <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Book Balance</div>
                    <div class="font-mono" style="font-size:1rem;font-weight:700;" x-text="fmtAmt(reconSummary.book_balance)"></div>
                </div>
                <div class="card" style="padding:12px;">
                    <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Statement Balance</div>
                    <div class="font-mono" style="font-size:1rem;font-weight:700;" x-text="fmtAmt(activeRecon.statement_ending_balance)"></div>
                </div>
                <div class="card" style="padding:12px;">
                    <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Cleared Deposits</div>
                    <div class="font-mono" style="font-size:1rem;font-weight:700;color:var(--color-success);" x-text="fmtAmt(reconSummary.cleared_deposits)"></div>
                </div>
                <div class="card" style="padding:12px;">
                    <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Cleared Withdrawals</div>
                    <div class="font-mono" style="font-size:1rem;font-weight:700;color:var(--color-danger);" x-text="fmtAmt(reconSummary.cleared_withdrawals)"></div>
                </div>
                <div class="card" style="padding:12px;" :style="reconSummary.is_balanced ? 'border:2px solid var(--color-success);' : 'border:2px solid var(--color-danger);'">
                    <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;letter-spacing:0.05em;" :style="reconSummary.is_balanced ? 'color:var(--color-success)' : 'color:var(--color-danger)'">Difference</div>
                    <div class="font-mono" style="font-size:1.25rem;font-weight:700;" :style="reconSummary.is_balanced ? 'color:var(--color-success)' : 'color:var(--color-danger)'" x-text="fmtAmt(reconSummary.difference)"></div>
                </div>
            </div>

            <!-- Transaction Checklist -->
            <div class="card" style="overflow:hidden;">
                <div style="padding:14px 16px;border-bottom:1px solid var(--border-default);display:flex;justify-content:space-between;align-items:center;">
                    <h3 class="h5" style="margin:0;">Transactions</h3>
                    <div style="font-size:0.8125rem;color:var(--text-secondary);">
                        <span x-text="reconTransactions.filter(t=>t.is_cleared).length"></span> / <span x-text="reconTransactions.length"></span> cleared
                    </div>
                </div>
                <table class="table" style="font-size:0.875rem;">
                    <thead>
                        <tr>
                            <th style="width:40px;">Clear</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Type</th>
                            <th class="text-right">Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="t in reconTransactions" :key="t.id">
                            <tr :style="t.is_cleared ? 'background:var(--bg-selected);' : ''">
                                <td>
                                    <input type="checkbox" :checked="t.is_cleared == 1"
                                        @change="toggleCleared(t.id, $event.target.checked ? 1 : 0)"
                                        :disabled="activeRecon.status !== 'in_progress'">
                                </td>
                                <td class="font-mono" x-text="t.transaction_date"></td>
                                <td x-text="t.description" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></td>
                                <td><span class="badge badge-neutral" style="text-transform:capitalize;font-size:0.6875rem;" x-text="(t.transaction_type||'').replace('_',' ')"></span></td>
                                <td class="text-right font-mono" :style="parseFloat(t.amount) < 0 ? 'color:var(--color-danger)' : 'color:var(--color-success)'" x-text="fmtAmt(t.amount)"></td>
                                <td>
                                    <span class="badge" :class="{'badge-success':t.status==='matched','badge-warning':t.status==='unmatched'}" style="font-size:0.6875rem;" x-text="t.status"></span>
                                </td>
                            </tr>
                        </template>
                        <template x-if="reconTransactions.length === 0">
                            <tr><td colspan="6" class="text-center" style="padding:30px;color:var(--text-secondary);">No transactions to reconcile.</td></tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Outstanding Items Report -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;">
                <div class="card" style="padding:16px;">
                    <h4 class="h6" style="margin-bottom:8px;">Outstanding Deposits (in transit)</h4>
                    <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-success);" x-text="fmtAmt(reconSummary.outstanding_deposits)"></div>
                </div>
                <div class="card" style="padding:16px;">
                    <h4 class="h6" style="margin-bottom:8px;">Outstanding Checks</h4>
                    <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-danger);" x-text="fmtAmt(reconSummary.outstanding_checks)"></div>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function reconPage() {
    return {
        history: [],
        activeRecon: null,
        reconTransactions: [],
        reconSummary: { book_balance:'0.00', statement_ending_balance:'0.00', outstanding_deposits:'0.00', outstanding_checks:'0.00', adjusted_book_balance:'0.00', difference:'0.00', cleared_deposits:'0.00', cleared_withdrawals:'0.00', is_balanced:false },
        saving: false,
        historyAccountFilter: '',
        newRecon: { bank_account_id: '', statement_date: '', statement_ending_balance: '' },

        _csrfHeaders() {
            return {
                'X-CSRF-Token': document.querySelector('meta[name=csrf-token]')?.content || '',
                'X-Requested-With': 'XMLHttpRequest',
            };
        },

        fmtAmt(v) {
            const n = parseFloat(v || 0);
            return (n < 0 ? '-' : '') + '$' + Math.abs(n).toLocaleString('en-CA', {minimumFractionDigits:2, maximumFractionDigits:2});
        },

        async loadHistory() {
            // Load all reconciliations across all accounts
            const accounts = <?= json_encode(array_column($bankAccounts, 'id')) ?>;
            this.history = [];
            for (const accId of accounts) {
                try {
                    const r = await fetch(`<?= base_url('api/v1/accounting/bank-reconciliations') ?>?bank_account_id=${accId}&per_page=50`);
                    const j = await r.json();
                    const items = j.data?.items || j.data || [];
                    // Add account name
                    const acc = <?= json_encode($bankAccounts) ?>.find(a => a.id == accId);
                    items.forEach(i => i.bank_account_name = acc?.name || '');
                    this.history.push(...items);
                } catch (e) { console.error(e); }
            }
            this.history.sort((a,b) => b.statement_date.localeCompare(a.statement_date));
        },

        async startRecon() {
            if (!this.newRecon.bank_account_id || !this.newRecon.statement_date || !this.newRecon.statement_ending_balance) {
                window.FleetForge?.toast?.('error', 'Fill in all required fields.');
                return;
            }
            this.saving = true;
            const fd = new FormData();
            for (const [k,v] of Object.entries(this.newRecon)) fd.append(k, v);
            try {
                const r = await fetch(`<?= base_url('api/v1/accounting/bank-reconciliations/create') ?>`, {method:'POST', body:fd, headers:this._csrfHeaders()});
                const j = await r.json();
                if (j.success) {
                    this.openRecon(j.data.id);
                } else {
                    window.FleetForge?.toast?.('error', j.error?.message || 'Failed');
                }
            } catch (e) { window.FleetForge?.toast?.('error', 'Network error'); }
            this.saving = false;
        },

        async openRecon(id) {
            try {
                const r = await fetch(`<?= base_url('api/v1/accounting/bank-reconciliations/show') ?>?id=${id}`);
                const j = await r.json();
                if (j.success) {
                    this.activeRecon = j.data;
                    this.reconTransactions = j.data.transactions || [];
                    this.reconSummary = j.data.summary || this.reconSummary;
                } else {
                    window.FleetForge?.toast?.('error', j.error?.message || 'Failed to load');
                }
            } catch (e) { window.FleetForge?.toast?.('error', 'Network error'); }
        },

        async toggleCleared(txnId, isCleared) {
            const fd = new FormData();
            fd.append('reconciliation_id', this.activeRecon.id);
            fd.append('transaction_id', txnId);
            fd.append('is_cleared', isCleared);
            try {
                const r = await fetch(`<?= base_url('api/v1/accounting/bank-reconciliations/toggle-cleared') ?>`, {method:'POST', body:fd, headers:this._csrfHeaders()});
                const j = await r.json();
                if (j.success) {
                    // Update local state
                    const txn = this.reconTransactions.find(t => t.id == txnId);
                    if (txn) txn.is_cleared = isCleared;
                    this.reconSummary = j.data.summary;
                } else {
                    window.FleetForge?.toast?.('error', j.error?.message || 'Failed');
                    // Revert checkbox
                    const txn = this.reconTransactions.find(t => t.id == txnId);
                    if (txn) txn.is_cleared = isCleared ? 0 : 1;
                }
            } catch (e) { window.FleetForge?.toast?.('error', 'Network error'); }
        },

        async completeRecon() {
            if (!confirm('Complete this reconciliation? Once completed, it cannot be modified.')) return;
            this.saving = true;
            const fd = new FormData();
            fd.append('id', this.activeRecon.id);
            try {
                const r = await fetch(`<?= base_url('api/v1/accounting/bank-reconciliations/complete') ?>`, {method:'POST', body:fd, headers:this._csrfHeaders()});
                const j = await r.json();
                if (j.success) {
                    window.FleetForge?.toast?.('success', 'Reconciliation completed and locked.');
                    this.activeRecon = null;
                    this.loadHistory();
                } else {
                    window.FleetForge?.toast?.('error', j.error?.message || 'Failed');
                }
            } catch (e) { window.FleetForge?.toast?.('error', 'Network error'); }
            this.saving = false;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
