<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/index.php
 *
 * Paginated list of rate cards (with item counts).
 *
 * Filters: is_default, is_active (effective date range), q (name LIKE).
 * Sort allowlist: name, effective_from, effective_to, status, created_at, updated_at.
 * Default sort: effective_from DESC.
 *
 * S-RATES-MODULE (Rates home → All rate cards):
 *   status=in_force|ending|upcoming|expired  window filter (company-local today)
 *   equipment=c:{slug}|t:{template id}       cards with a line covering it
 *   scope=customer|general                   same as has_customer / customer_id=0
 *   sort=customer_name                       added; 'status' now sorts by end date
 *                                            (it named a column that does not exist)
 *   every row carries status + lines[] (label, scope) so the list can say
 *   what a card covers without a second request; include_items=1 returns
 *   every price column (hourly, GPS, minimum days, template name too).
 *
 * SOFT_DELETE: rate_cards has deleted_at — always AND rc.deleted_at IS NULL.
 * "Active" means is_default=1 OR (effective_from <= TODAY AND
 *   (effective_to IS NULL OR effective_to >= TODAY)).
 *
 * @method  GET
 * @auth    Session required; require_permission('rates','view')
 * @returns 200 json_paginated([rate_card rows], total, page, per_page)
 *
 * Decisions: D5 (soft delete), D7 (routing), §10 (list pattern)
 * Session: S019, S-RATES-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('rates', 'view');

// -----------------------------------------------------------------------
// 1. Filters
// -----------------------------------------------------------------------
$where  = ['rc.deleted_at IS NULL'];
$params = [];

// Filter: is_default
if (isset($_GET['is_default']) && $_GET['is_default'] !== '') {
    $where[]  = 'rc.is_default = ?';
    $params[] = (int)(bool)$_GET['is_default'];
}

// Filter: active — effective_from <= today AND (effective_to IS NULL OR effective_to >= today)
if (isset($_GET['active']) && $_GET['active'] !== '') {
    $today    = date('Y-m-d');
    $where[]  = 'rc.effective_from <= ?';
    $params[] = $today;
    $where[]  = '(rc.effective_to IS NULL OR rc.effective_to >= ?)';
    $params[] = $today;
}

// Filter: customer_id — '0' = global cards only, numeric = specific customer
if (isset($_GET['customer_id']) && $_GET['customer_id'] !== '') {
    $cid = clean_int($_GET['customer_id']);
    if ($cid === 0) {
        $where[] = 'rc.customer_id IS NULL';
    } else {
        $where[]  = 'rc.customer_id = ?';
        $params[] = $cid;
    }
}

// Filter: has_customer=1 — customer-specific cards only (any customer)
if (!empty($_GET['has_customer'])) {
    $where[] = 'rc.customer_id IS NOT NULL';
}

// S-RATES-MODULE: scope=customer|general
$scope = (string) ($_GET['scope'] ?? '');
if ($scope === 'customer') {
    $where[] = 'rc.customer_id IS NOT NULL';
} elseif ($scope === 'general') {
    $where[] = 'rc.customer_id IS NULL';
}

// S-RATES-MODULE: status=in_force|ending|upcoming|expired (company-local day)
$ffToday = ff_today();
$ffSoon  = (new DateTimeImmutable($ffToday))->modify('+' . \FleetForge\RateCards\RateCardItems::ENDING_SOON_DAYS . ' days')->format('Y-m-d');
switch ((string) ($_GET['status'] ?? '')) {
    case 'in_force':
        $where[]  = 'rc.effective_from <= ? AND (rc.effective_to IS NULL OR rc.effective_to >= ?)';
        array_push($params, $ffToday, $ffToday);
        break;
    case 'ending':
        $where[]  = 'rc.effective_from <= ? AND rc.effective_to IS NOT NULL AND rc.effective_to >= ? AND rc.effective_to <= ?';
        array_push($params, $ffToday, $ffToday, $ffSoon);
        break;
    case 'upcoming':
        $where[]  = 'rc.effective_from > ?';
        $params[] = $ffToday;
        break;
    case 'expired':
        $where[]  = 'rc.effective_to IS NOT NULL AND rc.effective_to < ?';
        $params[] = $ffToday;
        break;
}

// S-RATES-MODULE: equipment=c:{slug} (a line for the category or any type in
// it) | t:{template id} (a line for that type or its whole category).
$equipFilter = (string) ($_GET['equipment'] ?? '');
if (str_starts_with($equipFilter, 't:') && ($eqTid = clean_int(substr($equipFilter, 2)))) {
    $where[]  = "EXISTS (SELECT 1 FROM rate_card_items fi
                          JOIN equipment_templates ft ON ft.id = ?
                         WHERE fi.rate_card_id = rc.id
                           AND (fi.equipment_template_id = ft.id
                                OR (fi.equipment_template_id IS NULL AND fi.equipment_type = ft.category)))";
    $params[] = $eqTid;
} elseif (str_starts_with($equipFilter, 'c:') && ($eqSlug = clean_string(substr($equipFilter, 2), 50))) {
    $where[]  = 'EXISTS (SELECT 1 FROM rate_card_items fi WHERE fi.rate_card_id = rc.id AND fi.equipment_type = ?)';
    $params[] = $eqSlug;
}

// Name search
if ($q = clean_string($_GET['q'] ?? null)) {
    $like     = '%' . $q . '%';
    $where[]  = '(rc.name LIKE ? OR c.company_name LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}

// -----------------------------------------------------------------------
// 2. Sort — allowlisted
// -----------------------------------------------------------------------
// S-RATES-MODULE: mapped to real expressions — 'status' used to emit
// ORDER BY rc.status, a column rate_cards does not have (SQL error).
$sortMap = [
    'name'           => 'rc.name',
    'effective_from' => 'rc.effective_from',
    'effective_to'   => 'COALESCE(rc.effective_to, \'9999-12-31\')',
    'status'         => 'COALESCE(rc.effective_to, \'9999-12-31\')',
    'created_at'     => 'rc.created_at',
    'updated_at'     => 'rc.updated_at',
    'customer_name'  => 'COALESCE(c.company_name, \'\')',
];
$sortKey = array_key_exists($_GET['sort'] ?? '', $sortMap) ? $_GET['sort'] : 'effective_from';
$sort    = $sortMap[$sortKey];
$dir     = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

// -----------------------------------------------------------------------
// 3. Pagination
// -----------------------------------------------------------------------
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset  = ($page - 1) * $perPage;

$whereSQL = implode(' AND ', $where);

// -----------------------------------------------------------------------
// 4. Count + rows — include item_count per card
// -----------------------------------------------------------------------
$total = db_count(
    "SELECT COUNT(*) FROM rate_cards rc
     LEFT JOIN customers c ON c.id = rc.customer_id AND c.deleted_at IS NULL
     WHERE $whereSQL",
    $params
);

$rows = db_select(
    "SELECT
         rc.id, rc.name, rc.description, rc.is_default,
         rc.effective_from, rc.effective_to,
         rc.customer_id, c.company_name AS customer_name,
         rc.created_by, rc.created_at, rc.updated_at,
         (SELECT COUNT(*) FROM rate_card_items rci
          WHERE rci.rate_card_id = rc.id) AS item_count,
         u.name AS created_by_name
     FROM rate_cards rc
     LEFT JOIN customers c ON c.id = rc.customer_id AND c.deleted_at IS NULL
     LEFT JOIN users u ON u.id = rc.created_by AND u.deleted_at IS NULL
     WHERE $whereSQL
     ORDER BY $sort $dir, rc.id DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

// Add computed is_active flag for each card (useful for UI badges)
$today = date('Y-m-d');
foreach ($rows as &$row) {
    $row['is_active'] = (
        $row['effective_from'] <= $today &&
        ($row['effective_to'] === null || $row['effective_to'] >= $today)
    ) ? 1 : 0;
    // S-RATES-MODULE: active | ending | upcoming | expired
    $row['status'] = \FleetForge\RateCards\RateCardItems::status($row, $ffToday);
}
unset($row);

// S-RATES-MODULE: what each card covers, for the list's "Covers" column.
if ($rows) {
    $cover = \FleetForge\RateCards\RateInsights::itemsByCard(array_map('intval', array_column($rows, 'id')));
    foreach ($rows as &$row) {
        $row['lines'] = array_map(static function (array $it): array {
            [$label, $scopeLabel] = \FleetForge\RateCards\RateInsights::lineLabels($it);
            return ['label' => $label, 'scope' => $scopeLabel, 'issues' => count(\FleetForge\RateCards\RateInsights::lineIssues($it))];
        }, $cover[(int) $row['id']] ?? []);
    }
    unset($row);
}

// When include_items=1, nest rate_card_items within each card row so the
// customer panel can show individual items without a per-card API round-trip.
if (!empty($_GET['include_items']) && $rows) {
    $cardIds = array_column($rows, 'id');
    $in      = implode(',', array_fill(0, count($cardIds), '?'));
    $items   = db_select(
        "SELECT rci.id, rci.rate_card_id, rci.equipment_type, rci.equipment_template_id,
                et.name AS equipment_template_name,
                rci.daily_rate, rci.weekly_rate, rci.monthly_rate,
                rci.mileage_rate, rci.mileage_unit, rci.hourly_rate, rci.gps_price,
                rci.minimum_days, rci.currency, rci.notes
         FROM rate_card_items rci
         LEFT JOIN equipment_templates et ON et.id = rci.equipment_template_id
         WHERE rci.rate_card_id IN ($in)
         ORDER BY rci.rate_card_id ASC, rci.equipment_type ASC",
        $cardIds
    );
    $byCard = [];
    foreach ($items as $item) {
        $byCard[$item['rate_card_id']][] = $item;
    }
    foreach ($rows as &$row) {
        $row['items'] = $byCard[$row['id']] ?? [];
    }
    unset($row);
}

json_paginated($rows, $total, $page, $perPage);
