<?php declare(strict_types=1);

/**
 * FleetForge — Accounting Periods
 *
 * @file        app/admin/accounting/periods/index.php
 * @description Accounting period management page with 4 KPI tiles (Total, Open,
 *              Closed, Locked), year filter dropdown, and a 12-month card grid
 *              showing each period's status, dates, JE count, and action buttons
 *              for closing and locking periods. Actions driven by Alpine.js via
 *              FF_Api calls to the accounting periods API.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/accounting/periods,
 *              api/v1/accounting/periods/close, api/v1/accounting/periods/lock
 */

// dirname(__DIR__, 4): periods/ -> accounting/ -> admin/ -> app/ -> root
require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('period_management', 'view');

// ── KPI tiles — server-side for accuracy on initial load ───────
$totalPeriods  = db_count("SELECT COUNT(*) FROM acc_periods");
$openPeriods   = db_count("SELECT COUNT(*) FROM acc_periods WHERE status = 'open'");
$closedPeriods = db_count("SELECT COUNT(*) FROM acc_periods WHERE status = 'closed'");
$lockedPeriods = db_count("SELECT COUNT(*) FROM acc_periods WHERE status = 'locked'");

$pageTitle = 'Accounting Periods';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ── Breadcrumb ──────────────────────────────────────────────── -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Periods</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Accounting Periods</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- TILES-1: KPI tiles dispatch `ff-periods-filter` to set a client-side
     statusFilter that x-show filters the 12 period cards below. -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card stat-card--blue" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-periods-filter',{detail:{status:''}}))">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Total Periods</div>
        <div class="stat-value font-mono"><?= e((string) $totalPeriods) ?></div>
    </div>
    <div class="stat-card stat-card--green" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-periods-filter',{detail:{status:'open'}}))">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Open</div>
        <div class="stat-value font-mono"><?= e((string) $openPeriods) ?></div>
    </div>
    <div class="stat-card stat-card--amber" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-periods-filter',{detail:{status:'closed'}}))">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Closed</div>
        <div class="stat-value font-mono"><?= e((string) $closedPeriods) ?></div>
    </div>
    <div class="stat-card stat-card--red" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-periods-filter',{detail:{status:'locked'}}))">
        <span class="stat-icon stat-icon--red"><svg><use href="#icon-shield-check"/></svg></span>
        <div class="stat-label">Locked</div>
        <div class="stat-value font-mono"><?= e((string) $lockedPeriods) ?></div>
    </div>
</div>

<!-- ============================================================
     ACCOUNTING PERIODS — ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_Periods()"
     @ff-periods-filter.window="statusFilter = (statusFilter === $event.detail.status && statusFilter !== '') ? '' : $event.detail.status">

    <!-- ── Year Filter ────────────────────────────────────────── -->
    <div class="table-toolbar" style="margin-bottom:20px;">
        <div class="table-toolbar-left">
            <label class="form-label" style="margin:0;margin-right:8px;font-weight:600;">Year:</label>
            <select class="form-select form-control-sm"
                    x-model="year"
                    @change="load()"
                    aria-label="Filter by year"
                    style="min-width:120px;">
                <template x-for="y in yearOptions" :key="y">
                    <option :value="y" x-text="y"></option>
                </template>
            </select>
        </div>
        <div class="table-toolbar-right">
            <span class="text-secondary text-sm" x-text="periods.length + ' period' + (periods.length !== 1 ? 's' : '')"></span>
        </div>
    </div>

    <!-- ── Loading skeleton ───────────────────────────────────── -->
    <template x-if="loading">
        <div class="stat-grid" aria-busy="true" aria-label="Loading periods...">
            <template x-for="n in 12" :key="n">
                <div class="card" style="min-height:160px;">
                    <div class="skeleton skeleton-row"></div>
                    <div class="skeleton skeleton-row" style="width:60%;"></div>
                    <div class="skeleton skeleton-row" style="width:40%;"></div>
                </div>
            </template>
        </div>
    </template>

    <!-- ── Error state ────────────────────────────────────────── -->
    <template x-if="!loading && loadError">
        <div class="card">
            <div class="empty-state">
                <p class="empty-state-title">Failed to load periods</p>
                <p class="empty-state-text" x-text="loadError"></p>
                <button class="btn btn-secondary btn-sm" @click="load()">Retry</button>
            </div>
        </div>
    </template>

    <!-- ── Empty state ────────────────────────────────────────── -->
    <template x-if="!loading && !loadError && periods.length === 0">
        <div class="card">
            <div class="empty-state">
                <p class="empty-state-title">No periods found</p>
                <p class="empty-state-text">No accounting periods exist for this year. Periods are created automatically by the system.</p>
            </div>
        </div>
    </template>

    <!-- ── Period Grid — 12 month cards ───────────────────────── -->
    <template x-if="!loading && !loadError && periods.length > 0">
        <div class="stat-grid">
            <template x-for="period in periods" :key="period.id">
                <div class="card" style="padding:20px;"
                     x-show="!statusFilter || period.status === statusFilter">

                    <!-- Header: month name + status badge -->
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                        <h3 class="h6" style="margin:0;" x-text="period.name"></h3>
                        <span class="badge badge-no-dot"
                              :class="statusBadge(period.status)"
                              x-text="capitalize(period.status)"></span>
                    </div>

                    <!-- Dates -->
                    <div class="text-secondary text-sm" style="margin-bottom:8px;">
                        <span x-text="formatDate(period.start_date)"></span>
                        &mdash;
                        <span x-text="formatDate(period.end_date)"></span>
                    </div>

                    <!-- JE count -->
                    <div class="text-sm" style="margin-bottom:16px;">
                        <span class="font-mono font-medium" x-text="period.je_count ?? 0"></span>
                        <span class="text-secondary">journal entries</span>
                    </div>

                    <!-- Action buttons -->
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <?php if (can('period_management', 'edit')): ?>
                        <button class="btn btn-secondary btn-xs"
                                x-show="period.status === 'open'"
                                @click="closePeriod(period)"
                                :disabled="period._acting">
                            <span x-show="!period._acting">Close Period</span>
                            <span x-show="period._acting">Closing...</span>
                        </button>
                        <?php endif; ?>

                        <?php if (can('period_management', 'delete')): ?>
                        <button class="btn btn-danger btn-xs"
                                x-show="period.status === 'closed'"
                                @click="lockPeriod(period)"
                                :disabled="period._acting">
                            <span x-show="!period._acting">Lock Period</span>
                            <span x-show="period._acting">Locking...</span>
                        </button>
                        <?php endif; ?>

                        <span x-show="period.status === 'locked'" class="text-secondary text-sm" style="display:flex;align-items:center;">
                            Permanently sealed
                        </span>
                    </div>

                </div>
            </template>
        </div>
    </template>

</div><!-- /x-data -->

<script>
function FF_Periods() {
    return {
        // ── State ──────────────────────────────────────────────
        year:        new Date().getFullYear(),
        yearOptions: [],
        periods:     [],
        loading:     true,
        loadError:   null,

        // TILES-1: client-side filter set by the KPI tiles above.
        // Empty string → show all; 'open' | 'closed' | 'locked' → filter.
        statusFilter: '',

        // ── Init ───────────────────────────────────────────────
        async init() {
            // WHY: Build year options from current year down to a reasonable start
            // so users can browse historical periods.
            const now = new Date().getFullYear();
            for (let y = now + 1; y >= now - 5; y--) {
                this.yearOptions.push(y);
            }
            await this.load();
        },

        // ── Load periods from API ──────────────────────────────
        async load() {
            this.loading = true;
            this.loadError = null;

            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/periods') ?>?year=' + this.year);
                if (r.success) {
                    // WHY: Attach a transient _acting flag to each period
                    // for button loading state tracking.
                    this.periods = (r.data.periods || []).map(p => ({
                        ...p,
                        _acting: false
                    }));
                } else {
                    this.loadError = r.message || 'Failed to load periods.';
                }
            } catch (e) {
                this.loadError = 'Network error. Please try again.';
            }
            this.loading = false;
        },

        // ── Close period action ────────────────────────────────
        closePeriod(period) {
            FF_Confirm.show({
                title: 'Close Period',
                message: 'Close ' + period.name + '? No more journal entries can be posted to this period after closing.',
                confirmLabel: 'Close Period',
                dangerMode: true,
                onConfirm: async () => {
                    period._acting = true;
                    try {
                        const r = await FF_Api.post('<?= base_url('api/v1/accounting/periods/close') ?>', {
                            id: period.id
                        });
                        if (r.success) {
                            FF_Toast.success(period.name + ' closed successfully.');
                            await this.load();
                        } else {
                            FF_Toast.error(r.message || 'Failed to close period.');
                        }
                    } catch (e) {
                        FF_Toast.error('Network error. Please try again.');
                    }
                    period._acting = false;
                }
            });
        },

        // ── Lock period action ─────────────────────────────────
        lockPeriod(period) {
            FF_Confirm.show({
                title: 'Lock Period',
                message: 'Lock ' + period.name + '? This is permanent and cannot be undone. The period will be permanently sealed.',
                confirmLabel: 'Lock Period',
                dangerMode: true,
                onConfirm: async () => {
                    period._acting = true;
                    try {
                        const r = await FF_Api.post('<?= base_url('api/v1/accounting/periods/lock') ?>', {
                            id: period.id
                        });
                        if (r.success) {
                            FF_Toast.success(period.name + ' locked successfully.');
                            await this.load();
                        } else {
                            FF_Toast.error(r.message || 'Failed to lock period.');
                        }
                    } catch (e) {
                        FF_Toast.error('Network error. Please try again.');
                    }
                    period._acting = false;
                }
            });
        },

        // ── Display helpers ────────────────────────────────────
        statusBadge(status) {
            const map = {
                open:   'badge-green',
                closed: 'badge-amber',
                locked: 'badge-red'
            };
            return map[status] || 'badge-neutral';
        },

        capitalize(s) {
            return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
        },

        formatDate(d) {
            if (!d) return '';
            const dt = new Date(d + 'T00:00:00');
            return dt.toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' });
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
