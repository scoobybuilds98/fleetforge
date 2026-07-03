<?php
declare(strict_types=1);

/**
 * FleetForge — Presentation Demo Extras (S-DEMO-MULTIYEAR)
 *
 * @file        scripts/seed_presentation_extras.php
 * @description Fills the modules the marketing seeder + payoff showcase +
 *              demo_accounting.php do NOT cover, so the presentation dataset
 *              reads as a company operating for multiple years:
 *
 *              --phase=assets  (run BEFORE scripts/demo_accounting.php)
 *                • acc_fixed_assets for EVERY equipment unit that lacks one
 *                  (acquisition dates derived from unit model year, financed
 *                  mix, CCA classes, per-category cost/insurance profiles).
 *                  demo_accounting.php's multi-year depreciation runs then
 *                  pick these up and reconcile accum-depr/NBV.
 *
 *              --phase=ops     (run AFTER scripts/demo_accounting.php — needs
 *                               the vendors it creates)
 *                • maintenance work orders (multi-year, all work types)
 *                • damage claims + inspections (pre/post-lease + periodic)
 *                • reservations (confirmed/pending/completed/cancelled)
 *                • collections story (notes + promise-to-pay for the
 *                  credit-hold customer's aged AR)
 *                • customer security deposits (held/applied/refunded)
 *                • 2026 operating budget derived from posted GL actuals
 *                • recurring journal entry templates
 *                • realism backdating: customers/units/templates/payments
 *                  created_at pushed back to their operational dates
 *                • equipment_templates.category_id backfill from slugs
 *
 * @usage       php scripts/seed_presentation_extras.php --phase=assets
 *              php scripts/seed_presentation_extras.php --phase=ops
 *
 * @session     S-DEMO-MULTIYEAR
 * @decision    One-shot post-wipe seeder (no purge tags) — the whole DB is a
 *              disposable demo build; demo_wipe.php is the reset lever.
 */

require_once __DIR__ . '/../config/app.php';

// Fail-closed: local dev only (mirrors demo_wipe Layer-1).
if (!defined('APP_ENV') || APP_ENV === 'production') {
    fwrite(STDERR, "REFUSED: APP_ENV is production/undefined.\n");
    exit(1);
}

$phase = null;
foreach ($argv as $a) { if (str_starts_with($a, '--phase=')) $phase = substr($a, 8); }
if (!in_array($phase, ['assets', 'ops'], true)) {
    fwrite(STDERR, "Usage: php scripts/seed_presentation_extras.php --phase=assets|ops\n");
    exit(1);
}

const SEED_USER = 1;

/** Account id by code (throws if the chart is missing the code). */
function acctId(string $code): int {
    $r = db_row("SELECT id FROM acc_accounts WHERE code = ?", [$code]);
    if (!$r) throw new RuntimeException("Missing account code {$code}");
    return (int) $r['id'];
}
/** First account id matching a name LIKE, or null. */
function acctLike(string $like, ?string $type = null): ?int {
    $sql = "SELECT id FROM acc_accounts WHERE name LIKE ?" . ($type ? " AND account_type = ?" : "") . " ORDER BY code LIMIT 1";
    $r = db_row($sql, $type ? [$like, $type] : [$like]);
    return $r ? (int) $r['id'] : null;
}
function money(float $v): string { return number_format($v, 2, '.', ''); }
/** Deterministic-ish jitter from an id so re-reads of the script make sense. */
function jit(int $seed, int $lo, int $hi): int { return $lo + (($seed * 2654435761) >> 8) % max(1, $hi - $lo + 1); }

// ════════════════════════════════════════════════════════════════════════════
// PHASE A — FIXED ASSETS FOR THE WHOLE FLEET
// ════════════════════════════════════════════════════════════════════════════
if ($phase === 'assets') {
    echo "=== Phase A — fleet-wide fixed assets ===\n";

    // Per-category acquisition profile: [cost_lo, cost_hi, life_yrs, ins_lo, ins_hi, cca_id, cra_class, cca_rate]
    // CCA: highway tractors = class 16 (40%), trailers = class 10 (30%).
    $profiles = [
        'other'     => [158000, 212000,  8, 210, 320, 5, '16', 0.40],
        'reefer'    => [ 92000, 128000, 12,  95, 150, 2, '10', 0.30],
        'dry_van'   => [ 52000,  71000, 15,  55,  90, 2, '10', 0.30],
        'flatbed'   => [ 58000,  76000, 15,  55,  90, 2, '10', 0.30],
        'step_deck' => [ 64000,  82000, 15,  60,  95, 2, '10', 0.30],
        'chassis'   => [ 24000,  36000, 20,  35,  60, 2, '10', 0.30],
    ];

    $glCost = acctId('1210'); $glAccum = acctId('1220'); $glDepr = acctId('5010');

    $units = db_select(
        "SELECT eu.id, eu.unit_number, eu.vin, eu.year, eu.yard_location, t.category, t.brand, t.model
           FROM equipment_units eu
           JOIN equipment_templates t ON t.id = eu.template_id
          WHERE eu.deleted_at IS NULL
            AND NOT EXISTS (SELECT 1 FROM acc_fixed_assets fa WHERE fa.equipment_unit_id = eu.id)
          ORDER BY eu.id", []
    );

    $n = 0;
    foreach ($units as $u) {
        $p = $profiles[$u['category']] ?? $profiles['dry_van'];
        [$lo, $hi, $life, $insLo, $insHi, $ccaId, $craClass, $ccaRate] = $p;
        $uid  = (int) $u['id'];
        $cost = (float) jit($uid, $lo, $hi);
        // Acquired in its model year (units run 2019-2025) → multi-year register.
        $acqYear  = max(2019, min((int) $u['year'], (int) date('Y')));
        $acqMonth = jit($uid + 3, 1, 12);
        if ($acqYear === (int) date('Y')) $acqMonth = min($acqMonth, (int) date('n'));
        $acq   = sprintf('%d-%02d-%02d', $acqYear, $acqMonth, jit($uid + 7, 2, 26));
        $deprStart = date('Y-m-01', strtotime($acq . ' +1 month'));

        $salvage = round($cost * 0.15, 2);
        $depreciable = $cost - $salvage;
        $monthly = $depreciable / ($life * 12);
        // Opening accumulated depreciation up to 2022-12-31; the patched
        // demo_accounting depreciation runs take over from 2023-01.
        $preMonths = max(0, (int) ((new DateTime('2023-01-01'))->diff(new DateTime($deprStart))->days / 30.44));
        if ($deprStart >= '2023-01-01') $preMonths = 0;
        $openAccum = min($depreciable, round($monthly * $preMonths, 2));

        $financed = ($uid % 5) < 2;   // ~40% of the fleet carries financing
        $termMonths = 60;
        $monthsHeld = (int) ((new DateTime($acq))->diff(new DateTime())->days / 30.44);
        $remaining  = max(0, $termMonths - $monthsHeld);
        // Rough level-payment on 90% LTV at ~7.9% — demo-grade, not amortization-exact.
        $finPayment = round(($cost * 0.9) * 0.0203, 2);

        db_insert('acc_fixed_assets', [
            'asset_number'            => 'FA-' . $u['unit_number'],
            'name'                    => "{$u['year']} {$u['brand']} {$u['model']} — {$u['unit_number']}",
            'description'             => 'Fleet acquisition — ' . ucfirst(str_replace('_', ' ', $u['category'])),
            'asset_class'             => 'fleet_equipment',
            'cca_class_id'            => $ccaId,
            'cra_class'               => $craClass,
            'cra_cca_rate'            => number_format($ccaRate, 4, '.', ''),
            'equipment_unit_id'       => $uid,
            'acquisition_date'        => $acq,
            'available_for_use_date'  => $acq,
            'acquisition_cost'        => money($cost),
            'purchase_tax_gst'        => money(round($cost * 0.05, 2)),
            'purchase_tax_pst'        => money(round($cost * 0.07, 2)),
            'delivery_cost'           => money((float) jit($uid + 11, 400, 1400)),
            'setup_cost'              => money((float) jit($uid + 13, 150, 700)),
            'is_financed'             => $financed ? 1 : 0,
            'financing_monthly_payment'   => $financed && $remaining > 0 ? money($finPayment) : null,
            'financing_interest_rate'     => $financed && $remaining > 0 ? number_format(jit($uid + 17, 590, 890) / 100, 4, '.', '') : null,
            'financing_remaining_months'  => $financed && $remaining > 0 ? $remaining : null,
            'monthly_insurance_cost'      => money((float) jit($uid + 19, $insLo, $insHi)),
            'monthly_licensing_cost'      => money((float) jit($uid + 23, 18, 42)),
            'monthly_registration_cost'   => money((float) jit($uid + 29, 8, 22)),
            'depreciation_method'     => 'straight_line',
            'useful_life_years'       => number_format((float) $life, 2, '.', ''),
            'salvage_value'           => money($salvage),
            'depreciable_cost'        => money($depreciable),
            'accumulated_depreciation'=> money($openAccum),
            'net_book_value'          => money($cost - $openAccum),
            'depreciation_start_date' => $deprStart,
            'asset_account_id'        => $glCost,
            'accum_depr_account_id'   => $glAccum,
            'depr_expense_account_id' => $glDepr,
            'status'                  => 'active',
            'location'                => $u['yard_location'],
            'serial_number'           => $u['vin'],
            'notes'                   => 'Demo presentation asset',
            'created_by'              => SEED_USER,
        ]);
        $n++;
    }
    echo "  + {$n} fixed assets (fleet now fully capitalized)\n";
    exit(0);
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE B — OPERATIONS + REALISM LAYER (run after demo_accounting.php)
// ════════════════════════════════════════════════════════════════════════════
echo "=== Phase B — operations + realism layer ===\n";

$today = date('Y-m-d');

// Vendor ids by type (created by demo_accounting.php Step 3).
$vendors = db_select("SELECT id, name, vendor_type FROM vendors WHERE deleted_at IS NULL", []);
$vendorByType = [];
foreach ($vendors as $v) $vendorByType[$v['vendor_type']][] = (int) $v['id'];
$pickVendor = function (string $workType) use ($vendorByType): ?int {
    $type = match ($workType) {
        'tire'                          => 'parts',
        'inspection'                    => 'inspection',
        'breakdown'                     => 'towing',
        default                         => 'maintenance',
    };
    $pool = $vendorByType[$type] ?? $vendorByType['maintenance'] ?? [];
    return $pool ? $pool[array_rand($pool)] : null;
};

$units = db_select(
    "SELECT eu.id, eu.unit_number, eu.mileage, t.category
       FROM equipment_units eu JOIN equipment_templates t ON t.id = eu.template_id
      WHERE eu.deleted_at IS NULL ORDER BY eu.id", []
);
$leases = db_select(
    "SELECT l.id, l.customer_id, l.equipment_unit_id, l.status, l.start_date, l.end_date,
            l.actual_return_date, l.company_name_snapshot, l.unit_number_snapshot
       FROM leases l WHERE l.deleted_at IS NULL ORDER BY l.id", []
);

// ── B1: Maintenance work orders — multi-year, every work type/status ─────────
echo "[B1] Maintenance work orders…\n";
$workTypes = ['scheduled_service','repair','inspection','tire','electrical','body_damage','breakdown','other'];
$woTitles = [
    'scheduled_service' => ['A-service — oil, filters, grease', 'B-service — full chassis inspection + brake adjust', 'Annual service — bearings repack + brake reline'],
    'repair'            => ['Landing gear replacement', 'Roof panel reseal — water ingress', 'Air line + gladhand replacement', 'Door hinge + seal replacement'],
    'inspection'        => ['Annual CVI inspection', 'Insurance condition survey'],
    'tire'              => ['Replace 4 trailer tires — worn to spec', 'Tire rotation + alignment check', 'Emergency roadside tire replacement'],
    'electrical'        => ['ABS fault trace + sensor replacement', '7-way harness + marker light repair', 'Liftgate wiring repair'],
    'body_damage'       => ['Side skirt + mudflap bracket repair', 'Rear impact guard straightening', 'Dock rash panel repair'],
    'breakdown'         => ['Roadside breakdown — brake chamber failure', 'Reefer unit failure — emergency callout'],
    'other'             => ['Decal + unit-number refresh', 'Floor reseal + wash-out'],
];
$woSeqByYear = []; $woCount = 0; $woIdsByUnit = [];
for ($i = 0; $i < 46; $i++) {
    $u = $units[array_rand($units)];
    $daysAgo = random_int(4, 1200);
    $reqDate = date('Y-m-d', strtotime("-{$daysAgo} days"));
    $yr = (int) substr($reqDate, 0, 4);
    $woSeqByYear[$yr] = ($woSeqByYear[$yr] ?? 0) + 1;
    $wt = $workTypes[$i % count($workTypes)];
    $title = $woTitles[$wt][array_rand($woTitles[$wt])];
    // Anything older than a month is finished; recent ones show live statuses.
    if ($daysAgo > 35) {
        $status = random_int(0, 19) === 0 ? 'cancelled' : 'completed';
    } else {
        $status = ['open','in_progress','waiting_parts','completed'][random_int(0, 3)];
    }
    $labor = random_int(150, 1800) + 0.0;
    $parts = in_array($wt, ['tire','repair','body_damage','electrical'], true) ? random_int(200, 2600) + 0.0 : random_int(0, 400) + 0.0;
    $done  = $status === 'completed';
    $woId = db_insert('maintenance_work_orders', [
        'work_order_number' => sprintf('WO-%d-%04d', $yr, $woSeqByYear[$yr]),
        'equipment_unit_id' => (int) $u['id'],
        'vendor_id'         => $pickVendor($wt),
        'work_type'         => $wt,
        'priority'          => ['low','medium','medium','high','emergency'][random_int(0, 4)],
        'status'            => $status,
        'title'             => $title,
        'description'       => "{$title} — unit {$u['unit_number']}.",
        'mileage_at_service'=> $u['category'] === 'other' ? max(1000, (int) $u['mileage'] - $daysAgo * 250) : null,
        'requested_date'    => $reqDate,
        'scheduled_date'    => date('Y-m-d', strtotime($reqDate . ' +' . random_int(1, 6) . ' days')),
        'completed_date'    => $done ? date('Y-m-d', strtotime($reqDate . ' +' . random_int(2, 12) . ' days')) : null,
        'labor_cost'        => $done ? money($labor) : '0.00',
        'parts_cost'        => $done ? money($parts) : '0.00',
        'total_cost'        => $done ? money($labor + $parts) : '0.00',
        'resolution_notes'  => $done ? 'Work completed and unit returned to service.' : null,
        'created_by'        => SEED_USER,
        'assigned_to'       => SEED_USER,
        'completed_by'      => $done ? SEED_USER : null,
    ]);
    db_execute("UPDATE maintenance_work_orders SET created_at = ?, updated_at = ? WHERE id = ?",
        [$reqDate . ' 09:15:00', $reqDate . ' 09:15:00', $woId]);
    $woIdsByUnit[(int) $u['id']][] = $woId;
    $woCount++;
}
echo "  + {$woCount} work orders\n";

// ── B2: Damage claims — severity/status spread, tied to real leases ─────────
echo "[B2] Damage claims…\n";
$claimDefs = [
    // [severity, status, est, actual, liable, insurance, daysAgo, description, location]
    ['minor',    'resolved',      850,   790,   790,    null, 720, 'Scuffed side panel + torn mudflap at customer dock', 'Curbside rear'],
    ['moderate', 'resolved',     3200,  2940,  1500,    null, 540, 'Backing collision — rear frame + door damage',       'Rear doors'],
    ['major',    'resolved',    11800, 12450,  2500,   9950,  400, 'Highway debris strike — floor + crossmember damage', 'Undercarriage'],
    ['minor',    'resolved',      620,   580,   580,    null, 260, 'Marker lights + reflector tape damage',              'Roadside'],
    ['moderate', 'invoiced',     4100,  3875,  3875,    null,  90, 'Forklift puncture through side wall',                'Curbside wall'],
    ['moderate', 'repair_ordered',2900,  null,  null,    null,  30, 'Landing gear bent — dropped loaded',                 'Landing gear'],
    ['minor',    'assessed',      780,   null,   780,    null,  12, 'Door seal torn + hinge sprung',                      'Rear doors'],
    ['major',    'reported',     9500,   null,  null,    null,   4, 'Reefer unit impact damage at port terminal',          'Front bulkhead'],
];
$claimSeqByYear = []; $claimCount = 0;
$claimableLeases = array_values(array_filter($leases, fn($l) => in_array($l['status'], ['active','completed'], true)));
foreach ($claimDefs as $cd) {
    [$sev, $st, $est, $act, $liable, $ins, $daysAgo, $desc, $loc] = $cd;
    $L = $claimableLeases[array_rand($claimableLeases)];
    $repDate = date('Y-m-d', strtotime("-{$daysAgo} days"));
    // Claim date must fall inside the lease window to read as real.
    if ($repDate < $L['start_date']) $repDate = date('Y-m-d', strtotime($L['start_date'] . ' +' . random_int(10, 40) . ' days'));
    $yr = (int) substr($repDate, 0, 4);
    $claimSeqByYear[$yr] = ($claimSeqByYear[$yr] ?? 0) + 1;
    $cid = db_insert('damage_claims', [
        'claim_number'          => sprintf('CLM-%d-%03d', $yr, $claimSeqByYear[$yr]),
        'equipment_unit_id'     => (int) $L['equipment_unit_id'],
        'lease_id'              => (int) $L['id'],
        'customer_id'           => (int) $L['customer_id'],
        'customer_name'         => $L['company_name_snapshot'],
        'description'           => $desc . " — unit {$L['unit_number_snapshot']}.",
        'damage_location'       => $loc,
        'severity'              => $sev,
        'estimated_repair_cost' => $est !== null ? money((float) $est) : null,
        'actual_repair_cost'    => $act !== null ? money((float) $act) : null,
        'customer_liable_amount'=> $liable !== null ? money((float) $liable) : null,
        'insurance_claim_amount'=> $ins !== null ? money((float) $ins) : null,
        'status'                => $st,
        'resolution_notes'      => $st === 'resolved' ? 'Repair completed; customer portion recovered.' : null,
        'reported_by'           => SEED_USER,
    ]);
    db_execute("UPDATE damage_claims SET created_at = ?, updated_at = ? WHERE id = ?",
        [$repDate . ' 11:40:00', $repDate . ' 11:40:00', $cid]);
    $claimCount++;
}
echo "  + {$claimCount} damage claims\n";

// ── B3: Inspections — pre/post-lease + periodic CVI ─────────────────────────
echo "[B3] Inspections…\n";
$inspSeqByYear = []; $inspCount = 0;
$mkInsp = function (array $row, string $date) use (&$inspSeqByYear, &$inspCount): void {
    $yr = (int) substr($date, 0, 4);
    $inspSeqByYear[$yr] = ($inspSeqByYear[$yr] ?? 0) + 1;
    $row['inspection_number'] = sprintf('INSP-%d-%04d', $yr, $inspSeqByYear[$yr]);
    $row['inspection_date']   = $date;
    $id = db_insert('inspections', $row);
    db_execute("UPDATE inspections SET created_at = ?, updated_at = ? WHERE id = ?", [$date . ' 08:20:00', $date . ' 08:20:00', $id]);
    $inspCount++;
};
$conditions = ['excellent','good','good','fair'];
foreach ($leases as $i => $L) {
    if ($i % 2 === 1) continue;   // pre/post docs on roughly half the book
    $cond = $conditions[array_rand($conditions)];
    $mkInsp([
        'inspection_type'      => 'pre_lease',
        'equipment_unit_id'    => (int) $L['equipment_unit_id'],
        'lease_id'             => (int) $L['id'],
        'overall_condition'    => $cond,
        'condition_score'      => $cond === 'excellent' ? random_int(92, 99) : ($cond === 'good' ? random_int(80, 91) : random_int(65, 79)),
        'status'               => 'signed',
        'inspected_by'         => 'Yard staff',
        'inspected_by_user_id' => SEED_USER,
        'fuel_level'           => ['half','three_quarter','full'][random_int(0, 2)],
        'is_clean'             => 1,
        'signed_at'            => $L['start_date'] . ' 09:05:00',
        'notes'                => 'Pre-lease walkaround — photos on file.',
    ], date('Y-m-d', strtotime($L['start_date'] . ' -1 day')));
    if ($L['status'] === 'completed' && $L['actual_return_date']) {
        $mkInsp([
            'inspection_type'      => 'post_lease',
            'equipment_unit_id'    => (int) $L['equipment_unit_id'],
            'lease_id'             => (int) $L['id'],
            'overall_condition'    => ['good','good','fair'][random_int(0, 2)],
            'condition_score'      => random_int(70, 92),
            'status'               => 'signed',
            'inspected_by'         => 'Yard staff',
            'inspected_by_user_id' => SEED_USER,
            'fuel_level'           => ['quarter','half','three_quarter'][random_int(0, 2)],
            'is_clean'             => random_int(0, 1),
            'signed_at'            => $L['actual_return_date'] . ' 15:45:00',
            'notes'                => 'Return inspection — condition compared against pre-lease report.',
        ], $L['actual_return_date']);
    }
}
// Periodic compliance (annual CVI) on a third of the fleet.
foreach ($units as $i => $u) {
    if ($i % 3 !== 0) continue;
    $d = date('Y-m-d', strtotime('-' . random_int(20, 340) . ' days'));
    $mkInsp([
        'inspection_type'      => 'compliance',
        'equipment_unit_id'    => (int) $u['id'],
        'overall_condition'    => 'good',
        'condition_score'      => random_int(78, 96),
        'status'               => 'complete',
        'inspected_by'         => 'Desmond CVI Inspections',
        'inspected_by_user_id' => SEED_USER,
        'cvi_expiry'           => date('Y-m-d', strtotime($d . ' +1 year')),
        'notes'                => 'Annual CVI — passed.',
    ], $d);
}
echo "  + {$inspCount} inspections\n";

// ── B4: Reservations — full status spread, past + pipeline ──────────────────
echo "[B4] Reservations…\n";
$customers = db_select("SELECT id, company_name, contact_name, phone, email FROM customers WHERE deleted_at IS NULL AND status='active' ORDER BY id", []);
$availUnits = db_select(
    "SELECT eu.id, eu.unit_number, eu.status, t.name tname
       FROM equipment_units eu JOIN equipment_templates t ON t.id = eu.template_id
      WHERE eu.deleted_at IS NULL AND eu.status = 'available' ORDER BY eu.id", []
);
$purposes = ['Produce season overflow capacity','Intermodal port repositioning','Lumber contract — open deck','Retail DC peak surge','Cold-chain pharma lane','Cross-border reefer lane','Container drayage surge','Dedicated dry van lane'];
$resDefs = [];
foreach ([3, 8, 14, 21] as $d)   $resDefs[] = ['confirmed', $d];
foreach ([10, 18, 28, 40] as $d) $resDefs[] = ['pending', $d];
foreach ([-70, -220, -420, -640] as $d) $resDefs[] = ['completed', $d];
foreach ([-35, -150] as $d)      $resDefs[] = ['cancelled', $d];
$resCount = 0; $ui = 0;
foreach ($resDefs as $k => [$rs, $dd]) {
    if ($ui >= count($availUnits)) break;
    $u = $availUnits[$ui++];
    $c = $customers[$k % count($customers)];
    $pickup = date('Y-m-d', strtotime(($dd >= 0 ? "+{$dd}" : $dd) . ' days'));
    $createdAt = date('Y-m-d H:i:s', strtotime($pickup . ' -' . random_int(4, 15) . ' days 10:00'));
    $rid = db_insert('reservations', [
        'status'        => $rs,
        'customer_id'   => (int) $c['id'],
        'contact_name'  => $c['contact_name'],
        'company_name'  => $c['company_name'],
        'contact_phone' => $c['phone'],
        'contact_email' => $c['email'],
        'quantity'      => 1,
        'pickup_date'   => $pickup,
        'pickup_time'   => random_int(0, 1) ? sprintf('%02d:00:00', random_int(7, 15)) : null,
        'yard_location' => null,
        'purpose'       => $purposes[$k % count($purposes)],
        'priority'      => ['low','medium','medium','high','urgent'][random_int(0, 4)],
        'notes'         => "Reservation — {$c['company_name']}",
        'created_by'    => SEED_USER,
        'updated_by'    => SEED_USER,
    ]);
    db_execute("UPDATE reservations SET created_at = ?, updated_at = ? WHERE id = ?", [$createdAt, $createdAt, $rid]);
    db_insert('reservation_units', [
        'reservation_id'         => $rid,
        'equipment_unit_id'      => (int) $u['id'],
        'unit_number_snapshot'   => $u['unit_number'],
        'template_name_snapshot' => $u['tname'],
        'status_at_reservation'  => $u['status'],
        'entry_type'             => 'system',
    ]);
    if ($rs === 'confirmed') {
        db_execute("UPDATE equipment_units SET status = 'reserved', updated_by = ? WHERE id = ?", [SEED_USER, (int) $u['id']]);
    }
    $resCount++;
}
echo "  + {$resCount} reservations\n";

// ── B5: Collections story — notes + promises on the credit-hold customer ────
echo "[B5] Collections…\n";
$holdCust = db_row("SELECT id, contact_name FROM customers WHERE status = 'credit_hold' LIMIT 1", []);
$noteCount = 0;
if ($holdCust) {
    $hcId = (int) $holdCust['id'];
    $overdueInvs = db_select("SELECT id, invoice_number, balance_due, due_date FROM invoices WHERE customer_id = ? AND status = 'overdue' ORDER BY due_date LIMIT 5", [$hcId]);
    $noteDefs = [
        [95, 'phone',  'no_answer',            'Called AP line twice — voicemail full. Emailing statement.'],
        [70, 'email',  'left_message',         'Emailed full statement + aging. Requested payment plan discussion.'],
        [45, 'phone',  'spoke_with_customer',  'Spoke with owner — cash-flow issues from a lost contract. Wants a plan.'],
        [24, 'phone',  'payment_promised',     'Promised $6,500 by end of month. Account remains on credit hold.'],
        [6,  'email',  'dispute',              'Disputes mileage charge on latest invoice — reviewing GPS logs before escalating.'],
    ];
    foreach ($noteDefs as $i => [$ago, $method, $outcome, $note]) {
        db_insert('acc_collection_notes', [
            'customer_id'    => $hcId,
            'invoice_id'     => isset($overdueInvs[$i]) ? (int) $overdueInvs[$i]['id'] : null,
            'note_date'      => date('Y-m-d', strtotime("-{$ago} days")),
            'contact_method' => $method,
            'contact_person' => $holdCust['contact_name'],
            'note'           => $note,
            'outcome'        => $outcome,
            'follow_up_date' => date('Y-m-d', strtotime("-{$ago} days +10 days")),
            'created_by'     => SEED_USER,
        ]);
        $noteCount++;
    }
    if ($overdueInvs) {
        db_insert('acc_promise_to_pay', [
            'customer_id'     => $hcId,
            'invoice_id'      => (int) $overdueInvs[0]['id'],
            'promised_amount' => $overdueInvs[0]['balance_due'],
            'promise_date'    => date('Y-m-d', strtotime('-18 days')),
            'promised_by'     => $holdCust['contact_name'],
            'status'          => 'broken',
            'notes'           => 'Missed the promised date — no funds received.',
            'created_by'      => SEED_USER,
        ]);
        db_insert('acc_promise_to_pay', [
            'customer_id'     => $hcId,
            'invoice_id'      => isset($overdueInvs[1]) ? (int) $overdueInvs[1]['id'] : (int) $overdueInvs[0]['id'],
            'promised_amount' => money(6500.00),
            'promise_date'    => date('Y-m-d', strtotime('+8 days')),
            'promised_by'     => $holdCust['contact_name'],
            'status'          => 'pending',
            'notes'           => 'Payment plan instalment 1 of 3 agreed on last call.',
            'created_by'      => SEED_USER,
        ]);
        $noteCount += 2;
    }
}
echo "  + {$noteCount} collection notes/promises\n";

// ── B6: Customer security deposits ───────────────────────────────────────────
echo "[B6] Deposits…\n";
$depositAcct = acctLike('%Deposit%', 'liability') ?? acctLike('%Deposit%');
$activeLeases = array_values(array_filter($leases, fn($l) => $l['status'] === 'active'));
$doneLeases   = array_values(array_filter($leases, fn($l) => $l['status'] === 'completed'));
$depCount = 0; $depSeqByYear = [];
$mkDeposit = function (array $L, string $status) use (&$depCount, &$depSeqByYear, $depositAcct): void {
    $recv = $L['start_date'];
    $yr = (int) substr($recv, 0, 4);
    $depSeqByYear[$yr] = ($depSeqByYear[$yr] ?? 0) + 1;
    $amount = (float) (random_int(15, 40) * 100);
    db_insert('acc_customer_deposits', [
        'deposit_number'       => sprintf('DEP-%d-%03d', $yr, $depSeqByYear[$yr]),
        'customer_id'          => (int) $L['customer_id'],
        'lease_id'             => (int) $L['id'],
        'deposit_type'         => 'security',
        'amount'               => money($amount),
        'currency'             => 'CAD',
        'received_date'        => $recv,
        'status'               => $status,
        'refund_date'          => $status === 'refunded' ? ($L['actual_return_date'] ?: $L['end_date']) : null,
        'refund_method'        => $status === 'refunded' ? 'eft' : null,
        'liability_account_id' => $depositAcct,
        'notes'                => 'Security deposit — one month equivalent.',
        'created_by'           => SEED_USER,
    ]);
    $depCount++;
};
foreach (array_slice($activeLeases, 0, 4) as $L) $mkDeposit($L, 'held');
foreach (array_slice($doneLeases, 0, 2) as $L)   $mkDeposit($L, 'refunded');
if (isset($doneLeases[2])) $mkDeposit($doneLeases[2], 'forfeited');
echo "  + {$depCount} customer deposits\n";

// ── B7: 2026 operating budget derived from posted GL actuals ────────────────
echo "[B7] Budget…\n";
$budgetId = db_insert('acc_budgets', [
    'name'       => '2026 Operating Budget',
    'year'       => 2026,
    'version'    => 'base',
    'status'     => 'active',
    'is_active'  => 1,
    'notes'      => 'Board-approved January 2026. Revenue +15% over 2025 actuals; maintenance held flat.',
    'created_by' => SEED_USER,
]);
// Budget = 2025/2026 actual monthly activity ±10% so budget-vs-actual reads sane.
$budgetAccounts = db_select(
    "SELECT l.account_id,
            SUM(CASE WHEN a.account_type IN ('revenue') THEN l.credit - l.debit ELSE l.debit - l.credit END) / 18 AS avg_m
       FROM acc_journal_entry_lines l
       JOIN acc_journal_entries e ON e.id = l.journal_entry_id
       JOIN acc_accounts a ON a.id = l.account_id
      WHERE e.entry_date >= '2025-01-01'
        AND a.account_type IN ('revenue','operating_expense','cost_of_revenue')
      GROUP BY l.account_id
     HAVING ABS(avg_m) > 100", []
);
$monthCols = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
$blCount = 0;
foreach ($budgetAccounts as $ba) {
    $base = abs((float) $ba['avg_m']) * 1.12;
    $row = ['budget_id' => $budgetId, 'account_id' => (int) $ba['account_id']];
    foreach ($monthCols as $mi => $mc) {
        $season = 1.0 + 0.12 * sin(($mi + 1) / 12 * 2 * M_PI);   // mild seasonality
        $row[$mc] = money(round($base * $season / 50) * 50);
    }
    db_insert('acc_budget_lines', $row);
    $blCount++;
}
echo "  + budget #{$budgetId} with {$blCount} account lines\n";

// ── B8: Recurring entry templates ────────────────────────────────────────────
echo "[B8] Recurring entries…\n";
$recCount = 0;
$mkRecurring = function (string $name, string $desc, ?int $drAcct, ?int $crAcct, float $amt) use (&$recCount): void {
    if (!$drAcct || !$crAcct) return;   // chart variant lacks the account — skip quietly
    $rid = db_insert('acc_recurring_entries', [
        'name'           => $name,
        'description'    => $desc,
        'frequency'      => 'monthly',
        'day_of_month'   => 1,
        'start_date'     => '2024-01-01',
        'next_post_date' => date('Y-m-01', strtotime('first day of next month')),
        'last_posted_date' => date('Y-m-01'),
        'is_active'      => 1,
        'auto_post'      => 0,
        'created_by'     => SEED_USER,
    ]);
    db_insert('acc_recurring_entry_lines', ['recurring_entry_id' => $rid, 'account_id' => $drAcct, 'line_number' => 1, 'description' => $desc, 'debit' => money($amt), 'credit' => '0.00']);
    db_insert('acc_recurring_entry_lines', ['recurring_entry_id' => $rid, 'account_id' => $crAcct, 'line_number' => 2, 'description' => $desc, 'debit' => '0.00', 'credit' => money($amt)]);
    $recCount++;
};
$mkRecurring('Yard lease — Surrey', 'Monthly yard ground lease', acctLike('%Rent%'), acctId('1010'), 6800.00);
$mkRecurring('Software subscriptions', 'Fleet telematics + accounting SaaS', acctLike('%Software%'), acctId('1010'), 940.00);
$mkRecurring('Fleet insurance premium', 'Monthly commercial fleet policy instalment', acctLike('%Insurance%'), acctId('1010'), 7200.00);
echo "  + {$recCount} recurring entry templates\n";

// ── B9: Realism backdating + taxonomy backfill ───────────────────────────────
echo "[B9] Backdating + category backfill…\n";
// Templates predate the fleet.
db_execute("UPDATE equipment_templates SET created_at = '2022-09-15 10:00:00' WHERE deleted_at IS NULL", []);
// Units appear when their asset was acquired.
db_execute(
    "UPDATE equipment_units eu JOIN acc_fixed_assets fa ON fa.equipment_unit_id = eu.id
        SET eu.created_at = CONCAT(fa.acquisition_date, ' 09:00:00')", []
);
// Customers appear ~2 weeks before their first lease (or ~2 years ago if lease-less).
db_execute(
    "UPDATE customers c
       LEFT JOIN (SELECT customer_id, MIN(start_date) fs FROM leases WHERE deleted_at IS NULL GROUP BY customer_id) f
              ON f.customer_id = c.id
        SET c.created_at = CONCAT(COALESCE(DATE_SUB(f.fs, INTERVAL 16 DAY), DATE_SUB(CURDATE(), INTERVAL 700 DAY)), ' 11:30:00')", []
);
// Payments recorded the day they were received.
db_execute("UPDATE payments SET created_at = CONCAT(payment_date, ' 14:10:00') WHERE deleted_at IS NULL", []);
// Rate cards authored when they took effect.
db_execute("UPDATE rate_cards SET created_at = CONCAT(effective_from, ' 09:45:00') WHERE deleted_at IS NULL", []);
// Two-level taxonomy: mirror slug → category_id (S-EQTAX contract).
db_execute(
    "UPDATE equipment_templates t JOIN equipment_categories c ON c.slug = t.category
        SET t.category_id = c.id WHERE t.category_id IS NULL", []
);
echo "  done\n";

echo "\n=== Phase B complete ===\n";
