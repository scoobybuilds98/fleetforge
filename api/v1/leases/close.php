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
 *              The final invoice (holistic partial_end, invoice_type='final'),
 *              overshoot reconciliation, closeout charges, mileage true-up,
 *              and the precharge refund dispatch all run INSIDE this
 *              endpoint's transaction — see the ordered sequence below.
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
// S-LEASE-RENTAL-DAY-TIME: optional actual return time. When present (and
// lease.start_time is also set), InvoiceGenerator computes the effective
// billable end date (effective_billable_end_date rule) before calling the
// engine. NULL = legacy fall-back (inclusive date-only extent unchanged).
$actualReturnTime = clean_time($body['actual_return_time'] ?? null);
$mileageAtEnd     = clean_int($body['mileage_at_end'] ?? null);
$closeNotes       = clean_string($body['close_notes'] ?? null, 5000);
// S-CLOSE-RETURN-FAR-PAST-END: explicit operator confirmation that a return date
// well beyond the lease's scheduled end_date is intentional. Guards a fat-fingered
// far-future date (e.g. wrong year) that would otherwise silently bill every
// period through that date. Checked inside the transaction once $lease.end_date is
// loaded; captured here so it rides into the transaction closure's use() list.
$confirmExtendedReturn = !empty($body['confirm_extended_return']);

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

// ── S-LEASE-CLOSE-REMOVE-DAYS: "Remove N billable days" at close ──────
// Operator input: subtract N days off the END of the lease's BILLABLE
// period so those days are not in the billing math. The lease's
// actual_return_date and all displayed dates are UNCHANGED; the removal is
// internal-only. NULL/unset = 0 (no removal). Must be >= 0 (the column is
// TINYINT UNSIGNED). The existing 3-day S-LEASE-MIN-DAYS floor still wins:
// it is evaluated on the REDUCED total inside HolisticLeaseEngine, so a
// removal can never shave below the floor. The N-day subtraction is applied
// in lockstep at three sites keyed on leases.billing_days_removed —
// InvoiceGenerator (holistic extent), lease_billable_extent() (overshoot
// clamp), and this endpoint's extentEnd below.
$billingDaysRemoved = clean_int($body['billing_days_removed'] ?? null);
if ($billingDaysRemoved !== null && ($billingDaysRemoved < 0 || $billingDaysRemoved > 255)) {
    // S-LEASE-CLOSE-REMOVE-DAYS: the column is TINYINT UNSIGNED (0..255). Reject
    // out-of-range up front so a typo'd value 422s here rather than 500-ing the
    // leases UPDATE under STRICT_TRANS_TABLES (1264 out-of-range). The downstream
    // extent clamp already floors any large value to start_date for the billing
    // math, but the persisted value still has to fit the column.
    json_error('VALIDATION_ERROR',
        'billing_days_removed must be between 0 and 255.', 422);
}
$billingDaysRemoved = (int) ($billingDaysRemoved ?? 0);

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

// S-LEASE-HOURLY-BILLING: optional closing engine/reefer hours (manual). Stored
// on the final invoice as engine_hours_at_period_end and on the lease as
// engine_hours_at_end so the final invoice bills the remaining period's hours.
$hoursAtClose = null;
if (isset($body['engine_hours_at_close']) && $body['engine_hours_at_close'] !== '' && $body['engine_hours_at_close'] !== null) {
    $dec = clean_decimal($body['engine_hours_at_close']);
    if ($dec !== null && bccomp($dec, '0', 2) >= 0) {
        $hoursAtClose = $dec;
    }
}

// ── S-LEASE-SERVICE-CHARGES: closeout service charges (sweep / wash / fuel) ──
// Each optional: omit or 0 to skip. sweep/wash are flat $ amounts; fuel is
// gallons × the server-authoritative per-gallon rate (settings, never trusts a
// client-sent rate). Built into line items here and billed on the final invoice.
$closeoutLines = [];
$svcDec = function ($v): ?string {
    if ($v === null || $v === '') return null;
    $d = clean_decimal($v);
    return ($d !== null && bccomp($d, '0', 2) > 0) ? $d : null;
};
// FLEETFORGE-15: every closeout line carries an explicit is_credit (these are
// charges, never credits) so the append/recompute path never reads an undefined
// key (legacy_append_mileage_to_full_month_draft hardened too).
$sweepAmt = $svcDec($body['sweep_amount'] ?? null);
if ($sweepAmt !== null) {
    $closeoutLines[] = ['item_type' => 'sweep', 'description' => 'Sweep out',
        'quantity' => '1.0000', 'unit_price' => $sweepAmt, 'amount' => $sweepAmt, 'is_credit' => 0, 'taxable' => 1];
}
$washAmt = $svcDec($body['wash_amount'] ?? null);
if ($washAmt !== null) {
    $closeoutLines[] = ['item_type' => 'wash', 'description' => 'Wash out',
        'quantity' => '1.0000', 'unit_price' => $washAmt, 'amount' => $washAmt, 'is_credit' => 0, 'taxable' => 1];
}
$fuelGallons = $svcDec($body['fuel_gallons'] ?? null);
if ($fuelGallons !== null) {
    // Per-gallon rate is server-side authoritative (global setting default $13.00).
    $fuelRate   = (string) settings_get('lease.fuel_rate_per_gallon', '13.00');
    $fuelAmount = bcround(bcmul($fuelGallons, $fuelRate, 6), 2);
    if (bccomp($fuelAmount, '0', 2) > 0) {
        $closeoutLines[] = ['item_type' => 'fuel',
            'description' => 'Fuel charge: ' . rtrim(rtrim($fuelGallons, '0'), '.') . ' gal × $' . $fuelRate . '/gal',
            'quantity' => $fuelGallons, 'unit' => 'gallons', 'unit_price' => $fuelRate,
            'amount' => $fuelAmount, 'is_credit' => 0, 'taxable' => 1];
    }
}

// S-CLOSE-OVERSHOOT: shared reconciliation helpers (also used by bulk_close.php).
require_once __DIR__ . "/_close_reconciliation.php";

/**
 * S-FIX-2 Bug 1 helper: locate any cron-generated full_month draft for the
 * closing month and (a) void it on a mid-month close, or (b) append mileage
 * reconciliation to it on a last-day-of-month close.
 *
 * Returns a descriptor of what was done (or null if no matching draft existed).
 *
 * Concurrency: the SELECT is FOR UPDATE so a concurrent monthly cron run cannot
 * insert a duplicate full_month invoice between our check and our action. The
 * outer close transaction already holds the LEASE row lock (the cron's
 * createFromLease serializes on it — the cron never locks equipment_units) so the cron
 * cannot mid-process the same lease.
 */
function legacy_handle_existing_full_month_draft(
    int $leaseId,
    string $returnDate,
    string $extentEnd,
    array $lease,
    array $extraLines,
    ?string $odoPeriodStart,
    ?string $odoAtClose,
    ?string $odoSource,
    ?string $odoFetchedAt
): ?array {
    // Find any LIVE (non-void) full_month invoice for the closing month so we can
    // surface a genuine non-draft conflict (sent/paid/written_off) before silently
    // double-billing. S-LEASE-REOPEN-RECLOSE-FIX: exclude status='void' — a voided
    // full_month (e.g. left behind by a PRIOR close cycle on a reopen→reclose) is
    // already neutralised and is NOT a conflict; treating it as one made the second
    // close of a start-on-the-1st lease fail with INVOICE_CONFLICT on a void row.
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
           AND status        <> 'void'
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

    // S-CLOSE-REMOVE-DAYS-FIX: append (keep the full month as the final invoice)
    // ONLY when the full_month draft is entirely within the lease's billable
    // extent — i.e. the closing-month full_month period_end (always month-end)
    // does not exceed $extentEnd. That is true exactly when the lease is returned
    // on the last day of the month AND no billable days were removed.
    //
    // Previously this gated on `$returnDate === last-day-of-month`, which IGNORED
    // billing_days_removed: a "Remove N days" close on the last day of the month
    // shortened the extent (e.g. → 06-25) yet still appended-and-kept the full
    // 06-01→06-30 draft, silently billing the removed days (and the same blind
    // spot would mis-handle a return-time that pulls the extent back to the prior
    // day). Comparing the draft's period_end against the canonical extent makes the
    // append/void decision agree with the extent the final invoice amount uses.
    $fullMonthWithinExtent = ($extentEnd >= (string) $existing['billing_period_end']);

    if ($fullMonthWithinExtent) {
        return legacy_append_mileage_to_full_month_draft(
            $existing, $lease, $extraLines,
            $odoPeriodStart, $odoAtClose, $odoSource, $odoFetchedAt
        );
    }

    // Overshooting full_month (mid-month close, or removed-days/return-time
    // shortened the extent below month-end) → void the draft. Path B canonical
    // truth for draft → void:
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

    // S-CLOSE-RECLOSE-IDEMPOTENT (F43): a reopen→reclose re-runs this fold. Without
    // this guard it APPENDED the mileage/closeout lines again, DUPLICATING them on
    // the same draft (real prod double-bill: INV-2026-00657 had fuel + mileage ×2).
    // Before (re)appending, remove any existing line whose item_type is one we are
    // about to add — these reconciliation/closeout types ('mileage' overage +
    // sweep/wash/fuel) are fold-OWNED; the engine's per-period 'mileage_usage' line
    // is a DIFFERENT type and is left intact. Back the removed lines' signed amount
    // out of the working subtotal so the re-add below is a REPLACE, not a duplicate.
    // First close = no matching rows = a no-op (identical totals to the old path).
    $reTypes = array_values(array_unique(array_map(
        static fn ($l) => $l['item_type'] ?? 'mileage', $extraLines
    )));
    $baseSubtotal = (string) $invoice['subtotal'];
    if ($reTypes) {
        $placeholders = implode(',', array_fill(0, count($reTypes), '?'));
        $priorRows = db_select(
            "SELECT id, amount, is_credit FROM invoice_line_items
              WHERE invoice_id = ? AND item_type IN ({$placeholders})",
            array_merge([(int) $invoice['id']], $reTypes)
        );
        foreach ($priorRows as $pr) {
            $signed = !empty($pr['is_credit'])
                ? bcmul((string) $pr['amount'], '-1', 2)
                : (string) $pr['amount'];
            $baseSubtotal = bcsub($baseSubtotal, $signed, 2);
            db_execute("DELETE FROM invoice_line_items WHERE id = ?", [(int) $pr['id']]);
        }
    }

    // Find the highest sort_order so appended lines come after the existing rental.
    $maxSortRow = db_row(
        "SELECT COALESCE(MAX(sort_order), -1) AS max_sort FROM invoice_line_items WHERE invoice_id = ?",
        [$invoice['id']]
    );
    $sortOrder = (int) ($maxSortRow['max_sort'] ?? -1) + 1;

    // Insert each appended line item AND accumulate the subtotal delta.
    $addToSubtotal = '0.00';
    foreach ($extraLines as $line) {
        // FLEETFORGE-15: read is_credit ONCE defensively — a caller line may omit it
        // (closeout lines historically did), and the old direct `$line['is_credit']`
        // read on the taxable branch warned "Undefined array key" on every such close.
        $isCredit     = !empty($line['is_credit']);
        $signedAmount = (string) ($line['amount'] ?? '0.00');
        if ($isCredit) {
            $signedAmount = bcmul($signedAmount, '-1', 2);
        }
        $addToSubtotal = bcadd($addToSubtotal, $signedAmount, 2);

        $lineTax = ['gst' => '0.00', 'pst' => '0.00', 'hst' => '0.00'];
        if (!empty($line['taxable'])) {
            $lineTax = $taxCalc->calculate(
                $isCredit ? bcsub('0', (string) ($line['amount'] ?? '0.00'), 2) : (string) ($line['amount'] ?? '0.00'),
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

    // S-AUDIT-BILLING-ENGINE-1 #17 (B5): totals via InvoiceRecalc — the ONE
    // draft-recompute authority. The fold used to re-implement the whole
    // pipeline itself: live TaxCalculator lookups that OVERWROTE the invoice's
    // frozen tax_*_rate snapshots (D14 violation), per-line taxes on
    // undiscounted amounts, invoice tax ignoring the `taxable` flag, and
    // discount re-derived from the LIVE lease instead of the invoice's frozen
    // discount snapshot. InvoiceRecalc replays the frozen snapshots with the
    // per-line prorated convention (same as createFromLease post-#16) and
    // rewrites the per-line taxes inserted above consistently.
    $invoiceUpdate = ['updated_by' => current_user_id()];
    if ($odoPeriodStart !== null) $invoiceUpdate['odometer_at_period_start_km'] = $odoPeriodStart;
    if ($odoAtClose     !== null) $invoiceUpdate['odometer_at_period_end_km']   = $odoAtClose;
    if ($odoSource      !== null) $invoiceUpdate['odometer_source']             = $odoSource;
    if ($odoFetchedAt   !== null) $invoiceUpdate['odometer_fetched_at']         = $odoFetchedAt;
    db_update('invoices', $invoiceUpdate, 'id = ?', [$invoice['id']]);

    $totals   = \FleetForge\Billing\InvoiceRecalc::recalc((int) $invoice['id']);
    $newTotal = (string) $totals['total_amount'];

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

// S-MILEAGE-EST-RATE-HOLE: the close-time final invoice runs the same engine as
// every other billing path, so a lease with an estimate and no rate to price it
// makes the CLOSE fail too — the operator can neither invoice nor close it
// (FLEETFORGE-1E, lease #528: the same exception from create.php:198 and from
// close.php:1366 an hour apart). A rate hole is an operator-fixable
// configuration problem, so answer with the fix instead of a 500 + a Sentry
// issue behind "An unexpected error occurred". Caught outside db_transaction,
// which has already rolled the whole close back — the lease stays active and
// no partial close is left behind. The try wraps the call without re-indenting
// the 1,300-line closure, matching invoices/regenerate.php.
try {
db_transaction(function () use ($id, $actualReturnDate, $actualReturnTime, $mileageAtEnd, $closeNotes, $odoAtClose, $odoSource, $odoFetchedAt, $hoursAtClose, $closeoutLines, $reconMode, $prechargeRefund, $billingDaysRemoved, $confirmExtendedReturn, &$result) {
    // ── Fetch lease ────────────────────────────────────────────
    // SAMSARA-3: include odometer_start_km so we can derive the period
    // start odometer for the final invoice when the user supplies a
    // closing odometer without an explicit start value.
    // S-FIX-2 Bug #1: include discount_* and JOIN customer.province so the
    // legacy_append_mileage_to_full_month_draft helper can recompute totals
    // with the right discount + tax rules on a last-day-of-month close.
    // ── L01: acquire the lease row lock BEFORE reading state ─────
    // Without this, the status gate below runs on an UNLOCKED snapshot, so two
    // concurrent POST /close both pass it, serialize only on the equipment_unit
    // lock (acquired later), and the second re-runs the entire close — a duplicate
    // 'completed' write plus a duplicate closeout/adjustment invoice. Locking the
    // lease row first (lease-before-unit, matching activate.php's order to avoid
    // deadlock) makes the second call block here, then see status='completed' and
    // get rejected by the gate. Lock the lease row only (not the joined customer).
    db_row(
        "SELECT id FROM leases WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$id]
    );

    $lease = db_row(
        "SELECT l.id, l.status, l.contract_number, l.company_name_snapshot, l.customer_id,
                l.equipment_unit_id, l.unit_number_snapshot, l.mileage_at_start,
                l.mileage_rate, l.mileage_unit, l.estimated_mileage,
                l.start_date, l.start_time, l.end_date, l.last_billed_date, l.odometer_start_km,
                l.mileage_tracking_mode,
                l.hourly_rate, l.engine_hours_at_start,
                l.estimated_engine_hours_per_day,
                l.estimated_mileage_km, l.estimated_mileage_miles,
                l.estimated_mileage_per_day, l.estimated_mileage_per_day_km,
                l.mileage_rate_km, l.mileage_rate_miles, l.km_to_miles_conversion,
                l.miles_to_km_conversion,
                l.advance_billing_periods, l.currency,
                l.discount_type, l.discount_value,
                l.precharge_enabled, l.precharge_amount, l.precharge_balance,
                l.precharge_invoiced_at, l.precharge_refund_method, l.precharge_refund_settled_at,
                c.province AS province,
                c.billing_address AS billing_address,
                c.email AS customer_email
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

    // ── S-LEASE-DATE-SANITY: implausible start_date → graceful error ──
    // A backfill typo like start_date '0001-03-02' (year 1) makes the billable
    // span ~739k days, which overflows invoices.billing_period_days (smallint
    // unsigned, max 65535) → SQLSTATE 22003/1264 → a cryptic "unexpected error".
    // Fail clearly here and point the operator at the lease dates instead.
    // (create.php now blocks this at entry; this catches rows created before the
    // guard. MTTS286 prod incident, 2026-06-26.)
    $startYear = (int) substr((string) ($lease['start_date'] ?? ''), 0, 4);
    if ($startYear > 0 && $startYear < 2000) {
        json_error(
            'INVALID_LEASE_DATES',
            sprintf(
                'Lease %s has an invalid start date (%s) — it looks like a data-entry error. Edit the lease and correct the start date before closing.',
                $lease['contract_number'],
                $lease['start_date']
            ),
            422,
            ['fields' => ['start_date' => 'Start date looks invalid; correct it before closing.']]
        );
    }

    // ── L11: actual return date must not precede the lease start ──
    // A mistyped/early return date makes the overshoot reconciliation treat
    // EVERY invoice as billed past the (clamped-to-start) billable extent and
    // void / credit-note them all — silently zeroing the lease. Reject it, like
    // the odometer-below-start guard. ISO 'Y-m-d' strings compare correctly.
    if ($actualReturnDate !== null && !empty($lease['start_date'])
        && $actualReturnDate < (string) $lease['start_date']) {
        json_error(
            'RETURN_BEFORE_START',
            sprintf(
                'Return date (%s) is before the lease start date (%s). Enter a return date on or after the start.',
                $actualReturnDate,
                $lease['start_date']
            ),
            422,
            ['fields' => ['actual_return_date' => 'Return date cannot be before the lease start date.']]
        );
    }

    // ── S-CLOSE-RETURN-FAR-PAST-END: confirm an absurdly late return ──
    // The billable extent (and therefore every period the final invoice bills)
    // follows actual_return_date, NOT the scheduled end_date — billing a real
    // overrun is intentional. But a fat-fingered far-future date (wrong year, a
    // stray digit) would silently bill many spurious extra months with no signal.
    // Normal short overruns (a few days/weeks past end_date) pass untouched; only
    // a return more than the grace window past end_date requires the operator to
    // re-submit with confirm_extended_return=true (the close modal turns this 422
    // into an "are you sure?" prompt). Skipped entirely for open-ended leases
    // (no end_date).
    if (!$confirmExtendedReturn
        && !empty($lease['end_date'])
        && $actualReturnDate > (string) $lease['end_date']) {
        $graceDays = (int) settings_get('lease.close_extended_return_grace_days', '31');
        $threshold = (new DateTimeImmutable((string) $lease['end_date']))
            ->modify("+{$graceDays} day")->format('Y-m-d');
        if ($actualReturnDate > $threshold) {
            $daysPast = (int) (new DateTimeImmutable($actualReturnDate))
                ->diff(new DateTimeImmutable((string) $lease['end_date']))->days;
            json_error(
                'RETURN_FAR_PAST_END',
                sprintf(
                    'Return date (%s) is %d days past the lease end date (%s). Closing will bill every period through %s. Re-submit with confirmation if this is correct.',
                    $actualReturnDate, $daysPast, $lease['end_date'], $actualReturnDate
                ),
                422,
                [
                    'confirm_required' => 'confirm_extended_return',
                    'days_past_end'    => $daysPast,
                    'end_date'         => $lease['end_date'],
                    'return_date'      => $actualReturnDate,
                ]
            );
        }
    }

    // S-LEASE-MILEAGE-MODE: an 'off' lease tracks no mileage, so never stamp a
    // closing odometer onto its final invoice — drop any operator-supplied value.
    // ('manual' keeps the operator's reading; 'samsara' keeps its GPS/operator
    // value and the InvoiceGenerator fallback stays gated to samsara mode.)
    if (($lease['mileage_tracking_mode'] ?? 'samsara') === 'off') {
        $odoAtClose   = null;
        $odoSource    = null;
        $odoFetchedAt = null;
    }

    // ── S-ODO-VALIDATION: closing odometer must be >= starting odometer ──
    // A real odometer only increases. A closing reading below the start is
    // impossible and previously slipped through — the engine clamped the negative
    // distance to 0.00 ("0 km driven"), silently saving a contradictory record
    // (cf. MTTS82: start 17,112.59 > close 12,198.00). Reject the close so the
    // operator fixes the bad reading: either the closing value, or the starting
    // odometer (which may be a stale GPS reading from a back-dated activation —
    // editable on the lease before re-closing).
    if ($odoAtClose !== null && $lease['odometer_start_km'] !== null
        && bccomp((string) $odoAtClose, (string) $lease['odometer_start_km'], 2) < 0) {
        json_error(
            'ODOMETER_BELOW_START',
            sprintf(
                'Closing odometer (%s km) is below the starting odometer (%s km). An odometer can only increase — verify the closing reading, or correct the starting odometer (it may have been captured at a late/back-dated activation) before closing.',
                number_format((float) $odoAtClose, 2),
                number_format((float) $lease['odometer_start_km'], 2)
            ),
            422,
            ['fields' => ['odometer_at_close_km' => 'Closing odometer cannot be below the starting odometer.']]
        );
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
        'status'               => 'completed',
        'actual_return_date'   => $actualReturnDate,
        'actual_return_time'   => $actualReturnTime,  // S-LEASE-RENTAL-DAY-TIME; NULL = not captured
        'closed_at'            => date('Y-m-d H:i:s'),
        'closed_by_user_id'    => current_user_id(),
        'updated_by'           => current_user_id(),
        // S-LEASE-CLOSE-REMOVE-DAYS: persist the operator's "Remove N days" input
        // so InvoiceGenerator (which re-loads the lease row inside its own
        // transaction) reads the same value the overshoot clamp below uses. 0 when
        // unset — a no-op everywhere downstream.
        'billing_days_removed' => $billingDaysRemoved,
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
    // S-LEASE-HOURLY-BILLING: persist closing engine hours on the lease row.
    if ($hoursAtClose !== null) {
        $leaseUpdate['engine_hours_at_end'] = $hoursAtClose;
    }
    if ($closeNotes) {
        // FIX #37: append close_notes to internal_notes instead of replacing
        $existing = db_row("SELECT internal_notes FROM leases WHERE id = ?", [$id]);
        $prior = $existing['internal_notes'] ?? '';
        $leaseUpdate['internal_notes'] = $prior
            ? $prior . "\n---\nClose notes: " . $closeNotes
            : 'Close notes: ' . $closeNotes;
    }

    // L01 belt-and-suspenders: only complete a still-active lease, so even if the
    // FOR UPDATE lock above were ever bypassed the status flip can't double-apply.
    db_update('leases', $leaseUpdate, 'id = ? AND status = ?', [$id, 'active']);

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
    // S-LEASE-CLOSE-REMOVE-DAYS: when the operator removed N billable days, fold
    // the removal into the close audit entry (notes + new_values) so the
    // internal-only adjustment is traceable. actual_return_date is unchanged —
    // the removal shaves the billable extent, not the displayed return date.
    $closeAuditNotes = "Lease {$lease['contract_number']} closed — unit {$unit['unit_number']} → available";
    $closeAuditNew   = ['status' => 'completed', 'actual_return_date' => $actualReturnDate];
    if ($billingDaysRemoved > 0) {
        // Reuse the canonical helper (already required) so the audit note quotes
        // the EXACT reduced extent the overshoot clamp + final invoice will use.
        $extentEndForAudit = lease_billable_extent(
            $actualReturnDate, $actualReturnTime, $lease['start_time'] ?? null, (string) $lease['start_date'],
            $billingDaysRemoved
        );
        $closeAuditNotes .= " — {$billingDaysRemoved} billable day(s) removed at close (billable extent shortened to {$extentEndForAudit})";
        $closeAuditNew['billing_days_removed'] = $billingDaysRemoved;
    }
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => $changedBy,
        'action'       => 'lease_closed',
        'module'       => 'leases',
        'entity_type'  => 'lease',
        'entity_id'    => $id,
        'entity_label' => $lease['contract_number'],
        'notes'        => $closeAuditNotes,
        'old_values'   => json_encode(['status' => 'active']),
        'new_values'   => json_encode($closeAuditNew),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // S-MILEAGE-3-FIX-0 (2026-05-13): precharge refund dispatch
    // block MOVED to AFTER final-invoice generation (~line 1180).
    // Original placement here was pre-final-drawdown — caused
    // double-credit on close because InvoiceGenerator's drawdown
    // emit during final-invoice generation independently consumed
    // precharge_balance after the refund had already promised it
    // (KI #106; T1 Step 5 evidence). The fix re-reads precharge_
    // balance FOR UPDATE after the final invoice lands, so refund
    // fires only on the post-drawdown residual.
    $prechargeRefundResult = null;

    // ── ADV-BILL-1 D-H: detect prepaid advance batch ───────────
    // If activation generated advance invoices, branch into reconciliation.
    // Otherwise fall through to the legacy partial_end final invoice flow.
    $advanceInvoices = db_select(
        "SELECT id, invoice_number, status, billing_period_start, billing_period_end,
                subtotal, total_amount, balance_due, currency,
                company_name_snapshot, customer_name_snapshot, billing_address_snapshot,
                province_snapshot, customer_email_snapshot
         FROM invoices
         WHERE lease_id = ?
           AND generation_source = 'advance'
           AND deleted_at IS NULL
           AND status != 'void'
         ORDER BY billing_period_start ASC",
        [$id]
    );

    $isAdvanceClose = !empty($advanceInvoices);

    // S-MILEAGE-3 D-F: priorExcessKm transitional safeguard retired
    // 2026-05-13. Model B replaced per-period excess with the
    // drawdown lifecycle (S-MILEAGE-2B C3 InvoiceGenerator emit) —
    // there is no quantity to subtract at close. excess_distance_km
    // column was dropped in S-MILEAGE-2B C4 migration 202605120907.
    // The S-MILEAGE-FIX-0 (Q9) audit_log entity_type
    // 'lease_close_with_prior_excess' workaround paths retire here
    // along with the SELECT, both subtraction blocks, the inverse-
    // case banner, and the closeReconciliation Alpine getter (C5
    // companion edit in app/admin/leases/show.php).
    //
    // Legacy partial-month mileage-overage line item path preserved
    // for backwards compat on leases without odometer tracking — the
    // priorExcessKm subtraction inside it is no longer relevant
    // (Model B leases use drawdown; legacy leases have nothing prior
    // to subtract). $closeAdjustment removed wholesale with D-G
    // lease_close_adjustments DROP.

    // S-MILEAGE-EST-DAILY: does this lease bill via the estimated-daily model?
    // When it does, the generator's running true-up owns ALL mileage
    // reconciliation at close — the legacy overage line + manual bridge below
    // are suppressed (they would double-bill against the estimate lines already
    // on prior invoices), and the actual closing distance is instead fed to the
    // true-up via the cumulative_actual_km param on every generator call.
    $usesEstimateMileage = bccomp((string) ($lease['estimated_mileage_per_day_km'] ?? '0'), '0', 4) > 0;

    // Cumulative actual distance (km) to hand the generator's true-up. When a
    // closing odometer was supplied, the generator derives cumulative from
    // odoEnd − lease-start odometer itself (no override needed). Otherwise, for a
    // manual estimate lease, the operator-entered actual mileage (mileage_at_end,
    // in the lease unit) IS the lifetime distance driven — convert it to km.
    $cumulativeActualKmOverride = null;
    if ($usesEstimateMileage && $odoAtClose === null
        && $mileageAtEnd !== null && $mileageAtEnd > 0) {
        // S-AUDIT-LIFECYCLE-1 #19b: local renamed from $unit — it silently
        // CLOBBERED the equipment-unit row fetched under FOR UPDATE above,
        // so the lease.closed notification later read unit_number off the
        // string 'km' and degraded to the snapshot.
        $mileageUnitStr = (string) ($lease['mileage_unit'] ?? 'km');
        if ($mileageUnitStr === 'miles') {
            $conv = (string) ($lease['km_to_miles_conversion'] ?? '0.621371');
            if (bccomp($conv, '0', 6) <= 0) $conv = '0.621371';
            // miles ÷ (miles per km) = km
            $cumulativeActualKmOverride = bcdiv((string) $mileageAtEnd, $conv, 4);
        } else {
            $cumulativeActualKmOverride = (string) $mileageAtEnd;
        }
    }

    // ── S-HOURS-EST-DAILY: does this lease bill via the estimated-daily engine-
    // hours model? When it does, the generator's running true-up owns ALL hours
    // reconciliation at close: the legacy S-LEASE-HOURLY-RECON append below is
    // suppressed (it would double-bill against the estimate lines already on prior
    // invoices), and the lifetime actual hours are fed to the true-up via the
    // cumulative_actual_hours param on the final-invoice generator call(s).
    $usesEstimateHours = bccomp((string) ($lease['estimated_engine_hours_per_day'] ?? '0'), '0', 2) > 0;

    // Lifetime actual engine hours to hand the generator's hours true-up:
    // engine_hours_at_close − engine_hours_at_start (clamped >= 0). NULL when
    // either reading is missing → the estimate lines stand un-reconciled and the
    // true-up defers (same posture as a mileage close with no reading).
    $cumulativeActualHoursOverride = null;
    if ($usesEstimateHours && $hoursAtClose !== null
        && ($lease['engine_hours_at_start'] ?? null) !== null) {
        $hDiff = bcsub((string) $hoursAtClose, (string) $lease['engine_hours_at_start'], 2);
        $cumulativeActualHoursOverride = bccomp($hDiff, '0', 2) >= 0 ? $hDiff : '0.00';
    }

    // Legacy mileage overage final-invoice line item (pre-odometer
    // leases). Preserved for backwards compat; no priorExcessKm
    // subtraction (D-F retired). Suppressed for estimate-model leases
    // (true-up owns reconciliation — see $usesEstimateMileage above).
    $extraLines     = [];
    $hasMileageLine = false;
    if (!$usesEstimateMileage
        && $mileageAtEnd !== null && $lease['mileage_at_start'] !== null
        && bccomp((string)$lease['mileage_rate'], '0', 4) > 0)
    {
        $actualMileage    = (string)($mileageAtEnd - (int)$lease['mileage_at_start']);
        $includedMileage  = (string)($lease['estimated_mileage'] ?? '0');
        $overageMileage   = bcsub($actualMileage, $includedMileage, 4);

        if (bccomp($overageMileage, '0', 4) > 0) {
            $mileageCharge = bcround(bcmul($overageMileage, (string)$lease['mileage_rate'], 6), 2);
            if (bccomp($mileageCharge, '0', 2) > 0) {
                // S-MILEAGE-RATE-CONVERT-FIX: $overageMileage is ALREADY in the
                // lease's unit (this legacy path bills mileage_rate × native reading,
                // which is why its charge was always correct) — only the description
                // hardcoded "km". Label it in the lease's unit.
                $mOverUnit     = ($lease['mileage_unit'] ?? 'km') === 'miles' ? 'miles' : 'km';
                $mOverRateUnit = $mOverUnit === 'miles' ? 'mile' : 'km';
                $extraLines[] = [
                    'item_type'   => 'mileage',
                    'description' => "Mileage overage: {$overageMileage} {$mOverUnit} × \${$lease['mileage_rate']}/{$mOverRateUnit}",
                    'quantity'    => $overageMileage,
                    'unit'        => $mOverUnit,
                    'unit_price'  => (string)$lease['mileage_rate'],
                    'amount'      => $mileageCharge,
                    'is_credit'   => 0,
                    'taxable'     => 1,
                ];
                $hasMileageLine = true;
            }
        }
    }

    // S-CLOSE-MANUAL-MILEAGE-BRIDGE: make the "Actual Mileage (for billing)"
    // field (mileage_at_end) actually bill on a MANUAL lease. The legacy overage
    // block above only fires for mileage_at_start-bearing leases; a SAMSARA-3-era
    // manual lease (mileage_at_start NULL but mileage_rate_km set) silently
    // dropped the entered mileage — the second half of the MTTS68 /
    // INV-2026-00150 trap. Bridge it through the same guaranteed $extraLines
    // carrier as the closeout charges. The helper returns NULL unless this is
    // genuinely the manual-distance case and cannot overlap the legacy overage or
    // modern odometer paths (see ff_close_manual_mileage_bridge_line's guards).
    // Suppressed for estimate-model leases: the true-up bills mileage_at_end via
    // cumulative_actual_km inside the generator, so a standalone bridge line here
    // would double-bill on top of the estimate lines.
    if (!$hasMileageLine && !$usesEstimateMileage) {
        $priorMileageBilled = (bool) db_row(
            "SELECT 1 FROM invoice_line_items li
               JOIN invoices i ON i.id = li.invoice_id
              WHERE i.lease_id = ? AND i.deleted_at IS NULL AND i.status <> 'void'
                AND li.item_type IN ('mileage_usage', 'mileage')
              LIMIT 1",
            [$id]
        );
        $bridgeLine = ff_close_manual_mileage_bridge_line(
            $lease, $mileageAtEnd, $odoAtClose, $priorMileageBilled
        );
        if ($bridgeLine !== null) {
            $extraLines[]   = $bridgeLine;
            $hasMileageLine = true;
        }
    }

    // S-LEASE-SERVICE-CHARGES: closeout charges (sweep/wash/fuel) ride on the
    // final invoice alongside any mileage overage. The post-pass below guarantees
    // they still bill when no rental final invoice is generated.
    if (!empty($closeoutLines)) {
        $extraLines = array_merge($extraLines, $closeoutLines);
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

    // S-CLOSE-MANUAL-MILEAGE-WARN (gap a): a MANUAL lease with a per-km rate that
    // closes with NO billable mileage — no actual mileage entered (so the bridge
    // above produced nothing) AND no usable closing-vs-start odometer pair (so the
    // modern odometer auto-line won't fire) — silently bills $0 mileage. The
    // common cause is a manual lease whose starting odometer was never captured
    // (activation skips capture for non-samsara modes). Surface it non-blockingly
    // (audit + response field) so the operator notices the missing reading instead
    // of discovering it on the invoice. Never blocks the close.
    $mileageWarning = null;
    // Mirror InvoiceGenerator's drawdownGate exactly, including its precharge
    // sub-condition (precharge_invoiced_at IS NOT NULL OR precharge_enabled = 0):
    // a never-invoiced precharge lease (precharge_enabled=1 AND
    // precharge_invoiced_at IS NULL) suppresses the auto mileage line even with a
    // valid odometer pair, so without this term the warning would be wrongly
    // skipped (low-severity miss caught in adversarial review). No new query —
    // both columns are already in the lease SELECT above.
    $modernMileageWillBill = (
        $odoAtClose !== null && $odoPeriodStart !== null
        && bccomp((string) $odoAtClose, (string) $odoPeriodStart, 2) > 0
        && (($lease['precharge_invoiced_at'] ?? null) !== null
            || (int) ($lease['precharge_enabled'] ?? 0) === 0)
    );
    if (($lease['mileage_tracking_mode'] ?? 'off') === 'manual'
        && bccomp((string) ($lease['mileage_rate_km'] ?? '0'), '0', 4) > 0
        && !$hasMileageLine
        && !$modernMileageWillBill
    ) {
        // S-MILEAGE-UNITS: label the rate in the lease's unit ($/mile for a miles lease).
        $warnDisp = ff_mileage_line_display($lease, '0', (string) ($lease['mileage_rate_km'] ?? '0'));
        $mileageWarning = sprintf(
            'Lease %s tracks mileage manually and has a $%s/%s rate, but no closing odometer or actual mileage was entered — $0 mileage billed on this close.',
            (string) ($lease['contract_number'] ?? ''),
            (string) $warnDisp['rate'],
            (string) $warnDisp['rate_unit']
        );
        db_insert('audit_log', [
            'user_id'      => current_user_id(),
            'user_name'    => $changedBy,
            'action'       => 'update',
            'module'       => 'billing',
            'entity_type'  => 'lease',
            'entity_id'    => $id,
            'entity_label' => $lease['contract_number'] ?? null,
            'notes'        => '[FLEETFORGE_BILLING_WARNING] ' . $mileageWarning,
            'old_values'   => null,
            'new_values'   => json_encode([
                'mileage_tracking_mode' => 'manual',
                'mileage_rate_km'       => (string) $lease['mileage_rate_km'],
                'mileage_at_end'        => $mileageAtEnd,
                'odometer_at_close_km'  => $odoAtClose,
            ]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
        try {
            \FleetForge\Observability\Sentry::captureMessage($mileageWarning, 'warning');
        } catch (\Throwable) {
            // Observability must never block the close path.
        }
    }

    // S-HOURS-EST-DAILY (parity with S-CLOSE-MANUAL-MILEAGE-WARN): an estimate-hours
    // lease that closes with NO usable engine-hours pair (no engine_hours_at_close,
    // or the lease never captured engine_hours_at_start) leaves $cumulativeActualHoursOverride
    // NULL → no hours true-up books, and the customer keeps paying the running
    // estimate un-reconciled to actual. Surface it non-blockingly (audit + Sentry +
    // response field) exactly like the mileage case so the operator notices the
    // missing reading instead of discovering it on the invoice. Never blocks close.
    $hoursWarning = null;
    if ($usesEstimateHours && $cumulativeActualHoursOverride === null) {
        $hoursWarning = sprintf(
            'Lease %s bills estimated engine hours (%s hrs/day at $%s/hr) but no closing engine-hours reading was available (engine_hours_at_close and/or engine_hours_at_start missing) — the running estimate was NOT trued up to actual on this close.',
            (string) ($lease['contract_number'] ?? ''),
            rtrim(rtrim((string) ($lease['estimated_engine_hours_per_day'] ?? '0'), '0'), '.'),
            (string) ($lease['hourly_rate'] ?? '0')
        );
        db_insert('audit_log', [
            'user_id'      => current_user_id(),
            'user_name'    => $changedBy,
            'action'       => 'update',
            'module'       => 'billing',
            'entity_type'  => 'lease',
            'entity_id'    => $id,
            'entity_label' => $lease['contract_number'] ?? null,
            'notes'        => '[FLEETFORGE_BILLING_WARNING] ' . $hoursWarning,
            'old_values'   => null,
            'new_values'   => json_encode([
                'estimated_engine_hours_per_day' => (string) ($lease['estimated_engine_hours_per_day'] ?? '0'),
                'hourly_rate'                    => (string) ($lease['hourly_rate'] ?? '0'),
                'engine_hours_at_start'          => $lease['engine_hours_at_start'],
                'engine_hours_at_close'          => $hoursAtClose,
            ]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
        try {
            \FleetForge\Observability\Sentry::captureMessage($hoursWarning, 'warning');
        } catch (\Throwable) {
            // Observability must never block the close path.
        }
    }

    // S-LEASE-HOURLY-BILLING: derive the period-start engine hours for the final
    // invoice. Same priority as odometer: latest prior invoice's period-end
    // hours → lease start hours.
    $hoursPeriodStart = null;
    if ($hoursAtClose !== null) {
        $prevH = db_row(
            "SELECT engine_hours_at_period_end
               FROM invoices
              WHERE lease_id = ? AND deleted_at IS NULL
                AND engine_hours_at_period_end IS NOT NULL
              ORDER BY billing_period_end DESC, id DESC LIMIT 1",
            [$id]
        );
        if ($prevH && $prevH['engine_hours_at_period_end'] !== null) {
            $hoursPeriodStart = $prevH['engine_hours_at_period_end'];
        } elseif ($lease['engine_hours_at_start'] !== null) {
            $hoursPeriodStart = $lease['engine_hours_at_start'];
        }
    }

    $generator    = new \FleetForge\Billing\InvoiceGenerator();
    $finalInvoiceId = null;
    $advanceActions = [];
    // S-CLOSE-NO-ESTIMATE: true when a legacy-path engine run received the
    // closing reading (the partial_end final invoice). The append/fold branches
    // never run the engine, so without a run the estimate-model true-up would
    // silently be skipped — the carrier guarantee below covers those shapes.
    $legacyTrueUpRan = false;

    // ── S-CLOSE-OVERSHOOT: canonical lease billable extent ─────────
    // ONE definition (lease_billable_extent in _close_reconciliation.php) shared
    // with bulk_close.php + the remediation scripts, matching the generator's
    // extent math so a shortened invoice's period_end agrees with its amount.
    // S-LEASE-CLOSE-REMOVE-DAYS: pass billing_days_removed so this local
    // extentEnd (used as the partial_end final invoice's period_end — handles the
    // legacy period_independent path + the description dates — and as the
    // overshoot clamp's extent) is reduced in lockstep with InvoiceGenerator's
    // holistic extent. All three sites apply the SAME clamp on the SAME N.
    $extentEnd = lease_billable_extent(
        $actualReturnDate, $actualReturnTime, $lease['start_time'] ?? null, (string) $lease['start_date'],
        $billingDaysRemoved
    );

    // ── S-CLOSE-OVERSHOOT: clamp every non-advance rental invoice billed
    // past the lease extent (e.g. an activation partial_start invoice billed to
    // month-end on a lease returned mid-month). Runs BEFORE the advance /
    // legacy-full_month / partial_end logic below so the coverage anchor those
    // paths read (MAX billing_period_end) already reflects the clamped periods.
    // S-LEASE-HOURLY-RECON: forward the engine-hours window (derived above) so a
    // straddle invoice clamped here — which becomes the final rental invoice when
    // the partial_end below is skipped — bills the lease's engine hours. This is
    // the path that silently dropped hours on advance-style manual invoices that
    // overshot the return (e.g. INV-2026-00466). $hoursPeriodStart/$hoursAtClose
    // are the same readings the partial_end final invoice uses (lines ~1231).
    $overshootActions = reconcile_overshoot_invoices(
        $id, $lease, $extentEnd,
        $closeNotes ?: "Lease closed {$actualReturnDate} — billing period shortened to lease extent {$extentEnd}.",
        false,
        $hoursPeriodStart, $hoursAtClose
    );

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
            // S-LEASE-HOURLY-RECON: pass the engine-hours window so the reissued
            // (clamped) invoice — which is THIS advance lease's final rental
            // invoice — bills hours, just like the non-advance final invoice.
            // $hoursPeriodStart/$hoursAtClose are the same readings the legacy
            // path feeds its final invoice (derived above at S-LEASE-HOURLY-BILLING).
            if ($containingInv) {
                $advanceActions[] = adv_partial_refund_containing(
                    $containingInv, $lease, $actualReturnDate,
                    $closeNotes ?: 'Lease closed mid-period — refunding unused days.',
                    $hoursPeriodStart, $hoursAtClose
                );
            }
        }
        // else mode='no_refund': leave every advance invoice as-is.

        // Mileage overage / true-up: create a mileage_only carrier invoice when
        // there's a legacy mileage line to bill, OR — for an estimate-model lease —
        // whenever a closing reading exists (odometer or operator mileage_at_end),
        // so the generator's running true-up reconciles actual distance against the
        // estimates already charged on the prepaid advance invoices. D-H drops the
        // partial_end final invoice, so mileage needs its own home here.
        $needsEstimateTrueUp = $usesEstimateMileage
            && ($cumulativeActualKmOverride !== null || $odoAtClose !== null);
        // S-HOURS-EST-DAILY: the same mileage_only carrier reconciles engine hours
        // for an advance-billed estimate-hours lease (the engine emits the hours
        // true-up on mileage_only when cumulative_actual_hours is supplied).
        $needsEstimateHoursTrueUp = $usesEstimateHours && $cumulativeActualHoursOverride !== null;
        if ($hasMileageLine || $needsEstimateTrueUp || $needsEstimateHoursTrueUp) {
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
                'cumulative_actual_km'        => $cumulativeActualKmOverride,
                'cumulative_actual_hours'     => $cumulativeActualHoursOverride,
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
        // holds the LEASE row lock (the serializer createFromLease also takes —
        // the cron never locks equipment_units) so the cron cannot insert mid-flight.
        $draftAction = legacy_handle_existing_full_month_draft(
            $id, $actualReturnDate, $extentEnd, $lease, $extraLines,
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
            // Per spec §12 [PASS-3:2C]: final period = day after the last billed day
            // through actual_return_date, pro-rated.
            //
            // Derive the coverage anchor from ACTUAL live-invoice coverage —
            // MAX(billing_period_end) over the lease's non-void, non-deleted
            // invoices — rather than the denormalized leases.last_billed_date.
            // The denormalized anchor is monotonic (GREATEST in InvoiceGenerator)
            // and is NOT walked back everywhere on void/delete, so it can point
            // PAST real coverage after the most-recent invoice is voided; trusting
            // it here would skip this final period and lose revenue. The live MAX
            // is authoritative. written_off counts as covered (the period was
            // billed; non-payment is a collections matter, not a re-bill), so we
            // exclude only 'void' — matching HolisticLeaseEngine::sumAlreadyBilled.
            // S-CLOSE-OVERSHOOT: restrict the coverage anchor to invoices STARTING
            // on/before the extent. A future-dated invoice (e.g. a sent/paid one
            // that was full-credited above but keeps its immutable future
            // period_end, or a stray manual future-month invoice) must not push
            // coverageEnd past the real usage — that would suppress the legitimate
            // partial_end final invoice for the actually-rented tail.
            $coverageRow = db_row(
                "SELECT MAX(billing_period_end) AS max_end
                   FROM invoices
                  WHERE lease_id = ?
                    AND deleted_at IS NULL
                    AND status <> 'void'
                    AND billing_period_end IS NOT NULL
                    AND billing_period_start <= ?",
                [$id, $extentEnd]
            );
            // S-CLOSE-ZEROBILL-FIX: trust ONLY the live invoice coverage (the MAX
            // above, restricted to non-void invoices starting on/before the
            // extent). Do NOT fall back to the denormalized leases.last_billed_date:
            // it is a monotonic GREATEST() that is NOT walked back when an invoice
            // is voided, so after legacy_handle_existing_full_month_draft() voids the
            // closing-month full_month draft (mid-month close — or a removed-days
            // close — of a lease that started on the 1st), last_billed_date still
            // points at month-end. Using it as the anchor pushed periodStart past the
            // extent and SKIPPED this partial_end regeneration entirely, zero-billing
            // the actual rental (repro: start 2026-05-01, return 2026-05-20 → $0).
            // A NULL live MAX correctly means "no live invoice covers any of this
            // lease" → bill from start_date. This is exactly the "live MAX is
            // authoritative" contract the block comment above already states.
            $coverageEnd = $coverageRow['max_end'] ?: null;

            $periodStart = $coverageEnd
                ? date('Y-m-d', strtotime($coverageEnd . ' +1 day'))
                : $lease['start_date'];

            // S-FIX-2 Bug #6 / S-CLOSE-OVERSHOOT: guard against date inversion.
            // If billing coverage already reaches (or extends past) the lease
            // extent, the previous invoice covers it and there is no new period
            // to bill. Skip partial_end generation rather than emit an invoice
            // with period_end < period_start. Compared against $extentEnd (the
            // time-of-day-adjusted billable extent), NOT the raw return date, so
            // this stays consistent with the overshoot clamp above and with the
            // period_end the engine uses for the final invoice's amount.
            if ($periodStart > $extentEnd) {
                // S-LEASE-CLOSE-ONE-INVOICE: the partial_end rental is skipped (the
                // already-billed invoice — typically the overshoot pass's clamped
                // regeneration — already covers through the billable extent). Rather
                // than let the close's mileage/closeout $extraLines spill onto a
                // SEPARATE adjustment invoice (the old behaviour, which produced a 2nd
                // invoice on top of the rental), FOLD them onto that existing draft so
                // the close yields ONE invoice. Draft-only + counter-safe (Path-B
                // total_invoiced delta, OB untouched on a draft). Only the
                // mileage/closeout lines are appended — never GPS — so the rental
                // invoice's own GPS (for the extent) is not double-counted. If no
                // matching draft is found we fall through and the closeout fallback
                // (~line 1183) still bills the extra_lines so nothing is dropped.
                if (!empty($extraLines)) {
                    $clampedDraft = db_row(
                        "SELECT id, invoice_number, lease_id, subtotal, total_amount,
                                gst_exempt_snapshot, pst_exempt_snapshot, tax_exempt_snapshot
                           FROM invoices
                          WHERE lease_id = ? AND status = 'draft' AND deleted_at IS NULL
                            AND billing_period_end = ?
                            AND billing_type IN ('partial_start', 'partial_end', 'single_period', 'full_month')
                          ORDER BY id DESC LIMIT 1 FOR UPDATE",
                        [$id, $extentEnd]
                    );
                    if ($clampedDraft) {
                        $appendAction = legacy_append_mileage_to_full_month_draft(
                            $clampedDraft, $lease, $extraLines,
                            $odoPeriodStart, $odoAtClose, $odoSource, $odoFetchedAt
                        );
                        $finalInvoiceId = (int) $clampedDraft['id'];
                        $advanceActions[] = array_merge($appendAction, [
                            'action' => 'appended_to_clamped_draft',
                            'reason' => "mileage/closeout charges folded onto the rental draft {$clampedDraft['invoice_number']} (one invoice — no separate adjustment).",
                        ]);
                    }
                }

                db_insert('audit_log', [
                    'user_id'      => current_user_id(),
                    'user_name'    => current_user()['name'] ?? 'system',
                    'action'       => 'lease_closed',
                    'module'       => 'leases',
                    'entity_type'  => 'lease',
                    'entity_id'    => $id,
                    'entity_label' => $lease['contract_number'],
                    'notes'        => "S-FIX-2 Bug #6: lease closed within an already-billed period (billing coverage through {$coverageEnd} >= billable extent {$extentEnd}, return {$actualReturnDate}). No partial_end invoice generated."
                                    . ($finalInvoiceId !== null ? " Mileage/closeout folded onto the existing draft (one invoice)." : ''),
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);
                $advanceActions[] = [
                    'action' => 'partial_end_skipped',
                    'reason' => "billing coverage ({$coverageEnd}) >= billable extent ({$extentEnd}); previous invoice already covers the closing date.",
                ];
            } else {
                $invoiceResult = $generator->createFromLease([
                    'lease_id'          => $id,
                    'period_start'      => $periodStart,
                    'period_end'        => $extentEnd,
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
                    'cumulative_actual_km'        => $cumulativeActualKmOverride,
                    // S-LEASE-HOURLY-BILLING: bill the closing period's engine hours.
                    'engine_hours_at_period_start' => $hoursPeriodStart,
                    'engine_hours_at_period_end'   => $hoursAtClose,
                    // S-HOURS-EST-DAILY: lifetime actual hours for the estimate-model
                    // true-up (ignored by the engine when perDayHours == 0).
                    'cumulative_actual_hours'     => $cumulativeActualHoursOverride,
                ]);
                $finalInvoiceId = $invoiceResult['invoice_id'];
                // S-CLOSE-NO-ESTIMATE: this engine run carried the estimate
                // true-up (cumulative_actual_km above) — the carrier guarantee
                // below must not duplicate it.
                $legacyTrueUpRan = true;
            }
        }
    }

    // ── S-LEASE-HOURLY-RECON guarantee: never silently drop engine hours ──
    // Most close paths bill hours on the final invoice (the partial_end engine
    // params above, or the overshoot reissue). But when the partial_end is SKIPPED
    // because an existing invoice already covers the lease extent (e.g. an
    // activation invoice billed exactly to the return date — prod INV-2026-00650),
    // NO invoice ever receives the hourly_usage line, and hours can't ride a
    // mileage_only/adjustment invoice (the engine suppresses them there). Belt-and-
    // suspenders: if hours are due and NO live invoice on this lease carries an
    // hourly line yet, fold one onto the draft covering the lease's final day,
    // reusing the same append+recompute helper the mileage fold uses. The
    // "none exists" guard makes this idempotent with the partial_end / reissue
    // paths — it can never double-bill.
    // S-HOURS-EST-DAILY: suppressed for estimate-model leases — the generator's
    // running true-up (fed cumulative_actual_hours on the final invoice / carrier)
    // owns hours reconciliation there; a raw hourly_usage append would double-bill
    // on top of the estimate lines already on prior invoices.
    if (!$usesEstimateHours
        && $hoursAtClose !== null && $hoursPeriodStart !== null
        && bccomp((string) ($lease['hourly_rate'] ?? '0'), '0', 4) > 0) {
        // $hrsDue is the FINAL still-unbilled segment: hoursAtClose minus the latest
        // prior invoice's period-end hours (derived above). It is > 0 only when no
        // prior invoice billed up to the close reading — so a reopen/reclose (where
        // the earlier fold set engine_hours_at_period_end = hoursAtClose) yields 0
        // and skips here without any lease-wide guard.
        $hrsDue = bcsub((string) $hoursAtClose, (string) $hoursPeriodStart, 2);
        if (bccomp($hrsDue, '0', 2) > 0) {
            // The covering invoice: the latest editable DRAFT with a NORMAL billing
            // type. Restricting to partial_start/partial_end/single_period/full_month
            // (a) keeps hours off mileage_only/adjustment/credit_note invoices — the
            // engine suppresses hours there by design, so folding would mis-type the
            // line (advance close creates a mileage_only invoice before this runs);
            // and (b) `target_has_hours` scopes the idempotency check to THIS covering
            // invoice — NOT a lease-wide count — so a multi-period lease whose earlier
            // interim invoice already carries hours still bills the final segment here
            // (a lease-wide count would wrongly skip it → silent under-billing).
            $hrsTarget = db_row(
                "SELECT id, invoice_number, subtotal, total_amount,
                        gst_exempt_snapshot, pst_exempt_snapshot, tax_exempt_snapshot,
                        (SELECT COUNT(*) FROM invoice_line_items li
                          WHERE li.invoice_id = invoices.id AND li.item_type = 'hourly_usage') AS target_has_hours
                   FROM invoices
                  WHERE lease_id = ? AND deleted_at IS NULL AND status = 'draft'
                    AND billing_type IN ('partial_start', 'partial_end', 'single_period', 'full_month')
                  ORDER BY billing_period_end DESC, id DESC LIMIT 1",
                [$id]
            );
            if ($hrsTarget && (int) $hrsTarget['target_has_hours'] === 0) {
                $rateHr  = (string) $lease['hourly_rate'];
                $hrsLine = [
                    'item_type'   => 'hourly_usage',
                    'description' => 'Engine hours: ' . number_format((float) $hrsDue, 2) . ' hrs × $' . $rateHr . '/hr',
                    'quantity'    => $hrsDue,
                    'unit'        => 'hours',
                    'unit_price'  => $rateHr,
                    'amount'      => bcround(bcmul($hrsDue, $rateHr, 6), 2),
                    'is_credit'   => 0,
                    'taxable'     => 1,
                ];
                legacy_append_mileage_to_full_month_draft($hrsTarget, $lease, [$hrsLine], null, null, null, null);
                // Snapshot the hours window (parity with the engine path; lets a
                // later regenerate preserve the line + makes a reclose compute 0).
                db_update('invoices', [
                    'engine_hours_at_period_start' => $hoursPeriodStart,
                    'engine_hours_at_period_end'   => $hoursAtClose,
                    'period_engine_hours'          => $hrsDue,
                ], 'id = ?', [(int) $hrsTarget['id']]);
                $advanceActions[] = [
                    'action' => 'hours_folded_onto_final',
                    'reason' => "engine hours ({$hrsDue} hrs) folded onto {$hrsTarget['invoice_number']} — the final invoice covering the return date (the partial_end that normally bills hours was skipped).",
                ];
            } elseif (!$hrsTarget) {
                // No editable normal-type draft to carry the hours (the covering
                // invoice is sent/paid, or the only draft is a mileage_only/adjustment
                // invoice the engine won't put hours on) — surface it, never silently
                // drop or mis-type it.
                $advanceActions[] = [
                    'action' => 'hours_unbilled_no_draft',
                    'reason' => "engine hours ({$hrsDue} hrs × \${$lease['hourly_rate']}/hr) are due but no editable rental draft covers the return — bill them manually (Create Invoice or a line on the final invoice).",
                ];
            }
            // else: the covering draft already carries an hourly line for this close
            // (partial_end / overshoot-reissue path billed it, or a reclose) → nothing.
        }
    }

    // S-LEASE-SERVICE-CHARGES: guarantee closeout charges always bill. The
    // branches above attach $extraLines to the rental final invoice, but some
    // close paths create none (close within an already-billed period; or an
    // advance close with no mileage overage). When that happens, emit a
    // standalone 'adjustment' invoice (base-rental-free, extra_lines only) so
    // operator-entered sweep/wash/fuel — and any mileage overage — are never
    // silently dropped. 'adjustment' invoices skip drawdown, so this runs
    // safely before the precharge-refund block below.
    if ($finalInvoiceId === null && !empty($extraLines)) {
        $adjInv = $generator->createFromLease([
            'lease_id'          => $id,
            'period_start'      => $actualReturnDate,
            'period_end'        => $actualReturnDate,
            'billing_type'      => 'adjustment',
            'invoice_type'      => 'final',
            'notes'             => $closeNotes,
            'created_by'        => current_user_id(),
            'auto_generated'    => 1,
            'generation_source' => 'lease_close',
            'extra_lines'       => $extraLines,
        ]);
        $finalInvoiceId = $adjInv['invoice_id'] ?? null;
        $advanceActions[] = [
            'action' => 'closeout_adjustment_invoice',
            'reason' => 'closeout/service charges billed on a standalone adjustment invoice (no rental final invoice was generated).',
        ];
    }

    // ── S-CLOSE-NO-ESTIMATE guarantee: the estimate-model true-up always gets
    // an engine run at close. The legacy partial_end call above carries it, but
    // two legacy shapes skip that call entirely: (a) last-day close onto an
    // existing cron full_month draft (append branch → $skipPartialEnd), and
    // (b) close within an already-billed period (S-FIX-2 Bug #6 skip). The fold
    // helpers only append raw lines — they never run InvoiceGenerator — so an
    // estimate lease with a closing reading ended those closes UN-reconciled
    // (estimates stood, actual ignored). Mirror the advance branch: emit a
    // mileage_only carrier; the engine suppresses estimates there and books the
    // lifetime true-up. Runs AFTER the closeout guarantee so extras keep their
    // own carrier ($extraLines stay off this invoice — never double-billed).
    // mode='off' excluded (the engine's true-up won't run → empty invoice).
    // If billing already equals actual the true-up is $0 and this emits a
    // line-less draft — rare (estimates virtually never equal actual exactly)
    // and visible, not silent.
    // S-HOURS-EST-DAILY: the SAME carrier reconciles engine hours — the engine
    // emits the hours true-up on a mileage_only invoice when cumulative_actual_hours
    // is supplied. A hours-only estimate lease (no mileage estimate) still needs the
    // carrier, so the gate fires for either model. $legacyTrueUpRan (set by the
    // partial_end run, which carries BOTH true-ups) suppresses it when that ran.
    $carrierNeedsMileage = $usesEstimateMileage
        && ($lease['mileage_tracking_mode'] ?? '') !== 'off'
        && ($cumulativeActualKmOverride !== null || $odoAtClose !== null);
    $carrierNeedsHours = $usesEstimateHours && $cumulativeActualHoursOverride !== null;
    if (!$isAdvanceClose && !$legacyTrueUpRan && ($carrierNeedsMileage || $carrierNeedsHours)) {
        $carrierInv = $generator->createFromLease([
            'lease_id'          => $id,
            'period_start'      => $actualReturnDate,
            'period_end'        => $actualReturnDate,
            'billing_type'      => 'mileage_only',
            'invoice_type'      => 'final',
            'notes'             => $closeNotes,
            'created_by'        => current_user_id(),
            'auto_generated'    => 1,
            'generation_source' => 'lease_close',
            'extra_lines'       => [],
            'odometer_at_period_start_km' => $odoPeriodStart,
            'odometer_at_period_end_km'   => $odoAtClose,
            'odometer_source'             => $odoSource,
            'odometer_fetched_at'         => $odoFetchedAt,
            // Pass each override only for the model that needs it — a hours-only
            // lease passes null km (the engine's mileage branch stays idle).
            'cumulative_actual_km'        => $carrierNeedsMileage ? $cumulativeActualKmOverride : null,
            'cumulative_actual_hours'     => $carrierNeedsHours ? $cumulativeActualHoursOverride : null,
        ]);
        if ($finalInvoiceId === null) {
            $finalInvoiceId = $carrierInv['invoice_id'] ?? null;
        }
        $reconWhat = $carrierNeedsMileage && $carrierNeedsHours ? 'mileage + engine hours'
            : ($carrierNeedsHours ? 'engine hours' : 'mileage');
        $advanceActions[] = [
            'action' => 'estimate_trueup_carrier',
            'reason' => "estimate-model {$reconWhat} reconciled on a standalone mileage_only invoice (the rental final invoice was skipped/folded, so no engine run carried the true-up).",
        ];
    }

    // S-MILEAGE-3 D-G: close_adjustment processing block + D-F
    // priorExcessKm safeguard audit_log rows retired wholesale
    // (~340 LOC). Model C per-period excess concept has no Model B
    // counterpart — the drawdown lifecycle handles all per-invoice
    // mileage events, and the close-time precharge refund (D-A
    // through D-N) handles residual-balance disposition. The
    // lease_close_adjustments table DROPs in the same commit via
    // migration 202605MMDDhhmm_S-MILEAGE-3_close_adjustments_drop.sql.

    // ── S-MILEAGE-3-FIX-0 (2026-05-13) / S-MILEAGE-3 D-B/D-C/D-D/D-E/D-K/D-L:
    //    precharge refund dispatch — REPOSITIONED post-final-invoice ──
    //
    // Original C3 placement of this block (BEFORE the final-invoice
    // generation) caused a financial double-credit bug (KI #106; T1
    // Step 5): the close-generated final invoice independently fired
    // InvoiceGenerator's drawdown emit which consumed precharge_balance
    // a second time on top of the refund. K-21 lock: any block reading
    // post-drawdown lease state must run AFTER InvoiceGenerator calls
    // and RE-READ the column from DB (not use the cached $lease value).
    //
    // Now: re-SELECT precharge_balance + precharge_refund_method via
    // FOR UPDATE on the lease row (existing transaction holds the
    // equipment_unit FOR UPDATE per D20; the lease row lock is implicit
    // via the prior db_update at line ~727, but this re-read makes the
    // post-drawdown read consistent). Refund fires only when
    // residualBalance > 0 after the final invoice's drawdown emit.
    //
    // Cases:
    //   (a) precharge_enabled=0 → needsRefund=false → skip block
    //   (b) precharge_enabled=1 + residualBalance=0 (drawdown consumed
    //       full balance) → needsRefund=false → skip; refund_method
    //       stays NULL on the closed lease (which is correct — no
    //       refund owed). The close response's precharge_refund field
    //       reports null in this case.
    //   (c) precharge_enabled=1 + residualBalance > 0 → needsRefund=true
    //       → require precharge_refund block in request body → dispatch
    //       cash/credit per locked semantics.
    //
    // D-D state machine: precharge_refund_method is immutable once
    // set. 409 PRECHARGE_REFUND_LOCKED fires if a request reaches here
    // with the method already populated (edge case — status was
    // reverted manually). Normal flow can't reach this branch on a
    // completed lease since the state machine check at line ~653
    // already rejects non-active leases.
    //
    // D-B (i) cash branch defers settled_at; D-C credit branch stamps
    // settled_at at close-commit (credit_note creation IS settlement
    // per D-E (ii)).
    $leasePostFinal = db_row(
        "SELECT precharge_balance, precharge_refund_method
           FROM leases
          WHERE id = ?
          FOR UPDATE",
        [$id]
    );
    $residualBalance = $leasePostFinal['precharge_balance'] !== null
        ? (string) $leasePostFinal['precharge_balance']
        : '0.00';

    $needsRefund = (int) ($lease['precharge_enabled'] ?? 0) === 1
        && bccomp($residualBalance, '0.00', 2) > 0;

    if ($needsRefund) {
        // D-D 409 gate — must NOT already have a refund method set.
        if ($leasePostFinal['precharge_refund_method'] !== null) {
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
        // S-MILEAGE-3-FIX-0: refund amount = post-drawdown residual,
        // NOT the pre-drawdown balance from the original $lease SELECT.
        $refundAmount  = $residualBalance;
        $refundNotes   = $prechargeRefund['notes'];
        $refundCnId    = null;
        $refundCnNum   = null;
        $settledAtSql  = null;

        if ($refundMethod === 'credit') {
            // D-C: create credit_note with source='precharge_refund'.
            // Reuses the gap-free credit_note_number pattern from
            // api/v1/credit_notes/create.php (FOR UPDATE on settings row).
            // S-AUDIT-LIFECYCLE-1 #24e: shared gap-free minting helper
            // (was one of four verbatim copies).
            $refundCnNum = ff_next_credit_note_number();

            $cnReason = "Precharge balance refund — lease {$lease['contract_number']}"
                . ($refundNotes ? " ({$refundNotes})" : '');

            $refundCnId = db_insert('credit_notes', [
                'credit_note_number'      => $refundCnNum,
                'company_name_snapshot'   => $lease['company_name_snapshot']  ?? null,
                'customer_name_snapshot'  => $lease['customer_name_snapshot'] ?? null,
                'billing_address_snapshot' => $lease['billing_address']       ?? null,
                'province_snapshot'        => $lease['province']               ?? null,
                'customer_email_snapshot'  => $lease['customer_email']         ?? null,
                'customer_id'             => $lease['customer_id'],
                'lease_id'                => $id,
                'source'                  => 'precharge_refund',
                'source_invoice_id'       => null,
                'source_payment_id'       => null,
                'amount'                  => $refundAmount,
                'currency'                => $lease['currency'] ?? 'CAD',
                // S-AUDIT-BILLING-ENGINE-1 #21: freeze the CAD rate (bridge conversion).
                'exchange_rate_to_cad'    => ($lease['currency'] ?? 'CAD') === 'USD' ? ($lease['exchange_rate_to_cad'] ?? null) : null,
                'amount_remaining'        => $refundAmount,
                'status'                  => 'active',
                'expires_at'              => null,
                'reason'                  => $cnReason,
                'internal_notes'          => null,
                'created_by'              => current_user_id(),
            ]);

            // Existing accounting integration — DR 4xxx / CR 2060
            // Customer Credits Liability per S-FIX-2 D47/D48. If CPA
            // confirms precharge-source credit needs different JE
            // shape (D-I question d), S-MILEAGE-3-ACCT-SPEC will rework.
            // S-AUDIT-BILLING-ENGINE-1 #20: NO try/catch — this was the ONLY
            // mint site that swallowed the issue-JE failure (inside the close
            // txn!), letting a precharge_refund CN commit with no 2060 credit;
            // its later apply then debited a liability that was never booked.
            // A mapping/period failure now rolls back the whole close, exactly
            // like credit_notes/create.php and every other mint site (§16
            // hard-block contract).
            \FleetForge\Accounting\AutoEntryBridge::onCreditNoteIssued((int) $refundCnId, current_user_id());
            // S-AUDIT-BILLING-ENGINE-1 #20: QBO mirror for the auto-minted CN
            // (queue row commits atomically with the CN; enqueue never throws).
            \FleetForge\QboPushers\CreditMemoEnqueuer::enqueue((int) $refundCnId, 'create');

            $settledAtSql = date('Y-m-d H:i:s');
        }
        // else: $refundMethod === 'cash' — settledAtSql stays null;
        // operator stamps later via api/v1/leases/mark_refund_settled.php.

        // D-D state machine UPDATE — write method (+ settled_at for
        // credit branch). precharge_balance is NOT zeroed — V1 D182
        // preserve discipline (the live balance retains the historical
        // post-drawdown residual; refund_method + settled_at are the
        // refund-execution signals).
        $leaseRefundUpdate = [
            'precharge_refund_method' => $refundMethod,
        ];
        if ($settledAtSql !== null) {
            $leaseRefundUpdate['precharge_refund_settled_at'] = $settledAtSql;
        }
        db_update('leases', $leaseRefundUpdate, 'id = ?', [$id]);

        // D-L audit_log: lease_precharge_refund_issued. action='update'
        // per D102 workaround pattern (audit_log.action ENUM doesn't
        // include refund-specific values; entity_type carries routing).
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
    // Case (b) — needsRefund=false because residualBalance=0: no
    // refund-block dispatch; $prechargeRefundResult stays null; close
    // response's precharge_refund field reports null (no refund owed).

    $result = [
        'id'                => $id,
        'status'            => 'completed',
        'invoice_id'        => $finalInvoiceId,
        'advance_close'     => $isAdvanceClose,
        'reconciliation'    => $isAdvanceClose ? $reconMode : null,
        'advance_actions'   => $advanceActions,
        // S-CLOSE-OVERSHOOT: per-invoice clamps applied to non-advance rental
        // invoices that were billed past the lease's billable extent.
        'overshoot_actions' => $overshootActions,
        // S-MILEAGE-3 D-K: precharge refund result. NULL when no refund
        // was needed (precharge_enabled=0 OR precharge_balance=0).
        // Populated with method + amount + settled_at + credit_note_*
        // when refund dispatch fired.
        'precharge_refund'  => $prechargeRefundResult,
        // S-CLOSE-MANUAL-MILEAGE-WARN: non-null when a manual lease with a
        // mileage rate closed without any billable mileage (gap a). The UI
        // surfaces it so the operator can correct the draft before sending.
        'mileage_warning'   => $mileageWarning,
        // S-HOURS-EST-DAILY: non-null when an estimate-hours lease closed with no
        // usable engine-hours reading (the estimate was not trued up to actual).
        'hours_warning'     => $hoursWarning,
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
                url:        '/fleetforge/portal/leases/view?id=' . $id
            );
        }
    } catch (\Throwable $e) {
        error_log('[NOTIF lease.closed] ' . $e->getMessage());
    }
});
} catch (\FleetForge\Billing\BillingRateException $e) {
    error_log("[leases/close] Lease #{$id} rate hole: " . $e->getMessage());
    json_error(
        'BILLING_RATE_INCOMPLETE',
        'The lease was not closed and is unchanged — its final invoice cannot be billed: '
        . 'this lease has an estimate configured with no rate to price it. Set the missing '
        . 'rate (Rate Amendment) or clear the estimate (Edit Lease), then close again.',
        422,
        ['lease_id' => $id, 'detail' => $e->getMessage(), 'billing_context' => $e->context]
    );
}

// ── S-ACCT-LESSOR-3: lease termination JE ────────────────────────────
// Post-commit hook — fires only for capital classifications (sales_type
// / direct_financing). Most cash flow is already booked by the period
// JEs (including the BPO settlement row). This handles the unguaranteed-
// residual write-off edge case. No-op when closing NI is already zero.
// Bridge gates on accounting.lessor_module_enabled='1'. Non-fatal —
// close transaction has already committed; JE failure logs only.
try {
    $leaseRowL3 = db_row(
        "SELECT id, classification FROM leases WHERE id = ? AND deleted_at IS NULL",
        [$id]
    );
    if ($leaseRowL3 && in_array($leaseRowL3['classification'], ['sales_type', 'direct_financing'], true)) {
        $term = \FleetForge\Accounting\AutoEntryBridge::onLeaseTermination(
            (int) $id,
            (int) current_user_id()
        );
        if ($term) {
            $result['termination_je'] = [
                'posted'                => $term['je'] !== null,
                'je_id'                 => $term['je']['id'] ?? null,
                'residual_written_off'  => $term['residual_written_off'] ?? null,
                'reason'                => $term['reason'] ?? null,
            ];
        } else {
            $result['termination_je'] = [
                'posted' => false,
                'reason' => 'bridge disabled or lessor module disabled',
            ];
        }
    }
} catch (\Throwable $e) {
    error_log('[S-ACCT-LESSOR-3 termination] ' . $e->getMessage());
    $result['termination_je'] = ['posted' => false, 'error' => $e->getMessage()];
}

// L43: roles without payments:view (dispatchers can close via leases:edit) must
// not see AR dollar figures in the close response. Strip the server-computed
// refund amount + capital-lease residual write-off; status, ids, refund method,
// dates, and operational actions remain so the closer still sees what happened.
if (!can_view_financials()) {
    if (isset($result['precharge_refund']) && is_array($result['precharge_refund'])) {
        // (S-AUDIT-LIFECYCLE-1: dropped the dead 'precharge_balance_at_close'
        // unset — that key only ever exists in the audit JSON, never here.)
        unset($result['precharge_refund']['amount']);
    }
    if (isset($result['termination_je']) && is_array($result['termination_je'])) {
        unset($result['termination_je']['residual_written_off']);
    }
    // S-AUDIT-LIFECYCLE-1 #19c: L43 residual — the advance/overshoot action
    // descriptors still carried dollar figures (CN amounts, old/new invoice
    // totals) past the redaction. Strip every money key from each action.
    foreach (['advance_actions', 'overshoot_actions'] as $actionsKey) {
        if (isset($result[$actionsKey]) && is_array($result[$actionsKey])) {
            foreach ($result[$actionsKey] as &$action) {
                if (is_array($action)) {
                    unset($action['amount'], $action['old_total'], $action['new_total'],
                          $action['credit_amount'], $action['total_amount']);
                }
            }
            unset($action);
        }
    }
}

json_success($result);
