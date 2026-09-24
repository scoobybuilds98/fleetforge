<?php
declare(strict_types=1);

/**
 * app/admin/vendors/show.php
 *
 * Vendor profile page (S-RECORD-REDESIGN layout).
 *
 *   Header  — entity hero (lib/Ui/ModuleHero.php): name + type / preferred /
 *             QuickBooks badges, contact facts, New Work Order + Edit visible,
 *             AI Analysis + Delete in the More menu (RecordUi::more).
 *   Strip   — key numbers: spend (last 12 months, lifetime underneath),
 *             open work orders, average job cost, last job, and — for AP
 *             roles — unpaid bills. Money segments only for
 *             can_view_financials(); other roles get completed jobs + units
 *             on rent instead.
 *   Main    — Work Orders (Alpine, paginated + filtered — the page's primary
 *             content), Vendor Details (view + inline edit), Units Serviced,
 *             Serviced Units On Rent, Activity.
 *   Rail    — Spend (money roles), Needs attention (waiting parts, late /
 *             urgent jobs, unpaid / overdue / draft bills, unbilled work,
 *             missing contact info), Contact, Unpaid bills (AP roles; each bill
 *             links to its page — the bills list has no vendor deep-link),
 *             Shortcuts.
 *
 * Edit mode: plain onclick toggle + raw fetch() to api/v1/vendors/update.php.
 * D19 optimistic lock: updated_at submitted with every save.
 * Delete: soft-delete via api/v1/vendors/delete.php (blocked if active WOs exist).
 *
 * Total Spent = vendors.total_spent, maintained by FleetForge\Accounting\VendorSpend
 * (approved AP bills + completed work orders no approved bill covers — bug #7).
 * The 12-month figure applies the SAME rule with a date window (bill_date /
 * completed_date), so the two numbers are comparable.
 * Strip segments deep-link to the work-order list with vendor_id / status params
 * that the list honours (bug #25); 'active' is the open+in_progress+waiting_parts
 * roll-up. The work-type filter offers exactly the maintenance_work_orders.work_type ENUM.
 *
 * Money: dispatchers hold maintenance:view, so this page is reachable without
 * payments:view — every dollar figure on it (spend, average job, bills) is
 * behind can_view_financials(); bills additionally need accounts_payable:view.
 *
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css / records.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/vendors/show.php, api/v1/vendors/update.php, api/v1/vendors/delete.php,
 *           lib/Ui/ModuleHero.php, lib/Ui/RecordUi.php, lib/Accounting/VendorSpend.php
 * @decisions D5/D7/D19/D30/D32
 * @session  S014, S-RECORD-REDESIGN
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('maintenance', 'view');

// ── Resolve vendor ───────────────────────────────────────────────────────────
$vendorId = clean_int($_GET['id'] ?? null);
if (!$vendorId) {
    header('Location: ' . base_url('vendors'));
    exit;
}

$vendor = db_row(
    "SELECT v.*, u.name AS created_by_name
     FROM vendors v
     LEFT JOIN users u ON u.id = v.created_by AND u.deleted_at IS NULL
     WHERE v.id = ? AND v.deleted_at IS NULL",
    [$vendorId]
);

if (!$vendor) {
    header('Location: ' . base_url('vendors') . '?error=not_found');
    exit;
}

// Decode JSON specializations
$specializations = $vendor['specializations']
    ? (json_decode($vendor['specializations'], true) ?: [])
    : [];

// ── QBO mapping (S-QBO-7) ─────────────────────────────────────
// Drives the QuickBooks badge in the page header. Only renders when
// the connection is established (no point teasing the feature pre-
// setup). Separate query to keep the vendor SELECT untouched.
$qboMapping = null;
if ((string) settings_get('quickbooks.connection_status', 'disconnected') === 'connected') {
    $qboMapping = db_row(
        "SELECT id, qbo_vendor_id, mapping_status, last_synced_at, last_push_at
           FROM acc_qbo_vendor_map
          WHERE ff_vendor_id = ?",
        [$vendorId]
    );
}

// ── S-RECORD-REDESIGN: strip + rail data ─────────────────────────────────────
// Money visibility: the page gate is maintenance:view (dispatchers have it),
// so dollar figures ride on the app-wide predicate. Bills also need AP view.
$canSeeMoney = can_view_financials();
$canSeeBills = $canSeeMoney && can('accounts_payable', 'view');
// Business dates (scheduled / completed / due) compare against the
// company-local day, never SQL CURDATE() (the UTC day).
$today   = ff_today();
$yearAgo = date('Y-m-d', strtotime($today . ' -12 months'));

// One pass over the vendor's work orders for every count the page shows.
$woStats = db_row(
    "SELECT COUNT(*) AS total_cnt,
            SUM(CASE WHEN status IN ('open','in_progress','waiting_parts') THEN 1 ELSE 0 END) AS open_cnt,
            SUM(CASE WHEN status = 'waiting_parts' THEN 1 ELSE 0 END) AS parts_cnt,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS progress_cnt,
            SUM(CASE WHEN status IN ('open','in_progress','waiting_parts')
                      AND scheduled_date IS NOT NULL AND scheduled_date < ? THEN 1 ELSE 0 END) AS late_cnt,
            SUM(CASE WHEN status IN ('open','in_progress','waiting_parts')
                      AND priority IN ('high','emergency') THEN 1 ELSE 0 END) AS urgent_cnt,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS done_cnt,
            SUM(CASE WHEN status = 'completed' AND total_cost > 0 THEN 1 ELSE 0 END) AS costed_cnt,
            COALESCE(SUM(CASE WHEN status = 'completed' AND total_cost > 0 THEN total_cost ELSE 0 END), 0) AS costed_sum,
            COUNT(DISTINCT equipment_unit_id) AS unit_cnt
       FROM maintenance_work_orders
      WHERE vendor_id = ? AND deleted_at IS NULL",
    [$today, $vendorId]
) ?? [];
$woTotal     = (int) ($woStats['total_cnt'] ?? 0);
$woOpen      = (int) ($woStats['open_cnt'] ?? 0);
$woCompleted = (int) ($woStats['done_cnt'] ?? 0);
$woParts     = (int) ($woStats['parts_cnt'] ?? 0);
$woProgress  = (int) ($woStats['progress_cnt'] ?? 0);
$woLate      = (int) ($woStats['late_cnt'] ?? 0);
$woUrgent    = (int) ($woStats['urgent_cnt'] ?? 0);
$woCosted    = (int) ($woStats['costed_cnt'] ?? 0);
// Average of completed jobs that carry a cost ($0 warranty / no-charge jobs
// would drag it toward zero and hide what a real job costs).
$avgJob = $woCosted > 0 ? bcdiv((string) ($woStats['costed_sum'] ?? '0'), (string) $woCosted, 2) : null;

// The most recent finished job (strip + rail), else the latest requested one.
$lastJob = db_row(
    "SELECT w.id, w.work_order_number, w.status, w.completed_date, w.requested_date,
            COALESCE(eu.unit_number, '') AS unit_number
       FROM maintenance_work_orders w
       LEFT JOIN equipment_units eu ON eu.id = w.equipment_unit_id
      WHERE w.vendor_id = ? AND w.deleted_at IS NULL
      ORDER BY (w.status = 'completed') DESC, w.completed_date DESC, w.requested_date DESC, w.id DESC
      LIMIT 1",
    [$vendorId]
);
$lastJobDone = $lastJob !== null && $lastJob['status'] === 'completed';
$lastJobDate = $lastJob ? (string) (($lastJobDone ? $lastJob['completed_date'] : null) ?: $lastJob['requested_date']) : '';
$lastJobDays = $lastJobDone && $lastJobDate !== ''
    ? (int) round((strtotime($today) - strtotime($lastJobDate)) / 86400)
    : null;

// Spend in the last 12 months — VendorSpend's rule with a date window:
// counted bills by bill_date + completed work orders (by completed_date) that
// no counted bill from this vendor covers. SUMs are exact DECIMALs; combined
// with bcmath.
$spend12 = null;
if ($canSeeMoney) {
    $counted = \FleetForge\Accounting\VendorSpend::COUNTED_BILL_STATUSES;
    $ph      = implode(', ', array_fill(0, count($counted), '?'));
    $s12b = db_row(
        "SELECT COALESCE(SUM(CASE WHEN b.currency <> 'CAD' AND b.exchange_rate_to_cad IS NOT NULL
                                       AND b.exchange_rate_to_cad > 0
                                  THEN ROUND(b.total_amount * b.exchange_rate_to_cad, 2)
                                  ELSE b.total_amount END), 0.00) AS t
           FROM acc_bills b
          WHERE b.vendor_id = ? AND b.status IN ({$ph}) AND b.bill_date >= ?",
        array_merge([$vendorId], $counted, [$yearAgo])
    );
    $s12w = db_row(
        "SELECT COALESCE(SUM(w.total_cost), 0.00) AS t
           FROM maintenance_work_orders w
          WHERE w.vendor_id = ? AND w.status = 'completed' AND w.deleted_at IS NULL
            AND w.completed_date >= ?
            AND NOT EXISTS (SELECT 1 FROM acc_bills b
                             WHERE b.work_order_id = w.id AND b.vendor_id = w.vendor_id
                               AND b.status IN ({$ph}))",
        array_merge([$vendorId, $yearAgo], $counted)
    );
    $spend12 = bcadd(bcadd((string) ($s12b['t'] ?? '0'), '0', 2), bcadd((string) ($s12w['t'] ?? '0'), '0', 2), 2);
}

// AP picture — unpaid / overdue / draft bills, per currency (a vendor can be
// billed in CAD and USD; balances are never summed across currencies).
$billsByCur   = [];
$billOpenCnt  = 0;
$billOverCnt  = 0;
$billDraftCnt = 0;
$billTotal    = '0';
$billPaid     = '0';
$openBills    = [];
$unbilledWos  = 0;
if ($canSeeBills) {
    foreach (db_select(
        "SELECT currency,
                SUM(CASE WHEN status IN ('approved','scheduled','partially_paid') AND balance_due > 0 THEN 1 ELSE 0 END) AS open_cnt,
                COALESCE(SUM(CASE WHEN status IN ('approved','scheduled','partially_paid') AND balance_due > 0 THEN balance_due ELSE 0 END), 0) AS open_amt,
                SUM(CASE WHEN status IN ('approved','scheduled','partially_paid') AND balance_due > 0 AND due_date < ? THEN 1 ELSE 0 END) AS over_cnt,
                COALESCE(SUM(CASE WHEN status IN ('approved','scheduled','partially_paid') AND balance_due > 0 AND due_date < ? THEN balance_due ELSE 0 END), 0) AS over_amt,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft_cnt,
                COALESCE(SUM(CASE WHEN status IN ('approved','scheduled','partially_paid','paid') THEN total_amount ELSE 0 END), 0) AS billed,
                COALESCE(SUM(CASE WHEN status IN ('approved','scheduled','partially_paid','paid') THEN amount_paid ELSE 0 END), 0) AS paid
           FROM acc_bills
          WHERE vendor_id = ?
          GROUP BY currency",
        [$today, $today, $vendorId]
    ) as $b) {
        $billsByCur[(string) $b['currency']] = $b;
        $billOpenCnt  += (int) $b['open_cnt'];
        $billOverCnt  += (int) $b['over_cnt'];
        $billDraftCnt += (int) $b['draft_cnt'];
        // The paid meter is a ratio, so mixing currencies only skews it
        // slightly; the amounts shown beside it stay per-currency.
        $billTotal = bcadd($billTotal, (string) $b['billed'], 2);
        $billPaid  = bcadd($billPaid, (string) $b['paid'], 2);
    }
    $openBills = db_select(
        "SELECT id, bill_number, vendor_bill_number, due_date, balance_due, currency, status
           FROM acc_bills
          WHERE vendor_id = ? AND status IN ('approved','scheduled','partially_paid') AND balance_due > 0
          ORDER BY due_date ASC, id ASC
          LIMIT 5",
        [$vendorId]
    );
    // Finished, chargeable work with no bill entered (draft counts as
    // entered; $0 warranty jobs expect no bill) — AP's "did we get invoiced
    // for this?" list.
    $unbilledWos = db_count(
        "SELECT COUNT(*) FROM maintenance_work_orders w
          WHERE w.vendor_id = ? AND w.status = 'completed' AND w.deleted_at IS NULL
            AND w.total_cost > 0
            AND NOT EXISTS (SELECT 1 FROM acc_bills b
                             WHERE b.work_order_id = w.id AND b.vendor_id = w.vendor_id AND b.status <> 'void')",
        [$vendorId]
    );
}

/** "CAD $1,200.00 · USD $300.00" — one amount per currency, never summed across. */
$fmtByCur = static function (array $byCur, string $key): string {
    $parts = [];
    foreach ($byCur as $cur => $row) {
        if (bccomp((string) $row[$key], '0', 2) > 0) {
            $parts[] = e(format_currency($row[$key])) . (count($byCur) > 1 ? ' ' . e($cur) : '');
        }
    }
    return $parts ? implode(' · ', $parts) : e(format_currency('0'));
};

// Equipment units this vendor has worked on (distinct, from work orders)
$unitHistory = db_select(
    "SELECT eu.id, eu.unit_number, eu.year, eb.label AS brand, et.model,
            COUNT(mwo.id) AS service_count,
            MAX(mwo.completed_date) AS last_service_date
     FROM maintenance_work_orders mwo
     JOIN equipment_units eu ON eu.id = mwo.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     LEFT JOIN equipment_brands eb ON eb.id = eu.brand_id
     WHERE mwo.vendor_id = ? AND mwo.deleted_at IS NULL
     GROUP BY eu.id, eu.unit_number, eu.year, eb.label, et.model
     ORDER BY last_service_date DESC, eu.unit_number ASC",
    [$vendorId]
);

// Active / pending leases on units this vendor has worked on
$leaseExposure = db_select(
    "SELECT l.id AS lease_id, l.contract_number, l.status,
            l.start_date, l.end_date,
            c.id AS customer_id, c.company_name,
            eu.id AS unit_id, eu.unit_number
     FROM leases l
     JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
     JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     WHERE eu.id IN (
         SELECT DISTINCT mwo.equipment_unit_id
         FROM maintenance_work_orders mwo
         WHERE mwo.vendor_id = ? AND mwo.deleted_at IS NULL AND mwo.equipment_unit_id IS NOT NULL
     )
     AND l.status IN ('active','pending')
     AND l.deleted_at IS NULL
     ORDER BY l.status ASC, l.end_date ASC",
    [$vendorId]
);

// WHY: Work orders section converted to Alpine.js (paginated + filtered) in S018-UX.
//      $woTotal already loaded above for the strip. No PHP pre-load needed.

// Vendor type label/badge maps — used in view mode
$typeLabels = [
    'maintenance' => 'Maintenance',
    'repair'      => 'Repair',
    'parts'       => 'Parts',
    'inspection'  => 'Inspection',
    'towing'      => 'Towing',
    'other'       => 'Other',
];
$typeBadges = [
    'maintenance' => 'badge-warning',
    'repair'      => 'badge-danger',
    'parts'       => 'badge-info',
    'inspection'  => 'badge-purple',
    'towing'      => 'badge-neutral',
    'other'       => 'badge-neutral',
];

$woListUrl = base_url('maintenance_work_orders') . '?vendor_id=' . (int) $vendorId;

$pageTitle = e($vendor['name']);
$helpModuleSlug = 'vendors';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header — entity hero (S-MODULE-CHROME / S-RECORD-REDESIGN).
     The badges and buttons are the page's own markup, captured with
     ob_start() and placed inside the hero unchanged.
     ============================================================ -->
<?php ob_start(); ?>
    <span class="badge <?= e($typeBadges[$vendor['vendor_type']] ?? 'badge-neutral') ?>">
        <?= e($typeLabels[$vendor['vendor_type']] ?? $vendor['vendor_type']) ?>
    </span>
    <?php if ($vendor['is_preferred']): ?>
    <span class="badge badge-success">Preferred</span>
    <?php endif; ?>
    <?php /* QBO mapping badge — S-QBO-7. Only shown when the
             connection is established AND a mapping row exists.
             Status drives the color: mapped=success (linked both
             sides), ff_only=warning (not pushed yet), qbo_only=
             info (QBO has but FF doesn't link — operator resolves
             via /quickbooks/vendors), ignored=neutral. */ ?>
    <?php if ($qboMapping !== null):
        $qm_status = (string) ($qboMapping['mapping_status'] ?? 'qbo_only');
        $qm_class  = match ($qm_status) {
            'mapped'   => 'badge-success',
            'ff_only'  => 'badge-warning',
            'qbo_only' => 'badge-info',
            'ignored'  => 'badge-neutral',
            default    => 'badge-neutral',
        };
        $qm_label  = match ($qm_status) {
            'mapped'   => 'QuickBooks: Synced',
            'ff_only'  => 'QuickBooks: Not synced',
            'qbo_only' => 'QuickBooks: Linked from QBO side',
            'ignored'  => 'QuickBooks: Excluded',
            default    => 'QuickBooks',
        };
        $qm_title = $qboMapping['qbo_vendor_id'] ? 'qbo#' . $qboMapping['qbo_vendor_id'] : '';
        if (!empty($qboMapping['last_synced_at'])) {
            // S-UTC-STAMPS: last_synced_at is UTC — show company-local time.
            $qm_title .= ($qm_title !== '' ? ' · ' : '') . 'last synced ' . format_datetime($qboMapping['last_synced_at'], 'Y-m-d H:i:s T');
        }
    ?>
    <a href="<?= base_url('quickbooks/vendors') ?>?q=<?= e(rawurlencode($vendor['name'])) ?>"
       class="badge <?= $qm_class ?>"
       title="<?= e($qm_title) ?>"
       style="text-decoration:none;">
        <?= e($qm_label) ?>
    </a>
    <?php endif; ?>
<?php $heroBadges = ob_get_clean(); ?>
<?php ob_start(); /* secondary actions → the header's More menu (S-RECORD-REDESIGN) */ ?>
        <?php if (function_exists('can') && can('ai', 'view') && (bool)settings_get('ai.enabled', false) && (settings_get('ai.anthropic_api_key') ?: env('AI_ANTHROPIC_API_KEY', ''))): ?>
        <button type="button" class="btn btn-secondary btn-sm no-print"
                onclick="aiPanel_vendor_<?= (int)$vendorId ?>_vendor_summary_open()"
                title="Open AI Vendor Summary">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:14px;height:14px;color:var(--color-primary);" aria-hidden="true">
                <path d="M12 2L14.5 9.5L22 12L14.5 14.5L12 22L9.5 14.5L2 12L9.5 9.5L12 2Z" fill="currentColor"/>
            </svg>
            AI Analysis
        </button>
        <?php endif; ?>
        <?php if (can('maintenance', 'delete')): ?>
        <button class="btn btn-danger btn-sm"
                onclick="document.getElementById('vendor-delete-modal').style.display='flex'">Delete</button>
        <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
        <?= help_button('vendors') ?>
        <?php if (can('maintenance', 'edit')): ?>
        <button id="btn-edit" class="btn btn-secondary btn-sm"
                onclick="showEdit()">Edit</button>
        <?php endif; ?>
        <?php if (can('maintenance', 'create')): ?>
        <a href="<?= base_url('maintenance_work_orders/create') ?>?vendor_id=<?= $vendorId ?>"
           class="btn btn-primary btn-sm">+ New Work Order</a>
        <?php endif; ?>
        <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?php
$heroFacts = [];
if (!empty($vendor['contact_name'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('users') . e($vendor['contact_name']);
}
if (!empty($vendor['phone'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('phone') . e($vendor['phone']);
}
$heroPlace = trim(implode(', ', array_filter([(string) ($vendor['city'] ?? ''), (string) ($vendor['state'] ?? '')])));
if ($heroPlace !== '') {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('map-pin') . e($heroPlace);
}
if ($vendor['rating']) {
    $heroFacts[] = '<span style="color:var(--color-warning);">' . str_repeat('★', (int) $vendor['rating']) . '</span>' . e((string) $vendor['rating']) . '/5';
}
if (!empty($specializations)) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('wrench-screwdriver') . e(implode(', ', array_slice($specializations, 0, 3)) . (count($specializations) > 3 ? ' +' . (count($specializations) - 3) : ''));
}
?>
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => 'info',
    'icon'       => 'wrench-screwdriver',
    'avatar'     => \FleetForge\Ui\ModuleHero::initials((string) $vendor['name']),
    'mark'       => \FleetForge\Ui\ModuleHero::initials((string) $vendor['name']),
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Vendors', base_url('vendors')], [(string) $vendor['name'], null]],
    'eyebrow'    => 'Vendor',
    'title_html' => e($vendor['name']) . $heroBadges,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<!-- ============================================================
     KEY NUMBERS — the summary strip (S-RECORD-REDESIGN). Segments
     deep-link to the work-order list filtered to this vendor (TILES-2).
     "Total work orders" / "Completed" counters became spend, average
     job cost and last job — the numbers a buyer acts on.
     ============================================================ -->
<div class="stat-grid ff-stats">

    <?php if ($canSeeMoney): ?>
    <a class="stat-card stat-card--green"
       href="<?= e($woListUrl) ?>&status=completed"
       title="Approved bills + completed work orders no bill covers, last 12 months">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Spent · 12 months</div>
        <div class="stat-value currency"><?= e(format_currency($spend12)) ?></div>
        <div class="stat-delta">lifetime <?= e(format_currency($vendor['total_spent'])) ?></div>
    </a>
    <?php endif; ?>

    <a class="stat-card <?= $woOpen > 0 ? 'stat-card--amber' : 'stat-card--blue' ?>"
       href="<?= e($woListUrl) ?>&status=active"
       title="Open / in-progress / waiting-parts work orders">
        <span class="stat-icon <?= $woOpen > 0 ? 'stat-icon--amber' : 'stat-icon--blue' ?>"><svg><use href="#icon-wrench"/></svg></span>
        <div class="stat-label">Open work orders</div>
        <div class="stat-value font-mono"><?= $woOpen ?></div>
        <div class="stat-delta"><?= $woParts > 0 ? $woParts . ' waiting on parts' : ($woProgress > 0 ? $woProgress . ' in progress' : ($woOpen > 0 ? 'not started' : 'nothing open')) ?></div>
    </a>

    <?php if ($canSeeMoney): ?>
    <a class="stat-card stat-card--purple"
       href="<?= e($woListUrl) ?>&status=completed"
       title="Average cost of a completed, costed work order">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-chart-bar"/></svg></span>
        <div class="stat-label">Avg job cost</div>
        <div class="stat-value currency"><?= $avgJob !== null ? e(format_currency($avgJob)) : '—' ?></div>
        <div class="stat-delta"><?= $woCosted > 0 ? 'over ' . $woCosted . ' job' . ($woCosted === 1 ? '' : 's') : 'no costed jobs yet' ?></div>
    </a>
    <?php else: ?>
    <a class="stat-card stat-card--green"
       href="<?= e($woListUrl) ?>&status=completed"
       title="Completed work orders for this vendor">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Completed jobs</div>
        <div class="stat-value font-mono"><?= $woCompleted ?></div>
        <div class="stat-delta"><?= (int) ($woStats['unit_cnt'] ?? 0) ?> unit<?= (int) ($woStats['unit_cnt'] ?? 0) === 1 ? '' : 's' ?> serviced</div>
    </a>
    <?php endif; ?>

    <?php if ($lastJob): ?>
    <a class="stat-card stat-card--teal"
       href="<?= base_url('maintenance_work_orders/show') ?>?id=<?= (int) $lastJob['id'] ?>"
       title="<?= e('Work order ' . $lastJob['work_order_number']) ?>">
        <span class="stat-icon stat-icon--teal"><svg><use href="#icon-clock"/></svg></span>
        <?php if ($lastJobDays !== null): ?>
        <div class="stat-label">Last job</div>
        <div class="stat-value"><?= e(format_date($lastJobDate)) ?></div>
        <div class="stat-delta"><?= $lastJobDays <= 0 ? 'today' : $lastJobDays . ' day' . ($lastJobDays === 1 ? '' : 's') . ' ago' ?><?= $lastJob['unit_number'] !== '' ? ' · unit ' . e($lastJob['unit_number']) : '' ?></div>
        <?php else: ?>
        <div class="stat-label">Last job requested</div>
        <div class="stat-value"><?= e(format_date($lastJobDate)) ?></div>
        <div class="stat-delta">none finished yet</div>
        <?php endif; ?>
    </a>
    <?php else: ?>
    <div class="stat-card stat-card--slate">
        <span class="stat-icon stat-icon--slate"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Last job</div>
        <div class="stat-value">—</div>
        <div class="stat-delta">no work orders yet</div>
    </div>
    <?php endif; ?>

    <?php if ($canSeeBills): ?>
    <a class="stat-card <?= $billOverCnt > 0 ? 'stat-card--red' : ($billOpenCnt > 0 ? 'stat-card--amber' : 'stat-card--green') ?>"
       href="#vendor-bills" title="Approved bills with a balance">
        <span class="stat-icon <?= $billOverCnt > 0 ? 'stat-icon--red' : ($billOpenCnt > 0 ? 'stat-icon--amber' : 'stat-icon--green') ?>"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Unpaid bills</div>
        <div class="stat-value currency"<?= $billOverCnt > 0 ? ' style="color:var(--color-danger);"' : '' ?>><?= $fmtByCur($billsByCur, 'open_amt') ?></div>
        <div class="stat-delta"><?= $billOpenCnt > 0 ? $billOpenCnt . ' bill' . ($billOpenCnt === 1 ? '' : 's') . ($billOverCnt > 0 ? ' · ' . $billOverCnt . ' overdue' : '') : 'nothing owing' ?></div>
    </a>
    <?php elseif (!$canSeeMoney): ?>
    <a class="stat-card <?= $leaseExposure ? 'stat-card--amber' : 'stat-card--slate' ?>"
       href="#lease-exposure" title="Units this vendor serviced that are on rent now">
        <span class="stat-icon <?= $leaseExposure ? 'stat-icon--amber' : 'stat-icon--slate' ?>"><svg><use href="#icon-key"/></svg></span>
        <div class="stat-label">Serviced units on rent</div>
        <div class="stat-value font-mono"><?= count($leaseExposure) ?></div>
    </a>
    <?php endif; ?>

</div>

<div class="rec-layout">
<div class="rec-main">

<!-- ── Work Orders (Alpine — paginated + filtered) ────────────────────────── -->
<div class="card" x-data="FF_VendorWorkOrders()">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
        <h3 class="card-title">
            Work Orders
            <span class="badge badge-neutral"><?= e($woTotal) ?></span>
        </h3>
        <a href="<?= e($woListUrl) ?>" class="btn btn-ghost btn-sm">Open in Work Orders →</a>
    </div>

    <!-- Filter bar -->
    <div class="tab-filter-bar">
        <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                x-model="filters.status" @change="applyFilters()">
            <option value="">All Statuses</option>
            <option value="open">Open</option>
            <option value="in_progress">In Progress</option>
            <option value="waiting_parts">Waiting Parts</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
        </select>
        <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                x-model="filters.work_type" @change="applyFilters()">
            <option value="">All Types</option>
            <?php /* Bug #25: must mirror the maintenance_work_orders.work_type ENUM —
                     'preventive'/'emergency' never existed (emergency is a PRIORITY),
                     so picking them silently returned every work order. */ ?>
            <option value="scheduled_service">Scheduled Service</option>
            <option value="repair">Repair</option>
            <option value="inspection">Inspection</option>
            <option value="tire">Tire</option>
            <option value="electrical">Electrical</option>
            <option value="body_damage">Body Damage</option>
            <option value="breakdown">Breakdown</option>
            <option value="other">Other</option>
        </select>
        <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                x-model="filters.sort" @change="applyFilters()">
            <option value="created_at">Sort: Date Added</option>
            <option value="requested_date">Sort: Requested Date</option>
            <option value="total_cost">Sort: Total Cost</option>
        </select>
        <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                x-model="filters.dir" @change="applyFilters()">
            <option value="DESC">Newest First</option>
            <option value="ASC">Oldest First</option>
        </select>
    </div>

    <!-- Loading -->
    <div x-show="loading && items.length === 0" class="card-body" style="text-align:center;padding:32px;">
        <span class="text-secondary">Loading work orders…</span>
    </div>

    <!-- Empty state -->
    <div x-show="loaded && !loading && items.length === 0" class="card-body">
        <div class="empty-state">
            <p class="empty-state-title">No work orders found</p>
            <p class="empty-state-text"><?= $woTotal > 0 ? 'No work orders match the current filters.' : 'This vendor has no work orders yet.' ?></p>
        </div>
    </div>

    <!-- Table + footer -->
    <div x-show="items.length > 0">
        <div class="tab-table-container">
            <div class="table-responsive">
<table class="table">
                <thead>
                    <tr>
                        <th>Work Order #</th>
                        <th>Unit</th>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th style="text-align:right;">Total Cost</th>
                        <th>Requested</th>
                        <th>Completed</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="wo in items" :key="wo.id">
                        <tr>
                            <td class="font-mono" x-text="wo.work_order_number"></td>
                            <td class="font-mono" x-text="wo.unit_number || '—'"></td>
                            <td x-text="wo.work_type ? wo.work_type.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()) : '—'"></td>
                            <td style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="wo.title"></td>
                            <td>
                                <span class="badge" :class="woBadge(wo.status)" x-text="woLabel(wo.status)"></span>
                            </td>
                            <td class="font-mono" style="text-align:right;"
                                x-text="wo.total_cost > 0 ? '$' + parseFloat(wo.total_cost).toLocaleString('en-CA',{minimumFractionDigits:2}) : '—'"></td>
                            <td x-text="wo.requested_date || '—'"></td>
                            <td x-text="wo.completed_date || '—'"></td>
                            <td>
                                <a :href="'<?= base_url('maintenance_work_orders/show') ?>?id=' + wo.id"
                                   class="btn btn-secondary btn-sm">View</a>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
</div>
        </div>
        <div class="tab-table-footer">
            <span x-text="`Showing ${items.length} of ${total}`"></span>
            <button class="btn btn-secondary btn-sm"
                    x-show="items.length < total"
                    :disabled="loading"
                    @click="loadMore()"
                    x-text="loading ? 'Loading…' : 'Load more'">
            </button>
        </div>
    </div>
</div><!-- /work orders card -->

<!-- ── Vendor Details card ───────────────────────────────────────────────── -->
<div class="card" id="vendor-details">
    <div class="card-header"><h3 class="card-title">Vendor Details</h3></div>

    <!-- VIEW MODE — always server-rendered. Name / created moved to the
         hero and the rail footer (S-RECORD-REDESIGN). -->
    <div id="vendor-view-section" class="card-body">
        <dl class="rec-dl">
            <dt>Type</dt>
            <dd>
                <span class="badge <?= e($typeBadges[$vendor['vendor_type']] ?? 'badge-neutral') ?>">
                    <?= e($typeLabels[$vendor['vendor_type']] ?? $vendor['vendor_type']) ?>
                </span>
                <?php if ($vendor['is_preferred']): ?>
                <span class="badge badge-success" style="margin-left:6px;">Preferred</span>
                <?php endif; ?>
            </dd>

            <dt>Contact</dt>
            <dd><?= $vendor['contact_name'] ? e($vendor['contact_name']) : '—' ?></dd>

            <dt>Email</dt>
            <dd>
                <?php if ($vendor['email']): ?>
                <a href="mailto:<?= e($vendor['email']) ?>"><?= e($vendor['email']) ?></a>
                <?php else: ?>—<?php endif; ?>
            </dd>

            <dt>Phone</dt>
            <dd>
                <?php if ($vendor['phone']): ?>
                <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $vendor['phone'])) ?>"><?= e($vendor['phone']) ?></a>
                <?php else: ?>—<?php endif; ?>
            </dd>

            <dt>Address</dt>
            <dd>
                <?php
                $addr = array_filter([$vendor['address'], $vendor['city'], $vendor['state']]);
                echo $addr ? e(implode(', ', $addr)) : '—';
                ?>
            </dd>

            <dt>Hourly Rate</dt>
            <dd class="font-mono"><?= $vendor['hourly_rate'] ? format_currency($vendor['hourly_rate']) . '/hr' : '—' ?></dd>

            <dt>Currency</dt>
            <dd class="font-mono"><?= e($vendor['currency'] ?? 'CAD') ?></dd>

            <dt>Rating</dt>
            <dd>
                <?php if ($vendor['rating']): ?>
                <span style="color:var(--color-warning);"><?= str_repeat('★', (int)$vendor['rating']) ?></span>
                <span class="text-secondary"><?= e($vendor['rating']) ?>/5</span>
                <?php else: ?>—<?php endif; ?>
            </dd>

            <dt>Specializations</dt>
            <dd>
                <?php if ($specializations): ?>
                <?php foreach ($specializations as $spec): ?>
                <span class="badge badge-info" style="margin-right:4px;"><?= e($spec) ?></span>
                <?php endforeach; ?>
                <?php else: ?>—<?php endif; ?>
            </dd>

            <dt>Notes</dt>
            <dd style="white-space:pre-wrap;"><?= $vendor['notes'] ? e($vendor['notes']) : '—' ?></dd>
        </dl>
    </div>

    <!-- EDIT MODE — hidden until showEdit() called -->
    <div id="vendor-edit-section" style="display:none;" class="card-body">

        <div id="vendor-edit-error" class="alert alert-danger"
             style="display:none;margin-bottom:16px;"></div>

        <form id="vendor-edit-form" onsubmit="return false;">

            <!-- D19 optimistic lock token -->
            <input type="hidden" id="edit-updated-at" value="<?= e($vendor['updated_at']) ?>">

            <!-- Identity -->
            <div class="form-row-2" style="margin-bottom:16px;">
                <div>
                    <label class="form-label">Vendor Name *</label>
                    <input type="text" id="edit-name" class="form-control"
                           value="<?= e($vendor['name']) ?>" maxlength="255">
                </div>
                <div>
                    <label class="form-label">Vendor Type *</label>
                    <select id="edit-vendor-type" class="form-control">
                        <option value="maintenance" <?= $vendor['vendor_type'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        <option value="repair"      <?= $vendor['vendor_type'] === 'repair'      ? 'selected' : '' ?>>Repair</option>
                        <option value="parts"       <?= $vendor['vendor_type'] === 'parts'       ? 'selected' : '' ?>>Parts</option>
                        <option value="inspection"  <?= $vendor['vendor_type'] === 'inspection'  ? 'selected' : '' ?>>Inspection</option>
                        <option value="towing"      <?= $vendor['vendor_type'] === 'towing'      ? 'selected' : '' ?>>Towing</option>
                        <option value="other"       <?= $vendor['vendor_type'] === 'other'       ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
            </div>

            <!-- Contact -->
            <div class="form-row-2" style="margin-bottom:16px;">
                <div>
                    <label class="form-label">Contact Name</label>
                    <input type="text" id="edit-contact-name" class="form-control"
                           value="<?= e($vendor['contact_name'] ?? '') ?>" maxlength="255">
                </div>
                <div>
                    <label class="form-label">Email</label>
                    <input type="email" id="edit-email" class="form-control"
                           value="<?= e($vendor['email'] ?? '') ?>" maxlength="255">
                </div>
            </div>

            <div class="form-row-2" style="margin-bottom:16px;">
                <div>
                    <label class="form-label">Phone</label>
                    <input type="text" id="edit-phone" class="form-control"
                           value="<?= e($vendor['phone'] ?? '') ?>" maxlength="50">
                </div>
                <div><!-- blank for grid alignment --></div>
            </div>

            <!-- Location -->
            <div style="margin-bottom:16px;">
                <label class="form-label">Address</label>
                <input type="text" id="edit-address" class="form-control"
                       value="<?= e($vendor['address'] ?? '') ?>" maxlength="500">
            </div>
            <div class="form-row-2" style="margin-bottom:16px;">
                <div>
                    <label class="form-label">City</label>
                    <input type="text" id="edit-city" class="form-control"
                           value="<?= e($vendor['city'] ?? '') ?>" maxlength="100">
                </div>
                <div>
                    <label class="form-label">Province / State</label>
                    <input type="text" id="edit-state" class="form-control"
                           value="<?= e($vendor['state'] ?? '') ?>" maxlength="100">
                </div>
            </div>

            <!-- Rates & Rating -->
            <div class="form-row-2" style="margin-bottom:16px;">
                <div>
                    <label class="form-label">Hourly Rate ($)</label>
                    <input type="number" min="0" id="edit-hourly-rate" class="form-control"
                           value="<?= e($vendor['hourly_rate'] ?? '') ?>"
                           min="0" step="0.01">
                </div>
                <div>
                    <label class="form-label">Rating (1–5)</label>
                    <select id="edit-rating" class="form-control">
                        <option value="">— Not rated —</option>
                        <option value="1" <?= (string)$vendor['rating'] === '1' ? 'selected' : '' ?>>★ 1 — Poor</option>
                        <option value="2" <?= (string)$vendor['rating'] === '2' ? 'selected' : '' ?>>★★ 2 — Fair</option>
                        <option value="3" <?= (string)$vendor['rating'] === '3' ? 'selected' : '' ?>>★★★ 3 — Good</option>
                        <option value="4" <?= (string)$vendor['rating'] === '4' ? 'selected' : '' ?>>★★★★ 4 — Very Good</option>
                        <option value="5" <?= (string)$vendor['rating'] === '5' ? 'selected' : '' ?>>★★★★★ 5 — Excellent</option>
                    </select>
                </div>
            </div>

            <!-- Specializations -->
            <div style="margin-bottom:16px;">
                <label class="form-label">Specializations (hold Ctrl/Cmd to select multiple)</label>
                <select id="edit-specializations" class="form-control" multiple size="6">
                    <?php
                    $specOptions = ['Brakes','Diesel Engine','Electrical','Exhaust',
                                   'Hydraulics','HVAC','Suspension','Tires','Transmission','Welding'];
                    foreach ($specOptions as $opt):
                    ?>
                    <option value="<?= e($opt) ?>"
                            <?= in_array($opt, $specializations, true) ? 'selected' : '' ?>>
                        <?= e($opt) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Preferred + Notes -->
            <div style="margin-bottom:16px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="edit-is-preferred"
                           <?= $vendor['is_preferred'] ? 'checked' : '' ?>>
                    <span class="form-label" style="margin:0;">Mark as Preferred Vendor</span>
                </label>
            </div>

            <div style="margin-bottom:24px;">
                <label class="form-label">Notes</label>
                <textarea id="edit-notes" class="form-control" rows="4"><?= e($vendor['notes'] ?? '') ?></textarea>
            </div>

            <!-- Edit actions -->
            <div style="display:flex;gap:12px;padding-top:16px;border-top:1px solid var(--border-default);">
                <button id="btn-save" type="button" class="btn btn-primary"
                        onclick="saveVendor()">Save Changes</button>
                <button type="button" class="btn btn-secondary"
                        onclick="cancelEdit()">Cancel</button>
            </div>

        </form>
    </div><!-- /vendor-edit-section -->

</div><!-- /card -->

<!-- ── Units Serviced ────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            Units Serviced
            <span class="badge badge-neutral"><?= e(count($unitHistory)) ?></span>
        </h3>
    </div>

    <?php if (empty($unitHistory)): ?>
    <div class="card-body">
        <div class="empty-state">
            <p class="empty-state-title">No equipment history</p>
            <p class="empty-state-text">Equipment units serviced by this vendor will appear here once work orders are assigned.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="tab-table-container">
        <div class="table-responsive">
<table class="table">
            <thead>
                <tr>
                    <th>Unit #</th>
                    <th>Equipment</th>
                    <th style="text-align:right;">Services</th>
                    <th>Last Service</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unitHistory as $u): ?>
                <tr>
                    <td>
                        <a href="<?= base_url('equipment/show') ?>?id=<?= e($u['id']) ?>"
                           class="link font-mono"><?= e($u['unit_number']) ?></a>
                    </td>
                    <td>
                        <?php
                        $unitDesc = implode(' ', array_filter([$u['year'], $u['brand'], $u['model']]));
                        echo $unitDesc ? e($unitDesc) : '—';
                        ?>
                    </td>
                    <td class="font-mono" style="text-align:right;"><?= e($u['service_count']) ?></td>
                    <td><?= $u['last_service_date'] ? format_date($u['last_service_date']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Serviced Units On Rent (was "Active Lease Exposure") ───────────────── -->
<div class="card" id="lease-exposure">
    <div class="card-header">
        <h3 class="card-title">
            Serviced Units On Rent
            <span class="badge badge-neutral"><?= e(count($leaseExposure)) ?></span>
        </h3>
    </div>

    <?php if (empty($leaseExposure)): ?>
    <div class="card-body">
        <div class="empty-state">
            <p class="empty-state-title">No active lease exposure</p>
            <p class="empty-state-text">None of the units serviced by this vendor are currently on an active lease.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="tab-table-container">
        <div class="table-responsive">
<table class="table">
            <thead>
                <tr>
                    <th>Unit #</th>
                    <th>Customer</th>
                    <th>Lease #</th>
                    <th>Status</th>
                    <th>Start</th>
                    <th>End</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leaseExposure as $le): ?>
                <?php
                $leaseBadge = $le['status'] === 'active' ? 'badge-success' : 'badge-warning';
                ?>
                <tr>
                    <td>
                        <a href="<?= base_url('equipment/show') ?>?id=<?= e($le['unit_id']) ?>"
                           class="link font-mono"><?= e($le['unit_number']) ?></a>
                    </td>
                    <td>
                        <a href="<?= base_url('customers/show') ?>?id=<?= e($le['customer_id']) ?>"
                           class="link"><?= e($le['company_name']) ?></a>
                    </td>
                    <td>
                        <a href="<?= base_url('leases/show') ?>?id=<?= e($le['lease_id']) ?>"
                           class="link font-mono"><?= e($le['contract_number']) ?></a>
                    </td>
                    <td><span class="badge <?= e($leaseBadge) ?>"><?= e(ucfirst($le['status'])) ?></span></td>
                    <td><?= format_date($le['start_date']) ?></td>
                    <td><?= $le['end_date'] ? format_date($le['end_date']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Activity Log ───────────────────────────────────────────── -->
<div class="card">
    <div class="card-header"><h3 class="card-title">Activity</h3></div>
    <div class="card-body">
        <?php $activityEntityType = 'vendor'; $activityEntityId = $vendorId; ?>
        <?php require_once FF_ROOT . '/includes/partials/activity-log.php'; ?>
    </div>
</div>

</div><!-- /rec-main -->

<?php
// ── RAIL (S-RECORD-REDESIGN) — the vendor at a glance ────────────────────────
$R = \FleetForge\Ui\RecordUi::class;
$railV = [];

// 1. Spend — money roles only.
if ($canSeeMoney) {
    $body = $R::big(e(format_currency($vendor['total_spent'])), 'spent all time');
    if ($canSeeBills && bccomp($billTotal, '0', 2) > 0) {
        $paidPct = (float) bcmul(bcdiv($billPaid, $billTotal, 6), '100', 2);
        $body .= $R::meter('Bills paid', e(round($paidPct)) . '%', $paidPct,
            $billOverCnt > 0 ? 'danger' : ($paidPct >= 99.99 ? 'ok' : 'info'),
            e(format_currency($billPaid)) . ' of ' . e(format_currency($billTotal)) . ' billed');
    }
    $body .= $R::kv([
        ['Last 12 months', e(format_currency($spend12)), 'mono'],
        ['Avg job', $avgJob !== null ? e(format_currency($avgJob)) : null, 'mono'],
        ['Hourly rate', $vendor['hourly_rate'] ? e(format_currency($vendor['hourly_rate'])) . '/hr' : null, 'mono'],
        ['Currency', e($vendor['currency'] ?? 'CAD')],
    ]);
    $railV[] = $R::card('Spend', $body, ['icon' => 'banknotes', 'class' => 'rec-card--accent']);
}

// 2. Needs attention.
$alertsV = [];
if ($woLate > 0) {
    $alertsV[] = ['danger', '<a href="' . e($woListUrl) . '&amp;status=active">' . $woLate . ' open work order' . ($woLate === 1 ? '' : 's') . '</a> past the scheduled date.'];
}
if ($woParts > 0) {
    $alertsV[] = ['warning', '<a href="' . e($woListUrl) . '&amp;status=waiting_parts">' . $woParts . ' work order' . ($woParts === 1 ? '' : 's') . '</a> waiting on parts.'];
}
if ($woUrgent > 0) {
    $alertsV[] = ['warning', $woUrgent . ' high-priority / emergency job' . ($woUrgent === 1 ? '' : 's') . ' still open.'];
}
if ($canSeeBills && $billOverCnt > 0) {
    $alertsV[] = ['danger', '<a href="#vendor-bills">' . $billOverCnt . ' bill' . ($billOverCnt === 1 ? '' : 's') . ' overdue</a> — ' . $fmtByCur($billsByCur, 'over_amt') . '.'];
} elseif ($canSeeBills && $billOpenCnt > 0) {
    $alertsV[] = ['info', '<a href="#vendor-bills">' . $billOpenCnt . ' unpaid bill' . ($billOpenCnt === 1 ? '' : 's') . '</a> — ' . $fmtByCur($billsByCur, 'open_amt') . ' owing.'];
}
if ($canSeeBills && $billDraftCnt > 0) {
    $alertsV[] = ['info', $billDraftCnt . ' draft bill' . ($billDraftCnt === 1 ? '' : 's') . ' waiting for approval.'];
}
if ($canSeeBills && $unbilledWos > 0) {
    $alertsV[] = ['info', '<a href="' . e($woListUrl) . '&amp;status=completed">' . $unbilledWos . ' completed job' . ($unbilledWos === 1 ? '' : 's') . '</a> with no bill entered yet.'];
}
if (trim((string) ($vendor['email'] ?? '')) === '' && trim((string) ($vendor['phone'] ?? '')) === '') {
    $alertsV[] = ['warning', 'No phone or email on file.'];
}
if ($qboMapping !== null && ($qboMapping['mapping_status'] ?? '') === 'ff_only') {
    $alertsV[] = ['info', 'Not in QuickBooks yet — <a href="' . e(base_url('quickbooks/vendors')) . '?q=' . e(rawurlencode((string) $vendor['name'])) . '">sync it</a>.'];
}
$railV[] = $R::card('Needs attention', $R::alerts($alertsV, 'All clear — nothing needs attention.'), ['icon' => 'exclamation-triangle']);

// 3. Contact.
$vAddr = trim(implode(', ', array_filter([(string) ($vendor['address'] ?? ''), (string) ($vendor['city'] ?? ''), (string) ($vendor['state'] ?? '')])));
$contactBody = $R::kv([
    ['Contact', !empty($vendor['contact_name']) ? e($vendor['contact_name']) : null],
    ['Phone', !empty($vendor['phone']) ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', (string) $vendor['phone'])) . '">' . e($vendor['phone']) . '</a>' : null],
    ['Email', !empty($vendor['email']) ? '<a href="mailto:' . e($vendor['email']) . '">' . e($vendor['email']) . '</a>' : null],
    ['Address', $vAddr !== '' ? e($vAddr) : null],
]);
$railV[] = $R::card('Contact', $contactBody !== '' ? $contactBody : '<p class="text-secondary" style="margin:0;font-size:12.5px;">No contact details yet.</p>', ['icon' => 'phone']);

// 4. Unpaid bills — AP roles. Each row opens the bill (the bills list has
//    no vendor deep-link, so a filtered "all bills" link isn't possible).
if ($canSeeBills) {
    if ($openBills) {
        $billLinks = [];
        foreach ($openBills as $b) {
            $overdue = (string) $b['due_date'] < $today;
            $billLinks[] = [
                (string) $b['bill_number'] . (!empty($b['vendor_bill_number']) ? ' · ' . $b['vendor_bill_number'] : ''),
                base_url('accounting/bills/show') . '?id=' . (int) $b['id'],
                'document-text',
                format_currency($b['balance_due']) . ' ' . $b['currency'] . ' · ' . ($overdue ? 'overdue ' : 'due ') . date('M j', strtotime((string) $b['due_date'])),
            ];
        }
        $billBody = $R::links($billLinks);
        $more = $billOpenCnt - count($openBills);
        $railV[] = $R::card('Unpaid bills', $billBody, [
            'icon' => 'receipt-percent',
            'id'   => 'vendor-bills',
            'foot' => $more > 0 ? '+' . $more . ' more — see <a href="' . e(base_url('accounting/ap-aging')) . '">AP aging</a>.' : '',
        ]);
    } else {
        $railV[] = $R::card('Unpaid bills', '<p class="rec-alerts-ok">Nothing owing to this vendor.</p>', ['icon' => 'receipt-percent', 'id' => 'vendor-bills']);
    }
}

// 5. Shortcuts — only links that open already scoped to this vendor.
$quickV = [['All work orders', $woListUrl, 'clipboard-document-list', (string) $woTotal]];
if ($woParts > 0) {
    $quickV[] = ['Waiting on parts', $woListUrl . '&status=waiting_parts', 'clock', (string) $woParts];
}
if (can('maintenance', 'create')) {
    $quickV[] = ['New work order', base_url('maintenance_work_orders/create') . '?vendor_id=' . (int) $vendorId, 'plus'];
}
if (!empty($vendor['email'])) {
    $quickV[] = ['Email ' . ($vendor['contact_name'] ?: 'vendor'), 'mailto:' . $vendor['email'], 'envelope'];
}
$railV[] = $R::card('Shortcuts', $R::links($quickV), ['icon' => 'sparkles']);
?>
<aside class="rec-rail" aria-label="Vendor at a glance">
    <?= implode("\n    ", $railV) ?>
    <p class="text-secondary" style="margin:0 4px;font-size:11.5px;line-height:1.5;">
        Added <?= e(format_datetime($vendor['created_at'])) ?><?= !empty($vendor['created_by_name']) ? ' by ' . e($vendor['created_by_name']) : '' ?><br>
        Last updated <?= e(format_datetime($vendor['updated_at'])) ?>
    </p>
</aside>
</div><!-- /rec-layout -->

<!-- ── Delete modal ─────────────────────────────────────────────────────── -->
<div id="vendor-delete-modal" class="modal-backdrop" style="display:none;"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3 class="modal-title">Delete Vendor</h3>
            <button class="btn-icon"
                    onclick="document.getElementById('vendor-delete-modal').style.display='none'">✕</button>
        </div>
        <div class="modal-body">
            <div id="vendor-delete-error" class="alert alert-danger"
                 style="display:none;margin-bottom:12px;"></div>
            <p>Are you sure you want to delete
                <strong><?= e($vendor['name']) ?></strong>?
            </p>
            <p class="text-secondary" style="font-size:0.875rem;">
                This action cannot be undone. Vendors with active work orders
                cannot be deleted.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary"
                    onclick="document.getElementById('vendor-delete-modal').style.display='none'">Cancel</button>
            <button id="btn-delete-confirm" class="btn btn-danger"
                    onclick="confirmDelete()">Delete Vendor</button>
        </div>
    </div>
</div>

<script>
// ── Work Orders Alpine component ─────────────────────────────────────────────
function FF_VendorWorkOrders() {
    return {
        items:   [],
        total:   0,
        page:    1,
        loading: false,
        loaded:  false,
        filters: { status: '', work_type: '', sort: 'created_at', dir: 'DESC' },

        init() { this.load(); },

        async load(append = false) {
            this.loading = true;
            try {
                const p = new URLSearchParams({ vendor_id: <?= $vendorId ?>, per_page: 50, page: this.page, sort: this.filters.sort, dir: this.filters.dir });
                if (this.filters.status)    p.set('status',    this.filters.status);
                if (this.filters.work_type) p.set('work_type', this.filters.work_type);
                const r    = await FF_Api.get('<?= base_url('api/v1/maintenance_work_orders/index.php') ?>?' + p);
                if (r.success) {
                    const rows  = r.data?.items ?? [];
                    this.items  = append ? [...this.items, ...rows] : rows;
                    this.total  = r.data.pagination?.total ?? rows.length;
                    this.loaded = true;
                }
            } catch(e) { /* non-fatal */ }
            this.loading = false;
        },

        loadMore()    { this.page++; this.load(true); },
        applyFilters(){ this.items = []; this.page = 1; this.total = 0; this.loaded = false; this.load(); },

        woBadge(s) {
            return { open:'badge badge-info', in_progress:'badge badge-warning', waiting_parts:'badge badge-warning',
                     completed:'badge badge-success', cancelled:'badge badge-neutral' }[s] ?? 'badge badge-neutral';
        },
        woLabel(s) {
            return { open:'Open', in_progress:'In Progress', waiting_parts:'Waiting Parts',
                     completed:'Completed', cancelled:'Cancelled' }[s] ?? s;
        },
    };
}

// ── Edit / Cancel ────────────────────────────────────────────────────────────
// The Edit button lives in the header (S-RECORD-REDESIGN) while the form is
// in the Vendor Details card further down — bring the form into view.
function showEdit() {
    document.getElementById('vendor-view-section').style.display = 'none';
    document.getElementById('vendor-edit-section').style.display = 'block';
    document.getElementById('btn-edit').style.display = 'none';
    document.getElementById('vendor-edit-error').style.display = 'none';
    document.getElementById('vendor-details').scrollIntoView({ behavior: 'smooth', block: 'start' });
    document.getElementById('edit-name').focus({ preventScroll: true });
}

function cancelEdit() {
    document.getElementById('vendor-edit-section').style.display = 'none';
    document.getElementById('vendor-view-section').style.display = 'block';
    document.getElementById('btn-edit').style.display = '';
}

// ── Save ─────────────────────────────────────────────────────────────────────
async function saveVendor() {
    const errEl  = document.getElementById('vendor-edit-error');
    const btnSave = document.getElementById('btn-save');
    errEl.style.display = 'none';

    const name = document.getElementById('edit-name').value.trim();
    const type = document.getElementById('edit-vendor-type').value;

    if (!name) {
        errEl.textContent = 'Vendor name is required.';
        errEl.style.display = 'block';
        return;
    }
    if (!type) {
        errEl.textContent = 'Vendor type is required.';
        errEl.style.display = 'block';
        return;
    }

    // Collect selected specializations
    const specSelect = document.getElementById('edit-specializations');
    const specializations = Array.from(specSelect.selectedOptions).map(o => o.value);

    const payload = {
        id:              <?= $vendorId ?>,
        updated_at:      document.getElementById('edit-updated-at').value,  // D19 lock
        name:            name,
        vendor_type:     type,
        contact_name:    document.getElementById('edit-contact-name').value.trim() || null,
        email:           document.getElementById('edit-email').value.trim() || null,
        phone:           document.getElementById('edit-phone').value.trim() || null,
        address:         document.getElementById('edit-address').value.trim() || null,
        city:            document.getElementById('edit-city').value.trim() || null,
        state:           document.getElementById('edit-state').value.trim() || null,
        specializations: specializations,
        hourly_rate:     document.getElementById('edit-hourly-rate').value || null,
        rating:          document.getElementById('edit-rating').value
                            ? parseInt(document.getElementById('edit-rating').value) : null,
        is_preferred:    document.getElementById('edit-is-preferred').checked ? 1 : 0,
        notes:           document.getElementById('edit-notes').value.trim() || null,
    };

    btnSave.disabled = true;
    btnSave.textContent = 'Saving…';

    try {
        const res = await fetch('<?= base_url('api/v1/vendors/update.php') ?>', {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token':     document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify(payload),
        });
        const d = await res.json();
        if (!res.ok) {
            throw d;
        }
        // Refresh page to show new server-rendered values
        window.location.reload();
    } catch (err) {
        errEl.textContent = err?.data?.message ?? err?.message ?? 'Save failed. Please try again.';
        errEl.style.display = 'block';
        btnSave.disabled = false;
        btnSave.textContent = 'Save Changes';
    }
}

// ── Delete ───────────────────────────────────────────────────────────────────
async function confirmDelete() {
    const errEl  = document.getElementById('vendor-delete-error');
    const btnDel = document.getElementById('btn-delete-confirm');
    errEl.style.display = 'none';

    btnDel.disabled = true;
    btnDel.textContent = 'Deleting…';

    try {
        const res = await fetch('<?= base_url('api/v1/vendors/delete.php') ?>', {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token':     document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ id: <?= $vendorId ?> }),
        });
        const d = await res.json();
        if (!res.ok) {
            throw d;
        }
        window.location = '<?= base_url('vendors') ?>';
    } catch (err) {
        errEl.textContent = err?.data?.message ?? err?.message ?? 'Delete failed. Please try again.';
        errEl.style.display = 'block';
        btnDel.disabled = false;
        btnDel.textContent = 'Delete Vendor';
    }
}
</script>

<?php
// ── AI Vendor Summary panel (S-AI-SUMMARY-PANELS) ──
$aiSummaryEntityType = 'vendor';
$aiSummaryEntityId   = $vendorId;
$aiSummaryType       = 'vendor_summary';
$aiSummaryTitle      = 'Vendor Summary — ' . ($vendor['name'] ?? ''); // raw — ai-panel.php escapes
require_once FF_ROOT . '/includes/partials/ai-panel.php';
?>
<?php require_once FF_ROOT . '/includes/footer.php'; ?>
