<?php declare(strict_types=1);

/**
 * app/admin/accounting/leases/show.php
 *
 * Capital lease detail page + amortization schedule viewer.
 * Only meaningful for classification IN ('sales_type','direct_financing').
 * Operating leases are managed on the main app/admin/leases/show.php page.
 *
 * Affordances:
 *   - Header card: lease basics + classification badge + implicit rate +
 *     initial NI + term + status.
 *   - Right-rail summary: total finance income, total principal,
 *     initial / final NI, posted-vs-scheduled period counts.
 *   - Schedule table:
 *       * If no schedule yet → "Preview Schedule" button (GET preview)
 *         → renders the projected table inline + "Generate & Save"
 *           (POST generate) button.
 *       * If schedule exists → render directly with status badges +
 *         JE drill-down placeholder (LESSOR-3 wires JE ids).
 *   - "Regenerate Schedule" (super_admin only, blocked when any row is
 *     posted) → confirm modal → POST generate.php?regenerate=1.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           includes/partials/accounting-nav.php,
 *           api/v1/accounting/leases/amortization/{generate,preview,show}.php
 * @session  S-ACCT-LESSOR-2
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$leaseId = clean_positive_int($_GET['id'] ?? null);
if ($leaseId === null) {
    header('Location: ' . base_url('accounting/leases'));
    exit;
}

$lease = db_row(
    "SELECT l.id, l.contract_number, l.classification, l.status,
            l.start_date, l.end_date, l.monthly_rate, l.implicit_rate,
            l.initial_fair_value, l.initial_direct_costs,
            l.guaranteed_residual_value, l.unguaranteed_residual_value,
            l.bargain_purchase_option_amount, l.bargain_purchase_option_date,
            l.classification_signed_off_at,
            c.company_name, c.contact_name,
            u.unit_number,
            t.brand, t.model,
            alc.criterion_b_lease_term_months AS term_months,
            signer.full_name AS signoff_name
       FROM leases l
       LEFT JOIN customers           c      ON c.id     = l.customer_id          AND c.deleted_at IS NULL
       LEFT JOIN equipment_units     u      ON u.id     = l.equipment_unit_id    AND u.deleted_at IS NULL
       LEFT JOIN equipment_templates t      ON t.id     = u.template_id          AND t.deleted_at IS NULL
       LEFT JOIN acc_lease_classifications alc ON alc.lease_id = l.id
       LEFT JOIN users               signer ON signer.id = l.classification_signed_off_by AND signer.deleted_at IS NULL
      WHERE l.id = ? AND l.deleted_at IS NULL
      LIMIT 1",
    [$leaseId]
);

if (!$lease) {
    header('Location: ' . base_url('accounting/leases'));
    exit;
}

// Operating leases don't have a schedule — bounce to the operational
// lease show page where the regular workflow lives.
if ($lease['classification'] === 'operating') {
    header('Location: ' . base_url('leases/show') . '?id=' . (int) $lease['id']);
    exit;
}

$existing = db_select(
    "SELECT id, period_number, status FROM acc_lease_amortization_schedules
      WHERE lease_id = ? ORDER BY period_number ASC",
    [$leaseId]
);
$hasSchedule = !empty($existing);
$postedCount = 0;
foreach ($existing as $r) if ($r['status'] === 'posted') $postedCount++;
$canRegenerate = $hasSchedule && $postedCount === 0 && is_super_admin();

$classBadge = $lease['classification'] === 'sales_type' ? 'badge-warning' : 'badge-info';
$classLabel = $lease['classification'] === 'sales_type' ? 'Sales-Type' : 'Direct Financing';
$annualRatePct = $lease['implicit_rate'] !== null
    ? number_format((float) $lease['implicit_rate'] * 100, 4) . '%'
    : '—';
$fairVal = $lease['initial_fair_value'] !== null
    ? '$' . number_format((float) $lease['initial_fair_value'], 2)
    : '—';
$unitDisp = trim(($lease['unit_number'] ?? '') . ' — ' . trim(($lease['brand'] ?? '') . ' ' . ($lease['model'] ?? '')));

$pageTitle = 'Capital Lease — ' . $lease['contract_number'];
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/leases') ?>">Capital Leases</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e($lease['contract_number']) ?></span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Capital Lease — <?= e($lease['contract_number']) ?></h1>
    <p class="page-header-subtitle">
        <span class="badge <?= $classBadge ?>"><?= $classLabel ?></span>
        &nbsp;Effective-interest amortization schedule (ASPE 3065).
    </p>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="capitalLeaseShow(<?= (int) $leaseId ?>, <?= $hasSchedule ? 'true' : 'false' ?>)" x-init="init()">

    <!-- ── Header info row + summary card ──────────────────────── -->
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px;">
        <!-- Lease detail card -->
        <div class="card">
            <div class="card-header"><div class="card-title">Lease Details</div></div>
            <div class="card-body">
                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px 24px;font-size:0.9rem;">
                    <div><strong>Customer:</strong><br><?= e($lease['company_name'] ?? '—') ?></div>
                    <div><strong>Unit:</strong><br><?= e($unitDisp) ?></div>
                    <div><strong>Start Date:</strong><br><?= e($lease['start_date']) ?></div>
                    <div><strong>End Date:</strong><br><?= e($lease['end_date'] ?? '—') ?></div>
                    <div><strong>Monthly Payment:</strong><br>$<?= number_format((float) $lease['monthly_rate'], 2) ?></div>
                    <div><strong>Term (wizard):</strong><br><?= e($lease['term_months'] ?? '—') ?> months</div>
                    <div><strong>Initial Fair Value:</strong><br><?= $fairVal ?></div>
                    <div><strong>Implicit Rate (annual):</strong><br><?= $annualRatePct ?></div>
                    <div><strong>Guaranteed Residual:</strong><br>$<?= number_format((float) $lease['guaranteed_residual_value'], 2) ?></div>
                    <div><strong>Unguaranteed Residual:</strong><br>$<?= number_format((float) $lease['unguaranteed_residual_value'], 2) ?></div>
                    <?php if ($lease['bargain_purchase_option_amount']): ?>
                    <div><strong>BPO Amount:</strong><br>$<?= number_format((float) $lease['bargain_purchase_option_amount'], 2) ?></div>
                    <div><strong>BPO Date:</strong><br><?= e($lease['bargain_purchase_option_date'] ?? '—') ?></div>
                    <?php endif; ?>
                    <div><strong>Status:</strong><br><span class="badge badge-neutral"><?= e($lease['status']) ?></span></div>
                    <div><strong>Classified By:</strong><br><?= e($lease['signoff_name'] ?? '—') ?>
                        <br><span style="font-size:0.75rem;color:var(--text-secondary);"><?= e($lease['classification_signed_off_at'] ?? '') ?></span></div>
                </div>
            </div>
        </div>

        <!-- Summary card -->
        <div class="card">
            <div class="card-header"><div class="card-title">Schedule Summary</div></div>
            <div class="card-body">
                <template x-if="!schedule.persisted && !schedule.preview">
                    <div style="color:var(--text-secondary);font-size:0.9rem;">
                        No schedule yet. Click <strong>Preview Schedule</strong> below to compute the
                        effective-interest amortization without writing.
                    </div>
                </template>
                <template x-if="schedule.persisted || schedule.preview">
                    <div style="display:flex;flex-direction:column;gap:6px;font-size:0.875rem;">
                        <div style="display:flex;justify-content:space-between;">
                            <span>Initial Net Investment:</span>
                            <strong x-text="'$' + fmt(schedule.summary.initial_ni)"></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span>Final Closing NI:</span>
                            <strong x-text="'$' + fmt(schedule.summary.final_closing_ni)"></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span>Total Finance Income:</span>
                            <strong x-text="'$' + fmt(schedule.summary.total_finance_income)"></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span>Total Principal:</span>
                            <strong x-text="'$' + fmt(schedule.summary.total_principal)"></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span>Periods:</span>
                            <strong>
                                <span x-text="schedule.summary.period_count"></span>
                                (<span x-text="schedule.summary.posted_count"></span> posted)
                            </strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span>Implicit Rate (annual):</span>
                            <strong x-text="schedule.annual_rate ? (Number(schedule.annual_rate) * 100).toFixed(4) + '%' : '—'"></strong>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ── NI Current vs Long-Term Breakdown (S-ACCT-LESSOR-5) ── -->
    <div class="card" style="margin-bottom:16px;" x-show="niBreakdown" x-cloak>
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <div class="card-title">NI Current vs Long-Term Breakdown (ASPE 3065.54)</div>
            <button class="btn btn-ghost btn-sm" @click="loadNiBreakdown()" :disabled="niBusy">
                <span x-show="!niBusy">Refresh Preview</span>
                <span x-show="niBusy">Loading…</span>
            </button>
        </div>
        <div class="card-body">
            <template x-if="niBreakdown">
                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px;font-size:0.9rem;">
                    <div>
                        <div style="font-weight:600;margin-bottom:4px;color:var(--text-secondary);">On-Books Now (GL trail)</div>
                        <div style="display:flex;justify-content:space-between;">
                            <span>NI Current (1090):</span>
                            <strong>$<span x-text="fmt(niBreakdown.currentBalance1090)"></span></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span>NI Long-Term (1600):</span>
                            <strong>$<span x-text="fmt(niBreakdown.currentBalance1600)"></span></strong>
                        </div>
                    </div>
                    <div>
                        <div style="font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Target After Next Reclass (next 12mo split)</div>
                        <div style="display:flex;justify-content:space-between;">
                            <span>Target 1090:</span>
                            <strong>$<span x-text="fmt(niBreakdown.target1090)"></span></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span>Target 1600:</span>
                            <strong>$<span x-text="fmt(niBreakdown.target1600)"></span></strong>
                        </div>
                    </div>
                </div>
            </template>
            <template x-if="niBreakdown && niBreakdown.reclass_needed">
                <div class="alert alert-info" style="margin-top:10px;padding:8px 12px;font-size:0.875rem;">
                    Next reclass: <strong>$<span x-text="fmt(niBreakdown.delta1090)"></span></strong>
                    will shift between current and long-term on the 1st of next month
                    (cron <code>accounting_lease_ni_reclass.php</code>).
                </div>
            </template>
            <template x-if="niBreakdown && !niBreakdown.reclass_needed">
                <div class="alert alert-success" style="margin-top:10px;padding:8px 12px;font-size:0.875rem;">
                    No reclass needed — on-books NI matches target split.
                </div>
            </template>
            <template x-if="niBreakdown && Number(niBreakdown.integrity_drift) > 0.02">
                <div class="alert alert-warning" style="margin-top:10px;padding:8px 12px;font-size:0.875rem;">
                    ⚠ Integrity drift: $<span x-text="fmt(niBreakdown.integrity_drift)"></span>.
                    Schedule projection vs on-books NI out of sync. Investigate per
                    D-LESSOR-4-PERIOD-PRINCIPAL-DERIVATION + residual impairments.
                </div>
            </template>
        </div>
    </div>

    <!-- ── Action bar ─────────────────────────────────────────── -->
    <div class="card" style="padding:14px 18px;margin-bottom:16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
        <template x-if="!hasSchedule && !schedule.preview">
            <button @click="loadPreview()" :disabled="busy" class="btn btn-secondary">
                <span x-show="!busy">Preview Schedule</span>
                <span x-show="busy">Computing…</span>
            </button>
        </template>
        <template x-if="schedule.preview && !schedule.persisted">
            <div style="display:flex;gap:10px;">
                <button @click="generateSchedule(false)" :disabled="busy" class="btn btn-primary">
                    <span x-show="!busy">Generate &amp; Save</span>
                    <span x-show="busy">Saving…</span>
                </button>
                <button @click="loadPreview()" :disabled="busy" class="btn btn-ghost">Re-preview</button>
            </div>
        </template>
        <?php if ($canRegenerate): ?>
        <template x-if="hasSchedule">
            <button @click="confirmRegenerate()" :disabled="busy" class="btn btn-warning">
                Regenerate Schedule
            </button>
        </template>
        <?php endif; ?>
        <?php if ($hasSchedule && $postedCount > 0): ?>
        <div class="alert alert-info" style="margin:0;padding:6px 12px;font-size:0.875rem;">
            <?= (int) $postedCount ?> period(s) posted — regenerate is blocked.
        </div>
        <?php endif; ?>
        <div x-show="banner" :class="bannerClass" style="padding:6px 12px;border-radius:6px;font-size:0.875rem;"
             x-text="banner"></div>
    </div>

    <!-- ── Schedule table ─────────────────────────────────────── -->
    <div class="card" x-show="schedule.preview || schedule.persisted">
        <table class="table" style="width:100%;font-size:0.85rem;">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th style="text-align:right;">Opening NI</th>
                    <th style="text-align:right;">Payment</th>
                    <th style="text-align:right;">Finance Income</th>
                    <th style="text-align:right;">Principal</th>
                    <th style="text-align:right;">Closing NI</th>
                    <th>Status</th>
                    <th>JE #</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="row in schedule.periods" :key="row.period_number">
                    <tr>
                        <td x-text="row.period_number"></td>
                        <td x-text="row.period_date"></td>
                        <td style="text-align:right;" x-text="'$' + fmt(row.opening_net_investment)"></td>
                        <td style="text-align:right;" x-text="'$' + fmt(row.cash_receipt)"></td>
                        <td style="text-align:right;" x-text="'$' + fmt(row.finance_income)"></td>
                        <td style="text-align:right;" x-text="'$' + fmt(row.principal_reduction)"></td>
                        <td style="text-align:right;" x-text="'$' + fmt(row.closing_net_investment)"></td>
                        <td>
                            <span class="badge"
                                  :class="(row.status||'scheduled') === 'posted' ? 'badge-success'
                                       : (row.status||'scheduled') === 'reversed' ? 'badge-danger' : 'badge-neutral'"
                                  x-text="row.status || 'preview'"></span>
                        </td>
                        <td>
                            <template x-if="row.posted_je_id">
                                <a :href="'<?= base_url('accounting/journal-entries/show') ?>?id=' + row.posted_je_id"
                                   x-text="'JE #' + row.posted_je_id"></a>
                            </template>
                            <template x-if="!row.posted_je_id">
                                <span style="color:var(--text-secondary);">—</span>
                            </template>
                        </td>
                    </tr>
                </template>
            </tbody>
            <tfoot x-show="schedule.summary">
                <tr style="background:var(--bg-input);font-weight:600;">
                    <td colspan="4" style="text-align:right;">Totals:</td>
                    <td style="text-align:right;" x-text="'$' + fmt(schedule.summary.total_finance_income)"></td>
                    <td style="text-align:right;" x-text="'$' + fmt(schedule.summary.total_principal)"></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- ── Empty state when no schedule yet AND no preview loaded ─ -->
    <div class="card" x-show="!schedule.preview && !schedule.persisted" style="padding:1.5rem;text-align:center;">
        <div style="color:var(--text-secondary);">
            This lease has been classified as <strong><?= $classLabel ?></strong> but no amortization
            schedule has been built yet. The schedule auto-generates on activation; you can also
            preview it now to validate the inputs before activating.
        </div>
    </div>

    <!-- ── Regenerate confirm modal ───────────────────────────── -->
    <div class="modal-overlay" x-show="showRegenModal" x-cloak
         style="background:rgba(0,0,0,0.55);"
         @click.self="showRegenModal = false">
        <div class="card" style="max-width:480px;padding:24px;">
            <div class="card-title" style="margin-bottom:10px;">Regenerate Schedule?</div>
            <p style="font-size:0.9rem;">
                This deletes the existing <strong x-text="schedule.summary?.period_count"></strong>
                scheduled rows and rebuilds from the lease's current rate and term inputs.
                Posted rows are not present (regenerate is blocked when any row is posted).
                This action is logged in <code>audit_log</code>.
            </p>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px;">
                <button class="btn btn-ghost" @click="showRegenModal = false">Cancel</button>
                <button class="btn btn-warning" @click="generateSchedule(true)" :disabled="busy">
                    <span x-show="!busy">Regenerate</span>
                    <span x-show="busy">Working…</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function capitalLeaseShow(leaseId, hasSchedule) {
    return {
        leaseId,
        hasSchedule,
        busy: false,
        showRegenModal: false,
        banner: '',
        bannerClass: 'alert alert-success',
        schedule: { preview: false, persisted: false, periods: [], summary: null, annual_rate: null },
        niBreakdown: null,
        niBusy: false,

        async init() {
            if (this.hasSchedule) await this.loadExisting();
            // Auto-load NI breakdown on page open — cheap (per-lease GL trail + 2 schedule sums)
            await this.loadNiBreakdown();
        },

        async loadNiBreakdown() {
            this.niBusy = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/leases/ni-reclass-preview') ?>?lease_id=' + this.leaseId);
                if (r.success && r.data.leases && r.data.leases[0]) {
                    this.niBreakdown = r.data.leases[0];
                }
            } catch (e) { /* non-fatal */ }
            this.niBusy = false;
        },

        fmt(v) {
            if (v === null || v === undefined) return '0.00';
            const n = Number(v);
            return n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        async loadExisting() {
            this.busy = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/leases/amortization/show') ?>?lease_id=' + this.leaseId);
                if (r.success) {
                    this.schedule = { ...r.data, preview: false, persisted: r.data.persisted };
                }
            } catch (e) {
                this.flash('Network error loading schedule.', 'danger');
            }
            this.busy = false;
        },

        async loadPreview() {
            this.busy = true;
            this.banner = '';
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/leases/amortization/preview') ?>?lease_id=' + this.leaseId);
                if (r.success) {
                    this.schedule = { ...r.data, preview: true, persisted: false };
                    this.flash('Preview ready. Click Generate & Save to commit.', 'info');
                } else {
                    this.flash(r.error?.message || 'Preview failed.', 'danger');
                }
            } catch (e) {
                this.flash('Network error.', 'danger');
            }
            this.busy = false;
        },

        async generateSchedule(regenerate) {
            this.busy = true;
            this.showRegenModal = false;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/leases/amortization/generate') ?>',
                    { lease_id: this.leaseId, regenerate });
                if (r.success) {
                    this.schedule = { ...r.data, preview: false, persisted: true };
                    this.hasSchedule = true;
                    this.flash(regenerate ? 'Schedule regenerated.' : 'Schedule generated and saved.', 'success');
                } else {
                    this.flash(r.error?.message || 'Generation failed.', 'danger');
                }
            } catch (e) {
                this.flash('Network error.', 'danger');
            }
            this.busy = false;
        },

        confirmRegenerate() {
            this.showRegenModal = true;
        },

        flash(msg, level) {
            this.banner = msg;
            this.bannerClass = 'alert alert-' + (level === 'success' ? 'success'
                : level === 'danger' ? 'danger'
                : level === 'info' ? 'info' : 'neutral');
            setTimeout(() => { this.banner = ''; }, 5000);
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
