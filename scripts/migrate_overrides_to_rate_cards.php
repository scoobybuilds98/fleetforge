<?php
declare(strict_types=1);

/**
 * scripts/migrate_overrides_to_rate_cards.php
 *
 * S-RATES-CONSOLIDATE — fold customer_equipment_rates ("overrides") into
 * customer-specific rate cards, so rate cards become the single per-customer
 * pricing mechanism.
 *
 * WHY: a customer-specific rate card already captures customer + equipment
 * type + rates, making the separate per-type override table redundant. This
 * converts every existing override into a rate_card_items row on a single
 * customer-owned rate card, after which lookup_rates.php no longer consults
 * the override table. The override table + history are LEFT INTACT (the
 * consolidation is reversible until a later cleanup migration drops them).
 *
 * For each customer that has overrides:
 *   1. Find an existing non-deleted customer-specific rate card, or create
 *      "<Company> — Custom Rates" (effective_from = earliest override date).
 *   2. For each override, map equipment_type → category slug:
 *        - already a known category slug → use as-is
 *        - else match equipment_templates.name → its category
 *        - else SKIP and report (cannot map safely)
 *   3. Insert a rate_card_items row for (card, category) IF one does not
 *      already exist — never clobber a manually-entered card item.
 *
 * Idempotent: re-running adds nothing already present. Safe to run twice.
 *
 * Usage:
 *   php scripts/migrate_overrides_to_rate_cards.php --dry-run   # preview only
 *   php scripts/migrate_overrides_to_rate_cards.php             # apply
 *
 * @session S-RATES-CONSOLIDATE
 * @decisions D5 (rate_cards soft delete), D16 (decimal strings)
 */

require_once dirname(__DIR__) . '/config/app.php';

$dryRun = in_array('--dry-run', $argv, true);
$mode   = $dryRun ? 'DRY-RUN (no writes)' : 'APPLY';
fwrite(STDOUT, "migrate_overrides_to_rate_cards — {$mode}\n\n");

// Known category slugs (rate_card_items.equipment_type domain).
$KNOWN_CATEGORIES = [
    'chassis', 'dry_van', 'reefer', 'container', 'flatbed',
    'step_deck', 'lowboy', 'tanker', 'dump', 'other',
];

// name → category map from equipment_templates (for overrides keyed by name).
$nameToCategory = [];
foreach (db_select("SELECT name, category FROM equipment_templates") as $t) {
    $nameToCategory[$t['name']] = $t['category'];
}

/** Resolve an override's equipment_type to a category slug, or null. */
$resolveCategory = static function (string $raw) use ($KNOWN_CATEGORIES, $nameToCategory): ?string {
    if (in_array($raw, $KNOWN_CATEGORIES, true)) return $raw;       // already a slug
    if (isset($nameToCategory[$raw]))            return $nameToCategory[$raw]; // name → slug
    return null;                                                    // unmappable
};

// Customers that have at least one override.
$customerIds = array_column(
    db_select("SELECT DISTINCT customer_id FROM customer_equipment_rates ORDER BY customer_id"),
    'customer_id'
);

if (!$customerIds) {
    fwrite(STDOUT, "No overrides found — nothing to migrate.\n");
    exit(0);
}

$report = ['cards_created' => 0, 'cards_reused' => 0, 'items_inserted' => 0, 'items_skipped' => 0, 'unmapped' => []];

foreach ($customerIds as $cid) {
    $customer = db_row("SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL", [$cid]);
    if (!$customer) {
        fwrite(STDOUT, "  customer #{$cid}: not found / soft-deleted — skipping its overrides\n");
        continue;
    }
    $company   = $customer['company_name'];
    $overrides = db_select("SELECT * FROM customer_equipment_rates WHERE customer_id = ? ORDER BY equipment_type", [$cid]);

    // 1. Find or create the customer-specific rate card.
    $card = db_row(
        "SELECT id, name FROM rate_cards
         WHERE customer_id = ? AND deleted_at IS NULL
         ORDER BY id ASC LIMIT 1",
        [$cid]
    );

    if ($card) {
        $cardId = (int)$card['id'];
        $report['cards_reused']++;
        fwrite(STDOUT, "  {$company} (#{$cid}): reuse existing card “{$card['name']}” (#{$cardId})\n");
    } else {
        $minRow   = db_row("SELECT MIN(effective_from) AS m FROM customer_equipment_rates WHERE customer_id = ?", [$cid]);
        $effFrom  = $minRow['m'] ?? date('Y-m-d');
        $cardName = $company . ' — Custom Rates';

        fwrite(STDOUT, "  {$company} (#{$cid}): create card “{$cardName}” (effective {$effFrom})\n");
        if ($dryRun) {
            $cardId = -1; // placeholder for dry-run item preview
        } else {
            $cardId = db_insert('rate_cards', [
                'name'           => $cardName,
                'customer_id'    => $cid,
                'is_default'     => 0,
                'effective_from' => $effFrom,
                'effective_to'   => null,
                'created_by'     => null,
            ]);
        }
        $report['cards_created']++;
    }

    // 2 + 3. Convert each override to a rate_card_items row (if absent).
    foreach ($overrides as $ov) {
        $cat = $resolveCategory((string)$ov['equipment_type']);
        if ($cat === null) {
            $report['unmapped'][] = "{$company}: '{$ov['equipment_type']}'";
            fwrite(STDOUT, "      ! cannot map '{$ov['equipment_type']}' to a category — skipped\n");
            continue;
        }

        // Skip if this card already covers the category (never clobber).
        $exists = $cardId > 0 && db_exists('rate_card_items', 'rate_card_id = ? AND equipment_type = ?', [$cardId, $cat]);
        if ($exists) {
            $report['items_skipped']++;
            fwrite(STDOUT, "      = {$cat}: already on card — skipped\n");
            continue;
        }

        fwrite(STDOUT, "      + {$cat}: daily={$ov['daily_rate']} weekly={$ov['weekly_rate']} monthly={$ov['monthly_rate']} mileage={$ov['mileage_rate']}\n");
        $report['items_inserted']++;
        if (!$dryRun && $cardId > 0) {
            db_insert('rate_card_items', [
                'rate_card_id'  => $cardId,
                'equipment_type'=> $cat,
                'daily_rate'    => $ov['daily_rate'],
                'weekly_rate'   => $ov['weekly_rate'],
                'monthly_rate'  => $ov['monthly_rate'],
                'mileage_rate'  => $ov['mileage_rate'],
                'mileage_unit'  => $ov['mileage_unit'] ?: 'km',
                'currency'      => $ov['currency'] ?: 'CAD',
                'notes'         => $ov['notes'],
            ]);
        }
    }
}

// ── Summary ─────────────────────────────────────────────────────────────────
fwrite(STDOUT, "\nSummary ({$mode}):\n");
fwrite(STDOUT, "  cards created : {$report['cards_created']}\n");
fwrite(STDOUT, "  cards reused  : {$report['cards_reused']}\n");
fwrite(STDOUT, "  items inserted: {$report['items_inserted']}\n");
fwrite(STDOUT, "  items skipped : {$report['items_skipped']} (already present)\n");
if ($report['unmapped']) {
    fwrite(STDOUT, "  UNMAPPED (left as overrides, need manual attention):\n");
    foreach (array_unique($report['unmapped']) as $u) fwrite(STDOUT, "    - {$u}\n");
}
fwrite(STDOUT, "\nOverride table left intact (reversible). " . ($dryRun ? "Re-run without --dry-run to apply.\n" : "Done.\n"));
