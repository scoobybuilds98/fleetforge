<?php declare(strict_types=1);

/**
 * app/admin/accounting/leases/residual-reviews/index.php
 *
 * Annual residual review workflow for active sales-type and direct-
 * financing leases per ASPE 3065 §24.7. Fiscal-year picker; per-lease
 * row with current residual + last review (date / revised value / JE
 * link) + "Run Review" button. Modal collects revised_residual_value
 * + notes; POST to residual-review.php; result surfaced inline.
 *
 * Below the active-leases table: history table of all
 * acc_lease_residual_reviews rows for the selected year (read-only).
 *
 * Upward revisions are blocked at the API; the UI also disables the
 * Submit button until revised < current to give immediate feedback.
 *
 * @session S-ACCT-LESSOR-5
 */

require_once realpath(dirname(__DIR__, 5) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$fiscalYear = (int) ($_GET['fiscal_year'] ?? date('Y'));
if ($fiscalYear < 2000 || $fiscalYear > 2100) $fiscalYear = (int) date('Y');

// Active capital leases with their current residual + last review for this FY.
$leases = db_select(
    "SELECT l.id, l.contract_number, l.classification,
            l.unguaranteed_residual_value, l.start_date,
            c.company_name, u.unit_number, eb.label AS brand, t.model,
            lrr.id AS last_review_id,
            lrr.revised_residual_value AS last_revised,
            lrr.delta AS last_delta,
            lrr.reviewed_at AS last_reviewed_at,
            lrr.impairment_je_id AS last_je_id,
            je.entry_number AS last_je_number
       FROM leases l
       LEFT JOIN customers           c ON c.id = l.customer_id          AND c.deleted_at IS NULL
       LEFT JOIN equipment_units     u ON u.id = l.equipment_unit_id    AND u.deleted_at IS NULL
       LEFT JOIN equipment_templates t ON t.id = u.template_id          AND t.deleted_at IS NULL
       LEFT JOIN equipment_brands    eb ON eb.id = u.brand_id
       LEFT JOIN acc_lease_residual_reviews lrr
              ON lrr.lease_id = l.id AND lrr.fiscal_year = ?
       LEFT JOIN acc_journal_entries je
              ON je.id = lrr.impairment_je_id
      WHERE l.classification IN ('sales_type','direct_financing')
        AND l.status = 'active'
        AND l.deleted_at IS NULL
      ORDER BY l.id ASC",
    [$fiscalYear]
);

$history = \FleetForge\Accounting\LeaseResidualService::listForYear($fiscalYear);

$pageTitle = "Residual Reviews — FY {$fiscalYear}";
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/leases') ?>">Capital Leases</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Residual Reviews — FY <?= (int) $fiscalYear ?></span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Residual Reviews — FY <?= (int) $fiscalYear ?></h1>
    <p class="page-header-subtitle">
        ASPE 3065 §24.7 annual unguaranteed residual review. Downward revisions
        post an impairment JE + regenerate the unposted schedule.
        <strong>Upward revisions are prohibited per ASPE.</strong>
    </p>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="residualReviews(<?= (int) $fiscalYear ?>)">

    <!-- Fiscal year picker -->
    <div class="card" style="padding:14px 18px;margin-bottom:16px;display:flex;gap:14px;align-items:center;">
        <label class="form-label" style="margin:0;">Fiscal Year:</label>
        <input type="number" min="2000" max="2100" x-model.number="year"
               @change="window.location.href = '?fiscal_year=' + year"
               style="width:120px;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);">
        <div style="color:var(--text-secondary);font-size:0.85rem;">
            Active capital leases:&nbsp;<strong><?= count($leases) ?></strong>
            &nbsp;·&nbsp;Reviews this year:&nbsp;<strong><?= count($history) ?></strong>
        </div>
    </div>

    <!-- Active leases — run a review per lease -->
    <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><div class="card-title">Active Capital Leases</div></div>
        <table class="table" style="width:100%;font-size:0.9rem;">
            <thead>
                <tr>
                    <th>Lease #</th>
                    <th>Customer</th>
                    <th>Unit</th>
                    <th>Classification</th>
                    <th style="text-align:right;">Current Residual</th>
                    <th>Last Review</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($leases)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--text-secondary);padding:1.5rem;">
                    No active capital leases.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($leases as $l): ?>
                <?php
                    $classBadge = $l['classification'] === 'sales_type' ? 'badge-warning' : 'badge-info';
                    $unitDisp = trim(($l['unit_number'] ?? '') . ' — ' . trim(($l['brand'] ?? '') . ' ' . ($l['model'] ?? '')));
                ?>
                <tr>
                    <td><a href="<?= base_url('accounting/leases/show') ?>?id=<?= (int) $l['id'] ?>"><?= e($l['contract_number']) ?></a></td>
                    <td><?= e($l['company_name'] ?? '—') ?></td>
                    <td><?= e($unitDisp) ?></td>
                    <td><span class="badge <?= $classBadge ?>"><?= e($l['classification'] === 'sales_type' ? 'Sales-Type' : 'Direct Financing') ?></span></td>
                    <td style="text-align:right;">$<?= number_format((float) $l['unguaranteed_residual_value'], 2) ?></td>
                    <td>
                        <?php if ($l['last_review_id']): ?>
                            $<?= number_format((float) $l['last_revised'], 2) ?>
                            <span style="color:<?= (float) $l['last_delta'] < 0 ? 'var(--danger)' : 'var(--text-secondary)' ?>;">
                                (Δ <?= number_format((float) $l['last_delta'], 2) ?>)
                            </span>
                            <br><span style="font-size:0.75rem;color:var(--text-secondary);"><?= e($l['last_reviewed_at']) ?></span>
                            <?php if ($l['last_je_id']): ?>
                                <br><a href="<?= base_url('accounting/journal-entries/show') ?>?id=<?= (int) $l['last_je_id'] ?>" style="font-size:0.75rem;"><?= e($l['last_je_number']) ?></a>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-secondary);">No review yet</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-secondary btn-sm"
                                @click="openModal(<?= (int) $l['id'] ?>, '<?= e($l['contract_number']) ?>', '<?= e($l['unguaranteed_residual_value']) ?>')">
                            <?= $l['last_review_id'] ? 'Re-review' : 'Run Review' ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- History table -->
    <div class="card">
        <div class="card-header"><div class="card-title">Review History — FY <?= (int) $fiscalYear ?></div></div>
        <table class="table" style="width:100%;font-size:0.85rem;">
            <thead>
                <tr>
                    <th>Reviewed</th>
                    <th>Lease #</th>
                    <th style="text-align:right;">Prior</th>
                    <th style="text-align:right;">Revised</th>
                    <th style="text-align:right;">Delta</th>
                    <th>Direction</th>
                    <th>Impairment JE</th>
                    <th>Regen</th>
                    <th>Reviewer</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($history)): ?>
                <tr><td colspan="10" style="text-align:center;color:var(--text-secondary);padding:1.5rem;">No reviews recorded for this fiscal year.</td></tr>
            <?php endif; ?>
            <?php foreach ($history as $h): ?>
                <?php
                    $delta     = (float) $h['delta'];
                    $direction = $delta < 0 ? 'Downward (impairment)' : ($delta > 0 ? 'Upward (blocked)' : 'No change');
                    $deltaColor = $delta < 0 ? 'var(--danger)' : 'var(--text-primary)';
                ?>
                <tr>
                    <td><?= e($h['reviewed_at']) ?></td>
                    <td><a href="<?= base_url('accounting/leases/show') ?>?id=<?= (int) $h['lease_id'] ?>"><?= e($h['contract_number'] ?? ('#' . $h['lease_id'])) ?></a></td>
                    <td style="text-align:right;">$<?= number_format((float) $h['prior_residual_value'], 2) ?></td>
                    <td style="text-align:right;">$<?= number_format((float) $h['revised_residual_value'], 2) ?></td>
                    <td style="text-align:right;color:<?= $deltaColor ?>;">$<?= number_format($delta, 2) ?></td>
                    <td><?= e($direction) ?></td>
                    <td>
                        <?php if ($h['impairment_je_id']): ?>
                            <a href="<?= base_url('accounting/journal-entries/show') ?>?id=<?= (int) $h['impairment_je_id'] ?>"><?= e($h['impairment_je_number'] ?? ('JE #' . $h['impairment_je_id'])) ?></a>
                        <?php else: ?>
                            <span style="color:var(--text-secondary);">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $h['schedule_regenerated'] ? '✅' : '—' ?></td>
                    <td><?= e($h['reviewer_name'] ?? '—') ?></td>
                    <td><?= e($h['notes'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Review modal -->
    <div class="modal-overlay" x-show="showModal" x-cloak
         style="background:rgba(0,0,0,0.55);"
         @click.self="showModal = false">
        <div class="card" style="max-width:560px;padding:24px;">
            <div class="card-title" style="margin-bottom:10px;">
                Residual Review — <span x-text="modalContract"></span>
            </div>
            <div class="form-group">
                <label class="form-label">Current Unguaranteed Residual</label>
                <div style="font-weight:600;font-size:1.1rem;">$<span x-text="Number(modalCurrent).toFixed(2)"></span></div>
            </div>
            <div class="form-group">
                <label class="form-label" for="revised_value">Revised Residual Value ($)</label>
                <input type="number" id="revised_value" step="0.01" min="0"
                       x-model="revised" class="form-control">
                <div class="form-hint" x-show="Number(revised) > Number(modalCurrent)" style="color:var(--danger);">
                    ⚠ Upward revisions are prohibited per ASPE 3065. Enter a value ≤ current.
                </div>
                <div class="form-hint" x-show="Number(revised) >= 0 && Number(revised) < Number(modalCurrent)" style="color:var(--warning);">
                    Downward revision: impairment of $<span x-text="(Number(modalCurrent) - Number(revised)).toFixed(2)"></span> will post + schedule regenerated.
                </div>
                <div class="form-hint" x-show="revised !== '' && Number(revised) === Number(modalCurrent)">
                    No change — review will be recorded with no JE.
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="review_notes">Notes</label>
                <textarea id="review_notes" class="form-control" rows="3" x-model="notes"
                          placeholder="Rationale for the revision (audit trail)"></textarea>
            </div>
            <div x-show="banner" :class="bannerClass" style="padding:8px 12px;border-radius:6px;font-size:0.875rem;margin:8px 0;" x-text="banner"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px;">
                <button class="btn btn-ghost" @click="showModal = false" :disabled="busy">Cancel</button>
                <button class="btn btn-primary" @click="submit()"
                        :disabled="busy || revised === '' || Number(revised) < 0 || Number(revised) > Number(modalCurrent)">
                    <span x-show="!busy">Submit Review</span>
                    <span x-show="busy">Saving…</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function residualReviews(initialYear) {
    return {
        year: initialYear,
        showModal: false,
        modalLeaseId: null,
        modalContract: '',
        modalCurrent: '0.00',
        revised: '',
        notes: '',
        busy: false,
        banner: '',
        bannerClass: 'alert alert-info',
        openModal(id, contract, current) {
            this.modalLeaseId = id;
            this.modalContract = contract;
            this.modalCurrent = current;
            this.revised = '';
            this.notes = '';
            this.banner = '';
            this.showModal = true;
        },
        async submit() {
            this.busy = true;
            this.banner = '';
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/leases/residual-review') ?>', {
                    lease_id: this.modalLeaseId,
                    fiscal_year: this.year,
                    revised_residual_value: String(this.revised),
                    notes: this.notes,
                });
                if (r.success) {
                    this.banner = 'Review recorded — '
                        + (r.data.direction === 'downward'
                            ? 'impairment JE posted + schedule regenerated.'
                            : 'no change.');
                    this.bannerClass = 'alert alert-success';
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    this.banner = r.error?.message || 'Review failed.';
                    this.bannerClass = 'alert alert-danger';
                }
            } catch (e) {
                this.banner = 'Network error.';
                this.bannerClass = 'alert alert-danger';
            }
            this.busy = false;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
