<?php
declare(strict_types=1);

/**
 * api/v1/damage_claims/update.php
 *
 * Update a damage claim — metadata fields and/or status transition.
 *
 * Two operations in one endpoint:
 *   A) Metadata update: description, damage_location, severity, notes,
 *      resolution_notes, estimated_repair_cost, actual_repair_cost,
 *      customer_liable_amount, insurance_claim_amount, work_order_id,
 *      invoice_id.
 *   B) Status transition: status field triggers the state machine.
 *
 * State machine (inferred from ENUM):
 *   reported      → assessed, written_off
 *   assessed      → repair_ordered, written_off
 *   repair_ordered→ invoiced, resolved, written_off
 *   invoiced      → resolved, written_off
 *   resolved      → [terminal]
 *   written_off   → [terminal]
 *
 * Recovery invoice rules (bug #8 — claims moved to 'invoiced' with no invoice
 * link silently skipped the GL step, and the UI had no way to set the link):
 *   - invoice_id must exist, not be void, and belong to the claim's customer.
 *   - Moving to 'invoiced' (or changing/clearing the link while invoiced)
 *     requires a customer + a linked invoice. A DRAFT is accepted: it is not
 *     revenue yet, so nothing posts now — FinancialActions::sendInvoice() calls
 *     AutoEntryBridge::onDamageRecoveryBilled() for every invoiced claim linked
 *     to the invoice when it is sent. (Requiring a sent invoice would have made
 *     'invoiced' unreachable on a deployment that bills from drafts.)
 *   - Once the recovery is linked in the GL (a live source_type='damage_recovery'
 *     JE for this claim), the invoice link cannot be re-pointed or removed.
 *   - AutoEntryBridge::onDamageRecoveryBilled fires on the transition to
 *     'invoiced' AND when the link is set/changed on an already-invoiced claim
 *     (so legacy invoiced-without-invoice claims can be repaired). It is
 *     idempotent (one damage_recovery JE per claim) — never double-posts.
 *   GL effect: the invoice's existing send JE (DR 1030 AR / CR revenue / CR tax)
 *   is re-tagged source_type 'invoice' → 'damage_recovery', source_id = claim id.
 *   No new money moves. Only when a sent invoice has NO JE (accounting was off
 *   at send) does the bridge post DR AR / CR Damage Recovery Revenue for the
 *   customer-liable amount (falling back to the invoice total).
 *
 * D19: optimistic lock — caller must supply updated_at matching DB value.
 * D16: monetary amounts via bcmath.
 *
 * @method  POST
 * @body    JSON: id (required), updated_at (required for D19),
 *               status?, description?, severity?, damage_location?,
 *               notes?, resolution_notes?,
 *               estimated_repair_cost?, actual_repair_cost?,
 *               customer_liable_amount?, insurance_claim_amount?,
 *               work_order_id?, invoice_id?
 * @auth    Session required; require_permission('maintenance','edit')
 * @returns 200 { id, claim_number, status, updated_at, gl_journal_entry?, write_off_id? }
 *          (write_off_id: the acc_bad_debt_writeoffs row when a 'written_off'
 *          transition wrote off the recovery invoice — S-QBO-INVOICE-WRITEOFF)
 *
 * Decisions: D5 (soft delete), D16 (bcmath), D19 (optimistic lock), §6 (state machine)
 * Session: S012
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'edit');

// -----------------------------------------------------------------------
// 1. Load and validate claim
// -----------------------------------------------------------------------
$body   = json_body();
$fields = [];

$claimId = clean_int($body['id'] ?? null);
if (!$claimId) {
    $fields['id'] = 'Damage claim ID is required.';
}

$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    $fields['updated_at'] = 'Optimistic lock token is required.';
}

if ($fields) {
    json_validation_error($fields);
}

$claim = db_row(
    "SELECT * FROM damage_claims WHERE id = ? AND deleted_at IS NULL",
    [$claimId]
);
if (!$claim) {
    json_error('NOT_FOUND', 'Damage claim not found.', 404);
}

// D19: optimistic lock
if (!optimistic_lock_matches($submittedUpdatedAt, $claim['updated_at'])) {
    json_error('STALE_DATA',
        'This damage claim was modified by another user. Refresh and try again.', 409,
        ['fields' => ['updated_at' => 'This damage claim was modified by another user. Refresh and try again.']]);
}

// -----------------------------------------------------------------------
// 2. State machine validation
// -----------------------------------------------------------------------
$allowedTransitions = [
    'reported'       => ['assessed', 'written_off'],
    'assessed'       => ['repair_ordered', 'written_off'],
    'repair_ordered' => ['invoiced', 'resolved', 'written_off'],
    'invoiced'       => ['resolved', 'written_off'],
    'resolved'       => [],    // terminal
    'written_off'    => [],    // terminal
];

$newStatus = clean_string($body['status'] ?? null);
if ($newStatus !== null && $newStatus !== $claim['status']) {
    $allowed = $allowedTransitions[$claim['status']] ?? [];
    if (!in_array($newStatus, $allowed, true)) {
        json_error('INVALID_TRANSITION',
            "Cannot move a damage claim from '{$claim['status']}' to '{$newStatus}'.", 409,
            ['fields' => ['status' => "Cannot move a damage claim from '{$claim['status']}' to '{$newStatus}'."]]);
    }
}

// -----------------------------------------------------------------------
// 3. Collect updatable fields (only apply if present in body)
//    VALID-2: accumulate every field error before responding
// -----------------------------------------------------------------------
$updates = [];

// Status
if ($newStatus !== null) {
    $updates['status'] = $newStatus;
}

// Text fields
if (array_key_exists('description', $body)) {
    $desc = clean_string($body['description'] ?? null, 5000);
    if (!$desc) {
        $fields['description'] = 'Description is required.';
    } else {
        $updates['description'] = $desc;
    }
}

if (array_key_exists('severity', $body)) {
    $sev = clean_string($body['severity'] ?? null);
    $validSeverities = ['minor', 'moderate', 'major', 'total_loss'];
    if (!$sev) {
        $fields['severity'] = 'Please select a severity.';
    } elseif (!in_array($sev, $validSeverities, true)) {
        $fields['severity'] = 'Please select a valid severity.';
    } else {
        $updates['severity'] = $sev;
    }
}

if (array_key_exists('damage_location', $body)) {
    $updates['damage_location'] = clean_string($body['damage_location'] ?? null);
}

// Customer: either FK or free-text name — mutually exclusive
if (array_key_exists('customer_id', $body)) {
    $cId = clean_int($body['customer_id'] ?? null);
    if ($cId) {
        $cCheck = db_row("SELECT id FROM customers WHERE id = ? AND deleted_at IS NULL", [$cId]);
        if (!$cCheck) {
            $fields['customer_id'] = 'Customer not found.';
        } else {
            $updates['customer_id']   = $cId;
            $updates['customer_name'] = null; // clear free-text when FK is set
        }
    } else {
        $updates['customer_id'] = null;
    }
}
if (array_key_exists('customer_name', $body) && !array_key_exists('customer_id', $body)) {
    // Only apply free-text name when customer_id is not being set in the same request
    $cName = clean_string($body['customer_name'] ?? null, 255);
    $updates['customer_name'] = $cName ?: null;
    if ($cName) {
        $updates['customer_id'] = null; // clear FK when free-text is set
    }
}

if (array_key_exists('notes', $body)) {
    $updates['notes'] = clean_string($body['notes'] ?? null, 5000);
}

if (array_key_exists('resolution_notes', $body)) {
    $updates['resolution_notes'] = clean_string($body['resolution_notes'] ?? null, 5000);
}

// D16: monetary amounts — non-negative per field with friendly label
$moneyLabels = [
    'estimated_repair_cost'  => 'Estimated repair cost',
    'actual_repair_cost'     => 'Actual repair cost',
    'customer_liable_amount' => 'Customer liable amount',
    'insurance_claim_amount' => 'Insurance claim amount',
];
foreach ($moneyLabels as $field => $label) {
    if (!array_key_exists($field, $body)) {
        continue;
    }
    $raw = $body[$field];
    if ($raw === null || $raw === '') {
        $updates[$field] = null;
        continue;
    }
    $val = clean_decimal((string)$raw);
    if ($val === null || bccomp($val, '0', 6) < 0) {
        $fields[$field] = "{$label} cannot be negative.";
    } else {
        $updates[$field] = bcround($val, 2);
    }
}

// Optional FK links
if (array_key_exists('work_order_id', $body)) {
    $woId = clean_int($body['work_order_id'] ?? null);
    if ($woId) {
        $woCheck = db_row("SELECT id FROM maintenance_work_orders WHERE id = ?", [$woId]);
        if (!$woCheck) {
            $fields['work_order_id'] = 'Work order not found.';
        } else {
            $updates['work_order_id'] = $woId;
        }
    } else {
        $updates['work_order_id'] = null;
    }
}

if (array_key_exists('invoice_id', $body)) {
    $invId = clean_int($body['invoice_id'] ?? null);
    if ($invId) {
        $invCheck = db_row("SELECT id, invoice_number, status FROM invoices WHERE id = ? AND deleted_at IS NULL", [$invId]);
        if (!$invCheck) {
            $fields['invoice_id'] = 'Invoice not found.';
        } elseif ($invCheck['status'] === 'void') {
            // A void invoice's JE is already reversed — linking it would record a
            // recovery that was never billed.
            $fields['invoice_id'] = "Invoice {$invCheck['invoice_number']} is void. Pick the live recovery invoice.";
        } else {
            $updates['invoice_id'] = $invId;
        }
    } else {
        $updates['invoice_id'] = null;
    }
}

if (array_key_exists('vendor_id', $body)) {
    $vId = clean_int($body['vendor_id'] ?? null);
    if ($vId) {
        $vCheck = db_row("SELECT id FROM vendors WHERE id = ? AND deleted_at IS NULL", [$vId]);
        if (!$vCheck) {
            $fields['vendor_id'] = 'Vendor not found.';
        } else {
            $updates['vendor_id'] = $vId;
        }
    } else {
        $updates['vendor_id'] = null;
    }
}

// -----------------------------------------------------------------------
// 3b. Recovery-invoice rules (bug #8) — evaluated on the POST-update state
// -----------------------------------------------------------------------
$effectiveStatus     = $updates['status'] ?? $claim['status'];
$effectiveCustomerId = array_key_exists('customer_id', $updates) ? $updates['customer_id'] : $claim['customer_id'];
$effectiveInvoiceId  = array_key_exists('invoice_id', $updates) ? $updates['invoice_id'] : $claim['invoice_id'];
$transitionInvoiced  = $newStatus === 'invoiced' && $claim['status'] !== 'invoiced';
$invoiceLinkChanged  = array_key_exists('invoice_id', $updates)
    && (int) ($updates['invoice_id'] ?? 0) !== (int) ($claim['invoice_id'] ?? 0);
$customerChanged     = array_key_exists('customer_id', $updates)
    && (int) ($updates['customer_id'] ?? 0) !== (int) ($claim['customer_id'] ?? 0);

// Lock the link once the recovery is classified in the GL. A voided invoice's
// JE is reversed (reversed_by_id set), which releases the lock.
if ($invoiceLinkChanged && !isset($fields['invoice_id'])) {
    $liveRecoveryJe = db_row(
        "SELECT id, entry_number FROM acc_journal_entries
          WHERE source_type = 'damage_recovery' AND source_id = ?
            AND is_reversal = 0 AND reversed_by_id IS NULL
          LIMIT 1",
        [$claimId]
    );
    if ($liveRecoveryJe) {
        $fields['invoice_id'] = "This claim's recovery is already posted to the general ledger (journal entry "
            . "{$liveRecoveryJe['entry_number']}) against its current invoice, so the invoice link can't be changed. "
            . "Void that invoice first if it was wrong.";
    }
}

// The invoice must belong to the claim's customer (AutoEntryBridge enforces the
// same rule and would otherwise silently refuse to link).
if ($effectiveInvoiceId && ($invoiceLinkChanged || $customerChanged || $transitionInvoiced) && !isset($fields['invoice_id'])) {
    $inv = db_row("SELECT invoice_number, customer_id, status FROM invoices WHERE id = ?", [$effectiveInvoiceId]);
    if ($inv && (int) ($inv['customer_id'] ?? 0) !== (int) ($effectiveCustomerId ?? 0)) {
        $fields['invoice_id'] = $effectiveCustomerId
            ? "Invoice {$inv['invoice_number']} belongs to a different customer than this claim."
            : "Link this claim to a customer before attaching invoice {$inv['invoice_number']}.";
    }
}

// Being 'invoiced' means a recovery invoice is on file — that link is what posts
// the recovery to the GL (now if the invoice is sent, otherwise when it is sent).
if (($transitionInvoiced || ($invoiceLinkChanged && $effectiveStatus === 'invoiced')) && !isset($fields['invoice_id'])) {
    if (!$effectiveCustomerId) {
        $fields['invoice_id'] = 'Link this claim to a customer before marking it invoiced — the recovery invoice must belong to that customer.';
    } elseif (!$effectiveInvoiceId) {
        $fields['invoice_id'] = $transitionInvoiced
            ? 'Select the recovery invoice before marking this claim invoiced — that link is what posts the damage recovery to the general ledger.'
            : 'An invoiced claim must keep its recovery invoice.';
    }
}

if ($fields) {
    // Surface the invoice rule (the most common blocker) as the banner message
    // so single-message UIs like the status panel show the actual reason.
    json_validation_error($fields, $fields['invoice_id'] ?? 'Please correct the highlighted fields.');
}

if (empty($updates)) {
    json_error('VALIDATION_ERROR', 'No fields provided to update.', 422);
}

// -----------------------------------------------------------------------
// 4. Transaction: update + audit
// -----------------------------------------------------------------------
$resultRow = null;

// S-QBO-INVOICE-WRITEOFF: writing a claim off writes off its recovery
// invoice's remaining balance IN the same transaction (InvoiceWriteOff —
// GL entry, invoice closed, customer balance, the record QuickBooks gets).
// It used to post only the GL entry, after the commit, and leave the FF
// invoice open with its full balance. A claim with no invoice — or one that
// is draft / paid / already written off — just changes status.
$writeoffId = null;
try {
    db_transaction(function () use ($claimId, $claim, $updates, $newStatus, $effectiveInvoiceId, &$resultRow, &$writeoffId) {
        db_update('damage_claims', $updates, 'id = ?', [$claimId]);

        if ($newStatus === 'written_off' && $newStatus !== $claim['status'] && $effectiveInvoiceId) {
            $inv = db_row("SELECT status, balance_due FROM invoices WHERE id = ? AND deleted_at IS NULL", [(int) $effectiveInvoiceId]);
            if ($inv && in_array($inv['status'], \FleetForge\Accounting\InvoiceWriteOff::WRITABLE_STATUSES, true)
                && bccomp((string) $inv['balance_due'], '0', 2) > 0) {
                $w = \FleetForge\Accounting\InvoiceWriteOff::writeOff(
                    (int) $effectiveInvoiceId,
                    "Damage claim {$claim['claim_number']} written off",
                    current_user_id(),
                    $claimId
                );
                $writeoffId = $w['writeoff_id'];
            }
        }

        // Reload to get DB-stamped updated_at
        $fresh = db_row(
            "SELECT id, claim_number, status, updated_at FROM damage_claims WHERE id = ?",
            [$claimId]
        );

        $action = ($newStatus && $newStatus !== $claim['status']) ? 'status_change' : 'update';
        $notes  = $action === 'status_change'
            ? "Status changed from '{$claim['status']}' to '{$newStatus}' on claim {$claim['claim_number']}."
            : "Damage claim {$claim['claim_number']} updated. Fields: " . implode(', ', array_keys($updates)) . '.';

        db_insert('audit_log', [
            'user_id'      => current_user_id(),
            'user_name'    => current_user()['name'] ?? 'System',
            'action'       => $action,
            'module'       => 'maintenance',
            'entity_type'  => 'damage_claim',
            'entity_id'    => $claimId,
            'entity_label' => $claim['claim_number'],
            'old_values'   => json_encode(['status' => $claim['status']]),
            'new_values'   => json_encode($updates),
            'notes'        => $notes,
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);

        $resultRow = $fresh;

        // ── In-app notification (NOTIF-1) ──────────────────────────
        try {
            \FleetForge\Notifications\NotificationService::notify(
                type:       'damage.updated',
                title:      "Damage claim {$claim['claim_number']} updated",
                message:    "Damage claim {$claim['claim_number']} updated",
                entityType: 'damage_claim',
                entityId:   $claimId,
                url:        '/fleetforge/damage_claims/show?id=' . $claimId
            );
        } catch (\Throwable $e) {
            error_log('[NOTIF damage.updated] ' . $e->getMessage());
        }
    });
} catch (\DomainException | \RuntimeException $e) {
    if ($newStatus !== 'written_off') {
        throw $e;   // not a write-off problem — the normal error path
    }
    // Write-off refused (e.g. Bad Debt Expense account not configured, or the
    // invoice changed under us) — nothing was saved; say why.
    json_error('WRITE_OFF_FAILED', 'Could not write off the recovery invoice: ' . $e->getMessage(), 422,
        ['fields' => ['status' => 'Could not write off the recovery invoice: ' . $e->getMessage()]]);
}

if ($writeoffId !== null) {
    $resultRow['write_off_id'] = $writeoffId;
    // QuickBooks: CreditMemo (Bad-debt item) applied to the invoice.
    // Best-effort after commit (D-ENQUEUER-CONTRACT).
    \FleetForge\QboPushers\InvoiceWriteoffEnqueuer::enqueue($writeoffId, 'create');
}

// ── S-ACCT-DMG: fire AutoEntryBridge on status transitions ─────────────
// Runs OUTSIDE the db_transaction so a bridge failure logs + continues
// rather than rolling back the operational status change. Only fires on
// the actual transition (newStatus differs from prior status).
// Per spec §23.11 + K-22 catch: 'invoiced' is the damage_claims status
// equivalent of "billed_to_customer" (not present in the ENUM).
// Bug #8: also fire when the invoice link is set/changed on an already-invoiced
// claim (the validation above guarantees a customer-matched, non-void invoice).
// A draft invoice returns null here by design (not revenue yet); the send path
// links it when the invoice is sent.
if ($effectiveStatus === 'invoiced' && $effectiveInvoiceId && ($transitionInvoiced || $invoiceLinkChanged)) {
    try {
        $glJe = \FleetForge\Accounting\AutoEntryBridge::onDamageRecoveryBilled(
            $claimId, (int) $effectiveInvoiceId, current_user_id()
        );
        $resultRow['gl_journal_entry'] = $glJe
            ? ['id' => (int) $glJe['id'], 'entry_number' => $glJe['entry_number'], 'source_type' => $glJe['source_type']]
            : null;
        if (!$glJe) {
            error_log("[S-ACCT-DMG] Claim {$claim['claim_number']} → invoiced with invoice #{$effectiveInvoiceId} but no recovery JE was linked yet (draft invoice — links on send — or accounting disabled).");
        }
    } catch (\Throwable $e) {
        error_log('[S-ACCT-DMG onDamageRecoveryBilled] ' . $e->getMessage());
    }
}

json_success($resultRow);
