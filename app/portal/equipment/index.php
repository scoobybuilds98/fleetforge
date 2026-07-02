<?php
declare(strict_types=1);

/**
 * app/portal/equipment/index.php
 *
 * Portal equipment page — cards showing currently leased units.
 * Trap 8: JOIN through leases — equipment_units has no customer_id.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$cid = portal_customer_id();

// Equipment via active leases (Trap 8)
$units = db_select(
    "SELECT eu.id, eu.unit_number, eu.vin, eu.license_plate,
            eu.samsara_vehicle_url, eu.mileage,
            eu.cvi_expiry, eu.registration_expiry, eu.mvi_expiry, eu.insurance_expiry,
            et.brand, et.model, et.category, eu.year,
            l.id AS lease_id, l.contract_number, l.start_date,
            l.estimated_mileage, l.mileage_at_start, l.mileage_unit,
            eu.yard_location
     FROM equipment_units eu
     JOIN leases l ON eu.id = l.equipment_unit_id
     JOIN equipment_templates et ON et.id = eu.template_id
     WHERE l.customer_id = ? AND l.status = 'active'
     AND l.deleted_at IS NULL AND eu.deleted_at IS NULL AND et.deleted_at IS NULL
     ORDER BY eu.unit_number ASC",
    [$cid]
);

$pageTitle = 'Equipment';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<?php if (empty($units)): ?>
    <div class="portal-section">
        <div class="portal-empty" style="padding:60px 20px;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0H21M3.375 14.25V3.75h8.25m0 0h4.875c.621 0 1.125.504 1.125 1.125v4.875m-6-6v6h6"/></svg>
            <p class="portal-empty-title">No equipment currently on lease</p>
            <p class="portal-empty-text">Equipment you lease will appear here with tracking and compliance info.</p>
        </div>
    </div>
<?php else: ?>

    <div style="margin-bottom:16px;font-size:0.875rem;color:var(--text-secondary);">
        <?= e(count($units)) ?> unit<?= count($units) !== 1 ? 's' : '' ?> currently on lease
    </div>

    <div class="portal-cards-grid">
        <?php foreach ($units as $u): ?>
        <?php
            // Mileage progress
            $mileageUsed = 0;
            $mileagePct  = 0;
            $mileageClass = '';
            if ($u['estimated_mileage'] && (float)$u['estimated_mileage'] > 0) {
                $start = (float)($u['mileage_at_start'] ?? 0);
                $current = (float)($u['mileage'] ?? 0);
                $mileageUsed = max(0, $current - $start);
                $allowance = (float)$u['estimated_mileage'];
                $mileagePct = min(100, ($mileageUsed / $allowance) * 100);
                if ($mileagePct >= 95) $mileageClass = 'portal-mileage-fill--danger';
                elseif ($mileagePct >= 80) $mileageClass = 'portal-mileage-fill--warning';
            }

            // Compliance check
            $complianceItems = [];
            $today = date('Y-m-d');
            $soon  = date('Y-m-d', strtotime('+30 days'));
            foreach (['cvi_expiry', 'registration_expiry', 'mvi_expiry', 'insurance_expiry'] as $field) {
                if ($u[$field]) {
                    $label = strtoupper(str_replace('_expiry', '', $field));
                    if ($u[$field] < $today) {
                        $complianceItems[] = ['label' => $label, 'status' => 'expired', 'date' => $u[$field]];
                    } elseif ($u[$field] <= $soon) {
                        $complianceItems[] = ['label' => $label, 'status' => 'expiring', 'date' => $u[$field]];
                    }
                }
            }
        ?>
        <div class="portal-equip-card">
            <div class="portal-equip-header">
                <div>
                    <div class="portal-equip-unit"><?= e($u['unit_number']) ?></div>
                    <div class="portal-equip-type"><?= e($u['brand']) ?> <?= e($u['model']) ?> (<?= e($u['category']) ?>)</div>
                </div>
                <span class="badge badge-success">Active</span>
            </div>

            <div class="portal-equip-meta">
                <div class="portal-equip-meta-item">
                    <span class="portal-equip-meta-label">Lease</span>
                    <span class="portal-equip-meta-value">
                        <a href="<?= e(base_url('portal/leases/view?id=' . $u['lease_id'])) ?>" class="portal-table-link"><?= e($u['contract_number']) ?></a>
                    </span>
                </div>
                <div class="portal-equip-meta-item">
                    <span class="portal-equip-meta-label">Since</span>
                    <span class="portal-equip-meta-value"><?= e(format_date($u['start_date'])) ?></span>
                </div>
                <?php if ($u['yard_location']): ?>
                <div class="portal-equip-meta-item">
                    <span class="portal-equip-meta-label">Yard Location</span>
                    <span class="portal-equip-meta-value"><?= e($u['yard_location']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($u['year']): ?>
                <div class="portal-equip-meta-item">
                    <span class="portal-equip-meta-label">Year</span>
                    <span class="portal-equip-meta-value"><?= e((string)$u['year']) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Mileage Progress -->
            <?php if ($u['estimated_mileage'] && (float)$u['estimated_mileage'] > 0): ?>
            <div class="portal-mileage-bar">
                <?php $uUnit = ($u['mileage_unit'] ?? 'km') === 'miles' ? 'miles' : 'km'; ?>
                <div class="portal-mileage-header">
                    <span>Mileage: <?= e(number_format($mileageUsed)) ?> <?= e($uUnit) ?> used</span>
                    <span><?= e(number_format((float)$u['estimated_mileage'])) ?> <?= e($uUnit) ?> allowed</span>
                </div>
                <div class="portal-mileage-track">
                    <div class="portal-mileage-fill <?= e($mileageClass) ?>" style="width:<?= e(number_format($mileagePct, 1)) ?>%"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Compliance Alerts -->
            <?php foreach ($complianceItems as $ci): ?>
            <div style="margin-top:8px;padding:6px 10px;border-radius:6px;font-size:0.75rem;font-weight:500;display:flex;align-items:center;gap:6px;<?= $ci['status'] === 'expired' ? 'background:var(--badge-danger-bg);color:var(--badge-danger-text);' : 'background:var(--badge-warning-bg);color:var(--badge-warning-text);' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                <?= e($ci['label']) ?> <?= $ci['status'] === 'expired' ? 'expired' : 'expiring' ?> <?= e(format_date($ci['date'])) ?>
            </div>
            <?php endforeach; ?>

            <!-- Actions -->
            <div style="display:flex;gap:8px;margin-top:14px;">
                <?php if ($u['samsara_vehicle_url']): ?>
                    <a href="<?= e($u['samsara_vehicle_url']) ?>" target="_blank" rel="noopener" class="btn btn-primary btn-xs">Track Live</a>
                <?php endif; ?>
                <a href="<?= e(base_url('portal/leases/view?id=' . $u['lease_id'])) ?>" class="btn btn-secondary btn-xs">View Lease</a>
                <a href="<?= e(base_url('portal/requests/create?type=damage_report&equipment_id=' . $u['id'] . '&lease_id=' . $u['lease_id'])) ?>" class="btn btn-ghost btn-xs">Report Issue</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
