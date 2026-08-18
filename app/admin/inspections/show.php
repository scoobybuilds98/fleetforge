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
 * Status transitions: draft→complete, complete→signed, complete→draft (reopen, manager only).
 * "Create Damage Claim" button visible on complete/signed inspections.
 * Photo upload per section via raw fetch() + FormData (FF_Api.post does not support multipart).
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/inspections/{show,update,update_status,delete}.php
 *           api/v1/inspections/sections/update.php
 *           api/v1/inspections/photos/{upload,delete}.php
 * @decisions D7/D19/D30/D32, Trap 5 (MIME), Trap 7 (no file_path in API)
 * @session  S016
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
        i.*, eu.unit_number, eb.label AS brand, et.model, et.category AS unit_type,
        l.contract_number, c.company_name AS customer_name,
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

$pageTitle = $insp['inspection_number'] ?? ('Inspection #' . $id);
$helpModuleSlug = 'inspections';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ── Hero header ──────────────────────────────────────────────────────── -->
<div class="page-header" style="align-items:flex-start;gap:16px;flex-wrap:wrap;">
    <div>
        <a href="<?= base_url('inspections') ?>" class="btn btn-secondary btn-sm">Back</a>
    </div>
    <div style="flex:1;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <h1 class="page-header-title" style="margin:0;">
                <?= e($insp['inspection_number'] ?? ('Inspection #' . $id)) ?>
            </h1>
            <?php
            $statusBadge = ['draft' => 'badge-warning', 'complete' => 'badge-info', 'signed' => 'badge-success'];
            $statusLabel = ['draft' => 'Draft', 'complete' => 'Complete', 'signed' => 'Signed'];
            ?>
            <span class="badge <?= $statusBadge[$insp['status']] ?? 'badge-neutral' ?>">
                <?= $statusLabel[$insp['status']] ?? e($insp['status']) ?>
            </span>
            <?php
            $typeBadge = ['pre_lease'=>'badge-info','post_lease'=>'badge-warning','periodic'=>'badge-neutral','damage'=>'badge-danger','compliance'=>'badge-success'];
            $typeLabel = ['pre_lease'=>'Pre-Lease','post_lease'=>'Post-Lease','periodic'=>'Periodic','damage'=>'Damage','compliance'=>'Compliance'];
            ?>
            <span class="badge <?= $typeBadge[$insp['inspection_type']] ?? 'badge-neutral' ?>">
                <?= $typeLabel[$insp['inspection_type']] ?? e($insp['inspection_type']) ?>
            </span>
        </div>
        <div style="margin-top:6px;font-size:0.875rem;color:var(--text-secondary);display:flex;gap:16px;flex-wrap:wrap;">
            <span>Unit: <strong><?= e($insp['unit_number']) ?></strong><?= $insp['brand'] ? ' — ' . e($insp['brand']) . ' ' . e($insp['model']) : '' ?></span>
            <?php if ($insp['contract_number']): ?>
            <span>Lease: <a href="<?= base_url('leases/show') ?>?id=<?= (int)$insp['lease_id'] ?>" class="link"><?= e($insp['contract_number']) ?></a></span>
            <?php endif; ?>
            <?php if ($insp['customer_name']): ?>
            <span>Customer: <?= e($insp['customer_name']) ?></span>
            <?php endif; ?>
            <span>Date: <?= e(format_date($insp['inspection_date'])) ?></span>
            <?php if ($insp['inspected_by']): ?>
            <span>Inspector: <?= e($insp['inspected_by']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <!-- Action buttons -->
    <div class="page-header-actions">
        <?= help_button('inspections') ?>
        <?php if ($insp['status'] === 'complete' && can('inspections', 'create')): ?>
        <a href="<?= base_url('damage_claims/create') ?>?inspection_id=<?= (int)$id ?>&unit_id=<?= (int)$insp['equipment_unit_id'] ?><?= $insp['lease_id'] ? '&lease_id=' . (int)$insp['lease_id'] : '' ?>"
           class="btn btn-danger btn-sm">+ Create Damage Claim</a>
        <?php endif; ?>
        <?php if ($insp['status'] === 'signed' && can('inspections', 'create')): ?>
        <a href="<?= base_url('damage_claims/create') ?>?inspection_id=<?= (int)$id ?>&unit_id=<?= (int)$insp['equipment_unit_id'] ?><?= $insp['lease_id'] ? '&lease_id=' . (int)$insp['lease_id'] : '' ?>"
           class="btn btn-danger btn-sm">+ Create Damage Claim</a>
        <?php endif; ?>
        <?php if ($canDelete && $insp['status'] === 'draft'): ?>
        <button class="btn btn-secondary btn-sm" onclick="deleteInspection()">Delete</button>
        <?php endif; ?>
    </div>
</div>

<!-- ── Header KPI tiles ──────────────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-label">Overall Condition</div>
        <div class="stat-value"><?= $insp['overall_condition'] ? e(ucfirst($insp['overall_condition'])) : '—' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Mileage at Inspection</div>
        <div class="stat-value font-mono"><?= $insp['mileage_at_inspection'] ? e(number_format((int)$insp['mileage_at_inspection'])) : '—' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Reefer Hours</div>
        <div class="stat-value font-mono"><?= $insp['reefer_hours'] !== null ? e(number_format((int)$insp['reefer_hours'])) : '—' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Fuel Level</div>
        <div class="stat-value">
            <?php
            $fuelLabels = ['empty'=>'Empty','quarter'=>'1/4','half'=>'1/2','three_quarter'=>'3/4','full'=>'Full'];
            echo $insp['fuel_level'] ? e($fuelLabels[$insp['fuel_level']] ?? $insp['fuel_level']) : '—';
            ?>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-label">CVI Expiry</div>
        <div class="stat-value font-mono"><?= $insp['cvi_expiry'] ? e(format_date($insp['cvi_expiry'])) : '—' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Cleanliness</div>
        <div class="stat-value">
            <?php if ($insp['is_clean'] === null): ?>—
            <?php elseif ((int)$insp['is_clean'] === 1): ?>
                <span class="badge badge-success">Clean</span>
            <?php else: ?>
                <span class="badge badge-warning">Dirty</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Status transition buttons ─────────────────────────────────────────── -->
<?php if (can('inspections', 'edit')): ?>
<div class="card" style="margin-bottom:24px;">
    <div class="card-body" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <span class="text-secondary" style="font-size:0.875rem;">Status transitions:</span>

        <?php if ($insp['status'] === 'draft'): ?>
        <button class="btn btn-primary btn-sm"
                onclick="transitionStatus('complete')">
            Mark Complete
        </button>

        <?php elseif ($insp['status'] === 'complete'): ?>
        <button class="btn btn-success btn-sm"
                onclick="transitionStatus('signed')">
            Sign Off
        </button>
        <?php if (can('inspections', 'settings')): ?>
        <button class="btn btn-secondary btn-sm"
                onclick="transitionStatus('draft')">
            Re-open (Draft)
        </button>
        <?php endif; ?>

        <?php elseif ($insp['status'] === 'signed'): ?>
        <span class="badge badge-success">Signed <?= $insp['signed_at'] ? '— ' . e(format_datetime($insp['signed_at'])) : '' ?></span>
        <?php endif; ?>

        <span id="status-msg" style="font-size:0.875rem;"></span>
    </div>
</div>
<?php endif; ?>

<!-- ── Sections ───────────────────────────────────────────────────────────── -->
<?php foreach ($sections as $sec):
    $secId     = (int)$sec['id'];
    $isTires   = $sec['section_name'] === 'Tires';
    $isTrailer = $sec['section_name'] === 'Trailer Condition';
    $secPhotos = $photosBySec[$secId] ?? [];
    $condLabels = ['ok'=>'OK','fair'=>'Fair','damaged'=>'Damaged','missing'=>'Missing','na'=>'N/A'];
    $condBadge  = ['ok'=>'badge-success','fair'=>'badge-warning','damaged'=>'badge-danger','missing'=>'badge-danger','na'=>'badge-neutral'];
?>
<div class="card" style="margin-bottom:20px;" id="section-<?= $secId ?>">
    <div class="card-header" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;font-size:1rem;font-weight:600;"><?= e($sec['section_name']) ?></h3>
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

<!-- ── General notes + delete ────────────────────────────────────────────── -->
<?php if ($insp['notes']): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h3 style="margin:0;font-size:1rem;">General Notes</h3></div>
    <div class="card-body"><p style="white-space:pre-wrap;"><?= e($insp['notes']) ?></p></div>
</div>
<?php endif; ?>

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

<!-- ── Activity Log ───────────────────────────────────────────── -->
<div class="card" style="margin-top:24px;">
    <div class="card-header"><h3 class="card-title">Activity</h3></div>
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

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
