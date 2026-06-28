<?php
declare(strict_types=1);

/**
 * scripts/bill_missing_hours_2026_06_28.php
 *
 * ONE-OFF REMEDIATION (S-LEASE-HOURLY-RECON) — bill the engine hours that the
 * close-reconciliation bug dropped on two production DRAFT invoices.
 *
 * Both invoices belong to completed hourly leases (hourly_rate>0, engine hours
 * recorded start→end) but carry NO `hourly_usage` line because the close path
 * that should have billed the hours either reissued the invoice without the
 * engine-hours window (INV-466 — overshoot reissue) or skipped the hours-bearing
 * partial_end final entirely (INV-650 — activation invoice already covered the
 * extent). The code fix lands for FUTURE closes; these two pre-existing drafts
 * have no hours snapshot to recover from, so we add the line here.
 *
 * Targets (production ids — verified read-only 2026-06-28):
 *   inv 448  INV-2026-00466  lease 223  MTTS0262  8967→8998 = 31.00 hrs × $1.50 = $46.50
 *   inv 632  INV-2026-00650  lease 318  MTTS176   22530→22532 = 2.00 hrs × $1.50 = $3.00
 *
 * METHOD (mirrors api/v1/invoices/update_lines.php — the supported draft-edit path):
 *   1. Insert ONE `hourly_usage` line, shaped exactly like InvoiceGenerator emits
 *      it (so it's indistinguishable from an engine-billed line).
 *   2. Snapshot engine_hours_at_period_start/end + period_engine_hours on the
 *      invoice (audit + lets a future regenerate preserve the hours).
 *   3. Recompute subtotal/tax/total authoritatively via InvoiceRecalc::recalc().
 *   4. Audit row.
 *
 * SAFETY:
 *   - DRY-RUN by default; pass --apply to write. Each invoice in its own txn.
 *   - STRICT preconditions per target: must be the exact (id, invoice_number,
 *     lease_id, contract) tuple, status=draft, lease hourly_rate>0 with both
 *     engine-hour readings, the invoice period must COVER the whole lease
 *     (start→return) so lease hours == period hours, and NO hourly_usage line
 *     may already exist. Any mismatch SKIPS that invoice (never guesses).
 *   - Idempotent: re-running after success is a no-op (the existing-line guard).
 *
 * Run:  php scripts/bill_missing_hours_2026_06_28.php            (dry-run)
 *       php scripts/bill_missing_hours_2026_06_28.php --apply    (write)
 *
 * @session S-LEASE-HOURLY-RECON
 */

// Locate the app root whether this script runs from the repo's scripts/ dir or
// from an arbitrary location on prod (it may be copied to a writable home dir if
// the deploy-owned scripts/ dir is read-only to the operator's shell user).
$appRoot = is_file(dirname(__DIR__) . '/config/app.php')
    ? dirname(__DIR__)
    : (getenv('FF_APP_ROOT') ?: '/var/www/fleetforge');
require_once $appRoot . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Billing\InvoiceRecalc;

$APPLY = in_array('--apply', $argv, true);

// Exact production targets. Each tuple is fully asserted before any write.
$TARGETS = [
    ['inv_id' => 448, 'inv_no' => 'INV-2026-00466', 'lease_id' => 223, 'contract' => 'MTTS0262'],
    ['inv_id' => 632, 'inv_no' => 'INV-2026-00650', 'lease_id' => 318, 'contract' => 'MTTS176'],
];

// Audit/updated_by actor — the first super_admin (CLI has no session).
$actor = db_row(
    "SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id=u.role_id
      WHERE ur.slug='super_admin' AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1"
);
$actorId   = (int) ($actor['id'] ?? 0);
$actorName = (string) ($actor['name'] ?? 'system');
if (!$actorId) { fwrite(STDERR, "FATAL: no super_admin user found for the audit actor.\n"); exit(2); }

echo str_repeat('=', 78) . "\n";
echo "BILL MISSING ENGINE HOURS — " . ($APPLY ? "\033[31mAPPLY (writing)\033[0m" : "DRY-RUN (no writes)") . "\n";
echo "Actor: {$actorName} (#{$actorId})\n";
echo str_repeat('=', 78) . "\n\n";

$done = 0; $skipped = 0; $errors = 0;

foreach ($TARGETS as $t) {
    $invId = $t['inv_id'];
    echo "── {$t['inv_no']} (inv #{$invId}, lease {$t['contract']}) ───────────────────\n";

    try {
        $inv = db_row(
            "SELECT id, invoice_number, lease_id, status, billing_period_start, billing_period_end,
                    subtotal, total_amount, currency
               FROM invoices WHERE id = ? AND deleted_at IS NULL",
            [$invId]
        );
        $lease = db_row(
            "SELECT id, contract_number, status, start_date, actual_return_date,
                    hourly_rate, engine_hours_at_start, engine_hours_at_end
               FROM leases WHERE id = ? AND deleted_at IS NULL",
            [$t['lease_id']]
        );

        // ── Strict preconditions — refuse to touch anything that doesn't match. ──
        $why = null;
        if (!$inv)                                              $why = "invoice not found";
        elseif ($inv['invoice_number'] !== $t['inv_no'])       $why = "invoice_number mismatch ({$inv['invoice_number']})";
        elseif ((int) $inv['lease_id'] !== $t['lease_id'])     $why = "lease_id mismatch ({$inv['lease_id']})";
        elseif ($inv['status'] !== 'draft')                    $why = "invoice is '{$inv['status']}' not draft";
        elseif (!$lease)                                        $why = "lease not found";
        elseif ($lease['contract_number'] !== $t['contract'])  $why = "contract mismatch ({$lease['contract_number']})";
        elseif (bccomp((string) ($lease['hourly_rate'] ?? '0'), '0', 4) <= 0) $why = "lease hourly_rate not > 0";
        elseif ($lease['engine_hours_at_start'] === null || $lease['engine_hours_at_end'] === null) $why = "lease engine hours not set";
        if ($why) { echo "  SKIP — {$why}\n\n"; $skipped++; continue; }

        // The invoice must cover the WHOLE lease (start→return) so the lease's
        // start→end hours equal this period's hours (no risk of over/under-billing
        // a multi-period lease — neither target is multi-period, this asserts it).
        if ($inv['billing_period_start'] !== $lease['start_date']
            || $inv['billing_period_end'] !== $lease['actual_return_date']) {
            echo "  SKIP — invoice period ({$inv['billing_period_start']}→{$inv['billing_period_end']}) "
               . "does not match lease span ({$lease['start_date']}→{$lease['actual_return_date']}); "
               . "hours would be ambiguous.\n\n";
            $skipped++; continue;
        }

        // Idempotency: never add a second hourly line.
        $existing = db_row(
            "SELECT COUNT(*) c FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'hourly_usage'",
            [$invId]
        );
        if ((int) ($existing['c'] ?? 0) > 0) { echo "  SKIP — hourly_usage line already present (already fixed).\n\n"; $skipped++; continue; }

        // ── Compute the line exactly as InvoiceGenerator would. ──
        $hStart = (string) $lease['engine_hours_at_start'];
        $hEnd   = (string) $lease['engine_hours_at_end'];
        $rateHr = (string) $lease['hourly_rate'];
        $hDiff  = bcsub($hEnd, $hStart, 2);
        $periodHours = bccomp($hDiff, '0', 2) >= 0 ? $hDiff : '0.00';
        if (bccomp($periodHours, '0', 2) <= 0) { echo "  SKIP — zero/negative period hours ({$periodHours}).\n\n"; $skipped++; continue; }
        $amount = bcround(bcmul($periodHours, $rateHr, 6), 2);
        $desc   = 'Engine hours: ' . number_format((float) $periodHours, 2) . ' hrs × $' . $rateHr . '/hr';

        $nextSort = (int) (db_row("SELECT COALESCE(MAX(sort_order),0)+1 n FROM invoice_line_items WHERE invoice_id = ?", [$invId])['n'] ?? 1);

        echo "  lease {$lease['contract_number']}: {$hStart} → {$hEnd} = {$periodHours} hrs × \${$rateHr} = \${$amount}\n";
        echo "  line: [{$desc}]  taxable=1\n";
        echo "  current total: \${$inv['total_amount']}  (subtotal \${$inv['subtotal']})\n";

        if (!$APPLY) {
            // Show the projected total without writing: simulate by adding the line in a rolled-back txn.
            db_execute("BEGIN");
            db_insert('invoice_line_items', [
                'invoice_id' => $invId, 'sort_order' => $nextSort, 'item_type' => 'hourly_usage',
                'description' => $desc, 'quantity' => $periodHours, 'unit' => 'hours',
                'unit_price' => $rateHr, 'amount' => $amount, 'is_credit' => 0, 'taxable' => 1,
                'period_start' => $inv['billing_period_start'], 'period_end' => $inv['billing_period_end'],
            ]);
            $proj = InvoiceRecalc::recalc($invId);
            db_execute("ROLLBACK");
            echo "  → projected total: \${$proj['total_amount']}  (subtotal \${$proj['subtotal']}, tax \${$proj['tax_total']})\n";
            echo "  (dry-run — nothing written)\n\n";
            $done++;
            continue;
        }

        // ── APPLY ──
        $result = db_transaction(function () use ($invId, $inv, $lease, $nextSort, $desc, $periodHours, $rateHr, $amount, $hStart, $hEnd, $actorId, $actorName) {
            db_insert('invoice_line_items', [
                'invoice_id' => $invId, 'sort_order' => $nextSort, 'item_type' => 'hourly_usage',
                'description' => $desc, 'quantity' => $periodHours, 'unit' => 'hours',
                'unit_price' => $rateHr, 'amount' => $amount, 'is_credit' => 0, 'taxable' => 1,
                'period_start' => $inv['billing_period_start'], 'period_end' => $inv['billing_period_end'],
            ]);
            db_update('invoices', [
                'engine_hours_at_period_start' => $hStart,
                'engine_hours_at_period_end'   => $hEnd,
                'period_engine_hours'          => $periodHours,
                'updated_by'                   => $actorId,
            ], 'id = ?', [$invId]);

            $totals = InvoiceRecalc::recalc($invId);

            db_insert('audit_log', [
                'user_id'      => $actorId,
                'user_name'    => $actorName,
                'action'       => 'update',
                'module'       => 'invoices',
                'entity_type'  => 'invoice',
                'entity_id'    => $invId,
                'entity_label' => $inv['invoice_number'],
                'notes'        => "S-LEASE-HOURLY-RECON remediation: added the missing hourly_usage line "
                                . "({$periodHours} hrs × \${$rateHr} = \${$amount}) that the close-reconciliation bug dropped. "
                                . "Total {$inv['total_amount']} → {$totals['total_amount']}.",
                'old_values'   => json_encode(['total_amount' => $inv['total_amount']]),
                'new_values'   => json_encode(['total_amount' => $totals['total_amount']]),
                'ip_address'   => '127.0.0.1',
            ]);
            return $totals;
        });

        echo "  \033[32m✓ APPLIED\033[0m — new total: \${$result['total_amount']}  (subtotal \${$result['subtotal']}, tax \${$result['tax_total']})\n\n";
        $done++;

    } catch (\Throwable $e) {
        echo "  \033[31mERROR\033[0m — {$e->getMessage()}\n\n";
        $errors++;
    }
}

echo str_repeat('=', 78) . "\n";
printf("%s — processed %d, skipped %d, errors %d\n",
    $APPLY ? 'APPLY COMPLETE' : 'DRY-RUN COMPLETE', $done, $skipped, $errors);
if (!$APPLY) echo "Re-run with --apply to write.\n";
exit($errors > 0 ? 1 : 0);
