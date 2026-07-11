<?php declare(strict_types=1);

/**
 * app/admin/accounting/budgets/create.php
 *
 * Create-budget form. POSTs to api/v1/accounting/budgets/create.php
 * via fetch; on success redirects to show.php for the new id.
 *
 * @session S036
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'create');

$pageTitle = 'New Budget';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/budgets') ?>">Budgets</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">New Budget</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">New Budget</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="budgetCreate()" style="max-width:600px;">
    <div class="card" style="padding:18px;">
        <div x-show="error" x-cloak class="alert alert-danger" style="margin-bottom:12px;font-size:0.8125rem;" x-text="error"></div>

        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Name *</label>
            <input type="text" x-model="form.name" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;" placeholder="e.g. 2027 Operating Budget">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Fiscal Year *</label>
                <input type="number" x-model.number="form.year" min="2000" max="2100" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Version</label>
                <select x-model="form.version" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                    <option value="base">Base</option>
                    <option value="conservative">Conservative</option>
                    <option value="optimistic">Optimistic</option>
                </select>
            </div>
        </div>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Notes</label>
            <textarea x-model="form.notes" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;min-height:60px;" placeholder="Optional notes"></textarea>
        </div>
        <div style="margin-bottom:18px;">
            <label style="display:flex;gap:8px;align-items:center;font-size:0.8125rem;cursor:pointer;">
                <input type="checkbox" x-model="form.copy_prior_year">
                Copy line items from the most recent prior-year budget
            </label>
        </div>
        <div style="display:flex;gap:10px;">
            <button class="btn btn-primary btn-sm" @click="submit()" :disabled="saving" x-text="saving ? 'Saving…' : 'Create Budget'">Create Budget</button>
            <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/budgets') ?>">Cancel</a>
        </div>
    </div>
</div>

<script>
function budgetCreate() {
    return {
        form: { name: '', year: <?= (int) date('Y') + 1 ?>, version: 'base', notes: '', copy_prior_year: false },
        saving: false,
        error: '',
        init() { // S-FORM-DRAFT-ROLLOUT
            if (window.FF_FormDraft) { // S-FORM-DRAFT-ROLLOUT
                this._draft = FF_FormDraft.attach({ // S-FORM-DRAFT-ROLLOUT
                    formId: 'budget-create', // S-FORM-DRAFT-ROLLOUT
                    entityId: 'new', // S-FORM-DRAFT-ROLLOUT
                    el: this.$root, // S-FORM-DRAFT-ROLLOUT
                    model: this.form, // S-FORM-DRAFT-ROLLOUT
                    version: '1', // S-FORM-DRAFT-ROLLOUT
                }); // S-FORM-DRAFT-ROLLOUT
            } // S-FORM-DRAFT-ROLLOUT
        }, // S-FORM-DRAFT-ROLLOUT
        async submit() {
            this.saving = true; this.error = '';
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const r = await fetch('<?= e(base_url('api/v1/accounting/budgets/create.php')) ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify(this.form)
                });
                const j = await r.json();
                if (j && j.success) {
                    if (this._draft) this._draft.clear(true); // S-FORM-DRAFT-ROLLOUT
                    window.location.href = '<?= e(base_url('accounting/budgets/show')) ?>?id=' + j.data.id;
                } else {
                    this.error = (j && j.error && j.error.message) || 'Create failed.';
                }
            } catch (e) { this.error = 'Create failed: ' + e.message; }
            this.saving = false;
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
