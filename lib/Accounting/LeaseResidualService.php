<?php
declare(strict_types=1);

/**
 * lib/Accounting/LeaseResidualService.php
 *
 * Annual residual review for active sales-type and direct-financing
 * leases, per ASPE 3065 §24.7. The lessor reviews the current
 * estimate of unguaranteed residual value each fiscal year (typically
 * triggered by year-end close, but the API can be hit any time during
 * the year). Three outcome paths:
 *
 *   1. revised == prior   → no JE, no schedule change, just a recorded
 *                            "no change" review row in acc_lease_residual_reviews.
 *   2. revised < prior    → DOWNWARD revision (impairment):
 *                            - acc_lease_residual_reviews row written
 *                            - DR Impairment Loss — Residual / CR NI Long-Term
 *                              for abs(delta), source_type='lease_residual_impairment'
 *                            - leases.unguaranteed_residual_value updated
 *                            - LeaseAmortizationService::regeneratePartial
 *                              rebuilds the scheduled-but-not-posted rows
 *                              with the lower closing-NI floor
 *   3. revised > prior    → UPWARD revision: HARD REJECT. ASPE 3065
 *                            prohibits upward residual revisions. 422.
 *
 * The implicit rate is NOT re-solved on a downward revision — ASPE 3065
 * locks the rate at inception. Residual revision is a write-down event,
 * not a re-pricing event. Posted schedule rows are preserved unchanged.
 *
 * @session S-ACCT-LESSOR-5
 */

namespace FleetForge\Accounting;

class LeaseResidualService
{
    /**
     * Record the annual residual review and (when downward) post the
     * impairment JE + regenerate the unposted schedule.
     *
     * @param int $leaseId
     * @param int $fiscalYear
     * @param string $revisedValue  Decimal string ≥ 0.
     * @param string $notes
     * @param int $userId
     * @return array {
     *   review:    array,  // acc_lease_residual_reviews row
     *   je:        array|null,
     *   regen:     array|null,
     *   direction: 'no_change'|'downward'|'upward_blocked',
     *   delta:     string,
     * }
     * @throws \InvalidArgumentException  upward revision (422)
     * @throws \RuntimeException          state / config errors (422-class)
     */
    public static function reviewResidual(
        int $leaseId,
        int $fiscalYear,
        string $revisedValue,
        string $notes,
        int $userId
    ): array {
        if ($fiscalYear < 2000 || $fiscalYear > 2100) {
            throw new \InvalidArgumentException("Invalid fiscal_year: {$fiscalYear}.");
        }
        if (bccomp($revisedValue, '0', 2) < 0) {
            throw new \InvalidArgumentException("Revised residual value cannot be negative: {$revisedValue}.");
        }

        return \db_transaction(function () use ($leaseId, $fiscalYear, $revisedValue, $notes, $userId): array {
            \db_execute("SELECT GET_LOCK(?, 10) AS got", ["ff_lease_residual_{$leaseId}"]);

            try {
                $lease = \db_row(
                    "SELECT id, contract_number, classification, status,
                            unguaranteed_residual_value, deleted_at
                       FROM leases WHERE id = ? FOR UPDATE",
                    [$leaseId]
                );
                if (!$lease || $lease['deleted_at'] !== null) {
                    throw new \InvalidArgumentException("Lease #{$leaseId} not found.");
                }
                if (!in_array($lease['classification'], ['sales_type', 'direct_financing'], true)) {
                    throw new \InvalidArgumentException(
                        "Lease #{$leaseId} classification '{$lease['classification']}' is not a capital lease. "
                        . 'Residual reviews apply only to sales-type and direct-financing leases.'
                    );
                }
                if ($lease['status'] !== 'active') {
                    throw new \InvalidArgumentException(
                        "Lease #{$leaseId} status '{$lease['status']}' — residual reviews are only valid on active leases."
                    );
                }

                $priorValue = (string) $lease['unguaranteed_residual_value'];
                $delta      = bcsub($revisedValue, $priorValue, 2);

                // ── STOP CONDITION: upward revision blocked ─────────
                // ASPE 3065 prohibits upward residual revisions. The
                // bridge throws InvalidArgumentException so the calling
                // endpoint can map to 422.
                if (bccomp($delta, '0', 2) > 0) {
                    throw new \InvalidArgumentException(sprintf(
                        'ASPE 3065 prohibits upward residual revisions. Locked: prior=%s, requested=%s, delta=+%s.',
                        $priorValue, $revisedValue, $delta
                    ));
                }

                // Direction: no-change OR downward.
                $isNoChange = bccomp($delta, '0', 2) === 0;
                $direction  = $isNoChange ? 'no_change' : 'downward';

                // ── Insert review row (UPSERT on year+lease) ────────
                // UNIQUE(lease_id, fiscal_year) per spec; re-running
                // within the same fiscal year overwrites the prior row.
                $reviewId = \db_row(
                    "SELECT id FROM acc_lease_residual_reviews
                      WHERE lease_id = ? AND fiscal_year = ?",
                    [$leaseId, $fiscalYear]
                )['id'] ?? null;

                if ($reviewId) {
                    \db_execute(
                        "UPDATE acc_lease_residual_reviews
                            SET prior_residual_value   = ?,
                                revised_residual_value = ?,
                                impairment_je_id       = NULL,
                                schedule_regenerated   = 0,
                                notes                  = ?,
                                reviewed_by            = ?,
                                reviewed_at            = NOW()
                          WHERE id = ?",
                        [$priorValue, $revisedValue, $notes, $userId, $reviewId]
                    );
                } else {
                    $reviewId = \db_insert('acc_lease_residual_reviews', [
                        'lease_id'               => $leaseId,
                        'fiscal_year'            => $fiscalYear,
                        'prior_residual_value'   => $priorValue,
                        'revised_residual_value' => $revisedValue,
                        'notes'                  => $notes,
                        'reviewed_by'            => $userId,
                    ]);
                }

                $jeRow = null;
                $regen = null;

                if (!$isNoChange) {
                    // Downward path — impairment write-down.
                    $impairmentAmount = bcmul($delta, '-1', 2);  // abs(delta)

                    $impairmentAcct  = self::requireAccountId(
                        'accounting.lessor_residual_impairment_account_id',
                        'Impairment Loss — Residual'
                    );
                    $niLongTermAcct = self::requireAccountId(
                        'accounting.lessor_ni_longterm_account_id',
                        'NI Lease — Long-Term'
                    );

                    $jeLines = [
                        [
                            'account_id'  => $impairmentAcct,
                            'debit'       => $impairmentAmount,
                            'credit'      => '0.00',
                            'description' => "Residual impairment — lease {$lease['contract_number']} FY{$fiscalYear}",
                            'customer_id' => null,
                        ],
                        [
                            'account_id'  => $niLongTermAcct,
                            'debit'       => '0.00',
                            'credit'      => $impairmentAmount,
                            'description' => "NI long-term written down — lease {$lease['contract_number']} FY{$fiscalYear}",
                            'customer_id' => null,
                        ],
                    ];

                    $entryDate = self::resolveEntryDate();
                    $jeRow = JournalEntryService::create([
                        'entry_date'       => $entryDate,
                        'description'      => sprintf(
                            'Residual impairment — lease %s FY%d. Prior=%s, Revised=%s.',
                            $lease['contract_number'], $fiscalYear, $priorValue, $revisedValue
                        ),
                        'entry_type'       => 'system',
                        'reference'        => "LSE-RES-IMP-{$lease['contract_number']}-FY{$fiscalYear}",
                        'source_type'      => 'lease_residual_impairment',
                        'source_id'        => $leaseId,
                        'post_immediately' => true,
                    ], $jeLines, $userId);

                    // Update the lease row with the revised residual.
                    \db_execute(
                        "UPDATE leases SET unguaranteed_residual_value = ? WHERE id = ?",
                        [$revisedValue, $leaseId]
                    );

                    // Partial schedule regen so the unposted rows
                    // reflect the lower closing-NI floor.
                    $regen = LeaseAmortizationService::regeneratePartial($leaseId, $userId);

                    // Update the review row with the JE link + regen flag.
                    \db_execute(
                        "UPDATE acc_lease_residual_reviews
                            SET impairment_je_id     = ?,
                                schedule_regenerated = 1
                          WHERE id = ?",
                        [(int) $jeRow['id'], (int) $reviewId]
                    );
                }

                // Audit log.
                \db_insert('audit_log', [
                    'user_id'     => $userId,
                    'action'      => $isNoChange ? 'create' : 'status_change',
                    'module'      => 'accounting',
                    'entity_type' => 'lease',
                    'entity_id'   => $leaseId,
                    'entity_label' => $lease['contract_number'],
                    'notes'       => sprintf(
                        'Residual review FY%d: prior=%s → revised=%s (delta=%s, direction=%s).%s',
                        $fiscalYear, $priorValue, $revisedValue, $delta, $direction,
                        $jeRow ? sprintf(' Impairment JE: %s. Regen: %d new periods.',
                            $jeRow['entry_number'], $regen['regenerated_periods'] ?? 0) : ' No JE / no regen.'
                    ),
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]);

                $reviewRow = \db_row(
                    "SELECT * FROM acc_lease_residual_reviews WHERE id = ?",
                    [(int) $reviewId]
                );

                return [
                    'review'    => $reviewRow,
                    'je'        => $jeRow,
                    'regen'     => $regen,
                    'direction' => $direction,
                    'delta'     => $delta,
                ];
            } finally {
                \db_execute("SELECT RELEASE_LOCK(?)", ["ff_lease_residual_{$leaseId}"]);
            }
        });
    }

    /**
     * List all residual reviews for a fiscal year — used by the admin
     * page's history table.
     */
    public static function listForYear(int $fiscalYear): array
    {
        return \db_select(
            "SELECT lrr.*, l.contract_number,
                    je.entry_number AS impairment_je_number,
                    u.full_name AS reviewer_name
               FROM acc_lease_residual_reviews lrr
               LEFT JOIN leases l ON l.id = lrr.lease_id
               LEFT JOIN acc_journal_entries je ON je.id = lrr.impairment_je_id
               LEFT JOIN users u ON u.id = lrr.reviewed_by AND u.deleted_at IS NULL
              WHERE lrr.fiscal_year = ?
              ORDER BY lrr.reviewed_at DESC",
            [$fiscalYear]
        );
    }

    // ============================================================
    // Helpers
    // ============================================================

    private static function requireAccountId(string $settingKey, string $friendlyName): int
    {
        $accountId = (int) AccountingService::setting($settingKey, 0);
        if ($accountId <= 0) {
            throw new \RuntimeException(
                "Cannot complete residual review — accounting configuration incomplete. "
                . "No GL account mapped for {$friendlyName} (setting: {$settingKey})."
            );
        }
        return $accountId;
    }

    /** Resolve a valid entry_date — prefer today; fall back to the
     *  earliest open period's start_date if today's period is closed. */
    private static function resolveEntryDate(): string
    {
        $today = date('Y-m-d');
        $period = AccountingService::periodForDate($today);
        if ($period && $period['status'] === 'open') {
            return $today;
        }
        $open = AccountingService::currentOpenPeriod();
        if (!$open) {
            throw new \RuntimeException(
                "No open accounting period available. Cannot post residual impairment JE."
            );
        }
        return (string) $open['start_date'];
    }
}
