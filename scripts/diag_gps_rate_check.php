<?php
/**
 * scripts/diag_gps_rate_check.php
 *
 * Diagnostic: check GPS rate card setup for a specific customer and equipment.
 * Simulates exactly what lookup_rates.php does.
 *
 * USAGE:
 *   php scripts/diag_gps_rate_check.php [customer_name] [equipment_type]
 *
 * Examples:
 *   php scripts/diag_gps_rate_check.php aquatrans
 *   php scripts/diag_gps_rate_check.php aquatrans dry_van
 *
 * Run on prod: ssh fleetforge 'cd /var/www/fleetforge && php scripts/diag_gps_rate_check.php aquatrans'
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/app.php';

$customerSearch  = strtolower($argv[1] ?? '');
$equipTypeFilter = strtolower($argv[2] ?? '');
$today           = date('Y-m-d');

// ── 1. Find customer(s) matching the search term ──────────────────────────
$customers = db_select(
    "SELECT id, company_name FROM customers
     WHERE deleted_at IS NULL
       AND LOWER(company_name) LIKE ?
     ORDER BY company_name",
    ["%{$customerSearch}%"]
);

if (empty($customers)) {
    echo "No customers found matching '{$customerSearch}'\n";
    exit(1);
}

echo "=== CUSTOMER MATCHES ===\n";
foreach ($customers as $c) {
    echo "  [{$c['id']}] {$c['company_name']}\n";
}
echo "\n";

foreach ($customers as $cust) {
    $custId = $cust['id'];
    echo "=== Customer: {$cust['company_name']} (id={$custId}) ===\n\n";

    // ── 2. Rate cards for this customer ──────────────────────────────────
    $cards = db_select(
        "SELECT rc.id, rc.name, rc.is_default, rc.effective_from, rc.effective_to, rc.customer_id,
                CASE
                    WHEN rc.effective_from > ? THEN 'future'
                    WHEN rc.effective_to IS NOT NULL AND rc.effective_to < ? THEN 'EXPIRED'
                    ELSE 'active'
                END AS status
         FROM rate_cards rc
         WHERE rc.deleted_at IS NULL
           AND rc.customer_id = ?
         ORDER BY rc.effective_from DESC",
        [$today, $today, $custId]
    );

    echo "--- Customer-Specific Rate Cards ---\n";
    if (empty($cards)) {
        echo "  (none — only global rate cards will match)\n";
    } else {
        foreach ($cards as $rc) {
            $flag = $rc['status'] === 'EXPIRED' ? ' *** EXPIRED — lookup will skip this! ***' : '';
            $eff_to = $rc['effective_to'] ?? 'no end';
            echo "  [{$rc['id']}] {$rc['name']} | default={$rc['is_default']} | {$rc['effective_from']}..{$eff_to} | {$rc['status']}{$flag}\n";
        }
    }

    // Also global cards
    $globalCards = db_select(
        "SELECT rc.id, rc.name, rc.is_default, rc.effective_from, rc.effective_to,
                CASE
                    WHEN rc.effective_from > ? THEN 'future'
                    WHEN rc.effective_to IS NOT NULL AND rc.effective_to < ? THEN 'EXPIRED'
                    ELSE 'active'
                END AS status
         FROM rate_cards rc
         WHERE rc.deleted_at IS NULL
           AND rc.customer_id IS NULL
         ORDER BY rc.is_default DESC, rc.effective_from DESC",
        [$today, $today]
    );
    echo "\n--- Global Rate Cards (fallback) ---\n";
    foreach ($globalCards as $rc) {
        $flag   = $rc['status'] === 'EXPIRED' ? ' *** EXPIRED ***' : '';
        $eff_to = $rc['effective_to'] ?? 'no end';
        echo "  [{$rc['id']}] {$rc['name']} | default={$rc['is_default']} | {$rc['effective_from']}..{$eff_to} | {$rc['status']}{$flag}\n";
    }

    // ── 3. Rate card items with gps_price for this customer's cards ───────
    echo "\n--- Rate Card Items (gps_price column) ---\n";
    $allCardIds = array_merge(
        array_column($cards, 'id'),
        array_column($globalCards, 'id')
    );
    if (!empty($allCardIds)) {
        $placeholders = implode(',', array_fill(0, count($allCardIds), '?'));
        $items = db_select(
            "SELECT rci.id, rci.rate_card_id, rc.name AS card_name, rc.customer_id,
                    rci.equipment_type, rci.equipment_template_id,
                    et.name AS template_name,
                    rci.daily_rate, rci.weekly_rate, rci.monthly_rate,
                    rci.hourly_rate, rci.gps_price
             FROM rate_card_items rci
             JOIN rate_cards rc ON rc.id = rci.rate_card_id
             LEFT JOIN equipment_templates et ON et.id = rci.equipment_template_id
             WHERE rci.rate_card_id IN ({$placeholders})
             ORDER BY rc.customer_id IS NOT NULL DESC, rci.equipment_type ASC",
            $allCardIds
        );

        if (empty($items)) {
            echo "  NO ITEMS FOUND — no rates will be looked up for this customer!\n";
            echo "  ACTION NEEDED: Go to the rate card UI and add items with equipment types.\n";
        } else {
            foreach ($items as $i) {
                if ($equipTypeFilter && strpos($i['equipment_type'], $equipTypeFilter) === false) continue;
                $gpsDisplay  = $i['gps_price'] !== null ? '$' . number_format((float)$i['gps_price'], 2) . '/day' : 'NULL (no GPS set)';
                $tmplDisplay = $i['equipment_template_id'] ? "tmpl={$i['equipment_template_id']}({$i['template_name']})" : 'category-level';
                $custMarker  = $i['customer_id'] ? '[CUSTOMER-SPECIFIC]' : '[GLOBAL]';
                echo "  [{$i['id']}] card={$i['rate_card_id']}({$i['card_name']}) {$custMarker} | type={$i['equipment_type']} | {$tmplDisplay} | gps={$gpsDisplay}\n";
            }
        }
    }

    // ── 4. Simulate lookup_rates.php for each equipment template the customer has active leases on ──
    echo "\n--- Simulated lookup_rates.php (active leases) ---\n";
    $activeLeases = db_select(
        "SELECT l.id, l.equipment_unit_id, eu.unit_number, eu.template_id,
                et.name AS template_name, et.category
         FROM leases l
         JOIN equipment_units eu ON eu.id = l.equipment_unit_id
         JOIN equipment_templates et ON et.id = eu.template_id
         WHERE l.customer_id = ? AND l.status = 'active' AND l.deleted_at IS NULL
         LIMIT 5",
        [$custId]
    );

    if (empty($activeLeases)) {
        echo "  (no active leases — run with a template_id to simulate)\n";
    } else {
        foreach ($activeLeases as $l) {
            $equipType = $l['category'];
            $tmplId    = $l['template_id'];

            $found = db_row(
                "SELECT rci.daily_rate, rci.gps_price, rc.name AS card_name,
                        rc.customer_id, rci.equipment_template_id
                 FROM rate_card_items rci
                 JOIN rate_cards rc ON rc.id = rci.rate_card_id
                 WHERE (
                     (rci.equipment_template_id = ? AND rci.equipment_type = ?)
                     OR
                     (rci.equipment_template_id IS NULL AND rci.equipment_type = ?)
                 )
                   AND rc.deleted_at IS NULL
                   AND rc.effective_from <= ?
                   AND (rc.effective_to IS NULL OR rc.effective_to >= ?)
                   AND (rc.customer_id = ? OR rc.customer_id IS NULL)
                 ORDER BY
                     (rc.customer_id IS NOT NULL) DESC,
                     (rci.equipment_template_id IS NOT NULL) DESC,
                     rc.is_default DESC,
                     rc.effective_from DESC
                 LIMIT 1",
                [$tmplId, $equipType, $equipType, $today, $today, $custId]
            );

            if (!$found) {
                echo "  Lease #{$l['id']} unit={$l['unit_number']} type={$equipType}: NO RATE CARD MATCH — lookup falls to template defaults\n";
            } else {
                $gps = $found['gps_price'] !== null ? '$' . number_format((float)$found['gps_price'], 2) . '/day' : 'NULL (no GPS)';
                $src = $found['customer_id'] ? 'customer-specific' : 'global';
                $tmplSpec = $found['equipment_template_id'] ? 'template-specific' : 'category-level';
                echo "  Lease #{$l['id']} unit={$l['unit_number']} type={$equipType}: matched card='{$found['card_name']}' ({$src}, {$tmplSpec}) | gps={$gps}\n";
                if ($found['gps_price'] === null) {
                    echo "    *** GPS IS NULL on winning item — check if another item has GPS but lower priority ***\n";
                }
            }
        }
    }
    echo "\n";
}
