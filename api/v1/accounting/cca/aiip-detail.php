<?php
declare(strict_types=1);

/**
 * api/v1/accounting/cca/aiip-detail.php
 *
 * Returns the per-asset AIIP breakdown for one or all CCA classes in
 * a fiscal year per spec §23.4. Pure read — does not modify
 * acc_cca_continuity rows or call compute().
 *
 * Used by the CCA admin page's "AIIP Detail" expandable section to show
 * which assets triggered which AIIP treatment (full / phase-out /
 * reinstated / ineligible / pre-AIIP).
 *
 * @method  GET
 * @query   fiscal_year (required), class_id? (optional — all active classes
 *          if omitted)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { fiscal_year, reinstatement_enabled,
 *                classes: [{class_id, class_number, description,
 *                  aiip_adjustment, half_year_adjustment, half_year_suspended,
 *                  fallback_count, per_asset_breakdown[]}] }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.4
 * Session: S-ACCT-CCA-2
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\CcaService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$fiscalYear = clean_int($_GET['fiscal_year'] ?? null);
$classId    = clean_int($_GET['class_id'] ?? null);

if (!$fiscalYear) {
    json_error('MISSING_REQUIRED', 'fiscal_year is required.', 422);
}

$classFilter = $classId ? 'AND id = ?' : '';
$params      = ['active' => 1];
$sql = "SELECT id, class_number, description, half_year_rule, rate
          FROM acc_cca_classes
         WHERE is_active = 1 {$classFilter}
         ORDER BY CAST(class_number AS DECIMAL(6,2)) ASC";
$classes = $classId
    ? db_select($sql, [$classId])
    : db_select($sql);

// For the per-class AIIP computation we need the additions + proceeds totals
// in this fiscal year. Re-derive them here rather than depending on the
// continuity row (the user may be inspecting AIIP detail before clicking
// Compute for the year).
$results = [];
$reinstatement = ((string) settings_get('accounting.aiip_proposed_reinstatement_enabled', '0')) === '1';

foreach ($classes as $cls) {
    $cid = (int) $cls['id'];
    $addRow = db_row(
        "SELECT COALESCE(SUM(acquisition_cost), 0) AS total
           FROM acc_fixed_assets
          WHERE cca_class_id = ?
            AND YEAR(COALESCE(available_for_use_date, acquisition_date)) = ?",
        [$cid, $fiscalYear]
    );
    $additions = (string) ($addRow['total'] ?? '0.00');

    $disp = db_select(
        "SELECT d.proceeds, fa.acquisition_cost
           FROM acc_asset_disposals d
           JOIN acc_fixed_assets fa ON fa.id = d.asset_id
          WHERE fa.cca_class_id = ? AND YEAR(d.disposal_date) = ?",
        [$cid, $fiscalYear]
    );
    $proceeds = '0.00';
    foreach ($disp as $d) {
        $capped = bccomp($d['proceeds'], $d['acquisition_cost'], 2) > 0
            ? (string) $d['acquisition_cost']
            : (string) $d['proceeds'];
        $proceeds = bcadd($proceeds, $capped, 2);
    }

    $aiip = CcaService::computeAiipForClass(
        $cid,
        $fiscalYear,
        $additions,
        $proceeds,
        (int) $cls['half_year_rule'] === 1
    );

    // Skip classes with no AIIP-relevant activity (nothing in the breakdown).
    if (empty($aiip['per_asset_breakdown'])) continue;

    $results[] = [
        'class_id'            => $cid,
        'class_number'        => $cls['class_number'],
        'description'         => $cls['description'],
        'rate'                => $cls['rate'],
        'additions'           => $additions,
        'proceeds'            => $proceeds,
        'aiip_adjustment'     => $aiip['aiip_adjustment'],
        'half_year_adjustment' => $aiip['half_year_adjustment'],
        'half_year_suspended' => $aiip['half_year_suspended'],
        'fallback_count'      => $aiip['fallback_count'],
        'per_asset_breakdown' => $aiip['per_asset_breakdown'],
    ];
}

json_success([
    'fiscal_year'           => $fiscalYear,
    'reinstatement_enabled' => $reinstatement,
    'classes'               => $results,
]);
