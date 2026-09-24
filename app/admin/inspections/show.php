<?php
declare(strict_types=1);

/**
 * app/admin/inspections/show.php
 *
 * Inspection conduct page. Displays all 9 sections:
 *   Sections 1-7: exterior/interior — condition dropdown + notes + photo zone
 *   Section 8:    Tires — full per-position table (brakes/tread/brand/org/wheels)
 *   Section 9:    Trailer Condition — 7-item checklist with legend codes
 *
 * Layout (S-RECORD-REDESIGN):
 *   - Entity hero: inspection number + status + type badges; unit (linked),
 *     lease (linked), customer, date and inspector as facts. The header holds
 *     the status transitions (Mark Complete / Sign Off / Re-open) and
 *     "+ Create Damage Claim"; "New inspection for this unit" and Delete sit
 *     in the More menu.
 *   - Key-numbers strip: overall condition (+ score), issues found (sections
 *     damaged / missing / fair + flagged checklist items — jumps to Findings),
 *     mileage, reefer hours, CVI expiry (days left / expired).
 *   - Main column: Findings (all 9 sections at a glance with jump links, plus
 *     the flagged checklist items), the 9 section cards (unchanged), General
 *     Notes, Activity.
 *   - Right rail: Needs attention (damage found with no claim, still a draft,
 *     awaiting sign-off, CVI expired / expiring, dirty before a lease, fuel
 *     not full on return), Unit (links equipment/show), Lease & customer,
 *     Damage claims raised from this inspection, Related inspections (the
 *     other inspection(s) on the same lease — pre vs post — and this unit's
 *     previous inspection), Details (type, date, inspector, fuel, cleanliness,
 *     photos, signed).
 *
 * Status transitions: draft→complete, complete→signed, complete→draft (reopen, manager only).
 * "Create Damage Claim" button visible on complete/signed inspections.
 * Photo upload per section via raw fetch() + FormData (FF_Api.post does not support multipart).
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/inspections/{show,update,update_status,delete}.php
 *           api/v1/inspections/sections/update.php
 *           api/v1/inspections/photos/{upload,delete}.php
 * @decisions D7/D19/D30/D32, Trap 5 (MIME), Trap 7 (no file_path in API)
 * @session  S016, S-RECORD-REDESIGN
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('inspections', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    header('Location: ' . base_url('inspections'));
    exit;
}

// ── Fetch inspection with all joins
$insp = db_row(
    "SELECT
        i.*, eu.unit_number, eb.label AS brand, et.model, et.category AS unit_type, eu.status AS unit_status,
        l.contract_number, l.customer_id, l.status AS lease_status, c.company_name AS customer_name,
        u.name AS inspected_by_user_name
     FROM inspections i
     LEFT JOIN equipment_units     eu ON eu.id = i.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates et ON et.id = eu.template_id      AND et.deleted_at IS NULL
     LEFT JOIN equipment_brands    eb ON eb.id = eu.brand_id
     LEFT JOIN leases              l  ON l.id  = i.lease_id          AND l.deleted_at IS NULL
     LEFT JOIN customers           c  ON c.id  = l.customer_id       AND c.deleted_at IS NULL
     LEFT JOIN users               u  ON u.id  = i.inspected_by_user_id
     WHERE i.id = ?",
    [$id]
);
if (!$insp) {
    header('Location: ' . base_url('inspections'));
    exit;
}

// ── Fetch sections
$sections = db_select(
    "SELECT id, section_name, `condition`, notes, section_data, sort_order
     FROM inspection_sections WHERE inspection_id = ? ORDER BY sort_order ASC",
    [$id]
);
foreach ($sections as &$sec) {
    if ($sec['section_data'] !== null) {
        $sec['section_data'] = json_decode($sec['section_data'], true);
    }
}
unset($sec);

// ── Fetch photos indexed by section_id for display
$photos = db_select(
    "SELECT id, section_id, caption, sort_order, uploaded_at FROM inspection_photos
     WHERE inspection_id = ? ORDER BY sort_order ASC, id ASC",
    [$id]
);
// Group by section_id (null = inspection-level)
$photosBySec = [];
foreach ($photos as $ph) {
    $key = $ph['section_id'] ?? 0;
    $photosBySec[$key][] = $ph;
}

$isImmutable = in_array($insp['status'], ['complete', 'signed'], true);
$canEdit     = can('inspections', 'edit') && !($insp['status'] === 'signed');
$canDelete   = can('inspections', 'delete');

// ── S-RECORD-REDESIGN: strip + rail context ──────────────────────────────────
$today = ff_today();   // company-local business day (never SQL CURDATE())
$condLabels = ['ok'=>'OK','fair'=>'Fair','damaged'=>'Damaged','missing'=>'Missing','na'=>'N/A'];
$condBadge  = ['ok'=>'badge-success','fair'=>'badge-warning','damaged'=>'badge-danger','missing'=>'badge-danger','na'=>'badge-neutral'];
$trailerItemLabels = [
    'mud_flaps' => 'Mud Flaps', 'lights' => 'Lights', 'canlocks' => 'Canlocks',
    'landing_gear' => 'Landing Gear (L/G)', 'inflation' => 'Inflation',
    'tray_skirts' => 'Tray / Skirts', 'rub_rail' => 'Rub Rail',
];
$trailerCodeLabels = ['C'=>'Cut','D'=>'Dent','S'=>'Scratch','B'=>'Bruise','P'=>'Patch','H'=>'Hole','missing'=>'Missing'];
// Findings: sections that are damaged / missing (serious) or fair (minor),
// plus Trailer Condition checklist items carrying a damage code.
$secSerious = 0;
$secMinor   = 0;
$flaggedChecklist = [];
foreach ($sections as $s) {
    if (in_array($s['condition'], ['damaged', 'missing'], true)) {
        $secSerious++;
    } elseif ($s['condition'] === 'fair') {
        $secMinor++;
    }
    if ($s['section_name'] === 'Trailer Condition' && is_array($s['section_data'])) {
        foreach ($s['section_data'] as $k => $entry) {
            $code = is_array($entry) ? (string) ($entry['code'] ?? 'ok') : 'ok';
            if (isset($trailerCodeLabels[$code])) {
                $flaggedChecklist[] = ['section_id' => (int) $s['id'], 'item' => $trailerItemLabels[$k] ?? ucwords(str_replace('_', ' ', (string) $k)),
                                       'code' => $code, 'notes' => (string) ($entry['notes'] ?? '')];
            }
        }
    }
}
$issueCount = $secSerious + $secMinor + count($flaggedChecklist);
$damageFound = $secSerious > 0 || $flaggedChecklist !== [] || in_array($insp['overall_condition'], ['poor', 'damaged'], true);
// CVI expiry: days left (negative = expired).
$cviDays = $insp['cvi_expiry'] ? (int) round((strtotime($insp['cvi_expiry']) - strtotime($today)) / 86400) : null;
// Damage claims raised from this inspection (damage_claims.inspection_id).
$inspClaims = db_select(
    "SELECT id, claim_number, status, severity FROM damage_claims
      WHERE inspection_id = ? AND deleted_at IS NULL ORDER BY id ASC",
    [$id]
);
// Related inspections: the other inspection(s) on the same lease (pre vs post
// — the comparison a damage dispute needs) and this unit's previous one.
$leaseInspections = $insp['lease_id'] ? db_select(
    "SELECT id, inspection_number, inspection_type, inspection_date, overall_condition, status
       FROM inspections WHERE lease_id = ? AND id <> ? ORDER BY inspection_date ASC, id ASC LIMIT 5",
    [(int) $insp['lease_id'], $id]
) : [];
$prevUnitInspection = db_row(
    "SELECT id, inspection_number, inspection_type, inspection_date, overall_condition
       FROM inspections
      WHERE equipment_unit_id = ? AND id <> ?
        AND (inspection_date < ? OR (inspection_date = ? AND id < ?))
      ORDER BY inspection_date DESC, id DESC LIMIT 1",
    [(int) $insp['equipment_unit_id'], $id, $insp['inspection_date'], $insp['inspection_date'], $id]
);
$typeLabel   = ['pre_lease'=>'Pre-Lease','post_lease'=>'Post-Lease','periodic'=>'Periodic','damage'=>'Damage','compliance'=>'Compliance'];
$typeBadge   = ['pre_lease'=>'badge-info','post_lease'=>'badge-warning','periodic'=>'badge-neutral','damage'=>'badge-danger','compliance'=>'badge-success'];
$statusBadge = ['draft' => 'badge-warning', 'complete' => 'badge-info', 'signed' => 'badge-success'];
$statusLabel = ['draft' => 'Draft', 'complete' => 'Complete', 'signed' => 'Signed'];
$fuelLabels  = ['empty'=>'Empty','quarter'=>'1/4','half'=>'1/2','three_quarter'=>'3/4','full'=>'Full'];
$canCreateClaim = in_array($insp['status'], ['complete', 'signed'], true) && can('inspections', 'create');
$createClaimUrl = base_url('damage_claims/create') . '?inspection_id=' . (int) $id . '&unit_id=' . (int) $insp['equipment_unit_id']
    . ($insp['lease_id'] ? '&lease_id=' . (int) $insp['lease_id'] : '');

$pageTitle = $insp['inspection_number'] ?? ('Inspection #' . $id);
$helpModuleSlug = 'inspections';
require_once FF_ROOT . '/includes/header.php';
?>

<?php
// ── Header (S-RECORD-REDESIGN) ────────────────────────────────────────────────
$unitDesc  = trim(($insp['brand'] ?? '') . ' ' . ($insp['model'] ?? ''));
$heroFacts = [];
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('truck') . '<a href="' . e(base_url('equipment/show')) . '?id=' . (int) $insp['equipment_unit_id'] . '">Unit <b>' . e($insp['unit_number']) . '</b></a>' . ($unitDesc !== '' ? ' · ' . e($unitDesc) : '');
if ($insp['contract_number']) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('calendar-days') . '<a href="' . e(base_url('leases/show')) . '?id=' . (int) $insp['lease_id'] . '">Lease ' . e($insp['contract_number']) . '</a>';
}
if ($insp['customer_name']) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('user-group') . e($insp['customer_name']);
}
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('clock') . e(format_date($insp['inspection_date']));
if ($insp['inspected_by']) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('users') . e($insp['inspected_by']);
}
?>
<?php ob_start(); /* secondary + destructive actions → the header's More menu (S-RECORD-REDESIGN) */ ?>
        <?php if (can('inspections', 'create')): ?>
        <a href="<?= base_url('inspections/create') ?>?unit_id=<?= (int) $insp['equipment_unit_id'] ?>" class="btn btn-secondary btn-sm">New inspection for this unit</a>
        <?php endif; ?>
        <?php if ($canDelete && $insp['status'] === 'draft'): ?>
        <button type="button" class="btn btn-danger btn-sm" onclick="deleteInspection()">Delete</button>
        <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
        <?= help_button('inspections') ?>
        <?php /* Status transitions — moved up from the old "Status transitions"
                 card (S-RECORD-REDESIGN). Same global handlers. */ ?>
        <?php if (can('inspections', 'edit')): ?>
            <?php if ($insp['status'] === 'draft'): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="transitionStatus('complete')">Mark Complete</button>
            <?php elseif ($insp['status'] === 'complete'): ?>
            <button type="button" class="btn btn-success btn-sm" onclick="transitionStatus('signed')">Sign Off</button>
            <?php if (can('inspections', 'settings')): ?>
            <button type="button" class="btn btn-secondary btn-sm" onclick="transitionStatus('draft')">Re-open (Draft)</button>
            <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($canCreateClaim): ?>
        <a href="<?= e($createClaimUrl) ?>" class="btn btn-danger btn-sm">+ Create Damage Claim</a>
        <?php endif; ?>
        <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => 'info',
    'icon'       => 'clipboard-document-check',
    'mark'       => (string) ($insp['inspection_number'] ?? ('#' . $id)),
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Inspections', base_url('inspections')], [(string) ($insp['inspection_number'] ?? ('Inspection #' . $id)), null]],
    'eyebrow'    => 'Inspection',
    'title_html' => e($insp['inspection_number'] ?? ('Inspection #' . $id))
        . ' <span class="badge ' . ($statusBadge[$insp['status']] ?? 'badge-neutral') . '" style="font-size:0.75rem;vertical-align:middle;margin-left:6px;">' . e($statusLabel[$insp['status']] ?? $insp['status']) . '</span>'
        . ' <span class="badge ' . ($typeBadge[$insp['inspection_type']] ?? 'badge-neutral') . '" style="font-size:0.75rem;vertical-align:middle;">' . e($typeLabel[$insp['inspection_type']] ?? $insp['inspection_type']) . '</span>',
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<!-- ============================================================
     KEY NUMBERS — the summary strip (S-RECORD-REDESIGN): the verdict
     (condition + how many things were flagged) and the readings that
     drive billing / compliance. Fuel + cleanliness moved to the rail.
     ============================================================ -->
<?php
$condTone = match ($insp['overall_condition']) {
    'excellent', 'good' => 'green',
    'fair'              => 'amber',
    'poor', 'damaged'   => 'red',
    default             => 'slate',
};
$cviTone = $cviDays === null ? 'slate' : ($cviDays < 0 ? 'red' : ($cviDays <= 30 ? 'amber' : 'green'));
?>
<div class="stat-grid stat-grid--5 ff-stats">
    <div class="stat-card stat-card--<?= $condTone ?>">
        <span class="stat-icon stat-icon--<?= $condTone ?>"><svg><use href="#icon-shield-check"/></svg></span>
        <div class="stat-label">Condition</div>
        <div class="stat-value"><?= $insp['overall_condition'] ? e(ucfirst($insp['overall_condition'])) : 'Not rated' ?></div>
        <div class="stat-delta"><?= $insp['condition_score'] !== null ? 'score ' . (int) $insp['condition_score'] . '/100' : 'overall' ?></div>
    </div>

    <a class="stat-card <?= $secSerious > 0 || $flaggedChecklist ? 'stat-card--red' : ($issueCount > 0 ? 'stat-card--amber' : 'stat-card--green') ?>" href="#insp-findings" title="Jump to the findings">
        <span class="stat-icon <?= $secSerious > 0 || $flaggedChecklist ? 'stat-icon--red' : ($issueCount > 0 ? 'stat-icon--amber' : 'stat-icon--green') ?>"><svg><use href="#icon-exclamation-triangle"/></svg></span>
        <div class="stat-label">Issues found</div>
        <div class="stat-value font-mono"><?= $issueCount ?></div>
        <div class="stat-delta"><?= $issueCount === 0 ? 'all sections OK' : $secSerious . ' damaged · ' . $secMinor . ' fair · ' . count($flaggedChecklist) . ' checklist' ?></div>
    </a>

    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-truck"/></svg></span>
        <div class="stat-label">Mileage</div>
        <div class="stat-value font-mono"><?= $insp['mileage_at_inspection'] ? e(number_format((int)$insp['mileage_at_inspection'])) : '—' ?></div>
        <div class="stat-delta"><?= $insp['mileage_at_inspection'] ? 'km at inspection' : 'not recorded' ?></div>
    </div>

    <div class="stat-card stat-card--purple">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Reefer hours</div>
        <div class="stat-value font-mono"><?= $insp['reefer_hours'] !== null ? e(number_format((int)$insp['reefer_hours'])) : '—' ?></div>
        <div class="stat-delta"><?= $insp['reefer_hours'] !== null ? 'engine hours' : 'not recorded' ?></div>
    </div>

    <div class="stat-card stat-card--<?= $cviTone ?>">
        <span class="stat-icon stat-icon--<?= $cviTone ?>"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">CVI expiry</div>
        <div class="stat-value"<?= $cviDays !== null && $cviDays < 0 ? ' style="color:var(--color-danger);"' : '' ?>><?= $insp['cvi_expiry'] ? e(format_date($insp['cvi_expiry'])) : '—' ?></div>
        <div class="stat-delta"><?php
            if ($cviDays === null) {
                echo 'not recorded';
            } elseif ($cviDays < 0) {
                echo '<span class="text-danger">expired ' . (-$cviDays) . ' day' . ($cviDays === -1 ? '' : 's') . ' ago</span>';
            } elseif ($cviDays === 0) {
                echo 'expires today';
            } else {
                echo $cviDays . ' day' . ($cviDays === 1 ? '' : 's') . ' left';
            }
        ?></div>
    </div>
</div>

<div class="rec-layout">
<div class="rec-main" style="display:flex;flex-direction:column;gap:16px;">

<!-- Status-transition errors (the buttons live in the header). -->
<span id="status-msg" style="font-size:0.875rem;"></span>

<!-- ── Findings — every section at a glance (S-RECORD-REDESIGN). Chips jump
     to the section card; a condition saved on this page updates its chip
     (saveConditionNotes). ────────────────────────────────────────────── -->
<div class="card" id="insp-findings">
    <div class="card-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <h2 class="card-title">Findings</h2>
        <span class="text-secondary text-sm" style="margin-left:auto;"><?= count($sections) ?> sections · <?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body">
        <div class="insp-chips">
            <?php foreach ($sections as $s): $sid = (int) $s['id']; ?>
            <a class="insp-chip" href="#section-<?= $sid ?>">
                <span class="insp-chip-name"><?= e($s['section_name']) ?></span>
                <span class="badge <?= $condBadge[$s['condition']] ?? 'badge-neutral' ?>" id="find-badge-<?= $sid ?>"><?= $condLabels[$s['condition']] ?? e($s['condition']) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php if ($flaggedChecklist): ?>
        <div style="margin-top:14px;">
            <div class="text-secondary" style="font-size:12px;font-weight:600;margin-bottom:6px;">Trailer checklist — flagged items</div>
            <dl class="rec-dl">
                <?php foreach ($flaggedChecklist as $fc): ?>
                <dt><a href="#section-<?= (int) $fc['section_id'] ?>" class="link"><?= e($fc['item']) ?></a></dt>
                <dd><span class="badge badge-warning"><?= e($trailerCodeLabels[$fc['code']] ?? $fc['code']) ?></span><?= $fc['notes'] !== '' ? ' <span class="text-secondary">— ' . e($fc['notes']) . '</span>' : '' ?></dd>
                <?php endforeach; ?>
            </dl>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Sections ───────────────────────────────────────────────────────────── -->
<?php foreach ($sections as $sec):
    $secId     = (int)$sec['id'];
    $isTires   = $sec['section_name'] === 'Tires';
    $isTrailer = $sec['section_name'] === 'Trailer Condition';
    $secPhotos = $photosBySec[$secId] ?? [];
    $condLabels = ['ok'=>'OK','fair'=>'Fair','damaged'=>'Damaged','missing'=>'Missing','na'=>'N/A'];
    $condBadge  = ['ok'=>'badge-success','fair'=>'badge-warning','damaged'=>'badge-danger','missing'=>'badge-danger','na'=>'badge-neutral'];
?>
<div class="card" id="section-<?= $secId ?>">
    <div class="card-header" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <h2 class="card-title"><?= e($sec['section_name']) ?></h2>
        <span class="badge <?= $condBadge[$sec['condition']] ?? 'badge-neutral' ?>" id="sec-badge-<?= $secId ?>">
            <?= $condLabels[$sec['condition']] ?? e($sec['condition']) ?>
        </span>
        <span style="margin-left:auto;font-size:0.8rem;color:var(--text-muted);">Section <?= (int)$sec['sort_order'] ?> of 9</span>
    </div>
    <div class="card-body">

        <?php if ($isTires): ?>
        <!-- ── TIRE TABLE ─────────────────────────────────────────────────── -->
        <p style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:12px;">
            Legend: <strong>C</strong>=Cut &nbsp;<strong>D</strong>=Dent &nbsp;<strong>S</strong>=Scratch &nbsp;<strong>B</strong>=Bruise &nbsp;<strong>P</strong>=Patch &nbsp;<strong>H</strong>=Hole
        </p>
        <div style="overflow-x:auto;">
            <table class="table" style="font-size:0.8rem;min-width:700px;" id="tire-table-<?= $secId ?>">
                <thead>
                    <tr>
                        <th>Position</th>
                        <th>Brakes</th>
                        <th>Tread</th>
                        <th>Brand</th>
                        <th>OEM/ORG</th>
                        <th>Wheels</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $tireData   = $sec['section_data']['positions'] ?? [];
                $sideLabels = ['L' => 'Left', 'R' => 'Right'];
                $posLabels  = ['O' => 'Outer', 'I' => 'Inner'];
                foreach (['L', 'R'] as $side):
                    for ($axle = 1; $axle <= 5; $axle++):
                        foreach (['O', 'I'] as $pos):
                            $key    = $side . $pos . '.' . $axle;
                            $tEntry = $tireData[$key] ?? ['brakes'=>'','tread'=>'','brand'=>'','org'=>'','wheels'=>''];
                ?>
                <tr>
                    <td style="white-space:nowrap;font-weight:600;"><?= e($sideLabels[$side]) ?> <?= e($posLabels[$pos]) ?> #<?= $axle ?></td>
                    <?php if ($canEdit): ?>
                    <td><input class="form-control form-control-sm" style="width:70px;" data-tire="<?= e($key) ?>" data-field="brakes" value="<?= e($tEntry['brakes']) ?>" <?= $isImmutable ? 'disabled' : '' ?>></td>
                    <td><input class="form-control form-control-sm" style="width:70px;" data-tire="<?= e($key) ?>" data-field="tread"  value="<?= e($tEntry['tread'])  ?>" <?= $isImmutable ? 'disabled' : '' ?>></td>
                    <td><input class="form-control form-control-sm" style="width:90px;" data-tire="<?= e($key) ?>" data-field="brand"  value="<?= e($tEntry['brand'])  ?>" <?= $isImmutable ? 'disabled' : '' ?>></td>
                    <td><input class="form-control form-control-sm" style="width:70px;" data-tire="<?= e($key) ?>" data-field="org"    value="<?= e($tEntry['org'])    ?>" <?= $isImmutable ? 'disabled' : '' ?>></td>
                    <td>
                        <select class="form-control form-control-sm" style="width:80px;" data-tire="<?= e($key) ?>" data-field="wheels" <?= $isImmutable ? 'disabled' : '' ?>>
                            <option value="" <?= $tEntry['wheels']==='' ? 'selected' : '' ?>>—</option>
                            <option value="AL" <?= $tEntry['wheels']==='AL' ? 'selected' : '' ?>>AL</option>
                            <option value="STL" <?= $tEntry['wheels']==='STL' ? 'selected' : '' ?>>STL</option>
                        </select>
                    </td>
                    <?php else: ?>
                    <td><?= e($tEntry['brakes']) ?: '—' ?></td>
                    <td><?= e($tEntry['tread'])  ?: '—' ?></td>
                    <td><?= e($tEntry['brand'])  ?: '—' ?></td>
                    <td><?= e($tEntry['org'])    ?: '—' ?></td>
                    <td><?= e($tEntry['wheels']) ?: '—' ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; endfor; endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canEdit && !$isImmutable): ?>
        <div style="margin-top:12px;">
            <button class="btn btn-primary btn-sm" onclick="saveTires(<?= $secId ?>)">Save Tire Data</button>
            <span id="tire-msg-<?= $secId ?>" style="margin-left:10px;font-size:0.875rem;"></span>
        </div>
        <?php endif; ?>

        <?php elseif ($isTrailer): ?>
        <!-- ── TRAILER CONDITION CHECKLIST ───────────────────────────────── -->
        <p style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:12px;">
            Legend: <strong>OK</strong>=OK &nbsp;<strong>C</strong>=Cut &nbsp;<strong>D</strong>=Dent &nbsp;<strong>S</strong>=Scratch &nbsp;<strong>B</strong>=Bruise &nbsp;<strong>P</strong>=Patch &nbsp;<strong>H</strong>=Hole &nbsp;<strong>N/A</strong>=Not Applicable
        </p>
        <?php
        $trailerData = $sec['section_data'] ?? [];
        $trailerItems = [
            'mud_flaps'    => 'Mud Flaps',
            'lights'       => 'Lights',
            'canlocks'     => 'Canlocks',
            'landing_gear' => 'Landing Gear (L/G)',
            'inflation'    => 'Inflation',
            'tray_skirts'  => 'Tray / Skirts',
            'rub_rail'     => 'Rub Rail',
        ];
        $legendCodes = ['ok','C','D','S','B','P','H','missing','na'];
        ?>
        <table class="table" style="font-size:0.875rem;" id="trailer-table-<?= $secId ?>">
            <thead>
                <tr><th style="width:180px;">Item</th><th>Condition Code</th><th>Notes</th></tr>
            </thead>
            <tbody>
            <?php foreach ($trailerItems as $itemKey => $itemLabel):
                $entry = $trailerData[$itemKey] ?? ['code'=>'ok','notes'=>''];
            ?>
            <tr>
                <td style="font-weight:600;"><?= e($itemLabel) ?></td>
                <td>
                    <?php if ($canEdit && !$isImmutable): ?>
                    <select class="form-control form-control-sm" style="width:100px;"
                            data-trailer="<?= e($itemKey) ?>" data-field="code">
                        <option value="ok"      <?= $entry['code']==='ok'      ? 'selected':'' ?>>OK</option>
                        <option value="C"       <?= $entry['code']==='C'       ? 'selected':'' ?>>C — Cut</option>
                        <option value="D"       <?= $entry['code']==='D'       ? 'selected':'' ?>>D — Dent</option>
                        <option value="S"       <?= $entry['code']==='S'       ? 'selected':'' ?>>S — Scratch</option>
                        <option value="B"       <?= $entry['code']==='B'       ? 'selected':'' ?>>B — Bruise</option>
                        <option value="P"       <?= $entry['code']==='P'       ? 'selected':'' ?>>P — Patch</option>
                        <option value="H"       <?= $entry['code']==='H'       ? 'selected':'' ?>>H — Hole</option>
                        <option value="missing" <?= $entry['code']==='missing' ? 'selected':'' ?>>Missing</option>
                        <option value="na"      <?= $entry['code']==='na'      ? 'selected':'' ?>>N/A</option>
                    </select>
                    <?php else: ?>
                    <span class="badge <?= in_array($entry['code'],['C','D','S','B','P','H','missing'],true) ? 'badge-warning' : 'badge-success' ?>">
                        <?= e(strtoupper($entry['code'])) ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($canEdit && !$isImmutable): ?>
                    <input class="form-control form-control-sm" style="width:100%;"
                           placeholder="Notes..."
                           data-trailer="<?= e($itemKey) ?>" data-field="notes"
                           value="<?= e($entry['notes'] ?? '') ?>">
                    <?php else: ?>
                    <?= e($entry['notes'] ?? '') ?: '<span class="text-muted">—</span>' ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($canEdit && !$isImmutable): ?>
        <div style="margin-top:12px;">
            <button class="btn btn-primary btn-sm" onclick="saveTrailer(<?= $secId ?>)">Save Trailer Condition</button>
            <span id="trailer-msg-<?= $secId ?>" style="margin-left:10px;font-size:0.875rem;"></span>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- ── STANDARD EXTERIOR / INTERIOR SECTION ──────────────────────── -->
        <div style="display:flex;flex-direction:column;gap:16px;">

            <!-- Condition -->
            <div class="form-group" style="max-width:240px;">
                <label class="form-label">Condition</label>
                <?php if ($canEdit && !$isImmutable): ?>
                <select class="form-control" id="cond-<?= $secId ?>"
                        onchange="saveConditionNotes(<?= $secId ?>)">
                    <option value="ok"      <?= $sec['condition']==='ok'      ? 'selected':'' ?>>OK</option>
                    <option value="fair"    <?= $sec['condition']==='fair'    ? 'selected':'' ?>>Fair</option>
                    <option value="damaged" <?= $sec['condition']==='damaged' ? 'selected':'' ?>>Damaged</option>
                    <option value="missing" <?= $sec['condition']==='missing' ? 'selected':'' ?>>Missing</option>
                    <option value="na"      <?= $sec['condition']==='na'      ? 'selected':'' ?>>N/A</option>
                </select>
                <?php else: ?>
                <span class="badge <?= $condBadge[$sec['condition']] ?? 'badge-neutral' ?>">
                    <?= $condLabels[$sec['condition']] ?? e($sec['condition']) ?>
                </span>
                <?php endif; ?>
            </div>

            <!-- Notes -->
            <div class="form-group">
                <label class="form-label">Notes</label>
                <?php if ($canEdit && !$isImmutable): ?>
                <textarea class="form-control" rows="3" id="notes-<?= $secId ?>"
                          placeholder="Describe any damage, observations..."
                          onblur="saveConditionNotes(<?= $secId ?>)"><?= e($sec['notes'] ?? '') ?></textarea>
                <?php else: ?>
                <p style="color:var(--text-secondary);"><?= $sec['notes'] ? e($sec['notes']) : '—' ?></p>
                <?php endif; ?>
            </div>
            <span id="sec-save-msg-<?= $secId ?>" style="font-size:0.875rem;"></span>

        </div>
        <?php endif; ?>

        <!-- ── Photos for this section ──────────────────────────────────── -->
        <div style="margin-top:20px;border-top:1px solid var(--border-color);padding-top:16px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <span style="font-size:0.875rem;font-weight:600;color:var(--text-secondary);">
                    Photos (<?= count($secPhotos) ?>)
                </span>
                <?php if ($canEdit && !$isImmutable): ?>
                <label class="btn btn-sm btn-ghost" style="cursor:pointer;">
                    + Add Photo
                    <input type="file" accept="image/jpeg,image/png,image/heic"
                           style="display:none;"
                           onchange="uploadPhoto(event, <?= $secId ?>)">
                </label>
                <?php endif; ?>
            </div>
            <div id="photos-<?= $secId ?>" style="display:flex;flex-wrap:wrap;gap:12px;">
                <?php foreach ($secPhotos as $ph): ?>
                <div class="photo-thumb" id="photo-<?= (int)$ph['id'] ?>"
                     style="position:relative;background:var(--bg-muted);border:1px solid var(--border-color);border-radius:6px;padding:8px;width:120px;text-align:center;">
                    <div style="font-size:2rem;margin-bottom:4px;">&#128247;</div>
                    <div style="font-size:0.75rem;color:var(--text-secondary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($ph['caption'] ?? '') ?>"><?= $ph['caption'] ? e($ph['caption']) : 'Photo' ?></div>
                    <div style="font-size:0.7rem;color:var(--text-muted);"><?= e(date('M j', strtotime($ph['uploaded_at']))) ?></div>
                    <?php if ($canEdit && !$isImmutable): ?>
                    <button onclick="deletePhoto(<?= (int)$ph['id'] ?>, <?= $secId ?>)"
                            style="position:absolute;top:2px;right:4px;background:none;border:none;cursor:pointer;color:var(--color-danger);font-size:0.75rem;">x</button>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <span id="photo-msg-<?= $secId ?>" style="font-size:0.875rem;margin-top:6px;display:block;"></span>
        </div>

    </div><!-- /card-body -->
</div>
<?php endforeach; ?>

<!-- ── General notes ─────────────────────────────────────────────────────── -->
<?php if ($insp['notes']): ?>
<div class="card">
    <div class="card-header"><h2 class="card-title">General Notes</h2></div>
    <div class="card-body"><p style="white-space:pre-wrap;margin:0;"><?= e($insp['notes']) ?></p></div>
</div>
<?php endif; ?>

<!-- ── Activity Log ───────────────────────────────────────────── -->
<div class="card">
    <div class="card-header"><h2 class="card-title">Activity</h2></div>
    <div class="card-body">
        <?php
        $activityEntityType = 'inspection';
        $activityEntityId   = $id;
        $activityOriginAt   = $insp['created_at'];
        // inspections has no created_by FK; use the linked inspector name as the origin label.
        $activityOriginBy   = $insp['inspected_by_user_name'] ?? $insp['inspected_by'] ?? null;
        ?>
        <?php require_once FF_ROOT . '/includes/partials/activity-log.php'; ?>
    </div>
</div>

</div><!-- /rec-main -->

<?php
// ── RAIL (S-RECORD-REDESIGN) — the inspection at a glance ──────────────────
$R = \FleetForge\Ui\RecordUi::class;
$railI = [];

// 1. Needs attention.
$alertsIn = [];
if ($damageFound && !$inspClaims) {
    $what = $secSerious > 0
        ? $secSerious . ' section' . ($secSerious === 1 ? '' : 's') . ' damaged / missing'
        : ($flaggedChecklist ? count($flaggedChecklist) . ' checklist item' . (count($flaggedChecklist) === 1 ? '' : 's') . ' flagged' : 'condition rated ' . e((string) $insp['overall_condition']));
    $alertsIn[] = ['danger', 'Damage found (' . $what . ') and no damage claim yet — '
        . ($canCreateClaim ? '<a href="' . e($createClaimUrl) . '">create one</a>.' : ($insp['status'] === 'draft' ? 'mark the inspection complete, then create one.' : 'raise one if the customer is liable.'))];
}
if ($insp['status'] === 'draft') {
    $alertsIn[] = ['warning', 'Still a draft — <b>Mark Complete</b> when every section is filled in.'];
} elseif ($insp['status'] === 'complete') {
    $alertsIn[] = ['info', 'Complete — awaiting <b>Sign Off</b>.'];
}
if ($cviDays !== null && $cviDays < 0) {
    $alertsIn[] = ['danger', 'CVI expired on ' . e(format_date($insp['cvi_expiry'])) . '.'];
} elseif ($cviDays !== null && $cviDays <= 30) {
    $alertsIn[] = ['warning', 'CVI expires in ' . $cviDays . ' day' . ($cviDays === 1 ? '' : 's') . ' (' . e(format_date($insp['cvi_expiry'])) . ').'];
}
if ($insp['inspection_type'] === 'pre_lease' && $insp['is_clean'] !== null && (int) $insp['is_clean'] === 0) {
    $alertsIn[] = ['warning', 'Marked <b>dirty</b> before going out on lease.'];
}
if ($insp['inspection_type'] === 'post_lease' && $insp['fuel_level'] && $insp['fuel_level'] !== 'full') {
    $alertsIn[] = ['info', 'Returned with fuel at ' . e($fuelLabels[$insp['fuel_level']] ?? $insp['fuel_level']) . ' — check for a fuel charge on the lease.'];
}
if ($insp['inspection_type'] === 'post_lease' && $insp['is_clean'] !== null && (int) $insp['is_clean'] === 0) {
    $alertsIn[] = ['info', 'Returned dirty — check for a wash charge on the lease.'];
}
$railI[] = $R::card('Needs attention', $R::alerts($alertsIn, 'All clear — nothing needs attention.'), ['icon' => 'exclamation-triangle']);

// 2. Unit.
$railI[] = $R::card('Unit', $R::entity(
    'Unit ' . (string) $insp['unit_number'],
    base_url('equipment/show') . '?id=' . (int) $insp['equipment_unit_id'],
    e($unitDesc !== '' ? $unitDesc : ucwords(str_replace('_', ' ', (string) ($insp['unit_type'] ?? '')))) . (!empty($insp['unit_status']) ? ' · ' . e(str_replace('_', ' ', (string) $insp['unit_status'])) : ''),
    '',
    'truck'
), ['icon' => 'truck', 'link' => ['Inspections', base_url('equipment/show') . '?id=' . (int) $insp['equipment_unit_id'] . '#inspections']]);

// 3. Lease & customer.
if ($insp['lease_id'] && $insp['contract_number']) {
    $lcBody = $R::entity(
        'Lease ' . (string) $insp['contract_number'],
        base_url('leases/show') . '?id=' . (int) $insp['lease_id'],
        e(ucfirst((string) ($insp['lease_status'] ?? ''))),
        '',
        'calendar-days'
    );
    if ($insp['customer_name']) {
        $lcBody .= '<div style="margin-top:10px;">' . $R::entity(
            (string) $insp['customer_name'],
            $insp['customer_id'] ? base_url('customers/show') . '?id=' . (int) $insp['customer_id'] : '',
            'Customer',
            \FleetForge\Ui\ModuleHero::initials((string) $insp['customer_name'])
        ) . '</div>';
    }
    $railI[] = $R::card('Lease & customer', $lcBody, ['icon' => 'user-group']);
}

// 4. Damage claims raised from this inspection.
if ($inspClaims) {
    $clLinks = [];
    foreach ($inspClaims as $cl) {
        $clLinks[] = [$cl['claim_number'], base_url('damage_claims/show') . '?id=' . (int) $cl['id'], 'exclamation-triangle',
            ucwords(str_replace('_', ' ', (string) $cl['status'])) . ' · ' . ucwords(str_replace('_', ' ', (string) $cl['severity']))];
    }
    $railI[] = $R::card('Damage claims', $R::links($clLinks), ['icon' => 'exclamation-triangle'] + ($canCreateClaim ? ['link' => ['+ New', $createClaimUrl]] : []));
} elseif ($canCreateClaim) {
    $railI[] = $R::card('Damage claims', '<p class="text-secondary" style="margin:0 0 8px;font-size:12.5px;">No claim raised from this inspection.</p>'
        . $R::links([['Create damage claim', $createClaimUrl, 'plus']]), ['icon' => 'exclamation-triangle']);
}

// 5. Related inspections — pre vs post on the same lease + the unit's last one.
$relIn = [];
foreach ($leaseInspections as $li) {
    $relIn[] = [($typeLabel[$li['inspection_type']] ?? $li['inspection_type']) . ' · ' . ($li['inspection_number'] ?? ('#' . $li['id'])),
        base_url('inspections/show') . '?id=' . (int) $li['id'], 'clipboard-document-check',
        format_date($li['inspection_date']) . ($li['overall_condition'] ? ' · ' . ucfirst((string) $li['overall_condition']) : '')];
}
if ($prevUnitInspection && !in_array((int) $prevUnitInspection['id'], array_map(static fn ($x) => (int) $x['id'], $leaseInspections), true)) {
    $relIn[] = ['Previous: ' . ($prevUnitInspection['inspection_number'] ?? ('#' . $prevUnitInspection['id'])),
        base_url('inspections/show') . '?id=' . (int) $prevUnitInspection['id'], 'clock',
        format_date($prevUnitInspection['inspection_date']) . ($prevUnitInspection['overall_condition'] ? ' · ' . ucfirst((string) $prevUnitInspection['overall_condition']) : '')];
}
if ($relIn) {
    $railI[] = $R::card('Related inspections', $R::links($relIn), ['icon' => 'clipboard-document-list']);
}

// 6. Details — the readings the strip doesn't carry + who / when.
$railI[] = $R::card('Details', $R::kv([
    ['Type', e($typeLabel[$insp['inspection_type']] ?? $insp['inspection_type'])],
    ['Date', e(format_date($insp['inspection_date']))],
    ['Inspector', $insp['inspected_by'] ? e($insp['inspected_by']) . ($insp['inspected_by_user_name'] && $insp['inspected_by_user_name'] !== $insp['inspected_by'] ? ' <span class="text-secondary">(' . e($insp['inspected_by_user_name']) . ')</span>' : '') : ($insp['inspected_by_user_name'] ? e($insp['inspected_by_user_name']) : null)],
    ['Fuel', $insp['fuel_level'] ? e($fuelLabels[$insp['fuel_level']] ?? $insp['fuel_level']) : '—'],
    ['Cleanliness', $insp['is_clean'] === null ? '—' : ((int) $insp['is_clean'] === 1 ? '<span class="badge badge-success">Clean</span>' : '<span class="badge badge-warning">Dirty</span>')],
    ['Photos', (string) count($photos)],
    ['Signed', $insp['status'] === 'signed' ? ($insp['signed_at'] ? e(format_datetime($insp['signed_at'])) : 'Yes') : null],
    ['Created', e(format_datetime($insp['created_at']))],
]), ['icon' => 'document-text']);
?>
<aside class="rec-rail" aria-label="Inspection at a glance">
    <?= implode("\n    ", $railI) ?>
</aside>
</div><!-- /rec-layout -->

<script>
const INSP_ID        = <?= (int)$id ?>;
const CSRF_TOKEN     = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const canEdit        = <?= $canEdit ? 'true' : 'false' ?>;

// ── Extract user-facing error message from VALID-2 envelope ──────────────
function extractError(d, fallback) {
    if (!d || !d.error) return fallback;
    // Prefer field-specific message if only one error, else the top message
    const f = d.error.fields || d.error.errors || {};
    const keys = Object.keys(f);
    if (keys.length === 1) return f[keys[0]];
    if (keys.length > 1)   return keys.map(k => f[k]).join(' ');
    return d.error.message || fallback;
}

// ── Save standard section condition + notes ──────────────────────────────
function saveConditionNotes(secId) {
    const condEl  = document.getElementById('cond-'  + secId);
    const notesEl = document.getElementById('notes-' + secId);
    if (!condEl) return;

    const payload = {
        section_id: secId,
        condition:  condEl.value,
        notes:      notesEl ? notesEl.value : '',
    };

    FF_Api.post('<?= base_url('api/v1/inspections/sections/update.php') ?>', payload)
        .then(d => {
            const msg = document.getElementById('sec-save-msg-' + secId);
            if (d && d.error) {
                if (msg) { msg.style.color = 'var(--color-danger)'; msg.textContent = extractError(d, 'Save failed.'); }
            } else {
                // Update badge inline
                const badge = document.getElementById('sec-badge-' + secId);
                const labels = {ok:'OK', fair:'Fair', damaged:'Damaged', missing:'Missing', na:'N/A'};
                const classes = {ok:'badge-success', fair:'badge-warning', damaged:'badge-danger', missing:'badge-danger', na:'badge-neutral'};
                if (badge) {
                    badge.className = 'badge ' + (classes[condEl.value] ?? 'badge-neutral');
                    badge.textContent = labels[condEl.value] ?? condEl.value;
                }
                // S-RECORD-REDESIGN: keep the Findings chip in step.
                const chip = document.getElementById('find-badge-' + secId);
                if (chip) {
                    chip.className = 'badge ' + (classes[condEl.value] ?? 'badge-neutral');
                    chip.textContent = labels[condEl.value] ?? condEl.value;
                }
                if (msg) { msg.style.color = 'var(--color-success)'; msg.textContent = 'Saved.'; setTimeout(() => { if(msg) msg.textContent = ''; }, 2000); }
            }
        });
}

// ── Save tire table ───────────────────────────────────────────────────────
function saveTires(secId) {
    const table = document.getElementById('tire-table-' + secId);
    if (!table) return;
    const positions = {};
    table.querySelectorAll('[data-tire]').forEach(el => {
        const key   = el.dataset.tire;
        const field = el.dataset.field;
        if (!positions[key]) positions[key] = {brakes:'',tread:'',brand:'',org:'',wheels:''};
        positions[key][field] = el.value;
    });

    // Client-side numeric validation — tread must be non-negative if set
    const problems = [];
    for (const [key, pos] of Object.entries(positions)) {
        if (pos.tread && pos.tread.trim()) {
            const n = parseFloat(pos.tread);
            if (isNaN(n) || n < 0) problems.push(`Tire ${key}: tread depth must be a positive number.`);
        }
    }
    const msg = document.getElementById('tire-msg-' + secId);
    if (problems.length) {
        if (msg) { msg.style.color = 'var(--color-danger)'; msg.textContent = problems[0]; }
        return;
    }

    const payload = { section_id: secId, section_data: { positions } };

    FF_Api.post('<?= base_url('api/v1/inspections/sections/update.php') ?>', payload)
        .then(d => {
            if (d && d.error) {
                if (msg) { msg.style.color = 'var(--color-danger)'; msg.textContent = extractError(d, 'Save failed.'); }
            } else {
                if (msg) { msg.style.color = 'var(--color-success)'; msg.textContent = 'Tire data saved.'; setTimeout(() => { if(msg) msg.textContent = ''; }, 2000); }
            }
        });
}

// ── Save trailer condition ────────────────────────────────────────────────
function saveTrailer(secId) {
    const table = document.getElementById('trailer-table-' + secId);
    if (!table) return;
    const data = {};
    table.querySelectorAll('[data-trailer]').forEach(el => {
        const key   = el.dataset.trailer;
        const field = el.dataset.field;
        if (!data[key]) data[key] = {code:'ok', notes:''};
        data[key][field] = el.value;
    });
    const payload = { section_id: secId, section_data: data };

    FF_Api.post('<?= base_url('api/v1/inspections/sections/update.php') ?>', payload)
        .then(d => {
            const msg = document.getElementById('trailer-msg-' + secId);
            if (d && d.error) {
                if (msg) { msg.style.color = 'var(--color-danger)'; msg.textContent = extractError(d, 'Save failed.'); }
            } else {
                if (msg) { msg.style.color = 'var(--color-success)'; msg.textContent = 'Trailer condition saved.'; setTimeout(() => { if(msg) msg.textContent = ''; }, 2000); }
            }
        });
}

// ── Status transitions ────────────────────────────────────────────────────
// [UI-AUDIT-1:M13] async because FF_Confirm.ask() returns a Promise.
async function transitionStatus(newStatus) {
    const labels = { complete: 'Mark Complete', signed: 'Sign Off', draft: 'Re-open' };
    if (!(await FF_Confirm.ask('Confirm: ' + (labels[newStatus] ?? newStatus) + '?'))) return;

    FF_Api.post('<?= base_url('api/v1/inspections/update_status.php') ?>', {
        id: INSP_ID, status: newStatus
    }).then(d => {
        if (d && d.error) {
            const msg = document.getElementById('status-msg');
            if (msg) { msg.style.color = 'var(--color-danger)'; msg.textContent = extractError(d, 'Failed.'); }
        } else {
            window.location.reload();
        }
    });
}

// ── Photo upload ─────────────────────────────────────────────────────────
function uploadPhoto(event, secId) {
    const file = event.target.files[0];
    if (!file) return;
    const msg = document.getElementById('photo-msg-' + secId);

    // Client-side validation before hitting network
    if (file.size > 10 * 1024 * 1024) {
        if (msg) { msg.style.color = 'var(--color-danger)'; msg.textContent = 'Photo must be 10 MB or smaller.'; }
        event.target.value = '';
        return;
    }
    const allowedExt = ['jpg','jpeg','png','heic','heif'];
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    if (!allowedExt.includes(ext)) {
        if (msg) { msg.style.color = 'var(--color-danger)'; msg.textContent = 'Unsupported file type. Allowed formats: JPEG, PNG, HEIC.'; }
        event.target.value = '';
        return;
    }

    if (msg) { msg.style.color = 'var(--text-secondary)'; msg.textContent = 'Uploading...'; }

    const fd = new FormData();
    fd.append('photo', file);
    fd.append('inspection_id', INSP_ID);
    fd.append('section_id', secId);

    // Raw fetch() required for multipart/form-data — FF_Api.post sends JSON
    fetch('<?= base_url('api/v1/inspections/photos/upload.php') ?>', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': CSRF_TOKEN,
        },
        body: fd,
    })
    .then(r => r.json())
    .then(d => {
        if (d && d.error) {
            if (msg) { msg.style.color = 'var(--color-danger)'; msg.textContent = extractError(d, 'Upload failed.'); }
        } else {
            // Append new thumb
            const zone = document.getElementById('photos-' + secId);
            if (zone) {
                const thumb = document.createElement('div');
                thumb.className = 'photo-thumb';
                thumb.id = 'photo-' + d.data.id;
                thumb.style.cssText = 'position:relative;background:var(--bg-muted);border:1px solid var(--border-color);border-radius:6px;padding:8px;width:120px;text-align:center;';
                thumb.innerHTML = `<div style="font-size:2rem;margin-bottom:4px;">&#128247;</div>
                    <div style="font-size:0.75rem;color:var(--text-secondary);">Photo</div>
                    <button onclick="deletePhoto(${d.data.id},${secId})"
                        style="position:absolute;top:2px;right:4px;background:none;border:none;cursor:pointer;color:var(--color-danger);font-size:0.75rem;">x</button>`;
                zone.appendChild(thumb);
            }
            if (msg) { msg.style.color = 'var(--color-success)'; msg.textContent = 'Uploaded.'; setTimeout(() => { if(msg) msg.textContent=''; }, 2000); }
        }
        event.target.value = ''; // reset file input
    })
    .catch(() => {
        if (msg) { msg.style.color = 'var(--color-danger)'; msg.textContent = 'Upload failed — network error.'; }
    });
}

// ── Photo delete ─────────────────────────────────────────────────────────
// [UI-AUDIT-1:M13] async for FF_Confirm.ask().
async function deletePhoto(photoId, secId) {
    if (!(await FF_Confirm.ask('Delete this photo?'))) return;
    FF_Api.post('<?= base_url('api/v1/inspections/photos/delete.php') ?>', { photo_id: photoId })
        .then(d => {
            if (d && d.error) {
                FF_Toast.error(extractError(d, 'Delete failed.'));
            } else {
                const el = document.getElementById('photo-' + photoId);
                if (el) el.remove();
            }
        });
}

// ── Delete inspection ─────────────────────────────────────────────────────
// [UI-AUDIT-1:M13] async for FF_Confirm.ask().
async function deleteInspection() {
    if (!(await FF_Confirm.ask('Delete this inspection? This cannot be undone.'))) return;
    FF_Api.post('<?= base_url('api/v1/inspections/delete.php') ?>', { id: INSP_ID })
        .then(d => {
            if (d && d.error) {
                FF_Toast.error(extractError(d, 'Delete failed.'));
            } else {
                window.location.href = '<?= base_url('inspections') ?>';
            }
        });
}
</script>

<!-- ── Page styles (S-RECORD-REDESIGN) — tokens only ─────────────────────── -->
<style>
/* Findings: the 9 sections as jump chips. */
.insp-chips {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 8px;
}
.insp-chip {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    min-width: 0;
    padding: 8px 10px;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    color: var(--text-primary);
    font-size: 12.5px;
    font-weight: 550;
    text-decoration: none;
    transition: border-color .15s ease, background .15s ease;
}
.insp-chip:hover {
    border-color: color-mix(in srgb, var(--acc, var(--color-primary)) 50%, var(--border-color));
    background: color-mix(in srgb, var(--acc, var(--color-primary)) 7%, transparent);
}
.insp-chip-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
/* Section cards are jump targets — clear the sticky topbar. */
.rec-main [id^="section-"], #insp-findings { scroll-margin-top: calc(var(--topbar-height, 60px) + 16px); }
/* Hero fact links (unit, lease) keep the chip colour. */
.ff-hero-fact a { color: inherit; text-decoration: none; }
.ff-hero-fact a:hover { text-decoration: underline; }
</style>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
