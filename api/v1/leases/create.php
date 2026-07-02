<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Create API
 *
 * @file        api/v1/leases/create.php
 * @description Creates a new lease in 'pending' status.
 *
 *              Concurrency (D20): acquires FOR UPDATE lock on equipment_unit
 *              before checking availability — prevents two concurrent requests
 *              from double-leasing the same unit.
 *
 *              Snapshots frozen at creation: customer_name_snapshot,
 *              company_name_snapshot, unit_number_snapshot, template_name_snapshot,
 *              equipment_snapshot_json. These persist even if the customer/unit
 *              is later soft-deleted.
 *
 *              Tax rates (D11): looked up from customer's tax_rate_id at creation
 *              time and frozen on the lease (tax_rate_gst, tax_rate_pst, tax_rate_hst).
 *
 *              Contract number: if contract_number is submitted and non-empty, it is
 *              used verbatim (trimmed). A duplicate returns 422 CONTRACT_NUMBER_TAKEN.
 *              If blank, auto-generates CN-XXXXXX-YYYY (generate_random_code(6) + year)
 *              with collision retry loop (unlikely but safe).
 *              Duplicate detection spans soft-deleted leases (the contract_number
 *              UNIQUE index is global), and the INSERT is wrapped to translate a
 *              concurrent-race 1062 into the same 422 (Sentry FLEETFORGE-P).
 *
 *              Mileage (S-MILEAGE-UNIT-SIMPLIFY): accepts mileage_unit + single
 *              mileage_rate + single estimated_mileage. Counterpart columns
 *              (mileage_rate_km, mileage_rate_miles, estimated_mileage_km,
 *              estimated_mileage_miles) are derived using global settings factors
 *              (lease.km_to_miles_conversion / lease.miles_to_km_conversion)
 *              and snapshots those factors into km_to_miles_conversion /
 *              miles_to_km_conversion on the lease row.
 *
 * @method      POST
 * @body        JSON — customer_id, equipment_unit_id, start_date (required)
 *              daily_rate, weekly_rate, monthly_rate (at least one > 0, or mileage_rate)
 *              Optional: end_date, currency, mileage_unit, billing_cycle,
 *              gst_exempt, pst_exempt, discount_type, discount_value,
 *              insurance_opt_in, insurance_cost, warranty_opt_in, warranty_cost,
 *              gps_opt_in, gps_cost (S-LEASE-GPS-COST: per-day rate, defaults
 *              opt_in=true / cost=$1.00 if absent),
 *              po_number, notes, internal_notes, rate_notes, minimum_end_date,
 *              minimum_billing_days (S-LEASE-MIN-DAYS: short-lease floor frozen on
 *              the lease — absent inherits settings 'lease.minimum_billing_days'
 *              default 3; ''/null stores NULL = no minimum; numeric clamped 0..90,
 *              where 0/1 mean "no minimum")
 * @auth        Session required; require_permission('leases','create')
 * @returns     201 { id, contract_number } | 409 UNIT_UNAVAILABLE | 422 validation errors
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases, §12 billing
 * @decisions   D14 (day counting), D16 (bcmath), D19 (optimistic lock), D20 (FOR UPDATE)
 * @session     S007, S-LEASE-UNITS, S-MILEAGE-UNIT-SIMPLIFY
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'create');

$body = json_body();

// ════════════════════════════════════════════════════════════
// VALID-2: collect every validation error into $fields, then
// return them in one 422 response so the UI can show all
// problems at once instead of one-at-a-time.
// ════════════════════════════════════════════════════════════
$fields = [];

// ── Required fields ────────────────────────────────────────────
$customerId    = clean_int($body['customer_id'] ?? null);
$unitId        = clean_int($body['equipment_unit_id'] ?? null);
$startDate     = clean_date($body['start_date'] ?? null);
// S-LEASE-RENTAL-DAY-TIME: start_time is mandatory; end_time is optional.
$startTime     = clean_time($body['start_time'] ?? null);
$endTime       = clean_time($body['end_time'] ?? null);

if (!$customerId)  $fields['customer_id']        = 'Please select a customer.';
if (!$unitId)      $fields['equipment_unit_id']  = 'Please select an equipment unit.';
if (!$startDate)   $fields['start_date']         = 'Start date is required.';
if (!$startTime)   $fields['start_time']         = 'Lease start time is required.';

// S-LEASE-DATE-SANITY: reject an implausible start_date YEAR. clean_date() accepts
// any valid calendar date, so a backfill typo like 0001-03-02 passes — then at
// close the ~739k-day span overflows invoices.billing_period_days (smallint
// unsigned, max 65535) → SQLSTATE 22003/1264 → a cryptic "unexpected error".
// Allow historical backfill but block absurd past/future. (MTTS286 prod, 2026-06-26.)
if ($startDate && !isset($fields['start_date'])) {
    $startYear = (int) substr($startDate, 0, 4);
    $maxYear   = (int) date('Y') + 2;
    if ($startYear < 2000 || $startYear > $maxYear) {
        $fields['start_date'] = "Start date {$startDate} looks invalid — the year must be between 2000 and {$maxYear}.";
    }
}

// ── Rate fields — at least one must be > 0; negatives rejected ─
// VALID-2: use clean_decimal() so we can detect negatives — the
// old code used clean_non_negative_decimal() which silently
// coerced negatives to null/0, meaning a -$50 rate looked fine.
$dailyRateIn   = clean_decimal($body['daily_rate']   ?? null);
$weeklyRateIn  = clean_decimal($body['weekly_rate']  ?? null);
$monthlyRateIn = clean_decimal($body['monthly_rate'] ?? null);
$mileageRateIn = clean_decimal($body['mileage_rate'] ?? null);

if ($dailyRateIn   !== null && bccomp($dailyRateIn,   '0', 4) < 0) $fields['daily_rate']   = 'Daily rate cannot be negative.';
if ($weeklyRateIn  !== null && bccomp($weeklyRateIn,  '0', 4) < 0) $fields['weekly_rate']  = 'Weekly rate cannot be negative.';
if ($monthlyRateIn !== null && bccomp($monthlyRateIn, '0', 4) < 0) $fields['monthly_rate'] = 'Monthly rate cannot be negative.';
if ($mileageRateIn !== null && bccomp($mileageRateIn, '0', 4) < 0) $fields['mileage_rate'] = 'Mileage rate cannot be negative.';

$dailyRate   = ($dailyRateIn   !== null && bccomp($dailyRateIn,   '0', 4) >= 0) ? $dailyRateIn   : '0.00';
$weeklyRate  = ($weeklyRateIn  !== null && bccomp($weeklyRateIn,  '0', 4) >= 0) ? $weeklyRateIn  : '0.00';
$monthlyRate = ($monthlyRateIn !== null && bccomp($monthlyRateIn, '0', 4) >= 0) ? $monthlyRateIn : '0.00';
$mileageRate = ($mileageRateIn !== null && bccomp($mileageRateIn, '0', 4) >= 0) ? $mileageRateIn : '0.0000';

// Enforce: at least one rate must be positive (spec §7.5 docblock)
$anyMileageRate = bccomp($mileageRate, '0', 4) > 0;

if (
    !isset($fields['daily_rate']) && !isset($fields['weekly_rate']) &&
    !isset($fields['monthly_rate']) && !isset($fields['mileage_rate']) &&
    bccomp($dailyRate, '0', 4) <= 0 &&
    bccomp($weeklyRate, '0', 4) <= 0 &&
    bccomp($monthlyRate, '0', 4) <= 0 &&
    !$anyMileageRate
) {
    $fields['daily_rate'] = 'At least one rate (daily, weekly, monthly, or mileage) must be greater than zero.';
}

// ════════════════════════════════════════════════════════════════════════
// S-BILLING-RATE-FIX D-D / D132 — rate-tier completeness invariant
//
// When billing_cycle='monthly', all three rate tiers (daily/weekly/monthly)
// must be present in the request body AND form a complete set: if any one
// is > 0, all must be > 0. Closes the upstream hole that allowed leases to
// be created with weekly_rate=0 while other tiers were populated, which
// silently produced $0 base_rental for 8-29 day periods (the weekly-math
// branch of ProRateCalculator computed full_weeks*0 + remainder*0/7 = 0
// without triggering the "exceeds monthly" cap).
//
// Origin: 2026-05-06 audit of INV-2026-00086, locked as D132.
// ════════════════════════════════════════════════════════════════════════
$billingCycleRaw = (string)($body['billing_cycle'] ?? 'monthly');
if ($billingCycleRaw === 'monthly') {
    // Required-key check — relies on the form sending '0' for blank rate
    // inputs (Layer 1). A missing key would have been silently coerced to
    // '0.00' by the legacy clean_decimal path, hiding the defect.
    foreach (['daily_rate', 'weekly_rate', 'monthly_rate'] as $rateKey) {
        if (!array_key_exists($rateKey, $body) && !isset($fields[$rateKey])) {
            $fields[$rateKey] = "{$rateKey} is required when billing cycle is monthly.";
        }
    }

    // Zero-with-siblings rule. Skip if a per-field error already exists so
    // the user sees the most specific message (e.g. "cannot be negative").
    $rateValuesByField = [
        'daily_rate'   => $dailyRate,
        'weekly_rate'  => $weeklyRate,
        'monthly_rate' => $monthlyRate,
    ];
    $anyTierPositive = false;
    foreach ($rateValuesByField as $v) {
        if (bccomp((string)$v, '0', 4) > 0) { $anyTierPositive = true; break; }
    }
    if ($anyTierPositive) {
        foreach ($rateValuesByField as $rateKey => $v) {
            if (!isset($fields[$rateKey]) && bccomp((string)$v, '0', 4) <= 0) {
                $fields[$rateKey] = "Rate tier {$rateKey} must be > 0 when other rate tiers are populated. Use 0 explicitly only if this rate tier is intentionally not offered for this lease.";
            }
        }
    }
}

// ── Mileage conversion factors (S-MILEAGE-UNIT-SIMPLIFY) ───────────────────
// Always derived from global settings. Per-lease overrides removed —
// conversion factors are configured once in Settings → General and
// snapshots into each lease at creation.
$kmToMilesFinal = bcround(
    (string)(settings_get('lease.km_to_miles_conversion', '0.621371') ?? '0.621371'), 6);
$milesToKmFinal = bcround(
    (string)(settings_get('lease.miles_to_km_conversion', '1.609344') ?? '1.609344'), 6);

// ── Optional fields ────────────────────────────────────────────
$endDate        = clean_date($body['end_date'] ?? null);
$currency       = in_array($body['currency'] ?? '', ['CAD','USD']) ? $body['currency'] : 'CAD';
$mileageUnit    = in_array($body['mileage_unit'] ?? '', ['km','miles']) ? $body['mileage_unit'] : 'km';
$billingCycle   = in_array($body['billing_cycle'] ?? '', ['monthly','on_close_only']) ? $body['billing_cycle'] : 'monthly';
$gstExempt      = isset($body['gst_exempt']) ? (bool) $body['gst_exempt'] : null;
$pstExempt      = isset($body['pst_exempt']) ? (bool) $body['pst_exempt'] : null;
$discountType   = in_array($body['discount_type'] ?? '', ['none','percentage','flat']) ? $body['discount_type'] : 'none';

$discountValueIn = clean_decimal($body['discount_value'] ?? null);
if ($discountValueIn !== null && bccomp($discountValueIn, '0', 4) < 0) {
    $fields['discount_value'] = 'Discount value cannot be negative.';
}
$discountValue = ($discountValueIn !== null && bccomp($discountValueIn, '0', 4) >= 0) ? $discountValueIn : '0.0000';
if ($discountType === 'percentage' && bccomp($discountValue, '100', 4) > 0) {
    $fields['discount_value'] = 'Discount cannot exceed 100%.';
}

$insuranceOptIn = isset($body['insurance_opt_in']) ? (bool) $body['insurance_opt_in'] : false;
$insuranceCostIn = clean_decimal($body['insurance_cost'] ?? null);
if ($insuranceCostIn !== null && bccomp($insuranceCostIn, '0', 4) < 0) {
    $fields['insurance_cost'] = 'Insurance cost cannot be negative.';
}
$insuranceCost = ($insuranceCostIn !== null && bccomp($insuranceCostIn, '0', 4) >= 0) ? $insuranceCostIn : '0.00';

$warrantyOptIn  = isset($body['warranty_opt_in']) ? (bool) $body['warranty_opt_in'] : false;
$warrantyCostIn = clean_decimal($body['warranty_cost'] ?? null);
if ($warrantyCostIn !== null && bccomp($warrantyCostIn, '0', 4) < 0) {
    $fields['warranty_cost'] = 'Warranty cost cannot be negative.';
}
$warrantyCost = ($warrantyCostIn !== null && bccomp($warrantyCostIn, '0', 4) >= 0) ? $warrantyCostIn : '0.00';

// S-GPS-RATE-CARD: GPS opt-in toggle stays on the lease. GPS cost is no longer
// entered manually — the form auto-populates gps_cost from the rate card's
// gps_price via lookup_rates. Default to '0.00' (no billing) when absent so
// leases without a rate-card GPS price don't generate phantom GPS charges.
$gpsOptIn  = isset($body['gps_opt_in']) ? (bool) $body['gps_opt_in'] : true;
$gpsCostIn = clean_decimal($body['gps_cost'] ?? null);
if ($gpsCostIn !== null && bccomp($gpsCostIn, '0', 4) < 0) {
    $fields['gps_cost'] = 'GPS cost cannot be negative.';
}
$gpsCost = ($gpsCostIn !== null && bccomp($gpsCostIn, '0', 4) >= 0) ? $gpsCostIn : '0.00';

// S-LEASE-HOURLY-RATE: hourly rate captured from rate card; NULL = no hourly billing.
$hourlyCostIn = clean_decimal($body['hourly_rate'] ?? null);
if ($hourlyCostIn !== null && bccomp($hourlyCostIn, '0', 4) < 0) {
    $fields['hourly_rate'] = 'Hourly rate cannot be negative.';
}
$hourlyCost = ($hourlyCostIn !== null && bccomp($hourlyCostIn, '0', 4) >= 0) ? $hourlyCostIn : null;

// ── S-LEASE-MIN-DAYS: short-lease floor (Config Layer 2 — frozen on lease) ──
// Resolution rules (mirrors how clean_int rate fields above are ingested, but
// with a three-way present/absent/empty distinction so an operator can BOTH
// inherit the global default AND explicitly clear the minimum):
//   • key ABSENT from body  → inherit the global default
//       settings 'lease.minimum_billing_days' (Config Layer 3, default '3').
//   • present but ''/null   → store NULL (operator deliberately cleared it = no
//                             minimum on this lease; it does NOT re-inherit the
//                             global default — an explicit clear wins).
//   • present and numeric   → (int), clamped to 0..90. 0 and 1 are valid and
//                             mean "no minimum" (floor binds only when N >= 2).
// Non-numeric / out-of-range input is a hard validation error (no silent coerce),
// matching the negative-rate rejection idiom used for the rate fields above.
if (!array_key_exists('minimum_billing_days', $body)) {
    // Absent → inherit the global default floor.
    $minimumBillingDays = (int) settings_get('lease.minimum_billing_days', '3');
} else {
    $minDaysRaw = $body['minimum_billing_days'];
    if ($minDaysRaw === null || $minDaysRaw === '') {
        // Explicit clear → no per-lease minimum (NULL, not the global default).
        $minimumBillingDays = null;
    } elseif (is_numeric($minDaysRaw)) {
        $minDaysInt = (int) $minDaysRaw;
        if ($minDaysInt < 0 || $minDaysInt > 90) {
            $fields['minimum_billing_days'] = 'Minimum billing days must be between 0 and 90.';
            $minimumBillingDays = null;
        } else {
            $minimumBillingDays = $minDaysInt;
        }
    } else {
        $fields['minimum_billing_days'] = 'Minimum billing days must be a whole number between 0 and 90.';
        $minimumBillingDays = null;
    }
}

// ── S-LEASE-MILEAGE-MODE: per-lease mileage data source ────────
// 'manual' | 'off' | 'samsara'. Absent → 'off' (matches the DB column
// default; the operator must consciously opt into Manual or Samsara on the
// lease form). An out-of-enum value is a hard validation error (no silent
// coerce), matching the minimum_billing_days idiom above.
$mileageTrackingMode = 'off';
$mtmRaw = $body['mileage_tracking_mode'] ?? null;
if ($mtmRaw !== null && $mtmRaw !== '') {
    if (in_array($mtmRaw, ['manual', 'off', 'samsara'], true)) {
        $mileageTrackingMode = $mtmRaw;
    } else {
        $fields['mileage_tracking_mode'] = 'Invalid mileage tracking mode.';
    }
}

$poNumber       = clean_string($body['po_number'] ?? null, 100);
$notes          = clean_string($body['notes'] ?? null, 5000);
$internalNotes  = clean_string($body['internal_notes'] ?? null, 5000);
$rateNotes      = clean_string($body['rate_notes'] ?? null, 5000);

$estimatedMileageIn = clean_decimal($body['estimated_mileage'] ?? null);
if ($estimatedMileageIn !== null && bccomp($estimatedMileageIn, '0', 4) < 0) {
    $fields['estimated_mileage'] = 'Estimated mileage cannot be negative.';
}
$estimatedMileage = ($estimatedMileageIn !== null && bccomp($estimatedMileageIn, '0', 4) >= 0) ? $estimatedMileageIn : '0.00';

// S-MILEAGE-EST-DAILY: estimated distance driven PER DAY (lease mileage_unit).
// Drives the recurring mileage estimate line (days x per-day x rate) + running
// true-up in InvoiceGenerator. Dual-unit counterparts derived below alongside
// the allowance columns. Independent of the estimated_mileage allowance/precharge.
$estimatedPerDayIn = clean_decimal($body['estimated_mileage_per_day'] ?? null);
if ($estimatedPerDayIn !== null && bccomp($estimatedPerDayIn, '0', 4) < 0) {
    $fields['estimated_mileage_per_day'] = 'Estimated mileage per day cannot be negative.';
}
$estimatedPerDay = ($estimatedPerDayIn !== null && bccomp($estimatedPerDayIn, '0', 4) >= 0) ? $estimatedPerDayIn : '0.00';

$mileageAtStartRaw = $body['mileage_at_start'] ?? null;
if ($mileageAtStartRaw !== null && $mileageAtStartRaw !== '') {
    $mileageAtStartInt = clean_int($mileageAtStartRaw);
    if ($mileageAtStartInt === null || $mileageAtStartInt < 0) {
        $fields['mileage_at_start'] = 'Starting mileage cannot be negative.';
        $mileageAtStart = null;
    } else {
        $mileageAtStart = $mileageAtStartInt;
    }
} else {
    $mileageAtStart = null;
}

$minimumEndDate    = clean_date($body['minimum_end_date'] ?? null);

// ADV-BILL-1: advance_billing_periods — extra prepaid future periods generated
// at activation in addition to Invoice 1. Monthly only; capped by setting.
$advancePeriodsRaw = $body['advance_billing_periods'] ?? 0;
$advancePeriods    = clean_int($advancePeriodsRaw) ?? 0;
if ($advancePeriods < 0) {
    $fields['advance_billing_periods'] = 'Advance billing periods cannot be negative.';
    $advancePeriods = 0;
}
$advanceCap = (int) settings_get('billing.max_advance_periods', '24');
if ($advancePeriods > $advanceCap) {
    $fields['advance_billing_periods'] = "Advance billing periods cannot exceed {$advanceCap}.";
}
if ($advancePeriods > 0 && $billingCycle !== 'monthly') {
    $fields['advance_billing_periods'] = 'Advance billing is only available for monthly billing cycles.';
}

// ── SAMSARA-3: starting odometer (optional) ────────────────────
// odometer_start_km    — decimal km (allow negatives? no — odometer is monotonic)
// odometer_start_source— 'gps' or 'manual'; null when odometer_start_km is empty
// odometer_start_fetched_at — ISO datetime; only set by the client when source='gps'
$odometerStartKm       = null;
$odometerStartSource   = null;
$odometerStartFetchedAt = null;

$odoStartRaw = $body['odometer_start_km'] ?? null;
if ($odoStartRaw !== null && $odoStartRaw !== '') {
    $odoStartDec = clean_decimal($odoStartRaw);
    if ($odoStartDec === null || bccomp($odoStartDec, '0', 2) < 0) {
        $fields['odometer_start_km'] = 'Starting odometer cannot be negative.';
    } else {
        $odometerStartKm = $odoStartDec;
    }

    // Validate source when odometer has a value
    $srcRaw = $body['odometer_start_source'] ?? null;
    if ($srcRaw !== null && in_array($srcRaw, ['gps', 'manual'], true)) {
        $odometerStartSource = $srcRaw;
    } else {
        // Default to manual if client didn't supply a source
        $odometerStartSource = 'manual';
    }

    // fetched_at only meaningful when source is 'gps'
    if ($odometerStartSource === 'gps') {
        $fetchedAtRaw = $body['odometer_start_fetched_at'] ?? null;
        if ($fetchedAtRaw !== null && $fetchedAtRaw !== '') {
            // Parse ISO 8601 into MySQL datetime format
            try {
                $dt = new DateTime((string) $fetchedAtRaw);
                $odometerStartFetchedAt = $dt->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                // Ignore malformed timestamp — keep source but leave timestamp null
            }
        }
        if ($odometerStartFetchedAt === null) {
            // Stamp server-side so we always have an audit trail
            $odometerStartFetchedAt = date('Y-m-d H:i:s');
        }
    }

    // SAMSARA-3: auto-derive the legacy integer `mileage_at_start` column
    // from the new decimal odometer. This preserves all downstream consumers
    // (close.php overage math, reports, AI tools) without changing their
    // contracts. Convert km → miles when the lease is contracted in miles
    // (factor 0.621371, same as the existing close-modal conversion).
    // Only overrides $mileageAtStart when the request didn't already supply
    // one explicitly, so API callers that still send mileage_at_start
    // directly keep working.
    if ($mileageAtStart === null) {
        $kmVal = (float) $odometerStartKm;
        $mileageAtStart = ($mileageUnit === 'miles')
            ? (int) round($kmVal * 0.621371)
            : (int) round($kmVal);
    }
}

// ── S-LEASE-HOURLY-BILLING: starting engine/reefer hours (manual) ──
// Optional baseline for hours billing (only meaningful when hourly_rate>0).
// Validated non-negative; null when not supplied.
$engineHoursAtStart = null;
$ehsRaw = $body['engine_hours_at_start'] ?? null;
if ($ehsRaw !== null && $ehsRaw !== '') {
    $ehsDec = clean_decimal($ehsRaw);
    if ($ehsDec === null || bccomp($ehsDec, '0', 2) < 0) {
        $fields['engine_hours_at_start'] = 'Starting engine hours cannot be negative.';
    } else {
        $engineHoursAtStart = $ehsDec;
    }
}

// ── S-LEASE-SERVICE-CHARGES: cartage (one-time delivery charge) ──────
// Manual amount entered when delivering a unit; no global default. Bills once
// on the first invoice. NULL when not supplied.
$cartageAmount = null;
$cartRaw = $body['cartage_amount'] ?? null;
if ($cartRaw !== null && $cartRaw !== '') {
    $cartDec = clean_decimal($cartRaw);
    if ($cartDec === null || bccomp($cartDec, '0', 2) < 0) {
        $fields['cartage_amount'] = 'Cartage charge cannot be negative.';
    } else {
        $cartageAmount = $cartDec;
    }
}

// ── S-MILEAGE-1 Model B: precharge fields ──────────────────────
// precharge_enabled: 0/1 toggle, defaults off when not supplied.
// precharge_amount: required (>0) when enabled, NULL when disabled.
// CHECK constraint enforces this at the DB layer; here we provide
// the user-facing error message and reject early.
$prechargeEnabled = 0;
if (array_key_exists('precharge_enabled', $body)) {
    $rawEnabled = $body['precharge_enabled'];
    if ($rawEnabled === 0 || $rawEnabled === '0' || $rawEnabled === false) {
        $prechargeEnabled = 0;
    } elseif ($rawEnabled === 1 || $rawEnabled === '1' || $rawEnabled === true) {
        $prechargeEnabled = 1;
    } else {
        $fields['precharge_enabled'] = 'Precharge toggle must be 0 or 1.';
    }
}

$prechargeAmount = null;
if (array_key_exists('precharge_amount', $body) && $body['precharge_amount'] !== null && $body['precharge_amount'] !== '') {
    $amt = clean_decimal($body['precharge_amount']);
    if ($amt === null) {
        $fields['precharge_amount'] = 'Precharge amount must be a valid number.';
    } elseif (bccomp($amt, '0', 2) <= 0) {
        $fields['precharge_amount'] = 'Precharge amount must be greater than zero.';
    } else {
        $prechargeAmount = bcround($amt, 2);
    }
}

if ($prechargeEnabled === 1 && $prechargeAmount === null && !isset($fields['precharge_amount'])) {
    $fields['precharge_amount'] = 'Precharge amount is required when precharge is enabled.';
}
if ($prechargeEnabled === 0 && $prechargeAmount !== null) {
    // Don't fight the user — if they sent an amount but disabled, just clear it.
    $prechargeAmount = null;
}

// ════════════════════════════════════════════════════════════════════════
// S-MILEAGE-RATE-VALIDATION D-A / D133 — mileage rate-tier completeness
//
// Parallel to D132 D-D Layer 2 (rental rate-tier completeness above).
// Trigger fires on any intent signal that the lease will involve per-km
// billing: an estimated_mileage value > 0 (allowance configured) OR
// precharge_enabled = 1 (operator opted into the precharge model). When
// any trigger is true, mileage_rate must be > 0 (any of the three columns
// — legacy mileage_rate, dual-unit mileage_rate_km, dual-unit
// mileage_rate_miles).
//
// Permissive on the inverse: a lease with no allowance and no precharge
// can legitimately have rate=0 (no per-km charge expected). Only the
// "intent + zero rate" combination is rejected.
//
// Origin: closes the create-time hole that produced the zero-rate billing
// class S-MILEAGE-RATE-ZERO-FIX backfilled. See FLEETFORGE_PROGRESS.md
// D133 row + REFERENCE.md §13.8 mileage tier extension.
// ════════════════════════════════════════════════════════════════════════
// S-MILEAGE-EST-DAILY: an estimated_mileage_per_day > 0 is the strongest intent
// signal of all — the lease will bill an estimated mileage line every period, so
// a rate is mandatory. Folded into the same completeness gate.
$anyAllowancePositive = bccomp($estimatedMileage, '0', 4) > 0
    || bccomp($estimatedPerDay, '0', 4) > 0;
$intentSignalPresent  = $anyAllowancePositive || ($prechargeEnabled === 1);

if ($intentSignalPresent && !$anyMileageRate) {
    if (!isset($fields['mileage_rate'])) {
        $fields['mileage_rate'] = 'Mileage rate must be > 0 when an estimated mileage (per-day or allowance) or precharge is configured. Set a rate or clear the estimate.';
    }
}

// ── Date validation ─────────────────────────────────────────────
if ($startDate && $endDate && $endDate < $startDate) {
    $fields['end_date'] = 'End date must be after start date.';
}
if ($startDate && $minimumEndDate && $minimumEndDate < $startDate) {
    $fields['minimum_end_date'] = 'Minimum end date must be on or after start date.';
}

// ── Bail out if any validation errors collected ────────────────
if ($fields) {
    json_validation_error($fields);
}

// ── Derive dual-unit values from single rate + global factor ──────────────
// (S-MILEAGE-UNIT-SIMPLIFY) Form sends mileage_rate in the selected unit.
// Counterpart columns are derived here and snapshot the global factors.
//
// S-MILEAGE-RATE-CONVERT-FIX: a RATE ($/unit) converts with the INVERSE factor of
// a distance — a km is SHORTER than a mile, so $/km is LOWER than $/mile. So:
//   $/km   = $/mile × (miles-per-km = kmToMiles 0.621371)
//   $/mile = $/km   × (km-per-mile  = milesToKm 1.609344)
// Both lines previously used the DISTANCE factor (the wrong direction), which left
// mileage_rate_km inflated by milesToKm² ≈ 2.59× on every miles lease — the modern
// `mileage_usage` billing path (km_distance × mileage_rate_km) then over-charged by
// that factor. Distances below still scale directly (correct, unchanged).
if ($mileageUnit === 'km') {
    $rateKmFinal    = bcround($mileageRate, 4);
    $rateMilesFinal = bcround(bcmul($mileageRate, $milesToKmFinal, 8), 4); // $/mile = $/km × milesToKm
} else {
    $rateMilesFinal = bcround($mileageRate, 4);
    $rateKmFinal    = bcround(bcmul($mileageRate, $kmToMilesFinal, 8), 4); // $/km = $/mile × kmToMiles
}

if ($mileageUnit === 'km') {
    $allowKmFinal    = bcround($estimatedMileage, 3);
    $allowMilesFinal = bcround(bcmul($estimatedMileage, $kmToMilesFinal, 8), 3);
} else {
    $allowMilesFinal = bcround($estimatedMileage, 3);
    $allowKmFinal    = bcround(bcmul($estimatedMileage, $milesToKmFinal, 8), 3);
}

// S-MILEAGE-EST-DAILY: per-day estimate distances scale directly (a distance,
// not a rate) — km-canonical drives the billing math, miles is informational.
if ($mileageUnit === 'km') {
    $perDayKmFinal    = bcround($estimatedPerDay, 4);
    $perDayMilesFinal = bcround(bcmul($estimatedPerDay, $kmToMilesFinal, 8), 4);
} else {
    $perDayMilesFinal = bcround($estimatedPerDay, 4);
    $perDayKmFinal    = bcround(bcmul($estimatedPerDay, $milesToKmFinal, 8), 4);
}

// ── Fetch customer (for snapshot + tax defaults) ───────────────
$customer = db_row(
    "SELECT id, contact_name, company_name, province, currency, mileage_unit,
            gst_exempt, pst_exempt, gst_exempt_expiry, pst_exempt_expiry,
            billing_cycle, discount_type, discount_value, tax_rate_id
     FROM customers
     WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);
if (!$customer) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

// ── Resolve tax exemption (customer defaults if not overridden) ─
// D22: gst_exempt and pst_exempt are independent, frozen on lease at creation
$gstExemptFinal = $gstExempt ?? (bool) $customer['gst_exempt'];
$pstExemptFinal = $pstExempt ?? (bool) $customer['pst_exempt'];
// FIX #10: check exemption expiry against start_date (not today), because the
// exemption must still be valid when the lease actually starts, not just today.
if ($customer['gst_exempt_expiry'] && $customer['gst_exempt_expiry'] < $startDate) {
    $gstExemptFinal = false; // D22: expired by lease start date = not exempt
}
if ($customer['pst_exempt_expiry'] && $customer['pst_exempt_expiry'] < $startDate) {
    $pstExemptFinal = false;
}

// ── Look up tax rates (D11: at creation time from tax_rates table) ─
$taxRateGst = '0.0000';
$taxRatePst = '0.0000';
$taxRateHst = '0.0000';
if ($customer['tax_rate_id']) {
    $taxRow = db_row(
        "SELECT gst_rate, pst_rate, hst_rate FROM tax_rates
         WHERE id = ? AND is_active = 1",
        [$customer['tax_rate_id']]
    );
    if ($taxRow) {
        $taxRateGst = $taxRow['gst_rate'];
        $taxRatePst = $taxRow['pst_rate'];
        $taxRateHst = $taxRow['hst_rate'];
    }
}

// ── Build contract number: PREFIX-XXXXXX-YYYY ─────────────────
// WHY: prefix from settings so admin can rebrand without code change
$leasePrefix     = settings_get('lease.prefix', 'CN');
$year            = date('Y');
$suppliedCN      = trim($body['contract_number'] ?? '');

// leases.contract_number carries a GLOBAL UNIQUE index (FLEETFORGE_DATABASE_MASTER.sql
// — `UNIQUE KEY contract_number`) that spans soft-deleted rows too. db_exists()
// auto-appends `AND deleted_at IS NULL`, so it was BLIND to a soft-deleted lease
// still holding the number: the create passed this pre-check, then the INSERT
// collided on the index → 1062 → HTTP 500 (Sentry FLEETFORGE-P, events 97s apart =
// reuse-after-delete, not a sub-second race). Check against ALL rows so the
// pre-check matches what the index actually enforces — once used, a contract
// number is taken for good, exactly like users.email / customers (FLEETFORGE-F).
$contractNumberTaken = static fn(string $cn): bool =>
    db_count("SELECT COUNT(*) FROM leases WHERE contract_number = ?", [$cn]) > 0;

if ($suppliedCN !== '') {
    // User supplied a value — use it verbatim; reject duplicates explicitly.
    // WHY: silent re-generate would discard the operator's intent and produce
    // a number they didn't ask for, with no feedback.
    if ($contractNumberTaken($suppliedCN)) {
        json_error('CONTRACT_NUMBER_TAKEN',
            'Contract number ' . $suppliedCN . ' already in use.', 422);
    }
    $contractNumber = $suppliedCN;
} else {
    // Auto-generate; de-duplicate on collision (extremely rare with 6-char A-Z0-9 space)
    $contractNumber = $leasePrefix . '-' . generate_random_code(6) . '-' . $year;
    $attempt        = 0;
    while ($contractNumberTaken($contractNumber)) {
        $attempt++;
        if ($attempt > 20) {
            json_error('SERVER_ERROR', 'Could not generate unique contract number.', 500);
        }
        $contractNumber = $leasePrefix . '-' . generate_random_code(6) . '-' . $year;
    }
}

// ── Transaction: FOR UPDATE on unit + create lease ─────────────
$leaseId = null;

$createLease = function () use (
    $unitId, $customerId, $contractNumber, $customer,
    $startDate, $startTime, $endDate, $endTime, $minimumEndDate,
    $dailyRate, $weeklyRate, $monthlyRate, $mileageRate, $rateNotes,
    $currency, $mileageUnit, $billingCycle,
    $gstExemptFinal, $pstExemptFinal,
    $taxRateGst, $taxRatePst, $taxRateHst,
    $discountType, $discountValue,
    $insuranceOptIn, $insuranceCost, $warrantyOptIn, $warrantyCost,
    $gpsOptIn, $gpsCost,
    $poNumber, $notes, $internalNotes,
    $estimatedMileage, $mileageAtStart,
    $odometerStartKm, $odometerStartSource, $odometerStartFetchedAt,
    $advancePeriods,
    $rateKmFinal, $rateMilesFinal, $allowKmFinal, $allowMilesFinal,
    // S-MILEAGE-EST-DAILY: per-day estimate + its dual-unit mirrors. These are in
    // the db_insert array below — omit from use() and they resolve to NULL
    // (project_lease_create_closure_use_trap). per_day is NOT NULL DEFAULT 0.00.
    $estimatedPerDay, $perDayKmFinal, $perDayMilesFinal,
    $kmToMilesFinal, $milesToKmFinal,
    $prechargeEnabled, $prechargeAmount,
    $minimumBillingDays,
    // S-LEASE-MILEAGE-MODE / S-LEASE-HOURLY-BILLING / S-LEASE-SERVICE-CHARGES:
    // these are referenced in the db_insert below and MUST be captured, or they
    // resolve to undefined → NULL. mileage_tracking_mode is NOT NULL → 1048 abort.
    $hourlyCost, $mileageTrackingMode, $engineHoursAtStart, $cartageAmount,
    &$leaseId
) {
    // D20: FOR UPDATE — lock the unit row before status check
    // Prevents two concurrent create requests from both passing the availability check
    // equipment_units has no mileage_unit column — that field lives on customers/leases/templates
    $unit = db_row(
        "SELECT u.id, u.unit_number, u.status, u.vin, u.year, u.length_ft, u.width_ft,
                u.height_ft, u.axle_count, u.weight_capacity_lbs, u.license_plate,
                u.license_state, u.tracking_provider, u.mileage,
                t.name AS template_name, t.category
         FROM equipment_units u
         JOIN equipment_templates t ON t.id = u.template_id AND t.deleted_at IS NULL
         WHERE u.id = ? AND u.deleted_at IS NULL
         FOR UPDATE",
        [$unitId]
    );

    if (!$unit) {
        json_validation_error(
            ['equipment_unit_id' => 'Equipment unit not found.'],
            'Equipment unit not found.'
        );
    }

    if ($unit['status'] !== 'available') {
        // VALID-2: specific user-facing message per spec
        // "This unit is not available for the selected dates" / "already has an active lease"
        $isLeased = in_array($unit['status'], ['on_lease', 'reserved'], true);
        $msg = $isLeased
            ? "Unit {$unit['unit_number']} already has an active lease."
            : "Unit {$unit['unit_number']} is not available for the selected dates (status: {$unit['status']}).";
        json_error('UNIT_UNAVAILABLE', $msg, 409,
            ['fields' => ['equipment_unit_id' => $msg]]);
    }

    // ── Build snapshots ────────────────────────────────────────
    $customerNameSnapshot  = $customer['contact_name'] ?? null;
    $companyNameSnapshot   = $customer['company_name'];
    $unitNumberSnapshot    = $unit['unit_number'];
    $templateNameSnapshot  = $unit['template_name'];

    // Equipment snapshot JSON — captures unit specs at lease creation
    $equipmentSnapshotJson = json_encode([
        'unit_number'         => $unit['unit_number'],
        'template_name'       => $unit['template_name'],
        'category'            => $unit['category'],
        'vin'                 => $unit['vin'],
        'year'                => $unit['year'],
        'length_ft'           => $unit['length_ft'],
        'width_ft'            => $unit['width_ft'],
        'height_ft'           => $unit['height_ft'],
        'axle_count'          => $unit['axle_count'],
        'weight_capacity_lbs' => $unit['weight_capacity_lbs'],
        'license_plate'       => $unit['license_plate'],
        'license_state'       => $unit['license_state'],
        'tracking_provider'   => $unit['tracking_provider'],
        'mileage_at_snapshot' => $unit['mileage'], // equipment_units.mileage (odometer INT at snapshot time)
    ]);

    // ── Insert lease (status = 'pending') ──────────────────────
    $leaseId = db_insert('leases', [
        'contract_number'          => $contractNumber,
        'customer_id'              => $customerId,
        'equipment_unit_id'        => $unitId,
        'customer_name_snapshot'   => $customerNameSnapshot,
        'company_name_snapshot'    => $companyNameSnapshot,
        'unit_number_snapshot'     => $unitNumberSnapshot,
        'template_name_snapshot'   => $templateNameSnapshot,
        'equipment_snapshot_json'  => $equipmentSnapshotJson,
        'status'                   => 'pending',
        'start_date'               => $startDate,
        'start_time'               => $startTime,
        'end_date'                 => $endDate,
        'end_time'                 => $endTime,
        'minimum_end_date'         => $minimumEndDate,
        'daily_rate'               => $dailyRate,
        'weekly_rate'              => $weeklyRate,
        'monthly_rate'             => $monthlyRate,
        'mileage_rate'             => $mileageRate,
        'mileage_rate_km'          => $rateKmFinal,
        'mileage_rate_miles'       => $rateMilesFinal,
        'rate_notes'               => $rateNotes,
        'currency'                 => $currency,
        'mileage_unit'             => $mileageUnit,
        'billing_cycle'            => $billingCycle,
        'gst_exempt'               => $gstExemptFinal ? 1 : 0,
        'pst_exempt'               => $pstExemptFinal ? 1 : 0,
        'tax_exempt'               => ($gstExemptFinal && $pstExemptFinal) ? 1 : 0,
        'tax_rate_gst'             => $taxRateGst,
        'tax_rate_pst'             => $taxRatePst,
        'tax_rate_hst'             => $taxRateHst,
        'discount_type'            => $discountType,
        'discount_value'           => $discountValue,
        'insurance_opt_in'         => $insuranceOptIn ? 1 : 0,
        'insurance_cost'           => $insuranceCost,
        'warranty_opt_in'          => $warrantyOptIn ? 1 : 0,
        'warranty_cost'            => $warrantyCost,
        'gps_opt_in'               => $gpsOptIn ? 1 : 0,
        'gps_cost'                 => $gpsCost,
        'hourly_rate'              => $hourlyCost,
        'po_number'                => $poNumber,
        'notes'                    => $notes,
        'internal_notes'           => $internalNotes,
        // S-LEASE-MIN-DAYS: frozen short-lease floor (Config Layer 2). NULL = no
        // per-lease minimum; billing then consults rate-card item / global setting.
        'minimum_billing_days'     => $minimumBillingDays,
        // S-LEASE-MILEAGE-MODE: per-lease mileage data source (manual/off/samsara).
        'mileage_tracking_mode'    => $mileageTrackingMode,
        'estimated_mileage'        => $estimatedMileage,
        'estimated_mileage_km'     => $allowKmFinal,
        'estimated_mileage_miles'  => $allowMilesFinal,
        // S-MILEAGE-EST-DAILY: per-day estimate (drives the mileage estimate line
        // + true-up). km-canonical mirror is authoritative for the billing math.
        'estimated_mileage_per_day'       => $estimatedPerDay,
        'estimated_mileage_per_day_km'    => $perDayKmFinal,
        'estimated_mileage_per_day_miles' => $perDayMilesFinal,
        'km_to_miles_conversion'   => $kmToMilesFinal,
        'miles_to_km_conversion'   => $milesToKmFinal,
        'mileage_at_start'         => $mileageAtStart,
        'advance_billing_periods'  => $advancePeriods,
        // SAMSARA-3: starting odometer captured at lease start (decimal km)
        'odometer_start_km'        => $odometerStartKm,
        'odometer_start_source'    => $odometerStartSource,
        'odometer_start_fetched_at'=> $odometerStartFetchedAt,
        // S-LEASE-HOURLY-BILLING: manual starting engine/reefer hours baseline.
        'engine_hours_at_start'    => $engineHoursAtStart,
        // S-LEASE-SERVICE-CHARGES: one-time cartage (delivery) charge.
        'cartage_amount'           => $cartageAmount,
        // S-MILEAGE-1 Model B: precharge toggle + amount captured at create.
        // precharge_balance defaults to NULL here; activation in S-MILEAGE-2
        // will initialize it = precharge_amount when the lease activates.
        // (Per D-A: balance is not user-editable via API, set internally.)
        'precharge_enabled'        => $prechargeEnabled,
        'precharge_amount'         => $prechargeAmount,
        'created_by'               => current_user_id(),
        'updated_by'               => current_user_id(),
    ]);

    // Increment denormalized lease_count on both customer and unit (any status)
    db_execute("UPDATE customers SET lease_count = lease_count + 1, updated_at = NOW() WHERE id = ?", [$customerId]);
    db_execute("UPDATE equipment_units SET lease_count = lease_count + 1, updated_at = NOW() WHERE id = ?", [$unitId]);

    // ── FIX #16: Reserve unit — prevents a second pending lease ──
    // Unit status: available → reserved. Activate moves it to on_lease.
    // Cancel moves it back to available. This ensures UNIT_UNAVAILABLE
    // fires on a second create attempt for the same unit.
    db_execute(
        "UPDATE equipment_units SET status = 'reserved', updated_by = ?, updated_at = NOW() WHERE id = ?",
        [current_user_id(), $unitId]
    );
    db_insert('equipment_status_log', [
        'equipment_unit_id'  => $unitId,
        'old_status'         => 'available',
        'new_status'         => 'reserved',
        'reason'             => "Lease {$contractNumber} created — unit reserved pending activation",
        'changed_by'         => current_user()['name'] ?? 'system',
        'changed_by_user_id' => current_user_id(),
    ]);

    // ── lease_status_log: record initial status transition ─────
    db_insert('lease_status_log', [
        'lease_id'   => $leaseId,
        'old_status' => '',
        'new_status' => 'pending',
        'notes'      => 'Lease created',
        'changed_by' => current_user()['name'] ?? 'system',
        'user_id'    => current_user_id(),
    ]);

    // ── audit_log ──────────────────────────────────────────────
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'leases',
        'entity_type'  => 'lease',
        'entity_id'    => $leaseId,
        'entity_label' => $contractNumber,
        'notes'        => "Lease {$contractNumber} created for {$companyNameSnapshot}",
        'old_values'   => null,
        'new_values'   => json_encode([
            'contract_number'       => $contractNumber,
            'status'                => 'pending',
            'mileage_unit'          => $mileageUnit,
            'mileage_rate_km'       => $rateKmFinal,
            'mileage_rate_miles'    => $rateMilesFinal,
            'estimated_mileage_km'  => $allowKmFinal,
            'estimated_mileage_miles' => $allowMilesFinal,
            'estimated_mileage_per_day'    => $estimatedPerDay,
            'estimated_mileage_per_day_km' => $perDayKmFinal,
            'km_to_miles_conversion'  => $kmToMilesFinal,
            'miles_to_km_conversion'  => $milesToKmFinal,
            'precharge_enabled'     => $prechargeEnabled,
            'precharge_amount'      => $prechargeAmount,
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // ── In-app notification (NOTIF-1) ──────────────────────────
    // Wrapped in try/catch so notification failure NEVER rolls back the lease.
    try {
        \FleetForge\Notifications\NotificationService::notify(
            type:       'lease.created',
            title:      "New lease {$contractNumber}",
            message:    "Lease {$contractNumber} created for {$companyNameSnapshot}",
            entityType: 'lease',
            entityId:   $leaseId,
            url:        '/fleetforge/leases/show?id=' . $leaseId
        );
    } catch (\Throwable $e) {
        error_log('[NOTIF lease.created] ' . $e->getMessage());
    }
};

// Belt-and-suspenders for the TOCTOU race (Sentry FLEETFORGE-P): two concurrent
// creates — or a stale-draft resubmit (S-FORM-DRAFT-ROLLOUT) — carrying the same
// supplied contract_number can both pass the duplicate pre-check above, then one
// collides on the leases.contract_number UNIQUE index inside the transaction. The
// soft-delete blind spot is already closed by the pre-check; this covers the race.
// db_transaction
// has already rolled the row back by the time we catch here; translate the 1062
// into the same friendly 422 the pre-check returns instead of letting the
// PDOException surface as an HTTP 500. Mirrors the users/create.php FLEETFORGE-F fix.
// Guarded narrowly on the contract_number key so other 23000s (FK violations,
// other unique keys) still surface for real triage rather than being masked.
try {
    db_transaction($createLease);
} catch (\PDOException $e) {
    if ($e->getCode() === '23000' && stripos($e->getMessage(), 'contract_number') !== false) {
        json_error('CONTRACT_NUMBER_TAKEN',
            'Contract number ' . $contractNumber . ' already in use.', 422);
    }
    throw $e;
}

json_success(['id' => $leaseId, 'contract_number' => $contractNumber], 201);
