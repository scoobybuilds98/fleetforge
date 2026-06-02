<?php
/**
 * FleetForge Demo — Accounting module data
 *
 * Back-fills the full accounting module from the demo lease/invoice/payment
 * data so the GL, trial balance, bank reconciliation, fixed asset register,
 * AP/bills module, depreciation schedule, capex requests, and year-end
 * checklist all have populated data for the demo.
 *
 * Must be run AFTER scripts/demo_seed.php + scripts/demo_backdate_invoices.php.
 *
 * Creates:
 *   - Journal entries for every invoice  (Dr A/R, Cr revenue, Cr tax)
 *   - Journal entries for every payment  (Dr cash, Cr A/R)
 *   - Bank transactions for every payment (deposits into the right bank)
 *   - Vendors (5 — maintenance/parts/insurance/inspection/towing)
 *   - Vendor bills (10 — mix of paid and unpaid)
 *   - Monthly depreciation runs + run_lines + JEs for 2026 YTD
 *   - CapEx requests (3 — tied to the newest assets)
 *   - Year-end checklist items (2025)
 *
 * All JEs are in CAD (reporting currency). USD invoices/payments are
 * translated to CAD at the seeded FX rate (1.375) and the original
 * USD amount is stored in line descriptions + foreign_amount columns.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';

$userId = 1;
$now    = date('Y-m-d H:i:s');

echo "=== FleetForge demo accounting seeder ===\n\n";

// Idempotent: clear any accounting data from prior runs of this script.
// We're only clearing what this script creates — not the 20 fixed assets,
// bank accounts, or FX rate, which are seeded by demo_seed.php.
//
// WHY acc_ap_payment_allocations + acc_ap_payments are in this list
// (added S-ACCT-FIX-AP Phase 4, 2026-05-18):
//   Prior to this fix, the demo emitted ap_payment JEs (via postJE() with
//   source_type='ap_payment') but did NOT write rows into acc_ap_payments
//   or acc_ap_payment_allocations — leaving AP subledger drill-down
//   impossible. The 6 orphan JEs (JE-2026-00036/00038/00040/00042/00045/00047)
//   surfaced in docs/FLEETFORGE_ACCOUNTING_AUDIT_2026-05-07.md §4 were the
//   downstream symptom. The Phase 2 reversal of those JEs is one-shot;
//   re-running this demo without seeding the subledger would recreate the
//   orphan condition. This block now truncates the subledger AND the demo
//   writes proper subledger rows in the paid-bill block below.
// Order note: acc_ap_payment_allocations cascades from acc_ap_payments via
//   ON DELETE CASCADE, but we list both explicitly for clarity. The
//   SET FOREIGN_KEY_CHECKS = 0 wrap makes the order irrelevant.
echo "Clearing prior accounting seed data (idempotent)...\n";
db_execute('SET FOREIGN_KEY_CHECKS = 0', []);
foreach (['acc_journal_entry_lines', 'acc_journal_entries', 'acc_bank_transactions',
          'acc_ap_payment_allocations', 'acc_ap_payments',
          'acc_bill_lines', 'acc_bills', 'acc_depreciation_run_lines',
          'acc_depreciation_runs', 'acc_capex_requests', 'acc_year_end_checklist',
          'vendors'] as $t) {
    db_execute("TRUNCATE TABLE {$t}", []);
}
db_execute('SET FOREIGN_KEY_CHECKS = 1', []);
echo "  Cleared.\n\n";

// ────────────────────────────────────────────────────────────
// Helpers
// ────────────────────────────────────────────────────────────

// Account lookups
$acctCache = [];
function acct(string $code): int {
    global $acctCache;
    if (isset($acctCache[$code])) return $acctCache[$code];
    $row = db_row("SELECT id FROM acc_accounts WHERE code = ?", [$code]);
    if (!$row) throw new RuntimeException("Account $code not found");
    return $acctCache[$code] = (int) $row['id'];
}

// Period lookup by entry date
$periodCache = [];
function periodForDate(string $date): int {
    global $periodCache;
    $key = substr($date, 0, 7);
    if (isset($periodCache[$key])) return $periodCache[$key];
    $row = db_row(
        "SELECT id FROM acc_periods WHERE ? BETWEEN start_date AND end_date",
        [$date]
    );
    if (!$row) throw new RuntimeException("No period covers $date");
    return $periodCache[$key] = (int) $row['id'];
}

// Equipment category → revenue account code
function revenueAcct(string $category): string {
    return match ($category) {
        'chassis'              => '4010',
        'dry_van'              => '4020',
        'reefer'               => '4030',
        'flatbed'              => '4040',
        default                => '4050',  // container, step_deck, lowboy, tanker, dump, other
    };
}

// Entry number generator — scoped per period
$entrySeq = [];
function nextEntryNumber(int $periodId): string {
    global $entrySeq;
    $entrySeq[$periodId] = ($entrySeq[$periodId] ?? 0) + 1;
    return sprintf('JE-%d-%05d', date('Y'), count($entrySeq) + 0 + ($entrySeq[$periodId] - 1) + array_sum(array_slice($entrySeq, 0, array_search($periodId, array_keys($entrySeq)))) - $entrySeq[$periodId] + 1);
}
// Simpler: use a global counter
$globalEntryCounter = 0;
function nextJeNumber(): string {
    global $globalEntryCounter;
    $globalEntryCounter++;
    return sprintf('JE-%d-%05d', date('Y'), $globalEntryCounter);
}

// Insert a balanced journal entry (header + lines)
function postJE(array $header, array $lines): int {
    global $userId, $now;

    $periodId = periodForDate($header['entry_date']);
    $totalDr = 0.0; $totalCr = 0.0;
    foreach ($lines as $l) {
        $totalDr += (float) ($l['debit'] ?? 0);
        $totalCr += (float) ($l['credit'] ?? 0);
    }
    if (round($totalDr, 2) !== round($totalCr, 2)) {
        throw new RuntimeException(sprintf("Unbalanced JE: Dr=%.2f Cr=%.2f — %s", $totalDr, $totalCr, $header['description']));
    }

    $jeId = db_insert('acc_journal_entries', [
        'entry_number'  => nextJeNumber(),
        'period_id'     => $periodId,
        'entry_date'    => $header['entry_date'],
        'entry_type'    => $header['entry_type'] ?? 'system',
        'status'        => 'posted',
        'description'   => $header['description'],
        'reference'     => $header['reference'] ?? null,
        'source_type'   => $header['source_type'] ?? 'manual',
        'source_id'     => $header['source_id'] ?? null,
        'currency'      => $header['currency'] ?? 'CAD',
        'exchange_rate' => $header['exchange_rate'] ?? '1.000000',
        'posted_by'     => $userId,
        'posted_at'     => $now,
        'created_by'    => $userId,
    ]);

    foreach ($lines as $i => $l) {
        db_insert('acc_journal_entry_lines', [
            'journal_entry_id'   => $jeId,
            'account_id'         => $l['account_id'],
            'line_number'        => $i + 1,
            'description'        => $l['description'] ?? $header['description'],
            'debit'              => number_format((float) ($l['debit']  ?? 0), 2, '.', ''),
            'credit'             => number_format((float) ($l['credit'] ?? 0), 2, '.', ''),
            'foreign_amount'     => $l['foreign_amount'] ?? null,
            'foreign_currency'   => $l['foreign_currency'] ?? null,
            'exchange_rate'      => $l['exchange_rate'] ?? null,
            'customer_id'        => $l['customer_id'] ?? null,
            'vendor_id'          => $l['vendor_id'] ?? null,
            'equipment_unit_id'  => $l['equipment_unit_id'] ?? null,
        ]);
    }
    return $jeId;
}

// ────────────────────────────────────────────────────────────
// Step 1 — Post JEs for every invoice
// ────────────────────────────────────────────────────────────
echo "Step 1 — Posting invoice journal entries...\n";

$invoices = db_select(
    "SELECT i.id, i.invoice_number, i.invoice_date, i.customer_id, i.lease_id,
            i.currency, i.exchange_rate_to_cad, i.total_amount, i.subtotal_after_discount,
            i.tax_gst_amount, i.tax_pst_amount, i.tax_hst_amount,
            l.equipment_unit_id, u.template_id, t.category
       FROM invoices i
       JOIN leases l ON l.id = i.lease_id
       JOIN equipment_units u ON u.id = l.equipment_unit_id
       JOIN equipment_templates t ON t.id = u.template_id
      WHERE i.deleted_at IS NULL
      ORDER BY i.invoice_date, i.id",
    []
);

$invJeCount = 0;
foreach ($invoices as $inv) {
    // Translate USD→CAD for reporting
    $fx = $inv['currency'] === 'USD' ? (float) ($inv['exchange_rate_to_cad'] ?: 1.375) : 1.0;

    $totalCad = (float) $inv['total_amount'] * $fx;
    $subCad   = (float) $inv['subtotal_after_discount'] * $fx;
    $gstCad   = (float) $inv['tax_gst_amount'] * $fx;
    $pstCad   = (float) $inv['tax_pst_amount'] * $fx;
    $hstCad   = (float) $inv['tax_hst_amount'] * $fx;

    $lines = [
        // Dr Accounts Receivable
        [
            'account_id'  => acct('1030'),
            'description' => "A/R — {$inv['invoice_number']}",
            'debit'       => $totalCad,
            'customer_id' => $inv['customer_id'],
        ],
        // Cr Revenue (by equipment category)
        [
            'account_id'         => acct(revenueAcct($inv['category'])),
            'description'        => ucfirst(str_replace('_', ' ', $inv['category'])) . " rental revenue — {$inv['invoice_number']}",
            'credit'             => $subCad,
            'customer_id'        => $inv['customer_id'],
            'equipment_unit_id'  => $inv['equipment_unit_id'],
        ],
    ];

    // Cr GST/HST Payable (both go to 2030)
    $taxPayable = $gstCad + $hstCad;
    if ($taxPayable > 0) {
        $lines[] = [
            'account_id'  => acct('2030'),
            'description' => "GST/HST payable — {$inv['invoice_number']}",
            'credit'      => $taxPayable,
            'customer_id' => $inv['customer_id'],
        ];
    }
    // Cr PST Payable
    if ($pstCad > 0) {
        $lines[] = [
            'account_id'  => acct('2040'),
            'description' => "PST payable — {$inv['invoice_number']}",
            'credit'      => $pstCad,
            'customer_id' => $inv['customer_id'],
        ];
    }

    $jeId = postJE(
        [
            'entry_date'    => $inv['invoice_date'],
            'description'   => "Invoice {$inv['invoice_number']} issued",
            'reference'     => $inv['invoice_number'],
            'source_type'   => 'invoice',
            'source_id'     => $inv['id'],
            'currency'      => 'CAD',  // Reporting in CAD, FX already applied
        ],
        $lines
    );

    // (invoices table has no journal_entry_id FK — link is the other way,
    //  JE.source_type='invoice' + JE.source_id=invoice.id)
    $invJeCount++;
}
echo "  Posted $invJeCount invoice JEs\n";

// ────────────────────────────────────────────────────────────
// Step 2 — Post JEs for every payment + bank transactions
// ────────────────────────────────────────────────────────────
echo "\nStep 2 — Posting payment journal entries + bank transactions...\n";

$payments = db_select(
    "SELECT p.id, p.payment_number, p.customer_id, p.payment_date, p.amount,
            p.currency, p.exchange_rate_to_cad, p.amount_in_cad, p.bank_name
       FROM payments p
      WHERE p.deleted_at IS NULL
      ORDER BY p.payment_date, p.id",
    []
);

$cadBank = db_row("SELECT id FROM acc_bank_accounts WHERE currency = 'CAD' LIMIT 1", [])['id'];
$usdBank = db_row("SELECT id FROM acc_bank_accounts WHERE currency = 'USD' LIMIT 1", [])['id'];

$payJeCount = 0; $bankTxnCount = 0;
foreach ($payments as $pay) {
    $isUsd = $pay['currency'] === 'USD';
    $bankAcctId = $isUsd ? acct('1020') : acct('1010');

    $amountCad = (float) $pay['amount_in_cad'];

    $jeId = postJE(
        [
            'entry_date'  => $pay['payment_date'],
            'description' => "Payment received — {$pay['payment_number']}",
            'reference'   => $pay['payment_number'],
            'source_type' => 'payment',
            'source_id'   => $pay['id'],
            'currency'    => 'CAD',
        ],
        [
            [
                'account_id'  => $bankAcctId,
                'description' => "Cash receipt — {$pay['payment_number']} ({$pay['bank_name']})",
                'debit'       => $amountCad,
                'customer_id' => $pay['customer_id'],
            ],
            [
                'account_id'  => acct('1030'),
                'description' => "A/R applied — {$pay['payment_number']}",
                'credit'      => $amountCad,
                'customer_id' => $pay['customer_id'],
            ],
        ]
    );
    $payJeCount++;

    // Matching bank transaction
    db_insert('acc_bank_transactions', [
        'bank_account_id'   => $isUsd ? $usdBank : $cadBank,
        'transaction_date'  => $pay['payment_date'],
        'description'       => "Deposit — {$pay['payment_number']}",
        'reference'         => $pay['payment_number'],
        'amount'            => number_format((float) $pay['amount'], 2, '.', ''),
        'transaction_type'  => 'deposit',
        'source'            => 'system',
        'status'            => 'matched',
        'matched_type'      => 'payment',
        'matched_id'        => $pay['id'],
        'matched_at'        => $now,
        'matched_by'        => $userId,
        'is_cleared'        => 1,
        'cleared_date'      => $pay['payment_date'],
        'journal_entry_id'  => $jeId,
        'created_by'        => $userId,
    ]);
    $bankTxnCount++;
}
echo "  Posted $payJeCount payment JEs + $bankTxnCount bank transactions\n";

// ────────────────────────────────────────────────────────────
// Step 3 — Vendors
// ────────────────────────────────────────────────────────────
echo "\nStep 3 — Creating vendors...\n";

$vendorDefs = [
    ['name' => 'Freightliner Service Center',     'vendor_type' => 'maintenance', 'contact_name' => 'Dave Kowalski',  'email' => 'service@freightliner.ca',  'phone' => '604-555-1100', 'city' => 'Surrey', 'state' => 'BC', 'hourly_rate' => '145.00', 'rating' => 5],
    ['name' => 'Bridgestone Tire Canada',          'vendor_type' => 'parts',       'contact_name' => 'Maria Gonzales', 'email' => 'sales@bridgestone.ca',     'phone' => '604-555-2200', 'city' => 'Burnaby','state' => 'BC', 'hourly_rate' => null,     'rating' => 4],
    ['name' => 'ICBC Commercial Insurance',        'vendor_type' => 'other',       'contact_name' => 'Sarah Chen',     'email' => 'commercial@icbc.com',      'phone' => '1-800-663-3051','city' => 'North Vancouver','state' => 'BC', 'hourly_rate' => null,     'rating' => 4],
    ['name' => 'Desmond CVI Inspections',          'vendor_type' => 'inspection',  'contact_name' => 'Raj Patel',      'email' => 'raj@desmondcvi.ca',        'phone' => '604-555-3300', 'city' => 'Delta',  'state' => 'BC', 'hourly_rate' => '95.00',  'rating' => 5],
    ['name' => 'Acme Heavy Tow',                   'vendor_type' => 'towing',      'contact_name' => 'Jim O\'Brien',    'email' => 'dispatch@acmetow.ca',     'phone' => '604-555-4400', 'city' => 'Surrey', 'state' => 'BC', 'hourly_rate' => '225.00', 'rating' => 3],
];

$vendorIds = [];
foreach ($vendorDefs as $v) {
    $v['specializations'] = json_encode([$v['vendor_type']]);
    $id = db_insert('vendors', $v);
    $vendorIds[$v['name']] = $id;
    printf("  id=%d %s (%s)\n", $id, $v['name'], $v['vendor_type']);
}

// ────────────────────────────────────────────────────────────
// Step 4 — Vendor bills (AP side)
// ────────────────────────────────────────────────────────────
echo "\nStep 4 — Creating vendor bills...\n";

$billDefs = [
    // Maintenance bills for leased equipment
    ['vendor' => 'Freightliner Service Center', 'date_offset' => -58, 'amount' => '1450.00',  'description' => 'Quarterly brake + ABS service — 12TR1309', 'gl_account' => '6070', 'equipment_key' => 't1', 'status' => 'paid'],
    ['vendor' => 'Freightliner Service Center', 'date_offset' => -32, 'amount' => '2280.00',  'description' => 'Reefer unit compressor service — RFR-001', 'gl_account' => '6070', 'equipment_key' => 't2', 'status' => 'paid'],
    ['vendor' => 'Freightliner Service Center', 'date_offset' => -9,  'amount' => '875.00',   'description' => 'Post-lease inspection + minor repairs — CHS-001', 'gl_account' => '6070', 'equipment_key' => 't3', 'status' => 'paid'],
    ['vendor' => 'Bridgestone Tire Canada',     'date_offset' => -45, 'amount' => '3250.00',  'description' => 'Replacement tires — 8 drive tires for dry van fleet', 'gl_account' => '6070', 'equipment_key' => null, 'status' => 'paid'],
    ['vendor' => 'Bridgestone Tire Canada',     'date_offset' => -12, 'amount' => '1680.00',  'description' => 'Emergency roadside tire replacement — 40TR1352', 'gl_account' => '6070', 'equipment_key' => 'm1', 'status' => 'approved'],
    ['vendor' => 'ICBC Commercial Insurance',   'date_offset' => -30, 'amount' => '8400.00',  'description' => 'Q2 2026 commercial fleet insurance premium', 'gl_account' => '6100', 'equipment_key' => null, 'status' => 'paid'],
    ['vendor' => 'Desmond CVI Inspections',     'date_offset' => -22, 'amount' => '480.00',   'description' => 'Annual CVI — 4 trailers', 'gl_account' => '6070', 'equipment_key' => null, 'status' => 'paid'],
    ['vendor' => 'Desmond CVI Inspections',     'date_offset' => -4,  'amount' => '360.00',   'description' => 'Pre-lease inspection — 12TR1327', 'gl_account' => '6070', 'equipment_key' => 'a1', 'status' => 'approved'],
    ['vendor' => 'Acme Heavy Tow',              'date_offset' => -18, 'amount' => '1125.00',  'description' => 'Emergency towing from Coquihalla — SEED-LOW-009', 'gl_account' => '6070', 'equipment_key' => 'a5', 'status' => 'approved'],
    ['vendor' => 'Freightliner Service Center', 'date_offset' => -6,  'amount' => '650.00',   'description' => 'Tire + wheel balance — CHS-002', 'gl_account' => '6070', 'equipment_key' => 'v2', 'status' => 'approved'],
];

// Figure out maintenance expense account (Fleet Maintenance & Repairs)
$maintAcct = db_row("SELECT id FROM acc_accounts WHERE code = '6070' OR name LIKE '%Maintenance%' OR name LIKE '%Repair%' ORDER BY code LIMIT 1", []);
$insuranceAcct = db_row("SELECT id FROM acc_accounts WHERE name LIKE '%Insurance%' AND account_type IN ('operating_expense','cost_of_revenue') ORDER BY code LIMIT 1", []);
$gstRcvbleAcct = db_row("SELECT id FROM acc_accounts WHERE name LIKE '%GST/HST%' AND account_type = 'asset' LIMIT 1", []);

// $apPaymentCount: per-paid-bill counter for APAY-YYYY-NNNNN payment-number
// generation (added S-ACCT-FIX-AP Phase 4). The live path generates payment
// numbers via AccountingService::nextApPaymentNumber(); the demo uses its
// own sprintf scheme to stay self-contained and not advance the live counter.
$billCount = 0; $billJeCount = 0; $apPaymentCount = 0;
foreach ($billDefs as $b) {
    $billDate = date('Y-m-d', strtotime("{$b['date_offset']} days"));
    $dueDate  = date('Y-m-d', strtotime($billDate . ' +30 days'));
    $vendorId = $vendorIds[$b['vendor']];
    $amount   = (float) $b['amount'];
    $gst      = round($amount * 0.05, 2);
    $pst      = round($amount * 0.07, 2);
    $total    = $amount + $gst + $pst;

    // Pick expense account
    $isInsurance = $b['vendor'] === 'ICBC Commercial Insurance';
    $expenseAcctId = $isInsurance && $insuranceAcct ? (int) $insuranceAcct['id'] : ($maintAcct ? (int) $maintAcct['id'] : acct('6170'));

    $status = $b['status'];
    $amountPaid  = $status === 'paid' ? number_format($total, 2, '.', '') : '0.00';
    $balanceDue  = $status === 'paid' ? '0.00' : number_format($total, 2, '.', '');

    $billId = db_insert('acc_bills', [
        'bill_number'         => sprintf('BILL-%d-%05d', date('Y'), ++$billCount),
        'vendor_id'           => $vendorId,
        'vendor_bill_number'  => 'V-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
        'bill_date'           => $billDate,
        'due_date'            => $dueDate,
        'period_id'           => periodForDate($billDate),
        'status'              => $status,
        'currency'            => 'CAD',
        'subtotal'            => number_format($amount, 2, '.', ''),
        'tax_gst_amount'      => number_format($gst, 2, '.', ''),
        'tax_pst_amount'      => number_format($pst, 2, '.', ''),
        'tax_total'           => number_format($gst + $pst, 2, '.', ''),
        'total_amount'        => number_format($total, 2, '.', ''),
        'amount_paid'         => $amountPaid,
        'balance_due'         => $balanceDue,
        'notes'               => $b['description'],
        'created_by'          => $userId,
    ]);

    // Bill line
    db_insert('acc_bill_lines', [
        'bill_id'          => $billId,
        'account_id'       => $expenseAcctId,
        'description'      => $b['description'],
        'quantity'         => '1.0000',
        'unit_cost'        => number_format($amount, 2, '.', ''),
        'amount'           => number_format($amount, 2, '.', ''),
        'tax_gst_amount'   => number_format($gst, 2, '.', ''),
        'tax_pst_amount'   => number_format($pst, 2, '.', ''),
        'sort_order'       => 1,
    ]);

    // JE for the bill: Dr expense, Dr GST receivable, Cr A/P
    // We'll skip the ITC complexity and just Dr expense + Cr A/P (2100)
    $apAcctId = db_row("SELECT id FROM acc_accounts WHERE code = '2010' OR name = 'Accounts Payable' LIMIT 1", []);
    if (!$apAcctId) $apAcctId = ['id' => acct('2030')]; // fallback
    $apAcctId = (int) $apAcctId['id'];

    $jeId = postJE(
        [
            'entry_date'  => $billDate,
            'description' => "Vendor bill — {$b['vendor']} — {$b['description']}",
            'reference'   => "BILL-" . str_pad((string) $billCount, 5, '0', STR_PAD_LEFT),
            'source_type' => 'ap_bill',
            'source_id'   => $billId,
            'currency'    => 'CAD',
        ],
        [
            [
                'account_id'  => $expenseAcctId,
                'description' => $b['description'],
                'debit'       => $amount,
                'vendor_id'   => $vendorId,
            ],
            // Simple — include tax in the expense line for now rather than
            // a separate ITC line (keeps the demo math tidy).
            [
                'account_id'  => $expenseAcctId,
                'description' => 'GST + PST (' . ($gst + $pst) . ')',
                'debit'       => $gst + $pst,
                'vendor_id'   => $vendorId,
            ],
            [
                'account_id'  => $apAcctId,
                'description' => "A/P — {$b['vendor']}",
                'credit'      => $total,
                'vendor_id'   => $vendorId,
            ],
        ]
    );
    db_execute("UPDATE acc_bills SET journal_entry_id = ? WHERE id = ?", [$jeId, $billId]);
    $billJeCount++;

    // For paid bills, post (1) the cash-out JE, (2) the acc_ap_payments
    // subledger row, (3) the acc_ap_payment_allocations row linking the
    // payment to the bill, and (4) the matching bank withdrawal.
    //
    // Pre-S-ACCT-FIX-AP this block only wrote (1) and (4), leaving the AP
    // subledger empty and the JE orphan. The orphan-JE class fixed in
    // S-ACCT-FIX-AP Phase 2 (reversal of JE-2026-00036/00038/00040/00042/
    // 00045/00047) was caused by that missing-write, not by a TRUNCATE
    // ordering bug. The forward link pattern (acc_ap_payments.journal_entry_id
    // → acc_journal_entries.id) matches what api/v1/accounting/ap-payments/
    // create.php writes in the live UI flow. acc_journal_entries.source_id
    // is then updated to point at the new ap_payment row so subledger
    // drill-down via (source_type, source_id) works (spec §16 + §15).
    if ($status === 'paid') {
        $paymentDate = date('Y-m-d', strtotime($billDate . ' +10 days'));

        $jePaymentId = postJE(
            [
                'entry_date'  => $paymentDate,
                'description' => "AP payment — {$b['vendor']}",
                'reference'   => sprintf('APAY-%d-%05d', date('Y'), ++$apPaymentCount),
                'source_type' => 'ap_payment',
                // source_id is wired below once the acc_ap_payments row exists
                'source_id'   => null,
                'currency'    => 'CAD',
            ],
            [
                [
                    'account_id'  => $apAcctId,
                    'description' => "A/P cleared — {$b['vendor']}",
                    'debit'       => $total,
                    'vendor_id'   => $vendorId,
                ],
                [
                    'account_id'  => acct('1010'),
                    'description' => "Cash out — {$b['vendor']}",
                    'credit'      => $total,
                    'vendor_id'   => $vendorId,
                ],
            ]
        );

        // Subledger row — the missing write that caused the 6 orphan JEs
        // pre-S-ACCT-FIX-AP. assert (via foreign-key shape): vendor + bank
        // + journal_entry_id all resolve; status='cleared' to mirror the
        // live create.php default.
        $apPaymentId = db_insert('acc_ap_payments', [
            'payment_number'   => sprintf('APAY-%d-%05d', date('Y'), $apPaymentCount),
            'vendor_id'        => $vendorId,
            'bank_account_id'  => $cadBank,
            'payment_date'     => $paymentDate,
            'payment_method'   => 'eft',
            'amount'           => number_format($total, 2, '.', ''),
            'currency'         => 'CAD',
            'status'           => 'cleared',
            'journal_entry_id' => $jePaymentId,
            'notes'            => "Demo seed payment for {$b['vendor']} — bill #{$billCount}",
            'created_by'       => $userId,
        ]);

        // Wire JE.source_id back to the subledger row for drill-down
        // (spec §16: "All auto-entries reference source_type and source_id
        // in acc_journal_entries").
        db_execute(
            "UPDATE acc_journal_entries SET source_id = ? WHERE id = ?",
            [$apPaymentId, $jePaymentId]
        );

        // Allocation: this demo payment clears the full bill balance.
        db_insert('acc_ap_payment_allocations', [
            'ap_payment_id'  => $apPaymentId,
            'bill_id'        => $billId,
            'amount_applied' => number_format($total, 2, '.', ''),
        ]);

        // Matching bank withdrawal
        db_insert('acc_bank_transactions', [
            'bank_account_id'  => $cadBank,
            'transaction_date' => $paymentDate,
            'description'      => "AP payment — {$b['vendor']}",
            'reference'        => sprintf('APAY-%d-%05d', date('Y'), $apPaymentCount),
            'amount'           => number_format(-$total, 2, '.', ''),
            'transaction_type' => 'withdrawal',
            'source'           => 'system',
            'status'           => 'matched',
            'matched_type'     => 'other',
            'is_cleared'       => 1,
            'cleared_date'     => $paymentDate,
            'created_by'       => $userId,
        ]);
    }
}
echo "  Created $billCount vendor bills + JEs\n";

// ────────────────────────────────────────────────────────────
// Step 5 — Monthly depreciation runs for 2026 YTD
// ────────────────────────────────────────────────────────────
echo "\nStep 5 — Creating monthly depreciation runs...\n";

$currentYear  = (int) date('Y');
$currentMonth = (int) date('n');

$assets = db_select(
    "SELECT id, asset_number, name, acquisition_cost, salvage_value, depreciable_cost,
            useful_life_years, accumulated_depreciation, net_book_value,
            asset_account_id, accum_depr_account_id, depr_expense_account_id,
            equipment_unit_id, depreciation_start_date
       FROM acc_fixed_assets
      WHERE status = 'active'",
    []
);

$runCount = 0;
for ($month = 1; $month <= $currentMonth; $month++) {
    $runDate = sprintf('%d-%02d-%02d 18:00:00', $currentYear, $month, min(28, (int) date('t', strtotime(sprintf('%d-%02d-01', $currentYear, $month)))));
    $periodId = periodForDate(substr($runDate, 0, 10));

    // Create the run header
    $totalDepr = 0.0;
    $lineRows = [];
    foreach ($assets as $a) {
        $monthlyDepr = round((float) $a['depreciable_cost'] / ((float) $a['useful_life_years'] * 12), 2);
        $lineRows[] = [
            'asset'  => $a,
            'depr'   => $monthlyDepr,
        ];
        $totalDepr += $monthlyDepr;
    }

    $runId = db_insert('acc_depreciation_runs', [
        'period_id'         => $periodId,
        'run_date'          => $runDate,
        'status'            => 'posted',
        'total_depreciation'=> number_format($totalDepr, 2, '.', ''),
        'asset_count'       => count($assets),
        'run_by'            => $userId,
        'notes'             => "Monthly straight-line depreciation — " . date('F Y', strtotime(substr($runDate, 0, 10))),
    ]);

    // Insert run lines + build aggregated JE
    $runJeLines = [];
    $exprAcctTotal = [];  // account_id => debit
    $accumAcctTotal = []; // account_id => credit
    foreach ($lineRows as $lr) {
        $a = $lr['asset'];
        $depr = $lr['depr'];
        $openingNbv = (float) $a['net_book_value'] - ($depr * ($currentMonth - $month));
        $closingNbv = $openingNbv - $depr;

        db_insert('acc_depreciation_run_lines', [
            'run_id'          => $runId,
            'asset_id'        => (int) $a['id'],
            'period_id'       => $periodId,
            'opening_nbv'     => number_format($openingNbv, 2, '.', ''),
            'depreciation'    => number_format($depr, 2, '.', ''),
            'closing_nbv'     => number_format($closingNbv, 2, '.', ''),
            'method_used'     => 'straight_line',
            'calculation_detail' => json_encode(['monthly_rate' => $depr, 'useful_life_years' => $a['useful_life_years']]),
        ]);

        $exprAcctTotal[$a['depr_expense_account_id']]  = ($exprAcctTotal[$a['depr_expense_account_id']]  ?? 0) + $depr;
        $accumAcctTotal[$a['accum_depr_account_id']]   = ($accumAcctTotal[$a['accum_depr_account_id']]   ?? 0) + $depr;
    }

    // Post one JE for the whole run
    $jeLines = [];
    foreach ($exprAcctTotal as $acctId => $amt) {
        $jeLines[] = [
            'account_id'  => (int) $acctId,
            'description' => 'Monthly depreciation expense — fleet assets',
            'debit'       => $amt,
        ];
    }
    foreach ($accumAcctTotal as $acctId => $amt) {
        $jeLines[] = [
            'account_id'  => (int) $acctId,
            'description' => 'Accumulated depreciation — fleet assets',
            'credit'      => $amt,
        ];
    }

    $jeId = postJE(
        [
            'entry_date'  => substr($runDate, 0, 10),
            'description' => 'Monthly depreciation — ' . date('F Y', strtotime(substr($runDate, 0, 10))),
            'reference'   => "DEPR-$runId",
            'source_type' => 'depreciation',
            'source_id'   => $runId,
            'entry_type'  => 'system',
        ],
        $jeLines
    );
    db_execute("UPDATE acc_depreciation_runs SET journal_entry_id = ? WHERE id = ?", [$jeId, $runId]);
    $runCount++;
}
echo "  Created $runCount monthly depreciation runs\n";

// ────────────────────────────────────────────────────────────
// Step 6 — CapEx requests
// ────────────────────────────────────────────────────────────
echo "\nStep 6 — Creating CapEx requests...\n";

$capexDefs = [
    [
        'title'          => 'Replace 5 end-of-life dry van trailers',
        'description'    => 'Aging Q3 2016 vintage fleet — out of warranty and hitting high-repair-cost territory. Plan to acquire 5 new Wabash DuraPlate 53ft vans to rotate in.',
        'asset_class'    => 'fleet_equipment',
        'budget_amount'  => '275000.00',
        'actual_amount'  => '262500.00',
        'status'         => 'approved',
        'requested_days' => 45,
        'approved_days'  => 38,
        'justification'  => 'Fleet renewal program — reduces repair costs and improves fuel economy.',
    ],
    [
        'title'          => 'Reefer fleet expansion — 2 Utility 48ft units',
        'description'    => 'Growing demand from produce customers (Tonny Bhinder + Avi). Adding 2 reefer units to meet summer season bookings.',
        'asset_class'    => 'fleet_equipment',
        'budget_amount'  => '190000.00',
        'actual_amount'  => null,
        'status'         => 'pending_approval',
        'requested_days' => 8,
        'approved_days'  => null,
        'justification'  => 'All 7 existing reefers are on lease or reserved. Losing bookings to competitors.',
    ],
    [
        'title'          => 'Yard security upgrade — gate cameras + fencing',
        'description'    => 'Upgrade Surrey yard perimeter security following a break-in attempt in February.',
        'asset_class'    => 'leasehold_improvements',
        'budget_amount'  => '48000.00',
        'actual_amount'  => '45200.00',
        'status'         => 'completed',
        'requested_days' => 90,
        'approved_days'  => 82,
        'justification'  => 'Insurance premium reduction of ~8% and risk mitigation.',
    ],
];

$capexCount = 0;
foreach ($capexDefs as $c) {
    $reqAt = $c['requested_days'] ? date('Y-m-d H:i:s', strtotime("-{$c['requested_days']} days")) : null;
    $appAt = $c['approved_days']  ? date('Y-m-d H:i:s', strtotime("-{$c['approved_days']} days"))  : null;
    $comAt = $c['status'] === 'completed' ? date('Y-m-d H:i:s', strtotime('-14 days')) : null;

    db_insert('acc_capex_requests', [
        'request_number'  => sprintf('CAPEX-%d-%03d', date('Y'), ++$capexCount),
        'title'           => $c['title'],
        'description'     => $c['description'],
        'asset_class'     => $c['asset_class'],
        'budget_amount'   => $c['budget_amount'],
        'actual_amount'   => $c['actual_amount'],
        'status'          => $c['status'],
        'requested_by'    => $userId,
        'requested_at'    => $reqAt,
        'approved_by'     => $appAt ? $userId : null,
        'approved_at'     => $appAt,
        'completed_by'    => $comAt ? $userId : null,
        'completed_at'    => $comAt,
        'justification'   => $c['justification'],
    ]);
}
echo "  Created $capexCount CapEx requests\n";

// ────────────────────────────────────────────────────────────
// Step 7 — Year-end checklist items for 2025 (show off the year-end module)
// ────────────────────────────────────────────────────────────
echo "\nStep 7 — Seeding year-end checklist...\n";

$checklistItems = [
    ['item_key' => 'bank_recon_dec',    'item_label' => 'Reconcile all bank accounts for December 2025',        'is_complete' => 1, 'sort_order' => 10],
    ['item_key' => 'ap_review',         'item_label' => 'Review all outstanding A/P bills and ensure posted',    'is_complete' => 1, 'sort_order' => 20],
    ['item_key' => 'ar_review',         'item_label' => 'Review A/R aging and write off bad debts',              'is_complete' => 1, 'sort_order' => 30],
    ['item_key' => 'depreciation_q4',   'item_label' => 'Run Q4 depreciation for all fixed assets',              'is_complete' => 1, 'sort_order' => 40],
    ['item_key' => 'physical_inv',      'item_label' => 'Physical inventory count for fleet + spare parts',      'is_complete' => 1, 'sort_order' => 50],
    ['item_key' => 'inventory_adjust',  'item_label' => 'Post inventory adjustments from physical count',        'is_complete' => 0, 'sort_order' => 60],
    ['item_key' => 'accruals',          'item_label' => 'Post year-end accruals (unpaid maintenance, insurance)', 'is_complete' => 0, 'sort_order' => 70],
    ['item_key' => 'prepaid_amortize',  'item_label' => 'Amortize prepaid expenses (insurance, registrations)',  'is_complete' => 0, 'sort_order' => 80],
    ['item_key' => 'tax_provision',     'item_label' => 'Calculate and post corporate tax provision',            'is_complete' => 0, 'sort_order' => 90],
    ['item_key' => 'close_periods',     'item_label' => 'Close all 2025 periods (lock against editing)',         'is_complete' => 0, 'sort_order' => 100],
    ['item_key' => 'gst_return',        'item_label' => 'File final GST/HST return for 2025',                     'is_complete' => 0, 'sort_order' => 110],
    ['item_key' => 'gifi_export',       'item_label' => 'Export GIFI-formatted financials for accountant',        'is_complete' => 0, 'sort_order' => 120],
    ['item_key' => 'retained_earnings', 'item_label' => 'Post year-end close entry to retained earnings',         'is_complete' => 0, 'sort_order' => 130],
    ['item_key' => 'annual_reports',    'item_label' => 'Generate annual financial statements',                   'is_complete' => 0, 'sort_order' => 140],
    ['item_key' => 'archive',           'item_label' => 'Archive 2025 supporting documents',                      'is_complete' => 0, 'sort_order' => 150],
];

foreach ($checklistItems as $item) {
    db_insert('acc_year_end_checklist', [
        'year'         => 2025,
        'item_key'     => $item['item_key'],
        'item_label'   => $item['item_label'],
        'is_complete'  => $item['is_complete'],
        'completed_by' => $item['is_complete'] ? $userId : null,
        'completed_at' => $item['is_complete'] ? date('Y-m-d H:i:s', strtotime('-' . random_int(5, 30) . ' days')) : null,
        'sort_order'   => $item['sort_order'],
    ]);
}
echo "  Seeded " . count($checklistItems) . " year-end checklist items (5 complete, 10 outstanding)\n";

// ────────────────────────────────────────────────────────────
// Done
// ────────────────────────────────────────────────────────────
echo "\n=== Accounting data seeded ===\n";

$jeTotal = db_row("SELECT COUNT(*) AS n FROM acc_journal_entries", [])['n'];
$lineTotal = db_row("SELECT COUNT(*) AS n FROM acc_journal_entry_lines", [])['n'];
$bankTxns = db_row("SELECT COUNT(*) AS n FROM acc_bank_transactions", [])['n'];
$bills = db_row("SELECT COUNT(*) AS n FROM acc_bills", [])['n'];
$runs = db_row("SELECT COUNT(*) AS n FROM acc_depreciation_runs", [])['n'];
$runLines = db_row("SELECT COUNT(*) AS n FROM acc_depreciation_run_lines", [])['n'];
$capex = db_row("SELECT COUNT(*) AS n FROM acc_capex_requests", [])['n'];
$yec = db_row("SELECT COUNT(*) AS n FROM acc_year_end_checklist", [])['n'];
$vendorRows = db_row("SELECT COUNT(*) AS n FROM vendors", [])['n'];

printf("  Journal entries:        %d (%d lines)\n", $jeTotal, $lineTotal);
printf("  Bank transactions:      %d\n", $bankTxns);
printf("  Vendors:                %d\n", $vendorRows);
printf("  Vendor bills:           %d\n", $bills);
printf("  Depreciation runs:      %d (%d run lines)\n", $runs, $runLines);
printf("  CapEx requests:         %d\n", $capex);
printf("  Year-end checklist:     %d items\n", $yec);
