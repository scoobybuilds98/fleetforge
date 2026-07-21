<?php
declare(strict_types=1);

/**
 * FleetForge — Revenue History Top-Up (S-DEMO-MULTIYEAR)
 *
 * @file        scripts/seed_revenue_topup.php
 * @description ADDITIVE pass over the presentation dataset: layers extra
 *              COMPLETED leases (2023 → mid-2026) with engine-generated,
 *              fully-paid invoices so fleet utilization/revenue reaches a
 *              realistic level relative to the capitalized fleet. Without
 *              this, straight-line depreciation on the ~$4.4M register
 *              exceeds revenue in every year and the Statements page shows
 *              a chronically loss-making company — wrong story for a demo.
 *              Target arc: 2023 startup loss → 2024 breakeven → 2025+ profit.
 *
 *              Run BEFORE scripts/demo_accounting.php (which truncates and
 *              reposts every invoice/payment JE, so ordering is: this script,
 *              then demo_accounting.php, then counter recompute).
 *
 * @usage       php scripts/seed_revenue_topup.php
 * @session     S-DEMO-MULTIYEAR
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/Billing/InvoiceGenerator.php';

if (!defined('APP_ENV') || APP_ENV === 'production') {
    fwrite(STDERR, "REFUSED: APP_ENV is production/undefined.\n");
    exit(1);
}

const SEED_USER = 1;
function money(float $v): string { return number_format($v, 2, '.', ''); }

// ── Customers (CAD only — keeps the JE math simple) with frozen tax rates ────
$customers = db_select(
    "SELECT id, company_name, contact_name, currency, mileage_unit, tax_rate_id,
            gst_exempt, pst_exempt, tax_exempt, payment_terms
       FROM customers WHERE deleted_at IS NULL AND currency = 'CAD' AND status = 'active'
      ORDER BY id", []
);
if (!$customers) { fwrite(STDERR, "no CAD customers\n"); exit(1); }

// ── Units by category, with their template rates ─────────────────────────────
$unitRows = db_select(
    "SELECT eu.id, eu.unit_number, eu.vin, eu.year, t.id tpl_id, t.category, t.name tname, eb.label AS brand, t.model,
            t.default_daily_rate d, t.default_weekly_rate w, t.default_monthly_rate m
       FROM equipment_units eu JOIN equipment_templates t ON t.id = eu.template_id
       LEFT JOIN equipment_brands eb ON eb.id = eu.brand_id
      WHERE eu.deleted_at IS NULL ORDER BY eu.id", []
);
$byCat = [];
foreach ($unitRows as $u) $byCat[$u['category']][] = $u;

/** True when the unit already has a lease overlapping [start, end] — avoids double-booking. */
function unitBusy(int $unitId, string $start, string $end): bool {
    $r = db_row(
        "SELECT COUNT(*) n FROM leases
          WHERE equipment_unit_id = ? AND deleted_at IS NULL
            AND NOT (end_date < ? OR start_date > ?)",
        [$unitId, $start, $end]
    );
    return (int) $r['n'] > 0;
}

function freezeTaxRow(array $c): array {
    if (!$c['tax_rate_id']) return ['gst' => '0.0000', 'pst' => '0.0000', 'hst' => '0.0000'];
    $r = db_row("SELECT gst_rate, pst_rate, hst_rate FROM tax_rates WHERE id = ?", [(int) $c['tax_rate_id']]);
    if (!$r) return ['gst' => '0.0000', 'pst' => '0.0000', 'hst' => '0.0000'];
    $taxEx = (int) $c['tax_exempt']; $gstEx = (int) $c['gst_exempt']; $pstEx = (int) $c['pst_exempt'];
    return [
        'gst' => ($taxEx || $gstEx) ? '0.0000' : $r['gst_rate'],
        'pst' => ($taxEx || $pstEx) ? '0.0000' : $r['pst_rate'],
        'hst' => $taxEx ? '0.0000' : $r['hst_rate'],
    ];
}

/** Calendar-month billing periods (marketing-seeder pattern), completed lease. */
function periodsFor(string $start, string $end): array {
    $periods = [];
    $cursor = new DateTime($start); $endDt = new DateTime($end);
    while ($cursor <= $endDt && count($periods) < 40) {
        $monthEnd = (clone $cursor)->modify('last day of this month');
        $pEnd = $monthEnd <= $endDt ? $monthEnd : $endDt;
        $periods[] = [$cursor->format('Y-m-d'), $pEnd->format('Y-m-d')];
        $cursor = (clone $pEnd)->modify('+1 day');
    }
    $n = count($periods);
    foreach ($periods as $i => &$p) {
        if ($n === 1)            $p[2] = 'single_period';
        elseif ($i === 0)        $p[2] = (new DateTime($p[0]))->format('d') === '01' ? 'full_month' : 'partial_start';
        elseif ($i === $n - 1)   $p[2] = 'partial_end';
        else                     $p[2] = 'full_month';
    }
    unset($p);
    return $periods;
}

// ── Utilization plan: [year, category, count, avg_len_days] ──────────────────
// Tractor-heavy (highest monthly rate) so revenue scales past fixed depreciation.
$plan = [
    [2023, 'other', 3, 200], [2023, 'reefer', 2, 190], [2023, 'dry_van', 1, 170],
    [2024, 'other', 4, 230], [2024, 'reefer', 3, 220], [2024, 'dry_van', 2, 200], [2024, 'flatbed', 1, 180],
    [2025, 'other', 5, 260], [2025, 'reefer', 4, 240], [2025, 'dry_van', 2, 220], [2025, 'step_deck', 1, 200],
    // "2026" cohort: starts late 2025, returns through H1-2026 → current-year revenue.
    [2026, 'other', 6, 200], [2026, 'reefer', 4, 190], [2026, 'dry_van', 3, 180], [2026, 'flatbed', 1, 170],
];

$gen = new \FleetForge\Billing\InvoiceGenerator();
$today = date('Y-m-d');
$paySeq = 5000;   // high offset — never collides with marketing-seeder PMT numbers
$leaseCount = 0; $invCount = 0; $revenue = 0.0;

foreach ($plan as [$year, $cat, $count, $avgLen]) {
    $pool = $byCat[$cat] ?? [];
    for ($k = 0; $k < $count; $k++) {
        // Start somewhere sensible for the cohort year; the 2026 cohort starts late 2025.
        if ($year === 2026) {
            $start = date('Y-m-d', strtotime('2025-09-01 +' . random_int(0, 150) . ' days'));
        } else {
            $start = sprintf('%d-%02d-%02d', $year, random_int(1, 5), random_int(1, 28));
        }
        $len = $avgLen + random_int(-30, 40);
        $end = date('Y-m-d', strtotime("{$start} +{$len} days"));
        if ($end >= $today) $end = date('Y-m-d', strtotime($today . ' -' . random_int(5, 20) . ' days'));
        if ($end <= $start) continue;

        // First non-double-booked unit of the category for this window.
        $unit = null;
        foreach ($pool as $cand) {
            if (!unitBusy((int) $cand['id'], $start, $end)) { $unit = $cand; break; }
        }
        if (!$unit) continue;   // category fully utilized in that window — fine, skip

        $c = $customers[($leaseCount + $k) % count($customers)];
        $tax = freezeTaxRow($c);

        $leaseId = db_insert('leases', [
            'contract_number'        => 'CN-DEMO-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)) . '-' . substr($start, 0, 4),
            'customer_id'            => (int) $c['id'],
            'equipment_unit_id'      => (int) $unit['id'],
            'customer_name_snapshot' => $c['contact_name'],
            'company_name_snapshot'  => $c['company_name'],
            'unit_number_snapshot'   => $unit['unit_number'],
            'template_name_snapshot' => $unit['tname'],
            'equipment_snapshot_json'=> json_encode([
                'vin' => $unit['vin'], 'year' => (int) $unit['year'], 'category' => $cat,
                'unit_number' => $unit['unit_number'], 'template_name' => $unit['tname'],
                'brand' => $unit['brand'], 'model' => $unit['model'],
            ]),
            'status'                 => 'completed',
            'start_date'             => $start,
            'end_date'               => $end,
            'actual_return_date'     => $end,
            'daily_rate'             => $unit['d'],
            'weekly_rate'            => $unit['w'],
            'monthly_rate'           => $unit['m'],
            'mileage_rate'           => '0.0000',
            'estimated_mileage'      => '0.00',
            'currency'               => 'CAD',
            'mileage_unit'           => $c['mileage_unit'],
            'billing_cycle'          => 'monthly',
            'gst_exempt'             => (int) $c['gst_exempt'],
            'pst_exempt'             => (int) $c['pst_exempt'],
            'tax_exempt'             => (int) $c['tax_exempt'],
            'tax_rate_gst'           => $tax['gst'],
            'tax_rate_pst'           => $tax['pst'],
            'tax_rate_hst'           => $tax['hst'],
            'discount_type'          => 'none',
            'discount_value'         => '0.00',
            'mileage_tracking_mode'  => 'off',
            'closed_at'              => $end . ' 15:00:00',
            'closed_by_user_id'      => SEED_USER,
            'notes'                  => 'Historical utilization — ' . $c['company_name'],
            'internal_notes'         => '[FFDEMO-MKT] revenue top-up lease',
            'created_by'             => SEED_USER,
            'updated_by'             => SEED_USER,
        ]);
        $created = date('Y-m-d H:i:s', strtotime($start . ' -' . random_int(2, 10) . ' days 10:00'));
        db_execute("UPDATE leases SET created_at = ?, updated_at = ? WHERE id = ?", [$created, $created, $leaseId]);
        $leaseCount++;

        $terms = (int) (preg_match('/(\d+)/', (string) $c['payment_terms'], $mm) ? $mm[1] : 30);
        foreach (periodsFor($start, $end) as $idx => [$pStart, $pEnd, $billType]) {
            try {
                $res = $gen->createFromLease([
                    'lease_id'          => $leaseId,
                    'period_start'      => $pStart,
                    'period_end'        => $pEnd,
                    'billing_type'      => $billType,
                    'invoice_type'      => 'regular',
                    'generation_source' => 'manual',
                    'auto_generated'    => 1,
                    'created_by'        => SEED_USER,
                    'internal_notes'    => '[FFDEMO-MKT] revenue top-up invoice',
                ]);
            } catch (\Throwable $e) {
                echo "  ! invoice {$leaseId} {$pStart}: {$e->getMessage()}\n";
                continue;
            }
            $invId = (int) $res['invoice_id'];
            $total = (float) $res['total_amount'];
            $dueDate = date('Y-m-d', strtotime("{$pEnd} +{$terms} days"));
            $payDate = date('Y-m-d', strtotime($pEnd . ' +' . random_int(6, $terms + 5) . ' days'));
            if ($payDate > $today) $payDate = $today;

            db_execute(
                "UPDATE invoices SET invoice_date = ?, due_date = ?, created_at = ?,
                        status = 'paid', amount_paid = ?, balance_due = 0, paid_date = ?
                  WHERE id = ?",
                [$pEnd, $dueDate, $pEnd . ' 12:00:00', money($total), $payDate, $invId]
            );
            if ($total > 0) {
                $paySeq++;
                $pid = db_insert('payments', [
                    'payment_number'       => 'PMT-DEMO-' . substr($payDate, 0, 4) . '-' . str_pad((string) $paySeq, 5, '0', STR_PAD_LEFT),
                    'customer_id'          => (int) $c['id'],
                    'amount'               => money($total),
                    'currency'             => 'CAD',
                    'exchange_rate_to_cad' => '1.0000',
                    'amount_in_cad'        => money($total),
                    'payment_method'       => ['ach','wire','e_transfer','check'][random_int(0, 3)],
                    'origin'               => 'ff_native',
                    'reference_number'     => 'REF-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
                    'payment_date'         => $payDate,
                    'status'               => 'cleared',
                    'notes'                => 'Payment in full',
                    'internal_notes'       => '[FFDEMO-MKT] revenue top-up payment',
                    'recorded_by'          => SEED_USER,
                ]);
                db_insert('payment_allocations', [
                    'payment_id' => $pid, 'invoice_id' => $invId, 'amount' => money($total),
                    'currency' => 'CAD', 'allocation_type' => 'manual', 'allocated_by' => SEED_USER,
                ]);
                db_execute("UPDATE payments SET created_at = ? WHERE id = ?", [$payDate . ' 14:10:00', $pid]);
            }
            $invCount++;
            $revenue += $total;
        }
    }
}

printf("\n+ %d leases, %d invoices, \$%s billed (all paid)\n", $leaseCount, $invCount, number_format($revenue, 2));
echo "Now re-run: php scripts/demo_accounting.php (reposts all JEs incl. these)\n";
