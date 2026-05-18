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

<!-- ── Documents ───────────────────────────────────────────────────────── -->
<?php
$entityType = 'asset';
$entityId   = (int) $asset['id'];
require FF_ROOT . '/includes/partials/acc-documents-section.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
