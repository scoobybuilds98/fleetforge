<?php declare(strict_types=1);

/**
 * app/admin/accounting/bank-accounts/index.php
 *
 * Bank accounts management — list, create, edit, deactivate bank accounts.
 * Includes bank transaction list, CSV import wizard, manual transaction entry,
 * transfer modal, and NSF processing modal.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @session S033
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('bank_accounts', 'view');

$glAccounts = db_select(
    "SELECT id, code, name, account_type, is_bank_account FROM acc_accounts WHERE is_active = 1 AND is_header = 0 ORDER BY code",
    []
);
$expenseAccounts = db_select(
    "SELECT id, code, name FROM acc_accounts WHERE is_active = 1 AND is_header = 0 AND account_type IN ('operating_expense','other_expense','cost_of_revenue') ORDER BY code",
    []
);

$canCreate = can('bank_accounts', 'create');
$canEdit   = can('bank_accounts', 'edit');

$pageTitle = 'Bank Accounts';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Bank Accounts</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Bank Accounts</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="bankAccountsPage()" x-init="load()">

    <!-- KPI Row -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;">
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Active Accounts</div>
            <div style="font-size:1.125rem;font-weight:700;color:var(--text-primary);" x-text="accounts.filter(a=>a.is_active==1).length"></div>
        </div>
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Total CAD Balance</div>
            <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-accent);" x-text="fmtAmt(cadBalance)"></div>
        </div>
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Total USD Balance</div>
            <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-info);" x-text="'US' + fmtAmt(usdBalance)"></div>
        </div>
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Unmatched Txns</div>
            <div style="font-size:1.125rem;font-weight:700;" :style="unmatchedCount > 0 ? 'color:var(--color-warning)' : 'color:var(--color-success)'" x-text="unmatchedCount"></div>
        </div>
    </div>

    <!-- Toolbar -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:12px;flex-wrap:wrap;">
        <div></div>
        <div style="display:flex;gap:8px;">
            <?php if ($canCreate): ?>
            <button class="btn btn-secondary btn-sm" @click="showImportModal=true">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="vertical-align:-3px;margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                Import CSV
            </button>
            <button class="btn btn-secondary btn-sm" @click="openTransferModal()">Transfer</button>
            <button class="btn btn-primary btn-sm" @click="openAccountModal()">+ Add Account</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Accounts Table -->
    <div class="card" style="overflow:hidden;">
        <div class="table-responsive">
<table class="table">
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Institution</th>
                    <th>Type</th>
                    <th>Currency</th>
                    <th>GL Account</th>
                    <th class="text-right">Balance</th>
                    <th>Status</th>
                    <th style="width:100px;"></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="a in accounts" :key="a.id">
                    <tr :class="{'opacity-50': !a.is_active}" style="cursor:pointer;" @click="selectAccount(a)">
                        <td>
                            <div style="font-weight:600;" x-text="a.name"></div>
                            <div style="font-size:0.75rem;color:var(--text-secondary);">
                                <span x-show="a.account_number_last4">••••<span x-text="a.account_number_last4"></span></span>
                                <span x-show="a.is_default" class="badge badge-info" style="font-size:0.625rem;margin-left:4px;">Default</span>
                            </div>
                        </td>
                        <td x-text="a.institution || '—'"></td>
                        <td style="text-transform:capitalize;" x-text="(a.account_type||'').replace('_',' ')"></td>
                        <td><span class="badge" :class="a.currency==='USD'?'badge-info':'badge-neutral'" x-text="a.currency"></span></td>
                        <td style="font-size:0.8125rem;" x-text="(a.gl_account_code||'') + ' ' + (a.gl_account_name||'')"></td>
                        <td class="text-right font-mono" x-text="fmtAmt(a.gl_balance || '0.00')"></td>
                        <td>
                            <span class="badge" :class="a.is_active ? 'badge-success' : 'badge-neutral'" x-text="a.is_active ? 'Active' : 'Inactive'"></span>
                        </td>
                        <td @click.stop>
                            <?php if ($canEdit): ?>
                            <button class="btn btn-ghost btn-xs" @click="openAccountModal(a)" title="Edit">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                </template>
                <template x-if="accounts.length === 0 && !loading">
                    <tr><td colspan="8" class="text-center" style="padding:40px;color:var(--text-secondary);">No bank accounts yet. Click "+ Add Account" to create one.</td></tr>
                </template>
            </tbody>
        </table>
</div>
    </div>

    <!-- Selected Account Detail: Transactions -->
    <template x-if="selectedAccount">
        <div style="margin-top:24px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h2 class="h5" x-text="selectedAccount.name + ' — Transactions'"></h2>
                <div style="display:flex;gap:8px;">
                    <select class="form-select form-select-sm" x-model="txnFilter.status" @change="loadTransactions()">
                        <option value="">All Status</option>
                        <option value="unmatched">Unmatched</option>
                        <option value="matched">Matched</option>
                        <option value="excluded">Excluded</option>
                    </select>
                    <?php if ($canCreate): ?>
                    <button class="btn btn-secondary btn-xs" @click="showManualTxnModal=true">+ Manual Entry</button>
                    <button class="btn btn-secondary btn-xs" @click="showNsfModal=true">NSF</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card" style="overflow:hidden;">
                <div class="table-responsive">
<table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Reference</th>
                            <th>Type</th>
                            <th class="text-right">Amount</th>
                            <th>Status</th>
                            <th>Match</th>
                            <th style="width:80px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="t in transactions" :key="t.id">
                            <tr>
                                <td class="font-mono" style="font-size:0.8125rem;" x-text="t.transaction_date"></td>
                                <td x-text="t.description" style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></td>
                                <td x-text="t.reference || '—'" style="font-size:0.8125rem;"></td>
                                <td><span class="badge badge-neutral" style="text-transform:capitalize;font-size:0.6875rem;" x-text="(t.transaction_type||'').replace('_',' ')"></span></td>
                                <td class="text-right font-mono" :style="parseFloat(t.amount) < 0 ? 'color:var(--color-danger)' : 'color:var(--color-success)'" x-text="fmtAmt(t.amount)"></td>
                                <td>
                                    <span class="badge" :class="{'badge-success':t.status==='matched','badge-warning':t.status==='unmatched','badge-neutral':t.status==='excluded'}" x-text="t.status"></span>
                                </td>
                                <td style="font-size:0.75rem;" x-text="t.matched_type ? t.matched_type + ' #' + t.matched_id : '—'"></td>
                                <td>
                                    <?php if ($canEdit): ?>
                                    <template x-if="t.status==='unmatched'">
                                        <button class="btn btn-ghost btn-xs" @click="excludeTxn(t.id, 1)" title="Exclude">✕</button>
                                    </template>
                                    <template x-if="t.status==='excluded'">
                                        <button class="btn btn-ghost btn-xs" @click="excludeTxn(t.id, 0)" title="Un-exclude">↩</button>
                                    </template>
                                    <template x-if="t.status==='matched' && !t.is_cleared">
                                        <button class="btn btn-ghost btn-xs" @click="unmatchTxn(t.id)" title="Unmatch">⊘</button>
                                    </template>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                        <template x-if="transactions.length === 0 && !txnLoading">
                            <tr><td colspan="8" class="text-center" style="padding:30px;color:var(--text-secondary);">No transactions found.</td></tr>
                        </template>
                    </tbody>
                </table>
</div>
            </div>
            <!-- Pagination -->
            <div x-show="txnTotalPages > 1" style="display:flex;justify-content:center;gap:6px;margin-top:12px;">
                <button class="btn btn-ghost btn-xs" :disabled="txnPage <= 1" @click="txnPage--;loadTransactions()">← Prev</button>
                <span style="font-size:0.8125rem;padding:4px 8px;" x-text="'Page ' + txnPage + ' of ' + txnTotalPages"></span>
                <button class="btn btn-ghost btn-xs" :disabled="txnPage >= txnTotalPages" @click="txnPage++;loadTransactions()">Next →</button>
            </div>
        </div>
    </template>

    <!-- ═══ ACCOUNT MODAL ═══ -->
    <div x-show="showAccountModal" x-cloak class="modal-backdrop" @click.self="showAccountModal=false" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;">
        <div class="card" style="width:560px;max-height:90vh;overflow-y:auto;padding:24px;" @click.stop>
            <h3 class="h5" style="margin-bottom:16px;" x-text="editingAccount ? 'Edit Bank Account' : 'Add Bank Account'"></h3>
            <div class="form-error-banner" x-show="accountFormError" x-cloak x-text="accountFormError" style="margin-bottom:12px;"></div>
            <div style="display:grid;gap:12px;">
                <div>
                    <label class="form-label">Account Name *</label>
                    <input type="text" class="form-input" :class="accountErrors.name ? 'is-invalid' : ''" x-model="accountForm.name" @input="accountErrors.name = ''" maxlength="255">
                    <div class="field-error" x-show="accountErrors.name" x-cloak x-text="accountErrors.name"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label class="form-label">Institution</label>
                        <input type="text" class="form-input" :class="accountErrors.institution ? 'is-invalid' : ''" x-model="accountForm.institution" @input="accountErrors.institution = ''" maxlength="255">
                        <div class="field-error" x-show="accountErrors.institution" x-cloak x-text="accountErrors.institution"></div>
                    </div>
                    <div>
                        <label class="form-label">Account Type *</label>
                        <select class="form-select" :class="accountErrors.account_type ? 'is-invalid' : ''" x-model="accountForm.account_type" @change="accountErrors.account_type = ''">
                            <option value="checking">Checking</option>
                            <option value="savings">Savings</option>
                            <option value="line_of_credit">Line of Credit</option>
                            <option value="credit_card">Credit Card</option>
                        </select>
                        <div class="field-error" x-show="accountErrors.account_type" x-cloak x-text="accountErrors.account_type"></div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label class="form-label">Last 4 Digits</label>
                        <input type="text" class="form-input" :class="accountErrors.account_number_last4 ? 'is-invalid' : ''" x-model="accountForm.account_number_last4" @input="accountErrors.account_number_last4 = ''" maxlength="4" placeholder="0000">
                        <div class="field-error" x-show="accountErrors.account_number_last4" x-cloak x-text="accountErrors.account_number_last4"></div>
                    </div>
                    <div>
                        <label class="form-label">Routing Number</label>
                        <input type="text" class="form-input" :class="accountErrors.routing_number ? 'is-invalid' : ''" x-model="accountForm.routing_number" @input="accountErrors.routing_number = ''" maxlength="20">
                        <div class="field-error" x-show="accountErrors.routing_number" x-cloak x-text="accountErrors.routing_number"></div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label class="form-label">Currency *</label>
                        <select class="form-select" :class="accountErrors.currency ? 'is-invalid' : ''" x-model="accountForm.currency" @change="accountErrors.currency = ''">
                            <option value="CAD">CAD</option>
                            <option value="USD">USD</option>
                        </select>
                        <div class="field-error" x-show="accountErrors.currency" x-cloak x-text="accountErrors.currency"></div>
                    </div>
                    <div>
                        <label class="form-label">GL Cash Account *</label>
                        <select class="form-select" :class="accountErrors.gl_account_id ? 'is-invalid' : ''" x-model="accountForm.gl_account_id" @change="accountErrors.gl_account_id = ''">
                            <option value="">Select...</option>
                            <?php foreach ($glAccounts as $ga): ?>
                            <option value="<?= $ga['id'] ?>"><?= e($ga['code']) ?> — <?= e($ga['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-error" x-show="accountErrors.gl_account_id" x-cloak x-text="accountErrors.gl_account_id"></div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label class="form-label">Opening Balance</label>
                        <input type="text" class="form-input font-mono" :class="accountErrors.opening_balance ? 'is-invalid' : ''" x-model="accountForm.opening_balance" @input="accountErrors.opening_balance = ''" placeholder="0.00">
                        <div class="field-error" x-show="accountErrors.opening_balance" x-cloak x-text="accountErrors.opening_balance"></div>
                    </div>
                    <div>
                        <label class="form-label">Opening Date</label>
                        <input type="date" class="form-input" :class="accountErrors.opening_balance_date ? 'is-invalid' : ''" x-model="accountForm.opening_balance_date" @change="accountErrors.opening_balance_date = ''">
                        <div class="field-error" x-show="accountErrors.opening_balance_date" x-cloak x-text="accountErrors.opening_balance_date"></div>
                    </div>
                </div>
                <div>
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" x-model="accountForm.is_default" :true-value="1" :false-value="0">
                        <span class="form-label" style="margin:0;">Default account for this currency</span>
                    </label>
                </div>
                <div>
                    <label class="form-label">Notes</label>
                    <textarea class="form-input" x-model="accountForm.notes" rows="2"></textarea>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
                <button class="btn btn-secondary btn-sm" @click="showAccountModal=false">Cancel</button>
                <button class="btn btn-primary btn-sm" @click="saveAccount()" :disabled="saving" x-text="saving ? 'Saving...' : (editingAccount ? 'Update' : 'Create')"></button>
            </div>
        </div>
    </div>

    <!-- ═══ CSV IMPORT MODAL ═══ -->
    <div x-show="showImportModal" x-cloak class="modal-backdrop" @click.self="showImportModal=false" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;">
        <div class="card" style="width:800px;max-height:90vh;overflow-y:auto;padding:24px;" @click.stop>
            <h3 class="h5" style="margin-bottom:16px;">Import Bank CSV</h3>

            <!-- Step 1: Upload -->
            <template x-if="importStep === 1">
                <div style="display:grid;gap:12px;">
                    <div class="form-error-banner" x-show="importFormError" x-cloak x-text="importFormError"></div>
                    <div>
                        <label class="form-label">Bank Account *</label>
                        <select class="form-select" :class="importErrors.bank_account_id ? 'is-invalid' : ''" x-model="importForm.bank_account_id" @change="importErrors.bank_account_id = ''">
                            <option value="">Select account...</option>
                            <template x-for="a in accounts.filter(x=>x.is_active)" :key="a.id">
                                <option :value="a.id" x-text="a.name + ' (' + a.currency + ')'"></option>
                            </template>
                        </select>
                        <div class="field-error" x-show="importErrors.bank_account_id" x-cloak x-text="importErrors.bank_account_id"></div>
                    </div>
                    <div>
                        <label class="form-label">CSV File *</label>
                        <input type="file" class="form-input" :class="importErrors.csv_file ? 'is-invalid' : ''" accept=".csv" @change="importForm.file = $event.target.files[0]; importErrors.csv_file = ''">
                        <div class="field-error" x-show="importErrors.csv_file" x-cloak x-text="importErrors.csv_file"></div>
                    </div>
                    <div>
                        <label class="form-label">Bank Format (auto-detected if blank)</label>
                        <select class="form-select" :class="importErrors.format ? 'is-invalid' : ''" x-model="importForm.format" @change="importErrors.format = ''">
                            <option value="">Auto-detect</option>
                            <option value="rbc">RBC Royal Bank</option>
                            <option value="td">TD Canada Trust</option>
                            <option value="bmo">BMO Bank of Montreal</option>
                            <option value="scotiabank">Scotiabank</option>
                            <option value="cibc">CIBC</option>
                        </select>
                        <div class="field-error" x-show="importErrors.format" x-cloak x-text="importErrors.format"></div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:8px;">
                        <button class="btn btn-secondary btn-sm" @click="showImportModal=false">Cancel</button>
                        <button class="btn btn-primary btn-sm" @click="uploadCsv()" :disabled="importing">Upload & Preview</button>
                    </div>
                </div>
            </template>

            <!-- Step 2: Preview -->
            <template x-if="importStep === 2">
                <div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <div style="font-size:0.875rem;">
                            Detected format: <strong x-text="importPreview.format_name || importPreview.format"></strong>
                        </div>
                        <div style="display:flex;gap:12px;font-size:0.8125rem;">
                            <span>Total: <strong x-text="importPreview.summary?.total_rows || 0"></strong></span>
                            <span style="color:var(--color-success);">Matched: <strong x-text="importPreview.summary?.auto_matched || 0"></strong></span>
                            <span style="color:var(--color-warning);">Unmatched: <strong x-text="importPreview.summary?.unmatched || 0"></strong></span>
                            <span style="color:var(--text-secondary);">Duplicates: <strong x-text="importPreview.summary?.duplicates || 0"></strong></span>
                        </div>
                    </div>
                    <div style="max-height:400px;overflow-y:auto;border:1px solid var(--border-default);border-radius:6px;">
                        <div class="table-responsive">
<table class="table" style="font-size:0.8125rem;">
                            <thead>
                                <tr>
                                    <th style="width:30px;"><input type="checkbox" @change="toggleAllImport($event.target.checked)" checked></th>
                                    <th>Row</th>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th class="text-right">Amount</th>
                                    <th>Match</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(t, i) in importPreview.transactions || []" :key="i">
                                    <tr :style="t.is_duplicate ? 'opacity:0.4;text-decoration:line-through;' : ''">
                                        <td><input type="checkbox" :value="t.row_number" x-model="importSelected" :disabled="t.is_duplicate"></td>
                                        <td x-text="t.row_number"></td>
                                        <td class="font-mono" x-text="t.date"></td>
                                        <td x-text="t.description" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></td>
                                        <td class="text-right font-mono" :style="parseFloat(t.amount) < 0 ? 'color:var(--color-danger)' : 'color:var(--color-success)'" x-text="fmtAmt(t.amount)"></td>
                                        <td style="font-size:0.75rem;">
                                            <template x-if="t.match">
                                                <span>
                                                    <span class="badge" :class="{'badge-success':t.match.confidence==='high','badge-warning':t.match.confidence==='medium','badge-neutral':t.match.confidence==='low'}" x-text="t.match.confidence" style="font-size:0.625rem;"></span>
                                                    <span x-text="t.match.label" style="margin-left:4px;"></span>
                                                </span>
                                            </template>
                                            <template x-if="!t.match && !t.is_duplicate">
                                                <span style="color:var(--text-muted);">No match</span>
                                            </template>
                                        </td>
                                        <td>
                                            <template x-if="t.is_duplicate"><span class="badge badge-neutral">Duplicate</span></template>
                                            <template x-if="!t.is_duplicate && t.match"><span class="badge badge-success">Matched</span></template>
                                            <template x-if="!t.is_duplicate && !t.match"><span class="badge badge-warning">New</span></template>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
</div>
                    </div>
                    <template x-if="importPreview.errors && importPreview.errors.length > 0">
                        <div style="margin-top:8px;padding:8px 12px;background:var(--badge-warning-bg);color:var(--badge-warning-text);border-radius:6px;font-size:0.8125rem;">
                            <strong>Warnings:</strong>
                            <template x-for="e in importPreview.errors" :key="e">
                                <div x-text="e"></div>
                            </template>
                        </div>
                    </template>
                    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
                        <button class="btn btn-secondary btn-sm" @click="importStep=1">← Back</button>
                        <button class="btn btn-primary btn-sm" @click="confirmImport()" :disabled="importing" x-text="importing ? 'Importing...' : 'Confirm Import (' + importSelected.length + ' rows)'"></button>
                    </div>
                </div>
            </template>

            <!-- Step 3: Done -->
            <template x-if="importStep === 3">
                <div style="text-align:center;padding:24px;">
                    <div style="font-size:2rem;margin-bottom:8px;">✓</div>
                    <div style="font-size:1rem;font-weight:600;">Import Complete</div>
                    <div style="font-size:0.875rem;color:var(--text-secondary);margin-top:8px;">
                        <span x-text="importResult.imported"></span> transactions imported,
                        <span x-text="importResult.skipped"></span> skipped.
                    </div>
                    <button class="btn btn-primary btn-sm" style="margin-top:16px;" @click="showImportModal=false;importStep=1;loadTransactions();">Done</button>
                </div>
            </template>
        </div>
    </div>

    <!-- ═══ MANUAL TRANSACTION MODAL ═══ -->
    <div x-show="showManualTxnModal" x-cloak class="modal-backdrop" @click.self="showManualTxnModal=false" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;">
        <div class="card" style="width:480px;padding:24px;" @click.stop>
            <h3 class="h5" style="margin-bottom:16px;">Manual Bank Transaction</h3>
            <div class="form-error-banner" x-show="manualTxnFormError" x-cloak x-text="manualTxnFormError" style="margin-bottom:12px;"></div>
            <div style="display:grid;gap:12px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label class="form-label">Date *</label>
                        <input type="date" class="form-input" :class="manualTxnErrors.transaction_date ? 'is-invalid' : ''" x-model="manualTxnForm.transaction_date" @change="manualTxnErrors.transaction_date = ''">
                        <div class="field-error" x-show="manualTxnErrors.transaction_date" x-cloak x-text="manualTxnErrors.transaction_date"></div>
                    </div>
                    <div>
                        <label class="form-label">Type *</label>
                        <select class="form-select" :class="manualTxnErrors.transaction_type ? 'is-invalid' : ''" x-model="manualTxnForm.transaction_type" @change="manualTxnErrors.transaction_type = ''">
                            <option value="deposit">Deposit</option>
                            <option value="withdrawal">Withdrawal</option>
                            <option value="bank_charge">Bank Charge</option>
                            <option value="interest">Interest</option>
                            <option value="other">Other</option>
                        </select>
                        <div class="field-error" x-show="manualTxnErrors.transaction_type" x-cloak x-text="manualTxnErrors.transaction_type"></div>
                    </div>
                </div>
                <div>
                    <label class="form-label">Description *</label>
                    <input type="text" class="form-input" :class="manualTxnErrors.description ? 'is-invalid' : ''" x-model="manualTxnForm.description" @input="manualTxnErrors.description = ''" maxlength="500">
                    <div class="field-error" x-show="manualTxnErrors.description" x-cloak x-text="manualTxnErrors.description"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label class="form-label">Amount *</label>
                        <input type="text" class="form-input font-mono" :class="manualTxnErrors.amount ? 'is-invalid' : ''" x-model="manualTxnForm.amount" @input="manualTxnErrors.amount = ''" placeholder="0.00">
                        <div class="field-error" x-show="manualTxnErrors.amount" x-cloak x-text="manualTxnErrors.amount"></div>
                    </div>
                    <div>
                        <label class="form-label">Reference</label>
                        <input type="text" class="form-input" x-model="manualTxnForm.reference">
                    </div>
                </div>
                <div>
                    <label class="form-label">GL Account (auto-creates JE if set)</label>
                    <select class="form-select" x-model="manualTxnForm.expense_account_id">
                        <option value="">None — manual match later</option>
                        <?php foreach ($expenseAccounts as $ea): ?>
                        <option value="<?= $ea['id'] ?>"><?= e($ea['code']) ?> — <?= e($ea['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
                <button class="btn btn-secondary btn-sm" @click="showManualTxnModal=false">Cancel</button>
                <button class="btn btn-primary btn-sm" @click="saveManualTxn()" :disabled="saving">Create</button>
            </div>
        </div>
    </div>

    <!-- ═══ TRANSFER MODAL ═══ -->
    <div x-show="showTransferModal" x-cloak class="modal-backdrop" @click.self="showTransferModal=false" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;">
        <div class="card" style="width:480px;padding:24px;" @click.stop>
            <h3 class="h5" style="margin-bottom:16px;">Transfer Between Accounts</h3>
            <div class="form-error-banner" x-show="transferFormError" x-cloak x-text="transferFormError" style="margin-bottom:12px;"></div>
            <div style="display:grid;gap:12px;">
                <div>
                    <label class="form-label">From Account *</label>
                    <select class="form-select" :class="transferErrors.from_account_id ? 'is-invalid' : ''" x-model="transferForm.from_account_id" @change="transferErrors.from_account_id = ''">
                        <option value="">Select...</option>
                        <template x-for="a in accounts.filter(x=>x.is_active)" :key="a.id">
                            <option :value="a.id" x-text="a.name + ' (' + a.currency + ')'"></option>
                        </template>
                    </select>
                    <div class="field-error" x-show="transferErrors.from_account_id" x-cloak x-text="transferErrors.from_account_id"></div>
                </div>
                <div>
                    <label class="form-label">To Account *</label>
                    <select class="form-select" :class="transferErrors.to_account_id ? 'is-invalid' : ''" x-model="transferForm.to_account_id" @change="transferErrors.to_account_id = ''">
                        <option value="">Select...</option>
                        <template x-for="a in accounts.filter(x=>x.is_active)" :key="a.id">
                            <option :value="a.id" x-text="a.name + ' (' + a.currency + ')'"></option>
                        </template>
                    </select>
                    <div class="field-error" x-show="transferErrors.to_account_id" x-cloak x-text="transferErrors.to_account_id"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label class="form-label">Amount From *</label>
                        <input type="text" class="form-input font-mono" :class="transferErrors.from_amount ? 'is-invalid' : ''" x-model="transferForm.from_amount" @input="transferErrors.from_amount = ''" placeholder="0.00">
                        <div class="field-error" x-show="transferErrors.from_amount" x-cloak x-text="transferErrors.from_amount"></div>
                    </div>
                    <div>
                        <label class="form-label">Amount To</label>
                        <input type="text" class="form-input font-mono" :class="transferErrors.to_amount ? 'is-invalid' : ''" x-model="transferForm.to_amount" @input="transferErrors.to_amount = ''" placeholder="Same if same currency">
                        <div class="field-error" x-show="transferErrors.to_amount" x-cloak x-text="transferErrors.to_amount"></div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label class="form-label">Transfer Date *</label>
                        <input type="date" class="form-input" :class="transferErrors.transfer_date ? 'is-invalid' : ''" x-model="transferForm.transfer_date" @change="transferErrors.transfer_date = ''">
                        <div class="field-error" x-show="transferErrors.transfer_date" x-cloak x-text="transferErrors.transfer_date"></div>
                    </div>
                    <div>
                        <label class="form-label">Reference</label>
                        <input type="text" class="form-input" x-model="transferForm.reference">
                    </div>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
                <button class="btn btn-secondary btn-sm" @click="showTransferModal=false">Cancel</button>
                <button class="btn btn-primary btn-sm" @click="saveTransfer()" :disabled="saving">Transfer</button>
            </div>
        </div>
    </div>

    <!-- ═══ NSF MODAL ═══ -->
    <div x-show="showNsfModal" x-cloak class="modal-backdrop" @click.self="showNsfModal=false" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;">
        <div class="card" style="width:480px;padding:24px;" @click.stop>
            <h3 class="h5" style="margin-bottom:16px;color:var(--color-danger);">Process NSF (Returned Payment)</h3>
            <div style="padding:10px 14px;background:var(--badge-danger-bg);color:var(--badge-danger-text);border-radius:6px;font-size:0.8125rem;margin-bottom:16px;">
                This will reverse the original payment, reopen associated invoices, and create a bank charge entry. This action cannot be undone.
            </div>
            <div class="form-error-banner" x-show="nsfFormError" x-cloak x-text="nsfFormError" style="margin-bottom:12px;"></div>
            <div style="display:grid;gap:12px;">
                <div>
                    <label class="form-label">Payment ID *</label>
                    <input type="number" min="0" class="form-input" :class="nsfErrors.payment_id ? 'is-invalid' : ''" x-model="nsfForm.payment_id" @input="nsfErrors.payment_id = ''" placeholder="Enter payment ID">
                    <div class="field-error" x-show="nsfErrors.payment_id" x-cloak x-text="nsfErrors.payment_id"></div>
                </div>
                <div>
                    <label class="form-label">NSF Fee (charged to customer)</label>
                    <input type="text" class="form-input font-mono" :class="nsfErrors.nsf_fee ? 'is-invalid' : ''" x-model="nsfForm.nsf_fee" @input="nsfErrors.nsf_fee = ''" placeholder="0.00">
                    <div class="field-error" x-show="nsfErrors.nsf_fee" x-cloak x-text="nsfErrors.nsf_fee"></div>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
                <button class="btn btn-secondary btn-sm" @click="showNsfModal=false">Cancel</button>
                <button class="btn btn-danger btn-sm" @click="processNsf()" :disabled="saving">Confirm NSF</button>
            </div>
        </div>
    </div>
</div>

<script>
function bankAccountsPage() {
    return {
        accounts: [],
        selectedAccount: null,
        transactions: [],
        loading: false,
        txnLoading: false,
        saving: false,
        importing: false,
        cadBalance: '0.00',
        usdBalance: '0.00',
        unmatchedCount: 0,
        txnPage: 1,
        txnTotalPages: 1,
        txnFilter: { status: '' },

        // Modals
        showAccountModal: false,
        showImportModal: false,
        showManualTxnModal: false,
        showTransferModal: false,
        showNsfModal: false,
        editingAccount: null,

        // Forms
        accountForm: { name: '', institution: '', account_type: 'checking', account_number_last4: '', routing_number: '', currency: 'CAD', gl_account_id: '', opening_balance: '0.00', opening_balance_date: '', is_default: 0, notes: '' },
        importForm: { bank_account_id: '', file: null, format: '' },
        importStep: 1,
        importPreview: {},
        importSelected: [],
        importResult: {},
        manualTxnForm: { transaction_date: new Date().toISOString().slice(0,10), description: '', amount: '', transaction_type: 'bank_charge', reference: '', expense_account_id: '' },
        transferForm: { from_account_id: '', to_account_id: '', from_amount: '', to_amount: '', transfer_date: new Date().toISOString().slice(0,10), reference: '' },
        nsfForm: { payment_id: '', nsf_fee: '0.00' },

        // Per-form error state
        accountFormError: '',
        accountErrors: { name: '', institution: '', account_type: '', account_number_last4: '', routing_number: '', currency: '', gl_account_id: '', opening_balance: '', opening_balance_date: '' },
        importFormError: '',
        importErrors: { bank_account_id: '', csv_file: '', format: '' },
        manualTxnFormError: '',
        manualTxnErrors: { transaction_date: '', transaction_type: '', description: '', amount: '' },
        transferFormError: '',
        transferErrors: { from_account_id: '', to_account_id: '', from_amount: '', to_amount: '', transfer_date: '' },
        nsfFormError: '',
        nsfErrors: { payment_id: '', nsf_fee: '' },

        // -- Helper: extract message from API response --------------
        _extractError(r, fallback) {
            if (!r) return fallback || 'An unexpected error occurred.';
            if (r.error && r.error.message) return r.error.message;
            if (r.message) return r.message;
            return fallback || 'An unexpected error occurred.';
        },

        // -- Helper: paint server-side field errors ------------------
        _paintErrors(errorsObj, bannerKey, r, fallback) {
            const err = r && r.error ? r.error : r;
            const fields = (err && err.fields) || (r && r.fields) || {};
            for (const k in errorsObj) errorsObj[k] = '';
            let painted = false;
            for (const k in fields) {
                if (k === '_general') continue;
                if (k in errorsObj) {
                    errorsObj[k] = fields[k];
                    painted = true;
                }
            }
            if (fields._general) {
                this[bannerKey] = fields._general;
            } else if (!painted) {
                this[bannerKey] = this._extractError(r, fallback);
            } else {
                this[bannerKey] = this._extractError(r, 'Please correct the highlighted fields.');
            }
        },

        _clearErrors(errorsObj, bannerKey) {
            for (const k in errorsObj) errorsObj[k] = '';
            this[bannerKey] = '';
        },

        _csrfHeaders() {
            return {
                'X-CSRF-Token': document.querySelector('meta[name=csrf-token]')?.content || '',
                'X-Requested-With': 'XMLHttpRequest',
            };
        },

        _emptyAccountForm() {
            return { name: '', institution: '', account_type: 'checking', account_number_last4: '', routing_number: '', currency: 'CAD', gl_account_id: '', opening_balance: '0.00', opening_balance_date: '', is_default: 0, notes: '' };
        },

        fmtAmt(v) {
            const n = parseFloat(v || 0);
            return (n < 0 ? '-' : '') + '$' + Math.abs(n).toLocaleString('en-CA', {minimumFractionDigits:2, maximumFractionDigits:2});
        },

        async load() {
            this.loading = true;
            try {
                const r = await fetch(`<?= base_url('api/v1/accounting/bank-accounts') ?>?per_page=100`);
                const j = await r.json();
                this.accounts = j.data?.items || j.data || [];
                // Load balances for each account
                for (const a of this.accounts) {
                    const sr = await fetch(`<?= base_url('api/v1/accounting/bank-accounts/show') ?>?id=${a.id}`);
                    const sj = await sr.json();
                    if (sj.success) {
                        a.gl_balance = sj.data.gl_balance;
                        a.transaction_count = sj.data.transaction_count;
                    }
                }
                this.calcKpis();
            } catch (e) { console.error(e); }
            this.loading = false;
        },

        calcKpis() {
            this.cadBalance = '0.00';
            this.usdBalance = '0.00';
            for (const a of this.accounts) {
                if (!a.is_active) continue;
                const bal = parseFloat(a.gl_balance || 0);
                if (a.currency === 'CAD') this.cadBalance = (parseFloat(this.cadBalance) + bal).toFixed(2);
                else this.usdBalance = (parseFloat(this.usdBalance) + bal).toFixed(2);
            }
        },

        selectAccount(a) {
            this.selectedAccount = a;
            this.txnPage = 1;
            this.loadTransactions();
        },

        async loadTransactions() {
            if (!this.selectedAccount) return;
            this.txnLoading = true;
            let url = `<?= base_url('api/v1/accounting/bank-transactions') ?>?bank_account_id=${this.selectedAccount.id}&page=${this.txnPage}&per_page=50`;
            if (this.txnFilter.status) url += `&status=${this.txnFilter.status}`;
            try {
                const r = await fetch(url);
                const j = await r.json();
                const d = j.data || {};
                this.transactions = d.items || [];
                this.txnTotalPages = d.pagination?.total_pages || 1;
                // Update unmatched count
                const ur = await fetch(`<?= base_url('api/v1/accounting/bank-transactions') ?>?bank_account_id=${this.selectedAccount.id}&status=unmatched&per_page=1`);
                const uj = await ur.json();
                this.unmatchedCount = uj.data?.pagination?.total || 0;
            } catch (e) { console.error(e); }
            this.txnLoading = false;
        },

        openAccountModal(account = null) {
            this.editingAccount = account;
            this.accountForm = account ? {...account, is_default: account.is_default ? 1 : 0} : this._emptyAccountForm();
            this._clearErrors(this.accountErrors, 'accountFormError');
            this.showAccountModal = true;
        },

        validateAccount() {
            this._clearErrors(this.accountErrors, 'accountFormError');
            let ok = true;
            if (!this.accountForm.name || !this.accountForm.name.trim()) {
                this.accountErrors.name = 'Account name is required.';
                ok = false;
            }
            const validTypes = ['checking', 'savings', 'line_of_credit', 'credit_card'];
            if (!this.accountForm.account_type || !validTypes.includes(this.accountForm.account_type)) {
                this.accountErrors.account_type = 'Account type must be one of: checking, savings, line_of_credit, credit_card.';
                ok = false;
            }
            if (this.accountForm.account_number_last4 && !/^\d{1,4}$/.test(this.accountForm.account_number_last4)) {
                this.accountErrors.account_number_last4 = 'Last 4 digits must be numeric (up to 4 digits).';
                ok = false;
            }
            if (this.accountForm.currency !== 'CAD' && this.accountForm.currency !== 'USD') {
                this.accountErrors.currency = 'Currency must be CAD or USD.';
                ok = false;
            }
            if (!this.accountForm.gl_account_id) {
                this.accountErrors.gl_account_id = 'Please select a GL cash account.';
                ok = false;
            }
            const ob = String(this.accountForm.opening_balance || '0.00').trim();
            if (ob && !/^-?\d+(\.\d{1,2})?$/.test(ob)) {
                this.accountErrors.opening_balance = 'Opening balance must be a valid amount.';
                ok = false;
            } else if (ob && parseFloat(ob) !== 0 && !this.accountForm.opening_balance_date) {
                this.accountErrors.opening_balance_date = 'Opening date is required when opening balance is non-zero.';
                ok = false;
            }
            return ok;
        },

        async saveAccount() {
            if (!this.validateAccount()) {
                this.accountFormError = 'Please correct the highlighted fields.';
                return;
            }
            this.saving = true;
            this.accountFormError = '';
            const fd = new FormData();
            for (const [k,v] of Object.entries(this.accountForm)) {
                if (v !== null && v !== undefined) fd.append(k, v);
            }
            const url = this.editingAccount
                ? `<?= base_url('api/v1/accounting/bank-accounts/update') ?>`
                : `<?= base_url('api/v1/accounting/bank-accounts/create') ?>`;
            if (this.editingAccount) fd.append('id', this.editingAccount.id);
            try {
                const r = await fetch(url, {method:'POST', body:fd, headers:this._csrfHeaders()});
                const j = await r.json();
                if (j.success) {
                    this.showAccountModal = false;
                    window.FleetForge?.toast?.('success', this.editingAccount ? 'Account updated' : 'Account created');
                    this.load();
                } else {
                    this._paintErrors(this.accountErrors, 'accountFormError', j, 'Failed to save account.');
                }
            } catch (e) {
                this.accountFormError = 'Network error. Please try again.';
            }
            this.saving = false;
        },

        async excludeTxn(id, exclude) {
            const fd = new FormData();
            fd.append('id', id);
            fd.append('exclude', exclude);
            try {
                const r = await fetch(`<?= base_url('api/v1/accounting/bank-transactions/exclude') ?>`, {method:'POST', body:fd, headers:this._csrfHeaders()});
                const j = await r.json();
                if (j.success) this.loadTransactions();
                else window.FleetForge?.toast?.('error', j.error?.message || 'Failed');
            } catch (e) { console.error(e); }
        },

        async unmatchTxn(id) {
            const fd = new FormData();
            fd.append('id', id);
            try {
                const r = await fetch(`<?= base_url('api/v1/accounting/bank-transactions/unmatch') ?>`, {method:'POST', body:fd, headers:this._csrfHeaders()});
                const j = await r.json();
                if (j.success) this.loadTransactions();
                else window.FleetForge?.toast?.('error', j.error?.message || 'Failed');
            } catch (e) { console.error(e); }
        },

        async uploadCsv() {
            this._clearErrors(this.importErrors, 'importFormError');
            let ok = true;
            if (!this.importForm.bank_account_id) {
                this.importErrors.bank_account_id = 'Please select a bank account.';
                ok = false;
            }
            if (!this.importForm.file) {
                this.importErrors.csv_file = 'Please choose a CSV file to upload.';
                ok = false;
            }
            if (!ok) {
                this.importFormError = 'Please correct the highlighted fields.';
                return;
            }
            this.importing = true;
            const fd = new FormData();
            fd.append('bank_account_id', this.importForm.bank_account_id);
            fd.append('csv_file', this.importForm.file);
            if (this.importForm.format) fd.append('format', this.importForm.format);
            try {
                const r = await fetch(`<?= base_url('api/v1/accounting/bank-import/upload-preview') ?>`, {method:'POST', body:fd, headers:this._csrfHeaders()});
                const j = await r.json();
                if (j.success) {
                    this.importPreview = j.data;
                    this.importSelected = (j.data.transactions || []).filter(t => !t.is_duplicate).map(t => t.row_number);
                    this.importStep = 2;
                } else {
                    this._paintErrors(this.importErrors, 'importFormError', j, 'Upload failed.');
                }
            } catch (e) {
                this.importFormError = 'Network error. Please try again.';
            }
            this.importing = false;
        },

        toggleAllImport(checked) {
            this.importSelected = checked
                ? (this.importPreview.transactions || []).filter(t => !t.is_duplicate).map(t => t.row_number)
                : [];
        },

        async confirmImport() {
            this.importing = true;
            const fd = new FormData();
            fd.append('selected_rows', JSON.stringify(this.importSelected));
            try {
                const r = await fetch(`<?= base_url('api/v1/accounting/bank-import/confirm') ?>`, {method:'POST', body:fd, headers:this._csrfHeaders()});
                const j = await r.json();
                if (j.success) {
                    this.importResult = j.data;
                    this.importStep = 3;
                } else {
                    window.FleetForge?.toast?.('error', j.error?.message || 'Import failed');
                }
            } catch (e) { window.FleetForge?.toast?.('error', 'Import failed'); }
            this.importing = false;
        },

        validateManualTxn() {
            this._clearErrors(this.manualTxnErrors, 'manualTxnFormError');
            let ok = true;
            if (!this.manualTxnForm.transaction_date) {
                this.manualTxnErrors.transaction_date = 'Transaction date is required.';
                ok = false;
            }
            const validTypes = ['deposit', 'withdrawal', 'bank_charge', 'interest', 'other'];
            if (!this.manualTxnForm.transaction_type || !validTypes.includes(this.manualTxnForm.transaction_type)) {
                this.manualTxnErrors.transaction_type = 'Transaction type must be one of: deposit, withdrawal, bank_charge, interest, other.';
                ok = false;
            }
            if (!this.manualTxnForm.description || !this.manualTxnForm.description.trim()) {
                this.manualTxnErrors.description = 'Description is required.';
                ok = false;
            }
            const amt = String(this.manualTxnForm.amount || '').trim();
            if (!amt) {
                this.manualTxnErrors.amount = 'Amount is required.';
                ok = false;
            } else if (!/^-?\d+(\.\d{1,2})?$/.test(amt)) {
                this.manualTxnErrors.amount = 'Amount must be a valid number.';
                ok = false;
            } else if (parseFloat(amt) === 0) {
                this.manualTxnErrors.amount = 'Amount cannot be zero.';
                ok = false;
            }
            return ok;
        },

        async saveManualTxn() {
            if (!this.selectedAccount) {
                this.manualTxnFormError = 'Select a bank account first.';
                return;
            }
            if (!this.validateManualTxn()) {
                this.manualTxnFormError = 'Please correct the highlighted fields.';
                return;
            }
            this.saving = true;
            this.manualTxnFormError = '';
            const fd = new FormData();
            fd.append('bank_account_id', this.selectedAccount.id);
            for (const [k,v] of Object.entries(this.manualTxnForm)) {
                if (v) fd.append(k, v);
            }
            try {
                const r = await fetch(`<?= base_url('api/v1/accounting/bank-transactions/create') ?>`, {method:'POST', body:fd, headers:this._csrfHeaders()});
                const j = await r.json();
                if (j.success) {
                    this.showManualTxnModal = false;
                    window.FleetForge?.toast?.('success', 'Transaction created');
                    this.loadTransactions();
                } else {
                    this._paintErrors(this.manualTxnErrors, 'manualTxnFormError', j, 'Failed to save transaction.');
                }
            } catch (e) {
                this.manualTxnFormError = 'Network error. Please try again.';
            }
            this.saving = false;
        },

        openTransferModal() {
            this.transferForm = { from_account_id: '', to_account_id: '', from_amount: '', to_amount: '', transfer_date: new Date().toISOString().slice(0,10), reference: '' };
            this._clearErrors(this.transferErrors, 'transferFormError');
            this.showTransferModal = true;
        },

        validateTransfer() {
            this._clearErrors(this.transferErrors, 'transferFormError');
            let ok = true;
            if (!this.transferForm.from_account_id) {
                this.transferErrors.from_account_id = 'Please select the source account.';
                ok = false;
            }
            if (!this.transferForm.to_account_id) {
                this.transferErrors.to_account_id = 'Please select the destination account.';
                ok = false;
            }
            if (this.transferForm.from_account_id && this.transferForm.to_account_id
                && this.transferForm.from_account_id === this.transferForm.to_account_id) {
                this.transferErrors.to_account_id = 'Source and destination must be different.';
                ok = false;
            }
            const fa = String(this.transferForm.from_amount || '').trim();
            if (!fa) {
                this.transferErrors.from_amount = 'Amount from is required.';
                ok = false;
            } else if (!/^\d+(\.\d{1,2})?$/.test(fa)) {
                this.transferErrors.from_amount = 'Amount from must be a valid number.';
                ok = false;
            } else if (parseFloat(fa) <= 0) {
                this.transferErrors.from_amount = 'Amount from must be greater than zero.';
                ok = false;
            }
            const ta = String(this.transferForm.to_amount || '').trim();
            if (ta && !/^\d+(\.\d{1,2})?$/.test(ta)) {
                this.transferErrors.to_amount = 'Amount to must be a valid number.';
                ok = false;
            } else if (ta && parseFloat(ta) <= 0) {
                this.transferErrors.to_amount = 'Amount to must be greater than zero.';
                ok = false;
            }
            if (!this.transferForm.transfer_date) {
                this.transferErrors.transfer_date = 'Transfer date is required.';
                ok = false;
            }
            return ok;
        },

        async saveTransfer() {
            if (!this.validateTransfer()) {
                this.transferFormError = 'Please correct the highlighted fields.';
                return;
            }
            this.saving = true;
            this.transferFormError = '';
            const fd = new FormData();
            for (const [k,v] of Object.entries(this.transferForm)) {
                if (v) fd.append(k, v);
            }
            try {
                const r = await fetch(`<?= base_url('api/v1/accounting/bank-transfers/create') ?>`, {method:'POST', body:fd, headers:this._csrfHeaders()});
                const j = await r.json();
                if (j.success) {
                    this.showTransferModal = false;
                    window.FleetForge?.toast?.('success', 'Transfer recorded');
                    this.load();
                } else {
                    this._paintErrors(this.transferErrors, 'transferFormError', j, 'Transfer failed.');
                }
            } catch (e) {
                this.transferFormError = 'Network error. Please try again.';
            }
            this.saving = false;
        },

        validateNsf() {
            this._clearErrors(this.nsfErrors, 'nsfFormError');
            let ok = true;
            if (!this.nsfForm.payment_id || parseInt(this.nsfForm.payment_id, 10) <= 0) {
                this.nsfErrors.payment_id = 'Please select a payment.';
                ok = false;
            }
            const fee = String(this.nsfForm.nsf_fee || '0.00').trim();
            if (fee && !/^\d+(\.\d{1,2})?$/.test(fee)) {
                this.nsfErrors.nsf_fee = 'NSF fee must be a valid amount.';
                ok = false;
            } else if (fee && parseFloat(fee) < 0) {
                this.nsfErrors.nsf_fee = 'NSF fee cannot be negative.';
                ok = false;
            }
            return ok;
        },

        async processNsf() {
            if (!this.selectedAccount) {
                this.nsfFormError = 'Select a bank account first.';
                return;
            }
            if (!this.validateNsf()) {
                this.nsfFormError = 'Please correct the highlighted fields.';
                return;
            }
            if (!(await FF_Confirm.ask('Are you sure you want to process this NSF? This will reverse the payment and reopen associated invoices.'))) return;
            this.saving = true;
            this.nsfFormError = '';
            const fd = new FormData();
            fd.append('payment_id', this.nsfForm.payment_id);
            fd.append('bank_account_id', this.selectedAccount.id);
            fd.append('nsf_fee', this.nsfForm.nsf_fee || '0.00');
            try {
                const r = await fetch(`<?= base_url('api/v1/accounting/bank-nsf/create') ?>`, {method:'POST', body:fd, headers:this._csrfHeaders()});
                const j = await r.json();
                if (j.success) {
                    this.showNsfModal = false;
                    window.FleetForge?.toast?.('success', 'NSF processed — payment reversed');
                    this.loadTransactions();
                    this.load();
                } else {
                    this._paintErrors(this.nsfErrors, 'nsfFormError', j, 'NSF failed.');
                }
            } catch (e) {
                this.nsfFormError = 'Network error. Please try again.';
            }
            this.saving = false;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
