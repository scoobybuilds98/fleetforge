<?php declare(strict_types=1);

/**
 * app/admin/accounting/leases/index.php
 *
 * Capital Lease Register — sales-type + direct-financing leases per
 * ASPE 3065. Read-only register that the lessor-module subledger pages
 * (LESSOR-2 amortization schedules, LESSOR-3/4 JE posting) hang off of.
 *
 * Expected to be empty until the operator originates the first capital
 * lease — the empty state explains where the wizard runs.
 *
 * NI Current / Long-Term columns will be populated once LESSOR-2
 * (acc_lease_amortization_schedules) ships. Until then they read from
 * leases.initial_fair_value as a placeholder.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           includes/partials/accounting-nav.php
 * @session  S-ACCT-LESSOR-1
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

// Query capital leases (sales_type + direct_financing). 42 'operating'
// leases are excluded by the WHERE clause. Soft-deleted leases excluded
// per SOFT_DELETE_TABLES discipline.
$capitalLeases = db_select(
    "SELECT
        l.id, l.contract_number, l.classification, l.start_date, l.end_date,
        l.initial_fair_value, l.implicit_rate, l.status,
        c.company_name, c.contact_name,
        u.unit_number,
        t.brand, t.model,
        signer.full_name AS signoff_name,
        l.classification_signed_off_at
     FROM leases l
     LEFT JOIN customers          c      ON c.id      = l.customer_id          AND c.deleted_at IS NULL
     LEFT JOIN equipment_units    u      ON u.id      = l.equipment_unit_id    AND u.deleted_at IS NULL
     LEFT JOIN equipment_templates t     ON t.id      = u.template_id          AND t.deleted_at IS NULL
     LEFT JOIN users              signer ON signer.id = l.classification_signed_off_by AND signer.deleted_at IS NULL
     WHERE l.classification IN ('sales_type','direct_financing')
       AND l.deleted_at IS NULL
     ORDER BY l.classification_signed_off_at DESC, l.id DESC",
    []
);

$pageTitle = 'Capital Lease Register';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Capital Lease Register</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Capital Lease Register</h1>
    <p class="page-header-subtitle">
        Sales-type and direct-financing leases per ASPE 3065. Operating leases
        are managed on the main <a href="<?= base_url('leases') ?>">Leases</a> page.
    </p>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<?php if (empty($capitalLeases)): ?>

<div class="card" style="padding:2rem;text-align:center;">
    <div style="font-size:1.1rem;font-weight:600;margin-bottom:0.5rem;">
        No capital leases on record.
    </div>
    <div style="color:var(--text-secondary);max-width:560px;margin:0 auto;">
        The ASPE 3065 classification wizard appears during lease creation when a
        lease-to-own contract is structured.
        <br><br>
        Start a new lease and pick
        <strong>"Potential Capital Lease"</strong> in the Lease Classification
        section to evaluate criteria A/B/C and have the result archived here.
    </div>
    <div style="margin-top:1.25rem;">
        <a href="<?= base_url('leases/create') ?>" class="btn btn-primary">
            Create New Lease
        </a>
    </div>
</div>

<?php else: ?>

<div class="card">
    <table class="table" style="width:100%;">
        <thead>
            <tr>
                <th>Lease #</th>
                <th>Customer</th>
                <th>Unit</th>
                <th>Classification</th>
                <th>Start</th>
                <th>End</th>
                <th style="text-align:right;">Fair Value</th>
                <th style="text-align:right;">Implicit Rate</th>
                <th style="text-align:right;">NI Current</th>
                <th style="text-align:right;">NI Long-Term</th>
                <th>Status</th>
                <th>Signed Off</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($capitalLeases as $l): ?>
            <?php
                $classBadge = $l['classification'] === 'sales_type'
                    ? 'badge-warning'
                    : 'badge-info';
                $classLabel = $l['classification'] === 'sales_type'
                    ? 'Sales-Type'
                    : 'Direct Financing';
                $unitDisp = trim(($l['unit_number'] ?? '') . ' — ' . trim(($l['brand'] ?? '') . ' ' . ($l['model'] ?? '')));
                $fair = $l['initial_fair_value'] !== null ? '$' . number_format((float) $l['initial_fair_value'], 2) : '—';
                $rate = $l['implicit_rate'] !== null ? number_format((float) $l['implicit_rate'] * 100, 2) . '%' : '—';
                // LESSOR-2 will replace these placeholders with reads from
                // acc_lease_amortization_schedules (next-12-month vs beyond).
                $niCurrent  = '<span style="color:var(--text-secondary);">pending LESSOR-2</span>';
                $niLongTerm = '<span style="color:var(--text-secondary);">pending LESSOR-2</span>';
            ?>
            <tr>
                <td><a href="<?= base_url('leases/show') ?>?id=<?= (int) $l['id'] ?>"><?= e($l['contract_number']) ?></a></td>
                <td><?= e($l['company_name'] ?? '—') ?></td>
                <td><?= e($unitDisp) ?></td>
                <td><span class="badge <?= $classBadge ?>"><?= $classLabel ?></span></td>
                <td><?= e($l['start_date']) ?></td>
                <td><?= e($l['end_date'] ?? '—') ?></td>
                <td style="text-align:right;"><?= $fair ?></td>
                <td style="text-align:right;"><?= $rate ?></td>
                <td style="text-align:right;"><?= $niCurrent ?></td>
                <td style="text-align:right;"><?= $niLongTerm ?></td>
                <td><span class="badge badge-neutral"><?= e($l['status']) ?></span></td>
                <td><?= e($l['signoff_name'] ?? '—') ?><br>
                    <span style="font-size:0.75rem;color:var(--text-secondary);"><?= e($l['classification_signed_off_at'] ?? '') ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
