<?php declare(strict_types=1);

/**
 * tests/_smoke_payoff_api.php
 *
 * Renders the payoff endpoint for every seeded asset and prints the
 * key numbers — total invested, net revenue, still to recover,
 * projection. Used as a quick sanity check that the seeder filled
 * the right fields and that the API math computes cleanly.
 *
 * Each asset is rendered in a separate child PHP process because
 * the API endpoint calls exit() after json_response().
 *
 * Usage:
 *   php tests/_smoke_payoff_api.php           # parent — runs all assets
 *   php tests/_smoke_payoff_api.php <id>      # child  — renders one asset
 */

// ── CHILD MODE ──────────────────────────────────────────────
if (isset($argv[1]) && ctype_digit($argv[1])) {
    $assetId = (int) $argv[1];

    $_SERVER['HTTPS']           = 'on';
    $_SERVER['HTTP_HOST']       = 'fleetforge.test';
    $_SERVER['REQUEST_METHOD']  = 'GET';
    $_SERVER['REQUEST_URI']     = '/api/v1/accounting/fixed_assets/payoff.php?asset_id=' . $assetId;
    $_SERVER['SCRIPT_NAME']     = '/api/v1/accounting/fixed_assets/payoff.php';
    $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'ff-smoke-cli';
    $_GET                       = ['asset_id' => $assetId, 'period' => 6];

    require_once __DIR__ . '/../config/app.php';
    require_once FF_ROOT . '/includes/db.php';
    require_once FF_ROOT . '/includes/auth.php';
    $_SESSION['ff_user'] = [
        'id'          => 1,
        'name'        => 'Smoke Admin',
        'email'       => 'smoke@fleetforge.test',
        'role_id'     => 1,
        'role_slug'   => 'super_admin',
        'permissions' => [],
        'theme'       => 'dark',
    ];
    $_SESSION['ff_last_activity'] = time();

    require FF_ROOT . '/api/v1/accounting/fixed_assets/payoff.php';
    // The endpoint exits via json_response(); we never reach this line.
    exit(0);
}

// ── PARENT MODE ─────────────────────────────────────────────
require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';

// Pull every active linked asset so we exercise the whole seeded set.
$assets = db_select(
    "SELECT a.id, a.asset_number, a.name, a.acquisition_cost, a.is_financed,
            eu.unit_number, et.category
       FROM acc_fixed_assets a
       JOIN equipment_units eu ON eu.id = a.equipment_unit_id
       LEFT JOIN equipment_templates et ON et.id = eu.template_id
      WHERE a.equipment_unit_id IS NOT NULL
        AND a.status != 'disposed'
        AND eu.deleted_at IS NULL
      ORDER BY eu.id"
);

echo "\n=== payoff API sanity check (" . count($assets) . " assets) ===\n";
echo str_repeat('-', 140) . "\n";
echo sprintf("%-4s %-13s %-9s %-10s %-3s %12s %12s %12s %7s %12s\n",
    'AID', 'NUMBER', 'UNIT', 'CATEGORY', 'FIN',
    'INVESTED', 'NET REV', 'TO RECOVER', 'PROG%', 'PROJ');
echo str_repeat('-', 140) . "\n";

$self = __FILE__;
$php  = PHP_BINARY;
$totalOk = 0;

foreach ($assets as $a) {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($self) . ' ' . (int) $a['id'] . ' 2>&1';
    $body = shell_exec($cmd) ?: '';
    // Strip any header lines emitted by header() — only the JSON body matters.
    // (CLI doesn't actually emit headers, so $body should already be pure JSON.)
    $json = json_decode(trim($body), true);

    if (!is_array($json) || !($json['success'] ?? false)) {
        echo sprintf("  [FAIL] asset #%-3d %-13s — %s\n",
            (int) $a['id'], $a['asset_number'],
            substr(trim($body), 0, 200));
        continue;
    }

    $d = $json['data'];
    // Resolved paths after inspecting the live response:
    //   data.acquisition.total
    //   data.totals.net_revenue_to_date
    //   data.totals.still_to_recover
    //   data.totals.progress_pct
    //   data.selected.months    (the projection for the chosen period)
    $proj = $d['selected']['months'] ?? null;
    $projStr = ($proj === null) ? 'no proj' : ($proj . ' mo');

    echo sprintf("%-4d %-13s %-9s %-10s %-3s %12s %12s %12s %7s %12s\n",
        (int) $a['id'],
        $a['asset_number'],
        $a['unit_number'],
        $a['category'] ?? '—',
        ((int) $a['is_financed']) ? 'Y' : 'N',
        '$' . number_format((float) ($d['acquisition']['total'] ?? 0), 0),
        '$' . number_format((float) ($d['totals']['net_revenue_to_date'] ?? 0), 0),
        '$' . number_format((float) ($d['totals']['still_to_recover'] ?? 0), 0),
        ($d['totals']['progress_pct'] ?? '0') . '%',
        $projStr
    );
    $totalOk++;
}

echo str_repeat('-', 140) . "\n";
echo "$totalOk / " . count($assets) . " assets returned a clean payoff payload.\n";
exit($totalOk === count($assets) ? 0 : 1);
