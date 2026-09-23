<?php
declare(strict_types=1);

/**
 * lib/QboPushers/InvoiceWriteoffEnqueuer.php
 *
 * S-QBO-INVOICE-WRITEOFF — queue an FF invoice write-off
 * (acc_bad_debt_writeoffs row) for InvoiceWriteoffPusher, which closes the
 * invoice in QuickBooks with a CreditMemo on the Bad-debt item applied to it.
 *
 * Called after the write-off commits by both write-off paths
 * (api/v1/accounting/ar/bad_debt_writeoff.php, api/v1/damage_claims/
 * update.php → InvoiceWriteOff). Best-effort per D-ENQUEUER-CONTRACT: never
 * throws; a refusal returns false and the write-off itself stands.
 *
 * Gates: the write-off exists and was not recovered; master sync on;
 * quickbooks.sync_mode.invoice_writeoff not 'qbo_to_ff' / 'disabled';
 * operation 'create' (a write-off is never edited; recovery is not pushed).
 *
 * @session  S-QBO-INVOICE-WRITEOFF
 * @decision D-QBO-INVOICE-WRITEOFF-1, D-ENQUEUER-CONTRACT
 */

namespace FleetForge\QboPushers;

class InvoiceWriteoffEnqueuer
{
    /**
     * @param int    $ffWriteoffId acc_bad_debt_writeoffs.id
     * @param string $operation    'create'
     * @return bool  true when a queue row was inserted
     */
    public static function enqueue(int $ffWriteoffId, string $operation): bool
    {
        try {
            // Gate 0: eligibility.
            $w = db_row("SELECT id, recovered FROM acc_bad_debt_writeoffs WHERE id = ?", [$ffWriteoffId]);
            if ($w === null) {
                error_log("[InvoiceWriteoffEnqueuer] gate-0 reject: write-off {$ffWriteoffId} not found");
                return false;
            }
            if (!empty($w['recovered'])) {
                return false;
            }

            // Gate 1: master kill switch (D-CPA-5).
            if ((string) settings_get('quickbooks.sync_enabled', '0') !== '1') {
                return false;
            }

            // Gate 2: per-entity mode.
            $mode = (string) settings_get('quickbooks.sync_mode.invoice_writeoff', 'queue');
            if ($mode === 'qbo_to_ff' || $mode === 'disabled') {
                return false;
            }

            // Gate 3: operation allowlist.
            if ($operation !== 'create') {
                return false;
            }

            // Gate 4: INSERT (QuickBooksSync dedupes pending jobs).
            \FleetForge\QuickBooksSync::insertQueueRow([
                'entity_type' => 'invoice_writeoff',
                'entity_id'   => $ffWriteoffId,
                'operation'   => $operation,
                'status'      => 'queued',
                'priority'    => 100,
                'retry_count' => 0,
                'max_retries' => 3,
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log("[InvoiceWriteoffEnqueuer] enqueue failed for write-off {$ffWriteoffId} op={$operation}: " . $e->getMessage());
            return false;
        }
    }
}
