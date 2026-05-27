<?php declare(strict_types=1);

/**
 * app/admin/accounting/bills/index.php
 *
 * AP Bills management — list, create, edit, approve, void bills.
 * Tabs: All | Draft | Approved | Partially Paid | Paid | Overdue | Void.
 * KPI tiles: Total Outstanding AP, Due This Week, Overdue.
 * Create/edit modal with line items, auto-categorization, tax fields.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @session S032
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('accounts_payable', 'view');

// Preload data for dropdowns
$vendors = db_select(
    "SELECT id, name, vendor_type FROM vendors WHERE deleted_at IS NULL ORDER BY name",
    []
);
$glAccounts = db_select(
    "SELECT id, code, name, account_type FROM acc_accounts WHERE is_active = 1 AND is_header = 0 ORDER BY code",
    []
);
$bankAccounts = db_select(
    "SELECT id, name FROM acc_bank_accounts WHERE is_active = 1 ORDER BY name",
    []
);

$canCreate = can('accounts_payable', 'create');
$canEdit   = can('accounts_payable', 'edit');
$canDelete = can('accounts_payable', 'delete');

$pageTitle = 'AP Bills';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Bills</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Accounts Payable — Bills</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="billsPage()" x-init="load()">
    <!-- KPI Tiles -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;">
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Outstanding AP</div>
            <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-accent);" x-text="fmtAmt(kpi.outstanding)"></div>
        </div>
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Due This Week</div>
            <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-warning);" x-text="fmtAmt(kpi.dueThisWeek)"></div>
        </div>
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Overdue</div>
            <div class="font-mono" style="font-size:1.125rem;font-weight:700;color:var(--color-danger);" x-text="fmtAmt(kpi.overdue)"></div>
        </div>
        <div class="card" style="padding:14px;">
            <div style="font-size:0.6875rem;text-transform:uppercase;font-weight:600;color:var(--text-secondary);letter-spacing:0.05em;">Total Bills</div>
            <div style="font-size:1.125rem;font-weight:700;color:var(--text-primary);" x-text="totalRows"></div>
        </div>
    </div>

    <!-- Toolbar -->
    <div style="display:flex;justify-content:space-between;align-items:end;margin-bottom:16px;gap:12px;flex-wrap:wrap;">
        <div style="display:flex;gap:6px;overflow-x:auto;">
            <template x-for="t in tabs" :key="t.value">
                <button class="btn btn-sm" :class="tab === t.value ? 'btn-primary' : 'btn-secondary'" @click="tab = t.value; load()" x-text="t.label" style="white-space:nowrap;"></button>
            </template>
        </div>
        <div style="display:flex;gap:8px;align-items:end;">
            <select x-model="filterVendor" @change="load()" class="form-input" style="padding:7px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;max-width:200px;">
                <option value="">All Vendors</option>
                <?php foreach ($vendors as $v): ?>
                <option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" x-model="search" @keyup.enter="load()" placeholder="Search bills..." class="form-input" style="padding:7px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;width:180px;">
            <?php if ($canCreate): ?>
            <button class="btn btn-primary btn-sm" @click="openCreate()">+ New Bill</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Loading -->
    <template x-if="loading">
        <div class="card" style="padding:48px;text-align:center;">
            <div class="spinner" style="margin:0 auto 12px;"></div>
            <div style="color:var(--text-secondary);font-size:0.875rem;">Loading bills...</div>
        </div>
    </template>

    <!-- Bills Table -->
    <template x-if="!loading && bills.length > 0">
        <div class="card" style="overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-default);">
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Bill #</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Vendor</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Vendor Ref</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Date</th>
                        <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Due</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Total</th>
                        <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Balance</th>
                        <th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--text-secondary);">Status</th>
                        <th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--text-secondary);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="b in bills" :key="b.id">
                        <tr style="border-bottom:1px solid var(--border-default);" :style="b.status === 'void' ? 'opacity:0.5' : ''">
                            <td class="font-mono" style="padding:8px 12px;font-weight:500;" x-text="b.bill_number"></td>
                            <td style="padding:8px 12px;" x-text="b.vendor_name"></td>
                            <td style="padding:8px 12px;color:var(--text-secondary);" x-text="b.vendor_bill_number || '—'"></td>
                            <td class="font-mono" style="padding:8px 12px;" x-text="b.bill_date"></td>
                            <td class="font-mono" style="padding:8px 12px;" x-text="b.due_date"></td>
                            <td class="font-mono" style="padding:8px 12px;text-align:right;" x-text="fmtAmt(b.total_amount)"></td>
                            <td class="font-mono" style="padding:8px 12px;text-align:right;font-weight:600;" x-text="fmtAmt(b.balance_due)"></td>
                            <td style="padding:8px 12px;text-align:center;">
                                <span class="badge" :class="badgeClass(b.status)" x-text="b.status"></span>
                            </td>
                            <td style="padding:8px 12px;text-align:center;">
                                <div style="display:flex;gap:4px;justify-content:center;">
                                    <button class="btn btn-ghost btn-xs" @click="viewBill(b.id)" title="View">👁</button>
                                    <?php if ($canEdit): ?>
                                    <template x-if="b.status === 'draft'">
                                        <button class="btn btn-success btn-xs" @click="approveBill(b.id)" title="Approve">✓</button>
                                    </template>
                                    <template x-if="b.status === 'draft' || b.status === 'approved'">
                                        <button class="btn btn-danger btn-xs" @click="voidBill(b.id)" title="Void">✕</button>
                                    </template>
                                    <?php endif; ?>
                                    <template x-if="b.status === 'approved' || b.status === 'partially_paid'">
                                        <button class="btn btn-primary btn-xs" @click="openPay(b)" title="Pay">$</button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <!-- Pagination -->
            <div style="padding:12px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border-default);">
                <div style="font-size:0.75rem;color:var(--text-secondary);" x-text="'Showing ' + bills.length + ' of ' + totalRows"></div>
                <div style="display:flex;gap:4px;">
                    <button class="btn btn-secondary btn-xs" @click="page--; load()" :disabled="page <= 1">Prev</button>
                    <button class="btn btn-secondary btn-xs" @click="page++; load()" :disabled="page * perPage >= totalRows">Next</button>
                </div>
            </div>
        </div>
    </template>

    <!-- Empty -->
    <template x-if="!loading && bills.length === 0">
        <div class="card" style="padding:48px;text-align:center;">
            <div style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:4px;">No Bills Found</div>
            <div style="font-size:0.8125rem;color:var(--text-secondary);">
                <span x-show="tab">No bills match this filter.</span>
                <span x-show="!tab">Create your first vendor bill to start tracking AP.</span>
            </div>
            <?php if ($canCreate): ?>
            <button class="btn btn-primary btn-sm" @click="openCreate()" style="margin-top:12px;">+ New Bill</button>
            <?php endif; ?>
        </div>
    </template>

    <!-- Create/Edit Modal -->
    <div x-show="showModal" x-cloak style="position:fixed;inset:0;z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:40px 20px;background:rgba(0,0,0,0.5);overflow-y:auto;"
         @click.self="showModal = false">
            <div class="card" style="width:100%;max-width:900px;padding:24px;" x-show="showModal" x-transition>
                <div style="font-weight:700;font-size:1rem;margin-bottom:16px;" x-text="form.id ? 'Edit Bill' : 'New Bill'"></div>
                <div class="form-error-banner" x-show="saveFormError" x-cloak x-text="saveFormError" style="margin-bottom:12px;"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px;">
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Vendor *</label>
                        <select x-model="form.vendor_id" @change="saveErrors.vendor_id = ''; onVendorChange()" :class="saveErrors.vendor_id ? 'is-invalid' : ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                            <option value="">Select vendor...</option>
                            <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int)$v['id'] ?>" data-type="<?= e($v['vendor_type']) ?>"><?= e($v['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-error" x-show="saveErrors.vendor_id" x-cloak x-text="saveErrors.vendor_id"></div>
                    </div>
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Bill Date *</label>
                        <input type="date" x-model="form.bill_date" :class="saveErrors.bill_date ? 'is-invalid' : ''" @change="saveErrors.bill_date = ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                        <div class="field-error" x-show="saveErrors.bill_date" x-cloak x-text="saveErrors.bill_date"></div>
                    </div>
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Due Date *</label>
                        <input type="date" x-model="form.due_date" :class="saveErrors.due_date ? 'is-invalid' : ''" @change="saveErrors.due_date = ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                        <div class="field-error" x-show="saveErrors.due_date" x-cloak x-text="saveErrors.due_date"></div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Vendor Invoice #</label>
                        <input type="text" x-model="form.vendor_bill_number" class="form-input" maxlength="21" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;" placeholder="Vendor's invoice # (max 21 chars — QBO limit)">
                        <div class="text-xs text-secondary" style="margin-top:2px;" x-show="form.vendor_bill_number"><span x-text="(form.vendor_bill_number || '').length"></span>/21 chars</div>
                    </div>
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Notes</label>
                        <input type="text" x-model="form.notes" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;" placeholder="Internal notes">
                    </div>
                </div>

                <!-- Line Items -->
                <div style="font-weight:600;font-size:0.875rem;margin-bottom:8px;">Line Items</div>
                <div style="overflow-x:auto;margin-bottom:12px;">
                    <table style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                        <thead>
                            <tr style="border-bottom:1px solid var(--border-default);">
                                <th style="padding:6px 8px;text-align:left;font-weight:600;color:var(--text-secondary);width:200px;">GL Account</th>
                                <th style="padding:6px 8px;text-align:left;font-weight:600;color:var(--text-secondary);">Description</th>
                                <th style="padding:6px 8px;text-align:right;font-weight:600;color:var(--text-secondary);width:70px;">Qty</th>
                                <th style="padding:6px 8px;text-align:right;font-weight:600;color:var(--text-secondary);width:90px;">Unit Cost</th>
                                <th style="padding:6px 8px;text-align:right;font-weight:600;color:var(--text-secondary);width:80px;">GST</th>
                                <th style="padding:6px 8px;text-align:right;font-weight:600;color:var(--text-secondary);width:80px;">PST</th>
                                <th style="padding:6px 8px;text-align:right;font-weight:600;color:var(--text-secondary);width:90px;">Amount</th>
                                <th style="padding:6px 8px;width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(line, li) in form.lines" :key="li">
                                <tr style="border-bottom:1px solid var(--border-default);">
                                    <td style="padding:4px 4px;vertical-align:top;">
                                        <div style="position:relative;">
                                            <select x-model="line.account_id" :class="(lineErrors[li] && lineErrors[li].account_id) ? 'is-invalid' : ''" @change="lineErrors[li] && (lineErrors[li].account_id = '')" class="form-input" style="width:100%;padding:6px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.75rem;">
                                                <option value="">Select...</option>
                                                <?php foreach ($glAccounts as $a): ?>
                                                <option value="<?= (int)$a['id'] ?>"><?= e($a['code'] . ' — ' . $a['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <template x-if="line.is_auto_categorized">
                                                <span style="position:absolute;top:-6px;right:2px;font-size:0.6rem;background:var(--badge-info-bg);color:var(--badge-info-text);padding:1px 4px;border-radius:3px;">Auto</span>
                                            </template>
                                            <div class="field-error" x-show="lineErrors[li] && lineErrors[li].account_id" x-cloak x-text="lineErrors[li] && lineErrors[li].account_id"></div>
                                        </div>
                                    </td>
                                    <td style="padding:4px;vertical-align:top;">
                                        <input type="text" x-model="line.description" :class="(lineErrors[li] && lineErrors[li].description) ? 'is-invalid' : ''" @input="lineErrors[li] && (lineErrors[li].description = '')" class="form-input" style="width:100%;padding:6px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.75rem;" placeholder="Description">
                                        <div class="field-error" x-show="lineErrors[li] && lineErrors[li].description" x-cloak x-text="lineErrors[li] && lineErrors[li].description"></div>
                                    </td>
                                    <td style="padding:4px;vertical-align:top;">
                                        <input type="number" x-model="line.quantity" @input="calcLineAmt(li); lineErrors[li] && (lineErrors[li].quantity = '')" step="0.01" min="0" :class="(lineErrors[li] && lineErrors[li].quantity) ? 'is-invalid' : ''" class="form-input font-mono" style="width:100%;padding:6px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.75rem;text-align:right;">
                                        <div class="field-error" x-show="lineErrors[li] && lineErrors[li].quantity" x-cloak x-text="lineErrors[li] && lineErrors[li].quantity"></div>
                                    </td>
                                    <td style="padding:4px;vertical-align:top;">
                                        <input type="number" x-model="line.unit_cost" @input="calcLineAmt(li); lineErrors[li] && (lineErrors[li].unit_cost = '')" step="0.01" min="0" :class="(lineErrors[li] && lineErrors[li].unit_cost) ? 'is-invalid' : ''" class="form-input font-mono" style="width:100%;padding:6px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.75rem;text-align:right;">
                                        <div class="field-error" x-show="lineErrors[li] && lineErrors[li].unit_cost" x-cloak x-text="lineErrors[li] && lineErrors[li].unit_cost"></div>
                                    </td>
                                    <td style="padding:4px;vertical-align:top;">
                                        <input type="number" x-model="line.tax_gst_amount" @input="calcTotals(); lineErrors[li] && (lineErrors[li].tax_gst_amount = '')" step="0.01" min="0" :class="(lineErrors[li] && lineErrors[li].tax_gst_amount) ? 'is-invalid' : ''" class="form-input font-mono" style="width:100%;padding:6px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.75rem;text-align:right;">
                                        <div class="field-error" x-show="lineErrors[li] && lineErrors[li].tax_gst_amount" x-cloak x-text="lineErrors[li] && lineErrors[li].tax_gst_amount"></div>
                                    </td>
                                    <td style="padding:4px;vertical-align:top;">
                                        <input type="number" x-model="line.tax_pst_amount" @input="calcTotals(); lineErrors[li] && (lineErrors[li].tax_pst_amount = '')" step="0.01" min="0" :class="(lineErrors[li] && lineErrors[li].tax_pst_amount) ? 'is-invalid' : ''" class="form-input font-mono" style="width:100%;padding:6px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.75rem;text-align:right;">
                                        <div class="field-error" x-show="lineErrors[li] && lineErrors[li].tax_pst_amount" x-cloak x-text="lineErrors[li] && lineErrors[li].tax_pst_amount"></div>
                                    </td>
                                    <td class="font-mono" style="padding:6px 8px;text-align:right;font-weight:500;vertical-align:top;" x-text="fmtAmt(line.amount)"></td>
                                    <td style="padding:4px;text-align:center;vertical-align:top;"><button class="btn btn-ghost btn-xs" @click="removeLine(li)" title="Remove">&times;</button></td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr><td colspan="8" style="padding:8px 4px;">
                                <button class="btn btn-secondary btn-xs" @click="addLine()">+ Add Line</button>
                                <button class="btn btn-secondary btn-xs" @click="autoCategorize()" style="margin-left:8px;" title="Run auto-categorization rules">⚡ Auto-Categorize</button>
                            </td></tr>
                            <tr style="border-top:2px solid var(--border-default);">
                                <td colspan="5"></td>
                                <td style="padding:6px 8px;text-align:right;font-weight:600;color:var(--text-secondary);font-size:0.75rem;">Subtotal:</td>
                                <td class="font-mono" style="padding:6px 8px;text-align:right;font-weight:600;" x-text="fmtAmt(calcSubtotal())"></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="5"></td>
                                <td style="padding:4px 8px;text-align:right;color:var(--text-secondary);font-size:0.75rem;">Tax:</td>
                                <td class="font-mono" style="padding:4px 8px;text-align:right;" x-text="fmtAmt(calcTaxTotal())"></td>
                                <td></td>
                            </tr>
                            <tr style="border-top:1px solid var(--border-default);">
                                <td colspan="5"></td>
                                <td style="padding:6px 8px;text-align:right;font-weight:700;font-size:0.875rem;">Total:</td>
                                <td class="font-mono" style="padding:6px 8px;text-align:right;font-weight:700;font-size:0.875rem;" x-text="fmtAmt(calcGrandTotal())"></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
                    <button class="btn btn-secondary btn-sm" @click="cancelEditModal()">Cancel</button>
                    <button class="btn btn-primary btn-sm" @click="saveBill(false)" :disabled="saving">Save as Draft</button>
                    <button class="btn btn-success btn-sm" @click="saveBill(true)" :disabled="saving">Save & Approve</button>
                </div>
            </div>
    </div>

    <!-- Pay Modal -->
    <div x-show="showPayModal" x-cloak style="position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);"
         @click.self="showPayModal = false">
            <div class="card" style="width:100%;max-width:520px;padding:24px;" x-show="showPayModal" x-transition>
                <div style="font-weight:700;font-size:1rem;margin-bottom:16px;">Record Payment</div>
                <div style="font-size:0.8125rem;color:var(--text-secondary);margin-bottom:12px;">
                    Bill: <span class="font-mono" x-text="payForm.bill_number"></span> — Balance: <span class="font-mono" x-text="fmtAmt(payForm.balance_due)"></span>
                </div>
                <div class="form-error-banner" x-show="payFormError" x-cloak x-text="payFormError" style="margin-bottom:12px;"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Amount *</label>
                        <input type="number" x-model="payForm.amount" step="0.01" min="0.01" :class="payErrors.amount ? 'is-invalid' : ''" @input="payErrors.amount = ''" class="form-input font-mono" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
                        <div class="field-error" x-show="payErrors.amount" x-cloak x-text="payErrors.amount"></div>
                    </div>
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Payment Date *</label>
                        <input type="date" x-model="payForm.payment_date" :class="payErrors.payment_date ? 'is-invalid' : ''" @change="payErrors.payment_date = ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
                        <div class="field-error" x-show="payErrors.payment_date" x-cloak x-text="payErrors.payment_date"></div>
                    </div>
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Method *</label>
                        <select x-model="payForm.payment_method" :class="payErrors.payment_method ? 'is-invalid' : ''" @change="payErrors.payment_method = ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
                            <option value="check">Check</option>
                            <option value="eft">EFT</option>
                            <option value="wire">Wire</option>
                            <option value="credit_card">Credit Card</option>
                            <option value="cash">Cash</option>
                        </select>
                        <div class="field-error" x-show="payErrors.payment_method" x-cloak x-text="payErrors.payment_method"></div>
                    </div>
                    <div>
                        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Bank Account *</label>
                        <select x-model="payForm.bank_account_id" :class="payErrors.bank_account_id ? 'is-invalid' : ''" @change="payErrors.bank_account_id = ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
                            <?php foreach ($bankAccounts as $ba): ?>
                            <option value="<?= (int)$ba['id'] ?>"><?= e($ba['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-error" x-show="payErrors.bank_account_id" x-cloak x-text="payErrors.bank_account_id"></div>
                    </div>
                </div>
                <div x-show="payForm.payment_method === 'check'" style="margin-bottom:12px;">
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Check Number *</label>
                    <input type="text" x-model="payForm.check_number" :class="payErrors.check_number ? 'is-invalid' : ''" @input="payErrors.check_number = ''" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
                    <div class="field-error" x-show="payErrors.check_number" x-cloak x-text="payErrors.check_number"></div>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button class="btn btn-secondary btn-sm" @click="cancelPayModal()">Cancel</button>
                    <button class="btn btn-success btn-sm" @click="submitPay()" :disabled="payProcessing">Record Payment</button>
                </div>
            </div>
    </div>

    <!-- View Detail Modal -->
    <div x-show="showDetail" x-cloak style="position:fixed;inset:0;z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:40px 20px;background:rgba(0,0,0,0.5);overflow-y:auto;"
         @click.self="showDetail = false">
            <div class="card" style="width:100%;max-width:800px;padding:24px;" x-show="showDetail" x-transition>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                    <div style="font-weight:700;font-size:1rem;" x-text="'Bill ' + detail.bill_number"></div>
                    <span class="badge" :class="badgeClass(detail.status)" x-text="detail.status"></span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;font-size:0.8125rem;">
                    <div><span style="color:var(--text-secondary);">Vendor:</span> <span x-text="detail.vendor_name"></span></div>
                    <div><span style="color:var(--text-secondary);">Bill Date:</span> <span class="font-mono" x-text="detail.bill_date"></span></div>
                    <div><span style="color:var(--text-secondary);">Due Date:</span> <span class="font-mono" x-text="detail.due_date"></span></div>
                    <div><span style="color:var(--text-secondary);">Vendor Ref:</span> <span x-text="detail.vendor_bill_number || '—'"></span></div>
                    <div><span style="color:var(--text-secondary);">Total:</span> <span class="font-mono" x-text="fmtAmt(detail.total_amount)"></span></div>
                    <div><span style="color:var(--text-secondary);">Balance:</span> <span class="font-mono" style="font-weight:700;" x-text="fmtAmt(detail.balance_due)"></span></div>
                </div>
                <div style="font-weight:600;font-size:0.875rem;margin-bottom:8px;">Line Items</div>
                <table style="width:100%;border-collapse:collapse;font-size:0.8125rem;margin-bottom:16px;">
                    <thead><tr style="border-bottom:1px solid var(--border-default);">
                        <th style="padding:6px 8px;text-align:left;color:var(--text-secondary);">Account</th>
                        <th style="padding:6px 8px;text-align:left;color:var(--text-secondary);">Description</th>
                        <th style="padding:6px 8px;text-align:right;color:var(--text-secondary);">Amount</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="l in detail.lines" :key="l.id">
                            <tr style="border-bottom:1px solid var(--border-default);">
                                <td style="padding:6px 8px;" x-text="l.account_code + ' — ' + l.account_name"></td>
                                <td style="padding:6px 8px;">
                                    <span x-text="l.description"></span>
                                    <template x-if="l.is_auto_categorized == 1">
                                        <span style="font-size:0.6rem;background:var(--badge-info-bg);color:var(--badge-info-text);padding:1px 4px;border-radius:3px;margin-left:4px;">Auto-suggested</span>
                                    </template>
                                </td>
                                <td class="font-mono" style="padding:6px 8px;text-align:right;" x-text="fmtAmt(l.amount)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <template x-if="detail.payments && detail.payments.length > 0">
                    <div>
                        <div style="font-weight:600;font-size:0.875rem;margin-bottom:8px;">Payments</div>
                        <template x-for="p in detail.payments" :key="p.payment_number">
                            <div style="font-size:0.8125rem;padding:4px 0;display:flex;justify-content:space-between;">
                                <span><span class="font-mono" x-text="p.payment_number"></span> — <span x-text="p.payment_date"></span> via <span x-text="p.payment_method"></span></span>
                                <span class="font-mono" x-text="fmtAmt(p.amount_applied)"></span>
                            </div>
                        </template>
                    </div>
                </template>
                <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                    <button class="btn btn-secondary btn-sm" @click="showDetail = false">Close</button>
                </div>
            </div>
    </div>
</div>

<script>
function billsPage() {
    return {
        bills: [], loading: false, totalRows: 0, page: 1, perPage: 25,
        tab: '', filterVendor: '', search: '',
        kpi: { outstanding: '0.00', dueThisWeek: '0.00', overdue: '0.00' },
        showModal: false, showPayModal: false, showDetail: false,
        saving: false, payProcessing: false,
        form: {}, payForm: {}, detail: {},

        // VALID-2 per-form error state
        saveFormError: '',
        saveErrors: { vendor_id: '', bill_date: '', due_date: '', vendor_bill_number: '', notes: '', lines: '' },
        lineErrors: [],
        payFormError: '',
        payErrors: { amount: '', payment_date: '', payment_method: '', bank_account_id: '', check_number: '' },

        tabs: [
            { label: 'All', value: '' },
            { label: 'Draft', value: 'draft' },
            { label: 'Approved', value: 'approved' },
            { label: 'Partial', value: 'partially_paid' },
            { label: 'Paid', value: 'paid' },
            { label: 'Void', value: 'void' },
        ],

        // WHY: CSRF headers required on all POST requests (api/bootstrap.php enforces)
        _csrfHeaders() {
            return {
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            };
        },

        // VALID-2 helpers
        _extractError(r, fallback) {
            if (r && r.error) {
                if (typeof r.error === 'string') return r.error;
                if (r.error.message) return r.error.message;
            }
            return fallback || 'Request failed.';
        },
        _clearErrors(errorsObj, bannerKey) {
            this[bannerKey] = '';
            for (const k in errorsObj) errorsObj[k] = '';
        },
        _paintErrors(errorsObj, bannerKey, r, fallback) {
            this._clearErrors(errorsObj, bannerKey);
            const fields = r && r.error && r.error.fields;
            if (fields && typeof fields === 'object') {
                let mapped = false;
                for (const k in fields) {
                    if (k === '_general') {
                        this[bannerKey] = String(fields[k]);
                        mapped = true;
                    } else if (k in errorsObj) {
                        errorsObj[k] = String(fields[k]);
                        mapped = true;
                    }
                }
                if (mapped) return;
            }
            this[bannerKey] = this._extractError(r, fallback);
        },
        _paintSaveServerErrors(r, fallback) {
            this._clearSaveErrors();
            const fields = r && r.error && r.error.fields;
            if (fields && typeof fields === 'object') {
                let mapped = false;
                const lineRe = /^lines\[(\d+)\]\.(.+)$/;
                for (const k in fields) {
                    const m = lineRe.exec(k);
                    if (m) {
                        const idx = parseInt(m[1], 10);
                        const field = m[2];
                        if (this.lineErrors[idx] && field in this.lineErrors[idx]) {
                            this.lineErrors[idx][field] = String(fields[k]);
                            mapped = true;
                        }
                    } else if (k === '_general') {
                        this.saveFormError = String(fields[k]);
                        mapped = true;
                    } else if (k in this.saveErrors) {
                        this.saveErrors[k] = String(fields[k]);
                        mapped = true;
                    }
                }
                if (mapped) return;
            }
            this.saveFormError = this._extractError(r, fallback);
        },
        _emptyLineErr() {
            return { account_id: '', description: '', quantity: '', unit_cost: '', tax_gst_amount: '', tax_pst_amount: '' };
        },
        _syncLineErrors() {
            while (this.lineErrors.length < this.form.lines.length) this.lineErrors.push(this._emptyLineErr());
            while (this.lineErrors.length > this.form.lines.length) this.lineErrors.pop();
        },
        _clearSaveErrors() {
            this.saveFormError = '';
            for (const k in this.saveErrors) this.saveErrors[k] = '';
            for (let i = 0; i < this.lineErrors.length; i++) {
                for (const k in this.lineErrors[i]) this.lineErrors[i][k] = '';
            }
        },
        _isValidAmount(v) {
            if (v === null || v === undefined || v === '') return false;
            return /^\d+(\.\d{1,2})?$/.test(String(v).trim());
        },

        validateBill() {
            this._clearSaveErrors();
            this._syncLineErrors();
            let ok = true;
            if (!this.form.vendor_id) {
                this.saveErrors.vendor_id = 'Please select a vendor.';
                ok = false;
            }
            if (!this.form.bill_date) {
                this.saveErrors.bill_date = 'Bill date is required.';
                ok = false;
            }
            if (!this.form.due_date) {
                this.saveErrors.due_date = 'Due date is required.';
                ok = false;
            }
            if (this.form.bill_date && this.form.due_date && this.form.due_date < this.form.bill_date) {
                this.saveErrors.due_date = 'Due date must be on or after bill date.';
                ok = false;
            }
            if (!this.form.lines || this.form.lines.length === 0) {
                this.saveFormError = 'Bill must have at least one line item.';
                ok = false;
            }
            // Per-line validation
            this.form.lines.forEach((line, i) => {
                if (!line.account_id) {
                    this.lineErrors[i].account_id = 'Please select an account.';
                    ok = false;
                }
                if (!line.description || String(line.description).trim() === '') {
                    this.lineErrors[i].description = 'Description is required.';
                    ok = false;
                }
                if (!this._isValidAmount(line.quantity) || Number(line.quantity) <= 0) {
                    this.lineErrors[i].quantity = 'Quantity must be greater than zero.';
                    ok = false;
                }
                if (!this._isValidAmount(line.unit_cost)) {
                    this.lineErrors[i].unit_cost = 'Unit cost must be a valid amount.';
                    ok = false;
                } else if (Number(line.unit_cost) < 0) {
                    this.lineErrors[i].unit_cost = 'Unit cost cannot be negative.';
                    ok = false;
                }
                if (line.tax_gst_amount !== '' && !this._isValidAmount(line.tax_gst_amount)) {
                    this.lineErrors[i].tax_gst_amount = 'GST must be a valid amount.';
                    ok = false;
                }
                if (line.tax_pst_amount !== '' && !this._isValidAmount(line.tax_pst_amount)) {
                    this.lineErrors[i].tax_pst_amount = 'PST must be a valid amount.';
                    ok = false;
                }
            });
            return ok;
        },

        validatePay() {
            this._clearErrors(this.payErrors, 'payFormError');
            let ok = true;
            if (!this._isValidAmount(this.payForm.amount)) {
                this.payErrors.amount = 'Amount must be a valid number with up to 2 decimal places.';
                ok = false;
            } else if (Number(this.payForm.amount) <= 0) {
                this.payErrors.amount = 'Amount must be greater than zero.';
                ok = false;
            } else if (Number(this.payForm.amount) > Number(this.payForm.balance_due)) {
                this.payErrors.amount = 'Amount cannot exceed bill balance.';
                ok = false;
            }
            if (!this.payForm.payment_date) {
                this.payErrors.payment_date = 'Payment date is required.';
                ok = false;
            }
            const validMethods = ['check','eft','wire','credit_card','cash'];
            if (!this.payForm.payment_method || !validMethods.includes(this.payForm.payment_method)) {
                this.payErrors.payment_method = 'Please select a valid payment method.';
                ok = false;
            }
            if (!this.payForm.bank_account_id) {
                this.payErrors.bank_account_id = 'Please select a bank account.';
                ok = false;
            }
            if (this.payForm.payment_method === 'check' && (!this.payForm.check_number || String(this.payForm.check_number).trim() === '')) {
                this.payErrors.check_number = 'Check number is required for check payments.';
                ok = false;
            }
            return ok;
        },

        cancelEditModal() {
            this.showModal = false;
            this._clearSaveErrors();
        },
        cancelPayModal() {
            this.showPayModal = false;
            this._clearErrors(this.payErrors, 'payFormError');
        },

        async load() {
            this.loading = true;
            try {
                const p = new URLSearchParams({page: this.page, per_page: this.perPage});
                if (this.tab) p.set('status', this.tab);
                if (this.filterVendor) p.set('vendor_id', this.filterVendor);
                if (this.search) p.set('q', this.search);
                const r = await fetch(FF_Api.url('/api/v1/accounting/bills/index.php?' + p));
                const j = await r.json();
                if (j.success) {
                    const d = j.data;
                    this.bills = d.items ?? d;
                    this.totalRows = d.pagination?.total ?? j.pagination?.total ?? 0;
                }
            } catch (e) { console.error(e); }
            this.loading = false;
            this.loadKpis();
        },

        async loadKpis() {
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/reports/cash-requirements.php'));
                const j = await r.json();
                if (j.success) {
                    this.kpi.outstanding = j.data.total_due;
                    const week = j.data.periods.find(p => p.label === 'Next 7 Days');
                    const today = j.data.periods.find(p => p.label === 'Due Today');
                    this.kpi.dueThisWeek = String(Number(week?.total || 0) + Number(today?.total || 0));
                    this.kpi.overdue = j.data.periods.find(p => p.label === 'Overdue')?.total || '0.00';
                }
            } catch (e) {}
        },

        openCreate() {
            this.form = {
                id: null, vendor_id: '', bill_date: new Date().toISOString().slice(0,10),
                due_date: '', vendor_bill_number: '', notes: '',
                lines: [this.newLine()],
            };
            this.lineErrors = [this._emptyLineErr()];
            this._clearSaveErrors();
            this.showModal = true;
        },

        newLine() {
            return { account_id: '', description: '', quantity: '1', unit_cost: '0.00',
                     tax_gst_amount: '0.00', tax_pst_amount: '0.00', amount: '0.00',
                     is_auto_categorized: false };
        },
        addLine() { this.form.lines.push(this.newLine()); this._syncLineErrors(); },
        removeLine(i) {
            if (this.form.lines.length > 1) {
                this.form.lines.splice(i, 1);
                this.lineErrors.splice(i, 1);
            }
            this.calcTotals();
        },

        calcLineAmt(i) {
            const l = this.form.lines[i];
            l.amount = (Number(l.quantity || 0) * Number(l.unit_cost || 0)).toFixed(2);
        },
        calcSubtotal() { return this.form.lines?.reduce((s, l) => s + Number(l.amount || 0), 0).toFixed(2) || '0.00'; },
        calcTaxTotal() { return this.form.lines?.reduce((s, l) => s + Number(l.tax_gst_amount||0) + Number(l.tax_pst_amount||0), 0).toFixed(2) || '0.00'; },
        calcGrandTotal() { return (Number(this.calcSubtotal()) + Number(this.calcTaxTotal())).toFixed(2); },
        calcTotals() {},

        async autoCategorize() {
            if (!this.form.vendor_id) return;
            try {
                const fd = new FormData();
                fd.append('vendor_id', this.form.vendor_id);
                fd.append('lines', JSON.stringify(this.form.lines.map(l => ({description: l.description, amount: l.amount}))));
                const r = await fetch(FF_Api.url('/api/v1/accounting/categorization-rules/test.php'), {method: 'POST', body: fd, headers: this._csrfHeaders()});
                const j = await r.json();
                if (j.success && j.data.suggestions) {
                    j.data.suggestions.forEach(s => {
                        if (s.is_auto && s.suggestion && this.form.lines[s.line_index]) {
                            this.form.lines[s.line_index].account_id = String(s.suggestion.account_id);
                            this.form.lines[s.line_index].is_auto_categorized = true;
                        }
                    });
                }
            } catch (e) { console.error(e); }
        },

        onVendorChange() { /* Could auto-set due date from vendor terms */ },

        async saveBill(autoApprove) {
            if (!this.validateBill()) return;
            this.saving = true;
            try {
                const fd = new FormData();
                fd.append('vendor_id', this.form.vendor_id);
                fd.append('bill_date', this.form.bill_date);
                fd.append('due_date', this.form.due_date);
                fd.append('vendor_bill_number', this.form.vendor_bill_number || '');
                fd.append('notes', this.form.notes || '');
                fd.append('auto_approve', autoApprove ? '1' : '0');
                fd.append('lines', JSON.stringify(this.form.lines));
                const url = this.form.id ? '/api/v1/accounting/bills/update.php' : '/api/v1/accounting/bills/create.php';
                if (this.form.id) { fd.append('id', this.form.id); fd.append('updated_at', this.form.updated_at || ''); }
                const r = await fetch(FF_Api.url(url), {method: 'POST', body: fd, headers: this._csrfHeaders()});
                const j = await r.json();
                if (j.success) {
                    this.showModal = false;
                    this._clearSaveErrors();
                    this.load();
                } else {
                    this._paintSaveServerErrors(j, 'Failed to save bill.');
                }
            } catch (e) { this.saveFormError = 'Network error. Please try again.'; }
            this.saving = false;
        },

        async viewBill(id) {
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/bills/show.php?id=' + id));
                const j = await r.json();
                if (j.success) { this.detail = j.data; this.showDetail = true; }
            } catch (e) { console.error(e); }
        },

        async approveBill(id) {
            if (!(await FF_Confirm.ask('Approve this bill? This will post a journal entry.'))) return;
            try {
                const fd = new FormData(); fd.append('id', id);
                const r = await fetch(FF_Api.url('/api/v1/accounting/bills/approve.php'), {method: 'POST', body: fd, headers: this._csrfHeaders()});
                const j = await r.json();
                if (j.success) this.load();
                else FF_Toast.error(j.error?.message || 'Approve failed.');
            } catch (e) { FF_Toast.error(e.message); }
        },

        async voidBill(id) {
            const reason = (await FF_Confirm.askText('Void reason:'));
            if (!reason) return;
            try {
                const fd = new FormData(); fd.append('id', id); fd.append('void_reason', reason);
                const r = await fetch(FF_Api.url('/api/v1/accounting/bills/void.php'), {method: 'POST', body: fd, headers: this._csrfHeaders()});
                const j = await r.json();
                if (j.success) this.load();
                else FF_Toast.error(j.error?.message || 'Void failed.');
            } catch (e) { FF_Toast.error(e.message); }
        },

        openPay(bill) {
            this.payForm = {
                vendor_id: bill.vendor_id, bill_id: bill.id, bill_number: bill.bill_number,
                balance_due: bill.balance_due, amount: bill.balance_due,
                payment_date: new Date().toISOString().slice(0,10), payment_method: 'check',
                bank_account_id: '<?= (int)($bankAccounts[0]['id'] ?? 1) ?>',
                check_number: '', reference_number: '',
            };
            this._clearErrors(this.payErrors, 'payFormError');
            this.showPayModal = true;
        },

        async submitPay() {
            if (!this.validatePay()) return;
            this.payProcessing = true;
            try {
                const fd = new FormData();
                fd.append('vendor_id', this.payForm.vendor_id);
                fd.append('bank_account_id', this.payForm.bank_account_id);
                fd.append('payment_date', this.payForm.payment_date);
                fd.append('payment_method', this.payForm.payment_method);
                fd.append('check_number', this.payForm.check_number || '');
                fd.append('allocations', JSON.stringify([{bill_id: this.payForm.bill_id, amount_applied: this.payForm.amount}]));
                const r = await fetch(FF_Api.url('/api/v1/accounting/ap-payments/create.php'), {method: 'POST', body: fd, headers: this._csrfHeaders()});
                const j = await r.json();
                if (j.success) {
                    this.showPayModal = false;
                    this._clearErrors(this.payErrors, 'payFormError');
                    this.load();
                } else {
                    this._paintErrors(this.payErrors, 'payFormError', j, 'Payment failed.');
                }
            } catch (e) { this.payFormError = 'Network error. Please try again.'; }
            this.payProcessing = false;
        },

        badgeClass(s) {
            return {draft:'badge-neutral',approved:'badge-info',scheduled:'badge-purple',
                    partially_paid:'badge-warning',paid:'badge-success',void:'badge-neutral',
                    overdue:'badge-danger'}[s] || 'badge-neutral';
        },
        fmtAmt(v) { const n = Number(v); return n ? '$' + n.toLocaleString('en-CA',{minimumFractionDigits:2}) : '—'; },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
