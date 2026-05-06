<?php
declare(strict_types=1);

/**
 * scripts/mileage_allowance_zero_fix_2026_05_07.php
 *
 * S-MILEAGE-ALLOWANCE-ZERO-FIX C2 (combined with C3 per the
 * S-MILEAGE-RATE-VALIDATION C3 atomic-closure precedent) —
 * one-shot remediation of the engine-fix-exposed silent-skip
 * on lease 52 (MTTS-GJEMC7-2026):
 *
 *   1. Void INV-2026-00089 (id=95). True duplicate-period draft —
 *      no period_distance_km recorded; supplanted by INV-2026-00090.
 *      Surfaced by S-INVOICE-CREATION-UX C2 form auto-fill guard.
 *      void_reason cites the C2 finding; counter movement per Path B
 *      (draft→void: customer OB unchanged, lease.total_invoiced -=
 *      total_amount).
 *
 *   2. Void INV-2026-00090 (id=96). Silent-skip victim — period
 *      distance 507.04 km against rate $0.18/km but excess_charge=$0
 *      under the pre-C1 engine guard. Period also overshot lease
 *      end_date 2026-05-30 by 1 day (will be capped on regen).
 *      void_reason cites both findings.
 *
 *   3. Regen replacement invoice via InvoiceGenerator::createFromLease().
 *      Period 2026-05-06..2026-05-30 (capped at lease.end_date per
 *      S-INVOICE-CREATION-UX C2). odometer_at_period_start_km = 3492.96
 *      (lease.odometer_start_km), odometer_at_period_end_km = 4000.00
 *      (matches INV-90's recorded value), period_distance_km = 507.04.
 *      Post-C1 engine path produces excess_charge_amount=$91.27,
 *      excess_distance_km=507.04, mileage_review_status='pending'.
 *
 *   4. Auto-approve the mileage review on the regen (D-C Option B).
 *      Inserts the mileage_adjustment line item ($91.27 + tax),
 *      recomputes invoice totals (subtotal $2491.27, tax ≈ $300,
 *      total ≈ $2790), sets mileage_review_status='approved'.
 *      Audit_log row records the auto-approval with structured reason.
 *
 * SAFETY:
 *   - Default mode is --dry-run. Writes nothing, exits 0.
 *   - --execute prints the proposed diff first, then prompts for
 *     the literal string 'yes'.
 *   - Single db_transaction wraps all writes — full rollback on any error.
 *   - Idempotent: re-running with --execute on already-corrected state
 *     writes zero rows (each step has a `needs work?` guard).
 *
 * USAGE:
 *   php scripts/mileage_allowance_zero_fix_2026_05_07.php --dry-run   (default)
 *   php scripts/mileage_allowance_zero_fix_2026_05_07.php --execute   (prompts 'yes')
 *
 * @session    S-MILEAGE-ALLOWANCE-ZERO-FIX C2 (2026-05-07)
 * @decisions  D-C (regen + void path), D-C Option B (auto-approve in script),
 *             D14 (void of unsent invoice permitted), Path B counter semantics
 * @spec       FLEETFORGE_PROGRESS.md SESSION LOG entry for S-MILEAGE-ALLOWANCE-ZERO-FIX
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Billing\InvoiceGenerator;

// ────────────────────────────────────────────────────────────────────────────
// Argument parsing
// ────────────────────────────────────────────────────────────────────────────
$args = array_slice($argv ?? [], 1);
$mode = 'dry-run';
foreach ($args as $arg) {
    if      ($arg === '--execute') $mode = 'execute';
    elseif  ($arg === '--dry-run') $mode = 'dry-run';
    else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        fwrite(STDERR, "Usage: php scripts/mileage_allowance_zero_fix_2026_05_07.php [--dry-run|--execute]\n");
        exit(2);
    }
}

echo "═══ S-MILEAGE-ALLOWANCE-ZERO-FIX C2/C3 — INV-89/INV-90 void + regen ═══\n";
echo "mode: {$mode}\n\n";

// ────────────────────────────────────────────────────────────────────────────
// Pre-flight read of state
// ────────────────────────────────────────────────────────────────────────────
$lease = db_row(
    "SELECT id, contract_number, status, start_date, end_date, actual_return_date,
            mileage_rate_km, estimated_mileage_km, precharge_enabled,
            customer_id, equipment_unit_id, odometer_start_km,
            total_invoiced
     FROM leases WHERE id = 52"
);
if (!$lease) {
    fwrite(STDERR, "ERROR: lease 52 not found.\n");
    exit(1);
}

$inv89 = db_row(
    "SELECT id, invoice_number, status, billing_period_start, billing_period_end,
            period_distance_km, total_amount, balance_due, void_reason
     FROM invoices WHERE invoice_number = 'INV-2026-00089' AND deleted_at IS NULL"
);
$inv90 = db_row(
    "SELECT id, invoice_number, status, billing_period_start, billing_period_end,
            period_distance_km, total_amount, balance_due, void_reason
     FROM invoices WHERE invoice_number = 'INV-2026-00090' AND deleted_at IS NULL"
);

echo "Lease 52 ({$lease['contract_number']}): rate=\${$lease['mileage_rate_km']}/km, allowance={$lease['estimated_mileage_km']} km, end_date={$lease['end_date']}, total_invoiced=\${$lease['total_invoiced']}\n";
echo "INV-2026-00089: " . ($inv89 ? "id={$inv89['id']}, status={$inv89['status']}, period={$inv89['billing_period_start']}..{$inv89['billing_period_end']}, dist=" . ($inv89['period_distance_km'] ?? 'NULL') . " km, total=\${$inv89['total_amount']}" : "NOT FOUND") . "\n";
echo "INV-2026-00090: " . ($inv90 ? "id={$inv90['id']}, status={$inv90['status']}, period={$inv90['billing_period_start']}..{$inv90['billing_period_end']}, dist=" . ($inv90['period_distance_km'] ?? 'NULL') . " km, total=\${$inv90['total_amount']}" : "NOT FOUND") . "\n";

if (!$inv89 || !$inv90) {
    fwrite(STDERR, "\nERROR: one or both target invoices not found. Aborting.\n");
    exit(1);
}

$step1Needed = $inv89['status'] !== 'void';
$step2Needed = $inv90['status'] !== 'void';
$step3Needed = !db_exists('invoices',
    "lease_id = 52 AND deleted_at IS NULL AND status IN ('draft','sent') AND billing_period_start = '2026-05-06' AND billing_period_end = '2026-05-30' AND period_distance_km = 507.04",
    []
);

echo "\nProposed changes:\n";
echo "  Step 1 (void INV-2026-00089):    " . ($step1Needed ? "YES" : "skip — already void") . "\n";
echo "  Step 2 (void INV-2026-00090):    " . ($step2Needed ? "YES" : "skip — already void") . "\n";
echo "  Step 3 (regen capped 05-06..05-30 with mileage line): " . ($step3Needed ? "YES" : "skip — replacement already exists") . "\n";

if (!$step1Needed && !$step2Needed && !$step3Needed) {
    echo "\nAll steps already complete. Nothing to do.\n";
    exit(0);
}

if ($mode === 'dry-run') {
    echo "\n(dry-run) No writes performed. Re-run with --execute to apply.\n";
    exit(0);
}

// ────────────────────────────────────────────────────────────────────────────
// Confirmation prompt
// ────────────────────────────────────────────────────────────────────────────
echo "\nType 'yes' to apply these changes: ";
$response = trim((string) fgets(STDIN));
if ($response !== 'yes') {
    echo "Aborted (response was: '{$response}').\n";
    exit(1);
}

// ────────────────────────────────────────────────────────────────────────────
// Execute — single transaction
// ────────────────────────────────────────────────────────────────────────────
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$replacementId = null;
$replacementNumber = null;

db_transaction(function () use ($inv89, $inv90, $lease, $step1Needed, $step2Needed, $step3Needed, $now, $today, &$replacementId, &$replacementNumber) {
    // ── Step 1 — void INV-2026-00089 ─────────────────────────────────
    if ($step1Needed) {
        $voidReason89 = "S-MILEAGE-ALLOWANCE-ZERO-FIX C2 — duplicate-period draft superseded by INV-2026-00090 regen. " .
            "Lease 52 had two drafts covering same 2026-05-06..2026-05-31 period (no period_distance recorded on this one). " .
            "Flagged by S-INVOICE-CREATION-UX C2 form auto-fill defensive guard 2026-05-07.";

        db_execute(
            "UPDATE invoices SET status='void', voided_date=?, void_reason=?, balance_due='0.00', updated_at=? WHERE id=?",
            [$today, $voidReason89, $now, $inv89['id']]
        );

        // Path B counter: draft→void = decOb=0, leases.total_invoiced -= total_amount
        db_execute(
            "UPDATE leases SET total_invoiced = total_invoiced - ? WHERE id = 52",
            [$inv89['total_amount']]
        );

        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system (S-MILEAGE-ALLOWANCE-ZERO-FIX)',
            'action'       => 'invoice_voided',
            'module'       => 'invoices',
            'entity_type'  => 'invoice',
            'entity_id'    => $inv89['id'],
            'entity_label' => $inv89['invoice_number'],
            'notes'        => $voidReason89,
            'old_values'   => json_encode(['status' => $inv89['status'], 'balance_due' => $inv89['balance_due']]),
            'new_values'   => json_encode(['status' => 'void', 'balance_due' => '0.00']),
            'ip_address'   => '127.0.0.1',
        ]);

        echo "  Step 1: voided INV-2026-00089 (id={$inv89['id']}, total=\${$inv89['total_amount']} subtracted from lease.total_invoiced)\n";
    }

    // ── Step 2 — void INV-2026-00090 ─────────────────────────────────
    if ($step2Needed) {
        $voidReason90 = "S-MILEAGE-ALLOWANCE-ZERO-FIX C2 — voided to regenerate post engine-guard fix. " .
            "Pre-fix engine silent-skipped the mileage block (rate=\$0.18, allowance=0, distance=507.04 km) " .
            "producing no \$91.27 mileage line. Period also overshot lease end_date 2026-05-30 by 1 day (capped on regen per S-INVOICE-CREATION-UX C2). " .
            "Closes KNOWN ISSUE #103 silent-skip case for lease 52.";

        db_execute(
            "UPDATE invoices SET status='void', voided_date=?, void_reason=?, balance_due='0.00', updated_at=? WHERE id=?",
            [$today, $voidReason90, $now, $inv90['id']]
        );

        db_execute(
            "UPDATE leases SET total_invoiced = total_invoiced - ? WHERE id = 52",
            [$inv90['total_amount']]
        );

        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system (S-MILEAGE-ALLOWANCE-ZERO-FIX)',
            'action'       => 'invoice_voided',
            'module'       => 'invoices',
            'entity_type'  => 'invoice',
            'entity_id'    => $inv90['id'],
            'entity_label' => $inv90['invoice_number'],
            'notes'        => $voidReason90,
            'old_values'   => json_encode(['status' => $inv90['status'], 'balance_due' => $inv90['balance_due']]),
            'new_values'   => json_encode(['status' => 'void', 'balance_due' => '0.00']),
            'ip_address'   => '127.0.0.1',
        ]);

        echo "  Step 2: voided INV-2026-00090 (id={$inv90['id']}, total=\${$inv90['total_amount']} subtracted from lease.total_invoiced)\n";
    }

    // ── Step 3 — regen replacement (capped period + mileage line) ─────
    if ($step3Needed) {
        $generator = new InvoiceGenerator();
        $result = $generator->createFromLease([
            'lease_id'                    => 52,
            'period_start'                => '2026-05-06',
            'period_end'                  => '2026-05-30',  // capped at lease.end_date
            'billing_type'                => 'partial_end',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '3492.96',     // matches lease odometer_start
            'odometer_at_period_end_km'   => '4000.00',     // matches INV-90's recorded value → 507.04 km
            'odometer_source'             => 'manual',
            'created_by'                  => null,
            'generation_source'           => 'manual',
            'notes'                       => 'Replacement for voided INV-2026-00090 (S-MILEAGE-ALLOWANCE-ZERO-FIX engine fix).',
            'internal_notes'              => 'Regenerated 2026-05-07 to apply post-C1 mileage charge (rate=$0.18 × 507.04 km = $91.27). Period capped at lease.end_date 2026-05-30 (was 2026-05-31 in INV-90). Auto-approved mileage review at end of script per D-C Option B.',
        ]);
        $replacementId = $result['invoice_id'];
        $replacementNumber = $result['invoice_number'];

        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system (S-MILEAGE-ALLOWANCE-ZERO-FIX)',
            'action'       => 'create',
            'module'       => 'invoices',
            'entity_type'  => 'invoice',
            'entity_id'    => $replacementId,
            'entity_label' => $replacementNumber,
            'notes'        => "Regen replacement for voided INV-2026-00089 + INV-2026-00090. Period 2026-05-06..2026-05-30 (capped). Distance 507.04 km. Engine fix S-MILEAGE-ALLOWANCE-ZERO-FIX C1 produces mileage_review_status='pending' with excess_charge_amount=\$91.27.",
            'ip_address'   => '127.0.0.1',
        ]);

        echo "  Step 3: regen created {$replacementNumber} (id={$replacementId}, total=\${$result['total_amount']})\n";

        // ── Step 3b — auto-approve mileage review (D-C Option B) ────
        // Mirrors api/v1/invoices/review_mileage.php approve path.
        $newInv = db_row("SELECT * FROM invoices WHERE id = ?", [$replacementId]);
        $excessKm     = (string) ($newInv['excess_distance_km'] ?? '0');
        $appliedAmount = (string) ($newInv['excess_charge_amount'] ?? '0');

        if (bccomp($appliedAmount, '0', 2) > 0) {
            $description = "Excess mileage charge: {$excessKm} km @ \${$lease['mileage_rate_km']}/km (Model B Lite — every km billable, no allowance configured)";

            $maxSort = db_row(
                "SELECT COALESCE(MAX(sort_order), 0) AS max_sort FROM invoice_line_items WHERE invoice_id = ?",
                [$replacementId]
            );
            $nextSort = (int) ($maxSort['max_sort'] ?? 0) + 1;

            $isTaxable  = true;
            $gstRate    = (string) ($newInv['tax_gst_rate'] ?? '0');
            $pstRate    = (string) ($newInv['tax_pst_rate'] ?? '0');
            $hstRate    = (string) ($newInv['tax_hst_rate'] ?? '0');

            $lineGst = $isTaxable && bccomp($gstRate, '0', 4) > 0
                ? bcdiv(bcmul($appliedAmount, $gstRate, 6), '100', 2) : '0.00';
            $linePst = $isTaxable && bccomp($pstRate, '0', 4) > 0
                ? bcdiv(bcmul($appliedAmount, $pstRate, 6), '100', 2) : '0.00';
            $lineHst = $isTaxable && bccomp($hstRate, '0', 4) > 0
                ? bcdiv(bcmul($appliedAmount, $hstRate, 6), '100', 2) : '0.00';

            $unitPrice = bccomp($excessKm, '0', 2) > 0
                ? bcdiv($appliedAmount, $excessKm, 2)
                : '0.00';

            db_insert('invoice_line_items', [
                'invoice_id'      => $replacementId,
                'item_type'       => 'mileage_adjustment',
                'description'     => $description,
                'quantity'        => $excessKm,
                'unit'            => 'km',
                'unit_price'      => $unitPrice,
                'amount'          => $appliedAmount,
                'taxable'         => 1,
                'tax_gst_amount'  => $lineGst,
                'tax_pst_amount'  => $linePst,
                'tax_hst_amount'  => $lineHst,
                'mileage_distance'=> $excessKm,
                'mileage_unit'    => 'km',
                'mileage_rate'    => (string) $lease['mileage_rate_km'],
                'reference_type'  => 'mileage_review',
                'reference_id'    => $replacementId,
                'sort_order'      => $nextSort,
            ]);

            // Recompute invoice totals
            $newSubtotal           = bcadd((string) $newInv['subtotal'], $appliedAmount, 2);
            $newSubtotalAfterDisc  = bcadd((string) $newInv['subtotal_after_discount'], $appliedAmount, 2);
            $newTaxTotal           = bcadd((string) $newInv['tax_total'],
                                           bcadd($lineGst, bcadd($linePst, $lineHst, 2), 2), 2);
            $newTotal              = bcadd($newSubtotalAfterDisc, $newTaxTotal, 2);

            db_execute(
                "UPDATE invoices
                    SET subtotal = ?, subtotal_after_discount = ?, tax_total = ?, total_amount = ?, balance_due = ?,
                        mileage_review_status = 'approved', mileage_reviewed_at = ?,
                        mileage_review_notes = ?, updated_at = ?
                    WHERE id = ?",
                [
                    $newSubtotal, $newSubtotalAfterDisc, $newTaxTotal, $newTotal, $newTotal,
                    $now,
                    "Auto-approved by S-MILEAGE-ALLOWANCE-ZERO-FIX C2 regen script (D-C Option B). Mileage line=\${$appliedAmount} for {$excessKm} km @ \${$lease['mileage_rate_km']}/km (Model B Lite).",
                    $now, $replacementId
                ]
            );

            // Update leases.total_invoiced for the regen
            db_execute(
                "UPDATE leases SET total_invoiced = total_invoiced + ? WHERE id = 52",
                [$newTotal]
            );

            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system (S-MILEAGE-ALLOWANCE-ZERO-FIX)',
                'action'       => 'update',
                'module'       => 'invoices',
                'entity_type'  => 'invoice',
                'entity_id'    => $replacementId,
                'entity_label' => $replacementNumber,
                'notes'        => sprintf(
                    'Mileage review AUTO-APPROVED by S-MILEAGE-ALLOWANCE-ZERO-FIX C2 regen script (D-C Option B). Calculated $%s, applied $%s for %s km @ $%s/km (Model B Lite — every km billable). Subtotal $%s → $%s, total $%s → $%s.',
                    $appliedAmount, $appliedAmount, $excessKm, $lease['mileage_rate_km'],
                    $newInv['subtotal'], $newSubtotal, $newInv['total_amount'], $newTotal
                ),
                'old_values'   => json_encode([
                    'mileage_review_status' => 'pending',
                    'subtotal'              => $newInv['subtotal'],
                    'total_amount'          => $newInv['total_amount'],
                ]),
                'new_values'   => json_encode([
                    'mileage_review_status' => 'approved',
                    'subtotal'              => $newSubtotal,
                    'total_amount'          => $newTotal,
                ]),
                'ip_address'   => '127.0.0.1',
            ]);

            echo "  Step 3b: auto-approved mileage review — line item \${$appliedAmount} ({$excessKm} km × \${$lease['mileage_rate_km']}/km), new total \${$newTotal}\n";
        } else {
            echo "  Step 3b: SKIPPED — engine produced excess_charge_amount=\${$appliedAmount} (expected positive). Investigate.\n";
        }
    }
});

// ────────────────────────────────────────────────────────────────────────────
// Post-flight verification
// ────────────────────────────────────────────────────────────────────────────
echo "\nPost-flight state:\n";
$invs = db_select(
    "SELECT id, invoice_number, status, billing_period_start, billing_period_end,
            period_distance_km, excess_distance_km, excess_charge_amount,
            mileage_review_status, subtotal, total_amount
     FROM invoices WHERE lease_id = 52 AND deleted_at IS NULL ORDER BY id"
);
foreach ($invs as $i) {
    echo "  {$i['invoice_number']} ({$i['status']}): period {$i['billing_period_start']}..{$i['billing_period_end']}, dist=" . ($i['period_distance_km'] ?? 'NULL') . " km, excess={$i['excess_distance_km']}km/\${$i['excess_charge_amount']}, review={$i['mileage_review_status']}, total=\${$i['total_amount']}\n";
}

$leaseAfter = db_row("SELECT total_invoiced FROM leases WHERE id = 52");
echo "\nLease 52 total_invoiced: \${$leaseAfter['total_invoiced']}\n";

echo "\nDone.\n";
exit(0);
