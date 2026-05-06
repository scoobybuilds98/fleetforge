<?php
declare(strict_types=1);

/**
 * api/v1/leases/lookup_rates.php
 *
 * Rate priority lookup for the lease create form.
 *
 * Priority order (CRITICAL — S019 session decision):
 *   1st — customer_equipment_rates: customer + equipment_type match,
 *          effective_from <= today AND (effective_to IS NULL OR effective_to >= today)
 *   2nd — rate_cards: active card (effective_from <= today AND
 *          (effective_to IS NULL OR effective_to >= today) AND deleted_at IS NULL)
 *          with a rate_card_items row matching the equipment_type.
 *          If multiple active cards match, prefer is_default=1, then latest effective_from.
 *   3rd — equipment_templates default rates (default_daily_rate etc.)
 *
 * Returns the rates found and the source so the UI can show the correct banner:
 *   "customer" → green banner "Custom rates loaded for this customer"
 *   "rate_card" → info banner "Standard rate card applied"
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
 *   currency: "CAD"|"USD"
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
            default_mileage_rate, default_mileage_unit, default_currency
     FROM equipment_templates
     WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
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
// (base_rental side). UI dropdowns at app/admin/{customers/show,rates/create,
// rates/show}.php still submit template names; the UX dedup question (multiple
// templates per category) is queued as S-RATES-UI-CATEGORY-DEDUP — this fix
// does not make that worse but also does not address it.
$equipmentType = $template['category'];
$today         = date('Y-m-d');

// -----------------------------------------------------------------------
// 2. Priority 1 — customer_equipment_rates
// -----------------------------------------------------------------------
$customerRate = db_row(
    "SELECT daily_rate, weekly_rate, monthly_rate,
            mileage_rate, mileage_unit, currency
     FROM customer_equipment_rates
     WHERE customer_id = ?
       AND equipment_type = ?
       AND effective_from <= ?
       AND (effective_to IS NULL OR effective_to >= ?)
     ORDER BY effective_from DESC
     LIMIT 1",
    [$customerId, $equipmentType, $today, $today]
);

if ($customerRate) {
    $customer = db_row("SELECT company_name FROM customers WHERE id = ?", [$customerId]);
    json_success([
        'source'       => 'customer',
        'source_label' => 'Custom rates for ' . ($customer['company_name'] ?? 'this customer'),
        'daily_rate'   => $customerRate['daily_rate'],
        'weekly_rate'  => $customerRate['weekly_rate'],
        'monthly_rate' => $customerRate['monthly_rate'],
        'mileage_rate' => $customerRate['mileage_rate'],
        'mileage_unit' => $customerRate['mileage_unit'],
        'currency'     => $customerRate['currency'],
    ]);
}

// -----------------------------------------------------------------------
// 3. Priority 2 — active rate_cards with matching item
//    D5: rate_cards has deleted_at — always filter it.
//    Prefer is_default=1 card first, then latest effective_from.
// -----------------------------------------------------------------------
$rateCardItem = db_row(
    "SELECT rci.daily_rate, rci.weekly_rate, rci.monthly_rate,
            rci.mileage_rate, rci.mileage_unit, rci.currency,
            rc.name AS card_name
     FROM rate_card_items rci
     JOIN rate_cards rc ON rc.id = rci.rate_card_id
     WHERE rci.equipment_type = ?
       AND rc.deleted_at IS NULL
       AND rc.effective_from <= ?
       AND (rc.effective_to IS NULL OR rc.effective_to >= ?)
     ORDER BY rc.is_default DESC, rc.effective_from DESC
     LIMIT 1",
    [$equipmentType, $today, $today]
);

if ($rateCardItem) {
    json_success([
        'source'       => 'rate_card',
        'source_label' => 'Rate card: ' . $rateCardItem['card_name'],
        'daily_rate'   => $rateCardItem['daily_rate'],
        'weekly_rate'  => $rateCardItem['weekly_rate'],
        'monthly_rate' => $rateCardItem['monthly_rate'],
        'mileage_rate' => $rateCardItem['mileage_rate'],
        'mileage_unit' => $rateCardItem['mileage_unit'],
        'currency'     => $rateCardItem['currency'],
    ]);
}

// -----------------------------------------------------------------------
// 4. Priority 3 — equipment_templates default rates
// -----------------------------------------------------------------------
$hasTemplateRates = (
    $template['default_daily_rate']   !== null ||
    $template['default_weekly_rate']  !== null ||
    $template['default_monthly_rate'] !== null
);

if ($hasTemplateRates) {
    json_success([
        'source'       => 'template',
        'source_label' => 'Default rates from template',
        'daily_rate'   => $template['default_daily_rate'],
        'weekly_rate'  => $template['default_weekly_rate'],
        'monthly_rate' => $template['default_monthly_rate'],
        'mileage_rate' => $template['default_mileage_rate'],
        'mileage_unit' => $template['default_mileage_unit'] ?? 'km',
        'currency'     => $template['default_currency'] ?? 'CAD',
    ]);
}

// -----------------------------------------------------------------------
// 5. No rates found
// -----------------------------------------------------------------------
json_success([
    'source'       => 'none',
    'source_label' => 'No rates configured for this equipment type',
    'daily_rate'   => null,
    'weekly_rate'  => null,
    'monthly_rate' => null,
    'mileage_rate' => null,
    'mileage_unit' => 'km',
    'currency'     => 'CAD',
]);
