<?php
declare(strict_types=1);

/**
 * S035 — TEST DATA SETUP
 *
 * Builds the data needed by test_s035_tax.php:
 *   1. Open Feb/Mar 2026 periods (closed by default in seed)
 *   2. Pick 5 BC invoices from Q1 2026 + 1 MB invoice (PST)
 *      and post a JE for each via JournalEntryService::create()
 *      with source_type='invoice' so TaxFilingService can find them.
 *   3. Create 3 vendor bills in Q1 2026 with GST input tax credits.
 *      Each bill gets an acc_bills row + a JE with source_type='ap_bill'
 *      so the drill-down can pick them up.
 *
 * Expected GL deltas after this script:
 *   2030 GST/HST Payable  CR  ≈ \$497.41  (5 BC + 1 MB invoices)
 *   2040 PST Payable      CR  ≈ \$96.00   (1 MB invoice with PST)
 *   1050 GST ITC          DR  ≈ \$80.00   (3 vendor bills @ \$1600 subtotal each * 5%)
 *
 * Persists IDs to test_s035_ids.json for downstream tests.
 */

require __DIR__ . '/../../config/app.php';

use FleetForge\Accounting\JournalEntryService;

$pad = fn(string $s, int $w = 70) => str_pad($s, $w);

echo str_repeat('=', 80) . PHP_EOL;
echo "S035 — TEST DATA SETUP" . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;

// ============================================================
// STEP 1 — Open Feb/Mar 2026 so JEs can land there
// ============================================================
echo "\n## STEP 1 — Open Feb/Mar 2026 periods\n";
db_execute("UPDATE acc_periods SET status = 'open' WHERE id IN (14, 15)");
$opened = db_select("SELECT id, name, status FROM acc_periods WHERE id IN (13, 14, 15)");
foreach ($opened as $p) {
    printf("  #%d %s → %s\n", $p['id'], $p['name'], $p['status']);
}

// ============================================================
// STEP 2 — Post JEs for 5 BC invoices + 1 MB invoice
// ============================================================
// We pick existing Q1 2026 invoices and create their AR JEs by hand
// (simpler than wiring up the full revenue-mapping system AutoEntryBridge
// expects). Each JE has source_type='invoice' + source_id pointing to
// the real invoice row, so TaxFilingService::drillDownInvoices can find
// them via its INNER JOIN.

echo "\n## STEP 2 — Post JEs for 5 BC invoices + 1 MB invoice\n";

$ar      = 4;   // 1030 Accounts Receivable
$rev     = 38;  // 4010 Chassis Rental Revenue
$gst     = 23;  // 2030 GST/HST Payable
$pstAcct = 24;  // 2040 PST Payable

$invoiceIds = [161, 162, 163, 164, 165, 6]; // 5 BC + 1 MB (id=6 is for cust=1, MB)
$createdJeIds = [];

foreach ($invoiceIds as $invoiceId) {
    $inv = db_row("SELECT * FROM invoices WHERE id = ?", [$invoiceId]);
    if (!$inv) {
        echo "  ❌ invoice #{$invoiceId} not found\n";
        continue;
    }

    // Build the JE lines: DR AR / CR Revenue / CR GST/HST / CR PST (if any)
    $lines = [
        [
            'account_id'  => $ar,
            'debit'       => (string) $inv['total_amount'],
            'credit'      => '0.00',
            'description' => "AR — Invoice {$inv['invoice_number']}",
        ],
        [
            'account_id'  => $rev,
            'debit'       => '0.00',
            'credit'      => (string) $inv['subtotal'],
            'description' => "Revenue — Invoice {$inv['invoice_number']}",
        ],
    ];

    $gstHst = bcadd((string) $inv['tax_gst_amount'], (string) $inv['tax_hst_amount'], 2);
    if (bccomp($gstHst, '0', 2) > 0) {
        $lines[] = [
            'account_id'  => $gst,
            'debit'       => '0.00',
            'credit'      => $gstHst,
            'description' => "GST/HST — Invoice {$inv['invoice_number']}",
        ];
    }

    $pst = (string) $inv['tax_pst_amount'];
    if (bccomp($pst, '0', 2) > 0) {
        $lines[] = [
            'account_id'  => $pstAcct,
            'debit'       => '0.00',
            'credit'      => $pst,
            'description' => "PST — Invoice {$inv['invoice_number']}",
        ];
    }

    try {
        $je = JournalEntryService::create([
            'entry_date'       => $inv['invoice_date'],
            'description'      => "S035 test — Invoice {$inv['invoice_number']}",
            'entry_type'       => 'system',
            'reference'        => $inv['invoice_number'],
            'source_type'      => 'invoice',
            'source_id'        => $invoiceId,
            'post_immediately' => true,
        ], $lines, 1);

        $createdJeIds[] = (int) $je['id'];
        printf("  ✓ Invoice #%-4d %s → JE #%d (sub \$%s, gst \$%s, pst \$%s)\n",
            $invoiceId, $inv['invoice_number'], $je['id'],
            $inv['subtotal'], $gstHst, $pst);
    } catch (\Throwable $e) {
        printf("  ❌ Invoice #%d failed: %s\n", $invoiceId, $e->getMessage());
    }
}

// ============================================================
// STEP 3 — Create 3 vendor bills with GST input tax credits
// ============================================================
echo "\n## STEP 3 — Create 3 vendor bills (GST ITC)\n";

$apAcct  = 21;  // 2010 Accounts Payable
$itcAcct = 6;   // 1050 GST ITC
$expAcct = 55;  // 6010 Maintenance & Repairs - Labour

// One bill per month so each Q1 month has one
$billSpecs = [
    ['date' => '2026-01-15', 'period_id' => 13, 'subtotal' => '1600.00', 'gst' => '80.00'],
    ['date' => '2026-02-12', 'period_id' => 14, 'subtotal' => '1600.00', 'gst' => '80.00'],
    ['date' => '2026-03-22', 'period_id' => 15, 'subtotal' => '1600.00', 'gst' => '80.00'],
];

$createdBillIds = [];

foreach ($billSpecs as $i => $spec) {
    $billNumber  = sprintf('TAX-BILL-%s-%03d', date('Ymd'), $i + 1);
    $vendorBillN = sprintf('SUPPLIER-INV-%03d', $i + 1);
    $total       = bcadd($spec['subtotal'], $spec['gst'], 2);

    $billId = db_insert('acc_bills', [
        'bill_number'        => $billNumber,
        'vendor_id'          => 3, // existing test vendor
        'vendor_bill_number' => $vendorBillN,
        'bill_date'          => $spec['date'],
        'due_date'           => date('Y-m-d', strtotime($spec['date'] . ' +30 days')),
        'period_id'          => $spec['period_id'],
        'status'             => 'approved',
        'currency'           => 'CAD',
        'subtotal'           => $spec['subtotal'],
        'tax_gst_amount'     => $spec['gst'],
        'tax_pst_amount'     => '0.00',
        'tax_hst_amount'     => '0.00',
        'tax_total'          => $spec['gst'],
        'total_amount'       => $total,
        'amount_paid'        => '0.00',
        'balance_due'        => $total,
        'notes'              => 'S035 tax management test bill',
        'created_by'         => 1,
    ]);

    // Build the bill JE: DR Expense / DR GST ITC / CR AP
    $billLines = [
        [
            'account_id'  => $expAcct,
            'debit'       => $spec['subtotal'],
            'credit'      => '0.00',
            'description' => "Expense — Bill {$billNumber}",
        ],
        [
            'account_id'  => $itcAcct,
            'debit'       => $spec['gst'],
            'credit'      => '0.00',
            'description' => "GST ITC — Bill {$billNumber}",
        ],
        [
            'account_id'  => $apAcct,
            'debit'       => '0.00',
            'credit'      => $total,
            'description' => "AP — Bill {$billNumber}",
        ],
    ];

    try {
        $billJe = JournalEntryService::create([
            'entry_date'       => $spec['date'],
            'description'      => "S035 test — Bill {$billNumber}",
            'entry_type'       => 'system',
            'reference'        => $billNumber,
            'source_type'      => 'ap_bill',
            'source_id'        => $billId,
            'post_immediately' => true,
        ], $billLines, 1);

        // Link the bill back to the JE
        db_update('acc_bills', ['journal_entry_id' => $billJe['id']], 'id = ?', [$billId]);

        $createdBillIds[] = $billId;
        printf("  ✓ Bill #%d %s (%s) sub \$%s + gst \$%s → JE #%d\n",
            $billId, $billNumber, $spec['date'], $spec['subtotal'], $spec['gst'], $billJe['id']);
    } catch (\Throwable $e) {
        printf("  ❌ Bill creation failed: %s\n", $e->getMessage());
    }
}

// ============================================================
// PERSIST IDs FOR DOWNSTREAM TESTS
// ============================================================
file_put_contents(__DIR__ . '/test_s035_ids.json', json_encode([
    'invoice_ids'    => $invoiceIds,
    'invoice_je_ids' => $createdJeIds,
    'bill_ids'       => $createdBillIds,
], JSON_PRETTY_PRINT));

echo "\n## SUMMARY\n";
printf("  Invoice JEs created: %d\n", count($createdJeIds));
printf("  Bills created:       %d\n", count($createdBillIds));

// ── Quick GL sanity check ─────────────────────────────────────
echo "\n## GL SANITY CHECK (Q1 2026 contributions from S035 setup):\n";

// Total GST/HST credits in 2030 from these invoices
$gstSumRow = db_row(
    "SELECT COALESCE(SUM(jel.credit), 0) AS total
     FROM acc_journal_entry_lines jel
     JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
     WHERE jel.account_id = ?
       AND jel.credit > 0
       AND je.entry_date BETWEEN '2026-01-01' AND '2026-03-31'
       AND je.source_type = 'invoice'
       AND je.status = 'posted'
       AND je.is_reversal = 0",
    [$gst]
);
printf("  2030 GST/HST CR (from S035 invoices): \$%s\n", $gstSumRow['total']);

// PST in 2040
$pstSumRow = db_row(
    "SELECT COALESCE(SUM(jel.credit), 0) AS total
     FROM acc_journal_entry_lines jel
     JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
     WHERE jel.account_id = ?
       AND jel.credit > 0
       AND je.entry_date BETWEEN '2026-01-01' AND '2026-03-31'
       AND je.source_type = 'invoice'
       AND je.status = 'posted'
       AND je.is_reversal = 0",
    [$pstAcct]
);
printf("  2040 PST CR (from S035 invoices): \$%s\n", $pstSumRow['total']);

// ITC in 1050
$itcSumRow = db_row(
    "SELECT COALESCE(SUM(jel.debit), 0) AS total
     FROM acc_journal_entry_lines jel
     JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
     WHERE jel.account_id = ?
       AND jel.debit > 0
       AND je.entry_date BETWEEN '2026-01-01' AND '2026-03-31'
       AND je.source_type = 'ap_bill'
       AND je.status = 'posted'
       AND je.is_reversal = 0",
    [$itcAcct]
);
printf("  1050 GST ITC DR (from S035 bills):  \$%s\n", $itcSumRow['total']);

echo "\n" . str_repeat('=', 80) . PHP_EOL;
echo "SETUP COMPLETE — IDs saved to test_s035_ids.json" . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;
