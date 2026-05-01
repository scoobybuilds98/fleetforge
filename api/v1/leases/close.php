<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Close API
 *
 * @file        api/v1/leases/close.php
 * @description Transitions a lease from 'active' → 'completed'.
 *
 *              State machine (spec §6):
 *                active → completed (this endpoint)
 *                active → cancelled (via cancel endpoint — future session)
 *
 *              Concurrency (D20): FOR UPDATE lock on equipment_unit prevents
 *              race with monthly invoice cron (which also writes to the lease).
 *
 *              On close:
 *              1. Validate lease is active
 *              2. FOR UPDATE lock on equipment_unit
 *              3. Update lease: status → 'completed', actual_return_date, closed_at, closed_by
 *              4. Update equipment_unit: status → 'available'
 *              5. Write equipment_status_log
 *              6. Write lease_status_log
 *              7. Write audit_log
 *
 *              Final invoice generation (mileage reconciliation) is stubbed here;
 *              InvoiceGenerator will be called from this point in S009+.
 *
 * @method      POST
 * @body        JSON — id (required), actual_return_date (optional, defaults today),
 *              mileage_at_end (optional), close_notes (optional),
 *              odometer_at_close_km (optional, SAMSARA-3),
 *              odometer_source (optional, SAMSARA-3),
 *              odometer_fetched_at (optional, SAMSARA-3)
 * @auth        Session required; require_permission('leases','edit')
 * @returns     200 { id, status } | 409 INVALID_TRANSITION | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases, §12 Final Invoice [PASS-3:2C]
 * @decisions   D20 (FOR UPDATE on unit)
 * @session     S007, SAMSARA-3 (closing odometer on final invoice)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'edit');

$body = json_body();
$id   = clean_int($body['id'] ?? null);

if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$actualReturnDate = clean_date($body['actual_return_date'] ?? null) ?? date('Y-m-d');
$mileageAtEnd     = clean_int($body['mileage_at_end'] ?? null);
$closeNotes       = clean_string($body['close_notes'] ?? null, 5000);

// ADV-BILL-1 D-H: reconciliation mode for leases that activated with an advance batch.
// 'refund_unused' (default) → void/credit unused future advance invoices and refund the
// unused portion of the containing-period invoice. 'no_refund' → customer forfeits the
// prepaid future periods (used when the customer agreed to keep the full prepaid amount).
// Ignored entirely on leases without an advance batch.
$reconMode = $body['reconciliation_mode'] ?? 'refund_unused';
if (!in_array($reconMode, ['refund_unused', 'no_refund'], true)) {
    json_error('VALIDATION_ERROR',
        "reconciliation_mode must be 'refund_unused' or 'no_refund'.", 422);
}

// SAMSARA-3: optional closing odometer (decimal km) — stored on the final
// invoice as odometer_at_period_end_km so the invoice can show per-period
// and cumulative distance.
$odoAtClose     = null;
$odoSource      = null;
$odoFetchedAt   = null;

if (isset($body['odometer_at_close_km']) && $body['odometer_at_close_km'] !== '' && $body['odometer_at_close_km'] !== null) {
    $dec = clean_decimal($body['odometer_at_close_km']);
    if ($dec !== null && bccomp($dec, '0', 2) >= 0) {
        $odoAtClose = $dec;
        $srcRaw = $body['odometer_source'] ?? null;
        $odoSource = in_array($srcRaw, ['gps', 'manual'], true) ? $srcRaw : 'manual';
        if ($odoSource === 'gps' && !empty($body['odometer_fetched_at'])) {
            try {
                $dt = new DateTime((string) $body['odometer_fetched_at']);
                $odoFetchedAt = $dt->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                $odoFetchedAt = null;
            }
        }
    }
}

/**
 * ADV-BILL-1 D-H helper: void a draft advance invoice or issue a full credit_note.
 *
 * Returns an action descriptor for the API response (so the UI can show what
 * happened to each prepaid period).
 *
 * @param array  $inv   advance invoice row (id, invoice_number, status, billing_period_*, total_amount, currency)
 * @param array  $lease lease row (customer_id, contract_number)
 * @param string $reason free-text reason captured on void/credit_note
 */
function adv_void_or_credit_full(array $inv, array $lease, string $reason): array
{
    if ($inv['status'] === 'draft') {
        // Draft → void. Mirror api/v1/invoices/void.php exactly so denormalized
        // counters and accounting JE reversal stay correct (Trap 6 + A8/§16).
        adv_void_invoice($inv, $lease, $reason);
        return [
            'invoice_id'     => (int) $inv['id'],
            'invoice_number' => $inv['invoice_number'],
            'action'         => 'voided',
        ];
    }

    // Sent / paid / partial → leave invoice intact, issue a full credit_note.
    return adv_create_credit_note($inv, $lease, (string) $inv['total_amount'], $reason);
}

/**
 * ADV-BILL-1 D-H helper: void an advance invoice.
 *
 * Mirrors api/v1/invoices/void.php so the Trap-6 counter reversal and the
 * accounting JE reversal both happen (otherwise lease.total_invoiced stays
 * inflated and accounting drifts from the invoice ledger).
 */
function adv_void_invoice(array $inv, array $lease, string $reason): void
{
    db_update('invoices', [
        'status'      => 'void',
        'voided_date' => date('Y-m-d'),
        'void_reason' => $reason,
        'voided_by'   => current_user_id(),
        'updated_by'  => current_user_id(),
    ], 'id = ?', [$inv['id']]);

    // Trap 6: reverse denormalized counters bumped at invoice insert time.
    db_execute(
        "UPDATE leases
            SET total_invoiced     = total_invoiced     - ?,
                outstanding_balance = outstanding_balance - ?,
                updated_at = NOW()
          WHERE id = ?",
        [(string) $inv['total_amount'], (string) $inv['balance_due'], (int) $lease['id']]
    );
    if (!empty($lease['customer_id'])) {
        db_execute(
            "UPDATE customers
                SET outstanding_balance = outstanding_balance - ?,
                    updated_at = NOW()
              WHERE id = ?",
            [(string) $inv['balance_due'], (int) $lease['customer_id']]
        );
    }

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'status_change',
        'module'       => 'invoices',
        'entity_type'  => 'invoice',
        'entity_id'    => $inv['id'],
        'entity_label' => $inv['invoice_number'],
        'notes'        => "Advance invoice {$inv['invoice_number']} voided on lease close: {$reason}",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    \FleetForge\Accounting\AutoEntryBridge::onInvoiceVoided((int) $inv['id'], current_user_id());
}

/**
 * ADV-BILL-1 D-H helper: refund the unused portion of the containing-period invoice.
 *
 * Draft → modify the existing invoice in place: shorten period to actual_return_date
 *         and recompute amounts via createFromLease. Implementation: void the draft and
 *         spawn a replacement (functionally identical to in-place edit, gives us tested
 *         math without duplicating ProRateCalculator/TaxCalculator logic).
 * Sent/paid → issue a credit_note for the prorated unused amount.
 */
function adv_partial_refund_containing(array $inv, array $lease, string $returnDate, string $reason): array
{
    $start = $inv['billing_period_start'];
    $end   = $inv['billing_period_end'];

    // D14 inclusive day counting.
    $totalDays = (int)(new \DateTimeImmutable($end))->diff(new \DateTimeImmutable($start))->days + 1;
    $usedDays  = (int)(new \DateTimeImmutable($returnDate))->diff(new \DateTimeImmutable($start))->days + 1;
    if ($usedDays < 1)         $usedDays = 1;
    if ($usedDays > $totalDays) $usedDays = $totalDays;
    $unusedDays = $totalDays - $usedDays;

    if ($unusedDays <= 0) {
        // Returning on the last day of the period — nothing to refund.
        return [
            'invoice_id'     => (int) $inv['id'],
            'invoice_number' => $inv['invoice_number'],
            'action'         => 'no_refund_needed',
        ];
    }

    if ($inv['status'] === 'draft') {
        // Void the draft (with counter + JE reversal) then regenerate at the shortened period.
        adv_void_invoice($inv, $lease, $reason);

        $generator   = new \FleetForge\Billing\InvoiceGenerator();
        $billingType = ((new \DateTimeImmutable($start))->format('Y-m-01') === $start)
            ? 'partial_end' : 'partial_start';
        $replacement = $generator->createFromLease([
            'lease_id'          => (int) $lease['id'],
            'period_start'      => $start,
            'period_end'        => $returnDate,
            'billing_type'      => $billingType,
            'invoice_type'      => 'regular',
            'notes'             => "Replaces voided advance invoice {$inv['invoice_number']} (lease closed {$returnDate}).",
            'created_by'        => current_user_id(),
            'auto_generated'    => 1,
            'generation_source' => 'lease_close',
        ]);

        // Replacement invoice link recorded as a follow-up audit entry; the
        // void itself was already audited inside adv_void_invoice().
        db_insert('audit_log', [
            'user_id'      => current_user_id(),
            'user_name'    => current_user()['name'] ?? 'system',
            'action'       => 'create',
            'module'       => 'invoices',
            'entity_type'  => 'invoice',
            'entity_id'    => $replacement['invoice_id'],
            'entity_label' => $replacement['invoice_number'],
            'notes'        => "Replacement invoice {$replacement['invoice_number']} for voided advance invoice {$inv['invoice_number']} (lease closed {$returnDate}).",
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);

        return [
            'invoice_id'              => (int) $inv['id'],
            'invoice_number'          => $inv['invoice_number'],
            'action'                  => 'replaced',
            'replacement_invoice_id'  => (int) $replacement['invoice_id'],
            'replacement_invoice_number' => $replacement['invoice_number'],
        ];
    }

    // Sent / paid → credit_note for the unused portion (D16 bcmath, 6dp interim).
    $totalAmt   = (string) $inv['total_amount'];
    $unusedFrac = bcdiv((string) $unusedDays, (string) $totalDays, 6);
    $refundAmt  = bcround(bcmul($totalAmt, $unusedFrac, 6), 2);
    if (bccomp($refundAmt, '0', 2) <= 0) {
        return [
            'invoice_id'     => (int) $inv['id'],
            'invoice_number' => $inv['invoice_number'],
            'action'         => 'no_refund_needed',
        ];
    }

    return adv_create_credit_note(
        $inv, $lease, $refundAmt,
        "Unused {$unusedDays} of {$totalDays} days on advance invoice {$inv['invoice_number']} — {$reason}"
    );
}

/**
 * ADV-BILL-1 D-H helper: create a credit_note tied to an advance invoice.
 *
 * Replicates the gap-free numbering pattern from api/v1/credit_notes/create.php
 * (FOR UPDATE on settings counter row) and posts the auto-JE so accounting stays
 * in sync. Inside the outer close() transaction.
 */
function adv_create_credit_note(array $inv, array $lease, string $amount, string $reason): array
{
    $year = date('Y');
    $key  = "credit_note.next_number.{$year}";
    $row  = db_row("SELECT `key`, `value` FROM settings WHERE `key` = ? FOR UPDATE", [$key]);
    $next = $row ? (int) $row['value'] : 1;
    $prefix = settings_get('credit_note.prefix', 'CN-CR');
    $cnNumber = sprintf('%s-%s-%05d', $prefix, $year, $next);
    if ($row) {
        db_execute("UPDATE settings SET `value` = ? WHERE `key` = ?", [$next + 1, $key]);
    } else {
        db_execute(
            "INSERT INTO settings (`key`, `value`, `group_name`) VALUES (?, ?, 'credit_notes')",
            [$key, $next + 1]
        );
    }

    $cnId = db_insert('credit_notes', [
        'credit_note_number' => $cnNumber,
        'customer_id'        => $lease['customer_id'],
        'lease_id'           => (int) $lease['id'],
        'source'             => 'invoice_adjustment',
        'source_invoice_id'  => (int) $inv['id'],
        'amount'             => $amount,
        'currency'           => $inv['currency'] ?? 'CAD',
        'amount_remaining'   => $amount,
        'status'             => 'active',
        'reason'             => $reason,
        'created_by'         => current_user_id(),
    ]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'invoices',
        'entity_type'  => 'credit_note',
        'entity_id'    => $cnId,
        'entity_label' => $cnNumber,
        'notes'        => "Credit note {$cnNumber} ({$inv['currency']} {$amount}) issued against advance invoice {$inv['invoice_number']} on lease close.",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // Auto-JE — same call site as api/v1/credit_notes/create.php.
    \FleetForge\Accounting\AutoEntryBridge::onCreditNoteIssued($cnId, current_user_id());

    return [
        'invoice_id'         => (int) $inv['id'],
        'invoice_number'     => $inv['invoice_number'],
        'action'             => 'credit_note_issued',
        'credit_note_id'     => $cnId,
        'credit_note_number' => $cnNumber,
        'amount'             => $amount,
    ];
}

$result = null;

db_transaction(function () use ($id, $actualReturnDate, $mileageAtEnd, $closeNotes, $odoAtClose, $odoSource, $odoFetchedAt, $reconMode, &$result) {
    // ── Fetch lease ────────────────────────────────────────────
    // SAMSARA-3: include odometer_start_km so we can derive the period
    // start odometer for the final invoice when the user supplies a
    // closing odometer without an explicit start value.
    $lease = db_row(
        "SELECT id, status, contract_number, company_name_snapshot, customer_id,
                equipment_unit_id, unit_number_snapshot, mileage_at_start,
                mileage_rate, mileage_unit, estimated_mileage, mileage_precharge_amount,
                start_date, last_billed_date, odometer_start_km,
                advance_billing_periods, currency
         FROM leases WHERE id = ? AND deleted_at IS NULL",
        [$id]
    );

    if (!$lease) {
        json_error('NOT_FOUND', 'Lease not found.', 404);
    }

    // ── State machine validation ───────────────────────────────
    if ($lease['status'] !== 'active') {
        json_error('INVALID_TRANSITION',
            "Cannot close lease {$lease['contract_number']}: current status is '{$lease['status']}'. Only active leases can be closed.", 409);
    }

    // ── D20: FOR UPDATE lock on unit ───────────────────────────
    // Prevents race with monthly billing cron that also reads/writes the lease
    $unit = db_row(
        "SELECT id, unit_number, status
         FROM equipment_units
         WHERE id = ? AND deleted_at IS NULL
         FOR UPDATE",
        [$lease['equipment_unit_id']]
    );

    if (!$unit) {
        json_error('NOT_FOUND', 'Equipment unit not found.', 404);
    }

    // Validate mileage order — end must be >= start
    if ($mileageAtEnd !== null && $lease['mileage_at_start'] !== null) {
        if ($mileageAtEnd < (int) $lease['mileage_at_start']) {
            json_error('MILEAGE_DATA_ERROR',
                'End mileage cannot be less than start mileage.', 422);
        }
    }

    $user      = current_user();
    $changedBy = $user['name'] ?? 'system';

    // ── Update lease → completed ───────────────────────────────
    $leaseUpdate = [
        'status'             => 'completed',
        'actual_return_date' => $actualReturnDate,
        'closed_at'          => date('Y-m-d H:i:s'),
        'closed_by_user_id'  => current_user_id(),
        'updated_by'         => current_user_id(),
    ];
    if ($mileageAtEnd !== null) {
        $leaseUpdate['mileage_at_end'] = $mileageAtEnd;
        // Calculate actual mileage for reconciliation
        if ($lease['mileage_at_start'] !== null) {
            $leaseUpdate['actual_mileage'] = $mileageAtEnd - (int) $lease['mileage_at_start'];
        }
    }
    if ($closeNotes) {
        // FIX #37: append close_notes to internal_notes instead of replacing
        $existing = db_row("SELECT internal_notes FROM leases WHERE id = ?", [$id]);
        $prior = $existing['internal_notes'] ?? '';
        $leaseUpdate['internal_notes'] = $prior
            ? $prior . "\n---\nClose notes: " . $closeNotes
            : 'Close notes: ' . $closeNotes;
    }

    db_update('leases', $leaseUpdate, 'id = ?', [$id]);

    // ── Update unit → available ────────────────────────────────
    db_execute(
        "UPDATE equipment_units SET status = 'available', updated_by = ? WHERE id = ?",
        [current_user_id(), $lease['equipment_unit_id']]
    );

    // ── equipment_status_log ───────────────────────────────────
    db_insert('equipment_status_log', [
        'equipment_unit_id'  => $lease['equipment_unit_id'],
        'old_status'         => 'on_lease',
        'new_status'         => 'available',
        'reason'             => "Lease {$lease['contract_number']} closed",
        'changed_by'         => $changedBy,
        'changed_by_user_id' => current_user_id(),
    ]);

    // ── lease_status_log ───────────────────────────────────────
    db_insert('lease_status_log', [
        'lease_id'   => $id,
        'old_status' => 'active',
        'new_status' => 'completed',
        'notes'      => $closeNotes ?? 'Lease closed',
        'changed_by' => $changedBy,
        'user_id'    => current_user_id(),
    ]);

    // ── audit_log ──────────────────────────────────────────────
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => $changedBy,
        'action'       => 'lease_closed',
        'module'       => 'leases',
        'entity_type'  => 'lease',
        'entity_id'    => $id,
        'entity_label' => $lease['contract_number'],
        'notes'        => "Lease {$lease['contract_number']} closed — unit {$unit['unit_number']} → available",
        'old_values'   => json_encode(['status' => 'active']),
        'new_values'   => json_encode(['status' => 'completed', 'actual_return_date' => $actualReturnDate]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // ── ADV-BILL-1 D-H: detect prepaid advance batch ───────────
    // If activation generated advance invoices, branch into reconciliation.
    // Otherwise fall through to the legacy partial_end final invoice flow.
    $advanceInvoices = db_select(
        "SELECT id, invoice_number, status, billing_period_start, billing_period_end,
                subtotal, total_amount, balance_due, currency
         FROM invoices
         WHERE lease_id = ?
           AND generation_source = 'advance'
           AND deleted_at IS NULL
           AND status != 'void'
         ORDER BY billing_period_start ASC",
        [$id]
    );

    $isAdvanceClose = !empty($advanceInvoices);

    // Mileage overage shared between branches (computed once)
    $extraLines     = [];
    $hasMileageLine = false;
    if ($mileageAtEnd !== null && $lease['mileage_at_start'] !== null
        && bccomp((string)$lease['mileage_rate'], '0', 4) > 0)
    {
        $actualMileage    = (string)($mileageAtEnd - (int)$lease['mileage_at_start']);
        $includedMileage  = (string)($lease['estimated_mileage'] ?? '0');
        $overageMileage   = bcsub($actualMileage, $includedMileage, 4);
        if (bccomp($overageMileage, '0', 4) > 0) {
            $mileageCharge = bcround(bcmul($overageMileage, (string)$lease['mileage_rate'], 6), 2);
            if (bccomp($mileageCharge, '0', 2) > 0) {
                $extraLines[] = [
                    'item_type'   => 'mileage',
                    'description' => "Mileage overage: {$overageMileage} km × \${$lease['mileage_rate']}/km",
                    'quantity'    => $overageMileage,
                    'unit'        => $lease['mileage_unit'] ?? 'km',
                    'unit_price'  => (string)$lease['mileage_rate'],
                    'amount'      => $mileageCharge,
                    'is_credit'   => 0,
                    'taxable'     => 1,
                ];
                $hasMileageLine = true;
            }
        }
    }

    // SAMSARA-3: derive the period-start odometer for the final invoice.
    // Priority: latest prior invoice's period-end odometer → lease start.
    $odoPeriodStart = null;
    if ($odoAtClose !== null) {
        $prev = db_row(
            "SELECT odometer_at_period_end_km
               FROM invoices
              WHERE lease_id = ? AND deleted_at IS NULL
                AND odometer_at_period_end_km IS NOT NULL
              ORDER BY billing_period_end DESC, id DESC LIMIT 1",
            [$id]
        );
        if ($prev && $prev['odometer_at_period_end_km'] !== null) {
            $odoPeriodStart = $prev['odometer_at_period_end_km'];
        } elseif ($lease['odometer_start_km'] !== null) {
            $odoPeriodStart = $lease['odometer_start_km'];
        }
    }

    $generator    = new \FleetForge\Billing\InvoiceGenerator();
    $finalInvoiceId = null;
    $advanceActions = [];

    if ($isAdvanceClose) {
        // ── ADV-BILL-1 D-H: advance reconciliation ─────────────
        // Categorize each non-void advance invoice relative to actual_return_date.
        $containingInv = null;
        $futureInvs    = [];
        foreach ($advanceInvoices as $inv) {
            if ($actualReturnDate >= $inv['billing_period_start']
                && $actualReturnDate <= $inv['billing_period_end']) {
                $containingInv = $inv;
            } elseif ($inv['billing_period_start'] > $actualReturnDate) {
                $futureInvs[] = $inv;
            }
            // else: period entirely before return date — already consumed, leave alone.
        }

        if ($reconMode === 'refund_unused') {
            // Future invoices: full refund.
            foreach ($futureInvs as $inv) {
                $advanceActions[] = adv_void_or_credit_full(
                    $inv, $lease, $closeNotes ?: 'Lease closed early — period not used.'
                );
            }

            // Containing invoice: partial refund for unused days.
            if ($containingInv) {
                $advanceActions[] = adv_partial_refund_containing(
                    $containingInv, $lease, $actualReturnDate,
                    $closeNotes ?: 'Lease closed mid-period — refunding unused days.'
                );
            }
        }
        // else mode='no_refund': leave every advance invoice as-is.

        // Mileage overage: when present, always create a mileage_only adjustment invoice
        // (D-H drops the partial_end final invoice, so mileage needs its own home).
        if ($hasMileageLine) {
            $finalInv = $generator->createFromLease([
                'lease_id'          => $id,
                'period_start'      => $actualReturnDate,
                'period_end'        => $actualReturnDate,
                'billing_type'      => 'mileage_only',
                'invoice_type'      => 'final',
                'notes'             => $closeNotes,
                'created_by'        => current_user_id(),
                'auto_generated'    => 1,
                'generation_source' => 'lease_close',
                'extra_lines'       => $extraLines,
                'odometer_at_period_start_km' => $odoPeriodStart,
                'odometer_at_period_end_km'   => $odoAtClose,
                'odometer_source'             => $odoSource,
                'odometer_fetched_at'         => $odoFetchedAt,
            ]);
            $finalInvoiceId = $finalInv['invoice_id'] ?? null;
        }
    } else {
        // ── Legacy path: single final invoice ──────────────────
        // Per spec §12 [PASS-3:2C]: final period = day after last_billed_date (or
        // start_date if never billed) through actual_return_date. Pro-rated.
        $periodStart = $lease['last_billed_date']
            ? date('Y-m-d', strtotime($lease['last_billed_date'] . ' +1 day'))
            : $lease['start_date'];

        $invoiceResult = $generator->createFromLease([
            'lease_id'          => $id,
            'period_start'      => $periodStart,
            'period_end'        => $actualReturnDate,
            'billing_type'      => 'partial_end',
            'invoice_type'      => 'final',
            'notes'             => $closeNotes,
            'created_by'        => current_user_id(),
            'auto_generated'    => 1,
            'generation_source' => 'lease_close',
            'extra_lines'       => $extraLines,
            'odometer_at_period_start_km' => $odoPeriodStart,
            'odometer_at_period_end_km'   => $odoAtClose,
            'odometer_source'             => $odoSource,
            'odometer_fetched_at'         => $odoFetchedAt,
        ]);
        $finalInvoiceId = $invoiceResult['invoice_id'];
    }

    $result = [
        'id'              => $id,
        'status'          => 'completed',
        'invoice_id'      => $finalInvoiceId,
        'advance_close'   => $isAdvanceClose,
        'reconciliation'  => $isAdvanceClose ? $reconMode : null,
        'advance_actions' => $advanceActions,
    ];

    // ── In-app notifications (NOTIF-1) ─────────────────────────
    try {
        $unitNumber = $unit['unit_number'] ?? ($lease['unit_number_snapshot'] ?? '');
        \FleetForge\Notifications\NotificationService::notify(
            type:       'lease.closed',
            title:      "Lease {$lease['contract_number']} closed",
            message:    "Lease {$lease['contract_number']} closed — unit {$unitNumber} returned",
            entityType: 'lease',
            entityId:   $id,
            url:        '/fleetforge/leases/show?id=' . $id
        );
        if (!empty($lease['customer_id'])) {
            \FleetForge\Notifications\NotificationService::notifyPortal(
                type:       'lease.closed',
                customerId: (int) $lease['customer_id'],
                title:      "Your lease has been closed",
                message:    "Lease {$lease['contract_number']} has been closed.",
                entityType: 'lease',
                entityId:   $id,
                url:        '/fleetforge/portal/leases/show?id=' . $id
            );
        }
    } catch (\Throwable $e) {
        error_log('[NOTIF lease.closed] ' . $e->getMessage());
    }
});

json_success($result);
