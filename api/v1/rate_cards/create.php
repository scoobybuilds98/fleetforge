<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/create.php
 *
 * Create a new rate card (header only — items added via update or item endpoints).
 *
 * Business rules:
 *   - name required and unique among non-deleted rate cards.
 *   - effective_from required (Y-m-d). effective_to optional but must be >= effective_from.
 *   - is_default: only one card can be default at a time. Setting is_default=1 clears
 *     is_default on all other cards in the same transaction.
 *   - items[] optional array — each item has equipment_type (required) + rates.
 *   - D16: rate values via clean_decimal(), stored as strings for bcmath.
 *   - Audit log: action='create', module='rates', entity_type='rate_card'.
 *
 * @method  POST
 * @body    JSON: name (required), effective_from (required), description?,
 *               effective_to?, is_default?, items[]?
 * @auth    Session required; require_permission('rates','create')
 * @returns 201 { id, name, effective_from }
 *
 * Decisions: D5 (soft delete), D7, D16 (bcmath), §7 (audit log)
 * Session: S019
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('rates', 'create');

// -----------------------------------------------------------------------
// 1. Parse JSON body
// -----------------------------------------------------------------------
$body = json_body();

// -----------------------------------------------------------------------
// 2. Validate required fields
// -----------------------------------------------------------------------
$name = clean_string($body['name'] ?? null, 255);
if (!$name) {
    json_error('MISSING_REQUIRED', 'name is required.', 422);
}

$effectiveFrom = clean_date($body['effective_from'] ?? null);
if (!$effectiveFrom) {
    json_error('MISSING_REQUIRED', 'effective_from is required (Y-m-d).', 422);
}

// -----------------------------------------------------------------------
// 3. Uniqueness check — name among non-deleted cards
// -----------------------------------------------------------------------
if (db_exists('rate_cards', 'name = ? AND deleted_at IS NULL', [$name])) {
    json_error('ALREADY_EXISTS', 'A rate card with that name already exists.', 409);
}

// -----------------------------------------------------------------------
// 4. Optional fields
// -----------------------------------------------------------------------
$description = clean_string($body['description'] ?? null, 1000);
$isDefault   = isset($body['is_default']) ? (int)(bool)$body['is_default'] : 0;

$effectiveTo = null;
if (!empty($body['effective_to'])) {
    $effectiveTo = clean_date($body['effective_to']);
    if (!$effectiveTo) {
        json_error('VALIDATION_ERROR', 'effective_to must be a valid date (Y-m-d).', 422);
    }
    if ($effectiveTo < $effectiveFrom) {
        json_error('VALIDATION_ERROR', 'effective_to must be on or after effective_from.', 422);
    }
}

// -----------------------------------------------------------------------
// 5. Validate items array (optional)
// -----------------------------------------------------------------------
$validCurrencies   = ['CAD', 'USD'];
$validMileageUnits = ['km', 'miles'];
$itemsToInsert     = [];

if (!empty($body['items']) && is_array($body['items'])) {
    $seenTypes = [];
    foreach ($body['items'] as $idx => $item) {
        $equipType = clean_string($item['equipment_type'] ?? null, 255);
        if (!$equipType) {
            json_error('VALIDATION_ERROR', "items[$idx].equipment_type is required.", 422);
        }
        if (in_array($equipType, $seenTypes, true)) {
            json_error('VALIDATION_ERROR', "Duplicate equipment_type '$equipType' in items.", 409);
        }
        $seenTypes[] = $equipType;

        // Rates — D16 bcmath strings
        $dailyRate   = null;
        $weeklyRate  = null;
        $monthlyRate = null;
        $mileageRate = null;

        foreach ([
            'daily_rate'   => 'dailyRate',
            'weekly_rate'  => 'weeklyRate',
            'monthly_rate' => 'monthlyRate',
            'mileage_rate' => 'mileageRate',
        ] as $field => $varName) {
            if (!empty($item[$field])) {
                $val = clean_decimal($item[$field]);
                if ($val === null) {
                    json_error('VALIDATION_ERROR', "items[$idx].$field must be a valid decimal.", 422);
                }
                $$varName = $val;
            }
        }

        $currency    = clean_string($item['currency'] ?? null, 10) ?? 'CAD';
        $mileageUnit = clean_string($item['mileage_unit'] ?? null, 10) ?? 'km';

        if (!in_array($currency, $validCurrencies, true)) {
            json_error('VALIDATION_ERROR', "items[$idx].currency must be CAD or USD.", 422);
        }
        if (!in_array($mileageUnit, $validMileageUnits, true)) {
            json_error('VALIDATION_ERROR', "items[$idx].mileage_unit must be km or miles.", 422);
        }

        $itemsToInsert[] = [
            'equipment_type' => $equipType,
            'daily_rate'     => $dailyRate,
            'weekly_rate'    => $weeklyRate,
            'monthly_rate'   => $monthlyRate,
            'mileage_rate'   => $mileageRate,
            'mileage_unit'   => $mileageUnit,
            'currency'       => $currency,
            'notes'          => clean_string($item['notes'] ?? null, 1000),
        ];
    }
}

// -----------------------------------------------------------------------
// 6. Insert inside transaction + items + audit log
// -----------------------------------------------------------------------
$newId = db_transaction(function() use (
    $name, $description, $isDefault, $effectiveFrom, $effectiveTo, $itemsToInsert
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
            'item_count'     => count($itemsToInsert),
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return $id;
});

json_success(['id' => $newId, 'name' => $name, 'effective_from' => $effectiveFrom], 201);
