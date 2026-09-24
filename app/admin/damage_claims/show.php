<?php
declare(strict_types=1);

/**
 * app/admin/damage_claims/show.php
 *
 * Damage Claim detail page (S-RECORD-REDESIGN record layout):
 *   - Entity hero: claim number + status + severity badges, the damage
 *     location, and unit / customer / lease / reported-date facts. The header
 *     holds "Change Status" (toggles the status panel at the top of the main
 *     column); Delete (reported/assessed only) sits in the More menu.
 *   - Key-numbers strip: estimated repair, actual repair (vs the estimate),
 *     customer liable (+ insurance), recovery invoice (paid / balance for
 *     money roles; its status only for everyone else), days open.
 *   - Main column: Change Status panel, Claim Details (view / edit form),
 *     Photos (gallery + upload), Activity.
 *   - Right rail: Needs attention (liable amount not invoiced, invoiced with no
 *     link, recovery invoice draft / overdue / paid-but-claim-open, no photos,
 *     no estimate, no work order on a repair, customer not linked), Recovery
 *     (invoice link + status; paid meter, balance and GL entry for money
 *     roles), Unit, Customer (+ lease), Related (inspection, work order,
 *     vendor), Summary.
 *
 * Recovery invoice link (bug #8): the status panel requires picking the
 * customer's (sent) recovery invoice when moving to 'invoiced', and the edit
 * form can set / fix the link — damage_claims/update.php validates it and fires
 * AutoEntryBridge::onDamageRecoveryBilled so the recovery is classified in the
 * GL. Options are the claim customer's non-void invoices, rendered server-side;
 * amounts only for users who pass can_view_financials() — the same gate holds
 * the recovery invoice's paid / balance figures in the strip and rail. The
 * claim's own estimate / actual / liable / insurance figures keep the page's
 * existing visibility (maintenance:view).
 *
 * All writes go through Alpine.js → API endpoints.
 * File upload uses FormData (multipart) to upload_photo.php.
 *
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/damage_claims/show.php, update.php, upload_photo.php, delete_photo.php,
 *           delete.php
 * @decisions D5/D9/D19/D30/D32
 * @session  S012, S-RECORD-REDESIGN
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\Storage\StorageClient;

require_auth();
require_permission('maintenance', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    http_response_code(400);
    die('Missing id.');
}

// Load claim
$claim = db_row(
    "SELECT
        dc.id,
        dc.claim_number,
        dc.equipment_unit_id,
        eu.unit_number,
        eu.year,
        eb.label AS brand,
        et.model,
        dc.lease_id,
        l.contract_number,
        dc.customer_id,
        dc.customer_name,
        c.company_name        AS customer_company_name,
        dc.inspection_id,
        dc.work_order_id,
        dc.invoice_id,
        dc.description,
        dc.damage_location,
        dc.severity,
        dc.estimated_repair_cost,
        dc.actual_repair_cost,
        dc.customer_liable_amount,
        dc.insurance_claim_amount,
        dc.status,
        dc.notes,
        dc.resolution_notes,
        dc.vendor_id,
        v.name                AS vendor_name,
        v.vendor_type         AS vendor_type,
        dc.reported_by,
        u.name                AS reported_by_name,
        dc.created_at,
        dc.updated_at
     FROM damage_claims dc
     LEFT JOIN equipment_units     eu ON eu.id = dc.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates et ON et.id = eu.template_id        AND et.deleted_at IS NULL
     LEFT JOIN equipment_brands    eb ON eb.id = eu.brand_id
     LEFT JOIN customers c            ON c.id  = dc.customer_id        AND c.deleted_at  IS NULL
     LEFT JOIN leases l            ON l.id  = dc.lease_id          AND l.deleted_at  IS NULL
     LEFT JOIN vendors v            ON v.id  = dc.vendor_id         AND v.deleted_at  IS NULL
     LEFT JOIN users u            ON u.id  = dc.reported_by
     WHERE dc.id = ? AND dc.deleted_at IS NULL",
    [$id]
);

if (!$claim) {
    http_response_code(404);
    die('Damage claim not found.');
}

// ── Recovery invoice options (bug #8) ────────────────────────────────────────
// The claim customer's non-void invoices, newest first. A draft may be linked:
// the recovery posts to the GL when that invoice is sent
// (FinancialActions::sendInvoice → AutoEntryBridge::onDamageRecoveryBilled).
$canSeeMoney     = can_view_financials();
$invoiceOptions  = [];
if ($claim['customer_id']) {
    $invoiceOptions = db_select(
        "SELECT id, invoice_number, status, invoice_date, total_amount
           FROM invoices
          WHERE customer_id = ? AND deleted_at IS NULL AND status <> 'void'
          ORDER BY invoice_date DESC, id DESC
          LIMIT 200",
        [$claim['customer_id']]
    );
}
$linkedInvoice = $claim['invoice_id']
    // S-RECORD-REDESIGN: + dates / amounts for the strip + Recovery rail card
    // (amounts are rendered only for can_view_financials() users).
    ? db_row("SELECT id, invoice_number, status, invoice_date, due_date, total_amount, amount_paid, balance_due, currency FROM invoices WHERE id = ?", [$claim['invoice_id']])
    : null;
// Live GL classification for this claim (idempotency marker of the bridge).
$recoveryJe = db_row(
    "SELECT id, entry_number FROM acc_journal_entries
      WHERE source_type = 'damage_recovery' AND source_id = ?
        AND is_reversal = 0 AND reversed_by_id IS NULL
      LIMIT 1",
    [$id]
);
/**
 * Human label for a recovery-invoice <option>.
 *
 * @param array $inv          invoices row (invoice_number, status, invoice_date, total_amount)
 * @param bool  $canSeeMoney  include the total only for financial viewers
 * @return string
 */
$invoiceOptionLabel = static function (array $inv, bool $canSeeMoney): string {
    $label = $inv['invoice_number'] . ' · ' . ($inv['invoice_date'] ?? '') . ' · ' . str_replace('_', ' ', (string) $inv['status']);
    if ($canSeeMoney) {
        $label .= ' · ' . format_currency($inv['total_amount']);
    }
    if ($inv['status'] === 'draft') {
        $label .= ' (draft — posts to GL when sent)';
    }
    return $label;
};

// Vendors list for edit form dropdown
$vendorsList = db_select(
    "SELECT id, name FROM vendors WHERE deleted_at IS NULL ORDER BY name ASC"
);

// Customers list for edit form dropdown
$customersList = db_select(
    "SELECT id, company_name FROM customers WHERE status = 'active' AND deleted_at IS NULL ORDER BY company_name ASC"
);

// Load photos — serve signed URLs, never raw file_path
$photos = db_select(
    "SELECT id, claim_id, photo_type, caption, uploaded_at, file_path
     FROM damage_claim_photos
     WHERE claim_id = ?
     ORDER BY uploaded_at ASC",
    [$id]
);

$photoData = [];
foreach ($photos as $p) {
    $photoData[] = [
        'id'          => (int)$p['id'],
        'photo_type'  => $p['photo_type'],
        'caption'     => $p['caption'],
        'uploaded_at' => $p['uploaded_at'],
        'url'         => StorageClient::url($p['file_path']),
    ];
}

// State machine — valid next states
$allowedTransitions = [
    'reported'       => ['assessed', 'written_off'],
    'assessed'       => ['repair_ordered', 'written_off'],
    'repair_ordered' => ['invoiced', 'resolved', 'written_off'],
    'invoiced'       => ['resolved', 'written_off'],
    'resolved'       => [],
    'written_off'    => [],
];
$nextStates = $allowedTransitions[$claim['status']] ?? [];

// Badge helpers (PHP-rendered for header)
$statusBadgeClass = match($claim['status']) {
    'reported'       => 'badge badge-info',
    'assessed'       => 'badge badge-warning',
    'repair_ordered' => 'badge badge-warning',
    'invoiced'       => 'badge badge-purple',
    'resolved'       => 'badge badge-success',
    'written_off'    => 'badge badge-neutral',
    default          => 'badge badge-neutral',
};

$statusLabel = match($claim['status']) {
    'reported'       => 'Reported',
    'assessed'       => 'Assessed',
    'repair_ordered' => 'Repair Ordered',
    'invoiced'       => 'Invoiced',
    'resolved'       => 'Resolved',
    'written_off'    => 'Written Off',
    default          => $claim['status'],
};

$severityBadgeClass = match($claim['severity']) {
    'minor'      => 'badge badge-info',
    'moderate'   => 'badge badge-warning',
    'major'      => 'badge badge-danger',
    'total_loss' => 'badge badge-danger',
    default      => 'badge badge-neutral',
};

$severityLabel = match($claim['severity']) {
    'minor'      => 'Minor',
    'moderate'   => 'Moderate',
    'major'      => 'Major',
    'total_loss' => 'Total Loss',
    default      => $claim['severity'],
};

// ── S-RECORD-REDESIGN: strip + rail context ──────────────────────────────────
$today      = ff_today();   // company-local business day (never SQL CURDATE())
$isClosed   = in_array($claim['status'], ['resolved', 'written_off'], true);
// created_at is a UTC DATETIME — the business day it was reported is local.
$claimStart = $claim['created_at'] ? ff_utc_to_local((string) $claim['created_at']) : null;
$daysOpen   = $claimStart ? max(0, (int) round((strtotime($today) - strtotime($claimStart)) / 86400)) : null;
$liable     = (string) ($claim['customer_liable_amount'] ?? '');
$hasLiable  = $liable !== '' && bccomp($liable, '0', 2) > 0;
// Recovery invoice state (the link is damage_claims.invoice_id).
$recInv       = $linkedInvoice;
$recStatus    = $recInv['status'] ?? null;
$recOpen      = $recInv && in_array($recStatus, ['sent', 'partially_paid', 'overdue'], true)
                && bccomp((string) $recInv['balance_due'], '0', 2) > 0;
$recOverdue   = $recOpen && !empty($recInv['due_date']) && $recInv['due_date'] < $today;
$recPaid      = $recInv && $recStatus === 'paid';
$recCur       = ($recInv['currency'] ?? 'CAD') === 'USD' ? 'US$' : '$';
$recPaidPct   = ($recInv && bccomp((string) $recInv['total_amount'], '0', 2) > 0)
    ? (float) bcmul(bcdiv((string) $recInv['amount_paid'], (string) $recInv['total_amount'], 6), '100', 2) : 0.0;
// Linked inspection's number (the claim row only carries the id).
$claimInspection = $claim['inspection_id']
    ? db_row("SELECT id, inspection_number, inspection_type, inspection_date FROM inspections WHERE id = ?", [(int) $claim['inspection_id']])
    : null;
$claimWorkOrder = $claim['work_order_id']
    ? db_row("SELECT id, work_order_number, status FROM maintenance_work_orders WHERE id = ? AND deleted_at IS NULL", [(int) $claim['work_order_id']])
    : null;
$customerDisplay = $claim['customer_company_name'] ?? $claim['customer_name'] ?? null;
$unitDesc = trim(($claim['year'] ? $claim['year'] . ' ' : '') . ($claim['brand'] ?? '') . ($claim['model'] ? ' ' . $claim['model'] : ''));
$pageTitle = e($claim['claim_number']) . ' — Damage Claim';
$helpModuleSlug = 'damage-claims';
require_once FF_ROOT . '/includes/header.php';
?>

<div x-data="damageClaimShow()">

<?php
// ── Header (S-RECORD-REDESIGN) ────────────────────────────────────────────────
$heroFacts = [];
if ($claim['equipment_unit_id']) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('truck') . '<a href="' . e(base_url('equipment/show')) . '?id=' . (int) $claim['equipment_unit_id'] . '">Unit <b>' . e($claim['unit_number'] ?? ('#' . $claim['equipment_unit_id'])) . '</b></a>' . ($unitDesc !== '' ? ' · ' . e($unitDesc) : '');
}
if ($customerDisplay) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('user-group') . ($claim['customer_id']
        ? '<a href="' . e(base_url('customers/show')) . '?id=' . (int) $claim['customer_id'] . '">' . e($customerDisplay) . '</a>'
        : e($customerDisplay));
}
if ($claim['lease_id']) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('calendar-days') . '<a href="' . e(base_url('leases/show')) . '?id=' . (int) $claim['lease_id'] . '">Lease ' . e($claim['contract_number'] ?? ('#' . $claim['lease_id'])) . '</a>';
}
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('clock') . 'Reported ' . e(format_date($claimStart));
?>
<?php ob_start(); /* destructive actions → the header's More menu (S-RECORD-REDESIGN) */ ?>
        <?php if (can('maintenance', 'delete') && in_array($claim['status'], ['reported', 'assessed'], true)): ?>
        <button type="button" class="btn btn-danger btn-sm"
                @click="confirmDelete = true">
            Delete
        </button>
        <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
        <?= help_button('damage-claims') ?>
        <?php if (can('maintenance', 'edit') && $nextStates): ?>
        <button type="button" class="btn btn-primary btn-sm"
                @click="showStatusPanel = !showStatusPanel; if (showStatusPanel) $nextTick(() => document.getElementById('dc-status-panel')?.scrollIntoView({ block: 'nearest' }))"
                :aria-expanded="showStatusPanel ? 'true' : 'false'">
            Change Status
        </button>
        <?php endif; ?>
        <?php if (can('maintenance', 'edit')): ?>
        <button type="button" class="btn btn-secondary btn-sm"
                @click="showEditForm = true; $nextTick(() => document.getElementById('dc-details')?.scrollIntoView({ block: 'start' }))">
            Edit
        </button>
        <?php endif; ?>
        <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => 'danger',
    'icon'       => 'exclamation-triangle',
    'mark'       => (string) $claim['claim_number'],
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Damage Claims', base_url('damage_claims')], [(string) $claim['claim_number'], null]],
    'eyebrow'    => 'Damage claim',
    'title_html' => e($claim['claim_number'])
        . ' <span class="' . $statusBadgeClass . '" style="font-size:0.75rem;vertical-align:middle;margin-left:6px;">' . e($statusLabel) . '</span>'
        . ' <span class="' . $severityBadgeClass . '" style="font-size:0.75rem;vertical-align:middle;" title="Severity">' . e($severityLabel) . '</span>',
    'subtitle'   => $claim['damage_location'] ? e($claim['damage_location']) : e(mb_strimwidth((string) $claim['description'], 0, 120, '…')),
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<!-- ============================================================
     KEY NUMBERS — the summary strip (S-RECORD-REDESIGN): what the
     damage costs, what the customer owes for it, and how much of that
     has come back. Unit + customer moved to the header / rail.
     Recovery paid / balance: can_view_financials() only.
     ============================================================ -->
<?php
$estimate = $claim['estimated_repair_cost'];
$actual   = $claim['actual_repair_cost'];
$variance = ($estimate !== null && $actual !== null) ? bcsub((string) $actual, (string) $estimate, 2) : null;
?>
<div class="stat-grid stat-grid--5 ff-stats">
    <div class="stat-card stat-card--amber">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-wrench"/></svg></span>
        <div class="stat-label">Est. repair</div>
        <div class="stat-value font-mono"><?= $estimate !== null ? e(format_currency($estimate)) : '—' ?></div>
        <div class="stat-delta"><?= $estimate !== null ? 'estimate' : 'not assessed' ?></div>
    </div>

    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Actual repair</div>
        <div class="stat-value font-mono"><?= $actual !== null ? e(format_currency($actual)) : '—' ?></div>
        <div class="stat-delta"><?php
            if ($actual === null) {
                echo 'pending';
            } elseif ($variance === null || bccomp($variance, '0', 2) === 0) {
                echo 'as estimated';
            } else {
                echo e(format_currency(ltrim($variance, '-'))) . (bccomp($variance, '0', 2) > 0 ? ' over' : ' under') . ' estimate';
            }
        ?></div>
    </div>

    <div class="stat-card stat-card--purple">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Customer liable</div>
        <div class="stat-value font-mono"><?= $hasLiable ? e(format_currency($liable)) : '—' ?></div>
        <div class="stat-delta"><?= $claim['insurance_claim_amount'] ? 'insurance ' . e(format_currency($claim['insurance_claim_amount'])) : 'no insurance claim' ?></div>
    </div>

    <?php
    $recTone = !$recInv ? ($hasLiable && !$isClosed ? 'red' : 'slate') : ($recPaid ? 'green' : ($recOverdue ? 'red' : 'amber'));
    ?>
    <<?= $recInv ? 'a href="' . e(base_url('invoices/show')) . '?id=' . (int) $recInv['id'] . '" title="Open the recovery invoice"' : 'div' ?> class="stat-card stat-card--<?= $recTone ?>">
        <span class="stat-icon stat-icon--<?= $recTone ?>"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Recovered</div>
        <?php if (!$recInv): ?>
        <div class="stat-value">Not invoiced</div>
        <div class="stat-delta"><?= $hasLiable && !$isClosed ? 'no recovery invoice' : '—' ?></div>
        <?php elseif ($canSeeMoney): ?>
        <div class="stat-value font-mono"><?= e(format_currency($recInv['amount_paid'], $recCur)) ?></div>
        <div class="stat-delta"><?= $recPaid ? 'paid in full' : e(format_currency($recInv['balance_due'], $recCur)) . ' due' . ($recOverdue ? ' · overdue' : '') ?></div>
        <?php else: ?>
        <div class="stat-value"><?= e(ucwords(str_replace('_', ' ', (string) $recStatus))) ?></div>
        <div class="stat-delta"><?= e($recInv['invoice_number']) ?></div>
        <?php endif; ?>
    </<?= $recInv ? 'a' : 'div' ?>>

    <div class="stat-card <?= $isClosed ? 'stat-card--green' : (($daysOpen ?? 0) > 30 ? 'stat-card--red' : 'stat-card--slate') ?>">
        <span class="stat-icon <?= $isClosed ? 'stat-icon--green' : (($daysOpen ?? 0) > 30 ? 'stat-icon--red' : 'stat-icon--slate') ?>"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label"><?= $isClosed ? 'Closed' : 'Days open' ?></div>
        <div class="stat-value<?= $isClosed ? '' : ' font-mono' ?>"><?= $isClosed ? e($statusLabel) : ($daysOpen !== null ? number_format($daysOpen) : '—') ?></div>
        <div class="stat-delta">reported <?= e(format_date($claimStart)) ?></div>
    </div>
</div>

<div class="rec-layout">
<div class="rec-main" style="display:flex;flex-direction:column;gap:16px;">
<!-- ── Status transition panel ───────────────────────────────────────────── -->
<?php if (can('maintenance', 'edit') && $nextStates): ?>
<div class="card" id="dc-status-panel" x-show="showStatusPanel" style="display:none;">
    <div class="card-header">
        <h2 class="card-title">Change Status</h2>
    </div>
    <div class="card-body">
        <template x-if="statusError">
            <div class="alert alert-danger" style="margin-bottom:12px;" x-text="statusError"></div>
        </template>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div class="form-group" style="min-width:200px;">
                <label class="form-label">New Status</label>
                <select class="form-select" x-model="newStatus" @change="statusError = ''">
                    <option value="">— Select —</option>
                    <?php foreach ($nextStates as $s): ?>
                    <option value="<?= e($s) ?>"><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (in_array('invoiced', $nextStates, true)): ?>
            <!-- Bug #8: 'invoiced' requires the recovery invoice — the link is what posts to the GL (on send for a draft). -->
            <div class="form-group" style="min-width:320px;" x-show="newStatus === 'invoiced'" x-cloak>
                <label class="form-label" for="status_invoice_id">Recovery Invoice <span class="text-danger">*</span></label>
                <?php if ($claim['customer_id']): ?>
                <select id="status_invoice_id" class="form-select" x-model="statusInvoiceId" @change="statusError = ''">
                    <option value="">— Select the customer's invoice —</option>
                    <?php foreach ($invoiceOptions as $inv): ?>
                    <option value="<?= (int) $inv['id'] ?>"><?= e($invoiceOptionLabel($inv, $canSeeMoney)) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint"><?= $invoiceOptions ? 'Linking the invoice posts the damage recovery to the general ledger (a draft posts when it is sent).' : 'This customer has no invoices yet — create and send the recovery invoice first.' ?></div>
                <?php else: ?>
                <div class="form-hint">Link this claim to a customer (Edit → Customer) first — the recovery invoice must belong to that customer.</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (in_array('written_off', $nextStates, true)):
                // S-QBO-INVOICE-WRITEOFF: say what "Written Off" does to the
                // recovery invoice before the operator applies it.
                $woInv = $claim['invoice_id']
                    ? db_row("SELECT invoice_number, status, balance_due, currency FROM invoices WHERE id = ? AND deleted_at IS NULL", [(int) $claim['invoice_id']])
                    : null;
                $woWritable = $woInv && in_array($woInv['status'], \FleetForge\Accounting\InvoiceWriteOff::WRITABLE_STATUSES, true)
                    && bccomp((string) $woInv['balance_due'], '0', 2) > 0;
            ?>
            <div class="form-group" style="min-width:320px;max-width:560px;" x-show="newStatus === 'written_off'" x-cloak>
                <div class="form-hint">
                    <?php if ($woWritable): ?>
                    This also writes off the remaining balance of invoice <strong><?= e($woInv['invoice_number']) ?></strong><?= $canSeeMoney ? ' (' . e(format_currency($woInv['balance_due'], $woInv['currency'] === 'USD' ? 'US$' : '$')) . ')' : '' ?>
                    as bad debt: the invoice is closed and the customer's balance goes down. When QuickBooks is connected, the invoice is closed there too.
                    <?php elseif ($woInv): ?>
                    Invoice <?= e($woInv['invoice_number']) ?> is <?= e(str_replace('_', ' ', $woInv['status'])) ?> — nothing is written off; only the claim's status changes.
                    <?php else: ?>
                    No recovery invoice is linked — only the claim's status changes.
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <button class="btn btn-primary"
                    :disabled="!newStatus || statusSaving || (newStatus === 'invoiced' && !statusInvoiceId)"
                    @click="changeStatus()">
                <span x-text="statusSaving ? 'Saving…' : 'Apply'"></span>
            </button>
            <button class="btn btn-secondary"
                    @click="showStatusPanel = false; newStatus = ''; statusError = ''">
                Cancel
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Detail card ───────────────────────────────────────────────────────── -->
<div class="card" id="dc-details">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h2 class="card-title">Claim Details</h2>
        <?php if (can('maintenance', 'edit')): ?>
        <button class="btn btn-secondary btn-sm"
                @click="showEditForm = !showEditForm"
                x-text="showEditForm ? 'Cancel Edit' : 'Edit'"></button>
        <?php endif; ?>
    </div>
    <div class="card-body">

        <!-- View mode -->
        <div x-show="!showEditForm">
            <dl class="rec-dl">
                <?php /* S-RECORD-REDESIGN: claim number (header), reported by / at and
                         last updated (rail Summary) no longer repeat here. */ ?>
                <dt>Severity</dt>
                <dd><span class="<?= $severityBadgeClass ?>"><?= e($severityLabel) ?></span></dd>

                <dt>Damage Location</dt>
                <dd><?= $claim['damage_location'] ? e($claim['damage_location']) : '—' ?></dd>

                <dt>Description</dt>
                <dd style="white-space:pre-wrap;"><?= e($claim['description']) ?></dd>

                <dt>Work Order</dt>
                <dd>
                    <?php if ($claim['work_order_id']): ?>
                    <a href="<?= base_url('maintenance_work_orders/show') ?>?id=<?= e($claim['work_order_id']) ?>"
                       class="link"><?= e($claimWorkOrder['work_order_number'] ?? ('#' . $claim['work_order_id'])) ?></a>
                    <?php else: ?>—<?php endif; ?>
                </dd>

                <dt>Invoice</dt>
                <dd>
                    <?php if ($claim['invoice_id']): ?>
                    <a href="<?= base_url('invoices/show') ?>?id=<?= e($claim['invoice_id']) ?>"
                       class="link"><?= e($linkedInvoice['invoice_number'] ?? ('#' . $claim['invoice_id'])) ?></a>
                    <?php if ($linkedInvoice): ?>
                    <span class="badge badge-neutral" style="margin-left:6px;"><?= e(ucwords(str_replace('_', ' ', $linkedInvoice['status']))) ?></span>
                    <?php endif; ?>
                    <?php if ($recoveryJe && $canSeeMoney): ?>
                    <span class="text-secondary" style="margin-left:6px;font-size:0.8rem;">recovery posted to GL (<?= e($recoveryJe['entry_number']) ?>)</span>
                    <?php endif; ?>
                    <?php elseif ($claim['status'] === 'invoiced'): ?>
                    <span class="text-danger">Not linked — edit the claim and pick the recovery invoice so it posts to the general ledger.</span>
                    <?php else: ?>—<?php endif; ?>
                </dd>

                <dt>Vendor Sent To</dt>
                <dd>
                    <?php if ($claim['vendor_id']): ?>
                    <a href="<?= base_url('vendors/show') ?>?id=<?= e($claim['vendor_id']) ?>"
                       class="link"><?= e($claim['vendor_name']) ?></a>
                    <?php else: ?>—<?php endif; ?>
                </dd>

                <?php if ($claimInspection): ?>
                <dt>Inspection</dt>
                <dd><a href="<?= base_url('inspections/show') ?>?id=<?= (int) $claimInspection['id'] ?>" class="link"><?= e($claimInspection['inspection_number'] ?? ('#' . $claimInspection['id'])) ?></a>
                    <span class="text-secondary">· <?= e(format_date($claimInspection['inspection_date'])) ?></span></dd>
                <?php endif; ?>
            </dl>

            <?php if ($claim['notes']): ?>
            <div style="margin-top:16px;">
                <strong>Notes</strong>
                <p style="white-space:pre-wrap;margin-top:4px;"><?= e($claim['notes']) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($claim['resolution_notes']): ?>
            <div style="margin-top:16px;">
                <strong>Resolution Notes</strong>
                <p style="white-space:pre-wrap;margin-top:4px;"><?= e($claim['resolution_notes']) ?></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Edit form (visible when showEditForm) -->
        <?php if (can('maintenance', 'edit')): ?>
        <div x-show="showEditForm" style="display:none;">
            <template x-if="staleError">
                <div class="alert alert-danger" style="margin-bottom:12px;">
                    This damage claim was modified by another user. Please reload this page to get the latest version.
                </div>
            </template>
            <form id="dmg-edit-form" @submit.prevent="saveEdit()" novalidate>
                <div class="form-error-banner" data-form-error></div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                    <div class="form-group">
                        <label class="form-label" for="edit_severity">Severity</label>
                        <select id="edit_severity" name="severity" class="form-select" x-model="editForm.severity">
                            <option value="minor">Minor</option>
                            <option value="moderate">Moderate</option>
                            <option value="major">Major</option>
                            <option value="total_loss">Total Loss</option>
                        </select>
                        <div class="field-error" data-error-for="severity"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_damage_location">Damage Location</label>
                        <input type="text" id="edit_damage_location" name="damage_location" class="form-control" x-model="editForm.damage_location" maxlength="255">
                        <div class="field-error" data-error-for="damage_location"></div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="edit_description">Description</label>
                    <textarea id="edit_description" name="description" class="form-control" rows="4" x-model="editForm.description"></textarea>
                    <div class="field-error" data-error-for="description"></div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="edit_customer_id">Customer</label>
                    <select id="edit_customer_id" name="customer_id" class="form-select" x-model="editForm.customer_id"
                            @change="if(editForm.customer_id) editForm.customer_name = ''">
                        <option value="">— Select existing —</option>
                        <?php foreach ($customersList as $c): ?>
                        <option value="<?= e($c['id']) ?>"><?= e($c['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="customer_name" class="form-control" style="margin-top:6px;"
                           placeholder="Or type customer name…"
                           x-model="editForm.customer_name"
                           maxlength="255"
                           @input="if(editForm.customer_name) editForm.customer_id = ''">
                    <div class="field-error" data-error-for="customer_id"></div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                    <div class="form-group">
                        <label class="form-label" for="edit_estimated_repair_cost">Est. Repair Cost</label>
                        <input type="number" min="0" id="edit_estimated_repair_cost" name="estimated_repair_cost" class="form-control" step="0.01" x-model="editForm.estimated_repair_cost">
                        <div class="field-error" data-error-for="estimated_repair_cost"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_actual_repair_cost">Actual Repair Cost</label>
                        <input type="number" min="0" id="edit_actual_repair_cost" name="actual_repair_cost" class="form-control" step="0.01" x-model="editForm.actual_repair_cost">
                        <div class="field-error" data-error-for="actual_repair_cost"></div>
                        <?php // SOP I14 (by design): the claim records the figure; the ledger gets the cost from the shop's bill. ?>
                        <div class="form-hint text-secondary text-sm">For reference only — the repair cost reaches the books when the repair shop's bill is approved (Payables → Bills, linked to the work order). Posting it here too would count it twice.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_customer_liable_amount">Liable Amount ($)</label>
                        <input type="number" min="0" id="edit_customer_liable_amount" name="customer_liable_amount" class="form-control" step="0.01" x-model="editForm.customer_liable_amount">
                        <div class="field-error" data-error-for="customer_liable_amount"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_insurance_claim_amount">Insurance Claim</label>
                        <input type="number" min="0" id="edit_insurance_claim_amount" name="insurance_claim_amount" class="form-control" step="0.01" x-model="editForm.insurance_claim_amount">
                        <div class="field-error" data-error-for="insurance_claim_amount"></div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="edit_notes">Notes</label>
                    <textarea id="edit_notes" name="notes" class="form-control" rows="3" x-model="editForm.notes"></textarea>
                    <div class="field-error" data-error-for="notes"></div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="edit_resolution_notes">Resolution Notes</label>
                    <textarea id="edit_resolution_notes" name="resolution_notes" class="form-control" rows="3" x-model="editForm.resolution_notes"></textarea>
                    <div class="field-error" data-error-for="resolution_notes"></div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="edit_vendor_id">Vendor Sent To</label>
                    <select id="edit_vendor_id" name="vendor_id" class="form-select" x-model="editForm.vendor_id">
                        <option value="">— None —</option>
                        <?php foreach ($vendorsList as $v): ?>
                        <option value="<?= e($v['id']) ?>"><?= e($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-error" data-error-for="vendor_id"></div>
                </div>

                <!-- Bug #8: recovery invoice link (was API-only — no UI could set it). -->
                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="edit_invoice_id">Recovery Invoice</label>
                    <select id="edit_invoice_id" name="invoice_id" class="form-select" x-model="editForm.invoice_id"
                            <?= (!$claim['customer_id'] || ($recoveryJe && $claim['invoice_id'])) ? 'disabled' : '' ?>>
                        <option value="">— None —</option>
                        <?php
                        $optionIds = array_map(static fn($i) => (int) $i['id'], $invoiceOptions);
                        if ($linkedInvoice && !in_array((int) $linkedInvoice['id'], $optionIds, true)): ?>
                        <option value="<?= (int) $linkedInvoice['id'] ?>"><?= e($linkedInvoice['invoice_number'] . ' · ' . str_replace('_', ' ', $linkedInvoice['status'])) ?></option>
                        <?php endif; ?>
                        <?php foreach ($invoiceOptions as $inv): ?>
                        <option value="<?= (int) $inv['id'] ?>"><?= e($invoiceOptionLabel($inv, $canSeeMoney)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">
                        <?php if (!$claim['customer_id']): ?>
                        Save a customer on this claim first — the recovery invoice must belong to the claim's customer.
                        <?php elseif ($recoveryJe && $claim['invoice_id']): ?>
                        Locked: the recovery is already posted to the general ledger against this invoice.
                        <?php else: ?>
                        The customer's invoice that bills this damage. Linking it posts the recovery to the general ledger (a draft posts when it is sent).
                        <?php endif; ?>
                    </div>
                    <div class="field-error" data-error-for="invoice_id"></div>
                </div>

                <div style="display:flex;gap:12px;">
                    <button type="submit" class="btn btn-primary" :disabled="editSaving">
                        <span x-text="editSaving ? 'Saving…' : 'Save Changes'"></span>
                    </button>
                    <button type="button" class="btn btn-secondary"
                            @click="cancelEdit()">Cancel</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ── Photo gallery ─────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h2 class="card-title">Photos <span class="badge badge-neutral" x-text="photos.length"></span></h2>
        <?php if (can('maintenance', 'edit')): ?>
        <button class="btn btn-secondary btn-sm"
                @click="showUploadForm = !showUploadForm"
                x-text="showUploadForm ? 'Cancel Upload' : 'Add Photo'"></button>
        <?php endif; ?>
    </div>

    <!-- Upload form -->
    <?php if (can('maintenance', 'edit')): ?>
    <div x-show="showUploadForm" style="padding:16px;border-bottom:1px solid var(--border-color);display:none;">
        <template x-if="uploadError">
            <div class="alert alert-danger" style="margin-bottom:12px;" x-text="uploadError"></div>
        </template>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div class="form-group" style="flex:0 0 auto;">
                <label class="form-label">Photo Type</label>
                <select class="form-select form-select-sm" x-model="uploadPhotoType">
                    <option value="damage">Damage</option>
                    <option value="repair_before">Before Repair</option>
                    <option value="repair_after">After Repair</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group" style="flex:1;min-width:180px;">
                <label class="form-label">Caption (optional)</label>
                <input type="text" class="form-input form-input-sm" x-model="uploadCaption" maxlength="255">
            </div>
            <div class="form-group" style="flex:0 0 auto;">
                <label class="form-label">File (JPG, PNG, HEIC — max 10 MB)</label>
                <input type="file"
                       class="form-input form-input-sm"
                       accept="image/jpeg,image/png,image/heic"
                       @change="uploadFile = $event.target.files[0]">
            </div>
            <button class="btn btn-primary btn-sm"
                    :disabled="!uploadFile || uploading"
                    @click="uploadPhoto()">
                <span x-text="uploading ? 'Uploading…' : 'Upload'"></span>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Photo grid -->
    <div class="card-body">
        <template x-if="photos.length === 0">
            <div class="empty-state">
                <p class="empty-state-title">No photos</p>
                <p class="empty-state-text">Upload photos to document the damage.</p>
            </div>
        </template>

        <div x-show="photos.length > 0"
             style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;">
            <template x-for="photo in photos" :key="photo.id">
                <div style="border:1px solid var(--border-color);border-radius:8px;overflow:hidden;">
                    <a :href="photo.url" target="_blank" rel="noopener">
                        <img :src="photo.url"
                             :alt="photo.caption ?? 'Damage photo'"
                             style="width:100%;height:140px;object-fit:cover;display:block;">
                    </a>
                    <div style="padding:8px;">
                        <span class="badge badge-neutral"
                              style="font-size:0.7rem;"
                              x-text="photoTypeLabel(photo.photo_type)"></span>
                        <template x-if="photo.caption">
                            <p style="font-size:0.75rem;margin:4px 0 0;color:var(--text-secondary);"
                               x-text="photo.caption"></p>
                        </template>
                        <?php if (can('maintenance', 'edit')): ?>
                        <button class="btn btn-danger btn-sm"
                                style="width:100%;margin-top:8px;font-size:0.75rem;"
                                @click="deletePhoto(photo.id)">
                            Delete
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<!-- ── Activity Log ───────────────────────────────────────────── -->
<div class="card">
    <div class="card-header"><h2 class="card-title">Activity</h2></div>
    <div class="card-body">
        <?php $activityEntityType = 'damage_claim'; $activityEntityId = $id; ?>
        <?php require_once FF_ROOT . '/includes/partials/activity-log.php'; ?>
    </div>
</div>

</div><!-- /rec-main -->

<?php
// ── RAIL (S-RECORD-REDESIGN) — the claim at a glance ───────────────────────
$R = \FleetForge\Ui\RecordUi::class;
$railD = [];

// 1. Needs attention. Amounts only for money roles.
$alertsD = [];
if (!$isClosed && $hasLiable && !$claim['invoice_id'] && $claim['status'] !== 'invoiced') {
    $alertsD[] = ['warning', 'The customer is liable' . ($canSeeMoney ? ' for <b>' . e(format_currency($liable)) . '</b>' : '') . ' but no recovery invoice is linked yet.'];
}
if ($claim['status'] === 'invoiced' && !$claim['invoice_id']) {
    $alertsD[] = ['danger', 'Marked invoiced with no recovery invoice linked — <b>Edit</b> the claim and pick it so the recovery posts to the ledger.'];
}
if ($recInv && $recStatus === 'draft') {
    $alertsD[] = ['info', 'Recovery invoice <a href="' . e(base_url('invoices/show')) . '?id=' . (int) $recInv['id'] . '">' . e($recInv['invoice_number']) . '</a> is still a draft — the recovery posts when it is sent.'];
}
if ($recOverdue) {
    $alertsD[] = ['danger', 'Recovery invoice <a href="' . e(base_url('invoices/show')) . '?id=' . (int) $recInv['id'] . '">' . e($recInv['invoice_number']) . '</a> is overdue'
        . ($canSeeMoney ? ' — ' . e(format_currency($recInv['balance_due'], $recCur)) . ' owing' : '') . '.'];
}
if ($recPaid && !$isClosed) {
    $alertsD[] = ['success', 'The recovery invoice is paid — mark the claim <b>Resolved</b>.'];
}
if (!$claim['customer_id'] && !$isClosed) {
    $alertsD[] = ['warning', 'Not linked to a customer account' . ($claim['customer_name'] ? ' (typed as “' . e($claim['customer_name']) . '”)' : '') . ' — a recovery invoice can\'t be linked until it is.'];
}
if (!$photoData && !$isClosed) {
    $alertsD[] = ['info', 'No photos yet — add damage photos as evidence.'];
}
if ($claim['estimated_repair_cost'] === null && !in_array($claim['status'], ['reported', 'resolved', 'written_off'], true)) {
    $alertsD[] = ['info', 'No repair estimate recorded.'];
}
if ($claim['status'] === 'repair_ordered' && !$claim['work_order_id']) {
    $alertsD[] = ['info', 'Repair ordered but no work order is linked.'];
}
$railD[] = $R::card('Needs attention', $R::alerts($alertsD, $isClosed ? 'Closed — nothing needs attention.' : 'All clear — nothing needs attention.'), ['icon' => 'exclamation-triangle']);

// 2. Recovery — the invoice that bills the damage back to the customer.
if ($recInv) {
    $recBody = '';
    if ($canSeeMoney) {
        $recBody .= $R::meter('Paid', e(round($recPaidPct)) . '%', $recPaidPct, $recPaidPct >= 99.99 ? 'ok' : ($recOverdue ? 'danger' : 'info'),
            e(format_currency($recInv['amount_paid'], $recCur)) . ' of ' . e(format_currency($recInv['total_amount'], $recCur)) . ' ' . e($recInv['currency'] ?? ''));
    }
    $recBody .= $R::kv([
        ['Invoice', '<a href="' . e(base_url('invoices/show')) . '?id=' . (int) $recInv['id'] . '">' . e($recInv['invoice_number']) . '</a>'],
        ['Status', '<span class="badge badge-no-dot ' . ($recPaid ? 'badge-success' : ($recOverdue ? 'badge-danger' : 'badge-neutral')) . '">' . e(ucwords(str_replace('_', ' ', (string) $recStatus))) . '</span>'],
        ['Balance', $canSeeMoney && !$recPaid ? e(format_currency($recInv['balance_due'], $recCur)) : null, 'mono'],
        ['Due', !empty($recInv['due_date']) && !$recPaid ? e(format_date($recInv['due_date'])) : null],
        ['Liable', $canSeeMoney && $hasLiable ? e(format_currency($liable)) : null, 'mono'],
        ['Ledger', $canSeeMoney ? ($recoveryJe ? 'Posted · ' . e($recoveryJe['entry_number']) : 'Not posted yet') : null],
    ]);
    $railD[] = $R::card('Recovery', $recBody, ['icon' => 'banknotes', 'class' => 'rec-card--accent']);
} elseif (!$isClosed && ($hasLiable || $claim['status'] === 'invoiced')) {
    $railD[] = $R::card('Recovery', '<p class="text-secondary" style="margin:0;font-size:12.5px;line-height:1.5;">No recovery invoice linked. '
        . ($claim['customer_id'] ? 'Create and send the invoice from the customer\'s account, then link it with <b>Edit</b> (or <b>Change Status → Invoiced</b> once the repair is ordered).' : 'Link a customer first (Edit → Customer).')
        . '</p>' . ($claim['customer_id'] && can('invoices', 'view') ? '<div style="margin-top:10px;">' . $R::links([['Customer\'s invoices', base_url('invoices') . '?customer_id=' . (int) $claim['customer_id'], 'document-duplicate']]) . '</div>' : ''),
        ['icon' => 'banknotes', 'class' => 'rec-card--accent']);
}

// 3. Unit.
if ($claim['equipment_unit_id']) {
    $railD[] = $R::card('Unit', $R::entity(
        'Unit ' . (string) ($claim['unit_number'] ?? $claim['equipment_unit_id']),
        base_url('equipment/show') . '?id=' . (int) $claim['equipment_unit_id'],
        e($unitDesc !== '' ? $unitDesc : 'Equipment unit'),
        '',
        'truck'
    ), ['icon' => 'truck', 'link' => ['Claims', base_url('equipment/show') . '?id=' . (int) $claim['equipment_unit_id'] . '#damage_claims']]);
}

// 4. Customer (+ the lease the damage happened on).
if ($customerDisplay || $claim['lease_id']) {
    $cBody = '';
    if ($customerDisplay) {
        $cBody .= $R::entity(
            (string) $customerDisplay,
            $claim['customer_id'] ? base_url('customers/show') . '?id=' . (int) $claim['customer_id'] : '',
            $claim['customer_id'] ? 'Customer' : 'Not linked to an account',
            \FleetForge\Ui\ModuleHero::initials((string) $customerDisplay)
        );
    }
    if ($claim['lease_id']) {
        $cBody .= ($cBody !== '' ? '<div style="margin-top:10px;">' : '<div>') . $R::entity(
            'Lease ' . (string) ($claim['contract_number'] ?? $claim['lease_id']),
            base_url('leases/show') . '?id=' . (int) $claim['lease_id'],
            'The lease the damage happened on',
            '',
            'calendar-days'
        ) . '</div>';
    }
    $railD[] = $R::card('Customer', $cBody, ['icon' => 'user-group']);
}

// 5. Related records.
$relD = [];
if ($claimInspection) {
    $relD[] = ['Inspection ' . ($claimInspection['inspection_number'] ?? ('#' . $claimInspection['id'])), base_url('inspections/show') . '?id=' . (int) $claimInspection['id'], 'clipboard-document-check', format_date($claimInspection['inspection_date'])];
} elseif ($claim['inspection_id']) {
    $relD[] = ['Inspection #' . (int) $claim['inspection_id'], base_url('inspections/show') . '?id=' . (int) $claim['inspection_id'], 'clipboard-document-check'];
}
if ($claim['work_order_id']) {
    $relD[] = ['Work order ' . ($claimWorkOrder['work_order_number'] ?? ('#' . $claim['work_order_id'])), base_url('maintenance_work_orders/show') . '?id=' . (int) $claim['work_order_id'], 'wrench-screwdriver',
        $claimWorkOrder ? ucwords(str_replace('_', ' ', (string) $claimWorkOrder['status'])) : ''];
}
if ($claim['vendor_id']) {
    $relD[] = [(string) $claim['vendor_name'], base_url('vendors/show') . '?id=' . (int) $claim['vendor_id'], 'building-storefront', 'Repair shop'];
}
if ($relD) {
    $railD[] = $R::card('Related', $R::links($relD), ['icon' => 'document-duplicate']);
}

// 6. Summary.
$railD[] = $R::card('Summary', $R::kv([
    ['Severity', '<span class="' . $severityBadgeClass . '">' . e($severityLabel) . '</span>'],
    ['Location', $claim['damage_location'] ? e($claim['damage_location']) : null],
    ['Reported', e(format_datetime($claim['created_at'])) . ($claim['reported_by_name'] ? ' · ' . e($claim['reported_by_name']) : '')],
    ['Updated', e(format_datetime($claim['updated_at']))],
]), ['icon' => 'document-text']);
?>
<aside class="rec-rail" aria-label="Damage claim at a glance">
    <?= implode("\n    ", $railD) ?>
</aside>
</div><!-- /rec-layout -->
<!-- ── Delete confirm modal ───────────────────────────────────────────────── -->
<template x-if="confirmDelete">
    <div class="modal-backdrop" style="z-index:1000;" @click.self="confirmDelete = false">
        <div class="modal modal-sm">
            <div class="modal-header">
                <h3 class="modal-title">Delete Claim</h3>
            </div>
            <div class="modal-body">
                <p>Permanently delete claim <strong><?= e($claim['claim_number']) ?></strong>?
                This cannot be undone.</p>
                <template x-if="deleteError">
                    <div class="alert alert-danger" style="margin-top:12px;" x-text="deleteError"></div>
                </template>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" @click="confirmDelete = false">Cancel</button>
                <button class="btn btn-danger"
                        :disabled="deleting"
                        @click="deleteClaim()">
                    <span x-text="deleting ? 'Deleting…' : 'Delete'"></span>
                </button>
            </div>
        </div>
    </div>
</template>

</div><!-- /x-data -->

<script>
function damageClaimShow() {
    return {
        // State
        showStatusPanel: false,
        showEditForm:    false,
        showUploadForm:  false,
        confirmDelete:   false,

        // Status change
        newStatus:    '',
        statusInvoiceId: <?= json_encode($claim['invoice_id'] ? (string) $claim['invoice_id'] : '') ?>,
        statusSaving: false,
        statusError:  '',

        // Edit form
        editForm: {
            severity:               <?= json_encode($claim['severity']) ?>,
            damage_location:        <?= json_encode($claim['damage_location'] ?? '') ?>,
            description:            <?= json_encode($claim['description']) ?>,
            customer_id:            <?= json_encode($claim['customer_id'] ? (string)$claim['customer_id'] : '') ?>,
            customer_name:          <?= json_encode($claim['customer_name'] ?? '') ?>,
            notes:                  <?= json_encode($claim['notes'] ?? '') ?>,
            resolution_notes:       <?= json_encode($claim['resolution_notes'] ?? '') ?>,
            estimated_repair_cost:  <?= json_encode($claim['estimated_repair_cost'] ?? '') ?>,
            actual_repair_cost:     <?= json_encode($claim['actual_repair_cost'] ?? '') ?>,
            customer_liable_amount: <?= json_encode($claim['customer_liable_amount'] ?? '') ?>,
            insurance_claim_amount: <?= json_encode($claim['insurance_claim_amount'] ?? '') ?>,
            vendor_id:              <?= json_encode($claim['vendor_id'] ? (string)$claim['vendor_id'] : '') ?>,
            invoice_id:             <?= json_encode($claim['invoice_id'] ? (string)$claim['invoice_id'] : '') ?>,
        },
        editSaving: false,
        staleError: false,

        // Photo upload
        uploadFile:      null,
        uploadPhotoType: 'damage',
        uploadCaption:   '',
        uploading:       false,
        uploadError:     '',

        // Photos (pre-seeded server-side)
        photos: <?= json_encode($photoData) ?>,

        // Delete
        deleting:     false,
        deleteError:  '',

        // Optimistic lock — updated_at needed for all updates (D19)
        updatedAt: <?= json_encode($claim['updated_at']) ?>,

        init() {
            // Nothing async on load — photos pre-seeded from PHP
        },

        // ── Status change ────────────────────────────────────────────────
        changeStatus() {
            if (!this.newStatus) return;
            this.statusSaving = true;
            this.statusError  = '';

            const payload = {
                id:         <?= (int)$claim['id'] ?>,
                updated_at: this.updatedAt,
                status:     this.newStatus,
            };
            // Bug #8: the recovery invoice travels with the 'invoiced' transition.
            if (this.newStatus === 'invoiced') {
                payload.invoice_id = this.statusInvoiceId ? parseInt(this.statusInvoiceId) : null;
            }

            FF_Api.post('<?= base_url('api/v1/damage_claims/update.php') ?>', payload).then(r => {
                if (!r.success) {
                    const f = (r.error && r.error.fields) || {};
                    this.statusError  = f.invoice_id || f.status || (r.error && r.error.message) || 'Failed to change status.';
                    this.statusSaving = false;
                } else {
                    window.location.reload();
                }
            }).catch(() => {
                this.statusError  = 'Network error. Please try again.';
                this.statusSaving = false;
            });
        },

        cancelEdit() {
            this.showEditForm = false;
            const form = document.getElementById('dmg-edit-form');
            if (form) FF_Validate.clear(form);
        },

        validateEdit(form) {
            FF_Validate.clear(form);
            let ok = true;

            if (!this.editForm.description || !this.editForm.description.trim()) {
                FF_Validate.field(form, 'description', 'Description is required.');
                ok = false;
            }
            if (!this.editForm.severity) {
                FF_Validate.field(form, 'severity', 'Please select a severity.');
                ok = false;
            }

            const moneyFields = {
                estimated_repair_cost:  'Estimated repair cost',
                actual_repair_cost:     'Actual repair cost',
                customer_liable_amount: 'Customer liable amount',
                insurance_claim_amount: 'Insurance claim amount',
            };
            for (const [field, label] of Object.entries(moneyFields)) {
                const raw = this.editForm[field];
                if (raw === '' || raw === null || raw === undefined) continue;
                const n = parseFloat(raw);
                if (isNaN(n) || n < 0) {
                    FF_Validate.field(form, field, `${label} cannot be negative.`);
                    ok = false;
                }
            }

            if (!ok) FF_Validate.scrollToFirst(form);
            return ok;
        },

        // ── Edit form save ───────────────────────────────────────────────
        saveEdit() {
            const form = document.getElementById('dmg-edit-form');
            if (!form) return;
            if (!this.validateEdit(form)) return;

            this.editSaving = true;

            const payload = {
                id:         <?= (int)$claim['id'] ?>,
                updated_at: this.updatedAt,
            };

            // Only send non-empty changed fields
            const fields = ['severity','damage_location','description','notes',
                            'resolution_notes','estimated_repair_cost',
                            'actual_repair_cost','customer_liable_amount','insurance_claim_amount'];
            fields.forEach(f => {
                payload[f] = this.editForm[f] !== '' ? this.editForm[f] : null;
            });
            // Integer FK fields + free-text customer name
            payload.customer_id   = this.editForm.customer_id   ? parseInt(this.editForm.customer_id)   : null;
            payload.customer_name = this.editForm.customer_name ? this.editForm.customer_name.trim() || null : null;
            payload.vendor_id     = this.editForm.vendor_id     ? parseInt(this.editForm.vendor_id)     : null;
<?php if ($claim['customer_id'] && !($recoveryJe && $claim['invoice_id'])): ?>
            // Bug #8: only sent when the picker is editable, so a locked / customer-less
            // claim never re-submits (or clears) its link by accident.
            payload.invoice_id    = this.editForm.invoice_id    ? parseInt(this.editForm.invoice_id)    : null;
<?php endif; ?>

            FF_Api.post('<?= base_url('api/v1/damage_claims/update.php') ?>', payload)
                .then(r => {
                    if (!r.success) {
                        if (r.error && r.error.code === 'STALE_DATA') {
                            this.staleError = true;
                        } else if (r.error && r.error.code === 'VALIDATION_ERROR') {
                            FF_Validate.applyApi(form, r.error);
                        } else {
                            FF_Validate.banner(form, (r.error && r.error.message) || 'Save failed.');
                        }
                        this.editSaving = false;
                        return;
                    }
                    window.location.reload();
                })
                .catch(() => {
                    FF_Validate.banner(form, 'Network error. Please try again.');
                    this.editSaving = false;
                });
        },

        // ── Photo upload ─────────────────────────────────────────────────
        uploadPhoto() {
            if (!this.uploadFile) return;
            this.uploading    = true;
            this.uploadError  = '';

            const fd = new FormData();
            fd.append('claim_id',   <?= (int)$claim['id'] ?>);
            fd.append('photo',      this.uploadFile);
            fd.append('photo_type', this.uploadPhotoType);
            fd.append('caption',    this.uploadCaption);

            // Use native fetch for multipart (FF_Api.post sends JSON)
            fetch('<?= base_url('api/v1/damage_claims/upload_photo.php') ?>', {
                method:      'POST',
                credentials: 'same-origin',
                body:        fd,
            })
            .then(r => r.json())
            .then(resp => {
                if (!resp.success) throw new Error(resp.error?.message ?? 'Upload failed.');
                this.photos.push(resp.data);
                this.uploadFile      = null;
                this.uploadCaption   = '';
                this.showUploadForm  = false;
                // Reset file input
                document.querySelector('input[type="file"]').value = '';
            })
            .catch(err => {
                this.uploadError = err.message ?? 'Upload failed.';
            })
            .finally(() => { this.uploading = false; });
        },

        // ── Delete photo ─────────────────────────────────────────────────
        // [UI-AUDIT-1:M13] async for FF_Confirm.ask().
        async deletePhoto(photoId) {
            if (!(await FF_Confirm.ask('Delete this photo?'))) return;

            FF_Api.post('<?= base_url('api/v1/damage_claims/delete_photo.php') ?>', {
                photo_id: photoId,
                claim_id: <?= (int)$claim['id'] ?>,
            }).then(d => {
                if (d && d.error) {
                    FF_Toast.error(d.error?.message ?? d.data?.message ?? 'Failed to delete photo.');
                } else {
                    this.photos = this.photos.filter(p => p.id !== photoId);
                }
            }).catch(err => {
                FF_Toast.error(err?.message ?? 'Failed to delete photo.');
            });
        },

        // ── Delete claim ─────────────────────────────────────────────────
        deleteClaim() {
            this.deleting    = true;
            this.deleteError = '';

            FF_Api.post('<?= base_url('api/v1/damage_claims/delete.php') ?>', {
                id: <?= (int)$claim['id'] ?>,
            }).then(d => {
                if (d && d.error) {
                    this.deleteError = d.error?.message ?? d.data?.message ?? 'Delete failed.';
                    this.deleting    = false;
                } else {
                    window.location = '<?= base_url('damage_claims') ?>';
                }
            }).catch(err => {
                this.deleteError = err?.message ?? 'Delete failed.';
                this.deleting    = false;
            });
        },

        // ── Helpers ──────────────────────────────────────────────────────
        photoTypeLabel(t) {
            return { damage:'Damage', repair_before:'Before Repair', repair_after:'After Repair', other:'Other' }[t] ?? t;
        },
    };
}
</script>

<!-- ── Page styles (S-RECORD-REDESIGN) — tokens only ─────────────────────── -->
<style>
/* Hero fact links (unit, customer, lease) keep the chip colour. */
.ff-hero-fact a { color: inherit; text-decoration: none; }
.ff-hero-fact a:hover { text-decoration: underline; }
/* The status panel opens under the strip when the header's Change Status is
   clicked — tint it so it reads as the pending action. */
#dc-status-panel {
    border-color: color-mix(in srgb, var(--acc, var(--color-primary)) 40%, var(--border-color));
    scroll-margin-top: calc(var(--topbar-height, 60px) + 16px);
}
#dc-details { scroll-margin-top: calc(var(--topbar-height, 60px) + 16px); }
</style>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
