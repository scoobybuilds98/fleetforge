<?php declare(strict_types=1);

/**
 * scripts/import_fixed_assets_2026_08_25.php
 *
 * One-time import: create an acc_fixed_assets row for every equipment unit
 * listed in a reconciled cost CSV.
 *
 * Background: acc_fixed_assets was empty in production, so the Payoff
 * Analysis module (app/admin/equipment/payoff.php) had nothing to read and
 * every unit rendered "No linked fixed asset". The operator supplied
 * per-unit costs in a spreadsheet; those were reconciled against
 * equipment_units.unit_number and written to the CSV this script consumes.
 *
 * The CSV is the contract. This script does NOT re-derive costs, back out
 * tax, or fuzzy-match unit numbers — all of that happened during
 * reconciliation so the numbers can be reviewed before anything is written.
 * Columns consumed: unit_id, prod_unit_number, acquisition_cost,
 * purchase_tax_gst. Everything else in the CSV is context for the reviewer.
 *
 * WHY costs are strings end to end: dollar amounts never touch float math
 * (project rule D16). The CSV carries pre-rounded 2dp decimal strings and we
 * hand them to FixedAssetService untouched.
 *
 * WHY this posts no journal entry: FixedAssetService::create() is a pure
 * subledger insert — it does not touch acc_journal_entries. Creating these
 * assets therefore cannot unbalance the GL. Depreciation is a separate,
 * explicitly-run step (Accounting -> Depreciation), which is what actually
 * posts to the GL.
 *
 * Idempotent: a unit that already has a non-disposed asset is skipped, so a
 * partial run can be re-run safely to fill only the gaps.
 *
 * Usage:
 *   php scripts/import_fixed_assets_2026_08_25.php <csv>            # dry-run
 *   php scripts/import_fixed_assets_2026_08_25.php <csv> --apply    # write
 *   php scripts/import_fixed_assets_2026_08_25.php <csv> --verify   # report only
 *   ... --apply --user-id=7    # attribute audit_log rows to user 7
 *
 * @session S-FA-IMPORT
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/lib/Accounting/FixedAssetService.php';

use FleetForge\Accounting\FixedAssetService;

// ── Import constants — operator-confirmed, see OPEN_QUESTIONS.md ────────────
const FA_ACQUISITION_DATE = '2025-07-01';
const FA_USEFUL_LIFE      = '10';
const FA_SALVAGE          = '0.00';
const FA_ASSET_CLASS      = 'fleet_equipment';
const FA_ASSET_ACCOUNT    = 12;  // 1210 Fleet Equipment — Cost
const FA_ACCUM_ACCOUNT    = 13;  // 1220 Fleet Equipment — Accumulated Depreciation
const FA_EXPENSE_ACCOUNT  = 50;  // 5010 Depreciation — Rental Fleet
const FA_IMPORT_TAG       = 'S-FA-IMPORT 2026-08-25';

$args    = array_slice($argv, 1);
$flags   = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$csvPath = null;
foreach ($args as $a) {
    if (!str_starts_with($a, '--')) { $csvPath = $a; break; }
}

$apply  = in_array('--apply', $flags, true);
$verify = in_array('--verify', $flags, true);

// Attribute the 166 audit_log rows to a real operator rather than 'system'.
// Optional — FixedAssetService::audit() falls back to 'system' without it.
$userId = null;
foreach ($flags as $f) {
    if (str_starts_with($f, '--user-id=')) {
        $userId = (int) substr($f, strlen('--user-id='));
    }
}

if (!$csvPath || !is_readable($csvPath)) {
    fwrite(STDERR, "ERROR: pass a readable reconciled CSV as the first argument.\n");
    fwrite(STDERR, "Usage: php " . basename(__FILE__) . " <csv> [--apply|--verify]\n");
    exit(1);
}

/**
 * Read the reconciled CSV into rows keyed by column name.
 *
 * @return array<int,array<string,string>>
 */
function fa_read_csv(string $path): array
{
    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException("Cannot open CSV: {$path}");
    }
    // Explicit $escape='' keeps RFC-4180 behaviour and silences the PHP 8.4
    // deprecation; valid on the 8.2 running in production too.
    $header = fgetcsv($fh, 0, ',', '"', '');
    if (!$header) {
        throw new RuntimeException('CSV has no header row.');
    }
    $header = array_map(fn($h) => trim((string) $h), $header);

    $required = ['unit_id', 'prod_unit_number', 'acquisition_cost', 'purchase_tax_gst'];
    $missing  = array_diff($required, $header);
    if ($missing) {
        throw new RuntimeException('CSV missing required column(s): ' . implode(', ', $missing));
    }

    $rows = [];
    while (($line = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        // Skip blank trailing lines rather than emitting a bogus row.
        if (count($line) === 1 && trim((string) $line[0]) === '') {
            continue;
        }
        if (count($line) !== count($header)) {
            throw new RuntimeException(
                'CSV column-count mismatch on row: ' . implode(',', array_map('strval', $line))
            );
        }
        $rows[] = array_combine($header, array_map(fn($v) => trim((string) $v), $line));
    }
    fclose($fh);
    return $rows;
}

/**
 * Assert a value is a plain 2-decimal money string. The CSV is generated, so
 * anything else means the file was hand-edited into a shape that would
 * silently corrupt the GL — fail loudly instead.
 */
function fa_assert_money(string $val, string $field, string $unit): string
{
    if (!preg_match('/^\d+\.\d{2}$/', $val)) {
        throw new RuntimeException("{$unit}: {$field} must be a 2dp decimal string, got '{$val}'.");
    }
    if (bccomp($val, '0.00', 2) <= 0 && $field === 'acquisition_cost') {
        throw new RuntimeException("{$unit}: acquisition_cost must be greater than zero.");
    }
    return $val;
}

// A malformed CSV is operator error, not a bug — report it as a plain message
// rather than dumping a stack trace at someone running this on prod.
try {
    $rows = fa_read_csv($csvPath);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'ERROR reading CSV: ' . $e->getMessage() . "\n");
    exit(1);
}

printf("Reconciled CSV : %s\n", $csvPath);
printf("Rows in file   : %d\n\n", count($rows));

// ── Pre-flight: validate every row against the live DB before writing one ──
// WHY up front: a bad unit_id or an already-imported unit halfway through a
// 166-row loop leaves a half-populated table. Validate the whole batch first.
$plan     = [];
$skipped  = [];
$errors   = [];

foreach ($rows as $r) {
    $unitId = (int) $r['unit_id'];
    $label  = $r['prod_unit_number'] !== '' ? $r['prod_unit_number'] : "unit_id={$unitId}";

    if ($unitId <= 0) {
        $errors[] = "{$label}: invalid unit_id.";
        continue;
    }

    $unit = db_row(
        "SELECT eu.id, eu.unit_number, eu.vin, eu.year, eu.yard_location, eu.status,
                et.name AS template, COALESCE(ec.label, et.category) AS category
           FROM equipment_units eu
           JOIN equipment_templates et ON et.id = eu.template_id
           LEFT JOIN equipment_categories ec ON ec.id = et.category_id
          WHERE eu.id = ? AND eu.deleted_at IS NULL",
        [$unitId]
    );

    if (!$unit) {
        $errors[] = "{$label}: unit_id {$unitId} not found (or soft-deleted).";
        continue;
    }

    // The CSV records the unit_number as it was at reconciliation time. If it
    // has since been renamed, the id still governs — but say so, because a
    // mismatch may mean the CSV is stale against a changed fleet.
    if ($r['prod_unit_number'] !== '' && $r['prod_unit_number'] !== $unit['unit_number']) {
        $errors[] = sprintf(
            "%s: CSV unit_number '%s' != DB '%s' for id %d — CSV may be stale.",
            $label, $r['prod_unit_number'], $unit['unit_number'], $unitId
        );
        continue;
    }

    // Idempotency: an existing non-disposed asset means this unit is done.
    $existing = db_row(
        "SELECT id, asset_number FROM acc_fixed_assets
          WHERE equipment_unit_id = ? AND status != 'disposed' LIMIT 1",
        [$unitId]
    );
    if ($existing) {
        $skipped[] = sprintf('%s — already has asset %s', $unit['unit_number'], $existing['asset_number']);
        continue;
    }

    try {
        $cost = fa_assert_money($r['acquisition_cost'], 'acquisition_cost', $unit['unit_number']);
        $gst  = fa_assert_money($r['purchase_tax_gst'], 'purchase_tax_gst', $unit['unit_number']);
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
        continue;
    }

    $plan[] = ['unit' => $unit, 'cost' => $cost, 'gst' => $gst];
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($skipped) {
    printf("SKIPPED (already imported): %d\n", count($skipped));
    foreach (array_slice($skipped, 0, 10) as $s) {
        echo "  - {$s}\n";
    }
    if (count($skipped) > 10) {
        printf("  ... and %d more\n", count($skipped) - 10);
    }
    echo "\n";
}

if ($errors) {
    printf("ERRORS: %d — nothing will be written until these are resolved.\n", count($errors));
    foreach ($errors as $e) {
        echo "  ! {$e}\n";
    }
    echo "\n";
}

$totalCost = '0.00';
$totalGst  = '0.00';
$byCategory = [];
foreach ($plan as $p) {
    $totalCost = bcadd($totalCost, $p['cost'], 2);
    $totalGst  = bcadd($totalGst, $p['gst'], 2);
    $cat = $p['unit']['category'] ?? 'Uncategorized';
    if (!isset($byCategory[$cat])) {
        $byCategory[$cat] = ['n' => 0, 'cost' => '0.00'];
    }
    $byCategory[$cat]['n']++;
    $byCategory[$cat]['cost'] = bcadd($byCategory[$cat]['cost'], $p['cost'], 2);
}

printf("TO CREATE: %d asset(s)\n", count($plan));
ksort($byCategory);
foreach ($byCategory as $cat => $agg) {
    printf("  %-14s n=%-4d depreciable $%s\n", $cat, $agg['n'], number_format((float) $agg['cost'], 2));
}
printf("\n  Depreciable base total : $%s\n", number_format((float) $totalCost, 2));
printf("  GST recorded (non-depr): $%s\n", number_format((float) $totalGst, 2));
printf("  Gross                  : $%s\n\n", number_format((float) bcadd($totalCost, $totalGst, 2), 2));

printf("  acquisition_date : %s\n", FA_ACQUISITION_DATE);
printf("  method           : straight_line over %s years, salvage $%s\n", FA_USEFUL_LIFE, FA_SALVAGE);
printf("  GL accounts      : asset=%d accum=%d expense=%d\n\n", FA_ASSET_ACCOUNT, FA_ACCUM_ACCOUNT, FA_EXPENSE_ACCOUNT);

if ($errors) {
    fwrite(STDERR, "Aborting: resolve the errors above first.\n");
    exit(1);
}

if ($verify) {
    echo "--verify: report only, nothing written.\n";
    exit(0);
}

if (!$apply) {
    echo "DRY RUN — nothing written. Re-run with --apply to create these assets.\n";
    exit(0);
}

if (!$plan) {
    echo "Nothing to do.\n";
    exit(0);
}

// ── Apply ──────────────────────────────────────────────────────────────────
// WHY per-row try/catch rather than one big transaction: FixedAssetService::create()
// opens its own db_transaction() per asset and allocates an asset number from a
// locked settings counter. Wrapping the batch in an outer transaction would hold
// that lock for the whole run. Each asset is therefore independently atomic, and
// the idempotency check above makes a re-run after a partial failure safe.
$created = 0;
$failed  = [];

foreach ($plan as $p) {
    $unit = $p['unit'];
    $name = trim(sprintf('%s — %s', $unit['template'], $unit['unit_number']));

    try {
        $asset = FixedAssetService::create([
            'name'                    => $name,
            'description'             => sprintf(
                '%s%s. Imported from operator cost inventory.',
                $unit['category'] ?? 'Equipment',
                $unit['year'] ? ', model year ' . $unit['year'] : ''
            ),
            'asset_class'             => FA_ASSET_CLASS,
            'equipment_unit_id'       => (int) $unit['id'],
            'acquisition_date'        => FA_ACQUISITION_DATE,
            'depreciation_start_date' => FA_ACQUISITION_DATE,
            'acquisition_cost'        => $p['cost'],
            'purchase_tax_gst'        => $p['gst'],
            'purchase_tax_pst'        => '0.00',
            'delivery_cost'           => '0.00',
            'setup_cost'              => '0.00',
            'depreciation_method'     => 'straight_line',
            'useful_life_years'       => FA_USEFUL_LIFE,
            'salvage_value'           => FA_SALVAGE,
            'asset_account_id'        => FA_ASSET_ACCOUNT,
            'accum_depr_account_id'   => FA_ACCUM_ACCOUNT,
            'depr_expense_account_id' => FA_EXPENSE_ACCOUNT,
            'serial_number'           => $unit['vin'] ?: null,
            'location'                => $unit['yard_location'] ?: null,
            'notes'                   => FA_IMPORT_TAG,
            // These units were bought over roughly two decades and are only now
            // being booked. FA_ACQUISITION_DATE is when they entered the system,
            // not when cash moved, so keep them out of cash-flow acquisitions.
            'is_opening_balance'      => 1,
        ], $userId);

        $created++;
        printf("  + %-22s %s  $%s\n", $unit['unit_number'], $asset['asset_number'], $p['cost']);
    } catch (\Throwable $e) {
        $failed[] = sprintf('%s: %s', $unit['unit_number'], $e->getMessage());
    }
}

printf("\nCreated: %d\n", $created);
if ($failed) {
    printf("Failed : %d\n", count($failed));
    foreach ($failed as $f) {
        echo "  ! {$f}\n";
    }
    fwrite(STDERR, "\nRe-run the same command to retry only the failures (already-created units are skipped).\n");
    exit(1);
}

echo "\nDone. Depreciation has NOT been run — accumulated depreciation is \$0.00 on every asset.\n";
echo "Run it from Accounting -> Depreciation when you're ready to book it.\n";
