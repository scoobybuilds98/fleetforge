<?php
declare(strict_types=1);

/**
 * lib/Exceptions/ChartOfAccountsIncompleteException.php
 *
 * Thrown by `AccountValidator::assertReadyForInvoicePush()` when one
 * or more bridge-account FF accounts (is_critical=1) lack a QBO
 * mapping row with status='mapped'. Future Pusher sessions
 * (S-QBO-11 invoice push, S-QBO-21 journal entry push) call the
 * gate at dispatch top to fail fast with an actionable message —
 * the operator must visit /quickbooks/accounts and map the listed
 * accounts before the Pusher can function.
 *
 * Extends the existing QuickBooksException family so the worker's
 * try/catch in cron/qbo_sync_worker.php can route it through the
 * standard drift-event path rather than crashing as an unclassified
 * Throwable. Carries the list of unmapped critical accounts in
 * `unmappedAccounts` so the worker can surface them in the
 * drift_events.description column.
 *
 * @session  S-QBO-8
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (Pusher contract — pre-flight gates)
 * @decision D-QBO-8-2 (bridge-account validator + assertReadyForInvoicePush gate)
 */

namespace FleetForge\Exceptions;

class ChartOfAccountsIncompleteException extends QuickBooksException
{
    /**
     * List of unmapped critical accounts. Each entry:
     *   [ff_account_id, code, name, account_type, account_subtype, critical_reason]
     *
     * @var array<int, array<string, mixed>>
     */
    public readonly array $unmappedAccounts;

    public function __construct(string $message, array $unmappedAccounts = [])
    {
        // No QBO-side errorCode / httpStatus — this exception fires
        // BEFORE the HTTP call. Pass null for those slots.
        parent::__construct(
            $message,
            'chart_of_accounts_incomplete',
            null,
            null,
            null
        );
        $this->unmappedAccounts = $unmappedAccounts;
    }
}
