<?php
declare(strict_types=1);

/**
 * scripts/backfill_equipment_taxonomy.php
 *
 * S-EQTAX companion data backfill (run ONCE per environment after the
 * 202606260001_S-EQTAX-1 migration; idempotent — safe to re-run).
 *
 * What it does, inside one transaction:
 *   1. Reconciles equipment_categories.enforce_minimum_billing_days from the
 *      live 'lease.minimum_billing_days_categories' setting (so an operator who
 *      had tuned it keeps their choice). The legacy 'combo' token maps to the
 *      Chassis category, since Combo is now a Chassis sub-type.
 *   2. Derives one sub-category per existing template name under that template's
 *      category, and populates equipment_templates.category_id / subcategory_id.
 *   3. Maps combo templates to Chassis + a single shared "Combo" sub-category.
 *
 * It deliberately does NOT rewrite equipment_templates.category (the legacy
 * "type slug" mirror) — existing reports/rate-matching stay byte-identical, and
 * Combo keeps its own line until the operator moves it from the manage screen.
 *
 * Usage:  php scripts/backfill_equipment_taxonomy.php            (apply)
 *         php scripts/backfill_equipment_taxonomy.php --dry-run  (report only)
 *
 * @depends config/app.php, includes/db.php, db_migrations/202606260001_S-EQTAX-1
 * @session S-EQTAX-1
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';

$dryRun = in_array('--dry-run', $argv, true);

/** Slugify a free-text equipment-type name into a stable <=50-char slug. */
function eqtax_slug(string $name): string
{
    $s = strtolower(trim($name));
    $s = str_replace('&', ' and ', $s);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
    $s = trim($s, '_');
    if ($s === '') {
        $s = 'type';
    }
    return substr($s, 0, 50);
}

/**
 * Find-or-create a sub-category under a category by slug (idempotent).
 * Returns its id. Honors a within-category slug collision by suffixing.
 */
function eqtax_ensure_subcategory(int $categoryId, string $slug, string $label, int $sort, bool $dryRun): ?int
{
    // Existing (including soft-deleted — reuse, don't duplicate the unique key).
    $existing = db_row(
        "SELECT id FROM equipment_subcategories WHERE category_id = ? AND slug = ? LIMIT 1",
        [$categoryId, $slug]
    );
    if ($existing) {
        return (int) $existing['id'];
    }
    if ($dryRun) {
        return null; // unknown id in dry-run
    }
    return db_insert('equipment_subcategories', [
        'category_id' => $categoryId,
        'slug'        => $slug,
        'label'       => $label,
        'sort_order'  => $sort,
    ]);
}

$summary = ['enforce_set' => [], 'templates' => 0, 'subcats_created' => 0, 'combo_mapped' => 0, 'skipped' => []];

$run = function () use ($dryRun, &$summary) {
    // ── 1. Reconcile enforce-minimum flags from the live setting ────────────
    $raw = (string) settings_get('lease.minimum_billing_days_categories', 'chassis');
    $tokens = array_filter(array_map(static fn ($t) => strtolower(trim($t)), explode(',', $raw)), static fn ($t) => $t !== '');
    foreach ($tokens as $tok) {
        // Combo folds into Chassis (Combo is a Chassis sub-type now).
        $targetSlug = ($tok === 'combo') ? 'chassis' : $tok;
        $cat = db_row("SELECT id FROM equipment_categories WHERE slug = ? LIMIT 1", [$targetSlug]);
        if (!$cat) {
            $summary['skipped'][] = "enforce token '{$tok}' has no category";
            continue;
        }
        if (!$dryRun) {
            db_execute("UPDATE equipment_categories SET enforce_minimum_billing_days = 1 WHERE id = ?", [(int) $cat['id']]);
        }
        $summary['enforce_set'][] = $targetSlug;
    }

    // ── 2/3. Backfill template FKs + derive sub-categories ──────────────────
    $templates = db_select(
        "SELECT id, name, category, category_id, subcategory_id
           FROM equipment_templates
          WHERE deleted_at IS NULL
          ORDER BY category, name"
    );

    // Cache category id by slug.
    $catBySlug = [];
    foreach (db_select("SELECT id, slug FROM equipment_categories") as $c) {
        $catBySlug[$c['slug']] = (int) $c['id'];
    }

    $sortByCat = []; // running sort_order per category

    foreach ($templates as $t) {
        $legacy = (string) $t['category'];

        // Combo -> Chassis category + a single shared "Combo" sub-category.
        if ($legacy === 'combo') {
            $catId = $catBySlug['chassis'] ?? null;
            if ($catId === null) { $summary['skipped'][] = "template {$t['id']}: no chassis category"; continue; }
            $subId = eqtax_ensure_subcategory($catId, 'combo', 'Combo', 5, $dryRun);
            $summary['combo_mapped']++;
        } else {
            $catId = $catBySlug[$legacy] ?? null;
            if ($catId === null) { $summary['skipped'][] = "template {$t['id']}: category slug '{$legacy}' not seeded"; continue; }
            $slug  = eqtax_slug((string) $t['name']);
            $sortByCat[$catId] = ($sortByCat[$catId] ?? 0) + 10;
            $subId = eqtax_ensure_subcategory($catId, $slug, (string) $t['name'], $sortByCat[$catId], $dryRun);
        }

        if (!$dryRun) {
            db_execute(
                "UPDATE equipment_templates SET category_id = ?, subcategory_id = ? WHERE id = ?",
                [$catId, $subId, (int) $t['id']]
            );
        }
        $summary['templates']++;
    }

    // Recount sub-categories actually present (post-run truth).
    $summary['subcats_total'] = db_count("SELECT COUNT(*) FROM equipment_subcategories WHERE deleted_at IS NULL");
};

if ($dryRun) {
    $run();
} else {
    db_transaction($run);
}

// ── Verification ────────────────────────────────────────────────────────────
$nullCat   = db_count("SELECT COUNT(*) FROM equipment_templates WHERE deleted_at IS NULL AND category_id IS NULL");
$comboLeft = db_count("SELECT COUNT(*) FROM equipment_templates WHERE deleted_at IS NULL AND category = 'combo' AND category_id IS NULL");

echo ($dryRun ? "[DRY-RUN] " : "[APPLIED] ") . "S-EQTAX taxonomy backfill\n";
echo "  enforce-min categories : " . implode(', ', array_unique($summary['enforce_set'])) . "\n";
echo "  templates backfilled   : {$summary['templates']}\n";
echo "  combo templates mapped : {$summary['combo_mapped']} (-> Chassis/Combo)\n";
echo "  sub-categories present  : " . ($summary['subcats_total'] ?? '?') . "\n";
if ($summary['skipped']) {
    echo "  SKIPPED:\n    - " . implode("\n    - ", $summary['skipped']) . "\n";
}
echo "  VERIFY templates with NULL category_id : {$nullCat}" . ($nullCat === 0 ? " OK" : " <-- INVESTIGATE") . "\n";
echo "  VERIFY combo templates still unmapped  : {$comboLeft}" . ($comboLeft === 0 ? " OK" : " <-- INVESTIGATE") . "\n";

exit(($nullCat === 0) ? 0 : 1);
