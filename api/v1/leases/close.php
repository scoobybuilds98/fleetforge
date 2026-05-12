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

// ── S-LEASE-MILEAGE: close-adjustment block (manager review) ───
// When the manager reviews excess/underage at lease close in the UI,
// the close request carries a `close_adjustment` block with the
// decision they made. Optional — when omitted, the legacy partial-
// month mileage-overage logic at the bottom of this file handles
// excess via the final invoice line items (backwards compat).
//
// Shape:
//   close_adjustment: {
//     decision: 'credit_note' | 'final_invoice_adjustment'
//             | 'waived' | 'no_adjustment',
//     final_amount: decimal (optional — override of calculated amount),
//     notes: string (required for any decision other than no_adjustment)
//   }
// ── S-MILEAGE-3 D-K: precharge_refund block ────────────────────
// When the lease has precharge_enabled=1 AND precharge_balance > 0
// at close time, the close request MUST carry a `precharge_refund`
// block specifying the refund method. The block is parsed here for
// shape validation; precharge_balance > 0 gate + method-required
// gate runs inside the transaction (precharge_balance is read under
// FOR UPDATE on the lease row per D20).
//
// Shape:
//   precharge_refund: {
//     method: 'cash' | 'credit',
//     notes:  string (optional, captured in audit_log)
//   }
//
// D-B (i) locked: cash refunds stamp method='cash' with
// settled_at=NULL at close; operator confirms physical disbursement
// later via api/v1/leases/mark_refund_settled.php.
// D-C: credit refunds create a credit_notes row + stamp settled_at
// at close-commit (credit issuance IS settlement per D-E (ii)).
$prechargeRefund = null;
if (isset($body['precharge_refund']) && is_array($body['precharge_refund'])) {
    $prRaw    = $body['precharge_refund'];
    $prMethod = $prRaw['method'] ?? null;
    if (!in_array($prMethod, ['cash', 'credit'], true)) {
        json_error('VALIDATION_ERROR',
            "precharge_refund.method must be 'cash' or 'credit'.", 422);
    }
    $prNotes = isset($prRaw['notes']) ? trim((string) $prRaw['notes']) : null;
    if ($prNotes === '') {
        $prNotes = null;
    }
    $prechargeRefund = [
        'method' => $prMethod,
        'notes'  => $prNotes,
    ];
}

$closeAdjustment = null;
if (isset($body['close_adjustment']) && is_array($body['close_adjustment'])) {
    $caRaw = $body['close_adjustment'];
    $caDecision = $caRaw['decision'] ?? null;
    if (!in_array($caDecision, ['credit_note', 'final_invoice_adjustment', 'waived', 'no_adjustment'], true)) {
        json_error('VALIDATION_ERROR',
            "close_adjustment.decision must be one of credit_note, final_invoice_adjustment, waived, no_adjustment.", 422);
    }
    $caNotes = trim((string) ($caRaw['notes'] ?? ''));
    if ($caDecision !== 'no_adjustment' && $caNotes === '') {
        json_error('VALIDATION_ERROR',
            'close_adjustment.notes is required for any decision other than no_adjustment.', 422);
    }
    $caFinalAmount = null;
    if (isset($caRaw['final_amount']) && $caRaw['final_amount'] !== '' && $caRaw['final_amount'] !== null) {
        $dec = clean_decimal($caRaw['final_amount']);
        if ($dec === null || bccomp($dec, '0', 2) < 0) {
            json_error('VALIDATION_ERROR', 'close_adjustment.final_amount must be a non-negative number.', 422);
        }
        $caFinalAmount = $dec;
    }
    $closeAdjustment = [
        'decision'     => $caDecision,
        'final_amount' => $caFinalAmount,
        'notes'        => $caNotes,
    ];
}

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
 *
 * S-FIX-2 Path B: status-aware OB decrement. Drafts: OB unchanged. Sent/etc:
 *   OB -= balance_due. Plus Phase 0.5 Bug B fix: zero balance_due on the void row.
 */
function adv_void_invoice(array $inv, array $lease, string $reason): void
{
    $preVoidStatus = $inv['status'];
    $totalAmount   = (string) $inv['total_amount'];
    $balanceDue    = (string) $inv['balance_due'];
    $decOb         = ($preVoidStatus === 'draft') ? '0.00' : $balanceDue;

    db_update('invoices', [
        'status'      => 'void',
        'balance_due' => '0.00',
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
        [$totalAmount, $decOb, (int) $lease['id']]
    );
    if (!empty($lease['customer_id'])) {
        db_execute(
            "UPDATE customers
                SET outstanding_balance = outstanding_balance - ?,
                    updated_at = NOW()
              WHERE id = ?",
            [$decOb, (int) $lease['customer_id']]
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
        'notes'        => "Advance invoice {$inv['invoice_number']} voided on lease close (was {$preVoidStatus}): {$reason}. Counter delta: total_invoiced -= {$totalAmount}, outstanding_balance -= {$decOb} (Path B).",
        'old_values'   => json_encode(['status' => $preVoidStatus, 'balance_due' => $balanceDue]),
        'new_values'   => json_encode(['status' => 'void', 'balance_due' => '0.00']),
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

/**
 * S-FIX-2 Bug 1 helper: locate any cron-generated full_month draft for the
 * closing month and (a) void it on a mid-month close, or (b) append mileage
 * reconciliation to it on a last-day-of-month close.
 *
 * Returns a descriptor of what was done (or null if no matching draft existed).
 *
 * Concurrency: the SELECT is FOR UPDATE so a concurrent monthly cron run cannot
 * insert a duplicate full_month invoice between our check and our action. The
 * outer close transaction already holds the equipment_unit lock so the cron
 * cannot mid-process the same lease.
 */
function legacy_handle_existing_full_month_draft(
    int $leaseId,
    string $returnDate,
    array $lease,
    array $extraLines,
    ?string $odoPeriodStart,
    ?string $odoAtClose,
    ?string $odoSource,
    ?string $odoFetchedAt
): ?array {
    // Find ANY existing full_month invoice for the closing month (any status)
    // so we can surface non-draft conflicts before silently double-billing.
    $existing = db_row(
        "SELECT id, invoice_number, status, billing_period_start, billing_period_end,
                subtotal, discount_amount, subtotal_after_discount, total_amount,
                balance_due, currency, customer_id, lease_id,
                gst_exempt_snapshot, pst_exempt_snapshot, tax_exempt_snapshot,
                odometer_at_period_start_km, odometer_at_period_end_km,
                odometer_source, odometer_fetched_at
         FROM invoices
         WHERE lease_id      = ?
           AND billing_type  = 'full_month'
           AND deleted_at    IS NULL
           AND YEAR(billing_period_start)  = YEAR(?)
           AND MONTH(billing_period_start) = MONTH(?)
         ORDER BY id DESC
         LIMIT 1
         FOR UPDATE",
        [$leaseId, $returnDate, $returnDate]
    );
    if (!$existing) {
        return null;
    }

    // Spec hard rule: only drafts may be auto-voided / auto-modified. Sent /
    // paid / partially_paid / overdue / void / written_off invoices are
    // CRA-immutable — refuse to proceed and surface the conflict.
    if ($existing['status'] !== 'draft') {
        json_error(
            'INVOICE_CONFLICT',
            "Cannot close lease: an existing full_month invoice {$existing['invoice_number']} (status '{$existing['status']}', total {$existing['currency']} {$existing['total_amount']}) covers the closing month. Resolve this invoice manually before closing the lease.",
            409,
            ['existing_invoice' => [
                'id'             => (int) $existing['id'],
                'invoice_number' => $existing['invoice_number'],
                'status'         => $existing['status'],
                'total_amount'   => (string) $existing['total_amount'],
                'period_start'   => $existing['billing_period_start'],
                'period_end'     => $existing['billing_period_end'],
            ]]
        );
    }

    $isLastDayOfMonth = ($returnDate === date('Y-m-t', strtotime($returnDate)));

    if ($isLastDayOfMonth) {
        return legacy_append_mileage_to_full_month_draft(
            $existing, $lease, $extraLines,
            $odoPeriodStart, $odoAtClose, $odoSource, $odoFetchedAt
        );
    }

    // Mid-month close → void the draft. Path B canonical truth for draft → void:
    //   total_invoiced -= total_amount; OB unchanged.
    // adv_void_invoice() already implements this status-aware logic.
    adv_void_invoice($existing, $lease,
        'Lease closed mid-month — replaced by final invoice (S-FIX-2 Bug #1).');

    return [
        'invoice_id'     => (int) $existing['id'],
        'invoice_number' => $existing['invoice_number'],
        'action'         => 'voided_for_replacement',
        'old_total'      => (string) $existing['total_amount'],
    ];
}

/**
 * S-FIX-2 Bug 1 helper (last-day branch): append mileage reconciliation lines
 * to an existing full_month draft, recompute totals, and emit the Path-B
 * lease.total_invoiced delta. The draft keeps its original invoice number so
 * the cron's gap-free numbering is preserved.
 *
 * Tax recomputation uses the invoice's exemption snapshots (CRA-correct — the
 * exemption that was in force at draft creation continues to apply).
 */
function legacy_append_mileage_to_full_month_draft(
    array $invoice,
    array $lease,
    array $extraLines,
    ?string $odoPeriodStart,
    ?string $odoAtClose,
    ?string $odoSource,
    ?string $odoFetchedAt
): array {
    if (empty($extraLines)) {
        // Last-day close with no mileage overage — nothing to append.
        return [
            'invoice_id'     => (int) $invoice['id'],
            'invoice_number' => $invoice['invoice_number'],
            'action'         => 'no_mileage_to_append',
            'old_total'      => (string) $invoice['total_amount'],
            'new_total'      => (string) $invoice['total_amount'],
        ];
    }

    $taxCalc   = new \FleetForge\Billing\TaxCalculator();
    $province  = $lease['province'] ?? 'BC';
    $gstExempt = (bool) $invoice['gst_exempt_snapshot'];
    $pstExempt = (bool) $invoice['pst_exempt_snapshot'];
    if ($invoice['tax_exempt_snapshot']) {
        $gstExempt = true;
        $pstExempt = true;
    }

    $oldTotal = (string) $invoice['total_amount'];

    // Find the highest sort_order so appended lines come after the existing rental.
    $maxSortRow = db_row(
        "SELECT COALESCE(MAX(sort_order), -1) AS max_sort FROM invoice_line_items WHERE invoice_id = ?",
        [$invoice['id']]
    );
    $sortOrder = (int) ($maxSortRow['max_sort'] ?? -1) + 1;

    // Insert each appended line item AND accumulate the subtotal delta.
    $addToSubtotal = '0.00';
    foreach ($extraLines as $line) {
        $signedAmount = (string) ($line['amount'] ?? '0.00');
        if (!empty($line['is_credit'])) {
            $signedAmount = bcmul($signedAmount, '-1', 2);
        }
        $addToSubtotal = bcadd($addToSubtotal, $signedAmount, 2);

        $lineTax = ['gst' => '0.00', 'pst' => '0.00', 'hst' => '0.00'];
        if (!empty($line['taxable'])) {
            $lineTax = $taxCalc->calculate(
                $line['is_credit'] ? bcsub('0', (string) $line['amount'], 2) : (string) $line['amount'],
                $province, $gstExempt, $pstExempt
            );
        }

        db_insert('invoice_line_items', [
            'invoice_id'     => $invoice['id'],
            'sort_order'     => $sortOrder++,
            'item_type'      => $line['item_type']   ?? 'mileage',
            'description'    => $line['description'] ?? 'Mileage adjustment',
            'quantity'       => $line['quantity']    ?? '1.0000',
            'unit'           => $line['unit']        ?? null,
            'unit_price'     => $line['unit_price']  ?? '0.00',
            'amount'         => $line['amount']      ?? '0.00',
            'is_credit'      => (int) ($line['is_credit'] ?? 0),
            'taxable'        => (int) ($line['taxable']   ?? 1),
            'tax_gst_amount' => $lineTax['gst'],
            'tax_pst_amount' => $lineTax['pst'],
            'tax_hst_amount' => $lineTax['hst'],
        ]);
    }

    // Recompute totals using the current discount on the lease (snapshotted into
    // the original draft as discount_amount; we re-run the formula on the new
    // subtotal so the discount stays proportional for percentage discounts).
    $newSubtotal   = bcadd((string) $invoice['subtotal'], $addToSubtotal, 2);
    $discountType  = $lease['discount_type']  ?? 'none';
    $discountValue = (string) ($lease['discount_value'] ?? '0.0000');
    $discountAmt   = '0.00';
    if ($discountType === 'percentage' && bccomp($discountValue, '0', 4) > 0) {
        $discountAmt = bcround(bcmul($newSubtotal, bcdiv($discountValue, '100', 6), 6), 2);
    } elseif ($discountType === 'flat' && bccomp($discountValue, '0', 4) > 0) {
        $discountAmt = bcround($discountValue, 2);
    }
    $newSubtotalAfterDiscount = bcsub($newSubtotal, $discountAmt, 2);
    $newTax     = $taxCalc->calculate($newSubtotalAfterDiscount, $province, $gstExempt, $pstExempt);
    $newTotal   = bcadd($newSubtotalAfterDiscount, $newTax['total'], 2);
    $newBalance = $newTotal; // draft → no payments / credits applied yet

    // Update odometer columns when supplied — last-day close brings closing odo.
    $invoiceUpdate = [
        'subtotal'                => $newSubtotal,
        'discount_amount'         => $discountAmt,
        'subtotal_after_discount' => $newSubtotalAfterDiscount,
        'tax_gst_rate'            => $newTax['gst_rate'],
        'tax_pst_rate'            => $newTax['pst_rate'],
        'tax_hst_rate'            => $newTax['hst_rate'],
        'tax_gst_amount'          => $newTax['gst'],
        'tax_pst_amount'          => $newTax['pst'],
        'tax_hst_amount'          => $newTax['hst'],
        'tax_total'               => $newTax['total'],
        'total_amount'            => $newTotal,
        'balance_due'             => $newBalance,
        'updated_by'              => current_user_id(),
    ];
    if ($odoPeriodStart !== null) $invoiceUpdate['odometer_at_period_start_km'] = $odoPeriodStart;
    if ($odoAtClose     !== null) $invoiceUpdate['odometer_at_period_end_km']   = $odoAtClose;
    if ($odoSource      !== null) $invoiceUpdate['odometer_source']             = $odoSource;
    if ($odoFetchedAt   !== null) $invoiceUpdate['odometer_fetched_at']         = $odoFetchedAt;

    db_update('invoices', $invoiceUpdate, 'id = ?', [$invoice['id']]);

    // Path B: lease.total_invoiced gets the delta. customer.outstanding_balance
    // unchanged (this is a draft edit; the OB increment fires only on send).
    $delta = bcsub($newTotal, $oldTotal, 2);
    if (bccomp($delta, '0', 2) !== 0 && !empty($invoice['lease_id'])) {
        db_execute(
            "UPDATE leases SET total_invoiced = total_invoiced + ?, updated_at = NOW() WHERE id = ?",
            [$delta, (int) $invoice['lease_id']]
        );
    }

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'invoices',
        'entity_type'  => 'invoice',
        'entity_id'    => (int) $invoice['id'],
        'entity_label' => $invoice['invoice_number'],
        'notes'        => "S-FIX-2 Bug #1 last-day close: appended mileage reconciliation to full_month draft {$invoice['invoice_number']}. Counter delta: total_invoiced += {$delta} (Path B; draft, OB unchanged).",
        'old_values'   => json_encode(['total_amount' => $oldTotal]),
        'new_values'   => json_encode(['total_amount' => $newTotal]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return [
        'invoice_id'     => (int) $invoice['id'],
        'invoice_number' => $invoice['invoice_number'],
        'action'         => 'appended_mileage',
        'old_total'      => $oldTotal,
        'new_total'      => $newTotal,
    ];
}

$result = null;

db_transaction(function () use ($id, $actualReturnDate, $mileageAtEnd, $closeNotes, $odoAtClose, $odoSource, $odoFetchedAt, $reconMode, $closeAdjustment, $prechargeRefund, &$result) {
    // ── Fetch lease ────────────────────────────────────────────
    // SAMSARA-3: include odometer_start_km so we can derive the period
    // start odometer for the final invoice when the user supplies a
    // closing odometer without an explicit start value.
    // S-FIX-2 Bug #1: include discount_* and JOIN customer.province so the
    // legacy_append_mileage_to_full_month_draft helper can recompute totals
    // with the right discount + tax rules on a last-day-of-month close.
    $lease = db_row(
        "SELECT l.id, l.status, l.contract_number, l.company_name_snapshot, l.customer_id,
                l.equipment_unit_id, l.unit_number_snapshot, l.mileage_at_start,
                l.mileage_rate, l.mileage_unit, l.estimated_mileage,
                l.start_date, l.end_date, l.last_billed_date, l.odometer_start_km,
                l.estimated_mileage_km, l.estimated_mileage_miles,
                l.mileage_rate_km, l.mileage_rate_miles, l.km_to_miles_conversion,
                l.advance_billing_periods, l.currency,
                l.discount_type, l.discount_value,
                l.precharge_enabled, l.precharge_amount, l.precharge_balance,
                l.precharge_invoiced_at, l.precharge_refund_method, l.precharge_refund_settled_at,
                c.province AS province
         FROM leases l
         LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
         WHERE l.id = ? AND l.deleted_at IS NULL",
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

    // ── S-LEASE-MILEAGE: persist closing odometer + total distance ───
    // odoAtClose came in via the SAMSARA-3 path (decimal km). Mirror it
    // into the new lease.odometer_end_* columns so the unit-history view
    // and the customer-portal closed-lease summary can read directly
    // off the lease row without joining final invoices.
    if ($odoAtClose !== null) {
        $leaseUpdate['odometer_end_km']         = $odoAtClose;
        $leaseUpdate['odometer_end_source']     = $odoSource ?? 'manual';
        $leaseUpdate['odometer_end_fetched_at'] = $odoFetchedAt;

        // total_distance_km = odometer_end - odometer_start. Skip when
        // start is unknown (lease pre-dated odometer tracking).
        if ($lease['odometer_start_km'] !== null) {
            $totalDist = bcsub((string) $odoAtClose, (string) $lease['odometer_start_km'], 2);
            // Defence-in-depth: clamp to 0 — a negative "total distance"
            // means a bad starting odometer; the audit log will flag it.
            $leaseUpdate['total_distance_km'] = bccomp($totalDist, '0', 2) >= 0 ? $totalDist : '0.00';
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

    // ── S-MILEAGE-3 D-B/D-C/D-D/D-E/D-K/D-L: precharge refund dispatch ──
    // Per the S-MILEAGE-3 spec block (locked via S-MILEAGE-3-SPEC-WRITE +
    // SPEC-LOCK), when a lease closes with precharge_enabled=1 AND
    // precharge_balance > 0, the operator must select a refund method
    // (cash or credit). The dispatch runs after the lease has flipped
    // to 'completed' + the lease-close audit row has been written, so
    // the refund's own state-change and audit row are clearly
    // distinguishable in the audit_log timeline.
    //
    // D-D state machine: precharge_refund_method is immutable once
    // set. 409 PRECHARGE_REFUND_LOCKED fires if a request reaches here
    // with the method already populated (edge case — status was
    // reverted manually). Normal flow can't reach this branch on a
    // completed lease since the state machine check at line ~653
    // already rejects non-active leases.
    //
    // D-J first-emitter status confirmed (0 leases with method set
    // pre-session); D-B (i) cash branch defers settled_at; D-C credit
    // branch stamps settled_at at close-commit (credit_note creation
    // IS settlement per D-E (ii)).
    $prechargeRefundResult = null;
    $needsRefund = (int) ($lease['precharge_enabled'] ?? 0) === 1
        && $lease['precharge_balance'] !== null
        && bccomp((string) $lease['precharge_balance'], '0.00', 2) > 0;

    if ($needsRefund) {
        // D-D 409 gate — must NOT already have a refund method set.
        // Normal flow has lease.status='active' (validated above), and
        // active leases shouldn't have refund_method written. This guard
        // catches the edge case where status was reverted manually but
        // the column was previously written.
        if ($lease['precharge_refund_method'] !== null) {
            json_error('PRECHARGE_REFUND_LOCKED',
                "Precharge refund method already locked at close. Method cannot be changed once the close transaction has committed.",
                409);
        }

        // Required-field gate per D-K.
        if ($prechargeRefund === null) {
            json_error('PRECHARGE_REFUND_REQUIRED',
                "Precharge refund method is required when closing a lease with a positive precharge balance.",
                422);
        }

        $refundMethod  = $prechargeRefund['method'];
        $refundAmount  = (string) $lease['precharge_balance'];   // bcmath string
        $refundNotes   = $prechargeRefund['notes'];
        $refundCnId    = null;
        $refundCnNum   = null;
        $settledAtSql  = null;   // NULL for cash; NOW() for credit

        if ($refundMethod === 'credit') {
            // D-C: create credit_note with source='precharge_refund'.
            // Reuses the gap-free credit_note_number pattern from
            // api/v1/credit_notes/create.php (FOR UPDATE on settings
            // row). AutoEntryBridge call deferred per D-I (CPA-blocked):
            // the credit_notes row is the data source of truth; the JE
            // shape lands in S-MILEAGE-3-ACCT-SPEC once CPA answers
            // questions (a-e). For now, follow the credit_notes/create.php
            // precedent and call onCreditNoteIssued — keeps the
            // accounting ledger consistent under the existing pattern.
            $year = date('Y');
            $cnSettingsKey = "credit_note.next_number.{$year}";

            $cnSettingsRow = db_row(
                "SELECT `key`, `value` FROM settings WHERE `key` = ? FOR UPDATE",
                [$cnSettingsKey]
            );
            $cnNext   = $cnSettingsRow ? (int) $cnSettingsRow['value'] : 1;
            $cnPrefix = settings_get('credit_note.prefix', 'CN-CR');
            $refundCnNum = sprintf('%s-%s-%05d', $cnPrefix, $year, $cnNext);

            if ($cnSettingsRow) {
                db_execute(
                    "UPDATE settings SET `value` = ? WHERE `key` = ?",
                    [$cnNext + 1, $cnSettingsKey]
                );
            } else {
                db_execute(
                    "INSERT INTO settings (`key`, `value`, `group_name`) VALUES (?, ?, 'credit_notes')",
                    [$cnSettingsKey, $cnNext + 1]
                );
            }

            $cnReason = "Precharge balance refund — lease {$lease['contract_number']}"
                . ($refundNotes ? " ({$refundNotes})" : '');

            $refundCnId = db_insert('credit_notes', [
                'credit_note_number' => $refundCnNum,
                'customer_id'        => $lease['customer_id'],
                'lease_id'           => $id,
                'source'             => 'precharge_refund',
                'source_invoice_id'  => null,
                'source_payment_id'  => null,
                'amount'             => $refundAmount,
                'currency'           => $lease['currency'] ?? 'CAD',
                'amount_remaining'   => $refundAmount,
                'status'             => 'active',
                'expires_at'         => null,
                'reason'             => $cnReason,
                'internal_notes'     => null,
                'created_by'         => current_user_id(),
            ]);

            // Existing accounting integration — DR 4xxx / CR 2060
            // Customer Credits Liability per S-FIX-2 D47/D48. If CPA
            // confirms precharge-source credit needs different JE
            // shape (D-I question d), S-MILEAGE-3-ACCT-SPEC will rework
            // this call site. Wrapped in try/catch with error_log
            // fallback to keep the close transaction observable but
            // not blocking — accounting integration is best-effort
            // at the AR layer level per S-FIX-2 precedent.
            try {
                \FleetForge\Accounting\AutoEntryBridge::onCreditNoteIssued((int) $refundCnId, current_user_id());
            } catch (\Throwable $e) {
                error_log('[S-MILEAGE-3 onCreditNoteIssued] ' . $e->getMessage());
            }

            $settledAtSql = date('Y-m-d H:i:s');
        }
        // else: $refundMethod === 'cash' — settledAtSql stays null;
        // operator stamps later via api/v1/leases/mark_refund_settled.php

        // D-D state machine UPDATE — write method (+ settled_at for
        // credit branch) on the lease row. FOR UPDATE lock on the unit
        // is already held above; the lease row UPDATE here is the
        // refund-method commitment. precharge_balance is NOT zeroed —
        // refund == balance at close time, and the balance remains as
        // the historical at-close figure for the audit trail.
        $leaseRefundUpdate = [
            'precharge_refund_method' => $refundMethod,
        ];
        if ($settledAtSql !== null) {
            $leaseRefundUpdate['precharge_refund_settled_at'] = $settledAtSql;
        }
        db_update('leases', $leaseRefundUpdate, 'id = ?', [$id]);

        // D-L audit_log: lease_precharge_refund_issued. Uses
        // action='update' per D102 workaround pattern (the
        // audit_log.action ENUM doesn't include refund-specific values;
        // descriptive entity_type carries the routing info).
        db_insert('audit_log', [
            'user_id'      => current_user_id(),
            'user_name'    => $changedBy,
            'action'       => 'update',
            'module'       => 'leases',
            'entity_type'  => 'lease_precharge_refund_issued',
            'entity_id'    => $id,
            'entity_label' => $lease['contract_number'],
            'notes'        => sprintf(
                'S-MILEAGE-3 [%s]: precharge refund issued at close. lease=%s, method=%s, amount=%s %s%s%s',
                $refundMethod,
                $lease['contract_number'],
                $refundMethod,
                $refundAmount,
                $lease['currency'] ?? 'CAD',
                $refundCnNum ? ", credit_note={$refundCnNum}" : '',
                $refundNotes ? ", notes={$refundNotes}" : ''
            ),
            'old_values'   => json_encode([
                'precharge_refund_method'      => null,
                'precharge_refund_settled_at'  => null,
            ]),
            'new_values'   => json_encode([
                'method'                       => $refundMethod,
                'amount'                       => $refundAmount,
                'precharge_balance_at_close'   => $refundAmount,
                'related_credit_note_id'       => $refundCnId,
                'related_credit_note_number'   => $refundCnNum,
                'settled_at'                   => $settledAtSql,
                'notes'                        => $refundNotes,
                'closed_by_user_id'            => current_user_id(),
            ]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);

        $prechargeRefundResult = [
            'method'                  => $refundMethod,
            'amount'                  => $refundAmount,
            'currency'                => $lease['currency'] ?? 'CAD',
            'settled_at'              => $settledAtSql,
            'credit_note_id'          => $refundCnId,
            'credit_note_number'      => $refundCnNum,
            'notes'                   => $refundNotes,
        ];
    }

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

    // ── S-MILEAGE-FIX-0 (Q9): prior monthly excess sum ──────────
    // Per-period excess billed via S-LEASE-MILEAGE on prior monthly
    // invoices already charged the customer for excess kilometres.
    // Both the legacy overage block (below) and the S-LEASE-MILEAGE
    // close-adjustment block (further down) must subtract this value
    // to avoid double-billing the same kilometres at close. Voided
    // invoices excluded — their excess never reached customer AR.
    // The cron-generated full_month draft for the closing month is
    // included here automatically (it's a non-void invoice on the
    // lease), which also handles the D-D
    // legacy_append_mileage_to_full_month_draft path.
    //
    // This is a transitional safeguard. The Model-B refactor in
    // S-MILEAGE-1+ replaces the per-period excess gate with a
    // drawdown balance and removes the seam entirely.
    $priorExcessRow = db_row(
        "SELECT COALESCE(SUM(excess_distance_km), 0) AS prior_excess
           FROM invoices
          WHERE lease_id = ?
            AND deleted_at IS NULL
            AND status != 'void'",
        [$id]
    );
    $priorExcessKm = (float) ($priorExcessRow['prior_excess'] ?? 0);

    // Mileage overage shared between branches (computed once)
    // S-LEASE-MILEAGE: when a `close_adjustment` block is supplied the
    // manager has already reviewed totals against the full-lease allowance,
    // so we suppress the legacy partial-month overage line and apply the
    // manager's chosen adjustment instead (after the final invoice is
    // generated, below). Backwards-compat: omitting close_adjustment keeps
    // the legacy line-item path intact for older clients.
    $extraLines     = [];
    $hasMileageLine = false;
    if ($closeAdjustment === null
        && $mileageAtEnd !== null && $lease['mileage_at_start'] !== null
        && bccomp((string)$lease['mileage_rate'], '0', 4) > 0)
    {
        $actualMileage    = (string)($mileageAtEnd - (int)$lease['mileage_at_start']);
        $includedMileage  = (string)($lease['estimated_mileage'] ?? '0');
        $overageMileage   = bcsub($actualMileage, $includedMileage, 4);

        // S-MILEAGE-FIX-0 (Q9 path 2): subtract prior excess. The new
        // excess_distance_km column is canonically km (D-E from
        // S-LEASE-MILEAGE); convert to the lease's primary mileage unit
        // before subtracting from the legacy integer-mileage overage.
        // In practice leases hitting this block predate odometer tracking
        // and have priorExcessKm = 0 by structure, but the conversion is
        // defence-in-depth for hybrid leases that have both legacy and
        // new tracking populated.
        if ($priorExcessKm > 0) {
            $mileageUnit = $lease['mileage_unit'] ?? 'km';
            $priorExcessInLeaseUnit = $priorExcessKm;
            if ($mileageUnit === 'miles') {
                $factor = (float) ($lease['km_to_miles_conversion'] ?? 0.621371);
                if ($factor > 0) {
                    $priorExcessInLeaseUnit = $priorExcessKm * $factor;
                }
            }
            $overageMileage = bcsub($overageMileage, (string) $priorExcessInLeaseUnit, 4);
        }

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
        // S-FIX-2 Bug #1 (audit #1): if the monthly cron already generated a
        // full_month draft for the closing month, do NOT generate a second
        // partial_end on top of it (would double-bill). Two branches:
        //   - Last-day close: append mileage reconciliation to the existing
        //     draft and use it as the final invoice (skip partial_end).
        //   - Mid-month close: void the draft, then fall through to partial_end.
        // Concurrency: helper uses FOR UPDATE on the draft row; outer txn already
        // holds the equipment_unit lock so the cron cannot insert mid-flight.
        $draftAction = legacy_handle_existing_full_month_draft(
            $id, $actualReturnDate, $lease, $extraLines,
            $odoPeriodStart, $odoAtClose, $odoSource, $odoFetchedAt
        );

        $skipPartialEnd = false;
        if ($draftAction !== null) {
            // Surface the action in the response alongside any advance actions.
            $advanceActions[] = $draftAction;
            // Only the append branch produces the final invoice; the void branch
            // falls through to partial_end generation below.
            if ($draftAction['action'] === 'appended_mileage'
                || $draftAction['action'] === 'no_mileage_to_append') {
                $finalInvoiceId = $draftAction['invoice_id'];
                $skipPartialEnd = true;
            }
        }

        if (!$skipPartialEnd) {
            // Per spec §12 [PASS-3:2C]: final period = day after last_billed_date (or
            // start_date if never billed) through actual_return_date. Pro-rated.
            $periodStart = $lease['last_billed_date']
                ? date('Y-m-d', strtotime($lease['last_billed_date'] . ' +1 day'))
                : $lease['start_date'];

            // S-FIX-2 Bug #6: guard against date inversion. If last_billed_date is
            // greater than or equal to actual_return_date, the previous invoice
            // already covered (or extended past) the close date and there is no
            // new period to bill. Skip partial_end generation rather than emit
            // an invoice with period_end < period_start.
            if ($periodStart > $actualReturnDate) {
                db_insert('audit_log', [
                    'user_id'      => current_user_id(),
                    'user_name'    => current_user()['name'] ?? 'system',
                    'action'       => 'lease_closed',
                    'module'       => 'leases',
                    'entity_type'  => 'lease',
                    'entity_id'    => $id,
                    'entity_label' => $lease['contract_number'],
                    'notes'        => "S-FIX-2 Bug #6: lease closed within an already-billed period (last_billed_date {$lease['last_billed_date']} >= actual_return_date {$actualReturnDate}). No partial_end invoice generated.",
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);
                $advanceActions[] = [
                    'action' => 'partial_end_skipped',
                    'reason' => "last_billed_date ({$lease['last_billed_date']}) >= actual_return_date ({$actualReturnDate}); previous invoice already covers the closing date.",
                ];
            } else {
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
        }
    }

    // ── S-LEASE-MILEAGE: process manager close adjustment ─────
    // Persist the manager's decision (excess charge / underage credit /
    // waived / no_adjustment) regardless of branch. Writes a row to
    // lease_close_adjustments for audit, and either creates a credit_note
    // or amends the final invoice's totals based on the chosen decision.
    $closeAdjustmentResult = null;
    if ($closeAdjustment !== null) {
        // Compute calculated distance + amount based on closing odometer
        // (preferred — decimal precision) or legacy mileage_at_end (fallback
        // for leases that pre-date the odometer columns).
        $totalAllowanceKm = (float) ($lease['estimated_mileage_km']
                                     ?? $lease['estimated_mileage'] ?? 0);
        $rateKm           = (string) ($lease['mileage_rate_km']
                                     ?? $lease['mileage_rate'] ?? '0');

        $totalDistanceKm = null;
        if ($odoAtClose !== null && $lease['odometer_start_km'] !== null) {
            $totalDistanceKm = (float) bcsub((string)$odoAtClose, (string)$lease['odometer_start_km'], 2);
        } elseif ($mileageAtEnd !== null && $lease['mileage_at_start'] !== null) {
            $totalDistanceKm = (float) ($mileageAtEnd - (int) $lease['mileage_at_start']);
        }

        if ($totalDistanceKm === null) {
            json_error('VALIDATION_ERROR',
                'Cannot apply close_adjustment: lease has no captured starting and ending odometer values to compute total distance.', 422);
        }
        if ($totalDistanceKm < 0) $totalDistanceKm = 0.0;

        // ── S-MILEAGE-FIX-0 (Q9): subtract prior monthly excess ────
        // priorExcessKm was computed earlier in this transaction
        // (above line ~795) as SUM(excess_distance_km) over non-void
        // invoices for this lease. Subtracting it here prevents
        // double-billing the same excess kilometres at close.
        //
        // Inverse case (D-B): if priorExcessKm exceeds the raw overage
        // (i.e. prior monthly excess billed MORE kilometres than the
        // lease was actually over allowance), the customer was
        // already over-billed. We do NOT auto-correct — adjustedExcess
        // is clamped to 0 so no further charge fires, and the close
        // UI surfaces a banner via api/v1/leases/show.php's
        // prior_excess_km field. Manager handles via manual credit_note
        // if business policy requires it. The audit_log row below also
        // captures the inverse case for ops visibility.
        $rawOverageKm  = $totalDistanceKm - $totalAllowanceKm;
        $excessKm      = max(0.0, $rawOverageKm - $priorExcessKm);
        $underageKm    = max(0.0, $totalAllowanceKm - $totalDistanceKm);
        $priorOverbillKm = ($rawOverageKm > 0 && $priorExcessKm > $rawOverageKm)
            ? ($priorExcessKm - $rawOverageKm)
            : 0.0;

        $adjType = $excessKm > 0 ? 'excess_charge'
                 : ($underageKm > 0 ? 'underage_credit' : 'no_adjustment');

        $absDiffKm  = $excessKm > 0 ? $excessKm : $underageKm;
        $calcAmount = bcmul((string) round($absDiffKm, 2), $rateKm, 2);

        // final_amount: manager override (if any) else calculated.
        // For 'waived' / 'no_adjustment' the final_amount is forced to 0.
        $finalAmount = $closeAdjustment['final_amount'] ?? $calcAmount;
        if (in_array($closeAdjustment['decision'], ['waived', 'no_adjustment'], true)) {
            $finalAmount = '0.00';
        }

        // Persist the decision to lease_close_adjustments.
        $relatedInvoiceId    = null;
        $relatedCreditNoteId = null;

        if ($closeAdjustment['decision'] === 'credit_note'
            && bccomp($finalAmount, '0', 2) > 0
            && $adjType === 'underage_credit'
        ) {
            // Issue a credit_note tied to this lease for the underage value.
            // Reuse existing credit_notes table with source='mileage_overpayment'
            // (already supported per S-FIX-2 schema). Counters on the customer
            // outstanding_balance happen via the credit_notes counter logic
            // — this row matches the same shape S-FIX-2 Bug #3 emits.
            $cnNumber = sprintf('CN-LEASE-%s-%s',
                $lease['contract_number'], date('YmdHis'));
            $relatedCreditNoteId = db_insert('credit_notes', [
                'credit_note_number' => $cnNumber,
                'customer_id'        => $lease['customer_id'],
                'lease_id'           => $id,
                'source'             => 'mileage_overpayment',
                'amount'             => $finalAmount,
                'currency'           => $lease['currency'],
                'amount_remaining'   => $finalAmount,
                'status'             => 'active',
                'reason'             => sprintf(
                    'Lease close underage credit: %.2f km under allowance (%.2f km of %.2f km), %s. %s',
                    $underageKm, $totalDistanceKm, $totalAllowanceKm,
                    $lease['contract_number'], $closeAdjustment['notes']
                ),
                'created_by'         => current_user_id(),
            ]);
        } elseif ($closeAdjustment['decision'] === 'final_invoice_adjustment'
                  && bccomp($finalAmount, '0', 2) > 0
                  && $finalInvoiceId !== null
        ) {
            // Modify the final invoice — add a line item (excess) or a credit
            // line (underage), then recompute totals like review_mileage does.
            $relatedInvoiceId = $finalInvoiceId;

            $isCredit = $adjType === 'underage_credit';
            $signedAmount = $isCredit
                ? bcsub('0', $finalAmount, 2)
                : $finalAmount;

            $maxSort = db_row(
                "SELECT COALESCE(MAX(sort_order), 0) AS max_sort FROM invoice_line_items WHERE invoice_id = ?",
                [$finalInvoiceId]
            );
            $nextSort = (int) ($maxSort['max_sort'] ?? 0) + 1;

            db_insert('invoice_line_items', [
                'invoice_id'   => $finalInvoiceId,
                'item_type'    => $isCredit ? 'mileage_credit' : 'mileage_adjustment',
                'description'  => $isCredit
                    ? sprintf('Lease close underage credit (%.2f km under allowance)', $underageKm)
                    : sprintf('Lease close excess mileage charge (%.2f km over allowance)', $excessKm),
                'quantity'     => (string) round($absDiffKm, 2),
                'unit'         => 'km',
                'unit_price'   => bccomp((string) round($absDiffKm, 2), '0', 2) > 0
                                  ? bcdiv($finalAmount, (string) round($absDiffKm, 2), 2)
                                  : '0.00',
                'amount'       => $signedAmount,
                'is_credit'    => $isCredit ? 1 : 0,
                'taxable'      => 1,
                'mileage_distance' => (string) round($absDiffKm, 2),
                'mileage_unit' => 'km',
                'mileage_rate' => $rateKm,
                'reference_type' => 'lease_close_adjustment',
                'reference_id'   => $id,
                'sort_order'   => $nextSort,
            ]);

            // Recompute invoice totals — fetch current values to apply delta.
            $finv = db_row(
                "SELECT subtotal, subtotal_after_discount, tax_total, total_amount
                   FROM invoices WHERE id = ?",
                [$finalInvoiceId]
            );
            if ($finv) {
                $newSubtotal       = bcadd((string) $finv['subtotal'], $signedAmount, 2);
                $newSubAfterDisc   = bcadd((string) $finv['subtotal_after_discount'], $signedAmount, 2);
                $newTotal          = bcadd((string) $finv['total_amount'], $signedAmount, 2);
                db_update('invoices', [
                    'subtotal'                  => $newSubtotal,
                    'subtotal_after_discount'   => $newSubAfterDisc,
                    'total_amount'              => $newTotal,
                    'balance_due'               => $newTotal,
                    'updated_by'                => current_user_id(),
                ], 'id = ?', [$finalInvoiceId]);
            }
        }

        // Persist the decision row regardless of action type — full audit trail.
        $closeAdjustmentRowId = db_insert('lease_close_adjustments', [
            'lease_id'                => $id,
            'adjustment_type'         => $adjType,
            'calculated_distance_km'  => (string) round($absDiffKm, 2),
            'calculated_amount'       => $calcAmount,
            'final_amount'            => $finalAmount,
            'decision'                => $closeAdjustment['decision'],
            'related_invoice_id'      => $relatedInvoiceId,
            'related_credit_note_id'  => $relatedCreditNoteId,
            'approved_by_user_id'     => current_user_id(),
            'approved_at'             => date('Y-m-d H:i:s'),
            'notes'                   => $closeAdjustment['notes'] ?: null,
        ]);

        db_insert('audit_log', [
            'user_id'      => current_user_id(),
            'user_name'    => $changedBy,
            'action'       => 'create',
            'module'       => 'leases',
            'entity_type'  => 'lease_close_adjustment',
            'entity_id'    => (int) $closeAdjustmentRowId,
            'entity_label' => $lease['contract_number'],
            'notes'        => sprintf(
                'Lease close adjustment: type=%s, decision=%s, total=%.2fkm vs allowance=%.2fkm, calc=$%s, applied=$%s. Notes: %s',
                $adjType, $closeAdjustment['decision'],
                $totalDistanceKm, $totalAllowanceKm,
                $calcAmount, $finalAmount,
                $closeAdjustment['notes']
            ),
            'old_values'   => null,
            'new_values'   => json_encode([
                'lease_close_adjustment_id' => $closeAdjustmentRowId,
                'decision'                  => $closeAdjustment['decision'],
                'adjustment_type'           => $adjType,
                'total_distance_km'         => $totalDistanceKm,
                'total_allowance_km'        => $totalAllowanceKm,
                'calculated_amount'         => $calcAmount,
                'final_amount'              => $finalAmount,
                'related_invoice_id'        => $relatedInvoiceId,
                'related_credit_note_id'    => $relatedCreditNoteId,
                'prior_excess_km'           => $priorExcessKm,
                'raw_overage_km'            => $rawOverageKm,
                'adjusted_excess_km'        => $excessKm,
                'prior_overbill_km'         => $priorOverbillKm,
            ]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);

        // ── S-MILEAGE-FIX-0 (D-E): transitional regression safeguard ─
        // Surface to ops + audit_log every close that touches a lease
        // with prior monthly excess. INFO when the math reconciles
        // cleanly; WARNING (Sentry) when the inverse case fires (prior
        // monthly excess billed MORE kilometres than the lease was over
        // allowance, meaning the customer was over-billed and the close
        // calc has clamped excess to 0). Manager handles via manual
        // credit_note if business policy requires correction.
        //
        // audit_log.action is an ENUM that doesn't include
        // close_adjustment_with_prior_excess as a value. We use 'update'
        // (existing enum value) and put the descriptive label in
        // entity_type so log searches still find these rows.
        if ($priorExcessKm > 0 && $closeAdjustment['decision'] !== 'waived') {
            $isInverseCase = $priorOverbillKm > 0;
            $severity      = $isInverseCase ? 'WARNING' : 'INFO';
            $safeguardMsg  = sprintf(
                'S-MILEAGE-FIX-0 [%s]: close adjustment with prior monthly excess. lease=%s, prior_excess=%.2fkm, total_distance=%.2fkm, allowance=%.2fkm, raw_overage=%.2fkm, adjusted_excess=%.2fkm, prior_overbill=%.2fkm, decision=%s.%s',
                $severity,
                $lease['contract_number'],
                $priorExcessKm, $totalDistanceKm, $totalAllowanceKm,
                $rawOverageKm, $excessKm, $priorOverbillKm,
                $closeAdjustment['decision'],
                $isInverseCase
                    ? ' INVERSE CASE: customer was over-billed in monthly excess; close_adjustment fired with auto-clamped 0 excess. Manager review required for credit_note issuance per business policy.'
                    : ''
            );

            db_insert('audit_log', [
                'user_id'      => current_user_id(),
                'user_name'    => $changedBy,
                'action'       => 'update',
                'module'       => 'leases',
                'entity_type'  => 'lease_close_with_prior_excess',
                'entity_id'    => $id,
                'entity_label' => $lease['contract_number'],
                'notes'        => $safeguardMsg,
                'old_values'   => null,
                'new_values'   => json_encode([
                    'severity'              => $severity,
                    'is_inverse_case'       => $isInverseCase,
                    'prior_excess_km'       => $priorExcessKm,
                    'total_distance_km'     => $totalDistanceKm,
                    'total_allowance_km'    => $totalAllowanceKm,
                    'raw_overage_km'        => $rawOverageKm,
                    'adjusted_excess_km'    => $excessKm,
                    'prior_overbill_km'     => $priorOverbillKm,
                    'decision'              => $closeAdjustment['decision'],
                    'final_amount'          => $finalAmount,
                ]),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            // Sentry warning only for the inverse case — that's the
            // actionable one. INFO-level audit_log is enough for the
            // routine prior-excess case.
            if ($isInverseCase) {
                try {
                    \FleetForge\Observability\Sentry::captureMessage(
                        $safeguardMsg,
                        'warning'
                    );
                } catch (\Throwable $e) {
                    // Never let observability break the close transaction.
                    error_log('[S-MILEAGE-FIX-0 Sentry] ' . $e->getMessage());
                }
            }
        }

        $closeAdjustmentResult = [
            'id'                     => (int) $closeAdjustmentRowId,
            'adjustment_type'        => $adjType,
            'decision'               => $closeAdjustment['decision'],
            'total_distance_km'      => $totalDistanceKm,
            'total_allowance_km'     => $totalAllowanceKm,
            'calculated_amount'      => $calcAmount,
            'final_amount'           => $finalAmount,
            'related_invoice_id'     => $relatedInvoiceId,
            'related_credit_note_id' => $relatedCreditNoteId,
            // S-MILEAGE-FIX-0 (Q9): exposed for response transparency.
            'prior_excess_km'        => $priorExcessKm,
            'raw_overage_km'         => $rawOverageKm,
            'adjusted_excess_km'     => $excessKm,
            'prior_overbill_km'      => $priorOverbillKm,
        ];
    }

    // ── S-MILEAGE-FIX-0 (D-E): legacy-path safeguard ──────────────
    // Fire the prior-excess safeguard when the legacy path closed the
    // lease without a close_adjustment block. The S-LEASE-MILEAGE block
    // above already wrote a safeguard row when closeAdjustment fired;
    // this branch covers the case where the manager dismissed the close
    // modal without picking a decision (e.g. inverse case where the UI
    // returned kind='exact' and hid the radios). Audit_log only — no
    // Sentry, since the manager intentionally chose not to record a
    // close_adjustment row.
    if ($closeAdjustment === null && $priorExcessKm > 0) {
        db_insert('audit_log', [
            'user_id'      => current_user_id(),
            'user_name'    => $changedBy,
            'action'       => 'update',
            'module'       => 'leases',
            'entity_type'  => 'lease_close_with_prior_excess',
            'entity_id'    => $id,
            'entity_label' => $lease['contract_number'],
            'notes'        => sprintf(
                'S-MILEAGE-FIX-0 [INFO]: legacy-path close on lease %s with prior monthly excess. prior_excess=%.2fkm, decision=NONE (legacy partial_end path).',
                $lease['contract_number'], $priorExcessKm
            ),
            'old_values'   => null,
            'new_values'   => json_encode([
                'severity'        => 'INFO',
                'is_inverse_case' => false,  // legacy path doesn't compute the inverse case explicitly
                'prior_excess_km' => $priorExcessKm,
                'decision'        => null,
                'path'            => 'legacy',
            ]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    }

    $result = [
        'id'                => $id,
        'status'            => 'completed',
        'invoice_id'        => $finalInvoiceId,
        'advance_close'     => $isAdvanceClose,
        'reconciliation'    => $isAdvanceClose ? $reconMode : null,
        'advance_actions'   => $advanceActions,
        'close_adjustment'  => $closeAdjustmentResult,
        // S-MILEAGE-FIX-0 (Q9): always returned, even when no
        // close_adjustment was supplied, so callers and ops dashboards
        // can detect prior-excess interactions on the lease.
        'prior_excess_km'   => $priorExcessKm,
        // S-MILEAGE-3 D-K: precharge refund result. NULL when no refund
        // was needed (precharge_enabled=0 OR precharge_balance=0).
        // Populated with method + amount + settled_at + credit_note_*
        // when refund dispatch fired.
        'precharge_refund'  => $prechargeRefundResult,
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
