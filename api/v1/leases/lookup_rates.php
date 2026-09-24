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
 * Session: S019, S-RATES-MODULE (lookup moved to lib/RateCards/RateResolver.php)
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
// S-RATES-MODULE: the lookup itself now lives in FleetForge\RateCards\RateResolver
// so the Rates module's Price check / "what they pay" views run the SAME code
// and can never disagree with what this form pre-fills. The response shape,
// labels and priority order are unchanged (the resolver adds rc.id / rci.id
// DESC as final tie-breakers so an exact tie is deterministic).
$template = \FleetForge\RateCards\RateResolver::template($equipmentTemplateId);
if (!$template) {
    json_error('NOT_FOUND', 'Equipment template not found.', 404);
}

// The equipment_type key used in rate tables is the template CATEGORY slug
// (rate_card_items.equipment_type) — one category line covers every template
// sharing that category, and a line naming a Specific Unit Type
// (equipment_template_id) beats it. HISTORY: pre-S-LOOKUP-RATES-NAMESPACE this
// keyed on the template NAME, which never matched live data (the zero-rate bug
// class closed by S-MILEAGE-RATE-ZERO-FIX / S-BILLING-RATE-FIX).
//
// Priority: 1) in-force rate-card line — customer card before general card,
// template-specific before category, is_default, newest effective_from;
// 2) the template's default rates; 3) none. S-HOURLY-ONLY: rate_card_id lets
// the form deep-link to the card; S-LEASE-MIN-DAYS: minimum_days is the
// per-line short-lease floor (Config Layer 1), null outside a card match.
json_success(\FleetForge\RateCards\RateResolver::resolve($customerId, $template, date('Y-m-d')));
