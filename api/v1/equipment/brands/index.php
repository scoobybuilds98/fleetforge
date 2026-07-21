<?php
declare(strict_types=1);

/**
 * api/v1/equipment/brands/index.php
 *
 * S-UNIT-BRAND — list equipment brands (manufacturers) for the manage screen
 * and for the brand dropdown on the unit create/edit forms. Read-only.
 *
 * `unit_count` drives the delete guard on the manage screen: a brand still on
 * units cannot be removed, only deactivated.
 *
 * @method  GET
 * @params  active_only (optional, "1") — only is_active brands, for the picker.
 *          The manage screen omits it so inactive brands stay visible/editable.
 * @auth    Session required; require_permission('equipment','view')
 * @returns 200 { brands: [ { id, slug, label, is_active, sort_order, unit_count } ] }
 *
 * @depends api/bootstrap.php
 * @session S-UNIT-BRAND
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('equipment', 'view');

$activeOnly = (string) ($_GET['active_only'] ?? '') === '1';

// Live unit usage per brand. Soft-deleted units don't count — they must not
// block retiring a brand.
$counts = [];
foreach (db_select(
    "SELECT brand_id, COUNT(*) n FROM equipment_units
      WHERE deleted_at IS NULL AND brand_id IS NOT NULL GROUP BY brand_id"
) as $r) {
    $counts[(int) $r['brand_id']] = (int) $r['n'];
}

$sql = "SELECT id, slug, label, is_active, sort_order
          FROM equipment_brands
         WHERE deleted_at IS NULL";
if ($activeOnly) {
    $sql .= " AND is_active = 1";
}
$sql .= " ORDER BY sort_order, label";

$brands = [];
foreach (db_select($sql) as $b) {
    $id = (int) $b['id'];
    $brands[] = [
        'id'         => $id,
        'slug'       => $b['slug'],
        'label'      => $b['label'],
        'is_active'  => (int) $b['is_active'],
        'sort_order' => (int) $b['sort_order'],
        'unit_count' => $counts[$id] ?? 0,
    ];
}

json_success(['brands' => $brands]);
