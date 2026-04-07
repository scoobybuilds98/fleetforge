<?php declare(strict_types=1);

/**
 * app/admin/accounting/statements/index.php
 *
 * Customer statement generation page — select customer, date range,
 * and generate/download PDF statement.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @session S031
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$customers = db_select(
    "SELECT id, company_name, outstanding_balance
     FROM customers WHERE deleted_at IS NULL AND status = 'active'
     ORDER BY company_name",
    []
);

$pageTitle = 'Customer Statements';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Statements</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Customer Statements</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="statementsPage()">

    <div class="card" style="padding:20px;margin-bottom:20px;">
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:14px;align-items:end;">
            <div>
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:4px;">Customer</label>
                <select x-model="customerId" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                    <option value="">Select customer...</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= e($c['company_name']) ?> (<?= format_currency($c['outstanding_balance']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:4px;">From</label>
                <input type="date" x-model="dateFrom" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
            </div>
            <div>
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:4px;">To</label>
                <input type="date" x-model="dateTo" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
            </div>
            <div>
                <button class="btn btn-primary btn-md" @click="generate()" :disabled="!customerId || busy" style="white-space:nowrap;">
                    <span x-show="!busy">Generate PDF</span>
                    <span x-show="busy">Generating...</span>
                </button>
            </div>
        </div>
    </div>

    <template x-if="error">
        <div class="card" style="padding:16px;background:var(--badge-danger-bg);border:1px solid var(--color-danger);border-radius:8px;">
            <div style="color:var(--badge-danger-text);font-size:0.875rem;" x-text="error"></div>
        </div>
    </template>

    <div class="card" style="padding:48px;text-align:center;" x-show="!customerId">
        <div style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:4px;">Generate a Statement</div>
        <div style="font-size:0.8125rem;color:var(--text-secondary);">Select a customer and date range, then click Generate PDF to download their account statement.</div>
    </div>
</div>

<script>
function statementsPage() {
    const now = new Date();
    const threeMonthsAgo = new Date(now.getFullYear(), now.getMonth() - 3, 1);
    return {
        customerId: '',
        dateFrom: threeMonthsAgo.toISOString().slice(0, 10),
        dateTo: now.toISOString().slice(0, 10),
        busy: false,
        error: null,

        async generate() {
            if (!this.customerId) return;
            this.busy = true;
            this.error = null;
            try {
                const params = new URLSearchParams({
                    customer_id: this.customerId,
                    date_from: this.dateFrom,
                    date_to: this.dateTo,
                });
                const url = FF_Api.url('/api/v1/accounting/ar/statement.php?' + params);
                const resp = await fetch(url);
                if (!resp.ok) {
                    const j = await resp.json().catch(() => ({}));
                    this.error = j.error?.message || j.message || 'Failed to generate statement.';
                    this.busy = false;
                    return;
                }
                const blob = await resp.blob();
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = 'statement_' + this.customerId + '_' + this.dateTo + '.pdf';
                link.click();
                URL.revokeObjectURL(link.href);
                FF_Toast.success('Statement generated.');
            } catch (e) {
                this.error = 'Error generating statement: ' + e.message;
            }
            this.busy = false;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
