<?php
declare(strict_types=1);

/**
 * scripts/fix_mileage_rate_conversion_2026_06_30.php
 *
 * ONE-OFF REMEDIATION (S-MILEAGE-RATE-CONVERT-FIX). Two parts:
 *
 *  PART 1 — BACKFILL the inverted dual-unit mileage rate columns on every lease:
 *    miles lease: mileage_rate_km    = mileage_rate × km_to_miles (0.621371)  [was ×1.609344]
 *    km    lease: mileage_rate_miles = mileage_rate × miles_to_km (1.609344)
 *    (the primary mileage_rate is already correct in the lease's unit). Only rows
 *    whose stored value is wrong are touched (idempotent).
 *
 *  PART 2 — RECOMPUTE the DRAFT `mileage_usage` lines that were billed with the
 *    inflated rate (≈2.59× on miles leases). For each, re-bill in the lease's unit
 *    from the original km distance × the (now-corrected) per-km rate, relabel via
 *    ff_mileage_line_display(), and recompute invoice totals via InvoiceRecalc.
 *    SAFETY: only status='draft' invoices are touched (nothing sent/paid exists);
 *    only leases with precharge_enabled=0 (no drawdown credit to keep in sync).
 *
 * DRY-RUN by default; --apply to write. Idempotent. Run from a writable copy.
 *   php scripts/fix_mileage_rate_conversion_2026_06_30.php            (dry-run)
 *   php scripts/fix_mileage_rate_conversion_2026_06_30.php --apply    (write)
 *
 * @session S-MILEAGE-RATE-CONVERT-FIX
 */

$appRoot = is_file(dirname(__DIR__) . '/config/app.php') ? dirname(__DIR__) : (getenv('FF_APP_ROOT') ?: '/var/www/fleetforge');
require_once $appRoot . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Billing\InvoiceRecalc;

// Self-contained copy of includes/functions.php::ff_mileage_line_display so this
// script runs BEFORE the code fix is deployed (prod's functions.php may not have it
// yet). The function_exists guard makes it a no-op once the deploy lands.
if (!function_exists('ff_mileage_line_display')) {
    function ff_mileage_line_display(array $lease, string $distanceKm, string $rateKm): array {
        $isMiles = (($lease['mileage_unit'] ?? 'km') === 'miles');
        if (!$isMiles) return ['distance' => $distanceKm, 'rate' => $rateKm, 'unit' => 'km', 'rate_unit' => 'km'];
        $kmToMiles = (string) ($lease['km_to_miles_conversion'] ?? '0.621371');
        if (bccomp($kmToMiles, '0', 6) <= 0) $kmToMiles = '0.621371';
        $distanceMiles = bcround(bcmul($distanceKm, $kmToMiles, 8), 2);
        $rateMile = (isset($lease['mileage_rate']) && $lease['mileage_rate'] !== null && bccomp((string) $lease['mileage_rate'], '0', 4) > 0)
            ? (string) $lease['mileage_rate']
            : bcround(bcmul($rateKm, (string) ($lease['miles_to_km_conversion'] ?? '1.609344'), 8), 4);
        return ['distance' => $distanceMiles, 'rate' => $rateMile, 'unit' => 'miles', 'rate_unit' => 'mile'];
    }
}

$APPLY = in_array('--apply', $argv, true);
$DKM = '0.621371'; $DMK = '1.609344';

$actor = db_row("SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1");
$actorId = (int) ($actor['id'] ?? 0); $actorName = (string) ($actor['name'] ?? 'system');
if (!$actorId) { fwrite(STDERR, "FATAL: no super_admin.\n"); exit(2); }

echo str_repeat('=', 78) . "\nMILEAGE RATE CONVERSION FIX — " . ($APPLY ? "\033[31mAPPLY\033[0m" : "DRY-RUN") . "\n" . str_repeat('=', 78) . "\n";

// ── PART 1 — backfill the dual-unit rate columns ──────────────────────────
echo "\n── PART 1: rate-column backfill ──\n";
$rateFixes = 0;
$leases = db_select(
    "SELECT id, contract_number, mileage_unit, mileage_rate, mileage_rate_km, mileage_rate_miles,
            COALESCE(NULLIF(km_to_miles_conversion,0), ?) AS k2m,
            COALESCE(NULLIF(miles_to_km_conversion,0), ?) AS m2k
       FROM leases WHERE deleted_at IS NULL AND mileage_rate IS NOT NULL AND mileage_rate > 0",
    [$DKM, $DMK]
);
foreach ($leases as $l) {
    if ($l['mileage_unit'] === 'miles') {
        $correct = bcround(bcmul((string) $l['mileage_rate'], (string) $l['k2m'], 8), 4);
        $col = 'mileage_rate_km'; $cur = (string) $l['mileage_rate_km'];
    } else {
        $correct = bcround(bcmul((string) $l['mileage_rate'], (string) $l['m2k'], 8), 4);
        $col = 'mileage_rate_miles'; $cur = (string) $l['mileage_rate_miles'];
    }
    if (bccomp($cur, $correct, 4) === 0) continue;             // already correct → idempotent skip
    $rateFixes++;
    if ($rateFixes <= 5) echo "  {$l['contract_number']} ({$l['mileage_unit']}): {$col} {$cur} → {$correct}\n";
    if ($APPLY) db_execute("UPDATE leases SET {$col} = ?, updated_at = NOW() WHERE id = ?", [$correct, (int) $l['id']]);
}
echo "  " . ($rateFixes > 5 ? "… and " . ($rateFixes - 5) . " more. " : "") . "{$rateFixes} lease rate column(s) " . ($APPLY ? "updated." : "would update.") . "\n";

// ── PART 2 — recompute the over-billed DRAFT mileage_usage lines ──────────
echo "\n── PART 2: recompute draft mileage_usage lines (miles leases) ──\n";
$lineFixes = 0;
$lines = db_select(
    "SELECT li.id AS line_id, li.invoice_id, li.mileage_distance, li.quantity, li.amount AS old_amount, li.unit AS line_unit,
            i.invoice_number, i.period_distance_km, l.id AS lease_id, l.contract_number, l.mileage_unit,
            l.mileage_rate, l.mileage_rate_km, l.precharge_enabled,
            COALESCE(NULLIF(l.km_to_miles_conversion,0), ?) AS k2m,
            COALESCE(NULLIF(l.miles_to_km_conversion,0), ?) AS m2k
       FROM invoice_line_items li
       JOIN invoices i ON i.id = li.invoice_id AND i.deleted_at IS NULL AND i.status = 'draft'
       JOIN leases l   ON l.id = i.lease_id
      WHERE li.item_type = 'mileage_usage' AND l.mileage_unit = 'miles' AND l.deleted_at IS NULL",
    [$DKM, $DMK]
);
$touchedInvoices = [];
foreach ($lines as $ln) {
    if ((int) $ln['precharge_enabled'] !== 0) { echo "  SKIP {$ln['invoice_number']} — precharge lease (drawdown sync needed; none expected).\n"; continue; }
    if (($ln['line_unit'] ?? '') === 'miles') { continue; } // already corrected → idempotent skip
    // Original km distance: invoice.period_distance_km is the canonical source; older
    // lines left mileage_distance NULL but stored the km figure in quantity.
    $distanceKm = ($ln['period_distance_km'] !== null && bccomp((string) $ln['period_distance_km'], '0', 2) > 0)
        ? (string) $ln['period_distance_km']
        : ((bccomp((string) ($ln['mileage_distance'] ?? '0'), '0', 2) > 0) ? (string) $ln['mileage_distance'] : (string) $ln['quantity']);
    if (bccomp($distanceKm, '0', 2) <= 0) { echo "  SKIP {$ln['invoice_number']} — no resolvable distance.\n"; continue; }
    // Use the lease row, whose mileage_rate_km is corrected (in --apply Part 1 ran above;
    // in dry-run we recompute the corrected rate locally so the projection is accurate).
    $leaseForDisp = [
        'mileage_unit' => 'miles', 'mileage_rate' => $ln['mileage_rate'],
        'km_to_miles_conversion' => $ln['k2m'], 'miles_to_km_conversion' => $ln['m2k'],
    ];
    $rateKmCorrect = $APPLY
        ? (string) db_row("SELECT mileage_rate_km FROM leases WHERE id=?", [(int) $ln['lease_id']])['mileage_rate_km']
        : bcround(bcmul((string) $ln['mileage_rate'], (string) $ln['k2m'], 8), 4);
    $disp = ff_mileage_line_display($leaseForDisp, $distanceKm, $rateKmCorrect);
    $newAmount = bcround(bcmul((string) $disp['distance'], (string) $disp['rate'], 6), 2);
    $desc = 'Mileage usage: ' . number_format((float) $disp['distance'], 2) . ' ' . $disp['unit'] . ' × $' . $disp['rate'] . '/' . $disp['rate_unit'];
    $lineFixes++;
    if ($lineFixes <= 6) echo "  {$ln['invoice_number']} ({$ln['contract_number']}): \${$ln['old_amount']} → \${$newAmount}  [{$disp['distance']} {$disp['unit']} × \${$disp['rate']}]\n";
    if ($APPLY) {
        db_update('invoice_line_items', [
            'quantity' => $disp['distance'], 'unit' => $disp['unit'], 'unit_price' => $disp['rate'],
            'amount' => $newAmount, 'description' => $desc,
            'mileage_distance' => $disp['distance'], 'mileage_rate' => $disp['rate'], 'mileage_unit' => $disp['unit'],
        ], 'id = ?', [(int) $ln['line_id']]);
        $touchedInvoices[(int) $ln['invoice_id']] = $ln['invoice_number'];
    }
}
echo "  " . ($lineFixes > 6 ? "… and " . ($lineFixes - 6) . " more. " : "") . "{$lineFixes} draft mileage line(s) " . ($APPLY ? "corrected." : "would correct.") . "\n";

if ($APPLY && $touchedInvoices) {
    echo "\n── Recompute totals (InvoiceRecalc) + audit ──\n";
    foreach ($touchedInvoices as $invId => $invNo) {
        $old = db_row("SELECT total_amount FROM invoices WHERE id=?", [$invId])['total_amount'];
        $t = InvoiceRecalc::recalc($invId);
        db_insert('audit_log', [
            'user_id' => $actorId, 'user_name' => $actorName, 'action' => 'update', 'module' => 'invoices',
            'entity_type' => 'invoice', 'entity_id' => $invId, 'entity_label' => $invNo,
            'notes' => "S-MILEAGE-RATE-CONVERT-FIX remediation: recomputed the mileage line in the lease's unit (corrected the inverted mileage_rate_km ~2.59x over-bill). Total {$old} → {$t['total_amount']}.",
            'old_values' => json_encode(['total_amount' => $old]), 'new_values' => json_encode(['total_amount' => $t['total_amount']]),
            'ip_address' => '127.0.0.1',
        ]);
        echo "  {$invNo}: total → \${$t['total_amount']}\n";
    }
}

echo "\n" . str_repeat('=', 78) . "\n";
echo ($APPLY ? "APPLY COMPLETE" : "DRY-RUN COMPLETE") . " — {$rateFixes} rate column(s), {$lineFixes} draft line(s).\n";
if (!$APPLY) echo "Re-run with --apply to write.\n";
exit(0);
