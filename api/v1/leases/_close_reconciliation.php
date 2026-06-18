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
 * @param string      $returnDate actual_return_date, 'Y-m-d'
 * @param string|null $returnTime actual_return_time, 'H:i:s' or null
 * @param string|null $startTime  lease.start_time (pickup), 'H:i:s' or null
 * @param string      $startDate  lease.start_date, 'Y-m-d' (extent floors here)
 * @return string  billable extent, 'Y-m-d'
 */
function lease_billable_extent(string $returnDate, ?string $returnTime, ?string $startTime, string $startDate): string
{
    if (!empty($returnTime) && !empty($startTime)) {
        $grace = (int) (settings_get('lease.return_grace_minutes', '0') ?? '0');
        return \FleetForge\Billing\HolisticLeaseEngine::effectiveBillableEndDate(
            $returnDate, (string) $returnTime, (string) $startTime, $grace, $startDate
        );
    }
    return $returnDate;
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
 * KNOWN LIMITATION (OPERATOR_FOLLOWUPS F33): does NOT restore leases.precharge_balance
 * that the voided invoice consumed via a mileage drawdown_credit line. If a
 * drawdown-carrying draft is voided here, the close-time precharge refund
 * under-refunds. Narrow (the canonical activation Invoice 1 carries no drawdown);
 * pre-existing void-path gap, deferred.
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
 * @return array            list of per-invoice action descriptors for the API response
 */
function reconcile_overshoot_invoices(int $leaseId, array $lease, string $extentEnd, string $reason, bool $clampFullMonthStraddle = false): array
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
            $act = adv_partial_refund_containing($inv, $lease, $extentEnd, $reason);
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
