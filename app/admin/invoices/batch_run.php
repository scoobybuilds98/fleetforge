<?php
declare(strict_types=1);

/**
 * app/admin/invoices/batch_run.php
 *
 * S-BATCH-APPROVAL — a single batch run at its own shareable URL
 * (/invoices/batch_run?id=N). This is the page a manager who is NOT doing
 * the billing opens to review and sign off on a proposed run.
 *
 * Renders the FROZEN snapshot straight from the DB — it does not recompute
 * anything. That is the point: the approver signs off on specific figures,
 * and those figures must still read the same tomorrow. Drift against live
 * data is surfaced at generation time (api/v1/invoices/batch_runs/generate.php),
 * never by silently re-running the numbers underneath the approver.
 *
 * Rendered light-on-paper regardless of the user's theme, matching the
 * in-page Billing Review — this is a document people read and print.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, api/v1/invoices/batch_runs/*
 * @session S-BATCH-APPROVAL
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'view');

$runId = clean_int($_GET['id'] ?? null);
if (!$runId || $runId <= 0) {
    header('Location: ' . base_url('invoices/batch'));
    exit;
}

$run = db_row(
    "SELECT r.*,
            su.name AS submitted_by_name,
            du.name AS decided_by_name,
            gu.name AS generated_by_name
       FROM invoice_batch_runs r
       LEFT JOIN users su ON su.id = r.submitted_by
       LEFT JOIN users du ON du.id = r.decided_by
       LEFT JOIN users gu ON gu.id = r.generated_by
      WHERE r.id = ? AND r.deleted_at IS NULL",
    [$runId]
);
if (!$run) {
    http_response_code(404);
    $pageTitle = 'Batch run not found';
    require_once FF_ROOT . '/includes/header.php';
    echo '<div class="card"><div class="card-body"><p class="text-secondary">That batch run does not exist or has been deleted.</p>'
       . '<a class="btn btn-secondary btn-sm" href="' . e(base_url('invoices/batch')) . '">Back to Batch Invoicing</a></div></div>';
    require_once FF_ROOT . '/includes/footer.php';
    exit;
}

$snapshot   = json_decode((string) $run['snapshot'], true) ?: [];
$totalsByCur = json_decode((string) $run['total_by_currency'], true) ?: [];
$genResult  = json_decode((string) ($run['generation_result'] ?? ''), true) ?: null;

$canApprove  = can('invoices', 'approve');
$canGenerate = can('invoices', 'create');

$pageTitle      = 'Batch Run ' . $run['reference'];
$helpModuleSlug = 'invoices';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('invoices') ?>">Invoices</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('invoices/batch') ?>">Batch Invoicing</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e($run['reference']) ?></span>
</nav>

<div id="batch-run-app"
     x-data="BatchRunPage({
        id: <?= (int) $run['id'] ?>,
        status: <?= e(json_encode($run['status'])) ?>,
        canApprove: <?= $canApprove ? 'true' : 'false' ?>,
        canGenerate: <?= $canGenerate ? 'true' : 'false' ?>
     })">

    <div class="batch-review-surface" data-theme="light">
        <div class="batch-review-head">
            <div>
                <div class="batch-review-title">
                    <?= e($run['reference']) ?>
                    <span class="brc-status-pill" data-status="<?= e($run['status']) ?>"><?= e($run['status']) ?></span>
                </div>
                <div class="batch-review-sub">
                    <?= e(format_date($run['period_start'])) ?> &rarr; <?= e(format_date($run['period_end'])) ?>
                    &middot; submitted by <?= e($run['submitted_by_name'] ?? 'system') ?>
                    <?= $run['submitted_at'] ? '· ' . e(format_datetime($run['submitted_at'])) : '' ?>
                </div>
            </div>
            <div class="batch-review-head-actions">
                <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">Print / Save PDF</button>

                <?php if ($canApprove): ?>
                <template x-if="status === 'pending'">
                    <span style="display:flex; gap:8px;">
                        <button type="button" class="btn btn-danger btn-sm" :disabled="working" @click="decide('reject')">Reject</button>
                        <button type="button" class="btn btn-success btn-sm" :disabled="working" @click="decide('approve')">
                            <span x-show="!working">Approve</span><span x-show="working">Working…</span>
                        </button>
                    </span>
                </template>
                <?php endif; ?>

                <?php if ($canGenerate): ?>
                <template x-if="status === 'approved'">
                    <button type="button" class="btn btn-primary btn-sm" :disabled="working" @click="generate()">
                        <span x-show="!working">Generate <?= (int) $run['invoice_count'] ?> Invoice<?= (int) $run['invoice_count'] === 1 ? '' : 's' ?></span>
                        <span x-show="working">Generating…</span>
                    </button>
                </template>
                <?php endif; ?>

                <a class="btn btn-ghost btn-sm" href="<?= e(base_url('invoices/batch')) ?>">Back</a>
            </div>
        </div>

        <div class="batch-review-body">

            <?php if ($run['note']): ?>
            <div class="brc-note">
                <div class="batch-review-section-title">Submitter note</div>
                <?= nl2br(e($run['note'])) ?>
            </div>
            <?php endif; ?>

            <?php if ($run['decision_note']): ?>
            <div class="brc-note" data-kind="<?= $run['status'] === 'rejected' ? 'reject' : 'approve' ?>">
                <div class="batch-review-section-title">
                    <?= $run['status'] === 'rejected' ? 'Rejected' : 'Approved' ?>
                    by <?= e($run['decided_by_name'] ?? '—') ?>
                    <?= $run['decided_at'] ? '· ' . e(format_datetime($run['decided_at'])) : '' ?>
                </div>
                <?= nl2br(e($run['decision_note'])) ?>
            </div>
            <?php endif; ?>

            <!-- Totals -->
            <div class="batch-total-strip">
                <div class="batch-total">
                    <div class="batch-total-label"><?= $run['status'] === 'generated' ? 'Billed' : 'Would bill' ?></div>
                    <div class="batch-total-value"><?php
                        $parts = [];
                        foreach ($totalsByCur as $cur => $amt) { $parts[] = format_currency($amt) . ' ' . $cur; }
                        echo e($parts ? implode('  +  ', $parts) : '—');
                    ?></div>
                </div>
                <div class="batch-total">
                    <div class="batch-total-label">Invoices</div>
                    <div class="batch-total-value"><?= (int) $run['invoice_count'] ?></div>
                </div>
                <?php if ((int) $run['skipped_count'] > 0): ?>
                <div class="batch-total">
                    <div class="batch-total-label">Not billable</div>
                    <div class="batch-total-value text-danger"><?= (int) $run['skipped_count'] ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($genResult): ?>
            <div class="brc-note" data-kind="<?= !empty($genResult['drifted']) ? 'reject' : 'approve' ?>">
                <div class="batch-review-section-title">
                    Generated by <?= e($run['generated_by_name'] ?? '—') ?>
                    <?= $run['generated_at'] ? '· ' . e(format_datetime($run['generated_at'])) : '' ?>
                </div>
                <?= (int) ($genResult['actioned'] ?? 0) ?> created,
                <?= (int) ($genResult['skipped'] ?? 0) ?> skipped.
                <?php if (!empty($genResult['drifted'])): ?>
                    <div style="margin-top:8px;">
                        <strong>Totals changed since approval:</strong>
                        <ul style="margin:6px 0 0; padding-left:18px;">
                            <?php foreach ($genResult['drifted'] as $d): ?>
                                <li><?= e($d['invoice_number']) ?>: approved <?= e(format_currency($d['approved_total'])) ?>,
                                    billed <?= e(format_currency($d['actual_total'])) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if (!empty($genResult['errors'])): ?>
                    <ul style="margin:8px 0 0; padding-left:18px;">
                        <?php foreach ($genResult['errors'] as $err): ?>
                            <li>Lease #<?= (int) $err['lease_id'] ?>: <?= e($err['reason']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php
            $previews = $snapshot['previews'] ?? [];
            $problems = array_values(array_filter($previews, static fn ($p) => empty($p['ok'])));
            $billable = array_values(array_filter($previews, static fn ($p) => !empty($p['ok'])));
            ?>

            <?php if ($problems): ?>
            <div class="batch-review-problems">
                <div class="batch-review-section-title">Not billable — excluded from this run</div>
                <?php foreach ($problems as $p): ?>
                    <div class="batch-problem-row">
                        <strong><?= e($p['company_name'] ?? ('Lease #' . ($p['lease_id'] ?? '?'))) ?></strong>
                        <span class="batch-lease-contract"><?= e($p['contract_number'] ?? '') ?></span>
                        <span class="text-danger"><?= e($p['error'] ?? '') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php
            /* Per-customer rollup. The cards below are per LEASE (one card =
               one invoice), which is the detail view — but a customer with 5
               leases produces 5 cards with the same name at the top, and
               there was no way to see what that customer is being billed in
               TOTAL. Group here, toggle between the two. Totals stay
               per-currency: a customer can hold both CAD and USD leases and
               summing them into one number would be a lie. */
            $byCustomer = [];
            foreach ($billable as $p) {
                $cid = (int) ($p['customer_id'] ?? 0);
                if (!isset($byCustomer[$cid])) {
                    $byCustomer[$cid] = [
                        'company_name' => $p['company_name'],
                        'count'        => 0,
                        'totals'       => [],   // currency => bcmath string
                        'leases'       => [],
                    ];
                }
                $byCustomer[$cid]['count']++;
                $cur = (string) $p['currency'];
                $byCustomer[$cid]['totals'][$cur] = bcadd(
                    $byCustomer[$cid]['totals'][$cur] ?? '0', (string) $p['total_amount'], 2
                );
                $byCustomer[$cid]['leases'][] = $p;
            }
            uasort($byCustomer, static fn ($a, $b) => $b['count'] <=> $a['count']);
            ?>

            <div class="brc-view-toggle" x-data="{ view: 'lease' }">
                <div class="brc-view-switch">
                    <button type="button" class="brc-view-btn" :class="{ 'is-active': view === 'lease' }"
                            @click="view = 'lease'">By lease (<?= count($billable) ?>)</button>
                    <button type="button" class="brc-view-btn" :class="{ 'is-active': view === 'customer' }"
                            @click="view = 'customer'">By customer (<?= count($byCustomer) ?>)</button>
                </div>

                <!-- ── By customer: one row per customer, subtotal per currency ── -->
                <div x-show="view === 'customer'" x-cloak class="batch-review-card" style="margin-top:14px;">
                    <div class="data-table-wrap">
                        <table class="data-table">
                            <thead><tr>
                                <th>Customer</th>
                                <th class="text-right">Invoices</th>
                                <th>Leases</th>
                                <th class="text-right">Total</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($byCustomer as $cid => $row): ?>
                                <tr>
                                    <td><strong><?= e($row['company_name']) ?></strong></td>
                                    <td class="text-right"><?= (int) $row['count'] ?></td>
                                    <td class="text-sm text-secondary">
                                        <?php foreach ($row['leases'] as $l): ?>
                                            <span class="batch-lease-contract"><?= e($l['contract_number']) ?></span><?php
                                            ?><?= $l !== end($row['leases']) ? ' · ' : '' ?>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="text-right currency">
                                        <?php $parts = [];
                                        foreach ($row['totals'] as $cur => $amt) { $parts[] = format_currency($amt) . ' ' . $cur; }
                                        echo e(implode('  +  ', $parts)); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ── By lease: the existing per-invoice detail cards ── -->
                <div x-show="view === 'lease'">
            <?php foreach ($billable as $p): ?>
            <div class="batch-review-card">
                <div class="brc-head">
                    <div class="brc-head-main">
                        <div class="brc-customer"><?= e($p['company_name']) ?></div>
                        <div class="brc-meta">
                            <span class="batch-lease-contract"><?= e($p['contract_number']) ?></span>
                            <?php if (!empty($p['unit_number'])): ?><span>&middot; Unit <?= e($p['unit_number']) ?></span><?php endif; ?>
                            <?php if (!empty($p['po_number'])): ?><span>&middot; PO <?= e($p['po_number']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="brc-head-total">
                        <div class="batch-total-label">Total</div>
                        <div class="brc-total-value"><?= e(format_currency($p['total_amount'])) ?> <?= e($p['currency']) ?></div>
                    </div>
                </div>

                <?php $t = $p['terms'] ?? []; $u = $p['usage'] ?? []; ?>
                <div class="brc-facts">
                    <div class="brc-fact"><span>Billing period</span><b><?= e($p['period_start']) ?> → <?= e($p['period_end']) ?></b></div>
                    <div class="brc-fact"><span>Billable days</span><b><?= (int) $p['billing_days'] ?></b></div>
                    <div class="brc-fact"><span>Rate method</span><b><?= e($p['rate_method'] ?: '—') ?></b></div>
                    <div class="brc-fact"><span>Billing type</span><b><?= e($p['billing_type']) ?></b></div>
                    <div class="brc-fact"><span>Invoice / due</span><b><?= e($p['invoice_date']) ?> → <?= e($p['due_date']) ?></b></div>
                    <div class="brc-fact"><span>Lease term</span><b><?= e($t['lease_start'] ?? '—') ?> → <?= e($t['lease_end'] ?? 'open') ?></b></div>
                    <?php if ((float) ($t['daily_rate'] ?? 0) > 0): ?><div class="brc-fact"><span>Daily rate</span><b><?= e(format_currency($t['daily_rate'])) ?></b></div><?php endif; ?>
                    <?php if ((float) ($t['weekly_rate'] ?? 0) > 0): ?><div class="brc-fact"><span>Weekly rate</span><b><?= e(format_currency($t['weekly_rate'])) ?></b></div><?php endif; ?>
                    <?php if ((float) ($t['monthly_rate'] ?? 0) > 0): ?><div class="brc-fact"><span>Monthly rate</span><b><?= e(format_currency($t['monthly_rate'])) ?></b></div><?php endif; ?>
                    <?php if ((float) ($t['hourly_rate'] ?? 0) > 0): ?><div class="brc-fact"><span>Hourly rate</span><b><?= e(format_currency($t['hourly_rate'])) ?></b></div><?php endif; ?>
                    <?php
                    $mu   = $t['mileage_unit'] ?? 'km';
                    $mr   = $mu === 'miles' ? ($t['mileage_rate_miles'] ?? $t['mileage_rate'] ?? null) : ($t['mileage_rate_km'] ?? $t['mileage_rate'] ?? null);
                    if ($mr !== null && (float) $mr > 0): ?>
                        <div class="brc-fact"><span>Mileage rate</span><b><?= e(format_currency($mr)) ?> / <?= e($mu) ?></b></div>
                    <?php endif; ?>
                    <?php if ((float) ($t['estimated_mileage_per_day'] ?? 0) > 0): ?><div class="brc-fact"><span>Est. per day</span><b><?= e($t['estimated_mileage_per_day']) ?> <?= e($mu) ?></b></div><?php endif; ?>
                    <?php if ((float) ($t['estimated_engine_hours_per_day'] ?? 0) > 0): ?><div class="brc-fact"><span>Est. hours/day</span><b><?= e($t['estimated_engine_hours_per_day']) ?></b></div><?php endif; ?>
                    <?php if (($t['mileage_tracking_mode'] ?? 'off') !== 'off'): ?><div class="brc-fact"><span>Mileage mode</span><b><?= e($t['mileage_tracking_mode']) ?></b></div><?php endif; ?>
                    <?php if (($u['odometer_start'] ?? null) !== null || ($u['odometer_end'] ?? null) !== null): ?>
                        <div class="brc-fact"><span>Odometer</span><b><?= e($u['odometer_start'] ?? '—') ?> → <?= e($u['odometer_end'] ?? '—') ?></b></div>
                    <?php endif; ?>
                    <?php if (($u['hours_start'] ?? null) !== null || ($u['hours_end'] ?? null) !== null): ?>
                        <div class="brc-fact"><span>Engine hours</span><b><?= e($u['hours_start'] ?? '—') ?> → <?= e($u['hours_end'] ?? '—') ?></b></div>
                    <?php endif; ?>
                    <?php if (!empty($t['insurance'])): ?><div class="brc-fact"><span>Insurance</span><b><?= e(format_currency($t['insurance'])) ?></b></div><?php endif; ?>
                    <?php if (!empty($t['warranty'])): ?><div class="brc-fact"><span>Warranty</span><b><?= e(format_currency($t['warranty'])) ?></b></div><?php endif; ?>
                    <?php if (!empty($t['gps'])): ?><div class="brc-fact"><span>GPS</span><b><?= e(format_currency($t['gps'])) ?></b></div><?php endif; ?>
                    <?php if (!empty($t['minimum_billing_days'])): ?><div class="brc-fact"><span>Min. billing days</span><b><?= (int) $t['minimum_billing_days'] ?></b></div><?php endif; ?>
                    <?php if ((int) ($t['billing_days_removed'] ?? 0) > 0): ?><div class="brc-fact"><span>Days removed</span><b><?= (int) $t['billing_days_removed'] ?></b></div><?php endif; ?>
                    <?php if (!empty($p['exchange_rate_to_cad'])): ?><div class="brc-fact"><span>FX to CAD</span><b><?= e($p['exchange_rate_to_cad']) ?></b></div><?php endif; ?>
                </div>

                <div class="data-table-wrap">
                    <table class="data-table brc-lines">
                        <thead><tr>
                            <th>Type</th><th>Description</th><th>Period</th>
                            <th class="text-right">Qty</th><th class="text-right">Unit price</th><th class="text-right">Amount</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach (($p['lines'] ?? []) as $l): ?>
                            <?php
                            $type = (string) $l['item_type'];
                            $fam  = '';
                            if (!empty($l['is_credit']) || str_contains($type, 'credit'))            $fam = 'credit';
                            elseif (in_array($type, ['base_rental', 'insurance', 'warranty'], true)) $fam = 'rental';
                            elseif (str_starts_with($type, 'mileage') || str_starts_with($type, 'hours')
                                    || $type === 'hourly_usage' || $type === 'gps')                  $fam = 'usage';
                            elseif (in_array($type, ['late_fee', 'damage', 'cartage', 'sweep', 'wash', 'fuel'], true)) $fam = 'fee';
                            ?>
                            <tr>
                                <td><span class="brc-line-type" data-fam="<?= e($fam) ?>"><?= e(str_replace('_', ' ', $type)) ?></span></td>
                                <td>
                                    <div><?= e($l['description']) ?></div>
                                    <?php if (!empty($l['billing_days']) || !empty($l['rate_method'])): ?>
                                        <div class="brc-line-sub">
                                            <?php if (!empty($l['billing_days'])): ?><?= (int) $l['billing_days'] ?> days<?php endif; ?>
                                            <?php if (!empty($l['rate_method'])): ?>&middot; <?= e($l['rate_method']) ?><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($l['mileage_distance'])): ?>
                                        <div class="brc-line-sub">
                                            <?= e($l['mileage_distance']) ?> <?= e($l['mileage_unit']) ?>
                                            <?php if (!empty($l['mileage_rate'])): ?>@ <?= e($l['mileage_rate']) ?><?php endif; ?>
                                            <?php if (!empty($l['mileage_estimated'])): ?>&middot; est <?= e($l['mileage_estimated']) ?><?php endif; ?>
                                            <?php if (!empty($l['mileage_actual'])): ?>&middot; actual <?= e($l['mileage_actual']) ?><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="brc-nowrap"><?= e($l['period_start'] ?: '—') ?><?= $l['period_end'] ? ' → ' . e($l['period_end']) : '' ?></td>
                                <td class="text-right"><?= e(rtrim(rtrim(number_format((float) $l['quantity'], 4), '0'), '.')) ?> <span class="text-secondary"><?= e($l['unit'] ?? '') ?></span></td>
                                <td class="text-right currency"><?= e(format_currency($l['unit_price'])) ?></td>
                                <td class="text-right currency"><?= !empty($l['is_credit']) ? '&minus;' : '' ?><?= e(format_currency($l['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="brc-summary">
                    <div><span>Subtotal</span><b><?= e(format_currency($p['subtotal'])) ?></b></div>
                    <?php if ((float) $p['discount_amount'] > 0): ?><div><span>Discount</span><b>&minus;<?= e(format_currency($p['discount_amount'])) ?></b></div><?php endif; ?>
                    <?php if ((float) $p['tax_gst_amount'] > 0): ?><div><span>GST</span><b><?= e(format_currency($p['tax_gst_amount'])) ?></b></div><?php endif; ?>
                    <?php if ((float) $p['tax_pst_amount'] > 0): ?><div><span>PST</span><b><?= e(format_currency($p['tax_pst_amount'])) ?></b></div><?php endif; ?>
                    <?php if ((float) $p['tax_hst_amount'] > 0): ?><div><span>HST</span><b><?= e(format_currency($p['tax_hst_amount'])) ?></b></div><?php endif; ?>
                    <div class="brc-summary-total"><span>Total</span><b><?= e(format_currency($p['total_amount'])) ?> <?= e($p['currency']) ?></b></div>
                </div>
            </div>
            <?php endforeach; ?>
                </div><!-- /by-lease -->
            </div><!-- /brc-view-toggle -->

        </div>
    </div>
</div>

<?php
// Reuse the Billing Review look. The overlay variant is fixed/full-screen;
// this page is a normal document flow, so .batch-review-surface swaps that
// out while inheriting everything else.
$_brandPrimary = settings_get('brand.primary_color');
$_brandHover   = settings_get('brand.primary_hover');
$_brandLight   = settings_get('brand.primary_light');
?>
<style>
    /* Forced light, same rationale as the in-page Billing Review: this is a
       document people read and print. [data-theme="light"] in app.css is a
       bare attribute selector so it re-binds the light tokens for this
       subtree; the derived :root aliases and the brand override have to be
       re-asserted here because a var() reference resolves where it is
       DECLARED (see batch.php for the full write-up). */
    .batch-review-surface[data-theme="light"] {
        --bg-page:            var(--bg-body);
        --bg-card:            var(--bg-surface);
        --bg-elev:            var(--bg-surface-2);
        --bg-elevated:        var(--bg-surface-2);
        --bg-subtle:          var(--bg-surface-2);
        --bg-selected:        color-mix(in srgb, var(--color-primary) 12%, transparent);
        --border-default:     var(--border-color);
        --border-strong:      var(--border-color-strong);
        --text-muted:         var(--text-tertiary);
        --text-inverse:       var(--text-on-primary);
        --color-accent:       var(--color-primary);
        --color-accent-hover: var(--color-primary-hover);
        --card-sheen:         inset 0 1px 0 rgba(255, 255, 255, 0.9);
<?php if ($_brandPrimary): ?>
        --color-primary:       <?= e((string) $_brandPrimary) ?>;
        --color-primary-hover: <?= e((string) ($_brandHover ?: '#1e7ea0')) ?>;
        --color-primary-light: <?= e((string) ($_brandLight ?: '#e0f4fb')) ?>;
<?php endif; ?>
    }
    .batch-review-surface {
        color: var(--text-primary);   /* `color` inherits computed — must re-assert */
        background:
            radial-gradient(1200px 600px at 12% -8%, color-mix(in srgb, var(--color-primary) 11%, transparent), transparent 70%),
            var(--bg-body);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-xl);
        overflow: hidden;
    }
    .batch-review-head {
        display: flex; align-items: center; justify-content: space-between; gap: 16px;
        padding: 16px 24px; border-bottom: 1px solid var(--border-color);
        background: var(--bg-surface);
    }
    .batch-review-title { font-size: 20px; font-weight: 650; letter-spacing: -0.02em; display: flex; align-items: center; gap: 10px; }
    .batch-review-sub { font-size: 12.5px; color: var(--text-secondary); margin-top: 3px; }
    .batch-review-head-actions { display: flex; gap: 8px; align-items: center; flex-shrink: 0; }
    .batch-review-body { padding: 20px 24px 28px; }

    .brc-status-pill {
        font-size: 9.5px; font-weight: 700; letter-spacing: 0.09em; text-transform: uppercase;
        padding: 3px 9px; border-radius: 999px;
        color: var(--text-secondary); background: var(--bg-surface-2);
        border: 1px solid var(--border-color);
    }
    .brc-status-pill[data-status="pending"]   { color: var(--color-warning-text); background: var(--color-warning-light); border-color: color-mix(in srgb, var(--color-warning) 35%, transparent); }
    .brc-status-pill[data-status="approved"]  { color: var(--color-success-text); background: var(--color-success-light); border-color: color-mix(in srgb, var(--color-success) 35%, transparent); }
    .brc-status-pill[data-status="rejected"]  { color: var(--color-danger-text);  background: var(--color-danger-light);  border-color: color-mix(in srgb, var(--color-danger) 35%, transparent); }
    .brc-status-pill[data-status="generated"] { color: var(--color-info-text);    background: var(--color-info-light);    border-color: color-mix(in srgb, var(--color-info) 35%, transparent); }

    .brc-note { margin-bottom: 16px; padding: 12px 16px; border-radius: var(--radius-lg); background: var(--bg-surface-2); border: 1px solid var(--border-color); font-size: 13px; }
    .brc-note[data-kind="approve"] { background: var(--color-success-light); border-color: color-mix(in srgb, var(--color-success) 30%, transparent); }
    .brc-note[data-kind="reject"]  { background: var(--color-danger-light);  border-color: color-mix(in srgb, var(--color-danger) 30%, transparent); }

    .batch-review-section-title { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-secondary); margin-bottom: 6px; font-weight: 600; }
    .batch-review-problems { margin-bottom: 16px; padding: 14px 18px; border-radius: var(--radius-xl); border: 1px solid color-mix(in srgb, var(--color-danger) 40%, transparent); background: var(--color-danger-light); }
    .batch-problem-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: baseline; font-size: 13px; padding: 5px 0; }

    .batch-total-strip { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 4px; }
    .batch-total { flex: 1; min-width: 150px; position: relative; overflow: hidden; padding: 16px 18px; border-radius: var(--radius-xl); background: var(--bg-surface); border: 1px solid var(--border-color); box-shadow: var(--card-sheen), var(--shadow-md); }
    .batch-total::before { content: ""; position: absolute; inset: 0 auto 0 0; width: 3px; background: linear-gradient(180deg, var(--color-primary), color-mix(in srgb, var(--color-primary) 25%, transparent)); }
    .batch-total-label { font-size: 10px; letter-spacing: 0.09em; font-weight: 600; text-transform: uppercase; color: var(--text-secondary); }
    .batch-total-value { font-size: 24px; font-weight: 700; letter-spacing: -0.02em; margin-top: 4px; font-variant-numeric: tabular-nums; }

    /* View switch: per-lease detail (default) vs per-customer rollup. */
    /* .data-table-wrap has NO definition in app.css — it is a class these
       batch pages introduced and never styled. Without overflow-x:auto the
       table just overflowed its wrapper, and the ancestor .card
       (overflow:hidden) clipped the excess — so the right-hand columns were
       unreachable: no scrollbar, nothing to swipe, content simply gone.
       Scoped to these pages rather than added to app.css because nothing
       else in the app uses the class. */
    .data-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    /* Hint that there is more to the right, without a permanent scrollbar. */
    .data-table-wrap { scrollbar-width: thin; }

    .brc-view-toggle { margin-top: 18px; }
    .brc-view-switch {
        display: inline-flex; gap: 4px; padding: 4px;
        background: var(--bg-surface); border: 1px solid var(--border-color);
        border-radius: 11px;
    }
    .brc-view-btn {
        padding: 6px 14px; font-size: 13px; font-weight: 500; cursor: pointer;
        border-radius: 8px; border: 1px solid var(--border-color);
        background: var(--bg-surface-2); color: var(--text-secondary);
        transition: background 130ms ease, color 130ms ease;
    }
    .brc-view-btn:hover { background: var(--bg-hover); color: var(--text-primary); }
    .brc-view-btn.is-active {
        background: var(--color-primary); border-color: transparent;
        color: var(--text-on-primary);
    }

    .batch-review-card { margin-top: 18px; border-radius: var(--radius-xl); background: var(--bg-surface); border: 1px solid var(--border-color); box-shadow: var(--card-sheen), var(--shadow-md); overflow: hidden; }
    .brc-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 16px 20px; background: linear-gradient(135deg, color-mix(in srgb, var(--color-primary) 7%, var(--bg-surface-2)) 0%, var(--bg-surface-2) 55%); border-bottom: 1px solid var(--border-color); }
    .brc-customer { font-size: 16.5px; font-weight: 650; letter-spacing: -0.01em; }
    .brc-meta { font-size: 12px; color: var(--text-secondary); margin-top: 5px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .brc-head-total { text-align: right; flex-shrink: 0; }
    .brc-total-value { font-size: 22px; font-weight: 700; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; color: var(--color-primary); }

    .brc-facts { display: grid; grid-template-columns: repeat(auto-fill, minmax(215px, 1fr)); gap: 1px; background: var(--border-color); border-bottom: 1px solid var(--border-color); }
    .brc-fact { background: var(--bg-surface); padding: 9px 16px; font-size: 12px; display: flex; justify-content: space-between; gap: 10px; align-items: baseline; }
    .brc-fact > span { color: var(--text-secondary); white-space: nowrap; }
    .brc-fact > b { font-family: var(--font-mono); font-size: 11.5px; text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }

    .brc-lines { font-size: 12.5px; }
    .brc-lines thead th { font-size: 9.5px; letter-spacing: 0.08em; background: color-mix(in srgb, var(--bg-surface-2) 60%, transparent); }
    .brc-lines .currency { font-variant-numeric: tabular-nums; }
    .brc-line-type { font-size: 9.5px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; padding: 3px 8px; border-radius: 999px; white-space: nowrap; color: var(--text-secondary); background: var(--bg-surface-2); border: 1px solid var(--border-color); }
    .brc-line-type[data-fam="rental"] { color: color-mix(in srgb, var(--color-primary) 62%, var(--text-primary)); background: color-mix(in srgb, var(--color-primary) 13%, transparent); border-color: color-mix(in srgb, var(--color-primary) 30%, transparent); }
    .brc-line-type[data-fam="usage"]  { color: var(--color-info-text);    background: var(--color-info-light);    border-color: color-mix(in srgb, var(--color-info) 30%, transparent); }
    .brc-line-type[data-fam="credit"] { color: var(--color-success-text); background: var(--color-success-light); border-color: color-mix(in srgb, var(--color-success) 30%, transparent); }
    .brc-line-type[data-fam="fee"]    { color: var(--color-warning-text); background: var(--color-warning-light); border-color: color-mix(in srgb, var(--color-warning) 30%, transparent); }
    .brc-line-sub { font-size: 11px; color: var(--text-secondary); margin-top: 3px; }
    .brc-nowrap { white-space: nowrap; }
    .batch-lease-contract { font-family: var(--font-mono); font-size: 12px; }

    .brc-summary { display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: 10px 26px; padding: 14px 20px; border-top: 1px solid var(--border-color); background: linear-gradient(180deg, transparent, color-mix(in srgb, var(--color-primary) 4%, var(--bg-surface-2))); }
    .brc-summary > div { display: flex; gap: 9px; align-items: baseline; font-size: 12.5px; }
    .brc-summary span { color: var(--text-secondary); }
    .brc-summary b { font-family: var(--font-mono); font-variant-numeric: tabular-nums; }
    .brc-summary-total { padding-left: 22px; border-left: 1px solid var(--border-color); }
    .brc-summary-total span { color: var(--text-primary); font-weight: 600; }
    .brc-summary-total b { font-size: 17px; font-weight: 700; color: var(--color-primary); }

    @media print {
        /* Chrome only — the surface is a descendant of .app-layout/.app-main/
           .page-content, so those must be neutralised, never display:none'd
           (that blanks the printout). */
        .sidebar, .sidebar-overlay, .topbar, .breadcrumb, .app-footer,
        .ff-chat-fab, #ff-toast-container { display: none !important; }
        .app-layout, .app-main, .page-content {
            display: block !important;
            margin: 0 !important; padding: 0 !important;
            max-width: none !important; width: auto !important;
        }
        .batch-review-surface { border: 0; border-radius: 0; background: #fff !important; }
        .batch-review-head-actions { display: none !important; }
        .batch-review-card { break-inside: avoid; page-break-inside: avoid; box-shadow: none; }
    }
</style>

<script>
function BatchRunPage(cfg) {
    return {
        id: cfg.id,
        status: cfg.status,
        canApprove: cfg.canApprove,
        canGenerate: cfg.canGenerate,
        working: false,

        async decide(decision) {
            if (this.working) return;
            let note = '';
            if (decision === 'reject') {
                note = await FF_Confirm.askText({
                    title: 'Reject this batch run',
                    message: 'Tell the submitter what needs to change.',
                    confirmLabel: 'Reject run',
                    placeholder: 'e.g. Northgate rate is wrong for September',
                });
                if (!note) return;
            } else {
                const ok = await FF_Confirm.ask('Approve this batch run? It can then be generated into real draft invoices.');
                if (!ok) return;
                note = await FF_Confirm.askText({
                    title: 'Approval note (optional)',
                    message: 'Add a note for the record, or leave blank.',
                    confirmLabel: 'Approve run',
                    placeholder: 'Optional',
                }) || '';
            }

            this.working = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/batch_runs/decide') ?>', {
                    id: this.id, decision: decision, decision_note: note,
                });
                if (r.success) {
                    FF_Toast.success('Run ' + r.data.status + '.');
                    setTimeout(() => location.reload(), 800);
                } else {
                    FF_Toast.error(r.error?.message || 'Could not record the decision.');
                }
            } catch (e) {
                FF_Toast.error('Network error.');
            } finally {
                this.working = false;
            }
        },

        async generate() {
            if (this.working) return;
            const ok = await FF_Confirm.ask('Generate the invoices for this approved run? Each lease is re-checked against live data first — anything already billed since approval is skipped.');
            if (!ok) return;
            this.working = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/batch_runs/generate') ?>', { id: this.id });
                if (r.success) {
                    const d = r.data;
                    FF_Toast.success(d.actioned + ' invoice' + (d.actioned === 1 ? '' : 's') + ' created'
                        + (d.skipped ? ', ' + d.skipped + ' skipped' : '') + '.');
                    if (d.drifted?.length) FF_Toast.error(d.drifted.length + ' total(s) changed since approval — see the run record.');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    FF_Toast.error(r.error?.message || 'Generation failed.');
                }
            } catch (e) {
                FF_Toast.error('Network error during generation.');
            } finally {
                this.working = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
