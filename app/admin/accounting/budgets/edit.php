<?php declare(strict_types=1);

/**
 * app/admin/accounting/budgets/edit.php
 *
 * Header-only editor for an existing budget (name + version + notes).
 * Line items are edited on show.php inline. Both pages share the
 * same update.php API.
 *
 * @session S036
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'edit');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Budget Not Specified</h1>';
    exit;
}

$budget = db_row("SELECT * FROM acc_budgets WHERE id = ?", [$id]);
if (!$budget) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Budget Not Found</h1>';
    exit;
}

if ($budget['status'] === 'archived') {
    http_response_code(403);
    echo '<h1>403 — Archived budgets cannot be edited.</h1>';
    exit;
}

$pageTitle = 'Edit Budget ' . $budget['name'];
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/budgets') ?>">Budgets</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/budgets/show?id=' . (int) $id) ?>"><?= e($budget['name']) ?></a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Edit</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Edit Budget Header</h1>
    <div class="page-header-actions">
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/budgets/show?id=' . (int) $id) ?>">← Back</a>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="budgetEdit()" style="max-width:600px;">
    <div class="card" style="padding:18px;">
        <div x-show="error" x-cloak style="margin-bottom:12px;padding:8px 10px;background:#ffe6e6;border:1px solid #cc0000;color:#990000;border-radius:4px;font-size:0.8125rem;" x-text="error"></div>
        <div x-show="success" x-cloak style="margin-bottom:12px;padding:8px 10px;background:#e6ffe6;border:1px solid #008800;color:#005500;border-radius:4px;font-size:0.8125rem;" x-text="success"></div>

        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Name</label>
            <input type="text" x-model="form.name" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
        </div>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Version</label>
            <select x-model="form.version" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                <option value="base">Base</option>
                <option value="conservative">Conservative</option>
                <option value="optimistic">Optimistic</option>
            </select>
        </div>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Notes</label>
            <textarea x-model="form.notes" class="form-input" style="width:100%;padding:8px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;min-height:80px;"></textarea>
        </div>
        <div style="display:flex;gap:10px;">
            <button class="btn btn-primary btn-sm" @click="submit()" :disabled="saving" x-text="saving ? 'Saving…' : 'Save Header'">Save Header</button>
            <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/budgets/show?id=' . (int) $id) ?>">Cancel</a>
        </div>
    </div>
</div>

<script>
function budgetEdit() {
    return {
        form: {
            id: <?= (int) $id ?>,
            updated_at: <?= htmlspecialchars(json_encode($budget['updated_at']), ENT_QUOTES) ?>,
            name: <?= htmlspecialchars(json_encode($budget['name']), ENT_QUOTES) ?>,
            version: <?= htmlspecialchars(json_encode($budget['version']), ENT_QUOTES) ?>,
            notes: <?= htmlspecialchars(json_encode($budget['notes'] ?? ''), ENT_QUOTES) ?>
        },
        saving: false,
        error: '',
        success: '',
        async submit() {
            this.saving = true; this.error = ''; this.success = '';
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const r = await fetch('<?= e(base_url('api/v1/accounting/budgets/update.php')) ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify(this.form)
                });
                const j = await r.json();
                if (j && j.success) {
                    this.form.updated_at = j.data.updated_at;
                    this.success = 'Saved.';
                } else {
                    this.error = (j && j.error && j.error.message) || 'Save failed.';
                }
            } catch (e) { this.error = 'Save failed: ' + e.message; }
            this.saving = false;
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
