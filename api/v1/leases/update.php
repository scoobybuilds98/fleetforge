<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Update API
 *
 * @file        api/v1/leases/update.php
 * @description Updates lease metadata. Status changes are intentionally excluded —
 *              they require state machine validation and go through dedicated endpoints
 *              (activate.php, close.php).
 *
 *              D19 optimistic lock: client must supply updated_at from the initial load.
 *              Mismatch → 409 STALE_DATA (another user has modified the record).
 *
 *              Partial-update pattern: only fields present in the request body are
 *              updated. Fields not sent retain their current DB values.
 *
 *              Dual-unit mileage (S-LEASE-UNITS): new editable fields
 *              estimated_mileage_km, estimated_mileage_miles, km_to_miles_conversion,
 *              miles_to_km_conversion. When updated, legacy estimated_mileage is
 *              re-synced to primary-unit value for backward compat. Rate fields
 *              (daily_rate, weekly_rate, monthly_rate, mileage_rate_km/miles)
 *              are explicitly BLOCKED here per S-LEASE-RATE-AMENDMENT — returns
 *              422 RATE_AMENDMENT_REQUIRED. Use POST /api/v1/leases/amend_rate
 *              for rate changes (audit-trailed via lease_amendments table).
 *
 * @method      POST
 * @body        JSON — id, updated_at (required for optimistic lock)
 *              Optional metadata: end_date, minimum_end_date, rate_notes, po_number,
 *              notes, internal_notes, mileage_at_start, mileage_at_end,
 *              estimated_mileage, estimated_mileage_km, estimated_mileage_miles,
 *              km_to_miles_conversion, miles_to_km_conversion,
 *              insurance_opt_in, insurance_cost, warranty_opt_in, warranty_cost,
 *              gps_opt_in, gps_cost (S-LEASE-GPS-COST: per-day rate, mutable),
 *              minimum_billing_days (S-LEASE-MIN-DAYS: short-lease floor, mutable;
 *              ''/null clears to NULL = no minimum, numeric cast to int 0..90),
 *              gst_exempt, pst_exempt
 *              NOTE: status is immutable here (uses dedicated state-machine
 *              endpoints — activate.php, close.php). daily_rate, weekly_rate,
 *              monthly_rate, mileage_rate_km/miles are BLOCKED here per
 *              S-LEASE-RATE-AMENDMENT (use api/v1/leases/amend_rate.php).
 * @auth        Session required; require_permission('leases','edit')
 * @returns     200 { updated_at } | 409 STALE_DATA | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D19 (optimistic lock)
 * @session     S007, S-LEASE-UNITS, S-LEASE-DISTANCE-EDIT-ACTIVE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'edit');

$body = json_body();

$id        = clean_int($body['id'] ?? null);
$updatedAt = clean_string($body['updated_at'] ?? null);

if (!$id)        json_error('MISSING_REQUIRED', 'id is required.', 422);
if (!$updatedAt) json_error('MISSING_REQUIRED', 'updated_at is required for optimistic lock.', 422);

// ── Fetch existing lease ───────────────────────────────────────
// S-MILEAGE-1: include precharge_invoiced_at so we can reject any
// attempt to change precharge_enabled / precharge_amount after the
// precharge line has been billed on Invoice 1 (immutability rule —
// CRA D14 spirit: dollars billed are frozen).
$existing = db_row(
    "SELECT id, status, contract_number, company_name_snapshot, updated_at,
            precharge_invoiced_at
     FROM leases WHERE id = ? AND deleted_at IS NULL",
    [$id]
);

if (!$existing) {
    json_error('NOT_FOUND', 'Lease not found.', 404);
}

// FIX #15: completed and cancelled leases are read-only; prevent post-hoc editing
if (in_array($existing['status'], ['completed', 'cancelled'])) {
    json_error('IMMUTABLE_RECORD',
        "Lease {$existing['contract_number']} is {$existing['status']} and cannot be edited.", 422);
}

// S-LEASE-DISTANCE-EDIT-ACTIVE: once a lease is active the only permissible edits
// are distance/odometer fields. Dates, notes, add-ons, precharge, and tax flags must
// be configured while the lease is still pending; rate changes always go through the
// amendment workflow. This keeps active-lease financial state stable between billing
// cycles and avoids post-hoc edits that would invalidate already-sent invoices.
if ($existing['status'] === 'active') {
    $distanceAllowed = [
        'id', 'updated_at',
        'mileage_at_start',
        'estimated_mileage', 'estimated_mileage_km', 'estimated_mileage_miles',
        'km_to_miles_conversion', 'miles_to_km_conversion',
    ];
    $blocked = array_values(array_diff(array_keys($body), $distanceAllowed));
    if ($blocked !== []) {
        json_error(
            'ACTIVE_LEASE_DISTANCE_ONLY',
            'Only distance/odometer fields can be changed on an active lease '
            . '(mileage_at_start, estimated_mileage, km_to_miles_conversion, '
            . 'miles_to_km_conversion). Edit the lease while pending for other '
            . 'metadata; use the amendment workflow for rate changes. '
            . 'Blocked field' . (count($blocked) === 1 ? '' : 's') . ': '
            . implode(', ', $blocked) . '.',
            422,
            ['blocked_fields' => $blocked]
        );
    }
}

// ── D19 Optimistic lock check ──────────────────────────────────
if (!optimistic_lock_matches($updatedAt, $existing['updated_at'])) {
    json_error('STALE_DATA',
        'This lease was modified by another user. Reload the page to get the latest version.', 409);
}

// ── Build partial update — only fields supplied in body ────────
// VALID-2: collect every error into $fields, one 422 response at the end.
$data   = [];
$fields = [];

// ── S-LEASE-RATE-AMENDMENT: rate-field block ──────────────────
// Rate columns must NOT be changed via this endpoint. Pre-this-block
// behavior was to silently drop rate fields from the partial-update
// dispatch (they were never added to $data), which left no audit
// trail when a client tried to PATCH them — see api/v1/leases/
// amend_rate.php docblock for the full gap analysis. This explicit
// block returns a 422 with a clear pointer to the amendment workflow
// so the client surfaces the right next step instead of a silent
// no-op.
//
// gps_cost is intentionally EXCLUDED from this block per S-LEASE-
// RATE-AMENDMENT operator decision (option "keep mutable in
// update.php per S-LEASE-GPS-COST"). gps_cost is mutable here AND
// amendable via amend_rate.php — operators have both paths.
//
// rate_method is intentionally EXCLUDED — no such column exists on
// leases (the runtime concept lives in InvoiceGenerator). No block
// needed for a non-existent field.
$rateBlockedFields = [
    'daily_rate',
    'weekly_rate',
    'monthly_rate',
    'mileage_rate_km',
    'mileage_rate_miles',
];
$rateBlockedHits = [];
foreach ($rateBlockedFields as $blocked) {
    if (array_key_exists($blocked, $body)) {
        $rateBlockedHits[] = $blocked;
    }
}
if ($rateBlockedHits !== []) {
    json_error(
        'RATE_AMENDMENT_REQUIRED',
        'Rate fields cannot be changed via lease update. Use the Rate '
        . 'Amendment workflow (POST /api/v1/leases/amend_rate) so the '
        . 'change is captured in the audit trail. Blocked field'
        . (count($rateBlockedHits) === 1 ? '' : 's') . ': '
        . implode(', ', $rateBlockedHits) . '.',
        422,
        ['blocked_fields' => $rateBlockedHits]
    );
}

if (array_key_exists('end_date', $body))
    $data['end_date'] = clean_date($body['end_date']);

if (array_key_exists('minimum_end_date', $body))
    $data['minimum_end_date'] = clean_date($body['minimum_end_date']);

if (array_key_exists('rate_notes', $body))
    $data['rate_notes'] = clean_string($body['rate_notes'], 5000);

if (array_key_exists('po_number', $body))
    $data['po_number'] = clean_string($body['po_number'], 100);

if (array_key_exists('notes', $body))
    $data['notes'] = clean_string($body['notes'], 5000);

if (array_key_exists('internal_notes', $body))
    $data['internal_notes'] = clean_string($body['internal_notes'], 5000);

// VALID-2: mileage readings must be >= 0 — use clean_int so we can surface a specific message
if (array_key_exists('mileage_at_start', $body)) {
    $raw = $body['mileage_at_start'];
    if ($raw === null || $raw === '') {
        $data['mileage_at_start'] = null;
    } else {
        $i = clean_int($raw);
        if ($i === null || $i < 0) {
            $fields['mileage_at_start'] = 'Starting mileage cannot be negative.';
        } else {
            $data['mileage_at_start'] = $i;
        }
    }
}

if (array_key_exists('mileage_at_end', $body)) {
    $raw = $body['mileage_at_end'];
    if ($raw === null || $raw === '') {
        $data['mileage_at_end'] = null;
    } else {
        $i = clean_int($raw);
        if ($i === null || $i < 0) {
            $fields['mileage_at_end'] = 'End mileage cannot be negative.';
        } else {
            $data['mileage_at_end'] = $i;
        }
    }
}

if (array_key_exists('estimated_mileage', $body)) {
    $d = clean_decimal($body['estimated_mileage']);
    if ($d !== null && bccomp($d, '0', 4) < 0) {
        $fields['estimated_mileage'] = 'Estimated mileage cannot be negative.';
    }
    $data['estimated_mileage'] = ($d !== null && bccomp($d, '0', 4) >= 0) ? $d : '0.00';
}

if (array_key_exists('insurance_opt_in', $body))
    $data['insurance_opt_in'] = (bool) $body['insurance_opt_in'] ? 1 : 0;

if (array_key_exists('insurance_cost', $body)) {
    $d = clean_decimal($body['insurance_cost']);
    if ($d !== null && bccomp($d, '0', 4) < 0) {
        $fields['insurance_cost'] = 'Insurance cost cannot be negative.';
    }
    $data['insurance_cost'] = ($d !== null && bccomp($d, '0', 4) >= 0) ? $d : '0.00';
}

if (array_key_exists('warranty_opt_in', $body))
    $data['warranty_opt_in'] = (bool) $body['warranty_opt_in'] ? 1 : 0;

if (array_key_exists('warranty_cost', $body)) {
    $d = clean_decimal($body['warranty_cost']);
    if ($d !== null && bccomp($d, '0', 4) < 0) {
        $fields['warranty_cost'] = 'Warranty cost cannot be negative.';
    }
    $data['warranty_cost'] = ($d !== null && bccomp($d, '0', 4) >= 0) ? $d : '0.00';
}

// S-LEASE-GPS-COST: GPS toggle + per-day cost are mutable (parallel to
// insurance/warranty — not rate-immutable per D14). Negative cost rejected;
// invalid/null falls back to '1.00' to match schema default.
if (array_key_exists('gps_opt_in', $body))
    $data['gps_opt_in'] = (bool) $body['gps_opt_in'] ? 1 : 0;

if (array_key_exists('gps_cost', $body)) {
    $d = clean_decimal($body['gps_cost']);
    if ($d !== null && bccomp($d, '0', 4) < 0) {
        $fields['gps_cost'] = 'GPS cost cannot be negative.';
    }
    $data['gps_cost'] = ($d !== null && bccomp($d, '0', 4) >= 0) ? $d : '1.00';
}

// S-LEASE-HOURLY-RATE: hourly rate mutable via edit (like GPS). NULL = clear billing.
if (array_key_exists('hourly_rate', $body)) {
    if ($body['hourly_rate'] === null || $body['hourly_rate'] === '') {
        $data['hourly_rate'] = null;
    } else {
        $d = clean_decimal($body['hourly_rate']);
        if ($d !== null && bccomp($d, '0', 4) < 0) {
            $fields['hourly_rate'] = 'Hourly rate cannot be negative.';
        } else {
            $data['hourly_rate'] = $d;
        }
    }
}

// ── S-LEASE-MIN-DAYS: short-lease floor (Config Layer 2) is operator-editable ──
// Mutable here like insurance/warranty/gps (NOT a rate-immutable column — it's a
// billing-policy floor, not a price). Partial-update guard mirrors the sibling
// numeric fields: present + ''/null clears it to NULL (no per-lease minimum);
// present + numeric is cast to int and clamped to 0..90 (0/1 mean "no minimum");
// non-numeric / out-of-range is a hard validation error rather than a silent coerce.
if (array_key_exists('minimum_billing_days', $body)) {
    $raw = $body['minimum_billing_days'];
    if ($raw === null || $raw === '') {
        $data['minimum_billing_days'] = null;
    } elseif (is_numeric($raw)) {
        $i = (int) $raw;
        if ($i < 0 || $i > 90) {
            $fields['minimum_billing_days'] = 'Minimum billing days must be between 0 and 90.';
        } else {
            $data['minimum_billing_days'] = $i;
        }
    } else {
        $fields['minimum_billing_days'] = 'Minimum billing days must be a whole number between 0 and 90.';
    }
}

// D22: gst_exempt and pst_exempt can be changed via amendment (allow here for now)
if (array_key_exists('gst_exempt', $body)) {
    $data['gst_exempt'] = (bool) $body['gst_exempt'] ? 1 : 0;
}
if (array_key_exists('pst_exempt', $body)) {
    $data['pst_exempt'] = (bool) $body['pst_exempt'] ? 1 : 0;
}

// ── S-LEASE-UNITS: dual-unit allowance + conversion fields ─────
// Rate fields (mileage_rate_km/miles) are not editable here (require amendment).
// Allowance and conversion factor are updatable.

if (array_key_exists('estimated_mileage_km', $body)) {
    $d = clean_decimal($body['estimated_mileage_km']);
    if ($d !== null && bccomp($d, '0', 3) < 0) {
        $fields['estimated_mileage_km'] = 'KM allowance cannot be negative.';
    }
    $data['estimated_mileage_km'] = ($d !== null && bccomp($d, '0', 3) >= 0) ? bcround($d, 3) : '0.000';
}

if (array_key_exists('estimated_mileage_miles', $body)) {
    $d = clean_decimal($body['estimated_mileage_miles']);
    if ($d !== null && bccomp($d, '0', 3) < 0) {
        $fields['estimated_mileage_miles'] = 'Mile allowance cannot be negative.';
    }
    $data['estimated_mileage_miles'] = ($d !== null && bccomp($d, '0', 3) >= 0) ? bcround($d, 3) : '0.000';
}

if (array_key_exists('km_to_miles_conversion', $body)) {
    $d = clean_decimal($body['km_to_miles_conversion']);
    if ($d === null || bccomp($d, '0', 6) <= 0) {
        $fields['km_to_miles_conversion'] = 'KM to miles conversion factor must be greater than zero.';
    } else {
        $data['km_to_miles_conversion'] = bcround($d, 6);
    }
}

if (array_key_exists('miles_to_km_conversion', $body)) {
    $d = clean_decimal($body['miles_to_km_conversion']);
    if ($d === null || bccomp($d, '0', 6) <= 0) {
        $fields['miles_to_km_conversion'] = 'Miles to KM conversion factor must be greater than zero.';
    } else {
        $data['miles_to_km_conversion'] = bcround($d, 6);
    }
}

// Non-standard conversion warning — audit only, no rejection
$nonStdConv = false;
if (isset($data['km_to_miles_conversion'])) {
    $v = $data['km_to_miles_conversion'];
    if (bccomp($v, '0.5', 6) < 0 || bccomp($v, '0.7', 6) > 0) $nonStdConv = true;
}
if (isset($data['miles_to_km_conversion'])) {
    $v = $data['miles_to_km_conversion'];
    if (bccomp($v, '1.5', 6) < 0 || bccomp($v, '1.7', 6) > 0) $nonStdConv = true;
}

// ── S-MILEAGE-1 Model B: precharge fields ──────────────────────
// Immutability: once precharge_invoiced_at IS NOT NULL (Invoice 1
// has billed the precharge), neither precharge_enabled nor
// precharge_amount may change. Per D-H: spirit of CRA D14 — dollars
// billed are frozen; downstream lease must be voided/recreated to
// restructure the deal.
$prechargeFrozen = !empty($existing['precharge_invoiced_at']);

$prechargeEnabledIn = null;
$prechargeAmountIn  = null;
$prechargeAmountSupplied = false;
$prechargeEnabledSupplied = false;

if (array_key_exists('precharge_enabled', $body)) {
    $prechargeEnabledSupplied = true;
    $rawEnabled = $body['precharge_enabled'];
    if ($rawEnabled === 0 || $rawEnabled === '0' || $rawEnabled === false) {
        $prechargeEnabledIn = 0;
    } elseif ($rawEnabled === 1 || $rawEnabled === '1' || $rawEnabled === true) {
        $prechargeEnabledIn = 1;
    } else {
        $fields['precharge_enabled'] = 'Precharge toggle must be 0 or 1.';
    }
}

if (array_key_exists('precharge_amount', $body)) {
    $prechargeAmountSupplied = true;
    $rawAmt = $body['precharge_amount'];
    if ($rawAmt === null || $rawAmt === '') {
        $prechargeAmountIn = null;
    } else {
        $amt = clean_decimal($rawAmt);
        if ($amt === null) {
            $fields['precharge_amount'] = 'Precharge amount must be a valid number.';
        } elseif (bccomp($amt, '0', 2) <= 0) {
            $fields['precharge_amount'] = 'Precharge amount must be greater than zero.';
        } else {
            $prechargeAmountIn = bcround($amt, 2);
        }
    }
}

if ($prechargeEnabledSupplied || $prechargeAmountSupplied) {
    // Compare against current DB row to detect actual change vs no-op echo.
    $current = db_row(
        "SELECT precharge_enabled, precharge_amount FROM leases WHERE id = ?",
        [$id]
    );
    $curEnabled = (int) ($current['precharge_enabled'] ?? 0);
    $curAmount  = $current['precharge_amount'] ?? null;

    $newEnabled = $prechargeEnabledSupplied ? $prechargeEnabledIn : $curEnabled;
    $newAmount  = $prechargeAmountSupplied  ? $prechargeAmountIn  : $curAmount;

    $enabledChanged = ($newEnabled !== $curEnabled);
    $amountChanged  = (
        ($newAmount === null) !== ($curAmount === null)
        || ($newAmount !== null && $curAmount !== null && bccomp((string)$newAmount, (string)$curAmount, 2) !== 0)
    );

    if ($prechargeFrozen && ($enabledChanged || $amountChanged)) {
        // Hard reject — return 409 with explicit guidance.
        json_error('PRECHARGE_LOCKED',
            'Cannot change precharge settings after Invoice 1 has billed the precharge. Void/recreate the lease to restructure.',
            409
        );
    }

    // App-level mirror of CHECK chk_leases_precharge_amount.
    if ($newEnabled === 1 && $newAmount === null && !isset($fields['precharge_amount'])) {
        $fields['precharge_amount'] = 'Precharge amount is required when precharge is enabled.';
    }

    if ($enabledChanged || $prechargeEnabledSupplied) {
        $data['precharge_enabled'] = $newEnabled;
    }
    if ($amountChanged || $prechargeAmountSupplied) {
        // Disabling clears the amount; enabling sets it (validation above
        // ensures amount is present + > 0 when enabled).
        $data['precharge_amount'] = ($newEnabled === 1) ? $newAmount : null;
    }
}

// ADV-BILL-1: advance_billing_periods is editable ONLY while the lease is pending.
// Once activated, the prepaid invoice batch is locked in and changing the count
// would break invoice numbering / next_billing_date / customer notifications.
if (array_key_exists('advance_billing_periods', $body)) {
    if ($existing['status'] !== 'pending') {
        $fields['advance_billing_periods'] =
            'Advance billing periods cannot be changed after the lease is activated.';
    } else {
        $advIn = clean_int($body['advance_billing_periods']) ?? 0;
        if ($advIn < 0) {
            $fields['advance_billing_periods'] = 'Advance billing periods cannot be negative.';
        } else {
            $cap = (int) settings_get('billing.max_advance_periods', '24');
            if ($advIn > $cap) {
                $fields['advance_billing_periods'] = "Advance billing periods cannot exceed {$cap}.";
            } else {
                // Cross-field: monthly-only.
                $cycleRow = db_row("SELECT billing_cycle FROM leases WHERE id = ?", [$id]);
                $cycle    = $cycleRow['billing_cycle'] ?? null;
                if ($advIn > 0 && $cycle !== 'monthly') {
                    $fields['advance_billing_periods'] =
                        'Advance billing is only available for monthly billing cycles.';
                } else {
                    $data['advance_billing_periods'] = $advIn;
                }
            }
        }
    }
}

// Validate end_date and mileage cross-field rules
$currentLease = db_row("SELECT start_date, mileage_at_start FROM leases WHERE id = ?", [$id]);

if (isset($data['end_date']) && $data['end_date']) {
    if ($data['end_date'] < $currentLease['start_date']) {
        $fields['end_date'] = 'End date must be after start date.';
    }
}

if (array_key_exists('minimum_end_date', $data) && $data['minimum_end_date']) {
    if ($data['minimum_end_date'] < $currentLease['start_date']) {
        $fields['minimum_end_date'] = 'Minimum end date must be on or after start date.';
    }
}

if (array_key_exists('mileage_at_end', $data) && $data['mileage_at_end'] !== null) {
    $startMileage = (int) ($data['mileage_at_start'] ?? $currentLease['mileage_at_start'] ?? 0);
    if ((int) $data['mileage_at_end'] < $startMileage) {
        $fields['mileage_at_end'] = 'End mileage must be greater than or equal to start mileage.';
    }
}

if ($fields) {
    json_validation_error($fields);
}

if (empty($data)) {
    json_error('VALIDATION_ERROR', 'No updatable fields provided.', 422);
}

$data['updated_by'] = current_user_id();

// ── S-LEASE-UNITS: re-sync legacy estimated_mileage to primary unit value ──
// If any dual-unit allowance field was updated, keep legacy field in sync
// so close.php billing math (which reads estimated_mileage) stays correct.
if (isset($data['estimated_mileage_km']) || isset($data['estimated_mileage_miles'])) {
    $leaseRow = db_row("SELECT mileage_unit, estimated_mileage_km, estimated_mileage_miles FROM leases WHERE id = ?", [$id]);
    $primaryUnit = $leaseRow['mileage_unit'] ?? 'km';
    $resolvedKm    = $data['estimated_mileage_km']    ?? $leaseRow['estimated_mileage_km']    ?? '0.000';
    $resolvedMiles = $data['estimated_mileage_miles'] ?? $leaseRow['estimated_mileage_miles'] ?? '0.000';
    $data['estimated_mileage'] = ($primaryUnit === 'miles') ? $resolvedMiles : $resolvedKm;
}

// FIX #20: wrap db_update + audit_log in transaction so both commit or rollback together
$newUpdatedAt = null;

db_transaction(function () use ($id, $data, $existing, $nonStdConv, &$newUpdatedAt) {
    // S-LEASE-DISTANCE-EDIT-ACTIVE: capture old values of distance fields before
    // the write so the audit trail records old→new for each changed odometer/allowance
    // column. Required by the audit_log standard (old_values + new_values per change).
    $distanceCols = [
        'mileage_at_start', 'estimated_mileage', 'estimated_mileage_km',
        'estimated_mileage_miles', 'km_to_miles_conversion', 'miles_to_km_conversion',
    ];
    $changedDistCols = array_intersect(array_keys($data), $distanceCols);
    $oldDistValues   = null;
    if ($changedDistCols !== []) {
        $oldRow = db_row(
            "SELECT mileage_at_start, estimated_mileage, estimated_mileage_km,
                    estimated_mileage_miles, km_to_miles_conversion, miles_to_km_conversion
               FROM leases WHERE id = ?",
            [$id]
        );
        $oldDistValues = array_intersect_key($oldRow, array_flip($changedDistCols));
    }

    db_update('leases', $data, 'id = ?', [$id]);

    $newRow = db_row("SELECT updated_at FROM leases WHERE id = ?", [$id]);
    $newUpdatedAt = $newRow['updated_at'];

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'leases',
        'entity_type'  => 'lease',
        'entity_id'    => $id,
        'entity_label' => $existing['contract_number'],
        'notes'        => "Lease {$existing['contract_number']} metadata updated",
        'old_values'   => $oldDistValues !== null ? json_encode($oldDistValues) : null,
        'new_values'   => json_encode($data),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    if ($nonStdConv) {
        db_insert('audit_log', [
            'user_id'      => current_user_id(),
            'user_name'    => current_user()['name'] ?? 'system',
            'action'       => 'update',
            'module'       => 'leases',
            'entity_type'  => 'lease',
            'entity_id'    => $id,
            'entity_label' => $existing['contract_number'],
            'notes'        => "Non-standard conversion factors updated on {$existing['contract_number']} (S-LEASE-UNITS warning)",
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    }
});

json_success(['updated_at' => $newUpdatedAt]);
