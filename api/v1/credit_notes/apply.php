<?php
declare(strict_types=1);

/**
 * api/v1/credit_notes/apply.php
 *
 * Apply a credit note to an outstanding invoice. This is the most complex
 * endpoint in the credit notes module — it touches 5 counters in one transaction.
 *
 * Business rules:
 *   - D18: Credit note currency MUST match invoice currency → 422 CURRENCY_MISMATCH
 *   - D20: FOR UPDATE on BOTH the credit note AND the invoice
 *          (prevents concurrent apply + concurrent payment race)
 *   - CREDIT_EXCEEDS_BALANCE: amount_applied cannot exceed invoice balance_due
 *   - CREDIT_EXCEEDS_BALANCE: amount_applied cannot exceed credit_note.amount_remaining
 *   - D16: bcmath only — all monetary math via strings
 *   - Invoice state machine: sent/overdue → partially_paid or paid
 *   - Trap 6: 5 counters updated in ONE transaction:
 *       1. credit_notes.amount_remaining  (decremented)
 *       2. credit_notes.status            (active → partially_used → fully_used)
 *       3. invoices.credits_applied       (incremented)
 *       4. invoices.balance_due           (decremented)
 *       5. customers.outstanding_balance  (decremented)
 *   - credit_note_applications row created inside the same transaction
 *   - Accounting note (§PASS-6:G2): credit note application posts
 *     DR 2060 Customer Credits Liability / CR 1030 AR — deferred to S111+ accounting module
 *
 * @method  POST
 * @body    JSON: credit_note_id (required), invoice_id (required), amount (required)
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 201 { application_id, credit_note_number, invoice_number,
 *               invoice_status, invoice_balance_due, credit_note_remaining }
 *
 * Decisions: D16/D18/D20, Trap 6, §6 State Machines, §PASS-6:G2
 * Session: S011
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

// -----------------------------------------------------------------------
// 1. Input validation
// -----------------------------------------------------------------------
$body = json_body();

$creditNoteId = clean_int($body['credit_note_id'] ?? null);
if (!$creditNoteId) {
    json_error('MISSING_REQUIRED', 'credit_note_id is required.', 422);
}

$invoiceId = clean_int($body['invoice_id'] ?? null);
if (!$invoiceId) {
    json_error('MISSING_REQUIRED', 'invoice_id is required.', 422);
}

$amountRaw = clean_decimal($body['amount'] ?? null);
if ($amountRaw === null || bccomp($amountRaw, '0', 6) <= 0) {
    json_error('VALIDATION_ERROR', 'amount must be a positive number.', 422);
}

// -----------------------------------------------------------------------
// 2. Pre-flight checks (outside lock — fast-fail before locking both rows)
// -----------------------------------------------------------------------
$cnCheck = db_row(
    "SELECT id, credit_note_number, customer_id, currency, amount_remaining, status
     FROM credit_notes WHERE id = ? AND deleted_at IS NULL",
    [$creditNoteId]
);
if (!$cnCheck) {
    json_error('NOT_FOUND', 'Credit note not found.', 404);
}

// Only active or partially_used notes can be applied
if (!in_array($cnCheck['status'], ['active', 'partially_used'], true)) {
    json_error(
        'INVALID_TRANSITION',
        "Cannot apply a credit note with status '{$cnCheck['status']}'. Only active or partially_used notes can be applied.",
        409
    );
}

$invCheck = db_row(
    "SELECT id, invoice_number, customer_id, currency, status, balance_due
     FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$invoiceId]
);
if (!$invCheck) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
}

// Invoice must be payable
if ($invCheck['status'] === 'void') {
    json_error('INVOICE_VOID', 'Cannot apply a credit to a voided invoice.', 422);
}
if ($invCheck['status'] === 'paid') {
    json_error('CREDIT_EXCEEDS_BALANCE', 'This invoice is already fully paid.', 422);
}
if (!in_array($invCheck['status'], ['sent', 'partially_paid', 'overdue'], true)) {
    json_error('INVALID_TRANSITION', "Invoice status '{$invCheck['status']}' does not allow credit application.", 409);
}

// D18: Currency must match
if ($cnCheck['currency'] !== $invCheck['currency']) {
    json_error(
        'CURRENCY_MISMATCH',
        "Credit note currency ({$cnCheck['currency']}) does not match invoice currency ({$invCheck['currency']}).",
        422
    );
}

// Both must belong to the same customer
if ($cnCheck['customer_id'] !== $invCheck['customer_id']) {
    json_error(
        'VALIDATION_ERROR',
        'Credit note and invoice do not belong to the same customer.',
        422
    );
}

// -----------------------------------------------------------------------
// 3. Main transaction — lock both rows, apply, update 5 counters
// -----------------------------------------------------------------------
$result = null;

db_transaction(function () use (
    $creditNoteId, $invoiceId, $amountRaw, $cnCheck, $invCheck, &$result
) {
    // ------------------------------------------------------------------
    // 3a. Lock credit note first, then invoice (consistent lock ordering
    //     prevents deadlocks — always lock in the same order site-wide)
    //     D20: FOR UPDATE on both rows
    // ------------------------------------------------------------------
    $cn = db_row(
        "SELECT id, credit_note_number, customer_id, currency, amount_remaining, status
         FROM credit_notes WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$creditNoteId]
    );
    if (!$cn) {
        json_error('NOT_FOUND', 'Credit note not found.', 404);
    }

    // Re-check inside lock — status may have changed between pre-flight and now
    if (!in_array($cn['status'], ['active', 'partially_used'], true)) {
        json_error('INVALID_TRANSITION', "Credit note status '{$cn['status']}' does not allow application.", 409);
    }

    $invoice = db_row(
        "SELECT id, invoice_number, customer_id, lease_id, currency, status,
                balance_due, amount_paid, credits_applied, total_amount
         FROM invoices WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$invoiceId]
    );
    if (!$invoice) {
        json_error('NOT_FOUND', 'Invoice not found.', 404);
    }

    if ($invoice['status'] === 'void') {
        json_error('INVOICE_VOID', 'Cannot apply a credit to a voided invoice.', 422);
    }
    if ($invoice['status'] === 'paid') {
        json_error('CREDIT_EXCEEDS_BALANCE', 'Invoice is already fully paid.', 422);
    }

    $cnRemaining  = (string) $cn['amount_remaining'];
    $invoiceBalance = (string) $invoice['balance_due'];

    // D20 guard: amount cannot exceed invoice balance_due
    if (bccomp($amountRaw, $invoiceBalance, 6) > 0) {
        json_error(
            'CREDIT_EXCEEDS_BALANCE',
            "Amount ({$amountRaw}) exceeds invoice balance_due ({$invoiceBalance}).",
            422
        );
    }

    // Guard: amount cannot exceed credit_note.amount_remaining
    if (bccomp($amountRaw, $cnRemaining, 6) > 0) {
        json_error(
            'CREDIT_EXCEEDS_BALANCE',
            "Amount ({$amountRaw}) exceeds credit note remaining balance ({$cnRemaining}).",
            422
        );
    }

    $amountApplied = bcround($amountRaw, 2);

    // ------------------------------------------------------------------
    // 3b. Create credit_note_applications row
    // ------------------------------------------------------------------
    $appId = db_insert('credit_note_applications', [
        'credit_note_id' => $creditNoteId,
        'invoice_id'     => $invoiceId,
        'amount_applied' => $amountApplied,
        'applied_by'     => current_user_id(),
        'applied_at'     => date('Y-m-d H:i:s'),
    ]);

    // ------------------------------------------------------------------
    // 3c. Counter 1 + 2: Update credit_notes.amount_remaining and status
    // ------------------------------------------------------------------
    $newCnRemaining = bcround(bcsub($cnRemaining, $amountApplied, 6), 2);
    if (bccomp($newCnRemaining, '0', 2) < 0) {
        $newCnRemaining = '0.00';
    }

    // Status progression: active → partially_used → fully_used
    $newCnStatus = (bccomp($newCnRemaining, '0', 2) === 0) ? 'fully_used' : 'partially_used';

    db_update('credit_notes', [
        'amount_remaining' => $newCnRemaining,
        'status'           => $newCnStatus,
        'updated_at'       => date('Y-m-d H:i:s'),
    ], 'id = ?', [$creditNoteId]);

    // ------------------------------------------------------------------
    // 3d. Counter 3 + 4: Update invoices.credits_applied and balance_due
    //     balance_due = total_amount - credits_applied - amount_paid
    //     (recalculate from source values — never just subtract delta)
    // ------------------------------------------------------------------
    $newCreditsApplied = bcround(bcadd((string) $invoice['credits_applied'], $amountApplied, 6), 2);
    $newBalanceDue = bcround(
        bcsub(
            bcsub((string) $invoice['total_amount'], $newCreditsApplied, 6),
            (string) $invoice['amount_paid'],
            6
        ),
        2
    );
    if (bccomp($newBalanceDue, '0', 2) < 0) {
        $newBalanceDue = '0.00';
    }

    // Invoice state machine (§6): partially_paid or paid based on balance
    $newInvoiceStatus = (bccomp($newBalanceDue, '0', 2) === 0) ? 'paid' : 'partially_paid';
    // WHY: overdue stays payable — credit application resolves it same as a payment

    $invUpdates = [
        'credits_applied' => $newCreditsApplied,
        'balance_due'     => $newBalanceDue,
        'status'          => $newInvoiceStatus,
        'updated_at'      => date('Y-m-d H:i:s'),
    ];
    if ($newInvoiceStatus === 'paid') {
        $invUpdates['paid_date'] = date('Y-m-d');
    }

    db_update('invoices', $invUpdates, 'id = ?', [$invoiceId]);

    // ------------------------------------------------------------------
    // 3e. Counter 5: Update customers.outstanding_balance (Trap 6)
    // ------------------------------------------------------------------
    db_execute(
        "UPDATE customers SET outstanding_balance = GREATEST(0, outstanding_balance - ?), updated_at = NOW() WHERE id = ?",
        [$amountApplied, $invoice['customer_id']]
    );

    // ------------------------------------------------------------------
    // 3f. Audit log — inside same transaction (FIX #19 pattern)
    // ------------------------------------------------------------------
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'status_change',
        'module'       => 'invoices',
        'entity_type'  => 'credit_note_application',
        'entity_id'    => $appId,
        'entity_label' => $cn['credit_note_number'] . '→' . $invoice['invoice_number'],
        'notes'        => "Credit note {$cn['credit_note_number']} ({$invoice['currency']} {$amountApplied}) applied to invoice {$invoice['invoice_number']}. Invoice status: {$newInvoiceStatus}. CN remaining: {$newCnRemaining}.",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // Auto-JE: DR 2060 Customer Credits Liability / CR 1030 AR
    // WHY: PASS-6:G2 — this is the step that actually reduces AR in the GL.
    // Inside same transaction — JE failure rolls back the application (A8, §16).
    \FleetForge\Accounting\AutoEntryBridge::onCreditNoteApplied(
        $creditNoteId,
        $invoiceId,
        $amountApplied,
        current_user_id()
    );

    $result = [
        'application_id'        => $appId,
        'credit_note_number'    => $cn['credit_note_number'],
        'credit_note_status'    => $newCnStatus,
        'credit_note_remaining' => $newCnRemaining,
        'invoice_number'        => $invoice['invoice_number'],
        'invoice_status'        => $newInvoiceStatus,
        'invoice_balance_due'   => $newBalanceDue,
        'amount_applied'        => $amountApplied,
    ];
});

json_success($result, 201);
