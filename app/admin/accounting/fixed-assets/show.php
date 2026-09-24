<?php declare(strict_types=1);

/**
 * app/admin/accounting/fixed-assets/show.php
 *
 * Fixed asset detail view — read-only drill-down for a single
 * acc_fixed_assets row with summary + Components + Documents section.
 *
 * The existing index.php carries a deep Alpine modal with full
 * edit + dispose + depreciation history; this page is the dedicated
 * entity surface with a stable URL needed for deep-linking +
 * documents drill-down per FLEETFORGE_ACCOUNTING_SPEC.md §13 + §20.3.
 *
 * Edit / dispose / depreciate actions stay in index.php — this is view-only
 * (the one write here is "+ Add Component", S-ACCT-COMP).
 *
 * Layout (S-RECORD-REDESIGN):
 *   header  — ModuleHero entity: asset name + status / component badges;
 *             asset # · class · acquired · linked unit chips; "Unit payoff"
 *             when the asset is a fleet unit
 *   nav     — the accounting sub-nav, directly under the header
 *   strip   — cost · accumulated depreciation · net book value · months
 *             in service · last depreciated
 *   main    — Asset details (valuation, depreciation, tax) · Components
 *             (+ Add Component modal) · Documents
 *   rail    — Needs attention (depreciation behind, no CCA class, missing GL
 *             accounts, fully depreciated, disposed/impaired) · Depreciation
 *             meter · Related (unit, parent asset, vendor, acquisition bill,
 *             impairment tests) · Financing & carrying costs · Audit
 *   The hard-coded indigo "Part of" banner became a rail entity + alert
 *   (tokens only — it rendered as a light box in dark mode).
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, includes/partials/accounting-nav.php,
 *          includes/partials/acc-documents-section.php,
 *          includes/partials/qbo-fa-sync-note.php,
 *          includes/partials/pickers/lookup_picker.php, lib/Ui/RecordUi.php
 * @session S-ACCT-FIX-DOCS, S-ACCT-COMP, S-RECORD-REDESIGN
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
            u.name AS created_by_name,
            b.bill_number AS acquisition_bill_number
       FROM acc_fixed_assets fa
  LEFT JOIN vendors v ON v.id = fa.vendor_id
  LEFT JOIN equipment_units eu ON eu.id = fa.equipment_unit_id
  LEFT JOIN users u ON u.id = fa.created_by
  LEFT JOIN acc_bills b ON b.id = fa.acquisition_bill_id
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

// ── Derived numbers (S-RECORD-REDESIGN) ─────────────────────────────────────
// In service since: available-for-use date, else depreciation start, else
// acquisition. Months are whole calendar months to the company-local today.
$today        = ff_today();
$serviceStart = (string) ($asset['available_for_use_date'] ?: ($asset['depreciation_start_date'] ?: $asset['acquisition_date']));
$monthsInService = null;
if ($serviceStart !== '' && $serviceStart <= $today) {
    $d = (new DateTimeImmutable($serviceStart))->diff(new DateTimeImmutable($today));
    $monthsInService = $d->y * 12 + $d->m;
}
$lifeMonths = $asset['useful_life_years'] !== null ? (int) round((float) $asset['useful_life_years'] * 12) : null;
$depreciable = bcsub((string) $asset['acquisition_cost'], (string) $asset['salvage_value'], 2);
$deprPct = bccomp($depreciable, '0', 2) > 0
    ? (float) bcmul(bcdiv((string) $asset['accumulated_depreciation'], $depreciable, 6), '100', 2)
    : 0.0;

// Depreciation is posted per period (last_depreciation_date = the period's
// end date). "Behind" = an active, depreciating asset whose last run is
// before the end of last month.
$lastMonthEnd = (new DateTimeImmutable($today))->modify('first day of this month')->modify('-1 day')->format('Y-m-d');
$isDepreciating = $asset['status'] === 'active' && ($asset['depreciation_method'] ?? '') !== 'none';
$deprBehind = $isDepreciating
    && (string) ($asset['depreciation_start_date'] ?: $asset['acquisition_date']) <= $lastMonthEnd
    && (empty($asset['last_depreciation_date']) || $asset['last_depreciation_date'] < $lastMonthEnd);
$missingGl = empty($asset['asset_account_id']) || empty($asset['accum_depr_account_id']) || empty($asset['depr_expense_account_id']);

$impairmentCount = (int) (db_row("SELECT COUNT(*) AS c FROM acc_impairment_tests WHERE asset_id = ?", [$id])['c'] ?? 0);

$pageTitle = 'Asset ' . $asset['asset_number'];
require_once FF_ROOT . '/includes/header.php';

// ── Header (S-RECORD-REDESIGN) ──────────────────────────────────────────────
$heroTitle = e($asset['name']);
if (!empty($asset['status'])) {
    $heroTitle .= ' <span class="badge ' . e($statusBadgeClass((string) $asset['status'])) . '">' . e(str_replace('_', ' ', $asset['status'])) . '</span>';
}
if ((int) ($asset['is_component'] ?? 0) === 1) {
    $heroTitle .= ' <span class="badge badge-blue">component</span>';
}
$heroFacts = [
    \FleetForge\Sop\SopIcons::svg('document-text') . '<b>' . e($asset['asset_number']) . '</b>',
    \FleetForge\Sop\SopIcons::svg('cube') . e(ucwords(str_replace('_', ' ', (string) $asset['asset_class']))),
    \FleetForge\Sop\SopIcons::svg('calendar-days') . 'Acquired ' . e(format_date($asset['acquisition_date'])),
];
if (!empty($asset['equipment_unit_id'])) {
    $unitLabel = 'Unit ' . ($asset['equipment_unit_number'] ?? '#' . $asset['equipment_unit_id']);
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('truck') . (can('equipment', 'view')
        ? '<a href="' . e(base_url('equipment/show')) . '?id=' . (int) $asset['equipment_unit_id'] . '">' . e($unitLabel) . '</a>'
        : e($unitLabel));
}
if (!empty($asset['serial_number'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('magnifying-glass') . 'S/N ' . e($asset['serial_number']);
}
?>
<?php ob_start(); /* secondary actions → the header's More menu */ ?>
    <a href="<?= base_url('accounting/fixed-assets') ?>">Fixed asset register</a>
    <a href="<?= base_url('accounting/depreciation') ?>">Depreciation runs</a>
    <a href="<?= base_url('accounting/impairment') ?>">Impairment tests</a>
    <?php if (!empty($asset['acquisition_bill_id'])): ?>
    <a href="<?= base_url('accounting/bills/show?id=' . (int) $asset['acquisition_bill_id']) ?>">Acquisition bill <?= e((string) ($asset['acquisition_bill_number'] ?? '')) ?></a>
    <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
    <?php if (!empty($asset['equipment_unit_id']) && can('equipment', 'view')): ?>
    <a class="btn btn-secondary btn-sm" href="<?= base_url('equipment/show') ?>?id=<?= (int) $asset['equipment_unit_id'] ?>#payoff"><?= \FleetForge\Sop\SopIcons::svg('chart-bar') ?> Unit payoff</a>
    <?php endif; ?>
    <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => match ((string) $asset['status']) { 'disposed' => 'danger', 'impaired' => 'warning', 'fully_depreciated' => 'info', default => 'success' },
    'icon'       => !empty($asset['equipment_unit_id']) ? 'truck' : 'cube',
    'mark'       => (string) $asset['asset_number'],
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Accounting', base_url('accounting/dashboard')], ['Fixed Assets', base_url('accounting/fixed-assets')], [(string) $asset['asset_number'], null]],
    'eyebrow'    => 'Fixed asset',
    'title_html' => $heroTitle,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- KEY NUMBERS (S-RECORD-REDESIGN): what it cost, what has been written
     off, what it is carried at, how long it has worked, and whether this
     month's depreciation has run. -->
<div class="stat-grid stat-grid--5 ff-stats">
    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Cost</div>
        <div class="stat-value font-mono"><?= e(format_currency($asset['acquisition_cost'])) ?></div>
    </div>
    <div class="stat-card stat-card--amber">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-arrow-trending-up"/></svg></span>
        <div class="stat-label">Accum. depreciation</div>
        <div class="stat-value font-mono"><?= e(format_currency($asset['accumulated_depreciation'])) ?></div>
        <div class="stat-delta"><?= round($deprPct) ?>%</div>
    </div>
    <div class="stat-card stat-card--green">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-shield-check"/></svg></span>
        <div class="stat-label">Net book value</div>
        <div class="stat-value font-mono"><?= e(format_currency($asset['net_book_value'])) ?></div>
        <?php if ($components): ?><div class="stat-delta" title="Parent + components"><?= e(format_currency($totalNbv)) ?> total</div><?php endif; ?>
    </div>
    <div class="stat-card stat-card--purple">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">In service</div>
        <div class="stat-value font-mono"><?= $monthsInService !== null ? $monthsInService . ' mo' : '—' ?></div>
        <div class="stat-delta"><?php
            if ($lifeMonths !== null && $monthsInService !== null) {
                $left = $lifeMonths - $monthsInService;
                echo $left > 0 ? e($left . ' mo left') : 'past life';
            } else {
                echo $serviceStart > $today ? 'not yet' : 'no life set';
            }
        ?></div>
    </div>
    <div class="stat-card <?= $deprBehind ? 'stat-card--red' : 'stat-card--slate' ?>">
        <span class="stat-icon <?= $deprBehind ? 'stat-icon--red' : 'stat-icon--slate' ?>"><svg><use href="#icon-<?= $deprBehind ? 'exclamation-triangle' : 'check-circle' ?>"/></svg></span>
        <div class="stat-label">Last depreciated</div>
        <div class="stat-value stat-value--date font-mono"><?= !empty($asset['last_depreciation_date']) ? e(format_date($asset['last_depreciation_date'])) : 'Never' ?></div>
        <div class="stat-delta"><?= $deprBehind ? 'behind' : ($isDepreciating ? 'current' : e(str_replace('_', ' ', (string) $asset['status']))) ?></div>
    </div>
</div>

<div class="rec-layout">
<div class="rec-main">

<?php // F17: QBO sync hint — this asset's depreciation/disposal/impairment JEs enqueue for QBO push.
$qboFaNote = 'fixed-asset';
require FF_ROOT . '/includes/partials/qbo-fa-sync-note.php'; ?>

<!-- ── Asset details ────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header"><h3 class="card-title">Asset details</h3></div>
    <div class="card-body">
        <dl class="rec-dl">
            <dt>Asset #</dt>
            <dd class="font-mono"><?= e($asset['asset_number']) ?></dd>
            <dt>Name</dt>
            <dd><?= e($asset['name']) ?></dd>
            <dt>Asset class</dt>
            <dd><?= e(ucwords(str_replace('_', ' ', (string) $asset['asset_class']))) ?></dd>
            <dt>Acquisition date</dt>
            <dd class="font-mono"><?= e(format_date($asset['acquisition_date'])) ?><?= (int) ($asset['is_opening_balance'] ?? 0) === 1 ? ' <span class="badge badge-neutral">opening balance</span>' : '' ?></dd>
            <dt>Available for use</dt>
            <dd class="font-mono"><?= !empty($asset['available_for_use_date']) ? e(format_date($asset['available_for_use_date'])) : '<span class="text-secondary">— (uses acquisition date)</span>' ?></dd>
            <dt>Acquisition cost</dt>
            <dd class="font-mono" style="font-weight:700;"><?= e(format_currency($asset['acquisition_cost'])) ?></dd>
            <?php
            // Capitalised extras — shown only when present.
            foreach ([['purchase_tax_gst', 'Purchase GST'], ['purchase_tax_pst', 'Purchase PST'], ['delivery_cost', 'Delivery cost'], ['setup_cost', 'Setup cost']] as [$col, $lbl]):
                if (bccomp((string) ($asset[$col] ?? '0'), '0', 2) > 0): ?>
            <dt><?= e($lbl) ?></dt>
            <dd class="font-mono"><?= e(format_currency($asset[$col])) ?></dd>
            <?php endif; endforeach; ?>
            <dt>Salvage value</dt>
            <dd class="font-mono"><?= e(format_currency($asset['salvage_value'])) ?></dd>
            <dt>Depreciable cost</dt>
            <dd class="font-mono"><?= e(format_currency($asset['depreciable_cost'] ?? $depreciable)) ?></dd>
            <dt>Accumulated depreciation</dt>
            <dd class="font-mono"><?= e(format_currency($asset['accumulated_depreciation'])) ?></dd>
            <dt>Net book value</dt>
            <dd class="font-mono" style="font-weight:600;"><?= e(format_currency($asset['net_book_value'])) ?></dd>
            <dt>Depreciation method</dt>
            <dd><?= e(ucwords(str_replace('_', ' ', (string) $asset['depreciation_method']))) ?></dd>
            <dt>Useful life</dt>
            <dd class="font-mono"><?= $asset['useful_life_years'] !== null ? e((string) (float) $asset['useful_life_years']) . ' years' : '<span class="text-secondary">—</span>' ?></dd>
            <dt>Depreciation start</dt>
            <dd class="font-mono"><?= !empty($asset['depreciation_start_date']) ? e(format_date($asset['depreciation_start_date'])) : '<span class="text-secondary">—</span>' ?></dd>
            <?php if (!empty($asset['fully_depreciated_date'])): ?>
            <dt>Fully depreciated</dt>
            <dd class="font-mono"><?= e(format_date($asset['fully_depreciated_date'])) ?></dd>
            <?php endif; ?>
            <?php if (($asset['depreciation_method'] ?? '') === 'units_of_production'): ?>
            <dt>Units used</dt>
            <dd class="font-mono"><?= e(number_format((float) ($asset['units_used_to_date'] ?? 0))) ?> of <?= $asset['total_expected_units'] !== null ? e(number_format((float) $asset['total_expected_units'])) : '—' ?></dd>
            <?php endif; ?>
            <dt>CCA class (Schedule 8)</dt>
            <dd><?= $ccaClassLabel ? e($ccaClassLabel) : '<span class="text-secondary">— not assigned — <a class="link" href="' . e(base_url('accounting/fixed-assets')) . '?edit=' . (int) $asset['id'] . '">assign</a></span>' ?></dd>
            <dt>CRA class (legacy text)</dt>
            <dd class="font-mono"><?= $asset['cra_class'] ? e($asset['cra_class']) : '<span class="text-secondary">—</span>' ?></dd>
            <dt>AIIP eligible</dt>
            <dd><?= ((int) ($asset['is_aiip_eligible'] ?? 1)) === 1 ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-neutral">No</span>' ?></dd>
            <?php if (!empty($asset['equipment_unit_id'])): ?>
            <dt>Linked equipment</dt>
            <dd><a class="link font-mono" href="<?= base_url('equipment/show?id=' . (int) $asset['equipment_unit_id']) ?>"><?= e((string) ($asset['equipment_unit_number'] ?? '#' . $asset['equipment_unit_id'])) ?></a></dd>
            <?php endif; ?>
            <?php if (!empty($asset['vendor_id']) && !empty($asset['vendor_name'])): ?>
            <dt>Acquired from</dt>
            <dd><?= e((string) $asset['vendor_name']) ?></dd>
            <?php endif; ?>
            <?php if (!empty($asset['serial_number'])): ?>
            <dt>Serial #</dt>
            <dd class="font-mono"><?= e($asset['serial_number']) ?></dd>
            <?php endif; ?>
            <?php if (!empty($asset['location'])): ?>
            <dt>Location</dt>
            <dd><?= e($asset['location']) ?></dd>
            <?php endif; ?>
            <?php if (!empty($asset['description'])): ?>
            <dt>Description</dt>
            <dd style="white-space:pre-wrap;"><?= e($asset['description']) ?></dd>
            <?php endif; ?>
            <?php if (!empty($asset['notes'])): ?>
            <dt>Notes</dt>
            <dd style="white-space:pre-wrap;"><?= e($asset['notes']) ?></dd>
            <?php endif; ?>
        </dl>
    </div>
</div>

<?php if ((int) ($asset['is_component'] ?? 0) === 0): ?>
<!-- ── Components (S-ACCT-COMP — ASPE 3061.18) ─────────────────────────── -->
<div class="card" x-data="componentsPanel(<?= (int) $asset['id'] ?>)">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
        <h3 class="card-title">Components<?= $components ? ' <span class="badge badge-neutral">' . count($components) . '</span>' : '' ?></h3>
        <button class="btn btn-primary btn-sm" @click="openModal()">+ Add Component</button>
    </div>
    <div class="card-body">
    <p class="text-secondary" style="margin:0 0 12px;font-size:0.75rem;">
        ASPE 3061.18 — significant components with different useful lives depreciate separately.
    </p>

    <?php if (empty($components)): ?>
    <div style="padding:18px;text-align:center;color:var(--text-secondary);font-size:0.8125rem;border:1px dashed var(--border-default);border-radius:6px;">
        No components yet. Add a component (e.g. reefer unit, engine, cab) to depreciate it separately from the parent.
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
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
                    <a class="font-mono link"
                       href="<?= base_url('accounting/fixed-assets/show?id=' . (int) $c['id']) ?>"><?= e($c['asset_number']) ?></a>
                </td>
                <td><?= e($c['name']) ?></td>
                <td style="text-transform:capitalize;font-size:0.75rem;"><?= e(str_replace('_', ' ', $c['asset_class'])) ?></td>
                <td class="font-mono text-right"><?= e(format_currency($c['acquisition_cost'])) ?></td>
                <td class="font-mono text-right"><?= e(format_currency($c['accumulated_depreciation'])) ?></td>
                <td class="font-mono text-right" style="font-weight:600;"><?= e(format_currency($c['net_book_value'])) ?></td>
                <td class="font-mono"><?= e((string) ($c['useful_life_years'] ?? '—')) ?></td>
                <td style="text-transform:capitalize;font-size:0.75rem;"><?= e(str_replace('_', ' ', (string) $c['depreciation_method'])) ?></td>
                <td><span class="badge <?= e($statusBadgeClass((string) $c['status'])) ?>" style="text-transform:capitalize;"><?= e(str_replace('_', ' ', (string) $c['status'])) ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight:600;">
                <td colspan="5" class="text-right">Total NBV (parent + components)</td>
                <td class="font-mono text-right"><?= e(format_currency($totalNbv)) ?></td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <?php endif; ?>
    </div>

    <!-- Add Component modal -->
    <div x-show="modal.open" x-cloak class="modal-backdrop" @click.self="modal.open = false"
         style="background:rgba(0,0,0,0.4);">
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
            acquisition_cost: '', acquisition_date: FF_localDate(),
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

</div><!-- /rec-main -->
<?php
// ── RAIL (S-RECORD-REDESIGN) — the asset at a glance ────────────────────────
$R = \FleetForge\Ui\RecordUi::class;
$rail = [];

$alerts = [];
if ($deprBehind) {
    $alerts[] = ['warning', 'Depreciation not posted through ' . e(format_date($lastMonthEnd)) . ' (last: ' . (!empty($asset['last_depreciation_date']) ? e(format_date($asset['last_depreciation_date'])) : 'never') . '). Run it from <a href="' . e(base_url('accounting/depreciation')) . '">Depreciation</a>.'];
}
if ($missingGl && $asset['status'] !== 'disposed') {
    $alerts[] = ['danger', 'Missing GL accounts (asset / accumulated depreciation / expense) — depreciation cannot post.'];
}
if (!$ccaClassLabel && $asset['status'] !== 'disposed') {
    $alerts[] = ['warning', 'No CCA class — it is left off Schedule 8 until one is assigned in <a href="' . e(base_url('accounting/fixed-assets')) . '">Fixed Assets</a>.'];
}
if ($asset['status'] === 'active' && bccomp((string) $asset['net_book_value'], (string) $asset['salvage_value'], 2) <= 0 && ($asset['depreciation_method'] ?? '') !== 'none') {
    $alerts[] = ['info', 'Carried at salvage value — nothing left to depreciate.'];
}
if ($asset['status'] === 'disposed') {
    $alerts[] = ['info', 'Disposed — kept for history; it no longer depreciates.'];
}
if ($asset['status'] === 'impaired') {
    $alerts[] = ['info', 'Impaired — see the <a href="' . e(base_url('accounting/impairment')) . '">impairment tests</a>.'];
}
if ($parentSummary) {
    $alerts[] = ['info', 'A component of <a href="' . e(base_url('accounting/fixed-assets/show?id=' . (int) $parentSummary['id'])) . '">' . e($parentSummary['asset_number']) . '</a> (ASPE 3061.18) — depreciates on its own schedule.'];
}
$rail[] = $R::card('Needs attention', $R::alerts($alerts, 'All clear — depreciation is up to date.'), ['icon' => 'exclamation-triangle']);

// Depreciation progress.
$rail[] = $R::card('Depreciation',
    $R::meter('Written off', e((string) round($deprPct)) . '%', $deprPct, $deprPct >= 99.99 ? 'ok' : 'info',
        e(format_currency($asset['accumulated_depreciation'])) . ' of ' . e(format_currency($depreciable)) . ' depreciable')
    . $R::kv([
        ['Method', e(ucwords(str_replace('_', ' ', (string) $asset['depreciation_method'])))],
        ['Useful life', $asset['useful_life_years'] !== null ? e((string) (float) $asset['useful_life_years']) . ' yrs' : null],
        ['Life used', $lifeMonths && $monthsInService !== null ? e((string) min($monthsInService, $lifeMonths)) . ' of ' . e((string) $lifeMonths) . ' mo' : null],
        ['Net book value', e(format_currency($asset['net_book_value'])), 'mono'],
    ]),
    ['icon' => 'chart-bar', 'class' => 'rec-card--accent']);

// Related records.
$relBody = '';
if (!empty($asset['equipment_unit_id'])) {
    $relBody .= $R::entity('Unit ' . ($asset['equipment_unit_number'] ?? '#' . $asset['equipment_unit_id']),
        can('equipment', 'view') ? base_url('equipment/show') . '?id=' . (int) $asset['equipment_unit_id'] : '', 'Fleet unit', '', 'truck');
}
if ($parentSummary) {
    $relBody .= ($relBody !== '' ? '<div style="height:10px;"></div>' : '')
        . $R::entity((string) $parentSummary['asset_number'], base_url('accounting/fixed-assets/show?id=' . (int) $parentSummary['id']), e($parentSummary['name']) . ' · parent asset', '', 'cube');
}
if (!empty($asset['vendor_id']) && !empty($asset['vendor_name'])) {
    $relBody .= ($relBody !== '' ? '<div style="height:10px;"></div>' : '')
        . $R::entity((string) $asset['vendor_name'], can('vendors', 'view') ? base_url('vendors/show') . '?id=' . (int) $asset['vendor_id'] : '', 'Acquired from', \FleetForge\Ui\ModuleHero::initials((string) $asset['vendor_name']));
}
$relLinks = [];
if (!empty($asset['equipment_unit_id']) && can('equipment', 'view')) {
    $relLinks[] = ['Unit payoff', base_url('equipment/show') . '?id=' . (int) $asset['equipment_unit_id'] . '#payoff', 'chart-bar'];
}
if (!empty($asset['acquisition_bill_id'])) {
    $relLinks[] = ['Acquisition bill', base_url('accounting/bills/show?id=' . (int) $asset['acquisition_bill_id']), 'document-text', (string) ($asset['acquisition_bill_number'] ?? '')];
}
$relLinks[] = ['Impairment tests', base_url('accounting/impairment'), 'scale', $impairmentCount . ' on file'];
$relBody .= ($relBody !== '' ? '<div style="margin-top:12px;">' : '<div>') . $R::links($relLinks) . '</div>';
$rail[] = $R::card('Related', $relBody, ['icon' => 'document-duplicate']);

// Financing + monthly carrying costs (only when any are set).
$carry = '0.00';
foreach (['monthly_insurance_cost', 'monthly_licensing_cost', 'monthly_registration_cost'] as $col) {
    $carry = bcadd($carry, (string) ($asset[$col] ?? '0'), 2);
}
if ((int) ($asset['is_financed'] ?? 0) === 1 || bccomp($carry, '0', 2) > 0) {
    $rail[] = $R::card('Financing & carrying', $R::kv([
        ['Financed', (int) ($asset['is_financed'] ?? 0) === 1 ? 'Yes' : 'No'],
        ['Monthly payment', $asset['financing_monthly_payment'] !== null ? e(format_currency($asset['financing_monthly_payment'])) : null, 'mono'],
        // Stored as a decimal fraction (0.075 = 7.5%) — see the index form's validation.
        ['Interest rate', $asset['financing_interest_rate'] !== null ? e(rtrim(rtrim(bcmul((string) $asset['financing_interest_rate'], '100', 3), '0'), '.')) . '%' : null],
        ['Months remaining', $asset['financing_remaining_months'] !== null ? e((string) $asset['financing_remaining_months']) : null],
        ['Insurance / mo', bccomp((string) $asset['monthly_insurance_cost'], '0', 2) > 0 ? e(format_currency($asset['monthly_insurance_cost'])) : null, 'mono'],
        ['Licensing / mo', bccomp((string) $asset['monthly_licensing_cost'], '0', 2) > 0 ? e(format_currency($asset['monthly_licensing_cost'])) : null, 'mono'],
        ['Registration / mo', bccomp((string) $asset['monthly_registration_cost'], '0', 2) > 0 ? e(format_currency($asset['monthly_registration_cost'])) : null, 'mono'],
    ]), ['icon' => 'credit-card']);
}

// Audit. S-UTC-STAMPS: created_at / updated_at are UTC — show company time.
$rail[] = $R::card('Record', $R::kv([
    ['Created', e($asset['created_by_name'] ?? 'system') . '<br><span class="text-secondary">' . e(format_datetime($asset['created_at'])) . '</span>'],
    ['Updated', e(format_datetime($asset['updated_at']))],
]), ['icon' => 'clock']);
?>
<aside class="rec-rail" aria-label="Fixed asset at a glance">
    <?= implode("\n    ", $rail) ?>
</aside>
</div><!-- /rec-layout -->

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
