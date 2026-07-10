<?php
declare(strict_types=1);

/**
 * api/v1/leases/lookup_rates.php
 *
 * Rate priority lookup for the lease create form.
 *
 * Priority order (S-RATES-CONSOLIDATE — overrides retired):
 *   1st — rate_cards: active card (effective_from <= today AND
 *          (effective_to IS NULL OR effective_to >= today) AND deleted_at IS NULL)
 *          with a rate_card_items row matching the equipment_type.
 *          Customer-specific card (customer_id = this customer) is preferred
 *          over a global card; then is_default=1, then latest effective_from.
 *   2nd — equipment_templates default rates (default_daily_rate etc.)
 *
 * HISTORY: customer_equipment_rates ("overrides") used to be Priority 1.
 * S-RATES-CONSOLIDATE folded every override into a customer-specific rate
 * card (scripts/migrate_overrides_to_rate_cards.php) and removed that block,
 * since a customer card already captures customer + equipment type + rates.
 * The override table is retained (unused) until a later cleanup migration.
 *
 * Returns the rates found and the source so the UI can show the correct banner:
 *   "customer"  → green banner "Custom rates for this customer" (customer-specific card)
 *   "rate_card" → info banner "Standard rate card applied" (global card)
 *   "template"  → no banner / fields pre-filled but no badge
 *   "none"      → no banner / leave fields empty
 *
 * @method  GET
 * @query   customer_id (required), equipment_template_id (required)
 * @auth    Session required; require_permission('leases','view')
 * @returns 200 {
 *   source: "customer"|"rate_card"|"template"|"none",
 *   source_label: string,
 *   daily_rate: string|null,
 *   weekly_rate: string|null,
 *   monthly_rate: string|null,
 *   mileage_rate: string|null,
 *   mileage_unit: "km"|"miles",
 *   currency: "CAD"|"USD",
 *   gps_price: string|null,   (S-GPS-RATE-ITEM: per-item GPS daily rate, null if not set or not applicable)
 *   hourly_rate: string|null, (S-RATECARD-HOURLY: per-item hourly rate, null if not set)
 *   minimum_days: int|null     (S-LEASE-MIN-DAYS: per-item short-lease floor — bill a
 *                               flat N x daily rate when the lease runs fewer than N
 *                               days. Only populated from a rate_card_items match;
 *                               null on the template and "none" branches.)
 * }
 *
 * Decisions: D5 (soft delete on rate_cards), D16 (bcmath strings), D7 (routing)
 * Session: S019
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('leases', 'view');

// -----------------------------------------------------------------------
// 1. Inputs
// -----------------------------------------------------------------------
$customerId          = clean_int($_GET['customer_id'] ?? null);
$equipmentTemplateId = clean_int($_GET['equipment_template_id'] ?? null);

if (!$customerId) {
    json_error('MISSING_REQUIRED', 'customer_id is required.', 422);
}
if (!$equipmentTemplateId) {
    json_error('MISSING_REQUIRED', 'equipment_template_id is required.', 422);
}

// Verify customer exists
if (!db_exists('customers', 'id = ? AND deleted_at IS NULL', [$customerId])) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

// Load template to get equipment_type name and default rates
$template = db_row(
    "SELECT id, name, category,
            default_daily_rate, default_weekly_rate, default_monthly_rate,
            default_mileage_rate, default_mileage_unit, default_currency,
            default_hourly_rate
     FROM equipment_templates
     WHERE id = ? AND deleted_at IS NULL",
    [$equipmentTemplateId]
);
if (!$template) {
    json_error('NOT_FOUND', 'Equipment template not found.', 404);
}

// The equipment_type key used in rate tables is the template CATEGORY enum
// value (chassis|dry_van|reefer|container|flatbed|step_deck|lowboy|tanker|dump|other).
// rate_card_items.equipment_type and customer_equipment_rates.equipment_type
// both store this category string. This matches what scripts/demo_seed.php and
// scripts/seed_dataset.php write, and aligns with the per-category rate semantic
// (one rate covers all templates sharing a category — e.g. "53ft Dry Van" and
// "Seed: 53ft Dry Van Wabash" both resolve to category=dry_van and share rates).
//
// HISTORY: Pre-S-LOOKUP-RATES-NAMESPACE this used $template['name'] which never
// matched live data. Lookup silently fell through to Priority 3 (template
// defaults) for every production template — root cause of the zero-rate bug
// class closed by S-MILEAGE-RATE-ZERO-FIX (data side) and S-BILLING-RATE-FIX
// (base_rental side). The rate UIs (rates/create.php, rates/show.php) now store
// category slugs, and customers/show.php has no rate-lookup dropdown — the
// category/name mismatch and the per-category dedup are CLOSED (S-RATES-REDESIGN
// / S-RATES-UI-CATEGORY-DEDUP, 2026-06-12; memory project_rates_ui_category_dedup).
// (customer_equipment_rates retains legacy template-name data but lookup ignores it.)
$equipmentType = $template['category'];
$today         = date('Y-m-d');

// S-RATE-CARD-LABEL-EQTYPE: the selected equipment's name, surfaced in the rate
// banner so it's clear WHICH line item of a card was used. Customer cards bundle
// several equipment types under one card named after the customer's main product
// (e.g. a "53' T/A Dry HWY" card that also carries a Combo line item). Showing
// just the card name made a correct Combo rate look like it came from "dry van";
// prefixing the matched equipment type ("Combo rate · card …") removes that.
$typeLabel = (string) $template['name'];

// -----------------------------------------------------------------------
// 2. Priority 1 — active rate_cards with a matching item.
//    S-RATE-CARD-TEMPLATE-ITEM: a row can be template-specific
//    (rci.equipment_template_id = $equipmentTemplateId) or category-level
//    (rci.equipment_template_id IS NULL). Template-specific beats category.
//    Customer-specific card beats global. D5: filter deleted_at.
// -----------------------------------------------------------------------
$rateCardItem = db_row(
    "SELECT rci.daily_rate, rci.weekly_rate, rci.monthly_rate,
            rci.mileage_rate, rci.mileage_unit, rci.hourly_rate, rci.gps_price, rci.currency,
            rci.minimum_days,
            rc.name AS card_name, rc.customer_id
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
    [$equipmentTemplateId, $equipmentType, $equipmentType, $today, $today, $customerId]
);

if ($rateCardItem) {
    $isCustomerCard = $rateCardItem['customer_id'] !== null;
    json_success([
        'source'       => $isCustomerCard ? 'customer' : 'rate_card',
        'source_label' => $isCustomerCard
            ? $typeLabel . ' rate · custom card "' . $rateCardItem['card_name'] . '"'
            : $typeLabel . ' rate · card "' . $rateCardItem['card_name'] . '"',
        'daily_rate'   => $rateCardItem['daily_rate'],
        'weekly_rate'  => $rateCardItem['weekly_rate'],
        'monthly_rate' => $rateCardItem['monthly_rate'],
        'mileage_rate' => $rateCardItem['mileage_rate'],
        'mileage_unit' => $rateCardItem['mileage_unit'],
        'hourly_rate'  => $rateCardItem['hourly_rate'],
        'currency'     => $rateCardItem['currency'],
        'gps_price'    => $rateCardItem['gps_price'],
        // S-LEASE-MIN-DAYS: per-item short-lease floor (Config Layer 1, highest
        // priority). NULL when this rate-card item sets no minimum. The lease
        // create form pre-fills minimum_billing_days from this when present.
        'minimum_days' => $rateCardItem['minimum_days'],
    ]);
}

// -----------------------------------------------------------------------
// 3. Priority 2 — equipment_templates default rates
// -----------------------------------------------------------------------
$hasTemplateRates = (
    $template['default_daily_rate']   !== null ||
    $template['default_weekly_rate']  !== null ||
    $template['default_monthly_rate'] !== null ||
    // S-AUDIT-BILLING-ENGINE-1 #24: a template with ONLY a default hourly (or
    // mileage) rate used to return source 'none' and DROP those defaults.
    // NB: default_mileage_rate is NOT NULL DEFAULT 0.0000 (0 = mileage
    // disabled, D135) — so a strict !== null check would make EVERY template
    // register as "has rates". Compare > 0 for mileage; hourly stays nullable.
    $template['default_hourly_rate']  !== null ||
    bccomp((string)($template['default_mileage_rate'] ?? '0'), '0', 4) > 0
);

if ($hasTemplateRates) {
    json_success([
        'source'       => 'template',
        'source_label' => $typeLabel . ' rate · template default',
        'daily_rate'   => $template['default_daily_rate'],
        'weekly_rate'  => $template['default_weekly_rate'],
        'monthly_rate' => $template['default_monthly_rate'],
        'mileage_rate' => $template['default_mileage_rate'],
        'mileage_unit' => $template['default_mileage_unit'] ?? 'km',
        'hourly_rate'  => $template['default_hourly_rate'],
        'currency'     => $template['default_currency'] ?? 'CAD',
        'gps_price'    => null,
        // S-LEASE-MIN-DAYS: templates carry no per-item minimum (Config Layer 1
        // lives on rate_card_items only). Always null here so the response shape
        // is consistent across all branches; the lease falls back to its frozen
        // minimum_billing_days / the global setting default downstream.
        'minimum_days' => null,
    ]);
}

// -----------------------------------------------------------------------
// 5. No rates found
// -----------------------------------------------------------------------
json_success([
    'source'       => 'none',
    'source_label' => 'No rates configured for ' . $typeLabel,
    'daily_rate'   => null,
    'weekly_rate'  => null,
    'monthly_rate' => null,
    'mileage_rate' => null,
    'mileage_unit' => 'km',
    'hourly_rate'  => null,
    'currency'     => 'CAD',
    'gps_price'    => null,
    // S-LEASE-MIN-DAYS: no rate source resolved → no per-item minimum. Keep the
    // key present (null) so every lookup_rates response has an identical shape.
    'minimum_days' => null,
]);
