<?php declare(strict_types=1);

/**
 * api/v1/accounting/fixed_assets/payoff_report.php
 *
 * Fleet-wide payoff report. For every active fixed asset that is
 * linked to an equipment unit, returns the same core payoff metrics
 * the single-asset payoff endpoint returns — purchase cost, total
 * acquisition, net revenue to date, still to recover, progress %,
 * and the "current" scenario projection (6-month rolling net).
 *
 * WHY a separate endpoint:
 *   The single-asset endpoint ran 3-4 SQL queries per asset. Calling
 *   it in a loop for 100+ assets would be painfully slow. This
 *   endpoint runs exactly 4 aggregated queries regardless of fleet
 *   size: one for the asset list, then grouped sums across revenue,
 *   maintenance, and damage keyed by equipment_unit_id.
 *
 * Scenario math:
 *   Only the "current" (6-month rolling net) scenario is computed
 *   here — the fleet report only displays a single projection per
 *   row and "current" is what the UI uses. Users can click through
 *   to a specific asset to see conservative/optimistic too.
 *
 * @method  GET
 * @query   status         (optional) filter a.status, default 'active'
 *          sort           (optional) progress_pct|still_to_recover|
 *                                    net_revenue|total_acquisition|
 *                                    asset_number (default progress_pct)
 *          dir            (optional) ASC|DESC (default DESC)
 *          search         (optional) name/asset_number LIKE
 * @auth    Session required; require_permission('fixed_assets','view')
 * @returns 200 { rows: [...], summary: {...} }
 *
 * @depends api/bootstrap.php
 *
 * @session PAYOFF-1 — Unit Payoff Calculator
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('fixed_assets', 'view');

// ── BCMath helpers (same as payoff.php) ────────────────────────
$bcround = static function (string $val, int $scale = 2): string {
    if ($val === '' || !is_numeric($val)) return '0.00';
    $half = '0.' . str_repeat('0', $scale) . '5';
    return bcsub(bcadd($val, $half, $scale + 1), '0', $scale);
};

// ── Input ──────────────────────────────────────────────────────
$statusFilter = clean_string($_GET['status'] ?? 'active', 40);
if ($statusFilter === null || $statusFilter === '') {
    $statusFilter = 'active';
}

$allowedSorts = [
    'progress_pct',
    'still_to_recover',
    'net_revenue',
    'total_acquisition',
    'asset_number',
    'projection_months',
];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts, true)
    ? (string) $_GET['sort']
    : 'progress_pct';
$dir = strtoupper((string) ($_GET['dir'] ?? '')) === 'ASC' ? 'ASC' : 'DESC';

$search = clean_string($_GET['search'] ?? null, 100);

// ── Step 1: Load candidate assets ──────────────────────────────
// WHY: Only linked assets can have a payoff (unlinked ones have no
// revenue path). Filter by status (default 'active' so we don't
// include disposed assets).
$where = [
    'a.equipment_unit_id IS NOT NULL',
];
$params = [];

if ($statusFilter !== 'all') {
    $where[]  = 'a.status = ?';
    $params[] = $statusFilter;
}

if ($search !== null && $search !== '') {
    $where[]  = '(a.name LIKE ? OR a.asset_number LIKE ?)';
    $like     = '%' . addcslashes($search, '%_\\') . '%';
    $params[] = $like;
    $params[] = $like;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$assets = db_select(
    "SELECT a.id,
            a.asset_number,
            a.name,
            a.asset_class,
            a.status,
            a.equipment_unit_id,
            a.acquisition_date,
            a.acquisition_cost,
            a.purchase_tax_gst,
            a.purchase_tax_pst,
            a.delivery_cost,
            a.setup_cost,
            a.is_financed,
            a.financing_monthly_payment,
            a.monthly_insurance_cost,
            a.monthly_licensing_cost,
            a.monthly_registration_cost,
            eu.unit_number AS equipment_unit_number
     FROM acc_fixed_assets a
     LEFT JOIN equipment_units eu
            ON eu.id = a.equipment_unit_id
           AND eu.deleted_at IS NULL
     {$whereSQL}
     ORDER BY a.asset_number ASC",
    $params
);

if (empty($assets)) {
    json_success([
        'rows'    => [],
        'summary' => [
            'asset_count'            => 0,
            'total_invested'         => '0.00',
            'total_net_revenue'      => '0.00',
            'total_still_to_recover' => '0.00',
            'avg_progress_pct'       => '0.00',
            'fully_paid_count'       => 0,
        ],
    ]);
}

// Collect unit IDs for the aggregate queries.
$unitIds = [];
foreach ($assets as $a) {
    $unitIds[(int) $a['equipment_unit_id']] = true;
}
$unitIdList = array_keys($unitIds);
$placeholders = implode(',', array_fill(0, count($unitIdList), '?'));

// ── Step 2: Revenue totals per equipment unit ─────────────────
$revenueByUnit = [];
$revRows = db_select(
    "SELECT l.equipment_unit_id AS eu_id,
            COALESCE(SUM(ili.amount), 0) AS total
     FROM invoice_line_items ili
     JOIN invoices i ON i.id = ili.invoice_id
     JOIN leases   l ON l.id = i.lease_id
     WHERE l.equipment_unit_id IN ({$placeholders})
       AND l.deleted_at IS NULL
       AND i.deleted_at IS NULL
       -- Draft is NOT revenue — same predicate as the payoff page and API so
       -- the fleet-wide report cannot disagree with the per-unit view.
       AND i.status NOT IN ('void', 'written_off', 'draft')
       AND ili.is_credit = 0
     GROUP BY l.equipment_unit_id",
    $unitIdList
);
foreach ($revRows as $r) {
    $revenueByUnit[(int) $r['eu_id']] = (string) $r['total'];
}

// ── Step 3: Maintenance totals per unit ───────────────────────
$mntByUnit = [];
$mntRows = db_select(
    "SELECT equipment_unit_id AS eu_id,
            COALESCE(SUM(total_cost), 0) AS total
     FROM maintenance_work_orders
     WHERE equipment_unit_id IN ({$placeholders})
       AND status = 'completed'
       AND deleted_at IS NULL
     GROUP BY equipment_unit_id",
    $unitIdList
);
foreach ($mntRows as $r) {
    $mntByUnit[(int) $r['eu_id']] = (string) $r['total'];
}

// ── Step 4: Damage totals per unit ────────────────────────────
$dmgByUnit = [];
$dmgRows = db_select(
    "SELECT equipment_unit_id AS eu_id,
            COALESCE(SUM(COALESCE(actual_repair_cost, estimated_repair_cost, 0)), 0) AS total
     FROM damage_claims
     WHERE equipment_unit_id IN ({$placeholders})
       AND deleted_at IS NULL
     GROUP BY equipment_unit_id",
    $unitIdList
);
foreach ($dmgRows as $r) {
    $dmgByUnit[(int) $r['eu_id']] = (string) $r['total'];
}

// ── Step 5: 6-month rolling net revenue per unit ──────────────
// WHY: For the "current" scenario projection in the fleet report.
// We run one query that buckets the last 6 months of revenue by
// unit, then do the same for maintenance and damage. Each asset's
// monthly fixed cost and financing burden are subtracted per-asset
// below (they live on the asset row, not the unit).
$rev6ByUnit = [];
$rev6Rows = db_select(
    "SELECT l.equipment_unit_id AS eu_id,
            COALESCE(SUM(ili.amount), 0) AS total
     FROM invoice_line_items ili
     JOIN invoices i ON i.id = ili.invoice_id
     JOIN leases   l ON l.id = i.lease_id
     WHERE l.equipment_unit_id IN ({$placeholders})
       AND l.deleted_at IS NULL
       AND i.deleted_at IS NULL
       -- Draft is NOT revenue — same predicate as the payoff page and API so
       -- the fleet-wide report cannot disagree with the per-unit view.
       AND i.status NOT IN ('void', 'written_off', 'draft')
       AND ili.is_credit = 0
       AND i.invoice_date >= (CURDATE() - INTERVAL 6 MONTH)
     GROUP BY l.equipment_unit_id",
    $unitIdList
);
foreach ($rev6Rows as $r) {
    $rev6ByUnit[(int) $r['eu_id']] = (string) $r['total'];
}

$mnt6ByUnit = [];
$mnt6Rows = db_select(
    "SELECT equipment_unit_id AS eu_id,
            COALESCE(SUM(total_cost), 0) AS total
     FROM maintenance_work_orders
     WHERE equipment_unit_id IN ({$placeholders})
       AND status = 'completed'
       AND deleted_at IS NULL
       AND COALESCE(completed_date, requested_date) >= (CURDATE() - INTERVAL 6 MONTH)
     GROUP BY equipment_unit_id",
    $unitIdList
);
foreach ($mnt6Rows as $r) {
    $mnt6ByUnit[(int) $r['eu_id']] = (string) $r['total'];
}

$dmg6ByUnit = [];
$dmg6Rows = db_select(
    "SELECT equipment_unit_id AS eu_id,
            COALESCE(SUM(COALESCE(actual_repair_cost, estimated_repair_cost, 0)), 0) AS total
     FROM damage_claims
     WHERE equipment_unit_id IN ({$placeholders})
       AND deleted_at IS NULL
       AND created_at >= (CURDATE() - INTERVAL 6 MONTH)
     GROUP BY equipment_unit_id",
    $unitIdList
);
foreach ($dmg6Rows as $r) {
    $dmg6ByUnit[(int) $r['eu_id']] = (string) $r['total'];
}

// ── Step 6: Per-asset calculation loop ────────────────────────
$today = new DateTimeImmutable('today');
$rows = [];

// Summary accumulators
$sumInvested     = '0.00';
$sumNetRevenue   = '0.00';
$sumStillToRec   = '0.00';
$sumProgress     = '0.00';
$fullyPaidCount  = 0;

foreach ($assets as $a) {
    $euId = (int) $a['equipment_unit_id'];

    // Acquisition total (purchase + taxes + delivery + setup)
    $purchase = (string) ($a['acquisition_cost'] ?? '0.00');
    $gst      = (string) ($a['purchase_tax_gst']  ?? '0.00');
    $pst      = (string) ($a['purchase_tax_pst']  ?? '0.00');
    $delv     = (string) ($a['delivery_cost']     ?? '0.00');
    $setup    = (string) ($a['setup_cost']        ?? '0.00');
    if ($gst === '')   $gst = '0.00';
    if ($pst === '')   $pst = '0.00';
    if ($delv === '')  $delv = '0.00';
    if ($setup === '') $setup = '0.00';

    $totalAcquisition = bcadd($purchase, $gst, 2);
    $totalAcquisition = bcadd($totalAcquisition, $pst, 2);
    $totalAcquisition = bcadd($totalAcquisition, $delv, 2);
    $totalAcquisition = bcadd($totalAcquisition, $setup, 2);

    // Months since acquisition (>= 1)
    $monthsSince = 1;
    if (!empty($a['acquisition_date'])) {
        try {
            $start = new DateTimeImmutable((string) $a['acquisition_date']);
            if ($today > $start) {
                $diff = $start->diff($today);
                $ms = ($diff->y * 12) + $diff->m;
                if ($diff->d > 0) $ms += 1;
                if ($ms > 0) $monthsSince = $ms;
            }
        } catch (\Throwable $e) {
            // leave at 1
        }
    }

    // Monthly fixed cost total
    $ins = (string) ($a['monthly_insurance_cost']    ?? '0.00');
    $lic = (string) ($a['monthly_licensing_cost']    ?? '0.00');
    $reg = (string) ($a['monthly_registration_cost'] ?? '0.00');
    if ($ins === '') $ins = '0.00';
    if ($lic === '') $lic = '0.00';
    if ($reg === '') $reg = '0.00';
    $monthlyFixed = bcadd(bcadd($ins, $lic, 2), $reg, 2);
    $totalFixedPaid = bcmul($monthlyFixed, (string) $monthsSince, 2);

    // Financing paid to date
    $totalFinPaid = '0.00';
    if ((int) ($a['is_financed'] ?? 0) === 1
        && !empty($a['financing_monthly_payment'])) {
        $pmt = (string) $a['financing_monthly_payment'];
        if ($pmt === '') $pmt = '0.00';
        $totalFinPaid = bcmul($pmt, (string) $monthsSince, 2);
    }

    // Net revenue to date
    $rev = $revenueByUnit[$euId] ?? '0.00';
    $mnt = $mntByUnit[$euId]     ?? '0.00';
    $dmg = $dmgByUnit[$euId]     ?? '0.00';
    if ($rev === '') $rev = '0.00';
    if ($mnt === '') $mnt = '0.00';
    if ($dmg === '') $dmg = '0.00';

    $netRev = bcsub($rev, $mnt, 2);
    $netRev = bcsub($netRev, $dmg, 2);
    $netRev = bcsub($netRev, $totalFinPaid, 2);
    $netRev = bcsub($netRev, $totalFixedPaid, 2);

    // Still to recover
    $stillToRec = bcsub($totalAcquisition, $netRev, 2);
    $isFullyPaid = bccomp($stillToRec, '0', 2) <= 0;
    if ($isFullyPaid) {
        $stillToRec = '0.00';
    }

    // Progress % (can exceed 100)
    $progressPct = '0.00';
    if (bccomp($totalAcquisition, '0', 2) > 0) {
        $raw = bcdiv(bcmul($netRev, '100', 6), $totalAcquisition, 6);
        $progressPct = $bcround($raw, 2);
    }

    // 6-month rolling monthly net
    $rev6 = $rev6ByUnit[$euId] ?? '0.00';
    $mnt6 = $mnt6ByUnit[$euId] ?? '0.00';
    $dmg6 = $dmg6ByUnit[$euId] ?? '0.00';
    if ($rev6 === '') $rev6 = '0.00';
    if ($mnt6 === '') $mnt6 = '0.00';
    if ($dmg6 === '') $dmg6 = '0.00';

    // Monthly finance payment component
    $monthlyFin = '0.00';
    if ((int) ($a['is_financed'] ?? 0) === 1
        && !empty($a['financing_monthly_payment'])) {
        $monthlyFin = (string) $a['financing_monthly_payment'];
        if ($monthlyFin === '') $monthlyFin = '0.00';
    }

    // Net6 = (rev6 - mnt6 - dmg6) - 6 * (monthlyFin + monthlyFixed)
    $net6 = bcsub($rev6, $mnt6, 2);
    $net6 = bcsub($net6, $dmg6, 2);
    $sixMoFixed = bcmul(bcadd($monthlyFin, $monthlyFixed, 2), '6', 2);
    $net6 = bcsub($net6, $sixMoFixed, 2);

    // Average monthly net over 6 months
    $avg6 = bcdiv($net6, '6', 6);
    $avg6 = $bcround($avg6, 2);

    // Projection months and date
    $projMonths = null;
    $projDate   = null;
    if (!$isFullyPaid
        && bccomp($avg6, '0', 2) > 0
        && bccomp($stillToRec, '0', 2) > 0) {
        $rawMonths = bcdiv($stillToRec, $avg6, 2);
        $projMonths = (int) ceil((float) $rawMonths);
        try {
            $projDate = $today->modify("+{$projMonths} months")->format('Y-m-d');
        } catch (\Throwable $e) {
            $projDate = null;
        }
    }

    $rows[] = [
        'id'                    => (int) $a['id'],
        'asset_number'          => $a['asset_number'],
        'name'                  => $a['name'],
        'asset_class'           => $a['asset_class'],
        'status'                => $a['status'],
        'equipment_unit_id'     => $euId,
        'equipment_unit_number' => $a['equipment_unit_number'],
        'acquisition_date'      => $a['acquisition_date'],
        'purchase_cost'         => $bcround($purchase, 2),
        'total_acquisition'     => $bcround($totalAcquisition, 2),
        'total_revenue'         => $bcround($rev, 2),
        'total_maintenance'     => $bcround($mnt, 2),
        'total_damage'          => $bcround($dmg, 2),
        'total_financing_paid'  => $bcround($totalFinPaid, 2),
        'total_fixed_paid'      => $bcround($totalFixedPaid, 2),
        'net_revenue'           => $bcround($netRev, 2),
        'still_to_recover'      => $bcround($stillToRec, 2),
        'progress_pct'          => $progressPct,
        'avg_monthly_net_6mo'   => $avg6,
        'projection_months'     => $projMonths,
        'projection_date'       => $projDate,
        'fully_paid'            => $isFullyPaid,
    ];

    // Accumulate summary
    $sumInvested   = bcadd($sumInvested,   $totalAcquisition, 2);
    $sumNetRevenue = bcadd($sumNetRevenue, $netRev, 2);
    $sumStillToRec = bcadd($sumStillToRec, $stillToRec, 2);
    $sumProgress   = bcadd($sumProgress,   $progressPct, 4);
    if ($isFullyPaid) $fullyPaidCount += 1;
}

// ── Step 7: Server-side sort ──────────────────────────────────
// WHY: We can't sort in SQL because several fields are computed.
$sortCmp = function (array $a, array $b) use ($sort, $dir): int {
    $av = $a[$sort] ?? null;
    $bv = $b[$sort] ?? null;

    // Nulls always float to the bottom regardless of sort direction.
    if ($av === null && $bv === null) return 0;
    if ($av === null) return 1;
    if ($bv === null) return -1;

    if (is_numeric($av) && is_numeric($bv)) {
        $cmp = bccomp((string) $av, (string) $bv, 4);
    } else {
        $cmp = strcmp((string) $av, (string) $bv);
    }

    return $dir === 'ASC' ? $cmp : -$cmp;
};
usort($rows, $sortCmp);

// ── Summary ────────────────────────────────────────────────────
$count = count($rows);
$avgProgress = $count > 0
    ? $bcround(bcdiv($sumProgress, (string) $count, 6), 2)
    : '0.00';

json_success([
    'rows' => $rows,
    'summary' => [
        'asset_count'            => $count,
        'total_invested'         => $bcround($sumInvested, 2),
        'total_net_revenue'      => $bcround($sumNetRevenue, 2),
        'total_still_to_recover' => $bcround($sumStillToRec, 2),
        'avg_progress_pct'       => $avgProgress,
        'fully_paid_count'       => $fullyPaidCount,
    ],
]);
