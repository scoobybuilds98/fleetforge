<?php
declare(strict_types=1);

/**
 * lib/QboPusherDispatcher.php
 *
 * Convention-based dispatcher for QBO entity Pushers. Given an
 * (entity_type, operation, entity_id) triple, looks up the
 * corresponding Pusher class+method by naming convention and
 * delegates. This is the worker's only entry point into the
 * per-entity Pusher surface.
 *
 * CONVENTION (locked as D-QBO-3-2 — registry-less, name-based):
 *
 *   entity_type → Pusher class name (snake_case → PascalCase + 'Pusher' suffix):
 *     'customer'      → CustomerPusher
 *     'vendor'        → VendorPusher
 *     'invoice'       → InvoicePusher
 *     'payment'       → PaymentPusher
 *     'credit_memo'   → CreditMemoPusher
 *     'refund_receipt'→ RefundReceiptPusher
 *     'bill'          → BillPusher
 *     'bill_payment'  → BillPaymentPusher
 *     'journal_entry' → JournalEntryPusher
 *     'item'          → ItemPusher
 *     'account'       → AccountPusher
 *     'tax_code'      → TaxCodePusher
 *
 *   operation → method name:
 *     'create' → pushCreate(int $entityId, ?array $payloadSnapshot = null): array
 *     'update' → pushUpdate(int $entityId, ?array $payloadSnapshot = null): array
 *     'void'   → pushVoid(int $entityId, ?array $payloadSnapshot = null): array
 *     'delete' → pushDelete(int $entityId, ?array $payloadSnapshot = null): array
 *
 *   Namespace search order (first match wins — checked in
 *   resolveClassName):
 *     1. FleetForge\QboPushers\<EntityType>Pusher  (preferred: groups
 *        all Pushers under a dedicated namespace so the worker's
 *        dependency surface stays scannable)
 *     2. FleetForge\<EntityType>Pusher             (fallback: matches
 *        the top-level lib/ pattern used by QuickBooksClient per
 *        D-QBO-CLIENT-LOCATION; allowed but discouraged once more
 *        than 2-3 Pushers exist)
 *
 *   The first Pusher session (S-QBO-5 customers) chooses the actual
 *   on-disk location. Both namespaces are checked here so either
 *   placement works without dispatcher changes.
 *
 * RAISES:
 *   - PusherNotImplementedException — class+method lookup failed.
 *     Worker catches this and marks queue row 'failed' with
 *     error_code='pusher_not_implemented' WITHOUT dispatching a
 *     failure notification (expected pre-S-QBO-5 state).
 *   - Whatever the Pusher itself throws (QuickBooksException family
 *     or unexpected Throwable). Worker classifies and routes
 *     accordingly.
 *
 * @session  S-QBO-3
 * @spec     §6.7 (worker dispatch pattern)
 */

namespace FleetForge;

use FleetForge\Exceptions\PusherNotImplementedException;

class QboPusherDispatcher
{
    /** Map of operation string → expected Pusher method name. */
    private const OPERATION_METHODS = [
        'create' => 'pushCreate',
        'update' => 'pushUpdate',
        'void'   => 'pushVoid',
        'delete' => 'pushDelete',
    ];

    /**
     * Resolve the (entity_type, operation) pair to a concrete
     * {class, method} pair and invoke it. Throws
     * PusherNotImplementedException if either lookup fails.
     *
     * @param  string     $entityType     One of the acc_qbo_sync_queue.entity_type ENUM values
     * @param  string     $operation      One of the OPERATION_METHODS keys
     * @param  int        $entityId       FF entity ID
     * @param  array|null $payloadSnapshot Queue row's payload_snapshot (used for
     *                                     void/delete when the FF entity may have
     *                                     been hard-deleted between enqueue + dispatch)
     * @return array      Pusher response (shape determined by Pusher itself)
     *
     * @throws PusherNotImplementedException
     */
    public static function dispatch(string $entityType, string $operation, int $entityId, ?array $payloadSnapshot = null): array
    {
        $className  = self::resolveClassName($entityType);
        $methodName = self::OPERATION_METHODS[$operation] ?? null;

        if ($className === null) {
            throw new PusherNotImplementedException(
                "No Pusher class found for entity_type='{$entityType}'. Expected one of: " .
                "FleetForge\\QboPushers\\" . self::classNameFor($entityType) . " OR " .
                "FleetForge\\" . self::classNameFor($entityType) . ".",
                'pusher_not_implemented'
            );
        }

        if ($methodName === null) {
            throw new PusherNotImplementedException(
                "No method mapping for operation='{$operation}'. Expected one of: " .
                implode(', ', array_keys(self::OPERATION_METHODS)) . '.',
                'pusher_not_implemented'
            );
        }

        if (!method_exists($className, $methodName)) {
            throw new PusherNotImplementedException(
                "Pusher class {$className} exists but does not implement {$methodName}(). " .
                "Expected signature: public static function {$methodName}(int \$entityId, ?array \$payloadSnapshot = null): array.",
                'pusher_not_implemented'
            );
        }

        // Both class and method exist — invoke. The Pusher is
        // responsible for: building the QBO payload from FF entity
        // state, calling QuickBooksClient::createEntity/updateEntity,
        // persisting the QBO ID + SyncToken into acc_qbo_*_map, and
        // returning whatever shape is useful to the caller (typically
        // the QBO response or a summary).
        return $className::$methodName($entityId, $payloadSnapshot);
    }

    /**
     * Cheap availability check used by the worker BEFORE marking a
     * queue row 'processing'. Returns true only if both the Pusher
     * class exists in one of the canonical namespaces AND the method
     * exists on it.
     *
     * This avoids the worker churning queue rows through
     * processing → failed for pusher_not_implemented when the
     * implementation simply hasn't shipped yet — but does NOT change
     * the eventual marking logic (worker still calls dispatch() under
     * try/catch and catches PusherNotImplementedException to mark
     * 'failed' cleanly).
     */
    public static function hasImplementation(string $entityType, string $operation): bool
    {
        $className  = self::resolveClassName($entityType);
        $methodName = self::OPERATION_METHODS[$operation] ?? null;

        return $className !== null
            && $methodName !== null
            && method_exists($className, $methodName);
    }

    /**
     * Snake_case entity_type → PascalCase Pusher class basename.
     * 'customer'       → 'CustomerPusher'
     * 'credit_memo'    → 'CreditMemoPusher'
     * 'journal_entry'  → 'JournalEntryPusher'
     * 'bill_payment'   → 'BillPaymentPusher'
     */
    public static function classNameFor(string $entityType): string
    {
        $parts = explode('_', $entityType);
        $pascal = '';
        foreach ($parts as $p) {
            $pascal .= ucfirst($p);
        }
        return $pascal . 'Pusher';
    }

    /**
     * Walk the namespace search order and return the first
     * fully-qualified class name that actually exists (i.e. has
     * been autoloaded by composer). Returns null if neither
     * placement is found.
     */
    private static function resolveClassName(string $entityType): ?string
    {
        $basename = self::classNameFor($entityType);

        $candidates = [
            'FleetForge\\QboPushers\\' . $basename,   // preferred
            'FleetForge\\' . $basename,               // fallback
        ];

        foreach ($candidates as $fqcn) {
            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }
        return null;
    }
}
