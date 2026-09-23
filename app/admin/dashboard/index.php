<?php
declare(strict_types=1);

/**
 * FleetForge — Admin Dashboard
 *
 * @file        app/admin/dashboard/index.php
 * @description The home page. S-DASHBOARD-REDESIGN rebuilt it around one
 *              question — "what needs me today?" — in the SOP/module-chrome
 *              visual language:
 *                1. Welcome hero: greeting, a live one-line summary of the
 *                   fleet, quick actions, and an animated "fleet pulse" ring
 *                   (on lease / ready to rent / everything else).
 *                2. Needs attention: action cards built from the KPIs + lists
 *                   (overdue, leases waiting to start, drafts, returns this
 *                   week, pickups, renewals, claims, work orders) — only the
 *                   ones that apply, or an "all clear" state.
 *                3. The 12 key numbers, grouped Money / Fleet / Pipeline
 *                   (#kpi-grid; tile labels unchanged).
 *                4. A sticky section bar with scroll-spy, then numbered
 *                   sections (Money, Leases, Fleet, Customers, Activity).
 *                   The ten carousels became two tabbed work panels + a
 *                   reservations list; all 12 charts keep their element ids.
 *              Money is decided server-side: without can_view_financials()
 *              the money tiles, money charts and the Customers section are
 *              never rendered (the APIs already strip the figures).
 *              Data: api/v1/dashboard/{kpis,charts,tables,activity_feed}.
 *              No module permission required — every staff user lands here.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, public/assets/css/dashboard.css,
 *              lib/Sop/SopIcons.php, api/v1/dashboard/*
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.1 Dashboard
 * @design      FLEETFORGE_DESIGN_DETAILS.md §4 Dashboard + S-DASHBOARD-REDESIGN
 * @session     S004 (KPIs + charts), S-DASHBOARD-REDESIGN (this layout)
 */

use FleetForge\Sop\SopIcons;

// dirname(__DIR__, 3): app/admin/dashboard/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

$pageTitle      = 'Dashboard';
$helpModuleSlug = 'dashboard';
// S-PERF-CHARTS: this page draws ApexCharts — opt in before header.php so
// footer.php emits the 522 KB chart bundle. Without this the charts are blank.
$pageNeedsCharts = true;
require_once FF_ROOT . '/includes/header.php';

// ── Who is looking decides what renders ─────────────────────────────
// WHY server-side: the APIs strip money for roles without payments:view,
// but the old page still drew empty money charts and "$NaN" amounts for
// them. Not rendering those blocks at all is cleaner and leaks nothing.
$canMoney  = can_view_financials();
$_me       = current_user() ?? [];
$firstName = (string) (preg_split('/\s+/', trim((string) ($_me['name'] ?? '')))[0] ?? '');
$hour      = (int) date('G');                        // PHP runs in the company timezone
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$today     = ff_today();

// Links the Alpine component needs (built here so base_url() stays the one source).
$dashUrls = [
    'lease'       => base_url('leases/show') . '?id=',
    'invoice'     => base_url('invoices/show') . '?id=',
    'reservation' => base_url('reservations/show') . '?id=',
    'customer'    => base_url('customers/show') . '?id=',
    'unit'        => base_url('equipment/show') . '?id=',
    'ending'      => base_url('leases') . '?filter=expiring_this_month',
    'overdue'     => base_url('invoices') . '?status=overdue',
    'pending'     => base_url('leases') . '?status=pending',
    'drafts'      => base_url('invoices') . '?status=draft',
    'returns'     => base_url('leases') . '?filter=returning_soon',
    'pickups'     => base_url('reservations') . '?pickup_date=' . $today,
    'compliance'  => base_url('compliance'),
    'claims'      => base_url('damage_claims'),
    'workorders'  => base_url('maintenance_work_orders') . '?status=open',
    'panel'       => [
        'money'  => [
            'overdue'     => base_url('invoices') . '?status=overdue',
            'outstanding' => base_url('invoices') . '?status=outstanding',
            'drafts'      => base_url('invoices') . '?status=draft',
        ],
        'leases' => [
            'active'   => base_url('leases') . '?status=active',
            'pending'  => base_url('leases') . '?status=pending',
            'returns'  => base_url('leases') . '?filter=returning_soon',
            'expiring' => base_url('leases') . '?filter=expiring_this_month',
            'recent'   => base_url('leases') . '?status=active&sort=start_date_desc',
            'top'      => base_url('leases') . '?status=active&sort=rate_desc',
        ],
    ],
];

// Section order: money people start with money; everyone else with leases.
$dashSections = $canMoney
    ? [['money', 'Money'], ['leases', 'Leases'], ['fleet', 'Fleet'], ['customers', 'Customers'], ['activity', 'Activity']]
    : [['leases', 'Leases'], ['fleet', 'Fleet'], ['money', 'Billing'], ['activity', 'Activity']];
$dashNum = array_flip(array_column($dashSections, 0));    // id → 0-based position

/**
 * A numbered section heading: number chip, icon, title, one-line purpose,
 * optional link to the module.
 *
 * @param string      $id        section id (numbering comes from $dashSections)
 * @param string      $icon      public/assets/icons name
 * @param string      $title     heading text
 * @param string      $sub       one plain-English line
 * @param string|null $href      module link (null = none)
 * @param string      $linkLabel link text
 * @return string HTML
 */
$dashSecHead = static function (string $id, string $icon, string $title, string $sub, ?string $href = null, string $linkLabel = '') use ($dashNum): string {
    return '<header class="dash-sec-head">'
        . '<span class="dash-sec-num">' . sprintf('%02d', ($dashNum[$id] ?? 0) + 1) . '</span>'
        . '<span class="dash-sec-ic" aria-hidden="true">' . SopIcons::svg($icon) . '</span>'
        . '<div class="dash-sec-text"><h2 class="dash-sec-title">' . e($title) . '</h2><p class="dash-sec-sub">' . e($sub) . '</p></div>'
        . ($href !== null ? '<a class="dash-sec-link" href="' . e($href) . '">' . e($linkLabel) . ' <span aria-hidden="true">→</span></a>' : '')
        . '</header>';
};

/**
 * A dashboard card header: accent icon tile, title, one-line explanation,
 * and an optional right-hand figure (raw HTML, may carry Alpine bindings).
 * The .card-title text is what the training walkthrough highlights.
 *
 * @param string $icon  public/assets/icons name
 * @param string $title card title
 * @param string $sub   one plain-English line
 * @param string $right optional raw HTML for the headline figure
 * @return string HTML
 */
$dashHead = static function (string $icon, string $title, string $sub, string $right = ''): string {
    return '<div class="card-header dash-card-head"><span class="dash-card-ic" aria-hidden="true">' . SopIcons::svg($icon) . '</span>'
        . '<div class="dash-card-titles"><span class="card-title">' . e($title) . '</span><span class="dash-card-sub">' . e($sub) . '</span></div>'
        . $right . '</div>';
};

/**
 * Loading skeleton + error line for a chart-data block (hidden once
 * chartsLoaded flips, or replaced by the error when the fetch fails).
 *
 * @param int $height skeleton height in px
 * @return string HTML
 */
$dashLoading = static function (int $height): string {
    return '<div x-show="!chartsLoaded && !chartError" class="chart-skeleton" aria-hidden="true" style="height:' . $height . 'px;"></div>'
        . '<div x-show="chartError" class="dash-empty" style="display:none;">This could not load — refresh the page to try again.</div>';
};
?>
<link rel="stylesheet" href="<?= asset_url('assets/css/dashboard.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">

<!-- ============================================================
     DASHBOARD — one Alpine component (FF_Dashboard, script below).
     Fetches KPIs, charts, lists and activity on mount; every block
     shows a skeleton until its data lands.
     ============================================================ -->
<div x-data="FF_Dashboard()" class="dash">

    <!-- ── 1. WELCOME HERO ─────────────────────────────────────── -->
    <section class="ff-hero ff-acc--primary dash-hero" aria-label="Today at a glance">
        <div class="ff-hero-main">
            <div class="ff-hero-eyebrow">
                <span class="ff-hero-ic"><?= SopIcons::svg($hour >= 6 && $hour < 19 ? 'sun' : 'moon') ?></span>
                <?= e(date('l, F j', strtotime($today))) ?>
            </div>
            <h1 class="ff-hero-title page-header-title dash-hello">
                <?= e($greeting) ?><?php if ($firstName !== ''): ?>, <em><?= e($firstName) ?></em><?php endif; ?>
            </h1>
            <p class="ff-hero-sub dash-summary">
                <span x-show="kpisLoaded" x-text="summary" style="display:none;"></span>
                <span x-show="!kpisLoaded && !kpiError" class="dash-skel dash-skel--line" aria-hidden="true"></span>
                <span x-show="kpiError" style="display:none;">The live numbers could not load — refresh to try again.</span>
            </p>
            <div class="ff-hero-actions">
                <?php if (can('leases', 'create')): ?>
                <a href="<?= base_url('leases/create') ?>" class="btn btn-primary btn-sm dash-qa"><?= SopIcons::svg('plus') ?>New lease</a>
                <?php endif; ?>
                <?php if (can('reservations', 'create')): ?>
                <a href="<?= base_url('reservations/create') ?>" class="btn btn-secondary btn-sm dash-qa"><?= SopIcons::svg('calendar') ?>New reservation</a>
                <?php endif; ?>
                <?php if (can('payments', 'create')): ?>
                <a href="<?= base_url('payments/create') ?>" class="btn btn-secondary btn-sm dash-qa"><?= SopIcons::svg('banknotes') ?>Record payment</a>
                <?php endif; ?>
                <?php if (can('invoices', 'view')): ?>
                <a href="<?= base_url('invoices/batch') ?>" class="btn btn-secondary btn-sm dash-qa"><?= SopIcons::svg('document-duplicate') ?>Batch invoicing</a>
                <?php endif; ?>
                <?= help_button('dashboard') ?>
            </div>
        </div>

        <!-- Fleet pulse: one ring, three arcs (on lease / ready / other) -->
        <div class="dash-pulse" aria-label="The fleet right now">
            <svg class="dg dash-gauge" viewBox="0 0 220 220" role="img"
                 :aria-label="kpisLoaded ? kpis.fleet_utilization + '% of the fleet is on lease right now' : 'Loading the fleet'">
                <circle class="dg-orbit" cx="110" cy="110" r="104"/>
                <circle class="dash-ring-track" cx="110" cy="110" r="84"/>
                <g transform="rotate(-90 110 110)">
                    <circle class="dash-ring dash-ring--lease" cx="110" cy="110" r="84" :style="pulse.lease"/>
                    <circle class="dash-ring dash-ring--avail" cx="110" cy="110" r="84" :style="pulse.avail"/>
                    <circle class="dash-ring dash-ring--other" cx="110" cy="110" r="84" :style="pulse.other"/>
                </g>
                <circle class="dg-core" cx="110" cy="110" r="62"/>
                <text class="dash-gauge-num" x="110" y="118" x-text="kpisLoaded ? kpis.fleet_utilization + '%' : '—'">—</text>
                <text class="dg-t-xs" x="110" y="140">ON LEASE NOW</text>
            </svg>
            <ul class="dash-pulse-legend">
                <li><span class="dash-dot dash-dot--lease"></span>On lease<b x-text="kpisLoaded ? kpis.on_lease_count : '—'">—</b></li>
                <li><span class="dash-dot dash-dot--avail"></span>Ready to rent<b x-text="kpisLoaded ? kpis.available_units : '—'">—</b></li>
                <li><span class="dash-dot dash-dot--other"></span>Shop, reserved &amp; other<b x-text="kpisLoaded ? pulse.otherCount : '—'">—</b></li>
                <li class="dash-pulse-total">Whole fleet<b x-text="kpisLoaded ? kpis.total_active_units : '—'">—</b></li>
            </ul>
        </div>
    </section>

    <!-- ── 2. NEEDS ATTENTION ──────────────────────────────────── -->
    <section class="dash-attn" aria-labelledby="dash-attn-title">
        <div class="dash-attn-head">
            <h2 id="dash-attn-title" class="dash-h2">Needs attention</h2>
            <span class="dash-attn-count" x-show="attnReady" style="display:none;"
                  x-text="attn.length ? attn.length + (attn.length === 1 ? ' thing' : ' things') + ' to look at' : 'All clear'"></span>
        </div>
        <div class="dash-attn-grid">
            <template x-if="!attnReady">
                <div class="dash-attn-skel" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
            </template>
            <template x-for="a in attn" :key="a.key">
                <a class="dash-attn-card" :class="'dash-tone--' + a.tone" :href="a.href">
                    <span class="dash-attn-ic" aria-hidden="true"><svg><use :href="'#icon-' + a.icon"/></svg></span>
                    <span class="dash-attn-num" x-text="a.count"></span>
                    <span class="dash-attn-label" x-text="a.label"></span>
                    <span class="dash-attn-detail" x-text="a.detail"></span>
                    <span class="dash-attn-cta"><span x-text="a.cta"></span> <span aria-hidden="true">→</span></span>
                </a>
            </template>
            <div class="dash-attn-clear" x-show="attnReady && attn.length === 0" style="display:none;">
                <span class="dash-attn-clear-ic" aria-hidden="true"><?= SopIcons::svg('shield-check') ?></span>
                <div>
                    <strong>Nothing needs you right now.</strong>
                    <span>Overdue invoices, drafts, leases waiting to start, returns and renewals will show up here.</span>
                </div>
            </div>
        </div>
    </section>

    <!-- ── 3. KEY NUMBERS (grouped; tile labels unchanged) ─────── -->
    <div id="kpi-grid" class="dash-kpis" aria-label="Key performance indicators">

        <section class="dash-kpi-group ff-acc--success" aria-label="<?= $canMoney ? 'Money' : 'Billing' ?>">
            <header class="dash-kpi-head">
                <span class="dash-kpi-ic" aria-hidden="true"><?= SopIcons::svg('banknotes') ?></span>
                <h2 class="dash-h3"><?= $canMoney ? 'Money' : 'Billing' ?></h2>
                <span class="dash-kpi-note"><?= e(date('F', strtotime($today))) ?></span>
            </header>
            <div class="stat-grid stat-grid--2 ff-stats">
                <?php if ($canMoney): ?>
                <a href="<?= base_url('reports') ?>" class="stat-card stat-card--link stat-card--green"
                   :class="kpiError ? 'stat-card--error' : ''"
                   aria-label="Active Revenue — click to view revenue reports">
                    <span class="stat-icon stat-icon--green"><svg><use href="#icon-currency-dollar"/></svg></span>
                    <div class="stat-label">Active Revenue</div>
                    <div class="stat-value" x-text="kpisLoaded ? '$' + money0(kpis.active_revenue) : '—'">—</div>
                    <div class="stat-delta text-secondary" x-show="kpisLoaded">a month, active leases</div>
                    <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
                </a>
                <a href="<?= base_url('payments') ?>" class="stat-card stat-card--link stat-card--teal"
                   aria-label="Monthly Collections — click to view payments">
                    <span class="stat-icon stat-icon--teal"><svg><use href="#icon-arrow-trending-up"/></svg></span>
                    <div class="stat-label">Monthly Collections</div>
                    <div class="stat-value" x-text="kpisLoaded ? '$' + money0(kpis.monthly_collections) : '—'">—</div>
                    <div class="stat-delta text-secondary" x-show="kpisLoaded">collected this month</div>
                    <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
                </a>
                <?php endif; ?>
                <a href="<?= base_url('invoices') ?>?status=sent" class="stat-card stat-card--link stat-card--blue"
                   aria-label="Sent Invoices — click to view sent invoices">
                    <span class="stat-icon stat-icon--blue"><svg><use href="#icon-document-text"/></svg></span>
                    <div class="stat-label">Sent Invoices</div>
                    <div class="stat-value" x-text="kpisLoaded ? kpis.sent_invoices : '—'">—</div>
                    <div class="stat-delta text-secondary" x-show="kpisLoaded">awaiting payment</div>
                    <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
                </a>
                <a href="<?= base_url('invoices') ?>?status=overdue" class="stat-card stat-card--link stat-card--red"
                   aria-label="Overdue Invoices — click to view overdue invoices">
                    <span class="stat-icon stat-icon--red"><svg><use href="#icon-exclamation-triangle"/></svg></span>
                    <div class="stat-label">Overdue Invoices</div>
                    <div class="stat-value" x-text="kpisLoaded ? kpis.overdue_invoices.count : '—'">—</div>
                    <div class="stat-delta text-secondary" x-show="kpisLoaded"
                         x-text="kpisLoaded && has(kpis.overdue_invoices.total) ? '$' + money0(kpis.overdue_invoices.total) + ' outstanding' : 'past their due date'"></div>
                    <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
                </a>
            </div>
        </section>

        <section class="dash-kpi-group ff-acc--info" aria-label="Fleet">
            <header class="dash-kpi-head">
                <span class="dash-kpi-ic" aria-hidden="true"><?= SopIcons::svg('truck') ?></span>
                <h2 class="dash-h3">Fleet</h2>
                <span class="dash-kpi-note">Right now</span>
            </header>
            <div class="stat-grid stat-grid--2 ff-stats">
                <!-- On Lease Now — a point-in-time SNAPSHOT (units whose status is on_lease
                     right now ÷ fleet). Deliberately NOT labelled "Utilization": period
                     utilization (occupied ÷ available unit-days) is the Utilization Trend
                     chart, Reports → Fleet and Analytics (lib/Reports/FleetUtilization.php). -->
                <a href="<?= base_url('equipment') ?>" class="stat-card stat-card--link stat-card--blue"
                   aria-label="On lease now — share of the fleet currently on lease; click to view equipment">
                    <span class="stat-icon stat-icon--blue"><svg><use href="#icon-truck"/></svg></span>
                    <div class="stat-label">On Lease Now</div>
                    <div class="stat-value" x-text="kpisLoaded ? kpis.fleet_utilization + '%' : '—'">—</div>
                    <div class="stat-delta text-secondary" x-show="kpisLoaded"
                         x-text="kpisLoaded ? kpis.on_lease_count + ' of ' + kpis.total_active_units + ' units' : ''"></div>
                    <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
                </a>
                <a href="<?= base_url('equipment') ?>?status=available" class="stat-card stat-card--link stat-card--green"
                   aria-label="Available Units — click to view available equipment">
                    <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
                    <div class="stat-label">Available Units</div>
                    <div class="stat-value" x-text="kpisLoaded ? kpis.available_units : '—'">—</div>
                    <div class="stat-delta text-secondary" x-show="kpisLoaded">ready to rent</div>
                    <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
                </a>
                <a href="<?= base_url('maintenance_work_orders') ?>?status=open" class="stat-card stat-card--link stat-card--amber"
                   aria-label="Open Work Orders — click to view maintenance">
                    <span class="stat-icon stat-icon--amber"><svg><use href="#icon-wrench"/></svg></span>
                    <div class="stat-label">Open Work Orders</div>
                    <div class="stat-value" x-text="kpisLoaded ? kpis.open_work_orders : '—'">—</div>
                    <div class="stat-delta text-secondary" x-show="kpisLoaded">open &amp; in progress</div>
                    <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
                </a>
                <a href="<?= base_url('damage_claims') ?>" class="stat-card stat-card--link"
                   :class="kpisLoaded && kpis.open_damage_claims > 0 ? 'stat-card--red' : 'stat-card--slate'"
                   aria-label="Open Damage Claims — click to view damage claims">
                    <span class="stat-icon" :class="kpisLoaded && kpis.open_damage_claims > 0 ? 'stat-icon--red' : 'stat-icon--slate'"><svg><use href="#icon-fire"/></svg></span>
                    <div class="stat-label">Damage Claims</div>
                    <div class="stat-value" x-text="kpisLoaded ? kpis.open_damage_claims : '—'">—</div>
                    <div class="stat-delta text-secondary" x-show="kpisLoaded">open claims</div>
                    <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
                </a>
            </div>
        </section>

        <section class="dash-kpi-group ff-acc--warning" aria-label="Pipeline">
            <header class="dash-kpi-head">
                <span class="dash-kpi-ic" aria-hidden="true"><?= SopIcons::svg('calendar-days') ?></span>
                <h2 class="dash-h3">Pipeline</h2>
                <span class="dash-kpi-note">Leases &amp; bookings</span>
            </header>
            <div class="stat-grid stat-grid--2 ff-stats">
                <a href="<?= base_url('leases') ?>?status=active" class="stat-card stat-card--link stat-card--purple"
                   aria-label="Open Leases — click to view active leases">
                    <span class="stat-icon stat-icon--purple"><svg><use href="#icon-key"/></svg></span>
                    <div class="stat-label">Open Leases</div>
                    <div class="stat-value" x-text="kpisLoaded ? kpis.open_leases : '—'">—</div>
                    <div class="stat-delta text-secondary" x-show="kpisLoaded">active &amp; pending</div>
                    <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
                </a>
                <a href="<?= base_url('reservations') ?>" class="stat-card stat-card--link stat-card--blue"
                   aria-label="Active Reservations — click to view reservations">
                    <span class="stat-icon stat-icon--blue"><svg><use href="#icon-clipboard"/></svg></span>
                    <div class="stat-label">Active Reservations</div>
                    <div class="stat-value" x-text="kpisLoaded ? kpis.active_reservations : '—'">—</div>
                    <div class="stat-delta text-secondary" x-show="kpisLoaded">pending &amp; confirmed</div>
                    <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
                </a>
                <!-- Today's Pickups — links to reservations module (S018) -->
                <a href="<?= base_url('reservations') ?>?pickup_date=<?= e($today) ?>" class="stat-card stat-card--link stat-card--teal"
                   aria-label="Today's Pickups — click to view today's reservations">
                    <span class="stat-icon stat-icon--teal"><svg><use href="#icon-map-pin"/></svg></span>
                    <div class="stat-label">Today's Pickups</div>
                    <div class="stat-value" x-text="kpisLoaded ? kpis.todays_pickups : '—'">—</div>
                    <div class="stat-delta text-secondary" x-show="kpisLoaded">reservations today</div>
                    <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
                </a>
                <a href="<?= base_url('compliance') ?>" class="stat-card stat-card--link stat-card--amber"
                   aria-label="Compliance Alerts — click to view compliance">
                    <span class="stat-icon stat-icon--amber"><svg><use href="#icon-shield-check"/></svg></span>
                    <div class="stat-label">Compliance Alerts</div>
                    <div class="stat-value" x-text="kpisLoaded ? kpis.compliance_alerts : '—'">—</div>
                    <div class="stat-delta text-secondary" x-show="kpisLoaded">expiring in 30 days</div>
                    <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
                </a>
            </div>
        </section>

    </div><!-- /#kpi-grid -->

    <!-- ── 4. SECTION BAR (sticky, scroll-spy) ─────────────────── -->
    <nav class="dash-nav" aria-label="Dashboard sections">
        <?php foreach ($dashSections as $i => [$sid, $slabel]): ?>
        <a href="#dash-<?= e($sid) ?>" class="dash-nav-link"
           :class="{ 'is-on': spy === '<?= e($sid) ?>' }"
           @click.prevent="jump('<?= e($sid) ?>')"><span class="n"><?= sprintf('%02d', $i + 1) ?></span><?= e($slabel) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php ob_start(); ?>
    <!-- ── MONEY / BILLING ─────────────────────────────────────── -->
    <section id="dash-money" class="dash-sec ff-acc--success">
        <?= $dashSecHead('money', 'banknotes', $canMoney ? 'Money' : 'Billing',
            $canMoney ? 'What you billed, what came in, who still owes you, and what is coming next.'
                      : 'Invoices waiting to be paid or sent, and how quickly customers pay.',
            base_url('invoices'), 'Invoices') ?>

        <?php if ($canMoney): ?>
        <div class="dash-grid dash-grid--2-1">
            <!-- Billed vs collected: the month-by-month cash story -->
            <div class="card dash-card dv-card">
                <?= $dashHead('chart-bar', 'Billed vs Collected', 'Invoiced and paid in each of the last 12 months (CAD)') ?>
                <div class="card-body">
                    <div class="dv-kpis" x-show="chartsLoaded" style="display:none;">
                        <div class="dv-kpi">
                            <span class="dv-kpi-l"><span class="dv-dot dv-c--primary"></span>Billed this month</span>
                            <span class="dv-kpi-v" x-text="viz.cash.billed"></span>
                            <span class="dv-delta" :class="viz.cash.billedDelta.cls" x-text="viz.cash.billedDelta.text"></span>
                        </div>
                        <div class="dv-kpi">
                            <span class="dv-kpi-l"><span class="dv-dot dv-c--ok"></span>Collected this month</span>
                            <span class="dv-kpi-v" x-text="viz.cash.collected"></span>
                            <span class="dv-delta" :class="viz.cash.collectedDelta.cls" x-text="viz.cash.collectedDelta.text"></span>
                        </div>
                        <div class="dv-kpi">
                            <span class="dv-kpi-l">Collected ÷ billed</span>
                            <span class="dv-kpi-v" x-text="viz.cash.rate"></span>
                            <span class="dv-delta">over 12 months</span>
                        </div>
                    </div>
                    <?= $dashLoading(270) ?>
                    <div x-show="chartsLoaded" class="chart-wrap"><div id="chart-cash-flow" style="min-height:270px;" aria-label="Billed versus collected by month"></div></div>
                </div>
            </div>

            <!-- Receivables health: one aging bar instead of a bar chart -->
            <div class="card dash-card dv-card">
                <?= $dashHead('scale', 'Owed to you', 'Everything customers still owe, by how late it is') ?>
                <div class="card-body">
                    <?= $dashLoading(270) ?>
                    <div class="dv-ar" x-show="chartsLoaded" style="display:none;">
                        <div class="dv-big-row">
                            <span class="dv-big" x-text="viz.ar.total"></span>
                            <span class="dv-big-sub" x-text="viz.ar.sub"></span>
                        </div>
                        <div class="dv-segbar" role="img" :aria-label="viz.ar.aria">
                            <template x-for="b in viz.ar.buckets" :key="b.key">
                                <span class="dv-seg" :class="'dv-c--' + b.tone" :style="'flex-grow:' + b.w" :title="b.label + ': ' + b.amt"></span>
                            </template>
                        </div>
                        <ul class="dv-legend">
                            <template x-for="b in viz.ar.buckets" :key="b.key">
                                <li>
                                    <span class="dv-dot" :class="'dv-c--' + b.tone"></span>
                                    <span class="dv-legend-l" x-text="b.label"></span>
                                    <span class="dv-legend-n" x-text="b.countText"></span>
                                    <b x-text="b.amt"></b>
                                </li>
                            </template>
                        </ul>
                        <a class="dv-link" href="<?= base_url('invoices') ?>?status=outstanding">Open unpaid invoices <span aria-hidden="true">→</span></a>
                    </div>
                </div>
            </div>
        </div>

        <div class="dash-grid dash-grid--1-1">
            <div class="card dash-panel">
                <div class="dash-panel-head">
                    <span class="dash-card-ic" aria-hidden="true"><?= SopIcons::svg('receipt-percent') ?></span>
                    <h3 class="dashboard-section-title">Receivables</h3>
                    <div class="dash-tabs" role="tablist" aria-label="Receivables lists">
                        <?php foreach (['overdue' => 'Overdue', 'outstanding' => 'Unpaid', 'drafts' => 'Drafts'] as $tk => $tl): ?>
                        <button type="button" role="tab" class="dash-tab"
                                :class="{ 'is-on': tab.money === '<?= $tk ?>', 'is-empty': tablesLoaded && !lists.money.<?= $tk ?>.length }"
                                :aria-selected="tab.money === '<?= $tk ?>' ? 'true' : 'false'"
                                @click="tab.money = '<?= $tk ?>'"><?= e($tl) ?><span class="dash-tab-n" x-text="countLabel(lists.money.<?= $tk ?>)"></span></button>
                        <?php endforeach; ?>
                    </div>
                    <a class="dash-panel-all" :href="urls.panel.money[tab.money]">View all <span aria-hidden="true">→</span></a>
                </div>
                <?php $listKey = 'money'; include FF_ROOT . '/includes/partials/dashboard-list.php'; ?>
            </div>
            <!-- Who owes the most past-due money -->
            <div class="card dash-card dv-card">
                <?= $dashHead('exclamation-triangle', 'Most overdue', 'Customers with the largest past-due balances',
                    '<span class="dv-head-fig" x-show="chartsLoaded && viz.overdue.total" x-text="viz.overdue.total" style="display:none;"></span>') ?>
                <div class="card-body dv-flush">
                    <?= $dashLoading(300) ?>
                    <div x-show="chartsLoaded && !viz.overdue.rows.length" class="dash-empty" style="display:none;">Nobody is past due — nice.</div>
                    <ol class="dv-rank" x-show="chartsLoaded" style="display:none;">
                        <template x-for="r in viz.overdue.rows" :key="r.key">
                            <li>
                                <a class="dv-rank-row" :href="r.href">
                                    <span class="dash-item-av dash-tone--danger" x-text="r.av" aria-hidden="true"></span>
                                    <span class="dv-rank-main">
                                        <span class="dv-rank-name" x-text="r.name"></span>
                                        <span class="dv-rank-sub" x-text="r.sub"></span>
                                        <span class="dv-bar dv-bar--danger"><span :style="'width:' + r.w + '%'"></span></span>
                                    </span>
                                    <span class="dv-rank-amt" x-text="r.amt"></span>
                                </a>
                            </li>
                        </template>
                    </ol>
                </div>
            </div>
        </div>

        <div class="dash-grid dash-grid--1-1">
            <!-- What active leases will bill next -->
            <div class="card dash-card dv-card">
                <?= $dashHead('sparkles', 'Coming up', 'What today’s active leases will bill in each of the next 6 months',
                    '<span class="dv-head-fig" x-show="chartsLoaded" x-text="viz.outlook.total" style="display:none;"></span>') ?>
                <div class="card-body">
                    <?= $dashLoading(250) ?>
                    <div x-show="chartsLoaded" class="chart-wrap"><div id="chart-revenue-forecast" style="min-height:250px;" aria-label="Projected billing next 6 months"></div></div>
                </div>
            </div>
            <!-- How fast customers pay: one number + a sparkline -->
            <div class="card dash-card dv-card">
                <?= $dashHead('clock', 'Days to pay', 'Average days from invoice date to payment') ?>
                <div class="card-body">
                    <?= $dashLoading(240) ?>
                    <div class="dv-speed" x-show="chartsLoaded" style="display:none;">
                        <div class="dv-big-row">
                            <span class="dv-big" x-text="viz.speed.days"></span>
                            <span class="dv-big-sub" x-text="viz.speed.note"></span>
                        </div>
                        <span class="dv-delta dv-delta--pill" :class="viz.speed.delta.cls" x-text="viz.speed.delta.text" x-show="viz.speed.delta.text"></span>
                    </div>
                    <div x-show="chartsLoaded" class="chart-wrap"><div id="chart-payment-speed" style="min-height:170px;" aria-label="Average days to pay by month"></div></div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Everyone else: the lists (amounts redacted) and how fast customers pay. -->
        <div class="dash-grid dash-grid--1-1">
            <div class="card dash-panel">
                <div class="dash-panel-head">
                    <span class="dash-card-ic" aria-hidden="true"><?= SopIcons::svg('receipt-percent') ?></span>
                    <h3 class="dashboard-section-title">Receivables</h3>
                    <div class="dash-tabs" role="tablist" aria-label="Receivables lists">
                        <?php foreach (['overdue' => 'Overdue', 'outstanding' => 'Unpaid', 'drafts' => 'Drafts'] as $tk => $tl): ?>
                        <button type="button" role="tab" class="dash-tab"
                                :class="{ 'is-on': tab.money === '<?= $tk ?>', 'is-empty': tablesLoaded && !lists.money.<?= $tk ?>.length }"
                                :aria-selected="tab.money === '<?= $tk ?>' ? 'true' : 'false'"
                                @click="tab.money = '<?= $tk ?>'"><?= e($tl) ?><span class="dash-tab-n" x-text="countLabel(lists.money.<?= $tk ?>)"></span></button>
                        <?php endforeach; ?>
                    </div>
                    <a class="dash-panel-all" :href="urls.panel.money[tab.money]">View all <span aria-hidden="true">→</span></a>
                </div>
                <?php $listKey = 'money'; include FF_ROOT . '/includes/partials/dashboard-list.php'; ?>
            </div>
            <!-- How fast customers pay: one number + a sparkline -->
            <div class="card dash-card dv-card">
                <?= $dashHead('clock', 'Days to pay', 'Average days from invoice date to payment') ?>
                <div class="card-body">
                    <?= $dashLoading(240) ?>
                    <div class="dv-speed" x-show="chartsLoaded" style="display:none;">
                        <div class="dv-big-row">
                            <span class="dv-big" x-text="viz.speed.days"></span>
                            <span class="dv-big-sub" x-text="viz.speed.note"></span>
                        </div>
                        <span class="dv-delta dv-delta--pill" :class="viz.speed.delta.cls" x-text="viz.speed.delta.text" x-show="viz.speed.delta.text"></span>
                    </div>
                    <div x-show="chartsLoaded" class="chart-wrap"><div id="chart-payment-speed" style="min-height:170px;" aria-label="Average days to pay by month"></div></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </section>
    <?php $secMoney = ob_get_clean(); ?>

    <?php ob_start(); ?>
    <!-- ── LEASES ──────────────────────────────────────────────── -->
    <section id="dash-leases" class="dash-sec ff-acc--warning">
        <?= $dashSecHead('leases', 'calendar-days', 'Leases',
            'Who has what, what starts and ends soon, and how many units are out on rent.',
            base_url('leases'), 'Leases') ?>

        <div class="dash-grid dash-grid--2-1">
            <div class="card dash-panel">
                <div class="dash-panel-head">
                    <span class="dash-card-ic" aria-hidden="true"><?= SopIcons::svg('clipboard-document-list') ?></span>
                    <h3 class="dashboard-section-title">Leases</h3>
                    <div class="dash-tabs" role="tablist" aria-label="Lease lists">
                        <?php
                        $leaseTabs = ['active' => 'On lease', 'pending' => 'Starting', 'returns' => 'Returning', 'expiring' => 'Ending this month', 'recent' => 'Just started'];
                        if ($canMoney) { $leaseTabs['top'] = 'Top value'; }
                        foreach ($leaseTabs as $tk => $tl): ?>
                        <button type="button" role="tab" class="dash-tab"
                                :class="{ 'is-on': tab.leases === '<?= $tk ?>', 'is-empty': tablesLoaded && !lists.leases.<?= $tk ?>.length }"
                                :aria-selected="tab.leases === '<?= $tk ?>' ? 'true' : 'false'"
                                @click="tab.leases = '<?= $tk ?>'"><?= e($tl) ?><span class="dash-tab-n" x-text="countLabel(lists.leases.<?= $tk ?>)"></span></button>
                        <?php endforeach; ?>
                    </div>
                    <a class="dash-panel-all" :href="urls.panel.leases[tab.leases]">View all <span aria-hidden="true">→</span></a>
                </div>
                <?php $listKey = 'leases'; include FF_ROOT . '/includes/partials/dashboard-list.php'; ?>
            </div>

            <div class="card dash-panel">
                <div class="dash-panel-head">
                    <span class="dash-card-ic" aria-hidden="true"><?= SopIcons::svg('calendar') ?></span>
                    <h3 class="dashboard-section-title">Reservations</h3>
                    <span class="dash-tab-n" x-text="countLabel(lists.reservations)"></span>
                    <a class="dash-panel-all" href="<?= base_url('reservations') ?>">View all <span aria-hidden="true">→</span></a>
                </div>
                <?php $listKey = 'reservations'; include FF_ROOT . '/includes/partials/dashboard-list.php'; ?>
            </div>
        </div>

        <div class="dash-grid dash-grid--2-1">
            <!-- Starting vs returning: diverging bars around zero -->
            <div class="card dash-card dv-card">
                <?= $dashHead('arrow-path', 'Starting vs returning', 'Leases that started (up) and ended (down) each month',
                    '<span class="dv-head-fig" x-show="chartsLoaded" style="display:none;"><span x-text="viz.flow.onRent"></span> <small>on rent</small> <em class="dv-delta" :class="viz.flow.change.cls" x-text="viz.flow.change.text"></em></span>') ?>
                <div class="card-body">
                    <?= $dashLoading(260) ?>
                    <div x-show="chartsLoaded" class="chart-wrap"><div id="chart-lease-flow" style="min-height:260px;" aria-label="Leases starting and returning by month"></div></div>
                </div>
            </div>
            <!-- Coming to an end: a 12-month calendar strip -->
            <div class="card dash-card dv-card">
                <?= $dashHead('calendar', 'Coming to an end', 'Leases reaching their end date, month by month',
                    '<span class="dv-head-fig" x-show="chartsLoaded" style="display:none;"><span x-text="viz.ending.next90"></span> <small>in 90 days</small></span>') ?>
                <div class="card-body">
                    <?= $dashLoading(260) ?>
                    <div x-show="chartsLoaded && viz.ending.empty" class="dash-empty" style="display:none;">No lease has an end date in the next 12 months — they are all open-ended or already set to renew.</div>
                    <div class="dv-months" x-show="chartsLoaded && !viz.ending.empty" style="display:none;">
                        <template x-for="m in viz.ending.months" :key="m.key">
                            <a class="dv-month" :class="{ 'is-now': m.now, 'is-zero': !m.count }" :href="m.href" :style="'--i:' + m.i">
                                <span class="dv-month-l" x-text="m.label"></span>
                                <span class="dv-month-n" x-text="m.count"></span>
                                <span class="dv-month-s" x-text="m.count === 1 ? 'lease' : 'leases'"></span>
                            </a>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php $secLeases = ob_get_clean(); ?>

    <?php ob_start(); ?>
    <!-- ── FLEET ───────────────────────────────────────────────── -->
    <section id="dash-fleet" class="dash-sec ff-acc--info">
        <?= $dashSecHead('fleet', 'truck', 'Fleet',
            'Where every unit is right now, which types work hardest, and what is sitting idle.',
            base_url('equipment'), 'Equipment') ?>

        <!-- Fleet mix: status bar + per-type split bars -->
        <div class="card dash-card dv-card">
            <?= $dashHead('cube', 'Fleet mix', 'Every unit by status, and how busy each equipment type is',
                '<span class="dv-head-fig" x-show="chartsLoaded" style="display:none;"><span x-text="viz.fleet.total"></span> <small>units</small></span>') ?>
            <div class="card-body">
                <?= $dashLoading(220) ?>
                <div class="dv-fleet" x-show="chartsLoaded" style="display:none;">
                    <div class="dv-segbar dv-segbar--lg" role="img" :aria-label="viz.fleet.aria">
                        <template x-for="st in viz.fleet.statuses" :key="st.key">
                            <span class="dv-seg" :class="'dv-c--' + st.tone" :style="'flex-grow:' + st.count" :title="st.label + ': ' + st.count"></span>
                        </template>
                    </div>
                    <ul class="dv-legend dv-legend--row">
                        <template x-for="st in viz.fleet.statuses" :key="st.key">
                            <li><span class="dv-dot" :class="'dv-c--' + st.tone"></span><span class="dv-legend-l" x-text="st.label"></span><b x-text="st.count"></b><span class="dv-legend-n" x-text="st.pct"></span></li>
                        </template>
                    </ul>
                    <div class="dv-types">
                        <template x-for="t in viz.fleet.types" :key="t.label">
                            <div class="dv-type">
                                <div class="dv-type-top">
                                    <span class="dv-type-name" x-text="t.label"></span>
                                    <span class="dv-type-note"><b x-text="t.on_lease"></b> of <span x-text="t.total"></span> on lease</span>
                                    <span class="dv-type-pct" x-text="t.pctText"></span>
                                </div>
                                <div class="dv-split" :title="t.on_lease + ' on lease · ' + t.available + ' available · ' + t.other + ' other'">
                                    <span class="dv-c--primary" :style="'flex-grow:' + t.on_lease"></span>
                                    <span class="dv-c--ok" :style="'flex-grow:' + t.available"></span>
                                    <span class="dv-c--muted" :style="'flex-grow:' + t.other"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <div class="dash-grid dash-grid--2-1">
            <div class="card dash-card dv-card">
                <?= $dashHead('chart-bar-square', 'Utilization', 'Occupied ÷ available unit-days, each month',
                    '<span class="dv-head-fig" x-show="chartsLoaded" style="display:none;"><span x-text="viz.util.latest"></span> <small>this month</small></span>') ?>
                <div class="card-body">
                    <?= $dashLoading(250) ?>
                    <div x-show="chartsLoaded" class="chart-wrap"><div id="chart-utilization-trend" style="min-height:250px;" aria-label="Fleet utilization trend chart"></div></div>
                </div>
            </div>
            <!-- Idle units: available units waiting longest (from the lists API) -->
            <div class="card dash-card dv-card">
                <?= $dashHead('clock', 'Sitting idle', 'Available units that have waited longest for a lease') ?>
                <div class="card-body dv-flush">
                    <template x-if="!tablesLoaded && !tablesError">
                        <div class="dash-list-skel" aria-hidden="true"><span></span><span></span><span></span></div>
                    </template>
                    <template x-if="tablesLoaded && !lists.idle.length">
                        <div class="dash-empty">No units are sitting idle.</div>
                    </template>
                    <div class="dash-list">
                        <template x-for="row in (tablesLoaded ? lists.idle : [])" :key="row.key">
                            <a class="dash-item" :href="row.href">
                                <span class="dash-item-av" :class="'dash-tone--' + row.tone" aria-hidden="true"><svg><use href="#icon-truck"/></svg></span>
                                <span class="dash-item-main">
                                    <span class="dash-item-top"><span class="dash-item-id" x-text="row.id"></span><span class="cc-pill" :class="'cc-pill--' + row.pill" x-text="row.pillText"></span></span>
                                    <span class="dash-item-who" x-text="row.who"></span>
                                    <span class="dash-item-sub" x-show="row.sub" x-text="row.sub"></span>
                                </span>
                                <span class="dash-item-side"><span class="dash-item-meta" x-text="row.meta"></span></span>
                            </a>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php $secFleet = ob_get_clean(); ?>

    <?php ob_start(); ?>
    <?php if ($canMoney): ?>
    <!-- ── CUSTOMERS (money roles only — both views are revenue) ── -->
    <section id="dash-customers" class="dash-sec ff-acc--purple">
        <?= $dashSecHead('customers', 'user-group', 'Customers',
            'Who your best customers are this year, and which equipment earns the most.',
            base_url('customers'), 'Customers') ?>
        <div class="dash-grid dash-grid--1-1">
            <div class="card dash-card dv-card">
                <?= $dashHead('user-group', 'Top customers', 'Billed so far this year',
                    '<span class="dv-head-fig" x-show="chartsLoaded && viz.top.share" x-text="viz.top.share" style="display:none;"></span>') ?>
                <div class="card-body dv-flush">
                    <?= $dashLoading(300) ?>
                    <div x-show="chartsLoaded && !viz.top.rows.length" class="dash-empty" style="display:none;">No billing yet this year.</div>
                    <ol class="dv-rank" x-show="chartsLoaded" style="display:none;">
                        <template x-for="r in viz.top.rows" :key="r.key">
                            <li>
                                <div class="dv-rank-row">
                                    <span class="dash-item-av dash-tone--info" x-text="r.av" aria-hidden="true"></span>
                                    <span class="dv-rank-main">
                                        <span class="dv-rank-name" x-text="r.name"></span>
                                        <span class="dv-bar"><span :style="'width:' + r.w + '%'"></span></span>
                                    </span>
                                    <span class="dv-rank-amt"><span x-text="r.amt"></span><small x-text="r.share"></small></span>
                                </div>
                            </li>
                        </template>
                    </ol>
                </div>
            </div>
            <div class="card dash-card dv-card">
                <?= $dashHead('chart-pie', 'Revenue by equipment type', 'Share of this year’s billing') ?>
                <div class="card-body">
                    <?= $dashLoading(300) ?>
                    <div x-show="chartsLoaded && !viz.types.rows.length" class="dash-empty" style="display:none;">Revenue will show here once invoices exist.</div>
                    <div class="dv-shares" x-show="chartsLoaded" style="display:none;">
                        <template x-for="r in viz.types.rows" :key="r.key">
                            <div class="dv-share">
                                <div class="dv-share-top"><span x-text="r.name"></span><b x-text="r.amt"></b></div>
                                <div class="dv-share-track"><span :class="'dv-c--' + r.tone" :style="'width:' + r.w + '%'"></span><em x-text="r.pct"></em></div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>
    <?php $secCustomers = ob_get_clean(); ?>

    <?php ob_start(); ?>
    <!-- ── ACTIVITY ────────────────────────────────────────────── -->
    <section id="dash-activity" class="dash-sec ff-acc--primary">
        <?= $dashSecHead('activity', 'clock', 'Activity', 'The latest changes your team made in FleetForge.') ?>
        <div class="card dash-card dash-activity">
            <div class="card-header dash-card-head">
                <span class="dash-card-ic" aria-hidden="true"><?= SopIcons::svg('list-bullet') ?></span>
                <div class="dash-card-titles"><span class="card-title">Recent Activity</span><span class="dash-card-sub">Newest first</span></div>
            </div>
            <div class="card-body" style="padding:0;">
                <template x-if="!activityLoaded && !activityError">
                    <div class="dash-list-skel" aria-hidden="true"><span></span><span></span><span></span></div>
                </template>
                <template x-if="activityError">
                    <div class="dash-empty">
                        Failed to load activity.
                        <button class="btn btn-ghost btn-sm" @click="fetchActivity()">Retry</button>
                    </div>
                </template>
                <template x-if="activityLoaded && activity.length === 0">
                    <div class="dash-empty">No activity yet — actions appear here as staff use the system.</div>
                </template>
                <template x-if="activityLoaded && activity.length > 0">
                    <ul class="activity-feed dash-timeline" role="list">
                        <template x-for="item in activity" :key="item.id">
                            <li class="activity-item">
                                <span class="activity-dot" :class="'activity-dot--' + item.module" aria-hidden="true"></span>
                                <div class="activity-body">
                                    <span class="activity-desc" x-text="item.description"></span>
                                    <span class="activity-meta text-muted text-xs" x-text="item.user_name + ' · ' + item.time_ago"></span>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>
        </div>
    </section>
    <?php $secActivity = ob_get_clean(); ?>

    <?php
    // Emit the sections in the role's order (see $dashSections).
    $secHtml = ['money' => $secMoney, 'leases' => $secLeases, 'fleet' => $secFleet, 'customers' => $secCustomers, 'activity' => $secActivity];
    foreach ($dashSections as [$sid]) {
        echo $secHtml[$sid];
    }
    ?>

</div><!-- /x-data=FF_Dashboard -->


<!-- ============================================================
     DASHBOARD JAVASCRIPT
     Alpine component + ApexCharts initialisation.
     Defined inline so chart element IDs are guaranteed in DOM
     before the script runs. Alpine picks up x-data="FF_Dashboard()"
     on the outer div above.
     ============================================================ -->
<script>
/**
 * FF_Dashboard — Alpine.js component for the admin dashboard.
 *
 * Responsibilities:
 *   1. Fetch KPI data from api/v1/dashboard/kpis.php
 *   2. Fetch all 8 chart datasets from api/v1/dashboard/charts.php
 *   3. Fetch activity feed from api/v1/dashboard/activity_feed.php
 *   4. Render ApexCharts after data arrives
 *   5. Handle loading skeletons and error states
 *   6. (S-DASHBOARD-REDESIGN) shape the lists into one row form for the
 *      work panels, build the "Needs attention" cards, the hero's summary
 *      line + fleet-pulse ring, and drive the section bar's scroll-spy
 *
 * WHY inline rather than in app.js: chart element IDs must exist in the
 * DOM before ApexCharts renders. Putting this in a module-level init in
 * app.js would require a DOMContentLoaded listener and careful sequencing.
 * Inline keeps the dependency obvious.
 */
function FF_Dashboard() {
    return {
        // ── State ──────────────────────────────────────────────
        kpis:          {},
        kpisLoaded:    false,
        kpiError:      false,

        charts:        {},
        chartsLoaded:  false,
        chartError:    false,

        activity:      [],
        activityLoaded: false,
        activityError:  false,

        // S-DASHBOARD-CAROUSEL-REORGANIZE — added invoices + reservations
        // to the initial empty-array state so the x-if/x-for templates can
        // bind before fetchTables() resolves (avoids "cannot read length of
        // undefined" during first paint).
        tables:        { active_leases: [], pending_leases: [], upcoming_returns: [],
                         invoices: [], reservations: [],
                         expiring_this_month: [], draft_invoices: [], high_value_leases: [],
                         recently_activated: [], overdue_payments: [] },
        tablesLoaded:  false,
        tablesError:   false,

        // ── S-DASHBOARD-REDESIGN state ─────────────────────────
        urls:      <?= json_encode($dashUrls, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        sections:  <?= json_encode(array_column($dashSections, 0)) ?>,
        spy:       '',
        tab:       { money: 'overdue', leases: 'active' },
        // Rows pre-shaped by buildLists() — one form for every panel.
        lists:     { money: { overdue: [], outstanding: [], drafts: [] },
                     leases: { active: [], pending: [], returns: [], expiring: [], recent: [], top: [] },
                     reservations: [], idle: [] },
        emptyText: {
            money:  { overdue: 'Nothing overdue — nice.', outstanding: 'No unpaid invoices.', drafts: 'No drafts waiting to be sent.' },
            leases: { active: 'No active leases.', pending: 'No leases waiting to start.', returns: 'No returns in the next 60 days.',
                      expiring: 'No leases end this month.', recent: 'No leases started in the last 7 days.', top: 'No active leases.' },
        },
        attn:      [],
        attnReady: false,
        summary:   '',
        // Ring arcs as inline styles (stroke-dasharray/offset) — start empty so they sweep in.
        pulse:     { lease: '', avail: '', other: '', otherCount: 0 },
        // S-DASHBOARD-VIZ: display-ready shapes for every chart/visual card,
        // built once by buildViz() — templates read these, never raw API data.
        viz: {
            cash:    { billed: '—', collected: '—', rate: '—', billedDelta: { cls: '', text: '' }, collectedDelta: { cls: '', text: '' } },
            ar:      { total: '—', sub: '', aria: '', buckets: [] },
            overdue: { rows: [], total: '' },
            outlook: { total: '' },
            speed:   { days: '—', note: '', delta: { cls: '', text: '' } },
            flow:    { onRent: '—', change: { cls: '', text: '' } },
            ending:  { months: [], next90: '—', empty: false },
            fleet:   { total: '—', aria: '', statuses: [], types: [] },
            util:    { latest: '—' },
            top:     { rows: [], share: '' },
            types:   { rows: [] },
        },

        // Stored ApexChart instances for theme-update support
        _chartInstances: {},

        // ── Init ───────────────────────────────────────────────
        init() {
            // Fire all four fetches in parallel — no ordering dependency
            this.fetchKpis();
            this.fetchCharts();
            this.fetchActivity();
            this.fetchTables();
            // Section bar scroll-spy. WHY a plain scroll listener (not rAF):
            // requestAnimationFrame never fires in a hidden tab/pane (S-SOP-MODULE).
            window.addEventListener('scroll', () => this.onScroll(), { passive: true });
            // First read after layout settles (see onScroll's zero-height guard).
            setTimeout(() => this.onScroll(), 300);
            // S-DASHBOARD-VIZ: ff-chart-theme's rethemeAll() pushes its default
            // palette into every live chart on a theme toggle, which wipes these
            // charts' per-series colours (billed/collected, started/returned).
            // Redraw after it runs so colours are re-read from the new tokens.
            window.addEventListener('ff:theme-changed', () => {
                if (this.chartsLoaded) setTimeout(() => this.renderAllCharts(), 0);
            });
        },

        // ── KPI fetch ──────────────────────────────────────────
        async fetchKpis() {
            try {
                const res = await FF_Api.get('<?= base_url('api/v1/dashboard/kpis') ?>');
                if (res.success) {
                    this.kpis       = res.data;
                    this.kpisLoaded = true;
                } else {
                    this.kpiError = true;
                }
            } catch (e) {
                this.kpiError = true;
                console.error('[Dashboard] KPI fetch failed', e);
            }
            this.buildAttention();
        },

        // ── Charts fetch ───────────────────────────────────────
        async fetchCharts() {
            try {
                // S-DASHBOARD-VIZ: ask only for what this page draws. The API
                // drops the money datasets for roles without financial access.
                const want = 'cash_flow,receivables,overdue_customers,revenue_forecast,payment_speed,'
                           + 'lease_flow,lease_expiry_calendar,fleet_mix,utilization_trend,top_customers,revenue_by_type';
                const res = await FF_Api.get('<?= base_url('api/v1/dashboard/charts') ?>?charts=' + want);
                if (res.success) {
                    this.charts       = res.data || {};
                    this.buildViz();
                    this.chartsLoaded = true;
                    // Wait one tick for x-show to reveal chart divs, then render
                    this.$nextTick(() => this.renderAllCharts());
                } else {
                    this.chartError = true;
                }
            } catch (e) {
                this.chartError = true;
                console.error('[Dashboard] Charts fetch failed', e);
            }
        },

        // ── Tables fetch ───────────────────────────────────────
        async fetchTables() {
            this.tablesError = false;               // a Retry clears the old error first
            try {
                const res = await FF_Api.get('<?= base_url('api/v1/dashboard/tables') ?>');
                if (res.success) {
                    this.tables       = res.data;
                    this.buildLists();
                    this.tablesLoaded = true;
                } else {
                    this.tablesError = true;
                }
            } catch (e) {
                this.tablesError = true;
                console.error('[Dashboard] Tables fetch failed', e);
            }
            this.buildAttention();
        },

        // ── Activity feed fetch ────────────────────────────────
        async fetchActivity() {
            try {
                const res = await FF_Api.get('<?= base_url('api/v1/dashboard/activity_feed') ?>');
                if (res.success) {
                    this.activity       = res.data.items;
                    this.activityLoaded = true;
                } else {
                    this.activityError = true;
                }
            } catch (e) {
                this.activityError = true;
                console.error('[Dashboard] Activity fetch failed', e);
            }
        },

        // ── Render the ApexCharts (S-DASHBOARD-VIZ) ────────────
        /**
         * Five charts remain — the ones where a time series really is the
         * clearest picture: billed vs collected, the 6-month outlook, the
         * days-to-pay sparkline, starting vs returning, and utilization.
         * Everything else on the page is plain HTML built from viz.*.
         * Every chart goes through FF_CHART_THEME, so it follows the theme
         * toggle and the brand colour; colours are read from CSS tokens.
         */
        renderAllCharts() {
            const d = this.charts || {};
            Object.values(this._chartInstances || {}).forEach((ch) => { try { ch.destroy(); } catch (e) { /* already gone */ } });
            const c = this._chartInstances = {};

            const cs      = getComputedStyle(document.documentElement);
            const tok     = (v, fb) => cs.getPropertyValue(v).trim() || fb;
            const primary = tok('--color-primary', '#2596be');
            const success = tok('--color-success', '#22c55e');
            const warning = tok('--color-warning', '#eab308');
            const info    = tok('--color-info', '#06b6d4');
            const muted   = tok('--text-tertiary', '#64748b');
            const k       = (v) => this.moneyK(v);
            const draw    = (id, key, opts) => {
                const el = document.getElementById(id);
                if (!el) return;
                el.innerHTML = '';
                c[key] = new ApexCharts(el, FF_CHART_THEME(opts));
                c[key].render();
            };
            const axis = { labels: { style: { colors: muted, fontSize: '11px' } } };
            const grid = { strokeDashArray: 4, padding: { top: 0, right: 10, bottom: 0, left: 6 }, xaxis: { lines: { show: false } }, yaxis: { lines: { show: true } } };

            // 1. Billed vs collected — columns + a smooth line, shared tooltip.
            if (d.cash_flow) {
                draw('chart-cash-flow', 'cash_flow', {
                    chart: { type: 'line', height: 270 },
                    series: [
                        { name: 'Billed',    type: 'column', data: d.cash_flow.billed },
                        { name: 'Collected', type: 'line',   data: d.cash_flow.collected },
                    ],
                    colors: [primary, success],
                    stroke: { width: [0, 3], curve: 'smooth' },
                    fill: { type: ['gradient', 'solid'], gradient: { type: 'vertical', opacityFrom: 0.95, opacityTo: 0.55, stops: [0, 100] } },
                    plotOptions: { bar: { columnWidth: '46%', borderRadius: 6, borderRadiusApplication: 'end' } },
                    markers: { size: [0, 4], strokeWidth: 2, strokeColors: tok('--bg-surface', '#111'), hover: { size: 6 } },
                    labels: d.cash_flow.labels,
                    xaxis: Object.assign({ categories: d.cash_flow.labels, tickPlacement: 'on' }, axis,
                        { labels: { style: { colors: muted, fontSize: '11px' }, formatter: (v) => String(v || '').slice(0, 3) } }),
                    yaxis: { labels: { style: { colors: muted, fontSize: '11px' }, formatter: k }, tickAmount: 4 },
                    grid,
                    legend: { show: false },
                    tooltip: { shared: true, intersect: false, y: { formatter: (v) => '$' + this.money0(v) } },
                });
            }

            // 2. Coming up — the next 6 months of billing from active leases.
            if (d.revenue_forecast) {
                const data = (d.revenue_forecast.series && d.revenue_forecast.series[0] && d.revenue_forecast.series[0].data) || [];
                draw('chart-revenue-forecast', 'revenue_forecast', {
                    chart: { type: 'area', height: 250 },
                    series: [{ name: 'Expected billing', data }],
                    colors: [success],
                    stroke: { width: 3, curve: 'smooth' },
                    fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02, stops: [0, 95] } },
                    markers: { size: 4, strokeWidth: 2, strokeColors: tok('--bg-surface', '#111'), hover: { size: 6 } },
                    dataLabels: { enabled: true, offsetY: -8, formatter: k, background: { enabled: false }, style: { fontSize: '11px', fontWeight: 600, colors: [tok('--text-secondary', '#94a3b8')] } },
                    xaxis: Object.assign({ categories: d.revenue_forecast.labels }, axis),
                    yaxis: { show: false, min: 0 },
                    grid: Object.assign({}, grid, { yaxis: { lines: { show: false } }, padding: { top: 14, right: 18, bottom: 0, left: 18 } }),
                    tooltip: { y: { formatter: (v) => '$' + this.money0(v) } },
                });
            }

            // 3. Days to pay — a quiet sparkline under the big number.
            if (d.payment_speed) {
                const data = (d.payment_speed.series && d.payment_speed.series[0] && d.payment_speed.series[0].data) || [];
                draw('chart-payment-speed', 'payment_speed', {
                    chart: { type: 'area', height: 170, sparkline: { enabled: true } },
                    series: [{ name: 'Days to pay', data }],
                    labels: d.payment_speed.labels,
                    colors: [info],
                    stroke: { width: 2.5, curve: 'smooth' },
                    fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.02, stops: [0, 100] } },
                    markers: { size: 0, hover: { size: 5 } },
                    tooltip: { x: { show: true }, y: { formatter: (v) => (v === null || v === undefined) ? 'No payments' : Math.round(v) + ' days' } },
                });
            }

            // 4. Starting vs returning — diverging columns around zero.
            if (d.lease_flow) {
                draw('chart-lease-flow', 'lease_flow', {
                    chart: { type: 'bar', height: 260, stacked: true },
                    series: [
                        { name: 'Started',  data: d.lease_flow.opened },
                        { name: 'Returned', data: (d.lease_flow.closed || []).map((v) => -Math.abs(v)) },
                    ],
                    colors: [success, warning],
                    plotOptions: { bar: { columnWidth: '52%', borderRadius: 4, borderRadiusApplication: 'end' } },
                    xaxis: Object.assign({ categories: d.lease_flow.labels }, axis,
                        { labels: { style: { colors: muted, fontSize: '11px' }, formatter: (v) => String(v || '').slice(0, 3) } }),
                    yaxis: { labels: { style: { colors: muted, fontSize: '11px' }, formatter: (v) => Math.abs(Math.round(v)) } },
                    grid,
                    legend: { show: true, position: 'top', horizontalAlign: 'right', fontSize: '11px' },
                    tooltip: { shared: true, intersect: false, y: { formatter: (v) => Math.abs(v) + (Math.abs(v) === 1 ? ' lease' : ' leases') } },
                });
            }

            // 5. Utilization — area with a dashed 12-month average line.
            if (d.utilization_trend) {
                const data = (d.utilization_trend.series && d.utilization_trend.series[0] && d.utilization_trend.series[0].data) || [];
                const nums = data.map(Number).filter((v) => !isNaN(v));
                const avg  = nums.length ? nums.reduce((a, b) => a + b, 0) / nums.length : null;
                draw('chart-utilization-trend', 'utilization_trend', {
                    chart: { type: 'area', height: 250 },
                    series: [{ name: 'Utilization', data }],
                    colors: [primary],
                    stroke: { width: 3, curve: 'smooth' },
                    fill: { type: 'gradient', gradient: { opacityFrom: 0.32, opacityTo: 0.02, stops: [0, 100] } },
                    markers: { size: 0, hover: { size: 5 } },
                    xaxis: Object.assign({ categories: d.utilization_trend.labels }, axis,
                        { labels: { style: { colors: muted, fontSize: '11px' }, formatter: (v) => String(v || '').slice(0, 3) } }),
                    yaxis: { min: 0, max: 100, tickAmount: 4, labels: { style: { colors: muted, fontSize: '11px' }, formatter: (v) => Math.round(v) + '%' } },
                    grid,
                    annotations: avg === null ? {} : { yaxis: [{ y: avg, borderColor: muted, strokeDashArray: 5,
                        label: { text: 'avg ' + Math.round(avg) + '%', position: 'left', offsetX: 44, borderWidth: 0,
                                 style: { background: 'transparent', color: muted, fontSize: '11px' } } }] },
                    tooltip: { y: { formatter: (v) => Math.round(v) + '%' } },
                });
            }

            // S-DASHBOARD-CHART-POLISH: register instances at window scope so
            // the sidebar-toggle reflow handler in app.js can iterate them
            // without poking at Alpine internals. Idempotent — last render wins.
            window.FF_DashboardCharts = c;
        },

        /**
         * Shape every chart dataset into what its card shows (S-DASHBOARD-VIZ).
         * Datasets may be missing (redacted for the role, or not built) —
         * each block keeps its placeholder then. Display maths only; money
         * figures arrive CAD-canonical from the API.
         */
        buildViz() {
            const d = this.charts || {}, U = this.urls;
            const num = (v) => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
            const money = (v) => '$' + this.money0(v);

            if (d.cash_flow) {
                const tm = d.cash_flow.this_month || {}, pm = d.cash_flow.prev_month || {};
                const sum = (arr) => (arr || []).reduce((a, b) => a + num(b), 0);
                const billed = sum(d.cash_flow.billed), coll = sum(d.cash_flow.collected);
                this.viz.cash = {
                    billed: money(tm.billed), collected: money(tm.collected),
                    rate: billed > 0 ? Math.round(coll / billed * 100) + '%' : '—',
                    billedDelta: this.deltaPct(num(tm.billed), num(pm.billed), true),
                    collectedDelta: this.deltaPct(num(tm.collected), num(pm.collected), true),
                };
            }

            if (d.receivables) {
                const r = d.receivables, tones = { current: 'ok', d1_30: 'info', d31_60: 'warn', d61_90: 'orange', d90: 'danger' };
                const bs = (r.buckets || []).map((b) => ({
                    key: b.key, label: b.label, tone: tones[b.key] || 'muted', w: Math.max(0, num(b.amount)),
                    amt: money(b.amount), countText: num(b.count) + (num(b.count) === 1 ? ' invoice' : ' invoices'),
                }));
                this.viz.ar = {
                    total: money(r.total),
                    sub: num(r.invoice_count) + ' invoices · ' + num(r.customer_count) + (num(r.customer_count) === 1 ? ' customer' : ' customers'),
                    aria: bs.map((b) => b.label + ' ' + b.amt).join(', '),
                    buckets: bs,
                };
            }

            if (d.overdue_customers) {
                const rows = d.overdue_customers.rows || [];
                const max = rows.reduce((m, x) => Math.max(m, num(x.amount)), 0) || 1;
                this.viz.overdue = {
                    total: num(d.overdue_customers.total_overdue) > 0 ? money(d.overdue_customers.total_overdue) + ' overdue' : '',
                    rows: rows.map((x) => ({
                        key: 'oc' + x.customer_id, href: U.customer + x.customer_id, name: x.name, av: this.initials(x.name),
                        sub: num(x.invoice_count) + (num(x.invoice_count) === 1 ? ' invoice' : ' invoices') + ' · oldest ' + num(x.oldest_days) + ' days late',
                        amt: money(x.amount), w: Math.max(4, Math.round(num(x.amount) / max * 100)),
                    })),
                };
            }

            if (d.revenue_forecast) {
                const data = (d.revenue_forecast.series && d.revenue_forecast.series[0] && d.revenue_forecast.series[0].data) || [];
                this.viz.outlook = { total: data.length ? this.moneyK(data.reduce((a, b) => a + num(b), 0)) + ' over 6 months' : '' };
            }

            if (d.payment_speed) {
                const vals = ((d.payment_speed.series && d.payment_speed.series[0] && d.payment_speed.series[0].data) || [])
                    .filter((v) => v !== null && v !== undefined).map(num);
                const avg = (a) => a.length ? a.reduce((x, y) => x + y, 0) / a.length : null;
                const last3 = avg(vals.slice(-3)), prev3 = avg(vals.slice(-6, -3));
                let delta = { cls: '', text: '' };
                if (last3 !== null && prev3 !== null && Math.round(prev3 - last3) !== 0) {
                    const diff = Math.round(prev3 - last3);
                    delta = diff > 0 ? { cls: 'is-good', text: '▼ ' + diff + (diff === 1 ? ' day' : ' days') + ' faster than the 3 months before' }
                                     : { cls: 'is-bad',  text: '▲ ' + (-diff) + (-diff === 1 ? ' day' : ' days') + ' slower than the 3 months before' };
                }
                this.viz.speed = {
                    days: last3 === null ? '—' : String(Math.round(last3)),
                    note: last3 === null ? 'no payments yet' : 'days on average, last 3 months',
                    delta,
                };
            }

            if (d.lease_flow) {
                const onRent = d.lease_flow.on_rent || [];
                const now = num(onRent[onRent.length - 1]), then = num(onRent[0]), ch = now - then;
                this.viz.flow = {
                    onRent: String(now),
                    change: onRent.length < 2 || ch === 0 ? { cls: '', text: 'flat over 12 months' }
                          : { cls: ch > 0 ? 'is-good' : 'is-bad', text: (ch > 0 ? '▲ ' : '▼ ') + Math.abs(ch) + ' in 12 months' },
                };
            }

            if (d.lease_expiry_calendar) {
                const labels = d.lease_expiry_calendar.labels || [];
                const data = ((d.lease_expiry_calendar.series && d.lease_expiry_calendar.series[0] && d.lease_expiry_calendar.series[0].data) || []).map(num);
                const max = data.reduce((m, v) => Math.max(m, v), 0) || 1;
                this.viz.ending = {
                    empty: data.reduce((a, b) => a + b, 0) === 0,
                    next90: String(data.slice(0, 3).reduce((a, b) => a + b, 0)),
                    months: labels.map((l, i) => ({
                        key: 'm' + i, label: String(l).replace(/ (\d{2})(\d{2})$/, ' ’$2'), count: data[i] || 0,
                        i: ((data[i] || 0) / max).toFixed(2), now: i === 0, href: i === 0 ? U.ending : null,
                    })),
                };
            }

            if (d.fleet_mix) {
                const f = d.fleet_mix, total = num(f.total) || 0;
                const tones = { on_lease: 'primary', available: 'ok', reserved: 'info', maintenance: 'warn', inactive: 'muted' };
                const sts = (f.statuses || []).map((x) => ({
                    key: x.key, label: x.label, count: num(x.count), tone: tones[x.key] || 'muted',
                    pct: total ? Math.round(num(x.count) / total * 100) + '%' : '0%',
                }));
                this.viz.fleet = {
                    total: String(total),
                    aria: sts.map((x) => x.label + ' ' + x.count).join(', '),
                    statuses: sts,
                    types: (f.types || []).map((t) => ({
                        label: t.label, total: num(t.total), on_lease: num(t.on_lease), available: num(t.available), other: num(t.other),
                        pctText: Math.round(num(t.pct_on_lease)) + '%',
                    })),
                };
            }

            if (d.utilization_trend) {
                const data = (d.utilization_trend.series && d.utilization_trend.series[0] && d.utilization_trend.series[0].data) || [];
                this.viz.util = { latest: data.length ? Math.round(num(data[data.length - 1])) + '%' : '—' };
            }

            if (d.top_customers) {
                const labels = d.top_customers.labels || [];
                const data = ((d.top_customers.series && d.top_customers.series[0] && d.top_customers.series[0].data) || []).map(num);
                const ytd = num(d.top_customers.ytd_total), max = data.reduce((m, v) => Math.max(m, v), 0) || 1;
                const topSum = data.reduce((a, b) => a + b, 0);
                this.viz.top = {
                    share: ytd > 0 && data.length ? 'Top ' + data.length + ' = ' + Math.round(topSum / ytd * 100) + '% of the year' : '',
                    rows: labels.map((name, i) => ({
                        key: 'tc' + i, name, av: this.initials(name), amt: money(data[i]),
                        share: ytd > 0 ? Math.round(data[i] / ytd * 100) + '%' : '', w: Math.max(4, Math.round(data[i] / max * 100)),
                    })),
                };
            }

            if (d.revenue_by_type) {
                const labels = d.revenue_by_type.labels || [];
                const raw = d.revenue_by_type.series || [];
                const data = (Array.isArray(raw) && typeof raw[0] === 'object' && raw[0] !== null ? (raw[0].data || []) : raw).map(num);
                const total = data.reduce((a, b) => a + b, 0) || 1, max = data.reduce((m, v) => Math.max(m, v), 0) || 1;
                const tones = ['primary', 'ok', 'warn', 'info', 'orange', 'danger'];
                this.viz.types = {
                    rows: labels.map((name, i) => ({ key: 'rt' + i, name, amt: money(data[i]), tone: tones[i % tones.length],
                        pct: Math.round(data[i] / total * 100) + '%', w: Math.max(3, Math.round(data[i] / max * 100)) }))
                        .sort((a, b) => parseFloat(b.w) - parseFloat(a.w)),
                };
            }
        },

        // ── S-DASHBOARD-REDESIGN builders ─────────────────────

        /**
         * Shape every list from api/v1/dashboard/tables into one row form:
         * {key, href, av, tone, id, pill, pillText, who, sub, amt, meta}.
         * tone = ok|info|warn|danger (avatar tint); pill = cc-pill variant.
         * Money fields may be absent (redacted for the role) → amt stays ''.
         */
        buildLists() {
            const t   = this.tables || {};
            const U   = this.urls;
            const n   = (v) => parseInt(v, 10) || 0;
            const cap = (s) => s ? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ') : '';
            const rate = (r) => {
                if (this.has(r.monthly_rate) && parseFloat(r.monthly_rate) > 0) return '$' + this.money0(r.monthly_rate) + '/mo';
                if (this.has(r.daily_rate) && parseFloat(r.daily_rate) > 0) return '$' + parseFloat(r.daily_rate).toFixed(2) + '/day';
                return '';
            };
            const amt  = (v, suffix) => this.has(v) ? '$' + this.formatMoney(v) + (suffix || '') : '';
            const kind = (r) => [r.template_name_snapshot, r.unit_number].filter(Boolean).join(' · ');
            const left = (d) => d <= 0 ? 'Due today' : d + (d === 1 ? ' day left' : ' days left');
            const pillOf = (tone) => ({ ok: 'active', info: 'info', warn: 'warning', danger: 'danger' })[tone];
            const lease = (r, k, extra) => Object.assign({
                key: k + r.id, href: U.lease + r.id, id: r.contract_number, who: r.customer_name,
                av: this.initials(r.customer_name), sub: kind(r), amt: rate(r),
            }, extra, { pill: pillOf(extra.tone) });
            const inv = (r, k, extra) => Object.assign({
                key: k + r.id, href: U.invoice + r.id, id: r.invoice_number, who: r.customer_name,
                av: this.initials(r.customer_name), sub: '',
            }, extra, { pill: pillOf(extra.tone) });

            this.lists = {
                leases: {
                    active: (t.active_leases || []).map(r => lease(r, 'a', {
                        tone: 'ok', pillText: 'Active', meta: 'Since ' + this.fmtDate(r.start_date) })),
                    pending: (t.pending_leases || []).map(r => {
                        const late = n(r.days_overdue) > 0;
                        return lease(r, 'p', { tone: late ? 'danger' : 'warn',
                            pillText: late ? r.days_overdue + ' days late' : 'Pending',
                            sub: r.unit_number || 'No unit assigned yet', meta: 'Starts ' + this.fmtDate(r.start_date) });
                    }),
                    returns: (t.upcoming_returns || []).map(r => {
                        const d = n(r.days_remaining);
                        return lease(r, 'r', { d, tone: d <= 3 ? 'danger' : (d <= 7 ? 'warn' : 'info'),
                            pillText: left(d), meta: 'Returns ' + this.fmtDate(r.end_date) });
                    }),
                    expiring: (t.expiring_this_month || []).map(r => {
                        const d = n(r.days_remaining);
                        return lease(r, 'e', { tone: d <= 7 ? 'danger' : 'warn', pillText: left(d), meta: 'Ends ' + this.fmtDate(r.end_date) });
                    }),
                    recent: (t.recently_activated || []).map(r => lease(r, 'n', {
                        tone: 'ok', pillText: n(r.days_active) === 0 ? 'Today' : r.days_active + ' days ago',
                        meta: 'Started ' + this.fmtDate(r.start_date) })),
                    top: (t.high_value_leases || []).map(r => lease(r, 't', {
                        tone: 'ok', pillText: 'Active', meta: 'Since ' + this.fmtDate(r.start_date) })),
                },
                money: {
                    overdue: (t.overdue_payments || []).map(r => inv(r, 'o', {
                        tone: 'danger', pillText: r.days_overdue + ' days overdue',
                        amt: amt(r.balance_due, ' owing'), meta: 'Due ' + this.fmtDate(r.due_date) })),
                    // WHY 'partially_paid' not 'partial': the DB enum uses partially_paid
                    outstanding: (t.invoices || []).map(r => inv(r, 'i', {
                        tone: r.status === 'overdue' ? 'danger' : (r.status === 'partially_paid' ? 'warn' : 'info'),
                        pillText: r.status === 'partially_paid' ? 'Partial' : cap(r.status),
                        amt: amt(r.balance_due, ' owing'), meta: 'Due ' + this.fmtDate(r.due_date) })),
                    drafts: (t.draft_invoices || []).map(r => inv(r, 'd', {
                        tone: 'warn', pillText: 'Draft', amt: amt(r.total_amount),
                        meta: n(r.days_in_draft) + (n(r.days_in_draft) === 1 ? ' day' : ' days') + ' in draft' })),
                },
                // S-DASHBOARD-VIZ: available units waiting longest (tables idle_units).
                idle: (t.idle_units || []).map(r => {
                    const days = (r.idle_days === null || r.idle_days === undefined) ? null : n(r.idle_days);
                    const tone = days === null ? 'info' : (days > 60 ? 'danger' : (days > 30 ? 'warn' : 'info'));
                    return { key: 'u' + r.id, href: U.unit + r.id, id: r.unit_number, who: r.type || 'Unit',
                        sub: [r.category, r.yard].filter(Boolean).join(' · '), tone, pill: pillOf(tone),
                        pillText: days === null ? 'Not leased yet' : 'Idle ' + days + (days === 1 ? ' day' : ' days'),
                        meta: r.idle_since ? 'Since ' + this.fmtDate(r.idle_since) : '' };
                }),
                reservations: (t.reservations || []).map(r => {
                    const d = n(r.days_until_pickup), q = n(r.quantity), tone = r.status === 'confirmed' ? 'ok' : 'info';
                    return { key: 'v' + r.id, href: U.reservation + r.id, id: r.reservation_number, who: r.customer_name,
                        av: this.initials(r.customer_name), sub: q + (q === 1 ? ' unit' : ' units') + ' reserved',
                        tone, pill: pillOf(tone), pillText: cap(r.status), amt: '',
                        meta: (d === 0 ? 'Pickup today' : (d === 1 ? 'Pickup tomorrow' : 'Pickup ' + this.fmtDate(r.pickup_date)))
                              + (r.pickup_time ? ' · ' + r.pickup_time.substring(0, 5) : '') };
                }),
            };
        },

        /**
         * The hero summary line, the fleet-pulse ring and the "Needs
         * attention" cards. Called after KPIs and after lists land (either
         * order); cards from the lists appear once those arrive.
         */
        buildAttention() {
            if (!this.kpisLoaded) {
                this.attnReady = this.kpiError && (this.tablesLoaded || this.tablesError);
                return;
            }
            const k = this.kpis || {}, L = this.lists, U = this.urls, out = [];
            const n = (v) => parseInt(v, 10) || 0;
            const count = (list) => list.length >= 10 ? '10+' : list.length;   // the lists API caps at 10 rows

            // Hero summary — one plain sentence.
            const tot = n(k.total_active_units), on = n(k.on_lease_count), av = n(k.available_units), pk = n(k.todays_pickups);
            const bits = [];
            if (av) bits.push(av + (av === 1 ? ' is' : ' are') + ' ready to rent');
            if (pk) bits.push(pk + (pk === 1 ? ' pickup is' : ' pickups are') + ' booked for today');
            this.summary = tot
                ? on + ' of ' + tot + ' units are out on lease' + (bits.length ? ', ' + bits.join(' and ') : '') + '.'
                : 'No units in the fleet yet — add equipment to get started.';

            // Fleet pulse — three arcs around r=84 (circumference 2πr), 3px gaps.
            const C = 2 * Math.PI * 84, other = Math.max(0, tot - on - av);
            let offset = 0;
            const arc = (v) => {
                if (!tot || !v) return 'stroke-dasharray:0 ' + C;
                const len = C * v / tot, gap = len > 6 ? 3 : 0;
                const s = 'stroke-dasharray:' + (len - gap).toFixed(2) + ' ' + C.toFixed(2) + ';stroke-dashoffset:' + (-offset).toFixed(2);
                offset += len;
                return s;
            };
            this.pulse = { lease: arc(on), avail: arc(av), other: arc(other), otherCount: other };

            // Needs attention — most urgent first; only what applies.
            const od = k.overdue_invoices || {};
            if (n(od.count)) out.push({ key: 'overdue', tone: 'danger', icon: 'exclamation-triangle', count: n(od.count),
                label: n(od.count) === 1 ? 'Overdue invoice' : 'Overdue invoices',
                detail: this.has(od.total) ? '$' + this.money0(od.total) + ' past its due date' : 'Past their due date',
                cta: 'Chase payment', href: U.overdue });
            if (this.tablesLoaded) {
                const late = L.leases.pending.filter(r => r.tone === 'danger').length;
                if (L.leases.pending.length) out.push({ key: 'pending', tone: late ? 'danger' : 'warn', icon: 'clock',
                    count: count(L.leases.pending), label: 'Leases waiting to start',
                    detail: late ? late + ' past their start date' : 'Activate each one when the unit goes out',
                    cta: 'Review', href: U.pending });
                if (L.money.drafts.length) out.push({ key: 'drafts', tone: 'warn', icon: 'document-text',
                    count: count(L.money.drafts), label: L.money.drafts.length === 1 ? 'Draft invoice' : 'Draft invoices',
                    detail: 'Check them and send', cta: 'Send invoices', href: U.drafts });
                const week = L.leases.returns.filter(r => r.d <= 7);
                if (week.length) out.push({ key: 'returns', tone: 'info', icon: 'truck',
                    count: week.length >= 10 ? '10+' : week.length, label: week.length === 1 ? 'Return this week' : 'Returns this week',
                    detail: 'Plan the yard space and check-in inspections', cta: 'See returns', href: U.returns });
            }
            if (pk) out.push({ key: 'pickups', tone: 'info', icon: 'map-pin', count: pk,
                label: pk === 1 ? 'Pickup today' : 'Pickups today', detail: 'Units going out today',
                cta: 'Open reservations', href: U.pickups });
            if (n(k.compliance_alerts)) out.push({ key: 'compliance', tone: 'warn', icon: 'shield-check', count: n(k.compliance_alerts),
                label: 'Renewals due', detail: 'Unit documents expiring within 30 days', cta: 'Open compliance', href: U.compliance });
            if (n(k.open_damage_claims)) out.push({ key: 'claims', tone: 'danger', icon: 'fire', count: n(k.open_damage_claims),
                label: n(k.open_damage_claims) === 1 ? 'Open damage claim' : 'Open damage claims',
                detail: 'Waiting on repair or billing', cta: 'Review claims', href: U.claims });
            if (n(k.open_work_orders)) out.push({ key: 'workorders', tone: 'warn', icon: 'wrench', count: n(k.open_work_orders),
                label: n(k.open_work_orders) === 1 ? 'Open work order' : 'Open work orders',
                detail: 'Units in the shop or waiting for repair', cta: 'Open maintenance', href: U.workorders });

            this.attn      = out;
            this.attnReady = this.tablesLoaded || this.tablesError;
        },

        /** Scroll-spy: the section whose top has passed under the sticky bar. */
        onScroll() {
            let cur = '';
            for (const id of this.sections) {
                const el = document.getElementById('dash-' + id);
                if (!el) continue;
                const r = el.getBoundingClientRect();
                // WHY the height guard: while the page is still hidden/unlaid-out
                // every rect is 0×0 at top 0, which read as "scrolled past every
                // section" and lit the last tab on load.
                if (r.height > 0 && r.top < 190) cur = id;
            }
            this.spy = cur;
        },

        /** Section bar click: smooth-scroll to the section (scroll-margin clears the bar). */
        jump(id) {
            const el = document.getElementById('dash-' + id);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            this.spy = id;
        },

        // ── Helpers ────────────────────────────────────────────

        /** Is this a real number (not missing / redacted)? */
        has(v) {
            return v !== undefined && v !== null && v !== '' && !isNaN(parseFloat(v));
        },

        /** Compact money for axes and headlines: $840, $12.4k, $1.2M (display only). */
        moneyK(val) {
            const n = parseFloat(val);
            if (isNaN(n)) return '$0';
            const a = Math.abs(n), sign = n < 0 ? '-' : '';
            if (a >= 1e6) return sign + '$' + (a / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
            if (a >= 1e4) return sign + '$' + Math.round(a / 1e3) + 'k';
            if (a >= 1e3) return sign + '$' + (a / 1e3).toFixed(1).replace(/\.0$/, '') + 'k';
            return sign + '$' + Math.round(a);
        },

        /**
         * "▲ 12% vs last month" with a good/bad class. upIsGood says which
         * direction is good news for this figure.
         */
        deltaPct(cur, prev, upIsGood) {
            if (!prev) return { cls: '', text: cur ? 'nothing last month' : '' };
            const pct = Math.round((cur - prev) / Math.abs(prev) * 100);
            if (pct === 0) return { cls: '', text: 'same as last month' };
            const good = (pct > 0) === upIsGood;
            return { cls: good ? 'is-good' : 'is-bad', text: (pct > 0 ? '▲ ' : '▼ ') + Math.abs(pct) + '% vs last month' };
        },

        /** Whole-dollar display (tiles, cards) — display only, never money math. */
        money0(val) {
            const n = parseFloat(val);
            if (isNaN(n)) return '0';
            return n.toLocaleString('en-CA', { maximumFractionDigits: 0 });
        },

        /** Two-letter initials for a list avatar ("Rolls Right Industries Ltd" → "RR"). */
        initials(name) {
            const skip = ['inc', 'ltd', 'llc', 'corp', 'co', 'the', 'and'];
            const w = String(name || '').split(/[\s\-&.,]+/).filter(x => x && !skip.includes(x.toLowerCase()));
            if (!w.length) return '?';
            return ((w[0][0] || '') + (w[1] ? w[1][0] : (w[0][1] || ''))).toUpperCase();
        },

        /** Tab badge: the lists API returns at most 10 rows, so 10 means "10 or more". */
        countLabel(list) {
            const n = (list || []).length;
            return n >= 10 ? '10+' : String(n);
        },


        /**
         * Format a numeric or string value as a comma-separated money string.
         * WHY JS: format_currency() is PHP-side; for dynamic Alpine values we
         * need a client-side formatter that matches the server's output style.
         */
        formatMoney(val) {
            const n = parseFloat(val);
            if (isNaN(n)) return '0.00';
            return n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        fmtDate(d) {
            if (!d) return '—';
            return new Date(d + 'T00:00:00').toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
