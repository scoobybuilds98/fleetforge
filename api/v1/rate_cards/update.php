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
$body   = json_body();
$fields = [];

$id = clean_int($body['id'] ?? null);
if (!$id) {
    $fields['id'] = 'Rate card ID is required.';
}

$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    $fields['updated_at'] = 'Optimistic lock token is required.';
}

if ($fields) {
    json_validation_error($fields);
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
if (!optimistic_lock_matches($submittedUpdatedAt, $existing['updated_at'])) {
    json_error('STALE_DATA',
        'This rate card was modified by another user. Refresh and try again.', 409,
        ['fields' => ['updated_at' => 'This rate card was modified by another user. Refresh and try again.']]);
}

// -----------------------------------------------------------------------
// 3. Validate fields — VALID-2: accumulate every error
// -----------------------------------------------------------------------
$name = isset($body['name']) ? clean_string($body['name'], 255) : $existing['name'];
if (!$name) {
    $fields['name'] = 'Rate card name is required.';
}

$description = isset($body['description']) ? clean_string($body['description'], 1000) : $existing['description'];
$isDefault   = isset($body['is_default'])  ? (int)(bool)$body['is_default']            : (int)$existing['is_default'];

// S-GPS-RATE-CARD: GPS daily rate — optional, carry existing value when absent
$gpsPrice = $existing['gps_price'] ?? null;
if (array_key_exists('gps_price', $body)) {
    if ($body['gps_price'] === '' || $body['gps_price'] === null) {
        $gpsPrice = null;
    } else {
        $gpsPriceIn = clean_decimal((string)$body['gps_price']);
        if ($gpsPriceIn === null || bccomp($gpsPriceIn, '0', 4) < 0) {
            $fields['gps_price'] = 'GPS price must be a valid non-negative number.';
        } else {
            $gpsPrice = $gpsPriceIn;
        }
    }
}

// Optional customer_id update — null = clear to global, non-null = set customer
$customerId = $existing['customer_id'] ?? null;
if (array_key_exists('customer_id', $body)) {
    if (empty($body['customer_id'])) {
        $customerId = null;
    } else {
        $customerId = clean_int($body['customer_id']);
        if (!$customerId || !db_exists('customers', 'id = ? AND deleted_at IS NULL', [$customerId])) {
            $fields['customer_id'] = 'Customer not found.';
        }
    }
}

$effectiveFrom = $existing['effective_from'];
if (!empty($body['effective_from'])) {
    $effectiveFrom = clean_date($body['effective_from']);
    if (!$effectiveFrom) {
        $fields['effective_from'] = 'Effective from must be a valid date.';
    }
}

$effectiveTo = $existing['effective_to'];
if (array_key_exists('effective_to', $body)) {
    if (empty($body['effective_to'])) {
        $effectiveTo = null;
    } else {
        $effectiveTo = clean_date($body['effective_to']);
        if (!$effectiveTo) {
            $fields['effective_to'] = 'Effective to must be a valid date.';
        }
    }
}
if ($effectiveTo !== null && $effectiveFrom && $effectiveTo < $effectiveFrom) {
    $fields['effective_to'] = 'End date must be on or after the start date.';
}

// Short-circuit header validation before items
if ($fields) {
    json_validation_error($fields);
}

// Uniqueness excluding self
if ($name !== $existing['name']) {
    if (db_exists('rate_cards', 'name = ? AND id != ? AND deleted_at IS NULL', [$name, $id])) {
        json_validation_error(['name' => 'A rate card with this name already exists.']);
    }
}

// -----------------------------------------------------------------------
// 4. Validate items (optional — if provided, replaces existing items)
// -----------------------------------------------------------------------
$validCurrencies   = ['CAD', 'USD'];
$validMileageUnits = ['km', 'miles'];
$replaceItems      = array_key_exists('items', $body);
$itemsToInsert     = [];
$itemErrors        = [];

$rateLabels = [
    'daily_rate'   => 'Daily rate',
    'weekly_rate'  => 'Weekly rate',
    'monthly_rate' => 'Monthly rate',
    'mileage_rate' => 'Mileage rate',
];

if ($replaceItems && is_array($body['items'])) {
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
// 4b. Hard guard — a customer may not have two active cards covering the
//     same equipment type over an overlapping period (S-RATES-CARD-
//     CONFLICT-GUARD). Re-checked on every update because changing the
//     card's customer_id OR effective window can create a collision even
//     when items[] is untouched. Types checked = the post-update item set:
//     the replacement items when items[] is provided, else the card's
//     current items. Self is excluded. Global cards are exempt.
// -----------------------------------------------------------------------
$typesToCheck = $replaceItems
    ? array_column($itemsToInsert, 'equipment_type')
    : array_column(
        db_select("SELECT equipment_type FROM rate_card_items WHERE rate_card_id = ?", [$id]),
        'equipment_type'
      );

$conflicts = \FleetForge\RateCards\ConflictGuard::conflicts(
    $customerId,
    $typesToCheck,
    $effectiveFrom,
    $effectiveTo,
    $id
);
if ($conflicts) {
    $msg = \FleetForge\RateCards\ConflictGuard::message($conflicts);
    json_validation_error(['items' => $msg], $msg);
}

// -----------------------------------------------------------------------
// 5. Update inside transaction + audit log
// -----------------------------------------------------------------------
$newValues = [
    'name'           => $name,
    'description'    => $description,
    'gps_price'      => $gpsPrice,
    'is_default'     => $isDefault,
    'effective_from' => $effectiveFrom,
    'effective_to'   => $effectiveTo,
    'customer_id'    => $customerId,
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
            'customer_id'    => $existing['customer_id'] ?? null,
            'gps_price'      => $existing['gps_price'] ?? null,
        ]),
        'new_values'   => json_encode([
            'name'           => $newValues['name'],
            'effective_from' => $newValues['effective_from'],
            'effective_to'   => $newValues['effective_to'],
            'is_default'     => $newValues['is_default'],
            'customer_id'    => $newValues['customer_id'],
            'gps_price'      => $newValues['gps_price'],
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
    'customer_id'    => $customerId,
    'updated_at'     => $fresh['updated_at'],
]);
