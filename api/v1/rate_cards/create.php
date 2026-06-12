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
 *   - items[] optional array — each item has equipment_type (category slug, required) + rates.
 *   - D16: rate values via clean_decimal(), stored as strings for bcmath.
 *   - Audit log: action='create', module='rates', entity_type='rate_card'.
 *
 * @method  POST
 * @body    JSON: name (required), effective_from (required), description?,
 *               effective_to?, is_default?, customer_id?, items[]?
 * @auth    Session required; require_permission('rates','create')
 * @returns 201 { id, name, effective_from, customer_id }
 *
 * Decisions: D5 (soft delete), D7, D16 (bcmath), §7 (audit log)
 * Session: S019, S-RATES-REDESIGN
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

$effectiveFrom = clean_date($body['effective_from'] ?? null);
if (!$effectiveFrom) {
    $fields['effective_from'] = 'Effective from date is required.';
}

// Optional end date — must parse and be ≥ start date when provided
$effectiveTo = null;
if (!empty($body['effective_to'])) {
    $effectiveTo = clean_date($body['effective_to']);
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
// 5. Validate items array (optional)
// -----------------------------------------------------------------------
$validCurrencies   = ['CAD', 'USD'];
$validMileageUnits = ['km', 'miles'];
$itemsToInsert     = [];
$itemErrors        = [];

// Friendly labels for rate fields (used in error messages)
$rateLabels = [
    'daily_rate'   => 'Daily rate',
    'weekly_rate'  => 'Weekly rate',
    'monthly_rate' => 'Monthly rate',
    'mileage_rate' => 'Mileage rate',
];

if (!empty($body['items']) && is_array($body['items'])) {
    $seenTypes = [];
    foreach ($body['items'] as $idx => $item) {
        $lineNum   = $idx + 1;
        $equipType = clean_string($item['equipment_type'] ?? null, 255);
        if (!$equipType) {
            $itemErrors[] = "Item {$lineNum}: equipment type is required.";
            continue;
        }
        if (in_array($equipType, $seenTypes, true)) {
            $itemErrors[] = "Item {$lineNum}: equipment type '{$equipType}' is listed more than once.";
            continue;
        }
        $seenTypes[] = $equipType;

        // Rates — D16 bcmath strings; each rate must parse + be ≥ 0 when provided
        $rates = [
            'daily_rate'   => null,
            'weekly_rate'  => null,
            'monthly_rate' => null,
            'mileage_rate' => null,
        ];
        $itemHadError = false;
        foreach ($rateLabels as $field => $label) {
            if (!isset($item[$field]) || $item[$field] === '' || $item[$field] === null) continue;
            $val = clean_decimal((string)$item[$field]);
            if ($val === null) {
                $itemErrors[] = "Item {$lineNum}: {$label} must be a valid number.";
                $itemHadError = true;
                continue;
            }
            if (bccomp($val, '0', 6) < 0) {
                $itemErrors[] = "Item {$lineNum}: {$label} cannot be negative.";
                $itemHadError = true;
                continue;
            }
            $rates[$field] = $val;
        }

        $currency    = clean_string($item['currency'] ?? null, 10) ?? 'CAD';
        $mileageUnit = clean_string($item['mileage_unit'] ?? null, 10) ?? 'km';

        if (!in_array($currency, $validCurrencies, true)) {
            $itemErrors[] = "Item {$lineNum}: currency must be CAD or USD.";
            $itemHadError = true;
        }
        if (!in_array($mileageUnit, $validMileageUnits, true)) {
            $itemErrors[] = "Item {$lineNum}: mileage unit must be km or miles.";
            $itemHadError = true;
        }

        if ($itemHadError) continue;

        $itemsToInsert[] = [
            'equipment_type' => $equipType,
            'daily_rate'     => $rates['daily_rate'],
            'weekly_rate'    => $rates['weekly_rate'],
            'monthly_rate'   => $rates['monthly_rate'],
            'mileage_rate'   => $rates['mileage_rate'],
            'mileage_unit'   => $mileageUnit,
            'currency'       => $currency,
            'notes'          => clean_string($item['notes'] ?? null, 1000),
        ];
    }
}

if ($itemErrors) {
    json_validation_error(['items' => implode(' ', $itemErrors)], implode(' ', $itemErrors));
}

// -----------------------------------------------------------------------
// 5b. Hard guard — a customer may not have two active cards covering the
//     same equipment type over an overlapping period (S-RATES-CARD-
//     CONFLICT-GUARD). Global cards are exempt. See lib/RateCards/ConflictGuard.
// -----------------------------------------------------------------------
$conflicts = \FleetForge\RateCards\ConflictGuard::conflicts(
    $customerId,
    array_column($itemsToInsert, 'equipment_type'),
    $effectiveFrom,
    $effectiveTo,
    null
);
if ($conflicts) {
    $msg = \FleetForge\RateCards\ConflictGuard::message($conflicts);
    json_validation_error(['items' => $msg], $msg);
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
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return $id;
});

json_success(['id' => $newId, 'name' => $name, 'effective_from' => $effectiveFrom, 'customer_id' => $customerId], 201);
