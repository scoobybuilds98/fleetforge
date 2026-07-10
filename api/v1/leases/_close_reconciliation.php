<?php
/**
 * api/v1/leases/_close_reconciliation.php
 *
 * Shared lease-close billing reconciliation helpers. Required by BOTH
 * api/v1/leases/close.php and api/v1/leases/bulk_close.php so the
 * S-CLOSE-OVERSHOOT clamp (no invoice billed past the lease extent) and the
 * advance/credit-note primitives have ONE definition and cannot drift between
 * the two close paths. Pure global functions; callers must have bootstrapped
 * config/app.php first (db_*, current_user(), settings_get, AutoEntryBridge).
 *
 * Functions:
 *   lease_billable_extent()        canonical "last day this lease may be billed"
 *   adv_void_or_credit_full()      draft -> void | sent/paid -> full credit_note
 *   adv_void_invoice()             status-aware Path-B void + JE reversal
 *   adv_partial_refund_containing() containing period: draft -> void+regenerate-shortened | sent -> prorated credit
 *   adv_create_credit_note()       gap-free credit_note + auto-JE
 *   reconcile_overshoot_invoices() clamp every non-advance rental invoice billed past the extent
 */

/**
 * Canonical lease billable extent (the last calendar day this lease may be
 * billed). MUST match InvoiceGenerator::createFromLease / generateForLease
 * (lib/Billing/InvoiceGenerator.php:196-215 / 1599-1612): the return date, with
 * the S-LEASE-RENTAL-DAY-TIME rule applied when BOTH pickup (start) and return
 * times are captured (return at/before pickup-time-of-day + grace ⇒ the return
 * day is not billed). Defining it once here keeps close.php, bulk_close.php and
 * the remediation scripts from drifting from the generator's amount math.
 *
 * S-LEASE-CLOSE-REMOVE-DAYS: $daysRemoved subtracts N billable days off the END
 * of the extent (operator "Remove N days" at close). Applied AFTER the
 * time-of-day rule and clamped so the extent never goes before start_date
 * (min 1 billed day). This clamp is byte-identical in logic to the one in
 * InvoiceGenerator::createFromLease (lib/Billing/InvoiceGenerator.php) so the
 * overshoot clamp here and the holistic final-invoice amount cannot diverge
 * (S-CLOSE-OVERSHOOT invariant).
 *
 * @param string      $returnDate  actual_return_date, 'Y-m-d'
 * @param string|null $returnTime  actual_return_time, 'H:i:s' or null
 * @param string|null $startTime   lease.start_time (pickup), 'H:i:s' or null
 * @param string      $startDate   lease.start_date, 'Y-m-d' (extent floors here)
 * @param int         $daysRemoved leases.billing_days_removed — N billable days to
 *                                 shave off the end of the extent (0 = no-op)
 * @return string  billable extent, 'Y-m-d'
 */
function lease_billable_extent(string $returnDate, ?string $returnTime, ?string $startTime, string $startDate, int $daysRemoved = 0): string
{
    if (!empty($returnTime) && !empty($startTime)) {
        $grace  = (int) (settings_get('lease.return_grace_minutes', '0') ?? '0');
        $extent = \FleetForge\Billing\HolisticLeaseEngine::effectiveBillableEndDate(
            $returnDate, (string) $returnTime, (string) $startTime, $grace, $startDate
        );
    } else {
        $extent = $returnDate;
    }
    // S-LEASE-CLOSE-REMOVE-DAYS: shave N billable days off the end, clamped to
    // start_date (min 1 billed day). Must stay in lockstep with InvoiceGenerator.
    if ($daysRemoved > 0) {
        $reduced = (new \DateTimeImmutable($extent))->modify("-{$daysRemoved} day")->format('Y-m-d');
        $extent  = ($reduced < $startDate) ? $startDate : $reduced;
    }
    return $extent;
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
 *
 * F33 CLOSED (S-AUDIT-LIFECYCLE-1): ff_reverse_precharge_on_invoice_removal()
 * now restores leases.precharge_balance consumed by the voided invoice's
 * mileage_drawdown_credit line(s) — the clamped reissue's createFromLease
 * re-draws from the restored balance, so the close-time refund stays exact.
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

    // S-AUDIT-LIFECYCLE-1 (closes F33): restore the voided invoice's precharge
    // drawdown before any clamped reissue re-runs createFromLease, so the
    // replacement draws from a correct balance and the refund stays exact.
    ff_reverse_precharge_on_invoice_removal(
        (int) $inv['id'], current_user_id(), current_user()['name'] ?? 'system', 'voided (close reconciliation)'
    );
}

/**
 * ADV-BILL-1 D-H helper: refund the unused portion of the containing-period invoice.
 *
 * Draft → modify the existing invoice in place: shorten period to actual_return_date
 *         and recompute amounts via createFromLease. Implementation: void the draft and
 *         spawn a replacement (functionally identical to in-place edit, gives us tested
 *         math without duplicating ProRateCalculator/TaxCalculator logic).
 * Sent/paid → issue a credit_note for the prorated unused amount.
 *
 * S-LEASE-HOURLY-RECON: $hoursPeriodStart/$hoursPeriodEnd carry the engine-hours
 * readings for the reissued (draft) invoice. For an advance-billing lease the
 * D-H final partial_end invoice is dropped, so this clamped reissue IS the final
 * rental invoice and must bill engine/reefer hours (start→close) — exactly like
 * the non-advance final invoice in close.php. The reissue's billing_type is the
 * normal partial_start/partial_end, so the engine emits the hourly_usage line
 * (it suppresses hours only for mileage_only/adjustment/credit_note). Passed
 * through only on the draft branch; null on either ⇒ no hourly line (lease has no
 * hourly_rate or no close reading), matching the engine's own gate.
 */
function adv_partial_refund_containing(array $inv, array $lease, string $returnDate, string $reason, ?string $hoursPeriodStart = null, ?string $hoursPeriodEnd = null): array
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
        $genParams = [
            'lease_id'          => (int) $lease['id'],
            'period_start'      => $start,
            'period_end'        => $returnDate,
            'billing_type'      => $billingType,
            'invoice_type'      => 'regular',
            'notes'             => "Replaces voided advance invoice {$inv['invoice_number']} (lease closed {$returnDate}).",
            'created_by'        => current_user_id(),
            'auto_generated'    => 1,
            'generation_source' => 'lease_close',
        ];
        // S-LEASE-HOURLY-RECON: this clamped reissue is the advance lease's FINAL
        // rental invoice (D-H drops the partial_end), so it must carry the engine
        // hours — otherwise the close silently loses the hourly_usage charge.
        if ($hoursPeriodStart !== null && $hoursPeriodEnd !== null) {
            $genParams['engine_hours_at_period_start'] = $hoursPeriodStart;
            $genParams['engine_hours_at_period_end']   = $hoursPeriodEnd;
        }
        $replacement = $generator->createFromLease($genParams);

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
    // KNOWN LIMITATION (OPERATOR_FOLLOWUPS F32): this is a LINEAR day-fraction of
    // total_amount; base pricing is tiered/capped (ProRateCalculator), so for a
    // sent/paid invoice with tiered pricing the credit can over/under-refund. The
    // draft branch above is exact (re-runs the engine). Pre-existing advance-path
    // behavior; left linear here pending a tier-aware credit (= total − engine
    // charge for [start..extent]).
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
    // S-AUDIT-LIFECYCLE-1 #24e: shared gap-free minting helper
    // (was one of four verbatim copies).
    $cnNumber = ff_next_credit_note_number();

    $cnId = db_insert('credit_notes', [
        'credit_note_number'     => $cnNumber,
        'company_name_snapshot'  => $inv['company_name_snapshot']  ?? $lease['company_name_snapshot']  ?? null,
        'customer_name_snapshot' => $inv['customer_name_snapshot'] ?? $lease['customer_name_snapshot'] ?? null,
        'billing_address_snapshot' => $inv['billing_address_snapshot'] ?? null,
        'province_snapshot'      => $inv['province_snapshot']      ?? $lease['province']               ?? null,
        'customer_email_snapshot' => $inv['customer_email_snapshot'] ?? null,
        'customer_id'            => $lease['customer_id'],
        'lease_id'               => (int) $lease['id'],
        'source'                 => 'invoice_adjustment',
        'source_invoice_id'      => (int) $inv['id'],
        'amount'                 => $amount,
        'currency'               => $inv['currency'] ?? 'CAD',
        // S-AUDIT-BILLING-ENGINE-1 #21: freeze the source invoice's CAD rate.
        'exchange_rate_to_cad'   => ($inv['currency'] ?? 'CAD') === 'USD' ? ($inv['exchange_rate_to_cad'] ?? null) : null,
        'amount_remaining'       => $amount,
        'status'                 => 'active',
        'reason'                 => $reason,
        'created_by'             => current_user_id(),
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
    // S-AUDIT-BILLING-ENGINE-1 #20: QBO mirror for the auto-minted CN.
    \FleetForge\QboPushers\CreditMemoEnqueuer::enqueue((int) $cnId, 'create');

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
 * S-CLOSE-OVERSHOOT helper: clamp EVERY non-advance rental invoice whose
 * billing period extends past the lease's billable extent at close.
 *
 * This is the canonical fix for the whole class of "invoice billed past the
 * actual return" bugs. The motivating case: a lease that STARTS mid-month gets
 * an activation `partial_start` Invoice 1 billed start_date → end-of-month
 * (date('Y-m-t')); if the lease is then RETURNED within that same month, that
 * invoice's period_end (month-end) now exceeds the lease's real extent and was
 * never corrected — legacy_handle_existing_full_month_draft() only ever matched
 * `full_month` invoices, and the partial_end fallback was then suppressed by the
 * coverage guard because month-end coverage already reached past the return.
 *
 * For each overshooting invoice this reuses the SAME primitives the advance
 * branch already trusts:
 *   - period STRADDLES the extent (start ≤ extent < end)  → adv_partial_refund_containing()
 *       draft  → void + regenerate shortened to $extentEnd (Path-B: total_invoiced self-corrects, OB untouched)
 *       sent/paid → prorated credit_note for the unused tail (immutable invoice left intact)
 *   - period WHOLLY past the extent (start > extent)       → adv_void_or_credit_full()
 *       draft  → void;  sent/paid → full credit_note
 *
 * Scope / exclusions (WHERE clause):
 *   - status NOT IN (void, written_off): a void never overshoots; a written-off
 *     receivable is a collections matter, not an over-bill to credit.
 *   - generation_source <> 'advance': advance invoices remain the exclusive
 *     domain of the advance-reconciliation branch (no double-handling).
 *   - billing_type NOT IN (mileage_only, adjustment, credit_note): not calendar
 *     rental periods; never shortened.
 *   - `full_month` handling depends on $clampFullMonthStraddle (see below):
 *       • a full_month STRADDLING the extent (mid-month close) is, in close.php,
 *         the domain of legacy_handle_existing_full_month_draft() — which voids
 *         it and lets the partial_end block regenerate the final invoice WITH the
 *         mileage overage ($extraLines) + invoice_type='final'. We must NOT
 *         pre-empt that (it would silently drop the mileage charge), so close.php
 *         passes $clampFullMonthStraddle=false → such rows are skipped here.
 *         bulk_close.php has no legacy_handle and bills no mileage, so it passes
 *         true → the full_month straddle is clamped here directly.
 *       • a full_month WHOLLY PAST the extent (a future-month invoice, e.g. a
 *         manually-created one) is handled by neither legacy_handle (closing-month
 *         only) nor — historically — anything else, so it is ALWAYS voided/credited
 *         here regardless of $clampFullMonthStraddle.
 *   - billing_period_end > $extentEnd: strictly past the billable extent.
 *
 * Concurrency: the SELECT is FOR UPDATE; the outer close transaction already
 * holds the equipment_unit FOR UPDATE lock the monthly cron also respects, so
 * the cron cannot insert/modify a billing row between our read and our action.
 *
 * Idempotent across reopen/reclose: re-running against the same (or earlier)
 * extent finds nothing — clamped invoices end exactly at the extent and the
 * `> ?` predicate excludes them; sent/paid rows already credited are excluded by
 * the NOT EXISTS adjustment-credit guard.
 *
 * @param int    $leaseId   lease being closed
 * @param array  $lease     lease row (id, contract_number, customer_id, snapshots…)
 * @param string $extentEnd canonical billable extent (Y-m-d) — see caller; must
 *                          equal InvoiceGenerator's extent derivation so the
 *                          shortened invoice's period_end matches its amount.
 * @param string $reason    free-text reason captured on each void/credit/regenerate
 * @param bool   $clampFullMonthStraddle  clamp a full_month straddling the extent
 *                          HERE (true, bulk_close) vs defer to the caller's
 *                          legacy_handle_existing_full_month_draft (false, close.php).
 * @param ?string $hoursPeriodStart  S-LEASE-HOURLY-RECON: engine hours at the start
 *                          of the straddle reissue's period (null ⇒ no hourly line).
 * @param ?string $hoursPeriodEnd    engine hours at close. The straddle invoice is
 *                          clamped to $extentEnd (the lease's final billable day), so
 *                          it becomes the final rental invoice and the caller's
 *                          partial_end is then skipped (coverage reaches the extent) —
 *                          billing hours here is the ONLY place they land, and cannot
 *                          double-bill (the skipped partial_end would have billed
 *                          them otherwise). Only ONE invoice can contain the extent,
 *                          and advance invoices are excluded below, so this never
 *                          collides with the advance containing-invoice reissue.
 * @return array            list of per-invoice action descriptors for the API response
 */
function reconcile_overshoot_invoices(int $leaseId, array $lease, string $extentEnd, string $reason, bool $clampFullMonthStraddle = false, ?string $hoursPeriodStart = null, ?string $hoursPeriodEnd = null): array
{
    $overshoot = db_select(
        "SELECT id, invoice_number, status, billing_type,
                billing_period_start, billing_period_end,
                subtotal, discount_amount, subtotal_after_discount, total_amount,
                balance_due, currency, customer_id, lease_id,
                gst_exempt_snapshot, pst_exempt_snapshot, tax_exempt_snapshot,
                company_name_snapshot, customer_name_snapshot, billing_address_snapshot,
                province_snapshot, customer_email_snapshot
           FROM invoices
          WHERE lease_id = ?
            AND deleted_at IS NULL
            AND status NOT IN ('void', 'written_off')
            AND (generation_source IS NULL OR generation_source <> 'advance')
            AND billing_type NOT IN ('mileage_only', 'adjustment', 'credit_note')
            AND billing_period_end IS NOT NULL
            AND billing_period_end > ?
            -- Idempotency: a sent/paid invoice keeps its (immutable) period_end
            -- after being credited for an earlier overshoot, so it would re-match
            -- on a reopen+reclose. Skip any invoice that already carries an
            -- adjustment credit note from a prior overshoot reconciliation —
            -- prevents a double credit. (Drafts never have one: they are
            -- voided+regenerated, and the replacement ends at the extent.)
            AND NOT EXISTS (
                SELECT 1 FROM credit_notes cn
                 WHERE cn.source_invoice_id = invoices.id
                   AND cn.source = 'invoice_adjustment'
                   AND cn.status <> 'void'
                   AND cn.deleted_at IS NULL
            )
          ORDER BY billing_period_start ASC, id ASC
          FOR UPDATE",
        [$leaseId, $extentEnd]
    );

    $actions = [];
    $deferred = 0;
    foreach ($overshoot as $inv) {
        if ($inv['billing_period_start'] > $extentEnd) {
            // Entire period is past the lease extent → void (draft) or full credit (sent/paid).
            // Applies to ALL rental types, including a future-month full_month.
            $act = adv_void_or_credit_full($inv, $lease, $reason);
            $act['overshoot'] = 'wholly_past';
        } else {
            // Period straddles the extent. A full_month straddle is normally the
            // caller's (close.php legacy_handle) job — skip unless told to clamp.
            if ($inv['billing_type'] === 'full_month' && !$clampFullMonthStraddle) {
                $deferred++;
                continue;
            }
            // Shorten (draft) or prorated credit (sent/paid). Pass $extentEnd (not
            // the raw return date) so the regenerated invoice's period_end honours
            // the same time-of-day rule the engine uses for the amount.
            // S-LEASE-HOURLY-RECON: forward the engine-hours window so the clamped
            // reissue (now the final rental invoice — the caller skips partial_end)
            // bills the lease's engine hours instead of silently dropping them.
            $act = adv_partial_refund_containing($inv, $lease, $extentEnd, $reason, $hoursPeriodStart, $hoursPeriodEnd);
            $act['overshoot'] = 'straddles';
        }
        $act['old_period_end'] = $inv['billing_period_end'];
        $actions[] = $act;
    }

    // Summary audit row — records the pass ran even when it found nothing to do.
    $note = count($overshoot) === 0
        ? "S-CLOSE-OVERSHOOT: no non-advance invoice billed past lease extent {$extentEnd}; nothing reconciled."
        : "S-CLOSE-OVERSHOOT: reconciled " . count($actions) . " invoice(s) billed past lease extent {$extentEnd}"
          . ($deferred ? " ({$deferred} full_month straddle(s) deferred to legacy_handle)" : "") . ".";
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'lease_closed',
        'module'       => 'leases',
        'entity_type'  => 'lease',
        'entity_id'    => $leaseId,
        'entity_label' => $lease['contract_number'] ?? null,
        'notes'        => $note,
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return $actions;
}

/**
 * S-CLOSE-MANUAL-MILEAGE-BRIDGE — build the close-time mileage line for a MANUAL
 * lease from the operator-entered "Actual Mileage (for billing)" value
 * (leases.mileage_at_end), so that field actually bills instead of being
 * silently orphaned. This is the second half of the MTTS68 / INV-2026-00150
 * trap: a manual lease with mileage_rate_km set, the operator typed an actual
 * mileage at close, and NO mileage line appeared because the modern biller reads
 * only the decimal odometer_*_km columns and the legacy overage path needs
 * mileage_at_start (NULL on a SAMSARA-3-era lease).
 *
 * Returns ONE mileage_usage extra-line (distance × mileage_rate_km, km-canonical
 * to match the odometer path) for close.php to append to $extraLines — the same
 * guaranteed carrier sweep/wash/fuel ride (it reaches the rental final invoice,
 * the advance mileage_only invoice, OR a standalone adjustment invoice). Returns
 * NULL when the bridge does not apply.
 *
 * Cannot double-bill — each guard removes exactly one competing path:
 *   - mode must be 'manual'      → 'off' is suppressed+warned; 'samsara'
 *                                  auto-bills via GPS distance.
 *   - mileage_at_start IS NULL   → the legacy overage path (reads mileage_at_start
 *                                  + the old mileage_rate column) does not fire.
 *   - odoAtClose IS NULL         → the modern odometer auto-line does not fire
 *                                  (close.php derives the period-start odometer
 *                                  only when a closing odometer was supplied).
 *   - $priorMileageBilled false  → a per-period mileage model already in effect
 *                                  on this lease is not re-billed as a lump total.
 *
 * Pure function (no DB) so the gating is unit-testable; the caller supplies
 * $priorMileageBilled from a prior-invoice probe.
 *
 * @param array       $lease              close.php lease row (needs
 *                                        mileage_tracking_mode, mileage_rate_km,
 *                                        mileage_at_start, mileage_unit,
 *                                        km_to_miles_conversion)
 * @param int|null    $mileageAtEnd       operator-entered actual mileage (lease unit)
 * @param string|null $odoAtClose         decimal closing odometer (km), or null
 * @param bool        $priorMileageBilled a non-void invoice already carries a mileage line
 * @return array|null  one mileage_usage extra-line, or null if inapplicable
 */
function ff_close_manual_mileage_bridge_line(
    array $lease,
    ?int $mileageAtEnd,
    ?string $odoAtClose,
    bool $priorMileageBilled
): ?array {
    // Manual mode only.
    if (($lease['mileage_tracking_mode'] ?? 'off') !== 'manual') {
        return null;
    }
    // Needs a per-km rate to bill against.
    if (bccomp((string) ($lease['mileage_rate_km'] ?? '0'), '0', 4) <= 0) {
        return null;
    }
    // Needs an operator-entered actual mileage > 0.
    if ($mileageAtEnd === null || $mileageAtEnd <= 0) {
        return null;
    }
    // Legacy overage path owns mileage_at_start-bearing leases.
    if (($lease['mileage_at_start'] ?? null) !== null) {
        return null;
    }
    // Modern odometer path owns closing-odometer-bearing closes.
    if ($odoAtClose !== null) {
        return null;
    }
    // Per-period mileage already billed — don't re-bill the total.
    if ($priorMileageBilled) {
        return null;
    }

    // Bill km-canonical (matches the odometer path + mileage_rate_km). Convert a
    // miles-unit lease's actual mileage to km via the lease's conversion factor.
    $unit = (string) ($lease['mileage_unit'] ?? 'km');
    if ($unit === 'miles') {
        $conv = (string) ($lease['km_to_miles_conversion'] ?? '0.621371');
        if (bccomp($conv, '0', 6) <= 0) {
            $conv = '0.621371';
        }
        // miles ÷ (miles per km) = km
        $distanceKm = bcdiv((string) $mileageAtEnd, $conv, 4);
    } else {
        $distanceKm = (string) $mileageAtEnd;
    }

    $rateKm = (string) $lease['mileage_rate_km'];
    // S-MILEAGE-RATE-CONVERT-FIX: bill + label in the lease's unit; computing the
    // charge from the display values keeps quantity × unit_price == amount.
    $mDisp  = ff_mileage_line_display($lease, $distanceKm, $rateKm);
    $charge = bcround(bcmul((string) $mDisp['distance'], (string) $mDisp['rate'], 6), 2);
    if (bccomp($charge, '0', 2) <= 0) {
        return null;
    }

    return [
        'item_type'   => 'mileage_usage',
        'description' => 'Mileage usage: ' . number_format((float) $mDisp['distance'], 2) . ' ' . $mDisp['unit'] . ' × $' . $mDisp['rate'] . '/' . $mDisp['rate_unit'],
        'quantity'    => $mDisp['distance'],
        'unit'        => $mDisp['unit'],
        'unit_price'  => $mDisp['rate'],
        'amount'      => $charge,
        'is_credit'   => 0,
        'taxable'     => 1,
    ];
}
