<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/update.php
 *
 * Update a rate card header (name, description, dates, is_default).
 * Items are managed separately via the items sub-endpoints or via
 * the upsert_items action on this endpoint.
 *
 * Business rules:
 *   - D19 optimistic lock: submitted updated_at must match DB.
 *   - name uniqueness excludes current card.
 *   - effective_to must be >= effective_from when provided.
 *   - is_default=1 clears all other defaults in the same transaction.
 *   - Optional: items[] replaces the entire item set for this card
 *     (all existing items deleted, new ones inserted atomically).
 *   - Audit log: action='update', old/new captured.
 *
 * @method  POST
 * @body    JSON: id (required), updated_at (required D19 lock),
 *               name?, description?, effective_from?, effective_to?,
 *               is_default?, items[]?
 * @auth    Session required; require_permission('rates','edit')
 * @returns 200 { id, name, effective_from, updated_at }
 *          404 NOT_FOUND | 409 STALE_DATA | 409 ALREADY_EXISTS
 *
 * Decisions: D5 (soft delete), D16 (bcmath), D19 (optimistic lock), §7 (audit)
 * Session: S019
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('rates', 'edit');

// -----------------------------------------------------------------------
// 1. Parse body + resolve card
// -----------------------------------------------------------------------
$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    json_error('MISSING_REQUIRED', 'updated_at is required for optimistic locking.', 422);
}

$existing = db_row(
    "SELECT * FROM rate_cards WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$existing) {
    json_error('NOT_FOUND', 'Rate card not found.', 404);
}

// -----------------------------------------------------------------------
// 2. D19 optimistic lock
// -----------------------------------------------------------------------
if ($existing['updated_at'] !== $submittedUpdatedAt) {
    json_error('STALE_DATA', 'Rate card was modified by another user. Refresh and try again.', 409);
}

// -----------------------------------------------------------------------
// 3. Validate fields
// -----------------------------------------------------------------------
$name = isset($body['name']) ? clean_string($body['name'], 255) : $existing['name'];
if (!$name) {
    json_error('VALIDATION_ERROR', 'name cannot be empty.', 422);
}

// Uniqueness excluding self
if ($name !== $existing['name']) {
    if (db_exists('rate_cards', 'name = ? AND id != ? AND deleted_at IS NULL', [$name, $id])) {
        json_error('ALREADY_EXISTS', 'A rate card with that name already exists.', 409);
    }
}

$description   = isset($body['description']) ? clean_string($body['description'], 1000) : $existing['description'];
$isDefault     = isset($body['is_default'])  ? (int)(bool)$body['is_default']            : (int)$existing['is_default'];

$effectiveFrom = $existing['effective_from'];
if (!empty($body['effective_from'])) {
    $effectiveFrom = clean_date($body['effective_from']);
    if (!$effectiveFrom) {
        json_error('VALIDATION_ERROR', 'effective_from must be a valid date (Y-m-d).', 422);
    }
}

$effectiveTo = $existing['effective_to'];
if (array_key_exists('effective_to', $body)) {
    if (empty($body['effective_to'])) {
        $effectiveTo = null;
    } else {
        $effectiveTo = clean_date($body['effective_to']);
        if (!$effectiveTo) {
            json_error('VALIDATION_ERROR', 'effective_to must be a valid date (Y-m-d).', 422);
        }
    }
}
if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
    json_error('VALIDATION_ERROR', 'effective_to must be on or after effective_from.', 422);
}

// -----------------------------------------------------------------------
// 4. Validate items (optional — if provided, replaces existing items)
// -----------------------------------------------------------------------
$validCurrencies   = ['CAD', 'USD'];
$validMileageUnits = ['km', 'miles'];
$replaceItems      = array_key_exists('items', $body);
$itemsToInsert     = [];

if ($replaceItems && is_array($body['items'])) {
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

        foreach (['daily_rate', 'weekly_rate', 'monthly_rate', 'mileage_rate'] as $field) {
            $$field = null;
            if (!empty($item[$field])) {
                $val = clean_decimal($item[$field]);
                if ($val === null) {
                    json_error('VALIDATION_ERROR', "items[$idx].$field must be a valid decimal.", 422);
                }
                $$field = $val;
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
            'daily_rate'     => $daily_rate,
            'weekly_rate'    => $weekly_rate,
            'monthly_rate'   => $monthly_rate,
            'mileage_rate'   => $mileage_rate,
            'mileage_unit'   => $mileageUnit,
            'currency'       => $currency,
            'notes'          => clean_string($item['notes'] ?? null, 1000),
        ];
    }
}

// -----------------------------------------------------------------------
// 5. Update inside transaction + audit log
// -----------------------------------------------------------------------
$newValues = [
    'name'           => $name,
    'description'    => $description,
    'is_default'     => $isDefault,
    'effective_from' => $effectiveFrom,
    'effective_to'   => $effectiveTo,
];

db_transaction(function() use ($id, $newValues, $existing, $isDefault, $replaceItems, $itemsToInsert) {
    // If setting as default, clear others first
    if ($isDefault && !$existing['is_default']) {
        db_execute(
            "UPDATE rate_cards SET is_default = 0 WHERE is_default = 1 AND id != ? AND deleted_at IS NULL",
            [$id]
        );
    }

    db_update('rate_cards', $newValues, 'id = ?', [$id]);

    // Replace items if provided
    if ($replaceItems) {
        db_execute("DELETE FROM rate_card_items WHERE rate_card_id = ?", [$id]);
        foreach ($itemsToInsert as $item) {
            db_insert('rate_card_items', array_merge(['rate_card_id' => $id], $item));
        }
    }

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'rates',
        'entity_type'  => 'rate_card',
        'entity_id'    => $id,
        'entity_label' => $newValues['name'],
        'old_values'   => json_encode([
            'name'           => $existing['name'],
            'effective_from' => $existing['effective_from'],
            'effective_to'   => $existing['effective_to'],
            'is_default'     => $existing['is_default'],
        ]),
        'new_values'   => json_encode([
            'name'           => $newValues['name'],
            'effective_from' => $newValues['effective_from'],
            'effective_to'   => $newValues['effective_to'],
            'is_default'     => $newValues['is_default'],
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

// Return fresh updated_at for next D19 lock cycle
$fresh = db_row("SELECT updated_at FROM rate_cards WHERE id = ?", [$id]);

json_success([
    'id'             => $id,
    'name'           => $name,
    'effective_from' => $effectiveFrom,
    'updated_at'     => $fresh['updated_at'],
]);
