<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/create.php
 *
 * Create a new rate card.
 *
 * Business rules:
 *   - name required and unique among non-deleted rate cards.
 *   - effective_from required (Y-m-d). effective_to optional but must be >= effective_from.
 *   - is_default: only one card can be default at a time. Setting is_default=1 clears
 *     is_default on all other cards in the same transaction.
 *   - customer_id optional FK to customers — NULL = global card.
 *   - items[] optional array — each item has equipment_type (category slug, required),
 *     optional equipment_template_id (FK to equipment_templates), + rates.
 *     S-RATE-CARD-TEMPLATE-ITEM: template_id=NULL = category-level row;
 *     template_id=X = template-specific row. No two items may share the same
 *     (equipment_type, template_id) pair on a card.
 *     S-LEASE-MIN-DAYS: optional per-item minimum_days (nullable unsigned int 0..90;
 *     empty/absent = NULL) — the short-lease daily floor for this equipment.
 *   - D16: rate values via clean_decimal(), stored as strings for bcmath.
 *   - Audit log: action='create', module='rates', entity_type='rate_card'.
 *
 *   - S-RATES-MODULE: line validation is shared (lib/RateCards/RateCardItems);
 *     dates must fall between 2000 and 2100 (clean_date accepts year 0001);
 *     the audit row carries a snapshot of the lines so price history can show
 *     them; dry_run=1 runs every check (incl. the conflict guard) and returns
 *     200 { ok: true } without saving — the create page checks as you type.
 *
 * @method  POST
 * @body    JSON: name (required), effective_from (required), description?,
 *               effective_to?, is_default?, customer_id?, items[]?, dry_run?
 * @auth    Session required; require_permission('rates','create')
 * @returns 201 { id, name, effective_from, customer_id } | 200 { ok } on dry_run
 *
 * Decisions: D5 (soft delete), D7, D16 (bcmath), §7 (audit log)
 * Session: S019, S-RATES-REDESIGN, S-RATE-CARD-TEMPLATE-ITEM, S-RATES-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('rates', 'create');

// -----------------------------------------------------------------------
// 1. Parse JSON body
// -----------------------------------------------------------------------
$body   = json_body();
$fields = [];

// -----------------------------------------------------------------------
// 2. Validate required fields — VALID-2: accumulate every error
// -----------------------------------------------------------------------
$name = clean_string($body['name'] ?? null, 255);
if (!$name) {
    $fields['name'] = 'Rate card name is required.';
}

$dryRun = !empty($body['dry_run']);

$effectiveFrom = \FleetForge\RateCards\RateCardItems::validDate($body['effective_from'] ?? null);
if (!$effectiveFrom) {
    $fields['effective_from'] = 'Effective from date is required.';
}

// Optional end date — must parse and be ≥ start date when provided
$effectiveTo = null;
if (!empty($body['effective_to'])) {
    $effectiveTo = \FleetForge\RateCards\RateCardItems::validDate($body['effective_to']);
    if (!$effectiveTo) {
        $fields['effective_to'] = 'Effective to must be a valid date.';
    } elseif ($effectiveFrom && $effectiveTo < $effectiveFrom) {
        $fields['effective_to'] = 'End date must be on or after the start date.';
    }
}

// Short-circuit: if required header fields are invalid, stop now — item
// validations reference them.
if ($fields) {
    json_validation_error($fields);
}

// -----------------------------------------------------------------------
// 3. Uniqueness check — name among non-deleted cards
// -----------------------------------------------------------------------
if (db_exists('rate_cards', 'name = ? AND deleted_at IS NULL', [$name])) {
    json_validation_error(['name' => 'A rate card with this name already exists.']);
}

// -----------------------------------------------------------------------
// 4. Optional fields
// -----------------------------------------------------------------------
$description = clean_string($body['description'] ?? null, 1000);
$isDefault   = isset($body['is_default']) ? (int)(bool)$body['is_default'] : 0;

// Optional customer_id — NULL = global rate card
$customerId = null;
if (!empty($body['customer_id'])) {
    $customerId = clean_int($body['customer_id']);
    if (!$customerId || !db_exists('customers', 'id = ? AND deleted_at IS NULL', [$customerId])) {
        json_validation_error(['customer_id' => 'Customer not found.']);
    }
}

// -----------------------------------------------------------------------
// 5. Validate items array (optional) — S-RATES-MODULE: shared rules.
//    Each line: equipment_type (category slug, required), optional
//    equipment_template_id (S-RATE-CARD-TEMPLATE-ITEM), rates ≥ 0 (D16
//    bcmath strings), CAD|USD, km|miles, minimum_days 0..90 (S-LEASE-MIN-DAYS).
//    No two lines may share a (type, template) key.
// -----------------------------------------------------------------------
[$itemsToInsert, $itemErrors] = (!empty($body['items']) && is_array($body['items']))
    ? \FleetForge\RateCards\RateCardItems::normalize($body['items'])
    : [[], []];

if ($itemErrors) {
    json_validation_error(['items' => implode(' ', $itemErrors)], implode(' ', $itemErrors));
}

// -----------------------------------------------------------------------
// 5b. Hard guard — a customer may not have two active cards covering the
//     same equipment type (or same template) over an overlapping period
//     (S-RATES-CARD-CONFLICT-GUARD, S-RATE-CARD-TEMPLATE-ITEM).
//     Global cards are exempt. See lib/RateCards/ConflictGuard.
// -----------------------------------------------------------------------
$conflicts = \FleetForge\RateCards\ConflictGuard::conflicts(
    $customerId,
    $itemsToInsert,
    $effectiveFrom,
    $effectiveTo,
    null
);
if ($conflicts) {
    $msg = \FleetForge\RateCards\ConflictGuard::message($conflicts);
    json_validation_error(['items' => $msg], $msg);
}

// Dry run (the create page checks as the operator types): every rule above
// passed, nothing is written.
if ($dryRun) {
    json_success(['ok' => true, 'items' => count($itemsToInsert)]);
}

// -----------------------------------------------------------------------
// 6. Insert inside transaction + items + audit log
// -----------------------------------------------------------------------
$newId = db_transaction(function() use (
    $name, $description, $isDefault, $effectiveFrom, $effectiveTo, $customerId, $itemsToInsert
) {
    // If setting as default, clear all other defaults first
    if ($isDefault) {
        db_execute(
            "UPDATE rate_cards SET is_default = 0 WHERE is_default = 1 AND deleted_at IS NULL",
            []
        );
    }

    $id = db_insert('rate_cards', [
        'name'           => $name,
        'description'    => $description,
        'is_default'     => $isDefault,
        'effective_from' => $effectiveFrom,
        'effective_to'   => $effectiveTo,
        'customer_id'    => $customerId,
        'created_by'     => current_user_id(),
    ]);

    // Insert items
    foreach ($itemsToInsert as $item) {
        db_insert('rate_card_items', array_merge(['rate_card_id' => $id], $item));
    }

    // §7 audit log
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'rates',
        'entity_type'  => 'rate_card',
        'entity_id'    => $id,
        'entity_label' => $name,
        'new_values'   => json_encode([
            'name'           => $name,
            'effective_from' => $effectiveFrom,
            'effective_to'   => $effectiveTo,
            'is_default'     => $isDefault,
            'customer_id'    => $customerId,
            'item_count'     => count($itemsToInsert),
            // S-RATES-MODULE: the lines themselves, for price history.
            'items'          => \FleetForge\RateCards\RateCardItems::snapshot($itemsToInsert),
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return $id;
});

json_success(['id' => $newId, 'name' => $name, 'effective_from' => $effectiveFrom, 'customer_id' => $customerId], 201);
