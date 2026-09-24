<?php
declare(strict_types=1);

// ============================================================
// /mileage_logs/show?id=N — Mileage Log detail
//
// Shows full detail for one mileage log entry.
// - Read-only for system types (gps_sync, lease_start, lease_end)
// - Edit mode available for manual and service types
// - Delete blocked for lease_start / lease_end types
//
// Layout (S-RECORD-REDESIGN):
//   header — ModuleHero entity: the reading as the title + entry-type
//            badge; unit · lease/customer · date · recorded-by chips;
//            Edit (primary) + help, Delete in the More menu
//   strip  — odometer · distance since the previous reading · average per
//            day over that gap · distance on this lease so far
//   main   — Entry details · Edit entry (opens from the header)
//   rail   — Needs attention (reading lower than the previous one,
//            unusually high daily distance, system-created) · Unit ·
//            Lease · Neighbouring readings
//   The four identity tiles (reading / date / type / unit) became the
//   title + chips; the strip now carries the distance numbers instead.
//
// Permission: maintenance view
// @session S-RECORD-REDESIGN
// ============================================================

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();
require_permission('maintenance', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    header('Location: ' . base_url('mileage_logs'));
    exit;
}

// ── Load record
$log = db_row(
    "SELECT
        ml.*,
        eu.unit_number,
        eb.label AS brand,
        et.model,
        et.category AS unit_category,
        u.name AS recorded_by_name
     FROM mileage_logs ml
     JOIN equipment_units eu ON eu.id = ml.equipment_unit_id AND eu.deleted_at IS NULL
     JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     LEFT JOIN equipment_brands eb ON eb.id = eu.brand_id
     LEFT JOIN users u ON u.id = ml.recorded_by
     WHERE ml.id = ?",
    [$id]
);

if (!$log) {
    header('Location: ' . base_url('mileage_logs'));
    exit;
}

// ── Load linked lease (if any)
$lease = null;
if ($log['lease_id']) {
    $lease = db_row(
        "SELECT l.id, l.contract_number, l.status, l.start_date, l.end_date, l.customer_id, c.company_name
         FROM leases l
         JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
         WHERE l.id = ? AND l.deleted_at IS NULL",
        [$log['lease_id']]
    );
}

// ── Neighbouring readings for the same unit (S-RECORD-REDESIGN) ────────────
// Ordered by (log_date, id) so two readings on one day still have an order.
$prevLog = db_row(
    "SELECT id, odometer_reading, mileage_unit, log_date, log_type
       FROM mileage_logs
      WHERE equipment_unit_id = ? AND (log_date < ? OR (log_date = ? AND id < ?))
      ORDER BY log_date DESC, id DESC LIMIT 1",
    [(int) $log['equipment_unit_id'], $log['log_date'], $log['log_date'], $id]
);
$nextLog = db_row(
    "SELECT id, odometer_reading, mileage_unit, log_date, log_type
       FROM mileage_logs
      WHERE equipment_unit_id = ? AND (log_date > ? OR (log_date = ? AND id > ?))
      ORDER BY log_date ASC, id ASC LIMIT 1",
    [(int) $log['equipment_unit_id'], $log['log_date'], $log['log_date'], $id]
);
$unitLogCount = (int) (db_row("SELECT COUNT(*) AS c FROM mileage_logs WHERE equipment_unit_id = ?", [(int) $log['equipment_unit_id']])['c'] ?? 0);

// A reading in the other unit (km vs miles) is converted into this log's unit
// before any subtraction — distances only compare like with like.
$unitLabel = $log['mileage_unit'] === 'miles' ? 'mi' : 'km';
$toThisUnit = static function (array $row) use ($log): int {
    $v = (int) $row['odometer_reading'];
    if ($row['mileage_unit'] === $log['mileage_unit']) return $v;
    return (int) round($log['mileage_unit'] === 'miles' ? $v * 0.621371 : $v * 1.609344);
};
$reading    = (int) $log['odometer_reading'];
$sincePrev  = $prevLog ? $reading - $toThisUnit($prevLog) : null;
$gapDays    = $prevLog ? max(0, (int) round((strtotime($log['log_date']) - strtotime($prevLog['log_date'])) / 86400)) : null;
$perDay     = ($sincePrev !== null && $gapDays > 0) ? (int) round($sincePrev / $gapDays) : null;

// Distance on the lease so far: from the lease's earliest reading (normally
// its lease_start log) to this one.
$leaseFirst = null;
$onLease    = null;
if ($lease) {
    $leaseFirst = db_row(
        "SELECT id, odometer_reading, mileage_unit, log_date, log_type
           FROM mileage_logs
          WHERE lease_id = ? AND equipment_unit_id = ?
          ORDER BY (log_type = 'lease_start') DESC, log_date ASC, id ASC LIMIT 1",
        [(int) $lease['id'], (int) $log['equipment_unit_id']]
    );
    if ($leaseFirst && (int) $leaseFirst['id'] !== $id && $leaseFirst['log_date'] <= $log['log_date']) {
        $onLease = $reading - $toThisUnit($leaseFirst);
    }
}
// "Unusually high": more than ~1,500 km (≈930 mi) a day on average — a road
// unit can't do that, so it is almost always a typo.
$highPerDay = $perDay !== null && $perDay > ($log['mileage_unit'] === 'miles' ? 930 : 1500);

// ── Flags
$isEditable  = in_array($log['log_type'], ['manual', 'service'], true) && can('maintenance', 'edit');
$isDeletable = !in_array($log['log_type'], ['lease_start', 'lease_end'], true) && can('maintenance', 'delete');
$isImmutable = in_array($log['log_type'], ['gps_sync', 'lease_start', 'lease_end'], true);

$typeLabelMap = [
    'manual'      => 'Manual',
    'gps_sync'    => 'GPS Sync',
    'lease_start' => 'Lease Start',
    'lease_end'   => 'Lease End',
    'service'     => 'Service',
];
$typeBadgeMap = [
    'manual'      => 'badge-info',
    'gps_sync'    => 'badge-success',
    'lease_start' => 'badge-neutral',
    'lease_end'   => 'badge-neutral',
    'service'     => 'badge-warning',
];

$pageTitle      = 'Mileage Log #' . $id;
$helpModuleSlug = 'mileage-logs';
require_once dirname(__DIR__, 3) . '/includes/header.php';

// ── Header (S-RECORD-REDESIGN) ──────────────────────────────────────────────
$fmtDist = static fn (int $n): string => number_format($n) . ' ' . $unitLabel;
$heroTitle = e(format_mileage($reading, $log['mileage_unit']))
    . ' <span class="badge ' . e($typeBadgeMap[$log['log_type']] ?? 'badge-neutral') . '">' . e($typeLabelMap[$log['log_type']] ?? $log['log_type']) . '</span>';
$heroFacts = [
    \FleetForge\Sop\SopIcons::svg('truck') . (can('equipment', 'view')
        ? '<a href="' . e(base_url('equipment/show')) . '?id=' . (int) $log['equipment_unit_id'] . '">Unit ' . e($log['unit_number']) . '</a>'
        : 'Unit ' . e($log['unit_number'])),
];
if ($lease) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('calendar-days') . (can('leases', 'view')
        ? '<a href="' . e(base_url('leases/show')) . '?id=' . (int) $lease['id'] . '">' . e($lease['contract_number']) . '</a>'
        : e($lease['contract_number'])) . ' · ' . e($lease['company_name']);
}
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('clock') . e(format_date($log['log_date']));
if (!empty($log['recorded_by_name'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('users') . e($log['recorded_by_name']);
}
?>
<?php ob_start(); /* secondary actions → the header's More menu */ ?>
    <a href="<?= base_url('mileage_logs') ?>">All mileage logs</a>
    <?php if (can('equipment', 'view')): ?>
    <a href="<?= base_url('equipment/show') ?>?id=<?= (int) $log['equipment_unit_id'] ?>#mileage_logs">Unit's mileage history</a>
    <?php endif; ?>
    <?php if ($isDeletable): ?>
    <button class="btn btn-danger" onclick="document.getElementById('delete-modal').style.display='flex';">
        Delete
    </button>
    <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
    <?= help_button('mileage-logs') ?>
    <?php if ($isEditable): ?>
    <button id="mlog-edit-btn" class="btn btn-primary btn-sm" onclick="mlogOpenEdit()">
        Edit
    </button>
    <?php endif; ?>
    <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => 'info',
    'icon'       => 'chart-bar-square',
    'mark'       => '#' . $id,
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Mileage Logs', base_url('mileage_logs')], ['#' . $id, null]],
    'eyebrow'    => 'Mileage log #' . $id,
    'title_html' => $heroTitle,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<!-- KEY NUMBERS (S-RECORD-REDESIGN): the distance story around this
     reading — how far since the last one, the daily pace, and the lease
     total so far. -->
<div class="stat-grid stat-grid--4 ff-stats">
    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-map-pin"/></svg></span>
        <div class="stat-label">Odometer</div>
        <div class="stat-value font-mono"><?= e(format_mileage($reading, $log['mileage_unit'])) ?></div>
    </div>
    <?php $backwards = $sincePrev !== null && $sincePrev < 0; ?>
    <div class="stat-card <?= $backwards ? 'stat-card--red' : 'stat-card--green' ?>">
        <span class="stat-icon <?= $backwards ? 'stat-icon--red' : 'stat-icon--green' ?>"><svg><use href="#icon-<?= $backwards ? 'exclamation-triangle' : 'arrow-trending-up' ?>"/></svg></span>
        <div class="stat-label">Since previous</div>
        <div class="stat-value font-mono"<?= $backwards ? ' style="color:var(--color-danger);"' : '' ?>><?= $sincePrev !== null ? e(($sincePrev > 0 ? '+' : '') . $fmtDist($sincePrev)) : '—' ?></div>
        <div class="stat-delta"><?= $prevLog ? e($gapDays . 'd gap') : 'first reading' ?></div>
    </div>
    <div class="stat-card <?= $highPerDay ? 'stat-card--amber' : 'stat-card--purple' ?>">
        <span class="stat-icon <?= $highPerDay ? 'stat-icon--amber' : 'stat-icon--purple' ?>"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Per day</div>
        <div class="stat-value font-mono"><?= $perDay !== null ? e($fmtDist($perDay)) : '—' ?></div>
        <div class="stat-delta"><?= $perDay !== null ? 'avg' : '' ?></div>
    </div>
    <div class="stat-card stat-card--teal">
        <span class="stat-icon stat-icon--teal"><svg><use href="#icon-truck"/></svg></span>
        <div class="stat-label">On this lease</div>
        <div class="stat-value font-mono"><?= $onLease !== null ? e($fmtDist($onLease)) : ($lease ? 'start' : '—') ?></div>
        <div class="stat-delta"><?= $onLease !== null && $leaseFirst ? 'since ' . e(date('M j', strtotime($leaseFirst['log_date']))) : ($lease ? '' : 'no lease') ?></div>
    </div>
</div>

<div class="rec-layout">
<div class="rec-main">

<!-- Detail card -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Entry details</h3>
    </div>
    <div class="card-body">
        <dl class="rec-dl">
            <dt>Equipment unit</dt>
            <dd>
                <a href="<?= base_url('equipment/show') ?>?id=<?= e($log['equipment_unit_id']) ?>" class="link">
                    <?= e($log['unit_number']) ?>
                </a>
                <span class="text-secondary"> — <?= e($log['brand']) ?> <?= e($log['model']) ?></span>
            </dd>

            <dt>Linked lease</dt>
            <dd>
                <?php if ($lease): ?>
                <a href="<?= base_url('leases/show') ?>?id=<?= e($lease['id']) ?>" class="link">
                    <?= e($lease['contract_number']) ?>
                </a>
                <span class="text-secondary"> — <?= e($lease['company_name']) ?></span>
                <?php else: ?>
                <span class="text-secondary">—</span>
                <?php endif; ?>
            </dd>

            <dt>Odometer</dt>
            <dd class="font-mono"><?= e(format_mileage($reading, $log['mileage_unit'])) ?></dd>

            <dt>Entry type</dt>
            <dd>
                <span class="badge <?= e($typeBadgeMap[$log['log_type']] ?? 'badge-neutral') ?>">
                    <?= e($typeLabelMap[$log['log_type']] ?? $log['log_type']) ?>
                </span>
            </dd>

            <dt>Log date</dt>
            <dd><?= e(format_date($log['log_date'])) ?></dd>

            <dt>Recorded by</dt>
            <dd><?= e($log['recorded_by_name'] ?? '—') ?></dd>

            <dt>Notes</dt>
            <dd style="white-space:pre-wrap;"><?= $log['notes'] ? e($log['notes']) : '<span class="text-secondary">—</span>' ?></dd>

            <dt>Created at</dt>
            <dd><?= e(format_datetime($log['created_at'])) ?></dd>
        </dl>
    </div>
</div>

<?php if ($isEditable): ?>
<!-- Edit section (hidden until the header's Edit opens it) -->
<div id="edit-section" class="card" style="display:none;">
    <div class="card-header">
        <h3 class="card-title">Edit entry</h3>
    </div>
    <div class="card-body">
        <div class="form-error-banner" id="edit-error" style="display:none;"></div>
        <form id="edit-form" novalidate>
            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label" for="edit_odometer">Odometer Reading</label>
                    <input type="number" class="form-control" id="edit_odometer" name="odometer_reading"
                           min="0" step="1" value="<?= e($log['odometer_reading']) ?>">
                    <div class="field-error" id="err-odometer_reading"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_unit">Unit</label>
                    <select class="form-control" id="edit_unit" name="mileage_unit">
                        <option value="km"    <?= $log['mileage_unit'] === 'km'    ? 'selected' : '' ?>>km</option>
                        <option value="miles" <?= $log['mileage_unit'] === 'miles' ? 'selected' : '' ?>>miles</option>
                    </select>
                    <div class="field-error" id="err-mileage_unit"></div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="edit_date">Log Date</label>
                <input type="date" class="form-control" id="edit_date" name="log_date"
                       value="<?= e($log['log_date']) ?>" max="<?= e(ff_today()) ?>">
                <div class="field-error" id="err-log_date"></div>
            </div>
            <div class="form-group">
                <label class="form-label" for="edit_notes">Notes</label>
                <textarea class="form-control" id="edit_notes" name="notes" rows="3"><?= e($log['notes'] ?? '') ?></textarea>
                <div class="field-error" id="err-notes"></div>
            </div>
            <!-- Optimistic lock: use created_at since no updated_at on this table -->
            <input type="hidden" name="id" value="<?= e($id) ?>">
            <input type="hidden" name="created_at" value="<?= e($log['created_at']) ?>">
            <div style="display:flex;gap:.75rem;padding-top:1rem;border-top:1px solid var(--border-color);">
                <button type="submit" class="btn btn-primary" id="edit-btn">Save Changes</button>
                <button type="button" class="btn btn-ghost" onclick="mlogCloseEdit()">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>
<script>
// S-RECORD-REDESIGN: Edit lives in the header now — open the form, hide the
// button and bring the form into view; Cancel reverses it. (Was an inline
// onclick + a '[onclick*=edit-section]' lookup for the Cancel.)
function mlogOpenEdit() {
    document.getElementById('edit-section').style.display = 'block';
    const b = document.getElementById('mlog-edit-btn');
    if (b) b.style.display = 'none';
    document.getElementById('edit-section').scrollIntoView({ block: 'start' });
    document.getElementById('edit_odometer').focus({ preventScroll: true });
}
function mlogCloseEdit() {
    document.getElementById('edit-section').style.display = 'none';
    const b = document.getElementById('mlog-edit-btn');
    if (b) b.style.display = '';
}
// Clear/paint helpers for the edit form
function mlogEditClear() {
    const errEl = document.getElementById('edit-error');
    errEl.style.display = 'none';
    errEl.textContent = '';
    ['odometer_reading','mileage_unit','log_date','notes'].forEach(f => {
        const el = document.getElementById('err-' + f);
        if (el) { el.textContent = ''; el.style.display = 'none'; }
    });
}
function mlogEditPaint(fields, topMsg) {
    const errEl = document.getElementById('edit-error');
    errEl.textContent = topMsg || 'Please fix the errors below and try again.';
    errEl.style.display = 'block';
    for (const [f, msg] of Object.entries(fields || {})) {
        const el = document.getElementById('err-' + f);
        if (el) { el.textContent = msg; el.style.display = 'block'; }
    }
}

document.getElementById('edit-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    mlogEditClear();

    // Client-side validation
    const cErrs  = {};
    const odo    = document.getElementById('edit_odometer').value;
    const dt     = document.getElementById('edit_date').value;
    if (odo !== '' && (isNaN(parseInt(odo)) || parseInt(odo) < 0)) {
        cErrs.odometer_reading = 'Odometer cannot be negative.';
    }
    if (dt) {
        const today = FF_localDate();
        if (dt > today) cErrs.log_date = 'Log date cannot be in the future.';
    }
    if (Object.keys(cErrs).length) {
        mlogEditPaint(cErrs, 'Please fix the errors below and try again.');
        return;
    }

    const btn = document.getElementById('edit-btn');
    btn.disabled = true; btn.textContent = 'Saving…';

    try {
        const res  = await fetch('<?= base_url('api/v1/mileage_logs/update') ?>', {
            method: 'POST',
            body: new FormData(this),
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        });
        const data = await res.json();
        if (!data.success) {
            const err = data.error || {};
            const code = err.code || data.code;
            const fields = err.fields || {};
            if (code === 'STALE_DATA') {
                mlogEditPaint({}, 'This entry was modified by another user. Please refresh the page and try again.');
            } else {
                mlogEditPaint(fields, err.message || data.error?.message || 'Update failed.');
            }
            btn.disabled = false; btn.textContent = 'Save Changes';
            return;
        }
        window.location.reload();
    } catch(err) {
        mlogEditPaint({}, 'Network error. Please try again.');
        btn.disabled = false; btn.textContent = 'Save Changes';
    }
});
</script>
<?php endif; ?>

</div><!-- /rec-main -->
<?php
// ── RAIL (S-RECORD-REDESIGN) — the reading at a glance ──────────────────────
$R = \FleetForge\Ui\RecordUi::class;
$rail = [];

$alerts = [];
if ($backwards) {
    $alerts[] = ['danger', 'Lower than the previous reading (' . e(format_mileage((int) $prevLog['odometer_reading'], $prevLog['mileage_unit'])) . ' on ' . e(format_date($prevLog['log_date'])) . ') — check for a typo or a replaced odometer.'];
}
if ($nextLog && $toThisUnit($nextLog) < $reading) {
    $alerts[] = ['warning', 'The next reading (' . e(format_mileage((int) $nextLog['odometer_reading'], $nextLog['mileage_unit'])) . ' on ' . e(format_date($nextLog['log_date'])) . ') is lower than this one.'];
}
if ($highPerDay) {
    $alerts[] = ['warning', 'Averages <b>' . e($fmtDist($perDay)) . '/day</b> since the previous reading — unusually high; likely a typo.'];
}
if ($isImmutable) {
    // Was a full-width banner above the details.
    $alerts[] = ['info', 'Created automatically by the system (' . e($typeLabelMap[$log['log_type']]) . ') — it cannot be edited.'];
}
$rail[] = $R::card('Needs attention', $R::alerts($alerts, 'All clear — the reading is in sequence.'), ['icon' => 'exclamation-triangle']);

// Unit.
$rail[] = $R::card('Unit',
    $R::entity('Unit ' . $log['unit_number'], can('equipment', 'view') ? base_url('equipment/show') . '?id=' . (int) $log['equipment_unit_id'] : '',
        e(trim(($log['brand'] ?? '') . ' ' . ($log['model'] ?? ''))) ?: 'Equipment unit', '', 'truck')
    . '<div style="margin-top:10px;">' . $R::kv([
        ['Readings on file', (string) $unitLogCount],
        ['Category', !empty($log['unit_category']) ? e(ucwords(str_replace('_', ' ', (string) $log['unit_category']))) : null],
    ]) . '</div>',
    ['icon' => 'truck', 'class' => 'rec-card--accent']
    + (can('equipment', 'view') ? ['link' => ['Mileage', base_url('equipment/show') . '?id=' . (int) $log['equipment_unit_id'] . '#mileage_logs']] : []));

// Lease.
if ($lease) {
    $rail[] = $R::card('Lease',
        $R::entity((string) $lease['contract_number'], can('leases', 'view') ? base_url('leases/show') . '?id=' . (int) $lease['id'] : '',
            e($lease['company_name']) . ' · ' . e($lease['status']), \FleetForge\Ui\ModuleHero::initials((string) $lease['company_name']))
        . '<div style="margin-top:10px;">' . $R::kv([
            ['Term', e(format_date($lease['start_date'])) . ' → ' . ($lease['end_date'] ? e(format_date($lease['end_date'])) : 'open')],
            ['Distance so far', $onLease !== null ? e($fmtDist($onLease)) : null, 'mono'],
        ]) . '</div>',
        ['icon' => 'calendar-days']);
}

// Neighbouring readings.
$nb = [];
if ($prevLog) {
    $nb[] = ['Previous · ' . format_mileage((int) $prevLog['odometer_reading'], $prevLog['mileage_unit']), base_url('mileage_logs/show') . '?id=' . (int) $prevLog['id'], 'map-pin', format_date($prevLog['log_date'])];
}
if ($nextLog) {
    $nb[] = ['Next · ' . format_mileage((int) $nextLog['odometer_reading'], $nextLog['mileage_unit']), base_url('mileage_logs/show') . '?id=' . (int) $nextLog['id'], 'map-pin', format_date($nextLog['log_date'])];
}
if ($nb) {
    $rail[] = $R::card('Readings around it', $R::links($nb), ['icon' => 'map-pin']);
}
?>
<aside class="rec-rail" aria-label="Mileage log at a glance">
    <?= implode("\n    ", $rail) ?>
</aside>
</div><!-- /rec-layout -->

<?php if ($isDeletable): ?>
<!-- Delete modal -->
<div id="delete-modal" class="modal-backdrop"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div class="card" style="max-width:420px;width:90%;">
        <div class="card-header">
            <div class="card-title">Delete Mileage Entry?</div>
        </div>
        <div class="card-body">
            <p>This will permanently delete the mileage entry for
                <strong><?= e(format_mileage((int)$log['odometer_reading'], $log['mileage_unit'])) ?></strong>
                on <?= e(format_date($log['log_date'])) ?>. This action cannot be undone.</p>
            <div id="delete-error" class="alert alert-danger" style="display:none;"></div>
            <div style="display:flex;gap:.75rem;margin-top:1rem;">
                <button class="btn btn-danger" id="confirm-delete-btn" onclick="confirmDelete()">
                    Delete
                </button>
                <button class="btn btn-ghost" onclick="document.getElementById('delete-modal').style.display='none';">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>
<script>
async function confirmDelete() {
    const btn   = document.getElementById('confirm-delete-btn');
    const errEl = document.getElementById('delete-error');
    errEl.style.display = 'none';
    btn.disabled = true; btn.textContent = 'Deleting…';
    try {
        const form = new FormData();
        form.append('id', '<?= e($id) ?>');
        const res  = await fetch('<?= base_url('api/v1/mileage_logs/delete') ?>', {
            method: 'POST', body: form,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        });
        const data = await res.json();
        if (!data.success) {
            const err = data.error || {};
            const fields = err.fields || {};
            const msgs = Object.values(fields);
            errEl.textContent = msgs.length ? msgs.join(' ') : (err.message || data.error?.message || 'Delete failed.');
            errEl.style.display = 'block';
            btn.disabled = false; btn.textContent = 'Delete';
            return;
        }
        window.location.href = '<?= base_url('mileage_logs') ?>';
    } catch(err) {
        errEl.textContent = 'Network error. Please try again.';
        errEl.style.display = 'block';
        btn.disabled = false; btn.textContent = 'Delete';
    }
}
</script>
<?php endif; ?>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
