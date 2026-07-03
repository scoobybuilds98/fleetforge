<?php
declare(strict_types=1);

/**
 * scripts/seed_payoff_showcase.php
 *
 * Seeds 3 equipment units with realistic payoff data for showcase/dev:
 *   T5301 — 53' Dry Van   (financed, 22 months of history, active lease)
 *   T5302 — 53' Reefer    (cash, 16 months of history, completed leases)
 *   T5303 — 53' Flatbed   (financed, 9 months of history, current lease)
 *
 * Each unit gets:
 *   • A linked acc_fixed_assets row (acquisition, taxes, financing, fixed costs, depreciation)
 *   • 2–3 leases to real customers
 *   • Monthly invoices + line items (revenue history)
 *   • Maintenance work orders
 *   • 1–2 damage claims
 *
 * GL accounts used:
 *   asset_account_id     = 12 (Fleet Equipment — Cost, 1210)
 *   accum_depr_account_id = 13 (Fleet Equipment — Accumulated Depr., 1220)
 *   depr_expense_account_id = 50 (Depreciation — Rental Fleet, 5010)
 *
 * Safe to re-run: checks each unit's asset_number independently — skips units already seeded.
 *
 * Usage: php scripts/seed_payoff_showcase.php
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';

// ── Per-unit seed guards (re-run safe) ───────────────────────────────────────
// Each transaction is independent; skip any unit whose anchor asset already exists.
$seeded = [];
foreach (['SEED-DRY-VAN-001','SEED-REEFER-001','SEED-FLATBED-001'] as $an) {
    if (db_row("SELECT id FROM acc_fixed_assets WHERE asset_number = ? LIMIT 1", [$an])) {
        $seeded[$an] = true;
        echo "  ↳ skipping {$an} (already seeded)\n";
    }
}

// ── GL account IDs ────────────────────────────────────────────────────────────
define('GL_FLEET_COST',  12);   // 1210 Fleet Equipment — Cost
define('GL_FLEET_DEPR',  13);   // 1220 Fleet Equipment — Accumulated Depreciation
define('GL_DEPR_EXP',    50);   // 5010 Depreciation — Rental Fleet

$year  = date('Y');
$today = date('Y-m-d');

// ── Invoice numbering ──────────────────────────────────────────────────────────
// WHY: invoices MUST be numbered via the canonical InvoiceGenerator counter
// ('invoice.next_number.{year}'), NOT generate_id('INV', …) which keeps a
// SEPARATE 'inv.next_number.{year}' counter. Both emit "INV-YYYY-NNNNN", so
// using the wrong one leaves the real counter behind reality and the next live
// invoice mint (e.g. lease activation) collides on UNIQUE(invoice_number) and
// aborts — exactly the "failed to activate lease" bug this seed previously caused.
$invGen = new \FleetForge\Billing\InvoiceGenerator();
$mintInvoiceNumber = static fn (): string => $invGen->generateInvoiceNumber();

// ── Helper: months ago ────────────────────────────────────────────────────────
$mo = fn(int $n, string $day = '01'): string =>
    (new DateTimeImmutable('first day of this month'))
        ->modify("-{$n} months")
        ->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT);

$moLast = fn(int $n): string =>
    (new DateTimeImmutable('last day of this month'))
        ->modify("-{$n} months")
        ->format('Y-m-d');

echo "Seeding payoff showcase data…\n\n";

// ══════════════════════════════════════════════════════════════════════════════
// UNIT 1 — T5301  53' Dry Van (2022)
// Acquired 22 months ago, financed, 2 leases completed + 1 active
// ══════════════════════════════════════════════════════════════════════════════
$unitId1 = 1; // T5301

if (!isset($seeded['SEED-DRY-VAN-001'])) db_transaction(function () use ($unitId1, $mo, $moLast, $year, $today, $mintInvoiceNumber) {

    // ── Fixed asset ───────────────────────────────────────────────────────────
    $acqDate1 = $mo(22, '15');
    $deprCost1 = '68000.00'; // purchase price — depreciable portion
    $assetId1  = db_insert('acc_fixed_assets', [
        'asset_number'               => 'SEED-DRY-VAN-001',
        'name'                       => '53\' Dry Van Trailer — 2022',
        'asset_class'                => 'fleet_equipment',
        'acquisition_date'           => $acqDate1,
        'depreciation_start_date'    => $acqDate1,
        'acquisition_cost'           => '68000.00',
        'purchase_tax_gst'           => '3400.00',
        'purchase_tax_pst'           => '4760.00',
        'delivery_cost'              => '1200.00',
        'setup_cost'                 => '350.00',
        'depreciable_cost'           => $deprCost1,
        'salvage_value'              => '8000.00',
        'net_book_value'             => '50555.56', // 22/108 of deprn eaten
        'accumulated_depreciation'   => '17444.44',
        'depreciation_method'        => 'straight_line',
        'useful_life_years'          => '10',
        'is_financed'                => 1,
        'financing_monthly_payment'  => '1380.00',
        'financing_interest_rate'    => '6.75',
        'financing_remaining_months' => 38,
        'monthly_insurance_cost'     => '420.00',
        'monthly_licensing_cost'     => '185.00',
        'monthly_registration_cost'  => '22.00',
        'equipment_unit_id'          => $unitId1,
        'asset_account_id'           => GL_FLEET_COST,
        'accum_depr_account_id'      => GL_FLEET_DEPR,
        'depr_expense_account_id'    => GL_DEPR_EXP,
        'status'                     => 'active',
        'last_depreciation_date'     => $mo(1, '01'),
    ]);
    echo "  ✓ Fixed asset SEED-DRY-VAN-001 (id={$assetId1})\n";

    // ── Lease 1: Coastal Haul Logistics (months 22–12, completed) ────────────
    $l1ContractNum = generate_id('LSE', $year);
    $l1Id = db_insert('leases', [
        'contract_number'    => $l1ContractNum,
        'equipment_unit_id'  => $unitId1,
        'customer_id'        => 2, // Coastal Haul Logistics
        'status'             => 'completed',
        'start_date'         => $mo(22, '15'),
        'end_date'           => $mo(12, '14'),
        'monthly_rate'       => '2850.00',
        'classification'     => 'operating',
        'currency'           => 'CAD',
    ]);
    echo "  ✓ Lease 1 {$l1ContractNum} (id={$l1Id})\n";

    // Monthly invoices for lease 1 — months 22 down to 13
    for ($m = 22; $m >= 13; $m--) {
        $invNum = $mintInvoiceNumber();
        $bps    = $mo($m, '15');
        $bpe    = $moLast($m - 1);  // end of that month
        // Vary amounts slightly for realism
        $base   = '2850.00';
        $gst    = bcmul($base, '0.05', 2);
        $total  = bcadd($base, $gst, 2);
        $invId  = db_insert('invoices', [
            'invoice_number'       => $invNum,
            'lease_id'             => $l1Id,
            'customer_id'          => 2,
            'status'               => 'paid',
            'invoice_date'         => $mo($m - 1, '01'),
            'due_date'             => $mo($m - 1, '15'),
            'billing_period_start' => $bps,
            'billing_period_end'   => $bpe,
            'billing_period_days'  => 30,
            'billing_type'         => 'full_month',
            'subtotal'             => $base,
            'subtotal_after_discount' => $base,
            'tax_gst_amount'       => $gst,
            'tax_pst_amount'       => '0.00',
            'total_amount'         => $total,
            'balance_due'          => '0.00',
            'currency'             => 'CAD',
        ]);
        db_insert('invoice_line_items', [
            'invoice_id'  => $invId,
            'item_type'   => 'base_rental',
            'description' => 'Monthly trailer rental — ' . date('F Y', strtotime($bps)),
            'quantity'    => '1.0000',
            'unit_price'  => $base,
            'amount'      => $base,
            'is_credit'   => 0,
        ]);
    }
    echo "  ✓ 10 invoices for Lease 1\n";

    // ── Lease 2: Pacific Rim Transport (months 11–2, completed) ──────────────
    $l2ContractNum = generate_id('LSE', $year);
    $l2Id = db_insert('leases', [
        'contract_number'    => $l2ContractNum,
        'equipment_unit_id'  => $unitId1,
        'customer_id'        => 3, // Pacific Rim Transport
        'status'             => 'completed',
        'start_date'         => $mo(11, '20'),
        'end_date'           => $mo(2, '19'),
        'monthly_rate'       => '3100.00',
        'classification'     => 'operating',
        'currency'           => 'CAD',
    ]);
    echo "  ✓ Lease 2 {$l2ContractNum} (id={$l2Id})\n";

    for ($m = 11; $m >= 3; $m--) {
        $invNum = $mintInvoiceNumber();
        $base   = '3100.00';
        $gst    = bcmul($base, '0.05', 2);
        $total  = bcadd($base, $gst, 2);
        $invId  = db_insert('invoices', [
            'invoice_number'       => $invNum,
            'lease_id'             => $l2Id,
            'customer_id'          => 3,
            'status'               => 'paid',
            'invoice_date'         => $mo($m - 1, '20'),
            'due_date'             => $mo($m - 2, '05'),
            'billing_period_start' => $mo($m, '20'),
            'billing_period_end'   => $moLast($m - 1),
            'billing_period_days'  => 30,
            'billing_type'         => 'full_month',
            'subtotal'             => $base,
            'subtotal_after_discount' => $base,
            'tax_gst_amount'       => $gst,
            'tax_pst_amount'       => '0.00',
            'total_amount'         => $total,
            'balance_due'          => '0.00',
            'currency'             => 'CAD',
        ]);
        db_insert('invoice_line_items', [
            'invoice_id'  => $invId,
            'item_type'   => 'base_rental',
            'description' => 'Monthly trailer rental',
            'quantity'    => '1.0000',
            'unit_price'  => $base,
            'amount'      => $base,
            'is_credit'   => 0,
        ]);
    }
    echo "  ✓ 9 invoices for Lease 2\n";

    // ── Lease 3: Cascade Carriers (month 1 → active) ─────────────────────────
    $l3ContractNum = generate_id('LSE', $year);
    $l3Id = db_insert('leases', [
        'contract_number'    => $l3ContractNum,
        'equipment_unit_id'  => $unitId1,
        'customer_id'        => 4, // Cascade Carriers
        'status'             => 'active',
        'start_date'         => $mo(1, '01'),
        'end_date'           => null,
        'monthly_rate'       => '3250.00',
        'classification'     => 'operating',
        'currency'           => 'CAD',
    ]);
    // 1 invoice (last month sent, current in draft)
    $invNum = $mintInvoiceNumber();
    $base   = '3250.00';
    $gst    = bcmul($base, '0.05', 2);
    $total  = bcadd($base, $gst, 2);
    $invId  = db_insert('invoices', [
        'invoice_number'       => $invNum,
        'lease_id'             => $l3Id,
        'customer_id'          => 4,
        'status'               => 'sent',
        'invoice_date'         => $mo(1, '01'),
        'due_date'             => $mo(0, '15'),
        'billing_period_start' => $mo(1, '01'),
        'billing_period_end'   => $moLast(1),
        'billing_period_days'  => 30,
        'billing_type'         => 'full_month',
        'subtotal'             => $base,
            'subtotal_after_discount' => $base,
        'tax_gst_amount'       => $gst,
        'tax_pst_amount'       => '0.00',
        'total_amount'         => $total,
        'balance_due'          => $total,
        'currency'             => 'CAD',
    ]);
    db_insert('invoice_line_items', [
        'invoice_id'  => $invId, 'item_type' => 'base_rental',
        'description' => 'Monthly trailer rental', 'quantity' => '1.0000',
        'unit_price'  => $base, 'amount' => $base, 'is_credit' => 0,
    ]);
    echo "  ✓ Lease 3 {$l3ContractNum} (active) + 1 invoice\n";

    // Update unit status
    db_execute("UPDATE equipment_units SET status='on_lease' WHERE id=?", [$unitId1]);

    // ── Maintenance work orders ───────────────────────────────────────────────
    $wo1Num = generate_id('WO', $year);
    db_insert('maintenance_work_orders', [
        'work_order_number'  => $wo1Num,
        'equipment_unit_id'  => $unitId1,
        'work_type'          => 'tire',
        'title'              => 'Replace 4 drive axle tires',
        'description'        => 'Replaced 4 steer tires — 11R22.5 Michelin XDA2+',
        'status'             => 'completed',
        'requested_date'     => $mo(18, '10'),
        'completed_date'     => $mo(18, '14'),
        'labor_cost'         => '320.00',
        'parts_cost'         => '1840.00',
        'total_cost'         => '2160.00',
    ]);
    $wo2Num = generate_id('WO', $year);
    db_insert('maintenance_work_orders', [
        'work_order_number'  => $wo2Num,
        'equipment_unit_id'  => $unitId1,
        'work_type'          => 'scheduled_service',
        'title'              => '12-month scheduled inspection',
        'description'        => 'Annual DOT inspection, brake adjustment, lights check, greased all fittings',
        'status'             => 'completed',
        'requested_date'     => $mo(10, '05'),
        'completed_date'     => $mo(10, '07'),
        'labor_cost'         => '480.00',
        'parts_cost'         => '95.00',
        'total_cost'         => '575.00',
    ]);
    $wo3Num = generate_id('WO', $year);
    db_insert('maintenance_work_orders', [
        'work_order_number'  => $wo3Num,
        'equipment_unit_id'  => $unitId1,
        'work_type'          => 'repair',
        'title'              => 'Replace rear door seals',
        'description'        => 'Customer reported water ingress at rear doors. Replaced door seals and adjusted hinges.',
        'status'             => 'completed',
        'requested_date'     => $mo(5, '20'),
        'completed_date'     => $mo(5, '22'),
        'labor_cost'         => '220.00',
        'parts_cost'         => '145.00',
        'total_cost'         => '365.00',
    ]);
    $wo4Num = generate_id('WO', $year);
    db_insert('maintenance_work_orders', [
        'work_order_number'  => $wo4Num,
        'equipment_unit_id'  => $unitId1,
        'work_type'          => 'inspection',
        'title'              => 'Pre-lease inspection — Cascade Carriers',
        'description'        => 'Pre-lease condition inspection. Minor scuffs noted. All running lights pass, brakes at 80%.',
        'status'             => 'completed',
        'requested_date'     => $mo(1, '28'),
        'completed_date'     => $mo(1, '30'),
        'labor_cost'         => '150.00',
        'parts_cost'         => '0.00',
        'total_cost'         => '150.00',
    ]);
    echo "  ✓ 4 maintenance work orders\n";

    // ── Damage claim ──────────────────────────────────────────────────────────
    $clmNum = generate_id('CLM', $year);
    db_insert('damage_claims', [
        'claim_number'           => $clmNum,
        'equipment_unit_id'      => $unitId1,
        'lease_id'               => $l2Id,
        'customer_id'            => 3,
        'description'            => 'Forklift impact on driver-side rear panel while at customer warehouse. Approx 30cm dent, no structural damage.',
        'severity'               => 'minor',
        'status'                 => 'resolved',
        'estimated_repair_cost'  => '1200.00',
        'actual_repair_cost'     => '1050.00',
        'customer_liable_amount' => '1050.00',
        'insurance_claim_amount' => '0.00',
    ]);
    echo "  ✓ 1 damage claim\n";
}); // end Unit 1

echo "\n";

// ══════════════════════════════════════════════════════════════════════════════
// UNIT 2 — T5302  53' Reefer (2021)
// Acquired 16 months ago, cash purchase, 2 completed leases — HIGH REVENUE
// ══════════════════════════════════════════════════════════════════════════════
$unitId2 = 2; // T5302

if (!isset($seeded['SEED-REEFER-001'])) db_transaction(function () use ($unitId2, $mo, $moLast, $year, $today, $mintInvoiceNumber) {

    $acqDate2 = $mo(16, '01');
    $assetId2  = db_insert('acc_fixed_assets', [
        'asset_number'               => 'SEED-REEFER-001',
        'name'                       => '53\' Reefer Trailer — 2021 Thermo King',
        'asset_class'                => 'fleet_equipment',
        'acquisition_date'           => $acqDate2,
        'depreciation_start_date'    => $acqDate2,
        'acquisition_cost'           => '112000.00',
        'purchase_tax_gst'           => '5600.00',
        'purchase_tax_pst'           => '7840.00',
        'delivery_cost'              => '1800.00',
        'setup_cost'                 => '650.00',
        'depreciable_cost'           => '112000.00',
        'salvage_value'              => '15000.00',
        'net_book_value'             => '96266.67',
        'accumulated_depreciation'   => '15733.33',
        'depreciation_method'        => 'straight_line',
        'useful_life_years'          => '12',
        'is_financed'                => 0,
        'monthly_insurance_cost'     => '620.00',
        'monthly_licensing_cost'     => '210.00',
        'monthly_registration_cost'  => '28.00',
        'equipment_unit_id'          => $unitId2,
        'asset_account_id'           => GL_FLEET_COST,
        'accum_depr_account_id'      => GL_FLEET_DEPR,
        'depr_expense_account_id'    => GL_DEPR_EXP,
        'status'                     => 'active',
        'last_depreciation_date'     => $mo(1, '01'),
    ]);
    echo "  ✓ Fixed asset SEED-REEFER-001 (id={$assetId2})\n";

    // ── Lease 1: Delta Port Drayage (months 16–7, completed) ─────────────────
    $l1ContractNum = generate_id('LSE', $year);
    $l1Id = db_insert('leases', [
        'contract_number'    => $l1ContractNum,
        'equipment_unit_id'  => $unitId2,
        'customer_id'        => 6, // Delta Port Drayage
        'status'             => 'completed',
        'start_date'         => $mo(16, '01'),
        'end_date'           => $moLast(7),  // last day of month (avoids Feb/Nov/etc overflow)
        'monthly_rate'       => '4200.00',
        'classification'     => 'operating',
        'currency'           => 'CAD',
    ]);
    for ($m = 16; $m >= 8; $m--) {
        $invNum = $mintInvoiceNumber();
        // Add mileage surcharge occasionally
        $base   = '4200.00';
        $extras = ($m % 3 === 0) ? '380.00' : '0.00'; // mileage overage every 3rd month
        $sub    = bcadd($base, $extras, 2);
        $gst    = bcmul($sub, '0.05', 2);
        $total  = bcadd($sub, $gst, 2);
        $invId  = db_insert('invoices', [
            'invoice_number'       => $invNum,
            'lease_id'             => $l1Id,
            'customer_id'          => 6,
            'status'               => 'paid',
            'invoice_date'         => $mo($m - 1, '05'),
            'due_date'             => $mo($m - 1, '20'),
            'billing_period_start' => $mo($m, '01'),
            'billing_period_end'   => $moLast($m),
            'billing_period_days'  => 30,
            'billing_type'         => 'full_month',
            'subtotal'             => $sub,
            'subtotal_after_discount' => $sub,
            'tax_gst_amount'       => $gst,
            'tax_pst_amount'       => '0.00',
            'total_amount'         => $total,
            'balance_due'          => '0.00',
            'currency'             => 'CAD',
        ]);
        db_insert('invoice_line_items', [
            'invoice_id' => $invId, 'item_type' => 'base_rental',
            'description' => 'Monthly reefer trailer rental', 'quantity' => '1.0000',
            'unit_price' => $base, 'amount' => $base, 'is_credit' => 0,
        ]);
        if (bccomp($extras, '0', 2) > 0) {
            db_insert('invoice_line_items', [
                'invoice_id' => $invId, 'item_type' => 'mileage_precharge',
                'description' => 'Excess mileage charge', 'quantity' => '760.0000',
                'unit_price' => '0.50', 'amount' => $extras, 'is_credit' => 0,
            ]);
        }
    }
    echo "  ✓ 9 invoices for Reefer Lease 1\n";

    // ── Lease 2: Sumas Mountain Hauling (months 6–0, active) ─────────────────
    $l2ContractNum = generate_id('LSE', $year);
    $l2Id = db_insert('leases', [
        'contract_number'    => $l2ContractNum,
        'equipment_unit_id'  => $unitId2,
        'customer_id'        => 5, // Sumas Mountain Hauling
        'status'             => 'active',
        'start_date'         => $mo(6, '15'),
        'end_date'           => null,
        'monthly_rate'       => '4800.00',
        'classification'     => 'operating',
        'currency'           => 'CAD',
    ]);
    for ($m = 6; $m >= 1; $m--) {
        $invNum = $mintInvoiceNumber();
        $base   = '4800.00';
        $gst    = bcmul($base, '0.05', 2);
        $total  = bcadd($base, $gst, 2);
        $invId  = db_insert('invoices', [
            'invoice_number'       => $invNum,
            'lease_id'             => $l2Id,
            'customer_id'          => 5,
            'status'               => ($m === 1) ? 'sent' : 'paid',
            'invoice_date'         => $mo($m, '15'),
            'due_date'             => $mo($m - 1, '01'),
            'billing_period_start' => $mo($m, '15'),
            'billing_period_end'   => $moLast($m - 1),
            'billing_period_days'  => 30,
            'billing_type'         => 'full_month',
            'subtotal'             => $base,
            'subtotal_after_discount' => $base,
            'tax_gst_amount'       => $gst,
            'tax_pst_amount'       => '0.00',
            'total_amount'         => $total,
            'balance_due'          => ($m === 1) ? $total : '0.00',
            'currency'             => 'CAD',
        ]);
        db_insert('invoice_line_items', [
            'invoice_id' => $invId, 'item_type' => 'base_rental',
            'description' => 'Monthly reefer trailer rental', 'quantity' => '1.0000',
            'unit_price' => $base, 'amount' => $base, 'is_credit' => 0,
        ]);
    }
    echo "  ✓ 6 invoices for Reefer Lease 2 (active)\n";

    db_execute("UPDATE equipment_units SET status='on_lease' WHERE id=?", [$unitId2]);

    // ── Maintenance work orders ───────────────────────────────────────────────
    $wo1Num = generate_id('WO', $year);
    db_insert('maintenance_work_orders', [
        'work_order_number'  => $wo1Num,
        'equipment_unit_id'  => $unitId2,
        'work_type'          => 'scheduled_service',
        'title'              => 'Thermo King reefer unit PM service',
        'description'        => '2,000-hour PM — oil/filter change, belt inspection, coolant flush, calibration check',
        'status'             => 'completed',
        'requested_date'     => $mo(12, '05'),
        'completed_date'     => $mo(12, '06'),
        'labor_cost'         => '540.00',
        'parts_cost'         => '310.00',
        'total_cost'         => '850.00',
    ]);
    $wo2Num = generate_id('WO', $year);
    db_insert('maintenance_work_orders', [
        'work_order_number'  => $wo2Num,
        'equipment_unit_id'  => $unitId2,
        'work_type'          => 'repair',
        'title'              => 'Reefer compressor belt replacement',
        'description'        => 'Main compressor drive belt snapped in field. Customer called for breakdown service. Belt replaced on-site.',
        'status'             => 'completed',
        'requested_date'     => $mo(9, '14'),
        'completed_date'     => $mo(9, '14'),
        'labor_cost'         => '380.00',
        'parts_cost'         => '185.00',
        'total_cost'         => '565.00',
    ]);
    $wo3Num = generate_id('WO', $year);
    db_insert('maintenance_work_orders', [
        'work_order_number'  => $wo3Num,
        'equipment_unit_id'  => $unitId2,
        'work_type'          => 'tire',
        'title'              => 'Replace 6 trailer tires',
        'description'        => 'Replaced 6 worn reefer tires — 295/75R22.5. All balanced and torqued to spec.',
        'status'             => 'completed',
        'requested_date'     => $mo(4, '10'),
        'completed_date'     => $mo(4, '12'),
        'labor_cost'         => '420.00',
        'parts_cost'         => '2940.00',
        'total_cost'         => '3360.00',
    ]);
    echo "  ✓ 3 maintenance work orders\n";

    // ── 2 damage claims ───────────────────────────────────────────────────────
    $clm1Num = generate_id('CLM', $year);
    db_insert('damage_claims', [
        'claim_number'           => $clm1Num,
        'equipment_unit_id'      => $unitId2,
        'lease_id'               => $l1Id,
        'customer_id'            => 6,
        'description'            => 'Customer reversed into loading dock causing cracked tail light housing and bent rear frame member on driver side.',
        'severity'               => 'moderate',
        'status'                 => 'resolved',
        'estimated_repair_cost'  => '2800.00',
        'actual_repair_cost'     => '3150.00',
        'customer_liable_amount' => '3150.00',
        'insurance_claim_amount' => '0.00',
    ]);
    $clm2Num = generate_id('CLM', $year);
    db_insert('damage_claims', [
        'claim_number'           => $clm2Num,
        'equipment_unit_id'      => $unitId2,
        'lease_id'               => $l2Id,
        'customer_id'            => 5,
        'description'            => 'Reefer roof panel punctured by overhead beam at low-clearance warehouse. Approx 15cm hole, refrigeration compromised.',
        'severity'               => 'major',
        'status'                 => 'repair_ordered',
        'estimated_repair_cost'  => '6500.00',
        'actual_repair_cost'     => null,
        'customer_liable_amount' => '6500.00',
        'insurance_claim_amount' => '0.00',
    ]);
    echo "  ✓ 2 damage claims\n";
}); // end Unit 2

echo "\n";

// ══════════════════════════════════════════════════════════════════════════════
// UNIT 3 — T5303  53' Flatbed (2023)
// Acquired 9 months ago, financed, 1 active lease — NEWER, BUILDING HISTORY
// ══════════════════════════════════════════════════════════════════════════════
$unitId3 = 3; // T5303

if (!isset($seeded['SEED-FLATBED-001'])) db_transaction(function () use ($unitId3, $mo, $moLast, $year, $today, $mintInvoiceNumber) {

    $acqDate3 = $mo(9, '01');
    $assetId3  = db_insert('acc_fixed_assets', [
        'asset_number'               => 'SEED-FLATBED-001',
        'name'                       => '53\' Flatbed Trailer — 2023',
        'asset_class'                => 'fleet_equipment',
        'acquisition_date'           => $acqDate3,
        'depreciation_start_date'    => $acqDate3,
        'acquisition_cost'           => '58000.00',
        'purchase_tax_gst'           => '2900.00',
        'purchase_tax_pst'           => '4060.00',
        'delivery_cost'              => '950.00',
        'setup_cost'                 => '200.00',
        'depreciable_cost'           => '58000.00',
        'salvage_value'              => '6000.00',
        'net_book_value'             => '53650.00',
        'accumulated_depreciation'   => '4350.00',
        'depreciation_method'        => 'straight_line',
        'useful_life_years'          => '10',
        'is_financed'                => 1,
        'financing_monthly_payment'  => '1120.00',
        'financing_interest_rate'    => '7.25',
        'financing_remaining_months' => 51,
        'monthly_insurance_cost'     => '310.00',
        'monthly_licensing_cost'     => '155.00',
        'monthly_registration_cost'  => '18.00',
        'equipment_unit_id'          => $unitId3,
        'asset_account_id'           => GL_FLEET_COST,
        'accum_depr_account_id'      => GL_FLEET_DEPR,
        'depr_expense_account_id'    => GL_DEPR_EXP,
        'status'                     => 'active',
        'last_depreciation_date'     => $mo(1, '01'),
    ]);
    echo "  ✓ Fixed asset SEED-FLATBED-001 (id={$assetId3})\n";

    // ── Lease 1: Sumas Mountain Hauling (months 9–0, long-term active) ────────
    $l1ContractNum = generate_id('LSE', $year);
    $l1Id = db_insert('leases', [
        'contract_number'    => $l1ContractNum,
        'equipment_unit_id'  => $unitId3,
        'customer_id'        => 5, // Sumas Mountain Hauling
        'status'             => 'active',
        'start_date'         => $mo(9, '01'),
        'end_date'           => null,
        'monthly_rate'       => '2650.00',
        'classification'     => 'operating',
        'currency'           => 'CAD',
    ]);
    for ($m = 9; $m >= 1; $m--) {
        $invNum = $mintInvoiceNumber();
        $base   = '2650.00';
        $gst    = bcmul($base, '0.05', 2);
        $total  = bcadd($base, $gst, 2);
        $invId  = db_insert('invoices', [
            'invoice_number'       => $invNum,
            'lease_id'             => $l1Id,
            'customer_id'          => 5,
            'status'               => ($m === 1) ? 'sent' : 'paid',
            'invoice_date'         => $mo($m, '01'),
            'due_date'             => $mo($m - 1, '15'),
            'billing_period_start' => $mo($m, '01'),
            'billing_period_end'   => $moLast($m),
            'billing_period_days'  => 30,
            'billing_type'         => 'full_month',
            'subtotal'             => $base,
            'subtotal_after_discount' => $base,
            'tax_gst_amount'       => $gst,
            'tax_pst_amount'       => '0.00',
            'total_amount'         => $total,
            'balance_due'          => ($m === 1) ? $total : '0.00',
            'currency'             => 'CAD',
        ]);
        db_insert('invoice_line_items', [
            'invoice_id' => $invId, 'item_type' => 'base_rental',
            'description' => 'Monthly flatbed trailer rental', 'quantity' => '1.0000',
            'unit_price' => $base, 'amount' => $base, 'is_credit' => 0,
        ]);
    }
    echo "  ✓ 9 invoices for Flatbed Lease 1\n";

    db_execute("UPDATE equipment_units SET status='on_lease' WHERE id=?", [$unitId3]);

    // ── 2 maintenance work orders ─────────────────────────────────────────────
    $wo1Num = generate_id('WO', $year);
    db_insert('maintenance_work_orders', [
        'work_order_number'  => $wo1Num,
        'equipment_unit_id'  => $unitId3,
        'work_type'          => 'inspection',
        'title'              => 'Initial delivery inspection & outfitting',
        'description'        => 'Delivery inspection, stake pockets checked, winch rails inspected, headboard torqued, logistic chains installed.',
        'status'             => 'completed',
        'requested_date'     => $mo(9, '01'),
        'completed_date'     => $mo(9, '02'),
        'labor_cost'         => '180.00',
        'parts_cost'         => '320.00',
        'total_cost'         => '500.00',
    ]);
    $wo2Num = generate_id('WO', $year);
    db_insert('maintenance_work_orders', [
        'work_order_number'  => $wo2Num,
        'equipment_unit_id'  => $unitId3,
        'work_type'          => 'repair',
        'title'              => 'Tandem slider repair',
        'description'        => 'Tandem axle slider pin seized. Penetrating oil, heat applied, pin removed and replaced. All rollers lubed.',
        'status'             => 'completed',
        'requested_date'     => $mo(4, '18'),
        'completed_date'     => $mo(4, '19'),
        'labor_cost'         => '240.00',
        'parts_cost'         => '85.00',
        'total_cost'         => '325.00',
    ]);
    echo "  ✓ 2 maintenance work orders\n";
}); // end Unit 3

echo "\n";

// ── Summary ───────────────────────────────────────────────────────────────────
$assetCount = db_row("SELECT COUNT(*) AS c FROM acc_fixed_assets WHERE asset_number LIKE 'SEED-%'")['c'];
$leaseCount = db_row("SELECT COUNT(*) AS c FROM leases")['c'];
$invCount   = db_row("SELECT COUNT(*) AS c FROM invoices")['c'];
$woCount    = db_row("SELECT COUNT(*) AS c FROM maintenance_work_orders")['c'];
$dmgCount   = db_row("SELECT COUNT(*) AS c FROM damage_claims")['c'];

echo "═══════════════════════════════════════════════════\n";
echo "Seed complete!\n";
echo "  Fixed assets :  {$assetCount}\n";
echo "  Leases       :  {$leaseCount}\n";
echo "  Invoices     :  {$invCount}\n";
echo "  Work orders  :  {$woCount}\n";
echo "  Damage claims:  {$dmgCount}\n";
echo "═══════════════════════════════════════════════════\n";
echo "\nView payoff analysis:\n";
echo "  T5301 (Dry Van):  " . base_url('equipment/payoff?id=1') . "\n";
echo "  T5302 (Reefer):   " . base_url('equipment/payoff?id=2') . "\n";
echo "  T5303 (Flatbed):  " . base_url('equipment/payoff?id=3') . "\n";
