<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/QuickBooksConnectionKind.php
 *
 * "QuickBooks connection" — The QuickBooks connection broke or expired (quickbooks.connection_status is
 * 'error' or 'expired'), so nothing syncs. Urgent. 'disconnected' is NOT a
 * problem: it's the state before go-live or after a deliberate disconnect.
 * Closes when the connection is back to 'connected'.
 *
 * Single system item: entity id 0 (S-ATTENTION-INBOX).
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\QuickBooksConnectionKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class QuickBooksConnectionKind extends TruthKind
{
    /** @return string */
    public function key(): string
    {
        return 'qbo_connection';
    }

    /** @return string */
    public function label(): string
    {
        return 'QuickBooks connection';
    }

    /** @return string */
    public function description(): string
    {
        return 'The QuickBooks connection broke or expired, so nothing is syncing. Closes when it\'s reconnected.';
    }

    /** @return string */
    public function entityType(): string
    {
        return 'quickbooks';
    }

    /** @return string[] */
    public function defaultRoles(): array
    {
        return ['accountant'];
    }

    /** @return string */
    public function defaultPriority(): string
    {
        return 'urgent';
    }

    /**
     * @param  int|null $entityId
     * @return array<int, array>
     */
    protected function fetch(?int $entityId): array
    {
        if ($entityId !== null && $entityId !== 0) {
            return [];
        }
        $status = (string) \settings_get('quickbooks.connection_status', '');
        return in_array($status, ['error', 'expired'], true) ? [0 => ['status' => $status]] : [];
    }

    /**
     * @param  array $r
     * @return array|null
     */
    protected function build(array $r): ?array
    {
        return [
            'title' => $r['status'] === 'expired'
                ? 'QuickBooks connection expired: reconnect it'
                : 'QuickBooks connection has an error',
            'facts' => [self::fact('Invoices and payments aren\'t syncing until it\'s reconnected')],
            'url'   => '/fleetforge/quickbooks/dashboard',
        ];
    }
}
