<?php
declare(strict_types=1);

/**
 * app/admin/equipment/payoff.php
 *
 * Equipment Unit — Full Payoff Analysis page.
 *
 * A dedicated deep-dive page for a single equipment unit's complete
 * financial picture: acquisition cost breakdown, financing terms,
 * fixed operating costs, depreciation, every revenue-generating lease
 * with contribution, per-month P&L, full maintenance work-order list,
 * damage claim history, and utilisation statistics.
 *
 * The payoff API (api/v1/accounting/fixed_assets/payoff.php) is used for
 * the live scenario cards + chart (period switching via Alpine.js).
 * All other data is server-rendered directly from the DB on page load.
 *
 * Permissions: equipment:view + fixed_assets:view
 * Route:       /equipment/payoff?id={unit_id}
 *
 * @session S-EQUIP-PAYOFF-DETAIL
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('equipment', 'view');
require_permission('fixed_assets', 'view');

$unitId = clean_int($_GET['id'] ?? null);
if (!$unitId) {
    header('Location: ' . base_url('equipment'));
    exit;
}

// ── Unit ──────────────────────────────────────────────────────────────────────
$unit = db_row(
    "SELECT u.id, u.unit_number, u.status, u.year, u.health_score,
            u.acquired_date, u.acquisition_cost AS unit_acquisition_cost,
            u.mileage, u.vin, u.ownership_type,
            u.cvi_expiry, u.registration_expiry, u.mvi_expiry, u.insurance_expiry,
            u.samsara_odometer_km, u.samsara_last_synced_at, u.deleted_at,
            t.name AS template_name, t.category AS template_category
       FROM equipment_units u
       JOIN equipment_templates t ON t.id = u.template_id
      WHERE u.id = ? AND u.deleted_at IS NULL",
    [$unitId]
);

if (!$unit) {
    header('Location: ' . base_url('equipment'));
    exit;
}

// ── Linked fixed asset (excludes disposed — they have been written off) ───────
$asset = db_row(
    "SELECT a.*,
            a.monthly_insurance_cost, a.monthly_licensing_cost, a.monthly_registration_cost,
            a.financing_monthly_payment, a.financing_interest_rate, a.financing_remaining_months,
            a.is_financed,
            a.net_book_value, a.accumulated_depreciation, a.depreciable_cost,
            a.depreciation_method, a.useful_life_years, a.salvage_value,
            a.last_depreciation_date, a.fully_depreciated_date
       FROM acc_fixed_assets a
      WHERE a.equipment_unit_id = ? AND a.status != 'disposed'
      ORDER BY a.acquisition_date DESC, a.id DESC
      LIMIT 1",
    [$unitId]
);

// ── Payoff math (inline — mirrors payoff.php logic) ──────────────────────────
// WHY: We need all totals server-side for the hero stats on first paint.
// The Alpine component reloads scenarios on period change via the payoff API.
$payoffData = null;
if ($asset) {
    $assetId   = (int) $asset['id'];
    $eqUnitId  = $unitId;

    $bcround = static fn(string $val, int $sc = 2): string =>
        (!is_numeric($val) || $val === '') ? '0.00'
        : bcsub(bcadd($val, '0.' . str_repeat('0', $sc) . '5', $sc + 1), '0', $sc);

    $n = fn($v) => (string)($v ?? '0.00') === '' ? '0.00' : (string)($v ?? '0.00');

    $purchaseCost = $n($asset['acquisition_cost']);
    $gst          = $n($asset['purchase_tax_gst']);
    $pst          = $n($asset['purchase_tax_pst']);
    $delivery     = $n($asset['delivery_cost']);
    $setup        = $n($asset['setup_cost']);
    $totalAcq     = bcadd(bcadd(bcadd(bcadd($purchaseCost, $gst, 2), $pst, 2), $delivery, 2), $setup, 2);

    // Months since acquisition
    $monthsSince = 1;
    if (!empty($asset['acquisition_date'])) {
        try {
            $s = new DateTimeImmutable($asset['acquisition_date']);
            $e = new DateTimeImmutable('today');
            if ($e > $s) {
                $d = $s->diff($e);
                $monthsSince = ($d->y * 12) + $d->m + ($d->d > 0 ? 1 : 0);
            }
        } catch (\Throwable) {}
    }
    if ($monthsSince < 1) $monthsSince = 1;

    $ins   = $n($asset['monthly_insurance_cost']);
    $lic   = $n($asset['monthly_licensing_cost']);
    $reg   = $n($asset['monthly_registration_cost']);
    $monthlyFixed    = bcadd(bcadd($ins, $lic, 2), $reg, 2);
    $totalFixedPaid  = bcmul($monthlyFixed, (string) $monthsSince, 2);

    $totalFinancingPaid = '0.00';
    if ((int)($asset['is_financed'] ?? 0) === 1 && !empty($asset['financing_monthly_payment'])) {
        $totalFinancingPaid = bcmul($n($asset['financing_monthly_payment']), (string) $monthsSince, 2);
    }

    $revRow = db_row(
        "SELECT COALESCE(SUM(ili.amount),0) AS t
           FROM invoice_line_items ili
           JOIN invoices i ON i.id = ili.invoice_id
           JOIN leases  l ON l.id = i.lease_id
          WHERE l.equipment_unit_id = ? AND l.deleted_at IS NULL
            AND i.deleted_at IS NULL AND i.status NOT IN ('void','written_off')
            AND ili.is_credit = 0", [$eqUnitId]);
    $totalRevenue = $n($revRow['t']);

    $mntRow = db_row(
        "SELECT COALESCE(SUM(total_cost),0) AS t FROM maintenance_work_orders
          WHERE equipment_unit_id = ? AND status='completed' AND deleted_at IS NULL", [$eqUnitId]);
    $totalMaint = $n($mntRow['t']);

    $dmgRow = db_row(
        "SELECT COALESCE(SUM(COALESCE(actual_repair_cost,estimated_repair_cost,0)),0) AS t
           FROM damage_claims WHERE equipment_unit_id = ? AND deleted_at IS NULL", [$eqUnitId]);
    $totalDamage = $n($dmgRow['t']);

    $netRevenue     = bcsub(bcsub(bcsub(bcsub($totalRevenue, $totalMaint, 2), $totalDamage, 2), $totalFinancingPaid, 2), $totalFixedPaid, 2);
    $stillToRecover = bcsub($totalAcq, $netRevenue, 2);
    if (bccomp($stillToRecover, '0', 2) < 0) $stillToRecover = '0.00';
    $progressPct = bccomp($totalAcq, '0', 2) > 0
        ? $bcround(bcdiv(bcmul($netRevenue, '100', 6), $totalAcq, 6))
        : '0.00';

    $payoffData = compact(
        'totalAcq','purchaseCost','gst','pst','delivery','setup',
        'monthsSince','monthlyFixed','totalFixedPaid','ins','lic','reg',
        'totalFinancingPaid','totalRevenue','totalMaint','totalDamage',
        'netRevenue','stillToRecover','progressPct'
    );
}

// ── Revenue by lease ─────────────────────────────────────────────────────────
// WHY: One query with GROUP BY so we get each lease's total contribution
// without N+1 invoice queries.
$revenueByLease = db_select(
    "SELECT l.id, l.contract_number, l.start_date, l.end_date, l.status,
            c.company_name AS customer_name,
            COALESCE(SUM(ili.amount), 0) AS revenue
       FROM leases l
       JOIN customers c ON c.id = l.customer_id
       LEFT JOIN invoices i   ON i.lease_id = l.id AND i.deleted_at IS NULL
                              AND i.status NOT IN ('void','written_off')
       LEFT JOIN invoice_line_items ili ON ili.invoice_id = i.id AND ili.is_credit = 0
      WHERE l.equipment_unit_id = ? AND l.deleted_at IS NULL
      GROUP BY l.id
      ORDER BY revenue DESC, l.start_date DESC",
    [$unitId]
);

// ── Monthly P&L (last 24 months) ──────────────────────────────────────────────
// Revenue by month
$revByMonth = [];
foreach (db_select(
    "SELECT DATE_FORMAT(i.invoice_date,'%Y-%m') AS ym,
            COALESCE(SUM(ili.amount),0) AS rev
       FROM invoice_line_items ili
       JOIN invoices i ON i.id = ili.invoice_id
       JOIN leases   l ON l.id = i.lease_id
      WHERE l.equipment_unit_id = ? AND l.deleted_at IS NULL
        AND i.deleted_at IS NULL AND i.status NOT IN ('void','written_off')
        AND ili.is_credit = 0
        AND i.invoice_date >= (CURDATE() - INTERVAL 24 MONTH)
      GROUP BY ym ORDER BY ym DESC", [$unitId]
) as $r) { $revByMonth[$r['ym']] = (string) $r['rev']; }

// Maintenance by month
$mntByMonth = [];
foreach (db_select(
    "SELECT DATE_FORMAT(COALESCE(completed_date,requested_date),'%Y-%m') AS ym,
            COALESCE(SUM(total_cost),0) AS cost
       FROM maintenance_work_orders
      WHERE equipment_unit_id = ? AND status = 'completed' AND deleted_at IS NULL
        AND COALESCE(completed_date,requested_date) >= (CURDATE() - INTERVAL 24 MONTH)
      GROUP BY ym", [$unitId]
) as $r) { $mntByMonth[$r['ym']] = (string) $r['cost']; }

// Damage by month
$dmgByMonth = [];
foreach (db_select(
    "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym,
            COALESCE(SUM(COALESCE(actual_repair_cost,estimated_repair_cost,0)),0) AS cost
       FROM damage_claims WHERE equipment_unit_id = ? AND deleted_at IS NULL
        AND created_at >= (CURDATE() - INTERVAL 24 MONTH)
      GROUP BY ym", [$unitId]
) as $r) { $dmgByMonth[$r['ym']] = (string) $r['cost']; }

// Dense 24-month window (newest first for the table)
$monthlyPnl = [];
$cursor = new DateTimeImmutable('first day of this month');
$mStart = $cursor->modify('-23 months');
for ($m = $cursor; $m >= $mStart; $m = $m->modify('-1 month')) {
    $ym  = $m->format('Y-m');
    $rev = $revByMonth[$ym]  ?? '0.00';
    $mnt = $mntByMonth[$ym]  ?? '0.00';
    $dmg = $dmgByMonth[$ym]  ?? '0.00';
    $net = bcsub(bcsub($rev, $mnt, 2), $dmg, 2);
    $monthlyPnl[] = ['ym' => $ym, 'rev' => $rev, 'mnt' => $mnt, 'dmg' => $dmg, 'net' => $net];
}

// WHY: The table only renders months that have activity. Pre-filter here so the
// paginator below has an accurate row count and stable zero-based indices.
$monthlyPnlRows = array_values(array_filter($monthlyPnl, static function (array $row): bool {
    return bccomp($row['rev'], '0', 2) !== 0
        || bccomp($row['mnt'], '0', 2) !== 0
        || bccomp($row['dmg'], '0', 2) !== 0;
}));

// ── Maintenance work orders ───────────────────────────────────────────────────
$workOrders = db_select(
    "SELECT wo.id, wo.work_order_number, wo.work_type, wo.description,
            wo.status, wo.labor_cost, wo.parts_cost, wo.total_cost,
            wo.requested_date, wo.completed_date,
            v.name AS vendor_name
       FROM maintenance_work_orders wo
       LEFT JOIN vendors v ON v.id = wo.vendor_id AND v.deleted_at IS NULL
      WHERE wo.equipment_unit_id = ? AND wo.deleted_at IS NULL
      ORDER BY COALESCE(wo.completed_date, wo.requested_date) DESC
      LIMIT 100",
    [$unitId]
);

$woTotal     = array_reduce($workOrders, fn($c, $r) => bcadd($c, (string)($r['total_cost'] ?? '0'), 2), '0.00');
$woCompleted = array_filter($workOrders, fn($r) => $r['status'] === 'completed');
$woCompletedTotal = array_reduce($woCompleted, fn($c, $r) => bcadd($c, (string)($r['total_cost'] ?? '0'), 2), '0.00');

// ── Damage claims ─────────────────────────────────────────────────────────────
$damageClaims = db_select(
    "SELECT dc.id, dc.claim_number, dc.severity, dc.status,
            dc.estimated_repair_cost, dc.actual_repair_cost,
            dc.customer_liable_amount, dc.insurance_claim_amount,
            dc.created_at, dc.updated_at,
            c.company_name AS customer_name,
            l.contract_number AS lease_number
       FROM damage_claims dc
       LEFT JOIN customers c ON c.id = dc.customer_id AND c.deleted_at IS NULL
       LEFT JOIN leases   l ON l.id = dc.lease_id AND l.deleted_at IS NULL
      WHERE dc.equipment_unit_id = ? AND dc.deleted_at IS NULL
      ORDER BY dc.created_at DESC
      LIMIT 100",
    [$unitId]
);
$dmgTotal = array_reduce($damageClaims, fn($c, $r) =>
    bcadd($c, (string)(($r['actual_repair_cost'] ?? null) !== null ? $r['actual_repair_cost'] : ($r['estimated_repair_cost'] ?? '0')), 2),
    '0.00'
);

// ── Utilisation analysis ──────────────────────────────────────────────────────
// Days on lease = SUM(DATEDIFF(end_date, start_date)+1) for completed+active leases.
// Denominator = days since acquired_date (or asset acquisition_date).
$utilRow = db_row(
    "SELECT COUNT(*) AS lease_count,
            COALESCE(SUM(DATEDIFF(LEAST(COALESCE(end_date, CURDATE()), CURDATE()), start_date) + 1), 0) AS days_on_lease,
            COALESCE(MIN(start_date), NULL) AS first_lease_start,
            COALESCE(MAX(COALESCE(end_date, CURDATE())), NULL) AS last_lease_end
       FROM leases
      WHERE equipment_unit_id = ? AND status != 'cancelled' AND deleted_at IS NULL",
    [$unitId]
);

$acquiredDate = $asset['acquisition_date'] ?? $unit['acquired_date'] ?? null;
$daysSinceAcq = 0;
if ($acquiredDate) {
    try {
        $daysSinceAcq = max(1, (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($acquiredDate))->days);
    } catch (\Throwable) {}
}
$daysOnLease  = max(0, (int)($utilRow['days_on_lease'] ?? 0));
$daysOnLease  = min($daysOnLease, $daysSinceAcq); // cap
$utilPct      = $daysSinceAcq > 0 ? round(($daysOnLease / $daysSinceAcq) * 100, 1) : 0;
$leaseCount   = (int)($utilRow['lease_count'] ?? 0);
$avgLeaseDays = $leaseCount > 0 ? round($daysOnLease / $leaseCount) : 0;

$pageTitle      = 'Payoff Analysis — ' . $unit['unit_number'];
$helpModuleSlug = 'equipment';
require_once FF_ROOT . '/includes/header.php';

// ── Local helpers ─────────────────────────────────────────────────────────────
function pa_money(string $val): string {
    return '$' . number_format((float) $val, 2);
}
function pa_work_type_label(string $t): string {
    return match ($t) {
        'scheduled_service' => 'Scheduled Service',
        'repair'            => 'Repair',
        'inspection'        => 'Inspection',
        'tire'              => 'Tires',
        'electrical'        => 'Electrical',
        'body_damage'       => 'Body Damage',
        'breakdown'         => 'Breakdown',
        default             => ucfirst(str_replace('_', ' ', $t)),
    };
}
function pa_severity_badge(string $s): string {
    return match ($s) {
        'minor'      => 'badge-info',
        'moderate'   => 'badge-warning',
        'major'      => 'badge-danger',
        'total_loss' => 'badge-danger',
        default      => 'badge-neutral',
    };
}
function pa_status_badge_wo(string $s): string {
    return match ($s) {
        'completed'     => 'badge-success',
        'in_progress'   => 'badge-info',
        'waiting_parts' => 'badge-warning',
        'open'          => 'badge-neutral',
        'cancelled'     => 'badge-neutral',
        default         => 'badge-neutral',
    };
}
function pa_lease_badge(string $s): string {
    return match ($s) {
        'active'    => 'badge-success',
        'completed' => 'badge-neutral',
        'pending'   => 'badge-info',
        'cancelled' => 'badge-neutral',
        default     => 'badge-neutral',
    };
}
?>

<!-- breadcrumb -->
<nav class="breadcrumb">
    <a href="<?= e(base_url('equipment')) ?>">Equipment</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= e(base_url('equipment/show?id=' . $unitId)) ?>"><?= e($unit['unit_number']) ?></a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Payoff Analysis</span>
</nav>

<!-- ── HERO ──────────────────────────────────────────────────────────────────── -->
<div class="card" style="padding:20px 24px;margin-bottom:20px;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:16px;">
        <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);font-weight:600;margin-bottom:4px;">
                <?= e($unit['template_category']) ?> &middot; <?= e($unit['template_name']) ?>
            </div>
            <h1 class="font-mono" style="font-size:1.75rem;font-weight:700;margin:0 0 6px;"><?= e($unit['unit_number']) ?></h1>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span class="badge <?= e(unit_status_badge_class($unit['status'])) ?>"><?= e(ucfirst(str_replace('_', ' ', $unit['status']))) ?></span>
                <?php if ($unit['year']): ?>
                <span class="text-secondary text-sm"><?= e((string) $unit['year']) ?></span>
                <?php endif; ?>
                <?php if ($acquiredDate): ?>
                <span class="text-secondary text-sm">Acquired <?= e(format_date($acquiredDate)) ?></span>
                <?php endif; ?>
                <?php if ($asset): ?>
                <span class="text-secondary text-sm">&middot; Asset <span class="font-mono"><?= e($asset['asset_number']) ?></span></span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <a href="<?= e(base_url('equipment/show?id=' . $unitId . '#payoff')) ?>" class="btn btn-secondary btn-sm">← Back to Unit</a>
            <?php if ($asset): ?>
            <a href="<?= e(base_url('accounting/fixed-assets?asset=' . (int)$asset['id'])) ?>" class="btn btn-secondary btn-sm">Open in Fixed Assets</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($payoffData): ?>
    <!-- KPI strip -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-top:20px;padding-top:20px;border-top:1px solid var(--border-color);">
        <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:4px;">Total Invested</div>
            <div class="font-mono" style="font-size:1.375rem;font-weight:700;"><?= e(pa_money($payoffData['totalAcq'])) ?></div>
        </div>
        <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:4px;">Gross Revenue</div>
            <div class="font-mono" style="font-size:1.375rem;font-weight:700;color:var(--color-success);"><?= e(pa_money($payoffData['totalRevenue'])) ?></div>
        </div>
        <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:4px;">Net Revenue</div>
            <div class="font-mono" style="font-size:1.375rem;font-weight:700;color:<?= bccomp($payoffData['netRevenue'], '0', 2) >= 0 ? 'var(--color-success)' : 'var(--color-danger)' ?>;"><?= e(pa_money($payoffData['netRevenue'])) ?></div>
        </div>
        <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:4px;">Still to Recover</div>
            <div class="font-mono" style="font-size:1.375rem;font-weight:700;color:<?= bccomp($payoffData['stillToRecover'], '0', 2) > 0 ? 'var(--color-warning)' : 'var(--color-success)' ?>;"><?= e(pa_money($payoffData['stillToRecover'])) ?></div>
        </div>
        <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:4px;">Progress</div>
            <div class="font-mono" style="font-size:1.375rem;font-weight:700;"><?= e(max('0.00', $payoffData['progressPct'])) ?>%</div>
        </div>
        <div>
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:4px;">Months Active</div>
            <div class="font-mono" style="font-size:1.375rem;font-weight:700;"><?= e((string) $payoffData['monthsSince']) ?></div>
        </div>
    </div>

    <!-- Progress bar -->
    <?php
    $pct = max(0, min(100, (float) $payoffData['progressPct']));
    $barColor = $pct >= 100 ? 'var(--color-success)' : ($pct >= 60 ? 'var(--color-warning)' : 'var(--color-primary)');
    ?>
    <div style="margin-top:16px;">
        <div style="display:flex;justify-content:space-between;font-size:0.8125rem;margin-bottom:6px;">
            <span style="color:var(--text-secondary);font-weight:600;">Payoff progress</span>
            <span class="font-mono"><?= e(max('0.00', $payoffData['progressPct'])) ?>%</span>
        </div>
        <div style="width:100%;height:12px;background:var(--bg-secondary);border-radius:6px;overflow:hidden;">
            <div style="width:<?= e(number_format($pct, 2)) ?>%;height:100%;background:<?= $barColor ?>;border-radius:6px;transition:width 0.6s;"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:0.75rem;color:var(--text-muted);margin-top:4px;">
            <span>$0</span>
            <span class="font-mono"><?= e(pa_money($payoffData['totalAcq'])) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$asset): ?>
    <div style="margin-top:16px;padding:16px;background:var(--bg-secondary);border-radius:8px;text-align:center;">
        <span style="font-size:0.875rem;color:var(--text-secondary);">No linked fixed asset — <a href="<?= e(base_url('accounting/fixed-assets')) ?>" class="link">link one in Fixed Assets</a> to enable payoff analysis.</span>
    </div>
    <?php endif; ?>
</div>

<?php if ($asset && $payoffData): ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION 1 — Projection scenarios + chart (Alpine — live period switch)
     ══════════════════════════════════════════════════════════════════════════ -->
<div x-data="payoffPage(<?= (int)$assetId ?>)" x-init="load(6)">

    <!-- Scenario cards -->
    <div style="margin-bottom:20px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
            <h2 style="margin:0;font-size:1rem;font-weight:700;">Payoff Projections</h2>
            <div style="font-size:0.8125rem;color:var(--text-secondary);">Click a scenario to update the chart</div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">

            <!-- Conservative — 12 mo avg -->
            <div class="card" @click="load(12)"
                 :style="period===12 ? 'border:2px solid var(--color-primary);cursor:pointer;padding:16px;' : 'border:1px solid var(--border-color);cursor:pointer;padding:16px;'">
                <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);font-weight:700;">Conservative</div>
                <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:10px;">12-month rolling avg</div>
                <div class="font-mono" style="font-size:1.125rem;font-weight:700;" x-text="p ? '$'+fmt(p.scenarios.conservative.monthly_net)+'/mo' : '—'"></div>
                <div style="font-size:0.8125rem;margin-top:6px;">
                    <template x-if="p && p.scenarios.conservative.months">
                        <span>Paid off in <strong x-text="p.scenarios.conservative.months"></strong> mo</span>
                    </template>
                    <template x-if="p && !p.scenarios.conservative.months">
                        <span style="color:var(--text-muted);">— insufficient data —</span>
                    </template>
                    <template x-if="!p"><span style="color:var(--text-muted);">Loading…</span></template>
                </div>
                <template x-if="p && p.scenarios.conservative.date">
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;" x-text="'by '+p.scenarios.conservative.date"></div>
                </template>
            </div>

            <!-- Current — 6 mo avg -->
            <div class="card" @click="load(6)"
                 :style="period===6 ? 'border:2px solid var(--color-primary);cursor:pointer;padding:16px;' : 'border:1px solid var(--border-color);cursor:pointer;padding:16px;'">
                <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);font-weight:700;">Current</div>
                <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:10px;">6-month rolling avg</div>
                <div class="font-mono" style="font-size:1.125rem;font-weight:700;" x-text="p ? '$'+fmt(p.scenarios.current.monthly_net)+'/mo' : '—'"></div>
                <div style="font-size:0.8125rem;margin-top:6px;">
                    <template x-if="p && p.scenarios.current.months">
                        <span>Paid off in <strong x-text="p.scenarios.current.months"></strong> mo</span>
                    </template>
                    <template x-if="p && !p.scenarios.current.months">
                        <span style="color:var(--text-muted);">— insufficient data —</span>
                    </template>
                    <template x-if="!p"><span style="color:var(--text-muted);">Loading…</span></template>
                </div>
                <template x-if="p && p.scenarios.current.date">
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;" x-text="'by '+p.scenarios.current.date"></div>
                </template>
            </div>

            <!-- Optimistic — 3 mo avg -->
            <div class="card" @click="load(3)"
                 :style="period===3 ? 'border:2px solid var(--color-primary);cursor:pointer;padding:16px;' : 'border:1px solid var(--border-color);cursor:pointer;padding:16px;'">
                <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);font-weight:700;">Optimistic</div>
                <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:10px;">3-month rolling avg</div>
                <div class="font-mono" style="font-size:1.125rem;font-weight:700;" x-text="p ? '$'+fmt(p.scenarios.optimistic.monthly_net)+'/mo' : '—'"></div>
                <div style="font-size:0.8125rem;margin-top:6px;">
                    <template x-if="p && p.scenarios.optimistic.months">
                        <span>Paid off in <strong x-text="p.scenarios.optimistic.months"></strong> mo</span>
                    </template>
                    <template x-if="p && !p.scenarios.optimistic.months">
                        <span style="color:var(--text-muted);">— insufficient data —</span>
                    </template>
                    <template x-if="!p"><span style="color:var(--text-muted);">Loading…</span></template>
                </div>
                <template x-if="p && p.scenarios.optimistic.date">
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;" x-text="'by '+p.scenarios.optimistic.date"></div>
                </template>
            </div>

            <!-- Custom override card -->
            <div class="card" style="padding:16px;border:1px dashed var(--border-color);">
                <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);font-weight:700;margin-bottom:10px;">Custom Projection</div>
                <label style="font-size:0.75rem;color:var(--text-secondary);display:block;margin-bottom:4px;">Monthly Net Revenue ($)</label>
                <div style="display:flex;gap:8px;margin-bottom:10px;">
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm"
                           x-model="customMonthly" placeholder="e.g. 5000" style="flex:1;">
                    <button class="btn btn-primary btn-sm" @click="loadCustom()">Go</button>
                </div>
                <template x-if="custom">
                    <div>
                        <div class="font-mono" style="font-size:1rem;font-weight:700;color:var(--color-primary);">
                            <span x-text="'$'+fmt(custom.monthly_net)+'/mo'"></span>
                        </div>
                        <template x-if="custom.months">
                            <div style="font-size:0.8125rem;margin-top:4px;">Paid off in <strong x-text="custom.months"></strong> months <span style="color:var(--text-muted);" x-text="custom.date ? '(by '+custom.date+')' : ''"></span></div>
                        </template>
                        <template x-if="!custom.months">
                            <div style="font-size:0.8125rem;color:var(--text-muted);margin-top:4px;">Enter a positive monthly revenue to project</div>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- Chart: Cumulative net revenue vs target -->
    <div class="card" style="padding:16px;margin-bottom:20px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;flex-wrap:wrap;gap:8px;">
            <h2 style="margin:0;font-size:1rem;font-weight:700;">Cumulative Net Revenue vs Target</h2>
            <span style="font-size:0.75rem;color:var(--text-secondary);">Last 14 months · after maintenance, damage, financing &amp; fixed costs</span>
        </div>
        <div id="pa-cumulative-chart" style="min-height:300px;"></div>
    </div>

    <!-- Chart: Monthly breakdown bar -->
    <div class="card" style="padding:16px;margin-bottom:20px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;flex-wrap:wrap;gap:8px;">
            <h2 style="margin:0;font-size:1rem;font-weight:700;">Monthly Revenue &amp; Costs</h2>
            <span style="font-size:0.75rem;color:var(--text-secondary);">Gross revenue vs maintenance + damage per month</span>
        </div>
        <div id="pa-monthly-chart" style="min-height:280px;"></div>
    </div>

</div><!-- /x-data payoffPage -->

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION 2 — Cost Structure (server-rendered)
     ══════════════════════════════════════════════════════════════════════════ -->
<h2 style="font-size:1rem;font-weight:700;margin:0 0 12px;">Cost Structure</h2>
<div class="cost-structure-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:24px;">

    <!-- Acquisition cost -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="padding:12px 16px;">
            <div class="card-title">Acquisition Cost</div>
        </div>
        <div class="card-body" style="padding:0;">
            <table class="spec-table">
                <tr><td class="spec-label">Purchase Cost</td><td class="font-mono text-right"><?= e(pa_money($payoffData['purchaseCost'])) ?></td></tr>
                <?php if (bccomp($payoffData['gst'], '0', 2) > 0): ?>
                <tr><td class="spec-label">+ GST</td><td class="font-mono text-right"><?= e(pa_money($payoffData['gst'])) ?></td></tr>
                <?php endif; ?>
                <?php if (bccomp($payoffData['pst'], '0', 2) > 0): ?>
                <tr><td class="spec-label">+ PST</td><td class="font-mono text-right"><?= e(pa_money($payoffData['pst'])) ?></td></tr>
                <?php endif; ?>
                <?php if (bccomp($payoffData['delivery'], '0', 2) > 0): ?>
                <tr><td class="spec-label">+ Delivery</td><td class="font-mono text-right"><?= e(pa_money($payoffData['delivery'])) ?></td></tr>
                <?php endif; ?>
                <?php if (bccomp($payoffData['setup'], '0', 2) > 0): ?>
                <tr><td class="spec-label">+ Setup</td><td class="font-mono text-right"><?= e(pa_money($payoffData['setup'])) ?></td></tr>
                <?php endif; ?>
                <tr style="font-weight:700;background:var(--bg-secondary);">
                    <td class="spec-label">Total Invested</td>
                    <td class="font-mono text-right"><?= e(pa_money($payoffData['totalAcq'])) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Fixed operating costs -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="padding:12px 16px;">
            <div class="card-title">Fixed Operating Costs</div>
        </div>
        <div class="card-body" style="padding:0;">
            <table class="spec-table">
                <?php if (bccomp($payoffData['ins'], '0', 2) > 0): ?>
                <tr><td class="spec-label">Insurance / mo</td><td class="font-mono text-right"><?= e(pa_money($payoffData['ins'])) ?></td></tr>
                <?php endif; ?>
                <?php if (bccomp($payoffData['lic'], '0', 2) > 0): ?>
                <tr><td class="spec-label">Licensing / mo</td><td class="font-mono text-right"><?= e(pa_money($payoffData['lic'])) ?></td></tr>
                <?php endif; ?>
                <?php if (bccomp($payoffData['reg'], '0', 2) > 0): ?>
                <tr><td class="spec-label">Registration / mo</td><td class="font-mono text-right"><?= e(pa_money($payoffData['reg'])) ?></td></tr>
                <?php endif; ?>
                <tr style="border-top:2px solid var(--border-color);">
                    <td class="spec-label">Monthly Total</td>
                    <td class="font-mono text-right font-weight-600"><?= e(pa_money($payoffData['monthlyFixed'])) ?> /mo</td>
                </tr>
                <tr style="font-weight:700;background:var(--bg-secondary);">
                    <td class="spec-label">Total Paid (<?= $payoffData['monthsSince'] ?> mo)</td>
                    <td class="font-mono text-right text-danger"><?= e(pa_money($payoffData['totalFixedPaid'])) ?></td>
                </tr>
            </table>
            <?php if (bccomp($payoffData['monthlyFixed'], '0', 2) === 0): ?>
            <div style="padding:12px 16px;font-size:0.8125rem;color:var(--text-muted);">No fixed operating costs recorded. <a href="<?= e(base_url('accounting/fixed-assets?asset=' . (int)$asset['id'])) ?>" class="link">Edit asset</a> to add insurance, licensing and registration.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Financing (conditional) -->
    <?php if ((int)($asset['is_financed'] ?? 0) === 1): ?>
    <?php
    $finPmt  = (string)($asset['financing_monthly_payment'] ?? '0.00');
    $finRate = (string)($asset['financing_interest_rate'] ?? '0.00');
    $finRem  = (int)($asset['financing_remaining_months'] ?? 0);
    $finPaid = $payoffData['totalFinancingPaid'];
    $finRemTotal = bcmul($finPmt, (string) $finRem, 2);
    ?>
    <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="padding:12px 16px;display:flex;align-items:center;gap:8px;">
            <div class="card-title">Financing</div>
            <span class="badge badge-info">Financed</span>
        </div>
        <div class="card-body" style="padding:0;">
            <table class="spec-table">
                <tr><td class="spec-label">Monthly Payment</td><td class="font-mono text-right"><?= e(pa_money($finPmt)) ?> /mo</td></tr>
                <?php if (bccomp($finRate, '0', 4) > 0): ?>
                <tr><td class="spec-label">Interest Rate</td><td class="font-mono text-right"><?= e(number_format((float)$finRate, 2)) ?>%</td></tr>
                <?php endif; ?>
                <tr><td class="spec-label">Remaining Months</td><td class="font-mono text-right"><?= e((string) $finRem) ?></td></tr>
                <tr><td class="spec-label">Remaining Balance</td><td class="font-mono text-right text-danger"><?= e(pa_money($finRemTotal)) ?></td></tr>
                <tr style="font-weight:700;background:var(--bg-secondary);">
                    <td class="spec-label">Paid to Date</td>
                    <td class="font-mono text-right text-danger"><?= e(pa_money($finPaid)) ?></td>
                </tr>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Depreciation -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="padding:12px 16px;">
            <div class="card-title">Depreciation</div>
        </div>
        <div class="card-body" style="padding:0;">
            <table class="spec-table">
                <?php if (!empty($asset['depreciation_method'])): ?>
                <tr><td class="spec-label">Method</td><td class="text-right"><?= e(ucfirst(str_replace('_', ' ', $asset['depreciation_method']))) ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($asset['useful_life_years'])): ?>
                <tr><td class="spec-label">Useful Life</td><td class="font-mono text-right"><?= e((string)(int)$asset['useful_life_years']) ?> years</td></tr>
                <?php endif; ?>
                <?php if (!empty($asset['salvage_value'])): ?>
                <tr><td class="spec-label">Salvage Value</td><td class="font-mono text-right"><?= e(pa_money((string)$asset['salvage_value'])) ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($asset['depreciable_cost'])): ?>
                <tr><td class="spec-label">Depreciable Cost</td><td class="font-mono text-right"><?= e(pa_money((string)$asset['depreciable_cost'])) ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($asset['accumulated_depreciation'])): ?>
                <tr><td class="spec-label">Accumulated Depr.</td><td class="font-mono text-right text-danger"><?= e(pa_money((string)$asset['accumulated_depreciation'])) ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($asset['net_book_value'])): ?>
                <tr style="font-weight:700;background:var(--bg-secondary);">
                    <td class="spec-label">Net Book Value</td>
                    <td class="font-mono text-right"><?= e(pa_money((string)$asset['net_book_value'])) ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($asset['last_depreciation_date'])): ?>
                <tr><td class="spec-label">Last Depr. Run</td><td class="font-mono text-right text-sm"><?= e(format_date($asset['last_depreciation_date'])) ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($asset['fully_depreciated_date'])): ?>
                <tr><td class="spec-label">Fully Depr. By</td><td class="font-mono text-right text-sm"><?= e(format_date($asset['fully_depreciated_date'])) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- Earnings & Expenses Summary (sits next to Depreciation in the grid) -->
    <div class="card earnings-summary" style="margin-bottom:0;">
        <div class="card-header" style="padding:12px 16px;">
            <div class="card-title">Earnings &amp; Expenses Summary</div>
        </div>
        <div class="card-body" style="padding:0;">
            <table class="spec-table">
                <tr><td class="spec-label">Gross Revenue</td><td class="font-mono text-right text-success" style="font-weight:600;"><?= e(pa_money($payoffData['totalRevenue'])) ?></td></tr>
                <tr><td class="spec-label">− Maintenance Costs</td><td class="font-mono text-right text-danger">−<?= e(pa_money($payoffData['totalMaint'])) ?></td></tr>
                <tr><td class="spec-label">− Damage Claims</td><td class="font-mono text-right text-danger">−<?= e(pa_money($payoffData['totalDamage'])) ?></td></tr>
                <tr><td class="spec-label">− Financing Paid</td><td class="font-mono text-right text-danger">−<?= e(pa_money($payoffData['totalFinancingPaid'])) ?></td></tr>
                <tr><td class="spec-label">− Fixed Costs Paid (<?= $payoffData['monthsSince'] ?> mo @ <?= e(pa_money($payoffData['monthlyFixed'])) ?>)</td><td class="font-mono text-right text-danger">−<?= e(pa_money($payoffData['totalFixedPaid'])) ?></td></tr>
                <tr style="font-weight:700;background:var(--bg-secondary);border-top:2px solid var(--border-color);">
                    <td class="spec-label">= Net Revenue to Date</td>
                    <td class="font-mono text-right" style="color:<?= bccomp($payoffData['netRevenue'], '0', 2) >= 0 ? 'var(--color-success)' : 'var(--color-danger)' ?>;"><?= e(pa_money($payoffData['netRevenue'])) ?></td>
                </tr>
                <tr style="font-weight:700;">
                    <td class="spec-label">Still to Recover</td>
                    <td class="font-mono text-right" style="color:var(--color-warning);"><?= e(pa_money($payoffData['stillToRecover'])) ?></td>
                </tr>
            </table>
        </div>
    </div>

</div><!-- /grid cost structure -->

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION 4 — Revenue by Lease
     ══════════════════════════════════════════════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
    <h2 style="font-size:1rem;font-weight:700;margin:0;">Revenue by Lease</h2>
    <span style="font-size:0.8125rem;color:var(--text-secondary);"><?= count($revenueByLease) ?> lease<?= count($revenueByLease) !== 1 ? 's' : '' ?></span>
</div>
<?php if (empty($revenueByLease)): ?>
<div class="card" style="margin-bottom:24px;padding:32px;text-align:center;color:var(--text-muted);font-size:0.875rem;">No leases on record for this unit.</div>
<?php else: ?>
<div class="card" style="margin-bottom:24px;overflow:hidden;" x-data="ffPager(<?= count($revenueByLease) ?>)">
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Contract #</th>
                    <th>Customer</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Status</th>
                    <th class="text-right">Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $leaseRevTotal = '0.00';
                $lrIdx = 0;
                foreach ($revenueByLease as $lr):
                    $leaseRevTotal = bcadd($leaseRevTotal, (string)$lr['revenue'], 2);
                ?>
                <tr x-show="onPage(<?= $lrIdx ?>)"<?= $lrIdx >= 10 ? ' style="display:none;"' : '' ?>>
                    <td><a href="<?= e(base_url('leases/show?id=' . (int)$lr['id'])) ?>" class="table-link font-mono"><?= e($lr['contract_number']) ?></a></td>
                    <td><?= e($lr['customer_name']) ?></td>
                    <td class="font-mono text-sm"><?= e($lr['start_date'] ? format_date($lr['start_date']) : '—') ?></td>
                    <td class="font-mono text-sm"><?= e($lr['end_date'] ? format_date($lr['end_date']) : 'Ongoing') ?></td>
                    <td><span class="badge <?= e(pa_lease_badge($lr['status'])) ?>"><?= e(ucfirst($lr['status'])) ?></span></td>
                    <td class="text-right font-mono<?= bccomp((string)$lr['revenue'], '0', 2) > 0 ? ' text-success' : '' ?>" style="font-weight:600;"><?= e(pa_money((string)$lr['revenue'])) ?></td>
                </tr>
                <?php $lrIdx++; endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:700;background:var(--bg-secondary);">
                    <td colspan="5" style="padding:10px 12px;font-size:0.8125rem;">Total Gross Revenue (all leases)</td>
                    <td class="text-right font-mono text-success" style="padding:10px 12px;font-weight:700;"><?= e(pa_money($leaseRevTotal)) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php /* Pagination — 10 rows/page, shown only when there's more than one page */ ?>
    <div class="table-pager" x-show="pages > 1" x-cloak
         style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;border-top:1px solid var(--border-color);flex-wrap:wrap;">
        <span style="font-size:0.8125rem;color:var(--text-secondary);">
            Showing <span x-text="rangeStart"></span>–<span x-text="rangeEnd"></span> of <span x-text="total"></span>
        </span>
        <div style="display:flex;align-items:center;gap:8px;">
            <button class="btn btn-secondary btn-sm" @click="prev()" :disabled="page === 1">Prev</button>
            <span style="font-size:0.8125rem;color:var(--text-secondary);">Page <span x-text="page"></span> / <span x-text="pages"></span></span>
            <button class="btn btn-secondary btn-sm" @click="next()" :disabled="page === pages">Next</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION 5 — Monthly P&L Table (last 24 months)
     ══════════════════════════════════════════════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
    <h2 style="font-size:1rem;font-weight:700;margin:0;">Monthly P&amp;L</h2>
    <span style="font-size:0.8125rem;color:var(--text-secondary);">Last 24 months · revenue minus maintenance &amp; damage (excl. fixed costs)</span>
</div>
<div class="card" style="margin-bottom:24px;overflow:hidden;"<?= !empty($monthlyPnlRows) ? ' x-data="ffPager(' . count($monthlyPnlRows) . ')"' : '' ?>>
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th class="text-right">Revenue</th>
                    <th class="text-right">Maintenance</th>
                    <th class="text-right">Damage</th>
                    <th class="text-right">Net</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $pnlIdx = 0;
                foreach ($monthlyPnlRows as $row):
                    $netPos = bccomp($row['net'], '0', 2) >= 0;
                ?>
                <tr x-show="onPage(<?= $pnlIdx ?>)"<?= $pnlIdx >= 10 ? ' style="display:none;"' : '' ?>>
                    <td class="font-mono"><?= e($row['ym']) ?></td>
                    <td class="text-right font-mono<?= bccomp($row['rev'], '0', 2) > 0 ? ' text-success' : '' ?>"><?= e(pa_money($row['rev'])) ?></td>
                    <td class="text-right font-mono<?= bccomp($row['mnt'], '0', 2) > 0 ? ' text-danger' : '' ?>"><?= bccomp($row['mnt'], '0', 2) > 0 ? '−' . e(pa_money($row['mnt'])) : e(pa_money($row['mnt'])) ?></td>
                    <td class="text-right font-mono<?= bccomp($row['dmg'], '0', 2) > 0 ? ' text-danger' : '' ?>"><?= bccomp($row['dmg'], '0', 2) > 0 ? '−' . e(pa_money($row['dmg'])) : e(pa_money($row['dmg'])) ?></td>
                    <td class="text-right font-mono" style="font-weight:600;color:<?= $netPos ? 'var(--color-success)' : 'var(--color-danger)' ?>;"><?= e(pa_money($row['net'])) ?></td>
                </tr>
                <?php $pnlIdx++; endforeach; ?>
                <?php if (empty($monthlyPnlRows)): ?>
                <tr><td colspan="5" style="padding:24px;text-align:center;color:var(--text-muted);font-size:0.875rem;">No revenue or cost data recorded in the last 24 months.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (!empty($monthlyPnlRows)): ?>
    <?php /* Pagination — 10 rows/page, shown only when there's more than one page */ ?>
    <div class="table-pager" x-show="pages > 1" x-cloak
         style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;border-top:1px solid var(--border-color);flex-wrap:wrap;">
        <span style="font-size:0.8125rem;color:var(--text-secondary);">
            Showing <span x-text="rangeStart"></span>–<span x-text="rangeEnd"></span> of <span x-text="total"></span>
        </span>
        <div style="display:flex;align-items:center;gap:8px;">
            <button class="btn btn-secondary btn-sm" @click="prev()" :disabled="page === 1">Prev</button>
            <span style="font-size:0.8125rem;color:var(--text-secondary);">Page <span x-text="page"></span> / <span x-text="pages"></span></span>
            <button class="btn btn-secondary btn-sm" @click="next()" :disabled="page === pages">Next</button>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php endif; /* /if asset */?>

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION 6 — Maintenance Work Orders
     ══════════════════════════════════════════════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
    <h2 style="font-size:1rem;font-weight:700;margin:0;">Maintenance Work Orders</h2>
    <div style="display:flex;align-items:center;gap:12px;">
        <span style="font-size:0.8125rem;color:var(--text-secondary);"><?= count($workOrders) ?> record<?= count($workOrders) !== 1 ? 's' : '' ?></span>
        <?php if (bccomp($woCompletedTotal, '0', 2) > 0): ?>
        <span class="badge badge-danger font-mono"><?= e(pa_money($woCompletedTotal)) ?> completed</span>
        <?php endif; ?>
    </div>
</div>
<?php if (empty($workOrders)): ?>
<div class="card" style="margin-bottom:24px;padding:32px;text-align:center;color:var(--text-muted);font-size:0.875rem;">No maintenance work orders on record.</div>
<?php else: ?>
<div class="card" style="margin-bottom:24px;overflow:hidden;">
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Work Order</th>
                    <th>Type</th>
                    <th>Vendor</th>
                    <th>Status</th>
                    <th class="text-right">Labor</th>
                    <th class="text-right">Parts</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($workOrders as $wo): ?>
                <tr>
                    <td class="font-mono text-sm"><?= e(format_date($wo['completed_date'] ?? $wo['requested_date'] ?? '')) ?></td>
                    <td><a href="<?= e(base_url('maintenance/work-orders/show?id=' . (int)$wo['id'])) ?>" class="table-link font-mono"><?= e($wo['work_order_number'] ?? '#' . $wo['id']) ?></a></td>
                    <td style="font-size:0.8125rem;"><?= e(pa_work_type_label($wo['work_type'] ?? '')) ?></td>
                    <td style="font-size:0.8125rem;"><?= e($wo['vendor_name'] ?? '—') ?></td>
                    <td><span class="badge <?= e(pa_status_badge_wo($wo['status'])) ?>"><?= e(ucfirst(str_replace('_', ' ', $wo['status']))) ?></span></td>
                    <td class="text-right font-mono text-sm"><?= e(pa_money((string)($wo['labor_cost'] ?? '0'))) ?></td>
                    <td class="text-right font-mono text-sm"><?= e(pa_money((string)($wo['parts_cost'] ?? '0'))) ?></td>
                    <td class="text-right font-mono" style="font-weight:600;<?= $wo['status'] === 'completed' ? 'color:var(--color-danger);' : '' ?>"><?= e(pa_money((string)($wo['total_cost'] ?? '0'))) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:700;background:var(--bg-secondary);">
                    <td colspan="7" style="padding:10px 12px;font-size:0.8125rem;">Total (completed work orders)</td>
                    <td class="text-right font-mono text-danger" style="padding:10px 12px;font-weight:700;"><?= e(pa_money($woCompletedTotal)) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION 7 — Damage Claims
     ══════════════════════════════════════════════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
    <h2 style="font-size:1rem;font-weight:700;margin:0;">Damage Claims</h2>
    <div style="display:flex;align-items:center;gap:12px;">
        <span style="font-size:0.8125rem;color:var(--text-secondary);"><?= count($damageClaims) ?> claim<?= count($damageClaims) !== 1 ? 's' : '' ?></span>
        <?php if (bccomp($dmgTotal, '0', 2) > 0): ?>
        <span class="badge badge-danger font-mono"><?= e(pa_money($dmgTotal)) ?> total cost</span>
        <?php endif; ?>
    </div>
</div>
<?php if (empty($damageClaims)): ?>
<div class="card" style="margin-bottom:24px;padding:32px;text-align:center;color:var(--text-muted);font-size:0.875rem;">No damage claims on record.</div>
<?php else: ?>
<div class="card" style="margin-bottom:24px;overflow:hidden;">
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Claim #</th>
                    <th>Lease</th>
                    <th>Customer</th>
                    <th>Severity</th>
                    <th class="text-right">Est. Cost</th>
                    <th class="text-right">Actual Cost</th>
                    <th class="text-right">Cust. Liable</th>
                    <th class="text-right">Insurance</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($damageClaims as $dc): ?>
                <tr>
                    <td class="font-mono text-sm"><?= e(format_date($dc['created_at'])) ?></td>
                    <td><a href="<?= e(base_url('damage-claims/show?id=' . (int)$dc['id'])) ?>" class="table-link font-mono"><?= e($dc['claim_number'] ?? '#' . $dc['id']) ?></a></td>
                    <td class="font-mono text-sm"><?= e($dc['lease_number'] ?? '—') ?></td>
                    <td style="font-size:0.8125rem;"><?= e($dc['customer_name'] ?? '—') ?></td>
                    <td><span class="badge <?= e(pa_severity_badge($dc['severity'] ?? '')) ?>"><?= e(ucfirst($dc['severity'] ?? '—')) ?></span></td>
                    <td class="text-right font-mono text-sm"><?= e(pa_money((string)($dc['estimated_repair_cost'] ?? '0'))) ?></td>
                    <td class="text-right font-mono text-sm<?= !empty($dc['actual_repair_cost']) ? ' text-danger' : ' text-muted' ?>"><?= !empty($dc['actual_repair_cost']) ? e(pa_money((string)$dc['actual_repair_cost'])) : '—' ?></td>
                    <td class="text-right font-mono text-sm"><?= e(pa_money((string)($dc['customer_liable_amount'] ?? '0'))) ?></td>
                    <td class="text-right font-mono text-sm"><?= e(pa_money((string)($dc['insurance_claim_amount'] ?? '0'))) ?></td>
                    <td style="font-size:0.8125rem;"><?= e(ucfirst(str_replace('_', ' ', $dc['status'] ?? ''))) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:700;background:var(--bg-secondary);">
                    <td colspan="6" style="padding:10px 12px;font-size:0.8125rem;">Total Repair Cost (actual or estimated)</td>
                    <td class="text-right font-mono text-danger" style="padding:10px 12px;font-weight:700;"><?= e(pa_money($dmgTotal)) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION 8 — Utilisation Statistics
     ══════════════════════════════════════════════════════════════════════════ -->
<h2 style="font-size:1rem;font-weight:700;margin:0 0 12px;">Utilisation Statistics</h2>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:32px;">
    <div class="card" style="padding:16px;margin-bottom:0;">
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:6px;">Utilisation Rate</div>
        <div class="font-mono" style="font-size:1.5rem;font-weight:700;color:<?= $utilPct >= 70 ? 'var(--color-success)' : ($utilPct >= 40 ? 'var(--color-warning)' : 'var(--color-danger)') ?>;"><?= e((string) $utilPct) ?>%</div>
        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;">of time since acquired</div>
    </div>
    <div class="card" style="padding:16px;margin-bottom:0;">
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:6px;">Days on Lease</div>
        <div class="font-mono" style="font-size:1.5rem;font-weight:700;"><?= e(number_format($daysOnLease)) ?></div>
        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;">of <?= e(number_format($daysSinceAcq)) ?> total days</div>
    </div>
    <div class="card" style="padding:16px;margin-bottom:0;">
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:6px;">Total Leases</div>
        <div class="font-mono" style="font-size:1.5rem;font-weight:700;"><?= e((string) $leaseCount) ?></div>
        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;">non-cancelled</div>
    </div>
    <div class="card" style="padding:16px;margin-bottom:0;">
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:6px;">Avg Lease Duration</div>
        <div class="font-mono" style="font-size:1.5rem;font-weight:700;"><?= e($leaseCount > 0 ? number_format($avgLeaseDays) : '—') ?></div>
        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;"><?= $leaseCount > 0 ? 'days per lease' : 'no leases yet' ?></div>
    </div>
    <?php if (!empty($unit['samsara_odometer_km'])): ?>
    <div class="card" style="padding:16px;margin-bottom:0;">
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:6px;">GPS Odometer</div>
        <div class="font-mono" style="font-size:1.5rem;font-weight:700;"><?= e(number_format((float)$unit['samsara_odometer_km'])) ?> km</div>
        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;">via Samsara</div>
    </div>
    <?php elseif (!empty($unit['mileage'])): ?>
    <div class="card" style="padding:16px;margin-bottom:0;">
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:6px;">Mileage</div>
        <div class="font-mono" style="font-size:1.5rem;font-weight:700;"><?= e(number_format((int)$unit['mileage'])) ?></div>
        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;">recorded km</div>
    </div>
    <?php endif; ?>
</div>

<?php if ($asset && $payoffData): ?>
<script>
// ── payoffPage Alpine component ───────────────────────────────────────────────
// Handles period switching + custom projection. The chart is rendered by
// renderPayoffCharts() once the first API response arrives.
function payoffPage(assetId) {
    return {
        assetId, period: 6, loading: false, p: null, custom: null, customMonthly: '',
        chartsRendered: false,

        async load(period) {
            this.period = period;
            this.loading = true;
            try {
                const r = await FF_Api.get(
                    '<?= e(base_url('api/v1/accounting/fixed_assets/payoff')) ?>?asset_id=' + assetId + '&period=' + period
                );
                if (r.success) {
                    this.p = r.data;
                    if (!this.chartsRendered) {
                        this.$nextTick(() => renderPayoffCharts(this.p));
                        this.chartsRendered = true;
                    }
                }
            } catch(e) { console.error('payoff load error', e); }
            this.loading = false;
        },

        async loadCustom() {
            const v = parseFloat(this.customMonthly);
            if (!v || v <= 0) return;
            try {
                const r = await FF_Api.get(
                    '<?= e(base_url('api/v1/accounting/fixed_assets/payoff')) ?>?asset_id=' + assetId + '&period=' + this.period + '&custom_monthly_revenue=' + v
                );
                if (r.success && r.data.custom_projection) {
                    this.custom = { ...r.data.custom_projection, monthly_net: v.toFixed(2) };
                }
            } catch(e) {}
        },

        fmt(v) { return parseFloat(v || 0).toLocaleString('en-CA', {minimumFractionDigits:2,maximumFractionDigits:2}); },
    };
}

// ── ApexCharts ────────────────────────────────────────────────────────────────
function renderPayoffCharts(p) {
    // ── Chart 1: Cumulative net revenue vs target (line) ─────────────────────
    const months   = p.monthly_data.map(r => r.month);
    const cumNet   = p.monthly_data.map(r => parseFloat(r.cumulative_net_revenue));
    const target   = parseFloat(p.acquisition.adjusted_target);
    const targetLine = months.map(() => target);

    if (typeof ApexCharts !== 'undefined' && document.getElementById('pa-cumulative-chart')) {
        new ApexCharts(document.getElementById('pa-cumulative-chart'), {
            chart: { type:'line', height:300, toolbar:{show:false}, animations:{enabled:false} },
            series: [
                { name:'Cumulative Net Revenue', data: cumNet },
                { name:'Payoff Target',           data: targetLine },
            ],
            colors: ['var(--color-primary)', 'var(--color-success)'],
            stroke: { width:[3,2], curve:'smooth', dashArray:[0,6] },
            xaxis: { categories: months, labels:{rotate:0, style:{fontSize:'11px'}} },
            yaxis: { labels:{ formatter: v => '$'+v.toLocaleString('en-CA',{minimumFractionDigits:0,maximumFractionDigits:0}) } },
            tooltip: { y:{ formatter: v => '$'+v.toLocaleString('en-CA',{minimumFractionDigits:2,maximumFractionDigits:2}) } },
            legend: { position:'top' },
            grid:   { strokeDashArray:4, borderColor:'var(--border-color)' },
        }).render();
    }

    // ── Chart 2: Monthly revenue vs costs (grouped bar) ───────────────────────
    const rev  = p.monthly_data.map(r => parseFloat(r.revenue));
    const mnt  = p.monthly_data.map(r => parseFloat(r.maintenance));
    const dmg  = p.monthly_data.map(r => parseFloat(r.damage));
    const costs = mnt.map((m, i) => +(m + dmg[i]).toFixed(2));

    if (typeof ApexCharts !== 'undefined' && document.getElementById('pa-monthly-chart')) {
        new ApexCharts(document.getElementById('pa-monthly-chart'), {
            chart: { type:'bar', height:280, toolbar:{show:false}, animations:{enabled:false} },
            series: [
                { name:'Gross Revenue', data: rev  },
                { name:'Costs (Maint+Damage)', data: costs },
            ],
            colors: ['var(--color-success)', 'var(--color-danger)'],
            plotOptions: { bar:{ columnWidth:'60%', borderRadius:3 } },
            xaxis: { categories: months, labels:{rotate:0, style:{fontSize:'11px'}} },
            yaxis: { labels:{ formatter: v => '$'+v.toLocaleString('en-CA',{minimumFractionDigits:0,maximumFractionDigits:0}) } },
            tooltip: { y:{ formatter: v => '$'+v.toLocaleString('en-CA',{minimumFractionDigits:2,maximumFractionDigits:2}) } },
            legend: { position:'top' },
            grid:   { strokeDashArray:4, borderColor:'var(--border-color)' },
        }).render();
    }
}
</script>
<?php endif; ?>

<script>
// ── ffPager — client-side table paginator ──────────────────────────────────
// WHY: The Revenue-by-Lease and Monthly P&L tables render every row server-side.
// This Alpine component shows `perPage` rows at a time (default 10) and reveals
// the rest via Prev/Next — lighter DOM on screen and a tidier page. Rows are
// shown/hidden by zero-based index through onPage(i); totals in <tfoot> stay
// visible across pages.
function ffPager(total, perPage = 10) {
    return {
        page: 1,
        perPage: perPage,
        total: total,
        get pages() { return Math.max(1, Math.ceil(this.total / this.perPage)); },
        onPage(i) {
            const start = (this.page - 1) * this.perPage;
            return i >= start && i < start + this.perPage;
        },
        get rangeStart() { return this.total === 0 ? 0 : (this.page - 1) * this.perPage + 1; },
        get rangeEnd() { return Math.min(this.page * this.perPage, this.total); },
        next() { if (this.page < this.pages) this.page++; },
        prev() { if (this.page > 1) this.page--; },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
