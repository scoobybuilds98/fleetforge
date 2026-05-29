<?php declare(strict_types=1);

/**
 * app/admin/accounting/fixed-assets/show.php
 *
 * Fixed asset detail view — read-only drill-down for a single
 * acc_fixed_assets row with summary + Documents section.
 *
 * The existing index.php carries a deep Alpine modal with full
 * edit + dispose + depreciation history; this page is the dedicated
 * entity surface with a stable URL needed for deep-linking +
 * documents drill-down per FLEETFORGE_ACCOUNTING_SPEC.md §13 + §20.3.
 *
 * Edit / dispose / depreciate actions stay in index.php — this is view-only.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, includes/partials/accounting-nav.php,
 *          includes/partials/acc-documents-section.php
 * @session S-ACCT-FIX-DOCS
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('fixed_assets', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Asset Not Specified</h1>';
    exit;
}

$asset = db_row(
    "SELECT fa.*, v.name AS vendor_name,
            eu.unit_number AS equipment_unit_number,
            u.name AS created_by_name
       FROM acc_fixed_assets fa
  LEFT JOIN vendors v ON v.id = fa.vendor_id
  LEFT JOIN equipment_units eu ON eu.id = fa.equipment_unit_id
  LEFT JOIN users u ON u.id = fa.created_by
      WHERE fa.id = ?",
    [$id]
);

if (!$asset) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Asset Not Found</h1>';
    exit;
}

$statusBadgeClass = static function (?string $status): string {
    return match ($status) {
        'active'        => 'badge-green',
        'fully_depreciated' => 'badge-amber',
        'disposed'      => 'badge-red',
        'impaired'      => 'badge-amber',
        default         => 'badge-neutral',
    };
};

// S-ACCT-COMP: pre-fetch component children + parent context for display.
$components    = [];
$totalNbv      = (string) $asset['net_book_value'];
$parentSummary = null;
if ((int) ($asset['is_component'] ?? 0) === 0) {
    $components = db_select(
        "SELECT id, asset_number, name, asset_class, acquisition_cost,
                accumulated_depreciation, net_book_value, useful_life_years,
                depreciation_method, status
           FROM acc_fixed_assets
          WHERE parent_asset_id = ?
          ORDER BY id ASC",
        [(int) $asset['id']]
    );
    foreach ($components as $c) {
        $totalNbv = bcadd($totalNbv, (string) $c['net_book_value'], 2);
    }
} elseif (!empty($asset['parent_asset_id'])) {
    $parentSummary = db_row(
        "SELECT id, asset_number, name FROM acc_fixed_assets WHERE id = ?",
        [(int) $asset['parent_asset_id']]
    );
}

$pageTitle = 'Asset ' . $asset['asset_number'];
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/fixed-assets') ?>">Fixed Assets</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e($asset['asset_number']) ?></span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">
        <?= e($asset['asset_number']) ?>
        <span style="font-weight:400;color:var(--text-secondary);font-size:0.9rem;margin-left:6px;"><?= e($asset['name']) ?></span>
        <?php if (!empty($asset['status'])): ?>
            <span class="badge <?= e($statusBadgeClass((string) $asset['status'])) ?>" style="margin-left:8px;font-size:0.7rem;vertical-align:middle;"><?= e(str_replace('_', ' ', $asset['status'])) ?></span>
        <?php endif; ?>
    </h1>
    <div class="page-header-actions">
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/fixed-assets') ?>">← Back to list</a>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<?php if ($parentSummary): ?>
<div class="card" style="padding:12px 16px;margin-bottom:14px;background:#eef2ff;border:1px solid #6366f1;color:#3730a3;font-size:0.8125rem;">
    <strong>Part of:</strong>
    <a href="<?= base_url('accounting/fixed-assets/show?id=' . (int) $parentSummary['id']) ?>" style="color:#3730a3;text-decoration:underline;font-family:var(--font-mono);">
        <?= e($parentSummary['asset_number']) ?>
    </a>
    — <?= e($parentSummary['name']) ?>
    (this asset is a component, ASPE 3061.18)
</div>
<?php endif; ?>

<div class="card" style="padding:18px;margin-bottom:14px;">
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Asset Class</div>
            <div style="text-transform:capitalize;"><?= e(str_replace('_', ' ', $asset['asset_class'])) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Acquisition Date</div>
            <div class="font-mono"><?= e($asset['acquisition_date']) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Acquisition Cost</div>
            <div class="font-mono" style="font-size:1.05rem;font-weight:700;"><?= e('$' . number_format((float) $asset['acquisition_cost'], 2)) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Salvage Value</div>
            <div class="font-mono"><?= e('$' . number_format((float) $asset['salvage_value'], 2)) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Accumulated Depreciation</div>
            <div class="font-mono"><?= e('$' . number_format((float) $asset['accumulated_depreciation'], 2)) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Net Book Value</div>
            <div class="font-mono" style="font-weight:600;"><?= e('$' . number_format((float) $asset['net_book_value'], 2)) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Depreciation Method</div>
            <div style="text-transform:capitalize;"><?= e(str_replace('_', ' ', $asset['depreciation_method'])) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Useful Life (Years)</div>
            <div class="font-mono"><?= $asset['useful_life_years'] !== null ? e((string) $asset['useful_life_years']) : '<span style="color:var(--text-secondary);">—</span>' ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">CRA Class (legacy text)</div>
            <div class="font-mono"><?= $asset['cra_class'] ? e($asset['cra_class']) : '<span style="color:var(--text-secondary);">—</span>' ?></div>
        </div>
        <?php
        // S-ACCT-CCA-1: resolve CCA class label from the FK if assigned.
        $ccaClassLabel = null;
        if (!empty($asset['cca_class_id'])) {
            $ccaRow = db_row("SELECT class_number, description, rate FROM acc_cca_classes WHERE id = ?", [(int) $asset['cca_class_id']]);
            if ($ccaRow) {
                $ccaClassLabel = 'Class ' . $ccaRow['class_number']
                    . ' — ' . number_format(((float) $ccaRow['rate']) * 100, 0) . '% '
                    . $ccaRow['description'];
            }
        }
        ?>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">CCA Class (Schedule 8)</div>
            <div><?= $ccaClassLabel ? e($ccaClassLabel) : '<span style="color:var(--text-secondary);">— not assigned — <a href="' . e(base_url('accounting/fixed-assets')) . '?edit=' . (int) $asset['id'] . '">assign</a></span>' ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Available for Use Date</div>
            <div class="font-mono"><?= !empty($asset['available_for_use_date']) ? e($asset['available_for_use_date']) : '<span style="color:var(--text-secondary);">— (uses acquisition date)</span>' ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">AIIP Eligible</div>
            <div><?= ((int) ($asset['is_aiip_eligible'] ?? 1)) === 1 ? '<span class="badge badge-success" style="padding:2px 8px;font-size:0.6875rem;">Yes</span>' : '<span class="badge badge-neutral" style="padding:2px 8px;font-size:0.6875rem;">No</span>' ?></div>
        </div>
        <?php if (!empty($asset['equipment_unit_id'])): ?>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Linked Equipment</div>
            <div><a href="<?= base_url('equipment/show?id=' . (int) $asset['equipment_unit_id']) ?>" style="color:var(--color-accent);text-decoration:none;font-family:var(--font-mono);"><?= e((string) ($asset['equipment_unit_number'] ?? '#' . $asset['equipment_unit_id'])) ?></a></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($asset['vendor_id']) && !empty($asset['vendor_name'])): ?>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Acquired From</div>
            <div><?= e((string) $asset['vendor_name']) ?></div>
        </div>
        <?php endif; ?>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Created</div>
            <div><?= e($asset['created_by_name'] ?? 'system') ?> <span style="font-size:0.75rem;color:var(--text-secondary);">— <?= e($asset['created_at']) ?></span></div>
        </div>
    </div>
    <?php if (!empty($asset['description'])): ?>
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-default);">
        <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:4px;">Description</div>
        <div style="white-space:pre-wrap;font-size:0.875rem;"><?= e($asset['description']) ?></div>
    </div>
    <?php endif; ?>
</div>

<?php if ((int) ($asset['is_component'] ?? 0) === 0): ?>
<!-- ── Components (S-ACCT-COMP — ASPE 3061.18) ─────────────────────────── -->
<div class="card" style="padding:18px;margin-bottom:14px;" x-data="componentsPanel(<?= (int) $asset['id'] ?>)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <div>
            <h3 style="margin:0;font-size:0.95rem;font-weight:600;">Components</h3>
            <div style="font-size:0.75rem;color:var(--text-secondary);">
                ASPE 3061.18 — significant components with different useful lives depreciate separately.
            </div>
        </div>
        <button class="btn btn-primary btn-sm" @click="openModal()">+ Add Component</button>
    </div>

    <?php if (empty($components)): ?>
    <div style="padding:18px;text-align:center;color:var(--text-secondary);font-size:0.8125rem;border:1px dashed var(--border-default);border-radius:6px;">
        No components yet. Add a component (e.g. reefer unit, engine, cab) to depreciate it separately from the parent.
    </div>
    <?php else: ?>
    <table class="table" style="font-size:0.8125rem;margin-bottom:8px;">
        <thead>
            <tr>
                <th>Asset #</th>
                <th>Name</th>
                <th>Class</th>
                <th class="text-right">Cost</th>
                <th class="text-right">Accum Depr</th>
                <th class="text-right">NBV</th>
                <th>Life (yr)</th>
                <th>Method</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($components as $c): ?>
            <tr>
                <td>
                    <a class="font-mono" style="color:var(--color-accent);text-decoration:none;"
                       href="<?= base_url('accounting/fixed-assets/show?id=' . (int) $c['id']) ?>"><?= e($c['asset_number']) ?></a>
                </td>
                <td><?= e($c['name']) ?></td>
                <td style="text-transform:capitalize;font-size:0.75rem;"><?= e(str_replace('_', ' ', $c['asset_class'])) ?></td>
                <td class="font-mono text-right"><?= e('$' . number_format((float) $c['acquisition_cost'], 2)) ?></td>
                <td class="font-mono text-right"><?= e('$' . number_format((float) $c['accumulated_depreciation'], 2)) ?></td>
                <td class="font-mono text-right" style="font-weight:600;"><?= e('$' . number_format((float) $c['net_book_value'], 2)) ?></td>
                <td class="font-mono"><?= e((string) ($c['useful_life_years'] ?? '—')) ?></td>
                <td style="text-transform:capitalize;font-size:0.75rem;"><?= e(str_replace('_', ' ', (string) $c['depreciation_method'])) ?></td>
                <td><span class="badge <?= e($statusBadgeClass((string) $c['status'])) ?>" style="padding:2px 8px;font-size:0.6875rem;text-transform:capitalize;"><?= e(str_replace('_', ' ', (string) $c['status'])) ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight:600;border-top:2px solid var(--border-default);">
                <td colspan="5" class="text-right">Total NBV (parent + components)</td>
                <td class="font-mono text-right"><?= e('$' . number_format((float) $totalNbv, 2)) ?></td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <!-- Add Component modal -->
    <div x-show="modal.open" x-cloak class="modal-backdrop" @click.self="modal.open = false"
         style="position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:1000;">
        <div class="card" style="padding:24px;width:min(640px,95vw);max-height:90vh;overflow:auto;">
            <h3 style="margin-top:0;font-size:1rem;font-weight:600;">Add Component to <?= e($asset['asset_number']) ?></h3>
            <p style="font-size:0.75rem;color:var(--text-secondary);margin:0 0 12px;">
                The component depreciates independently — set its own useful life, method, and salvage value.
            </p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div style="grid-column:span 2;">
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;">Name *</label>
                    <input type="text" x-model="form.name" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;">
                </div>
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;">Asset Class *</label>
                    <select x-model="form.asset_class" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;">
                        <option value="fleet_equipment">Fleet Equipment</option>
                        <option value="vehicles">Vehicles</option>
                        <option value="office_equipment">Office Equipment</option>
                        <option value="leasehold_improvements">Leasehold Improvements</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;">Acquisition Cost *</label>
                    <input type="number" step="0.01" min="0.01" x-model="form.acquisition_cost" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;font-family:var(--font-mono);">
                </div>
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;">Acquisition Date *</label>
                    <input type="date" x-model="form.acquisition_date" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;">
                </div>
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;">Useful Life (years)</label>
                    <input type="number" step="0.5" min="0.5" x-model="form.useful_life_years" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;font-family:var(--font-mono);">
                </div>
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;">Depreciation Method</label>
                    <select x-model="form.depreciation_method" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;">
                        <option value="straight_line">Straight Line</option>
                        <option value="declining_balance">Declining Balance</option>
                        <option value="units_of_production">Units of Production</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;">Salvage Value</label>
                    <input type="number" step="0.01" min="0" x-model="form.salvage_value" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;font-family:var(--font-mono);">
                </div>
                <?php
                $pickerId       = 'show_asset_account_id';
                $pickerLabel    = 'Asset GL Acct';
                $pickerRequired = true;
                $pickerPlaceholder = 'Search code or name…';
                $pickerLabelHint = '';
                $pickerConfig = <<<JS
{
    endpoint: '/api/v1/accounting/accounts/index.php',
    extraParams: { flat: 1, active: 1 },
    format: r => (r.code ? r.code + ' — ' : '') + (r.name || ''),
    initialId: '',
    targetPath: 'form.asset_account_id'
}
JS;
                include __DIR__ . '/../../../../includes/partials/pickers/lookup_picker.php';
                ?>
                <?php
                $pickerId       = 'show_accum_depr_account_id';
                $pickerLabel    = 'Accum Depr Acct';
                $pickerConfig = <<<JS
{
    endpoint: '/api/v1/accounting/accounts/index.php',
    extraParams: { flat: 1, active: 1 },
    format: r => (r.code ? r.code + ' — ' : '') + (r.name || ''),
    initialId: '',
    targetPath: 'form.accum_depr_account_id'
}
JS;
                include __DIR__ . '/../../../../includes/partials/pickers/lookup_picker.php';
                ?>
                <?php
                $pickerId       = 'show_depr_expense_account_id';
                $pickerLabel    = 'Depr Expense Acct';
                $pickerConfig = <<<JS
{
    endpoint: '/api/v1/accounting/accounts/index.php',
    extraParams: { flat: 1, active: 1 },
    format: r => (r.code ? r.code + ' — ' : '') + (r.name || ''),
    initialId: '',
    targetPath: 'form.depr_expense_account_id'
}
JS;
                include __DIR__ . '/../../../../includes/partials/pickers/lookup_picker.php';
                ?>
                <div style="grid-column:span 2;">
                    <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;">Notes</label>
                    <textarea x-model="form.notes" rows="2" class="form-input" style="width:100%;padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;"></textarea>
                </div>
                <p x-show="modal.error" x-cloak style="grid-column:span 2;font-size:0.75rem;color:var(--color-danger);margin:0;" x-text="modal.error"></p>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
                <button class="btn btn-ghost" @click="modal.open = false">Cancel</button>
                <button class="btn btn-primary" :disabled="modal.saving" @click="save()">
                    <span x-show="!modal.saving">Add Component</span>
                    <span x-show="modal.saving">Saving...</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function componentsPanel(parentId) {
    return {
        parentId: parentId,
        modal: { open: false, saving: false, error: null },
        form: {
            name: '', asset_class: 'fleet_equipment',
            acquisition_cost: '', acquisition_date: new Date().toISOString().slice(0,10),
            useful_life_years: '5', depreciation_method: 'straight_line', salvage_value: '0',
            asset_account_id: '', accum_depr_account_id: '', depr_expense_account_id: '',
            notes: '',
        },
        openModal() {
            this.modal.open = true; this.modal.error = null;
        },
        async save() {
            this.modal.saving = true; this.modal.error = null;
            try {
                const payload = Object.assign({ parent_asset_id: this.parentId }, this.form);
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/fixed_assets/add_component') ?>', payload);
                if (r.success) {
                    FF_Toast.success('Component added.');
                    this.modal.open = false;
                    window.location.reload();
                } else {
                    this.modal.error = (r.error && (r.error.message || JSON.stringify(r.error.fields || {}))) || 'Save failed.';
                }
            } catch (e) { this.modal.error = 'Network error.'; }
            this.modal.saving = false;
        },
    };
}
</script>
<?php endif; ?>

<!-- ── Documents ───────────────────────────────────────────────────────── -->
<?php
$entityType = 'asset';
$entityId   = (int) $asset['id'];
require FF_ROOT . '/includes/partials/acc-documents-section.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
