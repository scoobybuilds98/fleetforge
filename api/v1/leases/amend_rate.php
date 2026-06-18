<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Rate Amendment API
 *
 * @file        api/v1/leases/amend_rate.php
 * @description Apply a prospective rate amendment to an active lease.
 *
 *              Closes the "rate fields immutable, amendment workflow not
 *              implemented" gap noted in api/v1/leases/update.php docblock
 *              lines 22 + 33-34. update.php silently dropped rate fields
 *              from its partial-update dispatch — this endpoint is now
 *              the authoritative path for changing daily/weekly/monthly/
 *              mileage rates on an active lease, with structured audit
 *              trail in lease_amendments (amendment_type='rate_change').
 *
 *              Operator-locked decisions (S-LEASE-RATE-AMENDMENT):
 *                D-A  Prospective only — new rate applies from the
 *                     amendment moment forward. Sent invoices remain
 *                     immutable per D14. Draft invoices for future
 *                     periods are SURFACED in the response (not
 *                     auto-regenerated) so the operator can review
 *                     before sending.
 *                D-B  No period-boundary restriction. Amend any time
 *                     while the lease is `active` or `pending`.
 *                D-C  Any admin can amend immediately — no approval
 *                     gate. audit_log + lease_amendments capture the
 *                     who/what/when paper trail.
 *                D-D  No automatic credit notes for already-sent
 *                     invoices. Manager issues credit notes manually
 *                     if a retroactive adjustment is wanted (out of
 *                     scope for this endpoint).
 *
 *              Schema strategy (S-LEASE-RATE-AMENDMENT operator
 *              decision): reuses the existing `lease_amendments` table
 *              via amendment_type='rate_change' with structured
 *              old_values / new_values JSON. NO new table created;
 *              the audit infrastructure shipped by AMEND-1 already
 *              covers the records-side of the rate-amendment workflow
 *              — this endpoint adds the actually-changes-the-lease
 *              side that was missing.
 *
 *              gps_cost (S-LEASE-RATE-AMENDMENT operator decision):
 *              remains mutable via api/v1/leases/update.php per the
 *              prior S-LEASE-GPS-COST decision; ALSO accepted here as
 *              one of the amendable fields so operators have two paths
 *              (audit-tracked via amend_rate, or quick-edit via update).
 *
 *              rate_method (S-LEASE-RATE-AMENDMENT operator decision):
 *              dropped — no `rate_method` column exists on `leases`.
 *              The runtime concept used by InvoiceGenerator is
 *              calculated per billing period from the daily/weekly/
 *              monthly rates and the period length; amending the
 *              numeric rates is the only persisted lever.
 *
 * @method      POST
 * @body        JSON — required: lease_id (int), updated_at (string,
 *                     D19 optimistic lock).
 *                     At least one of: new_daily_rate (decimal),
 *                     new_weekly_rate, new_monthly_rate,
 *                     new_mileage_rate_km, new_mileage_rate_miles,
 *                     new_gps_cost.
 *                     Optional: reason (string, max 2000 chars).
 *
 * @auth        Session required; require_permission('leases','edit').
 *              Per D-C any admin with leases:edit permission may amend.
 *
 * @returns     200 {
 *                 amendment_id: N,
 *                 effective_at: "Y-m-d H:i:s",
 *                 old_rates: { daily_rate, weekly_rate, monthly_rate,
 *                              mileage_rate_km, mileage_rate_miles,
 *                              gps_cost },
 *                 new_rates: { ...same shape... },
 *                 affected_drafts: [
 *                   { id, invoice_number, billing_period_start,
 *                     billing_period_end }, ...
 *                 ],
 *                 affected_draft_count: N,
 *                 lease_updated_at: "..." (new updated_at for
 *                                          subsequent optimistic-lock
 *                                          comparisons)
 *               }
 *              409 STALE_DATA       — optimistic-lock mismatch (D19)
 *              422 NOT_ACTIVE       — lease status not in (active, pending) (D-B)
 *              422 NO_RATE_PROVIDED — request had no amendable field
 *              422 INVALID_RATE     — a provided rate is negative
 *                                     or non-numeric
 *              404 NOT_FOUND        — lease missing or soft-deleted
 *
 * @depends     api/bootstrap.php
 * @decisions   D14 (sent-invoice immutability — preserved by D-A),
 *              D16 (bcmath), D19 (optimistic lock via updated_at),
 *              D20 (FOR UPDATE on lease in transaction)
 * @session     S-LEASE-RATE-AMENDMENT
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'edit');

$body      = json_body();
$leaseId   = clean_int($body['lease_id']    ?? null);
$updatedAt = clean_string($body['updated_at'] ?? null);
$reason    = trim((string) ($body['reason']  ?? ''));

if (!$leaseId)   json_error('MISSING_REQUIRED', 'lease_id is required.', 422);
if (!$updatedAt) json_error('MISSING_REQUIRED', 'updated_at is required for optimistic lock.', 422);

if ($reason !== '' && mb_strlen($reason) > 2000) {
    json_error('VALIDATION_ERROR', 'reason must be 2000 characters or fewer.', 422);
}

// ── Amendable rate field set ─────────────────────────────────────
// Map request keys to lease column names. Per operator decision the
// amendable set is the 6 persisted rate columns: daily / weekly /
// monthly base rentals, both mileage-rate units, and gps_cost.
// rate_method is omitted (no such column on leases). billing_cycle
// is omitted (different concern, not a rate per se).
$rateMap = [
    'new_daily_rate'         => 'daily_rate',
    'new_weekly_rate'        => 'weekly_rate',
    'new_monthly_rate'       => 'monthly_rate',
    'new_mileage_rate_km'    => 'mileage_rate_km',
    'new_mileage_rate_miles' => 'mileage_rate_miles',
    'new_gps_cost'           => 'gps_cost',
    'new_hourly_rate'        => 'hourly_rate',
];

// ── Collect + validate provided rates (bcmath per D16) ───────────
// VALIDATE-ALL-THEN-RESPOND: gather every error into $fields and
// emit one 422 at the end. Saves the operator from a 5-round-trip
// fix-one-error-at-a-time UX. Matches the discipline in update.php.
$provided = [];     // column_name => bcmath-clean decimal string
$fields   = [];     // request_key => message
foreach ($rateMap as $reqKey => $col) {
    if (!array_key_exists($reqKey, $body)) {
        continue;
    }
    $raw = $body[$reqKey];
    // Treat empty string as "skip" rather than "set to 0" — matches
    // the partial-update philosophy in update.php (absent vs blank).
    if ($raw === null || $raw === '') {
        continue;
    }
    $clean = clean_decimal($raw);
    if ($clean === null) {
        $fields[$reqKey] = 'Not a valid decimal value.';
        continue;
    }
    if (bccomp($clean, '0', 4) < 0) {
        $fields[$reqKey] = 'Rate cannot be negative.';
        continue;
    }
    // Round to the column's storage precision. daily/weekly/monthly/
    // gps_cost are DECIMAL(10,2); mileage_rate_* and hourly_rate are DECIMAL(10,4).
    // bcround keeps the trailing zeros that DECIMAL serializes back.
    $scale = in_array($col, ['mileage_rate_km', 'mileage_rate_miles', 'hourly_rate'], true) ? 4 : 2;
    $provided[$col] = bcround($clean, $scale);
}

if ($fields !== []) {
    json_error('INVALID_RATE', 'One or more rate fields failed validation.', 422, ['fields' => $fields]);
}

if ($provided === []) {
    json_error('NO_RATE_PROVIDED',
        'At least one of new_daily_rate, new_weekly_rate, new_monthly_rate, '
        . 'new_mileage_rate_km, new_mileage_rate_miles, new_gps_cost, or new_hourly_rate must '
        . 'be provided.',
        422);
}

// ── Transactional apply ──────────────────────────────────────────
// Order:
//   1. FOR UPDATE select on leases (D20) — locks the row for the
//      lifetime of the transaction so a concurrent writer (manual
//      SQL, admin tool, another amend_rate request) can't race the
//      old-rate snapshot. Pre-flight status + updated_at + soft-
//      delete checks happen post-lock to be authoritative.
//   2. Optimistic lock (D19) — compare client's updated_at against
//      the FOR-UPDATE row. Mismatch → 409 STALE_DATA.
//   3. UPDATE leases SET (provided columns), updated_at = NOW().
//   4. INSERT lease_amendments with structured old_values /
//      new_values JSON (amendment_type='rate_change').
//   5. SELECT affected drafts (status='draft' + future periods).
//   6. audit_log entry.
//   7. Build response payload.

$response = null;

db_transaction(function () use (
    $leaseId, $updatedAt, $provided, $reason, &$response
) {
    // 1 — fetch + lock lease row
    $lease = db_row(
        "SELECT id, status, contract_number, company_name_snapshot,
                billing_cycle,
                daily_rate, weekly_rate, monthly_rate,
                mileage_rate_km, mileage_rate_miles, gps_cost, hourly_rate,
                updated_at
         FROM leases
         WHERE id = ? AND deleted_at IS NULL
         FOR UPDATE",
        [$leaseId]
    );

    if (!$lease) {
        json_error('NOT_FOUND', 'Lease not found.', 404);
    }

    // 2 — status gate (D-B: active + pending allowed) + optimistic lock (D19)
    // Pending leases have no invoices, so rate amendments are safe and useful
    // for fixing rates before activation (e.g. correcting GPS cost before start).
    if (!in_array($lease['status'], ['active', 'pending'], true)) {
        json_error('NOT_ACTIVE',
            "Lease {$lease['contract_number']} is {$lease['status']}; "
            . 'rate amendments are only permitted on active or pending leases. '
            . 'Sent invoices remain immutable per D14.',
            422);
    }
    if (!optimistic_lock_matches($updatedAt, $lease['updated_at'])) {
        json_error('STALE_DATA',
            'This lease was modified by another user. Reload to get the latest version.',
            409);
    }

    // Snapshot old rates BEFORE the update so the lease_amendments
    // row carries the actual pre-amendment values (not the merged
    // half-state). Cast to string for JSON consistency — DECIMAL
    // round-trips through PHP as string when ATTR_STRINGIFY_FETCHES
    // is false, but defensive cast keeps the JSON shape stable.
    $oldRates = [
        'daily_rate'         => (string) $lease['daily_rate'],
        'weekly_rate'        => (string) $lease['weekly_rate'],
        'monthly_rate'       => (string) $lease['monthly_rate'],
        'mileage_rate_km'    => $lease['mileage_rate_km']    !== null ? (string) $lease['mileage_rate_km']    : null,
        'mileage_rate_miles' => $lease['mileage_rate_miles'] !== null ? (string) $lease['mileage_rate_miles'] : null,
        'gps_cost'           => (string) $lease['gps_cost'],
        'hourly_rate'        => $lease['hourly_rate'] !== null ? (string) $lease['hourly_rate'] : null,
    ];

    // Merge new rates: only overwrite the columns the request
    // actually provided. Unprovided columns keep their old value.
    $newRates = $oldRates;
    foreach ($provided as $col => $val) {
        $newRates[$col] = $val;
    }

    // ── D132 rate-tier completeness (mirror of leases/create.php) ────
    // An amendment must not leave a monthly lease with a rate-tier hole:
    // when any of daily/weekly/monthly is > 0, ALL must be > 0. The
    // holistic engine prorates partial periods (partial_start/partial_end)
    // off the daily/weekly tiers, so a zero tier silently yields $0
    // base_rental and a bogus reconciliation credit. create.php already
    // enforces this; update.php blocks rate edits and routes here — so
    // amend_rate.php was the one remaining path that could re-open the
    // hole. Evaluate the EFFECTIVE post-amendment tiers (merged old +
    // provided), not just the fields this request touched, so amending a
    // single tier is validated against the resulting row.
    if (($lease['billing_cycle'] ?? 'monthly') === 'monthly') {
        $effectiveTiers = [
            'daily_rate'   => (string) $newRates['daily_rate'],
            'weekly_rate'  => (string) $newRates['weekly_rate'],
            'monthly_rate' => (string) $newRates['monthly_rate'],
        ];
        $anyTierPositive = false;
        foreach ($effectiveTiers as $v) {
            if (bccomp($v, '0', 4) > 0) { $anyTierPositive = true; break; }
        }
        if ($anyTierPositive) {
            $holes = [];
            foreach ($effectiveTiers as $col => $v) {
                if (bccomp($v, '0', 4) <= 0) { $holes[] = $col; }
            }
            if ($holes !== []) {
                json_error('RATE_TIER_INCOMPLETE',
                    'Monthly leases require all three rate tiers (daily, weekly, monthly) '
                    . 'to be greater than zero when any tier is set (D132). This amendment '
                    . 'would leave these at zero: ' . implode(', ', $holes) . '.',
                    422,
                    ['fields' => array_fill_keys(
                        array_map(static fn(string $c): string => 'new_' . $c, $holes),
                        'Must be > 0 for a monthly lease when other rate tiers are set.'
                    )]
                );
            }
        }
    }

    // 3 — UPDATE leases. Build the SET list dynamically so we only
    // touch the columns the request provided. updated_at is always
    // bumped so the next optimistic-lock comparison sees a fresh value.
    $setParts = [];
    $bindings = [];
    foreach ($provided as $col => $val) {
        $setParts[] = "{$col} = ?";
        $bindings[] = $val;
    }
    $setParts[] = 'updated_at = NOW()';
    $bindings[] = $leaseId;

    $updateSql = 'UPDATE leases SET ' . implode(', ', $setParts) . ' WHERE id = ?';
    db_execute($updateSql, $bindings);

    // 4 — INSERT lease_amendments row. amendment_type='rate_change'
    // reuses the existing AMEND-1 audit table; structured JSON
    // captures the rate snapshot. description carries a human-
    // readable summary derived from the changed fields so the
    // existing Amendments tab in show.php renders meaningfully
    // without code changes.
    $changedCols = array_keys($provided);
    $summaryParts = [];
    foreach ($changedCols as $col) {
        $summaryParts[] = sprintf(
            '%s: %s → %s',
            $col,
            $oldRates[$col] ?? 'NULL',
            $newRates[$col] ?? 'NULL'
        );
    }
    $description = 'Rate amendment via amend_rate.php: ' . implode('; ', $summaryParts);
    if ($reason !== '') {
        $description .= ' — ' . $reason;
    }

    $amendmentId = db_insert('lease_amendments', [
        'lease_id'       => $leaseId,
        'amendment_type' => 'rate_change',
        'description'    => $description,
        'old_values'     => json_encode($oldRates),
        'new_values'     => json_encode($newRates),
        'created_by'     => current_user_id(),
        'created_at'     => date('Y-m-d H:i:s'),
    ]);

    // 5 — affected draft invoices for future periods (audit only;
    // no auto-regeneration per D-A / D-D).
    $affectedDrafts = db_select(
        "SELECT id, invoice_number, billing_period_start, billing_period_end
         FROM invoices
         WHERE lease_id = ?
           AND status = 'draft'
           AND billing_period_start > NOW()
           AND deleted_at IS NULL
         ORDER BY billing_period_start ASC, id ASC",
        [$leaseId]
    );

    // 6 — audit_log entry. Captures the change at the system layer
    // alongside the application-layer lease_amendments record.
    // entity_type uses a descriptive label ('lease_rate_amendment')
    // distinct from 'lease' so SIEM / forensic queries can isolate
    // rate-amendment events cleanly. action='update' is the closest
    // ENUM value (no 'rate_amendment' action exists; D102 workaround
    // pattern — use 'update' + descriptive entity_type).
    \db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'update',
        'module'      => 'leases',
        'entity_type' => 'lease_rate_amendment',
        'entity_id'   => $leaseId,
        'entity_label'=> $lease['contract_number'],
        'old_values'  => json_encode($oldRates),
        'new_values'  => json_encode(array_merge($newRates, [
            'amendment_id'         => (int) $amendmentId,
            'affected_draft_count' => count($affectedDrafts),
        ])),
        'notes'       => $description,
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // 7 — re-fetch the post-update updated_at so the client can use
    // it for any immediate follow-up update.php / amend_rate call
    // (skips a round trip + a STALE_DATA retry).
    $freshLease = db_row(
        'SELECT updated_at FROM leases WHERE id = ?',
        [$leaseId]
    );

    $response = [
        'amendment_id'         => (int) $amendmentId,
        'effective_at'         => date('Y-m-d H:i:s'),
        'old_rates'            => $oldRates,
        'new_rates'            => $newRates,
        'affected_drafts'      => $affectedDrafts,
        'affected_draft_count' => count($affectedDrafts),
        'lease_updated_at'     => $freshLease['updated_at'] ?? null,
    ];
});

json_success($response);
