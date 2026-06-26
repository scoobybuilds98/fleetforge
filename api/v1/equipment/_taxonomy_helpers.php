<?php
declare(strict_types=1);

/**
 * api/v1/equipment/_taxonomy_helpers.php
 *
 * S-EQTAX shared helpers for the equipment Category / Sub-category CRUD
 * endpoints. Slugs are STABLE identifiers mirrored into
 * equipment_templates.category, so they must be globally unique across BOTH
 * taxonomy tables (the mirror namespace) and immutable once created — only the
 * label is operator-editable.
 *
 * @depends api/bootstrap.php (db helpers)
 * @session S-EQTAX-7
 */

if (!function_exists('eqtax_slugify')) {
    /** Normalize a free-text label into a <=50-char slug candidate. */
    function eqtax_slugify(string $label): string
    {
        $s = strtolower(trim($label));
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
        $s = trim($s, '_');
        return $s !== '' ? substr($s, 0, 50) : 'type';
    }

    /**
     * Is this slug already used by any category OR sub-category? Counts ALL rows
     * including soft-deleted ones — the UNIQUE indexes span tombstones, so a
     * db_exists()-style `deleted_at IS NULL` check would miss a soft-deleted
     * holder and the INSERT would then 1062 (the documented soft-delete blind
     * spot). Global across both tables because both feed the same mirror.
     */
    function eqtax_slug_taken(string $slug): bool
    {
        return db_count("SELECT COUNT(*) FROM equipment_categories WHERE slug = ?", [$slug]) > 0
            || db_count("SELECT COUNT(*) FROM equipment_subcategories WHERE slug = ?", [$slug]) > 0;
    }

    /** Derive a globally-unique slug from a label, suffixing on collision. */
    function eqtax_unique_slug(string $label): string
    {
        $base = eqtax_slugify($label);
        $slug = $base;
        $i    = 2;
        while (eqtax_slug_taken($slug)) {
            $slug = substr($base, 0, 46) . '_' . $i;
            $i++;
        }
        return $slug;
    }
}
