<?php
declare(strict_types=1);

/**
 * scripts/inv91_tax_correct_2026_05_07.php
 *
 * S-MILEAGE-ALLOWANCE-ZERO-FIX C5 — corrective fix for INV-2026-00091.
 *
 * The parallel agent's combined-C2/C3 regen script
 * (scripts/mileage_allowance_zero_fix_2026_05_07.php committed in ef050e7)
 * mirrored the review_mileage.php approve path verbatim, including the
 * dormant tax-math bug at api/v1/invoices/review_mileage.php:213-218
 * (`bcdiv(bcmul($amount, $rate, 6), '100', 2)`). tax_rates are stored as
 * decimal fractions (0.13 = 13%) per TaxCalculator.php:62 — the `/100`
 * divisor reduces the line tax by 100×.
 *
 * Visible impact on INV-2026-00091:
 *   Line `mileage_adjustment` ($91.26): tax_hst_amount = $0.11 (incorrect)
 *                                                       $11.86 (correct)
 *   Invoice tax_hst_amount: $312.11 → corrected $323.86
 *   Invoice tax_total:      $312.11 → corrected $323.86 (HST-only province)
 *   Invoice total_amount:   $2803.37 → corrected $2815.12
 *   Invoice balance_due:    $2803.37 → corrected $2815.12
 *   Lease 52 total_invoiced: +$11.75 delta (since draft counters track total)
 *
 * Idempotent: re-running on already-corrected state writes 0 rows.
 *
 * The systemic fix to review_mileage.php is queued as
 * S-REVIEW-MILEAGE-TAX-FIX in CURRENT_SESSIONS.md. This script only
 * corrects the one live affected invoice (INV-2026-00091) so the
 * operator-flagged regen demonstration is mathematically correct.
 *
 * USAGE:
 *   php scripts/inv91_tax_correct_2026_05_07.php --dry-run   (default)
 *   php scripts/inv91_tax_correct_2026_05_07.php --execute   (prompts 'yes')
 *
 * @session   S-MILEAGE-ALLOWANCE-ZERO-FIX C5 (2026-05-07)
 */

require_once dirname(__DIR__) . '/config/app.php';

$args = array_slice($argv ?? [], 1);
$mode = 'dry-run';
foreach ($args as $arg) {
    if      ($arg === '--execute') $mode = 'execute';
    elseif  ($arg === '--dry-run') $mode = 'dry-run';
    else { fwrite(STDERR, "Unknown argument: {$arg}\n"); exit(2); }
}

echo "S-MILEAGE-ALLOWANCE-ZERO-FIX C5 — INV-2026-00091 tax correction\n";
echo str_repeat('═', 78) . "\n";
echo "Mode: {$mode}\n\n";

$inv91 = db_row("SELECT id, invoice_number, status, subtotal_after_discount, tax_gst_amount, tax_pst_amount, tax_hst_amount, tax_total, total_amount, balance_due FROM invoices WHERE id = 112");
if (!$inv91 || $inv91['status'] !== 'draft') {
    echo "INV-2026-00091 not in draft status (or not found). Status: " . ($inv91['status'] ?? 'NULL') . ". Bailing.\n";
    exit(2);
}

$lineItem = db_row("SELECT id, amount, tax_hst_amount FROM invoice_line_items WHERE invoice_id = 112 AND item_type = 'mileage_adjustment'");
if (!$lineItem) {
    echo "INV-91 has no mileage_adjustment line item. Bailing.\n";
    exit(2);
}

// Correct HST: amount × 0.13 directly (not / 100).
$correctLineHst = bcround(bcmul((string)$lineItem['amount'], '0.13', 6), 2);
$delta = bcsub($correctLineHst, (string)$lineItem['tax_hst_amount'], 2);

if (bccomp($delta, '0', 2) === 0) {
    echo "Line item already at correct HST (\${$correctLineHst}). No-op.\n";
    exit(0);
}

$newInvHst = bcadd((string)$inv91['tax_hst_amount'], $delta, 2);
$newInvTaxTotal = bcadd((string)$inv91['tax_total'], $delta, 2);
$newInvTotal = bcadd((string)$inv91['subtotal_after_discount'], $newInvTaxTotal, 2);

echo "Diff:\n";
echo "  line_items[mileage_adjustment].tax_hst_amount: \${$lineItem['tax_hst_amount']} → \${$correctLineHst}  (delta +\${$delta})\n";
echo "  invoices[id=112].tax_hst_amount:               \${$inv91['tax_hst_amount']} → \${$newInvHst}\n";
echo "  invoices[id=112].tax_total:                    \${$inv91['tax_total']} → \${$newInvTaxTotal}\n";
echo "  invoices[id=112].total_amount:                 \${$inv91['total_amount']} → \${$newInvTotal}\n";
echo "  invoices[id=112].balance_due:                  \${$inv91['balance_due']} → \${$newInvTotal}\n";
echo "  leases[id=52].total_invoiced:                  +\${$delta}\n";
echo "\n";

if ($mode === 'dry-run') {
    echo "[DRY-RUN] Re-run with --execute to apply.\n";
    exit(0);
}

echo "Type 'yes' to apply: ";
$confirm = trim(fgets(STDIN));
if ($confirm !== 'yes') { echo "Aborted.\n"; exit(1); }

db_transaction(function () use ($lineItem, $correctLineHst, $newInvHst, $newInvTaxTotal, $newInvTotal, $delta) {
    db_update('invoice_line_items', [
        'tax_hst_amount' => $correctLineHst,
    ], 'id = ?', [$lineItem['id']]);

    db_update('invoices', [
        'tax_hst_amount' => $newInvHst,
        'tax_total'      => $newInvTaxTotal,
        'total_amount'   => $newInvTotal,
        'balance_due'    => $newInvTotal,
    ], 'id = ?', [112]);

    db_execute(
        "UPDATE leases SET total_invoiced = total_invoiced + ? WHERE id = ?",
        [$delta, 52]
    );

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'update',
        'module'       => 'invoices',
        'entity_type'  => 'invoice',
        'entity_id'    => 112,
        'entity_label' => 'INV-2026-00091',
        'notes'        => sprintf(
            'S-MILEAGE-ALLOWANCE-ZERO-FIX C5 — corrected mileage_adjustment line HST from $%s to $%s (parallel agent regen used review_mileage.php buggy /100 math; correct math is bcmul(amount, rate) directly per TaxCalculator). Total delta: +$%s. Systemic fix queued as S-REVIEW-MILEAGE-TAX-FIX.',
            $lineItem['tax_hst_amount'], $correctLineHst, $delta
        ),
        'old_values'   => json_encode([
            'line_tax_hst_amount' => $lineItem['tax_hst_amount'],
            'invoice_total_amount' => bcsub($newInvTotal, $delta, 2),
        ]),
        'new_values'   => json_encode([
            'line_tax_hst_amount' => $correctLineHst,
            'invoice_total_amount' => $newInvTotal,
        ]),
        'ip_address'   => '127.0.0.1',
    ]);
});

echo "✓ INV-2026-00091 corrected. New total: \${$newInvTotal}\n";

$verify = db_row("SELECT total_amount, balance_due, tax_total, tax_hst_amount FROM invoices WHERE id = 112");
echo "\nPost-fix verification:\n";
print_r($verify);

$line = db_row("SELECT amount, tax_hst_amount FROM invoice_line_items WHERE invoice_id = 112 AND item_type = 'mileage_adjustment'");
echo "Line item: amount=\${$line['amount']}, tax_hst=\${$line['tax_hst_amount']}\n";

$lease = db_row("SELECT total_invoiced FROM leases WHERE id = 52");
echo "Lease 52 total_invoiced: \${$lease['total_invoiced']}\n";

exit(0);
