<?php
declare(strict_types=1);

/**
 * FleetForge — Dashboard
 *
 * @file        app/admin/dashboard/index.php
 * @description Main dashboard page. Renders KPI tiles and chart placeholders
 *              for all authenticated users. No module permission required —
 *              dashboard is visible to every logged-in user (sidebar module=null).
 * @depends     config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @spec        FLEETFORGE_SPEC_FINAL.md — Dashboard (lines 1901–1929)
 * @design      FLEETFORGE_DESIGN_DETAILS.md — Dashboard layout
 * @session     S003 — stub (real API calls wired in later sessions)
 */

// ── Bootstrap ────────────────────────────────────────────────
// dirname(__DIR__, 3): app/admin/dashboard/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

// Require login — no module permission needed for dashboard itself
require_auth();

// ── Page metadata ─────────────────────────────────────────────
$pageTitle = 'Dashboard';

// ── Stub data — replaced by api/dashboard/kpis.php in a later session ──
// These values keep the page renderable without a working billing engine.
$kpis = [
    [
        'label' => 'Active Revenue',
        'value' => '—',
        'delta' => 'Wired in billing session',
        'color' => '',
    ],
    [
        'label' => 'Fleet Utilization',
        'value' => '—',
        'delta' => 'Wired in equipment session',
        'color' => '',
    ],
    [
        'label' => 'Overdue Invoices',
        'value' => '—',
        'delta' => 'Wired in invoicing session',
        'color' => 'danger',
    ],
    [
        'label' => 'Compliance Alerts',
        'value' => '—',
        'delta' => 'Wired in compliance session',
        'color' => 'warning',
    ],
    [
        'label' => 'Open Leases',
        'value' => '—',
        'delta' => 'Wired in leases session',
        'color' => '',
    ],
    [
        'label' => "Today's Pickups",
        'value' => '—',
        'delta' => 'Wired in leases session',
        'color' => '',
    ],
];

require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <h1 class="page-header-title h4">Dashboard</h1>
    <div class="page-header-actions">
        <span class="text-secondary text-sm">
            <?= e(format_date(date('Y-m-d'))) ?>
        </span>
    </div>
</div>

<!-- ============================================================
     KPI tiles — 6 across on desktop, 3×2 tablet, 2×3 mobile
     Spec: FLEETFORGE_SPEC_FINAL.md Dashboard (KPI Tiles top row)
     Real data wired in api/dashboard/kpis.php (later session)
     ============================================================ -->
<div class="stat-grid" id="kpi-grid">
    <?php foreach ($kpis as $kpi): ?>
        <div class="stat-card<?= $kpi['color'] ? ' stat-card--' . e($kpi['color']) : '' ?>">
            <div class="stat-label"><?= e($kpi['label']) ?></div>
            <div class="stat-value"><?= e($kpi['value']) ?></div>
            <div class="stat-delta"><?= e($kpi['delta']) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ============================================================
     Charts row — 2-up layout (revenue area + fleet donut)
     Chart canvases are stubs; Chart.js wired in a later session.
     ============================================================ -->
<div class="grid-2" style="margin-bottom: 24px;">

    <div class="card">
        <div class="card-header">
            <span class="card-title">Revenue — Last 12 Months</span>
        </div>
        <div class="card-body" style="min-height: 200px; display:flex; align-items:center; justify-content:center;">
            <span class="text-muted text-sm">Chart wired in reporting session</span>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Fleet Status</span>
        </div>
        <div class="card-body" style="min-height: 200px; display:flex; align-items:center; justify-content:center;">
            <span class="text-muted text-sm">Chart wired in equipment session</span>
        </div>
    </div>

</div>

<!-- ============================================================
     Second charts row — AR aging + utilization trend
     ============================================================ -->
<div class="grid-2" style="margin-bottom: 24px;">

    <div class="card">
        <div class="card-header">
            <span class="card-title">AR Aging</span>
        </div>
        <div class="card-body" style="min-height: 160px; display:flex; align-items:center; justify-content:center;">
            <span class="text-muted text-sm">Chart wired in invoicing session</span>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Utilization Trend</span>
        </div>
        <div class="card-body" style="min-height: 160px; display:flex; align-items:center; justify-content:center;">
            <span class="text-muted text-sm">Chart wired in equipment session</span>
        </div>
    </div>

</div>

<!-- ============================================================
     Widgets row — today's pickups + compliance alerts
     ============================================================ -->
<div class="grid-2">

    <div class="card">
        <div class="card-header">
            <span class="card-title">Today's Pickups</span>
        </div>
        <div class="card-body" style="min-height: 120px; display:flex; align-items:center; justify-content:center;">
            <span class="text-muted text-sm">Wired in leases session</span>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Compliance Alerts</span>
        </div>
        <div class="card-body" style="min-height: 120px; display:flex; align-items:center; justify-content:center;">
            <span class="text-muted text-sm">Wired in compliance session</span>
        </div>
    </div>

</div>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
