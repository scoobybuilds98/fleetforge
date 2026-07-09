<?php
declare(strict_types=1);

/**
 * lib/Billing/OverflowCreditNotes.php
 *
 * Lifecycle management for the AUTO-CREATED overflow credit notes that
 * InvoiceGenerator emits when a final invoice's credit lines exceed its
 * charges (sources 'mileage_overpayment' / 'base_rental_reconciliation_
 * overflow' — the $0-floor cap, see InvoiceGenerator ~step 5).
 *
 * WHY THIS EXISTS (S-ORPHAN-OVERFLOW-CN): the mileage true-up subtracts
 * *live* overflow credit_notes from "mileage billed to date" so a reclose /
 * regenerate never re-credits the same overflow. But deleting, voiding, or
 * regenerating the SOURCE invoice used to leave its overflow CN active — an
 * orphan that then poisoned every subsequent settlement's true-up (observed
 * prod: deleted draft INV-2026-01444 left CN-CR-2026-00011 $40.01 active,
 * halving INV-2026-01760's mileage credit from $80.68 to $40.67).
 *
 * Contract: every write path that removes or voids an invoice MUST route
 * through voidForInvoice() so the invoice and its auto-created account
 * credit live and die together:
 *   - api/v1/invoices/delete.php / bulk_delete.php  (soft delete)
 *   - FinancialActions::voidInvoice / bulk_void.php (void)
 *   - api/v1/invoices/regenerate.php                (hard delete + recreate)
 *
 * An overflow CN that has ALREADY been applied (in part or full) blocks the
 * operation instead: the customer has spent money that traces back to this
 * invoice, so silently unwinding it would corrupt the credit trail. Callers
 * pre-check with findBlockers() and refuse with a clear message.
 *
 * @session S-ORPHAN-OVERFLOW-CN
 */

namespace FleetForge\Billing;

use FleetForge\Accounting\AutoEntryBridge;

class OverflowCreditNotes
{
    /** credit_notes.source values the invoice cap auto-creates. */
    public const SOURCES = ['mileage_overpayment', 'base_rental_reconciliation_overflow'];

    /**
     * Live (not void, not deleted) overflow CNs created from an invoice.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function findLive(int $invoiceId, bool $forUpdate = false): array
    {
        $ph = implode(',', array_fill(0, count(self::SOURCES), '?'));
        return \db_select(
            "SELECT id, credit_note_number, source, status, amount, amount_remaining
               FROM credit_notes
              WHERE source_invoice_id = ?
                AND source IN ($ph)
                AND deleted_at IS NULL
                AND voided_at IS NULL
                AND status <> 'void'"
            . ($forUpdate ? ' FOR UPDATE' : ''),
            array_merge([$invoiceId], self::SOURCES)
        );
    }

    /**
     * True when the invoice has ANY live overflow CN (applied or not).
     * Used by the draft line editor to refuse edits that would desync the
     * CN from its source computation (S-AUDIT-LIFECYCLE-1 #11b).
     */
    public static function hasLive(int $invoiceId): bool
    {
        return self::findLive($invoiceId) !== [];
    }

    /**
     * Overflow CNs that BLOCK removing/voiding the source invoice because the
     * customer has already drawn on them (amount_remaining < amount, or a
     * consumed status). Fully-unapplied CNs are not blockers — they are
     * auto-voided by voidForInvoice().
     *
     * @return array<int, array<string,mixed>>
     */
    public static function findBlockers(int $invoiceId): array
    {
        return array_values(array_filter(self::findLive($invoiceId), function (array $cn): bool {
            return \bccomp((string) $cn['amount_remaining'], (string) $cn['amount'], 2) !== 0
                || in_array($cn['status'], ['partially_used', 'fully_used'], true);
        }));
    }

    /**
     * Human-readable refusal message for a blocked operation.
     *
     * @param array<int, array<string,mixed>> $blockers  From findBlockers().
     */
    public static function blockerMessage(array $blockers, string $operation): string
    {
        $labels = array_map(
            static fn (array $cn): string => "{$cn['credit_note_number']} (\${$cn['amount']}, \${$cn['amount_remaining']} remaining)",
            $blockers
        );
        return "Cannot {$operation} this invoice: its auto-created account credit "
            . implode(', ', $labels)
            . ' has already been applied to other invoices. Unapply or resolve the credit note first.';
    }

    /**
     * Void every live, fully-unapplied overflow CN created from an invoice.
     *
     * MUST run inside the caller's db_transaction, alongside the invoice
     * delete/void itself, so the pair commits or rolls back atomically.
     * Mirrors api/v1/credit_notes/void.php semantics (status → 'void',
     * amount_remaining → 0, voided_by/voided_at, internal_notes appended,
     * audit_log row) and reverses the CN's issue JE via
     * AutoEntryBridge::onCreditNoteVoided (safe here: only fully-unapplied
     * CNs reach this point).
     *
     * QBO propagation is the CALLER's job after its transaction commits:
     *   foreach (returned as $cn) CreditMemoEnqueuer::enqueue($cn['id'], 'void');
     * (Enqueuers are post-commit best-effort per D-ENQUEUER-CONTRACT.)
     *
     * @param int      $invoiceId
     * @param int|null $userId
     * @param string   $userName
     * @param string   $context   Short human phrase for the audit trail, e.g.
     *                            "source invoice INV-2026-01760 deleted".
     * @return array<int, array{id:int, credit_note_number:string}>  Voided CNs.
     * @throws \RuntimeException  If a blocker CN is present (defense in depth —
     *                            callers pre-check findBlockers()).
     */
    public static function voidForInvoice(int $invoiceId, ?int $userId, string $userName, string $context): array
    {
        // S-AUDIT-LIFECYCLE-1 #16: read the CN rows FOR UPDATE. The old
        // non-locking read + stale re-check had a TOCTOU hole — an apply
        // committing between the read and the UPDATE below got blind-stomped
        // to void/0.00 (spent credit clawed back + issue JE over-reversed).
        // The lock serializes against credit_notes/apply.php's own FOR UPDATE.
        $live = self::findLive($invoiceId, true);
        if (!$live) {
            return [];
        }

        $voided = [];
        $now    = date('Y-m-d H:i:s');

        foreach ($live as $cn) {
            $isBlocked = \bccomp((string) $cn['amount_remaining'], (string) $cn['amount'], 2) !== 0
                || in_array($cn['status'], ['partially_used', 'fully_used'], true);
            if ($isBlocked) {
                // Callers gate on findBlockers() BEFORE mutating; reaching this
                // throw means a concurrent apply slipped in — abort the whole
                // transaction rather than orphan or over-void.
                throw new \RuntimeException(
                    "Overflow credit note {$cn['credit_note_number']} was applied concurrently; cannot auto-void."
                );
            }

            // Belt-and-suspenders on top of the FOR UPDATE read: predicate the
            // void on the exact observed state; 0 rows = concurrent change.
            $affected = \db_update('credit_notes', [
                'status'           => 'void',
                'amount_remaining' => '0.00',
                'voided_by'        => $userId,
                'voided_at'        => $now,
                'internal_notes'   => "Auto-voided: {$context} (S-ORPHAN-OVERFLOW-CN — an overflow credit lives and dies with its source invoice).",
                'updated_at'       => $now,
            ], 'id = ? AND status = ? AND amount_remaining = ?', [(int) $cn['id'], $cn['status'], $cn['amount_remaining']]);
            if ($affected === 0) {
                throw new \RuntimeException(
                    "Overflow credit note {$cn['credit_note_number']} changed concurrently; cannot auto-void."
                );
            }

            \db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'status_change',
                'module'       => 'invoices',
                'entity_type'  => 'credit_note',
                'entity_id'    => (int) $cn['id'],
                'entity_label' => $cn['credit_note_number'],
                'old_values'   => json_encode(['status' => $cn['status'], 'amount_remaining' => $cn['amount_remaining']]),
                'new_values'   => json_encode(['status' => 'void', 'amount_remaining' => '0.00']),
                'notes'        => "Credit note {$cn['credit_note_number']} auto-voided: {$context}.",
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            // Reverse the issue JE (DR revenue / CR 2060) — inside the txn so a
            // GL failure rolls the whole operation back. No-op when accounting
            // is disabled or the CN never posted a JE.
            AutoEntryBridge::onCreditNoteVoided((int) $cn['id'], $userId);

            $voided[] = ['id' => (int) $cn['id'], 'credit_note_number' => (string) $cn['credit_note_number']];
        }

        return $voided;
    }
}
