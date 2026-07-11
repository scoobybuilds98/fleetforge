<?php declare(strict_types=1);

/**
 * app/admin/accounting/recurring-entries/create.php
 *
 * Create-template form with header fields + lines builder. POSTs to
 * api/v1/accounting/recurring/create.php via fetch; on success redirects
 * to show.php for the new id.
 *
 * @session S037-REC
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'create');

$accounts = db_select(
    "SELECT id, code, name, account_type
       FROM acc_accounts
      WHERE is_active = 1 AND is_header = 0
      ORDER BY sort_order ASC, code ASC",
    []
);

$pageTitle = 'New Recurring Template';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/recurring-entries') ?>">Recurring Entries</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">New Template</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">New Recurring Template</h1>
    <div class="page-header-actions">
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/recurring-entries') ?>">← Back</a>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="recurringCreate()" style="max-width:1000px;">

    <!-- Header card -->
    <div class="card" style="padding:18px;margin-bottom:14px;">
        <div style="font-weight:600;font-size:0.95rem;margin-bottom:14px;">Template Details</div>

        <div x-show="error" x-cloak class="alert alert-danger" style="margin-bottom:12px;font-size:0.8125rem;" x-text="error"></div>

        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Name *</label>
                <input type="text" x-model="form.name" maxlength="255" class="form-input"
                       style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;"
                       placeholder="e.g. Monthly Rent Accrual">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Frequency *</label>
                <select x-model="form.frequency" class="form-input"
                        style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="annually">Annually</option>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Day of Month *</label>
                <input type="number" x-model.number="form.day_of_month" min="1" max="31" class="form-input font-mono"
                       style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                <div style="font-size:0.7rem;color:var(--text-secondary);margin-top:3px;">
                    Tip: use 1–28 to avoid month-end issues. Day 29–31 clamps to last day of shorter months.
                </div>
            </div>
        </div>

        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Description</label>
            <input type="text" x-model="form.description" maxlength="500" class="form-input"
                   style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;"
                   placeholder="Optional internal description">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Start Date *</label>
                <input type="date" x-model="form.start_date" class="form-input"
                       style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">End Date</label>
                <input type="date" x-model="form.end_date" class="form-input"
                       style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;"
                       placeholder="Leave blank for open-ended">
            </div>
            <div style="display:flex;align-items:end;">
                <label style="display:flex;gap:8px;align-items:center;font-size:0.8125rem;cursor:pointer;padding:8px 0;">
                    <input type="checkbox" x-model="form.auto_post">
                    Post automatically (else create draft for review)
                </label>
            </div>
        </div>
    </div>

    <!-- Lines builder -->
    <div class="card" style="padding:18px;margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:12px;">
            <div style="font-weight:600;font-size:0.95rem;">Journal Lines</div>
            <button class="btn btn-secondary btn-xs" @click="addLine()">+ Add Line</button>
        </div>
        <div style="overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.78rem;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-default);">
                        <th style="padding:6px 8px;text-align:left;width:100px;">Line</th>
                        <th style="padding:6px 8px;text-align:left;min-width:240px;">Account</th>
                        <th style="padding:6px 8px;text-align:left;">Description</th>
                        <th style="padding:6px 8px;text-align:right;width:120px;">Debit</th>
                        <th style="padding:6px 8px;text-align:right;width:120px;">Credit</th>
                        <th style="padding:6px 8px;text-align:center;width:50px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(l, i) in form.lines" :key="i">
                        <tr style="border-bottom:1px solid var(--border-default);">
                            <td class="font-mono" style="padding:4px 8px;" x-text="(i + 1)"></td>
                            <td style="padding:4px 4px;">
                                <select x-model.number="l.account_id" class="form-input"
                                        style="width:100%;padding:6px;border:1px solid var(--border-default);border-radius:3px;background:var(--bg-input);color:var(--text-primary);font-size:0.75rem;">
                                    <option value="0">— Select account —</option>
                                    <?php foreach ($accounts as $a): ?>
                                        <option value="<?= (int) $a['id'] ?>"><?= e($a['code'] . ' — ' . $a['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td style="padding:4px 4px;">
                                <input type="text" x-model="l.description" maxlength="500"
                                       style="width:100%;padding:6px;border:1px solid var(--border-default);border-radius:3px;background:var(--bg-input);color:var(--text-primary);font-size:0.75rem;">
                            </td>
                            <td style="padding:4px 4px;">
                                <input type="number" step="0.01" min="0" x-model.number="l.debit" @input="if (l.debit > 0) l.credit = 0"
                                       class="font-mono"
                                       style="width:100%;padding:6px;border:1px solid var(--border-default);border-radius:3px;background:var(--bg-input);color:var(--text-primary);font-size:0.75rem;text-align:right;">
                            </td>
                            <td style="padding:4px 4px;">
                                <input type="number" step="0.01" min="0" x-model.number="l.credit" @input="if (l.credit > 0) l.debit = 0"
                                       class="font-mono"
                                       style="width:100%;padding:6px;border:1px solid var(--border-default);border-radius:3px;background:var(--bg-input);color:var(--text-primary);font-size:0.75rem;text-align:right;">
                            </td>
                            <td style="padding:4px 4px;text-align:center;">
                                <button class="btn btn-ghost btn-xs" @click="removeLine(i)" :disabled="form.lines.length <= 2">✕</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid var(--border-default);background:var(--bg-elev);">
                        <td colspan="3" style="padding:7px 10px;text-align:right;font-weight:600;">Totals</td>
                        <td class="font-mono" style="padding:7px 10px;text-align:right;font-weight:700;" x-text="fmt(sumDr())"></td>
                        <td class="font-mono" style="padding:7px 10px;text-align:right;font-weight:700;" x-text="fmt(sumCr())"></td>
                        <td style="padding:7px 10px;text-align:center;font-weight:700;"
                            :style="balanced() ? 'color:var(--color-success);' : 'color:var(--color-danger);'"
                            x-text="balanced() ? '✓' : '✕'"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div x-show="!balanced()" x-cloak style="margin-top:8px;font-size:0.75rem;color:var(--color-danger);">
            Debits must equal credits before you can save.
        </div>
    </div>

    <!-- Save controls -->
    <div style="display:flex;gap:10px;margin-bottom:24px;">
        <button class="btn btn-success btn-sm" @click="submit()" :disabled="!canSubmit() || saving"
                x-text="saving ? 'Saving…' : 'Create Template'">Create Template</button>
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/recurring-entries') ?>">Cancel</a>
    </div>
</div>

<script>
function recurringCreate() {
    return {
        form: {
            name: '',
            description: '',
            frequency: 'monthly',
            day_of_month: 1,
            start_date: new Date().toISOString().slice(0, 10),
            end_date: '',
            auto_post: false,
            lines: [
                { account_id: 0, description: '', debit: 0, credit: 0 },
                { account_id: 0, description: '', debit: 0, credit: 0 },
            ],
        },
        saving: false,
        error: '',
        init() { // S-FORM-DRAFT-ROLLOUT
            if (window.FF_FormDraft) { // S-FORM-DRAFT-ROLLOUT
                this._draft = FF_FormDraft.attach({ // S-FORM-DRAFT-ROLLOUT
                    formId: 'recurring-entry-create', // S-FORM-DRAFT-ROLLOUT
                    entityId: 'new', // S-FORM-DRAFT-ROLLOUT
                    el: this.$root, // S-FORM-DRAFT-ROLLOUT
                    model: this.form, // S-FORM-DRAFT-ROLLOUT
                    version: '1', // S-FORM-DRAFT-ROLLOUT
                }); // S-FORM-DRAFT-ROLLOUT
            } // S-FORM-DRAFT-ROLLOUT
        }, // S-FORM-DRAFT-ROLLOUT
        sumDr() { return this.form.lines.reduce((a, l) => a + (parseFloat(l.debit)  || 0), 0); },
        sumCr() { return this.form.lines.reduce((a, l) => a + (parseFloat(l.credit) || 0), 0); },
        balanced() {
            const dr = this.sumDr(), cr = this.sumCr();
            return Math.abs(dr - cr) < 0.005 && dr > 0;
        },
        canSubmit() {
            if (!this.form.name.trim()) return false;
            if (!this.form.start_date) return false;
            if (this.form.day_of_month < 1 || this.form.day_of_month > 31) return false;
            if (!this.balanced()) return false;
            const allLinesValid = this.form.lines.every(l =>
                l.account_id > 0
                && ((parseFloat(l.debit) || 0) > 0) !== ((parseFloat(l.credit) || 0) > 0)
            );
            return allLinesValid;
        },
        addLine() {
            this.form.lines.push({ account_id: 0, description: '', debit: 0, credit: 0 });
        },
        removeLine(i) {
            if (this.form.lines.length <= 2) return;
            this.form.lines.splice(i, 1);
        },
        async submit() {
            if (!this.canSubmit()) return;
            this.saving = true; this.error = '';
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const payload = { ...this.form };
                payload.lines = payload.lines.map(l => ({
                    account_id: l.account_id,
                    description: l.description,
                    debit: (parseFloat(l.debit) || 0).toFixed(2),
                    credit: (parseFloat(l.credit) || 0).toFixed(2),
                }));
                if (!payload.end_date) delete payload.end_date;
                const r = await fetch('<?= e(base_url('api/v1/accounting/recurring/create.php')) ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify(payload)
                });
                const j = await r.json();
                if (j && j.success) {
                    if (this._draft) this._draft.clear(true); // S-FORM-DRAFT-ROLLOUT
                    window.location.href = '<?= e(base_url('accounting/recurring-entries/show')) ?>?id=' + j.data.id;
                } else {
                    this.error = (j && j.error && j.error.message) || 'Create failed.';
                }
            } catch (e) { this.error = 'Create failed: ' + e.message; }
            this.saving = false;
        },
        fmt(n) {
            return '$' + (n || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
