<?php
declare(strict_types=1);

/**
 * FleetForge — Edit Draft Invoice Line Items
 *
 * @file        app/admin/invoices/edit.php
 * @description Manual line-item editor for a DRAFT invoice (S-INVOICE-DRAFT-EDIT).
 *              Add / edit / remove lines; the server (api/v1/invoices/update_lines.php)
 *              replaces the lines and recomputes all totals authoritatively via
 *              InvoiceRecalc — the on-page total is a live ESTIMATE only.
 *              Draft-only: a sent/paid invoice is immutable (D14) and never
 *              reaches this page. Advance-batch invoices are excluded.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              api/v1/invoices/update_lines.php
 * @decisions   D14 (draft-only editability), D19 (optimistic lock)
 * @session     S-INVOICE-DRAFT-EDIT
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'edit');

$invoiceId = clean_int($_GET['id'] ?? null);
if (!$invoiceId) {
    header('Location: ' . base_url('invoices'));
    exit;
}

$invoice = db_row(
    "SELECT id, lease_id, invoice_number, status, generation_source, updated_at,
            currency, company_name_snapshot, customer_name_snapshot,
            tax_gst_rate, tax_pst_rate, tax_hst_rate,
            gst_exempt_snapshot, pst_exempt_snapshot,
            discount_type, discount_value, amount_paid, credits_applied
       FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$invoiceId]
);
if (!$invoice) {
    header('Location: ' . base_url('invoices'));
    exit;
}

// Draft-only. A sent/paid/void invoice is an issued record (D14) — bounce back
// to the read-only view. Advance-batch invoices are frozen at batch creation.
if ($invoice['status'] !== 'draft' || ($invoice['generation_source'] ?? '') === 'advance') {
    header('Location: ' . base_url('invoices/show') . '?id=' . $invoiceId);
    exit;
}

$lines = db_select(
    "SELECT item_type, description, quantity, unit, unit_price, amount, is_credit, taxable
       FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC",
    [$invoiceId]
);

// Curated line types for the picker (the schema enum is broader; these are the
// operator-meaningful ones for a hand-edited invoice).
$lineTypes = [
    'base_rental'       => 'Base rental',
    'mileage_estimate'  => 'Estimated mileage',
    'mileage_usage'     => 'Mileage',
    'hourly_usage'      => 'Engine/reefer hours',
    'insurance'         => 'Insurance',
    'warranty'          => 'Warranty',
    'gps'               => 'GPS',
    'cartage'           => 'Cartage / delivery',
    'sweep'             => 'Sweep',
    'wash'              => 'Wash',
    'fuel'              => 'Fuel',
    'late_fee'          => 'Late fee',
    'damage'            => 'Damage',
    'manual_adjustment' => 'Manual adjustment',
    'discount'          => 'Discount',
    'other'             => 'Other',
];

$pageTitle = 'Edit ' . $invoice['invoice_number'];
$helpModuleSlug = 'invoices';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="<?= base_url('invoices/show') ?>?id=<?= $invoiceId ?>"
           class="btn btn-ghost btn-sm" style="margin-bottom:0.5rem;">
            ← <?= e($invoice['invoice_number']) ?>
        </a>
        <h1 class="page-header-title h4">Edit line items — <?= e($invoice['invoice_number']) ?></h1>
        <div class="text-secondary text-sm">
            <?= e($invoice['company_name_snapshot'] ?: $invoice['customer_name_snapshot'] ?: 'Customer') ?>
            &nbsp;·&nbsp; Draft
        </div>
    </div>
</div>

<div class="card card-body" style="background:var(--color-warning-light);color:var(--color-warning-text);margin-bottom:1.5rem;">
    <strong>⚠️ You're editing a draft invoice directly.</strong>
    Changes here apply to this invoice only — they don't change the lease or any other invoice.
    Totals + tax are recomputed by the server from these lines (the figure below is a live estimate);
    tax uses this invoice's frozen rates. Only <strong>draft</strong> invoices are editable — once sent, an
    invoice is locked and corrections go through a credit note.
</div>

<div x-data="FF_InvoiceLineEditor()" x-init="init()">
    <form @submit.prevent="save()" novalidate>
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <div class="card-title">Line Items</div>
                <button type="button" class="btn btn-secondary btn-sm" @click="addLine()">+ Add line</button>
            </div>
            <div class="card-body">
                <div class="form-error-banner" data-form-error></div>

                <div style="overflow-x:auto;">
                <table class="table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="min-width:150px;">Type</th>
                            <th style="min-width:220px;">Description</th>
                            <th style="width:90px;text-align:right;">Qty</th>
                            <th style="width:120px;text-align:right;">Unit price</th>
                            <th style="width:130px;text-align:right;">Amount</th>
                            <th style="width:64px;text-align:center;" title="Taxable">Tax</th>
                            <th style="width:70px;text-align:center;" title="Credit (reduces the invoice)">Credit</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(ln, i) in lines" :key="ln._k">
                            <tr>
                                <td>
                                    <select class="form-control form-control-sm" x-model="ln.item_type">
                                        <?php foreach ($lineTypes as $val => $label): ?>
                                        <option value="<?= e($val) ?>"><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="text" class="form-control form-control-sm" x-model="ln.description" maxlength="500" placeholder="Description"></td>
                                <td><input type="number" class="form-control form-control-sm font-mono" style="text-align:right;" x-model="ln.quantity" step="0.0001" min="0" @input="syncAmount(ln)"></td>
                                <td><input type="number" class="form-control form-control-sm font-mono" style="text-align:right;" x-model="ln.unit_price" step="0.01" min="0" @input="syncAmount(ln)"></td>
                                <td><input type="number" class="form-control form-control-sm font-mono" style="text-align:right;" x-model="ln.amount" step="0.01" min="0"></td>
                                <td style="text-align:center;"><input type="checkbox" class="form-check-input" x-model="ln.taxable"></td>
                                <td style="text-align:center;"><input type="checkbox" class="form-check-input" x-model="ln.is_credit"></td>
                                <td style="text-align:center;">
                                    <button type="button" class="btn btn-outline-danger btn-xs" @click="removeLine(i)" :disabled="lines.length <= 1" title="Remove line">✕</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>

                <div style="display:flex;justify-content:flex-end;margin-top:1rem;">
                    <table style="min-width:260px;font-size:0.9rem;">
                        <tr><td class="text-secondary" style="padding:2px 12px 2px 0;">Subtotal</td><td class="font-mono" style="text-align:right;">$<span x-text="fmt(estSubtotal)"></span></td></tr>
                        <tr><td class="text-secondary" style="padding:2px 12px 2px 0;">Tax (est.)</td><td class="font-mono" style="text-align:right;">$<span x-text="fmt(estTax)"></span></td></tr>
                        <tr style="font-weight:600;border-top:1px solid var(--border-color);"><td style="padding:4px 12px 2px 0;">Estimated total</td><td class="font-mono" style="text-align:right;">$<span x-text="fmt(estTotal)"></span></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="d-flex gap-3" style="justify-content:flex-end;margin-bottom:2rem;">
            <a href="<?= base_url('invoices/show') ?>?id=<?= $invoiceId ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" :disabled="saving">
                <span x-show="!saving">Save Line Items</span>
                <span x-show="saving">Saving…</span>
            </button>
        </div>
    </form>
</div>

<script>
function FF_InvoiceLineEditor() {
    return {
        // Server-rendered seed values.
        invoiceId:  <?= (int)$invoiceId ?>,
        updatedAt:  <?= json_encode($invoice['updated_at']) ?>,
        gstRate:    <?= (float)($invoice['tax_gst_rate'] ?? 0) ?>,
        pstRate:    <?= (float)($invoice['tax_pst_rate'] ?? 0) ?>,
        hstRate:    <?= (float)($invoice['tax_hst_rate'] ?? 0) ?>,
        gstExempt:  <?= !empty($invoice['gst_exempt_snapshot']) ? 'true' : 'false' ?>,
        pstExempt:  <?= !empty($invoice['pst_exempt_snapshot']) ? 'true' : 'false' ?>,
        lines:      [],
        saving:     false,
        _seq:       0,

        init() {
            const seed = <?= json_encode(array_map(static function ($l) {
                return [
                    'item_type'  => $l['item_type'],
                    'description'=> $l['description'],
                    'quantity'   => (string)$l['quantity'],
                    'unit_price' => (string)$l['unit_price'],
                    'amount'     => (string)$l['amount'],
                    'is_credit'  => (bool)$l['is_credit'],
                    'taxable'    => (bool)$l['taxable'],
                ];
            }, $lines), JSON_UNESCAPED_UNICODE) ?>;
            this.lines = seed.map((l) => ({ ...l, _k: ++this._seq }));
            if (this.lines.length === 0) this.addLine();
        },

        addLine() {
            this.lines.push({
                item_type: 'manual_adjustment', description: '', quantity: '1',
                unit_price: '0.00', amount: '0.00', is_credit: false, taxable: true,
                _k: ++this._seq,
            });
        },
        removeLine(i) { if (this.lines.length > 1) this.lines.splice(i, 1); },

        // When qty or unit price changes, derive amount = qty × unit price.
        syncAmount(ln) {
            const q = parseFloat(ln.quantity);
            const p = parseFloat(ln.unit_price);
            if (!isNaN(q) && !isNaN(p)) ln.amount = (Math.round(q * p * 100) / 100).toFixed(2);
        },

        // ── Live estimate (server is authoritative on save) ──
        get estSubtotal() {
            return this.lines.reduce((s, ln) => {
                const a = parseFloat(ln.amount) || 0;
                return s + (ln.is_credit ? -a : a);
            }, 0);
        },
        get estTax() {
            const base = this.lines.reduce((s, ln) => {
                if (!ln.taxable) return s;
                const a = parseFloat(ln.amount) || 0;
                return s + (ln.is_credit ? -a : a);
            }, 0);
            let t = 0;
            if (!this.gstExempt) t += base * this.gstRate + base * this.hstRate;
            if (!this.pstExempt) t += base * this.pstRate;
            return Math.round(t * 100) / 100;
        },
        get estTotal() { return Math.round((this.estSubtotal + this.estTax) * 100) / 100; },
        fmt(n) { return (Math.round((n || 0) * 100) / 100).toFixed(2); },

        async save() {
            // Light client validation — the server re-validates authoritatively.
            for (const ln of this.lines) {
                if (!ln.description || !ln.description.trim()) {
                    FF_Validate.banner(document.querySelector('form'), 'Every line needs a description.');
                    return;
                }
                if (isNaN(parseFloat(ln.amount))) {
                    FF_Validate.banner(document.querySelector('form'), 'Every line needs a numeric amount.');
                    return;
                }
            }
            this.saving = true;
            const payload = {
                id: this.invoiceId,
                updated_at: this.updatedAt,
                lines: this.lines.map((ln) => ({
                    item_type: ln.item_type,
                    description: ln.description,
                    quantity: ln.quantity,
                    unit_price: ln.unit_price,
                    amount: ln.amount,
                    is_credit: ln.is_credit ? 1 : 0,
                    taxable: ln.taxable ? 1 : 0,
                })),
            };
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/update_lines') ?>', payload);
                if (r.success) {
                    window.location.href = '<?= base_url('invoices/show') ?>?id=' + this.invoiceId;
                } else {
                    FF_Validate.banner(document.querySelector('form'),
                        (r.error && r.error.message) || 'Failed to save line items.');
                    this.saving = false;
                }
            } catch (e) {
                FF_Validate.banner(document.querySelector('form'), 'Network error. Please try again.');
                this.saving = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
