<?php
declare(strict_types=1);

/**
 * app/portal/equipment/index.php
 *
 * Customer portal — Equipment on rent (S-PORTAL-REDESIGN).
 *
 * A card per unit currently on an active lease: type, model/year, plate,
 * VIN, which lease it's on and since when, and its paperwork status
 * (CVI + registration — MVI/insurance are no longer tracked,
 * S-UNIT-COMPLIANCE-HIDE-MVI-INS). Actions: track live (Samsara share
 * link), open the lease, report a problem, ask for the unit's paperwork.
 *
 * Dropped: the "mileage allowance" bar. It compared the unit's raw odometer
 * with the lease's estimate in possibly different units, and the allowance
 * model it described was retired (D154/D167) — mileage now lives on the
 * lease page, from invoiced distance.
 *
 * Trap 8: units reached only through the customer's active leases.
 *
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

$cid   = portal_customer_id();
$today = ff_today();
$soon  = ff_local_date_add($today, 30);

$units = db_select(
    "SELECT eu.id, eu.unit_number, eu.vin, eu.license_plate, eu.samsara_vehicle_url,
            eu.cvi_expiry, eu.registration_expiry, eu.year, eu.yard_location,
            eb.label AS brand, et.model, et.category, et.name AS type_name,
            l.id AS lease_id, l.contract_number, l.start_date, l.end_date
       FROM equipment_units eu
       JOIN leases l ON eu.id = l.equipment_unit_id
       JOIN equipment_templates et ON et.id = eu.template_id
       LEFT JOIN equipment_brands eb ON eb.id = eu.brand_id
      WHERE l.customer_id = ? AND l.status = 'active'
        AND l.deleted_at IS NULL AND eu.deleted_at IS NULL AND et.deleted_at IS NULL
      ORDER BY eu.unit_number ASC",
    [$cid]
);

$attentionCount = 0;
foreach ($units as &$u) {
    $u['papers'] = [];
    foreach (['cvi_expiry' => 'CVI', 'registration_expiry' => 'Registration'] as $col => $label) {
        $d = $u[$col] ?? null;
        if (!$d) continue;
        $tone = $d < $today ? 'danger' : ($d <= $soon ? 'warning' : 'success');
        $u['papers'][] = [$label, $d, $tone];
        if ($tone !== 'success') $attentionCount++;
    }
}
unset($u);

$pageTitle = 'Equipment';
require_once dirname(__DIR__) . '/includes/header.php';

echo pt_page_head([
    'eyebrow' => 'Fleet',
    'title'   => 'Equipment on rent',
    'sub'     => count($units)
        ? count($units) . ' unit' . (count($units) === 1 ? '' : 's') . ' with you right now' . ($attentionCount ? ' · ' . $attentionCount . ' paperwork item' . ($attentionCount === 1 ? '' : 's') . ' to check' : ' · all paperwork current')
        : 'Units you rent from us show up here with their paperwork and tracking.',
    'actions' => '<a class="pt-btn pt-btn--primary" href="' . e(pt_url('requests/create?type=new_lease_inquiry')) . '">' . pt_icon('plus') . ' Rent more</a>',
]);
?>

<?php if (!$units): ?>
    <div class="pt-card"><?= pt_empty('truck', 'No equipment on rent', 'Need a trailer, chassis or truck? We\'ll put a quote together.', '<a class="pt-btn pt-btn--soft pt-btn--sm" href="' . e(pt_url('requests/create?type=new_lease_inquiry')) . '">Request a quote</a>') ?></div>
<?php else: ?>
    <div class="pt-units">
        <?php foreach ($units as $u):
            $type = trim(($u['brand'] ? $u['brand'] . ' ' : '') . ($u['type_name'] ?: ucfirst((string) $u['category'])));
            $days = max(1, pt_days_between($u['start_date'], $today) + 1);
        ?>
            <article class="pt-unit">
                <div class="pt-unit-top">
                    <span class="pt-unit-art"><?= pt_icon('truck') ?></span>
                    <div style="min-width:0">
                        <div class="pt-unit-id"><?= e($u['unit_number']) ?></div>
                        <div class="pt-unit-type"><?= e($type) ?><?= $u['year'] ? ' · ' . e((string) $u['year']) : '' ?></div>
                    </div>
                    <span class="pt-unit-status"><?= pt_badge('On rent', 'success') ?></span>
                </div>
                <div class="pt-unit-facts">
                    <div class="pt-unit-fact"><div class="pt-unit-fact-k">Lease</div><div class="pt-unit-fact-v"><a class="pt-link" href="<?= e(pt_url('leases/view?id=' . (int) $u['lease_id'])) ?>"><?= e($u['contract_number']) ?></a></div></div>
                    <div class="pt-unit-fact"><div class="pt-unit-fact-k">On rent</div><div class="pt-unit-fact-v"><?= number_format($days) ?> day<?= $days === 1 ? '' : 's' ?></div></div>
                    <div class="pt-unit-fact"><div class="pt-unit-fact-k">Plate</div><div class="pt-unit-fact-v"><?= e($u['license_plate'] ?: '—') ?></div></div>
                    <div class="pt-unit-fact"><div class="pt-unit-fact-k">VIN</div><div class="pt-unit-fact-v pt-mono" title="<?= e((string) $u['vin']) ?>" style="font-size:12.5px"><?= e($u['vin'] ? '…' . substr((string) $u['vin'], -8) : '—') ?></div></div>
                </div>
                <?php if ($u['papers']): ?>
                    <div class="pt-unit-body" style="display:flex;flex-wrap:wrap;gap:6px">
                        <?php foreach ($u['papers'] as [$label, $date, $tone]): ?>
                            <?= pt_badge($label . ($tone === 'danger' ? ' expired ' : ($tone === 'warning' ? ' expires ' : ' to ')) . format_date($date), $tone) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="pt-unit-foot">
                    <?php if (!empty($u['samsara_vehicle_url'])): ?>
                        <a class="pt-btn pt-btn--soft pt-btn--sm" href="<?= e($u['samsara_vehicle_url']) ?>" target="_blank" rel="noopener"><?= pt_icon('map-pin') ?> Track live</a>
                    <?php endif; ?>
                    <a class="pt-btn pt-btn--ghost pt-btn--sm" href="<?= e(pt_url('requests/create?type=damage_report&lease_id=' . (int) $u['lease_id'] . '&equipment_id=' . (int) $u['id'])) ?>"><?= pt_icon('wrench-screwdriver') ?> Report a problem</a>
                    <a class="pt-btn pt-btn--ghost pt-btn--sm" href="<?= e(pt_url('requests/create?type=document_request&lease_id=' . (int) $u['lease_id'] . '&equipment_id=' . (int) $u['id'] . '&subject=' . rawurlencode('Paperwork for unit ' . $u['unit_number']))) ?>"><?= pt_icon('document-text') ?> Paperwork</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
