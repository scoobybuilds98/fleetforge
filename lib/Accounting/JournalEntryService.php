<?php
declare(strict_types=1);

/**
 * lib/Accounting/JournalEntryService.php
 *
 * Journal entry lifecycle — create drafts, post, reverse.
 * Every financial event in FleetForge flows through this service.
 *
 * Required by: All auto-JE bridge code, JE API endpoints
 * Depends on: AccountingService (period lookup, JE numbering, balance calc)
 *
 * Decisions: A8 (JE backbone — nothing bypasses GL)
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §4 (GL & JE rules)
 */

namespace FleetForge\Accounting;

class JournalEntryService
{
    /**
     * Entry types that participate in the adjusting-entry approval workflow
     * (S-ACCT-AJE / spec §23.1). Manual, system, recurring, reversing, year_end,
     * adjustment (legacy singular), and closing post directly without the
     * draft → submitted → approved review chain.
     */
    private const AJE_TYPES = ['adjusting', 'reclassifying', 'prior_period'];

    /**
     * Create a journal entry (draft or posted).
     *
     * Each line must have: account_id, debit (string), credit (string).
     * Optional per line: description, customer_id, vendor_id, equipment_unit_id.
     *
     * @param array $header  [entry_date, description, entry_type, reference?,
     *                        source_type?, source_id?, currency?, exchange_rate?,
     *                        auto_reverse?, auto_reverse_date?, post_immediately?]
     * @param array $lines   Array of line arrays
     * @param int|null $userId  User creating the entry
     * @return array         The created journal entry row
     * @throws \RuntimeException on validation failure
     */
    public static function create(array $header, array $lines, ?int $userId = null): array
    {
        // ── Validation ───────────────────────────────���──────
        if (count($lines) < 2) {
            throw new \RuntimeException('Journal entry must have at least 2 lines.');
        }
        if (count($lines) > 50) {
            throw new \RuntimeException('Journal entry cannot exceed 50 lines.');
        }

        // Verify debits = credits
        $totalDebit = '0.00';
        $totalCredit = '0.00';
        foreach ($lines as $line) {
            $totalDebit = bcadd($totalDebit, $line['debit'] ?? '0.00', 2);
            $totalCredit = bcadd($totalCredit, $line['credit'] ?? '0.00', 2);
        }
        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            throw new \RuntimeException(
                "Entry is unbalanced: debits ({$totalDebit}) ≠ credits ({$totalCredit})."
            );
        }
        if (bccomp($totalDebit, '0.00', 2) === 0) {
            throw new \RuntimeException('Entry total cannot be zero.');
        }

        // Validate all accounts
        foreach ($lines as $i => $line) {
            $accountId = (int) ($line['account_id'] ?? 0);
            if (!$accountId) {
                throw new \RuntimeException("Line " . ($i + 1) . ": account_id is required.");
            }
            $account = \db_row(
                "SELECT id, is_header, is_active FROM acc_accounts WHERE id = ?",
                [$accountId]
            );
            if (!$account) {
                throw new \RuntimeException("Line " . ($i + 1) . ": account not found.");
            }
            // WHY: Header accounts are grouping nodes — cannot receive postings
            if ($account['is_header']) {
                throw new \RuntimeException("Line " . ($i + 1) . ": cannot post to header account.");
            }
            if (!$account['is_active']) {
                throw new \RuntimeException("Line " . ($i + 1) . ": account is inactive.");
            }

            // Each line must have either a debit or credit, not both
            $d = $line['debit'] ?? '0.00';
            $c = $line['credit'] ?? '0.00';
            if (bccomp($d, '0.00', 2) > 0 && bccomp($c, '0.00', 2) > 0) {
                throw new \RuntimeException("Line " . ($i + 1) . ": cannot have both debit and credit.");
            }
        }

        // ── Period resolution ───────────────────────────────
        $entryDate = $header['entry_date'] ?? date('Y-m-d');
        $period = AccountingService::periodForDate($entryDate);
        if (!$period) {
            throw new \RuntimeException("No accounting period found for date {$entryDate}.");
        }

        // Check if we're posting immediately
        $postImmediately = (bool) ($header['post_immediately'] ?? false);
        $status = $postImmediately ? 'posted' : 'draft';

        // WHY: entry_status carries the AJE workflow layer (S-ACCT-AJE / D-AJE-1).
        // Bridge JEs + manual non-AJE drafts both reach 'posted'; only AJE drafts
        // (adjusting/reclassifying/prior_period via post_immediately=false) enter
        // the 'draft' → 'submitted' → 'approved' chain. Default 'posted' keeps
        // bridge code unchanged (D-AJE-2).
        $entryType = $header['entry_type'] ?? 'manual';
        if ($postImmediately) {
            $entryStatus = 'posted';
        } elseif (in_array($entryType, self::AJE_TYPES, true)) {
            $entryStatus = 'draft';
        } else {
            $entryStatus = 'posted';
        }

        if ($postImmediately) {
            $periodError = AccountingService::validatePeriodForPosting($period['id']);
            if ($periodError) {
                throw new \RuntimeException($periodError);
            }
        }

        // ── Create entry ────────────────────────────────────
        $result = \db_transaction(function () use ($header, $lines, $userId, $period, $entryDate, $status, $entryStatus, $entryType) {
            $year = substr($entryDate, 0, 4);
            $entryNumber = AccountingService::nextJeNumber($year);

            $entryId = \db_insert('acc_journal_entries', [
                'entry_number'     => $entryNumber,
                'period_id'        => $period['id'],
                'entry_date'       => $entryDate,
                'entry_type'       => $entryType,
                'status'           => $status,
                'entry_status'     => $entryStatus,
                'description'      => $header['description'] ?? '',
                'reference'        => $header['reference'] ?? null,
                'source_type'      => $header['source_type'] ?? null,
                'source_id'        => $header['source_id'] ?? null,
                'auto_reverse'     => (int) ($header['auto_reverse'] ?? 0),
                'auto_reverse_date'=> $header['auto_reverse_date'] ?? null,
                'currency'         => $header['currency'] ?? 'CAD',
                'exchange_rate'    => $header['exchange_rate'] ?? null,
                'posted_by'        => $status === 'posted' ? $userId : null,
                'posted_at'        => $status === 'posted' ? date('Y-m-d H:i:s') : null,
                'created_by'       => $userId,
            ]);

            // Insert lines
            foreach ($lines as $i => $line) {
                \db_insert('acc_journal_entry_lines', [
                    'journal_entry_id'  => $entryId,
                    'account_id'        => (int) $line['account_id'],
                    'line_number'       => $i + 1,
                    'description'       => $line['description'] ?? null,
                    'debit'             => $line['debit'] ?? '0.00',
                    'credit'            => $line['credit'] ?? '0.00',
                    'foreign_amount'    => $line['foreign_amount'] ?? null,
                    'foreign_currency'  => $line['foreign_currency'] ?? null,
                    'exchange_rate'     => $line['exchange_rate'] ?? null,
                    'customer_id'       => $line['customer_id'] ?? null,
                    'vendor_id'         => $line['vendor_id'] ?? null,
                    'equipment_unit_id' => $line['equipment_unit_id'] ?? null,
                ]);
            }

            // Audit log
            \db_insert('audit_log', [
                'user_id'     => $userId,
                'action'      => 'create',
                'module'      => 'accounting',
                'entity_type' => 'journal_entry',
                'entity_id'   => $entryId,
                'notes'       => "Journal entry {$entryNumber} created ({$status})",
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return \db_row("SELECT * FROM acc_journal_entries WHERE id = ?", [$entryId]);
        });

        return $result;
    }

    /**
     * Post a draft journal entry.
     * Validates the period is open and the entry is balanced.
     *
     * @param int $entryId
     * @param int|null $userId
     * @return array Updated entry row
     * @throws \RuntimeException
     */
    public static function post(int $entryId, ?int $userId = null): array
    {
        return \db_transaction(function () use ($entryId, $userId) {
            // FOR UPDATE to prevent race condition on double-post
            $entry = \db_row(
                "SELECT * FROM acc_journal_entries WHERE id = ? FOR UPDATE",
                [$entryId]
            );

            if (!$entry) {
                throw new \RuntimeException('Journal entry not found.');
            }
            if ($entry['status'] !== 'draft') {
                throw new \RuntimeException("Cannot post — entry is already {$entry['status']}.");
            }

            // AJE gate (S-ACCT-AJE / D-AJE-1, D-AJE-4): when review is required
            // and the entry type is an AJE type, post() must run via the
            // approveAndPost path — direct post() is not allowed from 'draft'.
            // From 'approved' it is permitted (this is the second leg of
            // approveAndPost). From 'submitted' it is not (caller must approve).
            if (in_array($entry['entry_type'], self::AJE_TYPES, true)) {
                $reviewRequired = (string) \settings_get('accounting.aje_review_required', '0') === '1';
                $entryStatus = $entry['entry_status'] ?? 'posted';
                if ($reviewRequired && $entryStatus === 'draft') {
                    throw new \RuntimeException(
                        'This adjusting entry requires review before posting. Submit it for approval first.'
                    );
                }
                if ($entryStatus === 'submitted') {
                    throw new \RuntimeException(
                        'This adjusting entry is awaiting approval. Use Approve & Post to complete it.'
                    );
                }
            }

            // Validate period is open
            $periodError = AccountingService::validatePeriodForPosting((int) $entry['period_id']);
            if ($periodError) {
                throw new \RuntimeException($periodError);
            }

            // Re-validate balance (defensive — should always be balanced)
            $sums = \db_row(
                "SELECT SUM(debit) AS td, SUM(credit) AS tc
                 FROM acc_journal_entry_lines WHERE journal_entry_id = ?",
                [$entryId]
            );
            if (bccomp($sums['td'] ?? '0', $sums['tc'] ?? '0', 2) !== 0) {
                throw new \RuntimeException('Cannot post — entry is unbalanced.');
            }

            \db_update('acc_journal_entries', [
                'status'       => 'posted',
                'entry_status' => 'posted',
                'posted_by'    => $userId,
                'posted_at'    => date('Y-m-d H:i:s'),
            ], 'id = ?', [$entryId]);

            \db_insert('audit_log', [
                'user_id'     => $userId,
                'action'      => 'status_change',
                'module'      => 'accounting',
                'entity_type' => 'journal_entry',
                'entity_id'   => $entryId,
                'notes'       => "Journal entry {$entry['entry_number']} posted",
                'old_values'  => json_encode(['status' => 'draft', 'entry_status' => $entry['entry_status'] ?? null]),
                'new_values'  => json_encode(['status' => 'posted', 'entry_status' => 'posted']),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return \db_row("SELECT * FROM acc_journal_entries WHERE id = ?", [$entryId]);
        });
    }

    /**
     * Reverse a posted journal entry.
     * Creates a new entry with debits and credits swapped.
     * Links the two entries via reversal_of_id / reversed_by_id.
     *
     * @param int         $entryId   Original entry to reverse
     * @param string|null $reversalDate  Date for the reversal (defaults to today)
     * @param int|null    $userId
     * @return array      The new reversal entry row
     * @throws \RuntimeException
     */
    public static function reverse(int $entryId, ?string $reversalDate = null, ?int $userId = null): array
    {
        $reversalDate = $reversalDate ?? date('Y-m-d');

        return \db_transaction(function () use ($entryId, $reversalDate, $userId) {
            $original = \db_row(
                "SELECT * FROM acc_journal_entries WHERE id = ? FOR UPDATE",
                [$entryId]
            );

            if (!$original) {
                throw new \RuntimeException('Journal entry not found.');
            }
            if ($original['status'] !== 'posted') {
                throw new \RuntimeException('Only posted entries can be reversed.');
            }
            if ($original['reversed_by_id']) {
                throw new \RuntimeException('This entry has already been reversed.');
            }

            // Get reversal period
            $period = AccountingService::periodForDate($reversalDate);
            if (!$period) {
                throw new \RuntimeException("No accounting period found for date {$reversalDate}.");
            }
            $periodError = AccountingService::validatePeriodForPosting($period['id']);
            if ($periodError) {
                throw new \RuntimeException($periodError);
            }

            // Generate reversal entry number
            $year = substr($reversalDate, 0, 4);
            $reversalNumber = AccountingService::nextJeNumber($year);

            // Create reversal entry (posted immediately)
            $reversalId = \db_insert('acc_journal_entries', [
                'entry_number'  => $reversalNumber,
                'period_id'     => $period['id'],
                'entry_date'    => $reversalDate,
                'entry_type'    => 'reversing',
                'status'        => 'posted',
                'description'   => "Reversal of {$original['entry_number']}: {$original['description']}",
                'reference'     => $original['reference'],
                'source_type'   => $original['source_type'],
                'source_id'     => $original['source_id'],
                'is_reversal'   => 1,
                'reversal_of_id'=> $entryId,
                'currency'      => $original['currency'],
                'exchange_rate' => $original['exchange_rate'],
                'posted_by'     => $userId,
                'posted_at'     => date('Y-m-d H:i:s'),
                'created_by'    => $userId,
            ]);

            // Copy lines with debits/credits swapped
            $lines = \db_select(
                "SELECT * FROM acc_journal_entry_lines WHERE journal_entry_id = ? ORDER BY line_number",
                [$entryId]
            );

            foreach ($lines as $line) {
                \db_insert('acc_journal_entry_lines', [
                    'journal_entry_id'  => $reversalId,
                    'account_id'        => $line['account_id'],
                    'line_number'       => $line['line_number'],
                    'description'       => $line['description'],
                    'debit'             => $line['credit'],   // WHY: swap debit↔credit for reversal
                    'credit'            => $line['debit'],
                    'foreign_amount'    => $line['foreign_amount'],
                    'foreign_currency'  => $line['foreign_currency'],
                    'exchange_rate'     => $line['exchange_rate'],
                    'customer_id'       => $line['customer_id'],
                    'vendor_id'         => $line['vendor_id'],
                    'equipment_unit_id' => $line['equipment_unit_id'],
                ]);
            }

            // Update original entry — mark as reversed
            \db_update('acc_journal_entries', [
                'status'         => 'reversed',
                'reversed_by_id' => $reversalId,
                'reversal_date'  => $reversalDate,
            ], 'id = ?', [$entryId]);

            // Audit log
            \db_insert('audit_log', [
                'user_id'     => $userId,
                'action'      => 'status_change',
                'module'      => 'accounting',
                'entity_type' => 'journal_entry',
                'entity_id'   => $entryId,
                'notes'       => "Journal entry {$original['entry_number']} reversed by {$reversalNumber}",
                'old_values'  => json_encode(['status' => 'posted']),
                'new_values'  => json_encode(['status' => 'reversed', 'reversed_by' => $reversalNumber]),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return \db_row("SELECT * FROM acc_journal_entries WHERE id = ?", [$reversalId]);
        });
    }

    /**
     * Submit an AJE draft for review (S-ACCT-AJE / spec §23.1).
     * Transitions entry_status 'draft' → 'submitted' and stamps submitter.
     * Only valid for AJE types (adjusting/reclassifying/prior_period).
     *
     * @param int $entryId
     * @param int|null $userId
     * @return array Updated entry row
     * @throws \RuntimeException
     */
    public static function submit(int $entryId, ?int $userId = null): array
    {
        return \db_transaction(function () use ($entryId, $userId) {
            $entry = \db_row(
                "SELECT * FROM acc_journal_entries WHERE id = ? FOR UPDATE",
                [$entryId]
            );

            if (!$entry) {
                throw new \RuntimeException('Journal entry not found.');
            }
            if (!in_array($entry['entry_type'], self::AJE_TYPES, true)) {
                throw new \RuntimeException(
                    'Only adjusting, reclassifying, or prior-period entries use the review workflow.'
                );
            }
            if ($entry['status'] !== 'draft') {
                throw new \RuntimeException("Cannot submit — entry is already {$entry['status']}.");
            }
            if (($entry['entry_status'] ?? 'posted') !== 'draft') {
                throw new \RuntimeException(
                    "Cannot submit — entry is already {$entry['entry_status']}."
                );
            }

            \db_update('acc_journal_entries', [
                'entry_status'    => 'submitted',
                'submitted_by_id' => $userId,
                'submitted_at'    => date('Y-m-d H:i:s'),
            ], 'id = ?', [$entryId]);

            \db_insert('audit_log', [
                'user_id'     => $userId,
                'action'      => 'status_change',
                'module'      => 'accounting',
                'entity_type' => 'journal_entry',
                'entity_id'   => $entryId,
                'notes'       => "AJE {$entry['entry_number']} submitted for review by user #{$userId}",
                'old_values'  => json_encode(['entry_status' => 'draft']),
                'new_values'  => json_encode(['entry_status' => 'submitted']),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return \db_row("SELECT * FROM acc_journal_entries WHERE id = ?", [$entryId]);
        });
    }

    /**
     * Approve a submitted AJE and post it in the same transaction
     * (S-ACCT-AJE / spec §23.1). Transitions entry_status 'submitted' →
     * 'approved' → 'posted' and (via post()) flips status='posted'.
     *
     * When accounting.aje_review_required='1', the approver must be a
     * different user from the submitter (D-AJE-4).
     *
     * Atomicity (STOP CONDITION): the entry_status update + post() run
     * inside one db_transaction. If post() throws (period closed,
     * unbalanced), the entry_status update rolls back — the entry stays
     * 'submitted' and an error is returned.
     *
     * @param int $entryId
     * @param int|null $userId
     * @return array Updated entry row
     * @throws \RuntimeException
     */
    public static function approveAndPost(int $entryId, ?int $userId = null): array
    {
        return \db_transaction(function () use ($entryId, $userId) {
            $entry = \db_row(
                "SELECT * FROM acc_journal_entries WHERE id = ? FOR UPDATE",
                [$entryId]
            );

            if (!$entry) {
                throw new \RuntimeException('Journal entry not found.');
            }
            if (!in_array($entry['entry_type'], self::AJE_TYPES, true)) {
                throw new \RuntimeException(
                    'Only adjusting, reclassifying, or prior-period entries use the review workflow.'
                );
            }
            if (($entry['entry_status'] ?? 'posted') !== 'submitted') {
                throw new \RuntimeException(
                    "Cannot approve — entry is {$entry['entry_status']}, expected 'submitted'."
                );
            }

            $reviewRequired = (string) \settings_get('accounting.aje_review_required', '0') === '1';
            if ($reviewRequired
                && $entry['submitted_by_id'] !== null
                && (int) $entry['submitted_by_id'] === (int) $userId
            ) {
                throw new \RuntimeException(
                    'Approver cannot be the same user who submitted this entry.'
                );
            }

            // Stamp approval first (within same transaction as the post()).
            \db_update('acc_journal_entries', [
                'entry_status'   => 'approved',
                'approved_by_id' => $userId,
                'approved_at'    => date('Y-m-d H:i:s'),
            ], 'id = ?', [$entryId]);

            \db_insert('audit_log', [
                'user_id'     => $userId,
                'action'      => 'status_change',
                'module'      => 'accounting',
                'entity_type' => 'journal_entry',
                'entity_id'   => $entryId,
                'notes'       => "AJE {$entry['entry_number']} approved and posted by user #{$userId}",
                'old_values'  => json_encode(['entry_status' => 'submitted']),
                'new_values'  => json_encode(['entry_status' => 'approved']),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            // post() will flip status='posted' + entry_status='posted'.
            // It runs inside the same outer transaction (db_transaction nests),
            // so a post() failure rolls back the approved stamp.
            return self::post($entryId, $userId);
        });
    }

    /**
     * Recall a submitted AJE back to draft (S-ACCT-AJE).
     * Permitted to the original submitter or a super_admin (the caller
     * enforces that gate; this method only validates the state transition).
     *
     * @param int $entryId
     * @param int|null $userId
     * @return array Updated entry row
     * @throws \RuntimeException
     */
    public static function recall(int $entryId, ?int $userId = null): array
    {
        return \db_transaction(function () use ($entryId, $userId) {
            $entry = \db_row(
                "SELECT * FROM acc_journal_entries WHERE id = ? FOR UPDATE",
                [$entryId]
            );

            if (!$entry) {
                throw new \RuntimeException('Journal entry not found.');
            }
            if (($entry['entry_status'] ?? 'posted') !== 'submitted') {
                throw new \RuntimeException(
                    "Cannot recall — entry is {$entry['entry_status']}, expected 'submitted'."
                );
            }

            \db_update('acc_journal_entries', [
                'entry_status'    => 'draft',
                'submitted_by_id' => null,
                'submitted_at'    => null,
            ], 'id = ?', [$entryId]);

            \db_insert('audit_log', [
                'user_id'     => $userId,
                'action'      => 'status_change',
                'module'      => 'accounting',
                'entity_type' => 'journal_entry',
                'entity_id'   => $entryId,
                'notes'       => "AJE {$entry['entry_number']} recalled from review by user #{$userId}",
                'old_values'  => json_encode(['entry_status' => 'submitted']),
                'new_values'  => json_encode(['entry_status' => 'draft']),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return \db_row("SELECT * FROM acc_journal_entries WHERE id = ?", [$entryId]);
        });
    }

    /**
     * True if entry_type participates in the AJE workflow (spec §23.1).
     */
    public static function isAjeType(string $entryType): bool
    {
        return in_array($entryType, self::AJE_TYPES, true);
    }

    /**
     * Get a journal entry with all its lines.
     *
     * @param int $entryId
     * @return array|null  Entry row with 'lines' key added
     */
    public static function getWithLines(int $entryId): ?array
    {
        $entry = \db_row("SELECT * FROM acc_journal_entries WHERE id = ?", [$entryId]);
        if (!$entry) return null;

        $entry['lines'] = \db_select(
            "SELECT jel.*, a.code AS account_code, a.name AS account_name
             FROM acc_journal_entry_lines jel
             JOIN acc_accounts a ON a.id = jel.account_id
             WHERE jel.journal_entry_id = ?
             ORDER BY jel.line_number",
            [$entryId]
        );

        return $entry;
    }
}
