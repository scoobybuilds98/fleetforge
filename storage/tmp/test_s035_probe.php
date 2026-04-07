<?php
declare(strict_types=1);

// S035 probe — survey existing data so the test setup can build on it.

require __DIR__ . '/../../config/app.php';

$pad = fn(string $s, int $w = 70) => str_pad($s, $w);

echo str_repeat('=', 80) . PHP_EOL;
echo "S035 — DATA PROBE — survey current DB before tax tests" . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;

// ── 1. Periods ─────────────────────────────────────────────────
echo "\n## ACC PERIODS — Q1/Q2 2026:\n";
$periods = db_select(
    "SELECT id, name, start_date, end_date, status FROM acc_periods
     WHERE start_date BETWEEN '2026-01-01' AND '2026-06-30'
     ORDER BY start_date"
);
foreach ($periods as $p) {
    printf("  #%-3d %s (%s → %s) status=%s\n",
        $p['id'], $p['name'], $p['start_date'], $p['end_date'], $p['status']);
}

// ── 2. Customers by province ───────────────────────────────────
echo "\n## CUSTOMERS by province:\n";
$custs = db_select(
    "SELECT id, company_name, province, tax_exempt, gst_exempt, pst_exempt FROM customers
     WHERE deleted_at IS NULL
     ORDER BY province, company_name"
);
foreach ($custs as $c) {
    printf("  #%-3d %-30s prov=%-3s tax_ex=%d gst_ex=%d pst_ex=%d\n",
        $c['id'], substr($c['company_name'], 0, 30),
        $c['province'] ?? 'NULL',
        (int) $c['tax_exempt'], (int) $c['gst_exempt'], (int) $c['pst_exempt']);
}

// ── 3. Vendors ─────────────────────────────────────────────────
echo "\n## VENDORS (first 5):\n";
$vends = db_select("SELECT id, name FROM vendors WHERE deleted_at IS NULL ORDER BY id LIMIT 5");
foreach ($vends as $v) {
    printf("  #%-3d %s\n", $v['id'], $v['name']);
}

// ── 4. Existing acc_tax_filing_periods ─────────────────────────
echo "\n## EXISTING TAX FILING PERIODS:\n";
$tx = db_select("SELECT id, tax_type, period_start, period_end, status FROM acc_tax_filing_periods ORDER BY id");
if (empty($tx)) {
    echo "  (none)\n";
} else {
    foreach ($tx as $p) {
        printf("  #%-3d %-8s %s → %s status=%s\n",
            $p['id'], $p['tax_type'], $p['period_start'], $p['period_end'], $p['status']);
    }
}

// ── 5. COA tax accounts ────────────────────────────────────────
echo "\n## COA — TAX ACCOUNTS:\n";
$accts = db_select(
    "SELECT id, code, name, account_type, is_active FROM acc_accounts
     WHERE code IN ('1010', '1050', '2030', '2040')
     ORDER BY code"
);
foreach ($accts as $a) {
    printf("  #%-3d %s — %-30s type=%s active=%d\n",
        $a['id'], $a['code'], substr($a['name'], 0, 30), $a['account_type'], (int) $a['is_active']);
}

// ── 6. Settings: accounting enabled? ───────────────────────────
echo "\n## ACCOUNTING SETTINGS (key flags):\n";
$rows = db_select(
    "SELECT `key`, `value` FROM settings
     WHERE `key` IN ('accounting_enabled', 'tax_gst_rate', 'tax_hst_rate',
                     'tax_pst_bc_rate', 'tax_pst_sk_rate', 'tax_pst_mb_rate',
                     'acc_default_cash_account_id', 'acc_gst_payable_account_id',
                     'acc_pst_payable_account_id', 'acc_gst_itc_account_id')"
);
foreach ($rows as $r) {
    printf("  %-32s = %s\n", $r['key'], $r['value']);
}

// ── 7. Invoices in Q1 2026 ─────────────────────────────────────
echo "\n## INVOICES in Q1 2026:\n";
$invs = db_select(
    "SELECT id, invoice_number, customer_id, invoice_date, subtotal,
            tax_gst_amount, tax_hst_amount, tax_pst_amount, status
     FROM invoices
     WHERE deleted_at IS NULL AND invoice_date BETWEEN '2026-01-01' AND '2026-03-31'
     ORDER BY invoice_date"
);
if (empty($invs)) {
    echo "  (none)\n";
} else {
    foreach ($invs as $i) {
        printf("  #%-3d %-15s cust=%-3d %s sub=\$%-10s gst=\$%-7s hst=\$%-7s pst=\$%-7s status=%s\n",
            $i['id'], $i['invoice_number'], $i['customer_id'], $i['invoice_date'],
            $i['subtotal'], $i['tax_gst_amount'], $i['tax_hst_amount'], $i['tax_pst_amount'], $i['status']);
    }
}

// ── 8. Bills in Q1 2026 ────────────────────────────────────────
echo "\n## ACC_BILLS in Q1 2026 (vendor bills with GST):\n";
$bills = db_select(
    "SELECT b.id, b.bill_number, b.vendor_bill_number, b.bill_date, b.subtotal,
            b.tax_gst_amount, b.tax_hst_amount, b.status, v.name AS vendor_name
     FROM acc_bills b
     LEFT JOIN vendors v ON v.id = b.vendor_id
     WHERE b.bill_date BETWEEN '2026-01-01' AND '2026-03-31'
     ORDER BY b.bill_date"
);
if (empty($bills)) {
    echo "  (none)\n";
} else {
    foreach ($bills as $b) {
        printf("  #%-3d %s vbill=%s %s sub=\$%-8s gst=\$%-6s vendor=%s status=%s\n",
            $b['id'], $b['bill_number'] ?? '—', $b['vendor_bill_number'] ?? '—',
            $b['bill_date'], $b['subtotal'], $b['tax_gst_amount'], $b['vendor_name'] ?? '—', $b['status']);
    }
}

// ── 9. Banks ───────────────────────────────────────────────────
echo "\n## ACC_BANK_ACCOUNTS:\n";
$banks = db_select("SELECT id, name, gl_account_id, is_active FROM acc_bank_accounts ORDER BY id LIMIT 5");
foreach ($banks as $b) {
    printf("  #%-3d %-30s gl_account_id=%s active=%d\n",
        $b['id'], $b['name'], $b['gl_account_id'], (int) $b['is_active']);
}

// ── 10. Source type ENUM check ─────────────────────────────────
echo "\n## SOURCE_TYPE ENUM CHECK:\n";
$enum = db_row(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'acc_journal_entries'
       AND COLUMN_NAME = 'source_type'"
);
echo "  " . ($enum['COLUMN_TYPE'] ?? 'NOT FOUND') . "\n";
$hasTaxRemittance = $enum && str_contains($enum['COLUMN_TYPE'], "'tax_remittance'");
echo "  tax_remittance present: " . ($hasTaxRemittance ? "✅" : "❌") . "\n";

// ── 11. updated_at columns check ───────────────────────────────
echo "\n## UPDATED_AT COLUMN CHECK:\n";
foreach (['acc_tax_filing_periods', 'acc_tax_remittances'] as $tbl) {
    $col = db_row(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ? AND COLUMN_NAME = 'updated_at'",
        [$tbl]
    );
    echo "  $tbl.updated_at: " . ($col ? "✅" : "❌") . "\n";
}

echo "\n" . str_repeat('=', 80) . PHP_EOL;
echo "PROBE COMPLETE" . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;
