<?php declare(strict_types=1);

/**
 * api/v1/accounting/depreciation/show.php
 *
 * Return a single depreciation run with its lines (per-asset breakdown).
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; require_permission('fixed_assets','view')
 * @returns 200 { run, lines } | 404
 *
 * @depends api/bootstrap.php
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('fixed_assets', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$run = db_row(
    "SELECT r.*, p.name AS period_name
     FROM acc_depreciation_runs r
     LEFT JOIN acc_periods p ON p.id = r.period_id
     WHERE r.id = ?",
    [$id]
);
if (!$run) {
    json_error('NOT_FOUND', 'Depreciation run not found.', 404);
}

$lines = db_select(
    "SELECT rl.*, a.asset_number, a.name AS asset_name, a.asset_class
     FROM acc_depreciation_run_lines rl
     LEFT JOIN acc_fixed_assets a ON a.id = rl.asset_id
     WHERE rl.run_id = ?
     ORDER BY a.asset_number",
    [$id]
);

json_success(['run' => $run, 'lines' => $lines]);
