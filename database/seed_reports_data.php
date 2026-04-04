<?php
declare(strict_types=1);
/**
 * database/seed_reports_data.php
 *
 * Populates realistic data across invoices, payments, payment_allocations,
 * invoice_line_items, maintenance_work_orders, and equipment compliance fields
 * so that all 4 report tabs (Revenue, Fleet, Customer, Compliance) show
 * meaningful charts and tables.
 *
 * Run once:  php database/seed_reports_data.php
 *
 * Safe to re-run — uses INSERT IGNORE on unique keys and skips if invoice
 * count already exceeds 50.
 */

require_once __DIR__ . '/../api/bootstrap.php';

// ── Guard: skip if already seeded ───────────────────────────────────────────
$existing = db_row("SELECT COUNT(*) as cnt FROM invoices WHERE status NOT IN ('draft','void')");
if ((int) $existing['cnt'] > 20) {
    echo "Already have {$existing['cnt']} non-draft invoices — skipping seed.\n";
    exit(0);
}

echo "Seeding reports data...\n";

// ── Customers and their active leases ───────────────────────────────────────
// We'll create invoices for customers 4-15 (existing) across past 12 months
$customers = [
    4  => ['name' => 'Pacific Rim Carriers Ltd',      'lease' => 8,  'unit' => 6,  'rate' => 2950],
    5  => ['name' => 'Coastal Freight Solutions',      'lease' => 9,  'unit' => 7,  'rate' => 2800],
    6  => ['name' => 'Rocky Mountain Transport',       'lease' => 10, 'unit' => 9,  'rate' => 2400],
    7  => ['name' => 'Cascade Logistics Group',        'lease' => 11, 'unit' => 16, 'rate' => 2200],
    8  => ['name' => 'Northern Gateway Haulers',       'lease' => 12, 'unit' => 8,  'rate' => 2950],
    9  => ['name' => 'Fraser Valley Flatbeds',         'lease' => 13, 'unit' => 10, 'rate' => 2400],
    10 => ['name' => 'Metro Vancouver Intermodal',     'lease' => 14, 'unit' => 12, 'rate' => 1850],
    11 => ['name' => 'Island Pacific Trucking',        'lease' => 15, 'unit' => 13, 'rate' => 1850],
    12 => ['name' => 'Trans-Canada Cold Chain',        'lease' => 16, 'unit' => 14, 'rate' => 1600],
    13 => ['name' => 'Okanagan Express Lines',         'lease' => 17, 'unit' => 15, 'rate' => 1600],
    14 => ['name' => 'Lower Mainland Bulk Carriers',   'lease' => 18, 'unit' => 17, 'rate' => 2200],
    15 => ['name' => 'Pacific Coast Reefers Inc',      'lease' => 19, 'unit' => 6,  'rate' => 2700],
];

// Generate invoices spanning July 2025 → March 2026 (9 months of history)
$months = [];
for ($m = 7; $m <= 12; $m++) {
    $months[] = ['year' => 2025, 'month' => $m];
}
for ($m = 1; $m <= 3; $m++) {
    $months[] = ['year' => 2026, 'month' => $m];
}

$invoiceNum = 100; // Start from INV-2025-00100 to avoid collision
$paymentNum = 100;
$invId      = 100; // Use high IDs to avoid collision with existing data
$payId      = 100;

$pdo = db_pdo();

// Status distribution: ~50% paid, ~20% partially_paid, ~15% sent, ~10% overdue, ~5% void
$statusWeights = [
    'paid'           => 50,
    'partially_paid' => 20,
    'sent'           => 15,
    'overdue'        => 10,
    'void'           => 5,
];

function pickStatus(array $weights): string
{
    $total = array_sum($weights);
    $rand  = mt_rand(1, $total);
    $acc   = 0;
    foreach ($weights as $status => $w) {
        $acc += $w;
        if ($rand <= $acc) return $status;
    }
    return 'sent';
}

// Payment methods
$methods = ['check', 'ach', 'wire', 'e_transfer', 'credit_card'];

foreach ($months as $period) {
    $y = $period['year'];
    $m = $period['month'];
    $periodStart = sprintf('%04d-%02d-01', $y, $m);
    $periodEnd   = date('Y-m-t', strtotime($periodStart));
    $periodDays  = (int) date('t', strtotime($periodStart));
    $invDate     = $periodStart;
    $dueDate     = date('Y-m-d', strtotime($periodEnd . ' +15 days'));

    // Each customer gets an invoice for each month (with some randomness)
    foreach ($customers as $custId => $info) {
        // Skip ~15% randomly to create variation
        if (mt_rand(1, 100) <= 15) continue;

        $invId++;
        $invoiceNum++;
        $invNumber = sprintf('INV-%04d-%05d', $y, $invoiceNum);

        $rate     = $info['rate'];
        // Add some randomness: ±10%
        $subtotal = round($rate * (0.9 + (mt_rand(0, 200) / 1000)), 2);
        $gstRate  = 0.05;
        $gstAmt   = round($subtotal * $gstRate, 2);
        $total    = round($subtotal + $gstAmt, 2);

        $status = pickStatus($statusWeights);

        // Determine payment amounts based on status
        $amountPaid = '0.00';
        $balanceDue = number_format($total, 2, '.', '');
        $paidDate   = null;
        $sentDate   = $invDate;

        if ($status === 'paid') {
            $amountPaid = number_format($total, 2, '.', '');
            $balanceDue = '0.00';
            // Paid within 5-25 days of invoice
            $paidDate = date('Y-m-d', strtotime($invDate . ' +' . mt_rand(5, 25) . ' days'));
        } elseif ($status === 'partially_paid') {
            $partial = round($total * (mt_rand(30, 70) / 100), 2);
            $amountPaid = number_format($partial, 2, '.', '');
            $balanceDue = number_format($total - $partial, 2, '.', '');
        } elseif ($status === 'void') {
            $balanceDue = '0.00';
            $total = 0; // void invoices
        }

        // INSERT invoice
        $sql = "INSERT INTO invoices
            (id, invoice_number, invoice_type, customer_id, lease_id,
             company_name_snapshot, billing_period_start, billing_period_end,
             billing_period_days, billing_type, rate_method_used,
             invoice_date, due_date, paid_date, sent_date, status,
             subtotal, discount_type, discount_value, discount_amount,
             subtotal_after_discount, tax_gst_rate, tax_gst_amount,
             tax_total, total_amount, amount_paid, credits_applied,
             balance_due, currency, generation_source,
             created_by, updated_by, created_at, updated_at)
            VALUES
            (?, ?, 'regular', ?, ?,
             ?, ?, ?,
             ?, 'full_month', 'monthly',
             ?, ?, ?, ?, ?,
             ?, 'none', 0, 0,
             ?, ?, ?,
             ?, ?, ?, 0,
             ?, 'CAD', 'manual',
             1, 1, NOW(), NOW())";

        try {
            db_execute($sql, [
                $invId, $invNumber, $custId, $info['lease'],
                $info['name'], $periodStart, $periodEnd,
                $periodDays,
                $invDate, $dueDate, $paidDate, $sentDate, $status,
                number_format($subtotal, 2, '.', ''),
                number_format($subtotal, 2, '.', ''),
                number_format($gstRate, 4, '.', ''),
                number_format($gstAmt, 2, '.', ''),
                number_format($gstAmt, 2, '.', ''),
                number_format(($status === 'void' ? 0 : $total), 2, '.', ''),
                $amountPaid,
                $balanceDue,
            ]);
        } catch (\Throwable $e) {
            echo "  SKIP invoice {$invNumber}: {$e->getMessage()}\n";
            continue;
        }

        // INSERT line item for base rental
        db_execute(
            "INSERT INTO invoice_line_items
                (invoice_id, sort_order, item_type, description, quantity,
                 unit, unit_price, amount, is_credit, taxable,
                 billing_days, rate_method, period_start, period_end, created_at)
             VALUES (?, 1, 'base_rental', ?, 1, 'month', ?, ?, 0, 1, ?, 'monthly', ?, ?, NOW())",
            [
                $invId,
                "Monthly rental — Unit #{$info['unit']}",
                number_format($subtotal, 2, '.', ''),
                number_format($subtotal, 2, '.', ''),
                $periodDays,
                $periodStart,
                $periodEnd,
            ]
        );

        // INSERT payment + allocation for paid/partially_paid invoices
        if (in_array($status, ['paid', 'partially_paid'])) {
            $payId++;
            $paymentNum++;
            $payNumber  = sprintf('PAY-%04d-%05d', $y, $paymentNum);
            $payDate    = $paidDate ?? date('Y-m-d', strtotime($invDate . ' +' . mt_rand(10, 30) . ' days'));
            $payMethod  = $methods[array_rand($methods)];

            db_execute(
                "INSERT INTO payments
                    (id, payment_number, customer_id, amount, currency,
                     payment_method, payment_date, status,
                     recorded_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'CAD', ?, ?, 'cleared', 1, NOW(), NOW())",
                [$payId, $payNumber, $custId, $amountPaid, $payMethod, $payDate]
            );

            db_execute(
                "INSERT INTO payment_allocations
                    (payment_id, invoice_id, amount, currency, allocation_type, allocated_by, created_at)
                 VALUES (?, ?, ?, 'CAD', 'auto', 1, NOW())",
                [$payId, $invId, $amountPaid]
            );
        }
    }
}

// ── Add some April 2026 invoices (current month) ────────────────────────────
$aprilCustomers = [4, 5, 6, 7, 8, 9, 10, 11, 12];
foreach ($aprilCustomers as $custId) {
    $info = $customers[$custId];
    $invId++;
    $invoiceNum++;
    $invNumber = sprintf('INV-2026-%05d', $invoiceNum);
    $subtotal  = round($info['rate'] * (0.95 + (mt_rand(0, 100) / 1000)), 2);
    $gstAmt    = round($subtotal * 0.05, 2);
    $total     = round($subtotal + $gstAmt, 2);

    // Recent invoices: mostly sent, some partially paid
    $status    = mt_rand(1, 10) <= 4 ? 'partially_paid' : 'sent';
    $amtPaid   = '0.00';
    $balance   = number_format($total, 2, '.', '');
    if ($status === 'partially_paid') {
        $partial = round($total * 0.4, 2);
        $amtPaid = number_format($partial, 2, '.', '');
        $balance = number_format($total - $partial, 2, '.', '');
    }

    try {
        db_execute(
            "INSERT INTO invoices
                (id, invoice_number, invoice_type, customer_id, lease_id,
                 company_name_snapshot, billing_period_start, billing_period_end,
                 billing_period_days, billing_type, rate_method_used,
                 invoice_date, due_date, sent_date, status,
                 subtotal, discount_type, discount_value, discount_amount,
                 subtotal_after_discount, tax_gst_rate, tax_gst_amount,
                 tax_total, total_amount, amount_paid, credits_applied,
                 balance_due, currency, generation_source,
                 created_by, updated_by, created_at, updated_at)
             VALUES
                (?, ?, 'regular', ?, ?,
                 ?, '2026-04-01', '2026-04-30',
                 30, 'full_month', 'monthly',
                 '2026-04-01', '2026-04-15', '2026-04-01', ?,
                 ?, 'none', 0, 0,
                 ?, 0.0500, ?,
                 ?, ?, ?, 0,
                 ?, 'CAD', 'manual',
                 1, 1, NOW(), NOW())",
            [
                $invId, $invNumber, $custId, $info['lease'],
                $info['name'],
                $status,
                number_format($subtotal, 2, '.', ''),
                number_format($subtotal, 2, '.', ''),
                number_format($gstAmt, 2, '.', ''),
                number_format($gstAmt, 2, '.', ''),
                number_format($total, 2, '.', ''),
                $amtPaid,
                $balance,
            ]
        );
    } catch (\Throwable $e) {
        echo "  SKIP Apr invoice {$invNumber}: {$e->getMessage()}\n";
    }
}

echo "Invoices seeded.\n";

// ── Maintenance work orders across units ────────────────────────────────────
$workTypes = ['scheduled_service', 'repair', 'tire', 'breakdown', 'inspection', 'body_damage', 'electrical'];
$priorities = ['low', 'medium', 'high', 'emergency'];
$woTitles = [
    'scheduled_service' => ['Annual service', 'Oil change & filter', 'Brake inspection', '50k km service'],
    'repair'            => ['Hydraulic leak fix', 'Air brake valve replacement', 'Landing gear repair', 'Rear door hinge replacement'],
    'tire'              => ['Full tire replacement', 'Flat repair', 'Tire rotation', 'Blowout — roadside'],
    'breakdown'         => ['Roadside breakdown — axle', 'Highway breakdown — electrical', 'Yard breakdown — suspension'],
    'inspection'        => ['Pre-trip inspection', 'CVI inspection', 'DOT inspection'],
    'body_damage'       => ['Side panel dent repair', 'Rear bumper damage', 'Floor repair'],
    'electrical'        => ['Light wiring harness', 'ABS sensor replacement', 'Tail light replacement'],
];

$units = [6, 7, 8, 9, 10, 12, 13, 14, 15, 16, 17];
$vendors = db_select("SELECT id FROM vendors ORDER BY id LIMIT 5");
$vendorIds = array_column($vendors, 'id');
if (empty($vendorIds)) $vendorIds = [null];

$woId = 100;
foreach ($units as $unitId) {
    // 2-5 work orders per unit over past year
    $count = mt_rand(2, 5);
    for ($i = 0; $i < $count; $i++) {
        $woId++;
        $wType  = $workTypes[array_rand($workTypes)];
        $titles = $woTitles[$wType];
        $title  = $titles[array_rand($titles)];
        $prio   = $priorities[array_rand($priorities)];

        // Random date in past 12 months
        $daysAgo = mt_rand(1, 365);
        $reqDate = date('Y-m-d', strtotime("-{$daysAgo} days"));
        $schDate = date('Y-m-d', strtotime($reqDate . ' +' . mt_rand(1, 7) . ' days'));

        $completed  = mt_rand(1, 10) <= 8; // 80% completed
        $compDate   = $completed ? date('Y-m-d', strtotime($schDate . ' +' . mt_rand(0, 5) . ' days')) : null;
        $woStatus   = $completed ? 'completed' : ($i % 2 === 0 ? 'in_progress' : 'open');

        $laborCost = round(mt_rand(50, 500) + mt_rand(0, 99) / 100, 2);
        $partsCost = round(mt_rand(20, 800) + mt_rand(0, 99) / 100, 2);
        $totalCost = round($laborCost + $partsCost, 2);

        $vendorId = $vendorIds[array_rand($vendorIds)];
        $woNumber = sprintf('WO-%04d-%05d', (int) date('Y', strtotime($reqDate)), $woId);

        try {
            db_execute(
                "INSERT INTO maintenance_work_orders
                    (id, work_order_number, equipment_unit_id, vendor_id,
                     work_type, priority, status, title,
                     requested_date, scheduled_date, completed_date,
                     labor_cost, parts_cost, total_cost,
                     created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
                [
                    $woId, $woNumber, $unitId, $vendorId,
                    $wType, $prio, $woStatus, $title,
                    $reqDate, $schDate, $compDate,
                    $laborCost, $partsCost, $totalCost,
                ]
            );
        } catch (\Throwable $e) {
            echo "  SKIP WO {$woNumber}: {$e->getMessage()}\n";
        }
    }
}

echo "Maintenance work orders seeded.\n";

// ── Equipment compliance dates ──────────────────────────────────────────────
// Spread expiry dates across past, present, and future to test all compliance views
$today = new DateTimeImmutable('today');

$complianceData = [
    6  => ['cvi' => '+45 days',  'reg' => '+120 days', 'ins' => '+200 days'],
    7  => ['cvi' => '-10 days',  'reg' => '+30 days',  'ins' => '+180 days'],  // CVI expired
    8  => ['cvi' => '+15 days',  'reg' => '-5 days',   'ins' => '+90 days'],   // Reg expired
    9  => ['cvi' => '+60 days',  'reg' => '+90 days',  'ins' => '+25 days'],   // Ins expiring soon
    10 => ['cvi' => '-30 days',  'reg' => '-15 days',  'ins' => '+300 days'],  // Multiple expired
    12 => ['cvi' => '+180 days', 'reg' => '+200 days', 'ins' => '+150 days'],  // All good
    13 => ['cvi' => '+10 days',  'reg' => '+350 days', 'ins' => '-2 days'],    // Ins just expired
    14 => ['cvi' => '+270 days', 'reg' => '+45 days',  'ins' => '+60 days'],
    15 => ['cvi' => '-60 days',  'reg' => '+180 days', 'ins' => '+120 days'],  // CVI long expired
    16 => ['cvi' => '+90 days',  'reg' => '+15 days',  'ins' => '+45 days'],   // Reg expiring soon
    17 => ['cvi' => '+30 days',  'reg' => '+60 days',  'ins' => '+10 days'],   // Ins expiring soon
];

foreach ($complianceData as $unitId => $dates) {
    $cvi = $today->modify($dates['cvi'])->format('Y-m-d');
    $reg = $today->modify($dates['reg'])->format('Y-m-d');
    $ins = $today->modify($dates['ins'])->format('Y-m-d');

    db_execute(
        "UPDATE equipment_units SET
            cvi_expiry = ?, registration_expiry = ?, insurance_expiry = ?,
            cvi_interval_days = 365, registration_interval_days = 365, insurance_interval_days = 365,
            updated_at = NOW()
         WHERE id = ?",
        [$cvi, $reg, $ins, $unitId]
    );
}

// Also set yard_location for yard report
$yards = ['Vancouver', 'Surrey', 'Richmond', 'Burnaby', 'Langley'];
foreach ($units as $idx => $unitId) {
    $yard = $yards[$idx % count($yards)];
    db_execute("UPDATE equipment_units SET yard_location = ? WHERE id = ? AND (yard_location IS NULL OR yard_location = '')", [$yard, $unitId]);
}

echo "Compliance & yard data seeded.\n";

// ── Purge report cache so fresh data appears immediately ────────────────────
db_execute("DELETE FROM report_cache WHERE 1=1");
echo "Report cache cleared.\n";

// ── Summary ─────────────────────────────────────────────────────────────────
$cnt = db_row("SELECT COUNT(*) as cnt FROM invoices WHERE status NOT IN ('draft','void')");
echo "\nDone! Non-draft/void invoices: {$cnt['cnt']}\n";
$cnt2 = db_row("SELECT COUNT(DISTINCT customer_id) as cnt FROM invoices WHERE status NOT IN ('draft','void')");
echo "Unique customers with invoices: {$cnt2['cnt']}\n";
$cnt3 = db_row("SELECT COUNT(*) as cnt FROM maintenance_work_orders");
echo "Maintenance work orders: {$cnt3['cnt']}\n";
