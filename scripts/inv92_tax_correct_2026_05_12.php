<?php
declare(strict_types=1);

/**
 * scripts/inv92_tax_correct_2026_05_12.php
 *
 * S-REVIEW-MILEAGE-TAX-FIX C3 — corrective fix for INV-2026-00092.
 *
 * Mirrors the shape of scripts/inv91_tax_correct_2026_05_07.php (which
 * remediated INV-2026-00091 in S-MILEAGE-ALLOWANCE-ZERO-FIX C5).
 *
 * INV-2026-00092 was created at 2026-05-06 22:11:16 via
 * api/v1/invoices/review_mileage.php approve flow, AFTER C5 had already
 * remediated INV-2026-00091. The C5 script only fixed the inciting row;
 * INV-92 was not in scope. This session's pre-work scan #7 enumerated
 * ALL mileage_adjustment line items and surfaced INV-92 line 175 as the
 * one remaining row produced by the buggy review_mileage.php tax-math
 * before this session's C2 fix landed.
 *
 * Visible impact on INV-2026-00092:
 *   Line `mileage_adjustment` ($699.84):
 *     tax_hst_amount = $0.90  (incorrect; bcdiv(699.84*0.13/100, 2) truncated)
 *                    = $90.98 (correct; bcround(699.84*0.13, 2))
 *   Invoice tax_hst_amount: $106.21 → corrected $196.29 (+$90.08)
 *   Invoice tax_total:      $107.11 → corrected $197.19 (+$90.08)
 *   Invoice total_amount:   $1623.95 → corrected $1714.03 (+$90.08)
 *   Invoice balance_due:    $1623.95 → corrected $1714.03 (+$90.08)
 *   Lease 52 total_invoiced: +$90.08 delta (draft counters track total)
 *
 * Idempotent: re-running on already-corrected state writes 0 rows.
 *
 * The systemic fix to review_mileage.php landed in this session's C2
 * commit. This script remediates the one live affected invoice missed
 * by the C5 audit-pass.
 *
 * USAGE:
 *   php scripts/inv92_tax_correct_2026_05_12.php --dry-run   (default)
 *   php scripts/inv92_tax_correct_2026_05_12.php --execute   (prompts 'yes')
 *
 * @session   S-REVIEW-MILEAGE-TAX-FIX C3 (2026-05-12)
 */

require_once dirname(__DIR__) . '/config/app.php';

$args = array_slice($argv ?? [], 1);
$mode = 'dry-run';
foreach ($args as $arg) {
    if      ($arg === '--execute') $mode = 'execute';
    elseif  ($arg === '--dry-run') $mode = 'dry-run';
    else { fwrite(STDERR, "Unknown argument: {$arg}\n"); exit(2); }
}

echo "S-REVIEW-MILEAGE-TAX-FIX C3 — INV-2026-00092 tax correction\n";
echo str_repeat('═', 78) . "\n";
echo "Mode: {$mode}\n\n";

$inv92 = db_row("SELECT id, invoice_number, status, lease_id, subtotal, subtotal_after_discount, tax_gst_amount, tax_pst_amount, tax_hst_amount, tax_hst_rate, tax_total, total_amount, balance_due FROM invoices WHERE id = 148");
if (!$inv92 || $inv92['status'] !== 'draft') {
    echo "INV-2026-00092 not in draft status (or not found). Status: " . ($inv92['status'] ?? 'NULL') . ". Bailing.\n";
    exit(2);
}
if ($inv92['invoice_number'] !== 'INV-2026-00092') {
    echo "Expected INV-2026-00092 at id=148, got {$inv92['invoice_number']}. Bailing.\n";
    exit(2);
}

$lineItem = db_row("SELECT id, amount, tax_hst_amount FROM invoice_line_items WHERE invoice_id = 148 AND item_type = 'mileage_adjustment'");
if (!$lineItem) {
    echo "INV-92 has no mileage_adjustment line item. Bailing.\n";
    exit(2);
}
if ($lineItem['id'] != 175) {
    echo "Expected line item id=175, got id={$lineItem['id']}. Bailing.\n";
    exit(2);
}

// Correct HST: amount × 0.13 (the actual stored rate) directly via bcround.
// Use the invoice's tax_hst_rate so we don't hard-code 0.13 — defensive in
// case the invoice was generated for a non-ON jurisdiction (it isn't, but
// the script generalizes).
$hstRate = (string) $inv92['tax_hst_rate'];
$correctLineHst = bcround(bcmul((string)$lineItem['amount'], $hstRate, 6), 2);
$delta = bcsub($correctLineHst, (string)$lineItem['tax_hst_amount'], 2);

if (bccomp($delta, '0', 2) === 0) {
    echo "Line item already at correct HST (\${$correctLineHst}). No-op.\n";
    exit(0);
}

$newInvHst       = bcadd((string)$inv92['tax_hst_amount'], $delta, 2);
$newInvTaxTotal  = bcadd((string)$inv92['tax_total'], $delta, 2);
$newInvTotal     = bcadd((string)$inv92['subtotal_after_discount'], $newInvTaxTotal, 2);

echo "Diff:\n";
echo "  line_items[mileage_adjustment id=175].tax_hst_amount: \${$lineItem['tax_hst_amount']} → \${$correctLineHst}  (delta +\${$delta})\n";
echo "  invoices[id=148].tax_hst_amount:                       \${$inv92['tax_hst_amount']} → \${$newInvHst}\n";
echo "  invoices[id=148].tax_total:                            \${$inv92['tax_total']} → \${$newInvTaxTotal}\n";
echo "  invoices[id=148].total_amount:                         \${$inv92['total_amount']} → \${$newInvTotal}\n";
echo "  invoices[id=148].balance_due:                          \${$inv92['balance_due']} → \${$newInvTotal}\n";
echo "  leases[id={$inv92['lease_id']}].total_invoiced:                              +\${$delta}\n";
echo "\n";

if ($mode === 'dry-run') {
    echo "[DRY-RUN] Re-run with --execute to apply.\n";
    exit(0);
}

echo "Type 'yes' to apply: ";
$confirm = trim((string) fgets(STDIN));
if ($confirm !== 'yes') { echo "Aborted.\n"; exit(1); }

db_transaction(function () use ($lineItem, $inv92, $correctLineHst, $newInvHst, $newInvTaxTotal, $newInvTotal, $delta) {
    db_update('invoice_line_items', [
        'tax_hst_amount' => $correctLineHst,
    ], 'id = ?', [$lineItem['id']]);

    db_update('invoices', [
        'tax_hst_amount' => $newInvHst,
        'tax_total'      => $newInvTaxTotal,
        'total_amount'   => $newInvTotal,
        'balance_due'    => $newInvTotal,
    ], 'id = ?', [148]);

    db_execute(
        "UPDATE leases SET total_invoiced = total_invoiced + ? WHERE id = ?",
        [$delta, (int) $inv92['lease_id']]
    );

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'update',
        'module'       => 'invoices',
        'entity_type'  => 'invoice',
        'entity_id'    => 148,
        'entity_label' => 'INV-2026-00092',
        'notes'        => sprintf(
            'S-REVIEW-MILEAGE-TAX-FIX C3 — corrected mileage_adjustment line HST from $%s to $%s (review_mileage.php divide-by-100 bug, fixed at source in C2 commit; this row was created post-C5 audit-pass and missed by inv91_tax_correct_2026_05_07.php). Total delta: +$%s.',
            $lineItem['tax_hst_amount'], $correctLineHst, $delta
        ),
        'old_values'   => json_encode([
            'line_tax_hst_amount'  => $lineItem['tax_hst_amount'],
            'invoice_total_amount' => bcsub($newInvTotal, $delta, 2),
        ]),
        'new_values'   => json_encode([
            'line_tax_hst_amount'  => $correctLineHst,
            'invoice_total_amount' => $newInvTotal,
        ]),
        'ip_address'   => '127.0.0.1',
    ]);
});

echo "✓ INV-2026-00092 corrected. New total: \${$newInvTotal}\n";

$verify = db_row("SELECT total_amount, balance_due, tax_total, tax_hst_amount FROM invoices WHERE id = 148");
echo "\nPost-fix verification:\n";
print_r($verify);

$line = db_row("SELECT amount, tax_hst_amount FROM invoice_line_items WHERE invoice_id = 148 AND item_type = 'mileage_adjustment'");
echo "Line item: amount=\${$line['amount']}, tax_hst=\${$line['tax_hst_amount']}\n";

$lease = db_row("SELECT total_invoiced FROM leases WHERE id = ?", [(int) $inv92['lease_id']]);
echo "Lease {$inv92['lease_id']} total_invoiced: \${$lease['total_invoiced']}\n";

exit(0);
