<?php declare(strict_types=1);

/**
 * FleetForge — Smoke test harness for PAYOFF-1 design polish.
 *
 * Renders each modified page through PHP CLI with a fake super_admin
 * session, captures the HTML, writes it to /tmp for grep verification,
 * and prints a pass/fail summary for the markers we care about.
 *
 * Each page runs in a SEPARATE child PHP process to avoid duplicate
 * function/declaration collisions across page includes.
 *
 * The two equipment/show.php cases are DATA-INDEPENDENT: instead of
 * hard-coding unit ids (units 6/7 were soft-deleted on 2026-06-25, so
 * show.php redirected + exited and rendered 0 bytes), the parent looks up
 * a live unit that IS linked to a fixed asset and one that is NOT, using
 * the same predicates show.php uses, and passes the unit id to the child.
 * A case with no qualifying unit is SKIPPED with a message, not failed.
 *
 * Usage:
 *   php tests/_smoke_payoff_design.php                  # parent — runs all 4 cases
 *   php tests/_smoke_payoff_design.php <case> [<id>]    # child  — renders one page
 *                                                       #   (<id> → $_GET['id'])
 */

// Each test case is: [label, relPath, expectedMarkers, $_GET overrides]
$cases = [
    'fixed_assets_index' => [
        'path'    => 'app/admin/accounting/fixed-assets/index.php',
        'get'     => [],
        'markers' => [
            'Fixed Assets Register',
            'Track depreciable assets',
            'class="section-header"',
            '<h3 class="section-title">Core Details</h3>',
            '<h3 class="section-title">Depreciation</h3>',
            '<h3 class="section-title">GL Accounts</h3>',
            '<h3 class="section-title">Identification</h3>',
            '<h3 class="section-title">Acquisition Details</h3>',
            '<h3 class="section-title">Financing</h3>',
            '<h3 class="section-title">Monthly Fixed Costs</h3>',
            'New Fixed Asset',
            'Edit Fixed Asset',
        ],
    ],
    'payoff_report' => [
        'path'    => 'app/admin/accounting/fixed-assets/payoff-report.php',
        'get'     => [],
        'markers' => [
            'Fleet Payoff Report',
            'See how every linked fixed asset is recovering its investment',
            'Back to Assets',
            "\$dispatch('payoff-export-csv')",
            '@payoff-export-csv.window="exportCsv()"',
            'stat-card stat-card--blue',
            'stat-icon--blue',
            'class="card" style="margin-bottom:20px;padding:14px 16px;"',
            'class="data-table"',
            "toggleSort('asset_number')",
        ],
    ],
    'equipment_show_linked' => [
        'path'    => 'app/admin/equipment/show.php',
        // WHY: resolved at run time by resolve_unit_fixture() — the unit id
        // becomes $_GET['id'] and the linked asset id feeds the regex below.
        'unit'    => 'linked',
        'markers' => [
            // S-RECORD-REDESIGN: sticky underline tabs.
            'class="tab-bar tab-bar--sticky"',
            'Payoff Analysis',
            'FF_UnitDetail',
            // S-PAYOFF-ONE-PAGE: the Payoff tab is the one payoff page — the
            // retired equipment/payoff.php deep-dive's sections live here.
            'id="unit-payoff-bars"',
            'Revenue by lease',
            'Month by month',
        ],
        // The PHP injects linkedAssetId via PHP_EOL-aligned formatting, so
        // we match it via regex (any amount of whitespace, then the id).
        // {asset_id} is substituted with the resolved fixed-asset id.
        'regex' => [
            '/linkedAssetId:\s*{asset_id}\b/',
        ],
    ],
    'equipment_show_unlinked' => [
        'path'    => 'app/admin/equipment/show.php',
        'unit'    => 'unlinked',
        'markers' => [
            'class="tab-bar tab-bar--sticky"',
            'Payoff Analysis',
            'FF_UnitDetail',
        ],
        'regex' => [
            '/linkedAssetId:\s*0\b/',
        ],
    ],
];

/**
 * Find a live equipment unit for a show.php case, mirroring show.php's own
 * lookups so the page renders and computes the linkedAssetId we expect.
 *
 * show.php only renders a unit that is live (deleted_at IS NULL) and has a
 * template (INNER JOIN equipment_templates); otherwise it redirects + exits.
 * Its linked asset is the newest non-disposed acc_fixed_assets row for the
 * unit, else 0.
 *
 * @param  string $kind 'linked' (has a non-disposed fixed asset) or
 *                      'unlinked' (has none — disposed-only counts as none)
 * @return array{unit_id:int, asset_id:int}|null null when no unit qualifies
 */
function resolve_unit_fixture(string $kind): ?array
{
    $existsClause = ($kind === 'linked' ? 'EXISTS' : 'NOT EXISTS');
    $unit = db_row(
        "SELECT u.id
           FROM equipment_units u
           JOIN equipment_templates t ON t.id = u.template_id
          WHERE u.deleted_at IS NULL
            AND {$existsClause} (
                SELECT 1 FROM acc_fixed_assets fa
                 WHERE fa.equipment_unit_id = u.id
                   AND fa.status != 'disposed'
            )
          ORDER BY u.id
          LIMIT 1"
    );
    if (!$unit) {
        return null;
    }
    $unitId = (int) $unit['id'];

    // WHY: same query as show.php's "Linked fixed asset lookup" — a unit
    // with several live assets shows the newest one, so the expected id
    // must be picked the same way, not just "any linked asset".
    $asset = db_row(
        "SELECT id
           FROM acc_fixed_assets
          WHERE equipment_unit_id = ?
            AND status != 'disposed'
          ORDER BY acquisition_date DESC, id DESC
          LIMIT 1",
        [$unitId]
    );
    return ['unit_id' => $unitId, 'asset_id' => $asset ? (int) $asset['id'] : 0];
}

// ── CHILD MODE ──────────────────────────────────────────────
// Invoked as: php _smoke_payoff_design.php <case-key> [<unit-id>]
// Renders one page and writes the captured HTML to /tmp.
if (isset($argv[1])) {
    $key = $argv[1];
    if (!isset($cases[$key])) {
        fwrite(STDERR, "unknown case: $key\n");
        exit(2);
    }
    $case = $cases[$key];

    // Pretend to be a real HTTPS web request.
    $_SERVER['HTTPS']           = 'on';
    $_SERVER['HTTP_HOST']       = 'fleetforge.test';
    $_SERVER['REQUEST_METHOD']  = 'GET';
    $_SERVER['REQUEST_URI']     = '/';
    $_SERVER['SCRIPT_NAME']     = '/index.php';
    $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'ff-smoke-cli';
    $_GET                       = $case['get'] ?? [];
    // WHY: unit-backed cases get their id from the parent's run-time lookup.
    if (isset($argv[2])) {
        $_GET['id'] = (string) (int) $argv[2];
    }

    // Bootstrap the app and stub a super_admin session.
    require_once __DIR__ . '/../config/app.php';
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

    $absPath = FF_ROOT . '/' . ltrim($case['path'], '/');
    if (!is_file($absPath)) {
        fwrite(STDERR, "missing file: $absPath\n");
        exit(3);
    }

    ob_start();
    try {
        require $absPath;
    } catch (\Throwable $e) {
        ob_end_clean();
        fwrite(STDERR, "exception: " . $e->getMessage() . "\n");
        exit(4);
    }
    $html = ob_get_clean();

    $outFile = '/tmp/ff_smoke_' . $key . '.html';
    file_put_contents($outFile, $html);
    echo $outFile . "\n" . strlen($html) . "\n";
    exit(0);
}

// ── PARENT MODE ─────────────────────────────────────────────
// Re-invoke this script for each case in a child process.
$self = __FILE__;
$php  = PHP_BINARY;

// WHY: resolve every unit-backed case BEFORE printing anything —
// config/app.php sets session ini values, which warns once output has
// been sent. Children bootstrap the app on their own, so this is the
// parent's only DB touch.
$fixtures = [];
foreach ($cases as $key => $case) {
    if (isset($case['unit'])) {
        require_once __DIR__ . '/../config/app.php';
        $fixtures[$key] = resolve_unit_fixture($case['unit']);
    }
}

echo "\n=== PAYOFF-1 design smoke test ===\n";
$totalPass = 0;
$totalSkip = 0;
foreach ($cases as $key => $case) {
    $childArgs = escapeshellarg($key);

    // Unit-backed case: pass the resolved unit id to the child and bake the
    // resolved asset id into the regex — or SKIP when the DB has no such unit.
    if (isset($case['unit'])) {
        $fixture = $fixtures[$key];
        if ($fixture === null) {
            $totalSkip++;
            $why = $case['unit'] === 'linked'
                ? 'no live equipment unit (deleted_at IS NULL, with a template) is linked to a non-disposed fixed asset'
                : 'every live equipment unit (deleted_at IS NULL, with a template) is linked to a non-disposed fixed asset';
            echo sprintf("[SKIP] %-26s %s\n", $key, $why);
            continue;
        }
        $childArgs .= ' ' . escapeshellarg((string) $fixture['unit_id']);
        $case['regex'] = array_map(
            static fn(string $rx): string => str_replace('{asset_id}', (string) $fixture['asset_id'], $rx),
            $case['regex'] ?? []
        );
        echo sprintf("       %-26s unit #%d → linkedAssetId %d\n", $key, $fixture['unit_id'], $fixture['asset_id']);
    }

    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($self) . ' ' . $childArgs . ' 2>&1';
    $out = [];
    $exitCode = 0;
    exec($cmd, $out, $exitCode);

    if ($exitCode !== 0) {
        echo sprintf("[FAIL] %-26s child exit=%d\n", $key, $exitCode);
        foreach ($out as $line) echo "        $line\n";
        continue;
    }

    // WHY: a page that redirects + `exit`s (missing/soft-deleted record)
    // ends the child with code 0 and prints nothing — say so explicitly
    // rather than reporting a wall of "missing markers" on 0 bytes.
    if ($out === []) {
        echo sprintf("[FAIL] %-26s child rendered nothing — page exited early (redirect on a missing or soft-deleted record?)\n", $key);
        continue;
    }

    $outFile = $out[0] ?? '';
    $bytes   = (int) ($out[1] ?? 0);
    $html    = is_file($outFile) ? (string) file_get_contents($outFile) : '';

    $missing = [];
    foreach ($case['markers'] as $marker) {
        if (strpos($html, $marker) === false) {
            $missing[] = $marker;
        }
    }
    foreach (($case['regex'] ?? []) as $rx) {
        if (!preg_match($rx, $html)) {
            $missing[] = 'regex ' . $rx;
        }
    }

    $tag = empty($missing) ? 'PASS' : 'FAIL';
    echo sprintf("[%s] %-26s %7d bytes  %s\n", $tag, $key, $bytes, $outFile);
    if (empty($missing)) {
        $totalPass++;
    } else {
        echo "       missing markers:\n";
        foreach ($missing as $m) {
            echo "         - " . $m . "\n";
        }
    }
}

echo sprintf("\n%d / %d pages passed", $totalPass, count($cases) - $totalSkip);
echo $totalSkip > 0 ? sprintf(" (%d skipped — no qualifying data)\n", $totalSkip) : "\n";
exit($totalPass === count($cases) - $totalSkip ? 0 : 1);
