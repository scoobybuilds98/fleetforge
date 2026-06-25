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
            precharge_invoiced_at, cartage_billed_at
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

// S-LEASE-EDIT-ACTIVE-UNLOCK (operator 2026-06-24): active leases are now fully
// editable — dates, notes, add-ons, precharge, tax flags, etc. The blanket
// "distance/odometer fields only" gate (formerly S-LEASE-DISTANCE-EDIT-ACTIVE) is
// removed; the edit form warns the operator that changes apply to FUTURE billing
// only and never rewrite invoices that have already been sent.
//
// Edits to an active lease affect future invoice generation (next monthly run /
// the close invoice); already-sent invoices are immutable rows in `invoices` and
// are not touched by a lease-row update. The hard immutability guards that protect
// dollars ALREADY billed remain in force below and are NOT relaxed by this change:
//   • completed / cancelled leases stay read-only (IMMUTABLE_RECORD, above).
//   • precharge is frozen once Invoice 1 has billed it (PRECHARGE_LOCKED, below).
//   • cartage is frozen once billed (cartage_billed_at, below).
//   • rate fields still route through the amendment workflow (RATE_* block, below).
//   • advance_billing_periods stays pending-only (locked once activated, below).

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

// S-LEASE-CONTRACT-NUMBER-EDIT: the contract number is editable on pending AND
// active leases. leases.contract_number carries a GLOBAL UNIQUE index that spans
// soft-deleted rows, so the duplicate pre-check counts ALL rows (db_exists()'s
// deleted_at filter would miss a soft-deleted collision and let the UPDATE 1062 →
// 500, per project_db_exists_softdelete_blindspot). The transaction below also
// narrowly catches a concurrent-race 1062 on this key. Invoices keep their
// contract_number_snapshot — a rename only affects the lease going forward.
if (array_key_exists('contract_number', $body)) {
    $cn = trim((string) ($body['contract_number'] ?? ''));
    if ($cn === '') {
        $fields['contract_number'] = 'Contract number is required.';
    } elseif (mb_strlen($cn) > 100) {
        $fields['contract_number'] = 'Contract number cannot exceed 100 characters.';
    } elseif (db_count("SELECT COUNT(*) FROM leases WHERE contract_number = ? AND id <> ?", [$cn, $id]) > 0) {
        $fields['contract_number'] = 'That contract number is already in use by another lease.';
    } else {
        $data['contract_number'] = $cn;
    }
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

// S-LEASE-HOURLY-BILLING: manual engine-hours readings (decimal, >= 0; clearable).
if (array_key_exists('engine_hours_at_start', $body)) {
    $raw = $body['engine_hours_at_start'];
    if ($raw === null || $raw === '') {
        $data['engine_hours_at_start'] = null;
    } else {
        $d = clean_decimal($raw);
        if ($d === null || bccomp($d, '0', 2) < 0) {
            $fields['engine_hours_at_start'] = 'Starting engine hours cannot be negative.';
        } else {
            $data['engine_hours_at_start'] = $d;
        }
    }
}
if (array_key_exists('engine_hours_at_end', $body)) {
    $raw = $body['engine_hours_at_end'];
    if ($raw === null || $raw === '') {
        $data['engine_hours_at_end'] = null;
    } else {
        $d = clean_decimal($raw);
        if ($d === null || bccomp($d, '0', 2) < 0) {
            $fields['engine_hours_at_end'] = 'Ending engine hours cannot be negative.';
        } else {
            $data['engine_hours_at_end'] = $d;
        }
    }
}

// S-LEASE-SERVICE-CHARGES: cartage is editable only until it bills. Once
// cartage_billed_at is set the charge is locked (editing wouldn't re-bill it).
if (array_key_exists('cartage_amount', $body)) {
    if ($existing['cartage_billed_at'] !== null) {
        $fields['cartage_amount'] = 'Cartage has already been billed and can no longer be changed.';
    } else {
        $raw = $body['cartage_amount'];
        if ($raw === null || $raw === '') {
            $data['cartage_amount'] = null;
        } else {
            $d = clean_decimal($raw);
            if ($d === null || bccomp($d, '0', 2) < 0) {
                $fields['cartage_amount'] = 'Cartage charge cannot be negative.';
            } else {
                $data['cartage_amount'] = $d;
            }
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

// S-LEASE-MILEAGE-MODE: per-lease mileage data source (manual/off/samsara).
// Editable on pending and active leases (affects future billing only).
// Out-of-enum is a hard validation error.
if (array_key_exists('mileage_tracking_mode', $body)) {
    $raw = $body['mileage_tracking_mode'];
    if (in_array($raw, ['manual', 'off', 'samsara'], true)) {
        $data['mileage_tracking_mode'] = $raw;
    } else {
        $fields['mileage_tracking_mode'] = 'Invalid mileage tracking mode.';
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

try {
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
} catch (\PDOException $e) {
    // S-LEASE-CONTRACT-NUMBER-EDIT: narrow race translation — a concurrent rename
    // can pass the duplicate pre-check above, then collide on the contract_number
    // UNIQUE index inside the transaction. Translate that specific 1062 into the
    // same 422; re-throw anything else (FK violations, etc.) for the global handler.
    if ($e->getCode() === '23000'
        && array_key_exists('contract_number', $data)
        && stripos($e->getMessage(), 'contract_number') !== false) {
        json_validation_error(['contract_number' => 'That contract number is already in use by another lease.']);
    }
    throw $e;
}

// S-INVOICE-LEASE-EDIT-SYNC: a lease edit does NOT auto-flow into invoices, so
// surface everything the date change implies for billing and let the edit form
// offer to apply it. Three buckets (skipped entirely for precharge leases, which
// regenerate refuses):
//   affected_drafts      — existing draft invoices that OVERLAP the lease → regenerate
//   new_billable_months  — billable months with no invoice yet (e.g. the lease was
//                          extended) → generate, in order
//   orphaned_drafts      — draft invoices now entirely AFTER the lease's billable
//                          extent (e.g. the lease was shortened) → void
$affectedDrafts     = [];
$newBillableMonths  = [];
$orphanedDrafts     = [];
$leasePrecharge = (int) (db_row("SELECT precharge_enabled FROM leases WHERE id = ?", [$id])['precharge_enabled'] ?? 0);
if (!$leasePrecharge) {
    // Lease's current billable extent (actual return → expected end → none/open).
    $extRow = db_row("SELECT COALESCE(actual_return_date, end_date) AS ext FROM leases WHERE id = ?", [$id]);
    $extent = $extRow['ext'] ?? null;

    $leaseStart = (string) (db_row("SELECT start_date FROM leases WHERE id = ?", [$id])['start_date'] ?? '');

    // Regenerable drafts — overlap the lease AND whose period would actually CHANGE
    // when re-derived from the current lease dates. A draft whose re-derived period
    // is identical (e.g. a month fully inside the lease, unaffected by the edit) is
    // SKIPPED — regenerating it needlessly would (a) be a no-op and (b) churn the
    // holistic running-reconciliation (regenerating an early invoice while later
    // ones exist makes sumAlreadyBilled count them). Clamp logic mirrors
    // regenerate.php exactly (full_month → natural calendar month, then clamp).
    $affectedDrafts = [];
    $candidates = db_select(
        "SELECT id, invoice_number, billing_period_start, billing_period_end, billing_type
           FROM invoices
          WHERE lease_id = ? AND status = 'draft' AND deleted_at IS NULL
            AND invoice_type = 'regular' AND COALESCE(generation_source, '') <> 'advance'
            AND (? IS NULL OR billing_period_start <= ?)
          ORDER BY billing_period_start ASC, id ASC",
        [$id, $extent, $extent]
    );
    foreach ($candidates as $d) {
        $ts = strtotime((string) $d['billing_period_start']);
        if (($d['billing_type'] ?? '') === 'full_month' && $ts !== false) {
            $natStart = date('Y-m-01', $ts);
            $natEnd   = date('Y-m-t',  $ts);
        } else {
            $natStart = (string) $d['billing_period_start'];
            $natEnd   = (string) $d['billing_period_end'];
        }
        $ext    = $extent ?: $natEnd;
        $pStart = max($natStart, $leaseStart);
        $pEnd   = min($natEnd, (string) $ext);
        // Only "affected" if the period genuinely moves (and still valid).
        if ($pStart <= $pEnd
            && ($pStart !== (string) $d['billing_period_start'] || $pEnd !== (string) $d['billing_period_end'])) {
            $affectedDrafts[] = ['id' => $d['id'], 'invoice_number' => $d['invoice_number']];
        }
    }

    // Orphaned drafts — period starts after the lease now ends (only when bounded).
    if ($extent) {
        $orphanedDrafts = db_select(
            "SELECT id, invoice_number FROM invoices
              WHERE lease_id = ? AND status = 'draft' AND deleted_at IS NULL
                AND invoice_type = 'regular' AND COALESCE(generation_source, '') <> 'advance'
                AND billing_period_start > ?
              ORDER BY id ASC",
            [$id, $extent]
        );
    }

    // Newly-billable months (un-invoiced segments) — reuse the picker's logic.
    if (!defined('FF_BILLABLE_MONTHS_INCLUDE')) {
        define('FF_BILLABLE_MONTHS_INCLUDE', true);
    }
    require_once FF_ROOT . '/api/v1/leases/billable_months.php';
    $bm = ff_billable_months($id);
    foreach (($bm['months'] ?? []) as $m) {
        if (($m['status'] ?? '') === 'unbilled') {
            $newBillableMonths[] = [
                'period_start' => $m['period_start'],
                'period_end'   => $m['period_end'],
                'billing_type' => $m['billing_type'],
                'label'        => $m['label'] ?? ($m['period_start'] . ' → ' . $m['period_end']),
            ];
        }
    }
}

json_success([
    'updated_at'          => $newUpdatedAt,
    'affected_drafts'     => $affectedDrafts,
    'new_billable_months' => $newBillableMonths,
    'orphaned_drafts'     => $orphanedDrafts,
]);
