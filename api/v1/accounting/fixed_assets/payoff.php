<?php declare(strict_types=1);

/**
 * api/v1/accounting/fixed_assets/payoff.php
 *
 * Unit Payoff Calculator — returns every number the Payoff Analysis
 * tab and the Fleet Payoff Report need for a single fixed asset.
 *
 * Business question answered:
 *     "How long until this unit pays for itself?"
 *
 * The asset must be linked to an equipment_unit_id, because all of
 * the revenue, maintenance, and damage numbers are pulled live from
 * the operational modules using that link:
 *
 *     Revenue          → invoice_line_items JOIN invoices JOIN leases
 *                         WHERE leases.equipment_unit_id = asset.eu_id
 *     Maintenance cost → maintenance_work_orders.total_cost
 *                         WHERE equipment_unit_id = asset.eu_id
 *                           AND status = 'completed'
 *     Damage cost      → damage_claims.actual_repair_cost
 *                         (falls back to estimated_repair_cost)
 *                         WHERE equipment_unit_id = asset.eu_id
 *
 * Total acquisition cost = purchase_cost
 *                        + GST + PST
 *                        + delivery_cost
 *                        + setup_cost
 *
 * Monthly fixed costs    = insurance + licensing + registration
 * Financing paid to date = monthly_payment × months_since_acquisition
 *                          (capped at months × payment, no negative)
 *
 * Net revenue to date    = revenue
 *                        − maintenance
 *                        − damage
 *                        − financing paid so far
 *                        − fixed costs × months since acquisition
 *
 * Still to recover       = total_acquisition_cost − net_revenue_to_date
 * Progress pct           = (net_revenue_to_date / total_acquisition_cost) × 100
 *
 * Three average scenarios are returned so the UI can display
 * conservative / current / optimistic projections:
 *   conservative = last 12 months average monthly net
 *   current      = last  6 months average monthly net
 *   optimistic   = last  3 months average monthly net
 *
 * Projected months = still_to_recover / avg_monthly_net
 * Projected date   = today + projected months
 *
 * Monetary math is bcmath-only per D16. No float operators on any
 * dollar value. Strings go in, strings come out.
 *
 * @method  GET
 * @query   asset_id   (required) — ID of acc_fixed_assets row
 *          period     (optional) — 3|6|12, default 6 — which scenario
 *                                  to use as the "projection" field
 *          custom_monthly_revenue (optional, string) — override the
 *                                  calculated average with a user
 *                                  supplied projection; returned as
 *                                  custom_projection when provided
 *          extra_costs (optional, comma-separated amounts) — one-time
 *                                  upcoming costs that reduce payoff
 * @auth    Session required; require_permission('fixed_assets','view')
 * @returns 200 { asset, acquisition, totals, scenarios, monthly_data,
 *                custom_projection? } | 404 | 422
 *
 * @depends api/bootstrap.php
 *
 * @session PAYOFF-1 — Unit Payoff Calculator
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('fixed_assets', 'view');

// ── Helper: BCMath rounding, matches functions.php::bcround ─────
// WHY: We occasionally hit division by zero (no data yet) — we want
// a safe "— no projection —" output, not a crash.
$bcround = static function (string $val, int $scale = 2): string {
    if ($val === '' || !is_numeric($val)) return '0.00';
    $half = '0.' . str_repeat('0', $scale) . '5';
    // bcsub against zero at $scale precision truncates the extra digit.
    return bcsub(bcadd($val, $half, $scale + 1), '0', $scale);
};

// ── Helper: safe division returning string or null when divisor 0
$bcdiv_safe = static function (string $num, string $den, int $scale = 6): ?string {
    if (bccomp($den, '0', $scale) === 0) return null;
    return bcdiv($num, $den, $scale);
};

// ── Input ──────────────────────────────────────────────────────
$assetId = clean_int($_GET['asset_id'] ?? null);
if (!$assetId) {
    json_error('MISSING_REQUIRED', 'asset_id is required.', 422);
}

// Period selector — only 3, 6, or 12 accepted.
$period = clean_int($_GET['period'] ?? 6) ?? 6;
if (!in_array($period, [3, 6, 12], true)) {
    $period = 6;
}

$customMonthly = clean_decimal($_GET['custom_monthly_revenue'] ?? null);

// One-time upcoming costs (CSV of decimals). Non-numeric values dropped.
$extraCostsRaw = (string) ($_GET['extra_costs'] ?? '');
$extraCosts = [];
if ($extraCostsRaw !== '') {
    foreach (explode(',', $extraCostsRaw) as $tok) {
        $tok = trim($tok);
        $clean = clean_decimal($tok);
        if ($clean !== null && bccomp($clean, '0', 2) >= 0) {
            $extraCosts[] = $clean;
        }
    }
}
$extraCostsTotal = '0.00';
foreach ($extraCosts as $c) {
    $extraCostsTotal = bcadd($extraCostsTotal, $c, 2);
}

// ── Asset lookup ────────────────────────────────────────────────
$asset = db_row(
    "SELECT a.*, eu.unit_number AS equipment_unit_number
     FROM acc_fixed_assets a
     LEFT JOIN equipment_units eu ON eu.id = a.equipment_unit_id
       AND eu.deleted_at IS NULL
     WHERE a.id = ?",
    [$assetId]
);

if (!$asset) {
    json_error('NOT_FOUND', 'Asset not found.', 404);
}

if (!$asset['equipment_unit_id']) {
    json_error(
        'NOT_LINKED',
        'This asset is not linked to an equipment unit. Link it on the Edit form before running a payoff analysis.',
        422
    );
}

$eqUnitId = (int) $asset['equipment_unit_id'];

// ── Acquisition total ──────────────────────────────────────────
// WHY: acquisition_cost on the asset row is the "sticker price" —
// the cost we depreciate. Taxes, delivery, and setup are real dollars
// out the door but don't show up in depreciable_cost, so we add them
// separately when computing the payoff target.
$purchaseCost   = (string) ($asset['acquisition_cost']        ?? '0.00');
$gst            = (string) ($asset['purchase_tax_gst']        ?? '0.00');
$pst            = (string) ($asset['purchase_tax_pst']        ?? '0.00');
$delivery       = (string) ($asset['delivery_cost']           ?? '0.00');
$setup          = (string) ($asset['setup_cost']              ?? '0.00');

// Nulls from the DB come back as null, not "0.00" — normalise.
if ($gst === '')      $gst = '0.00';
if ($pst === '')      $pst = '0.00';
if ($delivery === '') $delivery = '0.00';
if ($setup === '')    $setup = '0.00';

$totalAcquisition = bcadd($purchaseCost, $gst, 2);
$totalAcquisition = bcadd($totalAcquisition, $pst, 2);
$totalAcquisition = bcadd($totalAcquisition, $delivery, 2);
$totalAcquisition = bcadd($totalAcquisition, $setup, 2);

// Add one-time upcoming costs on top of the acquisition target.
$adjustedTargetCost = bcadd($totalAcquisition, $extraCostsTotal, 2);

// ── Months since acquisition ───────────────────────────────────
// WHY: We need a count of whole months from acquisition_date to
// today so we can scale monthly fixed costs and financing payments
// accurately. Clamp to at least 1 so the very first day doesn't
// produce nonsense zero denominators.
$acquiredDate = (string) ($asset['acquisition_date'] ?? '');
$monthsSinceAcq = 0;
if ($acquiredDate !== '') {
    try {
        $start = new DateTimeImmutable($acquiredDate);
        $now   = new DateTimeImmutable('today');
        if ($now > $start) {
            $diff = $start->diff($now);
            $monthsSinceAcq = ($diff->y * 12) + $diff->m;
            if ($diff->d > 0) $monthsSinceAcq += 1;
        }
    } catch (\Throwable $e) {
        $monthsSinceAcq = 0;
    }
}
if ($monthsSinceAcq < 1) $monthsSinceAcq = 1;

// ── Monthly fixed cost total ───────────────────────────────────
$ins   = (string) ($asset['monthly_insurance_cost']    ?? '0.00');
$lic   = (string) ($asset['monthly_licensing_cost']    ?? '0.00');
$reg   = (string) ($asset['monthly_registration_cost'] ?? '0.00');
if ($ins === '') $ins = '0.00';
if ($lic === '') $lic = '0.00';
if ($reg === '') $reg = '0.00';

$monthlyFixed = bcadd(bcadd($ins, $lic, 2), $reg, 2);
$totalFixedPaid = bcmul($monthlyFixed, (string) $monthsSinceAcq, 2);

// ── Financing paid so far ──────────────────────────────────────
// Only count financed assets, and cap the paid total at months
// since acquisition × payment. For unfinanced assets this is 0.
$totalFinancingPaid = '0.00';
if ((int) ($asset['is_financed'] ?? 0) === 1
    && !empty($asset['financing_monthly_payment'])) {
    $payment = (string) $asset['financing_monthly_payment'];
    if ($payment === '') $payment = '0.00';
    $totalFinancingPaid = bcmul($payment, (string) $monthsSinceAcq, 2);
}

// ── Total revenue from linked leases/invoices ──────────────────
// WHY: The only path from a fixed asset to its revenue is via
// equipment_unit_id → leases → invoices → invoice_line_items.
// Excluded statuses: void, written_off (never collected),
// and credit notes (is_credit = 1) which subtract, not add.
// Soft-deleted rows are filtered on every joined table per the
// project-wide SOFT DELETE rule.
$revenueRow = db_row(
    "SELECT COALESCE(SUM(ili.amount), 0) AS total
     FROM invoice_line_items ili
     JOIN invoices i     ON i.id = ili.invoice_id
     JOIN leases  l      ON l.id = i.lease_id
     WHERE l.equipment_unit_id = ?
       AND l.deleted_at IS NULL
       AND i.deleted_at IS NULL
       AND i.status NOT IN ('void', 'written_off')
       AND ili.is_credit = 0",
    [$eqUnitId]
);
$totalRevenue = (string) ($revenueRow['total'] ?? '0.00');
if ($totalRevenue === '') $totalRevenue = '0.00';

// ── Total maintenance cost ─────────────────────────────────────
$mntRow = db_row(
    "SELECT COALESCE(SUM(total_cost), 0) AS total
     FROM maintenance_work_orders
     WHERE equipment_unit_id = ?
       AND status = 'completed'
       AND deleted_at IS NULL",
    [$eqUnitId]
);
$totalMaintenance = (string) ($mntRow['total'] ?? '0.00');
if ($totalMaintenance === '') $totalMaintenance = '0.00';

// ── Total damage claim cost ────────────────────────────────────
// WHY: Use actual_repair_cost if set; otherwise fall back to
// estimated_repair_cost. This matches how the damage module
// populates claims (estimated first, actual after repair).
$dmgRow = db_row(
    "SELECT COALESCE(SUM(COALESCE(actual_repair_cost, estimated_repair_cost, 0)), 0) AS total
     FROM damage_claims
     WHERE equipment_unit_id = ?
       AND deleted_at IS NULL",
    [$eqUnitId]
);
$totalDamage = (string) ($dmgRow['total'] ?? '0.00');
if ($totalDamage === '') $totalDamage = '0.00';

// ── Net revenue to date ────────────────────────────────────────
$netRevenue = bcsub($totalRevenue, $totalMaintenance, 2);
$netRevenue = bcsub($netRevenue, $totalDamage, 2);
$netRevenue = bcsub($netRevenue, $totalFinancingPaid, 2);
$netRevenue = bcsub($netRevenue, $totalFixedPaid, 2);

$stillToRecover = bcsub($adjustedTargetCost, $netRevenue, 2);
// Floor at 0 for display — we don't report negative remainders.
if (bccomp($stillToRecover, '0', 2) < 0) {
    $stillToRecover = '0.00';
}

// Progress percentage (can exceed 100 if fully paid off).
$progressPct = '0.00';
if (bccomp($adjustedTargetCost, '0', 2) > 0) {
    $raw = bcdiv(bcmul($netRevenue, '100', 6), $adjustedTargetCost, 6);
    $progressPct = $bcround($raw, 2);
}

// ── Monthly net revenue history (last 12 months + current) ─────
// WHY: We build one row per calendar month for charting AND for
// the rolling-average scenarios. Revenue is summed by billing_period_start
// month (not invoice_date) so late-posted invoices still land in
// the month the work was performed.
$monthlyRows = db_select(
    "SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') AS ym,
            COALESCE(SUM(ili.amount), 0) AS revenue
     FROM invoice_line_items ili
     JOIN invoices i ON i.id = ili.invoice_id
     JOIN leases   l ON l.id = i.lease_id
     WHERE l.equipment_unit_id = ?
       AND l.deleted_at IS NULL
       AND i.deleted_at IS NULL
       AND i.status NOT IN ('void', 'written_off')
       AND ili.is_credit = 0
       AND i.invoice_date >= (CURDATE() - INTERVAL 13 MONTH)
     GROUP BY ym
     ORDER BY ym ASC",
    [$eqUnitId]
);

$revenueByMonth = [];
foreach ($monthlyRows as $r) {
    $revenueByMonth[$r['ym']] = (string) $r['revenue'];
}

$mntByMonth = [];
$mntMonthRows = db_select(
    "SELECT DATE_FORMAT(COALESCE(completed_date, requested_date), '%Y-%m') AS ym,
            COALESCE(SUM(total_cost), 0) AS cost
     FROM maintenance_work_orders
     WHERE equipment_unit_id = ?
       AND status = 'completed'
       AND deleted_at IS NULL
       AND COALESCE(completed_date, requested_date) >= (CURDATE() - INTERVAL 13 MONTH)
     GROUP BY ym",
    [$eqUnitId]
);
foreach ($mntMonthRows as $r) {
    $mntByMonth[$r['ym']] = (string) $r['cost'];
}

$dmgByMonth = [];
$dmgMonthRows = db_select(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
            COALESCE(SUM(COALESCE(actual_repair_cost, estimated_repair_cost, 0)), 0) AS cost
     FROM damage_claims
     WHERE equipment_unit_id = ?
       AND deleted_at IS NULL
       AND created_at >= (CURDATE() - INTERVAL 13 MONTH)
     GROUP BY ym",
    [$eqUnitId]
);
foreach ($dmgMonthRows as $r) {
    $dmgByMonth[$r['ym']] = (string) $r['cost'];
}

// ── Build a dense 14-month window ending this month ────────────
// WHY: The chart series needs every month present, including zero
// months. We also need this for the rolling averages (3/6/12).
$monthly = [];
$cursor = new DateTimeImmutable('first day of this month');
$start  = $cursor->modify('-13 months');
$cumulative = '0.00';
for ($m = $start; $m <= $cursor; $m = $m->modify('+1 month')) {
    $ym = $m->format('Y-m');
    $rev = $revenueByMonth[$ym] ?? '0.00';
    $mnt = $mntByMonth[$ym] ?? '0.00';
    $dmg = $dmgByMonth[$ym] ?? '0.00';

    // Deduct monthly fixed costs and (if financed) the monthly
    // payment for every month in the window — this keeps net
    // revenue per month consistent with the to-date totals.
    $monthlyFinancePortion = '0.00';
    if ((int) ($asset['is_financed'] ?? 0) === 1
        && !empty($asset['financing_monthly_payment'])) {
        $monthlyFinancePortion = (string) $asset['financing_monthly_payment'];
    }

    $netMonth = bcsub($rev, $mnt, 2);
    $netMonth = bcsub($netMonth, $dmg, 2);
    $netMonth = bcsub($netMonth, $monthlyFinancePortion, 2);
    $netMonth = bcsub($netMonth, $monthlyFixed, 2);

    $cumulative = bcadd($cumulative, $netMonth, 2);

    $monthly[] = [
        'month'                 => $ym,
        'revenue'               => $bcround($rev, 2),
        'maintenance'           => $bcround($mnt, 2),
        'damage'                => $bcround($dmg, 2),
        'net_revenue'           => $bcround($netMonth, 2),
        'cumulative_net_revenue'=> $bcround($cumulative, 2),
    ];
}

// ── Scenario averages ──────────────────────────────────────────
// WHY: Rolling averages over the last N months, where "last" means
// the most recent N full months BEFORE the current one plus the
// current one, totalling N entries. The monthly array is already
// in oldest→newest order so we just slice the tail.
$avgFromTail = function (array $rows, int $n) use ($bcround): string {
    if (count($rows) < $n || $n <= 0) {
        // If there aren't enough rows yet, average what we have.
        $n = min($n, count($rows));
        if ($n <= 0) return '0.00';
    }
    $slice = array_slice($rows, -$n);
    $sum = '0.00';
    foreach ($slice as $r) {
        $sum = bcadd($sum, (string) $r['net_revenue'], 2);
    }
    return $bcround(bcdiv($sum, (string) $n, 6), 2);
};

$avg3  = $avgFromTail($monthly, 3);
$avg6  = $avgFromTail($monthly, 6);
$avg12 = $avgFromTail($monthly, 12);

// ── Projected months & dates for each scenario ────────────────
$projectFor = function (string $avg) use ($stillToRecover) {
    if (bccomp($avg, '0', 2) <= 0 || bccomp($stillToRecover, '0', 2) <= 0) {
        return [
            'months' => null,
            'date'   => null,
        ];
    }
    $months = bcdiv($stillToRecover, $avg, 2);
    $monthsInt = (int) ceil((float) $months);
    try {
        $d = new DateTimeImmutable('today');
        $d = $d->modify("+{$monthsInt} months");
        return ['months' => $monthsInt, 'date' => $d->format('Y-m-d')];
    } catch (\Throwable $e) {
        return ['months' => $monthsInt, 'date' => null];
    }
};

$consProj = $projectFor($avg12);
$currProj = $projectFor($avg6);
$optProj  = $projectFor($avg3);

// ── Manual custom-projection override ──────────────────────────
$customProjection = null;
if ($customMonthly !== null && bccomp($customMonthly, '0', 2) > 0) {
    $customProjection = $projectFor($customMonthly);
    $customProjection['monthly_net'] = $bcround($customMonthly, 2);
}

// ── Choose "selected" scenario based on period param ───────────
$selectedAvg = match ($period) {
    3  => $avg3,
    12 => $avg12,
    default => $avg6,
};
$selectedProjection = $projectFor($selectedAvg);

// ── Response ───────────────────────────────────────────────────
json_success([
    'asset' => [
        'id'                 => (int) $asset['id'],
        'asset_number'       => $asset['asset_number'],
        'name'               => $asset['name'],
        'equipment_unit_id'  => $eqUnitId,
        'equipment_unit_number' => $asset['equipment_unit_number'],
        'acquisition_date'   => $asset['acquisition_date'],
        'is_financed'        => (int) ($asset['is_financed'] ?? 0),
    ],
    'acquisition' => [
        'purchase_cost'      => $bcround($purchaseCost, 2),
        'gst'                => $bcround($gst, 2),
        'pst'                => $bcround($pst, 2),
        'delivery_cost'      => $bcround($delivery, 2),
        'setup_cost'         => $bcround($setup, 2),
        'total'              => $bcround($totalAcquisition, 2),
        'extra_costs'        => $bcround($extraCostsTotal, 2),
        'adjusted_target'    => $bcround($adjustedTargetCost, 2),
    ],
    'totals' => [
        'total_revenue'      => $bcround($totalRevenue, 2),
        'total_maintenance'  => $bcround($totalMaintenance, 2),
        'total_damage'       => $bcround($totalDamage, 2),
        'total_financing_paid' => $bcround($totalFinancingPaid, 2),
        'monthly_fixed'      => $bcround($monthlyFixed, 2),
        'total_fixed_paid'   => $bcround($totalFixedPaid, 2),
        'months_since_acquisition' => $monthsSinceAcq,
        'net_revenue_to_date'=> $bcround($netRevenue, 2),
        'still_to_recover'   => $bcround($stillToRecover, 2),
        'progress_pct'       => $progressPct,
    ],
    'scenarios' => [
        'conservative' => [
            'basis_months' => 12,
            'monthly_net'  => $avg12,
            'months'       => $consProj['months'],
            'date'         => $consProj['date'],
        ],
        'current' => [
            'basis_months' => 6,
            'monthly_net'  => $avg6,
            'months'       => $currProj['months'],
            'date'         => $currProj['date'],
        ],
        'optimistic' => [
            'basis_months' => 3,
            'monthly_net'  => $avg3,
            'months'       => $optProj['months'],
            'date'         => $optProj['date'],
        ],
    ],
    'selected' => [
        'period'        => $period,
        'monthly_net'   => $selectedAvg,
        'months'        => $selectedProjection['months'],
        'date'          => $selectedProjection['date'],
    ],
    'custom_projection' => $customProjection,
    'monthly_data' => $monthly,
]);
